<?php
declare(strict_types=1);

/**
 * Coherence contract for NCIP circulation vs. the core desk paths
 * (review pass, branch fix/circulation-coherence-0781):
 *
 *   - RenewItem refuses a loan that is overdue BY DATE (in_corso with
 *     data_scadenza < application today), same predicate as
 *     PrestitiController::renew() — mapped to the permanent NCIP
 *     ProblemType 'item-not-renewable';
 *   - a successful RenewItem resets recall_count/last_recall_at like
 *     renew()/update()/bulkExtend, records the 'loan.renewed' audit
 *     event with source=ncip and leaves renewals incremented;
 *   - CheckInItem closes ANY active loan on the item, not just those
 *     with origine='ncip' (parity with the web barcode return: the copy
 *     is physically at the partner's desk), and the loan.returned audit
 *     event carries source=ncip through LoanRepository::close($source);
 *   - CheckOutItem records the 'loan.created' audit event (source=ncip)
 *     and no longer emails a false "loan approved" notification for an
 *     immediate in_corso desk loan;
 *   - CancelRequestItem records 'loan.cancelled' / 'reservation.cancelled'
 *     audit events with source=ncip.
 *
 * Uses the REAL plugin instance against the live local MySQL; touches only
 * data it creates (titles ZZ_NCIPCOH_%, emails zzncipcoh+…@test.local),
 * FK-safe cleanup at start/end and on failure.
 *
 * Run: php tests/ncip-circulation-coherence.unit.php
 */

use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Plugins\NcipServer\NcipServerPlugin;
use App\Support\ActivityLog;
use App\Support\DateHelper;
use App\Support\HookManager;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
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
    // Bind the application-local date like every production writer: the
    // circulation triggers otherwise fall back to the DB's UTC CURRENT_DATE().
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZ_NCIPCOH_' . $run;
$emailDomain = '@ncipcoh.test.local';

$cleanup = static function () use ($db, $titlePrefix, $emailDomain): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = 'zzncipcoh+%' . $emailDomain;
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
        'DELETE FROM utenti WHERE email LIKE ?',
    ] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $emailLike);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // best effort (outbox table may not exist on older schemas)
        }
    }
};

// The renew/checkin/cancel paths email the borrower post-commit. Stop
// sendWithRetry() deterministically before EmailService can contact SMTP.
$smtpProbe = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$originalSmtpReachable = $smtpProbe->getValue();
$smtpProbe->setValue(null, false);

set_exception_handler(static function (Throwable $e) use ($cleanup, $db, $smtpProbe, $originalSmtpReachable): void {
    try { $cleanup(); } catch (Throwable) {}
    $smtpProbe->setValue(null, $originalSmtpReachable);
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$ncip = new NcipServerPlugin($db, new HookManager($db));
$schemaResult = $ncip->ensureSchema();
$cleanup();

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
$makeBook = static function () use ($db, $titlePrefix, $run, &$bookSeq): array {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $copyId = (new CopyRepository($db))->create($bookId, 'ZZNC-' . $run . '-' . $bookSeq, 'disponibile');
    return [$bookId, (int) $copyId];
};

$userSeq = 0;
$makeUser = static function (string $role = 'standard') use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZNC' . strtoupper($run) . $userSeq;
    $email = 'zzncipcoh+' . $run . '-' . $userSeq . $emailDomain;
    $password = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Ncip', 'Coherence', ?, ?, ?, 'attivo')");
    $stmt->bind_param('ssss', $card, $email, $password, $role);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

/** Active copy-bound loan; $origin controls the circulation channel. */
$makeActiveLoan = static function (int $bookId, int $copyId, int $userId, string $start, string $due, string $origin, int $recallCount = 0, ?string $lastRecallAt = null) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza,
             stato, origine, attivo, renewals, recall_count, last_recall_at)
        VALUES (?, ?, ?, ?, ?, 'in_corso', ?, 1, 0, ?, ?)
    ");
    $stmt->bind_param('iiisssis', $bookId, $copyId, $userId, $start, $due, $origin, $recallCount, $lastRecallAt);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    $copyStmt = $db->prepare("UPDATE copie SET stato = 'prestato' WHERE id = ?");
    $copyStmt->bind_param('i', $copyId);
    $copyStmt->execute();
    $copyStmt->close();
    return $loanId;
};

