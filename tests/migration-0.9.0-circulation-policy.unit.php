<?php
declare(strict_types=1);

/**
 * Run the REAL migrate_0.9.0.sql (circulation policy section) against a
 * sandbox seeded with the 0.8.9 shape of `utenti` and `copie`, twice.
 *
 * Every identifier the migration touches is renamed to a run-scoped sandbox
 * name (tables and the foreign key, whose name is database-global), so the
 * real tables are never altered.
 *
 * Run: php tests/migration-0.9.0-circulation-policy.unit.php (or the tests/migration-0.9.0.unit.php dispatcher)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
try {
    $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(3));
$names = [
    'tipologie_lettore' => "zz_m090_tl_{$run}",
    'regole_prestito' => "zz_m090_rp_{$run}",
    'utenti' => "zz_m090_u_{$run}",
    'copie' => "zz_m090_c_{$run}",
    'fk_utenti_tipologia' => "zz_m090_fk_{$run}",
];
$file = dirname(__DIR__) . '/installer/database/migrations/migrate_0.9.0.sql';
$source = (string) file_get_contents($file);
// Only the circulation section: other features add their own sections.
if (!preg_match('/-- ── Circulation policy:.*?-- ── end Circulation policy ──/s', $source, $m)) {
    fwrite(STDERR, "FAIL: circulation section not found in migrate_0.9.0.sql\n");
    exit(1);
}
$section = (string) preg_replace('/^--.*$/m', '', $m[0]);
foreach ($names as $from => $to) {
    $section = (string) preg_replace('/\b' . preg_quote($from, '/') . '\b/', $to, $section);
}
$migrate = static function () use ($db, $section): void {
    foreach (explode(';', $section) as $sql) {
        if (trim($sql) !== '') {
            $db->query($sql);
        }
    }
};

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$scalar = static fn (string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$column = static function (string $table, string $col) use ($db): ?array {
    $r = $db->query("SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'");
    return $r->fetch_assoc();
};
[$tl, $rp, $u, $c, $fk] = array_values($names);

try {
    // 0.8.9 shape of the columns involved, with existing rows.
    $db->query("CREATE TABLE {$u} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, tipo_utente ENUM('standard','premium','staff','admin') DEFAULT 'standard', data_scadenza_tessera DATE DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE {$c} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, libro_id INT NOT NULL, stato VARCHAR(20) NOT NULL DEFAULT 'disponibile') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$u} (email, tipo_utente, data_scadenza_tessera) VALUES ('a@x', 'standard', '2027-01-01'), ('b@x', 'premium', NULL), ('c@x', 'admin', NULL)");
    $db->query("INSERT INTO {$c} (libro_id, stato) VALUES (1, 'disponibile'), (1, 'prestato')");
    $check($column($u, 'tipologia_id') === null && $column($c, 'solo_consultazione') === null, 'the pre-upgrade tables have no circulation columns');

    $migrate();

    $check((int) $scalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('{$tl}', '{$rp}')") === 2, 'patron types and rules tables are created');
    $check((int) $scalar("SELECT COUNT(*) FROM {$tl}") === 1, 'exactly one patron type is seeded');
    $check($scalar("SELECT CONCAT(codice, '|', nome, '|', predefinita, '|', attiva, '|', prenotazioni_abilitate) FROM {$tl}") === 'standard|Standard|1|1|1', 'the seeded type is the active default "Standard" with reservations allowed');
    $check($scalar("SELECT CONCAT_WS(',', max_prestiti, durata_prestito_giorni, max_durata_richiesta_giorni, max_rinnovi, giorni_rinnovo, max_prenotazioni, durata_tessera_giorni, multa_giornaliera, giorni_tolleranza, multa_massima_prestito, soglia_blocco_debito) FROM {$tl}") === '', 'every rule of the seeded type is NULL: it inherits the global settings');
    $col = $column($u, 'tipologia_id');
    $check(is_array($col) && $col['DATA_TYPE'] === 'int' && $col['IS_NULLABLE'] === 'YES' && ($col['COLUMN_DEFAULT'] === null || $col['COLUMN_DEFAULT'] === 'NULL'), 'utenti.tipologia_id is a nullable INT defaulting to NULL');
    $check($scalar("SELECT GROUP_CONCAT(CONCAT(email, ':', tipo_utente, ':', IFNULL(data_scadenza_tessera, '-'), ':', IFNULL(tipologia_id, '-')) ORDER BY id) FROM {$u}") === 'a@x:standard:2027-01-01:-,b@x:premium:-:-,c@x:admin:-:-', 'no existing user is touched (premium is NOT migrated to a type)');
    $check((int) $scalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '{$fk}' AND REFERENCED_TABLE_NAME = '{$tl}' AND DELETE_RULE = 'SET NULL'") === 1, 'tipologia_id references the types with ON DELETE SET NULL');
    $check((int) $scalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$u}' AND INDEX_NAME = 'idx_utenti_tipologia'") === 1, 'tipologia_id is indexed');
    $col = $column($u, 'card_expiry_warning_sent_for');
    $check(is_array($col) && $col['DATA_TYPE'] === 'date' && $col['IS_NULLABLE'] === 'YES' && ($col['COLUMN_DEFAULT'] === null || $col['COLUMN_DEFAULT'] === 'NULL'), 'utenti.card_expiry_warning_sent_for is a nullable DATE: nobody counts as warned');
    $col = $column($c, 'solo_consultazione');
    $check(is_array($col) && $col['IS_NULLABLE'] === 'NO' && trim((string) $col['COLUMN_DEFAULT'], "'") === '0', 'copie.solo_consultazione is NOT NULL DEFAULT 0');
    $check($scalar("SELECT GROUP_CONCAT(CONCAT(id, ':', stato, ':', solo_consultazione) ORDER BY id) FROM {$c}") === '1:disponibile:0,2:prestato:0', 'existing copies stay lendable');
    $check((int) $scalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$rp}' AND INDEX_NAME = 'uniq_regole_prestito_tipologia_media' AND NON_UNIQUE = 0") === 2, 'rules are UNIQUE on (tipologia_id, tipo_media)');
    $db->query("INSERT INTO {$rp} (tipologia_id, tipo_media, max_prestiti) VALUES (0, 'dvd', 1)");
    $duplicate = false;
    try {
        $db->query("INSERT INTO {$rp} (tipologia_id, tipo_media, max_prestiti) VALUES (0, 'dvd', 2)");
    } catch (mysqli_sql_exception) {
        $duplicate = true;
    }
    $check($duplicate, 'the sentinels make the "all types × dvd" rule unique (no NULL in the key)');
    $check($scalar("SELECT CONCAT(tipologia_id, '|', tipo_media, '|', IFNULL(prestabile, 'inherit')) FROM {$rp}") === '0|dvd|inherit', 'rule sentinels and an inheriting prestabile default');

    // A second run is a no-op that keeps what the admin changed.
    $db->query("UPDATE {$tl} SET nome = 'Adulti', max_prestiti = 4");
    $db->query("INSERT INTO {$tl} (codice, nome) VALUES ('studente', 'Studente')");
    $studente = (int) $scalar("SELECT id FROM {$tl} WHERE codice = 'studente'");
    $db->query("UPDATE {$u} SET tipologia_id = {$studente} WHERE email = 'a@x'");
    $db->query("UPDATE {$c} SET solo_consultazione = 1 WHERE id = 2");
    $migrate();
    $check((int) $scalar("SELECT COUNT(*) FROM {$tl}") === 2 && (int) $scalar("SELECT COUNT(*) FROM {$tl} WHERE predefinita = 1") === 1, 'a second run seeds nothing: one default type');
    $check($scalar("SELECT CONCAT(nome, '|', max_prestiti) FROM {$tl} WHERE predefinita = 1") === 'Adulti|4', 'a second run keeps the edited default type');
    $check((int) $scalar("SELECT tipologia_id FROM {$u} WHERE email = 'a@x'") === $studente && (int) $scalar("SELECT solo_consultazione FROM {$c} WHERE id = 2") === 1, 'a second run keeps assignments and copy flags');
    $check((int) $scalar("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '{$fk}'") === 1, 'a second run adds no second foreign key');

    // Deleting a type sends its patrons back to the default one (NULL).
    $db->query("DELETE FROM {$tl} WHERE id = {$studente}");
    $check($scalar("SELECT IFNULL(tipologia_id, 'NULL') FROM {$u} WHERE email = 'a@x'") === 'NULL', 'deleting a type sets its patrons to NULL (= default type)');

    $schema = (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
    $check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `tipologie_lettore`') && str_contains($schema, 'CREATE TABLE IF NOT EXISTS `regole_prestito`'), 'fresh install creates both tables');
    $check(str_contains($schema, '`tipologia_id` int DEFAULT NULL') && str_contains($schema, 'CONSTRAINT `fk_utenti_tipologia` FOREIGN KEY (`tipologia_id`) REFERENCES `tipologie_lettore` (`id`) ON DELETE SET NULL'), 'fresh install has utenti.tipologia_id with its foreign key');
    $check(str_contains($schema, "`solo_consultazione` tinyint(1) NOT NULL DEFAULT '0'"), 'fresh install has copie.solo_consultazione');
    $check(str_contains($schema, '`card_expiry_warning_sent_for` date DEFAULT NULL'), 'fresh install has utenti.card_expiry_warning_sent_for');
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
        $data = (string) file_get_contents(dirname(__DIR__) . "/installer/database/data_{$locale}.sql");
        $check(str_contains($data, "INSERT INTO `tipologie_lettore` (`codice`, `nome`, `predefinita`, `ordinamento`) VALUES ('standard', 'Standard', 1, 0);"), "fresh {$locale} install seeds the default type");
    }
    $version = json_decode((string) file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', (string) $version, '<='), 'the release includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
    exit(1);
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([$u, $c, $rp, $tl] as $table) {
        $db->query("DROP TABLE IF EXISTS {$table}");
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
