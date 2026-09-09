<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca 1.4.0 schema migration against
 * the REAL dev DB (issue #140 review follow-up).
 *
 * Covers:
 *   1. ensureSchema() reports no failures and creates the NEW table
 *      emeroteca_abbonamenti (declared in expectedTables());
 *   2. every 1.4.0 column exists with the exact expected type/nullability/
 *      default (testate identifiers + gestione, annate serie/collocazione/
 *      consistenza_dichiarata/updated_at, fascicoli barcode/condizione/
 *      acquisizione/prezzo/reclami);
 *   3. the new plain KEYs (issn, barcode_base, barcode, numero_inventario)
 *      and the abbonamenti indexes exist;
 *   4. every expectedForeignKeys() entry is real, including the NEW
 *      conditional annate.collocazione_id → mensole (ON DELETE SET NULL)
 *      and the abbonamenti CASCADE;
 *   5. PHP constants are exact twins of the ENUMs (STATI_FASCICOLO,
 *      COND_FASCICOLO, TIPI_ACQUISIZIONE, OPZIONI_PRESTABILE), order
 *      included;
 *   6. double execution is idempotent (no failures, identical column and
 *      index inventory);
 *   7. REAL legacy normalization: stato is ALTERed back to the widened
 *      legacy ENUM, old-style rows are seeded (stato='danneggiato' /
 *      'in_restauro'), then ensureSchema() runs the REAL split — rows
 *      become stato='posseduto' with condizione populated (COALESCE keeps
 *      a pre-existing condizione), and the ENUM is restricted to the
 *      final member list;
 *   8. REAL volume NULL → '' migration, including the UNIQUE(testata_id,
 *      anno, volume) collision cases (multiple NULLs in one group, NULL
 *      next to an existing '' row);
 *   9. consistenzaTestata(): condizione does not hide owned issues,
 *      'scartato' is neither owned nor a lacuna, consistenza_dichiarata
 *      is appended (and shown alone without computed holdings).
 *
 * Conventions follow tests/emeroteca.unit.php (env parsing, DB connection,
 * check()/pass() helpers, FK-ordered cleanup) — but this suite FAILS HARD
 * (exit 1) when the DB is unreachable: it exists to prove the migration.
 */

require __DIR__ . '/../vendor/autoload.php';

function emu140_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = emu140_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? @new mysqli(null, $user, $pass, $name, 0, $socket)
        : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database not reachable (" . $e->getMessage() . ") — this suite requires the real DB\n");
    exit(1);
}
if (!isset($db) || $db->connect_errno !== 0) {
    $error = isset($db) ? $db->connect_error : 'connection failed';
    fwrite(STDERR, "FAIL: database not reachable ({$error}) — this suite requires the real DB\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

/** @return array{type:string,nullable:string,default:?string,extra:string}|null */
$columnInfo = static function (string $table, string $column) use ($db): ?array {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }
    return [
        'type'     => strtolower((string) $row['COLUMN_TYPE']),
        'nullable' => strtoupper((string) $row['IS_NULLABLE']),
        'default'  => $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
        'extra'    => strtolower((string) $row['EXTRA']),
    ];
};

/** Parse "enum('a','b')" into ['a','b'] (order preserved). */
$enumMembers = static function (string $columnType): array {
    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $m) === false) {
        return [];
    }
    return $m[1];
};

$indexColumns = static function (string $table, string $index) use ($db): string {
    $stmt = $db->prepare(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    if ($stmt === false) {
        return '';
    }
    $stmt->bind_param('ss', $table, $index);
    if (!$stmt->execute()) {
        $stmt->close();
        return '';
    }
    $res = $stmt->get_result();
    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? (string) ($row['cols'] ?? '') : '';
};

$fkDeleteRule = static function (string $table, string $column, string $refTable) use ($db): string {
    $stmt = $db->prepare(
        'SELECT rc.DELETE_RULE
           FROM information_schema.KEY_COLUMN_USAGE kcu
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.TABLE_NAME = ?
            AND kcu.COLUMN_NAME = ? AND kcu.REFERENCED_TABLE_NAME = ?'
    );
    if ($stmt === false) {
        return '';
    }
    $stmt->bind_param('sss', $table, $column, $refTable);
    if (!$stmt->execute()) {
        $stmt->close();
        return '';
    }
    $res = $stmt->get_result();
    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? strtoupper((string) ($row['DELETE_RULE'] ?? '')) : '';
};

$tableExists = static function (string $t) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "'"
    )->num_rows;
};

