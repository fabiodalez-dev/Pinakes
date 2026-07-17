<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.37-rc.1.sql (issue #255 — configurable
 * registration fields) plus the RegistrationFields validation contract.
 *
 *   A. Run the REAL migration file against sandbox-renamed tables and assert
 *      the effect: both tables exist with the expected columns / PK / FKs,
 *      and a second run is a no-op (idempotency).
 *   B. FK semantics: deleting a field definition cascades its values;
 *      deleting a user cascades their values.
 *   C. RegistrationFields::validate() contract (pure): required/optional,
 *      type rules (email/url/number/checkbox), length cap.
 *
 * Run:  php tests/migration-0.7.37.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\RegistrationFields;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

// ── C first: pure validation contract (no DB needed) ────────────────────────
echo "C. RegistrationFields::validate() contract\n";

$defs = [
    ['id' => 1, 'etichetta' => 'Telegram', 'tipo' => 'text', 'obbligatorio' => true],
    ['id' => 2, 'etichetta' => 'Sito', 'tipo' => 'url', 'obbligatorio' => false],
    ['id' => 3, 'etichetta' => 'Newsletter', 'tipo' => 'checkbox', 'obbligatorio' => false],
    ['id' => 4, 'etichetta' => 'Anno', 'tipo' => 'number', 'obbligatorio' => false],
    ['id' => 5, 'etichetta' => 'Contatto', 'tipo' => 'email', 'obbligatorio' => false],
];

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => '@mario', 2 => '', 4 => '', 5 => '']]);
$check($r['error'] === null && $r['values'][1] === '@mario' && $r['values'][2] === '', 'required text present + optional empties accepted');

$r = RegistrationFields::validate($defs, ['custom_field' => [2 => 'https://x.y']]);
$check($r['error'] !== null, 'missing required field rejected');

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 2 => 'javascript:alert(1)']]);
$check($r['error'] !== null, 'non-http(s) URL rejected');

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 4 => 'abc']]);
$check($r['error'] !== null, 'non-numeric number rejected');

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 5 => 'not-an-email']]);
$check($r['error'] !== null, 'invalid email rejected');

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 3 => 'on']]);
$check($r['error'] === null && $r['values'][3] === '1', 'checkbox normalises to 1');

$r = RegistrationFields::validate($defs, ['custom_field' => [1 => str_repeat('a', 1001)]]);
$check($r['error'] !== null, 'over-long value rejected');

// error_reason distinguishes 'missing' from 'format' (issue #255 review fix #3)
$r = RegistrationFields::validate($defs, ['custom_field' => [2 => 'https://x.y']]);
$check($r['error_reason'] === 'missing', 'missing required reports reason=missing');
$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 5 => 'not-an-email']]);
$check($r['error_reason'] === 'format', 'invalid value reports reason=format');

// enforceRequired=false (profile self-edit): a blank required field is
// tolerated so a field made required AFTER signup can't wall an existing user
// out of unrelated edits — but format checks still apply (review fix #2).
$r = RegistrationFields::validate($defs, ['custom_field' => [1 => '', 2 => '']], false);
$check($r['error'] === null, 'enforceRequired=false tolerates a blank required field');
$r = RegistrationFields::validate($defs, ['custom_field' => [1 => 'x', 5 => 'bad-email']], false);
$check($r['error_reason'] === 'format', 'enforceRequired=false still rejects a bad format');

// full_name() collapses the trailing space for a surname-less member (fix #1)
require_once dirname(__DIR__) . '/app/helpers.php';
$check(full_name('Mario', '') === 'Mario', "full_name('Mario','') === 'Mario' (no trailing space)");
$check(full_name('Mario', 'Rossi') === 'Mario Rossi', "full_name joins name + surname");
$check(full_name(null, null) === '', 'full_name(null,null) === ""');

// ── DB sections ──────────────────────────────────────────────────────────────
echo "A. Real migration against sandbox tables\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    // A migration test that silently skips its DB sections is a false green in
    // CI (both ci-quality and ci-e2e provision MySQL). Fail loudly instead.
    fwrite(STDERR, "FAIL: database unreachable — the migration sections are mandatory: {$e->getMessage()}\n");
    exit(1);
}

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.37-rc.1.sql');

