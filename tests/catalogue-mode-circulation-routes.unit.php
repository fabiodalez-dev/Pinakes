<?php
declare(strict_types=1);

/**
 * Catalogue-only mode on the routes that create or change a loan request or a
 * reservation.
 *
 * The mode ("no loans, no reservations, no wishlist") was enforced on the
 * admin loan routes and in the Mobile API, but the website's own endpoints
 * and the admin reservation routes still wrote rows: a reader's POST to
 * /user/loan answered loan_request_success and left a `pendente` request that
 * staff, whose loan screens are closed in that mode, could never process.
 *
 * This drives the REAL Slim application (app/Routes/web.php with its
 * middlewares) against a throwaway sandbox and proves, route by route:
 *  - with the mode on, the route refuses (a redirect for a form, a JSON 404
 *    feature_disabled for the API) and writes nothing;
 *  - with the mode off, the same request still does its job;
 *  - cancelling stays possible with the mode on: a reader must be able to
 *    withdraw what was requested before the switch.
 *
 * Run: php tests/catalogue-mode-circulation-routes.unit.php
 */

use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\RouteTranslator;
use App\Support\SessionRoleRevalidator;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

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
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbPort = (string) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? '3306'));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — catalogue mode route suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}
// The route middlewares re-read the session's account through a connection of
// their own (config/settings.php): point it at the same sandbox database.
putenv('DB_HOST=' . ($socket !== '' && file_exists($socket) ? 'localhost' : $dbHost));
putenv('DB_USER=' . $dbUser);
putenv('DB_PASS=' . $dbPass);
putenv('DB_NAME=' . $dbName);
putenv('DB_PORT=' . $dbPort);
if ($socket !== '' && file_exists($socket)) {
    putenv('DB_SOCKET=' . $socket);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_CATMODE_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "  [{$detail}]") . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** @var int[] $bookIds */
