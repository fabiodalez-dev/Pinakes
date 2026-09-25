<?php
declare(strict_types=1);

/**
 * A password change and the revocations that go with it are one act.
 *
 * The connection runs in autocommit, so an UPDATE lands on its own the moment
 * it executes. Before this, a revocation that failed afterwards left the
 * account carrying the NEW password with the OLD access still open, and both
 * callers reported success — on a reset performed because an account had been
 * taken, that hands the intruder a live session and tells the owner they are
 * safe.
 *
 * Run: php tests/credential-revoke-atomicity.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\CredentialRevoker;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; return; }
    $fail++; echo "  FAIL {$label}\n";
};

$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) { continue; }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

// Deliberately NOT inside a transaction: this suite is about what happens when
// nobody else has opened one, which is the state both callers run in. A probe
// row is created and removed instead, so nothing existing is touched.
$email = 'zz-atomicity-' . bin2hex(random_bytes(6)) . '@example.invalid';
$userId = 0;

try {
    $stmt = $db->prepare(
        "INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, codice_tessera)
         VALUES ('zz', 'atomicity', ?, 'ORIGINAL', 'standard', 'attivo', ?)"
    );
    $card = 'ZZ' . strtoupper(bin2hex(random_bytes(5)));
    $stmt->bind_param('ss', $email, $card);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();
    $check($userId > 0, 'a probe account exists to act on');

    $passwordOf = static function () use ($db, $userId): string {
        return (string) ($db->query("SELECT password FROM utenti WHERE id = {$userId}")->fetch_row()[0] ?? '');
    };

    echo "\nA. A failure anywhere inside takes the password with it\n";

    $threw = false;
    try {
        CredentialRevoker::atomically($db, static function () use ($db, $userId): void {
            $db->query("UPDATE utenti SET password = 'CHANGED' WHERE id = {$userId}");
            // Stands in for a revocation that cannot run — a missing privilege,
            // a table the upgrade has not created yet, a connection that died
            // between the two statements.
            throw new \RuntimeException('revocation failed');
        });
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check($threw, 'the failure reaches the caller instead of being swallowed');
    $check($passwordOf() === 'ORIGINAL',
        'and the password is back to what it was — a reset that could not revoke is not a reset');

    echo "\nB. A clean run still commits\n";

    CredentialRevoker::atomically($db, static function () use ($db, $userId): void {
        $db->query("UPDATE utenti SET password = 'COMMITTED' WHERE id = {$userId}");
    });
    $check($passwordOf() === 'COMMITTED', 'the guard must not turn every password change into a no-op');

    echo "\nC. It refuses to nest\n";

    // Inside a caller's transaction the helper must not open a second one:
    // begin_transaction() would implicitly commit the caller's work. It runs
    // the body and leaves the commit or rollback to whoever owns it.
    $db->begin_transaction();
    try {
        CredentialRevoker::atomically($db, static function () use ($db, $userId): void {
            $db->query("UPDATE utenti SET password = 'INNER' WHERE id = {$userId}");
        });
        $check($passwordOf() === 'INNER', 'the body still runs inside the caller transaction');
        $db->rollback();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $check($passwordOf() === 'COMMITTED',
        "the caller's rollback still governs — the helper did not commit on its behalf");

    echo "\nD. A real revocation failure is raised, not counted as zero\n";

    // revokeAll() reads information_schema for the optional mobile table and
    // revokes user_sessions. Point it at a database the user cannot read to
    // make the statement genuinely fail: the answer must be an exception, not
    // "nothing to revoke".
    $broken = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $broken->close();
    $raised = false;
    try {
        CredentialRevoker::revokeAll($broken, $userId, false);
    } catch (\Throwable $e) {
        $raised = true;
    }
    $check($raised, 'a revocation that could not run raises instead of answering zero');
} finally {
    if ($userId > 0) {
        $db->query("DELETE FROM user_sessions WHERE utente_id = {$userId}");
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
