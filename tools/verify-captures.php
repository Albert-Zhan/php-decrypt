<?php
require_once __DIR__ . '/capture-store.php';

/* Read-only integrity check by default. Stop capture before --repair-current. */
if ($argc < 2 || $argc > 3 || ($argc === 3 && $argv[2] !== '--repair-current')) {
    fwrite(STDERR, "Usage: php tools/verify-captures.php OUTPUT_ROOT [--repair-current]\n");
    exit(2);
}
$root = realpath($argv[1]);
$repair = $argc === 3;
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Output root does not exist\n"); exit(2);
}
try {
    $latest = array(); $objects = array(); $count = 0;
    foreach (capture_index_records($root) as $count => $record) {
        $relative = $record['relative_path'];
        checked_capture_path($root, $relative);
        if (isset($record['object'])) {
            $objects[$record['sha256']] = array($record['object'], $record['bytes']);
        }
        $latest[$relative] = $record;
    }
    foreach ($objects as $hash => $object) {
        $path = checked_capture_path($root, $object[0]);
        if (!is_file($path) || filesize($path) !== $object[1] || hash_file('sha256', $path) !== $hash) {
            throw new Exception('Missing or corrupt object: ' . $object[0]);
        }
    }
    $repaired = 0;
    foreach ($latest as $relative => $record) {
        $path = checked_capture_path($root, $relative);
        if (is_file($path) && filesize($path) === $record['bytes'] && hash_file('sha256', $path) === $record['sha256']) continue;
        if (!$repair || !isset($record['object'])) throw new Exception('Missing or corrupt mirror: ' . $relative);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new Exception('Cannot create mirror directory');
        checked_capture_path($root, $relative);
        $temporary = tempnam($directory, '.decrypt-repair-');
        if ($temporary === false || dirname($temporary) !== $directory) throw new Exception('Cannot create local temporary file');
        try {
            $bytes = file_get_contents(checked_capture_path($root, $record['object']));
            if ($bytes === false || strlen($bytes) !== $record['bytes'] || hash('sha256', $bytes) !== $record['sha256']
                || file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new Exception('Mirror repair failed');
            }
        } finally { if (file_exists($temporary)) unlink($temporary); }
        ++$repaired;
    }
    printf("OK: %d records, %d unique objects, %d published paths, %d repaired\n", $count, count($objects), count($latest), $repaired);
} catch (Exception $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
