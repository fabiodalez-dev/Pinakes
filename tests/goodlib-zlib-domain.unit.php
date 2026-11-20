<?php
declare(strict_types=1);

/**
 * GoodLib: Z-Library moved from z-lib.gd to ur.1lib.sk.
 *
 * - the new default and the presets offered in both settings screens
 *   (plugin view and admin modal) agree, and z-lib.gd is no longer offered;
 * - an install that still has z-lib.gd stored (written on activation) links
 *   to ur.1lib.sk, while an operator's own mirror is left alone;
 * - the plugin version is bumped so existing installs pick up the files.
 *
 * Run: php tests/goodlib-zlib-domain.unit.php
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/goodlib/GoodLibPlugin.php';

$plugin = GoodLibPlugin::class;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        $passed++;
        echo "OK $label\n";
    } else {
        $failed++;
        echo "FAIL $label\n";
    }
};

$expected = ['ur.1lib.sk', 'z-lib.gl', 'z-lib.fm', '1lib.sk', 'z-library.ec', 'zliba.ru'];

$mirrors = $plugin::getMirrors()['zlib'];
$check($mirrors === $expected, 'the plugin offers ur.1lib.sk first, without z-lib.gd');
$check($plugin::resolveDomain('zlib_domain', null) === 'ur.1lib.sk', 'the default Z-Library domain is ur.1lib.sk');
$check($plugin::resolveDomain('zlib_domain', '') === 'ur.1lib.sk', 'an empty setting uses the default');
$check($plugin::resolveDomain('zlib_domain', 'z-lib.gd') === 'ur.1lib.sk', 'a stored z-lib.gd moves to ur.1lib.sk');
$check($plugin::resolveDomain('zlib_domain', ' Z-LIB.GD ') === 'ur.1lib.sk', 'the move ignores case and spaces');
$check($plugin::resolveDomain('zlib_domain', 'z-lib.fm') === 'z-lib.fm', 'another preset is kept');
$check($plugin::resolveDomain('zlib_domain', 'z-lib.custom.test:8443') === 'z-lib.custom.test:8443', 'a custom mirror is kept');
$check($plugin::resolveDomain('anna_domain', 'annas-archive.gl') === 'annas-archive.gl', "Anna's Archive is unaffected");
$check($plugin::resolveDomain('anna_domain', null) === 'annas-archive.gd', "Anna's Archive default unchanged");

$settingsView = (string) file_get_contents($root . '/storage/plugins/goodlib/views/settings.php');
preg_match('#<datalist id="zlib_domain_options">(.*?)</datalist>#s', $settingsView, $m);
preg_match_all('#<option value="([^"]+)"#', $m[1] ?? '', $opts);
$check($opts[1] === $expected, 'the plugin settings view lists the same presets');

$admin = (string) file_get_contents($root . '/app/Views/admin/plugins.php');
preg_match('#<select id="goodlib_zlib_domain_select".*?</select>#s', $admin, $sel);
preg_match_all('#<option value="([^"_]+)"#', $sel[0] ?? '', $adminOpts);
$check($adminOpts[1] === $expected, 'the admin modal lists the same presets');
$check(str_contains($admin, "zlib: ['" . implode("', '", $expected) . "'],"), 'the admin modal JS presets match');
$check(str_contains($admin, "btn.dataset.goodlibZlibDomain || 'ur.1lib.sk'"), 'the admin modal falls back to ur.1lib.sk');
$check(str_contains($admin, "\$goodlibZlibDomain === 'z-lib.gd'"), 'the admin modal shows ur.1lib.sk for a stored z-lib.gd');

$controller = (string) file_get_contents($root . '/app/Controllers/PluginController.php');
$check(str_contains($controller, "'zlib_domain' => 'ur.1lib.sk',"), 'the settings controller defaults to ur.1lib.sk');

$manifest = json_decode((string) file_get_contents($root . '/storage/plugins/goodlib/plugin.json'), true);
$check(is_array($manifest) && version_compare((string) ($manifest['version'] ?? '0'), '1.0.3', '>='), 'goodlib plugin version is at least 1.0.3');

if ($failed > 0) {
    echo "FAILED {$failed} of " . ($failed + $passed) . " GoodLib domain checks\n";
    exit(1);
}
echo "SUCCESS {$passed} GoodLib domain checks\n";
