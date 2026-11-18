<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Film and video facts that have no home in the book record (#539):
 * running time, cast, subtitles, age rating and region code, stored in
 * `libri.durata_minuti`, `libri.interpreti`, `libri.sottotitoli`,
 * `libri.classificazione_eta` and `libri.codice_regione`.
 *
 * Everything else a film needs reuses the existing record, as music does:
 * the director is the principal author, the studio or distributor is the
 * publisher, the release year is `anno_pubblicazione`, the audio languages
 * are `lingua`, the disc barcode is `ean` and the format (DVD, Blu-ray, VHS)
 * is `formato`.
 *
 * Like CataloguingExtras, the fields are written after the core save and
 * only for the keys the request carries: the book form always posts them,
 * while imports, the mobile API and plugins do not, so those paths leave
 * the stored values untouched. A column the database does not have yet
 * (files updated, schema check not run) is skipped instead of failing the
 * save.
 */
final class FilmFields
{
    /** Column => maximum length in characters (0 = integer column). */
    public const COLUMNS = [
        'durata_minuti' => 0,
        'interpreti' => 5000,
        'sottotitoli' => 255,
        'classificazione_eta' => 50,
        'codice_regione' => 20,
    ];

    /** Longest running time accepted, in minutes (smallint unsigned). */
    public const MAX_RUNTIME = 65535;

    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Normalise the film keys present in a submission. Absent keys stay
     * absent; a blank value becomes NULL; text is trimmed, collapsed to one
     * line where the field is a single line, and capped to the column size;
     * a running time outside 1..65535 minutes becomes NULL.
     *
     * @param array<string, mixed> $data
     * @return array<string, int|string|null>
     */
    public static function normalize(array $data): array
    {
        $out = [];
        foreach (self::COLUMNS as $column => $maxLength) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $raw = $data[$column];
            if ($maxLength === 0) {
                $out[$column] = self::runtime($raw);
                continue;
            }
            if (!is_scalar($raw)) {
                $out[$column] = null;
                continue;
            }
            $value = trim((string) $raw);
            if ($column === 'interpreti') {
                // The cast keeps its line breaks (one performer per line is
                // common); only runs of blank lines are collapsed.
                $value = preg_replace("/\r\n?/", "\n", $value) ?? $value;
                $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
            } else {
                $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
            }
            if ($value === '') {
                $out[$column] = null;
                continue;
            }
            $out[$column] = mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $out;
    }

    /** A running time in whole minutes, or NULL when blank or out of range. */
    public static function runtime(mixed $raw): ?int
    {
        if (is_int($raw)) {
            $minutes = $raw;
        } elseif (is_string($raw) && preg_match('/^\s*(\d{1,5})\s*$/', $raw, $m) === 1) {
            $minutes = (int) $m[1];
        } else {
            return null;
        }
        return $minutes >= 1 && $minutes <= self::MAX_RUNTIME ? $minutes : null;
    }

    /**
     * Persist the film fields present in a form submission.
     *
     * @param array<string, mixed> $data raw form data
     */
    public static function save(mysqli $db, int $bookId, array $data): void
    {
        $set = [];
        $types = '';
        $values = [];
        foreach (self::normalize($data) as $column => $value) {
            if (!self::hasColumn($db, $column)) {
                continue;
            }
            if ($value === null) {
                $set[] = "{$column} = NULL";
                continue;
            }
            $set[] = "{$column} = ?";
            $types .= is_int($value) ? 'i' : 's';
            $values[] = $value;
        }
        if ($set === []) {
            return;
        }

        $sql = 'UPDATE libri SET ' . implode(', ', $set) . ' WHERE id = ? AND deleted_at IS NULL';
        $types .= 'i';
        $values[] = $bookId;
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare the film fields update');
        }
        try {
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
        } finally {
            $stmt->close();
        }
    }

    /** "95 min", translated; empty for an unknown running time. */
    public static function runtimeLabel(mixed $minutes): string
    {
        $m = self::runtime($minutes);
        return $m === null ? '' : sprintf(__('%d min'), $m);
    }

    /** ISO 8601 duration for schema.org (PT1H35M), or NULL. */
    public static function iso8601(mixed $minutes): ?string
    {
        $m = self::runtime($minutes);
        if ($m === null) {
            return null;
        }
        $hours = intdiv($m, 60);
        $rest = $m % 60;
        return 'PT' . ($hours > 0 ? $hours . 'H' : '') . ($rest > 0 || $hours === 0 ? $rest . 'M' : '');
    }

    /** MARC 21 field 306 playing time (HHMMSS), or NULL. */
    public static function marc306(mixed $minutes): ?string
    {
        $m = self::runtime($minutes);
        if ($m === null) {
            return null;
        }
        $hours = intdiv($m, 60);
        if ($hours > 99) {
            return null; // 306 has two digits of hours
        }
        return sprintf('%02d%02d00', $hours, $m % 60);
    }

    /**
     * MARC 21 008/18-20 for visual materials: the running time in minutes
     * as three digits, "000" when it exceeds 999, "nnn" when it is unknown.
     */
    public static function marc008Runtime(mixed $minutes): string
    {
        $m = self::runtime($minutes);
        if ($m === null) {
            return 'nnn';
        }
        return $m > 999 ? '000' : sprintf('%03d', $m);
    }

    /**
     * The cast as a list of names, split on line breaks, semicolons and
     * commas.
     *
     * @return list<string>
     */
    public static function castList(mixed $interpreti): array
    {
        if (!is_string($interpreti) || trim($interpreti) === '') {
            return [];
        }
        $names = [];
        foreach (preg_split('/[\r\n;,]+/u', $interpreti) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** The cast on one line, names separated by ", ". */
    public static function castLine(mixed $interpreti): string
    {
        return implode(', ', self::castList($interpreti));
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
