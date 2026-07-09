<?php
declare(strict_types=1);

/**
 * Behavioral suite — verifies migrate_0.7.31.sql upgrades an EXISTING (pre-0.7.31)
 * install correctly: it must add `libri`.`search_index` (MEDIUMTEXT), add the
 * FULLTEXT index `ft_libri_search_index`, and backfill every non-deleted row
 * with the concatenation of title + subtitle + author names + publisher name(s)
 * + ISBN/EAN + keywords. A bad migration silently breaks every install that
 * updates through the admin UI, so this runs the REAL file (not a paraphrase).
 *
 * Strategy: build sandbox copies of the tables the migration touches
 * (zz_mig_libri WITHOUT search_index, plus zz_mig_libri_autori / zz_mig_autori /
 * zz_mig_editori / zz_mig_libri_editori), seed them, then run the real migration
 * with ONLY the table names / the 'libri' string-literal guard retargeted onto
 * the sandbox. The information_schema guards and every ALTER/UPDATE run verbatim.
 * This catches a broken idempotency guard, a wrong target type, or a wrong
 * backfill expression that a static "the file contains X" check cannot.
 *
 * Runs against the LIVE local MySQL; touches only the zz_mig_* tables, dropped
 * at start, end, and on failure.
 *
 * Run:   php tests/migration-0.7.31.unit.php
 * Exit:  0 only if all checks pass; prints "ALL N PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function migLoadEnv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
        $line = trim($line);
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

$env = migLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

const LIBRI = 'zz_mig_libri';
const LIBRI_AUTORI = 'zz_mig_libri_autori';
const AUTORI = 'zz_mig_autori';
const EDITORI = 'zz_mig_editori';
const LIBRI_EDITORI = 'zz_mig_libri_editori';

function migCleanup(mysqli $db): void
{
    // Children first (no FKs here, but keep a stable order).
    foreach ([LIBRI_EDITORI, LIBRI_AUTORI, LIBRI, AUTORI, EDITORI] as $t) {
        $db->query('DROP TABLE IF EXISTS `' . $t . '`');
    }
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        migCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

/* -------- harness -------- */
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

/** Introspection helpers scoped to the sandbox libri table. */
$dataType = function (string $column) use ($db): string {
    $stmt = $db->prepare(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $t = LIBRI;
    $stmt->bind_param('ss', $t, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string) $row[0] : '';
};
$indexExists = function (string $index) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $t = LIBRI;
    $stmt->bind_param('ss', $t, $index);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row && (int) $row[0] > 0;
};
$scalar = function (string $sql) use ($db) {
    $res = $db->query($sql);
    $row = $res ? $res->fetch_row() : null;
    return $row ? $row[0] : null;
};

/** Run the REAL migration file against the sandbox tables (rename only). */
$applyMigration = function () use ($db, $root): void {
    $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.31.sql');
    // Strip -- comment lines so the split doesn't choke on ';' inside prose.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Retarget every table reference and the 'libri' information_schema guard.
    // Backtick delimiters make each replacement exact (no `libri` inside
    // `libri_autori`, no `editori` inside `libri_editori`).
    $sql = str_replace(
        ['`libri_autori`', '`libri_editori`', '`libri`', '`autori`', '`editori`', "'libri'"],
        ['`' . LIBRI_AUTORI . '`', '`' . LIBRI_EDITORI . '`', '`' . LIBRI . '`', '`' . AUTORI . '`', '`' . EDITORI . '`', "'" . LIBRI . "'"],
        $sql
    );
    // Split on ';' but respect single-quoted string literals — the backfill's
    // REPLACE chain contains HTML entities like '&#039;' / '&amp;' whose ';' must
    // NOT split the statement. This mirrors production Updater::splitSqlStatements
    // (the naive explode(';') used before broke on those entities).
    foreach (migSplitSql($sql) as $stmt) {
        $db->query($stmt);
    }
};

