--TEST--
capture empty recovery
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
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
    file_put_contents($fixture, ''); capture_run($settings, $include);
    $records = capture_records($output);
    capture_check(file_get_contents($current) === '' && end($records)['bytes'] === 0, 'empty source');
    unlink($current); capture_run($settings, $include);
    capture_check(is_file($current) && count(capture_records($output)) === 2, 'missing current is recovered');

} finally { capture_remove($root); }
?>
--EXPECT--
empty source
missing current is recovered
