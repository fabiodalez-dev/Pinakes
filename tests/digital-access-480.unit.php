<?php
declare(strict_types=1);

/**
 * Digital Library files: who may open them, and the move out of the web root (issue #480).
 *
 *   A. DigitalStorage moves the old files from public/uploads/digital/ into
 *      storage/digital/ safely and idempotently (scratch folders, never the real ones):
 *      rename, identical leftover, conflicting leftover kept, lock, marker that reopens
 *      when a file is put back, folder and file modes, both folders shut by .htaccess.
 *   B. DigitalAccess answers per visibility and reader (anonymous, reader, borrower,
 *      staff, admin), follows the library default for `inherit`, ignores soft-deleted
 *      records, keeps an unlisted file staff only, trusts its index only while it is
 *      right, and signs links that expire, name one file and one active reader.
 *   C. ProtectedUploadController serves a real file in storage/digital/: 200, login
 *      redirect, 403, signed 200, Range 206, 304, HEAD, download disposition.
 *
 * Runs against the installation's database, scoped to the rows it inserts and deletes.
 *
 * Run:  php tests/digital-access-480.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}
if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return $path;
    }
}

use App\Support\DigitalAccess as Access;
use App\Support\DigitalStorage as Storage;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** Delete a scratch tree in PHP (no shell); a folder a test made read-only is opened first. */
$removeTree = static function (string $dir) use (&$removeTree): void {
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    @chmod($dir, 0755);
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
};

// ── A. Moving the old files ─────────────────────────────────────────────────
echo "A. DigitalStorage::migrate\n";
$scratch = sys_get_temp_dir() . '/pinakes-480-' . bin2hex(random_bytes(4));
$legacy = $scratch . '/public/uploads/digital';
$target = $scratch . '/storage/digital';
mkdir($legacy, 0755, true);
file_put_contents($legacy . '/20260101000000_aaaa.pdf', '%PDF-1.4 one');
file_put_contents($legacy . '/20260101000000_bbbb.mp3', str_repeat('b', 4096));
file_put_contents($legacy . '/.htaccess', "Options -Indexes\n");
file_put_contents($legacy . '/index.html', '');
// Leftovers of an interrupted earlier run: one identical, one with different bytes.
mkdir($target, 0775, true);
file_put_contents($target . '/20260101000000_same.pdf', 'same bytes');
file_put_contents($legacy . '/20260101000000_same.pdf', 'same bytes');
file_put_contents($target . '/20260101000000_clash.pdf', 'the stored one');
file_put_contents($legacy . '/20260101000000_clash.pdf', 'a different one');

$first = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($first === ['moved' => 3, 'remaining' => 0, 'failed' => 1], 'three files move (two renamed, one identical leftover settled), the clash is refused: ' . json_encode($first));
$check(is_file($target . '/20260101000000_aaaa.pdf') && !is_file($legacy . '/20260101000000_aaaa.pdf')
    && file_get_contents($target . '/20260101000000_aaaa.pdf') === '%PDF-1.4 one', 'a renamed file keeps its bytes and leaves the old folder');
$check(!is_file($legacy . '/20260101000000_same.pdf') && file_get_contents($target . '/20260101000000_same.pdf') === 'same bytes', 'an identical leftover from an interrupted run only costs the original');
$check(file_get_contents($target . '/20260101000000_clash.pdf') === 'the stored one' && file_get_contents($legacy . '/20260101000000_clash.pdf') === 'a different one',
    'a different file with the same name is never overwritten: both stay as they were');
$check(!is_file($target . '/' . Storage::MIGRATED_MARKER) && !Storage::migrationDone($legacy, $target), 'with a file refused the move is not marked done');
$check(sprintf('%o', fileperms($target . '/20260101000000_aaaa.pdf') & 0777) === '644', 'a moved file is 0644');
// A deny rule in the old folder would refuse every /uploads/digital/ URL before
// public/.htaccess hands it to Pinakes: the administrator's own .htaccess stays as it is,
// the one pre-release builds wrote goes.
$check((string) file_get_contents($legacy . '/.htaccess') === "Options -Indexes\n", 'an .htaccess of the administrator in the old folder is left as it is');
file_put_contents($legacy . '/.htaccess', Storage::denyAllHtaccess());
Storage::releaseLegacyDir($legacy);
$check(!is_file($legacy . '/.htaccess'), 'the deny-all rule of a pre-release build is removed from the old folder');
file_put_contents($legacy . '/.htaccess', "Options -Indexes\n");
$check(str_contains((string) file_get_contents($target . '/.htaccess'), 'Require all denied') && is_file($target . '/index.html'), 'the new folder, outside the web root, refuses direct requests too');
$check(is_file($legacy . '/index.html') && is_file($legacy . '/.htaccess'), 'index.html and .htaccess are not moved');
// What keeps the old folder from being served directly: every /uploads/digital/ request,
// file present or not, goes to Pinakes, before the rule that serves the public uploads.
$rootHtaccess = (string) file_get_contents($root . '/public/.htaccess');
$routed = strpos($rootHtaccess, 'RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [NC,QSA,L]');
$served = strpos($rootHtaccess, 'RewriteRule ^uploads/ - [L]');
$check($routed !== false && $served !== false && $routed < $served, 'public/.htaccess sends every /uploads/digital/ request to Pinakes, in any letter case, before serving public uploads');

unlink($legacy . '/20260101000000_clash.pdf');
$second = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($second === ['moved' => 0, 'remaining' => 0, 'failed' => 0] && Storage::migrationDone($legacy, $target), 'once nothing is left the move is marked done, and a second run changes nothing');

// No sleep: a file arriving in the same second as the marker must still be seen.
file_put_contents($legacy . '/20260101000000_back.pdf', 'restored from an old backup');
$check(!Storage::migrationDone($legacy, $target), 'a file put back in the old folder reopens the move');
$third = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($third['moved'] === 1 && is_file($target . '/20260101000000_back.pdf') && Storage::migrationDone($legacy, $target), 'and the next run moves it');

