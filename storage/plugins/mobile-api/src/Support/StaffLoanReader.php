<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Support\DateHelper;
use App\Support\HtmlHelper;
use mysqli;

/**
 * Read side of the staff circulation area (Mobile API 1.7.0).
 *
 * The list queries mirror the five desk lists of
 * LoanApprovalController::pendingLoans() (pending, ready for pickup,
 * scheduled, active, overdue) plus the history; the joined `libri` row always
 * carries `deleted_at IS NULL`. Writes never happen here: every mutation is
 * delegated to the core controllers, and this class only reloads the row
 * afterwards so the API answers with the resource as it now is.
 */
final class StaffLoanReader
{
    public const BUCKETS = ['pending', 'pickup', 'scheduled', 'active', 'overdue', 'history', 'all'];
    public const RESERVATION_STATES = ['attiva', 'completata', 'annullata', 'all'];

    private ?int $maxRenewals = null;

    public function __construct(private mysqli $db)
    {
    }

    // ─── Loans ──────────────────────────────────────────────────────────────

    private const LOAN_SELECT = "SELECT p.id, p.libro_id, p.copia_id, p.utente_id, p.data_prestito, p.data_scadenza,
               p.pickup_deadline, p.data_restituzione, p.restituito_in_ritardo, p.stato,
               COALESCE(p.origine, 'richiesta') AS origine, p.sanzione, p.renewals, p.processed_by, p.note,
               p.attivo, p.recall_count, p.last_recall_at, p.created_at, p.updated_at,
               l.id AS book_id, l.titolo, l.copertina_url, l.isbn13,
               c.numero_inventario AS copy_code, c.stato AS copy_status,
               u.nome AS u_nome, u.cognome AS u_cognome, u.email AS u_email,
               u.codice_tessera AS u_card, u.stato AS u_stato,
               pb.nome AS pb_nome, pb.cognome AS pb_cognome
          FROM prestiti p
          LEFT JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
          LEFT JOIN copie c ON c.id = p.copia_id
          JOIN utenti u ON u.id = p.utente_id
          LEFT JOIN utenti pb ON pb.id = p.processed_by";

    /** @return array<string, mixed>|null raw prestiti row (no joins) */
    public function loanRow(int $id): ?array
    {
        return $this->one('SELECT * FROM prestiti WHERE id = ?', 'i', [$id]);
    }

    /** @return array<string, mixed>|null StaffLoan */
    public function loan(int $id): ?array
    {
        $row = $this->one(self::LOAN_SELECT . ' WHERE p.id = ?', 'i', [$id]);

        return $row === null ? null : $this->mapLoan($row);
    }

