#!/usr/bin/env python3
"""PHP 7 integration check with a separately built, unmodified php-beast module."""
import argparse
import base64
import concurrent.futures
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import grp
import signal
import socket
import subprocess
import time


TOOLS = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('decrypt_fpm', TOOLS / 'test-fpm.py')
fastcgi = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fastcgi)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def execute(command, **kwargs):
    result = subprocess.run(command, capture_output=True, timeout=30, **kwargs)
    require(result.returncode == 0 and not result.stderr,
            'Command failed: ' + repr(command) + '\n' + result.stderr.decode(errors='replace'))
    return result.stdout


def php_arguments(php):
    # Reuse the normal build/example dependency detection, including shared PHP 7 JSON.
    script = '. "$1"; php_decrypt_isolated_args "$2"; printf "%s\\0" "${PHP_DECRYPT_ISOLATED_ARGS[@]}"'
    raw = execute(['bash', '-euc', script, 'bash', str(TOOLS / 'php-runtime.sh'), php])
    return [os.fsdecode(part) for part in raw.split(b'\0') if part]


def extension_args(paths):
    return [argument for path in paths for argument in ('-d', 'extension=' + str(path))]


def value_matches(output, expected):
    try:
        body = json.loads(output)
        return body['value_b64'] == expected and body['again_b64'] == expected
    except (ValueError, KeyError, TypeError):
        return False


class BeastPool:
    def __init__(self, fpm, arguments, project, root, workers):
        self.process = self.log = None
        root.mkdir()
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            self.address = ('127.0.0.1', probe.getsockname()[1])
        identity = ''
        if os.geteuid() == 0:
            identity = 'user = ' + pwd.getpwuid(0).pw_name + '\ngroup = ' + grp.getgrgid(0).gr_name + '\n'
        config = root / 'fpm.conf'
        config.write_text('[global]\nerror_log = ' + str(root / 'error.log') + '\ndaemonize = no\n'
                          + '[beast-test]\n' + identity + 'listen = 127.0.0.1:' + str(self.address[1])
                          + '\npm = static\npm.max_children = ' + str(workers)
                          + '\npm.max_requests = 0\nrequest_terminate_timeout = 15s\n'
                          + 'catch_workers_output = yes\nchdir = ' + str(project) + '\n')
        self.log = (root / 'master.log').open('wb')
        command = [fpm, *arguments, '-F', '-y', str(config)]
        if os.geteuid() == 0:
            command.append('-R')
        self.process = subprocess.Popen(command, stdout=self.log, stderr=subprocess.STDOUT)
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline and self.process.poll() is None:
            try:
                with socket.create_connection(self.address, timeout=0.2):
                    return
            except OSError:
                time.sleep(0.05)
        self.close()
        raise RuntimeError('FPM startup failed; inspect ' + str(root))

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


def check_store(cli, captures, expected):
    records = fastcgi.rows(captures)
    require(len(records) == 2 and all(row['kind'] == 'file' for row in records),
            'Expected exactly two ordinary file captures, with no eval records')
    for relative, content in expected.items():
        require((captures / 'current' / relative).read_bytes() == content,
                'Plaintext byte mismatch: ' + relative)
    fastcgi.validate_store(captures)
    execute([*cli, str(TOOLS / 'verify-captures.php'), str(captures)])


def fpm_check(args, isolated, settings, project, captures, expected, value, root):
    arguments = [*isolated, *extension_args([args.decrypt, args.beast]), *settings,
                 '-d', 'opcache.enable=0', '-d', 'display_errors=0', '-d', 'log_errors=1']
    counts = []
    for workers, count in ((1, 8), (4, 16)):
        pool = BeastPool(args.fpm, arguments, project, root / ('fpm-' + str(workers)), workers)
        try:
            def request(_):
                headers, body, errors = fastcgi.request(pool.address, project / 'index.php')
                require('Status: 500' not in headers and not errors and value_matches(body, value),
                        'FPM returned unexpected output: ' + repr((headers, body, errors)))
                return json.loads(body)['pid']
            if workers == 1:
                pids = {request(i) for i in range(count)}
                require(len(pids) == 1, 'Serial FPM requests did not reuse one worker')
            else:
                with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
                    pids = set(executor.map(request, range(count)))
                require(len(pids) >= 2, 'Concurrent requests did not reach multiple workers')
            check_store([args.php, *isolated], captures, expected)
            counts.append({'workers': workers, 'requests': count, 'observed_pids': len(pids)})
        finally:
            pool.close()
    return counts