file_put_contents($legacy . '/20260101000000_late.pdf', 'late');
$held = fopen($target . '/.migration.lock', 'c');
flock($held, LOCK_EX);
$locked = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($locked === ['moved' => 0, 'remaining' => 1, 'failed' => 0] && is_file($legacy . '/20260101000000_late.pdf'), 'a second concurrent run does nothing while the first holds the lock');
flock($held, LOCK_UN);
fclose($held);
$limited = Storage::migrate($legacy, $target, 0, 30.0);
$check($limited['remaining'] === 1 && $limited['moved'] === 0 && !Storage::migrationDone($legacy, $target), 'a run out of budget leaves the rest for the next one');

// Across disks (two Docker volumes) rename() fails: the file is copied, checked, swapped in.
Storage::$renameForTests = static fn (string $from, string $to): bool => false;
@unlink($legacy . '/20260101000000_late.pdf');
$big = random_bytes(300000);
file_put_contents($legacy . '/20260101000000_copy.mp3', $big);
file_put_contents($legacy . '/20260101000000_huge.mp3', random_bytes(500000));
$copied = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0, 400000);
$check($copied === ['moved' => 1, 'remaining' => 1, 'failed' => 0], 'across disks the copy fits the budget, the larger file waits: ' . json_encode($copied));
$check(is_file($target . '/20260101000000_copy.mp3') && hash_file('sha256', $target . '/20260101000000_copy.mp3') === hash('sha256', $big)
    && !is_file($legacy . '/20260101000000_copy.mp3'), 'the copied file has the same SHA-256 and the original is gone');
$check(is_file($legacy . '/20260101000000_huge.mp3') && !is_file($target . '/20260101000000_huge.mp3'), 'the file over budget stays where it is, still served');
$check(glob($target . '/.partial-*') === [], 'no partial copy is left behind');
$check(sprintf('%o', fileperms($target . '/20260101000000_copy.mp3') & 0777) === '644', 'a copied file is 0644 too');
$rest = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($rest['moved'] === 1 && is_file($target . '/20260101000000_huge.mp3') && Storage::migrationDone($legacy, $target), 'the cron run, with no budget, moves it');
Storage::$renameForTests = null;

$fresh = $scratch . '/fresh/storage/digital';
$previousUmask = umask(022); // a typical host: mkdir() alone would leave 0755
Storage::ensureDir($fresh);
umask($previousUmask);
$check(is_dir($fresh) && sprintf('%o', fileperms($fresh) & 0777) === '775', 'storage/digital is 0775 like the other writable folders, whatever the umask');
chmod($fresh, 0755); // created by an older updater copying storage/digital/.gitkeep
Storage::ensureDir($fresh);
$check(sprintf('%o', fileperms($fresh) & 0777) === '775', 'a 0755 folder left by an older updater gets the group bits');
chmod($fresh, 0750); // a stricter choice of the administrator
Storage::ensureDir($fresh);
$check(sprintf('%o', fileperms($fresh) & 0777) === '770', 'a stricter folder gets the group bits only, never the "other" ones');
chmod($fresh, 0775);
$check(is_file($fresh . '/.htaccess') && str_contains((string) file_get_contents($fresh . '/.htaccess'), 'Require all denied') && is_file($fresh . '/index.html'), 'storage/digital refuses direct requests and lists nothing');
$check(Storage::migrate($scratch . '/missing', $fresh, 10, 5.0) === ['moved' => 0, 'remaining' => 0, 'failed' => 0] && Storage::migrationDone($scratch . '/missing', $fresh), 'an installation that never had the old folder is done at once');

// A file that cannot be moved must not cost every page a re-hash: the first failure is
// remembered, page requests skip it while it is unchanged, the cron tries it again.
$stuckLegacy = $scratch . '/stuck/public/uploads/digital';
$stuckTarget = $scratch . '/stuck/storage/digital';
mkdir($stuckLegacy, 0755, true);
Storage::ensureDir($stuckTarget);
file_put_contents($stuckLegacy . '/20260101000000_stuck.mp3', str_repeat('s', 8192));
copy($stuckLegacy . '/20260101000000_stuck.mp3', $stuckTarget . '/20260101000000_stuck.mp3');
chmod($stuckLegacy, 0555); // the old folder is not writable: the original cannot be removed
$asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0; // root ignores the mode (a Docker CI)
if ($asRoot) {
    echo "  NOTE running as root: the unwritable-folder checks need an ordinary user and are skipped\n";
} else {
$cron = Storage::migrate($stuckLegacy, $stuckTarget, PHP_INT_MAX, 30.0);
$check($cron === ['moved' => 0, 'remaining' => 0, 'failed' => 1] && Storage::knownFailures($stuckTarget) === 1 && !Storage::migrationDone($stuckLegacy, $stuckTarget),
    'an original that cannot be removed is a failure, remembered: ' . json_encode($cron));
$page = Storage::migrate($stuckLegacy, $stuckTarget, 200, 3.0, Storage::REQUEST_COPY_BUDGET, false);
$check($page === ['moved' => 0, 'remaining' => 0, 'failed' => 0] && Storage::migrationDone($stuckLegacy, $stuckTarget),
    'a page request skips the remembered failure and marks the move done, so the next pages do nothing: ' . json_encode($page));
$check(Storage::knownFailures($stuckTarget) === 1, 'the failure stays remembered for the cron and the settings page');
$check(Storage::migrate($stuckLegacy, $stuckTarget, PHP_INT_MAX, 30.0)['failed'] === 1, 'the cron (retryFailed) tries it again');
// Across disks the same: the copy lands and is verified, but the original stays behind.
chmod($stuckLegacy, 0755);
file_put_contents($stuckLegacy . '/20260101000000_xdisk.mp3', str_repeat('x', 4096));
chmod($stuckLegacy, 0555);
Storage::$renameForTests = static fn (string $from, string $to): bool => false;
$xdisk = Storage::migrate($stuckLegacy, $stuckTarget, PHP_INT_MAX, 30.0);
Storage::$renameForTests = null;
$check($xdisk['failed'] === 2 && $xdisk['moved'] === 0 && Storage::knownFailures($stuckTarget) === 2 && is_file($stuckTarget . '/20260101000000_xdisk.mp3'),
    'a cross-disk copy whose original cannot be removed is a failure too, not a move: ' . json_encode($xdisk));
chmod($stuckLegacy, 0755);
@unlink($stuckLegacy . '/20260101000000_xdisk.mp3');
}
chmod($stuckLegacy, 0755);
touch($stuckLegacy . '/20260101000000_stuck.mp3', time() - 60);
$changed = Storage::migrate($stuckLegacy, $stuckTarget, 200, 3.0, Storage::REQUEST_COPY_BUDGET, false);
$check($changed['moved'] === 1 && Storage::knownFailures($stuckTarget) === 0 && !is_file($stuckLegacy . '/20260101000000_stuck.mp3'),
    'a remembered file that changed is tried again by a page request, and once moved is forgotten');
