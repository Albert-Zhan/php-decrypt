--TEST--
Integrity verification detects corruption and restores mirrors from immutable objects
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = sys_get_temp_dir() . '/decrypt-repair-' . uniqid();
$project = $root . '/project'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
$fixture = $project . '/index.php'; $source = '<?php /* original bytes */';
file_put_contents($fixture, $source);
$tool = dirname(__DIR__) . '/tools/verify-captures.php';
function verify_run($tool, $output, $repair = false) {
    $args = array($tool, $output);
    if ($repair) $args[] = '--repair-current';
    return capture_run(array(), '$argv=' . var_export($args, true) . ';$argc=count($argv);require ' . var_export($tool, true) . ';');
}
try {
    capture_run(capture_settings($project, $output), capture_include($fixture));
    $result = verify_run($tool, $output);
    capture_check($result[0] === 0 && strpos($result[1], '1 unique objects') !== false, 'verifier accepts a complete capture');
    $current = $output . '/current/index.php';
    file_put_contents($current, str_repeat('x', strlen($source)));
    $result = verify_run($tool, $output);
    capture_check($result[0] === 1 && strpos($result[2], 'corrupt mirror') !== false,
        'verifier detects same-length mirror corruption');
    $result = verify_run($tool, $output, true);
    capture_check($result[0] === 0 && strpos($result[1], '1 repaired') !== false
        && file_get_contents($current) === $source, 'repair restores exact original bytes');
    $records = capture_records($output); $object = $output . '/' . $records[0]['object'];
    file_put_contents($object, str_repeat('x', strlen($source)));
    $result = verify_run($tool, $output, true);
    capture_check($result[0] === 1 && strpos($result[2], 'corrupt object') !== false,
        'corrupt history is reported instead of used for repair');
} finally { capture_remove($root); }
?>
--EXPECT--
verifier accepts a complete capture
verifier detects same-length mirror corruption
repair restores exact original bytes
corrupt history is reported instead of used for repair