$bookIds = [];
/** @var int[] $userIds */
$userIds = [];
$catalogueRow = $db->query("SELECT setting_value FROM system_settings WHERE category = 'system' AND setting_key = 'catalogue_mode'")->fetch_row();
$cleanup = static function () use ($db, &$bookIds, &$userIds, $catalogueRow, $prefix): void {
    foreach ($bookIds as $id) {
        try { $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}"); } catch (Throwable) {}
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE '" . strtolower($prefix) . "-%@example.invalid'"); } catch (Throwable) {}
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
    // Catalogue mode is restored to exactly what this run found.
    if ($catalogueRow === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'system' AND setting_key = 'catalogue_mode'");
    } else {
        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category = 'system' AND setting_key = 'catalogue_mode'");
        $value = (string) $catalogueRow[0];
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->close();
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

// The handlers email after the commit: stop before any SMTP contact.
$smtpProbe = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$smtpProbe->setValue(null, false);

// ── Sandbox ──────────────────────────────────────────────────────────────────
$seq = 0;
$mkUser = static function (string $tipo) use ($db, $prefix, $run, &$userIds, &$seq): int {
    $n = ++$seq;
    $card = 'CM' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'CatalogueMode', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
/** @return array{0:int,1:int} book id, copy id */
$mkBook = static function () use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} book " . (count($bookIds) + 1);
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, 'disponibile', 1, 1, NOW(), NOW())");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $code = "{$prefix}-{$bookId}";
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
    $stmt->bind_param('is', $bookId, $code);
    $stmt->execute();
    $stmt->close();
    return [$bookId, (int) $db->insert_id];
};
$today = DateHelper::today();
$day = static fn (int $offset): string => date('Y-m-d', strtotime("{$today} +{$offset} days"));
$mkReservation = static function (int $book, int $user, int $from, int $to) use ($db, $day): int {
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
                          VALUES (?, ?, ?, ?, ?, 1, 'attiva')");
    $start = $day($from);
    $end = $day($to);
    $deadline = $end . ' 23:59:59';
    $stmt->bind_param('iisss', $book, $user, $start, $end, $deadline);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$mkPendingLoan = static function (int $book, int $user) use ($db, $day): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine) VALUES (?, ?, ?, ?, 'pendente', 0, 'richiesta')");
    $start = $day(0);
    $end = $day(14);
    $stmt->bind_param('iiss', $book, $user, $start, $end);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$commitments = static function (int $book, int $user) use ($db): int {
    return (int) $db->query("SELECT (SELECT COUNT(*) FROM prestiti WHERE libro_id = {$book} AND utente_id = {$user})
                                  + (SELECT COUNT(*) FROM prenotazioni WHERE libro_id = {$book} AND utente_id = {$user})")->fetch_row()[0];
};
$reservationRow = static function (int $id) use ($db): string {
    $row = $db->query("SELECT stato, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione FROM prenotazioni WHERE id = {$id}")->fetch_row();
    return json_encode($row);
};

$admin = $mkUser('admin');
$reader = $mkUser('standard');

$fatal = null;
try {
    // Settings are read from the sandbox database, never from the checkout's own.
    ConfigStore::usingConnection($db, static function () use (
        $db, $root, $check, $admin, $reader, $mkBook, $mkReservation, $mkPendingLoan, $commitments, $reservationRow, $day
    ): void {
        $container = new \DI\Container([
            'db' => $db,
            'hookManager' => new \App\Support\HookManager($db),
        ]);
        $app = new \Slim\App(new \Slim\Psr7\Factory\ResponseFactory(), $container);
        (require $root . '/app/Routes/web.php')($app);

        $settings = new SettingsRepository($db);
        $setCatalogueMode = static function (bool $on) use ($settings): void {
            $settings->set('system', 'catalogue_mode', $on ? '1' : '0');
            ConfigStore::clearCache();
        };
        $as = static function (int $userId, string $role): void {
            $_SESSION = [
                'user' => ['id' => $userId, 'tipo_utente' => $role, 'stato' => 'attivo'],
                'csrf_token' => 'catalogue-mode-suite',
                'csrf_token_time' => time(),
            ];
            SessionRoleRevalidator::reset();
        };
        /**
         * One request through the real application.
         *
         * @param array<string,mixed> $body
         * @return array{status:int, location:string, json:array<string,mixed>|null}
         */
        $post = static function (string $path, array $body, bool $json = false) use ($app): array {
            $_SERVER['HTTP_REFERER'] = '/';
            unset($_SESSION['error_message'], $_SESSION['success_message']);
            $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
            if ($json) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('Accept', 'application/json')
                    ->withHeader('X-CSRF-Token', 'catalogue-mode-suite')
                    ->withBody((new StreamFactory())->createStream((string) json_encode($body)));
            } else {
                $request = $request->withParsedBody($body + ['csrf_token' => 'catalogue-mode-suite']);
            }
            $response = $app->handle($request);
            $decoded = json_decode((string) $response->getBody(), true);
            return [
                'status' => $response->getStatusCode(),
                'location' => $response->getHeaderLine('Location'),
                'json' => is_array($decoded) ? $decoded : null,
            ];
        };

        $patronHome = RouteTranslator::route('user_dashboard');
        $adminHome = url('/admin/dashboard');
        $apiBook = RouteTranslator::getRouteForLocale('api_book', \App\Support\I18n::getInstallationLocale());
        $refusedPatron = static fn (array $r): bool => $r['status'] === 302 && $r['location'] === $patronHome;
        $refusedAdmin = static fn (array $r): bool => $r['status'] === 302 && $r['location'] === $adminHome;

        // ═════════ POST /user/loan ═══════════════════════════════════════════
        [$loanBook] = $mkBook();
        $as($reader, 'standard');
        $setCatalogueMode(true);
        $r = $post('/user/loan', ['libro_id' => $loanBook]);
        $check($refusedPatron($r) && $commitments($loanBook, $reader) === 0,
            '1 catalogue mode: POST /user/loan is refused and writes no request', json_encode($r));
        $setCatalogueMode(false);
        $r = $post('/user/loan', ['libro_id' => $loanBook]);
        $check($commitments($loanBook, $reader) === 1 && str_contains($r['location'], 'loan_request_success'),
            '2 mode off: POST /user/loan still creates the request', json_encode($r));

        // ═════════ POST /user/reserve ════════════════════════════════════════
        [$reserveBook] = $mkBook();
        $setCatalogueMode(true);
        $r = $post('/user/reserve', ['libro_id' => $reserveBook, 'desired_date' => $day(20)]);
        $check($refusedPatron($r) && $commitments($reserveBook, $reader) === 0,
            '3 catalogue mode: POST /user/reserve is refused and writes no reservation', json_encode($r));
        $setCatalogueMode(false);
        $r = $post('/user/reserve', ['libro_id' => $reserveBook, 'desired_date' => $day(20)]);
        $check($commitments($reserveBook, $reader) === 1 && str_contains($r['location'], 'reserve_success'),
            '4 mode off: POST /user/reserve still creates the reservation', json_encode($r));

        // ═════════ POST /reservation/change-date ═════════════════════════════
        [$moveBook] = $mkBook();
        $moved = $mkReservation($moveBook, $reader, 30, 40);
        $before = $reservationRow($moved);
        $setCatalogueMode(true);
        $r = $post('/reservation/change-date', ['reservation_id' => $moved, 'desired_date' => $day(50)]);
        $check($refusedPatron($r) && $reservationRow($moved) === $before,
            '5 catalogue mode: POST /reservation/change-date is refused and leaves the reservation alone', json_encode($r));
        $setCatalogueMode(false);
        $r = $post('/reservation/change-date', ['reservation_id' => $moved, 'desired_date' => $day(50)]);
        $check($reservationRow($moved) !== $before && str_contains($r['location'], 'updated=1'),
            '6 mode off: POST /reservation/change-date still moves the reservation', json_encode($r) . ' ' . $reservationRow($moved));

        // ═════════ POST {api_book}/{id}/reservation (JSON) ═══════════════════
        [$apiBookId] = $mkBook();
        $setCatalogueMode(true);
        $r = $post("{$apiBook}/{$apiBookId}/reservation", ['start_date' => $day(5)], true);
        $check($r['status'] === 404 && ($r['json']['code'] ?? null) === 'feature_disabled' && ($r['json']['success'] ?? null) === false
            && is_string($r['json']['message'] ?? null) && $r['location'] === '' && $commitments($apiBookId, $reader) === 0,
            '7 catalogue mode: the JSON reservation endpoint answers JSON 404 feature_disabled and writes nothing', json_encode($r));
        $setCatalogueMode(false);
        $r = $post("{$apiBook}/{$apiBookId}/reservation", ['start_date' => $day(5)], true);
        $check($r['status'] === 200 && ($r['json']['success'] ?? null) === true && $commitments($apiBookId, $reader) === 1,
            '8 mode off: the JSON reservation endpoint still creates the request', json_encode($r));

        // ═════════ POST /admin/reservations/create ═══════════════════════════
        [$adminBook] = $mkBook();
        $as($admin, 'admin');
        $setCatalogueMode(true);
        $form = ['libro_id' => $adminBook, 'utente_id' => $reader, 'data_prenotazione' => $day(10), 'data_scadenza' => $day(20)];
        $r = $post('/admin/reservations/create', $form);
        $check($refusedAdmin($r) && $commitments($adminBook, $reader) === 0,
            '9 catalogue mode: POST /admin/reservations/create is refused and writes no reservation', json_encode($r));
        $setCatalogueMode(false);
        $r = $post('/admin/reservations/create', $form);
        $check($commitments($adminBook, $reader) === 1 && !str_contains($r['location'], 'error='),
            '10 mode off: POST /admin/reservations/create still creates the reservation', json_encode($r));

        // ═════════ POST /admin/reservations/update/{id} ══════════════════════
        [$editBook] = $mkBook();
        $edited = $mkReservation($editBook, $reader, 30, 40);
        $before = $reservationRow($edited);
        $form = ['stato' => 'attiva', 'data_prenotazione' => $day(60), 'data_scadenza_prenotazione' => $day(70),
                 'data_inizio_richiesta' => $day(60), 'data_fine_richiesta' => $day(70)];
        $setCatalogueMode(true);
        $r = $post("/admin/reservations/update/{$edited}", $form);
        $check($refusedAdmin($r) && $reservationRow($edited) === $before,
            '11 catalogue mode: POST /admin/reservations/update is refused and leaves the reservation alone', json_encode($r));
        $setCatalogueMode(false);
        $r = $post("/admin/reservations/update/{$edited}", $form);
        $check($reservationRow($edited) !== $before && !str_contains($r['location'], 'error='),
            '12 mode off: POST /admin/reservations/update still changes the reservation', json_encode($r) . ' ' . $reservationRow($edited));

        // ═════════ POST /prestiti/update/{id}: the legacy alias of a guarded route
        [$aliasBook] = $mkBook();
        $aliasLoan = $mkPendingLoan($aliasBook, $reader);
        $setCatalogueMode(true);
        $guarded = $post("/admin/loans/update/{$aliasLoan}", []);
        $alias = $post("/prestiti/update/{$aliasLoan}", []);
        $check($refusedAdmin($guarded) && $refusedAdmin($alias),
            '13 catalogue mode: /prestiti/update is closed like /admin/loans/update, of which it is an alias',
            json_encode([$guarded, $alias]));
        $setCatalogueMode(false);
        $alias = $post("/prestiti/update/{$aliasLoan}", []);
        $check(!$refusedAdmin($alias), '14 mode off: /prestiti/update reaches its handler', json_encode($alias));

        // ═════════ cancelling stays open in catalogue mode ═══════════════════
        [$cancelBook] = $mkBook();
        [$cancelLoanBook] = $mkBook();
        $toCancel = $mkReservation($cancelBook, $reader, 30, 40);
        $loanToCancel = $mkPendingLoan($cancelLoanBook, $reader);
        $as($reader, 'standard');
        $setCatalogueMode(true);
        $r = $post('/reservation/cancel', ['reservation_id' => $toCancel]);
        $state = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$toCancel}")->fetch_row()[0];
        $check($state === 'annullata' && !$refusedPatron($r),
            '15 catalogue mode: a reader can still cancel a reservation', json_encode($r) . " stato={$state}");
        $r = $post('/loan/cancel', ['loan_id' => $loanToCancel]);
        $left = $db->query("SELECT stato FROM prestiti WHERE id = {$loanToCancel}")->fetch_row();
        $check(($left === null || (string) $left[0] !== 'pendente') && !$refusedPatron($r),
            '16 catalogue mode: a reader can still withdraw a pending loan request', json_encode($r) . ' left=' . json_encode($left));
        $setCatalogueMode(false);

        // ═════════ the refusal runs after authentication ═════════════════════
        $_SESSION = [];
        SessionRoleRevalidator::reset();
        $setCatalogueMode(true);
        $r = $post('/user/loan', ['libro_id' => $loanBook]);
        $check($r['status'] === 302 && str_contains($r['location'], 'auth_required'),
            '17 a visitor who is not signed in is sent to the login, not told how the library is configured', json_encode($r));
        $setCatalogueMode(false);
    });
} catch (Throwable $e) {
    $fatal = $e;
}

$cleanup();
$db->close();

if ($fatal !== null) {
    fwrite(STDERR, 'FAIL: uncaught ' . $fatal->getMessage() . ' @ ' . $fatal->getFile() . ':' . $fatal->getLine() . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "Passed: {$passed}  Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
