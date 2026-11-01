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
     * an active type. An unknown id, or a deactivated one other than the
     * type the user already has, keeps $current (the form and the CSV import
     * refuse deactivated types too).
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
        $types = (new CirculationPolicy($db))->types();
        if (!isset($types[$id])) {
            return $current;
        }
        return ((int) ($types[$id]['attiva'] ?? 1) === 1 || $id === $current) ? $id : $current;
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
        if (!Features::enabled('automatic_card_expiry')) {
            return null;
        }
        $days = (new CirculationPolicy($db))->forType($typeId)->user->cardDurationDays;
        return $days !== null ? self::todayPlus($days) : null;
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
    public static function nameOf(mysqli $db, mixed $typeId, ?CirculationPolicy $policy = null): string
    {
        return self::fieldOf($db, $typeId, 'nome', $policy);
    }

    /**
     * Code of a stored patron type (null/unknown = the default type). The
     * users CSV export writes it beside the name: the import resolves a type
     * by its code first, so an export re-imports exactly on an installation
     * with the same types, even when two of them share a name.
     */
    public static function codeOf(mysqli $db, mixed $typeId, ?CirculationPolicy $policy = null): string
    {
        return self::fieldOf($db, $typeId, 'codice', $policy);
    }

    /**
     * $policy: pass one instance when resolving many rows (the users export),
     * so the patron types are read once instead of once per call.
     */
    private static function fieldOf(mysqli $db, mixed $typeId, string $field, ?CirculationPolicy $policy = null): string
    {
        $policy ??= new CirculationPolicy($db);
        $types = $policy->types();
        $id = is_numeric($typeId) ? (int) $typeId : null;
        $id = $id !== null && isset($types[$id]) ? $id : $policy->defaultTypeId();
        return $id !== null ? (string) ($types[$id][$field] ?? '') : '';
    }

    /** Card validity used by "Rinnova tessera" when the patron type sets none. */
    public const DEFAULT_RENEWAL_DAYS = 365;

    /**
     * Renew the library card of $userId: expiry = today + the card duration
     * of the patron type (DEFAULT_RENEWAL_DAYS when the type sets none), an
     * expired account becomes active again and the expiry warning re-arms.
     * Returns the new expiry date, or null when the account has no card
     * (admin/staff) or does not exist.
     */
    public static function renewCard(mysqli $db, int $userId): ?string
    {
        $stmt = $db->prepare('SELECT id, tipo_utente, stato, data_scadenza_tessera FROM utenti WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || !in_array($user['tipo_utente'], ['standard', 'premium'], true)) {
            return null;
        }
        $policy = new CirculationPolicy($db);
        $days = $policy->forUser($userId)->cardDurationDays ?? self::DEFAULT_RENEWAL_DAYS;
        $expiry = self::renewalDate($days, $user['data_scadenza_tessera'],
            (string) (new \App\Models\SettingsRepository($db))->get('loans', 'card_renewal_base', 'today'));
        $state = $user['stato'] === 'scaduto' ? 'attivo' : (string) $user['stato'];
        $flagSql = self::hasExpiryWarningFlag($db) ? ', card_expiry_warning_sent_for = NULL' : '';
        $update = $db->prepare('UPDATE utenti SET data_scadenza_tessera = ?, stato = ?' . $flagSql . ' WHERE id = ?');
        $update->bind_param('ssi', $expiry, $state, $userId);
        $update->execute();
        $update->close();
        ActivityLog::recordEntityEvent(
            $db,
            'utenti',
            $userId,
            'user.card_renewed',
            ['data_scadenza_tessera' => $user['data_scadenza_tessera'], 'stato' => $user['stato']],
            ['data_scadenza_tessera' => $expiry, 'stato' => $state],
            source: 'manual'
        );
        return $expiry;
    }

    /** Shared by the confirmation preview and the actual renewal. */
    public static function renewalDate(int $days, ?string $currentExpiry, string $baseMode = 'today'): string
    {
        $base = DateHelper::today();
        if ($baseMode === 'expiry' && $currentExpiry !== null
            && DateHelper::isISODateFormat($currentExpiry) && $currentExpiry > $base) {
            $base = $currentExpiry;
        }
        $expiry = (new \DateTimeImmutable($base))->modify('+' . max(0, $days) . ' days');
        // MySQL DATE ends at 9999-12-31; lifetime cards must remain renewable.
        return (int) $expiry->format('Y') > 9999 ? '9999-12-31' : $expiry->format('Y-m-d');
    }

    /**
     * Apply the patron type's card duration on registration approval: only
     * when the type sets one (otherwise the stored expiry is kept).
     */
    public static function applyCardDurationOnApproval(mysqli $db, int $userId): void
    {
        if (!Features::enabled('automatic_card_expiry')) {
            return;
        }
        $policy = new CirculationPolicy($db);
        $days = $policy->forUser($userId)->cardDurationDays;
        if ($days === null) {
            return;
        }
        $expiry = self::todayPlus($days);
        $stmt = $db->prepare("UPDATE utenti SET data_scadenza_tessera = ? WHERE id = ? AND tipo_utente IN ('standard', 'premium')");
        $stmt->bind_param('si', $expiry, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * today + $days as a calendar date. Card expiries are not loan due dates:
     * they deliberately bypass CirculationPolicy::dueDateFor(), which the
     * closures calendar shifts to the next open day.
     */
    public static function todayPlus(int $days): string
    {
        return (new \DateTimeImmutable(DateHelper::today()))->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
    }

    private static function hasExpiryWarningFlag(mysqli $db): bool
    {
        $result = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'card_expiry_warning_sent_for'");
        return $result instanceof \mysqli_result && (int) ($result->fetch_row()[0] ?? 0) === 1;
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
