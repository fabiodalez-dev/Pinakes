<?php
declare(strict_types=1);

/**
 * Behavioural database contract for issue #360 — overdue-loan recalls (solleciti).
 *
 * The DB-free companion (tests/loan-recall-360.unit.php) asserts the source
 * *shape*; this test drives the REAL NotificationService against seeded
 * libri/utenti/prestiti rows and asserts the observable state, covering the
 * parts most likely to break silently:
 *   - the automatic scheduler is gated by loans.recall_auto_enabled;
 *   - sendManualRecall()'s validation guards (not found / not overdue / no
 *     email / not active) return the right verdict and never touch the counter;
 *   - the atomic claim is reverted when the send fails, so a failed recall never
 *     leaves recall_count bumped (the #252/M3 invariant, the risky path);
 *   - sendLoanReceiptEmail()'s guards;
 *   - the automatic-recall SELECT picks exactly the loans the interval/cap/
 *     already-recalled-today math says it should.
 *
 * SMTP is forced unreachable (smtp driver → a closed port) so every send fails
 * deterministically and the revert path is exercised on every environment,
 * with or without a real mail server. All settings and seeded rows are
 * restored on exit.
 *
 * Run: php tests/loan-recall-360-behavior.unit.php
 */

use App\Support\ConfigStore;
use App\Support\NotificationService;

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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}
// ConfigStore normally opens its own connection from config/settings.php (absent
// in the CI unit job). Inject the test connection so set()/get() are deterministic
// against this database on every runner — same reflection seam archives-plugin uses.
$sharedConn = new ReflectionProperty(ConfigStore::class, 'sharedConnection');
$sharedConn->setAccessible(true);
$sharedConn->setValue(null, $db);

