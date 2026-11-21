<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, UsersController:update:password-write-without-credential-revocation).
 *
 * The admin user editor is the third way to replace a password, after the
 * profile change and the email reset, and the natural one at the desk when an
 * account has been taken. It wrote the new hash and stopped: the remember-me
 * sessions, Mobile API tokens and calendar feed the old password authorised kept
 * working. It also let an operator replace their OWN password with no proof of
 * the current one, which the profile form requires.
 *
 * A. (DB-free) A self-edit that sets a password is refused unless the current
 *    password verifies, before anything is written; editing someone else, or a
 *    self-edit without a password, is unchanged.
 * B. (database) A password set in the editor revokes every persistent credential
 *    of the target in the same unit as the hash write; an operator editing their
 *    own account keeps only the browser they are typing in.
 *    Needs E2E_DB_NAME/E2E_DB_USER/E2E_DB_PASS (+ E2E_DB_SOCKET) or a .env with
 *    DB_*; skipped, with a message, when neither is configured. Every write
 *    happens inside a transaction that is rolled back.
 *
 * Run:  php tests/security-audit-users-password-change.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Controllers\UsersController;
use App\Support\RememberMeService;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$post = static function (int $id, array $body): \Psr\Http\Message\ServerRequestInterface {
    return (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/users/update/' . $id)
        ->withParsedBody($body);
};
$form = static fn(array $row, array $extra = []): array => $extra + [
    'nome' => $row['nome'], 'cognome' => $row['cognome'], 'email' => $row['email'],
    'telefono' => '000', 'indirizzo' => 'Audit street 1', 'tipo_utente' => $row['tipo_utente'],
    'stato' => 'attivo', 'codice_tessera' => $row['codice_tessera'] ?? '',
];

echo "A. Replacing one's own password needs the current one\n";

$staffHash = password_hash('OldPassw0rd', PASSWORD_DEFAULT);
$staffRow = [
    'id' => 50, 'nome' => 'Audit', 'cognome' => 'Staff', 'email' => 'audit-a1-staff@example.test',
    'password' => $staffHash, 'tipo_utente' => 'staff', 'stato' => 'attivo', 'codice_tessera' => 'AUDA1',
    'ruolo_id' => null, 'accesso_digitale' => 0, 'locale' => 'it_IT', 'email_verificata' => 1,
];
$readerRow = ['id' => 51, 'tipo_utente' => 'standard', 'email' => 'audit-a1-reader@example.test',
    'nome' => 'Audit', 'cognome' => 'Reader', 'password' => password_hash('Whatever1', PASSWORD_DEFAULT)] + $staffRow;

/** @return array{location: string, past: bool} */
$runA = static function (array $session, array $target, array $body) use ($post): array {
    $_SESSION = ['user' => $session];
    $db = new SecurityAuditFakeDb([$target], 1);
    $location = '';
    try {
        $out = (new UsersController())->update($post((int) $target['id'], $body), (new ResponseFactory())->createResponse(), $db, (int) $target['id']);
        $location = $out->getHeaderLine('Location');
    } catch (SecurityAuditPastGuard) {
    }
    $past = count($db->calls) > 1;
    $_SESSION = [];
    return ['location' => $location, 'past' => $past];
};

$staffSession = ['id' => 50, 'tipo_utente' => 'staff', 'stato' => 'attivo'];
$adminSession = ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo'];

$r = $runA($staffSession, $staffRow, $form($staffRow, ['password' => 'NewPassw0rd']));
$check(!$r['past'] && str_contains($r['location'], 'error=wrong_current_password'),
    'staff self-edit with a new password and no current password: refused before any write');
$r = $runA($staffSession, $staffRow, $form($staffRow, ['password' => 'NewPassw0rd', 'current_password' => 'Wrong0ne']));
$check(!$r['past'] && str_contains($r['location'], 'error=wrong_current_password'),
    'staff self-edit with a wrong current password: refused before any write');
$adminSelf = ['tipo_utente' => 'admin'] + $staffRow;
$r = $runA(['id' => 50] + $adminSession, $adminSelf, $form($adminSelf, ['password' => 'NewPassw0rd']));
$check(!$r['past'] && str_contains($r['location'], 'error=wrong_current_password'),
    'administrator self-edit with a new password and no current password: refused before any write');

$r = $runA($staffSession, $staffRow, $form($staffRow, ['password' => 'NewPassw0rd', 'current_password' => 'OldPassw0rd']));
$check($r['past'], 'staff self-edit with the right current password goes on to save');
$r = $runA($staffSession, $staffRow, $form($staffRow));
$check($r['past'], 'staff self-edit without a new password is not asked for one');
$r = $runA($adminSession, $readerRow, $form($readerRow, ['password' => 'NewPassw0rd']));
$check($r['past'], 'administrator setting a reader\'s password is not asked for their own');

echo "B. A password set in the editor ends the old credentials\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
if ($dbName === '') {
    echo "  SKIP no database configured (set E2E_DB_NAME/E2E_DB_USER/E2E_DB_PASS[/E2E_DB_SOCKET] or a .env) — section B not run\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: a database is configured but unreachable: {$e->getMessage()}\n");
    exit(1);
}

