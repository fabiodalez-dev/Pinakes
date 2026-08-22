<?php
declare(strict_types=1);

/**
 * Behavioural coverage for migrate_0.7.61-rc.1.sql (legacy copy backfill).
 *
 * 0.7.61 derives libri.copie_totali/copie_disponibili/stato from the `copie`
 * table. Legacy installs have books that carry only the counters and zero
 * `copie` rows: without this migration the first recalc zeroes every legacy
 * book. The test seeds a sandbox with the OLD state (counter-only books,
 * unbound active loans, out-of-circulation copies, code-collision cases) and
 * runs the REAL migration file against it, asserting:
 *   - the derived total (circulating copie rows) matches the legacy counter;
 *   - active loans get bound to distinct free copies (none orphaned/duplicated);
 *   - active book-level reservations still consume the same availability;
 *   - copy-bound loans and out-of-circulation copies are untouched;
 *   - soft-deleted and zero-copy books get nothing;
 *   - inventory codes never collide, including duplicate-base books;
 *   - the whole file is idempotent;
 *   - Updater ordering guarantees the backfill runs BEFORE the availability
 *     recalculation that would otherwise zero the counters.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

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
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$tLibri = 'zz_mig0761_libri';
$tCopie = 'zz_mig0761_copie';
$tPrestiti = 'zz_mig0761_prestiti';
$tPrenotazioni = 'zz_mig0761_prenotazioni';

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.61-rc.1.sql');
$sandboxMigration = static fn(string $sql): string => str_replace(
    ['`libri`', '`copie`', '`prestiti`'],
    ["`{$tLibri}`", "`{$tCopie}`", "`{$tPrestiti}`"],
    $sql
);
$runMigration = static function () use ($db, $migration, $sandboxMigration): void {
    // Mirror Updater::runMigrations(): strip full-line comments, split on ';'.
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sandboxMigration($withoutComments)) ?: [])) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $tLibri, $tCopie, $tPrestiti, $tPrenotazioni): void {
    $db->query("DROP TABLE IF EXISTS `{$tPrenotazioni}`");
    $db->query("DROP TABLE IF EXISTS `{$tPrestiti}`");
    $db->query("DROP TABLE IF EXISTS `{$tCopie}`");
    $db->query("DROP TABLE IF EXISTS `{$tLibri}`");
};

$scalar = static function (string $sql) use ($db): int {
    $row = $db->query($sql)->fetch_row();
    return (int) ($row[0] ?? 0);
};

echo "A. Real migration against a sandbox with the pre-0.7.61 legacy state\n";
try {
    $cleanup();
    $db->query("CREATE TABLE `{$tLibri}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `titolo` VARCHAR(255) NOT NULL,
        `numero_inventario` VARCHAR(50) DEFAULT NULL,
        `copie_totali` INT DEFAULT 1,
        `copie_disponibili` INT DEFAULT 1,
        `stato` ENUM('disponibile','prestato','prenotato','perso','danneggiato','non_disponibile') DEFAULT 'disponibile',
        `deleted_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE `{$tCopie}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `libro_id` INT NOT NULL,
        `numero_inventario` VARCHAR(100) NOT NULL,
        `stato` ENUM('disponibile','prestato','prenotato','manutenzione','in_restauro','perso','danneggiato','in_trasferimento') DEFAULT 'disponibile',
        `note` TEXT,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_zz0761_inv` (`numero_inventario`),
        KEY `idx_zz0761_libro` (`libro_id`),
        CONSTRAINT `fk_zz0761_copie_libro` FOREIGN KEY (`libro_id`) REFERENCES `{$tLibri}` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE `{$tPrestiti}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `libro_id` INT NOT NULL,
        `copia_id` INT DEFAULT NULL,
        `utente_id` INT NOT NULL,
        `data_prestito` DATE NOT NULL,
        `data_scadenza` DATE NOT NULL,
        `stato` ENUM('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') DEFAULT 'pendente',
        `attivo` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        KEY `idx_zz0761_copia` (`copia_id`),
        CONSTRAINT `fk_zz0761_prestiti_copia` FOREIGN KEY (`copia_id`) REFERENCES `{$tCopie}` (`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE `{$tPrenotazioni}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `libro_id` INT NOT NULL,
        `data_scadenza_prenotazione` DATETIME DEFAULT NULL,
        `data_inizio_richiesta` DATE DEFAULT NULL,
        `data_fine_richiesta` DATE DEFAULT NULL,
        `stato` ENUM('attiva','completata','annullata','scaduta') DEFAULT 'attiva',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Seed the OLD state ────────────────────────────────────────────────
    // 1: legacy book, counters 3/1, NO copie rows, two unbound active loans.
    // 2: healthy per-copy book (2 rows, one copy-bound active loan).
    // 3: out-of-circulation 'perso' copy + counter 1 (one circulating missing).
    // 4: soft-deleted legacy book (counter 5) — must get nothing.
    // 5: counter 0 — must get nothing.
    // 6/7: duplicate numero_inventario base 'DUP' (code-collision case).
    // 8: empty numero_inventario (falls back to LIB-{id}).
    // 9: unbound active 'prenotato' loan + counter 1.
    // 10: a valid high-volume legacy counter above the old 1,000-row sequence.
    // 11: two legacy copies, one occupied by a book-level reservation today.
    $db->query("INSERT INTO `{$tLibri}` (id, titolo, numero_inventario, copie_totali, copie_disponibili, stato, deleted_at) VALUES
        (1, 'Legacy with loans',   'INV-A', 3, 1, 'disponibile', NULL),
        (2, 'Healthy per-copy',    'PC',    2, 1, 'disponibile', NULL),
        (3, 'Out of circulation',  'OOC',   1, 1, 'disponibile', NULL),
        (4, 'Soft deleted',        'DEL',   5, 5, 'disponibile', NOW()),
        (5, 'Zero copies',         'ZERO',  0, 0, 'non_disponibile', NULL),
        (6, 'Duplicate base one',  'DUP',   1, 1, 'disponibile', NULL),
        (7, 'Duplicate base two',  'DUP',   1, 1, 'disponibile', NULL),
        (8, 'No inventory code',   '',      2, 2, 'disponibile', NULL),
        (9, 'Reserved legacy',     'RES',   1, 1, 'disponibile', NULL),
        (10, 'Large legacy set',   'LARGE', 2001, 2001, 'disponibile', NULL),
        (11, 'Legacy reservation', 'BOOKRES', 2, 1, 'disponibile', NULL)");
    $db->query("INSERT INTO `{$tCopie}` (id, libro_id, numero_inventario, stato) VALUES
        (1, 2, 'PC-C1',  'disponibile'),
        (2, 2, 'PC-C2',  'prestato'),
        (3, 3, 'OOC-C1', 'perso')");
    $db->query("INSERT INTO `{$tPrestiti}` (id, libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES
        (1, 1, NULL, 10, '2026-07-01', '2026-07-31', 'in_corso',   1),
        (2, 1, NULL, 11, '2026-07-05', '2026-08-05', 'in_ritardo', 1),
        (3, 2, 2,    12, '2026-07-10', '2026-08-10', 'in_corso',   1),
        (4, 1, NULL, 13, '2026-08-01', '2026-08-20', 'pendente',   0),
        (5, 9, NULL, 14, '2026-09-01', '2026-09-20', 'prenotato',  1)");
    $db->query("INSERT INTO `{$tPrenotazioni}` (id, libro_id, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, stato)
        VALUES (1, 11, '2030-12-31 23:59:59', '2020-01-01', '2030-12-31', 'attiva')");

    $runMigration();

    // Book 1: derived total must equal the legacy counter, codes {base}-C{N}.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 1") === 3, 'book 1: three copie rows backfilled (derived total = legacy counter 3)');
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 1 AND numero_inventario IN ('INV-A-C1','INV-A-C2','INV-A-C3')") === 3, 'book 1: codes follow the app scheme INV-A-C1..C3');
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 1 AND stato = 'disponibile'") === 3, 'book 1: backfilled copies are created disponibile (recalc derives loan states)');

    // Book 1: both unbound active loans are now bound to DISTINCT copies of book 1.
    $check($scalar("SELECT COUNT(*) FROM `{$tPrestiti}` p JOIN `{$tCopie}` c ON c.id = p.copia_id AND c.libro_id = 1 WHERE p.id IN (1,2)") === 2, 'book 1: both active loans bound to copies of their own book');
    $check($scalar("SELECT COUNT(DISTINCT copia_id) FROM `{$tPrestiti}` WHERE id IN (1,2)") === 2, 'book 1: the two loans hold two DIFFERENT copies');
    $check($scalar("SELECT COUNT(*) FROM `{$tPrestiti}` WHERE id = 4 AND copia_id IS NULL") === 1, 'book 1: inactive pendente request stays unbound (no new holds created)');
    // Derived availability the recalc will read: circulating copies minus held ones.
    $free = $scalar("SELECT COUNT(*) FROM `{$tCopie}` c WHERE c.libro_id = 1 AND c.stato NOT IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento') AND NOT EXISTS (SELECT 1 FROM `{$tPrestiti}` p WHERE p.copia_id = c.id AND p.attivo = 1)");
    $check($free === 1, 'book 1: derived availability (3 copies - 2 on loan = 1) matches legacy copie_disponibili');

    // Book 2: healthy per-copy book untouched.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 2") === 2, 'book 2: per-copy book gains no rows');
    $check($scalar("SELECT COUNT(*) FROM `{$tPrestiti}` WHERE id = 3 AND copia_id = 2") === 1, 'book 2: pre-existing copy-bound loan keeps its copy');

    // Book 3: 'perso' copy untouched, one circulating copy minted after it.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE id = 3 AND stato = 'perso' AND numero_inventario = 'OOC-C1'") === 1, 'book 3: out-of-circulation copy is untouched');
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 3 AND stato = 'disponibile' AND numero_inventario = 'OOC-C2'") === 1, 'book 3: missing circulating copy minted as OOC-C2 (numbering skips existing rows)');

    // Books 4 and 5: nothing created.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id IN (4,5)") === 0, 'soft-deleted and zero-counter books get no copies');

    // Books 6/7: duplicate base — one wins DUP-C1, the other falls back to LIB-{id}.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 6") === 1 && $scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 7") === 1, 'duplicate-base books both end with exactly one copy');
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE numero_inventario = 'DUP-C1'") === 1 && $scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id IN (6,7) AND numero_inventario LIKE 'LIB-%-C1'") === 1, 'collision resolved via the LIB-{id}-C{N} fallback, codes stay unique');

    // Book 8: empty inventory base falls back to LIB-{id}.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 8 AND numero_inventario IN ('LIB-8-C1','LIB-8-C2')") === 2, 'book 8: empty numero_inventario uses the LIB-{id} base');

    // Book 9: active future reservation-loan gets its copy too.
    $check($scalar("SELECT COUNT(*) FROM `{$tPrestiti}` p JOIN `{$tCopie}` c ON c.id = p.copia_id AND c.libro_id = 9 WHERE p.id = 5") === 1, 'book 9: active prenotato loan bound to the backfilled copy');

    // Book 10: regression for the former three-digit sequence cap.
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 10") === 2001, 'book 10: counters above 1,000 are fully materialised');
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 10 AND numero_inventario = 'LARGE-C2001'") === 1, 'book 10: high ordinal inventory code is generated');

    // Book 11: reservations remain book-level by design. The backfill creates
    // physical capacity but does not rewrite/delete the reservation; the same
    // canonical read formula still yields the legacy 2 total / 1 available.
    $book11Free = $scalar("SELECT GREATEST(
        (SELECT COUNT(*) FROM `{$tCopie}` c WHERE c.libro_id = 11 AND c.stato IN ('disponibile','prenotato'))
        - (SELECT COUNT(*) FROM `{$tPrenotazioni}` pr WHERE pr.libro_id = 11 AND pr.stato = 'attiva' AND pr.data_inizio_richiesta <= '2026-08-16' AND pr.data_fine_richiesta >= '2026-08-16'),
        0
    )");
    $check($scalar("SELECT COUNT(*) FROM `{$tCopie}` WHERE libro_id = 11") === 2 && $book11Free === 1, 'book 11: active reservation preserves legacy total and availability');
    $check($scalar("SELECT COUNT(*) FROM `{$tPrenotazioni}` WHERE id = 1 AND libro_id = 11 AND stato = 'attiva'") === 1, 'book 11: book-level reservation is untouched');

    // ── Idempotency: a second run must be a no-op ─────────────────────────
    $before = $db->query("SELECT id, libro_id, numero_inventario, stato FROM `{$tCopie}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $loansBefore = $db->query("SELECT id, copia_id, stato, attivo FROM `{$tPrestiti}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $reservationsBefore = $db->query("SELECT id, libro_id, stato FROM `{$tPrenotazioni}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $runMigration();
    $after = $db->query("SELECT id, libro_id, numero_inventario, stato FROM `{$tCopie}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $loansAfter = $db->query("SELECT id, copia_id, stato, attivo FROM `{$tPrestiti}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $reservationsAfter = $db->query("SELECT id, libro_id, stato FROM `{$tPrenotazioni}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $check($before === $after, 'idempotent: second run creates/renames no copies');
    $check($loansBefore === $loansAfter, 'idempotent: second run rebinds no loans');
    $check($reservationsAfter === $reservationsBefore, 'idempotent: reservations remain untouched');
} catch (Throwable $e) {
    $failed++;
    echo "  FAIL exception: {$e->getMessage()}\n";
} finally {
    $cleanup();
    $db->close();
}

echo "B. Updater wiring and ordering guarantees\n";
$mig = '0.7.61-rc.1';
$check(version_compare('0.7.61-rc.1', '0.7.61', '<'), 'version_compare orders the RC below its stable (prerelease semantics)');
$check(Updater::shouldRunMigration($mig, '0.7.59', '0.7.61-rc.1'), 'Updater runs it on 0.7.59 -> 0.7.61-rc.1 (RC upgrade)');
$check(Updater::shouldRunMigration($mig, '0.7.60', '0.7.61'), 'Updater runs it on 0.7.60 -> 0.7.61 stable (RC never installed)');
$check(!Updater::shouldRunMigration($mig, '0.7.61-rc.1', '0.7.61'), 'Updater does NOT re-run it on 0.7.61-rc.1 -> 0.7.61 (already applied; file is idempotent as a backstop)');

// Ordering guard: the availability recalc that derives the counters from
// `copie` must run AFTER runMigrations(), otherwise this backfill would read
// counters the recalc already zeroed. Assert the production call order.
$updaterSrc = (string) file_get_contents($root . '/app/Support/Updater.php');
$migrationsPos = strpos($updaterSrc, '$migrationResult = $this->runMigrations(');
$recalcPos = strpos($updaterSrc, '$this->recalculateAfterMigrations()');
$check($migrationsPos !== false && $recalcPos !== false && $migrationsPos < $recalcPos, 'Updater runs migrations BEFORE recalculateAfterMigrations (backfill precedes the zeroing recalc)');
$check(str_contains($updaterSrc, 'self::shouldRunMigration($migrationVersion, $fromVersion, $toVersion)'), 'Updater::runMigrations uses the tested production range predicate');

$transactionStart = strpos($migration, 'START TRANSACTION;');
$firstInsert = strpos($migration, 'INSERT IGNORE INTO `copie`');
$loanBinding = strpos($migration, 'UPDATE `prestiti` p');
$transactionCommit = strrpos($migration, 'COMMIT;');
$check(
    $transactionStart !== false && $firstInsert !== false && $loanBinding !== false && $transactionCommit !== false
        && $transactionStart < $firstInsert && $firstInsert < $loanBinding && $loanBinding < $transactionCommit,
    'backfill copies and loan binding are enclosed in one explicit transaction'
);
$check(
    substr_count($migration, '+ 1 <= @zz_max_deficit') === 2,
    'both copy-backfill passes cap their sequence domain at the real maximum deficit'
);
// Executable SQL only (strip full-line comments the way Updater::runMigrations
// does): the loan-to-copy pairing must avoid BOTH window functions (unsupported
// on the declared MySQL 5.7 floor) AND temporary tables (CREATE TEMPORARY TABLE
// inside a transaction is rejected under enforce_gtid_consistency=ON and needs
// the CREATE TEMPORARY TABLES privilege, often denied on shared hosting). Ranks
// come from materialised derived tables instead (paired on cr.rn = lr.rn).
$executableSql = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;
$check(
    stripos($executableSql, 'ROW_NUMBER(') === false
        && preg_match('/\bOVER\s*\(/i', $executableSql) !== 1
        && stripos($executableSql, 'CREATE TEMPORARY TABLE') === false
        && str_contains($executableSql, 'cr.rn = lr.rn'),
    'legacy loan-to-copy pairing uses derived-table ranks — no window functions (MySQL 5.7) and no temporary tables (GTID / shared-hosting privilege)'
);

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
