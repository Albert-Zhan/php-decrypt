<?php
require_once __DIR__ . '/capture-store.php';

function history_usage() {
    fwrite(STDERR, "Usage:\n"
        . "  php tools/capture-history.php OUTPUT_ROOT list PROJECT_RELATIVE_PATH\n"
        . "  php tools/capture-history.php OUTPUT_ROOT export --record NUMBER --output FILE\n"
        . "  php tools/capture-history.php OUTPUT_ROOT export --sha256 HASH --output FILE\n");
    exit(2);
}

function history_list($root, $path) {
    capture_path_components($path);
    $relative = 'current/' . $path;
    echo "RECORD\tCAPTURED_AT_UTC\tBYTES\tSHA256\n";
    foreach (capture_index_records($root) as $number => $record) {
        if ($record['relative_path'] !== $relative) continue;
        $timestamp = $record['captured_at'] ?? null;
        $time = is_int($timestamp) ? gmdate('Y-m-d\\TH:i:s\\Z', $timestamp) : '-';
        printf("%d\t%s\t%d\t%s\n", $number, $time, $record['bytes'], $record['sha256']);
    }
}

function history_find_record($root, $number) {
    foreach (capture_index_records($root) as $line => $record) {
        if ($line === $number) return $record;
    }
    throw new Exception('No index record at line ' . $number);
}

function history_export($root, $relative, $hash, $expectedBytes, $output) {
    $name = basename($output);
    $directory = realpath(dirname($output));
    if ($directory === false || !is_dir($directory) || $name === '' || $name === '.' || $name === '..'
        || substr($output, -1) === DIRECTORY_SEPARATOR) {
        throw new Exception('Output must name a file in an existing directory');
    }
    $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (file_exists($target) || is_link($target)) {
        throw new Exception('Output already exists: ' . $target);
    }

    $source = checked_capture_path($root, $relative);
    $input = is_file($source) ? @fopen($source, 'rb') : false;
    if ($input === false) throw new Exception('Capture source unavailable: ' . $relative);
    $temporary = false;
    $staged = false;
    try {
        $temporary = @tempnam($directory, '.decrypt-export-');
        if ($temporary === false || dirname($temporary) !== $directory) {
            throw new Exception('Cannot create temporary export in output directory');
        }
        $staged = @fopen($temporary, 'w+b');
        if ($staged === false) throw new Exception('Cannot open temporary export');
        $copied = @stream_copy_to_stream($input, $staged);
        if ($copied === false || !@fflush($staged) || !@rewind($staged)) {
            throw new Exception('Cannot copy capture source: ' . $relative);
        }
        $context = hash_init('sha256');
        $hashed = hash_update_stream($context, $staged);
        if ($hashed !== $copied || ($expectedBytes !== null && $hashed !== $expectedBytes)
            || hash_final($context) !== $hash) {
            throw new Exception('Capture size or SHA-256 mismatch: ' . $relative);
        }
        $closed = fclose($staged);
        $staged = false;
        if (!$closed) throw new Exception('Cannot close temporary export');

        // Link a fully verified file atomically. Unlike rename(), link() cannot
        // replace a destination created between the initial check and publication.
        if (!@link($temporary, $target)) {
            throw new Exception('Cannot publish export (destination exists or filesystem rejected it): ' . $target);
        }
    } finally {
        fclose($input);
        if (is_resource($staged)) fclose($staged);
        if ($temporary !== false) unlink($temporary);
    }
}

if ($argc < 3) history_usage();
$operation = $argv[2];
if (!(($operation === 'list' && $argc === 4)
    || ($operation === 'export' && $argc === 7 && $argv[5] === '--output'
        && ($argv[3] === '--record' || $argv[3] === '--sha256')))) {
    history_usage();
}
if ($operation === 'export' && $argv[3] === '--record') {
    $recordNumber = (int) $argv[4];
    if ($recordNumber < 1 || (string) $recordNumber !== $argv[4]) history_usage();
}
if ($operation === 'export' && $argv[3] === '--sha256' && !capture_valid_hash($argv[4])) history_usage();

try {
    $root = realpath($argv[1]);
    if ($root === false || !is_dir($root)) throw new Exception('Output root does not exist');
    if ($operation === 'list') {
        history_list($root, $argv[3]);
    } elseif ($argv[3] === '--record') {
        $record = history_find_record($root, $recordNumber);
        $source = $record['object'] ?? $record['relative_path'];
        history_export($root, $source, $record['sha256'], $record['bytes'], $argv[6]);
    } else {
        $hash = $argv[4];
        history_export($root, capture_object_path($hash), $hash, null, $argv[6]);
    }
} catch (Exception $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
