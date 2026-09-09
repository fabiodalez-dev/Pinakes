<?php
declare(strict_types=1);

/**
 * Behavioral unit tests for the Emeroteca 1.4.0 schema migration against
 * the REAL dev DB (issue #140 review follow-up).
 *
 * The point of this suite is the UPGRADE, not the fresh install: the 1.4.0
 * CREATE TABLE DDLs already declare every new column, so a run that only
 * calls ensureSchema() on an up-to-date schema proves nothing — a wrong
 * AFTER, a wrong type, or a column present in the DDL but forgotten in
 * additiveColumnDefs() would all stay green. So the suite DOWNGRADES the
 * real tables to the schema plugin 1.3.0 actually shipped (drops every
 * 1.4.0 column, restores the 6-member legacy `stato` ENUM taken verbatim
 * from `git show origin/main:…/EmerotecaPlugin.php`, makes `volume`
 * NULLable again, removes the 1.4.0 indexes and drops emeroteca_abbonamenti),
 * seeds legacy rows, and then runs the REAL ensureSchema() once — exactly
 * what an installation upgrading from 1.3.0 goes through.
 *
 * Covers:
 *   1. ensureSchema() reports no failures; expectedTables() is DERIVED from
 *      the ddl*() methods (no hardcoded count) and includes the new
 *      emeroteca_abbonamenti;
 *   2. every 1.4.0 column exists with the exact expected type/nullability/
 *      default — asserted on the converged schema AND re-asserted after the
 *      1.3.0 → 1.4.0 upgrade, including the column POSITION implied by each
 *      AFTER clause;
 *   3. code-derived consistency between the fresh DDLs and
 *      additiveColumnDefs(): no additive column missing from the DDL, and —
 *      the case that used to be untestable — no column added since 1.3.0
 *      that the upgrade path would forget. The 1.3.0 baseline embedded here
 *      is itself cross-checked against origin/main when git is available;
 *   4. the new plain KEYs and the abbonamenti indexes are (re)created by the
 *      upgrade, and every expectedForeignKeys() entry is real — including
 *      the new annate.collocazione_id → mensole (ON DELETE SET NULL) which
 *      the upgrade must re-attach after re-adding the column;
 *   5. PHP constants are exact twins of the ENUMs (order included);
 *   6. double execution is idempotent (identical column and index inventory);
 *   7. REAL legacy normalization of `stato`, starting from the ENUM 1.3.0
 *      shipped: 'danneggiato'/'in_restauro' become posseduto + condizione,
 *      a row written with stato='' (possible with sql_mode='') is normalized
 *      instead of being left outside the final member list, and a COALESCE
 *      case proves a pre-existing condizione is never overwritten;
 *   8. REAL volume NULL → '' migration with every UNIQUE(testata_id, anno,
 *      volume) collision: NULL twins, a NULL next to an existing '' row, and
 *      a synthetic 'v<id>' label that collides with a volume already in the
 *      group (the upgrade must not fail and must not lose the annata);
 *   9. consistenzaTestata(): condizione does not hide owned issues,
 *      'scartato' is neither owned nor a lacuna, and the declared
 *      consistenza is APPENDED to the computed one after ' · ' (shown alone
 *      when there is nothing computed, '—' when there is nothing at all).
 *
 * Data safety: the destructive fixtures run on the real dev tables, so every
 * value living in a 1.4.0-only column is copied into a zz_emu140_bak_* table
 * before the downgrade and written back afterwards; the finally block always
 * re-converges the schema first and restores second, so an assertion dying
 * mid-downgrade cannot leave the dev DB on the 1.3.0 shape.
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
function note(string $msg): void
{
    printf("     NOTE: %s\n", $msg);
}

/** @return array{type:string,nullable:string,default:?string,extra:string,position:int}|null */
$columnInfo = static function (string $table, string $column) use ($db): ?array {
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, ORDINAL_POSITION
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
        'position' => (int) $row['ORDINAL_POSITION'],
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

/** Constraint name of the FK on table.column, or '' when there is none. */
$fkName = static function (string $table, string $column) use ($db): string {
    $res = $db->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '" . $db->real_escape_string($table) . "'
            AND COLUMN_NAME = '" . $db->real_escape_string($column) . "'
            AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
    );
    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
    return is_array($row) ? (string) $row['CONSTRAINT_NAME'] : '';
};

$tableExists = static function (string $t) use ($db): bool {
    $res = $db->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
        . $db->real_escape_string($t) . "'"
    );
    return $res instanceof \mysqli_result && $res->num_rows > 0;
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

// ── code-derivation helpers ───────────────────────────────────────────

/**
 * Column name => definition of a CREATE TABLE DDL (one column per line in
 * every emeroteca DDL). Keys, constraints and the closing line are skipped.
 *
 * @return array<string,string>
 */
$parseDdlColumns = static function (string $ddl): array {
    $out = [];
    foreach (preg_split('/\R/', $ddl) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ')' || stripos($line, 'CREATE TABLE') === 0) {
            continue;
        }
        if (preg_match('/^(PRIMARY|UNIQUE|KEY|FULLTEXT|CONSTRAINT|FOREIGN|INDEX|SQL;)\b/i', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+(.+)$/', $line, $m) !== 1) {
            continue;
        }
        $out[$m[1]] = rtrim(trim($m[2]), ',');
    }
    return $out;
};

