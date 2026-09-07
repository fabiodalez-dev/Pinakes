<?php
declare(strict_types=1);

/**
 * Visibilità esiti prestito 0.7.81 (report review circolazione):
 *
 *  A. processReturn con esito 'perso' registra l'evento feed 'loan.lost'
 *     (e 'danneggiato' → 'loan.damaged') con la sanzione nello snapshot
 *     dati_nuovi; un rientro normale resta 'loan.returned'.
 *  B. L'export CSV dei prestiti contiene la colonna 'Sanzione' con il valore.
 *  C. bulkExtend accoda una conferma loan_renewed POST-commit per OGNI
 *     prestito esteso (outbox row check con trasporto SMTP forzato giù,
 *     stesso pattern di mail-coherence-0781).
 *  D. renew() su un prestito attivo il cui libro è stato archiviato
 *     (soft-delete) riesce — prima rispondeva book_not_found mentre
 *     update()/bulkExtend estendevano lo stesso prestito — e accoda la
 *     conferma email (fallback titolo).
 *  E. processReturn con la copia parcheggiata in uno stato curato fuori
 *     circolazione (drift: operatore l'ha messa in manutenzione a prestito
 *     aperto) NON la forza a 'disponibile'; gli esiti espliciti vincono.
 *
 * Run: php tests/loan-outcome-visibility-0781.unit.php
 * Fail duro senza DB: la parte comportamentale è obbligatoria.
 * Dati marcati: titoli ZZ_LOANOUT_%, inventario ZZLO-%, email zz-loanout*@test.local.
 */

use App\Controllers\PrestitiController;
use App\Support\DateHelper;
use App\Support\Mailer;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/* ── DB (fail duro) ─────────────────────────────────────────────────────── */
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the behavioral checks are mandatory: {$e->getMessage()}\n");
    exit(1);
}

/* ── Markers + cleanup ──────────────────────────────────────────────────── */
$run = bin2hex(random_bytes(4));
$TITLE_LIKE = 'ZZ_LOANOUT_%';
$EMAIL_LIKE = 'zz-loanout%@test.local';
$INV_LIKE = 'ZZLO-%';

$cleanup = static function () use ($db, $TITLE_LIKE, $EMAIL_LIKE, $INV_LIKE): void {
    try {
        $db->query("DELETE lm FROM log_modifiche lm JOIN libri l ON lm.record_id = l.id AND lm.tabella = 'libri' WHERE l.titolo LIKE '{$TITLE_LIKE}'");
        $db->query("DELETE p FROM prestiti p JOIN libri l ON p.libro_id = l.id WHERE l.titolo LIKE '{$TITLE_LIKE}'");
        $db->query("DELETE p FROM prestiti p JOIN utenti u ON p.utente_id = u.id WHERE u.email LIKE '{$EMAIL_LIKE}'");
        $db->query("DELETE c FROM copie c JOIN libri l ON c.libro_id = l.id WHERE l.titolo LIKE '{$TITLE_LIKE}'");
        $db->query("DELETE FROM copie WHERE numero_inventario LIKE '{$INV_LIKE}'");
        $db->query("DELETE FROM libri WHERE titolo LIKE '{$TITLE_LIKE}'");
        $db->query("DELETE FROM utenti WHERE email LIKE '{$EMAIL_LIKE}'");
        $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE '{$EMAIL_LIKE}'");
    } catch (Throwable) {
    }
};
$cleanup(); // residui di run precedenti
register_shutdown_function($cleanup);
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

/* ── Fixture helpers ────────────────────────────────────────────────────── */
$mkBook = static function (string $suffix) use ($db, $run): int {
    $title = "ZZ_LOANOUT_{$run}_{$suffix}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 0, 'prestato')");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
