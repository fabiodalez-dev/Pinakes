<?php
declare(strict_types=1);

/**
 * NCIP operator writes honour the same gates as the web desk and the public
 * request form:
 *
 *  A. RequestItem never stores a hold on days the title has no free capacity.
 *     The web refuses such a window and lets the patron pick later days; a
 *     hold placed through NCIP has no patron-chosen start, so it lands on the
 *     first window the web would also have accepted, and is refused when no
 *     copy can ever serve it (every copy lost).
 *  B. Separation of duties (App\Support\SelfDealingGuard): a Staff account
 *     authenticated by Basic auth cannot check out, check in, renew, request
 *     or cancel for its own user id; an administrator is exempt.
 *  C. Catalogue-only mode refuses CheckOutItem and RequestItem, while
 *     CheckInItem, RenewItem and CancelRequestItem stay open.
 *
 * The real plugin instance and its real XML handlers against the database in
 * E2E_DB_* (or the worktree .env); only rows this run creates are touched.
 *
 * Run: php tests/ncip-web-parity-gates.unit.php
 */

use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Plugins\NcipServer\NcipServerPlugin;
use App\Services\CapacityService;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\HookManager;
use App\Support\LibraryCalendar;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

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
$titlePrefix = 'ZZ_NCIPGATE_' . $run;
$emailDomain = '@ncipgate.test.local';

// Catalogue mode is restored to exactly what this run found.
$catalogueRow = $db->query("SELECT setting_value FROM system_settings WHERE category = 'system' AND setting_key = 'catalogue_mode'")->fetch_row();
$restoreCatalogueMode = static function () use ($db, $catalogueRow): void {
    if ($catalogueRow === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'system' AND setting_key = 'catalogue_mode'");
        return;
    }
    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category = 'system' AND setting_key = 'catalogue_mode'");
    $value = (string) $catalogueRow[0];
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
};

$cleanup = static function () use ($db, $titlePrefix, $emailDomain, $restoreCatalogueMode): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = 'zzncipgate+%' . $emailDomain;
    foreach ([
        'DELETE tx FROM ncip_transactions tx JOIN prestiti p ON p.id = tx.prestito_id JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE tx FROM ncip_transactions tx JOIN prenotazioni r ON r.id = tx.prenotazione_id JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
        "DELETE lm FROM log_modifiche lm JOIN libri l ON l.id = lm.record_id AND lm.tabella = 'libri' WHERE l.titolo LIKE ?",
        'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
        'DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $titleLike);
        $stmt->execute();
        $stmt->close();
    }
    foreach ([
        'DELETE FROM email_delivery_outbox WHERE recipient_email LIKE ?',
        'DELETE n FROM notifiche_utente n JOIN utenti u ON u.id = n.utente_id WHERE u.email LIKE ?',
        'DELETE FROM utenti WHERE email LIKE ?',
    ] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $emailLike);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // best effort: optional tables differ between schema versions
        }
    }
    $restoreCatalogueMode();
};

