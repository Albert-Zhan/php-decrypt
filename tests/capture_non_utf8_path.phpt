--TEST--
Capture preserves non-UTF8 filename bytes when supported by the filesystem
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
$probe = tempnam(sys_get_temp_dir(), 'decrypt-path-probe-');
if ($probe === false) die('skip cannot probe temporary filesystem');
unlink($probe);
$probe .= chr(255);
$created = @file_put_contents($probe, '');
if ($created === false) die('skip filesystem requires valid UTF-8 filenames');
unlink($probe);
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
    $raw = $project . '/app/raw-' . chr(255) . '.php';
    file_put_contents($raw, '<?php /* raw filename */');
    capture_run($settings, capture_include($raw));
    $rows = capture_records($output); $last = end($rows);
    capture_check(base64_decode($last['relative_path_b64'], true) === 'current/app/raw-' . chr(255) . '.php'
        && is_file($output . '/' . base64_decode($last['relative_path_b64'], true)),
        'non-UTF8 filename bytes remain recoverable through Base64 metadata');
} finally { capture_remove($root); }
?>
--EXPECT--
non-UTF8 filename bytes remain recoverable through Base64 metadata
