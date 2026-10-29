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
 * renewals, quantity and reservation limits, card validity).
 *
 * Every gate — staff desk, reader requests on the website and the app,
 * approval, queue promotion, renewal, NCIP — reads its numbers from here, so
 * a rule can never apply on one channel and not on another. The CI guard in
 * ci-quality.yml rejects a direct read of the loans settings anywhere else.
 *
 * Rules come from five layers resolved per field by PolicyResolver: the
 * matrix rule (patron type, material), the rule (patron type, every
 * material), the rule (every patron type, material), the patron type, the
 * global loans settings. A patron without a type gets the default type.
 *
 * Rows are cached per instance; counts are never cached (callers count under
 * their own locks).
 */
final class CirculationPolicy
{
    // @include-soft-deleted: the two reads of libri here (the material of a
    // title, the loans counted per material) must keep seeing a title archived
    // while a copy is still out: its loan keeps its rules and still counts.
    /**
     * Loan states that occupy an active-loan slot. This is the exact set every
     * max-active-loans check has always counted (attivo = 1 in these states).
     */
    public const ACTIVE_LOAN_STATES = ['prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'];
    public const ACTIVE_LOAN_STATES_SQL = "'prenotato','da_ritirare','in_corso','in_ritardo'";

    /** Matrix sentinels (no NULL in the UNIQUE key of regole_prestito). */
    public const ALL_TYPES = 0;
    public const ALL_MEDIA = '*';

    /** @var array<string, bool> schema availability per connection (one information_schema read per request) */
    private static array $schemaReady = [];

    /** @var array<string, mixed>|null */
    private ?array $globals = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $types = null;
    private ?int $defaultTypeId = null;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $rules = null;
    /** @var array<int, int|null> */
    private array $userTypes = [];
    /** @var array<int, string> */
    private array $media = [];

    private SettingsRepository $settings;

    public function __construct(private mysqli $db, ?SettingsRepository $settings = null, private bool $configuration = false)
    {
        $this->settings = $settings ?? new SettingsRepository($db);
    }

