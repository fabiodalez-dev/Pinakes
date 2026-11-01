<?php
declare(strict_types=1);

/**
 * Renewals: when a scheduled loan or a reservation on every copy begins
 * inside the extension window, every renewal surface says until when the
 * renewal is still possible instead of a bare "another loan or reservation
 * occupies the period", and lets the operator renew until that day.
 *
 * Companion of loan-window-cap.unit.php / loan-window-surfaces.unit.php for
 * new requests. Covered here, against the real database with a throwaway
 * sandbox:
 *   - LoanWindow::renewalCap() and its sentence (bounded, one day, none);
 *   - PrestitiController::renew(): the refusal carries max_extension_days,
 *     max_due_date, blocked_from and loan_id; new_due_date renews until that
 *     day; an out-of-bounds new_due_date is refused; a later loan on the
 *     loan's own copy (second copy free) names the day the copy is needed;
 *   - PrestitiController::bulkExtend(): the conflict names the loan and the
 *     longest extension still possible;
 *   - the Mobile API outcome mapper and the two views read those figures.
 *
 * Run: php tests/loan-renewal-window.unit.php
 */

use App\Controllers\PrestitiController;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Controllers\StaffCirculationController;
use App\Plugins\NcipServer\NcipServerPlugin;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\HookManager;
use App\Support\I18n;
use App\Support\LoanWindow;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
foreach (['ResponseEnvelope', 'CirculationOutcome', 'StaffLoanReader', 'StaffPaging', 'JsonBody', 'Delegation'] as $support) {
    require_once $root . '/storage/plugins/mobile-api/src/Support/' . $support . '.php';
}
require_once $root . '/storage/plugins/mobile-api/src/Controllers/StaffCirculationController.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

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
    fwrite(STDERR, "FAIL: database unreachable — renewal window suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_LWRNW_{$run}";
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
$mkUser = static function (int $n, string $tipo = 'standard') use ($db, $prefix, $run, &$userIds): int {
    // codice_tessera is a short column: a run tag plus the sequence fits it.
    $card = 'LR' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'LoanWindow', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
$mkBook = static function (int $copies, int $n) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} book {$n}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "{$prefix}-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};
$mkLoan = static function (int $bookId, ?int $copyId, int $userId, string $stato, string $from, string $to) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, renewals, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1, 0, 0, 0)");
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $from, $to, $stato);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$addDays = static fn (string $ymd, int $days): string => (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . "{$days} days")->format('Y-m-d');
$redirectParams = static function ($response): array {
    $query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);
    return $params;
};
$loanRow = static fn (int $id): array => $db->query("SELECT data_scadenza, renewals FROM prestiti WHERE id = {$id}")->fetch_assoc() ?: [];

$settings = new SettingsRepository($db);
$renewDays = $settings->loanDurationDays();
$maxRenewals = (int) ($settings->get('loans', 'max_renewals', '3') ?? 3);
if ($maxRenewals === 0) {
    echo "SKIP: loans.max_renewals is 0 on this installation: renew() refuses every renewal before any window is computed." . PHP_EOL;
    exit(0);
}
$today = DateHelper::today();
// The borrower's loan is due in two days; the other patron's scheduled loan
// starts a few days later, inside the standard renewal.
$dueDate = $addDays($today, 2);
$offset = max(2, min(5, $renewDays - 1));
$conflictStart = $addDays($dueDate, $offset);
$expectedMaxDue = $addDays($conflictStart, -1);
$expectedMaxDays = $offset - 1;

$borrower = $mkUser(1);
$otherUser = $mkUser(2);
$admin = $mkUser(3, 'admin');
[$book, $copies] = $mkBook(1, 1);
$ownLoan = $mkLoan($book, $copies[0], $borrower, 'in_corso', $addDays($today, -10), $dueDate);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copies[0]}");
$mkLoan($book, $copies[0], $otherUser, 'prenotato', $conflictStart, $addDays($conflictStart, 14));
echo "Sandbox: today {$today}, own loan due {$dueDate}, scheduled loan from {$conflictStart}, renewal {$renewDays} days" . PHP_EOL;

