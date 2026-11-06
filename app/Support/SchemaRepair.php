<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Brings an installed database up to installer/database/schema.sql: every
 * table the schema declares and the database lacks is created, and every
 * column a table lacks is added with the schema's definition. Nothing is
 * dropped, renamed or altered; indexes and constraints of existing tables
 * are left as they are.
 *
 * Why: a migration recorded as executed is not proof its statements ran.
 * The 0.3.x updater dropped every statement whose text began with a "--"
 * comment, so installs upgraded by it to 0.4.x recorded migrate_0.4.0.sql
 * (and later ones) while missing user_sessions, gdpr_requests, consent_log
 * and utenti.privacy_accettata. A file-only upgrade can skip migrations
 * altogether. Comparing with schema.sql repairs both, whatever the history.
 *
 * It runs after the migrations of every update and once per version on the
 * first request after the files change, because the update that installs
 * this class runs the previous release's updater.
 */
final class SchemaRepair
{
    private const LOCK_NAME = 'pinakes_schema_repair';

    /** Lines inside CREATE TABLE that are not column definitions. */
    private const NOT_COLUMNS = '/^(KEY|UNIQUE|PRIMARY|CONSTRAINT|INDEX|FOREIGN|FULLTEXT|SPATIAL|CHECK|REFERENCES|ON|OR|AND|NOT)$/i';