// Retarget the REAL migration at sandbox table names (project pattern: same
// DDL, only the names rewritten) so the test never collides with live tables.
$sandbox = static fn (string $sql): string => str_replace(
    ['`registrazione_campi`', '`utenti_campi_valori`', '`utenti_campi_valori_utente_fk`', '`utenti_campi_valori_campo_fk`', '`idx_ucv_campo`'],
    ['`zz_mig_registrazione_campi`', '`zz_mig_utenti_campi_valori`', '`zz_mig_ucv_utente_fk`', '`zz_mig_ucv_campo_fk`', '`zz_mig_idx_ucv_campo`'],
    $sql
);

$runMigration = static function () use ($db, $migration, $sandbox): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandbox(preg_replace('/^--.*$/m', '', $migration) ?? $migration)))) as $statement) {
        if ($statement !== '') {
            $db->query($statement);
        }
    }
};

$cleanup = static function () use ($db): void {
    $db->query('DROP TABLE IF EXISTS zz_mig_utenti_campi_valori');
    $db->query('DROP TABLE IF EXISTS zz_mig_registrazione_campi');
    $db->query("DELETE FROM utenti WHERE email LIKE 'zz-mig-237-%@example.test'");
};

try {
    $cleanup();
    $runMigration();

    $tables = static function (string $name) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    };
    $check($tables('zz_mig_registrazione_campi') && $tables('zz_mig_utenti_campi_valori'), 'migration creates both tables');

    $pk = $db->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.key_column_usage
          WHERE table_schema = DATABASE() AND table_name = 'zz_mig_utenti_campi_valori' AND constraint_name = 'PRIMARY'"
    )->fetch_row()[0] ?? '';
    $check($pk === 'utente_id,campo_id', 'values PK is (utente_id, campo_id)');

    $fkCount = (int) ($db->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE table_schema = DATABASE() AND table_name = 'zz_mig_utenti_campi_valori' AND constraint_type = 'FOREIGN KEY'"
    )->fetch_row()[0] ?? 0);
    $check($fkCount === 2, 'values table carries both FKs');

    // Idempotency: a second run must not error and must not duplicate anything.
    $runMigration();
    $check(true, 'second migration run is a no-op (no error)');

    echo "B. FK cascade semantics\n";
    $db->query("INSERT INTO utenti (nome, cognome, email, password, codice_tessera) VALUES ('ZZ', '', 'zz-mig-237-1@example.test', 'x', CONCAT('TZZ', FLOOR(RAND()*1000000)))");
    $uid = (int) $db->insert_id;
    $db->query("INSERT INTO zz_mig_registrazione_campi (etichetta, tipo) VALUES ('Telegram', 'text')");
    $fid = (int) $db->insert_id;
    $db->query("INSERT INTO zz_mig_utenti_campi_valori (utente_id, campo_id, valore) VALUES ({$uid}, {$fid}, '@zz')");

    $db->query("DELETE FROM zz_mig_registrazione_campi WHERE id = {$fid}");
    $left = (int) ($db->query("SELECT COUNT(*) FROM zz_mig_utenti_campi_valori WHERE campo_id = {$fid}")->fetch_row()[0] ?? -1);
    $check($left === 0, 'deleting a field definition cascades its values');

    $db->query("INSERT INTO zz_mig_registrazione_campi (etichetta, tipo) VALUES ('Sito', 'url')");
    $fid2 = (int) $db->insert_id;
    $db->query("INSERT INTO zz_mig_utenti_campi_valori (utente_id, campo_id, valore) VALUES ({$uid}, {$fid2}, 'https://x.y')");
    $db->query("DELETE FROM utenti WHERE id = {$uid}");
    $left = (int) ($db->query("SELECT COUNT(*) FROM zz_mig_utenti_campi_valori WHERE utente_id = {$uid}")->fetch_row()[0] ?? -1);
    $check($left === 0, 'deleting a user cascades their values');

    // Optional-surname contract: the utenti schema accepts '' for cognome
    // (kept NOT NULL by design; see migration header comment).
    $db->query("INSERT INTO utenti (nome, cognome, email, password, codice_tessera) VALUES ('SoloNome', '', 'zz-mig-237-2@example.test', 'x', CONCAT('TZY', FLOOR(RAND()*1000000)))");
    $uid2 = (int) $db->insert_id;
    $stored = $db->query("SELECT cognome FROM utenti WHERE id = {$uid2}")->fetch_row()[0] ?? null;
    $check($stored === '', "utenti accepts an empty surname (stored as '')");
} finally {
    $cleanup();
    $db->close();
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