$asAdmin = static function () use ($admin): void {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $admin];
};
$renew = static function (int $loanId, array $body = []) use ($db, $asAdmin, $redirectParams): array {
    $asAdmin();
    $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/loans/renew/{$loanId}")->withParsedBody($body);
    $response = (new PrestitiController())->renew($request, new SlimResponse(), $db, $loanId);
    return $redirectParams($response);
};

// ═════════ 1-3: the helper ═══════════════════════════════════════════════════
$cap = LoanWindow::renewalCap($dueDate, [$conflictStart, $addDays($conflictStart, 1)]);
$check($cap !== null
    && $cap['first_conflict_date'] === $conflictStart
    && $cap['max_due_date'] === $expectedMaxDue
    && $cap['max_extension_days'] === $expectedMaxDays,
    "1 LoanWindow::renewalCap bounds the renewal to {$expectedMaxDays} days ending {$expectedMaxDue}",
    json_encode($cap));
$next = LoanWindow::renewalCap($dueDate, [$addDays($dueDate, 1)]);
$check($next !== null && $next['max_extension_days'] === 0 && $next['max_due_date'] === $dueDate
    && LoanWindow::renewalCap($dueDate, []) === null
    && LoanWindow::renewalCap($dueDate, [$addDays($dueDate, -3)]) === null,
    '2 a conflict on the very next day leaves 0 days; nothing bounds an empty or earlier list');
$sentence = LoanWindow::explainRenewal($expectedMaxDays, $expectedMaxDue, $conflictStart);
$none = LoanWindow::explainRenewal(0, $dueDate, $addDays($dueDate, 1));
$check(str_contains($sentence, (string) $expectedMaxDays)
    && $none !== $sentence && str_contains($none, substr($addDays($dueDate, 1), 0, 4)) && !str_contains($none, ' 0 ')
    && str_contains(LoanWindow::explainRenewal(1, $dueDate, $addDays($dueDate, 2)), '1 ')
    && LoanWindow::explainRenewalFromParams(['max_extension_days' => (string) $expectedMaxDays, 'max_due_date' => $expectedMaxDue, 'blocked_from' => $conflictStart]) === $sentence
    && LoanWindow::explainRenewalFromParams(['max_extension_days' => 'x', 'max_due_date' => $expectedMaxDue, 'blocked_from' => $conflictStart]) === null,
    '3 the sentence names the days (plural, singular, none) and is rebuilt from validated redirect parameters only',
    $sentence);

// ═════════ 4-7: the admin renewal ════════════════════════════════════════════
$params = $renew($ownLoan);
$check(($params['error'] ?? null) === 'extension_conflicts'
    && (int) ($params['loan_id'] ?? 0) === $ownLoan
    && (int) ($params['max_extension_days'] ?? -1) === $expectedMaxDays
    && ($params['max_due_date'] ?? null) === $expectedMaxDue
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '4 renew() across the scheduled loan: extension_conflicts with loan_id, max_extension_days, max_due_date, blocked_from',
    json_encode($params));
$before = $loanRow($ownLoan);
$check(($before['data_scadenza'] ?? null) === $dueDate && (int) ($before['renewals'] ?? -1) === 0,
    '5 the refused renewal changed nothing');

$params = $renew($ownLoan, ['new_due_date' => $addDays($dueDate, -1)]);
$params2 = $renew($ownLoan, ['new_due_date' => $addDays($dueDate, $renewDays + 1)]);
$params3 = $renew($ownLoan, ['new_due_date' => 'not-a-date']);
$check(($params['error'] ?? null) === 'invalid_renewal_date'
    && ($params2['error'] ?? null) === 'invalid_renewal_date'
    && ($params3['error'] ?? null) === 'invalid_date_format',
    '6 new_due_date on or before the current due date, past the standard renewal, or malformed is refused',
    json_encode([$params, $params2, $params3]));

