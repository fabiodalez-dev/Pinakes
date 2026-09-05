<?php
declare(strict_types=1);

/**
 * Audit-trail behavioral suite for the automated circulation sweeps.
 *
 * Guards the review fixes of branch fix/circulation-coherence-0781: every
 * autonomous mutation of a loan/reservation must leave a SYSTEM-operator
 * audit event, and the DataIntegrity repair must not be silent.
 *
 * Covers, against the REAL database and the REAL services:
 *  - checkExpiredPickups: expired da_ritirare produces a `loan.expired`
 *    audit event with SYSTEM operator (utente_id NULL) and source 'sweep';
 *  - updateOverdueLoans: the bulk in_corso -> in_ritardo flip produces a
 *    `loan.overdue` event per flipped loan (ids collected BEFORE the UPDATE);
 *  - checkExpiredReservations: a `pendente` with origine='richiesta' whose
 *    window fully passed is expired (scaduto, attivo=0), gets a
 *    `reservation.expired` audit event and the reservation_expired email is
 *    claimed in email_delivery_outbox (or delivered when SMTP is reachable);
 *  - DataIntegrity::fixDataInconsistencies: a duplicate active reservation is
 *    cancelled WITH a `reservation.cancelled` event, source 'repair';
 *  - ActivityLog::loadLoanSnapshot includes the `sanzione` column so
 *    diffs/events preserve the penalty.
 *
 * Dates derive from DateHelper::today() (application timezone) — never from
 * the runner's local clock.
 *
 * Run: php tests/circulation-sweep-audit.unit.php
 */

use App\Support\ActivityLog;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\MaintenanceService;

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
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
    fwrite(STDERR, "FAIL: database unreachable — sweep audit suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$prefix = "ZZ_AUDIT_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$bookIds = [];
$userIds = [];
$smtpOriginals = [];

$cleanup = static function () use ($db, &$bookIds, &$userIds, &$smtpOriginals): void {
    // Ripristina le impostazioni SMTP originali (forzate a "bloccato" prima
    // dello sweep per rendere deterministico il check 16 sull'outbox).
    foreach ($smtpOriginals as [$cat, $key, $orig]) {
        try {
            if ($orig === null) {
                $del = $db->prepare("DELETE FROM system_settings WHERE category = ? AND setting_key = ?");
                $del->bind_param('ss', $cat, $key);
                $del->execute();
                $del->close();
            } else {
                $up = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE category = ? AND setting_key = ?");
                $up->bind_param('sss', $orig, $cat, $key);
                $up->execute();
                $up->close();
            }
        } catch (Throwable) {}
    }
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        try { $db->query("DELETE FROM notifications WHERE user_id = {$id}"); } catch (Throwable) {}
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
    try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-audit-%@test.local'"); } catch (Throwable) {}
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

$makeBook = static function (string $suffix, int $copies) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix}_{$suffix}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, ?, ?, 'disponibile')");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $inv = strtoupper("{$suffix}{$i}-") . strtoupper(substr($prefix, -6));
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copyIds[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $copyIds];
};

$makeUser = static function (string $suffix) use ($db, $run, &$userIds): array {
    $email = "zz-audit-{$suffix}-{$run}@test.local";
    $card = 'ZA' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
    $password = password_hash('AuditSuite!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Audit', ?, ?, ?, 'standard', 'attivo', 1)"
    );
    $cog = ucfirst($suffix);
    $stmt->bind_param('ssss', $card, $cog, $email, $password);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $userId;
    return [$userId, $email];
};

