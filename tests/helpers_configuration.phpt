--TEST--
Capture subprocess inherits the test harness INI and loaded extension settings
--SKIPIF--
<?php
if (!extension_loaded('decrypt') || !function_exists('proc_open')) die('skip decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$original = getenv('TEST_PHP_EXTRA_ARGS');
$modules = get_loaded_extensions(); sort($modules);
try {
    putenv('TEST_PHP_EXTRA_ARGS=' . $original . ' -d precision=12');
    $result = capture_run(array(), '$modules=get_loaded_extensions();sort($modules);echo ini_get("precision"),"|",implode(",",$modules);');
    capture_check($result === array(0, '12|' . implode(',', $modules), ''),
        'child preserves test harness INI and extension configuration');
} finally {
    if ($original === false) putenv('TEST_PHP_EXTRA_ARGS');
    else putenv('TEST_PHP_EXTRA_ARGS=' . $original);
}
?>
--EXPECT--
child preserves test harness INI and extension configuration
