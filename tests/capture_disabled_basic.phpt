--TEST--
capture disabled basic
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
    foreach (array(null, 'disable') as $switch) {
        $off = $settings;
        if ($switch === null) unset($off['decrypt.switch']); else $off['decrypt.switch'] = $switch;
        $result = capture_run($off, $include);
        capture_check($result === array(0, "ok\0tail", '') && count(capture_files($output)) === 0,
            $switch === null ? 'disabled by default' : 'explicitly disabled');
    }
} finally { capture_remove($root); }
?>
--EXPECT--
disabled by default
explicitly disabled