$loanRow = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare('SELECT stato, attivo, renewals, data_scadenza, recall_count, last_recall_at, warning_sent FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        throw new RuntimeException("loan {$loanId} vanished");
    }
    return $row;
};

/** True if the book's audit feed contains $event with meta.source === $source (optionally scoped to entity id). */
$hasAuditEvent = static function (int $bookId, string $event, string $source, ?int $entityId = null) use ($db): bool {
    $feed = ActivityLog::forBook($db, $bookId, 1, 50);
    foreach ($feed['items'] as $item) {
        if (($item['event'] ?? '') !== $event) {
            continue;
        }
        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        if (($meta['source'] ?? '') !== $source) {
            continue;
        }
        if ($entityId !== null && (int) ($meta['entity_id'] ?? 0) !== $entityId) {
            continue;
        }
        return true;
    }
    return false;
};

$staffCaller = ['id' => $makeUser('admin'), 'tipo_utente' => 'admin'];
$request = (new ServerRequestFactory())->createServerRequest('POST', '/ncip');

$today = DateHelper::today();
$settings = new SettingsRepository($db);
$loanDays = (int) ($settings->get('loans', 'loan_duration_days', '30') ?? 30);
$loanDays = $loanDays > 0 ? $loanDays : 30;

$check($schemaResult['failed'] === [], '00 NCIP schema is in place');

