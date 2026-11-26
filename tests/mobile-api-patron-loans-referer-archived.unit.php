<?php
declare(strict_types=1);

/**
 * Mobile API, a reader's own loans: two places where the app and the website
 * must tell the same truth.
 *
 * 1. GET /me/loans, pending list. A request promoted from the queue holds a
 *    copy (prestiti.copia_id) even after its title was archived, and the
 *    reader keeps receiving reminders for it: the website lists it so it can
 *    be withdrawn, the app hid it behind a strict `deleted_at IS NULL`.
 *    A bare request on an archived title (no copy) stays hidden on both.
 *
 * 2. POST /reservations. The plugin delegates to the website's controller and
 *    reads the outcome from the query string of its redirect. That redirect
 *    goes back to the HTTP Referer, which the app's caller chooses: a request
 *    that the controller REFUSED, sent with
 *    `Referer: /?loan_request_success=1&loan_id=N&auto_approved=1&loan_state=prenotato`,
 *    was answered 201 "approved" with the start date of loan N, whoever N
 *    belonged to. The outcome must come from the controller only, and the
 *    start date only from a loan of the authenticated reader.
 *
 * Run: php tests/mobile-api-patron-loans-referer-archived.unit.php
 */

use App\Plugins\MobileApi\Controllers\ActionsController as MobileActionsController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/AppAuthMiddleware.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/JsonBody.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/Input.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/CirculationOutcome.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php';

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
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mobile patron loans suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_MOBPL_{$run}";
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
$cleanup = static function () use ($db, &$bookIds, &$userIds): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
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
$mkUser = static function () use ($db, $prefix, $run, &$userIds, &$seq): int {
    $n = ++$seq;
    $card = 'MP' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'MobilePatron', ?, ?, 'x', 'attivo', 'standard', 1)");
    $stmt->bind_param('sss', $card, $cognome, $email);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
/** @return array{0:int,1:int,2:string} book id, copy id, title */
$mkBook = static function (string $label) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} {$label}";
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
    return [$bookId, (int) $db->insert_id, $title];
};
$today = DateHelper::today();
$day = static fn (int $offset): string => date('Y-m-d', strtotime("{$today} +{$offset} days"));
$mkLoan = static function (int $book, ?int $copy, int $user, string $state, int $active, string $origin, string $from, string $to) use ($db): int {
    $stmt = $db->prepare('INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iiisssis', $book, $copy, $user, $from, $to, $state, $active, $origin);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$archive = static function (int $book) use ($db): void {
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$book} AND deleted_at IS NULL");
};
$jsonBody = static function ($response): array {
    $decoded = json_decode((string) $response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
};

$reader = $mkUser();
$victim = $mkUser();

// ═════════ 1-4: GET /me/loans, pending requests on archived titles ═══════════
[$heldBook, $heldCopy, $heldTitle] = $mkBook('held archived');
[$bareBook] = $mkBook('bare archived');
[$liveBook, , $liveTitle] = $mkBook('live');
$held = $mkLoan($heldBook, $heldCopy, $reader, 'pendente', 0, 'prenotazione', $today, $day(14));
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$heldCopy}");
$bare = $mkLoan($bareBook, null, $reader, 'pendente', 0, 'richiesta', $today, $day(14));
$live = $mkLoan($liveBook, null, $reader, 'pendente', 0, 'richiesta', $today, $day(14));
$archive($heldBook);
$archive($bareBook);

$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/me/loans')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $reader]);
$response = (new MobileActionsController($db))->myLoans($request, new SlimResponse());
$body = $jsonBody($response);
$pending = [];
foreach (($body['data']['pending'] ?? []) as $row) {
    $pending[(int) $row['id']] = $row;
}
$check($response->getStatusCode() === 200 && isset($pending[$live]) && ($pending[$live]['title'] ?? null) === $liveTitle,
    '1 a pending request on a live title is listed', json_encode(array_keys($pending)));
$check(isset($pending[$held]), '2 a pending request that holds a copy of an archived title is listed, as on the website',
    json_encode(array_keys($pending)));
$check(($pending[$held]['title'] ?? null) === $heldTitle && ($pending[$held]['book_id'] ?? null) === $heldBook,
    '3 with the real title of the archived book', json_encode($pending[$held] ?? null));
$check(!isset($pending[$bare]), '4 a bare request on an archived title, which holds nothing, stays hidden');
$check(($body['meta']['pending_count'] ?? null) === count($pending) && count($pending) === 2, '5 the pending counter matches the list',
    json_encode($body['meta'] ?? null));