$mkCopy = static function (int $bookId, string $suffix, string $stato = 'prestato') use ($db, $run): int {
    $inv = "ZZLO-{$run}-{$suffix}";
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $bookId, $inv, $stato);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
$password = password_hash('LoanOutcome!1', PASSWORD_DEFAULT);
$mkUser = static function (string $suffix, string $tipo = 'standard') use ($db, $run, $password): array {
    $email = "zz-loanout{$suffix}-{$run}@test.local";
    $card = 'ZZLO' . strtoupper($suffix) . strtoupper($run);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, ?, 'Outcome', ?, ?, ?, 'attivo', 1)"
    );
    $nome = 'Zz' . ucfirst($suffix);
    $stmt->bind_param('sssss', $card, $nome, $email, $password, $tipo);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return [$id, $email];
};
$today = DateHelper::today();
$plusDays = static fn (string $ymd, int $days): string => (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$mkLoan = static function (int $bookId, int $copyId, int $userId, string $from, string $to, string $stato = 'in_corso') use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1)"
    );
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $from, $to, $stato);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
$latestLoanEvent = static function (int $bookId) use ($db): array {
    $stmt = $db->prepare("SELECT dati_nuovi FROM log_modifiche WHERE tabella = 'libri' AND record_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $after = json_decode((string) ($row['dati_nuovi'] ?? ''), true);
    return is_array($after) ? $after : [];
};

// Sessione staff reale: guardStaffAccess controlla tipo_utente, ActivityLog
// attribuisce l'operatore da $_SESSION['user']['id'] (FK su utenti).
[$adminId] = $mkUser('admin', 'admin');
$_SESSION['user'] = ['id' => $adminId, 'tipo_utente' => 'admin'];

// Trasporto SMTP forzato irraggiungibile PRIMA di ogni invio: nessuna email
// reale, ma sendWithRetry persiste comunque la riga outbox (il segnale che
// il sender è stato davvero chiamato) — pattern di mail-coherence-0781.
$smtpProbe = new ReflectionProperty(Mailer::class, 'smtpReachable');
$smtpProbe->setValue(null, false);

$controller = new PrestitiController();
$factory = new ServerRequestFactory();
$post = static function (string $path, array $body) use ($factory) {
    return $factory->createServerRequest('POST', $path)->withParsedBody($body);
};

/* ═══ A. Evento feed dedicato per perso/danneggiato + sanzione in snapshot ═ */
echo "A. dedicated feed events for lost/damaged outcomes\n";

[$userAId] = $mkUser('a');
$bookLost = $mkBook('LOST');
$copyLost = $mkCopy($bookLost, 'LOST');
$loanLost = $mkLoan($bookLost, $copyLost, $userAId, $plusDays($today, -7), $plusDays($today, 7));

$res = $controller->processReturn($post('/x', ['stato' => 'perso', 'sanzione' => '25,00']), new SlimResponse(), $db, $loanLost);
$check($res->getStatusCode() === 302 && !str_contains($res->getHeaderLine('Location'), 'error='), 'lost outcome: processReturn succeeds');
$row = $db->query("SELECT stato, attivo, sanzione FROM prestiti WHERE id = {$loanLost}")->fetch_assoc();
$check($row['stato'] === 'perso' && (int) $row['attivo'] === 0 && (string) $row['sanzione'] === '25.00', 'lost outcome: loan closed as perso with sanzione 25.00');
$check((string) $db->query("SELECT stato FROM copie WHERE id = {$copyLost}")->fetch_row()[0] === 'perso', 'lost outcome: the explicit outcome still wins on the copy state');
$after = $latestLoanEvent($bookLost);
$check(($after['_activity']['event'] ?? null) === 'loan.lost', "lost outcome: feed event is 'loan.lost' (not loan.returned)");
$check((string) ($after['sanzione'] ?? '') === '25.00', 'lost outcome: audit snapshot carries the assessed sanzione');

$bookDmg = $mkBook('DMG');
$copyDmg = $mkCopy($bookDmg, 'DMG');
$loanDmg = $mkLoan($bookDmg, $copyDmg, $userAId, $plusDays($today, -7), $plusDays($today, 7));
$res = $controller->processReturn($post('/x', ['stato' => 'danneggiato', 'sanzione' => '12.50']), new SlimResponse(), $db, $loanDmg);
$check($res->getStatusCode() === 302 && !str_contains($res->getHeaderLine('Location'), 'error='), 'damaged outcome: processReturn succeeds');
$after = $latestLoanEvent($bookDmg);
$check(($after['_activity']['event'] ?? null) === 'loan.damaged', "damaged outcome: feed event is 'loan.damaged'");
$check((string) ($after['sanzione'] ?? '') === '12.50', 'damaged outcome: audit snapshot carries the assessed sanzione');

$bookRet = $mkBook('RET');
$copyRet = $mkCopy($bookRet, 'RET');
$loanRet = $mkLoan($bookRet, $copyRet, $userAId, $plusDays($today, -7), $plusDays($today, 7));
$res = $controller->processReturn($post('/x', ['stato' => 'restituito']), new SlimResponse(), $db, $loanRet);
$check($res->getStatusCode() === 302 && !str_contains($res->getHeaderLine('Location'), 'error='), 'plain return: processReturn succeeds');
$after = $latestLoanEvent($bookRet);
$check(($after['_activity']['event'] ?? null) === 'loan.returned', "plain return: feed event stays 'loan.returned' (regression)");
$check((string) $db->query("SELECT stato FROM copie WHERE id = {$copyRet}")->fetch_row()[0] === 'disponibile', 'plain return: healthy copy goes back to disponibile');

/* ═══ B. Export CSV con colonna Sanzione ═══════════════════════════════════ */
echo "B. CSV export carries the Sanzione column\n";

$csvReq = $factory->createServerRequest('GET', '/admin/loans/export')->withQueryParams(['stati' => 'perso']);
$csvRes = $controller->exportCsv($csvReq, new SlimResponse(), $db);
$csv = (string) $csvRes->getBody();
$lines = array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => trim($l) !== ''));
$header = $lines[0] ?? '';
$check(str_contains($header, __('Sanzione')), "CSV header includes the '" . __('Sanzione') . "' column");
$lostLine = null;
foreach ($lines as $line) {
    if (str_contains($line, "ZZ_LOANOUT_{$run}_LOST")) {
        $lostLine = $line;
        break;
    }
}
$check($lostLine !== null, 'CSV export includes the lost loan row');
$check($lostLine !== null && str_contains($lostLine, '25.00'), 'CSV row carries the sanzione amount (25.00)');
// La colonna è l'ULTIMA: il numero di campi della riga deve pareggiare l'header.
$check($lostLine !== null && substr_count($header, ',') === substr_count(preg_replace('/"[^"]*"/', '""', $lostLine) ?? $lostLine, ','), 'CSV row field count matches the header');

