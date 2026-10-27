<?php
declare(strict_types=1);

/**
 * Inventory stocktake (plan §8) against the real database: the snapshot taken
 * at opening is independent of later catalogue edits, every scan lands in the
 * right bucket, the buckets add up, and closing with "mark as lost" changes
 * only the missing copies, recalculates availability and writes the audit log.
 *
 * Touches only rows it creates (titles/codes prefixed ZZST) and removes them.
 * Run: php tests/stocktake-snapshot.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
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
    $db->query("UPDATE libri SET copie_totali = 3, copie_disponibili = 3 WHERE id = {$b1}");

    $service = new StocktakeService($db);
    $sessionId = $service->open('ZZST ' . $run, null, ['scaffale_id' => (string) $shelves['X']], null);
    $sessionIds[] = $sessionId;
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$sessionId}") === 4, 'the snapshot holds the four copies on shelf X');
    $check($one("SELECT GROUP_CONCAT(copia_id ORDER BY copia_id) FROM inventario_attese WHERE sessione_id = {$sessionId}") === implode(',', [$c1, $c2, $c6, $c3]), 'exactly the shelf-X copies are expected');
    $check($one("SELECT stato_all_apertura FROM inventario_attese WHERE sessione_id = {$sessionId} AND copia_id = {$c3}") === 'prestato', 'the snapshot records each copy state at opening');

    // Catalogue edits after opening must not change what the session expects.
    $db->query("UPDATE libri SET scaffale_id = {$shelves['Y']} WHERE id = {$b1}");
    $c5 = $addCopy($b2, 'C5');
    $db->query("DELETE FROM copie WHERE id = {$c2}");
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$b3}");
    $check((int) $one("SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = {$sessionId}") === 4, 'moving a book, adding and deleting copies leaves the snapshot intact');
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
    $check($summary['attese'] === 4 && $summary['trovate'] === 1 && $summary['fuori_posto'] === 0 && $summary['in_prestito'] === 1 && $summary['mancanti'] === 2, 'found 1, on loan 1, missing 2 (C2 deleted, C6 unscanned)');
    $check($summary['trovate'] + $summary['fuori_posto'] + $summary['in_prestito'] + $summary['mancanti'] === $summary['attese'], 'the four buckets add up to the expected copies');
    $check($summary['sconosciute'] === 2 && $summary['fuori_ambito'] === 1 && $summary['duplicate'] === 1 && $summary['scansioni'] === 6, 'side counters: 2 unknown, 1 out of scope, 1 duplicate, 6 scans');

    $categories = array_count_values(array_column($service->reportRows($sessionId), 'categoria'));
    $check(($categories['mancante'] ?? 0) === 2 && ($categories['trovata'] ?? 0) === 1 && ($categories['in_prestito'] ?? 0) === 1, 'the report rows list the same buckets');

    $result = $service->close($sessionId, true, null);
    $check($result === ['marked' => 1, 'failed' => 0], 'closing with "mark lost" marks only C6 (C2 no longer exists, C3 is lent)');
    $check($one("SELECT stato FROM copie WHERE id = {$c6}") === 'perso', 'C6 is now lost');
    $check($one("SELECT stato FROM copie WHERE id = {$c1}") === 'disponibile' && $one("SELECT stato FROM copie WHERE id = {$c3}") === 'prestato', 'found and lent copies are untouched');
    $check((int) $one("SELECT copie_disponibili FROM libri WHERE id = {$b1}") === 1, 'book availability is recalculated (1 of the 2 remaining copies)');
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
    $check((int) $one("SELECT totale_attese FROM inventario_sessioni WHERE id = {$second}") === 2, 'a collocation range opens on B2 alone (C3, C5)');
    $import = $service->importCodes($second, StocktakeService::parseCodeList("codice\n{$p}-C5\n{$p}-C5\n{$p}-C1\n"), null, null);
    $check($import['total'] === 3 && $import['outcomes']['trovata'] === 1 && $import['outcomes']['duplicata'] === 1 && $import['outcomes']['fuori_ambito'] === 1, 'an uploaded list is classified line by line');
    $service->cancel($second, null);
    $check($one("SELECT stato FROM inventario_sessioni WHERE id = {$second}") === 'annullata' && $one("SELECT stato FROM copie WHERE id = {$c5}") === 'disponibile', 'cancelling leaves every copy as it was');

    echo "SUCCESS {$checks} stocktake snapshot checks\n";
} finally {
    foreach ($sessionIds as $sid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'inventario_sessioni' AND record_id = {$sid}");
        $db->query("DELETE FROM inventario_sessioni WHERE id = {$sid}");
    }
    foreach ($books as $bid) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$bid}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bid}");
        $db->query("DELETE FROM libri WHERE id = {$bid}");
    }
    foreach ($shelves as $sid) {
        $db->query("DELETE FROM scaffali WHERE id = {$sid}");
    }
}