// It can be withdrawn from the app, like from the website.
$_SESSION = ['user' => ['id' => $reader, 'tipo_utente' => 'standard', 'stato' => 'attivo']];
unset($_SERVER['HTTP_REFERER']);
$request = (new ServerRequestFactory())->createServerRequest('DELETE', "/api/v1/loans/{$held}")
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $reader]);
$response = (new MobileActionsController($db))->cancelLoan($request, new SlimResponse(), $held);
$left = $db->query("SELECT stato FROM prestiti WHERE id = {$held}")->fetch_row();
$check($response->getStatusCode() === 200 && ($left === null || (string) $left[0] !== 'pendente'),
    '6 and the reader can withdraw it from the app', $response->getStatusCode() . ' ' . json_encode($left));

// ═════════ 7-12: POST /reservations does not take its outcome from the Referer
[$victimBook, $victimCopy] = $mkBook('victim');
$victimStart = $day(9);
$victimLoan = $mkLoan($victimBook, $victimCopy, $victim, 'prenotato', 1, 'richiesta', $victimStart, $day(23));
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$victimCopy}");

// The reader already has a request for this title: a second one is refused as a duplicate.
[$dupBook] = $mkBook('duplicate');
$mkLoan($dupBook, null, $reader, 'pendente', 0, 'richiesta', $today, $day(14));
$before = (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$dupBook}")->fetch_row()[0];

$forged = "/?loan_request_success=1&loan_id={$victimLoan}&auto_approved=1&loan_state=prenotato";
$_SERVER['HTTP_REFERER'] = $forged;
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/reservations')
    ->withHeader('Referer', $forged)
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $reader])
    ->withParsedBody(['book_id' => $dupBook]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$raw = (string) $response->getBody();
$body = $jsonBody($response);
$check($response->getStatusCode() === 409 && ($body['error']['code'] ?? null) === 'duplicate',
    '7 a refused request stays refused whatever the Referer claims', $response->getStatusCode() . ' ' . $raw);
$check(($body['data'] ?? null) === null && !str_contains($raw, $victimStart) && !str_contains($raw, (string) $victimLoan),
    "8 nothing of another reader's loan is in the answer", $raw);
$check((int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$dupBook}")->fetch_row()[0] === $before,
    '9 and no request was written');
$serverValue = static fn (string $key): mixed => $_SERVER[$key] ?? null;
$check($serverValue('HTTP_REFERER') === $forged, '10 the request\'s own Referer is left as it was for the rest of the request');

// The same forged Referer on a reservation: a future date on a title the reader already waits for.
$_SERVER['HTTP_REFERER'] = '/?reserve_success=1';
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/reservations')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $reader])
    ->withParsedBody(['book_id' => $dupBook, 'desired_date' => $day(30)]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$body = $jsonBody($response);
$check($response->getStatusCode() === 409 && ($body['error']['code'] ?? null) === 'duplicate'
    && (int) $db->query("SELECT COUNT(*) FROM prenotazioni WHERE libro_id = {$dupBook}")->fetch_row()[0] === 0,
    '11 a refused reservation stays refused too', $response->getStatusCode() . ' ' . (string) $response->getBody());

// An honest request still succeeds, with a Referer or without.
[$freeBook] = $mkBook('free');
$_SERVER['HTTP_REFERER'] = '/some/page?x=1';
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/reservations')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $reader])
    ->withParsedBody(['book_id' => $freeBook]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$body = $jsonBody($response);
$created = $db->query("SELECT id FROM prestiti WHERE libro_id = {$freeBook} AND utente_id = {$reader}")->fetch_row();
$check($response->getStatusCode() === 201 && ($body['data']['type'] ?? null) === 'loan' && $created !== null
    && ($body['data']['loan_id'] ?? null) === (int) $created[0],
    '12 an accepted request answers 201 with the id of the loan it created', $response->getStatusCode() . ' ' . (string) $response->getBody());

// ═════════ 13-14: the start date is read only from the reader's own loan ═════
$startOf = new ReflectionMethod(MobileActionsController::class, 'loanStartDate');
$controller = new MobileActionsController($db);
$check($startOf->getNumberOfParameters() === 2 && $startOf->invoke($controller, $victimLoan, $reader) === null,
    "13 another reader's loan has no start date for this reader");
$check($startOf->getNumberOfParameters() === 2 && $startOf->invoke($controller, $victimLoan, $victim) === $victimStart,
    '14 the owner reads the start date of the own loan');

unset($_SERVER['HTTP_REFERER']);
$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}  Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
