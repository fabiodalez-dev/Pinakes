<?php
declare(strict_types=1);

/**
 * Shared harness for the issue #501 suites (quoted search = exact spelling).
 *
 * Each suite declares how many checks it runs; finish() fails when the count
 * differs, so a check that silently stops executing cannot pass as green.
 * The database comes from .env (socket when present, else host/port), the
 * same way tests/exact-quoted-search-501.unit.php connects. In strict CI mode
 * an unreachable database is a failure, never a skip.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

final class Exact501
{
    public static int $count = 0;
    /** @var list<callable> */
    private static array $cleanups = [];

    public static function check(bool $cond, string $desc): void
    {
        if (!$cond) {
            throw new \RuntimeException("assertion failed: {$desc}");
        }
        self::$count++;
        printf("[%02d] PASS: %s\n", self::$count, $desc);
    }

    public static function same(mixed $expected, mixed $actual, string $desc): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                "assertion failed: %s\n  expected: %s\n  actual:   %s",
                $desc,
                json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            ));
        }
        self::check(true, $desc);
    }

    public static function onCleanup(callable $fn): void
    {
        self::$cleanups[] = $fn;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$cleanups) as $fn) {
            try {
                $fn();
            } catch (\Throwable $ignored) {
            }
        }
        self::$cleanups = [];
    }

    /** @return array<string, string> */
    private static function env(): array
    {
        $env = [];
        $path = dirname(__DIR__, 2) . '/.env';
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $env[trim($k)] = $v;
        }
        return $env;
    }

    /**
     * Connect, or end the suite: SKIP locally, FAIL in strict CI mode.
     */
    public static function db(): \mysqli
    {
        $env = self::env();
        $user = $env['DB_USER'] ?? '';
        $pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
        $name = $env['DB_NAME'] ?? '';
        $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
        try {
            $db = (is_string($socket) && $socket !== '' && file_exists($socket))
                ? new \mysqli(null, $user, $pass, $name, 0, $socket)
                : new \mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
        } catch (\Throwable $e) {
            if (getenv('CI_STRICT_TESTS') === '1') {
                fwrite(STDERR, "FAIL: database not reachable in strict mode\n");
                exit(1);
            }
            echo 'SKIP: database not reachable (' . $e->getMessage() . ")\n";
            exit(0);
        }
        $db->set_charset('utf8mb4');
        return $db;
    }

    /** A token unique to this run, letters only (it becomes part of FULLTEXT words). */
    public static function token(): string
    {
        $letters = '';
        foreach (str_split(bin2hex(random_bytes(4))) as $c) {
            $letters .= chr(ord('a') + hexdec($c) % 26);
        }
        return 'zq' . $letters;
    }

    /**
     * Ids returned by "SELECT id FROM $from WHERE $cond".
     *
     * @param array{sql:string, params:array<int,string>, types:string}|null $cond
     * @return list<int>
     */
    public static function ids(\mysqli $db, string $from, ?array $cond, string $extra = ''): array
    {
        if ($cond === null) {
            return [];
        }
        $stmt = $db->prepare("SELECT id FROM {$from} WHERE {$cond['sql']} {$extra} ORDER BY id");
        if ($cond['types'] !== '') {
            $stmt->bind_param($cond['types'], ...$cond['params']);
        }
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
        return $ids;
    }

    /** @param list<string|int|null> $params */
    public static function exec(\mysqli $db, string $sql, array $params = []): int
    {
        $stmt = $db->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param(str_repeat('s', count($params)), ...array_map(static fn($p) => $p === null ? null : (string) $p, $params));
        }
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    }

    public static function finish(int $expected): void
    {
        self::cleanup();
        if (self::$count !== $expected) {
            fwrite(STDERR, sprintf("FAIL: ran %d checks, expected %d\n", self::$count, $expected));
            exit(1);
        }
        printf("\nALL %d PASS\n", self::$count);
    }
}

// A failed check (or any error) cleans up the seeded rows and exits 1 with a
// readable FAIL line, whether it happens before or after the database is up.
set_exception_handler(static function (\Throwable $e): void {
    Exact501::cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});
