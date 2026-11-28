<?php
declare(strict_types=1);

/**
 * Mobile API staff reservations: PUT /staff/reservations/{id} moves the two
 * date pairs of a reservation together, and POST …/cancel reports a
 * self-dealing refusal as such.
 *
 * Through the REAL StaffReservationsController and its delegation to
 * ReservationsAdminController::update() / LoanApprovalController::cancelReservation(),
 * against the REAL database:
 *  - sending only the requested window moves the reservation dates too (the
 *    deadline must not stay behind and expire before the window starts);
 *  - sending only the deadline, or only the reservation day, moves the
 *    requested window with it;
 *  - sending both pairs stores both as sent; sending no date changes none;
 *  - an operator cancelling their own reservation gets 403 not_permitted
 *    (reason self_dealing), not a 409 not_eligible.
 *
 * Run: php tests/mobile-staff-reservation-dates.unit.php
 */

use App\Plugins\MobileApi\Controllers\StaffReservationsController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';
// The plugin has no autoloader: its bootstrap requires every class.
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/MobileApiPlugin.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

$root = dirname(__DIR__);
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the staff reservation suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$exit = ConfigStore::usingConnection($db, static function () use ($db): int {
    DateHelper::synchronizeDatabaseSession($db);
    $run = bin2hex(random_bytes(6));
    $prefix = "ZZ_STAFFRES_{$run}";
    $passed = 0;
    $failed = 0;
    $check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
        $ok ? $passed++ : $failed++;
    };

    // Everything the suite inserts, so cleanup can remove it on any exit.
    $created = new class {
        /** @var list<int> */
        public array $books = [];
        /** @var list<int> */
        public array $users = [];
    };
    $sessionBefore = $_SESSION ?? null;
    $cleanup = static function () use ($db, $created, $run, $sessionBefore): void {
        try { $db->rollback(); } catch (Throwable) {}
        foreach ($created->books as $id) {
            $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
            $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
            $db->query("DELETE FROM copie WHERE libro_id = {$id}");
            try { $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}"); } catch (Throwable) {}
            $db->query("DELETE FROM libri WHERE id = {$id}");
        }
        foreach ($created->users as $id) {
            try { $db->query("DELETE FROM notifications WHERE user_id = {$id}"); } catch (Throwable) {}
            $db->query("DELETE FROM utenti WHERE id = {$id}");
        }
        try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-staffres-%-{$run}@test.local'"); } catch (Throwable) {}
        Features::resetOverrides();
        LibraryCalendar::reset();
        if ($sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $sessionBefore;
        }
    };

    try {
        // A library open every day, whatever calendar this database holds.
        Features::override('closures_calendar', false);
        LibraryCalendar::reset();
        $today = DateHelper::today();
        $d = static fn (int $offset): string =>
            (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        $makeUser = static function (string $suffix, string $role = 'standard') use ($db, $run, $created): int {
            $email = "zz-staffres-{$suffix}-{$run}@test.local";
            $card = 'SR' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
            $password = password_hash('StaffRes!1', PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
                 VALUES (?, 'Staffres', ?, ?, ?, ?, 'attivo', 1)"
            );
            $stmt->bind_param('sssss', $card, $suffix, $email, $password, $role);
            $stmt->execute();
            $userId = (int) $db->insert_id;
            $stmt->close();
            $created->users[] = $userId;
            return $userId;
        };
        // One book with one copy on the shelf and one reservation in its queue
        // for a future window, written with both date pairs as the web does.
        $makeReservation = static function (string $suffix, int $user, string $start, string $end) use ($db, $prefix, $created): int {
            $title = "{$prefix}_{$suffix}";
            $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 1, 'disponibile')");
            $stmt->bind_param('s', $title);
            $stmt->execute();
            $book = (int) $db->insert_id;
            $stmt->close();
            $created->books[] = $book;
            $inv = strtoupper("SR{$suffix}-" . substr($prefix, -8));
            $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
            $stmt->bind_param('is', $book, $inv);
            $stmt->execute();
            $stmt->close();
            $startDt = $start . ' 00:00:00';
            $endDt = $end . ' 23:59:59';
            $stmt = $db->prepare(
                "INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 'attiva')"
            );
            $stmt->bind_param('iissss', $book, $user, $startDt, $endDt, $start, $end);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        // [reservation day, deadline day, requested start, requested end, state]
        $stored = static function (int $id) use ($db): array {
            return $db->query(
                "SELECT DATE(data_prenotazione), DATE(data_scadenza_prenotazione), data_inizio_richiesta, data_fine_richiesta, stato
                 FROM prenotazioni WHERE id = {$id}"
            )->fetch_row();
        };
        $call = static function (string $action, int $id, array $json, int $operator) use ($db): array {
            $request = (new ServerRequestFactory())->createServerRequest($action === 'update' ? 'PUT' : 'POST', '/api/v1/staff/reservations/' . $id)
                ->withParsedBody($json)
                ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $operator]);
            $controller = new StaffReservationsController($db);
            $response = $action === 'update'
                ? $controller->update($request, new Response(), $id)
                : $controller->cancel($request, new Response(), $id);
            return [$response->getStatusCode(), (array) json_decode((string) $response->getBody(), true)];
        };

        $admin = $makeUser('adm', 'admin');
        $reader = $makeUser('rdr');
        $_SESSION = ['user' => ['id' => $admin, 'tipo_utente' => 'admin', 'stato' => 'attivo']];

        // ═════════ PUT: the two date pairs move together ═════════
        $reservation = $makeReservation('D', $reader, $d(10), $d(17));

        [$status] = $call('update', $reservation, ['requested_from' => $d(20), 'requested_to' => $d(27)], $admin);
        $check($status === 200, '01 PUT {requested_from, requested_to} is accepted');
        $check($stored($reservation) === [$d(20), $d(27), $d(20), $d(27), 'attiva'],
            '02 the reservation day and the deadline follow the new requested window (stored: ' . implode(' ', $stored($reservation)) . ')');

        [$status] = $call('update', $reservation, ['expires_at' => $d(30)], $admin);
        $check($status === 200 && $stored($reservation) === [$d(20), $d(30), $d(20), $d(30), 'attiva'],
            '03 PUT {expires_at} moves the requested end with the deadline (stored: ' . implode(' ', $stored($reservation)) . ')');

        [$status] = $call('update', $reservation, ['reserved_at' => $d(22)], $admin);
        $check($status === 200 && $stored($reservation) === [$d(22), $d(30), $d(22), $d(30), 'attiva'],
            '04 PUT {reserved_at} moves the requested start with the reservation day (stored: ' . implode(' ', $stored($reservation)) . ')');

        [$status] = $call('update', $reservation, ['requested_to' => $d(28)], $admin);
        $check($status === 200 && $stored($reservation) === [$d(22), $d(28), $d(22), $d(28), 'attiva'],
            '05 PUT {requested_to} moves the deadline with the requested end (stored: ' . implode(' ', $stored($reservation)) . ')');

        [$status] = $call('update', $reservation, [
            'reserved_at' => $d(23), 'expires_at' => $d(33), 'requested_from' => $d(24), 'requested_to' => $d(31),
        ], $admin);
        $check($status === 200 && $stored($reservation) === [$d(23), $d(33), $d(24), $d(31), 'attiva'],
            '06 both pairs sent are stored as sent (stored: ' . implode(' ', $stored($reservation)) . ')');

        [$status] = $call('update', $reservation, ['status' => 'attiva'], $admin);
        $check($status === 200 && $stored($reservation) === [$d(23), $d(33), $d(24), $d(31), 'attiva'],
            '07 a PUT without dates changes none of them');

        // ═════════ cancel: a self-dealing refusal keeps its meaning ═════════
        $staff = $makeUser('stf', 'staff');
        $own = $makeReservation('S', $staff, $d(10), $d(17));
        $_SESSION = ['user' => ['id' => $staff, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
        [$status, $body] = $call('cancel', $own, ['reason' => 'test'], $staff);
        $check($status === 403 && ($body['error']['code'] ?? null) === 'not_permitted' && ($body['meta']['reason'] ?? null) === 'self_dealing',
            '08 an operator cancelling their own reservation gets 403 not_permitted / self_dealing (got: ' . $status . ' ' . ($body['error']['code'] ?? '-') . ')');
        $check($stored($own)[4] === 'attiva', '09 and the reservation is untouched');

        $_SESSION = ['user' => ['id' => $admin, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
        [$status] = $call('cancel', $own, ['reason' => 'test'], $admin);
        $check($status === 200 && $stored($own)[4] === 'annullata', '10 an administrator can still cancel it');

        echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
        $result = $failed === 0 ? 0 : 1;
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL);
        $result = 1;
    }
    // The verdict comes after the teardown: one that fails leaves rows behind
    // for the suites after this one, so it fails the run even when every
    // check passed.
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'FAIL: cleanup: ' . $cleanupError->getMessage() . PHP_EOL);
        $result = 1;
    }
    return $result;
});

$db->close();
exit($exit);
