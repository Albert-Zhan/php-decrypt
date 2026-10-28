--TEST--
Project mirrors, deduplicated history, version transitions, and exact bytes
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
try {
    foreach (array(null, 'disable') as $switch) {
        $off = $settings;
        if ($switch === null) unset($off['decrypt.switch']); else $off['decrypt.switch'] = $switch;
        $result = capture_run($off, $include);
        capture_check($result === array(0, "ok\0tail", '') && count(capture_files($output)) === 0,
            $switch === null ? 'disabled by default' : 'explicitly disabled');
    }
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

    file_put_contents($fixture, ''); capture_run($settings, $include);
    $records = capture_records($output);
    capture_check(file_get_contents($current) === '' && end($records)['bytes'] === 0, 'empty source');
    unlink($current); capture_run($settings, $include);
    capture_check(is_file($current) && count(capture_records($output)) === 6, 'missing current is recovered');

    $light = $root . '/light'; mkdir($light);
    capture_run(capture_settings($project, $light, array('decrypt.history' => 0, 'decrypt.index' => 0)), $include);
    capture_check(is_file($light . '/current/app/源码 (demo).php') && !file_exists($light . '/objects')
        && !file_exists($light . '/index.jsonl'), 'history and index can be disabled');
    $sync = $root . '/sync'; mkdir($sync);
    $result = capture_run(capture_settings($project, $sync, array('decrypt.sync' => 1)), $include);
    capture_check($result === array(0, '', '') && count(capture_records($sync)) === 1, 'strict Linux sync mode');
    file_put_contents($project . '/app/include-path.php', "<?php echo 'included';");
    $result = capture_run($settings, 'set_include_path(' . var_export($project . '/app', true) . ');require "include-path.php";');
    capture_check($result === array(0, 'included', '')
        && is_file($output . '/current/app/include-path.php'), 'include_path resolves to the actual project file');
    $raw = $project . '/app/raw-' . chr(255) . '.php';
    file_put_contents($raw, '<?php /* raw filename */');
    capture_run($settings, capture_include($raw));
    $rows = capture_records($output); $last = end($rows);
    capture_check(base64_decode($last['relative_path_b64'], true) === 'current/app/raw-' . chr(255) . '.php'
        && is_file($output . '/' . base64_decode($last['relative_path_b64'], true)),
        'non-UTF8 filename bytes remain recoverable through Base64 metadata');
    capture_check(!file_exists($output . '/decrypt.code')
        && count(glob($output . '/current/app/.decrypt-*')) === 0, 'no legacy append file or successful-write temp files');
} finally { capture_remove($root); }
?>
--EXPECT--
disabled by default
explicitly disabled
exact binary bytes, Unicode paths, and repeated include deduplication
cross-process deduplication avoids replacing current
different paths share one content object
new content retains previous version
returning to an old version records the transition without duplicating objects
empty source
missing current is recovered
history and index can be disabled
strict Linux sync mode
include_path resolves to the actual project file
non-UTF8 filename bytes remain recoverable through Base64 metadata
no legacy append file or successful-write temp files