$hasTable = static function (string $table) use ($db): bool {
    $r = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
        . $db->real_escape_string($table) . "'");
    return $r instanceof mysqli_result && $r->num_rows > 0;
};
$mobile = $hasTable('mobile_app_tokens');
$calendar = $hasTable('mobile_calendar_tokens');

/** Creates an account with two remember-me rows and, when the plugin tables exist, one token each. */
$seed = static function (string $role, string $password) use ($db, $mobile, $calendar): array {
    $suffix = bin2hex(random_bytes(5));
    $row = [
        'nome' => 'AuditA1', 'cognome' => 'Probe', 'email' => "zz-audit-a1-{$suffix}@example.invalid",
        'tipo_utente' => $role, 'codice_tessera' => 'ZA' . strtoupper(substr($suffix, 0, 8)),
    ];
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, locale)
                          VALUES (?, ?, ?, ?, '000', 'Audit street 1', ?, 'attivo', 1, ?, 'it_IT')");
    $stmt->bind_param('ssssss', $row['nome'], $row['cognome'], $row['email'], $hash, $row['tipo_utente'], $row['codice_tessera']);
    $stmt->execute();
    $row['id'] = (int) $db->insert_id;
    $stmt->close();
    $sessions = [];
    for ($i = 0; $i < 2; $i++) {
        $th = hash('sha256', random_bytes(16));
        $s = $db->prepare("INSERT INTO user_sessions (utente_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $s->bind_param('is', $row['id'], $th);
        $s->execute();
        $sessions[] = (int) $db->insert_id;
        $s->close();
    }
    $row['sessions'] = $sessions;
    if ($mobile) {
        $th = hash('sha256', random_bytes(16));
        $s = $db->prepare("INSERT INTO mobile_app_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $s->bind_param('is', $row['id'], $th);
        $s->execute();
        $s->close();
    }
    if ($calendar) {
        $th = hash('sha256', random_bytes(16));
        $s = $db->prepare("INSERT INTO mobile_calendar_tokens (user_id, token_hash, token_enc) VALUES (?, ?, 'x')");
        $s->bind_param('is', $row['id'], $th);
        $s->execute();
        $s->close();
    }
    return $row;
};
$count = static function (string $sql, int $id) use ($db): int {
    $s = $db->prepare($sql);
    $s->bind_param('i', $id);
    $s->execute();
    $n = (int) ($s->get_result()->fetch_row()[0] ?? 0);
    $s->close();
    return $n;
};

$db->begin_transaction();
try {
    // An administrator recovers a reader's account at the desk.
    $reader = $seed('standard', 'OldPassw0rd');
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    $out = (new UsersController())->update($post($reader['id'], $form($reader, ['password' => 'NewPassw0rd'])),
        (new ResponseFactory())->createResponse(), $db, $reader['id']);
    $check(str_contains($out->getHeaderLine('Location'), 'updated=1'), 'the editor saved the new password (got: ' . $out->getHeaderLine('Location') . ')');
    $check($count('SELECT COUNT(*) FROM user_sessions WHERE utente_id = ? AND is_revoked = 0', $reader['id']) === 0,
        "every remember-me session of the reader is revoked");
    if ($mobile) {
        $check($count('SELECT COUNT(*) FROM mobile_app_tokens WHERE user_id = ? AND revoked_at IS NULL', $reader['id']) === 0,
            'every Mobile API token of the reader is revoked');
    }
    if ($calendar) {
        $check($count('SELECT COUNT(*) FROM mobile_calendar_tokens WHERE user_id = ? AND revoked_at IS NULL', $reader['id']) === 0,
            "the reader's calendar feed is revoked");
    }

    // An operator changes their own password: the browser in use stays signed in.
    $staff = $seed('staff', 'OldPassw0rd');
    $_SESSION = ['user' => ['id' => $staff['id'], 'tipo_utente' => 'staff', 'stato' => 'attivo'],
        RememberMeService::SESSION_ROW_KEY => $staff['sessions'][0]];
    $out = (new UsersController())->update($post($staff['id'], $form($staff, ['password' => 'NewPassw0rd', 'current_password' => 'OldPassw0rd'])),
        (new ResponseFactory())->createResponse(), $db, $staff['id']);
    $check(str_contains($out->getHeaderLine('Location'), 'updated=1'), 'the self-edit with the current password is saved');
    $check($count('SELECT COUNT(*) FROM user_sessions WHERE utente_id = ? AND is_revoked = 0', $staff['id']) === 1
        && $count('SELECT COUNT(*) FROM user_sessions WHERE id = ? AND is_revoked = 0', $staff['sessions'][0]) === 1,
        'only the session of the browser in use survives the self-edit');

    // Saving the form without a new password ends nothing.
    $other = $seed('standard', 'OldPassw0rd');
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    (new UsersController())->update($post($other['id'], $form($other)), (new ResponseFactory())->createResponse(), $db, $other['id']);
    $check($count('SELECT COUNT(*) FROM user_sessions WHERE utente_id = ? AND is_revoked = 0', $other['id']) === 2,
        'an edit that leaves the password alone leaves the sessions alone');
} finally {
    $db->rollback();
    $_SESSION = [];
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
