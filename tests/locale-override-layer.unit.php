<?php
declare(strict_types=1);

/**
 * Behavioural test for the locale override layer (docker walkthrough @48 / Option B).
 *
 * The Docker image re-seeds the shipped locale/*.json on every boot, so an in-app
 * edit written straight to locale/{code}.json is silently reverted on restart.
 * Fix: admin edits are written to a NON-reseeded locale/overrides/{code}.json, and
 * I18n::loadTranslations() merges that override on top of the shipped base (override
 * wins; keys shipped only in a later base image still flow through).
 *
 * This test drives the real I18n loader against sandbox base + override files via
 * reflection (setLocale() validates against installed languages, which a throwaway
 * code isn't), and asserts the source-level invariants of the editor write/delete.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\I18n;

$checks = [];

$localeDir = $root . '/locale';
$overrideDir = $localeDir . '/overrides';
$code = 'zz_ZZ';
$baseFile = "$localeDir/$code.json";
$overrideFile = "$overrideDir/$code.json";
$overrideDirPreexisted = is_dir($overrideDir);

// --- sandbox base + override ---
if (!$overrideDirPreexisted) {
    mkdir($overrideDir, 0755, true);
}
file_put_contents($baseFile, json_encode([
    'ovl_shared'    => 'BASE shared',
    'ovl_base_only' => 'BASE only',
], JSON_UNESCAPED_UNICODE));
file_put_contents($overrideFile, json_encode([
    'ovl_shared'        => 'OVERRIDE shared',
    'ovl_override_only' => 'OVERRIDE only',
], JSON_UNESCAPED_UNICODE));

// --- force I18n onto the sandbox locale, bypassing setLocale() validation ---
$ref = new ReflectionClass(I18n::class);
$setStatic = static function (string $prop, $value) use ($ref): void {
    $p = $ref->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue(null, $value);
};
$reload = static function () use ($setStatic, $code): void {
    $setStatic('locale', $code);
    $setStatic('translations', []);
    $setStatic('translationsLoaded', false);
};

try {
    $reload();
    $checks['override wins on a shared key'] = I18n::translate('ovl_shared') === 'OVERRIDE shared';
    $checks['base-only key comes from the base'] = I18n::translate('ovl_base_only') === 'BASE only';
    $checks['override-only key is applied'] = I18n::translate('ovl_override_only') === 'OVERRIDE only';

    // Remove the override: the base is untouched (it never held the edit), so the
    // app falls back to the shipped value — this is exactly the state a re-seed
    // leaves, proving the edit lived in the override, not the reseeded base.
    unlink($overrideFile);
    $reload();
    $checks['without override, base value shows'] = I18n::translate('ovl_shared') === 'BASE shared';
    $checks['without override, override-only key is gone'] = I18n::translate('ovl_override_only') === 'ovl_override_only';
} finally {
    @unlink($baseFile);
    @unlink($overrideFile);
    if (!$overrideDirPreexisted && is_dir($overrideDir)) {
        @rmdir($overrideDir);
    }
    // Reset I18n so the throwaway locale never leaks into other tests.
    $setStatic('locale', 'it_IT');
    $setStatic('translations', []);
    $setStatic('translationsLoaded', false);
}

// --- source-level invariants of the editor ---
$i18nSrc = (string) file_get_contents($root . '/app/Support/I18n.php');
$checks['loader reads the overrides/ subdirectory'] =
    str_contains($i18nSrc, "'overrides/' . \$locale") && str_contains($i18nSrc, 'array_merge($translations');

$lcSrc = (string) file_get_contents($root . '/app/Controllers/Admin/LanguagesController.php');
$checks['getOverrideFilePath helper exists'] = str_contains($lcSrc, 'function getOverrideFilePath');
$checks['editor writes to the override path, not the base'] =
    str_contains($lcSrc, '$targetPath = $this->getOverrideFilePath($code);');
$checks['delete removes base AND override'] =
    str_contains($lcSrc, '$this->getLocaleFilePath($code), $this->getOverrideFilePath($code)');
$checks['download serves the effective (merged) translations'] =
    str_contains($lcSrc, '$translations = $this->getEffectiveTranslations($code);');

// --- report ---
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failed++;
    }
}
echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
