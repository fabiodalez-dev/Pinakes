<?php
declare(strict_types=1);

/**
 * Backend unit tests — archival_unit_files multi-document support (v0.7.4+).
 *
 * 25 assertions covering:
 *  - DDL structure of the archival_unit_files table
 *  - ensureSchema() registration of the new table
 *  - ArchivesPlugin source-level checks for handleAssetUpload (document branch)
 *  - removeFileAction ownership-check path
 *  - fetchUnitFiles return-type contract
 *  - IIIF manifest multi-Canvas and rendering[] logic
 *  - METS fileGrp USE="documents" generation
 *  - EAD3 daoset multi-dao generation
 *  - Admin view receives $unit_files variable
 *  - Public view backward-compat: first-file → legacy $docUrl
 *  - i18n strings for multi-file UI present in it_IT, en_US, de_DE locales
 *
 * Run:
 *   php tests/archives-unit-files.unit.php
 * Exits 0 on success, non-zero on any failure.
 */

require_once __DIR__ . '/../app/Support/Hooks.php';
require_once __DIR__ . '/../app/Support/HookManager.php';
require_once __DIR__ . '/../app/Support/ConfigStore.php';
require_once __DIR__ . '/../app/Support/SecureLogger.php';
require_once __DIR__ . '/../storage/plugins/archives/ArchivesPlugin.php';

use App\Plugins\Archives\ArchivesPlugin;

$failed = 0;
$passed = 0;

$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        ++$passed;
        echo "  OK  $label\n";
    } else {
        ++$failed;
        echo "  FAIL $label\n";
    }
};

// ── DDL shape ─────────────────────────────────────────────────────────────────
echo "1. DDL — archival_unit_files:\n";
$ddlFiles = ArchivesPlugin::ddlArchivalUnitFiles();

$check(
    str_contains($ddlFiles, 'CREATE TABLE IF NOT EXISTS archival_unit_files'),
    '01. CREATE TABLE IF NOT EXISTS (idempotent guard)'
);
$check(
    str_contains($ddlFiles, 'unit_id'),
    '02. unit_id FK column present'
);
$check(
    str_contains($ddlFiles, 'file_path'),
    '03. file_path VARCHAR column present'
);
$check(
    str_contains($ddlFiles, 'file_mime'),
    '04. file_mime VARCHAR column present'
);
$check(
    str_contains($ddlFiles, 'original_filename'),
    '05. original_filename VARCHAR column present'
);
$check(
    str_contains($ddlFiles, 'sort_order'),
    '06. sort_order SMALLINT column present'
);
$check(
    str_contains($ddlFiles, 'ON DELETE CASCADE'),
    '07. CASCADE delete: removing archival_unit removes its files'
);
$check(
    str_contains($ddlFiles, 'KEY idx_unit_id'),
    '08. idx_unit_id index for fast file lookup by unit'
);
$check(
    str_contains($ddlFiles, 'ENGINE=InnoDB'),
    '09. InnoDB engine for FK integrity'
);
$check(
    str_contains($ddlFiles, 'utf8mb4'),
    '10. utf8mb4 charset for filenames with non-ASCII characters'
);

// ── ensureSchema() registration ───────────────────────────────────────────────
echo "\n11-12. ensureSchema() registers archival_unit_files:\n";
$src = (string) file_get_contents(__DIR__ . '/../storage/plugins/archives/ArchivesPlugin.php');
$check(
    str_contains($src, "'archival_unit_files'") && str_contains($src, 'ddlArchivalUnitFiles()'),
    '11. ensureSchema() steps array contains archival_unit_files => ddlArchivalUnitFiles()'
);
// Extract ensureSchema() body using brace-balancing — avoids fragile regex
// that fails when the function body is long or contains nested closures.
$ensureSchemaBody = '';
$funcStart = strpos($src, 'function ensureSchema');
if ($funcStart !== false) {
    $braceStart = strpos($src, '{', $funcStart);
    if ($braceStart !== false) {
        $depth = 0;
        $len   = strlen($src);
        for ($i = $braceStart; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $ensureSchemaBody = substr($src, $braceStart, $i - $braceStart + 1);
                    break;
                }
            }
        }
    }
}
$check(
    $ensureSchemaBody !== '' && str_contains($ensureSchemaBody, 'archival_unit_files'),
    '12. ddlArchivalUnitFiles() is called inside ensureSchema()'
);

