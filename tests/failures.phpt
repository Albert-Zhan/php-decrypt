--TEST--
Output failure isolation, symlink confinement, open_basedir, and interrupted index recovery
--SKIPIF--
<?php
if (PHP_OS === 'WINNT' || !extension_loaded('decrypt') || !function_exists('proc_open')) die('skip Linux test');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-fail-' . uniqid();
$project = $root . '/project'; $output = $root . '/output'; $outside = $root . '/project-other';
mkdir($root); mkdir($project); mkdir($output); mkdir($outside);
$fixture = $project . '/index.php'; file_put_contents($fixture, "<?php echo 'ok';");
$include = capture_include($fixture); $settings = capture_settings($project, $output);
try {
    $bad = $settings; $bad['decrypt.project_root'] = '';
    $result = capture_run($bad, $include . $include);
    capture_check($result[0] === 0 && $result[1] === 'okok'
        && substr_count($result[2], 'project_root and save_path') === 1,
        'invalid configuration warns once per request and preserves execution');
    $bad = $settings; $bad['decrypt.save_path'] = $root . '/missing';
    $result = capture_run($bad, $include);
    capture_check($result[0] === 0 && $result[1] === 'ok' && $result[2] !== '',
        'missing output root preserves execution');
    $bad = $settings; $bad['open_basedir'] = $project;
    $result = capture_run($bad, $include);
    capture_check($result[0] === 0 && $result[1] === 'ok'
        && strpos($result[2], 'open_basedir restriction') !== false && count(capture_files($output)) === 0,
        'open_basedir is respected');
    $other = $outside . '/outside.php'; file_put_contents($other, '<?php echo "outside";');
    $result = capture_run($settings, capture_include($other));
    capture_check($result === array(0, 'outside', '') && count(capture_records($output)) === 0,
        'project-root boundary excludes sibling prefixes');
    symlink($other, $project . '/external.php');
    capture_run($settings, capture_include($project . '/external.php'));
    capture_check(count(capture_records($output)) === 0, 'external source symlinks do not escape project root');
    $linked = $root . '/linked'; mkdir($linked); symlink($outside, $linked . '/current');
    $result = capture_run(capture_settings($project, $linked), $include);
    capture_check($result[0] === 0 && $result[1] === 'ok' && $result[2] !== ''
        && !file_exists($outside . '/index.php'), 'output directory symlinks are rejected');

    capture_run($settings, $include);
    $old = file_get_contents($output . '/current/index.php');
    $new = "<?php echo 'new';"; file_put_contents($fixture, $new);
    $hash = hash('sha256', $new); $blocked = $output . '/objects/' . substr($hash, 0, 2);
    mkdir($blocked); symlink($other, $blocked . '/' . $hash . '.code');
    $result = capture_run($settings, $include);
    capture_check($result[0] === 0 && $result[1] === 'new' && $result[2] !== ''
        && file_get_contents($output . '/current/index.php') === $old,
        'failed publication retains the previous complete mirror');
    unlink($blocked . '/' . $hash . '.code');
    file_put_contents($output . '/index.jsonl', '{"interrupted":', FILE_APPEND);
    $result = capture_run($settings, $include);
    capture_check($result === array(0, 'new', '') && count(capture_records($output)) === 2,
        'interrupted final index line is removed before the next append');

    $broken = $root . '/broken-index'; mkdir($broken); mkdir($broken . '/index.jsonl');
    $recover = capture_settings($project, $broken);
    $result = capture_run($recover, $include);
    capture_check($result[0] === 0 && $result[1] === 'new' && $result[2] !== '',
        'index failure preserves script execution');
    rmdir($broken . '/index.jsonl');
    $result = capture_run($recover, $include);
    capture_check($result === array(0, 'new', '') && count(capture_records($broken)) === 1,
        'uncommitted state is retried after the destination is repaired');
} finally { capture_remove($root); }
?>
--EXPECT--
invalid configuration warns once per request and preserves execution
missing output root preserves execution
open_basedir is respected
project-root boundary excludes sibling prefixes
external source symlinks do not escape project root
output directory symlinks are rejected
failed publication retains the previous complete mirror
interrupted final index line is removed before the next append
index failure preserves script execution
uncommitted state is retried after the destination is repaired