/* ═══ C. bulkExtend accoda loan_renewed per ogni prestito esteso ═══════════ */
echo "C. bulkExtend queues loan_renewed for every extended loan\n";

[$userB1Id, $emailB1] = $mkUser('b1');
[$userB2Id, $emailB2] = $mkUser('b2');
$bookB1 = $mkBook('BULK1');
$copyB1 = $mkCopy($bookB1, 'BULK1');
$loanB1 = $mkLoan($bookB1, $copyB1, $userB1Id, $plusDays($today, -3), $plusDays($today, 3));
$bookB2 = $mkBook('BULK2');
$copyB2 = $mkCopy($bookB2, 'BULK2');
$loanB2 = $mkLoan($bookB2, $copyB2, $userB2Id, $plusDays($today, -3), $plusDays($today, 3));

$res = $controller->bulkExtend($post('/x', ['ids' => [$loanB1, $loanB2], 'days' => 5]), new SlimResponse(), $db);
$check($res->getStatusCode() === 302 && str_contains($res->getHeaderLine('Location'), 'bulk_extended=2'), 'bulkExtend extends both loans');
$newDue = (string) $db->query("SELECT data_scadenza FROM prestiti WHERE id = {$loanB1}")->fetch_row()[0];
$check($newDue === $plusDays($today, 8), 'due date pushed forward by 5 days from the current due date');
foreach ([[$loanB1, $emailB1], [$loanB2, $emailB2]] as [$lid, $mail]) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'loan_renewed'");
    $stmt->bind_param('s', $mail);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    $check($count === 1, "loan {$lid}: exactly one loan_renewed confirmation queued post-commit");
}

/* ═══ D. renew() su libro archiviato (soft-delete) riesce ══════════════════ */
echo "D. renew succeeds on an active loan whose book is archived\n";

[$userDId, $emailD] = $mkUser('d');
$bookD = $mkBook('RENEW');
$copyD = $mkCopy($bookD, 'RENEW');
$loanD = $mkLoan($bookD, $copyD, $userDId, $plusDays($today, -2), $plusDays($today, 5));
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookD}");

$res = $controller->renew($post('/x', []), new SlimResponse(), $db, $loanD);
$location = $res->getHeaderLine('Location');
$check($res->getStatusCode() === 302 && str_contains($location, 'renewed=1'), "renew on an archived title succeeds (was: book_not_found) [{$location}]");
$row = $db->query("SELECT data_scadenza, renewals FROM prestiti WHERE id = {$loanD}")->fetch_assoc();
$check((string) $row['data_scadenza'] > $plusDays($today, 5) && (int) $row['renewals'] === 1, 'due date extended and renewal counted');
$stmt = $db->prepare("SELECT variables_json FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'loan_renewed' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $emailD);
$stmt->execute();
$outRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$vars = json_decode((string) ($outRow['variables_json'] ?? ''), true);
$check(is_array($vars) && ($vars['libro_titolo'] ?? '') === "ZZ_LOANOUT_{$run}_RENEW",
    'renewal confirmation queued with the archived title (email title fallback path)');

/* ═══ E. processReturn non forza a disponibile una copia in stato curato ═══ */
echo "E. curated out-of-circulation copy state survives a plain return\n";

[$userEId] = $mkUser('e');
$bookE = $mkBook('DRIFT');
$copyE = $mkCopy($bookE, 'DRIFT');
$loanE = $mkLoan($bookE, $copyE, $userEId, $plusDays($today, -7), $plusDays($today, 7));
// Drift: a prestito aperto l'operatore parcheggia la copia in manutenzione.
$db->query("UPDATE copie SET stato = 'manutenzione' WHERE id = {$copyE}");

$res = $controller->processReturn($post('/x', ['stato' => 'restituito']), new SlimResponse(), $db, $loanE);
$check($res->getStatusCode() === 302 && !str_contains($res->getHeaderLine('Location'), 'error='), 'drift scenario: processReturn still succeeds');
$row = $db->query("SELECT stato, attivo FROM prestiti WHERE id = {$loanE}")->fetch_assoc();
$check($row['stato'] === 'restituito' && (int) $row['attivo'] === 0, 'drift scenario: loan closed as restituito');
$check((string) $db->query("SELECT stato FROM copie WHERE id = {$copyE}")->fetch_row()[0] === 'manutenzione',
    'drift scenario: curated copy state is NOT forced back to disponibile');
$check((int) $db->query("SELECT copie_disponibili FROM libri WHERE id = {$bookE}")->fetch_row()[0] === 0,
    'drift scenario: availability recalc keeps the curated copy out of circulation');

/* ── Fine ───────────────────────────────────────────────────────────────── */
unset($_SESSION['user']);
$cleanup();
$db->close();

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
