<?php
declare(strict_types=1);

/**
 * The overdue push follows the same rule as the overdue sweep and e-mail
 * (LibraryCalendar::isPastDue()): a loan due yesterday, a day its copy's
 * branch marked closed afterwards, is returnable today without a fine, so
 * the reader gets no "Prestito scaduto" push yet; the same loan on a copy of
 * an open branch does get it. Runs the real PushDispatcher against the
 * database and reads the dedup claims it writes before each delivery.
 * Uses a dedicated test DB: it writes a closure for one branch.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (getenv('PINAKES_ISOLATED_TEST_DB') !== '1' && !str_contains((string) getenv('E2E_DB_NAME'), 'test')) {
    fwrite(STDERR, "FAIL: select a dedicated test database before running this suite.\n");
    exit(1);
}
Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';

use App\Plugins\MobileApi\Push\NullProvider;
use App\Plugins\MobileApi\Push\PushDispatcher;
use App\Support\DateHelper;
use App\Support\LibraryCalendar;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$db = new mysqli(
    getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'),
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null)
);
$db->set_charset('utf8mb4');

$tableExists = static fn (string $t): bool => ($r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'")) instanceof mysqli_result && $r->num_rows === 1;
if (!$tableExists('mobile_push_log') || !$tableExists('calendario_chiusure')) {
    fwrite(STDERR, "FAIL: the mobile API and closures calendar schema are required.\n");
    exit(1);
}

$run = substr((string) hrtime(true), -7);
$today = DateHelper::today();
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$featureRow = $db->query("SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='closures_calendar'");
$featureBefore = ($featureRow instanceof mysqli_result && ($row = $featureRow->fetch_row()) !== null) ? (string) $row[0] : null;
$ids = ['user' => 0, 'sede' => 0, 'books' => [], 'closure' => 0, 'loans' => []];

try {
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('features','closures_calendar','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $db->query("INSERT INTO utenti (nome, cognome, email, password, stato, tipo_utente, codice_tessera) VALUES ('Push', 'Closed{$run}', 'push-closed-{$run}@example.invalid', 'x', 'attivo', 'standard', 'PCD{$run}')");
    $ids['user'] = (int) $db->insert_id;
    $db->query("INSERT INTO sedi (nome) VALUES ('Push closed {$run}')");
    $ids['sede'] = (int) $db->insert_id;

    // Two loans due yesterday: one on a copy of the branch closed yesterday
    // (marked after the loan was made), one on a copy of no branch.
    // A third one, already flipped to 'in_ritardo' before the closure was saved.
    // Two more are returned below: one of the closed branch, one of no branch.
    foreach (['closed' => $ids['sede'], 'open' => null, 'late' => $ids['sede'], 'back' => $ids['sede'], 'backopen' => null] as $kind => $sede) {
        $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato, created_at, updated_at) VALUES ('Push {$kind} {$run}', 1, 0, 'prestato', NOW(), NOW())");
        $bookId = (int) $db->insert_id;
        $ids['books'][] = $bookId;
        $db->query("INSERT INTO copie (libro_id, numero_inventario, stato, sede_id, created_at) VALUES ({$bookId}, 'PCD-{$kind}-{$run}', 'prestato', " . ($sede === null ? 'NULL' : (int) $sede) . ', NOW())');
        $copyId = (int) $db->insert_id;
        $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES ({$bookId}, {$copyId}, {$ids['user']}, DATE_SUB('{$yesterday}', INTERVAL 14 DAY), '{$yesterday}', '" . ($kind === 'late' ? 'in_ritardo' : 'in_corso') . "', 'diretto', 1)");
        $ids['loans'][$kind] = (int) $db->insert_id;
    }
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, sede_id, attiva) VALUES ('{$yesterday}', '{$yesterday}', 'Push closed {$run}', {$ids['sede']}, 1)");
    $ids['closure'] = (int) $db->insert_id;
    LibraryCalendar::reset();

    $claimed = static function (int $loanId) use ($db, $ids, $yesterday): bool {
        $key = $db->real_escape_string("loan_overdue:{$loanId}:{$yesterday}");
        $r = $db->query("SELECT COUNT(*) FROM mobile_push_log WHERE user_id={$ids['user']} AND event_key='{$key}'");
        return $r instanceof mysqli_result && (int) $r->fetch_row()[0] === 1;
    };

    (new PushDispatcher($db, new NullProvider()))->dispatch();

    $check(LibraryCalendar::isPastDue($db, $yesterday, null, $today), 'a due date of yesterday on an open day is past due');
    $check(!$claimed($ids['loans']['closed']), 'no overdue push for a loan whose due day its branch marked closed afterwards');
    $check($claimed($ids['loans']['open']), 'the same loan on an open branch gets the overdue push');
    $check(!$claimed($ids['loans']['late']), 'no overdue push either for a loan already marked late before its due day was closed');

    // The due-soon push follows the same calendar as the feed: the loan whose
    // due day was closed is due today (first open day) and is announced so,
    // the loan that is really late is not "due soon".
    $dueClaimed = static function (int $loanId) use ($db, $ids, $yesterday): bool {
        $key = $db->real_escape_string("loan_due:{$loanId}:{$yesterday}");
        $r = $db->query("SELECT COUNT(*) FROM mobile_push_log WHERE user_id={$ids['user']} AND event_key='{$key}'");
        return $r instanceof mysqli_result && (int) $r->fetch_row()[0] === 1;
    };
    $check($dueClaimed($ids['loans']['closed']), 'a due-soon push for the loan whose due day was closed afterwards (due today)');
    $check(!$dueClaimed($ids['loans']['open']), 'no due-soon push for a loan already past due');

    // A return on the first open day after a closed due day is on time, as the
    // fine counts it; the same return with every day open is late.
    $repo = new \App\Models\LoanRepository($db);
    $flagOf = static fn (int $loanId): string => (string) $db->query("SELECT CONCAT(stato, '|', restituito_in_ritardo) FROM prestiti WHERE id={$loanId}")->fetch_row()[0];
    $check($repo->close($ids['loans']['back']) && $flagOf($ids['loans']['back']) === 'restituito|0', 'returned on the first open day after a closed due day: not flagged late');
    $check($repo->close($ids['loans']['backopen']) && $flagOf($ids['loans']['backopen']) === 'restituito|1', 'the same return with every day open is flagged late');

    // The claim re-reads the loan under the lock: a loan renewed or returned
    // after the sweep read it gets no overdue push with the stale data.
    $dispatcher = new PushDispatcher($db, new NullProvider());
    $claimIfPastDue = new ReflectionMethod($dispatcher, 'claimIfPastDue');
    $openLoan = $ids['loans']['open'];
    $openLoanCopy = (int) $db->query("SELECT copia_id FROM prestiti WHERE id={$openLoan}")->fetch_row()[0];
    $staleDue = (new DateTimeImmutable($yesterday))->modify('-3 day')->format('Y-m-d');
    $check($claimIfPastDue->invoke($dispatcher, $ids['user'], "loan_overdue:{$openLoan}:{$staleDue}", $openLoan, $staleDue, $openLoanCopy, $today) === false, 'no overdue push for a due date the loan no longer has (renewed since the sweep read it)');
    $db->query("UPDATE prestiti SET attivo=0, stato='restituito', data_restituzione='{$today}' WHERE id={$openLoan}");
    $check($claimIfPastDue->invoke($dispatcher, $ids['user'], "loan_overdue:{$openLoan}:{$yesterday}:returned", $openLoan, $yesterday, $openLoanCopy, $today) === false, 'no overdue push for a loan returned since the sweep read it');

    // Notices and recalls say how late the item is in open days, as the fine
    // counts them: due two days ago with yesterday closed is one day late.
    $twoDaysAgo = (new DateTimeImmutable($today))->modify('-2 day')->format('Y-m-d');
    $closedCopy = (int) $db->query("SELECT copia_id FROM prestiti WHERE id={$ids['loans']['closed']}")->fetch_row()[0];
    $openCopy = (int) $db->query("SELECT copia_id FROM prestiti WHERE id={$ids['loans']['open']}")->fetch_row()[0];
    $check(LibraryCalendar::openDaysLate($db, $twoDaysAgo, $closedCopy, $today) === 1, 'a closed day inside the late period is not counted (1 day, not 2)');
    $check(LibraryCalendar::openDaysLate($db, $twoDaysAgo, $openCopy, $today) === 2, 'with every day open the count is the plain number of days (2)');
    $check(LibraryCalendar::openDaysLate($db, $yesterday, $closedCopy, $today) === 0, 'returned on the first open day after a closed due day: 0 days late');
    $check(LibraryCalendar::lateDaysForNotice($db, $yesterday, $closedCopy, $today) === 0, 'a loan not late yet has no days late in a notice');

    // A loan list reads the branches of its page in one query: the primed
    // answers are the ones a lookup per copy gives, a missing copy included.
    LibraryCalendar::reset();
    LibraryCalendar::primeCopyBranches($db, [$closedCopy, $openCopy, $closedCopy, 2147483000]);
    $check(LibraryCalendar::branchOfCopy($db, $closedCopy) === $ids['sede'] && LibraryCalendar::branchOfCopy($db, $openCopy) === null && LibraryCalendar::branchOfCopy($db, 2147483000) === null, 'primed copy branches match the per-copy lookup');
    $check(LibraryCalendar::isPastDue($db, $twoDaysAgo, $closedCopy, $today) && LibraryCalendar::openDaysLate($db, $twoDaysAgo, $closedCopy, $today) === 1, 'the calendar reads the primed branch (the closed day is still skipped)');

    // Today closed for everyone, the loan due yesterday (open): late today,
    // with no open day counted yet. A notice or a recall says 1 day, not 0.
    $weekdaysRow = $db->query("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
    $weekdaysBefore = ($weekdaysRow instanceof mysqli_result && ($w = $weekdaysRow->fetch_row()) !== null) ? (string) $w[0] : null;
    $todayWeekday = (int) (new DateTimeImmutable($today))->format('w');
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','closed_weekdays','{$todayWeekday}') ON DUPLICATE KEY UPDATE setting_value='{$todayWeekday}'");
    LibraryCalendar::reset();
    try {
        $check(LibraryCalendar::isPastDue($db, $yesterday, $openCopy, $today), 'due on the last open day, late on the closed day after it');
        $check(LibraryCalendar::openDaysLate($db, $yesterday, $openCopy, $today) === 0, 'no open day late counted yet');
        $check(LibraryCalendar::lateDaysForNotice($db, $yesterday, $openCopy, $today) === 1, 'the notice says 1 day late, never 0');
    } finally {
        if ($weekdaysBefore === null) {
            $db->query("DELETE FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
        } else {
            $db->query("UPDATE system_settings SET setting_value='" . $db->real_escape_string($weekdaysBefore) . "' WHERE category='loans' AND setting_key='closed_weekdays'");
        }
        LibraryCalendar::reset();
    }
} finally {
    if ($ids['user'] > 0) {
        $db->query("DELETE FROM mobile_push_log WHERE user_id={$ids['user']}");
        $db->query("DELETE FROM prestiti WHERE utente_id={$ids['user']}");
    }
    if ($ids['books'] !== []) {
        $list = implode(',', array_map('intval', $ids['books']));
        $db->query("DELETE FROM copie WHERE libro_id IN ({$list})");
        $db->query("DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN ({$list})");
        $db->query("DELETE FROM libri WHERE id IN ({$list})");
    }
    if ($ids['closure'] > 0) {
        $db->query("DELETE FROM calendario_chiusure WHERE id={$ids['closure']}");
    }
    if ($ids['sede'] > 0) {
        $db->query("DELETE FROM sedi WHERE id={$ids['sede']}");
    }
    if ($ids['user'] > 0) {
        $db->query("DELETE FROM utenti WHERE id={$ids['user']}");
    }
    if ($featureBefore === null) {
        $db->query("DELETE FROM system_settings WHERE category='features' AND setting_key='closures_calendar'");
    } else {
        $db->query("UPDATE system_settings SET setting_value='" . $db->real_escape_string($featureBefore) . "' WHERE category='features' AND setting_key='closures_calendar'");
    }
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
