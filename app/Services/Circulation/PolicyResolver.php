<?php

declare(strict_types=1);

namespace App\Services\Circulation;

/**
 * Pure resolution of circulation rules from ordered layers (no database).
 *
 * For every field separately, the first non-null value wins, in this order:
 *   1. rule (patron type, material type)
 *   2. rule (patron type, '*')
 *   3. rule (all patron types, material type)
 *   4. patron type
 *   5. global loans settings
 *
 * Layers are associative arrays keyed by the field names in FIELDS; a missing
 * key or a null value means "inherit". The global layer is always last and is
 * the only one guaranteed complete. Values are normalised here so a malformed
 * override can never produce a non-positive duration.
 */
final class PolicyResolver
{
    public const FIELDS = [
        'loanable',
        'duration_days',
        'max_request_days',
        'max_renewals',
        'renewal_days',
        'max_active_for_media',
        'daily_fine',
        'grace_days',
        'max_fine_per_loan',
    ];

    /**
     * First non-null value of $field across $layers (in order).
     *
     * @param list<array<string, mixed>|null> $layers
     */
    public static function pick(array $layers, string $field): mixed
    {
        foreach ($layers as $layer) {
            if (is_array($layer) && array_key_exists($field, $layer) && $layer[$field] !== null && $layer[$field] !== '') {
                return $layer[$field];
            }
        }
        return null;
    }

    /**
     * @param list<array<string, mixed>|null> $layers rule/type layers, most specific first, WITHOUT the global one
     * @param array<string, mixed> $globals the complete global layer (see CirculationPolicy::globalLayer())
     */
    public static function resolve(array $layers, array $globals, UserPolicy $user, string $mediaType = '*'): LoanPolicy
    {
        $all = $layers;
        $all[] = $globals;

        $globalDuration = self::positiveInt($globals['duration_days'] ?? null, 30);
        $globalMaxRequest = self::positiveInt($globals['max_request_days'] ?? null, 90);
        $globalRenewals = self::nonNegativeInt($globals['max_renewals'] ?? null, 3);

        $duration = self::positiveInt(self::pick($all, 'duration_days'), $globalDuration);
        $renewal = self::pick($all, 'renewal_days');

        return new LoanPolicy(
            user: $user,
            mediaType: $mediaType,
            loanable: (bool) (self::pick($all, 'loanable') ?? true),
            durationDays: $duration,
            maxRequestDays: self::positiveInt(self::pick($all, 'max_request_days'), $globalMaxRequest),
            maxRenewals: self::nonNegativeInt(self::pick($all, 'max_renewals'), $globalRenewals),
            renewalDays: self::positiveInt($renewal, $duration),
            maxActiveLoans: $user->maxActiveLoans,
            maxActiveForMedia: self::nonNegativeInt(self::pick($all, 'max_active_for_media'), 0),
            dailyFine: self::nullableFloat(self::pick($all, 'daily_fine')),
            graceDays: self::nullableInt(self::pick($all, 'grace_days')),
            maxFinePerLoan: self::nullableFloat(self::pick($all, 'max_fine_per_loan')),
            debtBlockThreshold: $user->debtBlockThreshold,
        );
    }

    /**
     * Patron-only limits: patron type first, then the globals.
     *
     * @param array<string, mixed>|null $type patron type row (keys: id, code, name, max_active_loans,
     *                                        reservations_enabled, max_reservations, card_duration_days,
     *                                        debt_block_threshold)
     * @param array<string, mixed> $globals
     */
    public static function resolveUser(?array $type, array $globals): UserPolicy
    {
        $layers = [$type, $globals];
        $enabled = self::pick($layers, 'reservations_enabled');

        return new UserPolicy(
            patronTypeId: isset($type['id']) ? (int) $type['id'] : null,
            patronTypeCode: isset($type['code']) ? (string) $type['code'] : null,
            patronTypeName: isset($type['name']) ? (string) $type['name'] : null,
            maxActiveLoans: self::nonNegativeInt(self::pick($layers, 'max_active_loans'), 0),
            reservationsEnabled: $enabled === null ? true : (bool) $enabled,
            maxReservations: self::nonNegativeInt(self::pick($layers, 'max_reservations'), 0),
            cardDurationDays: self::nullablePositiveInt(self::pick($layers, 'card_duration_days')),
            debtBlockThreshold: self::nullableFloat(self::pick($layers, 'debt_block_threshold')),
        );
    }

    private static function positiveInt(mixed $value, int $fallback): int
    {
        if ($value === null || !is_numeric($value)) {
            return $fallback;
        }
        $int = (int) $value;
        return $int >= 1 ? $int : $fallback;
    }

    private static function nonNegativeInt(mixed $value, int $fallback): int
    {
        if ($value === null || !is_numeric($value)) {
            return $fallback;
        }
        $int = (int) $value;
        return $int >= 0 ? $int : $fallback;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : max(0, (int) $value);
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) || (int) $value < 1 ? null : (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null || !is_numeric($value) ? null : (float) $value;
    }
}
