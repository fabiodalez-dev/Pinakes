<?php
declare(strict_types=1);

/**
 * The availability counter and the booking gate count the same reservations.
 *
 * CapacityService leaves out the reservation of a reader who cannot borrow
 * right now (suspended, card expired, at the loan limit): it is paused, it
 * keeps its place in the queue and takes no copy from the readers who can.
 * DataIntegrity's counter did the same only when the closures calendar had
 * rules; on a default install (no rules) it counted every `attiva`
 * reservation covering today. A suspended reader's reservation then showed
 * the title as "0 available, prenotato" while the booking gate, correctly,
 * still lent the copy.
 *
 * Against the real database, DataIntegrity and CapacityService, in BOTH
 * branches (calendar without rules / with a rule) and through BOTH the
 * single-title and the whole-catalogue recalculation:
 *  - a paused reservation (suspended, expired card, loan limit) takes nothing;
 *  - an ordinary reader's reservation still takes its copy;
 *  - the counter equals copies minus what the gate counts, case by case.
 *
 * Run: php tests/reservation-count-paused-readers.unit.php
 */

use App\Models\SettingsRepository;
use App\Services\CapacityService;
use App\Support\ConfigStore;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;

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
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — reservation counter suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_RCNT_{$run}";
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
$settings = new SettingsRepository($db);
$savedLimit = $settings->get('loans', 'max_active_loans_per_user', null);
$cleanup = static function () use ($db, &$bookIds, &$userIds, $settings, $savedLimit, $prefix): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
    $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '" . $db->real_escape_string($prefix) . "'");
    if ($savedLimit === null) {
        $settings->delete('loans', 'max_active_loans_per_user');
    } else {
        $settings->set('loans', 'max_active_loans_per_user', (string) $savedLimit);
    }
    Features::resetOverrides();
    LibraryCalendar::reset();
    // The whole-catalogue recalculation of the test ran with the limit at 1
    // and the overrides on: it also rewrote the counters of every title that
    // is not a fixture. Now that both are back, compute them again.
    $restored = (new DataIntegrity($db))->recalculateAllBookAvailability();
    if ($restored['errors'] !== []) {
        throw new RuntimeException('catalogue counters not restored: ' . json_encode($restored['errors']));
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

$today = DateHelper::today();
$day = static fn (int $offset): string => date('Y-m-d', strtotime("{$today} " . ($offset >= 0 ? '+' : '') . "{$offset} days"));

// ── Sandbox ──────────────────────────────────────────────────────────────────
$seq = 0;
$mkUser = static function (string $stato = 'attivo', ?string $cardExpiry = null) use ($db, $prefix, $run, &$userIds, &$seq): int {
    $n = ++$seq;
    $card = 'RC' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, data_scadenza_tessera)
                          VALUES (?, 'ReservationCount', ?, ?, 'x', ?, 'standard', 1, ?)");
    $stmt->bind_param('sssss', $card, $cognome, $email, $stato, $cardExpiry);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
/** @return array{0:int, 1:list<int>} book id, copy ids */
$mkBook = static function (int $copies) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} book " . (count($bookIds) + 1);
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, 'disponibile', ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "{$prefix}-{$bookId}-{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};
// A reservation whose window covers today.
$mkReservation = static function (int $book, int $user, int $position = 1) use ($db, $day): void {
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
                          VALUES (?, ?, ?, ?, ?, ?, 'attiva')");
    $start = $day(-1);
    $end = $day(7);
    $deadline = $end . ' 23:59:59';
    $stmt->bind_param('iisssi', $book, $user, $start, $end, $deadline, $position);
    $stmt->execute();
    $stmt->close();
};
$bookState = static function (int $book) use ($db): array {
    $row = $db->query("SELECT copie_disponibili, stato FROM libri WHERE id = {$book} AND deleted_at IS NULL")->fetch_row();
    return [(int) $row[0], (string) $row[1]];
};
$resetBook = static function (int $book, int $copies) use ($db): void {
    // A known wrong value, so "unchanged" cannot pass for "recalculated".
    $db->query("UPDATE libri SET copie_disponibili = 99, stato = 'non_disponibile' WHERE id = {$book} AND deleted_at IS NULL");
};

