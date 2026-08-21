<?php
declare(strict_types=1);

/**
 * Regression test for the review-eligibility fix: a borrower whose loan is
 * 'in_ritardo' (overdue) still physically holds the book and MUST be able to
 * review it. Before the fix, canUserReview() gated on IN ('restituito','in_corso')
 * only, wrongly blocking overdue borrowers (real case: bibliodoc loan #6).
 *
 * A. Behavioural — the REAL RecensioniRepository::canUserReview() against seeded
 *    prestiti rows in every relevant state.
 * B. Source guard — the two mirrors that shared the same rule
 *    (mobile-api ReviewsController::hasBorrowed, ncip-server findNcipLoan) must
 *    keep 'in_ritardo' so the audit fix cannot silently regress.
 *
 * Run:  php tests/review-eligibility-in-ritardo.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Repositories\RecensioniRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// ── DB connection (same source as the other .unit.php tests) ────────────────
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZREV_' . $run;
$emailDomain = '@rev' . $run . '.test.local';

$cleanup = static function () use ($db, $titlePrefix, $emailDomain): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = '%' . $emailDomain;
    foreach ([
        'DELETE r FROM recensioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
        'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $titleLike);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare('DELETE FROM utenti WHERE email LIKE ?');
    $stmt->bind_param('s', $emailLike);
    $stmt->execute();
    $stmt->close();
};

$cleanup();
set_exception_handler(static function (\Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (\Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

// ── Fixtures ────────────────────────────────────────────────────────────────
$bookSeq = 0;
$makeBook = static function () use ($db, $titlePrefix, &$bookSeq): int {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$userSeq = 0;
$makeUser = static function () use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZREV' . strtoupper($run) . $userSeq;
    $email = $run . '-' . $userSeq . $emailDomain;
    $pwd = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente) VALUES (?, 'Rev', 'Test', ?, ?, 'standard')");
    $stmt->bind_param('sss', $card, $email, $pwd);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$makeLoan = static function (int $bookId, int $userId, string $stato) use ($db): void {
    $attivo = in_array($stato, ['restituito', 'annullato', 'scaduto', 'perso', 'danneggiato'], true) ? 0 : 1;
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, CURDATE(), CURDATE(), ?, 'diretto', ?)");
    $stmt->bind_param('iisi', $bookId, $userId, $stato, $attivo);
    $stmt->execute();
    $stmt->close();
};

$addReview = static function (int $bookId, int $userId) use ($db): void {
    $stmt = $db->prepare("INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione) VALUES (?, ?, 5, 'x', 'y')");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $stmt->close();
};

$repo = new RecensioniRepository($db);

// ── A. Behavioural: canUserReview() per loan state ──────────────────────────
echo "A. RecensioniRepository::canUserReview() by loan state\n";

// The regression: overdue borrower, never reviewed → CAN review.
$b = $makeBook(); $u = $makeUser(); $makeLoan($b, $u, 'in_ritardo');
$check($repo->canUserReview($u, $b) === true, "01 in_ritardo (overdue, holds the book) → CAN review [the fix]");

// Unchanged eligible states.
$b = $makeBook(); $u = $makeUser(); $makeLoan($b, $u, 'restituito');
$check($repo->canUserReview($u, $b) === true, "02 restituito → CAN review");
$b = $makeBook(); $u = $makeUser(); $makeLoan($b, $u, 'in_corso');
$check($repo->canUserReview($u, $b) === true, "03 in_corso → CAN review");

// States that do NOT mean "has/had the book to read" → cannot review.
foreach (['pendente' => '04', 'da_ritirare' => '05', 'prenotato' => '06', 'annullato' => '07'] as $stato => $n) {
    $b = $makeBook(); $u = $makeUser(); $makeLoan($b, $u, $stato);
    $check($repo->canUserReview($u, $b) === false, "{$n} {$stato} → cannot review");
}

// Already reviewed always blocks, even when overdue.
$b = $makeBook(); $u = $makeUser(); $makeLoan($b, $u, 'in_ritardo'); $addReview($b, $u);
$check($repo->canUserReview($u, $b) === false, "08 in_ritardo but already reviewed → blocked");

// No loan at all → cannot review.
$b = $makeBook(); $u = $makeUser();
$check($repo->canUserReview($u, $b) === false, "09 no loan → cannot review");

$cleanup();

// ── B. Source guard: the two mirrors keep 'in_ritardo' ──────────────────────
echo "B. Mirror source guard (mobile-api + ncip-server)\n";

$reviewsCtrl = (string) file_get_contents($root . '/storage/plugins/mobile-api/src/Controllers/ReviewsController.php');
// Isolate the hasBorrowed() query so we don't just match a comment.
$hasBorrowedOk = (bool) preg_match(
    "/stato\\s+IN\\s*\\(\\s*'restituito'\\s*,\\s*'in_corso'\\s*,\\s*'in_ritardo'\\s*\\)/",
    $reviewsCtrl
);
$check($hasBorrowedOk, "10 mobile-api ReviewsController::hasBorrowed includes 'in_ritardo'");

$ncip = (string) file_get_contents($root . '/storage/plugins/ncip-server/NcipServerPlugin.php');
// The NCIP active-loan lookup (findActiveLoan, used by CheckInItem/RenewItem)
// is the mirror that must keep 'in_ritardo': an overdue NCIP loan still holds
// the book. `[^)]*?` tolerates the `AND attivo = 1` that sits between the
// origine filter and the stato IN(...) clause.
// (cancelPendingNcipRequest is a DIFFERENT method — it cancels a still-
// 'pendente' request, so it correctly omits 'in_ritardo'.)
$ncipOk = (bool) preg_match(
    "/origine\\s*=\\s*'ncip'[^)]*?stato\\s+IN\\s*\\([^)]*'in_ritardo'[^)]*\\)/",
    $ncip
);
$check($ncipOk, "11 ncip-server active-loan lookup (findActiveLoan) includes 'in_ritardo'");

$db->close();
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