file_put_contents($stuckLegacy . '/20260101000000_large.mp3', str_repeat('l', 50000));
copy($stuckLegacy . '/20260101000000_large.mp3', $stuckTarget . '/20260101000000_large.mp3');
$budgeted = Storage::migrate($stuckLegacy, $stuckTarget, 200, 3.0, 40000, false);
$check($budgeted === ['moved' => 0, 'remaining' => 1, 'failed' => 0] && is_file($stuckLegacy . '/20260101000000_large.mp3'),
    'comparing a leftover larger than the page budget is left to the cron (hashing reads both files)');
$check(Storage::migrate($stuckLegacy, $stuckTarget, PHP_INT_MAX, 30.0)['moved'] === 1 && Storage::migrationDone($stuckLegacy, $stuckTarget), 'and the cron settles it');

$removeTree($scratch);

// ── B. Who may open what ────────────────────────────────────────────────────
echo "B. DigitalAccess\n";
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
if ($dbUser === '' || $dbName === '') {
    echo "SKIP: no database credentials\n";
    exit($fail > 0 ? 1 : 0);
}
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');
// The plugin's own schema step (also turns a development DATETIME table into the epoch one).
require_once $root . '/storage/plugins/digital-library/DigitalLibraryPlugin.php';
\DigitalLibraryPlugin::ensureIndexTable($db);

$tag = 'T480' . bin2hex(random_bytes(3));
$repo = new \App\Models\SettingsRepository($db);
$savedDefault = $repo->get(Access::CATEGORY, Access::DEFAULT_KEY, null);
$bookIds = [];
$userIds = [];
$files = [];
$cleanup = static function () use ($db, &$bookIds, &$userIds, &$files, $repo, $savedDefault): void {
    foreach ($bookIds as $id) {
        $db->query('DELETE FROM prestiti WHERE libro_id = ' . (int) $id);
        $db->query('DELETE FROM digital_library_files WHERE libro_id = ' . (int) $id);
        $db->query('DELETE FROM libri WHERE id = ' . (int) $id);
    }
    foreach ($userIds as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    foreach ($files as $file) {
        @unlink($file);
    }
    if ($savedDefault === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'digital_library' AND setting_key = 'default_visibility'");
    } else {
        $repo->set(Access::CATEGORY, Access::DEFAULT_KEY, $savedDefault);
    }
    Access::forgetDefault();
};
register_shutdown_function($cleanup);

$book = static function (array $attachments, bool $deleted = false) use ($db, $tag, &$bookIds): int {
    // The same writer as the editor: the list plus the first edition/track mirrored in
    // file_url / audio_url, which fromBook() checks against the list.
    $fields = \App\Support\DigitalAttachments::applySubmission([], ['digital_attachments_present' => '1', 'digital_attachments' => $attachments]);
    $json = $fields['digital_attachments'];
    $title = $tag . ' book ' . count($bookIds);
    $deletedAt = $deleted ? date('Y-m-d H:i:s') : null;
    $stmt = $db->prepare('INSERT INTO libri (titolo, digital_attachments, file_url, audio_url, deleted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('sssss', $title, $json, $fields['file_url'], $fields['audio_url'], $deletedAt);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $id;
    return $id;
};
$user = static function (string $role, string $state = 'attivo') use ($db, $tag, &$userIds): array {
    $n = count($userIds);
    $card = substr($tag . 'U' . $n, 0, 20);
    $email = strtolower($tag) . $n . '@example.invalid';
    $hash = password_hash('x', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'T', 'T', ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $card, $email, $hash, $role, $state);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $id;
    return ['id' => $id, 'tipo_utente' => $role];
};

$name = static fn (string $v): string => $tag . '_' . $v . '.pdf';
$url = static fn (string $v): string => '/uploads/digital/' . $name($v);
$open = $book([
    ['url' => $url('public'), 'label' => 'P', 'kind' => 'ebook', 'visibility' => 'public'],
    ['url' => $url('users'), 'label' => 'U', 'kind' => 'ebook', 'visibility' => 'users'],
    ['url' => $url('staff'), 'label' => 'S', 'kind' => 'supplement', 'visibility' => 'staff'],
    ['url' => $url('loan'), 'label' => 'L', 'kind' => 'ebook', 'visibility' => 'loan'],
    ['url' => $url('inherit'), 'label' => 'I', 'kind' => 'audio', 'visibility' => 'inherit'],
]);
$reader = $user('standard');
$borrower = $user('standard');
$staff = $user('staff');
$admin = $user('admin');
$suspended = $user('standard', 'sospeso');
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)");
$stmt->bind_param('ii', $open, $borrower['id']);
$stmt->execute();
$stmt->close();

$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'public');
Access::forgetDefault();
$expect = [
    // file          anonymous           reader              borrower            staff     admin
    'public'  => [Access::GRANTED, Access::GRANTED, Access::GRANTED, Access::GRANTED, Access::GRANTED],
    'users'   => [Access::LOGIN_REQUIRED, Access::GRANTED, Access::GRANTED, Access::GRANTED, Access::GRANTED],
    'staff'   => [Access::STAFF_ONLY, Access::STAFF_ONLY, Access::STAFF_ONLY, Access::GRANTED, Access::GRANTED],
    'loan'    => [Access::LOGIN_REQUIRED, Access::LOAN_REQUIRED, Access::GRANTED, Access::GRANTED, Access::GRANTED],
    'inherit' => [Access::GRANTED, Access::GRANTED, Access::GRANTED, Access::GRANTED, Access::GRANTED],
];
$viewers = [null, $reader, $borrower, $staff, $admin];
foreach ($expect as $file => $answers) {
    $got = array_map(static fn (?array $v): string => Access::authorizeFile($name($file), $v, $db), $viewers);
    $check($got === $answers, "visibility {$file}: anonymous/reader/borrower/staff/admin → " . implode(', ', $got));
}