// The recall columns are self-healed at runtime by the app; ensure them here so
// a pre-migration dev DB can still seed and read them directly.
foreach ([
    "SHOW COLUMNS FROM prestiti LIKE 'recall_count'" => "ALTER TABLE prestiti ADD COLUMN recall_count INT NOT NULL DEFAULT 0",
    "SHOW COLUMNS FROM prestiti LIKE 'last_recall_at'" => "ALTER TABLE prestiti ADD COLUMN last_recall_at DATETIME NULL DEFAULT NULL",
    "SHOW COLUMNS FROM prestiti LIKE 'overdue_notification_sent'" => "ALTER TABLE prestiti ADD COLUMN overdue_notification_sent BOOLEAN DEFAULT 0",
] as $check => $alter) {
    if ($db->query($check)->num_rows === 0) {
        $db->query($alter);
    }
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZRECALL360_' . $run;
$emailDomain = '@recall360.test.local';

// ── Snapshot the loans.* settings we mutate, so cleanup restores them ────────
$settingPaths = ['loans.recall_auto_enabled', 'loans.recall_interval_days', 'loans.recall_max_count'];
$origSettings = [];
foreach ($settingPaths as $path) {
    $origSettings[$path] = ConfigStore::get($path, null);
}

// Snapshot the real email rows we overwrite to force SMTP unreachable, so the
// dev machine's mail config is restored exactly (deleted where no row existed).
$mailDbKeys = ['driver_mode', 'smtp_host', 'smtp_port'];
$origMail = [];
$mailRes = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE category='email' AND setting_key IN ('driver_mode','smtp_host','smtp_port')");
while ($row = $mailRes->fetch_assoc()) {
    $origMail[$row['setting_key']] = $row['setting_value'];
}

$setConfig = static function (array $pairs): void {
    foreach ($pairs as $path => $value) {
        ConfigStore::set($path, (string) $value);
    }
    ConfigStore::clearCache();
};

$cardPrefix = 'ZZR360-' . $run . '-';
$cleanup = static function () use ($db, $titlePrefix, $cardPrefix, $origSettings, $origMail, $mailDbKeys): void {
    $titleLike = $titlePrefix . '%';
    $cardLike = $cardPrefix . '%';
    foreach ([
        'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $titleLike);
        $stmt->execute();
        $stmt->close();
    }
    // Delete users by card prefix, not email — the no-email fixture user has an
    // empty email that an email LIKE would miss, orphaning the row.
    $stmt = $db->prepare('DELETE FROM utenti WHERE codice_tessera LIKE ?');
    $stmt->bind_param('s', $cardLike);
    $stmt->execute();
    $stmt->close();
    // Restore the real email rows exactly: update the ones that existed, delete
    // the ones this test created.
    foreach ($mailDbKeys as $k) {
        if (array_key_exists($k, $origMail)) {
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category='email' AND setting_key = ?");
            $stmt->bind_param('ss', $origMail[$k], $k);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $db->prepare("DELETE FROM system_settings WHERE category='email' AND setting_key = ?");
            $stmt->bind_param('s', $k);
            $stmt->execute();
            $stmt->close();
        }
    }
    foreach ($origSettings as $path => $value) {
        [$cat, $settingKey] = explode('.', $path, 2);
        if ($value === null) {
            // The key had no row before the test — delete the one $setConfig
            // created so the dev DB is left exactly as found.
            $del = $db->prepare('DELETE FROM system_settings WHERE category = ? AND setting_key = ?');
            $del->bind_param('ss', $cat, $settingKey);
            $del->execute();
            $del->close();
            continue;
        }
        ConfigStore::set($path, (string) $value);
    }
    ConfigStore::clearCache();
};

$cleanup();

// Force every SMTP handshake to fail so the send path bails and the recall claim
// is reverted — deterministically, on any runner. isSmtpReachable() reads
// mail.driver + mail.smtp.host/port (loadDatabaseSettings maps these from the
// email/driver_mode + email/smtp_host + email/smtp_port rows); pointing the host
// at a closed loopback port makes the probe fail. Set AFTER the initial cleanup
// so cleanup's config-restore doesn't wipe it before the tests run.
$setConfig([
    'mail.driver' => 'smtp',
    'mail.smtp.host' => '127.0.0.1',
    'mail.smtp.port' => '59999',
    'loans.recall_interval_days' => '7',
    'loans.recall_max_count' => '3',
    'loans.recall_auto_enabled' => '0',
]);

set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

// ── Fixture helpers ─────────────────────────────────────────────────────────
$bookSeq = 0;
$makeBook = static function () use ($db, $titlePrefix, &$bookSeq): int {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'prestato', 1, 0)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$userSeq = 0;
$makeUser = static function (bool $withEmail) use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZR360-' . $run . '-' . $userSeq;
    $email = $withEmail ? ('patron' . $userSeq . $emailDomain) : '';
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Recall', 'Patron', ?, '', 'standard', 'attivo')");
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

/**
 * @param int         $daysOverdue positive = due N days ago; negative = due in the future
 * @param string      $stato       prestiti.stato
 * @param int         $recallCount seeded recall_count
 * @param string|null $lastRecall  seeded last_recall_at (DATETIME) or null
 */
$makeLoan = static function (
    int $daysOverdue,
    bool $withEmail = true,
    string $stato = 'in_corso',
    int $attivo = 1,
    int $overdueSent = 1,
    int $recallCount = 0,
    ?string $lastRecall = null,
    ?int $userId = null
) use ($db, $makeBook, $makeUser): int {
    $bookId = $makeBook();
    $userId ??= $makeUser($withEmail);
    // Fixture dates from the application clock (DateHelper), not PHP's
    // default timezone: the scheduler compares against DateHelper::today(),
    // and a tz mismatch of one calendar day would flip the one-day-margin
    // boundary cases in the eligibility matrix (mA 8vs7, mC 13vs14, mD 15vs14).
    $base = new DateTimeImmutable(\App\Support\DateHelper::today());
    $due = $base->modify("-{$daysOverdue} days")->format('Y-m-d');
    $lent = $base->modify('-40 days')->format('Y-m-d');
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, overdue_notification_sent, recall_count, last_recall_at)
         VALUES (?, ?, ?, ?, ?, 'diretto', ?, ?, ?, ?)"
    );
    $stmt->bind_param('issssiiss', $bookId, $userId, $lent, $due, $stato, $attivo, $overdueSent, $recallCount, $lastRecall);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$recallCountOf = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare('SELECT recall_count, last_recall_at FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['count' => (int) $row['recall_count'], 'last' => $row['last_recall_at']];
};

echo "Loan recall (#360) behavioural contract:\n";

$service = new NotificationService($db);

$updatedAtOf = static function (int $loanId) use ($db): string {
    $stmt = $db->prepare('SELECT updated_at FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) $row['updated_at'];
};

// ── G. Automatic scheduler is gated off by default ──────────────────────────
$gatedLoan = $makeLoan(daysOverdue: 20);            // plainly eligible on the math
$db->query("UPDATE prestiti SET updated_at = NOW() - INTERVAL 1 HOUR WHERE id = " . (int) $gatedLoan);
$gatedStamp = $updatedAtOf($gatedLoan);
$setConfig(['loans.recall_auto_enabled' => '0']);
$check($service->sendLoanRecalls() === 0, 'G1 sendLoanRecalls() is a no-op while loans.recall_auto_enabled=0');
$check($recallCountOf($gatedLoan)['count'] === 0, 'G2 gated run leaves recall_count untouched');
$check($updatedAtOf($gatedLoan) === $gatedStamp, 'G2b gated run never touches the eligible loan (updated_at unchanged)');

// ── G3. Enabled scheduler actually processes eligible loans. Regression guard
// for the ConfigStore 'loans' category being unmapped: the enable flag was
// read via ConfigStore::get('loans.recall_auto_enabled'), which always returned
// the '0' default no matter what the admin saved, so the scheduler was a
// permanent no-op. A successful send can't be asserted without a mail server,
// but with SMTP forced unreachable the eligible loan is still claimed-then-
// reverted, and each of those UPDATEs advances prestiti.updated_at — an
// observable the gated-off path (G2b) never produces. Before the fix this
// assertion fails (the gate reads '0' and returns before any UPDATE).
$enabledLoan = $makeLoan(daysOverdue: 20);
$db->query("UPDATE prestiti SET updated_at = NOW() - INTERVAL 1 HOUR WHERE id = " . (int) $enabledLoan);
$enabledStampBefore = $updatedAtOf($enabledLoan);
$setConfig(['loans.recall_auto_enabled' => '1']);
$service->sendLoanRecalls();
$check($updatedAtOf($enabledLoan) > $enabledStampBefore, 'G3 enabled scheduler claims eligible loans (updated_at advanced) — the config gate is honoured, not inert');
$check($recallCountOf($enabledLoan)['count'] === 0, 'G3b failed send during the enabled run still reverts the claim');
$setConfig(['loans.recall_auto_enabled' => '0']);

// ── V. sendManualRecall() validation guards ─────────────────────────────────
$missing = $service->sendManualRecall(2000000000);
$check($missing['success'] === false, 'V1 unknown loan id → failure');

$notOverdue = $makeLoan(daysOverdue: -3);            // due in the future
$r = $service->sendManualRecall($notOverdue);
$check($r['success'] === false && $recallCountOf($notOverdue)['count'] === 0, 'V2 not-overdue loan → refused, counter untouched');

// One shared no-email patron: utenti.email is NOT NULL + UNIQUE with a PAD SPACE
// collation, so two empty-string users would collide. Reuse the same row.
$noEmailUser = $makeUser(false);
$noEmail = $makeLoan(daysOverdue: 10, userId: $noEmailUser);
$r = $service->sendManualRecall($noEmail);
$check($r['success'] === false && $recallCountOf($noEmail)['count'] === 0, 'V3 loan whose patron has no email → refused, counter untouched');

$returned = $makeLoan(daysOverdue: 10, stato: 'restituito', attivo: 0);
$r = $service->sendManualRecall($returned);
$check($r['success'] === false && $recallCountOf($returned)['count'] === 0, 'V4 inactive/returned loan → refused, counter untouched');

// Per-loan daily cooldown: a loan already recalled today is refused, so a
// staff session (or a repeated bulk submit) can't re-email the same patron.
$cooled = $makeLoan(daysOverdue: 12, recallCount: 1, lastRecall: date('Y-m-d H:i:s'));
$r = $service->sendManualRecall($cooled);
$check($r['success'] === false && $recallCountOf($cooled)['count'] === 1, 'V5 loan already recalled today → refused by daily cooldown, counter untouched');

// ── R. Atomic claim is reverted when the send fails ─────────────────────────
$eligible = $makeLoan(daysOverdue: 12, recallCount: 1);
$before = $recallCountOf($eligible);
$r = $service->sendManualRecall($eligible);
$after = $recallCountOf($eligible);
if ($r['success'] === true) {
    // A real mail server accepted it: the counter advances by exactly one.
    $check($after['count'] === $before['count'] + 1 && $after['last'] !== null,
        'R1 successful recall bumps the counter by one and stamps last_recall_at');
} else {
    // SMTP forced unreachable here: the claim must be fully reverted.
    $check($after['count'] === $before['count'] && $after['last'] === $before['last'],
        'R1 failed recall reverts the claim — recall_count and last_recall_at unchanged');
}

// ── E. sendLoanReceiptEmail() guards ────────────────────────────────────────
$e = $service->sendLoanReceiptEmail(2000000001);
$check($e['success'] === false, 'E1 receipt email for an unknown loan → failure');
$receiptNoEmail = $makeLoan(daysOverdue: -1, overdueSent: 0, userId: $noEmailUser);
$e = $service->sendLoanReceiptEmail($receiptNoEmail);
$check($e['success'] === false, 'E2 receipt email for a patron with no address → failure');

// ── S. Automatic-recall SELECT picks exactly the scheduled loans ────────────
// The scheduler's eligibility math (interval=7, cap=3) mirrored against a seeded
// matrix. Kept in lock-step with sendLoanRecalls() by the source guard below.
$mA = $makeLoan(daysOverdue: 8,  recallCount: 0);                                   // 8 >= 7*1 → due
$mB = $makeLoan(daysOverdue: 5,  recallCount: 0);                                   // 5 <  7*1 → too soon
$mC = $makeLoan(daysOverdue: 13, recallCount: 1);                                   // 13 < 7*2 → too soon
$mD = $makeLoan(daysOverdue: 15, recallCount: 1, lastRecall: (new DateTimeImmutable(\App\Support\DateHelper::now()))->modify('-1 day')->format('Y-m-d H:i:s')); // 15 >= 14 → due
$mE = $makeLoan(daysOverdue: 30, recallCount: 3);                                   // cap reached
$mF = $makeLoan(daysOverdue: 10, overdueSent: 0);                                   // overdue notice never sent
$mG = $makeLoan(daysOverdue: 10, stato: 'restituito', attivo: 0);                  // not active
$mH = $makeLoan(daysOverdue: 15, recallCount: 1, lastRecall: \App\Support\DateHelper::now()); // already recalled today

$today = \App\Support\DateHelper::today();
$interval = 7; $max = 3;
// Scope the check to this matrix's own loan ids so unrelated seeded loans (V2/V3…)
// don't leak into the result set.
$matrix = [$mA, $mB, $mC, $mD, $mE, $mF, $mG, $mH];
$placeholders = implode(',', array_fill(0, count($matrix), '?'));
$stmt = $db->prepare("
    SELECT p.id
    FROM prestiti p
    JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
    JOIN utenti u ON p.utente_id = u.id
    WHERE p.stato IN ('in_corso', 'in_ritardo')
      AND p.attivo = 1
      AND u.email IS NOT NULL
      AND TRIM(u.email) <> ''
      AND p.data_scadenza < ?
      AND p.overdue_notification_sent = 1
      AND p.recall_count < ?
      AND DATEDIFF(?, p.data_scadenza) >= ? * (p.recall_count + 1)
      AND (p.last_recall_at IS NULL OR DATE(p.last_recall_at) < ?)
      AND p.id IN ($placeholders)
");
$types = 'sisis' . str_repeat('i', count($matrix));
$stmt->bind_param($types, $today, $max, $today, $interval, $today, ...$matrix);
$stmt->execute();
$res = $stmt->get_result();
$selected = [];
while ($row = $res->fetch_assoc()) {
    $selected[] = (int) $row['id'];
}
$stmt->close();
sort($selected);
$expected = [$mA, $mD];
sort($expected);
$check($selected === $expected, 'S1 scheduler SELECT returns exactly the interval/cap/last-recall-eligible loans');

// Drift guard: the mirrored query above must stay faithful to the real one.
$src = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$check(str_contains($src, 'DATEDIFF(?, p.data_scadenza) >= ? * (p.recall_count + 1)')
    && str_contains($src, 'p.recall_count < ?')
    && str_contains($src, 'p.overdue_notification_sent = 1'),
    'S2 sendLoanRecalls() still uses the eligibility predicate this test mirrors');

$cleanup();
echo "\n{$pass} passed\n";
$db->close();
exit(0);
