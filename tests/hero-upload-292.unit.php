<?php
declare(strict_types=1);

/**
 * Issue #292 — deterministic guard for the silent-upload-failure fix.
 *
 * A hero photo bigger than upload_max_filesize reaches the app with a non-OK
 * $_FILES error code. The old code only ran on UPLOAD_ERR_OK, so the failure
 * fell through silently and the page reported "saved" with no image. The fix
 * maps every error code to a user-facing message via
 * CmsController::heroUploadErrorMessage().
 *
 * The E2E can only exercise the real INI_SIZE path where
 * upload_max_filesize < post_max_size, which not every environment has — so this
 * unit test pins the mapping directly, independent of php.ini.
 *
 * Run: php tests/hero-upload-292.unit.php   (exit 0 iff all pass)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\CmsController;

$passed = 0;
$failed = 0;
$check = static function (bool $cond, string $label) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  OK  {$label}\n"; return; }
    $failed++; echo "  FAIL {$label}\n";
};

// No file / successful upload → nothing to report.
$check(CmsController::heroUploadErrorMessage(UPLOAD_ERR_OK) === null, 'UPLOAD_ERR_OK → null (no error)');
$check(CmsController::heroUploadErrorMessage(UPLOAD_ERR_NO_FILE) === null, 'UPLOAD_ERR_NO_FILE → null (no error)');

// The regression that started #292: an oversized upload must NOT be silent.
$ini = CmsController::heroUploadErrorMessage(UPLOAD_ERR_INI_SIZE);
$check($ini !== null, 'UPLOAD_ERR_INI_SIZE → a message (NOT silent — the core #292 fix)');
$check(is_string($ini) && str_contains($ini, 'supera il limite'), 'INI_SIZE message mentions the size limit');
$check(is_string($ini) && str_contains($ini, 'upload_max_filesize'), 'INI_SIZE message tells the admin which php.ini keys to raise');

$check(CmsController::heroUploadErrorMessage(UPLOAD_ERR_FORM_SIZE) === $ini, 'UPLOAD_ERR_FORM_SIZE → same size message');

$partial = CmsController::heroUploadErrorMessage(UPLOAD_ERR_PARTIAL);
$check($partial !== null && str_contains($partial, 'interrotto'), 'UPLOAD_ERR_PARTIAL → "interrotto" message');

$noTmp = CmsController::heroUploadErrorMessage(UPLOAD_ERR_NO_TMP_DIR);
$check($noTmp !== null && str_contains($noTmp, 'temporanea'), 'UPLOAD_ERR_NO_TMP_DIR → tmp-dir message');

$cantWrite = CmsController::heroUploadErrorMessage(UPLOAD_ERR_CANT_WRITE);
$check($cantWrite !== null && str_contains($cantWrite, 'scrivere'), 'UPLOAD_ERR_CANT_WRITE → write-permission message');

// Any unknown/future code must still surface *something* (never silent), and
// echo the code so an admin can look it up.
$unknown = CmsController::heroUploadErrorMessage(99);
$check($unknown !== null && str_contains($unknown, '99'), 'unknown code (99) → non-null message that includes the code');

// Belt-and-braces: every non-OK, non-NO_FILE code returns non-null.
foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, 7, 8, 99] as $code) {
    $check(CmsController::heroUploadErrorMessage($code) !== null, "error code {$code} is never silent");
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