/** Full column+index inventory of the emeroteca tables, for idempotency diffing. */
$schemaInventory = static function () use ($db): string {
    $out = [];
    $res = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'emeroteca\\_%'
          ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $out[] = implode('|', $row);
    }
    $res = $db->query(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'emeroteca\\_%'
          GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
          ORDER BY TABLE_NAME, INDEX_NAME"
    );
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $out[] = implode('|', $row);
    }
    return implode("\n", $out);
};

$pluginDir = __DIR__ . '/../storage/plugins/emeroteca';
require_once $pluginDir . '/EmerotecaPlugin.php';

$hm = new \App\Support\HookManager($db);
$plugin = new EmerotecaPlugin($db, $hm);

// Unique fixture marker so cleanup never touches pre-existing data.
$RUN = 'emu140-' . bin2hex(random_bytes(4));
$TITLE_LEGACY  = "Emeroteca140 Legacy {$RUN}";
$TITLE_VOLUME  = "Emeroteca140 Volume {$RUN}";
$TITLE_CONS    = "Emeroteca140 Consistenza {$RUN}";
$TITLE_DECL    = "Emeroteca140 Dichiarata {$RUN}";

/**
 * FK-ordered cleanup of every fixture testata (articoli → fascicoli →
 * abbonamenti → annate → testate). Safe when tables/columns are missing.
 */
