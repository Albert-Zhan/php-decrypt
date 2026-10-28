--TEST--
Capture preserves source bytes and configuration, and handles output failures
--SKIPIF--
<?php
if (!extension_loaded('decrypt')) die('skip decrypt extension is not loaded');
if (!function_exists('proc_open')) die('skip proc_open is required');
?>
--FILE--
<?php
$extension = getenv('DECRYPT_EXTENSION');
if (!$extension) {
    $extension = dirname(__DIR__) . '/modules/'
        . (DIRECTORY_SEPARATOR === '\\' ? 'php_decrypt.dll' : 'decrypt.' . PHP_SHLIB_SUFFIX);
}
$root = sys_get_temp_dir() . '/decrypt-test-' . uniqid();
mkdir($root);
mkdir($root . '/output with spaces');
$output = $root . '/output with spaces';
$capture = $output . '/decrypt.code';
$fixture = $root . '/source.php';
$source = "<?php echo 'ok'; ?>\0tail";
file_put_contents($fixture, $source);

function run_capture($settings, $code) {
    global $extension;
    $command = escapeshellarg(getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY)
        . ' -n -d ' . escapeshellarg('extension=' . $extension)
        . ' -d display_errors=stderr -d log_errors=0';
    foreach ($settings as $key => $value) {
        $command .= ' -d ' . escapeshellarg($key . '=' . $value);
    }
    $command .= ' -r ' . escapeshellarg($code);
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new Exception('Unable to start PHP');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($process), $stdout, $stderr);
}
function check_capture($condition, $label) {
    if (!$condition) throw new Exception($label);
    echo $label, "\n";
}
function remove_capture_tree($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') remove_capture_tree($path . '/' . $name);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

try {
    $include = 'require ' . var_export($fixture, true) . ';';
    foreach (array(null, 'disable') as $switch) {
        $settings = array('decrypt.save_path' => $output);
        if ($switch !== null) $settings['decrypt.switch'] = $switch;
        $result = run_capture($settings, $include);
        check_capture($result === array(0, "ok\0tail", '') && !file_exists($capture),
            $switch === null ? 'disabled by default' : 'explicitly disabled');
    }

    $settings = array('decrypt.switch' => 'enable', 'decrypt.save_path' => $output);
    $result = run_capture($settings, $include . $include
        . 'echo ini_get("decrypt.save_path");');
    $record = $fixture . PHP_EOL . $source . PHP_EOL;
    check_capture($result === array(0, "ok\0tailok\0tail" . $output, '')
        && file_get_contents($capture) === $record . $record,
        'repeated includes preserve bytes and INI path');
    unlink($capture);

    file_put_contents($fixture, '');
    $result = run_capture($settings, $include . 'echo "done";');
    check_capture($result === array(0, 'done', '')
        && file_get_contents($capture) === $fixture . PHP_EOL . PHP_EOL, 'empty source');
    unlink($capture);
    file_put_contents($fixture, "<?php echo 'ok';");

    foreach (array('' => 'missing setting', $root . '/missing' => 'missing directory',
        $fixture => 'regular file as directory') as $path => $label) {
        $settings['decrypt.save_path'] = $path;
        $result = run_capture($settings, $include);
        check_capture($result[0] === 0 && $result[1] === 'ok'
            && strpos($result[2], $path === '' ? 'not configured' : 'Unable to open capture file') !== false,
            $label . ' continues execution');
    }

    $settings['decrypt.save_path'] = $output;
    mkdir($capture);
    $result = run_capture($settings, $include);
    check_capture($result[0] === 0 && $result[1] === 'ok'
        && strpos($result[2], 'Unable to open capture file') !== false,
        'unopenable destination continues execution');
    rmdir($capture);

    mkdir($root . '/allowed');
    $allowed = $root . '/allowed/source.php';
    file_put_contents($allowed, "<?php echo 'ok';");
    $settings['open_basedir'] = $root . '/allowed';
    $result = run_capture($settings, 'require ' . var_export($allowed, true) . ';');
    check_capture($result[0] === 0 && $result[1] === 'ok'
        && strpos($result[2], 'open_basedir restriction') !== false && !file_exists($capture),
        'open_basedir is respected');
} finally {
    remove_capture_tree($root);
}
?>
--EXPECT--
disabled by default
explicitly disabled
repeated includes preserve bytes and INI path
empty source
missing setting continues execution
missing directory continues execution
regular file as directory continues execution
unopenable destination continues execution
open_basedir is respected