$params = $renew($ownLoan, ['new_due_date' => $expectedMaxDue]);
$after = $loanRow($ownLoan);
$check(($params['renewed'] ?? null) === '1'
    && ($after['data_scadenza'] ?? null) === $expectedMaxDue
    && (int) ($after['renewals'] ?? 0) === 1,
    "7 renew() with new_due_date {$expectedMaxDue} (the day before the scheduled loan) renews until that day",
    json_encode([$params, $after]));
// Back to the original due date for the checks below.
$db->query("UPDATE prestiti SET data_scadenza = '{$dueDate}', renewals = 0 WHERE id = {$ownLoan}");

// ═════════ 8: a later loan on the loan's own copy (second copy free) ═════════
[$twoBook, $twoCopies] = $mkBook(2, 2);
$twoLoan = $mkLoan($twoBook, $twoCopies[0], $borrower, 'in_corso', $addDays($today, -10), $dueDate);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$twoCopies[0]}");
$mkLoan($twoBook, $twoCopies[0], $otherUser, 'prenotato', $conflictStart, $addDays($conflictStart, 14));
$copyFrom = (new CapacityService($db))->firstCopyCommitmentAfter($twoCopies[0], $twoLoan, $dueDate);
$params = $renew($twoLoan);
$check($copyFrom === $conflictStart
    && ($params['error'] ?? null) === 'extension_conflicts'
    && ($params['blocked_from'] ?? null) === $conflictStart
    && ($params['max_due_date'] ?? null) === $expectedMaxDue,
    '8 two copies, the loan\'s own copy needed by a later loan: the refusal names the day the copy is needed from',
    json_encode(['copyFrom' => $copyFrom, 'params' => $params]));

// ═════════ 9: the bulk extension ═════════════════════════════════════════════
$asAdmin();
$request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/loans/bulk-extend')
    ->withParsedBody(['ids' => [(string) $ownLoan], 'days' => (string) ($offset + 2)]);
$response = (new PrestitiController())->bulkExtend($request, new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'bulk_extend_conflict'
    && (int) ($params['loan_id'] ?? 0) === $ownLoan
    && (int) ($params['max_extension_days'] ?? -1) === $expectedMaxDays
    && ($params['max_due_date'] ?? null) === $expectedMaxDue
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '9 bulkExtend across the scheduled loan: bulk_extend_conflict names the loan and the longest extension still possible',
    $response->getHeaderLine('Location'));
$check(($loanRow($ownLoan)['data_scadenza'] ?? null) === $dueDate, '10 the refused batch changed nothing');

// ═════════ 11-13: the other surfaces read the same figures (source contracts)
$indexSrc = (string) file_get_contents($root . '/app/Views/prestiti/index.php');
$bookSrc = (string) file_get_contents($root . '/app/Views/libri/scheda_libro.php');
$check(str_contains($indexSrc, 'LoanWindow::explainRenewalFromParams($_GET)')
    && str_contains($indexSrc, 'name="new_due_date"')
    && str_contains($indexSrc, 'LoanWindow::displayDate($renewalMaxDue)')
    && str_contains($indexSrc, "case 'invalid_renewal_date':")
    && str_contains($bookSrc, 'LoanWindow::explainRenewalFromParams($_GET)')
    && str_contains($bookSrc, 'name="new_due_date"')
    && str_contains($bookSrc, 'LoanWindow::displayDate($renewalMaxDue)')
    && str_contains($bookSrc, "case 'invalid_renewal_date':"),
    '11 the loans list and the book page explain the refusal and offer "renew until D" with new_due_date');
$outcomeSrc = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Support/CirculationOutcome.php');
$staffSrc = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/StaffCirculationController.php');
$check(str_contains($outcomeSrc, 'LoanWindow::explainRenewalFromParams($details)')
    && str_contains($outcomeSrc, "\$meta['max_due_date']")
    && str_contains($staffSrc, "'new_due_date'")
    && substr_count($staffSrc, 'self::windowDetails($outcome)') === 2,
    '12 the Mobile API carries the figures in meta on renew and bulk extend, and accepts new_due_date');
