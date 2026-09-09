<?php
/**
 * Versioned index file handling
 *
 * The index filename carries its version so the URL can be treated as
 * immutable. That brings three things worth pinning down: the upgrade path
 * for installs whose version option predates versioned filenames, atomic
 * writes so a visitor cannot read a half-written index, and pruning that
 * removes only superseded index files.
 *
 * Stubs the WordPress functions the plugin touches at load time, then runs
 * the real helpers against a temporary uploads directory.
 *
 * Usage: php tests/index-files.test.php
 */

define('ABSPATH', __DIR__);

$GLOBALS['vs_upload_dir'] = sys_get_temp_dir() . '/vital-search-test-' . getmypid();
mkdir($GLOBALS['vs_upload_dir'], 0777, true);

$GLOBALS['vs_options'] = [];

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/vital-search/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function get_option($key, $default = false) { return $GLOBALS['vs_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['vs_options'][$key] = $value; return true; }
function wp_upload_dir() {
    return [
        'basedir' => $GLOBALS['vs_upload_dir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
    ];
}

require __DIR__ . '/../vital-search.php';

$passed = 0;
$failed = 0;

/**
 * Record a check result
 *
 * @param string $name What is being asserted
 * @param bool $condition Whether it holds
 */
function check($name, $condition) {
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $name . "\n";
}

$uploads = $GLOBALS['vs_upload_dir'];
$base_url = 'https://example.test/wp-content/uploads';

// --- filename and version round trip ---
check('filename carries the version',
    vital_search_get_index_filename(1700000000) === 'search-index-1700000000.json');
check('version parses back out of the filename',
    vital_search_version_from_filename('/x/search-index-1700000000.json') === 1700000000);
check('legacy filename yields no version',
    vital_search_version_from_filename('/x/search-index.json') === 0);
check('unrelated filename yields no version',
    vital_search_version_from_filename('/x/other-1234.json') === 0);

// --- URL selection, including the upgrade path ---
$GLOBALS['vs_options'] = [];
check('falls back to the legacy URL on a fresh install',
    vital_search_get_index_url() === $base_url . '/search-index.json');

// An install upgraded from before versioned filenames has a version option
// but no versioned file on disk, so it must stay on the legacy path until
// the next export writes one. Otherwise search 404s across the deploy.
$GLOBALS['vs_options'] = ['vital_search_version' => 1788951565];
check('falls back to the legacy URL when upgrading an existing install',
    vital_search_get_index_url() === $base_url . '/search-index.json');

$GLOBALS['vs_options']['vital_search_index_file'] = 'search-index-1700000000.json';
check('uses the versioned URL once an export has written one',
    vital_search_get_index_url() === $base_url . '/search-index-1700000000.json');

// --- atomic write ---
$target = $uploads . '/atomic.json';
$bytes = vital_search_write_file_atomic($target, '{"a":1}');
check('atomic write reports bytes written', $bytes === 7);
check('atomic write lands the content', file_get_contents($target) === '{"a":1}');
check('atomic write leaves no temp file behind', !file_exists($target . '.tmp'));

// --- pruning ---
foreach ([100, 200, 300, 400, 500] as $version) {
    file_put_contents($uploads . '/' . vital_search_get_index_filename($version), 'x');
}
file_put_contents($uploads . '/search-index.json', 'legacy');
file_put_contents($uploads . '/unrelated.json', 'keep me');

vital_search_prune_old_indexes(3);

$remaining = array_map('basename', glob($uploads . '/search-index-*.json'));
sort($remaining);
check('pruning keeps the newest three', $remaining === [
    'search-index-300.json',
    'search-index-400.json',
    'search-index-500.json',
]);
check('pruning leaves the legacy file alone', file_exists($uploads . '/search-index.json'));
check('pruning leaves unrelated files alone', file_exists($uploads . '/unrelated.json'));

vital_search_prune_old_indexes(3);
check('pruning is safe to repeat', count(glob($uploads . '/search-index-*.json')) === 3);

// --- cleanup ---
array_map('unlink', glob($uploads . '/*'));
rmdir($uploads);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
