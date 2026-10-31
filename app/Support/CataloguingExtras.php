<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Book-form fields added in 0.9.0 that live beside the core record:
 * the supplier (`libri.fornitore_id`) and the RDA content/media/carrier
 * overrides (`libri.rda_*_type`).
 *
 * They are written after the core save, and only for the keys the request
 * actually carries: the book form always posts them, while CSV/LibraryThing
 * imports, the mobile API and plugins do not, so those paths leave the stored
 * values untouched. The MARC and CSV imports tag a whole batch with one
 * supplier through assignSupplier() instead.
 */
final class CataloguingExtras
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Persist the supplier and RDA overrides present in a form submission.
     *
     * @param array<string, mixed> $data raw form data
     */
    public static function save(mysqli $db, int $bookId, array $data): void
    {
        $set = [];
        $types = '';
        $values = [];

        // Only the empty choice ("no supplier", "Automatic") clears a value.
        // A malformed id, a supplier deleted meanwhile or an unknown RDA code
        // leaves the stored value as it is instead of wiping it. A module
        // switched off (Settings → Modules) never writes its field: the stored
        // value survives a save made while it is off.
        if (array_key_exists('fornitore_id', $data) && Features::enabled('suppliers') && self::hasColumn($db, 'fornitore_id')) {
            if (self::isEmptyChoice($data['fornitore_id'])) {
                $set[] = 'fornitore_id = NULL';
            } elseif (($supplierId = self::existingSupplierId($db, $data['fornitore_id'])) !== null) {
                $set[] = 'fornitore_id = ?';
                $types .= 'i';
                $values[] = $supplierId;
            }
        }

        foreach (RdaTypes::COLUMNS as $kind => $column) {
            if (!array_key_exists($column, $data) || !Features::enabled('rda_types') || !self::hasColumn($db, $column)) {
                continue;
            }
            if (self::isEmptyChoice($data[$column])) {
                $set[] = "{$column} = NULL";
            } elseif (($code = RdaTypes::normalize($kind, $data[$column])) !== null) {
                $set[] = "{$column} = ?";
                $types .= 's';
                $values[] = $code;
            }
        }

        if ($set === []) {
            return;
        }

        $sql = 'UPDATE libri SET ' . implode(', ', $set) . ' WHERE id = ? AND deleted_at IS NULL';
        $types .= 'i';
        $values[] = $bookId;
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare the cataloguing extras update');
        }
        try {
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
        } finally {
            $stmt->close();
        }
    }

    /**
     * Link a book to a supplier chosen for an import batch. Nothing is
     * written when the supplier was deleted meanwhile or the column does not
     * exist yet (the import must never clear a supplier on its own): FALSE
     * then, so the import can report the book as left without it.
     */
    public static function assignSupplier(mysqli $db, int $bookId, int $supplierId): bool
    {
        if (!Features::enabled('suppliers') || !self::hasColumn($db, 'fornitore_id') || self::existingSupplierId($db, $supplierId) === null) {
            return false;
        }
        $stmt = $db->prepare('UPDATE libri SET fornitore_id = ? WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare the supplier update');
        }
        try {
            $stmt->bind_param('ii', $supplierId, $bookId);
            $stmt->execute();
        } finally {
            $stmt->close();
        }
        return true;
    }

    /** The form's empty option: no value, or a blank string. */
    private static function isEmptyChoice(mixed $raw): bool
    {
        return $raw === null || (is_string($raw) && trim($raw) === '');
    }

    /** A submitted supplier id that exists, or NULL ("no supplier"). */
    public static function existingSupplierId(mysqli $db, mixed $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        $stmt = $db->prepare('SELECT id FROM fornitori WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        try {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        return $row ? (int) $row['id'] : null;
    }

    private static function hasColumn(mysqli $db, string $column): bool
    {
        if (!array_key_exists($column, self::$columnCache)) {
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'libri' AND COLUMN_NAME = ?");
            $exists = false;
            if ($stmt !== false) {
                $stmt->bind_param('s', $column);
                $stmt->execute();
                $exists = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                $stmt->close();
            }
            self::$columnCache[$column] = $exists;
        }
        return self::$columnCache[$column];
    }
}