$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'users');
Access::forgetDefault();
$check(Access::authorizeFile($name('inherit'), null, $db) === Access::LOGIN_REQUIRED && Access::authorizeFile($name('inherit'), $reader, $db) === Access::GRANTED,
    'an `inherit` file follows the library default as soon as it changes');
$check(Access::authorizeFile($name('public'), null, $db) === Access::GRANTED, 'an explicit visibility ignores the default');
$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'public');
Access::forgetDefault();

$check(Access::authorizeFile($tag . '_nobody.pdf', null, $db) === Access::STAFF_ONLY && Access::authorizeFile($tag . '_nobody.pdf', $admin, $db) === Access::GRANTED,
    'a file no record lists is staff only');
$gone = $book([['url' => $url('gone'), 'label' => 'G', 'kind' => 'ebook', 'visibility' => 'public']], true);
$check(Access::authorizeFile($name('gone'), null, $db) === Access::STAFF_ONLY, 'a file listed only by a soft-deleted record is not public');

// Two records list the same file: the most open answer wins.
$both = $book([['url' => $url('users'), 'label' => 'U2', 'kind' => 'ebook', 'visibility' => 'public']]);
$check(Access::authorizeFile($name('users'), null, $db) === Access::GRANTED, 'a file listed by two records takes the more open visibility');
$db->query('DELETE FROM libri WHERE id = ' . (int) $both);

// The index is a cache: a stale entry is dropped and the records are scanned again.
$indexed = (int) $db->query("SELECT COUNT(*) FROM digital_library_files WHERE file_name = '" . $db->real_escape_string($name('users')) . "'")->fetch_row()[0];
$check($indexed >= 1, 'the first lookup fills the index');
$check(Access::authorizeFile($name('users'), null, $db) === Access::LOGIN_REQUIRED, 'the index entry of a deleted record is dropped and the answer comes from the live records');
$stale = $book([['url' => $url('moved'), 'label' => 'M', 'kind' => 'ebook', 'visibility' => 'public']]);
$check(Access::authorizeFile($name('moved'), null, $db) === Access::GRANTED, 'a public file opens');
$db->query("UPDATE libri SET digital_attachments = '[]', file_url = '', audio_url = '' WHERE id = " . (int) $stale);
$check(Access::authorizeFile($name('moved'), null, $db) === Access::STAFF_ONLY, 'once the record no longer lists it, the index does not keep it open');

// The index compares instants, not wall clocks: a request in another time_zone (RememberMeService
// switches to UTC) must not take a verification for newer than a later change.
$db->query('DELETE FROM digital_library_files WHERE file_name = ' . "'" . $db->real_escape_string($name('users')) . "'");
$db->query("SET time_zone = '+09:00'"); // MariaDB accepts offsets up to +13:00 only
$check(Access::authorizeFile($name('users'), null, $db) === Access::LOGIN_REQUIRED, 'verified in a +09:00 session');
$db->query("SET time_zone = '+00:00'");
sleep(1); // updated_at has one-second resolution
$later = $book([['url' => $url('users'), 'label' => 'U3', 'kind' => 'ebook', 'visibility' => 'public']]);
$check(Access::authorizeFile($name('users'), null, $db) === Access::GRANTED, 'a record added later, read in a UTC session, outdates the index and is seen');
$db->query('DELETE FROM libri WHERE id = ' . (int) $later);
$db->query('SET time_zone = @@global.time_zone');

$listed = Access::forViewer(['id' => $open], \App\Support\DigitalAttachments::fromBook(['digital_attachments' => json_encode([
    ['url' => $url('users'), 'label' => 'U', 'kind' => 'ebook', 'visibility' => 'users'],
])]), null, $db);
$check($listed[0]['access'] === Access::LOGIN_REQUIRED && $listed[0]['visibility_resolved'] === 'users', 'forViewer adds the access and the resolved visibility');
$check(Access::anonymousUrl($url('users'), $db) === null && Access::anonymousUrl($url('public'), $db) === $url('public')
    && Access::anonymousUrl('https://example.org/x.pdf', $db) === 'https://example.org/x.pdf', 'anonymous consumers (public API, OAI) get only files anyone may open');