/** table => CREATE DDL, derived from every public static ddl*() method. */
$ddlByTable = static function () use ($plugin): array {
    $out = [];
    foreach ((new \ReflectionClass(EmerotecaPlugin::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if (!$method->isStatic() || !str_starts_with($method->getName(), 'ddl')) {
            continue;
        }
        $ddl = (string) $method->invoke(null);
        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/', $ddl, $m) === 1) {
            $out[$m[1]] = $ddl;
        }
    }
    return $out;
};

/** @return array<string, array<string,string>> table => (column => DDL fragment) */
$additiveDefs = static function (): array {
    $m = new \ReflectionMethod(EmerotecaPlugin::class, 'additiveColumnDefs');
    $m->setAccessible(true);
    /** @var array<string, array<string,string>> $defs */
    $defs = $m->invoke(null);
    return $defs;
};

/**
 * The schema plugin 1.3.0 really shipped, transcribed from
 * `git show origin/main:storage/plugins/emeroteca/EmerotecaPlugin.php`.
 * Embedded (not read from git at runtime) so the suite also runs in a
 * shallow clone; the embedded copy is cross-checked against origin/main
 * below whenever git can resolve that ref.
 */
$LEGACY_130 = [
    'emeroteca_testate' => [
        'id', 'titolo', 'sottotitolo', 'issn', 'editore_id', 'luogo_pubblicazione', 'lingua',
        'periodicita', 'tipo', 'anno_inizio', 'anno_fine', 'testata_precedente_id', 'genere_id',
        'logo_url', 'descrizione', 'note', 'stato_raccolta', 'created_at', 'updated_at',
    ],
    'emeroteca_annate' => [
        'id', 'testata_id', 'anno', 'volume', 'rilegata', 'copertina_url', 'note', 'created_at',
    ],
    'emeroteca_fascicoli' => [
        'id', 'annata_id', 'numero', 'numero_progressivo', 'titolo_fascicolo', 'data_copertina',
        'data_pubblicazione', 'pagine', 'copertina_url', 'numero_inventario', 'collocazione_id',
        'stato', 'supplementi', 'note', 'pdf_path', 'pdf_nome_originale', 'pdf_dimensione',
        'pdf_pubblico', 'created_at', 'updated_at',
    ],
    'emeroteca_articoli' => [
        'id', 'fascicolo_id', 'titolo', 'autori', 'pagina_inizio', 'pagina_fine', 'tipo',
        'keywords', 'created_at',
    ],
];
// The `stato` ENUM 1.3.0 shipped: SIX members, with 'danneggiato' and
// 'in_restauro' still masquerading as possession states and without
// 'reclamato'/'scartato'. The migration's step 1 (widening) is only
// exercised when the fixture starts from THIS list.
$LEGACY_STATO_ENUM = "enum('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso')";

$FINAL_STATO_ENUM = "enum('posseduto','mancante','atteso','smarrito','reclamato','scartato')";
$ACQ_ENUM         = "enum('abbonamento','acquisto','dono','scambio','deposito')";

// Unique fixture marker so cleanup never touches pre-existing data.
$RUN = 'emu140-' . bin2hex(random_bytes(4));
$TITLE_LEGACY  = "Emeroteca140 Legacy {$RUN}";
$TITLE_VOLUME  = "Emeroteca140 Volume {$RUN}";
$TITLE_COAL    = "Emeroteca140 Coalesce {$RUN}";
$TITLE_CONS    = "Emeroteca140 Consistenza {$RUN}";
$TITLE_DECL    = "Emeroteca140 Dichiarata {$RUN}";
$FIXTURE_TITLES = [$TITLE_LEGACY, $TITLE_VOLUME, $TITLE_COAL, $TITLE_CONS, $TITLE_DECL];

/**
 * FK-ordered cleanup of every fixture testata (articoli → fascicoli →
 * abbonamenti → annate → testate). Safe when tables/columns are missing.
 */
$cleanup = static function () use ($db, $FIXTURE_TITLES): void {
    $titles = implode(',', array_map(
        static fn (string $t): string => "'" . $db->real_escape_string($t) . "'",
        $FIXTURE_TITLES
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

/**
 * Legacy-shaped insert: no `condizione` column exists at 1.3.0, so only
 * the columns 1.3.0 really had are touched. `numero_inventario` is one of
 * them and doubles as the "evidence of possession" the out-of-ENUM stato
 * repair looks at.
 */
$insertLegacyFascicolo = static function (int $annataId, string $numero, string $stato, ?string $inventario = null) use ($db): int {
    $stmt = $db->prepare('INSERT INTO emeroteca_fascicoli (annata_id, numero, stato, numero_inventario) VALUES (?, ?, ?, ?)');
    if ($stmt === false) {
        throw new \RuntimeException('fascicolo insert prepare failed: ' . $db->error);
    }
    $stmt->bind_param('isss', $annataId, $numero, $stato, $inventario);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('fascicolo insert failed: ' . $err);
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

$setDeclared = static function (int $annataId, string $value) use ($db): void {
    $stmt = $db->prepare('UPDATE emeroteca_annate SET consistenza_dichiarata = ? WHERE id = ?');
    if ($stmt === false) {
        throw new \RuntimeException('declared-holdings prepare failed: ' . $db->error);
    }
    $stmt->bind_param('si', $value, $annataId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new \RuntimeException('declared-holdings update failed: ' . $err);
    }
    $stmt->close();
};

// ── expected spec of every 1.4.0 column (shared by both assert passes) ──
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

$assertColumnSpecs = static function (string $phase) use ($columnSpecs, $columnInfo): void {
    foreach ($columnSpecs as [$table, $column, $type, $nullable, $default]) {
        $info = $columnInfo($table, $column);
        check(
            $info !== null
                && $info['type'] === $type
                && $info['nullable'] === $nullable
                && $info['default'] === $default,
            "{$phase}: {$table}.{$column} is {$type} " . ($nullable === 'NO' ? 'NOT NULL' : 'NULL')
                . ($default !== null ? " DEFAULT '{$default}'" : '')
                . ($info !== null
                    ? " (got {$info['type']} " . ($info['nullable'] === 'NO' ? 'NOT NULL' : 'NULL')
                        . ' default ' . var_export($info['default'], true) . ')'
                    : ' (column missing)')
        );
    }
};

$indexSpecs = [
    ['emeroteca_testate',     'idx_emeroteca_testata_issn',           'issn'],
    ['emeroteca_testate',     'idx_emeroteca_testata_barcode',        'barcode_base'],
    ['emeroteca_fascicoli',   'idx_emeroteca_fascicolo_barcode',      'barcode'],
    ['emeroteca_fascicoli',   'idx_emeroteca_fascicolo_inventario',   'numero_inventario'],
    ['emeroteca_abbonamenti', 'idx_emeroteca_abbonamento_testata',    'testata_id'],
    ['emeroteca_abbonamenti', 'idx_emeroteca_abbonamento_scadenza',   'data_scadenza'],
];

$assertIndexes = static function (string $phase) use ($indexSpecs, $indexColumns): void {
    foreach ($indexSpecs as [$table, $index, $cols]) {
        check($indexColumns($table, $index) === $cols, "{$phase}: index {$index} on {$table}({$cols}) exists");
    }
};

// ── backup/restore of the columns the downgrade destroys ──────────────
$backupTables = [];
$backup = static function (string $table, array $columns) use ($db, &$backupTables): void {
    if ($columns === []) {
        return;
    }
    $bak = 'zz_emu140_bak_' . $table;
    @$db->query("DROP TABLE IF EXISTS {$bak}");
    $cols = implode(', ', array_merge(['id'], $columns));
    if ($db->query("CREATE TABLE {$bak} AS SELECT {$cols} FROM {$table}") === false) {
        throw new \RuntimeException("backup of {$table} failed: " . $db->error);
    }
    $backupTables[$table] = $columns;
};
$restore = static function (string $table, array $columns, string $where = '1=1') use ($db): void {
    $bak = 'zz_emu140_bak_' . $table;
    $sets = implode(', ', array_map(static fn (string $c): string => "t.{$c} = b.{$c}", $columns));
    @$db->query("UPDATE {$table} t JOIN {$bak} b ON b.id = t.id SET {$sets} WHERE {$where}");
    @$db->query("DROP TABLE IF EXISTS {$bak}");
};

$downgraded = false;

try {
    // ── 1. ensureSchema: no failures, tables derived from the DDLs ─────
    $result = $plugin->ensureSchema();
    check(($result['failed'] ?? ['x']) === [], 'ensureSchema() reports no failed tables ('
        . implode(',', $result['failed'] ?? []) . ')');

    $ddls = $ddlByTable();
    $expectedTables = $plugin->expectedTables();
    $ddlTables = array_keys($ddls);
    sort($ddlTables);
    $declaredTables = $expectedTables;
    sort($declaredTables);
    check(
        $declaredTables === $ddlTables && $ddlTables !== [],
        'expectedTables() is exactly the set of tables created by the ddl*() methods ('
            . implode(',', $ddlTables) . ')'
    );
    check(
        in_array('emeroteca_abbonamenti', $expectedTables, true),
        'expectedTables() declares the NEW emeroteca_abbonamenti table'
    );
    foreach ($expectedTables as $t) {
        check($tableExists((string) $t), "table {$t} exists after ensureSchema()");
    }

    // ── 2. every self-heal sentinel column exists ──────────────────────
    foreach ($plugin->expectedColumns() as $column) {
        $info = $columnInfo((string) $column['table'], (string) $column['column']);
        check($info !== null, "sentinel column {$column['table']}.{$column['column']} exists");
    }

    // ── 2b. exact types / nullability / defaults of the 1.4.0 columns ──
    $assertColumnSpecs('converged');
    $updatedAt = $columnInfo('emeroteca_annate', 'updated_at');
    check(
        $updatedAt !== null
            && $updatedAt['nullable'] === 'YES'
            && str_contains($updatedAt['extra'], 'on update current_timestamp'),
        'emeroteca_annate.updated_at is NULLable with ON UPDATE CURRENT_TIMESTAMP'
    );

    // ── 2c. DDL ↔ additiveColumnDefs(), both directions, from the CODE ─
    // A column present in the fresh DDL but missing from additiveColumnDefs()
    // is invisible to every DB assertion (fresh installs have it, upgraded
    // ones silently do not) — so the two lists are compared here instead.
    $defs = $additiveDefs();
    $ddlColumns = [];
    foreach ($ddls as $table => $ddl) {
        $ddlColumns[$table] = $parseDdlColumns($ddl);
        check($ddlColumns[$table] !== [], "DDL of {$table} parses into a column list");
    }
    foreach ($defs as $table => $definitions) {
        foreach (array_keys($definitions) as $column) {
            check(
                isset($ddlColumns[$table][$column]),
                "additive column {$table}.{$column} also exists in the fresh-install DDL"
            );
        }
    }
    foreach ($LEGACY_130 as $table => $legacyColumns) {
        $added = array_values(array_diff(array_keys($ddlColumns[$table] ?? []), $legacyColumns));
        $declared = array_keys($defs[$table] ?? []);
        $missing = array_values(array_diff($added, $declared));
        check(
            $missing === [],
            "every column added to {$table} since 1.3.0 is declared in additiveColumnDefs() "
                . '(added: ' . (implode(',', $added) ?: 'none') . ')'
                . ($missing !== [] ? ' — MISSING: ' . implode(',', $missing) : '')
        );
    }
    // Positions implied by the AFTER clauses must match the fresh DDL order,
    // so an install and an upgrade cannot produce two different layouts.
    foreach ($defs as $table => $definitions) {
        foreach ($definitions as $column => $ddl) {
            if (preg_match('/\bAFTER\s+([A-Za-z0-9_]+)/i', $ddl, $m) !== 1) {
                continue;
            }
            $names = array_keys($ddlColumns[$table] ?? []);
            $afterIdx = array_search($m[1], $names, true);
            $colIdx = array_search($column, $names, true);
            check(
                $afterIdx !== false && $colIdx === $afterIdx + 1,
                "AFTER clause of {$table}.{$column} matches the fresh DDL order (after {$m[1]})"
            );
        }
    }

    // ── 2d. the embedded 1.3.0 baseline still matches origin/main ──────
    $root = escapeshellarg(dirname(__DIR__));
    $mainSrc = @shell_exec(
        'git -C ' . $root . ' show origin/main:storage/plugins/emeroteca/EmerotecaPlugin.php 2>/dev/null'
    );
    if (!is_string($mainSrc) || trim($mainSrc) === '') {
        note('origin/main not resolvable (shallow clone?) — embedded 1.3.0 baseline not cross-checked');
    } else {
        foreach ($LEGACY_130 as $table => $legacyColumns) {
            if (preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \(.*?\n\s*SQL;/s', $mainSrc, $m) !== 1) {
                note("origin/main DDL for {$table} not found — baseline not cross-checked");
                continue;
            }
            check(
                array_keys($parseDdlColumns($m[0])) === $legacyColumns,
                "embedded 1.3.0 column list of {$table} matches origin/main"
            );
        }
        check(
            str_contains($mainSrc, "stato              ENUM('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso')"),
            'embedded 1.3.0 stato ENUM (6 members) matches the one origin/main ships'
        );
    }

    // ── 3. new plain KEYs + abbonamenti indexes ────────────────────────
    $assertIndexes('converged');
    // The issn KEY must NOT be unique (duplicate ISSN across supplements
    // and title changes is legitimate).
    $uniqueIssn = $db->query(
        "SELECT NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_testate'
            AND INDEX_NAME = 'idx_emeroteca_testata_issn' LIMIT 1"
    );
    $nonUnique = $uniqueIssn instanceof \mysqli_result ? (int) ($uniqueIssn->fetch_assoc()['NON_UNIQUE'] ?? -1) : -1;
    check($nonUnique === 1, 'issn KEY is non-unique');

    // ── 4. foreign keys ────────────────────────────────────────────────
    $assertForeignKeys = static function (string $phase) use ($plugin, $fkDeleteRule): void {
        $declaredFks = $plugin->expectedForeignKeys();
        $fkPairs = array_map(
            static fn (array $fk): string => $fk['table'] . '.' . $fk['column'] . '→' . $fk['ref_table'],
            $declaredFks
        );
        check(
            in_array('emeroteca_annate.collocazione_id→mensole', $fkPairs, true),
            "{$phase}: expectedForeignKeys() declares the new annate.collocazione_id → mensole FK"
        );
        check(
            in_array('emeroteca_abbonamenti.testata_id→emeroteca_testate', $fkPairs, true),
            "{$phase}: expectedForeignKeys() declares the abbonamenti → testate FK"
        );
        foreach ($declaredFks as $fk) {
            $rule = $fkDeleteRule((string) $fk['table'], (string) $fk['column'], (string) $fk['ref_table']);
            check($rule !== '', "{$phase}: declared FK {$fk['table']}.{$fk['column']} → {$fk['ref_table']} exists (rule {$rule})");
        }
        check(
            $fkDeleteRule('emeroteca_annate', 'collocazione_id', 'mensole') === 'SET NULL',
            "{$phase}: annate.collocazione_id → mensole is ON DELETE SET NULL"
        );
        check(
            $fkDeleteRule('emeroteca_abbonamenti', 'testata_id', 'emeroteca_testate') === 'CASCADE',
            "{$phase}: abbonamenti.testata_id → testate is ON DELETE CASCADE"
        );
    };
    $assertForeignKeys('converged');

    // ── 5. constants are exact ENUM twins (order included) ─────────────
    $assertEnumTwins = static function (string $phase) use ($columnInfo, $enumMembers, $FINAL_STATO_ENUM): void {
        $statoInfo = $columnInfo('emeroteca_fascicoli', 'stato');
        check(
            $statoInfo !== null && $statoInfo['type'] === $FINAL_STATO_ENUM,
            "{$phase}: fascicoli.stato ENUM is EXACTLY {$FINAL_STATO_ENUM}"
        );
        check(
            $statoInfo !== null && $enumMembers($statoInfo['type']) === array_keys(EmerotecaPlugin::STATI_FASCICOLO),
            "{$phase}: STATI_FASCICOLO keys match the stato ENUM members in order"
        );
        $condInfo = $columnInfo('emeroteca_fascicoli', 'condizione');
        check(
            $condInfo !== null && $enumMembers($condInfo['type']) === array_keys(EmerotecaPlugin::COND_FASCICOLO),
            "{$phase}: COND_FASCICOLO keys match the condizione ENUM members in order"
        );
        $acqInfo = $columnInfo('emeroteca_fascicoli', 'acquisizione');
        check(
            $acqInfo !== null && $enumMembers($acqInfo['type']) === array_keys(EmerotecaPlugin::TIPI_ACQUISIZIONE),
            "{$phase}: TIPI_ACQUISIZIONE keys match the acquisizione ENUM members in order"
        );
        $prestInfo = $columnInfo('emeroteca_testate', 'prestabile');
        check(
            $prestInfo !== null && $enumMembers($prestInfo['type']) === array_keys(EmerotecaPlugin::OPZIONI_PRESTABILE),
            "{$phase}: OPZIONI_PRESTABILE keys match the prestabile ENUM members in order"
        );
    };
    $assertEnumTwins('converged');

    // ── 6. idempotency: second run is a no-op ──────────────────────────
    $inventoryBefore = $schemaInventory();
    $result2 = $plugin->ensureSchema();
    check(($result2['failed'] ?? ['x']) === [], 'second ensureSchema() reports no failures');
    check($schemaInventory() === $inventoryBefore, 'second ensureSchema() leaves columns and indexes untouched (idempotent)');

    // ══ 7. THE UPGRADE: downgrade to the real 1.3.0 schema, seed legacy
    //       rows, run the REAL ensureSchema() once ═════════════════════
    $toDrop = [];
    foreach ($LEGACY_130 as $table => $legacyColumns) {
        $toDrop[$table] = array_values(array_diff(array_keys($ddlColumns[$table] ?? []), $legacyColumns));
    }
    check(
        $toDrop['emeroteca_testate'] !== [] && $toDrop['emeroteca_annate'] !== [] && $toDrop['emeroteca_fascicoli'] !== [],
        'downgrade set derived from the code is non-empty for testate/annate/fascicoli ('
            . implode(' | ', array_map(
                static fn (string $t): string => $t . ': ' . implode(',', $toDrop[$t]),
                ['emeroteca_testate', 'emeroteca_annate', 'emeroteca_fascicoli']
            )) . ')'
    );

    // 7a. protect the real data living in the columns about to be dropped.
    foreach ($toDrop as $table => $columns) {
        $backup($table, $columns);
    }
    // `stato` survives the downgrade as a column but the 1.3.0 ENUM has no
    // 'reclamato'/'scartato': snapshot it so pre-existing rows can be put
    // back exactly as they were.
    @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_stato');
    check(
        $db->query('CREATE TABLE zz_emu140_bak_stato AS SELECT id, stato FROM emeroteca_fascicoli') !== false,
        'downgrade: pre-existing stato values snapshotted before the ENUM reverts to the 1.3.0 list'
    );
    @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_abbonamenti');
    check(
        $db->query('CREATE TABLE zz_emu140_bak_abbonamenti AS SELECT * FROM emeroteca_abbonamenti') !== false,
        'downgrade: emeroteca_abbonamenti rows snapshotted before the table is dropped'
    );
    $downgraded = true;

    // 7b. tear the 1.4.0 schema down.
    $annataFk = $fkName('emeroteca_annate', 'collocazione_id');
    if ($annataFk !== '') {
        check(
            $db->query("ALTER TABLE emeroteca_annate DROP FOREIGN KEY {$annataFk}") !== false,
            "downgrade: FK {$annataFk} on annate.collocazione_id dropped"
        );
    }
    foreach ($indexSpecs as [$idxTable, $idxName]) {
        if ($idxTable === 'emeroteca_abbonamenti') {
            continue; // the whole table goes away below
        }
        @$db->query("ALTER TABLE {$idxTable} DROP INDEX {$idxName}");
    }
    foreach ($toDrop as $table => $columns) {
        foreach ($columns as $column) {
            check(
                $db->query("ALTER TABLE {$table} DROP COLUMN {$column}") !== false,
                "downgrade: {$table}.{$column} dropped ({$db->error})"
            );
        }
    }
    check(
        $db->query("ALTER TABLE emeroteca_fascicoli MODIFY stato {$LEGACY_STATO_ENUM} NOT NULL DEFAULT 'posseduto'") !== false,
        'downgrade: stato ENUM reverted to the SIX members plugin 1.3.0 shipped'
    );
    check(
        $db->query('ALTER TABLE emeroteca_annate MODIFY volume VARCHAR(50) NULL') !== false,
        'downgrade: annate.volume reverted to NULLable'
    );
    check(
        $db->query('DROP TABLE emeroteca_abbonamenti') !== false,
        'downgrade: emeroteca_abbonamenti dropped (the 1.4.0 table must be created by the upgrade)'
    );
    // Nothing 1.4.0 must be left standing, or the migration is not exercised.
    foreach ($toDrop as $table => $columns) {
        foreach ($columns as $column) {
            check($columnInfo($table, $column) === null, "downgrade: {$table}.{$column} really gone");
        }
    }
    check(
        !$tableExists('emeroteca_abbonamenti'),
        'downgrade: the schema is now the 1.3.0 one (abbonamenti absent)'
    );

    // 7c. seed the rows a 1.3.0 installation can legitimately hold.
    $legacyTestataId = $insertTestata($TITLE_LEGACY);
    $legacyAnnataId  = $insertAnnata($legacyTestataId, 2020, '');
    $fascA = $insertLegacyFascicolo($legacyAnnataId, '1', 'danneggiato'); // → posseduto + danneggiato
    $fascB = $insertLegacyFascicolo($legacyAnnataId, '2', 'in_restauro'); // → posseduto + in_restauro
    $fascD = $insertLegacyFascicolo($legacyAnnataId, '3', 'mancante');    // untouched

    // Rows written with stato='' — reachable on any install whose session
    // sql_mode is permissive (the ENUM stores the unnamed index-0 member,
    // which reads back as ''). Narrowing the ENUM does NOT fail on them:
    // MySQL copies index 0 verbatim into the narrowed type, so without an
    // explicit repair the rows survive the upgrade OUTSIDE the member list,
    // invisible to every stato-driven query (holdings, badges, counts).
    // Both branches of the documented repair rule are seeded: one row with
    // evidence of possession (an inventory number) and one bare row.
    $prevMode = '';
    $modeRes = $db->query('SELECT @@SESSION.sql_mode AS m');
    if ($modeRes instanceof \mysqli_result) {
        $prevMode = (string) ($modeRes->fetch_assoc()['m'] ?? '');
    }
    check($db->query("SET SESSION sql_mode=''") !== false, 'fixture: permissive sql_mode for the empty-stato rows');
    $fascEmpty      = $insertLegacyFascicolo($legacyAnnataId, '4', '');
    $fascEmptyOwned = $insertLegacyFascicolo($legacyAnnataId, '5', '', 'INV-' . $RUN);
    $db->query("SET SESSION sql_mode='" . $db->real_escape_string($prevMode) . "'");
    $probe = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli WHERE id IN ({$fascEmpty},{$fascEmptyOwned}) AND stato = ''"
    );
    $probeVal = $probe instanceof \mysqli_result ? (int) ($probe->fetch_assoc()['c'] ?? -1) : -1;
    check($probeVal === 2, "fixture: both rows really carry stato='' before the upgrade (got {$probeVal})");
    pass('fixture: 5 legacy fascicoli seeded (danneggiato, in_restauro, mancante, 2× empty stato)');

    // volume fixtures, including the collision the synthetic label can hit.
    $volTestataId = $insertTestata($TITLE_VOLUME);
    $plainNullId  = $insertAnnata($volTestataId, 2001, null);  // lone NULL → ''
    $dupNullId1   = $insertAnnata($volTestataId, 2002, null);  // NULL twins: lower id → ''
    $dupNullId2   = $insertAnnata($volTestataId, 2002, null);  //             higher id → 'v<id>'
    $emptyId      = $insertAnnata($volTestataId, 2003, '');    // existing '' kept
    $nullBesideId = $insertAnnata($volTestataId, 2003, null);  // NULL next to '' → 'v<id>'
    // The nasty one: the synthetic label 'v<id>' is ALREADY taken inside the
    // same UNIQUE(testata_id, anno, volume) group, so the blind
    // UPDATE … SET volume = CONCAT('v', id) hits a duplicate-key error and
    // the whole upgrade stops.
    $emptyClashId = $insertAnnata($volTestataId, 2004, '');
    $clashNullId  = $insertAnnata($volTestataId, 2004, null);
    $takenLabelId = $insertAnnata($volTestataId, 2004, 'v' . $clashNullId);
    pass('fixture: 8 annate seeded (lone NULL, NULL twins, NULL beside empty, synthetic-label collision)');

    // 7d. THE UPGRADE.
    $upgrade = $plugin->ensureSchema();
    check(
        ($upgrade['failed'] ?? ['x']) === [],
        'UPGRADE 1.3.0 → 1.4.0 completes with no failed tables (' . implode(',', $upgrade['failed'] ?? []) . ')'
    );

    // 7e. the whole 1.4.0 schema is back, byte for byte.
    check($tableExists('emeroteca_abbonamenti'), 'upgrade: emeroteca_abbonamenti created');
    $assertColumnSpecs('upgraded');
    $assertIndexes('upgraded');
    $assertForeignKeys('upgraded');
    $assertEnumTwins('upgraded');
    $updatedAt = $columnInfo('emeroteca_annate', 'updated_at');
    check(
        $updatedAt !== null
            && $updatedAt['nullable'] === 'YES'
            && str_contains($updatedAt['extra'], 'on update current_timestamp'),
        'upgrade: annate.updated_at re-added NULLable with ON UPDATE CURRENT_TIMESTAMP'
    );
    // Column POSITION: an ALTER with a wrong (or missing) AFTER lands the
    // column at the end of the table and the upgraded layout drifts from
    // the fresh-install one for good.
    foreach ($defs as $table => $definitions) {
        foreach ($definitions as $column => $ddl) {
            if (preg_match('/\bAFTER\s+([A-Za-z0-9_]+)/i', $ddl, $m) !== 1) {
                continue;
            }
            $colInfo = $columnInfo($table, $column);
            $afterInfo = $columnInfo($table, $m[1]);
            check(
                $colInfo !== null && $afterInfo !== null
                    && $colInfo['position'] === $afterInfo['position'] + 1,
                "upgrade: {$table}.{$column} sits immediately after {$m[1]} "
                    . '(' . ($colInfo['position'] ?? -1) . ' vs ' . ($afterInfo['position'] ?? -1) . ')'
            );
        }
    }

    // 7f. legacy stato normalization on the seeded rows.
    $rows = [];
    $res = $db->query(
        "SELECT id, stato, condizione FROM emeroteca_fascicoli WHERE annata_id = {$legacyAnnataId}"
    );
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $rows[(int) $row['id']] = [$row['stato'], $row['condizione']];
    }
    check(($rows[$fascA] ?? null) === ['posseduto', 'danneggiato'], "legacy 'danneggiato' row → stato='posseduto', condizione='danneggiato'");
    check(($rows[$fascB] ?? null) === ['posseduto', 'in_restauro'], "legacy 'in_restauro' row → stato='posseduto', condizione='in_restauro'");
    check(($rows[$fascD] ?? null) === ['mancante', null], "'mancante' row untouched by the normalization");
    // The out-of-ENUM repair guesses, so its documented rule is asserted on
    // BOTH branches: a bare placeholder is a gap, a row that describes a
    // copy (here: an inventory number) is on the shelf.
    check(
        ($rows[$fascEmpty][0] ?? null) === 'mancante',
        "bare stato='' row normalized to 'mancante' instead of being left outside the ENUM "
            . '(got ' . var_export($rows[$fascEmpty][0] ?? null, true) . ')'
    );
    check(
        ($rows[$fascEmptyOwned][0] ?? null) === 'posseduto',
        "stato='' row carrying an inventory number normalized to 'posseduto' "
            . '(got ' . var_export($rows[$fascEmptyOwned][0] ?? null, true) . ')'
    );
    $outsideEnum = $db->query(
        "SELECT COUNT(*) AS c FROM emeroteca_fascicoli
          WHERE stato NOT IN ('posseduto','mancante','atteso','smarrito','reclamato','scartato')"
    );
    check(
        $outsideEnum instanceof \mysqli_result && (int) ($outsideEnum->fetch_assoc()['c'] ?? -1) === 0,
        'no fascicolo is left with a stato outside the final ENUM member list'
    );

    // 7g. volume migration, every collision shape.
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
    check(count($vol) === 8, 'no annata was lost by the volume migration (8 seeded, ' . count($vol) . ' found)');
    check(($vol[$plainNullId] ?? null) === '', "lone NULL volume collapsed to ''");
    check(
        ($vol[$dupNullId1] ?? null) === '',
        "NULL twins: the LOWEST id took the empty volume (got " . var_export($vol[$dupNullId1] ?? null, true) . ')'
    );
    check(
        ($vol[$dupNullId2] ?? null) === 'v' . $dupNullId2,
        "NULL twins: the other row took the documented synthetic label 'v{$dupNullId2}' (got "
            . var_export($vol[$dupNullId2] ?? null, true) . ')'
    );
    check(($vol[$emptyId] ?? null) === '', "pre-existing '' volume untouched");
    check(
        ($vol[$nullBesideId] ?? null) === 'v' . $nullBesideId,
        "NULL beside an existing '' got the synthetic label 'v{$nullBesideId}' (got "
            . var_export($vol[$nullBesideId] ?? null, true) . ')'
    );
    check(($vol[$emptyClashId] ?? null) === '', "collision group: the pre-existing '' row is untouched");
    check(
        ($vol[$takenLabelId] ?? null) === 'v' . $clashNullId,
        "collision group: the row that already owned the label 'v{$clashNullId}' keeps it (got "
            . var_export($vol[$takenLabelId] ?? null, true) . ')'
    );
    check(
        ($vol[$clashNullId] ?? null) !== null
            && ($vol[$clashNullId] ?? '') !== ''
            && ($vol[$clashNullId] ?? '') !== 'v' . $clashNullId,
        "collision group: the NULL row whose synthetic label was taken got a DIFFERENT non-empty label (got "
            . var_export($vol[$clashNullId] ?? null, true) . ')'
    );
    $dupProbe = $db->query(
        'SELECT COUNT(*) AS c FROM (
            SELECT testata_id, anno, volume FROM emeroteca_annate
             GROUP BY testata_id, anno, volume HAVING COUNT(*) > 1
         ) d'
    );
    check(
        $dupProbe instanceof \mysqli_result && (int) ($dupProbe->fetch_assoc()['c'] ?? -1) === 0,
        'UNIQUE(testata_id, anno, volume) holds strictly across the whole table after the migration'
    );

    // 7h. restore the pre-existing rows now that the schema is back.
    @$db->query(
        "UPDATE emeroteca_fascicoli f JOIN zz_emu140_bak_stato b ON b.id = f.id
            SET f.stato = b.stato
          WHERE f.annata_id <> {$legacyAnnataId}"
    );
    @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_stato');
    $abbCols = [];
    $res = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zz_emu140_bak_abbonamenti'
          ORDER BY ORDINAL_POSITION"
    );
    while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
        $abbCols[] = (string) $row['COLUMN_NAME'];
    }
    if ($abbCols !== []) {
        $list = implode(',', $abbCols);
        @$db->query("INSERT INTO emeroteca_abbonamenti ({$list}) SELECT {$list} FROM zz_emu140_bak_abbonamenti");
    }
    @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_abbonamenti');
    foreach ($backupTables as $table => $columns) {
        $restore($table, $columns);
    }
    $backupTables = [];
    $downgraded = false;
    pass('pre-existing dev-DB rows restored into the re-created 1.4.0 columns');

    // ── 8. partial upgrade: only the ENUM is legacy, condizione already
    //       exists → COALESCE must not overwrite a recorded condition ──
    check(
        $db->query(
            "ALTER TABLE emeroteca_fascicoli
             MODIFY stato ENUM('posseduto','mancante','danneggiato','in_restauro','smarrito','atteso','reclamato','scartato')
                 NOT NULL DEFAULT 'posseduto'"
        ) !== false,
        'fixture: stato ENUM widened to the migration intermediate (partial-upgrade shape)'
    );
    $coalTestataId = $insertTestata($TITLE_COAL);
    $coalAnnataId  = $insertAnnata($coalTestataId, 2019, '');
    $fascC = $insertFascicolo($coalAnnataId, '1', 'danneggiato', 'discreto');
    $result3 = $plugin->ensureSchema();
    check(($result3['failed'] ?? ['x']) === [], 'ensureSchema() re-runs the split migration without failures');
    $res = $db->query("SELECT stato, condizione FROM emeroteca_fascicoli WHERE id = {$fascC}");
    $rowC = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
    check(
        is_array($rowC) && $rowC['stato'] === 'posseduto' && $rowC['condizione'] === 'discreto',
        'pre-existing condizione survives the normalization (COALESCE keeps discreto)'
    );

    // ── 9. consistenzaTestata: stato drives counts, scartato excluded,
    //       consistenza_dichiarata APPENDED after ' · ' ────────────────
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
    $setDeclared($a1990, $decl);

    // The label is part of the documented contract ("1990–2005 · lacune: 3"),
    // so it is asserted instead of being re-derived from the implementation.
    $lacuneLabel = function_exists('__') ? __('lacune') : 'lacune';
    check($lacuneLabel === 'lacune', "the lacune label rendered by this locale is 'lacune' (got '{$lacuneLabel}')");
    $expectedStr = '1990–1993 · lacune: 1 · ' . $decl;
    $got = EmerotecaPlugin::consistenzaTestata($db, $consTestataId);
    check(
        $got === $expectedStr,
        "consistenzaTestata: damaged-but-owned counts as owned, scartato is ignored, declared string appended (expected '{$expectedStr}', got '{$got}')"
    );

    // Declared-only testata (no fascicoli at all) → the declared string alone.
    $declTestataId = $insertTestata($TITLE_DECL);
    $aDecl = $insertAnnata($declTestataId, 2010, '');
    $declOnly = '2010: annata rilegata completa';
    $setDeclared($aDecl, $declOnly);
    $gotDecl = EmerotecaPlugin::consistenzaTestata($db, $declTestataId);
    check(
        $gotDecl === $declOnly,
        "consistenzaTestata with declared holdings only renders the declared string (got '{$gotDecl}')"
    );

    // ── 10. final convergence: one more run, still clean and idempotent ─
    $inventoryFinal = $schemaInventory();
    $result5 = $plugin->ensureSchema();
    check(($result5['failed'] ?? ['x']) === [], 'final ensureSchema() converges with no failures');
    check($schemaInventory() === $inventoryFinal, 'the upgraded schema is stable across one more ensureSchema()');
} finally {
    // Converge FIRST: an assertion dying between the downgrade and the
    // re-migration would otherwise leave the dev DB on the 1.3.0 schema,
    // and the restore below needs the 1.4.0 columns to exist.
    $converge = static function () use ($plugin): void {
        try {
            $plugin->ensureSchema();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cleanup ensureSchema failed: ' . $e->getMessage() . "\n");
        }
    };
    $converge();
    if ($downgraded) {
        fwrite(STDERR, "NOTE: the suite aborted mid-downgrade; restoring the snapshotted values\n");
        @$db->query(
            'UPDATE emeroteca_fascicoli f JOIN zz_emu140_bak_stato b ON b.id = f.id SET f.stato = b.stato'
        );
        @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_stato');
        $abbCols = [];
        $res = @$db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zz_emu140_bak_abbonamenti'
              ORDER BY ORDINAL_POSITION"
        );
        while ($res instanceof \mysqli_result && ($row = $res->fetch_assoc())) {
            $abbCols[] = (string) $row['COLUMN_NAME'];
        }
        if ($abbCols !== []) {
            $list = implode(',', $abbCols);
            @$db->query("INSERT IGNORE INTO emeroteca_abbonamenti ({$list}) SELECT {$list} FROM zz_emu140_bak_abbonamenti");
        }
        @$db->query('DROP TABLE IF EXISTS zz_emu140_bak_abbonamenti');
    }
    foreach ($backupTables as $table => $columns) {
        $restore($table, $columns);
    }
    $cleanup();
    // Fixture rows are gone now: a last pass guarantees the schema the next
    // suite (or the browser) finds is the converged 1.4.0 one.
    $converge();
    $db->close();
}

printf("\nALL %d PASS\n", $TESTNO);
