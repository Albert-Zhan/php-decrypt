--TEST--
capture binary basic
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = sys_get_temp_dir() . '/decrypt-' . uniqid();
$project = $root . '/project';
$output = $root . '/output with spaces';
mkdir($root); mkdir($project); mkdir($output); mkdir($project . '/app');
$fixture = $project . '/app/源码 (demo).php';
$source = "<?php echo 'ok'; ?>\0tail";
file_put_contents($fixture, $source);
$settings = capture_settings($project, $output);
$include = capture_include($fixture);
$current = $output . '/current/app/源码 (demo).php';
try {
    $result = capture_run($settings, $include . $include . 'echo ini_get("decrypt.save_path");');
    $records = capture_records($output);
    $current = $output . '/current/app/源码 (demo).php';
    capture_check($result === array(0, "ok\0tailok\0tail" . $output, '')
        && count($records) === 1 && file_get_contents($current) === $source
        && $records[0]['sha256'] === hash('sha256', $source)
        && file_get_contents($output . '/' . $records[0]['object']) === $source,
        'exact binary bytes, Unicode paths, and repeated include deduplication');
    clearstatcache(); $inode = fileinode($current);
    $result = capture_run($settings, '', $fixture);
    clearstatcache();
    capture_check($result === array(0, "ok\0tail", '') && count(capture_records($output)) === 1
        && fileinode($current) === $inode, 'cross-process deduplication avoids replacing current');

    capture_check(!file_exists($output . '/decrypt.code')
        && count(glob($output . '/current/app/.decrypt-*')) === 0, 'no legacy append file or successful-write temp files');
} finally { capture_remove($root); }
?>
--EXPECT--
exact binary bytes, Unicode paths, and repeated include deduplication
cross-process deduplication avoids replacing current
no legacy append file or successful-write temp files