$today = DateHelper::today();
$d = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$loanCol = static function (int $loanId, string $col) use ($db): ?string {
    $res = $db->query("SELECT {$col} AS v FROM prestiti WHERE id = {$loanId}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row === null ? null : ($row['v'] === null ? null : (string) $row['v']);
};

/**
 * Latest audit event of a given _activity.event for a book. Returns
 * ['utente_id' => ?string, 'meta' => array, 'entity_id' => ?int] or null.
 */
$auditEvent = static function (int $bookId, string $event, ?int $entityId = null) use ($db): ?array {
    $res = $db->query(
        "SELECT utente_id, dati_nuovi FROM log_modifiche
         WHERE tabella = 'libri' AND record_id = {$bookId}
         ORDER BY id DESC"
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $decoded = json_decode((string) $row['dati_nuovi'], true);
        $meta = is_array($decoded) && isset($decoded['_activity']) && is_array($decoded['_activity'])
            ? $decoded['_activity']
            : [];
        if (($meta['event'] ?? '') !== $event) {
            continue;
        }
        if ($entityId !== null && (int) ($meta['entity_id'] ?? 0) !== $entityId) {
            continue;
        }
        return [
            'utente_id' => $row['utente_id'],
            'meta' => $meta,
            'entity_id' => isset($meta['entity_id']) ? (int) $meta['entity_id'] : null,
        ];
    }
    return null;
};

// ═════════ Fixture P: expired pickup (audit loan.expired) ═════════
[$bookP, [$copyP]] = $makeBook('P', 1);
[$userP] = $makeUser('p');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyP}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookP}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
     VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
);
$sP = $d(-5); $eP = $d(9); $dlP = $d(-2);
$stmt->bind_param('iiisss', $bookP, $copyP, $userP, $sP, $eP, $dlP);
$stmt->execute();
$loanP = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture O: overdue in_corso (audit loan.overdue) ═════════
[$bookO, [$copyO]] = $makeBook('O', 1);
[$userO] = $makeUser('o');
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyO}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prestato' WHERE id = {$bookO}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, sanzione)
     VALUES (?, ?, ?, ?, ?, 'in_corso', 'diretto', 1, 12.50)"
);
$sO = $d(-20); $eO = $d(-2);
$stmt->bind_param('iiiss', $bookO, $copyO, $userO, $sO, $eO);
$stmt->execute();
$loanO = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture R: eternal pendente, origine='richiesta', window passed ═════════
[$bookR] = $makeBook('R', 1);
[$userR, $emailR] = $makeUser('r');
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, NULL, ?, ?, ?, 'pendente', 'richiesta', 0)"
);
$sR = $d(-15); $eR = $d(-3);
$stmt->bind_param('iiss', $bookR, $userR, $sR, $eR);
$stmt->execute();
$loanR = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture N: eternal pendente, origine='ncip', window passed ═════════
[$bookN] = $makeBook('N', 1);
[$userN] = $makeUser('n');
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, NULL, ?, ?, ?, 'pendente', 'ncip', 0)"
);
$sN = $d(-12); $eN = $d(-1);
$stmt->bind_param('iiss', $bookN, $userN, $sN, $eN);
$stmt->execute();
$loanN = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture G: pendente 'richiesta' with window still OPEN (control) ═════════
[$bookG] = $makeBook('G', 1);
[$userG] = $makeUser('g');
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, NULL, ?, ?, ?, 'pendente', 'richiesta', 0)"
);
$sG = $d(-2); $eG = $d(5);
$stmt->bind_param('iiss', $bookG, $userG, $sG, $eG);
$stmt->execute();
$loanG = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture Q: duplicate active reservations, same user/book (repair) ═════════
// The BEFORE INSERT trigger correctly forbids this nowadays: the repair targets
// LEGACY pre-trigger data. Seed it by suspending the trigger for one INSERT
// only (same extend/restore pattern as the ENUM-bypass tests), recreating it
// verbatim from SHOW CREATE TRIGGER.
[$bookQ] = $makeBook('Q', 1);
[$userQ] = $makeUser('q');
$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, ?, 'attiva')"
);
$rqS = $d(0); $rqE = $d(7);
$pos1 = 1;
$stmt->bind_param('iissi', $bookQ, $userQ, $rqS, $rqE, $pos1);
$stmt->execute();
$resQ1 = (int) $db->insert_id;
$stmt->close();

