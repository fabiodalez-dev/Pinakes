<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * The ONE place the user import touches patron types (`tipologie_lettore`).
 *
 * Patron types are a separate feature (plan phase 1: table `tipologie_lettore`
 * with a unique `codice`, column `utenti.tipologia_id`). This class is written
 * against that contract but stands on its own: when the table, its `codice`
 * column or `utenti.tipologia_id` is missing, available() is false, the import
 * does not offer the column, and assign() is a no-op. Once the patron types
 * schema exists the very same code starts assigning the type, with no other
 * change to the import.
 *
 * A value of the patron type column resolves by the type's `codice` first,
 * then by its `nome` (both trimmed and case-insensitive, active types only):
 * the users CSV export writes the name and the code, a hand-made file may
 * carry either. A name shared by two active types never resolves, the import
 * does not guess between them.
 *
 * The table and column names are constructor parameters only so the unit test
 * can run against sandbox tables.
 */
final class PatronTypeImport
{
    /** @var array<string,int>|null code (lowercase) => id */
    private ?array $codes = null;
    /** @var array<string,int|null> name (lowercase) => id, null when two active types share the name */
    private array $names = [];
    private ?bool $available = null;

    public function __construct(
        private readonly mysqli $db,
        private readonly string $typesTable = 'tipologie_lettore',
        private readonly string $usersTable = 'utenti'
    ) {
    }

    /** True when the patron types schema exists (checked once per instance). */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $this->available = $this->columnExists($this->typesTable, 'id')
            && $this->columnExists($this->typesTable, 'codice')
            && $this->columnExists($this->usersTable, 'tipologia_id');
        return $this->available;
    }

    /**
     * Assign the patron type whose code, or else whose name, is $value to the user.
     *
     * @return 'skipped'|'assigned'|'unknown' skipped = nothing to do (no value,
     *         or no patron types schema); unknown = the value matches no type
     *         by code or by name, so the reader keeps the default type
     */
    public function assign(int $userId, ?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || $userId <= 0 || !$this->available()) {
            return 'skipped';
        }
        $typeId = $this->idFor($value);
        if ($typeId === null) {
            return 'unknown';
        }
        $stmt = $this->db->prepare("UPDATE `{$this->usersTable}` SET tipologia_id = ? WHERE id = ?");
        if ($stmt === false) {
            return 'unknown';
        }
        $stmt->bind_param('ii', $typeId, $userId);
        $stmt->execute();
        $stmt->close();
        return 'assigned';
    }

    private function idFor(string $value): ?int
    {
        $this->load();
        $key = self::key($value);
        return $this->codes[$key] ?? $this->names[$key] ?? null;
    }

    /** Read the active types once: their codes, and their names where the table has them. */
    private function load(): void
    {
        if ($this->codes !== null) {
            return;
        }
        $this->codes = [];
        $this->names = [];
        // A deactivated type is not offered in the user form either, so
        // neither its code nor its name resolves here: the reader keeps the default.
        $active = $this->columnExists($this->typesTable, 'attiva') ? ' WHERE attiva = 1' : '';
        $withName = $this->columnExists($this->typesTable, 'nome');
        $columns = $withName ? 'id, codice, nome' : 'id, codice';
        $result = $this->db->query("SELECT {$columns} FROM `{$this->typesTable}`{$active}");
        if (!($result instanceof \mysqli_result)) {
            return;
        }
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id'];
            $this->codes[self::key((string) $row['codice'])] = $id;
            $name = $withName ? self::key((string) $row['nome']) : '';
            if ($name !== '') {
                // Two active types with one name: neither is chosen.
                $this->names[$name] = array_key_exists($name, $this->names) ? null : $id;
            }
        }
        $result->free();
    }

    /** The lookup key of a code or a name: trimmed, lower case. */
    private static function key(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $exists;
        } catch (\Throwable) {
            return false;
        }
    }
}