$cleanup = static function () use ($db, $TITLE_LEGACY, $TITLE_VOLUME, $TITLE_CONS, $TITLE_DECL): void {
    $titles = implode(',', array_map(
        static fn (string $t): string => "'" . $db->real_escape_string($t) . "'",
        [$TITLE_LEGACY, $TITLE_VOLUME, $TITLE_CONS, $TITLE_DECL]
    ));
    @$db->query(
        "DELETE ar FROM emeroteca_articoli ar
           JOIN emeroteca_fascicoli f ON ar.fascicolo_id = f.id
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE f FROM emeroteca_fascicoli f
           JOIN emeroteca_annate a ON f.annata_id = a.id
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE ab FROM emeroteca_abbonamenti ab
           JOIN emeroteca_testate t ON ab.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query(
        "DELETE a FROM emeroteca_annate a
           JOIN emeroteca_testate t ON a.testata_id = t.id
          WHERE t.titolo IN ({$titles})"
    );
    @$db->query("DELETE FROM emeroteca_testate WHERE titolo IN ({$titles})");
};

$insertTestata = static function (string $titolo) use ($db): int {
    $stmt = $db->prepare("INSERT INTO emeroteca_testate (titolo, tipo) VALUES (?, 'rivista')");
    if ($stmt === false) {
        throw new \RuntimeException('testata insert prepare failed: ' . $db->error);
    }
    $stmt->bind_param('s', $titolo);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('testata insert failed: ' . $err);
    }
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$insertAnnata = static function (int $testataId, int $anno, ?string $volume) use ($db): int {
    $stmt = $db->prepare('INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES (?, ?, ?)');
    if ($stmt === false) {
        throw new \RuntimeException('annata insert prepare failed: ' . $db->error);
    }
    $stmt->bind_param('iis', $testataId, $anno, $volume);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('annata insert failed: ' . $err);
    }
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$insertFascicolo = static function (int $annataId, string $numero, string $stato, ?string $condizione = null) use ($db): int {
    $stmt = $db->prepare('INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, condizione) VALUES (?, ?, ?, ?)');
    if ($stmt === false) {
        throw new \RuntimeException('fascicolo insert prepare failed: ' . $db->error);
    }
    $stmt->bind_param('isss', $annataId, $numero, $stato, $condizione);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('fascicolo insert failed: ' . $err);
    }
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$FINAL_STATO_ENUM = "enum('posseduto','mancante','atteso','smarrito','reclamato','scartato')";
$ACQ_ENUM         = "enum('abbonamento','acquisto','dono','scambio','deposito')";

try {
    // ── 1. ensureSchema: no failures, new table present ───────────────
    $result = $plugin->ensureSchema();
    check(($result['failed'] ?? ['x']) === [], 'ensureSchema() reports no failed tables ('
        . implode(',', $result['failed'] ?? []) . ')');

    $expectedTables = $plugin->expectedTables();
    check(
        in_array('emeroteca_abbonamenti', $expectedTables, true) && count($expectedTables) === 5,
        'expectedTables() declares 5 tables including emeroteca_abbonamenti'
    );
    foreach ($expectedTables as $t) {
        check($tableExists((string) $t), "table {$t} exists after ensureSchema()");
    }

    // ── 2. every self-heal sentinel column exists ─────────────────────
    foreach ($plugin->expectedColumns() as $column) {
        $info = $columnInfo((string) $column['table'], (string) $column['column']);
        check($info !== null, "sentinel column {$column['table']}.{$column['column']} exists");
    }

    // ── 2b. exact types / nullability / defaults of the 1.4.0 columns ─
    $columnSpecs = [
        // table, column, type, nullable, default
        ['emeroteca_testate', 'e_issn',                  'varchar(9)',   'YES', null],
        ['emeroteca_testate', 'issn_l',                  'varchar(9)',   'YES', null],
        ['emeroteca_testate', 'barcode_base',            'varchar(13)',  'YES', null],
        ['emeroteca_testate', 'direttore_responsabile',  'varchar(255)', 'YES', null],
        ['emeroteca_testate', 'registrazione_tribunale', 'varchar(255)', 'YES', null],
        ['emeroteca_testate', 'prezzo_copertina',        'decimal(8,2)', 'YES', null],
        ['emeroteca_testate', 'acquisizione_default',    $ACQ_ENUM,      'YES', null],
        ['emeroteca_testate', 'prestabile', "enum('escluso','consultazione','prestabile')", 'NO', 'consultazione'],
        ['emeroteca_annate',  'serie',                   'varchar(50)',  'YES', null],
        ['emeroteca_annate',  'collocazione_id',         'int',          'YES', null],
        ['emeroteca_annate',  'consistenza_dichiarata',  'varchar(255)', 'YES', null],
        ['emeroteca_annate',  'volume',                  'varchar(50)',  'NO',  ''],
        ['emeroteca_fascicoli', 'barcode',      'varchar(18)',  'YES', null],
        ['emeroteca_fascicoli', 'stato',        $FINAL_STATO_ENUM, 'NO', 'posseduto'],
        ['emeroteca_fascicoli', 'condizione',   "enum('buono','discreto','danneggiato','in_restauro')", 'YES', null],
        ['emeroteca_fascicoli', 'acquisizione', $ACQ_ENUM,      'YES', null],
        ['emeroteca_fascicoli', 'prezzo',       'decimal(8,2)', 'YES', null],
        ['emeroteca_fascicoli', 'reclamato_il', 'date',         'YES', null],
        ['emeroteca_fascicoli', 'n_reclami',    'tinyint unsigned', 'NO', '0'],
        ['emeroteca_abbonamenti', 'fornitore',          'varchar(255)',  'NO',  null],
        ['emeroteca_abbonamenti', 'costo',              'decimal(10,2)', 'YES', null],
        ['emeroteca_abbonamenti', 'valuta',             'varchar(3)',    'NO',  'EUR'],
        ['emeroteca_abbonamenti', 'data_inizio',        'date',          'YES', null],
        ['emeroteca_abbonamenti', 'data_scadenza',      'date',          'YES', null],
        ['emeroteca_abbonamenti', 'rinnovo_automatico', 'tinyint(1)',    'NO',  '0'],
        ['emeroteca_abbonamenti', 'attivo',             'tinyint(1)',    'NO',  '1'],
        ['emeroteca_abbonamenti', 'note',               'text',          'YES', null],
    ];
    foreach ($columnSpecs as [$table, $column, $type, $nullable, $default]) {
        $info = $columnInfo($table, $column);
        check(
            $info !== null
                && $info['type'] === $type
                && $info['nullable'] === $nullable
                && $info['default'] === $default,
            "{$table}.{$column} is {$type} " . ($nullable === 'NO' ? 'NOT NULL' : 'NULL')
                . ($default !== null ? " DEFAULT '{$default}'" : '')
                . ($info !== null ? '' : ' (column missing)')
        );
    }
    // MySQL 8 reports tinyint unsigned defaults reliably but ENUM widths vary
    // by version for tinyint(1); n_reclami tinyint may render as tinyint(3) —
    // handled above via 'tinyint unsigned' (MySQL 8/9 canonical form).
    $updatedAt = $columnInfo('emeroteca_annate', 'updated_at');
    check(
        $updatedAt !== null
            && $updatedAt['nullable'] === 'YES'
            && str_contains($updatedAt['extra'], 'on update current_timestamp'),
        'emeroteca_annate.updated_at is NULLable with ON UPDATE CURRENT_TIMESTAMP'
    );

    // ── 3. new plain KEYs + abbonamenti indexes ───────────────────────
    $indexSpecs = [
        ['emeroteca_testate',     'idx_emeroteca_testata_issn',           'issn'],
        ['emeroteca_testate',     'idx_emeroteca_testata_barcode',        'barcode_base'],
        ['emeroteca_fascicoli',   'idx_emeroteca_fascicolo_barcode',      'barcode'],
        ['emeroteca_fascicoli',   'idx_emeroteca_fascicolo_inventario',   'numero_inventario'],
        ['emeroteca_abbonamenti', 'idx_emeroteca_abbonamento_testata',    'testata_id'],
        ['emeroteca_abbonamenti', 'idx_emeroteca_abbonamento_scadenza',   'data_scadenza'],
    ];
    foreach ($indexSpecs as [$table, $index, $cols]) {
        check($indexColumns($table, $index) === $cols, "index {$index} on {$table}({$cols}) exists");
    }
    // The issn KEY must NOT be unique (duplicate ISSN across supplements
    // and title changes is legitimate).
    $uniqueIssn = $db->query(
        "SELECT NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_testate'
            AND INDEX_NAME = 'idx_emeroteca_testata_issn' LIMIT 1"
    );
    $nonUnique = $uniqueIssn instanceof \mysqli_result ? (int) ($uniqueIssn->fetch_assoc()['NON_UNIQUE'] ?? -1) : -1;
    check($nonUnique === 1, 'issn KEY is non-unique');

    // ── 4. foreign keys ───────────────────────────────────────────────
    $declaredFks = $plugin->expectedForeignKeys();
    $fkPairs = array_map(
        static fn (array $fk): string => $fk['table'] . '.' . $fk['column'] . '→' . $fk['ref_table'],
        $declaredFks
    );
    check(
        in_array('emeroteca_annate.collocazione_id→mensole', $fkPairs, true),
        'expectedForeignKeys() declares the new annate.collocazione_id → mensole FK (mensole exists on this DB)'
    );
    check(
        in_array('emeroteca_abbonamenti.testata_id→emeroteca_testate', $fkPairs, true),
        'expectedForeignKeys() declares the abbonamenti → testate FK'
    );
    foreach ($declaredFks as $fk) {
        $rule = $fkDeleteRule((string) $fk['table'], (string) $fk['column'], (string) $fk['ref_table']);
        check($rule !== '', "declared FK {$fk['table']}.{$fk['column']} → {$fk['ref_table']} exists (rule {$rule})");
    }
    check(
        $fkDeleteRule('emeroteca_annate', 'collocazione_id', 'mensole') === 'SET NULL',
        'annate.collocazione_id → mensole is ON DELETE SET NULL'
    );
    check(
        $fkDeleteRule('emeroteca_abbonamenti', 'testata_id', 'emeroteca_testate') === 'CASCADE',
        'abbonamenti.testata_id → testate is ON DELETE CASCADE'
    );

    // ── 5. constants are exact ENUM twins (order included) ────────────
    $statoInfo = $columnInfo('emeroteca_fascicoli', 'stato');
    check(
        $statoInfo !== null && $statoInfo['type'] === $FINAL_STATO_ENUM,
        "fascicoli.stato ENUM is EXACTLY {$FINAL_STATO_ENUM}"
    );
    check(
        $statoInfo !== null && $enumMembers($statoInfo['type']) === array_keys(EmerotecaPlugin::STATI_FASCICOLO),
        'STATI_FASCICOLO keys match the stato ENUM members in order'
    );
    $condInfo = $columnInfo('emeroteca_fascicoli', 'condizione');
    check(
        $condInfo !== null && $enumMembers($condInfo['type']) === array_keys(EmerotecaPlugin::COND_FASCICOLO),
        'COND_FASCICOLO keys match the condizione ENUM members in order'
    );
    $acqInfo = $columnInfo('emeroteca_fascicoli', 'acquisizione');
    check(
        $acqInfo !== null && $enumMembers($acqInfo['type']) === array_keys(EmerotecaPlugin::TIPI_ACQUISIZIONE),
        'TIPI_ACQUISIZIONE keys match the acquisizione ENUM members in order'
    );
    $prestInfo = $columnInfo('emeroteca_testate', 'prestabile');
    check(
        $prestInfo !== null && $enumMembers($prestInfo['type']) === array_keys(EmerotecaPlugin::OPZIONI_PRESTABILE),
        'OPZIONI_PRESTABILE keys match the prestabile ENUM members in order'
    );

    // ── 6. idempotency: second run is a no-op ─────────────────────────
    $inventoryBefore = $schemaInventory();
    $result2 = $plugin->ensureSchema();
    check(($result2['failed'] ?? ['x']) === [], 'second ensureSchema() reports no failures');
    check($schemaInventory() === $inventoryBefore, 'second ensureSchema() leaves columns and indexes untouched (idempotent)');

    // ── 7. REAL legacy stato normalization ────────────────────────────
    // Revert stato to the widened legacy ENUM (the exact intermediate the
    // migration itself uses — extending is additive and lossless), seed
    // old-style rows, then let the REAL ensureSchema() do the split.
    check(
        $db->query(
            "ALTER TABLE emeroteca_fascicoli
             MODIFY stato ENUM('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso','reclamato','scartato')
                 NOT NULL DEFAULT 'posseduto'"
        ) !== false,
        'fixture: stato ENUM temporarily reverted to the widened legacy set'
    );

    $legacyTestataId = $insertTestata($TITLE_LEGACY);
    $legacyAnnataId  = $insertAnnata($legacyTestataId, 2020, '');
    $fascA = $insertFascicolo($legacyAnnataId, '1', 'danneggiato');            // → posseduto + danneggiato
    $fascB = $insertFascicolo($legacyAnnataId, '2', 'in_restauro');            // → posseduto + in_restauro
    $fascC = $insertFascicolo($legacyAnnataId, '3', 'danneggiato', 'discreto'); // COALESCE keeps discreto
    $fascD = $insertFascicolo($legacyAnnataId, '4', 'mancante');               // untouched
    pass('fixture: 4 old-style fascicoli seeded (danneggiato ×2, in_restauro, mancante)');

    $result3 = $plugin->ensureSchema();
    check(($result3['failed'] ?? ['x']) === [], 'ensureSchema() re-runs the split migration without failures');

    $statoAfter = $columnInfo('emeroteca_fascicoli', 'stato');
    check(
        $statoAfter !== null && $statoAfter['type'] === $FINAL_STATO_ENUM,
        'stato ENUM restricted back to the final member list after normalization'
    );

    $rows = [];
    $res = $db->query(
        "SELECT id, stato, condizione FROM emeroteca_fascicoli WHERE annata_id = {$legacyAnnataId}"
    );
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $rows[(int) $row['id']] = [$row['stato'], $row['condizione']];
    }
    check(($rows[$fascA] ?? null) === ['posseduto', 'danneggiato'], "legacy 'danneggiato' row → stato='posseduto', condizione='danneggiato'");
    check(($rows[$fascB] ?? null) === ['posseduto', 'in_restauro'], "legacy 'in_restauro' row → stato='posseduto', condizione='in_restauro'");
    check(($rows[$fascC] ?? null) === ['posseduto', 'discreto'], 'pre-existing condizione survives the normalization (COALESCE)');
    check(($rows[$fascD] ?? null) === ['mancante', null], "'mancante' row untouched by the normalization");

    // ── 8. REAL volume NULL → '' migration (UNIQUE-safe) ──────────────
    check(
        $db->query("ALTER TABLE emeroteca_annate MODIFY volume VARCHAR(50) NULL") !== false,
        'fixture: annate.volume temporarily reverted to NULLable'
    );
    $volTestataId = $insertTestata($TITLE_VOLUME);
    $plainNullId  = $insertAnnata($volTestataId, 2001, null);  // lone NULL → ''
    $dupNullId1   = $insertAnnata($volTestataId, 2002, null);  // NULL twins:
    $dupNullId2   = $insertAnnata($volTestataId, 2002, null);  //   one '', one synthetic
    $emptyId      = $insertAnnata($volTestataId, 2003, '');    // existing '' kept
    $nullBesideId = $insertAnnata($volTestataId, 2003, null);  // NULL next to '' → synthetic
    pass('fixture: 5 annate seeded (lone NULL, NULL twins, NULL beside an existing empty volume)');

    $result4 = $plugin->ensureSchema();
    check(($result4['failed'] ?? ['x']) === [], 'ensureSchema() re-runs the volume migration without failures');

    $volAfter = $columnInfo('emeroteca_annate', 'volume');
    check(
        $volAfter !== null && $volAfter['nullable'] === 'NO' && $volAfter['default'] === '',
        "annate.volume is NOT NULL DEFAULT '' after the migration"
    );
    $nullCount = $db->query('SELECT COUNT(*) AS c FROM emeroteca_annate WHERE volume IS NULL');
    check(
        $nullCount instanceof \mysqli_result && (int) ($nullCount->fetch_assoc()['c'] ?? -1) === 0,
        'no NULL volume remains anywhere'
    );
    $vol = [];
    $res = $db->query("SELECT id, volume FROM emeroteca_annate WHERE testata_id = {$volTestataId}");
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $vol[(int) $row['id']] = (string) $row['volume'];
    }
    check(($vol[$plainNullId] ?? null) === '', "lone NULL volume collapsed to ''");
    $twins = [$vol[$dupNullId1] ?? null, $vol[$dupNullId2] ?? null];
    check(
        in_array('', $twins, true) && count(array_unique($twins)) === 2 && !in_array(null, $twins, true),
        "NULL twins resolved: one '' and one distinct synthetic label (no row lost)"
    );
    check(($vol[$emptyId] ?? null) === '', "pre-existing '' volume untouched");
    check(
        ($vol[$nullBesideId] ?? null) !== null && ($vol[$nullBesideId] ?? '') !== '',
        "NULL beside an existing '' got a synthetic label instead of colliding"
    );
    $dupProbe = $db->query(
        "SELECT COUNT(*) AS c FROM (
            SELECT testata_id, anno, volume FROM emeroteca_annate
             WHERE testata_id = {$volTestataId}
             GROUP BY testata_id, anno, volume HAVING COUNT(*) > 1
         ) d"
    );
    check(
        $dupProbe instanceof \mysqli_result && (int) ($dupProbe->fetch_assoc()['c'] ?? -1) === 0,
        'UNIQUE(testata_id, anno, volume) holds strictly after the migration'
    );

    // ── 9. consistenzaTestata: stato drives counts, scartato excluded,
    //       consistenza_dichiarata appended ───────────────────────────
    $consTestataId = $insertTestata($TITLE_CONS);
    $a1990 = $insertAnnata($consTestataId, 1990, '');
    $a1991 = $insertAnnata($consTestataId, 1991, '');
    $a1992 = $insertAnnata($consTestataId, 1992, '');
    $a1993 = $insertAnnata($consTestataId, 1993, '');
    $insertFascicolo($a1990, '1', 'posseduto', 'danneggiato'); // owned despite condition
    $insertFascicolo($a1991, '1', 'mancante');                 // the only lacuna
    $insertFascicolo($a1992, '1', 'scartato');                 // neither owned nor lacuna
    $insertFascicolo($a1993, '1', 'posseduto');
    $decl = 'solo 1° semestre';
    $updDecl = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
    check($updDecl !== false, 'consistenza fixture: declared-holdings update prepared');
    $updDecl->bind_param('si', $decl, $a1990);
    check($updDecl->execute(), 'consistenza fixture: consistenza_dichiarata set on 1990');
    $updDecl->close();

    $lacuneLabel = function_exists('__') ? __('lacune') : 'lacune';
    $expectedStr = '1990–1993 · ' . $lacuneLabel . ': 1 · ' . $decl;
    $got = EmerotecaPlugin::consistenzaTestata($db, $consTestataId);
    check(
        $got === $expectedStr,
        "consistenzaTestata: damaged-but-owned counts as owned, scartato is ignored, declared string appended (expected '{$expectedStr}', got '{$got}')"
    );

    // Declared-only testata (no fascicoli at all) → the declared string alone.
    $declTestataId = $insertTestata($TITLE_DECL);
    $aDecl = $insertAnnata($declTestataId, 2010, '');
    $declOnly = '2010: annata rilegata completa';
    $updDecl2 = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
    check($updDecl2 !== false, 'declared-only fixture: update prepared');
    $updDecl2->bind_param('si', $declOnly, $aDecl);
    check($updDecl2->execute(), 'declared-only fixture: consistenza_dichiarata set');
    $updDecl2->close();
    $gotDecl = EmerotecaPlugin::consistenzaTestata($db, $declTestataId);
    check(
        $gotDecl === $declOnly,
        "consistenzaTestata with declared holdings only renders the declared string (got '{$gotDecl}')"
    );

    // ── 10. final convergence: one more run, still clean ──────────────
    $result5 = $plugin->ensureSchema();
    check(($result5['failed'] ?? ['x']) === [], 'final ensureSchema() converges with no failures');
} finally {
    $cleanup();
    // Converge the schema back to 1.4.0 even when an assertion died between
    // a temporary legacy ALTER and its re-migration.
    try {
        $plugin->ensureSchema();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'cleanup ensureSchema failed: ' . $e->getMessage() . "\n");
    }
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
