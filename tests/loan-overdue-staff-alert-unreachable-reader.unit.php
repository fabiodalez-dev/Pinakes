<?php
declare(strict_types=1);

/**
 * The staff alert for an overdue loan does not depend on the reader's e-mail.
 *
 * NotificationService::sendOverdueLoanNotifications() alerted the staff (the
 * admin e-mail and the in-app notice) only after the READER's overdue e-mail
 * had been delivered. A reader whose address keeps failing had the loan turned
 * 'in_ritardo' and the notice retried on every run (deliberately: at least
 * once), but nobody at the library ever heard of it.
 *
 * Now:
 *  - a failed reader e-mail still alerts the staff, on that very run;
 *  - the reader's notice is still retried on every run, the staff alert is
 *    sent once per overdue period, not once per retry;
 *  - when the reader's e-mail finally goes out the staff are not alerted again;
 *  - a reader whose e-mail works alerts the staff once, as before;
 *  - the same loan overdue again after a new due date alerts again.
 *
 * The real service against the database in E2E_DB_* (or the worktree .env),
 * with an EmailService that fails for one address. Only rows this run creates
 * are kept changed: every other overdue loan the sweep touches is restored.
 *
 * Run: php tests/loan-overdue-staff-alert-unreachable-reader.unit.php
 */

use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\LibraryCalendar;
use App\Support\NotificationService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $dbUser, $dbPass, $dbName, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZ_STAFFALERT_' . $run;
$emailDomain = '@staffalert-' . $run . '.test.local';

// The sweep walks every overdue loan in the database, not only these fixtures.
$sweptBefore = $db->query("SELECT id, stato, overdue_notification_sent FROM prestiti WHERE attivo = 1 AND stato IN ('in_corso','in_ritardo')")->fetch_all(MYSQLI_NUM);
$noticesBefore = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM admin_notifications')->fetch_row()[0];

$cleanup = static function () use ($db, $titlePrefix, $emailDomain, $sweptBefore, $noticesBefore): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = '%' . $emailDomain;
    $ids = [0];
    $stmt = $db->prepare('SELECT p.id FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$loanId]) {
        $ids[] = (int) $loanId;
    }
    $stmt->close();
    $restore = $db->prepare('UPDATE prestiti SET stato = ?, overdue_notification_sent = ? WHERE id = ?');
    foreach ($sweptBefore as [$sweptId, $sweptState, $sweptSent]) {
        $ids[] = (int) $sweptId;
        $restore->bind_param('sii', $sweptState, $sweptSent, $sweptId);
        $restore->execute();
    }
    $restore->close();
    // The notices this run's sweeps created, for the fixtures and for the
    // loans restored above.
    $db->query("DELETE FROM admin_notifications WHERE type = 'overdue_loan' AND id > {$noticesBefore} AND related_id IN (" . implode(',', $ids) . ')');
    foreach ([
        'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $titleLike);
        $stmt->execute();
        $stmt->close();
    }
    foreach (['DELETE FROM email_delivery_outbox WHERE recipient_email LIKE ?', 'DELETE FROM utenti WHERE email LIKE ?'] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $emailLike);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // best effort: the outbox table is created on first use
        }
    }
};

