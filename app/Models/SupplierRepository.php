<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

/**
 * Suppliers (`fornitori`) books are acquired from. A book points at one
 * supplier through `libri.fornitore_id` (ON DELETE SET NULL).
 */
class SupplierRepository
{
    /** Writable columns, in form order. */
    public const FIELDS = ['nome', 'referente', 'email', 'telefono', 'sito_web', 'indirizzo', 'partita_iva', 'note'];

    public function __construct(private mysqli $db)
    {
    }

    /** True once the 0.9.0 migration created the table. */
    public function isAvailable(): bool
    {
        try {
            $res = $this->db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fornitori'");
            return $res instanceof \mysqli_result && $res->num_rows > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every supplier with the number of (non-deleted) books linked to it.
     *
     * @return list<array<string, mixed>>
     */
    public function listWithCounts(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = 'SELECT f.*, COUNT(l.id) AS libri_count
                FROM fornitori f
                LEFT JOIN libri l ON l.fornitore_id = f.id AND l.deleted_at IS NULL
                GROUP BY f.id
                ORDER BY f.nome ASC, f.id ASC';
        $rows = [];
        $res = $this->db->query($sql);
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * id => name, for the book form select and the book list filter.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $out = [];
        $res = $this->db->query('SELECT id, nome FROM fornitori ORDER BY nome ASC, id ASC');
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $out[(int) $row['id']] = (string) $row['nome'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fornitori WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @param array<string, ?string> $data normalized by normalize() */
    public function create(array $data): int
    {
        $values = $this->orderedValues($data);
        $columns = implode(', ', self::FIELDS);
        $placeholders = implode(', ', array_fill(0, count(self::FIELDS), '?'));
        $stmt = $this->db->prepare("INSERT INTO fornitori ({$columns}) VALUES ({$placeholders})");
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /** @param array<string, ?string> $data normalized by normalize() */
    public function update(int $id, array $data): bool
    {
        $values = $this->orderedValues($data);
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", self::FIELDS));
        $stmt = $this->db->prepare("UPDATE fornitori SET {$set} WHERE id = ?");
        $values[] = $id;
        $stmt->bind_param(str_repeat('s', count(self::FIELDS)) . 'i', ...$values);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Deleting a supplier unlinks its books (FK ON DELETE SET NULL). */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM fornitori WHERE id = ?');
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }

    /**
     * Trim every field, blank → NULL, cap the lengths to the column sizes and
     * keep only http(s) websites. The name is required by the caller.
     *
     * @param array<string, mixed> $input
     * @return array<string, ?string>
     */
    public static function normalize(array $input): array
    {
        $limits = ['nome' => 255, 'referente' => 150, 'email' => 255, 'telefono' => 30, 'sito_web' => 255, 'partita_iva' => 20, 'indirizzo' => 2000, 'note' => 5000];
        $out = [];
        foreach (self::FIELDS as $field) {
            $raw = $input[$field] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value !== '') {
                $value = mb_substr($value, 0, $limits[$field]);
            }
            $out[$field] = $value === '' ? null : $value;
        }
        if ($out['email'] !== null && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            $out['email'] = null;
        }
        if ($out['sito_web'] !== null) {
            $url = $out['sito_web'];
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . ltrim($url, '/');
            }
            $out['sito_web'] = filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
        }
        return $out;
    }

    /**
     * @param array<string, ?string> $data
     * @return list<?string>
     */
    private function orderedValues(array $data): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[] = $data[$field] ?? null;
        }
        return $values;
    }
}
