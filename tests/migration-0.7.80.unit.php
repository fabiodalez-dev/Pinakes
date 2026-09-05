<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the 0.7.80 durable-email-outbox migration.
 *
 * The REAL SQL file is retargeted to a per-run sandbox table and executed
 * TWICE against a database that starts without it (the pre-0.7.80 state):
 * effect asserted column-by-column against the runtime writer's
 * expectations, second run proven a no-op. The database being unreachable
 * is a hard FAIL — migration tests are mandatory, never skippable.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Credentials: E2E_DB_* env preferred, .env fallback REQUIRED — the schema
// gate (scripts/verify-schema.sh) runs migration tests reading creds from
// .env by documented contract, same as every migration-*.unit.php sibling.
// This test only touches a per-run zz_m80_* sandbox table, never live rows.
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
$table = 'zz_m80_outbox_' . $suffix;
$resTable = 'zz_m80_pren_' . $suffix;
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.80.sql');
$migration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;
$migration = str_replace('`email_delivery_outbox`', "`{$table}`", $migration);
$migration = str_replace('prenotazioni', $resTable, $migration);

$runMigration = static function () use ($db, $migration): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $migration))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $table, $resTable): void {
    $db->query("DROP TABLE IF EXISTS `{$table}`");
    $db->query("DROP TABLE IF EXISTS `{$resTable}`");
};

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $passed++;
    echo "  OK  {$label}\n";
};

