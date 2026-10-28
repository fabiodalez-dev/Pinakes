<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SettingsRepository;
use App\Services\Circulation\LoanPolicy;
use App\Services\Circulation\PolicyResolver;
use App\Services\Circulation\UserPolicy;
use mysqli;

/**
 * The single source of the circulation rules (loan duration, request window,
 * renewals, quantity limits).
 *
 * Every gate — staff desk, reader requests on the website and the app,
 * approval, queue promotion, renewal, NCIP — reads its numbers from here, so
 * a rule can never apply on one channel and not on another. The CI guard in
 * ci-quality.yml rejects a direct read of the loans settings anywhere else.
 *
 * Rows are cached per instance; counts are never cached (callers count under
 * their own locks).
 */
final class CirculationPolicy
{
    /**
     * Loan states that occupy an active-loan slot. This is the exact set every
     * max-active-loans check has always counted (attivo = 1 in these states).
     */
    public const ACTIVE_LOAN_STATES = ['prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'];
    public const ACTIVE_LOAN_STATES_SQL = "'prenotato','da_ritirare','in_corso','in_ritardo'";

    /** @var array<string, mixed>|null */
    private ?array $globals = null;

    private SettingsRepository $settings;

    public function __construct(private mysqli $db, ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository($db);
    }

    /**
     * The final due date of a loan or renewal that starts (or is extended
     * from) $startYmd and lasts $days days.
     *
     * Every due date in the application is computed here. The closures
     * calendar hooks in at this point to move a date that falls on a closed
     * day forward to the next open day.
     */
    public static function dueDateFor(string $startYmd, int $days): string
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($startYmd, 0, 10));
        if ($start === false) {
            $start = new \DateTimeImmutable($startYmd);
        }
        return $start->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
    }

    /** Rules that depend only on the patron. */
    public function forUser(int $userId): UserPolicy
    {
        return PolicyResolver::resolveUser(null, $this->globalLayer());
    }

    /** Rules for $userId borrowing title $libroId (optionally the copy $copiaId). */
    public function forLoan(int $userId, int $libroId, ?int $copiaId = null): LoanPolicy
    {
        $user = $this->forUser($userId);
        return PolicyResolver::resolve([], $this->globalLayer(), $user, '*');
    }

    /**
     * Rules for an existing loan row ($userId overrides the borrower, e.g. on
     * reassignment). Falls back to the global rules when the row is gone.
     */
    public function forExistingLoan(int $loanId, ?int $userId = null): LoanPolicy
    {
        $stmt = $this->db->prepare('SELECT utente_id, libro_id, copia_id FROM prestiti WHERE id = ?');
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return $this->defaults();
        }
        return $this->forLoan(
            $userId ?? (int) $row['utente_id'],
            (int) $row['libro_id'],
            $row['copia_id'] !== null ? (int) $row['copia_id'] : null
        );
    }

    /** Rules for the patron and title of an existing reservation (globals when it is gone). */
    public function forReservation(int $reservationId): LoanPolicy
    {
        $stmt = $this->db->prepare('SELECT utente_id, libro_id FROM prenotazioni WHERE id = ?');
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return $this->defaults();
        }
        return $this->forLoan((int) $row['utente_id'], (int) $row['libro_id']);
    }

    /** The global rules, for contexts with no patron (admin defaults, dashboards). */
    public function defaults(): LoanPolicy
    {
        $globals = $this->globalLayer();
        return PolicyResolver::resolve([], $globals, PolicyResolver::resolveUser(null, $globals), '*');
    }

    /**
     * The global layer, read from the loans settings with the historical
     * fallbacks (30-day duration, 90-day request cap, 3 renewals, no limit).
     *
     * @return array<string, mixed>
     */
    public function globalLayer(): array
    {
        if ($this->globals === null) {
            $rawRenewals = $this->settings->get('loans', 'max_renewals', '3');
            $renewals = is_numeric($rawRenewals) ? (int) $rawRenewals : 3;
            $duration = (int) ($this->settings->get('loans', 'loan_duration_days', '30') ?? 30);
            $maxRequest = (int) ($this->settings->get('loans', 'max_loan_duration_days', '90') ?? 90);
            $this->globals = [
                'loanable' => true,
                'duration_days' => $duration >= 1 ? $duration : 30,
                'max_request_days' => $maxRequest >= 1 ? $maxRequest : 90,
                'max_renewals' => $renewals >= 0 ? $renewals : 3,
                'renewal_days' => null,
                'max_active_for_media' => null,
                'max_active_loans' => max(0, (int) ($this->settings->get('loans', 'max_active_loans_per_user', '0') ?? 0)),
                'reservations_enabled' => true,
                'max_reservations' => 0,
                'card_duration_days' => null,
                'debt_block_threshold' => null,
                'daily_fine' => null,
                'grace_days' => null,
                'max_fine_per_loan' => null,
            ];
        }
        return $this->globals;
    }

    /**
     * Open loans of $userId that count against the active-loan limit.
     * Callers that enforce a limit hold the user row lock first.
     */
    public function countActiveLoans(int $userId, ?int $excludeLoanId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 1 AND stato IN (' . self::ACTIVE_LOAN_STATES_SQL . ')';
        if ($excludeLoanId !== null) {
            $sql .= ' AND id <> ?';
        }
        $stmt = $this->db->prepare($sql);
        if ($excludeLoanId !== null) {
            $stmt->bind_param('ii', $userId, $excludeLoanId);
        } else {
            $stmt->bind_param('i', $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result ? ($result->fetch_row()[0] ?? 0) : 0);
        $stmt->close();
        return $count;
    }

    /**
     * The quantity/loanability refusal for a new open loan, or null.
     *
     * Codes: 'not_loanable', 'max_loans_reached', 'media_limit_reached'.
     * $excludeLoanId leaves out the loan being approved/reassigned.
     */
    public function loanLimitError(LoanPolicy $policy, int $userId, ?int $excludeLoanId = null): ?string
    {
        if (!$policy->loanable) {
            return 'not_loanable';
        }
        if ($policy->maxActiveLoans > 0 && $this->countActiveLoans($userId, $excludeLoanId) >= $policy->maxActiveLoans) {
            return 'max_loans_reached';
        }
        return null;
    }

    /**
     * SQL expression evaluating to the total active-loan limit of the patron
     * row aliased $userAlias (utenti); 0 = no limit. No placeholders.
     * Queue selection uses it to skip patrons already at their limit.
     */
    public function totalLoanLimitSql(string $userAlias): string
    {
        return (string) (int) $this->globalLayer()['max_active_loans'];
    }

    /** Whether any patron can be subject to a total active-loan limit. */
    public function anyTotalLoanLimit(): bool
    {
        return (int) $this->globalLayer()['max_active_loans'] > 0;
    }
}
