<?php
function capture_quote($value) {
    if (DIRECTORY_SEPARATOR === '\\') return escapeshellarg($value);
    return "'" . str_replace("'", "'\\''", $value) . "'";
}
function capture_extension() {
    return getenv('DECRYPT_EXTENSION') ?: dirname(__DIR__) . '/modules/decrypt.' . PHP_SHLIB_SUFFIX;
}
function capture_start($settings, $code, $script = null) {
    // run-tests.php supplies the selected INI and extension settings here.
    // Reuse them so shared dependencies (e.g. PHP 7 json.so) remain loaded.
    $testArgs = getenv('TEST_PHP_EXTRA_ARGS');
    $command = capture_quote(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY);
    if ($testArgs !== false && trim($testArgs) !== '') {
        $command .= ' ' . $testArgs;
    } else {
        $command .= ' -d ' . capture_quote('extension=' . capture_extension());
    }
    $command .= ' -d display_errors=stderr -d log_errors=0';
    foreach ($settings as $key => $value) $command .= ' -d ' . capture_quote($key . '=' . $value);
    $command .= $script === null ? ' -r ' . capture_quote($code) : ' -f ' . capture_quote($script);
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new Exception('Unable to start PHP');
    return array($process, $pipes);
}
function capture_finish($child) {
    list($process, $pipes) = $child;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $result = array(proc_close($process), $stdout, $stderr);
    $GLOBALS['capture_last_result'] = $result;
    return $result;
}
function capture_run($settings, $code, $script = null) {
    return capture_finish(capture_start($settings, $code, $script));
}
function capture_check($condition, $label) {
    if (!$condition) {
        var_export(isset($GLOBALS['capture_last_result']) ? $GLOBALS['capture_last_result'] : null);
        throw new Exception($label);
    }
    echo $label, "\n";
}
function capture_remove($path) {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') capture_remove($path . '/' . $name);
        }
        rmdir($path);
    } else unlink($path);
}
function capture_records($output) {
    $path = $output . '/index.jsonl';
    if (!is_file($path)) return array();
    $rows = array();
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) throw new Exception('Invalid JSONL: ' . $line);
        $rows[] = $row;
    }
    return $rows;
}
function capture_settings($project, $output, $extra = array()) {
    return array_merge(array('decrypt.switch' => 'enable', 'decrypt.project_root' => $project,
        'decrypt.save_path' => $output), $extra);
}
function capture_include($path) { return 'require base64_decode(' . var_export(base64_encode($path), true) . ');'; }
function capture_files($directory) {
    if (!is_dir($directory)) return array();
    $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
        FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $files[] = $file->getPathname();
    sort($files);
    return $files;
}