    /**
     * The final due date of a loan or renewal that starts (or is extended
     * from) $startYmd and lasts $days days.
     *
     * Every due date in the application is computed here. With a connection
     * the closures calendar applies: a date that falls on a closed day moves
     * forward to the next open day (plan §13, decision 7), including the
     * closures of the copy's branch when $copiaId is known. A due date the
     * staff or the reader typed goes through here with $days = 0, so it is
     * moved by the same rule. Without closures (or without $db) the result is
     * the plain calendar arithmetic of every release before 0.9.0.
     */
    public static function dueDateFor(string $startYmd, int $days, ?mysqli $db = null, ?int $copiaId = null): string
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($startYmd, 0, 10));
        if ($start === false) {
            $start = new \DateTimeImmutable($startYmd);
        }
        $due = $start->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
        return $db === null ? $due : \App\Support\LibraryCalendar::dueDate($db, $due, $copiaId);
    }

    /** Drop the per-request schema probe (tests that migrate a database mid-process). */
    public static function resetSchemaCache(): void
    {
        self::$schemaReady = [];
    }

    /** Rules that depend only on the patron. */
    public function forUser(int $userId): UserPolicy
    {
        return PolicyResolver::resolveUser($this->userTypeLayer($this->patronTypeOf($userId)), $this->globalLayer());
    }

    /** Rules for $userId borrowing title $libroId (optionally the copy $copiaId). */
    public function forLoan(int $userId, int $libroId, ?int $copiaId = null): LoanPolicy
    {
        return $this->forTypeAndTitle($this->patronTypeOf($userId), $libroId, $copiaId);
    }

    /**
     * Rules for whoever views a title: the patron's when logged in, the
     * default patron type's for an anonymous visitor (book page buttons).
     */
    public function forViewer(?int $userId, int $libroId): LoanPolicy
    {
        return $userId !== null && $userId > 0
            ? $this->forLoan($userId, $libroId)
            : $this->forTypeAndTitle($this->defaultTypeId(), $libroId, null);
    }

    private function forTypeAndTitle(?int $typeId, int $libroId, ?int $copiaId): LoanPolicy
    {
        $user = PolicyResolver::resolveUser($this->userTypeLayer($typeId), $this->globalLayer());
        $media = $this->mediaOf($libroId);
        $layers = $this->ruleLayers($typeId, $media);
        // The single copy kept for consultation, or a title whose every copy
        // still in circulation is a consultation copy, is not lent.
        if ($copiaId !== null && $copiaId > 0
            ? $this->isConsultationCopy($copiaId)
            : $this->onlyConsultationCopies($libroId)) {
            array_unshift($layers, ['loanable' => false]);
        }
        return PolicyResolver::resolve($layers, $this->globalLayer(), $user, $media);
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

    /** The rules of the default patron type, for contexts with no patron (admin defaults, dashboards). */
    public function defaults(): LoanPolicy
    {
        $typeId = $this->defaultTypeId();
        $globals = $this->globalLayer();
        return PolicyResolver::resolve(
            $this->ruleLayers($typeId, self::ALL_MEDIA),
            $globals,
            PolicyResolver::resolveUser($this->userTypeLayer($typeId), $globals),
            self::ALL_MEDIA
        );
    }

    /**
     * Rules of a patron type for a material, without a patron (admin matrix,
     * effective-rules summaries). $typeId null = the default type.
     */
    public function forType(?int $typeId, string $media = self::ALL_MEDIA): LoanPolicy
    {
        $typeId ??= $this->defaultTypeId();
        $globals = $this->globalLayer();
        return PolicyResolver::resolve(
            $this->ruleLayers($typeId, $media),
            $globals,
            PolicyResolver::resolveUser($this->userTypeLayer($typeId), $globals),
            $media
        );
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
                // Fines (Settings → Loans → Fines). Amounts are normalised to
                // DECIMAL strings; empty or malformed = null (no fine, no cap,
                // no debt block), exactly the pre-0.9.0 behaviour.
                'debt_block_threshold' => self::moneyOrNull($this->settings->get('loans', 'debt_block_threshold', '')),
                'daily_fine' => self::moneyOrNull($this->settings->get('loans', 'daily_fine', '')),
                'grace_days' => self::graceOrNull($this->settings->get('loans', 'fine_grace_days', '')),
                'max_fine_per_loan' => self::moneyOrNull($this->settings->get('loans', 'fine_max_per_loan', '')),
            ];
        }
        return $this->globals;
    }

    /** An operator-typed amount ("2", "2,50") as a DECIMAL string, null when empty or malformed. */
    private static function moneyOrNull(?string $raw): ?string
    {
        $cents = FineParameters::toCents((string) $raw);
        return $cents === null ? null : FineParameters::centsToDecimal($cents);
    }

    /** Global grace days (0 … 365); null when unset or 0, which is the same rule: no grace. */
    private static function graceOrNull(?string $raw): ?int
    {
        $days = max(0, min(365, (int) ($raw ?? 0)));
        return $days > 0 ? $days : null;
    }

    // ─── Counting and refusals ──────────────────────────────────────────────

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

    /** Open loans of $userId on titles of material $media (same states as countActiveLoans()). */
    public function countActiveLoansForMedia(int $userId, string $media, ?int $excludeLoanId = null): int
    {
        // CI-SOFT-DELETE-EXEMPT: a loan on a title archived after checkout is still out and still counts against the limit.
        $sql = 'SELECT COUNT(*) FROM prestiti p JOIN libri l ON l.id = p.libro_id
                WHERE p.utente_id = ? AND p.attivo = 1 AND p.stato IN (' . self::ACTIVE_LOAN_STATES_SQL . ')
                  AND l.tipo_media = ?';
        if ($excludeLoanId !== null) {
            $sql .= ' AND p.id <> ?';
        }
        $stmt = $this->db->prepare($sql);
        if ($excludeLoanId !== null) {
            $stmt->bind_param('isi', $userId, $media, $excludeLoanId);
        } else {
            $stmt->bind_param('is', $userId, $media);
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
     * $excludeLoanId leaves out the loan being approved/reassigned. Both
     * limits apply: the total one and the one for the material.
     */
    public function loanLimitError(LoanPolicy $policy, int $userId, ?int $excludeLoanId = null): ?string
    {
        if (!$policy->loanable) {
            return 'not_loanable';
        }
        if ($policy->maxActiveLoans > 0 && $this->countActiveLoans($userId, $excludeLoanId) >= $policy->maxActiveLoans) {
            return 'max_loans_reached';
        }
        if ($policy->maxActiveForMedia > 0 && $policy->mediaType !== self::ALL_MEDIA
            && $this->countActiveLoansForMedia($userId, $policy->mediaType, $excludeLoanId) >= $policy->maxActiveForMedia) {
            return 'media_limit_reached';
        }
        return null;
    }

    /**
     * Patrons holding an active reservation on $libroId whom the per-material
     * rules refuse right now: the title's material is not loanable for their
     * patron type, or they already hold the limit for that material. Queue
     * promotion (ReservationManager) and capacity occupancy (CapacityService)
     * both leave their promotable reservations aside, the same pause the
     * total-limit SQL predicate applies, so a refused head of the queue does
     * not block the patrons behind it. Empty without rules-matrix rows (a
     * zero-config install never pauses anyone here).
     *
     * @return list<int>
     */
    public function materialRefusedReservationUserIds(int $libroId): array
    {
        if ($this->rules() === []) {
            return [];
        }
        $media = $this->mediaOf($libroId);
        $stmt = $this->db->prepare("SELECT DISTINCT utente_id FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userIds = [];
        while ($result && ($row = $result->fetch_row())) {
            $userIds[] = (int) $row[0];
        }
        $stmt->close();

        $refused = [];
        foreach ($userIds as $userId) {
            $policy = $this->forType($this->patronTypeOf($userId), $media);
            if (!$policy->loanable
                || ($policy->maxActiveForMedia > 0 && $media !== self::ALL_MEDIA
                    && $this->countActiveLoansForMedia($userId, $media) >= $policy->maxActiveForMedia)) {
                $refused[] = $userId;
            }
        }
        return $refused;
    }

    /**
     * The debt-block refusal for $userId, or null (plan §7, decision 6).
     *
     * The threshold is the patron type's (soglia_blocco_debito), else the
     * global loans.debt_block_threshold; NULL/0 = no block. Every channel
     * reaches it through LoanEligibility::checkUser(), and its SQL twin is
     * LoanEligibility::debtBlockSql() (the queue selection).
     */
    public function debtBlockError(int $userId): ?string
    {
        // No ledger table (code deployed before its migration): no balance, no block.
        if (!PatronLedger::active($this->db) || !\App\Support\Features::enabled('debt_block')) {
            return null;
        }
        $threshold = FineParameters::moneyToCents($this->forUser($userId)->debtBlockThreshold);
        if ($threshold === null || $threshold <= 0) {
            return null;
        }
        return PatronLedger::forDb($this->db)->balanceCents($userId) > $threshold ? 'debt_block' : null;
    }

    /**
     * Active queue reservations plus loan requests still awaiting approval:
     * what the reservation limit counts (plan decision 4).
     */
    public function countReservations(int $userId, ?int $excludeReservationId = null): int
    {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM prenotazioni WHERE utente_id = ? AND stato = 'attiva'" . ($excludeReservationId !== null ? ' AND id <> ?' : '') . ")
                  + (SELECT COUNT(*) FROM prestiti WHERE utente_id = ? AND attivo = 0 AND stato = 'pendente')";
        $stmt = $this->db->prepare($sql);
        if ($excludeReservationId !== null) {
            $stmt->bind_param('iii', $userId, $excludeReservationId, $userId);
        } else {
            $stmt->bind_param('ii', $userId, $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result ? ($result->fetch_row()[0] ?? 0) : 0);
        $stmt->close();
        return $count;
    }

    /**
     * Refusal for a new queue reservation ($queue = true) or reader loan
     * request ($queue = false), or null. Codes: 'reservations_disabled'
     * (queue reservations only), 'reservation_limit_reached'.
     */
    public function reservationLimitError(UserPolicy $user, int $userId, bool $queue = true): ?string
    {
        if ($queue && !$user->reservationsEnabled) {
            return 'reservations_disabled';
        }
        if ($user->maxReservations > 0 && $this->countReservations($userId) >= $user->maxReservations) {
            return 'reservation_limit_reached';
        }
        return null;
    }

    /**
     * SQL expression evaluating to the total active-loan limit of the patron
     * row aliased $userAlias (utenti); 0 = no limit. No placeholders: the ids
     * and the global value are integers rendered by the application.
     * Queue selection uses it to skip patrons already at their limit.
     */
    public function totalLoanLimitSql(string $userAlias): string
    {
        $global = (int) $this->globalLayer()['max_active_loans'];
        if (!$this->rulesActive()) {
            return (string) $global;
        }
        $default = $this->defaultTypeId() ?? 0;
        return "COALESCE((SELECT tl.max_prestiti FROM tipologie_lettore tl WHERE tl.id = COALESCE({$userAlias}.tipologia_id, {$default})), {$global})";
    }

    /** Whether any patron can be subject to a total active-loan limit. */
    public function anyTotalLoanLimit(): bool
    {
        if ((int) $this->globalLayer()['max_active_loans'] > 0) {
            return true;
        }
        foreach ($this->types() as $type) {
            if ($type['max_prestiti'] !== null && (int) $type['max_prestiti'] > 0) {
                return true;
            }
        }
        return false;
    }

    // ─── Patron types and matrix ────────────────────────────────────────────

    /**
     * Patron types ordered for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            if ($this->rulesActive()) {
                $result = $this->db->query('SELECT * FROM tipologie_lettore ORDER BY ordinamento ASC, nome ASC, id ASC');
                if ($result instanceof \mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $this->types[(int) $row['id']] = $row;
                        if ((int) $row['predefinita'] === 1 && $this->defaultTypeId === null) {
                            $this->defaultTypeId = (int) $row['id'];
                        }
                    }
                    $result->free();
                }
            }
        }
        return $this->types;
    }

    /** The patron type assigned to users without one (null when none exists). */
    public function defaultTypeId(): ?int
    {
        $this->types();
        return $this->defaultTypeId;
    }

    /**
     * Matrix rules keyed "typeId|media".
     *
     * @return array<string, array<string, mixed>>
     */
    public function rules(): array
    {
        if ($this->rules === null) {
            $this->rules = [];
            if ($this->rulesActive()) {
                $result = $this->db->query('SELECT * FROM regole_prestito');
                if ($result instanceof \mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $this->rules[(int) $row['tipologia_id'] . '|' . (string) $row['tipo_media']] = $row;
                    }
                    $result->free();
                }
            }
        }
        return $this->rules;
    }

    /** The patron type governing $userId: theirs, else the default one. */
    public function patronTypeOf(int $userId): ?int
    {
        if (!$this->rulesActive()) {
            return null;
        }
        if (!array_key_exists($userId, $this->userTypes)) {
            $stmt = $this->db->prepare('SELECT tipologia_id FROM utenti WHERE id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $own = is_array($row) && $row['tipologia_id'] !== null ? (int) $row['tipologia_id'] : null;
            $this->userTypes[$userId] = ($own !== null && isset($this->types()[$own])) ? $own : $this->defaultTypeId();
        }
        return $this->userTypes[$userId];
    }

    /**
     * The layers of $typeId for $media, most specific first (see the class
     * docblock), as PolicyResolver field arrays.
     *
     * @return list<array<string, mixed>|null>
     */
    public function ruleLayers(?int $typeId, string $media): array
    {
        $rules = $this->rules();
        $layers = [];
        if ($typeId !== null) {
            if ($media !== self::ALL_MEDIA) {
                $layers[] = self::ruleLayer($rules[$typeId . '|' . $media] ?? null, true);
            }
            $layers[] = self::ruleLayer($rules[$typeId . '|' . self::ALL_MEDIA] ?? null, false);
        }
        if ($media !== self::ALL_MEDIA) {
            $layers[] = self::ruleLayer($rules[self::ALL_TYPES . '|' . $media] ?? null, true);
        }
        $layers[] = $typeId !== null ? self::typeLayer($this->types()[$typeId] ?? null) : null;
        return $layers;
    }

    /**
     * @param array<string, mixed>|null $rule
     * @return array<string, mixed>|null
     */
    private static function ruleLayer(?array $rule, bool $concreteMedia): ?array
    {
        if ($rule === null) {
            return null;
        }
        return [
            'loanable' => $rule['prestabile'],
            'duration_days' => $rule['durata_prestito_giorni'],
            'max_renewals' => $rule['max_rinnovi'],
            'renewal_days' => $rule['giorni_rinnovo'],
            // The per-material limit exists only for a concrete material.
            'max_active_for_media' => $concreteMedia ? $rule['max_prestiti'] : null,
            'daily_fine' => $rule['multa_giornaliera'],
            'grace_days' => $rule['giorni_tolleranza'],
            'max_fine_per_loan' => $rule['multa_massima_prestito'],
        ];
    }

    /**
     * @param array<string, mixed>|null $type
     * @return array<string, mixed>|null
     */
    private static function typeLayer(?array $type): ?array
    {
        if ($type === null) {
            return null;
        }
        return [
            'duration_days' => $type['durata_prestito_giorni'],
            'max_request_days' => $type['max_durata_richiesta_giorni'],
            'max_renewals' => $type['max_rinnovi'],
            'renewal_days' => $type['giorni_rinnovo'],
            'daily_fine' => $type['multa_giornaliera'],
            'grace_days' => $type['giorni_tolleranza'],
            'max_fine_per_loan' => $type['multa_massima_prestito'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function userTypeLayer(?int $typeId): ?array
    {
        $type = $typeId !== null ? ($this->types()[$typeId] ?? null) : null;
        if ($type === null) {
            return null;
        }
        return [
            'id' => (int) $type['id'],
            'code' => (string) $type['codice'],
            'name' => (string) $type['nome'],
            'max_active_loans' => $type['max_prestiti'],
            'reservations_enabled' => $type['prenotazioni_abilitate'],
            'max_reservations' => $type['max_prenotazioni'],
            'card_duration_days' => $type['durata_tessera_giorni'],
            'debt_block_threshold' => $type['soglia_blocco_debito'],
        ];
    }

    /** libri.tipo_media of $libroId ('*' when the title is unknown). */
    public function mediaOf(int $libroId): string
    {
        if (!array_key_exists($libroId, $this->media)) {
            // CI-SOFT-DELETE-EXEMPT: the rules of a running loan stay those of its material after the title is archived.
            $stmt = $this->db->prepare('SELECT tipo_media FROM libri WHERE id = ?');
            $stmt->bind_param('i', $libroId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            $this->media[$libroId] = is_array($row) && is_string($row['tipo_media']) && $row['tipo_media'] !== ''
                ? $row['tipo_media']
                : self::ALL_MEDIA;
        }
        return $this->media[$libroId];
    }

    /** Whether the copy is kept for in-house consultation only. */
    public function isConsultationCopy(int $copiaId): bool
    {
        if (!\App\Support\Features::enabled('consultation_only') || !$this->schemaReady()) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT solo_consultazione FROM copie WHERE id = ?');
        $stmt->bind_param('i', $copiaId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) && (int) $row['solo_consultazione'] === 1;
    }

    /**
     * Whether $libroId has consultation copies and no other copy in
     * circulation (copies lost, damaged or in repair do not count).
     */
    public function onlyConsultationCopies(int $libroId): bool
    {
        if (!\App\Support\Features::enabled('consultation_only') || !$this->schemaReady()) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(solo_consultazione = 1), 0) AS consultation,
                    COALESCE(SUM(solo_consultazione = 0 AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')), 0) AS lendable
             FROM copie WHERE libro_id = ?"
        );
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) && (int) $row['consultation'] > 0 && (int) $row['lendable'] === 0;
    }

    /**
     * SQL predicate (no placeholders) excluding consultation-only copies from
     * lendable capacity and from copy allocation. $alias is the copie alias
     * (empty string for an unaliased table). Always true before the 0.9.0
     * migration has run.
     */
    public function lendableCopySql(string $alias = 'c'): string
    {
        if (!\App\Support\Features::enabled('consultation_only') || !$this->schemaReady()) {
            return '1 = 1';
        }
        return ($alias !== '' ? $alias . '.' : '') . 'solo_consultazione = 0';
    }

    /**
     * Whether patron types and the rules matrix
     * apply: their tables exist AND the module is on (Settings -> Loans,
     * features.circulation_rules; also off in catalogue mode). Off, every
     * rule is the global layer, exactly as before 0.9.0, on every channel
     * at once (web, Mobile API, NCIP, maintenance), since they all resolve
     * through this class. Consultation uses its own switch. The data stays: switching back on restores it.
     */
    public function rulesActive(): bool
    {
        return ($this->configuration || \App\Support\Features::enabled('circulation_rules')) && $this->schemaReady();
    }

    /**
     * Whether the 0.9.0 circulation tables and columns exist. Code ships
     * before its migration runs during an upgrade: until then every rule is
     * the global one, exactly as before.
     */
    public function schemaReady(): bool
    {
        $key = (string) spl_object_id($this->db);
        if (!array_key_exists($key, self::$schemaReady)) {
            $ready = false;
            try {
                $result = $this->db->query(
                    "SELECT
                        (SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('tipologie_lettore', 'regole_prestito'))
                      + (SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND ((TABLE_NAME = 'utenti' AND COLUMN_NAME = 'tipologia_id')
                              OR (TABLE_NAME = 'copie' AND COLUMN_NAME = 'solo_consultazione')))"
                );
                $ready = $result instanceof \mysqli_result && (int) ($result->fetch_row()[0] ?? 0) === 4;
            } catch (\Throwable) {
                $ready = false;
            }
            self::$schemaReady[$key] = $ready;
        }
        return self::$schemaReady[$key];
    }
}
