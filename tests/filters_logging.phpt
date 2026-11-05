--TEST--
Skip logs are optional deduplicated JSON diagnostics independent of application error handlers
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR !== '/' || !extension_loaded('decrypt') || !function_exists('proc_open')) die('skip POSIX, decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-filter-log-' . uniqid();
$project = $root . '/project'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
$relative = "a\nb.php"; $fixture = $project . '/' . $relative;
file_put_contents($fixture, '<?php echo "ok";eval(\'return 42;\');');
$log = $root . '/skips.log';
$settings = capture_settings($project, $output, array('decrypt.exclude_paths' => "'" . json_encode(array($relative)) . "'",
    'decrypt.log_skips' => 1, 'error_log' => $log));
$code = 'set_error_handler(function(){throw new Exception("unexpected warning");});json_decode("{");$saved=json_last_error();'
    . capture_include($fixture) . capture_include($fixture) . 'echo json_last_error()===$saved ? ":preserved" : ":changed";';
try {
    $result = capture_run($settings, $code);
    capture_check($result === array(0, 'okok:preserved', '') && count(capture_files($output)) === 0,
        'skip diagnostics preserve execution JSON state and user error handlers');
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    $kinds = array();
    foreach ($lines as $line) {
        $start = strpos($line, 'decrypt: ');
        if ($start === false) throw new Exception('Unexpected log line');
        $row = json_decode(substr($line, $start + strlen('decrypt: ')), true);
        if (!is_array($row) || $row['event'] !== 'capture_skipped' || $row['reason'] !== 'excluded'
            || strpos(base64_decode($row['source_path_b64']), $fixture) !== 0) throw new Exception('Invalid skip log');
        $kinds[] = $row['kind'];
    }
    sort($kinds);
    capture_check(count($lines) === 2 && $kinds === array('eval', 'file'),
        'skip logs escape filename newlines and deduplicate repeated file and eval sources');
    unlink($log);
    $settings['decrypt.log_skips'] = 0;
    $result = capture_run($settings, $code);
    capture_check($result === array(0, 'okok:preserved', '') && !file_exists($log), 'skip logging can be disabled');

    $settings['decrypt.exclude_paths'] = "'[]'";
    $settings['decrypt.max_capture_bytes'] = 1;
    $settings['decrypt.log_skips'] = 1;
    capture_run($settings, capture_include($fixture));
    $line = file($log, FILE_IGNORE_NEW_LINES);
    $row = json_decode(substr($line[0], strpos($line[0], 'decrypt: ') + strlen('decrypt: ')), true);
    capture_check(count($line) === 2 && $row['reason'] === 'max_capture_bytes' && $row['max_capture_bytes'] === 1
        && $row['bytes'] === filesize($fixture), 'size skip logs include actual byte length and configured limit');
    $result = capture_run(capture_settings($project, $output),
        'echo ini_set("decrypt.exclude_paths","[]")===false && ini_set("decrypt.max_capture_bytes","1")===false'
        . ' && ini_set("decrypt.log_skips","1")===false ? "system" : "mutable";');
    capture_check($result === array(0, 'system', ''), 'filter options remain system-level INI settings');
} finally { capture_remove($root); }
?>
--EXPECT--
skip diagnostics preserve execution JSON state and user error handlers
skip logs escape filename newlines and deduplicate repeated file and eval sources
skip logging can be disabled
size skip logs include actual byte length and configured limit
filter options remain system-level INI settings