// ── A. RenewItem refuses a loan overdue BY DATE ────────────────────────────
echo "A. RenewItem: in_corso loan with past data_scadenza is not renewable\n";
[$aBookId, $aCopyId] = $makeBook();
$aUserId = $makeUser();
$aStart = (new DateTimeImmutable($today))->modify('-10 days')->format('Y-m-d');
$aDue = (new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
$aLoanId = $makeActiveLoan($aBookId, $aCopyId, $aUserId, $aStart, $aDue, 'ncip', 1, $today . ' 09:00:00');

$extendLoan = new ReflectionMethod(NcipServerPlugin::class, 'extendLoan');
$aFailure = 'db_error';
$aResult = $extendLoan->invokeArgs($ncip, [$aLoanId, $aBookId, $aUserId, &$aFailure]);
$check($aResult === null, '01 extendLoan refuses the date-overdue loan');
$check($aFailure === 'overdue', "02 refusal reason is 'overdue' (got '{$aFailure}')");
$aRow = $loanRow($aLoanId);
$check(
    $aRow['data_scadenza'] === $aDue && (int) $aRow['renewals'] === 0 && (int) $aRow['recall_count'] === 1,
    '03 refused renewal mutates nothing (due date, renewals, recall_count intact)'
);

// Same refusal through the real XML dispatcher path → permanent ProblemType.
$handleRenew = new ReflectionMethod(NcipServerPlugin::class, 'handleRenewItem');
$aXml = new SimpleXMLElement(
    '<NCIPMessage><RenewItem>'
    . "<ItemId><ItemIdentifierValue>{$aBookId}</ItemIdentifierValue></ItemId>"
    . "<UserId><UserIdentifierValue>{$aUserId}</UserIdentifierValue></UserId>"
    . '</RenewItem></NCIPMessage>'
);
$aResponse = $handleRenew->invoke($ncip, $request, new SlimResponse(), $aXml, $staffCaller);
$aBody = (string) $aResponse->getBody();
$check(
    str_contains($aBody, '<Problem>') && str_contains($aBody, 'item-not-renewable'),
    '04 RenewItem answers the permanent item-not-renewable ProblemType, not a retryable failure'
);

// ── B. Successful RenewItem resets recall state and audits with source=ncip ─
echo "B. RenewItem success: recall reset + loan.renewed audit (source=ncip)\n";
[$bBookId, $bCopyId] = $makeBook();
$bUserId = $makeUser();
$bDue = (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d');
$bLoanId = $makeActiveLoan($bBookId, $bCopyId, $bUserId, $today, $bDue, 'ncip', 3, $today . ' 08:30:00');
$db->query("UPDATE prestiti SET warning_sent = 1 WHERE id = {$bLoanId}");

$bFailure = 'db_error';
$bNewDue = $extendLoan->invokeArgs($ncip, [$bLoanId, $bBookId, $bUserId, &$bFailure]);
$bExpectedDue = (new DateTimeImmutable($bDue))->modify("+{$loanDays} days")->format('Y-m-d');
$check($bNewDue === $bExpectedDue, "05 renewal extends from the current due date (expected {$bExpectedDue}, got " . var_export($bNewDue, true) . ')');
$bRow = $loanRow($bLoanId);
$check((int) $bRow['renewals'] === 1 && $bRow['data_scadenza'] === $bExpectedDue, '06 renewals incremented and due date persisted');
$check(
    (int) $bRow['recall_count'] === 0 && $bRow['last_recall_at'] === null && (int) $bRow['warning_sent'] === 0,
    '07 renewal resets recall_count/last_recall_at/warning_sent like the desk renew paths'
);
$check($hasAuditEvent($bBookId, 'loan.renewed', 'ncip', $bLoanId), '08 loan.renewed audit event recorded with source=ncip');

// ── C. CheckInItem closes an active loan of ANY origin ─────────────────────
echo "C. CheckInItem: closes a non-ncip active loan (parity with barcode return)\n";
[$cBookId, $cCopyId] = $makeBook();
$cUserId = $makeUser();
$cStart = (new DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
$cDue = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
$cLoanId = $makeActiveLoan($cBookId, $cCopyId, $cUserId, $cStart, $cDue, 'diretto');

$handleCheckIn = new ReflectionMethod(NcipServerPlugin::class, 'handleCheckInItem');
$cXml = new SimpleXMLElement(
    '<NCIPMessage><CheckInItem>'
    . "<ItemId><ItemIdentifierValue>{$cBookId}</ItemIdentifierValue></ItemId>"
    . "<UserId><UserIdentifierValue>{$cUserId}</UserIdentifierValue></UserId>"
    . '</CheckInItem></NCIPMessage>'
);
$cResponse = $handleCheckIn->invoke($ncip, $request, new SlimResponse(), $cXml, $staffCaller);
$cBody = (string) $cResponse->getBody();
$check(
    str_contains($cBody, 'CheckInItemResponse') && !str_contains($cBody, '<Problem>'),
    '09 CheckInItem succeeds on the origine=diretto loan'
);
$cRow = $loanRow($cLoanId);
$check($cRow['stato'] === 'restituito' && (int) $cRow['attivo'] === 0, '10 the web-origin loan is closed as restituito');
$check($hasAuditEvent($cBookId, 'loan.returned', 'ncip', $cLoanId), '11 loan.returned audit event carries source=ncip through LoanRepository::close');
$cTx = (int) $db->query(
    "SELECT COUNT(*) FROM ncip_transactions WHERE message_type = 'CheckInItem' AND prestito_id = {$cLoanId} AND status = 'success'"
)->fetch_row()[0];
$check($cTx === 1, '12 NCIP transaction log records the check-in');

// ── C2. CheckInItem: priorità NCIP e guardia di ambiguità (review A1) ───────
// L'ItemId NCIP identifica il TITOLO: con più prestiti attivi sullo stesso
// titolo la scelta deve essere deterministica (classe ncip prima) e MAI
// arbitraria (due candidati nella stessa classe = rifiuto per ambiguità).
echo "C2. CheckInItem: NCIP-priority class and ambiguity guard\n";
[$gBookId, $gCopy1] = $makeBook();
$gCopy2 = (int) (new CopyRepository($db))->create($gBookId, 'ZZNC-' . $run . '-G2', 'disponibile');
$gUserN = $makeUser();
$gUserM = $makeUser();
$gStartOld = (new DateTimeImmutable($today))->modify('-10 days')->format('Y-m-d');
$gStartNew = (new DateTimeImmutable($today))->modify('-1 days')->format('Y-m-d');
$gDue = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
$gLoanNcip = $makeActiveLoan($gBookId, $gCopy1, $gUserN, $gStartOld, $gDue, 'ncip');
$gLoanMan = $makeActiveLoan($gBookId, $gCopy2, $gUserM, $gStartNew, $gDue, 'diretto');
$gXml = new SimpleXMLElement(
    '<NCIPMessage><CheckInItem>'
    . "<ItemId><ItemIdentifierValue>{$gBookId}</ItemIdentifierValue></ItemId>"
    . '</CheckInItem></NCIPMessage>'
);
$gResponse = $handleCheckIn->invoke($ncip, $request, new SlimResponse(), $gXml, $staffCaller);
$gBody = (string) $gResponse->getBody();
$check(
    str_contains($gBody, 'CheckInItemResponse') && !str_contains($gBody, '<Problem>'),
    '12a mixed-origin title: check-in without UserId succeeds'
);
$check($loanRow($gLoanNcip)['stato'] === 'restituito', '12b the NCIP loan is the one closed (priority class beats recency)');
$gManRow = $loanRow($gLoanMan);
$check($gManRow['stato'] === 'in_corso' && (int) $gManRow['attivo'] === 1, '12c the newer manual loan stays untouched');

[$hBookId, $hCopy1] = $makeBook();
$hCopy2 = (int) (new CopyRepository($db))->create($hBookId, 'ZZNC-' . $run . '-H2', 'disponibile');
$hUser1 = $makeUser();
$hUser2 = $makeUser();
$hLoan1 = $makeActiveLoan($hBookId, $hCopy1, $hUser1, $gStartOld, $gDue, 'diretto');
$hLoan2 = $makeActiveLoan($hBookId, $hCopy2, $hUser2, $gStartNew, $gDue, 'diretto');
$hXml = new SimpleXMLElement(
    '<NCIPMessage><CheckInItem>'
    . "<ItemId><ItemIdentifierValue>{$hBookId}</ItemIdentifierValue></ItemId>"
    . '</CheckInItem></NCIPMessage>'
);
$hResponse = $handleCheckIn->invoke($ncip, $request, new SlimResponse(), $hXml, $staffCaller);
$hBody = (string) $hResponse->getBody();
$check(str_contains($hBody, '<Problem>'), '12d two same-class candidates without UserId: refused as ambiguous');
$check(
    $loanRow($hLoan1)['stato'] === 'in_corso' && $loanRow($hLoan2)['stato'] === 'in_corso',
    '12e neither manual loan is closed arbitrarily'
);

// ── D. CheckOutItem: loan.created audit, no false approval email ───────────
echo "D. CheckOutItem: audits loan.created (source=ncip)\n";
[$dBookId] = $makeBook();
$dUserId = $makeUser();
$dDue = (new DateTimeImmutable($today))->modify("+{$loanDays} days")->format('Y-m-d');
$createLoanAtomic = new ReflectionMethod(NcipServerPlugin::class, 'createLoanAtomic');
$dFailure = null;
$dLoanId = $createLoanAtomic->invokeArgs($ncip, [$dBookId, $dUserId, $dDue, (int) $staffCaller['id'], &$dFailure]);
$check(is_int($dLoanId) && $dLoanId > 0, '13 atomic NCIP checkout creates the in_corso loan (' . var_export($dFailure, true) . ')');
$check($hasAuditEvent($dBookId, 'loan.created', 'ncip', (int) $dLoanId), '14 loan.created audit event recorded with source=ncip');
$dOutbox = 0;
try {
    $dOutbox = (int) $db->query(
        "SELECT COUNT(*) FROM email_delivery_outbox o JOIN utenti u ON u.email = o.recipient_email WHERE u.id = {$dUserId} AND o.template_name = 'loan_approved'"
    )->fetch_row()[0];
} catch (Throwable) {
    // outbox table absent → certainly no queued approval email
}
$check($dOutbox === 0, '15 immediate desk checkout queues no loan-approved email (parity with store())');
$pluginSource = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');
$check(!str_contains($pluginSource, 'sendLoanApprovedNotification'), '16 CheckOutItem no longer calls sendLoanApprovedNotification at all');

// ── E. CancelRequestItem audits both cancellation flavours ─────────────────
echo "E. CancelRequestItem: loan.cancelled / reservation.cancelled audit (source=ncip)\n";
[$eBookId] = $makeBook();
$eUserId = $makeUser();
$stmt = $db->prepare("
    INSERT INTO prestiti
        (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
    VALUES (?, ?, ?, ?, 'pendente', 'ncip', 0)
");
$eDue = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
$stmt->bind_param('iiss', $eBookId, $eUserId, $today, $eDue);
$stmt->execute();
$ePendingId = (int) $db->insert_id;
$stmt->close();

$cancelPending = new ReflectionMethod(NcipServerPlugin::class, 'cancelPendingNcipRequest');
$eOutcome = $cancelPending->invoke($ncip, $eBookId, $eUserId);
$check(
    ($eOutcome['status'] ?? '') === 'cancelled' && (int) ($eOutcome['loan_id'] ?? 0) === $ePendingId,
    '17 the pending NCIP loan request is cancelled'
);
$eState = (string) $db->query("SELECT stato FROM prestiti WHERE id = {$ePendingId}")->fetch_row()[0];
$check($eState === 'annullato', '18 the pending request reaches the annullato state');
$check($hasAuditEvent($eBookId, 'loan.cancelled', 'ncip', $ePendingId), '19 loan.cancelled audit event recorded with source=ncip');

[$fBookId] = $makeBook();
$fUserId = $makeUser();
$stmt = $db->prepare("
    INSERT INTO prenotazioni
        (libro_id, utente_id, queue_position, stato, data_inizio_richiesta, data_fine_richiesta)
    VALUES (?, ?, 1, 'attiva', ?, ?)
");
$stmt->bind_param('iiss', $fBookId, $fUserId, $today, $eDue);
$stmt->execute();
$fReservationId = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare("
    INSERT INTO ncip_transactions
        (partner_id, message_type, prestito_id, prenotazione_id, request_id, status, created_at)
    VALUES (NULL, 'RequestItem', NULL, ?, ?, 'success', NOW())
");
$fRequestRef = 'ZZNC-' . $run;
$stmt->bind_param('is', $fReservationId, $fRequestRef);
$stmt->execute();
$stmt->close();

$fOutcome = $cancelPending->invoke($ncip, $fBookId, $fUserId);
$check(
    ($fOutcome['status'] ?? '') === 'cancelled' && (int) ($fOutcome['reservation_id'] ?? 0) === $fReservationId,
    '20 the NCIP-linked reservation is cancelled'
);
$fState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$fReservationId}")->fetch_row()[0];
$check($fState === 'annullata', '21 the reservation reaches the annullata state');
$check($hasAuditEvent($fBookId, 'reservation.cancelled', 'ncip', $fReservationId), '22 reservation.cancelled audit event recorded with source=ncip');

// ── F. Static contract: close() propagates its audit source ────────────────
echo "F. static contract: LoanRepository::close signature carries \$source\n";
$repoSource = (string) file_get_contents($root . '/app/Models/LoanRepository.php');
$check(
    str_contains($repoSource, "string \$source = 'manual'") && str_contains($repoSource, 'source: $source'),
    '23 LoanRepository::close accepts and propagates the audit source (default manual)'
);
$check(str_contains($pluginSource, "'ncip'\n            );") || preg_match('/close\(\s*\$loanId,\s*\$expectedBookId,\s*\$expectedUserId,\s*\'ncip\'/', $pluginSource) === 1,
    '24 the NCIP plugin passes source=ncip to LoanRepository::close');

$cleanup();
$smtpProbe->setValue(null, $originalSmtpReachable);
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);
