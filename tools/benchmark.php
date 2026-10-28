<?php
/* Small local microbenchmark; excludes process startup from elapsed_ms.
 * Usage: DECRYPT_EXTENSION=/absolute/decrypt.so php -n tools/benchmark.php [files] [bytes]
 */
$extension = getenv('DECRYPT_EXTENSION');
if (!$extension || !is_file($extension)) { fwrite(STDERR, "Set DECRYPT_EXTENSION to the built module\n"); exit(2); }
$count = isset($argv[1]) ? (int)$argv[1] : 200;
$size = isset($argv[2]) ? (int)$argv[2] : 20480;
if ($count < 1 || $count > 5000 || $size < 128 || $size > 1048576) exit(2);
$root = sys_get_temp_dir() . '/decrypt-bench-' . uniqid();
mkdir($root); mkdir($root . '/project');
function remove_bench($path) {
    foreach (scandir($path) as $name) if ($name !== '.' && $name !== '..') {
        $child = $path . '/' . $name;
        if (is_dir($child)) remove_bench($child); else unlink($child);
    }
    rmdir($path);
}
function bench_run($extension, $project, $output, $enabled) {
    $command = escapeshellarg(PHP_BINARY) . ' -n -d ' . escapeshellarg('extension=' . $extension)
        . ' -d decrypt.switch=' . ($enabled ? 'enable' : 'disable')
        . ' -d ' . escapeshellarg('decrypt.project_root=' . $project)
        . ' -d ' . escapeshellarg('decrypt.save_path=' . $output);
    $code = '$files=glob(' . var_export($project . '/*.php', true) . ');$start=microtime(true);'
        . 'foreach($files as $file){require $file;}echo json_encode(array("files"=>count($files),"elapsed_ms"=>(microtime(true)-$start)*1000));';
    $command .= ' -r ' . escapeshellarg($code);
    $process = proc_open($command, array(1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $stderr !== '') throw new Exception('Benchmark failed: ' . $stderr);
    return json_decode($stdout, true)['elapsed_ms'];
}
try {
    for ($i = 0; $i < $count; ++$i) {
        $prefix = '<?php /* file ' . $i . ' ';
        file_put_contents($root . '/project/' . $i . '.php', $prefix . str_repeat('x', $size - strlen($prefix) - 2) . '*/');
    }
    $times = array('disabled' => array(), 'first_capture' => array(), 'unchanged_capture' => array());
    for ($run = 0; $run < 3; ++$run) {
        $output = $root . '/capture' . $run; mkdir($output);
        $times['disabled'][] = bench_run($extension, $root . '/project', $output, false);
        $times['first_capture'][] = bench_run($extension, $root . '/project', $output, true);
        $times['unchanged_capture'][] = bench_run($extension, $root . '/project', $output, true);
    }
    $result = array('php' => PHP_VERSION, 'files' => $count, 'bytes_per_file' => $size,
        'history' => true, 'index' => true, 'sync' => false, 'median_ms' => array());
    foreach ($times as $mode => $samples) { sort($samples); $result['median_ms'][$mode] = round($samples[1], 3); }
    echo json_encode($result, JSON_PRETTY_PRINT), "\n";
} finally { remove_bench($root); }
