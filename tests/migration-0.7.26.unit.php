<?php
declare(strict_types=1);

/**
 * Behavioral suite — 10 checks that migrate_0.7.26.sql upgrades an EXISTING
 * install correctly (project rule: ALWAYS test the migration, never eyeball it).
 *
 * The migration seeds 4 email templates x 4 locales (INSERT IGNORE on
 * UNIQUE(name,locale)), adds the loans.max_loan_duration_days setting
 * (ON DUPLICATE no-op), and normalizes the loan_overdue_admin subject
 * placeholder. This test runs the REAL migration file against sandbox tables
 * seeded with the OLD state — including an admin-customized row and a stale
 * single-brace subject — and asserts the effects + idempotency + the
 * "never overwrite admin customizations" promise.
 *
 * Table names are rewritten to zz_mig_* ; the SQL is executed via
 * mysqli::multi_query because the template bodies legitimately contain ';'
 * inside quoted strings (a naive split would corrupt the statements).
 *
 * Run:   php tests/migration-0.7.26.unit.php
 * Exit:  0 only if all pass; prints "ALL <n> PASS".
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
// Socket/host configurabili (CI non ha il socket Homebrew di macOS):
// E2E_DB_SOCKET > .env DB_SOCKET > default macOS; se il socket non esiste,
// fallback TCP su DB_HOST/DB_PORT. DB irraggiungibile => SKIP, non FAIL.
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

const T_TPL = 'zz_mig_email_templates';
const T_SET = 'zz_mig_system_settings';

function migCleanup(mysqli $db): void
{
    $db->query('DROP TABLE IF EXISTS `' . T_TPL . '`');
    $db->query('DROP TABLE IF EXISTS `' . T_SET . '`');
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        migCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

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

$scalar = function (string $sql) use ($db) {
    $row = $db->query($sql)->fetch_row();
    return $row ? $row[0] : null;
};

/** Run the REAL migration file against the sandbox tables (names rewritten). */
$applyMigration = function () use ($db, $root): void {
    $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.26.sql');
    // Strip -- comment lines; keep statements whole (bodies contain ';').
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Sostituisci ENTRAMBE le forme: backtick (DML/DDL) e quoted (i lookup
    // information_schema delle guardie di sezione 0 usano TABLE_NAME = '...').
    $sql = str_replace(['`email_templates`', "'email_templates'"], ['`' . T_TPL . '`', "'" . T_TPL . "'"], $sql);
    $sql = str_replace(['`system_settings`', "'system_settings'"], ['`' . T_SET . '`', "'" . T_SET . "'"], $sql);
    if (!$db->multi_query($sql)) {
        throw new \RuntimeException('multi_query failed: ' . $db->error);
    }
    // Drain every result so errors in later statements surface.
    do {
        if ($res = $db->store_result()) {
            $res->free();
        }
    } while ($db->more_results() && $db->next_result());
};

/* -------- sandbox with the OLD (pre-0.7.26) state -------- */
migCleanup($db);
$db->query(
    'CREATE TABLE `' . T_TPL . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        locale VARCHAR(10) NOT NULL DEFAULT \'it_IT\',
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL,
        UNIQUE KEY name_locale (name, locale)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query(
    'CREATE TABLE `' . T_SET . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        description VARCHAR(255) DEFAULT NULL,
        UNIQUE KEY unique_setting (category, setting_key)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
// Old install: stale single-brace subject + an admin-CUSTOMIZED loan_returned row.
$db->query("INSERT INTO `" . T_TPL . "` (name, locale, subject, body, active) VALUES
    ('loan_overdue_admin', 'it_IT', 'Prestito in ritardo #{prestito_id}', '<p>admin body</p>', 1),
    ('loan_returned', 'it_IT', 'OGGETTO PERSONALIZZATO', '<p>corpo personalizzato dall admin</p>', 1),
    ('wishlist_book_available', 'it_IT', 'Libro disponibile',
     '<p>ciao</p><p><em>Questo libro è stato automaticamente rimosso dalla tua wishlist.</em></p>', 1)");

/* ========================= checks ========================= */

// 1 — pre-state sanity
check((int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "`") === 3, '01 pre: sandbox seeded with 3 legacy rows');

$applyMigration();

// 2 — all 4 templates x 4 locales present
$n = (int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "` WHERE name IN
    ('loan_returned','reservation_expired','copy_unavailable_user','reservation_cancelled')");
check($n === 16, "02 post: 4 templates x 4 locales present (got {$n}, expected 16)");

// 3 — every locale covered for every template
$missing = (int) $scalar("SELECT 16 - COUNT(DISTINCT CONCAT(name,'|',locale)) FROM `" . T_TPL . "` WHERE name IN
    ('loan_returned','reservation_expired','copy_unavailable_user','reservation_cancelled')
    AND locale IN ('it_IT','en_US','de_DE','fr_FR')");
check($missing === 0, '03 post: no (template, locale) pair missing');

// 4 — the admin-customized row was NOT overwritten (INSERT IGNORE promise)
check($scalar("SELECT subject FROM `" . T_TPL . "` WHERE name='loan_returned' AND locale='it_IT'") === 'OGGETTO PERSONALIZZATO',
    '04 post: admin-customized loan_returned it_IT preserved verbatim');

// 5 — new templates are active
$inactive = (int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "` WHERE name='reservation_cancelled' AND active <> 1");
check($inactive === 0, '05 post: seeded templates are active');

// 6 — setting inserted with default 90
check($scalar("SELECT setting_value FROM `" . T_SET . "` WHERE category='loans' AND setting_key='max_loan_duration_days'") === '90',
    '06 post: loans.max_loan_duration_days seeded to 90');

// 7 — stale single-brace subject normalized to double braces
check($scalar("SELECT subject FROM `" . T_TPL . "` WHERE name='loan_overdue_admin'") === 'Prestito in ritardo #{{prestito_id}}',
    '07 post: loan_overdue_admin subject placeholder normalized');

// 7b — wishlist false claim replaced (locale-preserving surgical REPLACE)
$wl = (string) $scalar("SELECT body FROM `" . T_TPL . "` WHERE name='wishlist_book_available'");
check(!str_contains($wl, 'automaticamente rimosso') && str_contains($wl, 'resta nella tua wishlist'),
    '7b post: wishlist false-removal claim replaced with the accurate text');

// 8 — idempotency: second run leaves counts and values unchanged, no error
$db->query("UPDATE `" . T_SET . "` SET setting_value='30' WHERE category='loans' AND setting_key='max_loan_duration_days'");
$applyMigration();
check((int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "`") === 18, '08 idempotent: re-run adds no duplicate template rows (18 total)');

// 9 — re-run must not double-normalize the subject (no triple braces)
check($scalar("SELECT subject FROM `" . T_TPL . "` WHERE name='loan_overdue_admin'") === 'Prestito in ritardo #{{prestito_id}}',
    '09 idempotent: subject not double-braced on re-run');

// 10 — admin-configured setting value survives the re-run (ON DUPLICATE no-op)
check($scalar("SELECT setting_value FROM `" . T_SET . "` WHERE category='loans' AND setting_key='max_loan_duration_days'") === '30',
    '10 idempotent: admin-configured setting value preserved on re-run');

/* ========================= legacy-schema scenario =========================
 * Installs whose email_templates was created by the OLD SettingsRepository
 * fallback have NO locale column and a UNIQUE on name alone. Section 0 of the
 * migration must upgrade that shape in place before seeding.
 */
$db->query('DROP TABLE `' . T_TPL . '`');
$db->query(
    'CREATE TABLE `' . T_TPL . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        subject VARCHAR(255) NOT NULL,
        body LONGTEXT NOT NULL,
        description TEXT,
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query("INSERT INTO `" . T_TPL . "` (name, subject, body, active) VALUES
    ('loan_approved', 'Vecchio oggetto', '<p>corpo legacy</p>', 1)");

$applyMigration();

// 11 — la colonna locale è stata aggiunta (default it_IT sulla riga esistente)
check($scalar("SELECT locale FROM `" . T_TPL . "` WHERE name='loan_approved'") === 'it_IT',
    '11 legacy: locale column added, existing row defaults to it_IT');

// 12 — l'indice UNIQUE(name) legacy è stato sostituito da name_locale
$idx = $db->query("SHOW INDEX FROM `" . T_TPL . "` WHERE Key_name = 'name_locale'")->num_rows;
$old = $db->query("SHOW INDEX FROM `" . T_TPL . "` WHERE Key_name = 'name'")->num_rows;
check($idx > 0 && $old === 0, '12 legacy: UNIQUE(name) swapped for UNIQUE(name, locale)');

// 13 — i seed sono entrati anche sulla tabella legacy migrata + re-run no-op
$n = (int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "` WHERE name IN
    ('loan_returned','reservation_expired','copy_unavailable_user','reservation_cancelled')");
$applyMigration();
$n2 = (int) $scalar("SELECT COUNT(*) FROM `" . T_TPL . "` WHERE name IN
    ('loan_returned','reservation_expired','copy_unavailable_user','reservation_cancelled')");
check($n === 16 && $n2 === 16, '13 legacy: 16 templates seeded post-upgrade, re-run adds none');

migCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
