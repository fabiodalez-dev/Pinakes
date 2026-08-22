<?php
declare(strict_types=1);

/**
 * Upgrade-path coverage for the #366 NULL-deadline ready-pickup backfill.
 *
 * Part A runs the REAL PickupDeadlineBackfill against the installed schema
 * (current canonical triggers active — proving the commitment-neutral UPDATE
 * passes the corrected BEFORE UPDATE gate) over seeded legacy rows:
 *   • a NULL-deadline ready pickup inside its loan window gets
 *     today + pickup_expiry_days;
 *   • one whose window already closed gets its data_scadenza (a past date the
 *     very next expiry sweep culls through the normal lifecycle);
 *   • rows with a deadline, scheduled rows and closed rows are untouched;
 *   • the pass is idempotent.
 *
 * Part B statically asserts the Updater wiring that makes the whole approach
 * safe: the backfill must run AFTER reapplyTriggers() — a migration SQL file
 * would execute under the STARTING version's trigger, whose overlap gate
 * aborts on any update of a copy-bound row.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\PickupDeadlineBackfill;

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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$titlePrefix = "ZZ0765PD {$run} ";
$emailDomain = "@0765pd-{$run}.test.local";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$plus = static fn (int $days): string => (new DateTimeImmutable('today'))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

$makeBookWithCopy = static function () use ($db, $titlePrefix): array {
    static $seq = 0;
    $seq++;
    $title = $titlePrefix . $seq;
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, 1, 1, NOW(), NOW())');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $code = "ZZ0765PD-{$bookId}";
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
    $stmt->bind_param('is', $bookId, $code);
    $stmt->execute();
    $stmt->close();
    return [$bookId, (int) $db->insert_id];
};

$makeLoan = static function (int $bookId, int $copyId, int $userId, string $state, int $active, string $start, string $end, ?string $deadline) use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)"
    );
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $start, $end, $state, $active);
    $stmt->execute();
    $stmt->close();
    $loanId = (int) $db->insert_id;
    if ($deadline !== null) {
        // Commitment-neutral edit: allowed by the corrected BEFORE UPDATE trigger.
        $stmt = $db->prepare('UPDATE prestiti SET pickup_deadline = ? WHERE id = ?');
        $stmt->bind_param('si', $deadline, $loanId);
        $stmt->execute();
        $stmt->close();
    }
    return $loanId;
};

$deadlineOf = static function (int $loanId) use ($db): ?string {
    $res = $db->query("SELECT pickup_deadline FROM prestiti WHERE id = {$loanId}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null ? $row['pickup_deadline'] : 'MISSING-ROW';
};

$cleanup = static function () use ($db, $titlePrefix, $emailDomain): void {
    $db->query("DELETE p FROM prestiti p JOIN libri l ON p.libro_id = l.id WHERE l.titolo LIKE '" . $db->real_escape_string($titlePrefix) . "%'");
    $db->query("DELETE c FROM copie c JOIN libri l ON c.libro_id = l.id WHERE l.titolo LIKE '" . $db->real_escape_string($titlePrefix) . "%'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '" . $db->real_escape_string($titlePrefix) . "%'");
    $db->query("DELETE FROM utenti WHERE email LIKE '%" . $db->real_escape_string($emailDomain) . "'");
};

echo "A. PickupDeadlineBackfill on seeded legacy rows (real schema, real triggers)\n";
try {
    $cleanup();

    $card = 'Z0765' . strtoupper(substr($run, 0, 8));
    $email = "u1-{$run}{$emailDomain}";
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
         VALUES (?, 'Test', 'ZZ0765PD', ?, 'x', 'attivo', 'standard', 1)"
    );
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $stmt->close();
    $userId = (int) $db->insert_id;

    // The expected deadline is derived exactly like the backfill derives it.
    $settings = new \App\Models\SettingsRepository($db);
    $pickupDays = max(1, (int) ($settings->get('loans', 'pickup_expiry_days', '3') ?? 3));

    [$b1, $c1] = $makeBookWithCopy();
    [$b2, $c2] = $makeBookWithCopy();
    [$b3, $c3] = $makeBookWithCopy();
    [$b4, $c4] = $makeBookWithCopy();
    [$b5, $c5] = $makeBookWithCopy();

    // 1. Legacy ready pickup inside its window, no deadline (the #366 stuck state).
    $legacyOpen = $makeLoan($b1, $c1, $userId, 'da_ritirare', 1, $today, $plus(30), null);
    // 2. Legacy ready pickup whose loan window already closed.
    $legacyClosedWindow = $makeLoan($b2, $c2, $userId, 'da_ritirare', 1, $plus(-40), $plus(-10), null);
    // 3. Healthy ready pickup: deadline already set, must not move.
    $healthyDeadline = $plus(5);
    $healthy = $makeLoan($b3, $c3, $userId, 'da_ritirare', 1, $today, $plus(30), $healthyDeadline);
    // 4. Scheduled loan: NULL deadline is its NORMAL state, must stay NULL.
    $scheduled = $makeLoan($b4, $c4, $userId, 'prenotato', 1, $plus(10), $plus(40), null);
    // 5. Closed historical row: not active, must stay NULL.
    $closed = $makeLoan($b5, $c5, $userId, 'scaduto', 0, $plus(-60), $plus(-30), null);

    $check(PickupDeadlineBackfill::run($db), 'the backfill runs cleanly under the current canonical triggers');

    $expectedOpen = min($plus($pickupDays), $plus(30));
    $check($deadlineOf($legacyOpen) === $expectedOpen,
        "in-window legacy pickup gets today+{$pickupDays}d capped at data_scadenza (got " . var_export($deadlineOf($legacyOpen), true) . ')');
    $check($deadlineOf($legacyClosedWindow) === $plus(-10),
        'closed-window legacy pickup gets its own past data_scadenza, so the next sweep expires it');
    $check($deadlineOf($healthy) === $healthyDeadline, 'an already-set deadline is not touched');
    $check($deadlineOf($scheduled) === null, 'a scheduled (prenotato) loan keeps its normal NULL deadline');
    $check($deadlineOf($closed) === null, 'an inactive historical row is not touched');

    $snapshot = $db->query(
        "SELECT p.id, p.stato, p.copia_id, p.pickup_deadline FROM prestiti p JOIN libri l ON p.libro_id = l.id
         WHERE l.titolo LIKE '" . $db->real_escape_string($titlePrefix) . "%' ORDER BY p.id"
    )->fetch_all(MYSQLI_ASSOC);
    $check(PickupDeadlineBackfill::run($db), 'second pass still reports success');
    $after = $db->query(
        "SELECT p.id, p.stato, p.copia_id, p.pickup_deadline FROM prestiti p JOIN libri l ON p.libro_id = l.id
         WHERE l.titolo LIKE '" . $db->real_escape_string($titlePrefix) . "%' ORDER BY p.id"
    )->fetch_all(MYSQLI_ASSOC);
    $check($after === $snapshot, 'the backfill is idempotent');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
}

echo "B. Updater wiring: backfill only after the corrected triggers are installed\n";
$updaterSource = (string) file_get_contents($root . '/app/Support/Updater.php');
$triggersPos = strpos($updaterSource, '$this->reapplyTriggers();');
$backfillPos = strpos($updaterSource, 'PickupDeadlineBackfill::run($this->db)');
$check($triggersPos !== false && $backfillPos !== false && $triggersPos < $backfillPos,
    'runMigrations() re-applies canonical triggers BEFORE the pickup-deadline backfill');
$check(str_contains($updaterSource, "version_compare(\$toVersion, '0.7.65-rc.1', '>=')"),
    'the backfill is gated on upgrades reaching 0.7.65-rc.1');
// A SQL migration for 0.7.65 may exist (the calendar-links email_templates
// update ships as one), but it must NOT touch `prestiti`: a prestiti write
// would run under the starting version's BEFORE UPDATE trigger and could abort
// the upgrade. The #366 backfill therefore stays PHP (PickupDeadlineBackfill),
// never SQL.
$sqlTwin = $root . '/installer/database/migrations/migrate_0.7.65-rc.1.sql';
$sqlTouchesPrestiti = is_file($sqlTwin)
    && preg_match('/\bprestiti\b/i', (string) file_get_contents($sqlTwin)) === 1;
$check(!$sqlTouchesPrestiti,
    'no 0.7.65 SQL migration touches prestiti (the #366 backfill stays PHP, safe under the legacy trigger)');

$db->close();

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed} check(s) failed, {$passed} passed\n";
    exit(1);
}
echo "ALL {$passed} PASS\n";
