<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Circulation\LoanPolicy;
use App\Services\Circulation\PolicyResolver;
use mysqli;

/**
 * Fine and debt-block parameters for one loan or one patron.
 *
 * A thin adapter over CirculationPolicy: the values are resolved there, per
 * field, rule (patron type × material) → patron type → global loans settings
 * (Settings → Loans → Fines, which are the global layer of
 * CirculationPolicy::globalLayer()). This class only turns them into integer
 * cents for the arithmetic, so the fines code never reads a setting itself.
 *
 * Money is kept in integer cents so sums and caps never drift on float maths.
 * A daily fine of 0/empty (the default) means fines are disabled; a debt
 * threshold of 0/empty means no debt block — an install that never opens the
 * settings behaves exactly as before the feature existed.
 */
final class FineParameters
{
    public const SETTING_DAILY_FINE = 'daily_fine';
    public const SETTING_GRACE_DAYS = 'fine_grace_days';
    public const SETTING_MAX_PER_LOAN = 'fine_max_per_loan';
    public const SETTING_DEBT_THRESHOLD = 'debt_block_threshold';
    public const SETTING_FINE_EMAIL = 'fine_charged_email';

    /**
     * Largest amount a fine setting accepts (daily fine, cap per loan, debt
     * threshold, in the loans settings, the patron type and the rules matrix):
     * 9999.99. The ledger column is DECIMAL(10,2); the fine of one loan is
     * clamped at PatronLedger::MAX_AMOUNT_CENTS whatever the settings say.
     */
    public const MAX_SETTING_CENTS = 999999;

    public function __construct(
        public readonly int $dailyFineCents,
        public readonly int $graceDays,
        public readonly ?int $maxFinePerLoanCents,
        public readonly ?int $debtBlockThresholdCents,
        public readonly string $currency,
    ) {
    }

    /** Parameters that govern the late fine of one loan (its patron's type × its material). */
    public static function forLoan(mysqli $db, int $loanId, ?CirculationPolicy $policy = null): self
    {
        return self::fromPolicy(($policy ?? new CirculationPolicy($db))->forExistingLoan($loanId));
    }

    /**
     * Parameters of a patron regardless of the material: the patron type's
     * fine fields and debt threshold over the globals (reader record, app).
     */
    public static function forUser(mysqli $db, int $userId, ?CirculationPolicy $policy = null): self
    {
        $policy ??= new CirculationPolicy($db);
        return self::fromPolicy($policy->forType($policy->patronTypeOf($userId)));
    }

    /** The global settings only (no patron type, no rule). */
    public static function global(mysqli $db): self
    {
        $globals = (new CirculationPolicy($db))->globalLayer();
        return self::fromPolicy(PolicyResolver::resolve([], $globals, PolicyResolver::resolveUser(null, $globals)));
    }

    /** Cents view of a resolved circulation policy. */
    public static function fromPolicy(LoanPolicy $policy, ?string $currency = null): self
    {
        $daily = self::moneyToCents($policy->dailyFine) ?? 0;
        $cap = self::moneyToCents($policy->maxFinePerLoan);
        $threshold = self::moneyToCents($policy->debtBlockThreshold);

        return new self(
            max(0, $daily),
            max(0, min(365, (int) ($policy->graceDays ?? 0))),
            $cap !== null && $cap > 0 ? $cap : null,
            $threshold !== null && $threshold > 0 ? $threshold : null,
            $currency ?? self::currency(),
        );
    }

    /** A resolved amount (float from PolicyResolver) to cents; null stays null. */
    public static function moneyToCents(?float $amount): ?int
    {
        return $amount === null ? null : (int) round($amount * 100);
    }

    /**
     * Build from raw setting strings (unit-testable, no database).
     *
     * @param array<string, string> $settings
     */
    public static function fromSettings(array $settings, string $currency = 'EUR'): self
    {
        $daily = self::toCents($settings[self::SETTING_DAILY_FINE] ?? '') ?? 0;
        $grace = max(0, min(365, (int) ($settings[self::SETTING_GRACE_DAYS] ?? 0)));
        $cap = self::toCents($settings[self::SETTING_MAX_PER_LOAN] ?? '');
        $threshold = self::toCents($settings[self::SETTING_DEBT_THRESHOLD] ?? '');

        return new self(
            max(0, $daily),
            $grace,
            $cap !== null && $cap > 0 ? $cap : null,
            $threshold !== null && $threshold > 0 ? $threshold : null,
            $currency,
        );
    }

    public function finesEnabled(): bool
    {
        return $this->dailyFineCents > 0;
    }

    public function debtBlockEnabled(): bool
    {
        return $this->debtBlockThresholdCents !== null;
    }

    /** ISO code configured for the library (app.currency, default EUR). */
    public static function currency(): string
    {
        $code = strtoupper(trim((string) \App\Support\ConfigStore::get('app.currency', 'EUR')));
        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : 'EUR';
    }

    /** Whether an operator-typed amount parses but is above MAX_SETTING_CENTS. */
    public static function exceedsSettingMax(string $raw): bool
    {
        $cents = self::toCents($raw);
        return $cents !== null && $cents > self::MAX_SETTING_CENTS;
    }

    /**
     * Parse an operator-typed amount ("2", "2.5", "2,50") into cents.
     * Returns null for an empty or malformed value (never a negative amount).
     */
    public static function toCents(string $raw): ?int
    {
        $value = str_replace(',', '.', trim($raw));
        if ($value === '' || preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value) !== 1) {
            return null;
        }
        [$units, $decimals] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $units) * 100 + (int) str_pad($decimals, 2, '0');
    }

    /** Cents to the DECIMAL(10,2) string the ledger stores. */
    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** DECIMAL string ("-12.50") to cents. */
    public static function decimalToCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        return (int) round(((float) $value) * 100);
    }

    /** Human amount with the configured currency, in the given locale. */
    public static function format(int $cents, ?string $locale = null, ?string $currency = null): string
    {
        $currency ??= self::currency();
        $lang = substr($locale ?? \App\Support\I18n::getLocale(), 0, 2);
        $decimalSep = in_array($lang, ['it', 'de', 'fr', 'da'], true) ? ',' : '.';
        $thousandSep = $decimalSep === ',' ? '.' : ',';
        $number = number_format(abs($cents) / 100, 2, $decimalSep, $thousandSep);
        $symbol = $currency === 'EUR' ? '€' : $currency;
        return ($cents < 0 ? '-' : '') . ($currency === 'EUR' ? $number . ' ' . $symbol : $symbol . ' ' . $number);
    }
}