    /**
     * Run the repair once for the version in version.json. The marker is
     * written even when a statement failed (logged), so a database the
     * account cannot alter is not retried on every request; the next update
     * runs the repair again.
     */
    public static function runOncePerVersion(\mysqli $db, ?string $rootPath = null): void
    {
        $root = $rootPath ?? dirname(__DIR__, 2);
        $version = self::installedVersion($root);
        if ($version === '') {
            return;
        }
        $marker = $root . '/storage/.schema-repaired';
        if (@file_get_contents($marker) === $version) {
            return;
        }
        // Only an installed application is repaired: during installation
        // the installer owns the schema.
        $installed = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' LIMIT 1");
        if (!$installed instanceof \mysqli_result || $installed->num_rows === 0) {
            return;
        }
        $lock = $db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)");
        $row = $lock instanceof \mysqli_result ? $lock->fetch_row() : null;
        if (($row[0] ?? '0') !== '1') {
            return; // another request is repairing
        }
        try {
            self::repair($db, $root);
            @file_put_contents($marker, $version, LOCK_EX);
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * @return array{tables: list<string>, columns: list<string>, failed: list<string>}
     */
    public static function repair(\mysqli $db, ?string $rootPath = null): array
    {
        $root = $rootPath ?? dirname(__DIR__, 2);
        $result = ['tables' => [], 'columns' => [], 'failed' => []];
        $sql = @file_get_contents($root . '/installer/database/schema.sql');
        if ($sql === false || $sql === '') {
            return $result;
        }
        $schema = self::parse($sql);
        if ($schema === []) {
            return $result;
        }
        $existing = self::existingColumns($db);

        // Missing tables, in schema order; a table whose foreign key points
        // at a table created later is retried once at the end.
        $pending = [];
        foreach ($schema as $table => $def) {
            if (!isset($existing[$table])) {
                $pending[$table] = $def['create'];
            }
        }
        for ($pass = 0; $pass < 2 && $pending !== []; $pass++) {
            foreach ($pending as $table => $create) {
                try {
                    if ($db->query($create) === true) {
                        $result['tables'][] = $table;
                        unset($pending[$table]);
                    } elseif ($pass === 1) {
                        $result['failed'][] = "$table: " . $db->error;
                    }
                } catch (\Throwable $e) {
                    if ($pass === 1) {
                        $result['failed'][] = "$table: " . $e->getMessage();
                    }
                }
            }
        }
        if ($result['tables'] !== []) {
            $existing = self::existingColumns($db);
        }

        // Missing columns of existing tables, placed after the previous
        // schema column the table has.
        foreach ($schema as $table => $def) {
            if (!isset($existing[$table]) || in_array($table, $result['tables'], true)) {
                continue;
            }
            $have = $existing[$table];
            $previous = null;
            foreach ($def['columns'] as $name => $definition) {
                if (!isset($have[strtolower($name)])) {
                    $position = $previous === null ? ' FIRST' : ' AFTER `' . str_replace('`', '``', $previous) . '`';
                    $alter = 'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $definition . $position;
                    try {
                        if ($db->query($alter) === true) {
                            $result['columns'][] = "$table.$name";
                            $have[strtolower($name)] = true;
                            self::afterColumnAdded($db, $table, $name);
                        } elseif ($db->errno !== 1060) {
                            $result['failed'][] = "$table.$name: " . $db->error;
                            continue;
                        }
                    } catch (\Throwable $e) {
                        if ((int) $e->getCode() !== 1060) {
                            $result['failed'][] = "$table.$name: " . $e->getMessage();
                            continue;
                        }
                    }
                }
                $previous = $name;
            }
        }

        if ($result['tables'] !== [] || $result['columns'] !== []) {
            SecureLogger::warning('[SchemaRepair] Added missing schema objects', [
                'tables' => $result['tables'],
                'columns' => $result['columns'],
            ]);
        }
        if ($result['failed'] !== []) {
            SecureLogger::error('[SchemaRepair] Could not add schema objects', ['failed' => $result['failed']]);
        }
        return $result;
    }

    /**
     * CREATE TABLE statements of schema.sql: per table, the statement (made
     * IF NOT EXISTS) and its column definitions in order.
     *
     * @return array<string, array{create: string, columns: array<string, string>}>
     */
    public static function parse(string $sql): array
    {
        $tables = [];
        $re = '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*))\s*\(([\s\S]*?)\n\s*\)[^\n]*;/im';
        if (preg_match_all($re, $sql, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $m) {
            $table = $m[1] !== '' ? $m[1] : $m[2];
            $columns = [];
            foreach (explode("\n", $m[3]) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                if (preg_match('/^(?:`([^`]+)`|([A-Za-z_][A-Za-z0-9_$]*))\s/', $line, $c) !== 1) {
                    continue;
                }
                $quoted = $c[1];
                $name = $quoted !== '' ? $quoted : ($c[2] ?? '');
                if ($quoted === '' && preg_match(self::NOT_COLUMNS, $name) === 1) {
                    continue;
                }
                $columns[$name] = rtrim($line, ', ');
            }
            $create = (string) preg_replace('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?/i', 'CREATE TABLE IF NOT EXISTS', $m[0], 1);
            $tables[$table] = ['create' => trim($create), 'columns' => $columns];
        }
        return $tables;
    }

    /**
     * Data a column needs when it arrives late. utenti.privacy_accettata is
     * only written at registration, so an install that missed it could not
     * register anyone; a reader with a recorded acceptance date accepted.
     */
    private static function afterColumnAdded(\mysqli $db, string $table, string $column): void
    {
        if ($table === 'utenti' && $column === 'privacy_accettata') {
            $hasDate = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'data_accettazione_privacy'");
            if ($hasDate instanceof \mysqli_result && $hasDate->num_rows > 0) {
                $db->query("UPDATE utenti SET privacy_accettata = 1 WHERE data_accettazione_privacy IS NOT NULL");
            }
        }
    }

    /** @return array<string, array<string, true>> table => lower-cased column names */
    private static function existingColumns(\mysqli $db): array
    {
        $out = [];
        $res = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()");
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_row()) {
                $out[(string) $row[0]][strtolower((string) $row[1])] = true;
            }
        }
        return $out;
    }

    private static function installedVersion(string $root): string
    {
        $data = json_decode((string) @file_get_contents($root . '/version.json'), true);
        return is_array($data) ? trim((string) ($data['version'] ?? '')) : '';
    }
}
