<?php
declare(strict_types=1);

/**
 * One registry for the optional 0.9.0 modules (App\Support\Features), derived
 * dynamically and checked everywhere it is repeated:
 *  - DEFAULTS (upgrade default = code fallback) and FRESH have the same keys;
 *  - ConfigStore::all() falls back to DEFAULTS and loadDatabaseSettings()
 *    maps every key (the allowlist trap: a saved switch never read back);
 *  - the five installer seeds carry FRESH, the migration DEFAULTS;
 *  - the settings tabs render a switch for each key, in exactly one tab;
 *  - every label and help text has a translation in the five locales;
 *  - an unknown key is an error, catalogue mode switches circulation off.
 *
 * Run: php tests/feature-toggles-registry.unit.php (the exit code is the verdict)
 */

use App\Support\Features;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$keys = Features::keys();
$check(count($keys) === 11, '11 optional modules (got ' . count($keys) . ')');
$check(array_keys(Features::FRESH) === $keys, 'FRESH lists the same keys as DEFAULTS, in the same order');
$check(array_diff(array_merge(Features::MODULES_TAB, Features::LOANS_TAB), $keys) === [] && count(Features::MODULES_TAB) + count(Features::LOANS_TAB) === count($keys)
    && array_intersect(Features::MODULES_TAB, Features::LOANS_TAB) === [], 'every module is saved by exactly one settings tab');
$check(array_diff(Features::CIRCULATION, $keys) === [], 'the circulation modules are known keys');
foreach ($keys as $k) {
    $check(in_array(Features::DEFAULTS[$k], ['0', '1'], true) && in_array(Features::FRESH[$k], ['0', '1'], true), "{$k}: defaults are '0'/'1'");
}
// Plan §0: off on an upgrade where the module adds sidebar entries, cataloguing fields, money or personal data.
$check(Features::DEFAULTS['stocktake'] === '0' && Features::DEFAULTS['suppliers'] === '0' && Features::DEFAULTS['rda_types'] === '0'
    && Features::DEFAULTS['visitor_log'] === '0' && Features::DEFAULTS['fines'] === '0', 'upgrade: stocktake, suppliers, RDA, visitor log and fines start off');
$check(Features::FRESH['visitor_log'] === '0' && Features::FRESH['fines'] === '0', 'fresh install: visitor log (personal data) and fines (money) stay opt-in');

// ConfigStore: defaults and allowlist.
$reflection = new ReflectionClass(\App\Support\ConfigStore::class);
$source = (string) file_get_contents((string) $reflection->getFileName());
$check(str_contains($source, "'features' => array_map(static fn (string \$v): bool => \$v === '1', Features::DEFAULTS)"), 'ConfigStore::all() defaults come from Features::DEFAULTS');
$method = $reflection->getMethod('loadDatabaseSettings');
$body = implode('', array_slice(file((string) $reflection->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
$check(str_contains($body, "\$raw['features']") && str_contains($body, 'array_keys(Features::DEFAULTS)'), 'loadDatabaseSettings() maps the features category for every key');

// Unknown keys fail loudly.
$threw = false;
try {
    Features::enabled('stocktak');
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check($threw, 'an unknown key throws instead of silently answering');
$threw = false;
try {
    new \App\Middleware\FeatureGateMiddleware('nope');
} catch (\InvalidArgumentException) {
    $threw = true;
}
$check($threw, 'FeatureGateMiddleware refuses an unknown key at boot');

// Overrides (test seam) and catalogue mode.
Features::override('fines', true);
$check(Features::enabled('fines') === true, 'override(true) switches a module on');
Features::override('fines', false);
$check(Features::enabled('fines') === false, 'override(false) switches it off');
Features::resetOverrides();

// Installer seeds: FRESH, one row per key, every locale.
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $seed = (string) file_get_contents("{$root}/installer/database/data_{$locale}.sql");
    preg_match_all("/\('features', '([a-z_]+)', '([01])',/", $seed, $m, PREG_SET_ORDER);
    $seeded = [];
    foreach ($m as $row) {
        $seeded[$row[1]] = $row[2];
    }
    $check($seeded === Features::FRESH, "data_{$locale}.sql seeds Features::FRESH");
}

// Migration: the static upgrade defaults; the data-dependent ones fall back to '0'.
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
$check(preg_match('/-- ── feature toggles ──(.*?)-- ── end feature toggles ──/s', $migration, $sec) === 1, 'migrate_0.9.0.sql has the feature toggles section');
$section = $sec[1] ?? '';
foreach ($keys as $k) {
    if (preg_match("/SELECT 'features', '{$k}', ('([01])'|@ft_val),/", $section, $mm) !== 1) {
        $check(false, "migration seeds {$k}");
        continue;
    }
    $value = $mm[1] === '@ft_val' ? '0' : $mm[2];
    $check($value === Features::DEFAULTS[$k], "migration seeds {$k} with the upgrade default " . Features::DEFAULTS[$k] . ($mm[1] === '@ft_val' ? ' (unless the library has data for it)' : ''));
}

// Settings views: a switch per key, in its own tab.
$modulesTab = (string) file_get_contents($root . '/app/Views/settings/modules-tab.php');
foreach (Features::MODULES_TAB as $k) {
    $check(str_contains($modulesTab, "'{$k}'"), "Settings → Modules renders {$k}");
}
$loansTab = (string) file_get_contents($root . '/app/Views/settings/loans-tab.php');
foreach (Features::LOANS_TAB as $k) {
    $check(str_contains($loansTab, "id=\"feature_{$k}\""), "Settings → Loans renders feature_{$k}");
}

// Labels and help: a translation in every locale.
$featuresSource = (string) file_get_contents($root . '/app/Support/Features.php');
preg_match_all("/=> __\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $featuresSource, $strings);
$strings = array_map(static fn (string $s): string => str_replace("\\'", "'", $s), $strings[1]);
$check(count($strings) === 2 * count($keys), 'Features defines a label and a help text per module');
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $catalogue = json_decode((string) file_get_contents("{$root}/locale/{$locale}.json"), true);
    $missing = array_values(array_filter($strings, static fn (string $s): bool => !isset($catalogue[$s]) || $catalogue[$s] === ''));
    $check($missing === [], "{$locale}: every label and help text is translated" . ($missing !== [] ? ' (missing: ' . $missing[0] . ')' : ''));
}
foreach ($keys as $k) {
    $check(Features::label($k) !== '' && Features::help($k) !== '', "{$k}: label() and help() answer");
}

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