$trgRow = $db->query('SHOW CREATE TRIGGER trg_check_prenotazione_before_insert')->fetch_assoc();
$trgSql = (string) ($trgRow['SQL Original Statement'] ?? '');
if ($trgSql === '') {
    throw new RuntimeException('Cannot capture trg_check_prenotazione_before_insert for the legacy-duplicate fixture');
}
$db->query('DROP TRIGGER trg_check_prenotazione_before_insert');
// Ripristino idempotente anche sul percorso di uscita FATALE (timeout, OOM,
// kill): il finally copre solo le eccezioni — senza shutdown hook un fatal
// error lascerebbe lo schema di test senza il trigger, in silenzio, per
// tutte le esecuzioni successive.
register_shutdown_function(static function () use ($db, $trgSql): void {
    try {
        if ($db->query("SHOW TRIGGERS LIKE 'prenotazioni'") !== false) {
            $exists = false;
            $res = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_check_prenotazione_before_insert'");
            if ($res && $res->fetch_row()) {
                $exists = true;
            }
            if (!$exists) {
                $db->query($trgSql);
            }
        }
    } catch (Throwable) {
        // Connessione già chiusa a fine run: il finally ha già ripristinato.
    }
});
try {
    $stmt = $db->prepare(
        "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
         VALUES (?, ?, ?, ?, 2, 'attiva')"
    );
    $stmt->bind_param('iiss', $bookQ, $userQ, $rqS, $rqE);
    $stmt->execute();
    $resQ2 = (int) $db->insert_id;
    $stmt->close();
} finally {
    $db->query($trgSql);
}

// SMTP deterministicamente BLOCCATO prima dello sweep: driver smtp verso la
// porta 1 di localhost (connessione rifiutata all'istante). Il claim outbox
// viene inserito PRIMA del circuit-breaker in sendWithRetry, quindi con SMTP
// giù la riga reservation_expired resta visibile e il check 16 non dipende
// più dall'ambiente (Mailpit acceso/spento). Originali ripristinati in cleanup.
// ConfigStore in CLI legge le credenziali da env (config/settings.php): vanno
// esportate, altrimenti ignora system_settings e usa il driver 'mail' di
// default — e il primo tentativo fallito latcha $connectionFailed.
putenv('DB_HOST=' . (getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1')));
putenv('DB_USER=' . (getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '')));
putenv('DB_PASS=' . (getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''))));
putenv('DB_NAME=' . (getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '')));
putenv('DB_PORT=' . (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? '3306')));
if ($socket !== '') {
    putenv('DB_SOCKET=' . $socket);
}
foreach ([['email', 'driver_mode', 'smtp'], ['email', 'smtp_host', '127.0.0.1'], ['email', 'smtp_port', '1']] as [$sCat, $sKey, $sVal]) {
    $cur = $db->prepare("SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ?");
    $cur->bind_param('ss', $sCat, $sKey);
    $cur->execute();
    $curRow = $cur->get_result()->fetch_assoc();
    $cur->close();
    $smtpOriginals[] = [$sCat, $sKey, $curRow['setting_value'] ?? null];
    $up = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $up->bind_param('sss', $sCat, $sKey, $sVal);
    $up->execute();
    $up->close();
}
// Bust della cache in-process di ConfigStore (già scaldata da chi ha letto
// config prima di questo punto): le scritture dirette su system_settings non
// la invalidano da sole. Va azzerato anche il latch connectionFailed: un
// primo tentativo senza env DB lo lascia a true e getConnection() non
// riprova mai più.
foreach (['runtimeCache' => null, 'dbSettingsCache' => null, 'sharedConnection' => null, 'connectionFailed' => false] as $cacheProp => $resetVal) {
    $rp = new ReflectionProperty(\App\Support\ConfigStore::class, $cacheProp);
    $rp->setAccessible(true);
    $rp->setValue(null, $resetVal);
}

$maint = new MaintenanceService($db);

