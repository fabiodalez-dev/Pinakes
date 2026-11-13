<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — LibraryThing import staging.
 *
 * prepareImport() used to stage the admin's upload in the fixed, host-shared
 * sys_get_temp_dir()/librarything_imports (0755 dir, 0644 file, pre-existing
 * directory trusted as-is), and every chunk re-read whatever file sat at the
 * session-stored path. A temp-dir co-tenant could read the upload, or swap it
 * between chunks and rewrite existing catalogue records. The upload is now
 * staged in storage/tmp/librarything_imports (0700 dir, 0600 file) and bound to
 * the session by its SHA-256; a chunk on a changed or moved file is refused
 * before any row is processed.
 *
 * DB-free: prepareImport() needs no database, and processChunk() refuses a
 * tampered file before its first query (an unconnected mysqli is passed).
 *
 * Run:  php tests/security-audit-librarything-staging.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\LibraryThingImportController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$tsv = "Book Id\tTitle\tISBNs\tPrimary Author\n"
    . "900001\tAUDIT Original Title\t9780000000001\tAuthor One\n"
    . "900002\tAUDIT Second Title\t9780000000002\tAuthor Two\n";

$scratch = sys_get_temp_dir() . '/secaudit-lt-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);
$upload = $scratch . '/export.tsv';
file_put_contents($upload, $tsv);

$_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin']];
$staged = null;

$prepare = static function (string $path, string $name): Response {
    $file = new UploadedFile($path, $name, 'text/tab-separated-values', filesize($path) ?: 0, UPLOAD_ERR_OK, false);
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/books/import/librarything/prepare')
        ->withParsedBody([])
        ->withUploadedFiles(['tsv_file' => $file]);
    return (new LibraryThingImportController())->prepareImport($request, new Response());
};

$chunk = static function (string $importId): Response {
    $db = mysqli_init();
    $body = (new StreamFactory())->createStream((string) json_encode(['import_id' => $importId, 'start' => 0, 'size' => 5]));
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/books/import/librarything/chunk')
        ->withBody($body);
    return (new LibraryThingImportController())->processChunk($request, new Response(), $db);
};

try {
    echo "A. Staging location and permissions\n";
    $res = $prepare($upload, 'export.tsv');
    $json = json_decode((string) $res->getBody(), true);
    $check($res->getStatusCode() === 200 && ($json['success'] ?? false) === true, 'prepareImport accepts a LibraryThing TSV');
    $state = $_SESSION['librarything_import'] ?? [];
    $staged = (string) ($state['file_path'] ?? '');
    $stagingDir = $root . '/storage/tmp/librarything_imports';
    $check(str_starts_with($staged, $stagingDir . '/'), 'the upload is staged under storage/tmp/librarything_imports');
    $check(!str_starts_with($staged, sys_get_temp_dir()), 'not in the host-shared temp dir');
    $check(is_file($staged) && (fileperms($staged) & 0777) === 0600, 'staged file is 0600 (got ' . (is_file($staged) ? decoct(fileperms($staged) & 0777) : 'missing') . ')');
    $check(is_dir($stagingDir) && (fileperms($stagingDir) & 0777) === 0700, 'staging directory is 0700');
    if (function_exists('posix_geteuid')) {
        $check(fileowner($stagingDir) === posix_geteuid(), 'staging directory is owned by the PHP user');
    }

    echo "B. The staged file is bound to the session\n";
    $check(is_string($state['file_sha256'] ?? null) && hash_equals((string) $state['file_sha256'], (string) hash_file('sha256', $staged)),
        'the session records the SHA-256 of the staged file');
    $check(LibraryThingImportController::stagedFileIsIntact($staged, (string) ($state['file_sha256'] ?? '')),
        'an untouched staged file passes the integrity check');

    echo "C. A file swapped between requests is refused\n";
    unlink($staged);
    file_put_contents($staged, str_replace('AUDIT Original Title', 'AUDIT INJECTED TITLE', $tsv));
    $res = $chunk((string) $json['import_id']);
    $out = json_decode((string) $res->getBody(), true);
    $check($res->getStatusCode() === 409, 'chunk on a replaced file answers 409 (got ' . $res->getStatusCode() . ')');
    $check(($out['success'] ?? null) === false
        && ($out['error'] ?? '') === __('Il file da importare è stato modificato o non è più disponibile. Caricalo di nuovo.'),
        'with the translated "file changed" message');
    $check((int) ($_SESSION['librarything_import']['current_row'] ?? -1) === 0
        && (int) ($_SESSION['librarything_import']['updated'] ?? -1) === 0, 'no row was processed');

    echo "D. Paths outside the staging directory and symlinks are refused\n";
    $outside = $scratch . '/outside.tsv';
    file_put_contents($outside, $tsv);
    $check(!LibraryThingImportController::stagedFileIsIntact($outside, (string) hash_file('sha256', $outside)),
        'a matching file outside the staging dir is refused');
    $link = $stagingDir . '/secaudit-link-' . bin2hex(random_bytes(4)) . '.tsv';
    symlink($outside, $link);
    $check(!LibraryThingImportController::stagedFileIsIntact($link, (string) hash_file('sha256', $outside)),
        'a symlink inside the staging dir is refused');
    @unlink($link);
    $check(!LibraryThingImportController::stagedFileIsIntact($staged, ''), 'a session without a digest is refused');
} finally {
    if (is_string($staged) && $staged !== '' && is_file($staged)) {
        @unlink($staged);
    }
    @unlink($upload);
    @unlink($scratch . '/outside.tsv');
    @rmdir($scratch);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
