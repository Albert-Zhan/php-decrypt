<?php
/* Demonstration-specific export: each sample loader evaluates exactly one
 * complete file body. General eval programs cannot be reconstructed this way. */
if ($argc !== 3) { fwrite(STDERR, "Usage: verify-and-restore.php CAPTURE_ROOT RESTORE_ROOT\n"); exit(2); }
$capture = realpath($argv[1]); $restore = $argv[2];
if ($capture === false || file_exists($restore)) { fwrite(STDERR, "Capture must exist; restore destination must be new\n"); exit(2); }
$rows = file($capture . '/index.jsonl', FILE_IGNORE_NEW_LINES);
$expected = array('index.php', 'lib/message.php');
$payloads = array();
foreach ($rows as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) throw new Exception('Invalid index');
    if ($row['kind'] !== 'eval') continue;
    foreach ($expected as $relative) {
        $original = realpath(__DIR__ . '/project/' . $relative);
        if (strpos($row['source_path'], $original . '(') !== 0) continue;
        $body = file_get_contents($capture . '/' . $row['relative_path']);
        $plain = file_get_contents(__DIR__ . '/plain/' . $relative);
        if ('<?php' . "\n" . $body !== $plain || hash('sha256', $body) !== $row['sha256']) {
            throw new Exception('Decoded payload differs from original: ' . $relative);
        }
        $payloads[$relative] = $plain;
    }
}
if (count($payloads) !== count($expected)) throw new Exception('Not all demo payloads were captured');
foreach ($payloads as $relative => $plain) {
    $target = $restore . '/' . $relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
    if (file_put_contents($target, $plain) !== strlen($plain)) throw new Exception('Export failed');
    echo 'Verified and restored: ', $relative, "\n";
}