    /**
     * @param array{q?:string, user_id?:int, book_id?:int, from?:string, to?:string} $filters
     * @return array{items:list<array<string, mixed>>, total:int, has_more:bool}
     */
    public function loans(string $bucket, array $filters, int $offset, int $limit): array
    {
        $today = DateHelper::today();
        $where = ['l.id IS NOT NULL'];
        $types = '';
        $params = [];
        switch ($bucket) {
            case 'pending':
                $where[] = "p.stato = 'pendente'";
                $order = 'p.created_at ASC, p.id ASC';
                break;
            case 'pickup':
                $where[] = "p.attivo = 1 AND (p.stato = 'da_ritirare' OR (p.stato = 'prenotato' AND p.data_prestito <= ?))";
                $types .= 's';
                $params[] = $today;
                $order = 'CASE WHEN p.pickup_deadline IS NOT NULL THEN p.pickup_deadline ELSE p.data_prestito END ASC, p.id ASC';
                break;
            case 'scheduled':
                $where[] = "p.stato = 'prenotato' AND p.data_prestito > ? AND p.attivo = 1";
                $types .= 's';
                $params[] = $today;
                $order = 'p.data_prestito ASC, p.id ASC';
                break;
            case 'active':
                $where[] = "p.stato = 'in_corso' AND p.attivo = 1";
                $order = 'p.data_scadenza ASC, p.id ASC';
                break;
            case 'overdue':
                $where[] = "p.stato = 'in_ritardo' AND p.attivo = 1";
                $order = 'p.data_scadenza ASC, p.id ASC';
                break;
            case 'history':
                $where[] = "p.attivo = 0 AND p.stato <> 'pendente'";
                $order = 'p.id DESC';
                break;
            default:
                $order = 'p.id DESC';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        // Quoted value = exact spelling, accents included (#501).
        $search = $q !== ''
            ? \App\Support\ExactSearch::filterCondition(['l.titolo', 'u.nome', 'u.cognome', "CONCAT(u.nome, ' ', u.cognome)", 'u.email', 'u.codice_tessera', 'c.numero_inventario'], $q)
            : null;
        if ($search !== null) {
            $where[] = $search['sql'];
            $types .= $search['types'];
            array_push($params, ...$search['params']);
        }
        foreach (['user_id' => 'p.utente_id', 'book_id' => 'p.libro_id'] as $key => $column) {
            $value = (int) ($filters[$key] ?? 0);
            if ($value > 0) {
                $where[] = $column . ' = ?';
                $types .= 'i';
                $params[] = $value;
            }
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'p.data_prestito >= ?';
            $types .= 's';
            $params[] = (string) $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'p.data_prestito <= ?';
            $types .= 's';
            $params[] = (string) $filters['to'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->scalar(
            'SELECT COUNT(*) FROM prestiti p
               LEFT JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
               LEFT JOIN copie c ON c.id = p.copia_id
               JOIN utenti u ON u.id = p.utente_id' . $whereSql,
            $types,
            $params
        ) ?? 0);

        $fetch = $limit + 1;
        $rows = $this->all(
            self::LOAN_SELECT . $whereSql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            $types . 'ii',
            array_merge($params, [$fetch, $offset])
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'items'    => array_map(fn (array $r): array => $this->mapLoan($r), $rows),
            'total'    => $total,
            'has_more' => $hasMore,
        ];
    }

    /**
     * The loan this request created (PrestitiController::store() does not
     * return the id). Called under the per-title create lock: the row must be
     * a `diretto` loan above the pre-call high-water mark for this borrower
     * and title, processed by this operator — and exactly one such row must
     * exist, otherwise null (the caller answers 500 rather than a wrong id).
     */
    public function resolveNewLoanId(int $before, int $userId, int $bookId, int $staffId): ?int
    {
        $rows = $this->all(
            "SELECT id FROM prestiti WHERE id > ? AND utente_id = ? AND libro_id = ? AND origine = 'diretto' AND processed_by = ?
              ORDER BY id DESC LIMIT 2",
            'iiii',
            [$before, $userId, $bookId, $staffId]
        );

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    public function maxId(string $table): int
    {
        if (!in_array($table, ['prestiti', 'prenotazioni'], true)) {
            return 0;
        }

        return (int) ($this->scalar("SELECT COALESCE(MAX(id), 0) FROM {$table}", '', []) ?? 0);
    }

    /**
     * Active loan by copy code — the same lookup as PrestitiController::returnByCode().
     *
     * @return list<int>
     */
    public function activeLoanIdsByCopyCode(string $code): array
    {
        $rows = $this->all(
            "SELECT p.id FROM prestiti p JOIN copie c ON c.id = p.copia_id
              WHERE c.numero_inventario = ? AND p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo')",
            's',
            [$code]
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    // ─── Summary ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = DateHelper::today();
        $join = ' FROM prestiti p JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL';
        $count = fn (string $where, string $types = '', array $params = []): int
            => (int) ($this->scalar('SELECT COUNT(*)' . $join . ' WHERE ' . $where, $types, $params) ?? 0);

        $settings = new \App\Models\SettingsRepository($this->db);

        return [
            'pending'        => $count("p.stato = 'pendente'"),
            'pickup_ready'   => $count("p.attivo = 1 AND (p.stato = 'da_ritirare' OR (p.stato = 'prenotato' AND p.data_prestito <= ?))", 's', [$today]),
            'scheduled'      => $count("p.stato = 'prenotato' AND p.data_prestito > ? AND p.attivo = 1", 's', [$today]),
            'active'         => $count("p.stato = 'in_corso' AND p.attivo = 1"),
            'overdue'        => $count("p.stato = 'in_ritardo' AND p.attivo = 1"),
            'reservations'   => (int) ($this->scalar(
                "SELECT COUNT(*) FROM prenotazioni r JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL WHERE r.stato = 'attiva'",
                '',
                []
            ) ?? 0),
            'due_today'      => $count("p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo') AND p.data_scadenza = ?", 's', [$today]),
            'returned_today' => $count('p.data_restituzione = ?', 's', [$today]),
            'today'          => $today,
            'settings'       => [
                'loan_duration_days'             => $settings->loanDurationDays(),
                'pickup_expiry_days'             => (int) ($settings->get('loans', 'pickup_expiry_days', '3') ?? 3),
                'max_renewals'                   => $this->maxRenewals(),
                'max_active_loans_per_user'      => (int) ($settings->get('loans', 'max_active_loans_per_user', '0') ?? 0),
                'allow_multiple_loans_same_book' => $settings->allowsMultipleLoansSameBook(),
            ],
        ];
    }

    public function maxRenewals(): int
    {
        if ($this->maxRenewals === null) {
            $this->maxRenewals = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_renewals', '3') ?? 3);
        }

        return $this->maxRenewals;
    }

    // ─── Reservations ───────────────────────────────────────────────────────

    private const RESERVATION_SELECT = "SELECT r.id, r.libro_id, r.utente_id, r.data_prenotazione, r.data_inizio_richiesta,
               r.data_fine_richiesta, r.data_scadenza_prenotazione, r.notifica_inviata, r.stato, r.created_at,
               CASE WHEN r.stato = 'attiva' THEN COALESCE(r.queue_position,
                   (SELECT COUNT(*) + 1 FROM prenotazioni r2
                     WHERE r2.libro_id = r.libro_id AND r2.stato = 'attiva'
                       AND (r2.created_at < r.created_at OR (r2.created_at = r.created_at AND r2.id < r.id))))
                    ELSE NULL END AS posizione_coda,
               l.id AS book_id, l.titolo, l.copertina_url,
               u.nome AS u_nome, u.cognome AS u_cognome, u.email AS u_email,
               u.codice_tessera AS u_card, u.stato AS u_stato
          FROM prenotazioni r
          LEFT JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
          JOIN utenti u ON u.id = r.utente_id";

    /** @return array<string, mixed>|null raw prenotazioni row */
    public function reservationRow(int $id): ?array
    {
        return $this->one('SELECT * FROM prenotazioni WHERE id = ?', 'i', [$id]);
    }

    /** @return array<string, mixed>|null StaffReservation */
    public function reservation(int $id): ?array
    {
        $row = $this->one(self::RESERVATION_SELECT . ' WHERE r.id = ?', 'i', [$id]);

        return $row === null ? null : $this->mapReservation($row);
    }

    /**
     * @param array{q?:string, user_id?:int, book_id?:int} $filters
     * @return array{items:list<array<string, mixed>>, total:int, has_more:bool}
     */
    public function reservations(string $status, array $filters, int $offset, int $limit): array
    {
        $where = ['l.id IS NOT NULL'];
        $types = '';
        $params = [];
        if ($status !== 'all') {
            $where[] = 'r.stato = ?';
            $types .= 's';
            $params[] = $status;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        // Quoted value = exact spelling, accents included (#501).
        $search = $q !== ''
            ? \App\Support\ExactSearch::filterCondition(['l.titolo', 'u.nome', 'u.cognome', "CONCAT(u.nome, ' ', u.cognome)", 'u.email', 'u.codice_tessera'], $q)
            : null;
        if ($search !== null) {
            $where[] = $search['sql'];
            $types .= $search['types'];
            array_push($params, ...$search['params']);
        }
        foreach (['user_id' => 'r.utente_id', 'book_id' => 'r.libro_id'] as $key => $column) {
            $value = (int) ($filters[$key] ?? 0);
            if ($value > 0) {
                $where[] = $column . ' = ?';
                $types .= 'i';
                $params[] = $value;
            }
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->scalar(
            'SELECT COUNT(*) FROM prenotazioni r
               LEFT JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
               JOIN utenti u ON u.id = r.utente_id' . $whereSql,
            $types,
            $params
        ) ?? 0);
        $rows = $this->all(
            self::RESERVATION_SELECT . $whereSql
                . ' ORDER BY r.libro_id ASC, r.queue_position IS NULL, r.queue_position ASC, r.created_at ASC, r.id ASC LIMIT ? OFFSET ?',
            $types . 'ii',
            array_merge($params, [$limit + 1, $offset])
        );
        $hasMore = count($rows) > $limit;

        return [
            'items'    => array_map(fn (array $r): array => $this->mapReservation($r), array_slice($rows, 0, $limit)),
            'total'    => $total,
            'has_more' => $hasMore,
        ];
    }

    /** The single active reservation above the high-water mark for this user + title, else null. */
    public function resolveNewReservationId(int $before, int $userId, int $bookId): ?int
    {
        $rows = $this->all(
            "SELECT id FROM prenotazioni WHERE id > ? AND utente_id = ? AND libro_id = ? AND stato = 'attiva'
              ORDER BY id DESC LIMIT 2",
            'iii',
            [$before, $userId, $bookId]
        );

        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    // ─── Users ──────────────────────────────────────────────────────────────

    private const USER_SELECT = "SELECT u.id, u.nome, u.cognome, u.email, u.codice_tessera, u.stato,
               u.data_scadenza_tessera, u.tipo_utente,
               (SELECT COUNT(*) FROM prestiti p WHERE p.utente_id = u.id AND p.attivo = 1
                   AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')) AS active_loans,
               (SELECT COUNT(*) FROM prenotazioni r WHERE r.utente_id = u.id AND r.stato = 'attiva') AS active_reservations
          FROM utenti u";

    /**
     * Same LIKE set as SearchController::users() (name, surname, phone,
     * email, card), plus "name surname".
     *
     * @return list<array<string, mixed>>
     */
    public function users(string $q, int $limit): array
    {
        // Quoted value = exact spelling, accents included (#501).
        $search = \App\Support\ExactSearch::filterCondition(['u.nome', 'u.cognome', 'u.telefono', 'u.email', 'u.codice_tessera', "CONCAT(u.nome, ' ', u.cognome)"], $q);
        if ($search === null) {
            return [];
        }
        $rows = $this->all(
            self::USER_SELECT . ' WHERE ' . $search['sql']
                . \App\Support\Permissions::accountScopeSql('u', \App\Support\Permissions::SCOPE_BORROWER) . " ORDER BY u.cognome, u.nome, u.id LIMIT ?",
            $search['types'] . 'i',
            [...$search['params'], $limit]
        );

        return array_map(fn (array $r): array => $this->mapUser($r), $rows);
    }

    /** @return array<string, mixed>|null StaffUser */
    public function user(int $id): ?array
    {
        // An account outside the caller's scope (an operator, for a custom role) is "not found".
        $row = $this->one(self::USER_SELECT . ' WHERE u.id = ?' . \App\Support\Permissions::accountScopeSql('u', \App\Support\Permissions::SCOPE_BORROWER), 'i', [$id]);

        return $row === null ? null : $this->mapUser($row);
    }

    /**
     * Open loans of a user / a book: active (attivo=1) or still pending.
     *
     * @return list<array<string, mixed>>
     */
    public function openLoans(string $column, int $id): array
    {
        $col = $column === 'book' ? 'p.libro_id' : 'p.utente_id';
        $rows = $this->all(
            self::LOAN_SELECT . " WHERE {$col} = ? AND (p.attivo = 1 OR p.stato = 'pendente') ORDER BY p.data_prestito ASC, p.id ASC LIMIT 200",
            'i',
            [$id]
        );

        return array_map(fn (array $r): array => $this->mapLoan($r), $rows);
    }

    /**
     * Active reservations of a user / a book, in queue order.
     *
     * @return list<array<string, mixed>>
     */
    public function activeReservations(string $column, int $id): array
    {
        $col = $column === 'book' ? 'r.libro_id' : 'r.utente_id';
        $rows = $this->all(
            self::RESERVATION_SELECT . " WHERE {$col} = ? AND r.stato = 'attiva'
                ORDER BY r.libro_id ASC, r.queue_position IS NULL, r.queue_position ASC, r.created_at ASC, r.id ASC LIMIT 200",
            'i',
            [$id]
        );

        return array_map(fn (array $r): array => $this->mapReservation($r), $rows);
    }

    // ─── Mapping ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function mapLoan(array $r): array
    {
        $today = DateHelper::today();
        $status = (string) ($r['stato'] ?? '');
        $due = self::date($r['data_scadenza'] ?? null);
        $daysOverdue = 0;
        if ($due !== null && in_array($status, ['in_corso', 'in_ritardo'], true) && (int) ($r['attivo'] ?? 0) === 1 && $due < $today) {
            $daysOverdue = (int) ((new \DateTimeImmutable($due))->diff(new \DateTimeImmutable($today))->days ?: 0);
        }
        $penalty = $r['sanzione'] ?? null;
        $maxRenewals = $this->maxRenewals();
        $processedBy = isset($r['processed_by'], $r['pb_nome'])
            ? ['id' => (int) $r['processed_by'], 'name' => self::name($r['pb_nome'], $r['pb_cognome'] ?? '')]
            : null;

        return [
            'id'              => (int) $r['id'],
            'status'          => $status,
            'status_label'    => translate_loan_status($status),
            'origin'          => (string) ($r['origine'] ?? 'richiesta'),
            'book'            => [
                'id'        => (int) ($r['libro_id'] ?? 0),
                'title'     => HtmlHelper::decode((string) ($r['titolo'] ?? '')),
                'cover_url' => $r['book_id'] !== null ? absoluteUrl(self::coverPath($r['copertina_url'] ?? null)) : null,
                'isbn13'    => self::str($r['isbn13'] ?? null),
            ],
            'copy'            => isset($r['copia_id']) ? [
                'id'               => (int) $r['copia_id'],
                'inventory_number' => (string) ($r['copy_code'] ?? ''),
                'status'           => (string) ($r['copy_status'] ?? ''),
            ] : null,
            'user'            => [
                'id'          => (int) $r['utente_id'],
                'name'        => self::name($r['u_nome'] ?? '', $r['u_cognome'] ?? ''),
                'email'       => (string) ($r['u_email'] ?? ''),
                'card_number' => self::str($r['u_card'] ?? null),
                'status'      => (string) ($r['u_stato'] ?? ''),
            ],
            'processed_by'    => $processedBy,
            'requested_at'    => self::date($r['created_at'] ?? null),
            'loaned_at'       => self::date($r['data_prestito'] ?? null),
            'due_at'          => $due,
            'pickup_deadline' => self::date($r['pickup_deadline'] ?? null),
            'returned_at'     => self::date($r['data_restituzione'] ?? null),
            'returned_late'   => (int) ($r['restituito_in_ritardo'] ?? 0) === 1,
            'days_overdue'    => $daysOverdue,
            'renewals'        => (int) ($r['renewals'] ?? 0),
            'max_renewals'    => $maxRenewals,
            'penalty'         => $penalty !== null && (float) $penalty > 0 ? (float) $penalty : null,
            'notes'           => self::str($r['note'] ?? null),
            'recall_count'    => (int) ($r['recall_count'] ?? 0),
            'last_recall_at'  => self::str($r['last_recall_at'] ?? null),
            'created_at'      => self::str($r['created_at'] ?? null),
            'updated_at'      => self::str($r['updated_at'] ?? null),
            'actions'         => CirculationOutcome::actionsFor($r, $maxRenewals, $today),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function mapReservation(array $r): array
    {
        $status = (string) ($r['stato'] ?? '');
        $label = match ($status) {
            'attiva'     => __('Attiva'),
            'completata' => __('Completata'),
            'annullata'  => __('Annullata'),
            default      => $status,
        };

        return [
            'id'             => (int) $r['id'],
            'status'         => $status,
            'status_label'   => $label,
            'book'           => [
                'id'        => (int) ($r['libro_id'] ?? 0),
                'title'     => HtmlHelper::decode((string) ($r['titolo'] ?? '')),
                'cover_url' => $r['book_id'] !== null ? absoluteUrl(self::coverPath($r['copertina_url'] ?? null)) : null,
            ],
            'user'           => [
                'id'          => (int) $r['utente_id'],
                'name'        => self::name($r['u_nome'] ?? '', $r['u_cognome'] ?? ''),
                'email'       => (string) ($r['u_email'] ?? ''),
                'card_number' => self::str($r['u_card'] ?? null),
                'status'      => (string) ($r['u_stato'] ?? ''),
            ],
            'queue_position' => $r['posizione_coda'] !== null ? (int) $r['posizione_coda'] : null,
            'reserved_at'    => self::date($r['data_prenotazione'] ?? null),
            'expires_at'     => self::date($r['data_scadenza_prenotazione'] ?? null),
            'requested_from' => self::date($r['data_inizio_richiesta'] ?? null),
            'requested_to'   => self::date($r['data_fine_richiesta'] ?? null),
            'notified'       => (int) ($r['notifica_inviata'] ?? 0) === 1,
            'created_at'     => self::str($r['created_at'] ?? null),
            'actions'        => CirculationOutcome::reservationActionsFor($status),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function mapUser(array $r): array
    {
        $reason = \App\Support\LoanEligibility::checkUser($this->db, (int) $r['id']);

        return [
            'id'                  => (int) $r['id'],
            'name'                => self::name($r['nome'] ?? '', $r['cognome'] ?? ''),
            'email'               => (string) ($r['email'] ?? ''),
            'card_number'         => self::str($r['codice_tessera'] ?? null),
            'status'              => (string) ($r['stato'] ?? ''),
            'card_expires_at'     => self::date($r['data_scadenza_tessera'] ?? null),
            'role'                => (string) ($r['tipo_utente'] ?? ''),
            'active_loans'        => (int) ($r['active_loans'] ?? 0),
            'active_reservations' => (int) ($r['active_reservations'] ?? 0),
            'eligible'            => $reason === null,
            'eligibility_reason'  => $reason,
        ];
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    public static function name(mixed $first, mixed $last): string
    {
        return HtmlHelper::decode(trim((is_scalar($first) ? (string) $first : '') . ' ' . (is_scalar($last) ? (string) $last : '')));
    }

    public static function coverPath(mixed $raw): string
    {
        $cover = is_string($raw) ? trim($raw) : '';
        if ($cover !== '' && preg_match('#^https?://#i', $cover) === 1) {
            return $cover;
        }

        return $cover !== '' ? $cover : '/uploads/copertine/placeholder.jpg';
    }

    public static function date(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return substr($value, 0, 10);
    }

    public static function str(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);

        return $value !== '' ? $value : null;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, string $types, array $params): ?array
    {
        $rows = $this->all($sql . ' LIMIT 1', $types, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, string $types, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return $row[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