// ═════════ 01-04: checkExpiredPickups → loan.expired audit (SYSTEM) ═════════
$maint->checkExpiredPickups();
$check($loanCol($loanP, 'stato') === 'scaduto', '01 expired pickup transitions to scaduto');
$evP = $auditEvent($bookP, 'loan.expired', $loanP);
$check($evP !== null, '02 the sweep records a loan.expired audit event for the expired pickup');
$check($evP !== null && $evP['utente_id'] === null, '03 loan.expired is attributed to SYSTEM (utente_id NULL, feed renders "Sistema")');
$check($evP !== null && ($evP['meta']['source'] ?? '') === 'sweep', '04 loan.expired carries source=sweep');

// ═════════ 05-08: updateOverdueLoans → loan.overdue audit per flipped loan ═════════
$flipped = $maint->updateOverdueLoans();
$check($flipped >= 1, '05 updateOverdueLoans flips at least the fixture loan');
$check($loanCol($loanO, 'stato') === 'in_ritardo', '06 fixture loan is now in_ritardo');
$evO = $auditEvent($bookO, 'loan.overdue', $loanO);
$check($evO !== null && $evO['utente_id'] === null, '07 the flip records a SYSTEM loan.overdue audit event');
$check($evO !== null && ($evO['meta']['source'] ?? '') === 'sweep', '08 loan.overdue carries source=sweep');
$maint->updateOverdueLoans();
$dupCount = 0;
$res = $db->query("SELECT dati_nuovi FROM log_modifiche WHERE tabella='libri' AND record_id={$bookO}");
while ($res && ($row = $res->fetch_assoc())) {
    $decoded = json_decode((string) $row['dati_nuovi'], true);
    if ((($decoded['_activity']['event'] ?? '')) === 'loan.overdue') {
        $dupCount++;
    }
}
$check($dupCount === 1, '09 a second sweep run does not duplicate the loan.overdue event (idempotent flip)');

// ═════════ 10-16: checkExpiredReservations → eternal pendenti expired + audit + email ═════════
$maint->checkExpiredReservations();
$check($loanCol($loanR, 'stato') === 'scaduto' && $loanCol($loanR, 'attivo') === '0',
    '10 a pendente with origine=richiesta and a fully-passed window is expired (scaduto, attivo=0)');
$check($loanCol($loanN, 'stato') === 'scaduto' && $loanCol($loanN, 'attivo') === '0',
    '11 a pendente with origine=ncip and a fully-passed window is expired too');
$check($loanCol($loanG, 'stato') === 'pendente' && $loanCol($loanG, 'attivo') === '0',
    '12 a pendente richiesta whose window is still open is NOT touched');
$evR = $auditEvent($bookR, 'reservation.expired', $loanR);
$check($evR !== null && $evR['utente_id'] === null, '13 the expiry records a SYSTEM reservation.expired audit event');
$check($evR !== null && ($evR['meta']['source'] ?? '') === 'sweep', '14 reservation.expired carries source=sweep');
$noteR = (string) $loanCol($loanR, 'note');
$check(str_contains($noteR, '[System]'), '15 the expired pendente carries the [System] audit note');
// Email: reservation_expired goes through the outbox claim (row persisted
// before the send, deleted only on success). SMTP is forced-unreachable for
// this suite (see the override above the sweep), so the claim row MUST be
// there — no environment-dependent disjunction.
$outboxRow = null;
try {
    $stmtOb = $db->prepare(
        "SELECT id FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'reservation_expired' LIMIT 1"
    );
    $stmtOb->bind_param('s', $emailR);
    $stmtOb->execute();
    $outboxRow = $stmtOb->get_result()->fetch_assoc();
    $stmtOb->close();
} catch (Throwable) {
    // outbox table missing would itself be a failure below
}
$check($outboxRow !== null,
    '16 the reservation_expired email is claimed in the outbox (SMTP forced down: row must persist)');

// ═════════ 17-20b: DataIntegrity repair → audit (source repair) ═════════
// Seminata QUI (dopo lo sweep) una prenotazione attiva già scaduta: deve
// essere il REPAIR a chiuderla, con evento reservation.expired — la causa è
// la scadenza, non un annullamento (review L3); reservation.cancelled resta
// riservato ai duplicati.
[$bookX] = $makeBook('X', 1);
[$userX] = $makeUser('x');
$expiredAt = (new DateTimeImmutable($today . ' 00:00:00'))->modify('-2 days')->format('Y-m-d H:i:s');
$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, queue_position, stato)
     VALUES (?, ?, ?, ?, 1, 'attiva')"
);
$stmt->bind_param('iiss', $bookX, $userX, $expiredAt, $expiredAt);
$stmt->execute();
$resX = (int) $db->insert_id;
$stmt->close();

