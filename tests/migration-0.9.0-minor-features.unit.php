<?php
declare(strict_types=1);
/**
 * Run the REAL "minor features" section of migrate_0.9.0-rc.1.sql against scoped
 * sandbox tables seeded with the 0.8.9 shape, twice: it must create presenze
 * and fornitori, add libri.fornitore_id (index + FK ON DELETE SET NULL) and
 * the three RDA override columns, keep existing rows untouched, and be a
 * no-op the second time.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));

$file = (string) file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.9.0-rc.1.sql');
$start = strpos($file, '-- ── minor features');
$end = strpos($file, '-- ── end minor features ──');
if ($start === false || $end === false || $end < $start) {
    throw new RuntimeException('FAIL the minor-features section markers are missing from migrate_0.9.0-rc.1.sql');
}
$section = substr($file, $start, $end - $start);

$sfx = '_zzm090_' . bin2hex(random_bytes(3));
$map = [];
foreach (['libri', 'utenti', 'sedi', 'presenze', 'fornitori', 'fk_libri_fornitore', 'fk_presenze_utente', 'fk_presenze_sede'] as $name) {
    $map[$name] = $name . $sfx;
}
$source = preg_replace('/^--.*$/m', '', $section) ?? '';
$source = preg_replace_callback('/\b(' . implode('|', array_map('preg_quote', array_keys($map))) . ')\b/', static fn (array $m): string => $map[$m[1]], $source) ?? '';
$run = static function () use ($db, $source): void {
    foreach (explode(";\n", $source) as $sql) {
        if (trim($sql) !== '') {
            if ($db->query($sql) === false) {
                throw new RuntimeException('FAIL statement errored: ' . $db->error . "\n" . $sql);
            }
        }
    }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $checks++; echo "OK {$label}\n";
};
$col = static function (string $table, string $column) use ($db): ?array {
    $stmt = $db->prepare('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
};
$tableExists = static fn (string $t): bool => (int) $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetch_row()[0] === 1;
$colOrder = static fn (string $t): string => (string) $db->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")->fetch_row()[0];

$L = $map['libri'];
$U = $map['utenti'];
$S = $map['sedi'];
$P = $map['presenze'];
$F = $map['fornitori'];
try {
    // 0.8.9 shape of the columns the section touches (plus neighbours for AFTER).
    $db->query("CREATE TABLE {$U} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100) NOT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE {$S} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE {$L} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, titolo VARCHAR(255) NOT NULL, tipo_media ENUM('libro','disco','audiolibro','dvd','altro') NOT NULL DEFAULT 'libro', peso FLOAT DEFAULT NULL, editore_id INT DEFAULT NULL, `source` VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$U} (id, nome) VALUES (1, 'Mario')");
    $db->query("INSERT INTO {$L} (id, titolo, tipo_media, editore_id) VALUES (1, 'Uno', 'libro', NULL), (2, 'Due', 'dvd', 7)");
    $check(!$tableExists($P) && !$tableExists($F) && $col($L, 'fornitore_id') === null, 'the pre-upgrade schema has no visitor log, suppliers or supplier column');

    $run();

    $check($tableExists($P) && $tableExists($F), 'presenze and fornitori are created');
    $f = $col($L, 'fornitore_id');
    $check($f !== null && $f['DATA_TYPE'] === 'int' && $f['IS_NULLABLE'] === 'YES', 'libri.fornitore_id is a nullable INT');
    foreach (['rda_content_type', 'rda_media_type', 'rda_carrier_type'] as $c) {
        $r = $col($L, $c);
        $check($r !== null && $r['DATA_TYPE'] === 'varchar' && (int) $r['CHARACTER_MAXIMUM_LENGTH'] === 10 && $r['IS_NULLABLE'] === 'YES', "libri.{$c} is a nullable VARCHAR(10)");
    }
    $check($colOrder($L) === 'id,titolo,tipo_media,rda_content_type,rda_media_type,rda_carrier_type,peso,editore_id,fornitore_id,source,deleted_at', 'new libri columns sit where schema.sql puts them');
    $fk = $db->query("SELECT DELETE_RULE, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '{$map['fk_libri_fornitore']}'")->fetch_assoc();
    $check(is_array($fk) && $fk['DELETE_RULE'] === 'SET NULL' && $fk['REFERENCED_TABLE_NAME'] === $F, 'libri.fornitore_id references fornitori ON DELETE SET NULL');
    $check($db->query("SELECT GROUP_CONCAT(CONCAT(id,':',titolo,':',tipo_media,':',IFNULL(editore_id,'-'),':',IFNULL(fornitore_id,'-'),':',IFNULL(rda_carrier_type,'-')) ORDER BY id) FROM {$L}")->fetch_row()[0] === '1:Uno:libro:-:-:-,2:Due:dvd:7:-:-', 'existing books are preserved, with no supplier and no RDA override (zero-config)');

    // Behaviour: the FK nulls the link, the visitor log keeps anonymous counts.
    $db->query("INSERT INTO {$F} (id, nome) VALUES (5, 'Libreria Rossi')");
    $db->query("UPDATE {$L} SET fornitore_id = 5, rda_carrier_type = 'cr' WHERE id = 2");
    $db->query("INSERT INTO {$P} (utente_id, tipo, created_at) VALUES (1, 'socio', '2026-10-01 10:00:00')");
    $db->query("INSERT INTO {$P} (tipo, nome_ospite) VALUES ('ospite', NULL)");
    $check($db->query("SELECT tipo FROM {$P} WHERE utente_id IS NULL")->fetch_row()[0] === 'ospite', 'a guest visit needs no user');
    $db->query("DELETE FROM {$F} WHERE id = 5");
    $check($db->query("SELECT fornitore_id FROM {$L} WHERE id = 2")->fetch_row()[0] === null, 'deleting a supplier sets the book link to NULL');
    $db->query("DELETE FROM {$U} WHERE id = 1");
    $check((int) $db->query("SELECT COUNT(*) FROM {$P} WHERE tipo = 'socio' AND utente_id IS NULL")->fetch_row()[0] === 1, 'deleting a member keeps the visit, anonymised');
    $check((string) $db->query("SELECT rda_carrier_type FROM {$L} WHERE id = 2")->fetch_row()[0] === 'cr', 'an RDA override is stored');

    $before = $colOrder($L) . '|' . $colOrder($P) . '|' . $colOrder($F);
    $run();
    $check($colOrder($L) . '|' . $colOrder($P) . '|' . $colOrder($F) === $before, 'a second run changes no column');
    $check((int) $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$L}' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")->fetch_row()[0] === 1, 'a second run adds no duplicate foreign key');
    $check((int) $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$L}' AND INDEX_NAME = 'idx_libri_fornitore'")->fetch_row()[0] === 1, 'a second run adds no duplicate index');
    $check((int) $db->query("SELECT COUNT(*) FROM {$P}")->fetch_row()[0] === 2 && (string) $db->query("SELECT rda_carrier_type FROM {$L} WHERE id = 2")->fetch_row()[0] === 'cr', 'a second run keeps the data written in between');

    $schema = (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
    $check(str_contains($schema, 'CREATE TABLE `presenze`') && str_contains($schema, 'CREATE TABLE `fornitori`')
        && str_contains($schema, '`fornitore_id` int DEFAULT NULL') && str_contains($schema, 'CONSTRAINT `fk_libri_fornitore`')
        && str_contains($schema, '`rda_content_type` varchar(10)') && str_contains($schema, '`rda_media_type` varchar(10)') && str_contains($schema, '`rda_carrier_type` varchar(10)'), 'fresh install (schema.sql) has the same tables and columns');
    $check(preg_match('/CONSTRAINT `fk_presenze_utente` FOREIGN KEY \(`utente_id`\) REFERENCES `utenti` \(`id`\) ON DELETE SET NULL\b/', $schema) === 1, 'fresh install (schema.sql) anonymises a visit on member deletion: fk_presenze_utente is ON DELETE SET NULL');
    $version = json_decode((string) file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.9.0-rc.1', $version, '<='), 'the release includes the migration (0.9.0 <= version.json)');
    echo "ALL {$checks} PASS\n";
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([$P, $L, $F, $S, $U] as $t) {
        $db->query("DROP TABLE IF EXISTS {$t}");
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}
