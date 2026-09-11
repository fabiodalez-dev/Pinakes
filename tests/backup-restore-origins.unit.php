<?php
declare(strict_types=1);

namespace App\Support {
    // Fail one selected operation regardless of uid/mode-bit enforcement.
    function unlink(string $path): bool
    {
        if ($path === ($GLOBALS['backupFailedUnlink'] ?? null)) {
            return false;
        }
        return \unlink($path);
    }
}
namespace {
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\BackupManager;

// Stop at the safety-backup boundary: exercise both restore entry points without
// importing SQL or touching application data. A failed safety backup must abort.
final class OriginRecordingBackupManager extends BackupManager
{
    public array $calls = [];

    public function createBackup(string $scope = 'full', string $origin = self::ORIGIN_AUTO): array
    {
        $this->calls[] = [$scope, $origin];
        return ['success' => false, 'name' => null, 'path' => null, 'size' => 0, 'error' => 'test stop'];
    }
}

$root = sys_get_temp_dir() . '/backup_origins_' . bin2hex(random_bytes(6));
mkdir($root . '/storage/backups', 0775, true);
$db = new mysqli(); // no connection is needed before the safety-backup boundary
$manager = new OriginRecordingBackupManager($db, $root);
$failed = 0;
$passed = 0;
$check = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};
$cleanup = static function (string $dir) use (&$cleanup): void {
    foreach (glob($dir . '/*') ?: [] as $p) {
        if (is_dir($p)) {
            chmod($p, 0755);
            $cleanup($p);
        } else {
            unlink($p);
        }
    }
    rmdir($dir);
};
try {
    $archive = $root . '/storage/backups/backup_2026-01-01_000000_abcdef.zip';
    $zip = new ZipArchive();
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('manifest.json', json_encode(['scope' => 'db', 'origin' => 'safety']));
    $zip->addFromString('database.sql', '-- never imported');
    $zip->close();
    $result = $manager->restoreFromBackup(basename($archive));
    $check(!$result['success'], 'stored restore aborts when its safety backup fails');
    $check($manager->calls === [['full', BackupManager::ORIGIN_SAFETY]], 'stored restore requests a full safety backup');

    $upload = $root . '/upload.zip';
    copy($archive, $upload);
    $result = $manager->restoreFromUploadedZip($upload, (int) filesize($upload));
    $check(!$result['success'], 'uploaded restore aborts when its safety backup fails');
    $check($manager->calls[1] === ['full', BackupManager::ORIGIN_SAFETY], 'uploaded restore requests a full safety backup');
    $uploads = glob($root . '/storage/backups/*_upload.zip') ?: [];
    $check(count($uploads) === 1, 'uploaded archive has its own preserved origin');
    $listed = array_column($manager->listBackups(), null, 'name');
    $check(($listed[basename($uploads[0] ?? '')]['origin'] ?? '') === BackupManager::ORIGIN_UPLOAD,
        'upload origin overrides the original manifest origin');

    $legacy = $root . '/storage/backups/update_2026-01-01_000000';
    mkdir($legacy);
    file_put_contents($legacy . '/database.sql', 'x');
    $GLOBALS['backupFailedUnlink'] = $legacy . '/database.sql';
    $result = $manager->deleteBackup(basename($legacy));
    $check(!$result['success'] && $result['error'] !== null, 'an injected recursive deletion failure is reported, including under root');
    $check(is_file($legacy . '/database.sql'), 'the failed child prevents removal of the legacy directory');
    unset($GLOBALS['backupFailedUnlink']);

    // Also exercise real mode-bit enforcement when the operating system applies
    // it. Running as root the bits are advisory and this cannot be provoked —
    // which is why it is a note and not a failed assertion: the injected failure
    // above already covers the requirement, uid and mode bits notwithstanding.
    chmod($legacy, 0555);
    if (!is_writable($legacy)) {
        $result = $manager->deleteBackup(basename($legacy));
        $check(!$result['success'] && $result['error'] !== null, 'failed recursive deletion is reported as failure');
        $check(is_file($legacy . '/database.sql'), 'failed deletion leaves the fixture available for retry');
    } else {
        // Indented on purpose: ci-run-unit-tests.sh reads 'SKIP:' at column 0,
        // and this is not a skipped requirement.
        echo "  note: mode bits are not enforced for this user; the requirement is covered by the injected failure above\n";
    }
    chmod($legacy, 0755);
    $result = $manager->deleteBackup(basename($legacy));
    $check($result['success'] && !is_dir($legacy), 'retry deletes the directory and its contents');
    $result = $manager->deleteBackup(basename($archive));
    $check($result['success'] && !is_file($archive), 'archive deletion succeeds and removes the file');
} finally {
    unset($GLOBALS['backupFailedUnlink']);
    $cleanup($root);
}
echo "Passed: $passed Failed: $failed\n";
exit($failed ? 1 : 0);
}