def run(args, root):
    isolated = php_arguments(args.php)
    cli = [args.php, *isolated]
    version = json.loads(execute([*cli, '-r', 'echo json_encode(array("php"=>PHP_VERSION,"id"=>PHP_VERSION_ID,"zts"=>PHP_ZTS));']))
    require(70000 <= version['id'] < 80000 and not version['zts'], 'This check targets PHP 7 NTS')
    beast_version = execute([*cli, *extension_args([args.beast]), '-d', 'beast.log_file=' + str(root / 'version.log'),
                             '-r', 'echo phpversion("beast");']).decode()
    report = {'runtime': version, 'beast_version': beast_version,
              'modules_sha256': {name: hashlib.sha256(Path(getattr(args, name)).read_bytes()).hexdigest()
                                 for name in ('beast', 'decrypt')}, 'cases': []}
    index = '''<?php
// A real extension-encrypted project: no eval or Base64 PHP wrapper.
$first = require __DIR__ . '/lib/message.php';
$second = require __DIR__ . '/lib/message.php';
usleep(20000);
echo json_encode(array('value_b64'=>base64_encode($first), 'again_b64'=>base64_encode($second), 'pid'=>getmypid())), "\\n";
'''
    encoder = '''foreach (array('index.php', 'lib/message.php') as $relative) {
        if (!beast_encode_file($argv[1].'/'.$relative, $argv[2].'/'.$relative, 0,
            constant('BEAST_ENCRYPT_TYPE_'.$argv[3]))) { exit(1); }
    }'''
    for algorithm in ('DES', 'AES', 'BASE64'):
        for language, message in (('ascii', 'extension plaintext'), ('unicode', '扩展加密明文')):
            case = root / (algorithm + '-' + language)
            plain, project, captures = (case / name for name in ('plain', 'encrypted', 'captures'))
            (plain / 'lib').mkdir(parents=True)
            (project / 'lib').mkdir(parents=True)
            captures.mkdir(mode=0o700)
            (plain / 'index.php').write_text(index)
            (plain / 'lib/message.php').write_text('<?php\n/* discarded before encryption */\nreturn "' + message + '";\n')
            value = base64.b64encode(message.encode()).decode()
            require(value_matches(execute([*cli, str(plain / 'index.php')]), value), 'Plain fixture failed')
            expected = {relative: execute([*cli, '-r', 'echo php_strip_whitespace($argv[1]);', '--', str(plain / relative)])
                        for relative in ('index.php', 'lib/message.php')}
            settings = ['-d', 'beast.log_file=' + str(case / 'beast.log'),
                        '-d', 'decrypt.switch=enable', '-d', 'decrypt.project_root=' + str(project),
                        '-d', 'decrypt.save_path=' + str(captures), '-d', 'opcache.enable_cli=0']
            execute([*cli, *extension_args([args.beast]), *settings, '-r', encoder, '--', str(plain), str(project), algorithm])
            encrypted = {relative: (project / relative).read_bytes() for relative in expected}
            require(all(encrypted[r] != expected[r] for r in expected), 'Encryption did not change the source')
            without = subprocess.run([*cli, str(project / 'index.php')], capture_output=True, timeout=15)
            require(not value_matches(without.stdout, value), 'Encrypted project unexpectedly worked without beast')
            baseline = execute([*cli, *extension_args([args.beast]), *settings, str(project / 'index.php')])
            result = {'algorithm': algorithm, 'language': language}
            if not value_matches(baseline, value):
                # Test upstream in isolation: never report corrupted upstream code as a decrypt pass.
                require(algorithm == 'BASE64' and language == 'unicode', 'Unexpected beast-only baseline failure')
                payload = encrypted['lib/message.php'][20:]  # unmodified upstream header.c: 8 + 3 * 4 bytes
                try:
                    encoding_intact = base64.b64decode(payload, validate=True) == expected['lib/message.php']
                except ValueError:
                    encoding_intact = False
                require(not encoding_intact, 'Unexpected Unicode failure with intact Base64 payload')
                result['status'] = 'UPSTREAM_BASE64_UNICODE_FAILURE'
                result['reason'] = 'beast-only output already differs; encoded Base64 payload is corrupt'
                report['cases'].append(result)
                print('UPSTREAM ISSUE BASE64 unicode: beast-only baseline and encoded payload are corrupt')
                continue
            proper = [*cli, *extension_args([args.decrypt, args.beast]), *settings]
            for _ in range(2):
                require(value_matches(execute([*proper, str(project / 'index.php')]), value), 'Capture run failed')
                check_store(cli, captures, expected)
            require(value_matches(execute([*cli, str(captures / 'current/index.php')]), value),
                    'Captured project did not run without beast and decrypt')
            wrong = case / 'wrong-order'
            wrong.mkdir()
            bad_settings = [*settings, '-d', 'decrypt.save_path=' + str(wrong)]
            bad = execute([*cli, *extension_args([args.beast, args.decrypt]), *bad_settings, str(project / 'index.php')])
            require(not value_matches(bad, value), 'Wrong-order negative control unexpectedly succeeded')
            require((wrong / 'current/index.php').read_bytes() == encrypted['index.php'],
                    'Wrong-order capture was not the encrypted entry file')
            require(len(fastcgi.rows(wrong)) == 1, 'Wrong-order capture unexpectedly reached the library')
            result.update(status='PASS', file_records=2, eval_records=0, wrong_order='ciphertext captured; application output incorrect')
            if args.fpm:
                result['fpm'] = fpm_check(args, isolated, settings, project, captures, expected, value, case)
            require(all((project / r).read_bytes() == b for r, b in encrypted.items()), 'Encrypted input was modified')
            report['cases'].append(result)
            print('PASS ' + algorithm + ' ' + language + ': plaintext bytes, history/index, dedup, restored execution, wrong-order control'
                  + ('; 24 FPM requests' if args.fpm else ''))
    (root / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print('Report and all fixtures retained: ' + str(root))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    parser.add_argument('--beast', required=True)
    parser.add_argument('--decrypt', required=True)
    parser.add_argument('--fpm', help='Optional matching PHP 7 FPM binary')
    parser.add_argument('--output', required=True, help='New output directory; fixtures and logs are kept')
    args = parser.parse_args()
    for name in ('php', 'beast', 'decrypt', 'fpm'):
        if getattr(args, name):
            path = Path(getattr(args, name)).resolve(strict=True)
            require(path.is_file(), 'Not a file: ' + str(path))
            setattr(args, name, str(path))
    root = Path(args.output)
    root.mkdir(mode=0o700, parents=True, exist_ok=False)
    root = root.resolve()
    try:
        run(args, root)
    except BaseException:
        print('Failure diagnostics retained: ' + str(root))
        raise


if __name__ == '__main__':
    main()
