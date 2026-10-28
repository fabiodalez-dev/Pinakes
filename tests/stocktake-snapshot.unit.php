<?php
declare(strict_types=1);

/**
 * Inventory stocktake (plan §8) against the real database: the snapshot taken
 * at opening is independent of later catalogue edits, every scan lands in the
 * right bucket, the buckets add up, and closing with "mark as lost" changes
 * only the missing copies (never one lent, waiting for pickup or damaged),
 * recalculates availability and writes the audit log.
 *
 * Touches only rows it creates (titles/codes prefixed ZZST) and removes them.
 * Run: php tests/stocktake-snapshot.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

use App\Services\StocktakeService;

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

if ($db->query("SHOW TABLES LIKE 'inventario_sessioni'")->num_rows === 0) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL inventario_sessioni missing (schema not installed)\n");
        exit(1);
    }
    echo "SKIP: inventario_sessioni table missing — apply migrate_0.9.0.sql first\n";
    exit(0);
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$one = static function (string $sql) use ($db): ?string {
    $row = $db->query($sql)->fetch_row();
    return $row === null || $row[0] === null ? null : (string) $row[0];
};

$run = strtoupper(bin2hex(random_bytes(3)));
$p = 'ZZST' . $run;
$shelves = [];
$books = [];
$sessionIds = [];
$triggers = [];
$borrowerId = 0;
try {
    foreach (['X', 'Y'] as $tag) {
        $db->query("INSERT INTO scaffali (codice, nome, lettera) VALUES ('{$p}{$tag}', 'Stocktake {$tag}', '{$tag}')");
        $shelves[$tag] = (int) $db->insert_id;
    }
    $addBook = static function (string $title, int $shelf) use ($db, $p, &$books): int {
        $db->query("INSERT INTO libri (titolo, scaffale_id, collocazione, copie_totali, copie_disponibili) VALUES ('{$p} {$title}', {$shelf}, '{$p}.{$title}', 1, 1)");
        $books[$title] = (int) $db->insert_id;
        return $books[$title];
    };
    $addCopy = static function (int $book, string $code, string $state = 'disponibile') use ($db, $p): int {
        $db->query("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES ({$book}, '{$p}-{$code}', '{$state}')");
        return (int) $db->insert_id;
    };
    $b1 = $addBook('B1', $shelves['X']);
    $b2 = $addBook('B2', $shelves['X']);
    $b3 = $addBook('B3', $shelves['Y']);
    $c1 = $addCopy($b1, 'C1');
    $c2 = $addCopy($b1, 'C2');
    $c6 = $addCopy($b1, 'C6');
    $c3 = $addCopy($b2, 'C3', 'prestato');
    $c4 = $addCopy($b3, 'C4');
    // C7 is on the pickup shelf for a reader: 'prenotato' with a 'da_ritirare' loan. C8 gets damaged during the stocktake.
    $c7 = $addCopy($b2, 'C7', 'prenotato');
    $c8 = $addCopy($b1, 'C8');
    $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, privacy_accettata, locale) VALUES ('{$p}', 'Reader', '{$p}', '{$p}@stocktake.test.local', 'x', 'standard', 'attivo', 1, 1, 'it_IT')");
    $borrowerId = (int) $db->insert_id;
    $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, pickup_deadline, stato, origine, attivo) VALUES ({$b2}, {$c7}, {$borrowerId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'da_ritirare', 'prenotazione', 1)");
    $holdId = (int) $db->insert_id;
    $db->query("UPDATE libri SET copie_totali = 4, copie_disponibili = 4 WHERE id = {$b1}");
    $db->query("UPDATE libri SET copie_totali = 2, copie_disponibili = 0 WHERE id = {$b2}");

    $service = new StocktakeService($db);
    $sessionId = $service->open('ZZST ' . $run, null, ['scaffale_id' => (string) $shelves['X']], null);
    $sessionIds[] = $sessionId;
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$sessionId}") === 6, 'the snapshot holds the six copies on shelf X');
    $check($one("SELECT GROUP_CONCAT(copia_id ORDER BY copia_id) FROM inventario_attese WHERE sessione_id = {$sessionId}") === implode(',', [$c1, $c2, $c6, $c3, $c7, $c8]), 'exactly the shelf-X copies are expected');
    $check($one("SELECT stato_all_apertura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c3}") === 'prestato' && $one("SELECT stato_all_apertura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c7}") === 'prenotato', 'the snapshot records each copy state at opening');

    // Catalogue edits after opening must not change what the session expects.
    $db->query("UPDATE libri SET scaffale_id = {$shelves['Y']} WHERE id = {$b1}");
    $c5 = $addCopy($b2, 'C5');
    $db->query("DELETE FROM copie WHERE id = {$c2}");
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$b3}");
    $db->query("UPDATE copie SET stato = 'danneggiato' WHERE id = {$c8}");
    $check((int) $one("SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = {$sessionId}") === 6, 'moving a book, adding and deleting copies leaves the snapshot intact');
    $check((int) $one("SELECT scaffale_atteso FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c1}") === $shelves['X'], 'the expected shelf is the one at opening, not the current one');

    $x = $shelves['X'];
    $check($service->scan($sessionId, "{$p}-C1\r", null, $x)['esito'] === 'trovata', 'C1 scanned on shelf X is found although its book was moved since');
    $check($service->scan($sessionId, "{$p}-C5", null, $x)['esito'] === 'fuori_ambito', 'a copy added after opening is out of scope');
    $check($service->scan($sessionId, "{$p}-C4", null, $x)['esito'] === 'sconosciuta', 'a copy of a book removed from the catalogue is unknown');
    $check($service->scan($sessionId, "{$p}-NOPE", null, $x)['esito'] === 'sconosciuta', 'an unknown code is unknown');
    $check($service->scan($sessionId, strtolower("{$p}-c1"), null, $x)['esito'] === 'duplicata', 'scanning C1 again (any case) is a duplicate');
    $check($service->scan($sessionId, "{$p}-C3", null, $x)['esito'] === 'in_prestito', 'a copy on loan is reported as on loan');
    $check((int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$sessionId} AND copia_id = {$c1}") === 1, 'the UNIQUE (sessione_id, copia_id) row stays single: duplicates are stored without a copy id');

    $summary = $service->summary($sessionId);
    $check($summary['attese'] === 6 && $summary['trovate'] === 1 && $summary['fuori_posto'] === 0 && $summary['in_prestito'] === 2 && $summary['mancanti'] === 3, 'found 1, on loan 2 (C3 lent, C7 waiting for pickup), missing 3 (C2 deleted, C6 unscanned, C8 damaged)');
    $check($summary['trovate'] + $summary['fuori_posto'] + $summary['in_prestito'] + $summary['mancanti'] === $summary['attese'], 'the four buckets add up to the expected copies');
    $check($summary['sconosciute'] === 2 && $summary['fuori_ambito'] === 1 && $summary['duplicate'] === 1 && $summary['scansioni'] === 6, 'side counters: 2 unknown, 1 out of scope, 1 duplicate, 6 scans');

    $categories = array_count_values(array_column($service->reportRows($sessionId), 'categoria'));
    $check(($categories['mancante'] ?? 0) === 3 && ($categories['trovata'] ?? 0) === 1 && ($categories['in_prestito'] ?? 0) === 2, 'the report rows list the same buckets');

    // Probe: record the session state at the instant a copy turns 'perso'.
    // close() must claim the session before it marks anything, so scans that
    // arrive while copies are being marked are refused (review #504).
    $probe = 'zzst_probe_' . strtolower($run);
    $db->query("SET @zzst_session = {$sessionId}, @zzst_state_at_lost = NULL");
    $db->query("CREATE TRIGGER {$probe} BEFORE UPDATE ON copie FOR EACH ROW BEGIN IF NEW.stato = 'perso' AND OLD.stato <> 'perso' AND @zzst_state_at_lost IS NULL THEN SET @zzst_state_at_lost = (SELECT stato FROM inventario_sessioni WHERE id = @zzst_session); END IF; END");
    $triggers[] = $probe;
    $result = $service->close($sessionId, true, null);
    $db->query("DROP TRIGGER IF EXISTS {$probe}");
    $check($one('SELECT @zzst_state_at_lost') === 'chiusa', 'the session is already closed when the first copy is marked lost');
    $check($result === ['marked' => 1, 'failed' => 0], 'closing with "mark lost" marks only C6 (C2 no longer exists, C3 is lent, C7 waits for pickup, C8 is damaged)');
    $check($one("SELECT stato FROM copie WHERE id = {$c6}") === 'perso', 'C6 is now lost');
    $check($one("SELECT stato FROM copie WHERE id = {$c1}") === 'disponibile' && $one("SELECT stato FROM copie WHERE id = {$c3}") === 'prestato', 'found and lent copies are untouched');
    $check($one("SELECT stato FROM copie WHERE id = {$c7}") === 'prenotato' && $one("SELECT CONCAT(stato, ':', attivo, ':', copia_id) FROM prestiti WHERE id = {$holdId}") === "da_ritirare:1:{$c7}", 'the copy on the pickup shelf keeps its state and its hold is not reassigned');
    $check($one("SELECT stato FROM copie WHERE id = {$c8}") === 'danneggiato', 'a damaged copy is not overwritten to lost');
    $check((int) $one("SELECT copie_disponibili FROM libri WHERE id = {$b1}") === 1, 'book availability is recalculated (1 of the 3 remaining copies)');
    $check((int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$b1} AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'copy.inventory_lost'") === 1, 'an ActivityLog entry records the copy declared lost');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$sessionId}") === 'chiusa', 'the session is closed');
    try {
        $service->scan($sessionId, "{$p}-C6", null, null);
        $check(false, 'a closed session refuses scans');
    } catch (DomainException) {
        $check(true, 'a closed session refuses scans');
    }

    // Upload path + cancel: nothing is modified.
    $second = $service->open('ZZST2 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $second;
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$second}") === 3, 'a collocation range opens on B2 alone (C3, C5, C7)');
    $import = $service->importCodes($second, StocktakeService::parseCodeList("codice\n{$p}-C5\n{$p}-C5\n{$p}-C1\n{$p}-C7\n"), null, null);
    $check($import['total'] === 4 && $import['outcomes']['trovata'] === 1 && $import['outcomes']['duplicata'] === 1 && $import['outcomes']['fuori_ambito'] === 1 && $import['outcomes']['in_prestito'] === 1, 'an uploaded list is classified line by line; a copy waiting for pickup is reported with the lent ones');
    $service->cancel($second, null);
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$second}") === 'annullata' && $one("SELECT stato FROM copie WHERE id = {$c5}") === 'disponibile', 'cancelling leaves every copy as it was');

    // A close or cancel that loses the race against another transition does nothing.
    $refused = static function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (DomainException) {
            return true;
        }
    };
    $check($refused(static fn () => $service->close($second, true, null)), 'closing a cancelled session is refused');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$second}") === 'annullata' && $one("SELECT stato FROM copie WHERE id = {$c5}") === 'disponibile', 'the refused close marks nothing and keeps the cancelled state');
    $check($refused(static fn () => $service->cancel($sessionId, null)), 'cancelling a closed session is refused');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$sessionId}") === 'chiusa', 'the closed session stays closed');
    $check((int) $one("SELECT COUNT(*) FROM log_modifiche WHERE tabella = 'inventario_sessioni' AND record_id IN ({$sessionId}, {$second}) AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) IN ('inventory.closed', 'inventory.cancelled')") === 2, 'only the winning transitions are logged');

    // A scan in flight while another connection is closing the session waits
    // for the close instead of landing on a closed session.
    $third = $service->open('ZZST3 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $third;
    $other = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
    $other->begin_transaction();
    $other->query("UPDATE inventario_sessioni SET stato = 'chiusa' WHERE id = {$third} AND stato = 'aperta'");
    $db->query('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $service->scan($third, "{$p}-C5", null, null);
        $blocked = false;
    } catch (\Throwable) {
        $blocked = true;
    }
    $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    $other->commit();
    $other->close();
    $check($blocked && (int) $one("SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = {$third}") === 0, 'a scan racing a pending close is not recorded');
    $check($refused(static fn () => $service->scan($third, "{$p}-C5", null, null)), 'once the close commits the scan is refused');

    // Closing with "mark lost" from the page must run to the end: the session
    // is claimed first, so a request cut off halfway could not be retried.
    $fourth = $service->open('ZZST4 ' . $run, null, ['collocazione_da' => "{$p}.B2", 'collocazione_a' => "{$p}.B2"], null);
    $sessionIds[] = $fourth;
    ignore_user_abort(false);
    $_SESSION = ['user' => ['id' => 0, 'tipo_utente' => 'admin']];
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/inventory/' . $fourth . '/close')
        ->withParsedBody(['azione_mancanti' => 'perso']);
    (new App\Controllers\InventoryController())->close($request, new Slim\Psr7\Response(), $db, $fourth);
    $check(ignore_user_abort() === 1, 'a mark-lost close keeps running if the browser goes away');
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$fourth}") === 'chiusa', 'and closes the session');

    echo "SUCCESS {$checks} stocktake snapshot checks\n";
} finally {
    foreach ($triggers as $trigger) {
        $db->query("DROP TRIGGER IF EXISTS {$trigger}");
    }
    foreach ($sessionIds as $sid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'inventario_sessioni' AND record_id = {$sid}");
        $db->query("DELETE FROM inventario_sessioni WHERE id = {$sid}");
    }
    foreach ($books as $bid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$bid}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bid}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bid}");
        $db->query("DELETE FROM libri WHERE id = {$bid}");
    }
    foreach ($shelves as $sid) {
        $db->query("DELETE FROM scaffali WHERE id = {$sid}");
    }
    if ($borrowerId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$borrowerId}");
    }
}
