--TEST--
Benchmark bootstraps isolated parent and child PHP processes with shared dependencies
--SKIPIF--
<?php
if (PHP_OS === 'WINNT' || !extension_loaded('decrypt') || !function_exists('proc_open')
    || !function_exists('passthru')) die('skip POSIX CLI, decrypt, proc_open and passthru required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$previous = getenv('DECRYPT_EXTENSION');
try {
    putenv('DECRYPT_EXTENSION=' . capture_extension());
    // Deliberately omit all harness INI arguments: PHP 7 may lack JSON under -n.
    $command = capture_quote(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY) . ' -n '
        . capture_quote(dirname(__DIR__) . '/tools/benchmark.php') . ' 2 128';
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new Exception('Unable to start benchmark');
    $result = capture_finish(array($process, $pipes));
    $report = json_decode($result[1], true);
    capture_check($result[0] === 0 && $result[2] === '' && is_array($report)
        && $report['files'] === 2 && $report['bytes_per_file'] === 128,
        'benchmark runs from php -n with isolated dependencies');
    foreach (array('disabled', 'first_capture', 'unchanged_capture') as $mode) {
        if (!isset($report['median_ms'][$mode]) || !is_numeric($report['median_ms'][$mode])
            || $report['median_ms'][$mode] < 0) throw new Exception('Invalid benchmark mode: ' . $mode);
    }
    capture_check(true, 'benchmark reports all three measured modes');
} finally {
    if ($previous === false) putenv('DECRYPT_EXTENSION');
    else putenv('DECRYPT_EXTENSION=' . $previous);
}
?>
--EXPECT--
benchmark runs from php -n with isolated dependencies
benchmark reports all three measured modes
