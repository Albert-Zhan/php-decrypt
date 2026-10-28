<?php
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
function checked_capture_path($root, $relative) {
    if ($relative === '' || $relative[0] === '/' || strpos($relative, "\0") !== false
        || strpos($relative, '\\') !== false) throw new Exception('Unsafe relative path');
    $path = $root;
    foreach (explode('/', $relative) as $component) {
        if ($component === '' || $component === '.' || $component === '..') throw new Exception('Unsafe path component');
        $path .= '/' . $component;
        if (is_link($path)) throw new Exception('Symlink in capture path: ' . $relative);
    }
    return $path;
}
$index = false;
try {
    $index = fopen(checked_capture_path($root, 'index.jsonl'), 'rb');
    if (!$index || !flock($index, LOCK_SH)) throw new Exception('Cannot read index');
    $latest = array(); $objects = array(); $count = 0;
    while (($line = fgets($index, 1024 * 1024)) !== false) {
        ++$count;
        if (substr($line, -1) !== "\n") throw new Exception('Incomplete index record at line ' . $count);
        $record = json_decode($line, true);
        if (!is_array($record) || !isset($record['schema']) || $record['schema'] !== 1
            || !isset($record['sha256'], $record['bytes'])
            || (!isset($record['relative_path']) && !isset($record['relative_path_b64']))
            || !preg_match('/^[0-9a-f]{64}$/D', $record['sha256'])
            || !is_int($record['bytes']) || $record['bytes'] < 0) throw new Exception('Invalid index record ' . $count);
        $relative = isset($record['relative_path_b64'])
            ? base64_decode($record['relative_path_b64'], true) : $record['relative_path'];
        if (!is_string($relative) || (strpos($relative, 'current/') !== 0 && strpos($relative, 'eval/') !== 0)) {
            throw new Exception('Invalid capture path at line ' . $count);
        }
        checked_capture_path($root, $relative);
        $record['relative_path'] = $relative;
        if (isset($record['object'])) {
            $expected = 'objects/' . substr($record['sha256'], 0, 2) . '/' . $record['sha256'] . '.code';
            if ($record['object'] !== $expected) throw new Exception('Unexpected object path');
            $objects[$record['sha256']] = array($record['object'], $record['bytes']);
        }
        $latest[$relative] = $record;
    }
    if (!feof($index)) throw new Exception('Index read failed');
    flock($index, LOCK_UN); fclose($index); $index = false;
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
    if (is_resource($index)) { flock($index, LOCK_UN); fclose($index); }
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
