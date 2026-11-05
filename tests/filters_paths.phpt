--TEST--
Literal project-relative exclusions respect path boundaries and eval origins
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR !== '/' || !extension_loaded('decrypt') || !function_exists('proc_open')) die('skip POSIX, decrypt and proc_open required');
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';
$root = realpath(sys_get_temp_dir()) . '/decrypt-filter-path-' . uniqid();
$project = $root . '/project'; $output = $root . '/output';
mkdir($root); mkdir($project); mkdir($output);
foreach (array('vendor', 'vendor-old', 'src', 'space,目录', "quote'name", 'back\\slash') as $directory) mkdir($project . '/' . $directory);
$sources = array('vendor/loader.php' => '<?php echo "V"; eval(\'echo "E";\');',
    'vendor-old/ok.php' => '<?php echo "O";', 'src/private.php' => '<?php echo "P";',
    'src/private.php-other' => '<?php echo "Q";', 'space,目录/a.php' => '<?php echo "D";',
    "quote'name/a.php" => '<?php echo "A";', 'back\\slash/a.php' => '<?php echo "B";');
foreach ($sources as $relative => $source) file_put_contents($project . '/' . $relative, $source);
$rules = array('vendor/', 'src/private.php', 'space,目录', "quote'name/", 'back\\slash/');
// PHP CLI -d consumes INI syntax; the outer raw quotes preserve JSON quotes.
$settings = capture_settings($project, $output, array('decrypt.exclude_paths' => "'" . json_encode($rules, JSON_HEX_APOS) . "'"));
try {
    $code = '';
    foreach ($sources as $relative => $source) $code .= capture_include($project . '/' . $relative);
    $result = capture_run($settings, $code);
    $rows = capture_records($output);
    capture_check($result === array(0, 'VEOPQDAB', '') && count($rows) === 2
        && is_file($output . '/current/vendor-old/ok.php') && is_file($output . '/current/src/private.php-other')
        && !is_dir($output . '/current/vendor') && !is_dir($output . '/eval'),
        'exclusions preserve execution and match complete path segments including eval origins');
    capture_check(!is_dir($output . '/current/space,目录') && !is_dir($output . "/current/quote'name")
        && !is_dir($output . '/current/back\\slash'), 'JSON rules preserve commas, Unicode, quotes and POSIX backslashes');

    $fixture = $project . '/src/private.php';
    capture_run(capture_settings($project, $output), capture_include($fixture));
    $before = file_get_contents($output . '/index.jsonl');
    $old = file_get_contents($output . '/current/src/private.php');
    file_put_contents($fixture, '<?php echo "new";');
    $result = capture_run($settings, capture_include($fixture));
    capture_check($result === array(0, 'new', '') && file_get_contents($output . '/index.jsonl') === $before
        && file_get_contents($output . '/current/src/private.php') === $old,
        'new exclusions retain existing mirrors and version state');
    capture_run(capture_settings($project, $output), capture_include($fixture));
    capture_check(file_get_contents($output . '/current/src/private.php') === '<?php echo "new";'
        && count(capture_records($output)) === 4, 'removing exclusions resumes normal publication');

    $badOutput = $root . '/invalid'; mkdir($badOutput);
    foreach (array('[', '{}', 'null', '[1]', '[""]', '["."]', '["../outside"]', '["/absolute"]',
                   '["a//b"]', '["a/../b"]', '["a\\u0000b"]') as $bad) {
        $badSettings = capture_settings($project, $badOutput, array('decrypt.exclude_paths' => "'" . $bad . "'"));
        $result = capture_run($badSettings, capture_include($fixture) . capture_include($fixture));
        if ($result[0] !== 0 || $result[1] !== 'newnew' || substr_count($result[2], 'decrypt.exclude_paths') !== 1
            || count(capture_files($badOutput)) !== 0) throw new Exception('Invalid exclusion accepted: ' . $bad);
    }
    capture_check(true, 'invalid exclusion configurations warn once and never publish captures');
} finally { capture_remove($root); }
?>
--EXPECT--
exclusions preserve execution and match complete path segments including eval origins
JSON rules preserve commas, Unicode, quotes and POSIX backslashes
new exclusions retain existing mirrors and version state
removing exclusions resumes normal publication
invalid exclusion configurations warn once and never publish captures