// The handlers email the borrower post-commit: stop before any SMTP contact.
$smtpProbe = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$originalSmtpReachable = $smtpProbe->getValue();
$smtpProbe->setValue(null, false);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$fatal = null;
try {
    // Settings are read from the test database, never from the worktree's own.
    ConfigStore::usingConnection($db, static function () use ($db, $run, $titlePrefix, $emailDomain, $check, $cleanup): void {
        $ncip = new NcipServerPlugin($db, new HookManager($db));
        $schema = $ncip->ensureSchema();
        $cleanup();
        $check($schema['failed'] === [], '00 NCIP schema is in place');

        $settings = new SettingsRepository($db);
        $setCatalogueMode = static function (bool $on) use ($settings): void {
            $settings->set('system', 'catalogue_mode', $on ? '1' : '0');
            ConfigStore::clearCache();
        };
        $setCatalogueMode(false);

        $today = DateHelper::today();
        $day = static fn (int $offset): string => LibraryCalendar::shift($today, $offset);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/ncip');

        $bookSeq = 0;
        /** @return array{0:int,1:int} */
        $makeBook = static function (string $copyState = 'disponibile') use ($db, $titlePrefix, $run, &$bookSeq): array {
            $bookSeq++;
            $title = $titlePrefix . '_' . $bookSeq;
            $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)");
            $stmt->bind_param('s', $title);
            $stmt->execute();
            $bookId = (int) $db->insert_id;
            $stmt->close();
            $copyId = (int) (new CopyRepository($db))->create($bookId, 'ZZNG-' . $run . '-' . $bookSeq, $copyState);
            return [$bookId, $copyId];
        };
        $userSeq = 0;
        $makeUser = static function (string $role = 'standard') use ($db, $run, $emailDomain, &$userSeq): int {
            $userSeq++;
            $card = 'ZZNG' . strtoupper($run) . $userSeq;
            $email = 'zzncipgate+' . $run . '-' . $userSeq . $emailDomain;
            $password = password_hash('test', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Ncip', 'Gate', ?, ?, ?, 'attivo')");
            $stmt->bind_param('ssss', $card, $email, $password, $role);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $makeActiveLoan = static function (int $bookId, int $copyId, int $userId, string $start, string $due, string $state = 'in_corso') use ($db): int {
            $stmt = $db->prepare("
                INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, renewals)
                VALUES (?, ?, ?, ?, ?, ?, 'ncip', 1, 0)
            ");
            $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $due, $state);
            $stmt->execute();
            $loanId = (int) $db->insert_id;
            $stmt->close();
            $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyId}");
            return $loanId;
        };
        $makePendingNcipRequest = static function (int $bookId, int $userId) use ($db, $day): int {
            $stmt = $db->prepare("
                INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                VALUES (?, ?, ?, ?, 'pendente', 'ncip', 0)
            ");
            $start = $day(0);
            $due = $day(10);
            $stmt->bind_param('iiss', $bookId, $userId, $start, $due);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $loanRow = static function (int $loanId) use ($db): array {
            return $db->query("SELECT stato, attivo, data_scadenza, utente_id FROM prestiti WHERE id = {$loanId}")->fetch_assoc() ?? [];
        };
        $loansOf = static fn (int $bookId, int $userId): int =>
            (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$userId}")->fetch_row()[0];
        $holdOf = static function (int $bookId, int $userId) use ($db): ?array {
            return $db->query("SELECT id, data_inizio_richiesta AS s, data_fine_richiesta AS e FROM prenotazioni WHERE libro_id = {$bookId} AND utente_id = {$userId} AND stato = 'attiva'")->fetch_assoc();
        };

        /** One NCIP message through its real handler; returns [status, body]. */
        $send = static function (string $type, int $bookId, ?int $userId, array $caller) use ($ncip, $request): array {
            $xml = new SimpleXMLElement(
                "<NCIPMessage><{$type}>"
                . ($userId !== null ? "<UserId><UserIdentifierValue>{$userId}</UserIdentifierValue></UserId>" : '')
                . "<ItemId><ItemIdentifierValue>{$bookId}</ItemIdentifierValue></ItemId>"
                . "</{$type}></NCIPMessage>"
            );
            $handler = new ReflectionMethod(NcipServerPlugin::class, 'handle' . $type);
            $response = $handler->invoke($ncip, $request, new SlimResponse(), $xml, $caller);
            return [$response->getStatusCode(), (string) $response->getBody()];
        };
        $isProblem = static fn (array $answer): bool => str_contains($answer[1], '<Problem>');

        $admin = ['id' => $makeUser('admin'), 'tipo_utente' => 'admin'];
        $staffId = $makeUser('staff');
        $staff = ['id' => $staffId, 'tipo_utente' => 'staff'];

        // ── A. RequestItem capacity ────────────────────────────────────────
        echo "A. RequestItem and the capacity rule of the web request form\n";
        $capacity = new CapacityService($db);

        // A1: the only copy is on loan until today+10.
        [$a1Book, $a1Copy] = $makeBook();
        $a1Borrower = $makeUser();
        $a1Reader = $makeUser();
        $makeActiveLoan($a1Book, $a1Copy, $a1Borrower, $day(-2), $day(10));
        $a1 = $send('RequestItem', $a1Book, $a1Reader, $admin);
        $a1Hold = $holdOf($a1Book, $a1Reader);
        $check(!$isProblem($a1) && $a1Hold !== null, 'A1 a title whose only copy is out still takes the hold');
        $check(
            $a1Hold !== null && $a1Hold['s'] > LibraryCalendar::holdingEnd($db, $day(10), $a1Copy),
            'A1 the hold starts after the running loan ends, not on top of it (starts ' . ($a1Hold['s'] ?? 'n/a') . ')'
        );
        $check(
            $a1Hold !== null && $capacity->occupiedCount($a1Book, (string) $a1Hold['s'], (string) $a1Hold['e']) <= $capacity->totalCopies($a1Book),
            'A1 no day of the stored window holds more commitments than copies'
        );
        $check(
            $a1Hold !== null && $capacity->hasFreeCapacity($a1Book, (string) $a1Hold['s'], (string) $a1Hold['e'], excludeUserId: $a1Reader, forQueue: true),
            'A1 the web request form accepts the very same window'
        );
        $check($loansOf($a1Book, $a1Reader) === 0, 'A1 no bare pending loan is written beside the hold');

        // A2: every copy is lost: nothing can ever serve the hold.
        [$a2Book] = $makeBook('perso');
        $a2Reader = $makeUser();
        $a2 = $send('RequestItem', $a2Book, $a2Reader, $admin);
        $check($isProblem($a2), 'A2 a title with no lendable copy refuses the hold');
        $check(!str_contains($a2[1], 'Temporary Processing Failure'), 'A2 the refusal is permanent, not a retryable processing failure');
        $check($holdOf($a2Book, $a2Reader) === null && $loansOf($a2Book, $a2Reader) === 0, 'A2 nothing is stored for the refused hold');

        // A3: a copy is on the shelf: the plain pending request, from today.
        [$a3Book] = $makeBook();
        $a3Reader = $makeUser();
        $a3 = $send('RequestItem', $a3Book, $a3Reader, $admin);
        $a3Loan = $db->query("SELECT stato, data_prestito FROM prestiti WHERE libro_id = {$a3Book} AND utente_id = {$a3Reader}")->fetch_assoc();
        $check(
            !$isProblem($a3) && is_array($a3Loan) && $a3Loan['stato'] === 'pendente'
                && $a3Loan['data_prestito'] === LibraryCalendar::requestStartDay($db, $today, $a3Book),
            'A3 a free copy still gives a pending request starting on the first open day'
        );

        // A4: the only copy is overdue. The web queues from tomorrow, never today.
        [$a4Book, $a4Copy] = $makeBook();
        $a4Borrower = $makeUser();
        $a4Reader = $makeUser();
        $makeActiveLoan($a4Book, $a4Copy, $a4Borrower, $day(-20), $day(-3), 'in_ritardo');
        $a4 = $send('RequestItem', $a4Book, $a4Reader, $admin);
        $a4Hold = $holdOf($a4Book, $a4Reader);
        $check(
            !$isProblem($a4) && $a4Hold !== null && $a4Hold['s'] > $today
                && $capacity->hasFreeCapacity($a4Book, (string) $a4Hold['s'], (string) $a4Hold['e'], excludeUserId: $a4Reader, forQueue: true),
            'A4 behind an overdue copy the hold starts after today, on a window the web accepts'
        );

        // ── B. Separation of duties ────────────────────────────────────────
        echo "B. An operator cannot record circulation on its own account\n";
        [$b1Book] = $makeBook();
        $b1 = $send('CheckOutItem', $b1Book, $staffId, $staff);
        $check($isProblem($b1) && $loansOf($b1Book, $staffId) === 0, 'B1 CheckOutItem for the operator itself is refused, no loan is written');
        $check($b1[0] === 403, 'B1 the refusal is a 403 like every other privilege refusal (got ' . $b1[0] . ')');

        [$b2Book, $b2Copy] = $makeBook();
        $b2Loan = $makeActiveLoan($b2Book, $b2Copy, $staffId, $day(-3), $day(7));
        $b2 = $send('CheckInItem', $b2Book, $staffId, $staff);
        $check($isProblem($b2) && (int) $loanRow($b2Loan)['attivo'] === 1, 'B2 CheckInItem of its own loan is refused, the loan stays open');
        $b2NoUser = $send('CheckInItem', $b2Book, null, $staff);
        $check($isProblem($b2NoUser) && (int) $loanRow($b2Loan)['attivo'] === 1, 'B2 omitting UserId does not get around it: the resolved borrower is judged');

        [$b3Book, $b3Copy] = $makeBook();
        $b3Loan = $makeActiveLoan($b3Book, $b3Copy, $staffId, $day(-3), $day(7));
        $b3 = $send('RenewItem', $b3Book, $staffId, $staff);
        $check($isProblem($b3) && $loanRow($b3Loan)['data_scadenza'] === $day(7), 'B3 RenewItem of its own loan is refused, the due date is unchanged');
        $b3NoUser = $send('RenewItem', $b3Book, null, $staff);
        $check($isProblem($b3NoUser) && $loanRow($b3Loan)['data_scadenza'] === $day(7), 'B3 RenewItem without UserId is refused as well');
        $b3Admin = $send('RenewItem', $b3Book, $staffId, $admin);
        $check(!$isProblem($b3Admin) && $loanRow($b3Loan)['data_scadenza'] > $day(7), "B3 an administrator renews the operator's loan");

        [$b4Book] = $makeBook();
        $b4 = $send('RequestItem', $b4Book, $staffId, $staff);
        $check($isProblem($b4) && $loansOf($b4Book, $staffId) === 0 && $holdOf($b4Book, $staffId) === null, 'B4 RequestItem for the operator itself is refused, nothing is stored');

        [$b5Book] = $makeBook();
        $b5Pending = $makePendingNcipRequest($b5Book, $staffId);
        $b5 = $send('CancelRequestItem', $b5Book, $staffId, $staff);
        $check($isProblem($b5) && $loanRow($b5Pending)['stato'] === 'pendente', 'B5 CancelRequestItem of its own request is refused, the request stays pending');
        $b5NoUser = $send('CancelRequestItem', $b5Book, null, $staff);
        $check($isProblem($b5NoUser) && $loanRow($b5Pending)['stato'] === 'pendente', 'B5 CancelRequestItem without UserId is refused as well');

        // The guard judges the operator, not the message: the same writes go
        // through for another reader, and an administrator is exempt.
        $b6Reader = $makeUser();
        [$b6Book] = $makeBook();
        $b6 = $send('CheckOutItem', $b6Book, $b6Reader, $staff);
        $check(!$isProblem($b6) && $loansOf($b6Book, $b6Reader) === 1, 'B6 Staff still checks out for another reader');
        $b6Renew = $send('RenewItem', $b6Book, $b6Reader, $staff);
        $check(!$isProblem($b6Renew), "B6 Staff still renews another reader's loan");
        $b6In = $send('CheckInItem', $b6Book, $b6Reader, $staff);
        $check(!$isProblem($b6In), "B6 Staff still checks in another reader's loan");
        $b7 = $send('CheckInItem', $b2Book, $staffId, $admin);
        $check(!$isProblem($b7) && $loanRow($b2Loan)['stato'] === 'restituito', "B7 an administrator checks in the operator's loan");
        [$b8Book] = $makeBook();
        $b8 = $send('CheckOutItem', $b8Book, (int) $admin['id'], $admin);
        $check(!$isProblem($b8) && $loansOf($b8Book, (int) $admin['id']) === 1, 'B8 an administrator is exempt on its own account');
        $b5Admin = $send('CancelRequestItem', $b5Book, null, $admin);
        $check(!$isProblem($b5Admin) && $loanRow($b5Pending)['stato'] === 'annullato', "B9 an administrator cancels the operator's request");

        // ── C. Catalogue-only mode ─────────────────────────────────────────
        echo "C. Catalogue-only mode: no new loans or requests, returns stay open\n";
        $cReader = $makeUser();
        [$c1Book] = $makeBook();
        [$c2Book] = $makeBook();
        [$c3Book, $c3Copy] = $makeBook();
        $c3Loan = $makeActiveLoan($c3Book, $c3Copy, $cReader, $day(-3), $day(7));
        [$c4Book, $c4Copy] = $makeBook();
        $c4Loan = $makeActiveLoan($c4Book, $c4Copy, $cReader, $day(-3), $day(7));
        [$c5Book] = $makeBook();
        $c5Pending = $makePendingNcipRequest($c5Book, $cReader);

        $setCatalogueMode(true);
        $c1 = $send('CheckOutItem', $c1Book, $cReader, $admin);
        $check($isProblem($c1) && $loansOf($c1Book, $cReader) === 0, 'C1 CheckOutItem is refused in catalogue mode, no loan is written');
        $check(!str_contains($c1[1], 'Temporary Processing Failure'), 'C1 the refusal is not a retryable processing failure');
        $c2 = $send('RequestItem', $c2Book, $cReader, $admin);
        $check($isProblem($c2) && $loansOf($c2Book, $cReader) === 0 && $holdOf($c2Book, $cReader) === null, 'C2 RequestItem is refused in catalogue mode, nothing is stored');
        $c4 = $send('RenewItem', $c4Book, $cReader, $admin);
        $check(!$isProblem($c4) && $loanRow($c4Loan)['data_scadenza'] > $day(7), 'C3 RenewItem still works in catalogue mode');
        $c3 = $send('CheckInItem', $c3Book, $cReader, $admin);
        $check(!$isProblem($c3) && $loanRow($c3Loan)['stato'] === 'restituito', 'C4 CheckInItem still works in catalogue mode');
        $c5 = $send('CancelRequestItem', $c5Book, $cReader, $admin);
        $check(!$isProblem($c5) && $loanRow($c5Pending)['stato'] === 'annullato', 'C5 CancelRequestItem still works in catalogue mode');

        $setCatalogueMode(false);
        $c6 = $send('CheckOutItem', $c1Book, $cReader, $admin);
        $check(!$isProblem($c6) && $loansOf($c1Book, $cReader) === 1, 'C6 leaving catalogue mode reopens CheckOutItem');
    });
} catch (Throwable $e) {
    $fatal = $e;
} finally {
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "cleanup failed: {$cleanupError->getMessage()}\n");
    }
    $smtpProbe->setValue(null, $originalSmtpReachable);
    $db->close();
}

if ($fatal !== null) {
    fwrite(STDERR, 'FAIL: ' . $fatal->getMessage() . ' @ ' . basename($fatal->getFile()) . ':' . $fatal->getLine() . "\n");
    exit(1);
}
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
