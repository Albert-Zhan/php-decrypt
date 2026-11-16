<?php
/* Shared validation for CLI tools that read capture stores. */

function capture_valid_hash($hash) {
    return is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1;
}

function capture_object_path($hash) {
    return 'objects/' . substr($hash, 0, 2) . '/' . $hash . '.code';
}

function capture_path_components($relative) {
    if ($relative === '' || $relative[0] === '/' || strpos($relative, "\0") !== false
        || (DIRECTORY_SEPARATOR === '\\' && strpos($relative, '\\') !== false)) {
        throw new Exception('Unsafe relative path');
    }
    $components = explode('/', $relative);
    foreach ($components as $component) {
        if ($component === '' || $component === '.' || $component === '..') {
            throw new Exception('Unsafe path component');
        }
    }
    return $components;
}

function checked_capture_path($root, $relative) {
    $path = $root;
    foreach (capture_path_components($relative) as $component) {
        $path .= '/' . $component;
        if (is_link($path)) throw new Exception('Symlink in capture path: ' . $relative);
    }
    return $path;
}

function capture_index_records($root) {
    $path = checked_capture_path($root, 'index.jsonl');
    $index = is_file($path) ? @fopen($path, 'rb') : false;
    if ($index === false) throw new Exception('Cannot read index: ' . $path);
    try {
        if (!flock($index, LOCK_SH)) throw new Exception('Cannot lock index');
        $lineNumber = 0;
        while (($line = fgets($index, 1024 * 1024)) !== false) {
            ++$lineNumber;
            if (substr($line, -1) !== "\n") {
                throw new Exception('Incomplete or oversized index record at line ' . $lineNumber);
            }
            $record = json_decode($line, true);
            if (!is_array($record) || ($record['schema'] ?? null) !== 1
                || !capture_valid_hash($record['sha256'] ?? null)
                || !isset($record['bytes']) || !is_int($record['bytes']) || $record['bytes'] < 0) {
                throw new Exception('Invalid index record ' . $lineNumber);
            }
            $relative = $record['relative_path'] ?? null;
            if (array_key_exists('relative_path_b64', $record)) {
                $encoded = $record['relative_path_b64'];
                $relative = is_string($encoded) ? base64_decode($encoded, true) : false;
            }
            if (!is_string($relative) || (strpos($relative, 'current/') !== 0 && strpos($relative, 'eval/') !== 0)) {
                throw new Exception('Invalid capture path at line ' . $lineNumber);
            }
            capture_path_components($relative);
            $record['relative_path'] = $relative;
            if (isset($record['object']) && $record['object'] !== capture_object_path($record['sha256'])) {
                throw new Exception('Unexpected object path at line ' . $lineNumber);
            }
            yield $lineNumber => $record;
        }
        if (!feof($index)) throw new Exception('Index read failed');
    } finally {
        fclose($index);
    }
}