// ── handleAssetUpload() document branch ──────────────────────────────────────
echo "\n13-16. handleAssetUpload() document branch → archival_unit_files:\n";
$check(
    str_contains($src, 'INSERT INTO archival_unit_files'),
    '13. document upload inserts into archival_unit_files (not UPDATE archival_units)'
);
$check(
    str_contains($src, 'COALESCE((SELECT MAX(sort_order) FROM archival_unit_files WHERE unit_id = ?), -1) + 1'),
    '14. sort_order auto-increments via MAX() subquery'
);
$check(
    str_contains($src, "bind_param('isssi', \$id, \$relPath"),
    '15. document INSERT binds (unit_id, path, mime, filename, unit_id) — 5 params'
);
$check(
    str_contains($src, "\$key = \$kind === 'cover' ? 'cover' : 'document'"),
    '16. file input key is "document" for the document upload branch'
);

// ── removeFileAction() ownership check ───────────────────────────────────────
echo "\n17-18. removeFileAction() ownership check:\n";
$check(
    str_contains($src, 'SELECT file_path FROM archival_unit_files WHERE id = ? AND unit_id = ?'),
    '17. removeFileAction() verifies file belongs to the given unit before deleting'
);
$check(
    str_contains($src, "str_starts_with(\$filePath, '/uploads/archives/documents/')"),
    '18. removeFileAction() only unlinks files under /uploads/archives/documents/'
);

// ── IIIF multi-Canvas and rendering[] ────────────────────────────────────────
echo "\n19-20. iiifManifestAction() multi-document logic:\n";
$check(
    (bool) preg_match('/\$unitFiles\s+=\s+\$this->fetchUnitFiles\(\$id\)/', $src),
    '19. iiifManifestAction() fetches unit files for Canvases and rendering'
);
$check(
    str_contains($src, "\$manifest['rendering'] = \$rendering"),
    '20. non-image files are added to manifest rendering[] array'
);

// ── METS fileGrp USE="documents" ─────────────────────────────────────────────
echo "\n21-22. buildMetsXml() fileGrp documents:\n";
$check(
    str_contains($src, "USE', 'documents'"),
    '21. buildMetsXml() emits fileGrp USE="documents" for multi-document files'
);
$check(
    str_contains($src, "\$docFileEntries[] = ['id' => \$fileId, 'label' => \$docLabel]"),
    '22. buildMetsXml() collects file entries (id+label) for structMap fptr references'
);

// ── EAD3 daoset multi-dao ─────────────────────────────────────────────────────
echo "\n23. writeEad3Document() multi-dao:\n";
$check(
    str_contains($src, '$filesToEmit = $unitFiles ?? $this->fetchUnitFiles($unitId)') &&
    str_contains($src, 'foreach ($filesToEmit as $uf)'),
    '23. writeEad3Document() loops archival_unit_files to emit one <dao> per file (with pre-fetch fallback)'
);

// ── View: $unit_files passed to admin and public show views ──────────────────
echo "\n24. showAction() and publicShowAction() pass unit_files to views:\n";
$check(
    (bool) preg_match("/'unit_files'\s+=>\s+\\\$this->fetchUnitFiles\(\\\$id\)/", $src) &&
    substr_count($src, '$this->fetchUnitFiles($id)') >= 2,
    '24. Both showAction() and publicShowAction() pass unit_files to their view'
);

// ── i18n: new multi-file strings in all three locales ────────────────────────
echo "\n25. i18n strings for multi-file UI:\n";
// All locales use Italian strings as keys; values are locale-specific translations.
// The check verifies that each key exists in every locale file AND its value is non-empty.
$itData = (array) json_decode((string) file_get_contents(__DIR__ . '/../locale/it_IT.json'), true);
$enData = (array) json_decode((string) file_get_contents(__DIR__ . '/../locale/en_US.json'), true);
$deData = (array) json_decode((string) file_get_contents(__DIR__ . '/../locale/de_DE.json'), true);
$i18nKeys = ['Documenti scaricabili', 'Rimuovere questo file?', 'Aggiungi documento'];
$i18nOk = true;
foreach ($i18nKeys as $k) {
    foreach (['it' => $itData, 'en' => $enData, 'de' => $deData] as $locale => $data) {
        if (!array_key_exists($k, $data) || trim((string) $data[$k]) === '') {
            $i18nOk = false;
            echo "  MISSING or empty: '$k' in $locale\n";
        }
    }
}
$check(
    $i18nOk,
    '25. "Documenti scaricabili", "Rimuovere questo file?", "Aggiungi documento" present with non-empty values in it/en/de locales'
);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n--- archival_unit_files backend unit tests ---\n";
echo "Passed: $passed / " . ($passed + $failed) . "\n";
if ($failed > 0) {
    echo "Failed: $failed\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