$now = 1_900_000_000;
$signed = Access::signedUrl(\App\Support\HtmlHelper::absoluteUrl($url('loan')), $borrower['id'], $db, $now);
parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);
$exp = (int) ($query['pk_exp'] ?? 0);
$check(Access::verifySignature($name('loan'), $query, $db, $now + 60) === $borrower['id'], 'a signed link names its reader');
$check($exp % 3600 === 0 && $exp >= $now + Access::SIGNED_TTL && $exp < $now + Access::SIGNED_TTL + 3600, 'it lives at least 24 hours, its expiry rounded up to the hour');
$check(Access::verifySignature($name('loan'), $query, $db, $exp) === $borrower['id'] && Access::verifySignature($name('loan'), $query, $db, $exp + 1) === null, 'and then it expires');
$check(Access::signedUrl(\App\Support\HtmlHelper::absoluteUrl($url('loan')), $borrower['id'], $db, $now + 60) === $signed,
    'the same link for the whole hour, so the book detail keeps its ETag and the app its cached media');
$offsite = 'https://example.org' . $url('loan');
$check(Access::fileNameOf($offsite) === null && Access::signedUrl($offsite, $borrower['id'], $db, $now) === $offsite,
    'a link on another host is not a local file, and never carries a signature');
$check(Access::verifySignature($name('users'), $query, $db, $now + 60) === null, 'it opens only the file it was signed for');
$check(Access::verifySignature($name('loan'), ['pk_uid' => (string) $reader['id']] + $query, $db, $now + 60) === null, 'it cannot be moved to another reader');
$check(Access::verifySignature($name('loan'), ['pk_sig' => str_repeat('0', 64)] + $query, $db, $now + 60) === null, 'a forged signature is refused');
$check(Access::signedViewer($suspended['id'], $db) === null && Access::signedViewer($borrower['id'], $db)['tipo_utente'] === 'standard', 'a suspended reader is refused even with a valid signature');

// ── C. Serving a real file ──────────────────────────────────────────────────
echo "C. ProtectedUploadController\n";
$dir = Storage::ensureDir(Storage::targetDir($root));
foreach (['public', 'users', 'staff'] as $v) {
    file_put_contents($dir . '/' . $name($v), '%PDF-1.4 ' . $v . ' 0123456789');
    $files[] = $dir . '/' . $name($v);
}
$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$controller = new \App\Controllers\ProtectedUploadController($db);
$serve = static function (string $v, array $headers = [], array $query = [], string $method = 'GET') use ($factory, $controller, $name) {
    $request = $factory->createServerRequest($method, '/uploads/digital/' . $name($v))->withQueryParams($query);
    foreach ($headers as $h => $value) {
        $request = $request->withHeader($h, $value);
    }
    return $controller->serve($request, new \Slim\Psr7\Response(), ['path' => 'digital/' . $name($v)]);
};
unset($_SESSION);
$res = $serve('public');
$check($res->getStatusCode() === 200 && (string) $res->getBody() === '%PDF-1.4 public 0123456789', 'a public file is served from storage/digital to anyone');
$check(str_starts_with($res->getHeaderLine('Content-Disposition'), 'inline') && $res->getHeaderLine('Cache-Control') === 'private, max-age=0, must-revalidate', 'inline, never cached by a shared cache');
$res = $serve('users');
$check($res->getStatusCode() === 302 && str_contains($res->getHeaderLine('Location'), 'return_url='), 'a signed-in-only file sends an anonymous reader to the login page, then back');
$check($serve('users', ['Accept' => 'application/json'])->getStatusCode() === 401, 'a JSON client gets 401 instead of a redirect');
$check($serve('staff')->getStatusCode() === 403, 'a staff-only file is refused to anyone else');
$_SESSION = ['user' => $reader];
$check($serve('users')->getStatusCode() === 200 && $serve('staff')->getStatusCode() === 403, 'a signed-in reader opens the users file, not the staff one');
$_SESSION = ['user' => $staff];
$check($serve('staff')->getStatusCode() === 200, 'staff open the staff file');
unset($_SESSION);
parse_str((string) parse_url(Access::signedUrl($url('users'), $reader['id'], $db), PHP_URL_QUERY), $sq);
$check($serve('users', [], $sq)->getStatusCode() === 200, 'the app opens it with a signed link and no session');
$check($serve('users', [], ['pk_sig' => 'x'] + $sq)->getStatusCode() === 403, 'a tampered signed link is refused');
parse_str((string) parse_url(Access::signedUrl($url('users'), $suspended['id'], $db), PHP_URL_QUERY), $susp);
$check($serve('users', [], $susp)->getStatusCode() === 403, 'a signed link of a suspended reader is refused');
$res = $serve('public', ['Range' => 'bytes=0-7']);
$check($res->getStatusCode() === 206 && (string) $res->getBody() === '%PDF-1.4' && str_starts_with($res->getHeaderLine('Content-Range'), 'bytes 0-7/'), 'Range requests still work for seeking');
$etag = $serve('public')->getHeaderLine('ETag');
$check($etag !== '' && $serve('public', ['If-None-Match' => $etag])->getStatusCode() === 304, 'a matching ETag answers 304');
$check($serve('public', ['Range' => 'bytes=0-7', 'If-Range' => '"other"'])->getStatusCode() === 200, 'If-Range for another version gets the whole file');
$res = $serve('public', [], [], 'HEAD');
$check($res->getStatusCode() === 200 && (string) $res->getBody() === '' && $res->getHeaderLine('Content-Length') !== '', 'HEAD answers the headers without the body');
$check(str_starts_with($serve('public', [], ['download' => '1'])->getHeaderLine('Content-Disposition'), 'attachment'), '?download=1 asks the browser to save the file');
$check($serve('missing')->getStatusCode() === 404, 'a file that does not exist is 404');

