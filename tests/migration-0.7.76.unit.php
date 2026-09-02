<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the 0.7.76 backfill copy-enrichment migration.
 *
 * The REAL SQL file is retargeted to per-run sandbox tables seeded with the
 * exact state the 0.7.75 backfill leaves behind: loan events with
 * source=backfill and NO copia_id, next to loans that carry one. Executed
 * twice to prove enrichment and idempotency; real runtime events and
 * reservations must be untouched.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Credentials: E2E_DB_* env preferred, .env fallback REQUIRED — the schema
// gate (scripts/verify-schema.sh) runs migration tests reading creds from
// .env by documented contract, same as every migration-*.unit.php sibling.
// This test only touches per-run zz_m76_* sandbox tables, never live rows.
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
    fwrite(STDERR, "FAIL: database unreachable — migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(5));
$auditTable = 'zz_m76_audit_' . $suffix;
$loansTable = 'zz_m76_loans_' . $suffix;
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.76.sql');
$migration = str_replace(
    ['log_modifiche', 'prestiti p'],
    [$auditTable, "{$loansTable} p"],
    $migration
);
$migration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;

$runMigration = static function () use ($db, $migration): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $migration))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $auditTable, $loansTable): void {
    $db->query("DROP TABLE IF EXISTS `{$auditTable}`");
    $db->query("DROP TABLE IF EXISTS `{$loansTable}`");
};

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $passed++;
    echo "  OK  {$label}\n";
};

$copiaOf = static function (int $entityId) use ($db, $auditTable) {
    $row = $db->query(
        "SELECT JSON_EXTRACT(dati_nuovi, '$.copia_id') AS c FROM `{$auditTable}` WHERE JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') = {$entityId} LIMIT 1"
    )->fetch_assoc();
    return $row['c'] ?? null;
};

try {
    $db->query("CREATE TABLE `{$loansTable}` (id INT PRIMARY KEY, libro_id INT NOT NULL, copia_id INT NULL)");
    $db->query("CREATE TABLE `{$auditTable}` (id INT AUTO_INCREMENT PRIMARY KEY, tabella VARCHAR(50) NOT NULL, record_id INT NOT NULL, azione ENUM('inserimento','aggiornamento','cancellazione') NOT NULL, dati_precedenti TEXT NULL, dati_nuovi TEXT NULL, utente_id INT NULL, data_modifica DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // The 0.7.75-backfill shape: loan events WITHOUT copia_id.
    $db->query("INSERT INTO `{$loansTable}` VALUES (601, 14, 4), (602, 14, NULL), (603, 15, 9)");
    $mk = static fn(int $eid, string $event, string $source, string $extra = '') => "('libri', 14, 'inserimento', '{}', '{\"stato\":\"in_corso\"{$extra},\"_activity\":{\"type\":\"loan\",\"event\":\"{$event}\",\"entity_id\":{$eid},\"source\":\"{$source}\"}}', NULL, NOW())";
    $db->query("INSERT INTO `{$auditTable}` (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id, data_modifica) VALUES "
        . $mk(601, 'loan.created', 'backfill') . ','
        . $mk(601, 'loan.returned', 'backfill') . ','
        . $mk(602, 'loan.created', 'backfill') . ','          // loan senza copia: resta senza
        . $mk(603, 'loan.created', 'backfill', ',"copia_id":77') . ','  // già arricchito: intoccato
        . $mk(601, 'loan.picked_up', 'pickup'));               // evento REALE: intoccato

    $runMigration();

    $check((string) $copiaOf(601) === '4' , 'backfilled events inherit the loan\'s copia_id');
    $returned = $db->query("SELECT JSON_EXTRACT(dati_nuovi, '$.copia_id') c FROM `{$auditTable}` WHERE JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi,'$._activity.event'))='loan.returned'")->fetch_assoc();
    $check((string) ($returned['c'] ?? '') === '4', 'loan.returned is enriched too');
    $check($copiaOf(602) === null || $copiaOf(602) === 'null', 'a loan without a copy stays without one');
    $check((string) $copiaOf(603) === '77', 'an already-enriched event keeps its value');
    $real = $db->query("SELECT JSON_EXTRACT(dati_nuovi, '$.copia_id') c FROM `{$auditTable}` WHERE JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi,'$._activity.source'))='pickup'")->fetch_assoc();
    $check(($real['c'] ?? null) === null, 'real runtime events are untouched');

    $before = md5((string) json_encode($db->query("SELECT dati_nuovi FROM `{$auditTable}` ORDER BY id")->fetch_all()));
    $runMigration();
    $after = md5((string) json_encode($db->query("SELECT dati_nuovi FROM `{$auditTable}` ORDER BY id")->fetch_all()));
    $check($before === $after, 'second run is a byte-for-byte no-op (idempotent)');

    $version = json_decode((string) file_get_contents($root . '/version.json'), true);
    $check(
        version_compare('0.7.76', (string) ($version['version'] ?? '0'), '<='),
        'release version includes the migration (0.7.76 <= version.json)'
    );
} catch (Throwable $e) {
    try {
        $cleanup();
    } catch (Throwable) {
    }
    $db->close();
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

$cleanup();
$db->close();
echo "\nPassed: {$passed}, Failed: 0\n";