$ncipSrc = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');
// The closures calendar (0.9.0) passes the calendar as a further argument.
$check(str_contains($ncipSrc, 'LoanWindow::renewalCap($currentDue, $blocked')
    && str_contains($ncipSrc, 'renewal possible at most until'),
    '13 NCIP RenewItem names until when the renewal is still possible in its ProblemDetail');

// Exercise the API boundary, not only the presence of source strings: a
// syntactically valid but out-of-range date used to become an unmapped 500.
$staff = new StaffCirculationController($db);
$staffRenew = static function (int $id, array $body = []) use ($staff, $asAdmin): array {
    $asAdmin();
    $request = (new ServerRequestFactory())->createServerRequest('POST', "/api/v1/staff/loans/{$id}/renew")
        ->withParsedBody($body);
    $response = $staff->renew($request, new SlimResponse(), $id);
    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
};
foreach ([$dueDate, $addDays($dueDate, -1), $addDays($dueDate, $renewDays + 1), 'bad-date', ['date' => $dueDate]] as $invalidDue) {
    [$status, $body] = $staffRenew($ownLoan, ['new_due_date' => $invalidDue]);
    $expectedMessage = is_string($invalidDue) && DateHelper::isISODateFormat($invalidDue)
        ? __('La data scelta non rientra nel periodo di rinnovo consentito. Aggiorna la pagina e riprova.')
        : null;
    $check($status === 422 && ($body['error']['code'] ?? '') === 'validation_error'
        && ($body['meta']['field'] ?? '') === 'new_due_date'
        && ($expectedMessage === null || ($body['error']['message'] ?? '') === $expectedMessage)
        && $loanRow($ownLoan) === $before,
        '14 invalid renewal dates return 422 for new_due_date without changing the loan', json_encode([$status, $body]));
}

// The assigned copy becomes busy BEFORE the whole book is full. A suggested
// renewal that only considered the later book-wide conflict would fail again
// when submitted. All surfaces must propose the earlier, actually usable day.
$laterConflict = $addDays($conflictStart, 3);
$mkLoan($twoBook, $twoCopies[1], $admin, 'prenotato', $laterConflict, $addDays($laterConflict, 14));
$capacity = new CapacityService($db);
$blocked = $capacity->renewalConflictDates($twoBook, $twoLoan, $twoCopies[0], $dueDate, $addDays($dueDate, $renewDays));
$check(min($blocked) === $conflictStart && in_array($laterConflict, $blocked, true)
    && $capacity->renewalConflictDates($twoBook, $twoLoan, $twoCopies[0], $dueDate, $addDays($dueDate, 1)) === [],
    '15 renewal conflict dates combine the copy and book limits inside the proposed extension');
$params = $renew($twoLoan);
$check(($params['blocked_from'] ?? '') === $conflictStart && ($params['max_due_date'] ?? '') === $expectedMaxDue,
    '16 web renewal proposes the earlier copy limit when both gates conflict', json_encode($params));
[$status, $body] = $staffRenew($twoLoan);
$check($status === 409 && ($body['meta']['max_due_date'] ?? '') === $expectedMaxDue
    && ($body['meta']['first_conflict_date'] ?? '') === $conflictStart,
    '17 Mobile renewal names the same earlier copy limit', json_encode([$status, $body]));
$asAdmin();
$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/staff/loans/bulk-extend')
    ->withParsedBody(['ids' => [$twoLoan], 'days' => $renewDays]);
$response = $staff->bulkExtend($request, new SlimResponse());
$body = json_decode((string) $response->getBody(), true);
$check($response->getStatusCode() === 409 && ($body['meta']['max_due_date'] ?? '') === $expectedMaxDue
    && ($body['meta']['loan_id'] ?? 0) === $twoLoan,
    '18 Mobile bulk extension proposes the earlier copy limit and names the loan', json_encode($body));

