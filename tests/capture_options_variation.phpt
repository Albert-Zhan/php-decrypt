--TEST--
capture options variation
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
if (PHP_OS === 'WINNT') die('skip POSIX sync test');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-' . uniqid();
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
    file_put_contents($fixture, '');
    $light = $root . '/light'; mkdir($light);
    capture_run(capture_settings($project, $light, array('decrypt.history' => 0, 'decrypt.index' => 0)), $include);
    capture_check(is_file($light . '/current/app/源码 (demo).php') && !file_exists($light . '/objects')
        && !file_exists($light . '/index.jsonl'), 'history and index can be disabled');
    $sync = $root . '/sync'; mkdir($sync);
    $result = capture_run(capture_settings($project, $sync, array('decrypt.sync' => 1)), $include);
    capture_check($result === array(0, '', '') && count(capture_records($sync)) === 1, 'strict POSIX sync mode');
} finally { capture_remove($root); }
?>
--EXPECT--
history and index can be disabled
strict POSIX sync mode
