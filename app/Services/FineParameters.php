<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Fine and debt-block parameters for one loan or one patron.
 *
 * This is the single place the fines feature reads its rules from. Today every
 * value comes from four global settings (Settings → Loans → Fines); once the
 * circulation-policy work lands, forLoan()/forUser() are the two methods to
 * re-point at CirculationPolicy (per patron type × material), and nothing else
 * in the fines code has to change.
 *
 * Money is kept in integer cents so sums and caps never drift on float maths.
 * A daily fine of 0 (the default) means fines are disabled; a debt threshold of
 * 0 means no debt block — an install that never opens the settings behaves
 * exactly as before the feature existed.
 */
final class FineParameters
{
    public const SETTING_DAILY_FINE = 'daily_fine';
    public const SETTING_GRACE_DAYS = 'fine_grace_days';
    public const SETTING_MAX_PER_LOAN = 'fine_max_per_loan';
    public const SETTING_DEBT_THRESHOLD = 'debt_block_threshold';
    public const SETTING_FINE_EMAIL = 'fine_charged_email';

    public function __construct(
        public readonly int $dailyFineCents,
        public readonly int $graceDays,
        public readonly ?int $maxFinePerLoanCents,
        public readonly ?int $debtBlockThresholdCents,
        public readonly string $currency,
    ) {
    }

    /**
     * Parameters that govern the late fine of one loan.
     * Stack hook: replace with CirculationPolicy::forLoan($userId, $libroId, $copiaId).
     */
    public static function forLoan(mysqli $db, int $loanId): self
    {
        return self::global($db);
    }

    /**
     * Parameters that depend on the patron only (debt block threshold).
     * Stack hook: replace with CirculationPolicy::forUser($userId)->debtBlockThreshold.
     */
    public static function forUser(mysqli $db, int $userId): self
    {
        return self::global($db);
    }

    /** The global settings, read in one query. */
    public static function global(mysqli $db): self
    {
        $settings = [];
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE category = 'loans' AND setting_key IN (?, ?, ?, ?)");
        if ($stmt !== false) {
            $keys = [self::SETTING_DAILY_FINE, self::SETTING_GRACE_DAYS, self::SETTING_MAX_PER_LOAN, self::SETTING_DEBT_THRESHOLD];
            $stmt->bind_param('ssss', $keys[0], $keys[1], $keys[2], $keys[3]);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
            $stmt->close();
        }

        return self::fromSettings($settings, self::currency());
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
