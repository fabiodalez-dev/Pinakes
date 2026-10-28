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
 * schema exists the very same code starts assigning the type by its code, with
 * no other change to the import.
 *
 * The table and column names are constructor parameters only so the unit test
 * can run against sandbox tables.
 */
final class PatronTypeImport
{
    /** @var array<string,int>|null code (lowercase) => id */
    private ?array $codes = null;
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
     * Assign the patron type with code $code to the user.
     *
     * @return 'skipped'|'assigned'|'unknown' skipped = nothing to do (no code,
     *         or no patron types schema); unknown = the code matches no type,
     *         so the reader keeps the default type
     */
    public function assign(int $userId, ?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '' || $userId <= 0 || !$this->available()) {
            return 'skipped';
        }
        $typeId = $this->idFor($code);
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

    private function idFor(string $code): ?int
    {
        if ($this->codes === null) {
            $this->codes = [];
            $result = $this->db->query("SELECT id, codice FROM `{$this->typesTable}`");
            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $this->codes[mb_strtolower((string) $row['codice'], 'UTF-8')] = (int) $row['id'];
                }
                $result->free();
            }
        }
        return $this->codes[mb_strtolower($code, 'UTF-8')] ?? null;
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
