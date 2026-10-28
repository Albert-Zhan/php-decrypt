--TEST--
capture paths variation
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
    file_put_contents($project . '/app/include-path.php', "<?php echo 'included';");
    $result = capture_run($settings, 'set_include_path(' . var_export($project . '/app', true) . ');require "include-path.php";');
    capture_check($result === array(0, 'included', '')
        && is_file($output . '/current/app/include-path.php'), 'include_path resolves to the actual project file');
} finally { capture_remove($root); }
?>
--EXPECT--
include_path resolves to the actual project file
