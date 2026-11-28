<?php
declare(strict_types=1);

/**
 * Deleting a reader who is referenced by a row that forbids the DELETE.
 *
 * prenotazioni.utente_id, feedback.utente_id and donazioni.donatore_id are
 * ON DELETE RESTRICT, like prestiti.utente_id. UsersController::delete()
 * used to look at loans and ledger rows only, so for a reader with a
 * reservation (even a cancelled one) it ran a DELETE that could only fail,
 * rolled back and answered ?error=db_error: the reader stayed active and
 * could never be removed.
 *
 * Against the real database and the real controller:
 *  - a reader with no reference at all is erased;
 *  - a reader referenced by a reservation (cancelled or live), a feedback
 *    row or a donation is suspended, as a reader with loans always was;
 *  - the answer says which of the two happened: the JSON one for the list's
 *    fetch() call, the redirect plus session flash for a plain form.
 *
 * Run: php tests/user-delete-restrict-history.unit.php
 */

use App\Controllers\UsersController;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
// An E2E password set to the empty string is a password, not a missing one.
$e2ePass = getenv('E2E_DB_PASS');
$dbPass = $e2ePass !== false ? $e2ePass : ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), $dbPass, getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), $dbPass, getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — user delete suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_UDEL_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "  [{$detail}]") . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** @var int[] $userIds */
$userIds = [];
/** @var int[] $bookIds */
$bookIds = [];
$cleanup = static function () use ($db, &$userIds, &$bookIds): void {
    foreach ($userIds as $id) {
        $db->query("DELETE FROM prenotazioni WHERE utente_id = {$id}");
        $db->query("DELETE FROM prestiti WHERE utente_id = {$id}");
        $db->query("DELETE FROM feedback WHERE utente_id = {$id}");
        $db->query("DELETE FROM donazioni WHERE donatore_id = {$id}");
    }
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

// ── Sandbox ──────────────────────────────────────────────────────────────────
$seq = 0;
$mkUser = static function (string $tipo = 'standard') use ($db, $prefix, $run, &$userIds, &$seq): int {
    $n = ++$seq;
    // codice_tessera is a short unique column: run tag plus sequence fits it.
    $card = 'UD' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'UserDelete', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
$title = "{$prefix} book";
$stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, 1, 1, NOW(), NOW())');
$stmt->bind_param('s', $title);
$stmt->execute();
$stmt->close();
$book = (int) $db->insert_id;
$bookIds[] = $book;

$today = DateHelper::today();
$mkReservation = static function (int $user, string $state) use ($db, $book, $today): void {
    $stmt = $db->prepare('INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
                          VALUES (?, ?, ?, ?, ?, 1, ?)');
    $deadline = $today . ' 23:59:59';
    $stmt->bind_param('iissss', $book, $user, $today, $today, $deadline, $state);
    $stmt->execute();
    $stmt->close();
};
$state = static function (int $user) use ($db): ?string {
    $row = $db->query("SELECT stato FROM utenti WHERE id = {$user}")->fetch_row();
    return $row === null ? null : (string) $row[0];
};

$admin = $mkUser('admin');
$_SESSION = ['user' => ['id' => $admin, 'tipo_utente' => 'admin', 'stato' => 'attivo', 'email' => 'udel-admin@example.invalid']];
// The flash a controller left in the session, null when there is none.
$flash = static function (string $key): ?string {
    $value = $_SESSION[$key] ?? null;
    return is_string($value) ? $value : null;
};

/**
 * @return array{status:int, location:string, body:array<string,mixed>|null}
 */
$delete = static function (int $user, bool $json) use ($db): array {
    unset($_SESSION['success_message'], $_SESSION['error_message']);
    $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/users/delete/{$user}");
    if ($json) {
        $request = $request->withHeader('Accept', 'application/json');
    }
    $response = (new UsersController())->delete($request, new SlimResponse(), $db, $user);
    $decoded = json_decode((string) $response->getBody(), true);
    return [
        'status' => $response->getStatusCode(),
        'location' => $response->getHeaderLine('Location'),
        'body' => is_array($decoded) ? $decoded : null,
    ];
};

// ═════════ 1-2: a reader nothing refers to is erased ═════════════════════════
$plain = $mkUser();
$r = $delete($plain, true);
$check($state($plain) === null, '1 a reader with no history is erased');
$check($r['status'] === 200 && ($r['body']['success'] ?? null) === true && ($r['body']['outcome'] ?? null) === 'deleted',
    '2 the JSON answer says "deleted"', json_encode($r));

// ═════════ 3-6: a cancelled reservation is history ═══════════════════════════
$cancelled = $mkUser();
$mkReservation($cancelled, 'annullata');
$r = $delete($cancelled, true);
$check($state($cancelled) === 'sospeso', '3 a reader with only a cancelled reservation is suspended, not left active',
    'stato=' . var_export($state($cancelled), true));
$check($r['status'] === 200 && ($r['body']['success'] ?? null) === true && ($r['body']['outcome'] ?? null) === 'suspended',
    '4 the JSON answer says "suspended", not "deleted" and not an error', json_encode($r));
$check(is_string($r['body']['message'] ?? null) && $r['body']['message'] !== '',
    '5 the JSON answer carries the message the operator reads');
$check($flash('success_message') === null && $flash('error_message') === null,
    '6 a JSON answer leaves no flash message behind for the next page');

// ═════════ 7-9: the plain form keeps its redirect and flash ══════════════════
$live = $mkUser();
$mkReservation($live, 'attiva');
$r = $delete($live, false);
$check($state($live) === 'sospeso', '7 a reader with a live reservation is suspended', 'stato=' . var_export($state($live), true));
$check($r['status'] === 302 && !str_contains($r['location'], 'error=') && str_ends_with($r['location'], '/admin/users'),
    '8 the form answer redirects to the list without an error flag', json_encode($r));
$check($flash('success_message') !== null && $flash('error_message') === null,
    '9 the form answer flashes the "suspended" message, no error');

$plainForm = $mkUser();
$r = $delete($plainForm, false);
$check($state($plainForm) === null && $r['status'] === 302 && $flash('success_message') !== null && $flash('error_message') === null,
    '10 the form answer for a reader with no history: erased, redirect, success flash', json_encode($r));

// ═════════ 11-12: the other RESTRICT references ══════════════════════════════
$withFeedback = $mkUser();
$db->query("INSERT INTO feedback (utente_id, tipo, messaggio) VALUES ({$withFeedback}, 'feedback', 'x')");
$r = $delete($withFeedback, true);
$check($state($withFeedback) === 'sospeso' && ($r['body']['outcome'] ?? null) === 'suspended',
    '11 a reader with a feedback row is suspended', json_encode($r));

$donor = $mkUser();
$db->query("INSERT INTO donazioni (donatore_id, descrizione) VALUES ({$donor}, 'x')");
$r = $delete($donor, true);
$check($state($donor) === 'sospeso' && ($r['body']['outcome'] ?? null) === 'suspended',
    '12 a reader with a donation is suspended', json_encode($r));

// ═════════ 13: the reservation rows are not touched ══════════════════════════
$kept = (int) $db->query("SELECT COUNT(*) FROM prenotazioni WHERE utente_id IN ({$cancelled}, {$live})")->fetch_row()[0];
$liveState = (string) $db->query("SELECT stato FROM prenotazioni WHERE utente_id = {$live}")->fetch_row()[0];
$check($kept === 2 && $liveState === 'attiva', '13 suspending the reader leaves the reservation rows as they were',
    "rows={$kept} live={$liveState}");

// ═════════ 14: only an administrator deletes ═════════════════════════════════
$victim = $mkUser();
$_SESSION['user']['tipo_utente'] = 'staff';
$r = $delete($victim, true);
$check($r['status'] === 403 && $state($victim) === 'attivo', '14 staff still cannot delete a reader', json_encode($r));
$_SESSION['user']['tipo_utente'] = 'admin';

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}  Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