/** Quote-aware statement splitter (mirrors Updater::splitSqlStatements). */
function migSplitSql(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($char === "'") {
            if ($inString && $i + 1 < $length && $sql[$i + 1] === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inString = !$inString;
            $current .= $char;
            continue;
        }
        if ($char === ';' && !$inString) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}

/* -------- start clean, build the OLD-schema sandbox -------- */
migCleanup($db);

$db->query(
    'CREATE TABLE `' . EDITORI . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query(
    'CREATE TABLE `' . AUTORI . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
// OLD `libri` schema: NO search_index column, NO ft_libri_search_index index.
$db->query(
    'CREATE TABLE `' . LIBRI . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titolo VARCHAR(255) NOT NULL,
        sottotitolo VARCHAR(255) NULL,
        isbn10 VARCHAR(20) NULL,
        isbn13 VARCHAR(20) NULL,
        ean VARCHAR(20) NULL,
        parole_chiave TEXT NULL,
        descrizione TEXT NULL,
        descrizione_plain TEXT NULL,
        editore_id INT NULL,
        deleted_at DATETIME NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query(
    'CREATE TABLE `' . LIBRI_AUTORI . '` (
        libro_id INT NOT NULL,
        autore_id INT NOT NULL,
        ruolo VARCHAR(50) NULL,
        PRIMARY KEY (libro_id, autore_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query(
    'CREATE TABLE `' . LIBRI_EDITORI . '` (
        libro_id INT NOT NULL,
        editore_id INT NOT NULL,
        ordine INT NOT NULL DEFAULT 0,
        PRIMARY KEY (libro_id, editore_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// Seed.
$db->query("INSERT INTO `" . EDITORI . "` (id, nome) VALUES (1,'Einaudi'), (2,'Adelphi')");
$db->query("INSERT INTO `" . AUTORI . "` (id, nome) VALUES (1,'Primo Levi'), (2,'Italo Calvino')");
$db->query("INSERT INTO `" . LIBRI . "`
    (id, titolo, sottotitolo, isbn10, isbn13, ean, parole_chiave, descrizione, descrizione_plain, editore_id, deleted_at) VALUES
    (1, 'Se questo è un uomo', 'Racconto', NULL, '9788806219356', NULL, 'memoria testimonianza', NULL, NULL, 1, NULL),
    (2, 'Le città invisibili', NULL, NULL, '9788804668237', NULL, NULL, NULL, NULL, 2, NULL),
    (3, 'Libro Cancellato', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, '2020-01-01 00:00:00'),
    -- Entity-encoded sottotitolo: FULLTEXT must see the DECODED apostrophe word
    -- ('orologio'), never the raw entity's spurious '039' token. descrizione_plain
    -- carries a distinctive word so the recall of the folded description is checked.
    (4, 'Libro Quattro', 'L&#039;orologio', NULL, NULL, NULL, NULL, NULL, 'supercalifragilistic', NULL, NULL),
    -- Entity-encoded ampersand: decode to '&', so 'jerry' is a token but the raw
    -- entity's spurious 'amp' token must NOT survive.
    (5, 'Libro Cinque', 'Tom &amp; Jerry', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
$db->query("INSERT INTO `" . LIBRI_AUTORI . "` (libro_id, autore_id, ruolo) VALUES
    (1, 1, 'principale'),
    (2, 2, 'principale')");
// Book 1 also carries Adelphi as a SECONDARY publisher (junction) — its name
// must land in search_index too.
$db->query("INSERT INTO `" . LIBRI_EDITORI . "` (libro_id, editore_id, ordine) VALUES (1, 2, 1)");

/* ========================= pre-migration checks ========================= */

check($dataType('search_index') === '', '01 pre-migration: libri has NO search_index column');
check(!$indexExists('ft_libri_search_index'), '02 pre-migration: ft_libri_search_index index absent');
check((int) $scalar("SELECT COUNT(*) FROM `" . LIBRI . "`") === 5, '03 pre-migration: 5 seed rows present');

/* ============================ run migration ============================= */
$applyMigration();

/* ========================= post-migration checks ======================== */

check($dataType('search_index') === 'mediumtext', '04 post-migration: search_index is MEDIUMTEXT');
check($indexExists('ft_libri_search_index'), '05 post-migration: ft_libri_search_index FULLTEXT index exists');

// Backfill: book 1 folds title, subtitle, author, primary + secondary publisher,
// isbn13 and keywords.
$idx1 = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=1");
foreach (['Se questo', 'Racconto', 'Primo Levi', 'Einaudi', 'Adelphi', '9788806219356', 'memoria'] as $needle) {
    check(str_contains($idx1, $needle), "06 post-migration: search_index[1] contains '{$needle}'");
}

// A book with no subtitle / no keywords still backfills its available fields.
$idx2 = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=2");
check(str_contains($idx2, 'Le città invisibili') && str_contains($idx2, 'Italo Calvino')
    && str_contains($idx2, 'Adelphi') && str_contains($idx2, '9788804668237'),
    '07 post-migration: search_index[2] folds title + author + publisher + isbn');

// Soft-deleted rows are skipped by the backfill (AND deleted_at IS NULL).
$idx3 = $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=3");
check($idx3 === null || $idx3 === '', '08 post-migration: soft-deleted row NOT backfilled');

// The FULLTEXT index actually matches on the denormalized author name.
$hit = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+Einaudi*' IN BOOLEAN MODE)"
);
check($hit >= 1, '09 post-migration: MATCH(search_index) AGAINST publisher token returns the book');

$hitAuthor = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+Calvino*' IN BOOLEAN MODE)"
);
check($hitAuthor === 1, '10 post-migration: MATCH(search_index) finds a book by its author name');

/* ================= HTML-entity decoding (findings 2/3) ================= */
// Book 4 sottotitolo `L&#039;orologio` → the decoded word 'orologio' matches,
// and the raw entity's spurious '039' token must NOT be present.
$idx4 = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=4");
check(!str_contains($idx4, '039') && !str_contains($idx4, '&#039;'),
    '13 post-migration: search_index[4] has neither the raw entity nor its 039 token');
$hitOrologio = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+orologio*' IN BOOLEAN MODE)"
);
check($hitOrologio === 1, '14 post-migration: decoded apostrophe word (orologio) is matchable');
$hit039 = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+039*' IN BOOLEAN MODE)"
);
check($hit039 === 0, '15 post-migration: spurious 039 token from &#039; does NOT match');

// Book 5 sottotitolo `Tom &amp; Jerry` → decode to '&', so 'jerry' matches but
// the raw entity's spurious 'amp' token must NOT survive.
$idx5 = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=5");
check(str_contains($idx5, 'Tom & Jerry'), '16 post-migration: search_index[5] decodes &amp; to &');
$hitAmp = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+amp*' IN BOOLEAN MODE)"
);
check($hitAmp === 0, '17 post-migration: spurious amp token from &amp; does NOT match');

/* ============ description folded in (finding 4 recall) ============ */
$hitDesc = (int) $scalar(
    "SELECT COUNT(*) FROM `" . LIBRI . "`
     WHERE MATCH(search_index) AGAINST ('+supercalifragilistic*' IN BOOLEAN MODE)"
);
check($hitDesc === 1, '18 post-migration: a book is findable by a plain-text description word');

/* ============================ idempotency ============================== */
$before = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=1");
$applyMigration(); // second run must be a guarded no-op
check($dataType('search_index') === 'mediumtext' && $indexExists('ft_libri_search_index'),
    '11 idempotent: second run leaves column + index unchanged (no error)');
$after = (string) $scalar("SELECT search_index FROM `" . LIBRI . "` WHERE id=1");
check($before === $after, '12 idempotent: backfill recomputes the identical value');

/* -------- done -------- */
migCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
