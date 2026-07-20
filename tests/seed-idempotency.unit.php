<?php
declare(strict_types=1);

/**
 * Behavioural guard for the settings seed idempotency fix.
 *
 * The installer's data_{locale}.sql seed used
 *   INSERT ... ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), ...
 * which FORCES setting_value back to the (usually empty) seed value on any
 * re-run — so a re-seed wiped admin-configured values (social links, sharing
 * providers, loan limits …). The fix drops `setting_value = VALUES(setting_value)`
 * from every system_settings ON DUPLICATE clause: a fresh install still INSERTs
 * the seed value, but a re-seed now PRESERVES whatever the admin set.
 *
 *   A. Static — no destructive setting_value upsert remains in any data file,
 *      and the shipped seed still parses (SET-based sanity, no orphan clause).
 *   B. Behavioural — against a sandbox table with the real ON DUPLICATE shape:
 *      a fresh key takes the seed value; an existing key keeps its admin value
 *      while its description still refreshes.
 *
 * Run:  php tests/seed-idempotency.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

// ── A. Static: no destructive upsert survives in any locale seed ─────────────
echo "A. Static — destructive setting_value upsert removed\n";
$dataFiles = ['it_IT', 'en_US', 'de_DE', 'fr_FR'];
foreach ($dataFiles as $loc) {
    $sql = (string) file_get_contents($root . "/installer/database/data_{$loc}.sql");
    $hits = substr_count($sql, 'setting_value = VALUES(setting_value)');
    $check($hits === 0, "data_{$loc}.sql: 0 destructive setting_value upserts (found {$hits})");
    // The system_settings ON DUPLICATE clauses must still refresh metadata.
    $check(str_contains($sql, 'ON DUPLICATE KEY UPDATE description = VALUES(description)')
        || str_contains($sql, "ON DUPLICATE KEY UPDATE\n    description = VALUES(description)"),
        "data_{$loc}.sql: system_settings ON DUPLICATE still refreshes description");

    // EVERY system_settings INSERT must be re-runnable: a plain INSERT
    // without ON DUPLICATE would abort the whole re-seed on duplicate key
    // *before* reaching the tested clauses (CodeRabbit #266 finding 2).
    // Split the file into statements and assert each system_settings
    // INSERT carries a non-destructive duplicate handler.
    $stmts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    $plain = [];
    foreach ($stmts as $stmt) {
        if (!preg_match('/INSERT\s+INTO\s+`?system_settings`?/i', $stmt)) {
            continue;
        }
        if (!preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $stmt)) {
            // Grab the first VALUES key for a readable failure label.
            preg_match("/\\(\\s*'[^']*'\\s*,\\s*'([^']*)'/", $stmt, $m);
            $plain[] = $m[1] ?? substr(trim($stmt), 0, 40);
        }
    }
    $check($plain === [],
        "data_{$loc}.sql: every system_settings INSERT is re-runnable (no ON DUPLICATE on: "
        . ($plain === [] ? '—' : implode(', ', $plain)) . ')');
}

// ── B. Behavioural: re-seed preserves an admin value, inserts a fresh one ────
echo "B. Behavioural — sandbox ON DUPLICATE semantics\n";
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the behavioural section is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$T = 'zz_seed_idem';
$cleanup = static fn () => $db->query("DROP TABLE IF EXISTS {$T}");
try {
    $cleanup();
    $db->query(
        "CREATE TABLE {$T} (
            category VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            description VARCHAR(255),
            updated_at TIMESTAMP NULL,
            UNIQUE KEY uq (category, setting_key)
        ) ENGINE=InnoDB"
    );

    // The exact non-destructive shape the seed now uses.
    $seed = static fn (string $value, string $desc): bool => $db->query(
        "INSERT INTO {$T} (category, setting_key, setting_value, description) VALUES
            ('app', 'social_telegram', '{$value}', '{$desc}')
         ON DUPLICATE KEY UPDATE description = VALUES(description), updated_at = NOW()"
    );

    // First install: empty seed value lands.
    $seed('', 'Telegram channel/group URL');
    $row = $db->query("SELECT setting_value, description FROM {$T} WHERE setting_key='social_telegram'")->fetch_assoc();
    $check($row['setting_value'] === '', 'fresh seed inserts the (empty) default value');

    // Admin configures it.
    $db->query("UPDATE {$T} SET setting_value='https://t.me/mylibrary' WHERE setting_key='social_telegram'");

    // Re-seed (the destructive scenario): value MUST survive, description may refresh.
    $seed('', 'Telegram channel/group URL (v2)');
    $row = $db->query("SELECT setting_value, description FROM {$T} WHERE setting_key='social_telegram'")->fetch_assoc();
    $check($row['setting_value'] === 'https://t.me/mylibrary', 're-seed PRESERVES the admin-configured value');
    $check($row['description'] === 'Telegram channel/group URL (v2)', 're-seed still refreshes the description metadata');

    // A brand-new key on re-seed is still inserted (fresh-install path intact).
    $db->query(
        "INSERT INTO {$T} (category, setting_key, setting_value, description) VALUES
            ('app', 'social_newthing', 'seedval', 'New')
         ON DUPLICATE KEY UPDATE description = VALUES(description), updated_at = NOW()"
    );
    $row = $db->query("SELECT setting_value FROM {$T} WHERE setting_key='social_newthing'")->fetch_assoc();
    $check($row['setting_value'] === 'seedval', 'a new key still receives its seed value');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
