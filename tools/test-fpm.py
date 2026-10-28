#!/usr/bin/env python3
"""Exercise real FPM workers over FastCGI, without Nginx or third-party Python packages."""
import argparse
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import pwd
import grp
import signal
import socket
import struct
import subprocess
import tempfile
import time


def record(kind, body=b''):
    padding = (-len(body)) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(body), padding, 0) + body + b'\0' * padding


def encode_length(length):
    return bytes([length]) if length < 128 else struct.pack('!I', length | 0x80000000)


def request(address, script, query=''):
    params = {'SCRIPT_FILENAME': str(script), 'SCRIPT_NAME': '/' + script.name,
              'REQUEST_METHOD': 'GET', 'QUERY_STRING': query, 'SERVER_PROTOCOL': 'HTTP/1.1',
              'SERVER_NAME': 'localhost', 'SERVER_PORT': '80', 'REMOTE_ADDR': '127.0.0.1',
              'GATEWAY_INTERFACE': 'CGI/1.1', 'REDIRECT_STATUS': '200'}
    body = b''
    for name, value in params.items():
        name, value = name.encode(), value.encode()
        body += encode_length(len(name)) + encode_length(len(value)) + name + value
    with socket.create_connection(address, timeout=15) as connection:
        connection.settimeout(15)
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, body) + record(4) + record(5))
        output, errors = b'', b''
        def read_exact(size):
            result = b''
            while len(result) < size:
                chunk = connection.recv(size - len(result))
                if not chunk:
                    raise RuntimeError('FPM closed FastCGI connection early')
                result += chunk
            return result
        while True:
            version, kind, request_id, size, padding, _ = struct.unpack('!BBHHBB', read_exact(8))
            if version != 1 or request_id != 1:
                raise RuntimeError('Unexpected FastCGI response')
            data = read_exact(size)
            if padding:
                read_exact(padding)
            if kind == 6:
                output += data
            elif kind == 7:
                errors += data
            elif kind == 3:
                if len(data) != 8 or struct.unpack('!IB3x', data) != (0, 0):
                    raise RuntimeError('FastCGI request did not finish successfully')
                break
        header, separator, payload = output.partition(b'\r\n\r\n')
        if not separator:
            raise RuntimeError('Missing FastCGI response headers: ' + repr(output))
        return header.decode(errors='replace'), payload.decode(errors='replace'), errors.decode(errors='replace')


def rows(output):
    return [json.loads(line) for line in (output / 'index.jsonl').read_text().splitlines()]


def validate_store(output):
    latest = {}
    for row in rows(output):
        latest[row['relative_path']] = row
        if row['object']:
            content = (output / row['object']).read_bytes()
            assert len(content) == row['bytes'] and hashlib.sha256(content).hexdigest() == row['sha256']
    for name, row in latest.items():
        content = (output / name).read_bytes()
        assert len(content) == row['bytes'] and hashlib.sha256(content).hexdigest() == row['sha256'], name


class Pool:
    def __init__(self, args, root, project, output, workers):
        self.root, self.process, self.log = root, None, None
        root.mkdir()
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        self.address = ('127.0.0.1', port)
        config = root / 'fpm.conf'
        identity = ''
        if os.geteuid() == 0:
            identity = 'user = ' + pwd.getpwuid(0).pw_name + '\ngroup = ' + grp.getgrgid(0).gr_name + '\n'
        config.write_text('[global]\nerror_log = ' + str(root / 'fpm-error.log')
                          + '\ndaemonize = no\n[verification]\n' + identity
                          + 'listen = 127.0.0.1:' + str(port) + '\n'
                          + 'pm = static\npm.max_children = ' + str(workers)
                          + '\npm.max_requests = 0\ncatch_workers_output = yes\nclear_env = no\n'
                          + 'request_terminate_timeout = 15s\nchdir = ' + str(project) + '\n')
        command = [args.fpm, '-F', '-y', str(config)]
        if os.geteuid() == 0:
            command.append('-R')
        for setting in ['extension=' + args.extension, 'decrypt.switch=enable',
                        'decrypt.project_root=' + str(project), 'decrypt.save_path=' + str(output),
                        'display_errors=0', 'log_errors=1', 'error_reporting=32767', 'opcache.enable=0']:
            command.extend(['-d', setting])
        self.log = (root / 'master.log').open('wb')
        self.process = subprocess.Popen(command, stdout=self.log, stderr=subprocess.STDOUT)
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                self.close()
                raise RuntimeError('FPM startup failed: ' + (root / 'master.log').read_text(errors='replace'))
            try:
                with socket.create_connection(self.address, timeout=0.2):
                    return
            except OSError:
                time.sleep(0.05)
        self.close()
        raise RuntimeError('FPM listener did not become ready')

    def close(self):
        if self.process is not None and self.process.poll() is None:
            self.process.send_signal(signal.SIGTERM)
            try:
                self.process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait()
        if self.log is not None:
            self.log.close()


