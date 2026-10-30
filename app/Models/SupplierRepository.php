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

    /** Size of `fornitori.sito_web`. */
    private const WEBSITE_MAX = 255;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * True once the 0.9.0 migration created both the table and the
     * `libri.fornitore_id` column: a half-applied migration must not reach
     * the queries that join the two.
     */
    public function isAvailable(): bool
    {
        try {
            $res = $this->db->query(
                "SELECT (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fornitori')
                      + (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = 'fornitore_id') AS c"
            );
            return $res instanceof \mysqli_result && (int) ($res->fetch_assoc()['c'] ?? 0) === 2;
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
        // A correlated count instead of JOIN + GROUP BY f.id: MariaDB's
        // ONLY_FULL_GROUP_BY does not accept f.* grouped by the key alone.
        $sql = 'SELECT f.*,
                       (SELECT COUNT(*) FROM libri l WHERE l.fornitore_id = f.id AND l.deleted_at IS NULL) AS libri_count
                FROM fornitori f
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

    /**
     * Save a supplier. FALSE when it no longer exists (deleted meanwhile);
     * a save that changes nothing on an existing row is still TRUE.
     *
     * @param array<string, ?string> $data normalized by normalize()
     */
    public function update(int $id, array $data): bool
    {
        $values = $this->orderedValues($data);
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", self::FIELDS));
        $stmt = $this->db->prepare("UPDATE fornitori SET {$set} WHERE id = ?");
        $values[] = $id;
        $stmt->bind_param(str_repeat('s', count(self::FIELDS)) . 'i', ...$values);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$ok) {
            return false;
        }
        // affected_rows is 0 both for "no row" and "same values": re-read.
        return $changed || $this->getById($id) !== null;
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
        // The website is not cut: websiteUrl() refuses one that does not fit
        // the column once the scheme is added, and a cut URL is a wrong one.
        $limits = ['nome' => 255, 'referente' => 150, 'email' => 255, 'telefono' => 30, 'partita_iva' => 20, 'indirizzo' => 2000, 'note' => 5000];
        $out = [];
        foreach (self::FIELDS as $field) {
            $raw = $input[$field] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            if ($value !== '' && isset($limits[$field])) {
                $value = mb_substr($value, 0, $limits[$field]);
            }
            $out[$field] = $value === '' ? null : $value;
        }
        if ($out['email'] !== null && !self::isValidEmail($out['email'])) {
            $out['email'] = null;
        }
        if ($out['sito_web'] !== null) {
            $out['sito_web'] = self::websiteUrl($out['sito_web']);
        }
        return $out;
    }

    /**
     * Fields of a submission that carry a value normalize() would have to
     * drop: 'email' when it is not an e-mail address, 'sito_web' when it is
     * not an http(s) URL of at most 255 characters once the scheme is added.
     * The controller refuses the form on these instead of saving NULL with a
     * success message.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function invalidFields(array $input): array
    {
        $invalid = [];
        $email = is_scalar($input['email'] ?? null) ? trim((string) $input['email']) : '';
        if ($email !== '' && !self::isValidEmail($email)) {
            $invalid[] = 'email';
        }
        $site = is_scalar($input['sito_web'] ?? null) ? trim((string) $input['sito_web']) : '';
        if ($site !== '' && self::websiteUrl($site) === null) {
            $invalid[] = 'sito_web';
        }
        return $invalid;
    }

    private static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * The website as an http(s) URL (a missing scheme becomes https), or NULL
     * when it is not a URL or, scheme included, exceeds the 255-character column.
     */
    private static function websiteUrl(string $raw): ?string
    {
        $url = $raw;
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        if (mb_strlen($url) > self::WEBSITE_MAX) {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
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
