<?php
declare(strict_types=1);

/**
 * Runs the REAL fines-ledger section of migrate_0.9.0-rc.1.sql against a sandbox
 * database seeded with the 0.8.9 shape of utenti/prestiti, twice:
 *  - movimenti_lettore is created with its keys and foreign keys;
 *  - every prestiti.sanzione > 0 becomes exactly one ledger charge
 *    (perso → addebito_perdita, anything else → addebito_danno), key
 *    penalty:<loan id>; sanzione = 0 / NULL rows produce nothing;
 *  - a second run (and the PHP backfill) adds nothing; prestiti.sanzione is
 *    left untouched (it stays the mirror for emails and the activity log);
 *  - a penalty assessed after the upgrade is picked up by the maintenance
 *    backfill once, with the same key;
 *  - the stored loan_returned template of an upgraded install (the previous
 *    shipped default, in-code or installer seed) gains the {{multa}} line
 *    through SettingsRepository::ensureEmailTemplates(); a customised row is
 *    left alone.
 *
 * Sandbox: <DB_NAME>_migfines, dropped at the end (override with MIG_SANDBOX_DB).
 * Run: php tests/migration-0.9.0-fines-ledger.unit.php (exit code is the verdict)
 */

use App\Services\PatronLedger;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
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
// Socket when one is configured and present (local dev), TCP otherwise (CI).
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$useSocket = $socket !== '' && file_exists($socket);
$sandbox = getenv('MIG_SANDBOX_DB') ?: ((getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? 'pinakes')) . '_migfines');
try {
    $user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
    $pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
    $db = $useSocket
        ? new mysqli(null, $user, $pass, null, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pass, null, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $db->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($sandbox);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: sandbox database unavailable ({$sandbox}): {$e->getMessage()}\n");
    exit(1);
}

// The real section of the migration file, statement by statement (no ';' in its literals).
$sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0-rc.1.sql');
$start = strpos($sql, '-- ── fines-ledger');
$end = strpos($sql, '-- ── end fines-ledger ──');
$section = $start !== false && $end !== false ? substr($sql, $start, $end - $start) : '';
$section = (string) preg_replace('/^\s*--.*$/m', '', $section);
$runMigration = static function () use ($db, $section): void {
    foreach (explode(';', $section) as $stmt) {
        if (trim($stmt) !== '') {
            $db->query($stmt);
        }
    }
};
$scalar = static fn (string $q): string => (string) ($db->query($q)->fetch_row()[0] ?? '');

try {
    $check($section !== '' && str_contains($section, 'movimenti_lettore'), 'the migration has a delimited fines-ledger section');

    // 0.8.9 shape of the referenced tables (only the columns the migration and
    // the ledger read; copie.sede_id is the copy's branch for closed days).
    $db->query("CREATE TABLE utenti (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE copie (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, sede_id INT DEFAULT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE prestiti (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, utente_id INT NOT NULL, copia_id INT DEFAULT NULL,
        stato ENUM('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') DEFAULT 'pendente',
        data_scadenza DATE DEFAULT NULL, sanzione DECIMAL(10,2) DEFAULT '0.00', data_restituzione DATE DEFAULT NULL, processed_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_p_u FOREIGN KEY (utente_id) REFERENCES utenti (id)) ENGINE=InnoDB");
    $db->query("INSERT INTO utenti (id, email) VALUES (1,'a@x'),(2,'b@x'),(3,'staff@x')");
    $db->query("INSERT INTO prestiti (id, utente_id, stato, sanzione, data_restituzione, processed_by) VALUES
        (10, 1, 'perso',       25.00, '2026-05-02', 3),
        (11, 1, 'danneggiato', 12.50, '2026-06-01', NULL),
        (12, 2, 'restituito',   0.00, '2026-06-03', NULL),
        (13, 2, 'restituito',   NULL, '2026-06-04', NULL),
        (14, 2, 'restituito',   4.00, NULL, NULL)");

    $check($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === '0', 'pre-upgrade: no ledger table');
    // Code deployed before its migration: no debt predicate, no backfill "done".
    $check(!PatronLedger::schemaReady($db) && (new PatronLedger($db))->backfillLegacyPenalties() === null, 'pre-upgrade: the backfill reports the missing table (null), so maintenance keeps its marker unset');
    $fragment = \App\Support\LoanEligibility::eligibleUserWhere('u', $db);
    $check(!str_contains($fragment, 'movimenti_lettore') && str_contains($fragment, '1 = 1') && substr_count($fragment, '?') === 1, 'pre-upgrade: eligibleUserWhere() carries no debt predicate and still needs ONE placeholder');
    $runMigration();
    PatronLedger::resetSchemaCache();
    \App\Services\CirculationPolicy::resetSchemaCache();
    $check(PatronLedger::schemaReady($db), 'after the migration the ledger probe says ready');
    $fragment = \App\Support\LoanEligibility::eligibleUserWhere('u', $db);
    $check(str_contains($fragment, 'movimenti_lettore') && !str_contains($fragment, 'tipologie_lettore') && str_contains($fragment, 'debt_block_threshold'), 'with the ledger but no patron type tables the debt predicate reads the global threshold only');
    $check($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === '1', 'the migration creates movimenti_lettore');
    $check($scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore' AND INDEX_NAME = 'uniq_movimenti_chiave' AND NON_UNIQUE = 0") === '1', 'idempotency key is UNIQUE');
    $check($scalar("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore' AND INDEX_NAME = 'idx_movimenti_utente'") === 'utente_id,importo', 'covering index on (utente_id, importo)');
    $check((int) $scalar("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === 3, 'three foreign keys (utente, prestito, operatore)');

    $rows = $db->query("SELECT prestito_id, utente_id, tipo, importo, chiave_idempotenza, creato_da, DATE(created_at) AS d FROM movimenti_lettore ORDER BY prestito_id")->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 3, 'three loans had a sanzione > 0 → three movements');
    $byLoan = array_column($rows, null, 'prestito_id');
    $check(($byLoan[10]['tipo'] ?? '') === 'addebito_perdita' && ($byLoan[10]['importo'] ?? '') === '25.00' && ($byLoan[10]['chiave_idempotenza'] ?? '') === 'penalty:10', 'lost copy → addebito_perdita 25.00, key penalty:10');
    $check(($byLoan[11]['tipo'] ?? '') === 'addebito_danno' && ($byLoan[11]['importo'] ?? '') === '12.50', 'damaged copy → addebito_danno 12.50');
    $check(($byLoan[14]['tipo'] ?? '') === 'addebito_danno' && ($byLoan[14]['importo'] ?? '') === '4.00', 'other state with a sanzione → addebito_danno');
    $check(!isset($byLoan[12]) && !isset($byLoan[13]), 'sanzione 0 or NULL → no movement');
    $check((int) ($byLoan[10]['creato_da'] ?? 0) === 3 && ($byLoan[10]['d'] ?? '') === '2026-05-02', 'operator and date come from the loan');
    $check($scalar("SELECT SUM(importo) FROM movimenti_lettore WHERE utente_id = 1") === '37.50', 'balance of user 1 = 37.50');

    $runMigration();
    $check($scalar("SELECT COUNT(*) FROM movimenti_lettore") === '3', 'second run: still three movements (idempotent)');
    $check($scalar("SELECT GROUP_CONCAT(CONCAT(id, ':', IFNULL(sanzione, '-')) ORDER BY id) FROM prestiti") === '10:25.00,11:12.50,12:0.00,13:-,14:4.00', 'prestiti.sanzione is left untouched (mirror)');

    $ledger = new PatronLedger($db);
    $check($ledger->backfillLegacyPenalties() === 0, 'maintenance backfill after the migration: nothing to do');
    $db->query("INSERT INTO prestiti (id, utente_id, stato, sanzione, data_restituzione) VALUES (15, 2, 'perso', 9.90, '2026-07-01')");
    $check($ledger->backfillLegacyPenalties(1) === 1, 'a penalty the migration did not reach is converted by the backfill');
    $check($ledger->backfillLegacyPenalties() === 0 && $scalar("SELECT COUNT(*) FROM movimenti_lettore WHERE chiave_idempotenza = 'penalty:15'") === '1', '...exactly once');
    $check($ledger->chargeLossPenalty(15) === 0, 'the live path (same key) does not double it either');
    $db->query("INSERT INTO prestiti (id, utente_id, stato, sanzione, data_restituzione) VALUES (16, 2, 'perso', 1.00, '2026-07-02'), (17, 2, 'perso', 2.00, '2026-07-03')");
    $check($ledger->backfillLegacyPenalties(1, 1) === null, 'a backfill stopped by its batch limit with rows left reports "not done" (null), so maintenance keeps its marker unset');
    $check($ledger->backfillLegacyPenalties(1, 5) === 1, '...and the next pass converts the rest and reports done');

    echo "Email templates: {{multa}} reaches a stored loan_returned default (SettingsRepository::ensureEmailTemplates)\n";
    $repo = new \App\Models\SettingsRepository($db);
    $repo->ensureTables();
    $defaults = static function (string $locale): array {
        $out = [];
        foreach (\App\Support\SettingsMailTemplates::all($locale) as $name => $meta) {
            $out[$name] = ['subject' => $meta['subject'], 'body' => \App\Support\EmailLayout::normalizeContent((string) $meta['body']), 'description' => $meta['description'] ?? null];
        }
        return $out;
    };
    $seedRow = static function (string $locale, string $body, string $subject = 'Old subject') use ($db): void {
        $stmt = $db->prepare("INSERT INTO email_templates (name, locale, subject, body, description, active) VALUES ('loan_returned', ?, ?, ?, 'seeded', 1)
                              ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), description = VALUES(description), active = 1");
        $stmt->bind_param('sss', $locale, $subject, $body);
        $stmt->execute();
        $stmt->close();
    };
    // The body of the installer seed of $locale (its SQL escapes decoded).
    $installerSeed = static function (string $locale) use ($root): string {
        $sql = (string) file_get_contents($root . '/installer/database/data_' . $locale . '.sql');
        if (preg_match("/INSERT (?:IGNORE )?INTO `email_templates` VALUES \\(\\d+,'loan_returned','" . $locale . "','(?:[^'\\\\]|\\\\.|'')*','((?:[^'\\\\]|\\\\.|'')*)'/", $sql, $m) !== 1) {
            return '';
        }
        $body = str_replace("''", "'", $m[1]);
        return (string) preg_replace_callback('/\\\\(.)/', static fn (array $x): string => $x[1] === 'n' ? "\n" : $x[1], $body);
    };
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
        $current = (string) $defaults($locale)['loan_returned']['body'];
        $retired = \App\Support\SettingsMailTemplates::retiredBodies('loan_returned', $locale);
        $check($retired !== [] && str_contains($current, '{{multa}}') && !str_contains(implode('', $retired), '{{multa}}'), "{$locale}: the current default carries {{multa}}, the retired bodies do not");
        $seed = $installerSeed($locale);
        $check($seed !== '' && in_array(trim(str_replace("<p>{{multa}}</p>\n", '', $seed)), array_map('trim', $retired), true), "{$locale}: the installer seed without its {{multa}} line is a retired body (what a pre-0.9.0 install stored)");
        foreach ($retired as $i => $old) {
            $seedRow($locale, $old);
            $repo->ensureEmailTemplates($defaults($locale), $locale);
            $row = $repo->getEmailTemplate('loan_returned', $locale);
            $check(is_array($row) && trim((string) $row['body']) === trim($current) && str_contains((string) $row['body'], '{{multa}}'), "{$locale}: stored retired body #{$i} is moved to the current default");
            $check(is_array($row) && (string) $row['subject'] === 'Old subject' && (string) $row['description'] === 'seeded', "{$locale}: subject and description of the row are kept");
            $repo->ensureEmailTemplates($defaults($locale), $locale);
            $again = $repo->getEmailTemplate('loan_returned', $locale);
            $check(is_array($again) && (string) $again['body'] === (string) $row['body'], "{$locale}: a second pass changes nothing (idempotent)");
        }
        if ($locale !== 'it_IT') {
            // A non-Italian install whose row still holds the Italian text an
            // older release seeded (no {{multa}} line, Italian subject): it is
            // translated to the current localized default, subject included.
            $italian = \App\Support\SettingsMailTemplates::get('loan_returned', 'it_IT');
            $seedRow($locale, (string) \App\Support\SettingsMailTemplates::retiredBodies('loan_returned', 'it_IT')[0], (string) $italian['subject']);
            $repo->ensureEmailTemplates($defaults($locale), $locale);
            $row = $repo->getEmailTemplate('loan_returned', $locale);
            $check(is_array($row) && trim((string) $row['body']) === trim($current) && (string) $row['subject'] === (string) $defaults($locale)['loan_returned']['subject'], "{$locale}: a row still in the pre-0.9.0 Italian text gets the localized default and subject");
        }
        $custom = str_replace('</h2>', ' (customised)</h2>', (string) $retired[0]);
        $seedRow($locale, $custom, 'My subject');
        $repo->ensureEmailTemplates($defaults($locale), $locale);
        $row = $repo->getEmailTemplate('loan_returned', $locale);
        $check(is_array($row) && (string) $row['body'] === $custom && (string) $row['subject'] === 'My subject', "{$locale}: a customised body (one word away from the old default) is left alone");
        $check(is_array($row) && !str_contains((string) $row['body'], '{{multa}}'), "{$locale}: ...and gets no {{multa}} added behind the administrator's back");
    }
    $check((int) $scalar("SELECT COUNT(*) FROM email_templates WHERE name = 'loan_returned'") === 5, 'one loan_returned row per locale after the passes');

    $schema = (string) file_get_contents($root . '/installer/database/schema.sql');
    $check(str_contains($schema, 'CREATE TABLE `movimenti_lettore`') && str_contains($schema, 'UNIQUE KEY `uniq_movimenti_chiave` (`chiave_idempotenza`)'), 'fresh install (schema.sql) has the same table');
    $version = (string) (json_decode((string) file_get_contents($root . '/version.json'), true)['version'] ?? '');
    $check(version_compare('0.9.0-rc.1', $version, '<='), 'migration version 0.9.0 <= version.json (' . $version . ')');
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}

echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