def run(args, root):
    project, output = root / 'project', root / 'captures'
    project.mkdir(); output.mkdir()
    script = project / 'index.php'
    script.write_text('''<?php
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'normal';
if ($mode === 'fatal') { throw new RuntimeException('expected FPM test exception'); }
if ($mode === 'exit') { exit('expected-exit'); }
if ($mode === 'parallel') { usleep(150000); }
$value = require __DIR__ . '/value.php';
$decoded = eval('return "decoded";');
header('Content-Type: application/json');
echo json_encode(array('pid' => getmypid(), 'value' => $value, 'decoded' => $decoded));
''')
    value = project / 'value.php'
    value.write_text('<?php return "A";')
    pool = Pool(args, root / 'serial-pool', project, output, 1)
    try:
        def normal(expected='A', allow_warning=False):
            header, payload, errors = request(pool.address, script)
            assert 'Status: 500' not in header and (not errors or allow_warning), (header, errors)
            body = json.loads(payload)
            assert body['value'] == expected and body['decoded'] == 'decoded', body
            return body['pid']
        pid = normal()
        initial = len(rows(output))
        for _ in range(20):
            assert normal() == pid, 'Serial requests did not reuse the same worker'
        assert len(rows(output)) == initial, 'Unchanged requests appended duplicate versions'
        print('PASS same worker: 21 requests, stable capture and deduplication')

        header, _, _ = request(pool.address, script, 'mode=fatal')
        assert 'Status: 500' in header, header
        assert normal() == pid, 'Worker did not survive an uncaught PHP exception'
        _, payload, _ = request(pool.address, script, 'mode=exit')
        assert payload == 'expected-exit' and normal() == pid
        print('PASS same worker recovers after uncaught exception and exit')

        value.write_text('<?php return "B";')
        assert normal('B') == pid
        value.write_text('<?php return "A";')
        assert normal('A') == pid
        versions = [r for r in rows(output) if r['relative_path'] == 'current/value.php']
        assert len(versions) == 3 and versions[0]['sha256'] == versions[2]['sha256']
        print('PASS A-to-B-to-A versions across requests')

        # A capture warning must not poison request globals in the next request.
        mirror = output / 'current/value.php'
        mirror.unlink(); mirror.mkdir()
        assert normal(allow_warning=True) == pid
        mirror.rmdir()
        assert normal() == pid and mirror.is_file()
        print('PASS output failure followed by repaired destination on the same worker')
        validate_store(output)
    finally:
        pool.close()

    parallel = Pool(args, root / 'parallel-pool', project, output, 4)
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
            responses = list(executor.map(lambda _: request(parallel.address, script, 'mode=parallel'), range(24)))
        pids = set()
        for header, payload, errors in responses:
            assert 'Status: 500' not in header and (not errors or allow_warning), (header, errors)
            body = json.loads(payload)
            assert body['value'] == 'A' and body['decoded'] == 'decoded'
            pids.add(body['pid'])
        assert len(pids) >= 2, 'Requests did not exercise multiple workers'
        validate_store(output)
        print('PASS concurrent FPM: 24 requests across ' + str(len(pids)) + ' workers, valid JSONL and captures')
    finally:
        parallel.close()
    print('FPM integration verification passed')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--fpm', required=True)
    parser.add_argument('--extension', required=True)
    parser.add_argument('--keep', action='store_true', help='Keep diagnostics after successful execution')
    args = parser.parse_args()
    args.fpm, args.extension = str(Path(args.fpm).resolve()), str(Path(args.extension).resolve())
    root = Path(tempfile.mkdtemp(prefix='decrypt-fpm-')).resolve()
    try:
        run(args, root)
    except BaseException:
        print('FPM diagnostics: ' + str(root))
        for log in root.glob('*/*.log'):
            print('--- ' + str(log) + '\n' + log.read_text(errors='replace')[-12000:])
        raise
    else:
        if args.keep:
            print('FPM diagnostics: ' + str(root))
        else:
            import shutil
            shutil.rmtree(root)


if __name__ == '__main__':
    main()
