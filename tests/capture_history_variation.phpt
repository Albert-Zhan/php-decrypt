--TEST--
capture history variation
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
    capture_run($settings, $include);
    $second = $project . '/app/copy.php'; file_put_contents($second, $source);
    capture_run($settings, capture_include($second));
    capture_check(count(capture_records($output)) === 2 && count(capture_files($output . '/objects')) === 1,
        'different paths share one content object');
    file_put_contents($fixture, "<?php echo 'new';");
    capture_run($settings, $include);
    capture_check(file_get_contents($current) === "<?php echo 'new';"
        && count(capture_records($output)) === 3 && count(capture_files($output . '/objects')) === 2,
        'new content retains previous version');
    file_put_contents($fixture, $source); capture_run($settings, $include);
    capture_check(count(capture_records($output)) === 4 && count(capture_files($output . '/objects')) === 2,
        'returning to an old version records the transition without duplicating objects');

} finally { capture_remove($root); }
?>
--EXPECT--
different paths share one content object
new content retains previous version
returning to an old version records the transition without duplicating objects
