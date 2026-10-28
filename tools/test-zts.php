<?php
/* Run with ZTS PHP, parallel and decrypt loaded, and project/captures pre-created. */
if (!PHP_ZTS || !extension_loaded('parallel') || !extension_loaded('decrypt') || $argc !== 2) {
    fwrite(STDERR, "Requires ZTS PHP, parallel, decrypt, and an existing test root\n"); exit(2);
}
$root = realpath($argv[1]);
if ($root === false || !is_dir($root . '/project') || !is_dir($root . '/captures')) exit(2);
$source = '<?php return eval(\'return 42;\');';
$shared = $root . '/project/common.php';
file_put_contents($shared, $source);
$runtimes = array(); $futures = array();
for ($i = 0; $i < 4; ++$i) {
    $own = $root . '/project/thread' . $i . '.php';
    file_put_contents($own, $source);
    $runtime = new parallel\Runtime();
    $futures[] = $runtime->run(function ($common, $own) {
        $sum = 0;
        for ($j = 0; $j < 20; ++$j) {
            $sum += require $common;
            $sum += require $own;
            usleep(1000);
        }
        return array('pid' => getmypid(), 'sum' => $sum);
    }, array($shared, $own));
    $runtimes[] = $runtime;
}
foreach ($futures as $future) {
    $result = $future->value();
    if ($result['pid'] !== getmypid() || $result['sum'] !== 1680) throw new RuntimeException('Thread result mismatch');
}
foreach ($runtimes as $runtime) $runtime->close();
$rows = file($root . '/captures/index.jsonl', FILE_IGNORE_NEW_LINES);
if (count($rows) !== 10) throw new RuntimeException('Expected 5 file records and 5 eval records, got ' . count($rows));
foreach ($rows as $line) {
    $row = json_decode($line, true);
    if (!is_array($row)) throw new RuntimeException('Invalid concurrent JSONL record');
    foreach (array($row['relative_path'], $row['object']) as $relative) {
        $bytes = file_get_contents($root . '/captures/' . $relative);
        if (strlen($bytes) !== $row['bytes'] || hash('sha256', $bytes) !== $row['sha256']) {
            throw new RuntimeException('Corrupt thread capture');
        }
    }
}
$objects = glob($root . '/captures/objects/*/*.code');
if (count($objects) !== 2) throw new RuntimeException('Thread content deduplication failed');
echo "PASS ZTS: 4 native threads, 160 file includes plus evals, valid deduplicated captures\n";
