<?php
declare(strict_types=1);

/**
 * Upgrade-path coverage for the #366 legacy ready-pickup repair.
 *
 * Part A replays the REAL migration file on sandbox tables seeded with the
 * pre-0.7.63 corruption, with the PRE-fix (main) BEFORE UPDATE trigger
 * installed: the demotion must escape its inherited-overlap gate exactly the
 * way it will on a production upgrade (the updater re-applies the corrected
 * trigger only AFTER migrations run). It also covers the copy-release mirror:
 * a copie row stuck in prenotato/prestato with no committing loan must come
 * back to 'disponibile'.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(5));
$loans = "zz_mig_0763_loans_{$suffix}";
$copies = "zz_mig_0763_copies_{$suffix}";
$trigger = "zz_mig_0763_trg_{$suffix}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, $loans, $copies, $trigger): void {
    $db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
    $db->query("DROP TABLE IF EXISTS `{$loans}`");
    $db->query("DROP TABLE IF EXISTS `{$copies}`");
};
$row = static function (int $id) use ($db, $loans): array {
    return $db->query("SELECT stato, attivo, copia_id, pickup_deadline FROM `{$loans}` WHERE id = {$id}")->fetch_assoc() ?: [];
};
$copyState = static function (int $id) use ($db, $copies): string {
    return (string) ($db->query("SELECT stato FROM `{$copies}` WHERE id = {$id}")->fetch_assoc()['stato'] ?? '');
};

echo "A. Data repair on a pre-0.7.63 sandbox\n";
try {
    $cleanup();
    $db->query("CREATE TABLE `{$copies}` (
        id INT NOT NULL,
        libro_id INT NOT NULL,
        stato VARCHAR(32) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE `{$loans}` (
        id INT NOT NULL,
        libro_id INT NOT NULL,
        copia_id INT NULL,
        stato VARCHAR(32) NOT NULL,
        attivo TINYINT(1) NOT NULL,
        data_prestito DATE NOT NULL,
        data_scadenza DATE NOT NULL,
        pickup_deadline DATE NULL,
        PRIMARY KEY (id),
        KEY idx_copy (copia_id)
    ) ENGINE=InnoDB");

    $db->query("INSERT INTO `{$copies}` (id, libro_id, stato) VALUES
        (1, 1, 'prestato'),
        (2, 2, 'disponibile'),
        (3, 3, 'prenotato'),
        (4, 4, 'disponibile'),
        (5, 5, 'prestato'),
        (6, 5, 'prenotato'),
        (7, 6, 'prestato'),
        (8, 7, 'prenotato')");
    $db->query("INSERT INTO `{$loans}` (id, libro_id, copia_id, stato, attivo, data_prestito, data_scadenza, pickup_deadline) VALUES
        (1, 1, 1, 'in_ritardo', 1, '2026-07-27', '2026-08-18', NULL),
        (2, 1, 1, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23'),
        (3, 2, 2, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23'),
        (4, 3, 3, 'in_corso', 1, '2026-08-01', '2026-08-25', NULL),
        (5, 3, 3, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23'),
        (6, 4, NULL, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23'),
        (7, 5, 5, 'in_ritardo', 1, '2026-07-27', '2026-08-18', NULL),
        (8, 5, 6, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23'),
        (9, 6, 7, 'da_ritirare', 1, '2026-08-19', '2026-08-31', '2026-08-23')");

    // Frozen copy of main's PRE-fix `trg_check_active_prestito_before_update`
    // (installer/database/triggers.sql as of 0.7.61), rewritten onto the
    // sandbox tables. On a real upgrade the demotion below runs while this
    // trigger is still installed — the updater re-applies the corrected
    // canonical trigger only after migrations complete.
    $db->query("CREATE TRIGGER `{$trigger}`
BEFORE UPDATE ON `{$loans}`
FOR EACH ROW
BEGIN
    IF NEW.copia_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM `{$copies}` WHERE id = NEW.copia_id AND libro_id = NEW.libro_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'La copia non appartiene al libro del prestito.';
    END IF;

    IF (NEW.copia_id IS NOT NULL AND (NEW.attivo = 1 OR NEW.stato = 'pendente')) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM `{$copies}` c
            WHERE c.id = NEW.copia_id
              AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'La copia non è disponibile per il prestito.';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM `{$loans}` p
            WHERE p.copia_id = NEW.copia_id
              AND p.id <> NEW.id
              AND p.data_prestito <= NEW.data_scadenza
              AND (p.stato = 'in_ritardo' OR p.data_scadenza >= NEW.data_prestito)
              AND (
                  (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','prenotato','da_ritirare'))
                  OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)
              )
        ) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Esiste già un prestito attivo e sovrapposto per questa copia.';
        END IF;
    END IF;
END");

    // Sanity: prove the legacy gate is live and would freeze the corrupt pair
    // if the repair kept copia_id — an ordinary edit of row 2 must fail.
    $legacyGateLive = false;
    try {
        $db->query("UPDATE `{$loans}` SET pickup_deadline = '2026-08-24' WHERE id = 2");
    } catch (mysqli_sql_exception $e) {
        $legacyGateLive = str_contains($e->getMessage(), 'Esiste già un prestito attivo');
    }
    $check($legacyGateLive, 'pre-fix trigger is installed and freezes an ordinary edit of the corrupt pair');

    $migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.63-rc.1.sql');
    $sandboxSql = str_replace(
        ['`prestiti`', '`copie`'],
        ["`{$loans}`", "`{$copies}`"],
        preg_replace('/^--.*$/m', '', $migration) ?? $migration
    );
    $statements = array_values(array_filter(
        array_map('trim', explode(';', $sandboxSql)),
        static fn (string $sql): bool => $sql !== ''
    ));
    $run = static function () use ($db, $statements): void {
        foreach ($statements as $sql) {
            $db->query($sql);
        }
    };

    // Behavioural proof that `copia_id = NULL` escapes the legacy overlap
    // gate: the whole migration must succeed with the PRE-fix trigger active.
    $migrationSucceeded = true;
    try {
        $run();
    } catch (mysqli_sql_exception $e) {
        $migrationSucceeded = false;
    }
    $check($migrationSucceeded, 'the demotion executes under the legacy overlap trigger (copia_id = NULL bypass, proven behaviourally)');
    $check($row(2)['stato'] === 'prenotato' && $row(2)['pickup_deadline'] === null && $row(2)['copia_id'] === null,
        'same-copy overdue survivor is demoted, unpinned and loses the stale deadline');
    $check($row(3)['stato'] === 'da_ritirare' && (int) $row(3)['copia_id'] === 2,
        'a truthful ready pickup on an available copy is preserved');
    $check($row(5)['stato'] === 'prenotato' && $row(5)['copia_id'] === null,
        'loan-row occupancy wins over a stale copie.stato=prenotato cache');
    $check($row(6)['stato'] === 'prenotato' && $row(6)['copia_id'] === null,
        'copy-less ready pickup is returned to the assignable scheduled state');
    $check($row(8)['stato'] === 'da_ritirare' && (int) $row(8)['copia_id'] === 6,
        'multi-copy ready pickup survives when its own copy is physically free');
    $check($row(9)['stato'] === 'prenotato' && $row(9)['copia_id'] === null,
        'pickup pinned to a stale prestato copy is demoted');

    // Copy-release mirror: freed/orphaned circulation states come back to the
    // shelf; copies still committed by an open loan are untouched.
    $check($copyState(7) === 'disponibile', 'the copy freed by the demotion is released to disponibile');
    $check($copyState(8) === 'disponibile', 'an orphaned prenotato copy with no loan at all is released');
    $check($copyState(1) === 'prestato' && $copyState(5) === 'prestato',
        'copies still held by an overdue loan keep their prestato state');
    $check($copyState(3) === 'prenotato' && $copyState(6) === 'prenotato',
        'copies committed by an open loan keep their prenotato state');
    $orphaned = (int) $db->query("
        SELECT COUNT(*) FROM `{$copies}` c
        WHERE c.stato IN ('prenotato', 'prestato')
          AND NOT EXISTS (
              SELECT 1 FROM `{$loans}` l
              WHERE l.copia_id = c.id
                AND ( (l.attivo = 1 AND l.stato IN ('in_corso','in_ritardo','da_ritirare','prenotato'))
                      OR (l.attivo = 0 AND l.stato = 'pendente' AND l.copia_id IS NOT NULL) )
          )
    ")->fetch_row()[0];
    $check($orphaned === 0, 'no copy is left prenotato/prestato without a committing loan');

    $snapshot = $db->query("SELECT id, stato, copia_id, pickup_deadline FROM `{$loans}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $copySnapshot = $db->query("SELECT id, stato FROM `{$copies}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $run();
    $check($db->query("SELECT id, stato, copia_id, pickup_deadline FROM `{$loans}` ORDER BY id")->fetch_all(MYSQLI_ASSOC) === $snapshot
        && $db->query("SELECT id, stato FROM `{$copies}` ORDER BY id")->fetch_all(MYSQLI_ASSOC) === $copySnapshot,
        'migration is idempotent');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
    $db->close();
}

echo "B. Upgrade wiring\n";
$migrationVersion = '0.7.63-rc.1';
$check(Updater::shouldRunMigration($migrationVersion, '0.7.62', '0.7.63-rc.1'),
    'Updater runs the repair on 0.7.62 -> 0.7.63-rc.1');
$check(Updater::shouldRunMigration($migrationVersion, '0.7.62', '0.7.63'),
    'Updater runs the repair when the RC was skipped');
$check(!Updater::shouldRunMigration($migrationVersion, '0.7.63-rc.1', '0.7.63'),
    'Updater does not repeat an already-recorded RC migration');
$check(!Updater::shouldRunMigration($migrationVersion, '0.7.62', '0.7.62'),
    'Updater never runs a migration named beyond toVersion (out-of-range = silently skipped, the naming-rule invariant)');

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
