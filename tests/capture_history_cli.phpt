--TEST--
Capture history lists index records and exports verified file and eval bytes
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR !== '/' || !extension_loaded('decrypt') || !function_exists('proc_open')) {
    die('skip POSIX, decrypt and proc_open required');
}
?>
--FILE--
<?php
require __DIR__ . '/helpers.inc';

function history_cli($output, $args) {
    $argv = array_merge(array('capture-history.php', $output), $args);
    $tool = dirname(__DIR__) . '/tools/capture-history.php';
    return capture_run(array(), '$argv=' . var_export($argv, true) . ';$argc=count($argv);require '
        . var_export($tool, true) . ';');
}

function export_record($output, $number, $destination) {
    return history_cli($output, array('export', '--record', (string) $number, '--output', $destination));
}

$root = realpath(sys_get_temp_dir()) . '/decrypt-history-cli-' . uniqid();
$project = $root . '/project';
$output = $root . '/capture';
$withoutHistory = $root . '/mirror-only';
mkdir($project . '/app', 0700, true);
mkdir($output);
mkdir($withoutHistory);
$relative = 'app/a\\b.php';
$source = $project . '/' . $relative;
$settings = capture_settings($project, $output);
$a = "<?php echo 'A'; ?>\0tail\n";
$b = "<?php echo 'B'; ?>\0tail\n";
try {
    file_put_contents($source, $a);
    capture_run($settings, capture_include($source));
    file_put_contents($project . '/other.php', '');
    capture_run($settings, capture_include($project . '/other.php'));
    foreach (array($b, $a) as $version) {
        file_put_contents($source, $version);
        capture_run($settings, capture_include($source));
    }
    $rows = capture_records($output);
    $result = history_cli($output, array('list', $relative));
    $expected = "RECORD\tCAPTURED_AT_UTC\tBYTES\tSHA256\n";
    foreach (array(1 => $a, 3 => $b, 4 => $a) as $line => $version) {
        $expected .= $line . "\t" . gmdate('Y-m-d\\TH:i:s\\Z', $rows[$line - 1]['captured_at']) . "\t"
            . strlen($version) . "\t" . hash('sha256', $version) . "\n";
    }
    capture_check($result === array(0, $expected, ''), 'list preserves A B A history and original index line numbers');
    $empty = $root . '/empty.php';
    $result = export_record($output, 2, $empty);
    capture_check($result[0] === 0 && file_get_contents($empty) === '', 'export accepts an empty capture');

    $destination = $root . '/version old.php';
    $index = file_get_contents($output . '/index.jsonl');
    $result = export_record($output, 3, $destination);
    capture_check($result[0] === 0 && file_get_contents($destination) === $b
        && file_get_contents($output . '/current/' . $relative) === $a
        && file_get_contents($output . '/index.jsonl') === $index, 'record export preserves original bytes and capture state');
    $result = export_record($output, 1, $destination);
    $dangling = $root . '/dangling.php';
    symlink($root . '/missing.php', $dangling);
    $linkResult = export_record($output, 1, $dangling);
    capture_check($result[0] === 1 && $linkResult[0] === 1 && is_link($dangling)
        && !file_exists($root . '/missing.php') && file_get_contents($destination) === $b,
        'export refuses existing destinations and dangling symlinks');

    unlink($output . '/index.jsonl');
    $byHash = $root . '/by-hash.php';
    $result = history_cli($output, array('export', '--sha256', hash('sha256', $b), '--output', $byHash));
    capture_check($result[0] === 0 && file_get_contents($byHash) === $b, 'full hash exports the object without an index');
    file_put_contents($output . '/index.jsonl', $index);

    $object = $output . '/' . $rows[2]['object'];
    file_put_contents($object, str_repeat('X', strlen($b)));
    $failed = $root . '/failed-export.php';
    $result = export_record($output, 3, $failed);
    capture_check($result[0] === 1 && strpos($result[2], 'SHA-256 mismatch') !== false
        && !file_exists($failed) && glob($root . '/.decrypt-export-*') === array(),
        'same-length object corruption leaves no destination or temporary export');

    $record = $rows[0];
    $record['object'] = '../../outside.php';
    file_put_contents($output . '/index.jsonl', json_encode($record) . "\n");
    $result = export_record($output, 1, $failed);
    capture_check($result[0] === 1 && strpos($result[2], 'Unexpected object path') !== false && !file_exists($failed),
        'record export refuses an object outside the store');

    $settings = capture_settings($project, $withoutHistory, array('decrypt.history' => 0));
    file_put_contents($source, $a);
    capture_run($settings, capture_include($source));
    file_put_contents($source, $b);
    capture_run($settings, capture_include($source));
    $result = export_record($withoutHistory, 1, $failed);
    $latest = $root . '/latest.php';
    $latestResult = export_record($withoutHistory, 2, $latest);
    capture_check($result[0] === 1 && !file_exists($failed) && $latestResult[0] === 0
        && file_get_contents($latest) === $b, 'records without objects require an exact matching mirror');

    $body = "return '片段';";
    file_put_contents($project . '/loader.php', '<?php eval(' . var_export($body, true) . ');');
    capture_run($settings, capture_include($project . '/loader.php'));
    $rows = capture_records($withoutHistory);
    $eval = $root . '/fragment.code';
    $result = export_record($withoutHistory, count($rows), $eval);
    capture_check(end($rows)['kind'] === 'eval' && $result[0] === 0 && file_get_contents($eval) === $body,
        'eval export preserves the original body without adding a PHP tag');
} finally {
    capture_remove($root);
}
?>
--EXPECT--
list preserves A B A history and original index line numbers
export accepts an empty capture
record export preserves original bytes and capture state
export refuses existing destinations and dangling symlinks
full hash exports the object without an index
same-length object corruption leaves no destination or temporary export
record export refuses an object outside the store
records without objects require an exact matching mirror
eval export preserves the original body without adding a PHP tag