// The check must not depend on how the path is spelled: every spelling that lands in a
// Digital Library folder goes through DigitalAccess, or is refused.
$legacyDir = Storage::legacyDir($root);
$legacyMade = !is_dir($legacyDir) && mkdir($legacyDir, 0775, true);
copy($dir . '/' . $name('staff'), $legacyDir . '/' . $name('staff'));
$files[] = $legacyDir . '/' . $name('staff');
$raw = static function (string $path) use ($factory, $controller) {
    return $controller->serve($factory->createServerRequest('GET', '/uploads/' . $path), new \Slim\Psr7\Response(), ['path' => $path])->getStatusCode();
};
foreach (['./digital/', 'digital/./', './/digital/', 'Digital/', 'DIGITAL/', 'storage/../digital/', 'archives/./../digital/'] as $spelling) {
    $check($raw($spelling . $name('staff')) !== 200, 'a staff-only file in the old folder is not served as ' . $spelling . '<name>');
}
$check($raw('digital/' . $name('staff')) === 403, 'the staff-only file in the old folder still goes through the check');
$check($raw('.htaccess') === 404 && $raw('archives/documents/.htaccess') === 404, 'dotfiles are never served');

// Names from older versions: a space, an accented letter, a subfolder of the old folder.
// The record may spell them in more than one way (here a raw è and an encoded space).
$spaced = $tag . ' nome è.pdf';
file_put_contents($dir . '/' . $spaced, '%PDF-1.4 spaced');
$files[] = $dir . '/' . $spaced;
@mkdir($legacyDir . '/' . $tag . 'sub', 0775, true);
$nested = $tag . 'sub/traccia 1.mp3';
file_put_contents($legacyDir . '/' . $nested, 'ID3 nested');
$files[] = $legacyDir . '/' . $nested;
$legacyNames = $book([
    ['url' => '/uploads/digital/' . $tag . '%20nome%20è.pdf', 'label' => 'Spaced', 'kind' => 'ebook', 'visibility' => 'public'],
    ['url' => '/uploads/digital/' . $tag . 'sub/traccia%201.mp3', 'label' => 'Nested', 'kind' => 'audio', 'visibility' => 'users'],
]);
unset($_SESSION);
$serveName = static fn (string $n) => $controller->serve($factory->createServerRequest('GET', '/uploads/digital/' . $n), new \Slim\Psr7\Response(), ['path' => 'digital/' . $n]);
$res = $serveName($spaced);
$check($res->getStatusCode() === 200 && (string) $res->getBody() === '%PDF-1.4 spaced', 'a legacy name with a space and an accented letter is still served, and matched to its record');
$check($serveName($nested)->getStatusCode() === 302, 'a file in a subfolder of the old folder goes through the check too (signed-in only: login)');
$_SESSION = ['user' => $reader];
$res = $serveName($nested);
$check($res->getStatusCode() === 200 && (string) $res->getBody() === 'ID3 nested', 'and opens for a signed-in reader');
unset($_SESSION);
$check(Access::fileNameOf('/uploads/digital/' . $tag . '%20nome%20%C3%A8.pdf') === $spaced && Access::isValidName($nested)
    && !Access::isValidName('a/../b') && !Access::isValidName('.hidden') && !Access::isValidName("a\\b") && !Access::isValidName("a\0b"),
    'one name rule for the route and the records: legacy names in, dot segments, backslashes and NUL out');
@unlink($legacyDir . '/' . $nested);
@rmdir($legacyDir . '/' . $tag . 'sub');

// Sendfile hands the transfer to the web server only for a file in storage/digital/,
// the folder the documented server setup exposes; LiteSpeed is not a mode.
$_SESSION = ['user' => $staff];
$legacyOnly = $tag . '_legacyonly.pdf';
file_put_contents($legacyDir . '/' . $legacyOnly, '%PDF-1.4 legacy only');
$files[] = $legacyDir . '/' . $legacyOnly;
$withSendfile = static function (string $mode, callable $call) {
    $_ENV['DIGITAL_LIBRARY_SENDFILE'] = $mode;
    putenv('DIGITAL_LIBRARY_SENDFILE=' . $mode);
    try {
        return $call();
    } finally {
        unset($_ENV['DIGITAL_LIBRARY_SENDFILE']);
        putenv('DIGITAL_LIBRARY_SENDFILE');
    }
};
$res = $withSendfile('nginx', static fn () => $serve('public'));
$check($res->getHeaderLine('X-Accel-Redirect') === '/_pinakes_digital/' . rawurlencode($name('public')) && (string) $res->getBody() === '', 'nginx: a file in storage/digital is handed to the web server');
$res = $withSendfile('apache', static fn () => $serve('public'));
$check($res->getHeaderLine('X-Sendfile') === realpath($dir . '/' . $name('public')), 'apache: X-Sendfile names the file in storage/digital');
$res = $withSendfile('nginx', static fn () => $controller->serve($factory->createServerRequest('GET', '/uploads/digital/' . $legacyOnly), new \Slim\Psr7\Response(), ['path' => 'digital/' . $legacyOnly]));
$check($res->getStatusCode() === 200 && $res->getHeaderLine('X-Accel-Redirect') === '' && (string) $res->getBody() === '%PDF-1.4 legacy only',
    'a file still in the old folder streams through PHP, never a sendfile pointing at storage/digital');
$res = $withSendfile('litespeed', static fn () => $serve('public'));
$check($res->getHeaderLine('X-LiteSpeed-Location') === '' && (string) $res->getBody() === '%PDF-1.4 public 0123456789', 'litespeed is not a sendfile mode: the file streams through PHP');
unset($_SESSION);
@unlink($legacyDir . '/' . $legacyOnly);

@unlink($legacyDir . '/' . $name('staff'));
if ($legacyMade) {
    @unlink($legacyDir . '/.htaccess');
    @rmdir($legacyDir);
}

