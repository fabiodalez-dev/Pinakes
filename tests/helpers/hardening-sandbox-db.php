<?php
declare(strict_types=1);

/**
 * Disposable database for the security-hardening behavioural tests
 * (tests/security-hardening-*.unit.php).
 *
 * The suites drive the REAL plugin / controller code against real tables, so
 * they need a schema they may write to freely. Nothing is borrowed from the
 * database the run points at: a fresh "<E2E_DB_NAME>_hb<tag>" database is
 * rebuilt from installer/database/schema.sql on every run and dropped at the
 * end. A suite that leaned on rows or plugin tables that happen to exist on a
 * developer's install would pass there and fail on the empty CI schema.
 *
 * Credentials: E2E_DB_* first (CI, run-e2e-wtN.sh), then the checkout's .env.
 * The sandbox name extends the base name, so a developer user granted on its
 * own prefix (e.g. fabiodal_biblioteca_wt%) can create and drop it.
 */

/**
 * Connection settings: E2E_DB_* first, then the checkout's .env.
 *
 * @return array{user: string, pass: string, host: string, port: int, socket: string, base: string, envName: string}
 */
function hardeningDbSettings(): array
{
    $dotenv = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents(dirname(__DIR__, 2) . '/.env')) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $dotenv[trim($k)] = trim(trim($v), "\"'");
    }
    $cfg = static fn (string $e2e, string $plain): string => (string) (getenv($e2e) ?: ($dotenv[$plain] ?? ''));
    return [
        'user' => $cfg('E2E_DB_USER', 'DB_USER'),
        'pass' => $cfg('E2E_DB_PASS', 'DB_PASS'),
        'host' => $cfg('E2E_DB_HOST', 'DB_HOST'),
        'port' => (int) ($cfg('E2E_DB_PORT', 'DB_PORT') ?: 3306),
        'socket' => $cfg('E2E_DB_SOCKET', 'DB_SOCKET'),
        'base' => $cfg('E2E_DB_NAME', 'DB_NAME'),
        'envName' => (string) ($dotenv['DB_NAME'] ?? ''),
    ];
}

/** A new connection to $database ('' = none selected), strict error mode. */
function hardeningSandboxConnect(string $database): mysqli
{
    $s = hardeningDbSettings();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = $s['socket'] !== '' && file_exists($s['socket'])
        ? new mysqli('localhost', $s['user'], $s['pass'], $database, 0, $s['socket'])
        : new mysqli($s['host'] !== '' ? $s['host'] : '127.0.0.1', $s['user'], $s['pass'], $database, $s['port']);
    $db->set_charset('utf8mb4');
    return $db;
}

/**
 * @return array{db: mysqli, name: string, drop: Closure(): void}|null null when
 *         no database is configured (the caller prints its SKIP line)
 */
function hardeningSandboxDb(string $tag): ?array
{
    $root = dirname(__DIR__, 2);
    $s = hardeningDbSettings();
    if ($s['user'] === '' || $s['base'] === '') {
        return null;
    }

    $name = substr(preg_replace('/[^A-Za-z0-9_]/', '', $s['base']) . '_hb' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)), 0, 64);
    $real = array_map('strtolower', array_filter([$s['base'], $s['envName']], static fn (string $n): bool => $n !== ''));
    if (in_array(strtolower($name), $real, true)) {
        throw new RuntimeException("sandbox name '{$name}' is a real database");
    }

    $admin = hardeningSandboxConnect('');
    $admin->query("DROP DATABASE IF EXISTS `{$name}`");
    $admin->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->close();

    $useSocket = $s['socket'] !== '' && file_exists($s['socket']);
    $host = $s['host'] !== '' ? $s['host'] : '127.0.0.1';
    $command = $useSocket
        ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($s['socket']), escapeshellarg($s['user']), escapeshellarg($name))
        : sprintf('mysql -h %s -P %d --protocol=TCP -u %s %s', escapeshellarg($host), $s['port'], escapeshellarg($s['user']), escapeshellarg($name));
    $command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
    $previous = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . $s['pass']);
    try {
        exec($command, $out, $rc);
    } finally {
        $previous === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previous);
    }
    if ($rc !== 0) {
        throw new RuntimeException('could not load schema.sql into the sandbox: ' . implode("\n", $out));
    }

    $db = hardeningSandboxConnect($name);
    $drop = static function () use ($db, $name): void {
        try {
            $db->query("DROP DATABASE IF EXISTS `{$name}`");
            $db->close();
        } catch (\Throwable) {
            // best effort: the next run drops it before rebuilding
        }
    };

    return ['db' => $db, 'name' => $name, 'drop' => $drop];
}

/**
 * Insert a user row with only the columns the code under test reads; the
 * bcrypt cost is the minimum so a wrong-password spray stays fast.
 *
 * @param array<string, mixed> $extra
 */
function hardeningSeedUser(mysqli $db, string $email, string $password, string $tipo = 'standard', array $extra = []): int
{
    static $n = 0;
    $n++;
    $row = array_merge([
        'codice_tessera' => 'HB' . getmypid() . '_' . $n,
        'nome' => 'Hb' . $n,
        'cognome' => 'Tester',
        'email' => $email,
        'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
        'tipo_utente' => $tipo,
        'stato' => 'attivo',
        'email_verificata' => 1,
    ], $extra);
    $cols = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($row)));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $stmt = $db->prepare("INSERT INTO utenti ({$cols}) VALUES ({$marks})");
    $values = array_map(static fn ($v) => $v === null ? null : (string) $v, array_values($row));
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
}