$reachable = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$reachableBefore = $reachable->getValue();
$reachable->setValue(null, true);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$fatal = null;
try {
    ConfigStore::usingConnection($db, static function () use ($db, $run, $titlePrefix, $emailDomain, $check): void {
        $today = DateHelper::today();
        $day = static fn (int $offset): string => LibraryCalendar::shift($today, $offset);

        $seq = 0;
        $makeUser = static function (string $role = 'standard') use ($db, $run, $emailDomain, &$seq): array {
            $seq++;
            $card = 'ZZSA' . strtoupper($run) . $seq;
            $email = $role . $seq . $emailDomain;
            $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Staff', 'Alert', ?, '', ?, 'attivo')");
            $stmt->bind_param('sss', $card, $email, $role);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return [$id, $email];
        };
        $makeLoan = static function (int $userId, string $due) use ($db, $titlePrefix, $day, &$seq): int {
            $seq++;
            $title = $titlePrefix . '_' . $seq;
            $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'prestato', 1, 0)");
            $stmt->bind_param('s', $title);
            $stmt->execute();
            $bookId = (int) $db->insert_id;
            $stmt->close();
            $start = $day(-40);
            $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, overdue_notification_sent) VALUES (?, ?, ?, ?, 'in_corso', 'diretto', 1, 0)");
            $stmt->bind_param('iiss', $bookId, $userId, $start, $due);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $loanRow = static fn (int $id): array => $db->query("SELECT stato, overdue_notification_sent AS sent FROM prestiti WHERE id = {$id}")->fetch_assoc() ?? [];
        $notices = static fn (int $loanId): int => (int) $db->query("SELECT COUNT(*) FROM admin_notifications WHERE type = 'overdue_loan' AND related_id = {$loanId}")->fetch_row()[0];

        [, $adminEmail] = $makeUser('admin');
        [$unreachableId, $unreachableEmail] = $makeUser();
        [$reachableId, $reachableEmail] = $makeUser();

        $mailbox = new class extends \App\Support\EmailService {
            /** @var list<array{to: string, template: string, variables: array<string, mixed>}> */
            public array $sent = [];
            /** @var list<array{to: string, template: string}> */
            public array $refused = [];
            /** @var array<string, true> addresses whose mail server refuses delivery */
            public array $failing = [];
            public function __construct()
            {
            }
            public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool
            {
                if (isset($this->failing[$to])) {
                    $this->refused[] = ['to' => $to, 'template' => $templateName];
                    return false;
                }
                $this->sent[] = ['to' => $to, 'template' => $templateName, 'variables' => $variables];
                return true;
            }
        };
        $service = new NotificationService($db);
        (new ReflectionProperty($service, 'emailService'))->setValue($service, $mailbox);
        /** How many e-mails of a template reached an address about one loan. */
        $delivered = static function (string $to, string $template, int $loanId) use ($mailbox, $db): int {
            $title = (string) $db->query("SELECT l.titolo FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE p.id = {$loanId}")->fetch_row()[0];
            return count(array_filter($mailbox->sent, static fn (array $m): bool =>
                $m['to'] === $to && $m['template'] === $template && ($m['variables']['libro_titolo'] ?? null) === $title));
        };
        $attempts = static fn (string $to): int => count(array_filter($mailbox->refused, static fn (array $m): bool => $m['to'] === $to));

        // ── A. The reader's address refuses every delivery ─────────────────
        echo "A. A reader who cannot be reached\n";
        $loan = $makeLoan($unreachableId, $day(-5));
        $mailbox->failing[$unreachableEmail] = true;

        $service->sendOverdueLoanNotifications();
        $row = $loanRow($loan);
        $check($row['stato'] === 'in_ritardo' && (int) $row['sent'] === 0, 'A1 the loan is overdue and the reader notice stays to be retried');
        $check($delivered($adminEmail, 'loan_overdue_admin', $loan) === 1, 'A2 the staff are e-mailed about it all the same');
        $check($notices($loan) === 1, 'A3 and get the in-app notice');
        $firstAttempts = $attempts($unreachableEmail);
        $check($firstAttempts > 0, 'A4 the reader e-mail was really attempted');

        $service->sendOverdueLoanNotifications();
        $check($attempts($unreachableEmail) > $firstAttempts, 'A5 the next run tries the reader again');
        $check($delivered($adminEmail, 'loan_overdue_admin', $loan) === 1 && $notices($loan) === 1, 'A6 but does not alert the staff a second time');

        unset($mailbox->failing[$unreachableEmail]);
        $service->sendOverdueLoanNotifications();
        $check($delivered($unreachableEmail, 'loan_overdue_notification', $loan) === 1 && (int) $loanRow($loan)['sent'] === 1, 'A7 once the address works the reader is notified and the notice is settled');
        $check($delivered($adminEmail, 'loan_overdue_admin', $loan) === 1 && $notices($loan) === 1, 'A8 the staff, alerted already, are not alerted again');

        $service->sendOverdueLoanNotifications();
        $check($delivered($unreachableEmail, 'loan_overdue_notification', $loan) === 1 && $notices($loan) === 1, 'A9 a further run sends nothing more');

        // ── B. A reader whose e-mail works ─────────────────────────────────
        echo "B. A reader who can be reached\n";
        $plain = $makeLoan($reachableId, $day(-4));
        $service->sendOverdueLoanNotifications();
        $check($delivered($reachableEmail, 'loan_overdue_notification', $plain) === 1 && (int) $loanRow($plain)['sent'] === 1, 'B1 the reader is notified');
        $check($delivered($adminEmail, 'loan_overdue_admin', $plain) === 1 && $notices($plain) === 1, 'B2 the staff are alerted once, as before');

        // ── C. The same loan late again after a new due date ───────────────
        echo "C. A new overdue period of the same loan\n";
        // The earlier alert belongs to a due date that was since moved forward
        // and has now passed as well: it is not an alert for this period.
        $db->query("UPDATE admin_notifications SET created_at = '{$day(-30)} 10:00:00' WHERE type = 'overdue_loan' AND related_id = {$loan}");
        $db->query("UPDATE prestiti SET data_scadenza = '{$day(-2)}', overdue_notification_sent = 0 WHERE id = {$loan}");
        $mailbox->failing[$unreachableEmail] = true;
        $service->sendOverdueLoanNotifications();
        $check($delivered($adminEmail, 'loan_overdue_admin', $loan) === 2 && $notices($loan) === 2, 'C1 the staff are alerted for the new period, the reader still unreachable');
        $service->sendOverdueLoanNotifications();
        $check($delivered($adminEmail, 'loan_overdue_admin', $loan) === 2 && $notices($loan) === 2, 'C2 and once only for it');
    });
} catch (Throwable $e) {
    $fatal = $e;
} finally {
    $reachable->setValue(null, $reachableBefore);
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "cleanup failed: {$cleanupError->getMessage()}\n");
    }
    $db->close();
}

if ($fatal !== null) {
    fwrite(STDERR, 'FAIL: ' . $fatal->getMessage() . ' @ ' . basename($fatal->getFile()) . ':' . $fatal->getLine() . "\n");
    exit(1);
}
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
