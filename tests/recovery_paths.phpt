--TEST--
POSIX backslash filenames can be verified and repaired without allowing path traversal
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR !== '/' || !extension_loaded('decrypt') || !function_exists('proc_open')) {
    die('skip POSIX, decrypt and proc_open required');
}
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-repair-path-' . uniqid();
$project = $root . '/project'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
$relative = 'a\\b.php';
$source = '<?php /* POSIX backslash filename */ return 42;';
$fixture = $project . '/' . $relative;
file_put_contents($fixture, $source);
$outside = $root . '/outside.php';
file_put_contents($outside, 'untouched');
$tool = dirname(__DIR__) . '/tools/verify-captures.php';
function verify_paths_run($tool, $output, $repair = false) {
    $args = array($tool, $output);
    if ($repair) $args[] = '--repair-current';
    return capture_run(array(), '$argv=' . var_export($args, true) . ';$argc=count($argv);require ' . var_export($tool, true) . ';');
}
try {
    $result = capture_run(capture_settings($project, $output), capture_include($fixture));
    capture_check($result === array(0, '', '') && file_get_contents($output . '/current/' . $relative) === $source,
        'capture preserves a POSIX backslash filename');
    $result = verify_paths_run($tool, $output);
    capture_check($result[0] === 0 && $result[2] === '', 'verifier accepts a POSIX backslash filename');
    file_put_contents($output . '/current/' . $relative, 'damaged');
    $result = verify_paths_run($tool, $output, true);
    capture_check($result[0] === 0 && $result[2] === '' && strpos($result[1], '1 repaired') !== false
        && file_get_contents($output . '/current/' . $relative) === $source,
        'repair restores a POSIX backslash filename');

    $records = capture_records($output); $record = $records[0];
    foreach (array('current/../../outside.php', '/absolute.php', 'current//file.php',
                   'current/./file.php', "current/a\0b.php") as $bad) {
        $record['relative_path'] = $bad; $record['relative_path_b64'] = base64_encode($bad);
        file_put_contents($output . '/index.jsonl', json_encode($record) . "\n");
        $result = verify_paths_run($tool, $output, true);
        if ($result[0] !== 1 || (strpos($result[2], 'Unsafe') === false && strpos($result[2], 'Invalid capture path') === false)) {
            throw new Exception('Unsafe path was not rejected');
        }
    }
    capture_check(file_get_contents($outside) === 'untouched', 'repair still rejects unsafe relative paths');
    symlink($outside, $output . '/current/link.php');
    $record['relative_path'] = 'current/link.php'; $record['relative_path_b64'] = base64_encode('current/link.php');
    file_put_contents($output . '/index.jsonl', json_encode($record) . "\n");
    $result = verify_paths_run($tool, $output, true);
    capture_check($result[0] === 1 && strpos($result[2], 'Symlink in capture path') !== false
        && file_get_contents($outside) === 'untouched', 'repair still rejects output symlinks');
} finally { capture_remove($root); }
?>
--EXPECT--
capture preserves a POSIX backslash filename
verifier accepts a POSIX backslash filename
repair restores a POSIX backslash filename
repair still rejects unsafe relative paths
repair still rejects output symlinks