try {
    $cleanup(); // pre-0.7.80 state: the outbox table does not exist

    // Pre-0.7.80 reservations with every legacy anomaly the new triggers
    // forbid: duplicate active positions, a non-positive position, a legacy
    // NULL, an inverted request window — plus closed/other-book rows that
    // must come through untouched.
    $db->query("CREATE TABLE `{$resTable}` (
        id INT PRIMARY KEY AUTO_INCREMENT,
        libro_id INT NOT NULL,
        stato VARCHAR(20) NOT NULL,
        queue_position INT NULL,
        data_inizio_richiesta DATE NULL,
        data_fine_richiesta DATE NULL
    )");
    $db->query("INSERT INTO `{$resTable}` (id, libro_id, stato, queue_position, data_inizio_richiesta, data_fine_richiesta) VALUES
        (1, 7, 'attiva',     1,    '2026-01-10', '2026-01-20'),
        (2, 7, 'attiva',     1,    '2026-02-01', '2026-02-10'),  -- duplicate of #1
        (3, 7, 'attiva',     NULL, '2026-03-01', '2026-03-10'),  -- legacy NULL
        (4, 7, 'attiva',     0,    '2026-04-10', '2026-04-01'),  -- non-positive + inverted window
        (5, 7, 'completata', 1,    '2025-01-01', '2025-01-05'),  -- closed: untouched
        (6, 9, 'attiva',     2,    '2026-05-01', '2026-05-08')   -- other book, healthy gap → compacted
    ");

    $runMigration();

    // Canonical repair ordering (queue_position ASC, id ASC — NULLs first):
    // #4 (pos 0) → 1, #1 (pos 1) → 2, #2 (dup 1) → 3, #3 (NULL) → 4.
    $positions = [];
    $res = $db->query("SELECT id, queue_position, data_inizio_richiesta, data_fine_richiesta FROM `{$resTable}` ORDER BY id");
    while ($row = $res->fetch_assoc()) {
        $positions[(int) $row['id']] = $row;
    }
    // NULL sorts first in MySQL ASC: #3 (NULL) → 1, #4 (0) → 2, #1 (1) → 3, #2 (dup 1) → 4.
    $check((int) $positions[3]['queue_position'] === 1, 'legacy NULL position is renumbered first (canonical NULLs-first ordering)');
    $check((int) $positions[4]['queue_position'] === 2, 'non-positive position is normalized');
    $check((int) $positions[1]['queue_position'] === 3 && (int) $positions[2]['queue_position'] === 4, 'duplicate active positions become sequential');
    $check($positions[4]['data_inizio_richiesta'] === '2026-04-01' && $positions[4]['data_fine_richiesta'] === '2026-04-10', 'inverted request window is swapped, not corrupted');
    $check((int) $positions[5]['queue_position'] === 1, 'closed reservations are untouched');
    $check((int) $positions[6]['queue_position'] === 1, 'other-book queue is compacted independently');
    $active = $db->query("SELECT COUNT(DISTINCT queue_position) c, COUNT(*) n FROM `{$resTable}` WHERE libro_id = 7 AND stato = 'attiva'")->fetch_assoc();
    $check((int) $active['c'] === (int) $active['n'], 'no duplicate active positions survive — the new triggers can never fire on legacy data');

    $cols = [];
    $res = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                         FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'");
    while ($row = $res->fetch_assoc()) {
        $cols[$row['COLUMN_NAME']] = $row;
    }
    $check(count($cols) === 10, 'migration creates all ten outbox columns');
    $check(($cols['id']['COLUMN_TYPE'] ?? '') === 'bigint unsigned', 'id is bigint unsigned');
    $check(($cols['claim_token']['COLUMN_TYPE'] ?? '') === 'char(32)' && ($cols['claim_token']['IS_NULLABLE'] ?? '') === 'YES', 'claim_token is nullable char(32)');
    $check(($cols['attempts']['COLUMN_TYPE'] ?? '') === 'int unsigned' && ($cols['attempts']['COLUMN_DEFAULT'] ?? '') === '0', 'attempts defaults to 0');
    $check(($cols['variables_json']['COLUMN_TYPE'] ?? '') === 'longtext', 'variables_json is longtext');

    $idx = [];
    $res = $db->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                         FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
                        GROUP BY INDEX_NAME");
    while ($row = $res->fetch_assoc()) {
        $idx[$row['INDEX_NAME']] = $row['cols'];
    }
    $check(($idx['idx_email_outbox_due'] ?? '') === 'available_at,claim_token', 'due-scan index (available_at, claim_token) exists');
    $check(($idx['idx_email_outbox_claimed'] ?? '') === 'claimed_at', 'claimed_at index exists');

    // The row shape the runtime writer produces must be storable.
    $db->query("INSERT INTO `{$table}` (recipient_email, template_name, variables_json)
                VALUES ('reader@example.org', 'loan_copy_outcome', '{\"utente_nome\":\"Å\"}')");
    $stored = $db->query("SELECT attempts, claim_token, available_at FROM `{$table}` LIMIT 1")->fetch_assoc();
    $check((int) $stored['attempts'] === 0 && $stored['claim_token'] === null && $stored['available_at'] !== null, 'runtime-shaped row stores with sane defaults');

    // Idempotency: a second run must be a guarded no-op that keeps data —
    // for the outbox AND for the normalized reservations.
    $before = md5((string) json_encode($db->query("SELECT id, queue_position, data_inizio_richiesta, data_fine_richiesta FROM `{$resTable}` ORDER BY id")->fetch_all()));
    $runMigration();
    $count = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0];
    $check($count === 1, 'second run is a guarded no-op (outbox row survives)');
    $after = md5((string) json_encode($db->query("SELECT id, queue_position, data_inizio_richiesta, data_fine_richiesta FROM `{$resTable}` ORDER BY id")->fetch_all()));
    $check($before === $after, 'second run leaves the normalized reservations byte-for-byte unchanged');

    // The three definitions of the outbox must never drift: migration,
    // fresh-install schema.sql and the runtime creator in EmailOutboxSchema.
    $schemaSql = (string) file_get_contents($root . '/installer/database/schema.sql');
    $runtime = (string) file_get_contents($root . '/app/Support/EmailOutboxSchema.php');
    foreach (array_keys($cols) as $col) {
        $check(
            str_contains($schemaSql, $col) && str_contains($runtime, $col),
            "column {$col} exists in schema.sql and EmailOutboxSchema too"
        );
    }

    $version = json_decode((string) file_get_contents($root . '/version.json'), true);
    $check(
        version_compare('0.7.80', (string) ($version['version'] ?? '0'), '<='),
        'release version includes the migration (0.7.80 <= version.json)'
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