$integrity = new DataIntegrity($db);
$fixResult = $integrity->fixDataInconsistencies();
$check(array_key_exists('errors', $fixResult) && ($fixResult['errors'] ?? []) === [],
    '17 fixDataInconsistencies completes without errors');
$stateQ2 = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$resQ2}")->fetch_assoc()['stato'];
$check($stateQ2 === 'annullata', '18 the duplicate reservation (same user, same book) is cancelled by the repair');
$evQ = $auditEvent($bookQ, 'reservation.cancelled', $resQ2);
$check($evQ !== null && $evQ['utente_id'] === null, '19 the repair records a SYSTEM reservation.cancelled audit event');
$check($evQ !== null && ($evQ['meta']['source'] ?? '') === 'repair', '20 reservation.cancelled carries source=repair');
$stateX = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$resX}")->fetch_assoc()['stato'];
$check($stateX === 'annullata', '20a the expired active reservation is closed by the repair');
$evX = $auditEvent($bookX, 'reservation.expired', $resX);
$check($evX !== null && ($evX['meta']['source'] ?? '') === 'repair' && $evX['utente_id'] === null,
    '20b ...with a SYSTEM reservation.expired event (cause = expiry, not cancellation)');
$check($auditEvent($bookX, 'reservation.cancelled', $resX) === null,
    '20c ...and no reservation.cancelled is recorded for the expiry');

// ═════════ 21-23: loan snapshot preserves sanzione + labels registry ═════════
$snapshot = ActivityLog::loadLoanSnapshot($db, $loanO);
$check(array_key_exists('sanzione', $snapshot), '21 loadLoanSnapshot selects the sanzione column');
$check(isset($snapshot['sanzione']) && abs((float) $snapshot['sanzione'] - 12.50) < 0.001,
    '22 the sanzione value survives in the snapshot (12.50)');
$labels = [
    'loan.overdue' => 'Prestito in ritardo',
    'loan.lost' => 'Copia dichiarata persa',
    'loan.damaged' => 'Copia dichiarata danneggiata',
    'reservation.promoted' => 'Prenotazione promossa',
    'reservation.expired' => 'Prenotazione scaduta',
];
$labelsOk = true;
foreach ($labels as $event => $expected) {
    if (ActivityLog::eventLabel($event) !== $expected) {
        $labelsOk = false;
    }
}
$check($labelsOk, '23 the event-label registry resolves every new sweep event type');

// ═════════ 24-25: runAll() è protetto da un lock per l'INTERA esecuzione ═════════
// Review #416: il claim timestamp di runIfNeeded() marca l'inizio ma non la
// durata — due sweep sovrapposti duplicherebbero le email. Il lock GET_LOCK
// (connection-scoped, auto-rilasciato) deve far saltare un runAll() mentre
// un'altra connessione lo tiene, e ripartire appena rilasciato.
$dbB = $socket !== '' && file_exists($socket)
    ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
    : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
$dbB->query("SELECT GET_LOCK(CONCAT('pinakes_maintenance_', DATABASE()), 0)");
$lockedRun = (new MaintenanceService($db))->runAll();
$check(($lockedRun['skipped'] ?? false) === true && ($lockedRun['reason'] ?? '') === 'in_progress',
    '24 runAll() skips with reason=in_progress while another connection holds the lock');
$dbB->query("SELECT RELEASE_LOCK(CONCAT('pinakes_maintenance_', DATABASE()))");
$dbB->close();
$freeRun = (new MaintenanceService($db))->runAll();
$check(($freeRun['skipped'] ?? false) !== true,
    '25 runAll() executes again as soon as the lock is released');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