// ── D. Every other way a file could leave Pinakes ───────────────────────────
echo "D. Public API, OAI-PMH, Mobile API, private mode\n";
require_once $root . '/storage/plugins/oai-pmh-server/wrapper.php';
require_once $root . '/storage/plugins/mobile-api/wrapper.php';
// null is an answer here ("not given out"), not a missing field: ?? would hide it.
$isNull = static fn (array $row, string $key): bool => array_key_exists($key, $row) && $row[$key] === null;
$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'public');
Access::forgetDefault();
$savedShowLocked = $repo->get(Access::CATEGORY, Access::SHOW_LOCKED_KEY, null);
$ean = static fn (): string => '480' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
$withEan = static function (int $id) use ($db, $ean): string {
    $code = $ean();
    $db->query("UPDATE libri SET ean = '" . $code . "' WHERE id = " . $id);
    return $code;
};
// The first edition and track are what file_url / audio_url mirror.
$publicFirst = $book([
    ['url' => $url('apiopen'), 'label' => 'Open', 'kind' => 'ebook', 'visibility' => 'public'],
    ['url' => $url('apiopenaudio'), 'label' => 'Open track', 'kind' => 'audio', 'visibility' => 'inherit'],
]);
$closedFirst = $book([
    ['url' => $url('apistaff'), 'label' => 'Staff', 'kind' => 'ebook', 'visibility' => 'staff'],
    ['url' => $url('apiusers'), 'label' => 'Readers track', 'kind' => 'audio', 'visibility' => 'users'],
    ['url' => $url('apilater'), 'label' => 'Open later', 'kind' => 'ebook', 'visibility' => 'public'],
]);
$external = $book([['url' => 'https://example.org/elsewhere.pdf', 'label' => 'Elsewhere', 'kind' => 'ebook', 'visibility' => 'public']]);
$externalStaff = $book([['url' => 'https://example.org/reserved.pdf', 'label' => 'Reserved elsewhere', 'kind' => 'ebook', 'visibility' => 'staff']]);
$eanOpen = $withEan($publicFirst);
$eanClosed = $withEan($closedFirst);
$eanExternal = $withEan($external);
$eanExternalStaff = $withEan($externalStaff);

// Public API: the key names an application, never a reader.
$api = new \App\Controllers\PublicApiController();
$apiResult = static function (string $code) use ($api, $factory, $db): array {
    $res = $api->searchBooks($factory->createServerRequest('GET', '/api/public/books/search')->withQueryParams(['ean' => $code]), new \Slim\Psr7\Response(), $db);
    $payload = json_decode((string) $res->getBody(), true);
    return $payload['results'][0] ?? ($payload['data']['results'][0] ?? []);
};
$row = $apiResult($eanOpen);
$check(($row['file_url'] ?? null) === $url('apiopen') && ($row['audio_url'] ?? null) === $url('apiopenaudio'), 'public API: files anyone may open (also through the library default) are listed');
$row = $apiResult($eanClosed);
$check($isNull($row, 'file_url') && $isNull($row, 'audio_url'), 'public API: a staff-only edition and a signed-in-only track are not given out');
$check(!str_contains(json_encode($row), $name('apistaff')) && !str_contains(json_encode($row), $name('apiusers')), 'public API: no field of the record carries their address');
$check(($apiResult($eanExternal)['file_url'] ?? null) === 'https://example.org/elsewhere.pdf', 'public API: an open link to another site is passed as is');
$check($isNull($apiResult($eanExternalStaff), 'file_url'), 'public API: a link to another site closed to anonymous readers is not given out either');
$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'users');
Access::forgetDefault();
$row = $apiResult($eanOpen);
$check($isNull($row, 'audio_url') && ($row['file_url'] ?? null) === $url('apiopen'), 'public API: a track left on the default follows the library default');
$repo->set(Access::CATEGORY, Access::DEFAULT_KEY, 'public');
Access::forgetDefault();

// OAI-PMH: harvesters are anonymous. MAG is the format that carries the file.
$oai = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, new \App\Support\HookManager($db));
$baseUrl = (new ReflectionMethod($oai, 'oaiBaseUrl'))->invoke($oai);
$oaiHost = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';
$record = static function (int $id, string $prefix = 'mag') use ($oai, $factory, $oaiHost): string {
    $req = $factory->createServerRequest('GET', '/oai')->withQueryParams(['verb' => 'GetRecord', 'metadataPrefix' => $prefix, 'identifier' => "oai:{$oaiHost}:book:{$id}"]);
    return (string) $oai->oaiPmhAction($req, new \Slim\Psr7\Response())->getBody();
};
$xml = $record($publicFirst);
$check(str_contains($xml, '<metadigit') && str_contains($xml, $name('apiopen')), 'OAI-PMH MAG: an open file is offered to harvesters');
$xml = $record($closedFirst);
$check(str_contains($xml, '<metadigit') && !str_contains($xml, $name('apistaff')), 'OAI-PMH MAG: a staff-only file is left out of the record');
foreach (['oai_dc', 'marcxml', 'mods'] as $prefix) {
    $xml = $record($closedFirst, $prefix);
    $check(str_contains($xml, '<GetRecord') && !str_contains($xml, '/uploads/digital/'), "OAI-PMH {$prefix}: no Digital Library address at all");
}

