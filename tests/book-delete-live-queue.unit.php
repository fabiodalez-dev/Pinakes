<?php
declare(strict_types=1);

/**
 * Archiving a title that readers are still waiting for.
 *
 * The single delete (LibriController::delete) refuses a title with an approved
 * loan or a live queue entry (prenotazioni 'attiva'); the bulk delete
 * (LibriApiController::bulkDelete) looked at loans only. A title with a queue
 * could be archived from the list: its reservations stayed 'attiva' on a
 * soft-deleted title, vanished from every screen and later died unnoticed.
 *
 * Against the real database and the real controllers:
 *  - both paths ask the same predicate (DataIntegrity::booksBlockingDeletion);
 *  - the bulk refusal names the titles that block and archives nothing;
 *  - a bare request awaiting approval (prestiti 'pendente') does not block in
 *    either path: that is the documented rule (it commits no copy and stays
 *    hidden on an archived title), pinned here so the two paths keep agreeing;
 *  - the bulk path writes the same `book.deleted` activity event as the
 *    single one.
 *
 * Run: php tests/book-delete-live-queue.unit.php
 */

use App\Controllers\LibriApiController;
use App\Controllers\LibriController;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

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
    fwrite(STDERR, "FAIL: database unreachable — book delete suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_BDEL_{$run}";
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
$cleanup = static function () use ($db, &$bookIds, &$userIds): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

// ── Sandbox ──────────────────────────────────────────────────────────────────
$seq = 0;
$mkUser = static function (string $tipo = 'standard') use ($db, $prefix, $run, &$userIds, &$seq): int {
    $n = ++$seq;
    $card = 'BD' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'BookDelete', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
/** @return array{0:int,1:int,2:string} book id, copy id, title */
$mkBook = static function (string $label) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} {$label}";
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, 1, 1, NOW(), NOW())');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $code = "{$prefix}-{$bookId}";
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
    $stmt->bind_param('is', $bookId, $code);
    $stmt->execute();
    $stmt->close();
    return [$bookId, (int) $db->insert_id, $title];
};
$today = DateHelper::today();
$later = date('Y-m-d', strtotime($today . ' +14 days'));
$mkReservation = static function (int $book, int $user, string $state = 'attiva') use ($db, $today, $later): void {
    $stmt = $db->prepare('INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, queue_position, stato)
                          VALUES (?, ?, ?, ?, ?, 1, ?)');
    $deadline = $later . ' 23:59:59';
    $stmt->bind_param('iissss', $book, $user, $today, $later, $deadline, $state);
    $stmt->execute();
    $stmt->close();
};
$mkLoan = static function (int $book, ?int $copy, int $user, string $state, int $active) use ($db, $today, $later): void {
    $stmt = $db->prepare('INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iiisssi', $book, $copy, $user, $today, $later, $state, $active);
    $stmt->execute();
    $stmt->close();
};
$isDeleted = static function (int $book) use ($db): bool {
    // The test reads the archived row on purpose.
    $row = $db->query("SELECT deleted_at FROM libri WHERE id = {$book}")->fetch_row();
    return $row !== null && $row[0] !== null;
};
$deletedEvents = static function (int $book) use ($db): int {
    $n = 0;
    $res = $db->query("SELECT dati_nuovi FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$book} AND azione = 'cancellazione'");
    while ($row = $res->fetch_row()) {
        $data = json_decode((string) $row[0], true);
        if (is_array($data) && ($data['_activity']['event'] ?? null) === 'book.deleted') {
            $n++;
        }
    }
    return $n;
};

$admin = $mkUser('admin');
$reader = $mkUser();
$_SESSION = ['user' => ['id' => $admin, 'tipo_utente' => 'admin', 'stato' => 'attivo']];

/**
 * @param list<int> $ids
 * @return array{status:int, body:array<string,mixed>}
 */
$bulkDelete = static function (array $ids) use ($db): array {
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/libri/bulk-delete')->withParsedBody(['ids' => $ids]);
    $response = (new LibriApiController())->bulkDelete($request, new SlimResponse(), $db);
    $decoded = json_decode((string) $response->getBody(), true);
    return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
};
// The flash a controller left in the session, null when there is none.
$flash = static function (string $key): ?string {
    $value = $_SESSION[$key] ?? null;
    return is_string($value) ? $value : null;
};
/** @return array{location:string, error:?string} the redirect and the error flash, if any */
$singleDelete = static function (int $id) use ($db, $flash): array {
    unset($_SESSION['error_message']);
    $request = (new ServerRequestFactory())->createServerRequest('POST', "/admin/books/delete/{$id}");
    $location = (new LibriController())->delete($request, new SlimResponse(), $db, $id)->getHeaderLine('Location');
    return ['location' => $location, 'error' => $flash('error_message')];
};

// ═════════ 1-4: a live queue blocks the bulk delete ══════════════════════════
[$queued, , $queuedTitle] = $mkBook('queued');
[$clean] = $mkBook('clean');
$mkReservation($queued, $reader);

$r = $bulkDelete([$queued, $clean]);
$check($r['status'] >= 400 && $r['status'] < 500 && ($r['body']['success'] ?? null) === false,
    '1 bulk delete of a title with a live queue is refused', json_encode($r));
$check(!$isDeleted($queued), '2 the queued title is still in the catalogue');
$check(!$isDeleted($clean), '3 nothing of a refused batch is archived, not even the free title');
$check(str_contains((string) ($r['body']['error'] ?? ''), $queuedTitle)
    && !str_contains((string) ($r['body']['error'] ?? ''), "{$prefix} clean"),
    '4 the refusal names the title that blocks, and only that one', (string) ($r['body']['error'] ?? ''));
$blockingIds = array_map(static fn (array $b): int => (int) ($b['id'] ?? 0), is_array($r['body']['blocking'] ?? null) ? $r['body']['blocking'] : []);
$check($blockingIds === [$queued], '5 the answer lists the blocking titles for the client', json_encode($r['body']['blocking'] ?? null));
$check((string) $db->query("SELECT stato FROM prenotazioni WHERE libro_id = {$queued}")->fetch_row()[0] === 'attiva',
    '6 the reservation is untouched');

// ═════════ 7-8: an approved loan still blocks, by name ═══════════════════════
[$lent, $lentCopy, $lentTitle] = $mkBook('lent');
$mkLoan($lent, $lentCopy, $reader, 'in_corso', 1);
$r = $bulkDelete([$lent]);
$check($r['status'] >= 400 && $r['status'] < 500 && !$isDeleted($lent), '7 bulk delete of a title on loan is still refused', json_encode($r));
$check(str_contains((string) ($r['body']['error'] ?? ''), $lentTitle), '8 and the refusal names it', (string) ($r['body']['error'] ?? ''));

// ═════════ 9-10: one predicate for both paths ════════════════════════════════
$blocking = DataIntegrity::booksBlockingDeletion($db, [$queued, $clean, $lent]);
ksort($blocking);
$expected = [$queued => $queuedTitle, $lent => $lentTitle];
ksort($expected);
$check($blocking === $expected, '9 booksBlockingDeletion returns the blocking titles by id', json_encode($blocking));
$single = $singleDelete($queued);
$check(!$isDeleted($queued) && str_ends_with($single['location'], "/admin/books/{$queued}") && $single['error'] !== null,
    '10 the single delete refuses the same title', $single['location']);

// ═════════ 11-13: finished business does not block ═══════════════════════════
[$past] = $mkBook('past');
$mkReservation($past, $reader, 'annullata');
$mkReservation($past, $reader, 'completata');
[$returned, $returnedCopy] = $mkBook('returned');
$mkLoan($returned, $returnedCopy, $reader, 'restituito', 0);
$check(DataIntegrity::booksBlockingDeletion($db, [$past, $returned]) === [],
    '11 cancelled or completed reservations and closed loans do not block');
$r = $bulkDelete([$past, $returned, $clean]);
$check($r['status'] === 200 && ($r['body']['success'] ?? null) === true && ($r['body']['affected'] ?? null) === 3
    && $isDeleted($past) && $isDeleted($returned) && $isDeleted($clean),
    '12 a batch with nothing live is archived', json_encode($r));
$check($deletedEvents($past) === 1 && $deletedEvents($returned) === 1 && $deletedEvents($clean) === 1,
    '13 the bulk delete writes one book.deleted activity event per title',
    json_encode([$deletedEvents($past), $deletedEvents($returned), $deletedEvents($clean)]));

// ═════════ 14-15: the single path, for comparison ════════════════════════════
[$alone] = $mkBook('alone');
$single = $singleDelete($alone);
$check($isDeleted($alone) && str_ends_with($single['location'], '/admin/books') && $single['error'] === null,
    '14 the single delete archives a free title', $single['location']);
$check($deletedEvents($alone) === 1, '15 and writes the same book.deleted event');

// ═════════ 16: an already archived title is not logged again ═════════════════
$r = $bulkDelete([$clean]);
$check(($r['body']['affected'] ?? null) === 0 && $deletedEvents($clean) === 1,
    '16 deleting an archived title again changes nothing and logs nothing', json_encode($r));

// ═════════ 17-18: a bare pending request does not block (documented rule) ════
[$requestedBulk] = $mkBook('requested bulk');
[$requestedSingle] = $mkBook('requested single');
$mkLoan($requestedBulk, null, $reader, 'pendente', 0);
$mkLoan($requestedSingle, null, $reader, 'pendente', 0);
$r = $bulkDelete([$requestedBulk]);
$singleDelete($requestedSingle);
$check($isDeleted($requestedBulk) && $isDeleted($requestedSingle),
    '17 a request still awaiting approval blocks neither path (it commits no copy)', json_encode($r));
$check(DataIntegrity::booksBlockingDeletion($db, [$requestedBulk, $requestedSingle]) === [],
    '18 and the shared predicate says so');

// ═════════ 19: input validation is unchanged ═════════════════════════════════
$r = $bulkDelete([]);
$check($r['status'] === 400 && ($r['body']['success'] ?? null) === false, '19 an empty selection is still a 400', json_encode($r));

// ═════════ 20-23: a pending loan that holds a copy blocks both paths ═════════
// A promoted reservation: the queue entry is 'completata', the loan is
// 'pendente' with attivo = 0 and the copy set aside for its reader.
[$promotedBulk, $promotedBulkCopy, $promotedBulkTitle] = $mkBook('promoted bulk');
[$promotedSingle, $promotedSingleCopy] = $mkBook('promoted single');
[$bystander] = $mkBook('bystander');
$mkReservation($promotedBulk, $reader, 'completata');
$mkLoan($promotedBulk, $promotedBulkCopy, $reader, 'pendente', 0);
$mkReservation($promotedSingle, $reader, 'completata');
$mkLoan($promotedSingle, $promotedSingleCopy, $reader, 'pendente', 0);
$r = $bulkDelete([$promotedBulk, $bystander]);
$check($r['status'] === 400 && !$isDeleted($promotedBulk) && !$isDeleted($bystander)
    && str_contains((string) ($r['body']['error'] ?? ''), $promotedBulkTitle),
    '20 a promoted reservation awaiting its pickup confirmation blocks the bulk delete', json_encode($r));
$single = $singleDelete($promotedSingle);
$check(!$isDeleted($promotedSingle) && $single['error'] !== null && str_ends_with($single['location'], '/admin/books/' . $promotedSingle),
    '21 and the single delete', json_encode($single));
$check(array_keys(DataIntegrity::booksBlockingDeletion($db, [$promotedBulk, $promotedSingle, $bystander])) === [$promotedBulk, $promotedSingle],
    '22 the shared predicate names the two titles, not the free one');
$db->query("UPDATE prestiti SET stato = 'annullato' WHERE libro_id IN ({$promotedBulk}, {$promotedSingle})");
$r = $bulkDelete([$promotedBulk]);
$singleDelete($promotedSingle);
$check($isDeleted($promotedBulk) && $isDeleted($promotedSingle), '23 once the pending loan is closed both titles can be archived', json_encode($r));

// ═════════ 24-25: an archived title in the selection does not hold the rest ══
// A title archived while it still had a queue entry (before the guard existed)
// may come back in a kept selection: only the live titles are asked about.
[$archivedQueued] = $mkBook('archived queued');
[$free] = $mkBook('free');
$mkReservation($archivedQueued, $reader);
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$archivedQueued}");
$r = $bulkDelete([$archivedQueued, $free]);
$check(($r['body']['success'] ?? null) === true && ($r['body']['affected'] ?? null) === 1 && $isDeleted($free),
    '24 the free title is archived although an archived one in the selection still has a queue entry', json_encode($r));

// ═════════ 26: the single delete of an archived title is a no-op ═════════════
$before = (string) $db->query("SELECT deleted_at FROM libri WHERE id = {$free}")->fetch_row()[0];
$db->query("UPDATE libri SET deleted_at = '2020-01-01 00:00:00' WHERE id = {$free}");
$single = $singleDelete($free);
$check((string) $db->query("SELECT deleted_at FROM libri WHERE id = {$free}")->fetch_row()[0] === '2020-01-01 00:00:00'
    && $deletedEvents($free) === 1 && $single['error'] === null && str_ends_with($single['location'], '/admin/books'),
    '25 deleting an archived title again from its page neither moves its archive date nor logs a second event', $before . ' ' . json_encode($single));

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}  Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
