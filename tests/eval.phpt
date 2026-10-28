--TEST--
Eval payloads, nested evals, origin mapping, and capture switch
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = sys_get_temp_dir() . '/decrypt-eval-' . uniqid();
$project = $root . '/project (demo)'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
$fixture = $project . '/index (entry).php';
$inner = 'echo "decoded";';
$outer = 'eval(' . var_export($inner, true) . ');';
$source = '<?php eval(base64_decode(' . var_export(base64_encode($outer), true) . '));';
file_put_contents($fixture, $source);
try {
    $settings = capture_settings($project, $output);
    $result = capture_run($settings, '', $fixture);
    $rows = capture_records($output); $payloads = array();
    foreach ($rows as $row) if ($row['kind'] === 'eval') {
        $payloads[] = file_get_contents($output . '/' . $row['relative_path']);
        if ($row['sha256'] !== hash('sha256', end($payloads))) throw new Exception('Wrong eval checksum');
    }
    capture_check($result === array(0, 'decoded', '') && count($rows) === 3
        && in_array($outer, $payloads, true) && in_array($inner, $payloads, true),
        'nested Base64 eval payloads preserve exact bytes and parentheses in origin paths');
    capture_check(file_get_contents($output . '/current/index (entry).php') === $source,
        'file mirror preserves loader while eval bodies remain separately addressable');
    capture_run($settings, '', $fixture);
    capture_check(count(capture_records($output)) === 3, 'repeated eval payloads are deduplicated');
    $off = $root . '/off'; mkdir($off);
    $result = capture_run(capture_settings($project, $off, array('decrypt.capture_eval' => 0)), '', $fixture);
    capture_check($result === array(0, 'decoded', '') && count(capture_records($off)) === 1
        && !is_dir($off . '/eval'), 'eval capture can be disabled');
    $jsonOutput = $root . '/json-state'; mkdir($jsonOutput);
    $code = 'json_decode("{");$saved=json_last_error();' . capture_include($fixture)
        . 'echo json_last_error()===$saved ? ":preserved" : ":changed";';
    $result = capture_run(capture_settings($project, $jsonOutput), $code);
    capture_check($result === array(0, 'decoded:preserved', ''), 'capture preserves application JSON error state');
} finally { capture_remove($root); }
?>
--EXPECT--
nested Base64 eval payloads preserve exact bytes and parentheses in origin paths
file mirror preserves loader while eval bodies remain separately addressable
repeated eval payloads are deduplicated
eval capture can be disabled
capture preserves application JSON error state