$db->query("UPDATE prestiti SET origine = 'ncip' WHERE id = {$twoLoan}");
$ncip = new NcipServerPlugin($db, new HookManager($db));
$extend = new ReflectionMethod(NcipServerPlugin::class, 'extendLoan');
$failureReason = null;
$ncipResult = $extend->invokeArgs($ncip, [$twoLoan, $twoBook, $borrower, &$failureReason]);
$ncipCap = (new ReflectionProperty(NcipServerPlugin::class, 'renewalCap'))->getValue($ncip);
$check($ncipResult === null && $failureReason === 'no_capacity' && ($ncipCap['max_due_date'] ?? '') === $expectedMaxDue
    && ($ncipCap['first_conflict_date'] ?? '') === $conflictStart,
    '19 NCIP computes the same earlier copy limit', json_encode([$failureReason, $ncipCap]));
$handleRenew = new ReflectionMethod(NcipServerPlugin::class, 'handleRenewItem');
$xml = new SimpleXMLElement("<NCIPMessage><RenewItem><ItemId><ItemIdentifierValue>{$twoBook}</ItemIdentifierValue></ItemId><UserId><UserIdentifierValue>{$borrower}</UserIdentifierValue></UserId></RenewItem></NCIPMessage>");
$response = $handleRenew->invoke($ncip, (new ServerRequestFactory())->createServerRequest('POST', '/ncip'), new SlimResponse(), $xml, ['tipo_utente' => 'admin']);
$problem = (string) $response->getBody();
$check(str_contains($problem, 'a conflicting loan or reservation blocks the extension from ' . $conflictStart)
    && !str_contains($problem, 'every copy is booked'),
    '19b NCIP describes the limiting commitment without claiming every copy is unavailable', $problem);
$db->query("UPDATE prestiti SET origine = 'diretto' WHERE id = {$twoLoan}");
[$status, $body] = $staffRenew($twoLoan, ['new_due_date' => $expectedMaxDue]);
$check($status === 200 && ($loanRow($twoLoan)['data_scadenza'] ?? '') === $expectedMaxDue,
    '20 the proposed shorter renewal succeeds on the next request', json_encode([$status, $body]));

// Bulk extends an overdue loan from today, so its reported number of days
// must use that same base rather than include the overdue gap.
[$lateBook, $lateCopies] = $mkBook(1, 3);
$lateDue = $addDays($today, -5);
$lateLoan = $mkLoan($lateBook, null, $borrower, 'in_ritardo', $addDays($today, -15), $lateDue);
$laterLoan = $mkLoan($lateBook, $lateCopies[0], $otherUser, 'prenotato', $addDays($today, 4), $addDays($today, 20));
foreach ([4 => 3, 1 => 0, -1 => 0] as $conflictOffset => $expectedDays) {
    $blockedFrom = $addDays($today, $conflictOffset);
    $db->query("UPDATE prestiti SET data_prestito = '{$blockedFrom}' WHERE id = {$laterLoan}");
    $asAdmin();
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/staff/loans/bulk-extend')
        ->withParsedBody(['ids' => [$lateLoan], 'days' => 10]);
    $response = $staff->bulkExtend($request, new SlimResponse());
    $body = json_decode((string) $response->getBody(), true);
    $check($response->getStatusCode() === 409 && ($body['meta']['max_extension_days'] ?? -1) === $expectedDays
        && ($body['meta']['max_due_date'] ?? '') === $addDays($today, $expectedDays)
        && ($body['meta']['first_conflict_date'] ?? '') === $blockedFrom
        && ($loanRow($lateLoan)['data_scadenza'] ?? '') === $lateDue,
        '21 overdue bulk caps count from today and never propose a past due date', json_encode($body));
}
$previousLocale = I18n::getLocale();
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    I18n::setLocale($locale);
    $date = LoanWindow::displayDate('2030-10-25');
    $expected = $locale === 'en_US' ? '10-25-2030' : '25-10-2030';
    $check($date === $expected && str_contains(__('Rinnova fino al %s', $date), $expected)
        && str_contains(LoanWindow::explainRenewal(3, '2030-10-25', '2030-10-26'), $expected),
        "22 renewal action and explanation share the date format in {$locale}");
}
I18n::setLocale($previousLocale);

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