// The readers. The limit is the global one (per-type rules off below), set to
// one loan: the reader "at the limit" has one copy out of another title.
$suspended = $mkUser('sospeso');
$expired = $mkUser('attivo', $day(-10));
$atLimit = $mkUser();
$normal = $mkUser();
$other = $mkUser();

Features::override('circulation_rules', false);
Features::override('debt_block', false);
$settings->set('loans', 'max_active_loans_per_user', '1');

[$outBook, $outCopies] = $mkBook(1);
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine) VALUES (?, ?, ?, ?, ?, 'in_corso', 1, 'diretto')");
$from = $day(-3);
$to = $day(20);
$stmt->bind_param('iiiss', $outBook, $outCopies[0], $atLimit, $from, $to);
$stmt->execute();
$stmt->close();
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$outCopies[0]}");

// One title per case: [label, copies, readers with a reservation, expected available, expected state].
$cases = [
    'suspended reader' => [1, [$suspended], 1, 'disponibile'],
    'reader with an expired card' => [1, [$expired], 1, 'disponibile'],
    'reader at the loan limit' => [1, [$atLimit], 1, 'disponibile'],
    'ordinary reader' => [1, [$normal], 0, 'prenotato'],
    'two copies, an ordinary and a suspended reader' => [2, [$normal, $suspended], 1, 'disponibile'],
    'two copies, two ordinary readers' => [2, [$normal, $other], 0, 'prenotato'],
];
$books = [];
foreach ($cases as $label => [$copies, $readers]) {
    [$bookId] = $mkBook($copies);
    foreach ($readers as $i => $reader) {
        $mkReservation($bookId, $reader, $i + 1);
    }
    $books[$label] = $bookId;
}

$fatal = null;
try {
    // Settings are read from the sandbox database, never from the checkout's own.
    ConfigStore::usingConnection($db, static function () use ($db, $check, $cases, $books, $bookState, $resetBook, $today, $day, $prefix): void {
        foreach (['no calendar rules' => false, 'a calendar rule' => true] as $branch => $withRule) {
            // Without the module the calendar is empty whatever the sandbox
            // holds; with it, one closure far from today is enough for the
            // "has rules" branch without closing any day the cases touch.
            Features::override('closures_calendar', $withRule);
            if ($withRule) {
                $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, attiva) VALUES (?, ?, ?, 0, 1)');
                $far = $day(300);
                $stmt->bind_param('sss', $far, $far, $prefix);
                $stmt->execute();
                $stmt->close();
            }
            LibraryCalendar::reset();
            $check(LibraryCalendar::forDb($db)->hasRules() === $withRule, "[{$branch}] the calendar is in the branch under test");

            foreach (['single title' => false, 'whole catalogue' => true] as $path => $global) {
                foreach ($books as $label => $bookId) {
                    $resetBook($bookId, $cases[$label][0]);
                }
                $integrity = new DataIntegrity($db);
                if ($global) {
                    $result = $integrity->recalculateAllBookAvailability();
                    $check($result['errors'] === [], "[{$branch}, {$path}] the recalculation ran", json_encode($result['errors']));
                } else {
                    foreach ($books as $bookId) {
                        $integrity->recalculateBookAvailability($bookId);
                    }
                }
                foreach ($cases as $label => [$copies, , $expectedAvailable, $expectedState]) {
                    $bookId = $books[$label];
                    [$available, $state] = $bookState($bookId);
                    $capacity = new CapacityService($db);
                    $gateCount = count($capacity->reservationIntervalsInRange($bookId, $today, $today));
                    $check($available === $expectedAvailable && $state === $expectedState,
                        "[{$branch}, {$path}] {$label}: {$expectedAvailable} available, '{$expectedState}'",
                        "copie_disponibili={$available} stato={$state}");
                    $check($available === max(0, $copies - $gateCount),
                        "[{$branch}, {$path}] {$label}: the counter equals copies minus what the booking gate counts",
                        "copie_disponibili={$available} copies={$copies} gate={$gateCount}");
                    $check($capacity->hasFreeCapacity($bookId, $today, $today) === ($expectedAvailable > 0),
                        "[{$branch}, {$path}] {$label}: the booking gate agrees");
                }
            }
        }
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
