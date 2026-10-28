<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\CirculationPolicy;
use mysqli;

/**
 * Patron type of a user account (utenti.tipologia_id) and the card validity
 * it implies. NULL = the default patron type.
 */
final class PatronTypeAssignment
{
    /**
     * The submitted patron type: null for "default" (empty value) or an id of
     * an existing type. An unknown id keeps $current.
     *
     * @param array<mixed> $data
     */
    public static function fromSubmission(mysqli $db, array $data, ?int $current = null): ?int
    {
        if (!array_key_exists('tipologia_id', $data)) {
            return $current;
        }
        $raw = $data['tipologia_id'];
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }
        if (!is_scalar($raw) || preg_match('/^\d+$/', (string) $raw) !== 1) {
            return $current;
        }
        $id = (int) $raw;
        return isset((new CirculationPolicy($db))->types()[$id]) ? $id : $current;
    }

    /** Store the patron type of $userId (no-op until the 0.9.0 schema exists). */
    public static function save(mysqli $db, int $userId, ?int $typeId): void
    {
        if (!(new CirculationPolicy($db))->schemaReady()) {
            return;
        }
        $stmt = $db->prepare('UPDATE utenti SET tipologia_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $typeId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /** The stored patron type of $userId (null = default or not migrated). */
    public static function of(mysqli $db, int $userId): ?int
    {
        if (!(new CirculationPolicy($db))->schemaReady()) {
            return null;
        }
        $stmt = $db->prepare('SELECT tipologia_id FROM utenti WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) && $row['tipologia_id'] !== null ? (int) $row['tipologia_id'] : null;
    }

    /**
     * Card expiry computed from the patron type ($typeId null = default type):
     * today + durata_tessera_giorni, or null when the type has no card duration.
     */
    public static function automaticCardExpiry(mysqli $db, ?int $typeId): ?string
    {
        $days = (new CirculationPolicy($db))->forType($typeId)->user->cardDurationDays;
        return $days !== null ? CirculationPolicy::dueDateFor(DateHelper::today(), $days) : null;
    }

    /**
     * SQL condition of the user-list "patron type" filter, or null when the
     * filter is empty or invalid. $column is the utenti.tipologia_id column
     * reference. Users without a type belong to the default one.
     *
     * @return array{sql: string, types: string, params: list<int>}|null
     */
    public static function filterCondition(mysqli $db, mixed $value, string $column = 'tipologia_id'): ?array
    {
        $policy = new CirculationPolicy($db);
        if (!$policy->schemaReady() || !is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return null;
        }
        $id = (int) $value;
        if (!isset($policy->types()[$id])) {
            return null;
        }
        if ($id === $policy->defaultTypeId()) {
            return ['sql' => "({$column} = ? OR {$column} IS NULL)", 'types' => 'i', 'params' => [$id]];
        }
        return ['sql' => "{$column} = ?", 'types' => 'i', 'params' => [$id]];
    }

    /**
     * Display name of a stored patron type (null/unknown = the default type).
     */
    public static function nameOf(mysqli $db, mixed $typeId): string
    {
        $policy = new CirculationPolicy($db);
        $types = $policy->types();
        $id = is_numeric($typeId) ? (int) $typeId : null;
        $id = $id !== null && isset($types[$id]) ? $id : $policy->defaultTypeId();
        return $id !== null ? (string) $types[$id]['nome'] : '';
    }

    /**
     * Patron types an operator may assign: the active ones plus $currentId
     * (kept selectable even after it was deactivated).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function choices(mysqli $db, ?int $currentId = null): array
    {
        $out = [];
        foreach ((new CirculationPolicy($db))->types() as $id => $type) {
            if ((int) $type['attiva'] === 1 || $id === $currentId) {
                $out[$id] = $type;
            }
        }
        return $out;
    }
}