// Mobile API: the reader the token names; open files get a signed link.
$catalog = new \App\Plugins\MobileApi\Controllers\CatalogController($db);
$mobile = static function (int $id, ?array $who) use ($catalog, $factory): array {
    $req = $factory->createServerRequest('GET', 'http://localhost/api/v1/books/' . $id);
    if ($who !== null) {
        $req = $req->withAttribute(\App\Plugins\MobileApi\Support\AppAuthMiddleware::ATTR_USER, $who);
    }
    $payload = json_decode((string) $catalog->bookDetail($req, new \Slim\Psr7\Response(), $id)->getBody(), true);
    return $payload['data'] ?? [];
};
$byLabel = static fn (array $data): array => array_column($data['digital_attachments'] ?? [], null, 'label');
$repo->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, '1');
$data = $mobile($open, $reader);
$listed = $byLabel($data);
$check(!isset($listed['S']), 'mobile: a staff-only file is not listed to a reader');
$check($isNull($listed['L'] ?? [], 'url') && ($listed['L']['access'] ?? '') === Access::LOAN_REQUIRED && ($listed['L']['visibility'] ?? '') === 'loan', 'mobile: the loan edition is listed closed, without its address, with the reason');
$signed = (string) ($listed['U']['url'] ?? '');
parse_str((string) parse_url($signed, PHP_URL_QUERY), $sq);
$check(str_contains($signed, $name('users')) && Access::verifySignature($name('users'), $sq, $db) === $reader['id'], 'mobile: an open file comes with a link signed for this reader and this file');
$check(($data['ebook_url'] ?? '') !== '' && str_contains((string) $data['ebook_url'], 'pk_sig=') && $data['has_ebook'] === true, 'mobile: ebook_url is the first file the reader may open, signed');
$borrowed = $byLabel($mobile($open, $borrower));
$check(is_string($borrowed['L']['url'] ?? null) && ($borrowed['L']['access'] ?? '') === Access::GRANTED, 'mobile: the reader with the loan gets the loan edition');
$staffView = $byLabel($mobile($open, ['id' => $staff['id'], 'tipo_utente' => 'staff']));
$check(isset($staffView['S']) && is_string($staffView['S']['url']), 'mobile: staff get the staff-only file too');
$anonymous = $byLabel($mobile($open, null));
$check(($anonymous['U']['access'] ?? '') === Access::LOGIN_REQUIRED && $isNull($anonymous['U'] ?? [], 'url') && is_string($anonymous['P']['url'] ?? null) && !str_contains((string) $anonymous['P']['url'], 'pk_sig='), 'mobile: without a reader, open files are plain links and the rest asks to sign in');
$repo->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, '0');
$hidden = $mobile($open, $reader);
$check(!isset($byLabel($hidden)['L']) && $hidden['has_ebook'] === true, 'mobile: with locked entries switched off, the closed file is not listed at all');
// URL and format come from the same edition; presence counts closed editions, never staff-only ones.
$mixed = $book([
    ['url' => $url('mixloan'), 'label' => 'Closed PDF', 'kind' => 'ebook', 'visibility' => 'loan'],
    ['url' => '/uploads/digital/' . $tag . '_mixopen.epub', 'label' => 'Open EPUB', 'kind' => 'ebook', 'visibility' => 'public'],
    ['url' => $url('mixstaffaudio'), 'label' => 'Staff track', 'kind' => 'audio', 'visibility' => 'staff'],
]);
$repo->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, '1'); // the closed PDF is listed, before the EPUB
$mixedData = $mobile($mixed, $reader);
$repo->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, '0');
$check(str_contains((string) ($mixedData['ebook_url'] ?? ''), '_mixopen.epub') && ($mixedData['ebook_format'] ?? '') === 'epub',
    'mobile: ebook_format describes the edition ebook_url opens (an EPUB after a closed PDF)');
$check($mixedData['has_ebook'] === true && $mixedData['has_audio'] === false, 'mobile: a staff-only track does not make has_audio true for a reader');
$closedOnly = $book([['url' => $url('onlyloan'), 'label' => 'Only loan', 'kind' => 'ebook', 'visibility' => 'loan']]);
$closedData = $mobile($closedOnly, $reader);
$check($closedData['has_ebook'] === true && ($closedData['digital_attachments'] ?? null) === [] && $isNull($closedData, 'ebook_url'),
    'mobile: with locked entries hidden, an edition closed to the reader still counts as existing');
$offsiteBook = $book([['url' => 'https://example.org' . $url('users'), 'label' => 'Mirror', 'kind' => 'ebook', 'visibility' => 'public']]);
$mirror = (string) ($byLabel($mobile($offsiteBook, $reader))['Mirror']['url'] ?? '');
$check($mirror === 'https://example.org' . $url('users'), 'mobile: a link to another host is never signed, even when its path looks like a local file');
if ($savedShowLocked === null) {
    $db->query("DELETE FROM system_settings WHERE category = 'digital_library' AND setting_key = 'show_locked_entries'");
} else {
    $repo->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, $savedShowLocked);
}

// Private mode: only a signed Digital Library link may pass without a session, and it
// still meets ProtectedUploadController, which checks the signature.
$runtimeCache = new ReflectionProperty(\App\Support\ConfigStore::class, 'runtimeCache');
$savedRuntime = $runtimeCache->getValue();
$runtimeCache->setValue(null, ['advanced' => ['private_mode' => '1']]);
unset($_SESSION);
$passed = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public int $calls = 0;
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $this->calls++;
        return new \Slim\Psr7\Response(204);
    }
};
$private = new \App\Middleware\PrivateModeMiddleware();
$through = static function (string $path, array $query = []) use ($private, $passed, $factory): bool {
    $before = $passed->calls;
    $private->process($factory->createServerRequest('GET', $path)->withQueryParams($query), $passed);
    return $passed->calls === $before + 1;
};
$check($through('/uploads/digital/' . $name('users'), $sq), 'private mode: a signed Digital Library link reaches the checked route');
$check(!$through('/uploads/digital/' . $name('users')), 'private mode: the same file without a signature is stopped at the door');
$check(!$through('/uploads/archives/documents/x.pdf', ['pk_sig' => 'x']) && !$through('/uploads/storage/x.bin', ['pk_sig' => 'x']) && !$through('/libro/1', ['pk_sig' => 'x']),
    'private mode: a pk_sig opens nothing but Digital Library files');
$runtimeCache->setValue(null, $savedRuntime);
// What the forged signature then meets: a 403 from the controller (section C), never the file.
$check($serve('users', [], ['pk_sig' => str_repeat('0', 64)] + $sq)->getStatusCode() === 403, 'private mode: a forged signature that got through is refused by the controller');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
