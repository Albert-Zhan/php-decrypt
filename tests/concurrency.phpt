--TEST--
Concurrent writers deduplicate records and readers only observe complete published files
--SKIPIF--
<?php
if (PHP_OS === 'WINNT' || !extension_loaded('decrypt') || !function_exists('proc_open')) die('skip Linux test');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = sys_get_temp_dir() . '/decrypt-concurrent-' . uniqid();
$project = $root . '/project'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
$fixture = $project . '/index.php'; $source = '<?php /*' . str_repeat('a', 256 * 1024) . '*/';
file_put_contents($fixture, $source); $settings = capture_settings($project, $output);
try {
    $children = array();
    for ($i = 0; $i < 8; ++$i) $children[] = capture_start($settings, capture_include($fixture));
    foreach ($children as $child) {
        if (capture_finish($child) !== array(0, '', '')) throw new Exception('Writer failed');
    }
    capture_check(count(capture_records($output)) === 1 && count(capture_files($output . '/objects')) === 1
        && file_get_contents($output . '/current/index.php') === $source,
        'eight processes produce one version record and one complete object');
    $children = array();
    for ($i = 0; $i < 8; ++$i) {
        $path = $project . '/file' . $i . '.php'; file_put_contents($path, $source);
        $children[] = capture_start($settings, capture_include($path));
    }
    foreach ($children as $child) {
        if (capture_finish($child) !== array(0, '', '')) throw new Exception('Writer failed');
    }
    capture_check(count(capture_records($output)) === 9 && count(capture_files($output . '/objects')) === 1,
        'different paths share objects and serialize complete JSONL records');

    $target = $output . '/current/index.php';
    $a = $source; $b = '<?php /*' . str_repeat('b', 256 * 1024) . '*/';
    $marker = $root . '/running'; file_put_contents($marker, '1');
    $ready = $root . '/reader-ready';
    $readerCode = 'file_put_contents(' . var_export($ready, true) . ',"1");$hashes=' . var_export(array(hash('sha256', $a), hash('sha256', $b)), true) . ';'
        . '$samples=0; while(file_exists(' . var_export($marker, true) . ')) {'
        . '$bytes=file_get_contents(' . var_export($target, true) . ');'
        . 'if(!in_array(hash("sha256",$bytes),$hashes,true)) { echo "partial";exit(1); } ++$samples;'
        . 'clearstatcache(); } echo $samples;';
    $reader = capture_start(array(), $readerCode);
    $deadline = microtime(true) + 5;
    while (!file_exists($ready) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
    if (!file_exists($ready)) { unlink($marker); throw new Exception('Reader did not start'); }
    $writerCode = '$path=' . var_export($fixture, true) . ';'
        . '$variants=' . var_export(array($a, $b), true) . ';'
        . 'for($i=0;$i<20;++$i){file_put_contents($path,$variants[$i%2]);require $path;}'
        . 'unlink(' . var_export($marker, true) . ');';
    /* Avoid command-line length limits: the worker itself only generates source. */
    $worker = $root . '/worker.php'; file_put_contents($worker, '<?php ' . $writerCode);
    $writerResult = capture_run($settings, '', $worker);
    $readerResult = capture_finish($reader);
    capture_check($writerResult === array(0, '', '') && $readerResult[0] === 0
        && is_numeric($readerResult[1]) && (int)$readerResult[1] > 0,
        'concurrent reader sees only complete old or new contents');
} finally { capture_remove($root); }
?>
--EXPECT--
eight processes produce one version record and one complete object
different paths share objects and serialize complete JSONL records
concurrent reader sees only complete old or new contents
