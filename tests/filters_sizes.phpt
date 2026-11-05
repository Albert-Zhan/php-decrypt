--TEST--
Capture byte limits handle boundaries, suffixes, eval bodies and invalid quantities
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-filter-size-' . uniqid();
$project = $root . '/project'; mkdir($root); mkdir($project);
$fixture = $project . '/index.php';
$source = '<?php echo "ok"; /*';
$source .= str_repeat('x', 1024 - strlen($source) - 2) . '*/';
file_put_contents($fixture, $source);
try {
    foreach (array('0', '1024', '1K', '1k', '1M', '1G', (string) PHP_INT_MAX) as $limit) {
        $output = $root . '/allowed-' . uniqid(); mkdir($output);
        $result = capture_run(capture_settings($project, $output, array('decrypt.max_capture_bytes' => $limit)), capture_include($fixture));
        if ($result !== array(0, 'ok', '') || file_get_contents($output . '/current/index.php') !== $source
            || count(capture_records($output)) !== 1) throw new Exception('Valid size rejected: ' . $limit);
    }
    capture_check(true, 'zero, byte integers and K M G suffixes accept exact-limit contents');
    $output = $root . '/small'; mkdir($output);
    $result = capture_run(capture_settings($project, $output, array('decrypt.max_capture_bytes' => '1023')), capture_include($fixture));
    capture_check($result === array(0, 'ok', '') && count(capture_files($output)) === 0,
        'oversized source executes without partial mirrors objects index or lock state');

    $binary = '<?php echo "ok"; ?>' . "\0中文";
    file_put_contents($fixture, $binary);
    $output = $root . '/binary'; mkdir($output);
    $result = capture_run(capture_settings($project, $output, array('decrypt.max_capture_bytes' => strlen($binary) - 1)), capture_include($fixture));
    capture_check($result === array(0, "ok\0中文", '') && count(capture_files($output)) === 0,
        'limits count UTF-8 and NUL bytes rather than characters');

    $body = 'return 42;';
    file_put_contents($fixture, '<?php echo eval(' . var_export($body, true) . '); /*' . str_repeat('x', 256) . '*/');
    foreach (array(strlen($body), strlen($body) - 1) as $limit) {
        $output = $root . '/eval-' . $limit; mkdir($output);
        $result = capture_run(capture_settings($project, $output, array('decrypt.max_capture_bytes' => $limit)), capture_include($fixture));
        $rows = capture_records($output);
        if ($result !== array(0, '42', '') || count($rows) !== ($limit === strlen($body) ? 1 : 0)) throw new Exception('Eval boundary mismatch');
        if ($rows && ($rows[0]['kind'] !== 'eval' || file_get_contents($output . '/' . $rows[0]['relative_path']) !== $body)) {
            throw new Exception('Wrong eval bytes');
        }
    }
    capture_check(true, 'eval bodies have independent limits and remain eligible when the loader is oversized');
    file_put_contents($fixture, '<?php $body=str_repeat(" ",512)."return 42;";echo eval($body);');
    $output = $root . '/large-eval'; mkdir($output);
    $result = capture_run(capture_settings($project, $output, array('decrypt.max_capture_bytes' => '256')), capture_include($fixture));
    $rows = capture_records($output);
    capture_check($result === array(0, '42', '') && count($rows) === 1 && $rows[0]['kind'] === 'file',
        'runtime-generated oversized eval bodies are skipped without blocking execution');

    file_put_contents($fixture, '<?php echo "ok";');
    $badOutput = $root . '/invalid'; mkdir($badOutput);
    foreach (array('-1', '1.5M', '2MB', '12junk', '', '1 K', '99999999999999999999999999999999999', (string) PHP_INT_MAX . 'K') as $bad) {
        $result = capture_run(capture_settings($project, $badOutput, array('decrypt.max_capture_bytes' => $bad === '' ? '' : "'" . $bad . "'")),
            capture_include($fixture) . capture_include($fixture));
        if ($result[0] !== 0 || $result[1] !== 'okok' || substr_count($result[2], 'decrypt.max_capture_bytes') !== 1
            || count(capture_files($badOutput)) !== 0) throw new Exception('Invalid size accepted: ' . $bad);
    }
    capture_check(true, 'negative malformed and overflowing byte limits warn once without captures');
} finally { capture_remove($root); }
?>
--EXPECT--
zero, byte integers and K M G suffixes accept exact-limit contents
oversized source executes without partial mirrors objects index or lock state
limits count UTF-8 and NUL bytes rather than characters
eval bodies have independent limits and remain eligible when the loader is oversized
runtime-generated oversized eval bodies are skipped without blocking execution
negative malformed and overflowing byte limits warn once without captures
