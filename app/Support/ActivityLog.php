<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Read/write access to the existing log_modifiche audit table.
 *
 * Book-related events are intentionally stored with tabella=libri and
 * record_id=<book id>. The concrete entity (book, copy, loan, import) lives in
 * the JSON metadata, which keeps per-book timelines fast on the existing
 * (tabella, record_id) index and requires no schema migration.
 */
final class ActivityLog
{
    // @include-soft-deleted: audit snapshots and historical feeds intentionally
    // retain archived books so their activity remains attributable to staff.
    public const TYPES = ['edit', 'copy', 'import', 'enrich', 'loan'];

    /**
     * Sentinel for events performed by the application itself (e.g. automatic
     * loan approval running inside the requesting reader's HTTP session).
     * Prevents the session-operator fallback: the event is stored with a NULL
     * operator and the feed renders it as "Sistema".
     */
    public const SYSTEM_OPERATOR = 0;

    /**
     * Bureaucracy fields hidden from CIRCULATION event cards: the header
     * already tells the story (event + date), the requested period stays via
     * data_inizio/fine_richiesta, and queue positions/duplicate datetime
     * pairs only add noise (production feedback, 2026-09-02).
     */
    private const LOAN_NOISE_FIELDS = [
        'queue_position',
        'data_prenotazione',
        'data_scadenza_prenotazione',
        'attivo',
        'renewals',
        'origine',
    ];

    private const HIDDEN_FIELDS = [
        '_activity',
        'libro_id',
        'descrizione_plain',
        'search_index',
        'updated_at',
        'created_at',
        'deleted_at',
    ];

    /** @var array<string,string> */
    private const EVENT_LABELS = [
        'book.created' => 'Libro inserito',
        'book.updated' => 'Libro aggiornato',
        'book.deleted' => 'Libro eliminato',
        'copy.created' => 'Copia fisica aggiunta',
        'copy.updated' => 'Copia fisica aggiornata',
        'copy.deleted' => 'Copia fisica eliminata',
        'import.created' => 'Libro creato tramite importazione',
        'import.updated' => 'Libro aggiornato tramite importazione',
        'enrich.updated' => 'Metadati arricchiti',
        'loan.created' => 'Prestito creato',
        'loan.updated' => 'Prestito aggiornato',
        'loan.approved' => 'Prestito approvato',
        'loan.picked_up' => 'Ritiro del prestito confermato',
        'loan.returned' => 'Prestito restituito',
        'loan.renewed' => 'Prestito rinnovato',
        'loan.cancelled' => 'Prestito annullato',
        'loan.expired' => 'Ritiro del prestito scaduto',
        'loan.overdue' => 'Prestito in ritardo',
        'loan.lost' => 'Copia dichiarata persa',
        'loan.damaged' => 'Copia dichiarata danneggiata',
        'reservation.created' => 'Prenotazione creata',
        'reservation.updated' => 'Prenotazione aggiornata',
        'reservation.cancelled' => 'Prenotazione annullata',
        'reservation.promoted' => 'Prenotazione promossa',
        'reservation.expired' => 'Prenotazione scaduta',
    ];

    /** @var array<string,string> */
    private const FIELD_LABELS = [
        'titolo' => 'Titolo',
        'sottotitolo' => 'Sottotitolo',
        'isbn10' => 'ISBN 10',
        'isbn13' => 'ISBN 13',
        'ean' => 'EAN/UPC',
        'issn' => 'ISSN',
        'editore_id' => 'Editore',
        'genere_id' => 'Genere',
        'sottogenere_id' => 'Sottogenere',
        'descrizione' => 'Descrizione',
        'copertina_url' => 'Copertina',
        'parole_chiave' => 'Parole chiave',
        'formato' => 'Formato',
        'tipo_media' => 'Tipo media',
        'lingua' => 'Lingua',
        'anno_pubblicazione' => 'Anno di pubblicazione',
        'data_pubblicazione' => 'Data pubblicazione',
        'edizione' => 'Edizione',
        'numero_pagine' => 'Numero pagine',
        'peso' => 'Peso',
        'dimensioni' => 'Dimensioni',
        'prezzo' => 'Prezzo',
        'data_acquisizione' => 'Data acquisizione',
        'tipo_acquisizione' => 'Tipo acquisizione',
        'collana' => 'Collana',
        'numero_serie' => 'Numero serie',
        'collocazione' => 'Collocazione',
        'scaffale_id' => 'Scaffale',
        'mensola_id' => 'Mensola',
        'posizione_progressiva' => 'Posizione progressiva',
        'numero_inventario' => 'Numero di inventario',
        'classificazione_dewey' => 'Classificazione Dewey',
        'file_url' => 'File digitale',
        'audio_url' => 'Audiolibro',
        'note_varie' => 'Note',
        'note' => 'Note',
        'copie_totali' => 'Copie totali',
        'copie_disponibili' => 'Copie disponibili',
        'stato' => 'Stato',
        'quantita' => 'Quantità',
        'data_prestito' => 'Data prestito',
        'data_scadenza' => 'Data scadenza',
        'sanzione' => 'Sanzione',
        'data_restituzione' => 'Data restituzione',
        'renewals' => 'Rinnovi',
        'utente_id' => 'Utente',
        'copia_id' => 'Copia',
        'pickup_deadline' => 'Scadenza ritiro',
        'data_prenotazione' => 'Data prenotazione',
        'data_scadenza_prenotazione' => 'Scadenza prenotazione',
        'data_inizio_richiesta' => 'Inizio richiesta',
        'data_fine_richiesta' => 'Fine richiesta',
        'origine' => 'Origine',
        'attivo' => 'Attivo',
        'queue_position' => 'Posizione in coda',
    ];

    /**
     * Audit failures never make the user operation fail. When called inside an
     * existing transaction, however, the INSERT participates in that transaction.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function recordBookEvent(
        mysqli $db,
        int $bookId,
        string $action,
        string $type,
        string $event,
        array $before = [],
        array $after = [],
        ?int $operatorId = null,
        ?int $entityId = null,
        ?string $bookTitle = null,
        ?string $source = null
    ): bool {
        if ($bookId <= 0 || !in_array($action, ['inserimento', 'aggiornamento', 'cancellazione'], true)) {
            return false;
        }
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }

        if ($operatorId === self::SYSTEM_OPERATOR) {
            $operatorId = null; // system action: never attribute to the session user
        } else {
            $operatorId ??= self::sessionOperatorId();
        }
        $operatorName = self::operatorName($db, $operatorId);
        $meta = [
            'type' => $type,
            'event' => $event,
            'entity_id' => $entityId,
            'book_title' => $bookTitle,
            'operator_name' => $operatorName,
            'source' => $source,
        ];
        $after['_activity'] = array_filter($meta, static fn(mixed $value): bool => $value !== null && $value !== '');

        try {
            $beforeJson = self::encodeSnapshot($before);
            $afterJson = self::encodeSnapshot($after);
            $stmt = $db->prepare(
                'INSERT INTO log_modifiche (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id) '
                . "VALUES ('libri', ?, ?, ?, ?, ?)"
            );
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('isssi', $bookId, $action, $beforeJson, $afterJson, $operatorId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog write failed', [
                'book_id' => $bookId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,total:int} */
    public static function forBook(
        mysqli $db,
        int $bookId,
        int $page = 1,
        int $perPage = 20,
        ?string $q = null,
        ?string $type = null
    ): array {
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            $type = null;
        }
        return self::fetch($db, $page, $perPage, $bookId, $type, null, $q);
    }

    /**
     * @return array{items:list<array<string,mixed>>,page:int,pages:int,total:int}
     */
    public static function recent(
        mysqli $db,
        int $page = 1,
        int $perPage = 12,
        ?string $type = null,
        ?int $operatorId = null,
        ?string $q = null
    ): array {
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            $type = null;
        }
        return self::fetch($db, $page, $perPage, null, $type, $operatorId, $q);
    }

    /** @return list<array{id:int,name:string}> */
    public static function operators(mysqli $db): array
    {
        $rows = [];
        try {
            $result = $db->query(
                "SELECT DISTINCT u.id, TRIM(CONCAT(u.nome, ' ', u.cognome)) AS name "
                . 'FROM log_modifiche lm JOIN utenti u ON u.id = lm.utente_id '
                . "WHERE lm.tabella = 'libri' ORDER BY name"
            );
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog operator list failed', ['error' => $e->getMessage()]);
        }
        return $rows;
    }

    public static function eventLabel(string $event, string $fallbackAction = ''): string
    {
        return self::EVENT_LABELS[$event] ?? match ($fallbackAction) {
            'inserimento' => 'Inserimento',
            'cancellazione' => 'Cancellazione',
            default => 'Aggiornamento',
        };
    }

    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'copy' => 'Copie fisiche',
            'import' => 'Importazione',
            'enrich' => 'Arricchimento',
            'loan' => 'Prestiti e prenotazioni',
            default => 'Modifiche libro',
        };
    }

    public static function valueLabel(string $field, string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($field === 'stato') {
            return match ($value) {
                'disponibile' => 'Disponibile',
                'non_disponibile' => 'Non Disponibile',
                'prestato' => 'Prestato',
                'prenotato' => 'Prenotato',
                'pendente' => 'Pendente',
                'da_ritirare' => 'Da Ritirare',
                'in_corso' => 'In Corso',
                'in_ritardo' => 'In Ritardo',
                'restituito' => 'Restituito',
                'perso' => 'Perso',
                'danneggiato' => 'Danneggiato',
                'annullato' => 'Annullato',
                'scaduto' => 'Scaduto',
                'manutenzione' => 'Manutenzione',
                'in_restauro' => 'In restauro',
                'in_trasferimento' => 'In trasferimento',
                'attiva' => 'Attiva',
                'completata' => 'Completata',
                'annullata' => 'Annullata',
                default => null,
            };
        }
        if ($field === 'origine') {
            return match ($value) {
                'richiesta' => 'Richiesta',
                'prenotazione' => 'Prenotazione',
                'diretto' => 'Diretto',
                'ncip' => 'NCIP',
                default => null,
            };
        }
        return null;
    }

    /** @return array<string,string> */
    public static function typeClasses(string $type): array
    {
        return match ($type) {
            'copy' => ['icon' => 'fa-clone', 'badge' => 'bg-purple-100 text-purple-800'],
            'import' => ['icon' => 'fa-file-import', 'badge' => 'bg-blue-100 text-blue-800'],
            'enrich' => ['icon' => 'fa-magic', 'badge' => 'bg-amber-100 text-amber-800'],
            'loan' => ['icon' => 'fa-handshake', 'badge' => 'bg-green-100 text-green-800'],
            default => ['icon' => 'fa-edit', 'badge' => 'bg-gray-100 text-gray-800'],
        };
    }

    /**
     * Keep only the persisted book columns useful to an operator. Joined labels,
     * plugin decorations and relation arrays from BookRepository are excluded.
     *
     * @param array<string,mixed> $book
     * @return array<string,mixed>
     */
    public static function bookSnapshot(array $book): array
    {
        $fields = [
            'titolo', 'sottotitolo', 'isbn10', 'isbn13', 'ean', 'issn',
            'editore_id', 'genere_id', 'sottogenere_id', 'descrizione',
            'copertina_url', 'parole_chiave', 'formato', 'tipo_media', 'lingua',
            'anno_pubblicazione', 'data_pubblicazione', 'edizione', 'numero_pagine',
            'peso', 'dimensioni', 'prezzo', 'data_acquisizione', 'tipo_acquisizione',
            'collana', 'numero_serie', 'classificazione_dewey', 'collocazione',
            'scaffale_id', 'mensola_id', 'posizione_progressiva', 'numero_inventario',
            'file_url', 'audio_url', 'note_varie', 'copie_totali',
            'copie_disponibili', 'stato',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $book)) {
                $snapshot[$field] = $book[$field];
            }
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public static function loadBookSnapshot(mysqli $db, int $bookId): array
    {
        try {
            // CI-SOFT-DELETE-EXEMPT: audit snapshots must also resolve a row
            // immediately after soft deletion, so deleted_at cannot be filtered.
            $stmt = $db->prepare('SELECT * FROM libri WHERE id = ? LIMIT 1');
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return is_array($row) ? self::bookSnapshot($row) : [];
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog snapshot failed', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @return array<string,mixed> */
    public static function loadLoanSnapshot(mysqli $db, int $loanId): array
    {
        try {
            // CI-SOFT-DELETE-EXEMPT: an audit event must retain the title of an existing loan even after its book is archived.
            $stmt = $db->prepare(
                'SELECT p.id, p.libro_id, p.copia_id, p.utente_id, p.data_prestito, '
                . 'p.data_scadenza, p.data_restituzione, p.stato, p.origine, p.renewals, '
                . 'p.note, p.attivo, p.pickup_deadline, p.sanzione, l.titolo AS book_title '
                . 'FROM prestiti p LEFT JOIN libri l ON l.id = p.libro_id WHERE p.id = ? LIMIT 1'
            );
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog loan snapshot failed', ['loan_id' => $loanId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @param array<string,mixed> $before */
    public static function recordLoanEvent(
        mysqli $db,
        int $loanId,
        string $event,
        array $before = [],
        string $action = 'aggiornamento',
        ?string $source = 'circulation',
        ?int $operatorId = null
    ): bool {
        $after = self::loadLoanSnapshot($db, $loanId);
        $bookId = (int) ($after['libro_id'] ?? $before['libro_id'] ?? 0);
        $bookTitle = (string) ($after['book_title'] ?? $before['book_title'] ?? '');
        unset($before['book_title'], $after['book_title'], $before['id'], $after['id']);
        if ($action === 'inserimento') {
            $before = [];
        } elseif ($action === 'cancellazione') {
            $after = [];
        }
        return self::recordBookEvent(
            $db,
            $bookId,
            $action,
            'loan',
            $event,
            $before,
            $after,
            operatorId: $operatorId,
            entityId: $loanId,
            bookTitle: $bookTitle,
            source: $source
        );
    }

    /** @return array<string,mixed> */
    public static function loadReservationSnapshot(mysqli $db, int $reservationId): array
    {
        try {
            // CI-SOFT-DELETE-EXEMPT: an audit event must retain the title of an existing reservation after book archival.
            $stmt = $db->prepare(
                'SELECT r.id, r.libro_id, r.utente_id, r.data_prenotazione, '
                . 'r.data_scadenza_prenotazione, r.data_inizio_richiesta, r.data_fine_richiesta, '
                . 'r.queue_position, r.stato, l.titolo AS book_title '
                . 'FROM prenotazioni r LEFT JOIN libri l ON l.id = r.libro_id WHERE r.id = ? LIMIT 1'
            );
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $reservationId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog reservation snapshot failed', ['reservation_id' => $reservationId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** @param array<string,mixed> $before */
    public static function recordReservationEvent(
        mysqli $db,
        int $reservationId,
        string $event,
        array $before = [],
        string $action = 'aggiornamento',
        ?string $source = 'circulation',
        ?int $operatorId = null
    ): bool {
        $after = self::loadReservationSnapshot($db, $reservationId);
        $bookId = (int) ($after['libro_id'] ?? $before['libro_id'] ?? 0);
        $bookTitle = (string) ($after['book_title'] ?? $before['book_title'] ?? '');
        unset($before['book_title'], $after['book_title'], $before['id'], $after['id']);
        if ($action === 'inserimento') {
            $before = [];
        } elseif ($action === 'cancellazione') {
            $after = [];
        }
        return self::recordBookEvent(
            $db,
            $bookId,
            $action,
            'loan',
            $event,
            $before,
            $after,
            operatorId: $operatorId,
            entityId: $reservationId,
            bookTitle: $bookTitle,
            source: $source
        );
    }

    /**
     * Decode a raw database row and calculate a field-level diff.
     * Public to keep the JSON compatibility behaviour independently testable.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decodeRow(array $row): array
    {
        $before = self::decodeSnapshot($row['dati_precedenti'] ?? null);
        $after = self::decodeSnapshot($row['dati_nuovi'] ?? null);
        $meta = isset($after['_activity']) && is_array($after['_activity']) ? $after['_activity'] : [];
        unset($after['_activity']);
        $type = (string) ($meta['type'] ?? 'edit');

        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            if (in_array($key, self::HIDDEN_FIELDS, true)) {
                continue;
            }
            if ($type === 'loan' && in_array($key, self::LOAN_NOISE_FIELDS, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (self::comparable($old) === self::comparable($new)) {
                continue;
            }
            $changes[] = ['field' => $key, 'before' => $old, 'after' => $new];
        }

        $row['meta'] = $meta;
        $row['type'] = $type;
        $row['event'] = (string) ($meta['event'] ?? '');
        $row['book_title'] = (string) ($row['book_title'] ?? $meta['book_title'] ?? '');
        $row['operator_name'] = (string) ($row['operator_name'] ?? $meta['operator_name'] ?? '');
        $row['changes'] = $changes;
        unset($row['dati_precedenti'], $row['dati_nuovi']);
        return $row;
    }

    /** @param array<string,mixed> $snapshot */
    private static function encodeSnapshot(array $snapshot): string
    {
        $clean = self::sanitizeSnapshot($snapshot);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) <= 64000) {
            return $json;
        }

        // TEXT is limited to 64 KiB. Preserve metadata and as many leading
        // fields as fit instead of failing the user operation.
        foreach (array_reverse(array_keys($clean)) as $key) {
            if ($key === '_activity') {
                continue;
            }
            unset($clean[$key]);
            $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) <= 64000) {
                return $json;
            }
        }
        return '{}';
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private static function sanitizeSnapshot(array $snapshot): array
    {
        $clean = [];
        foreach ($snapshot as $key => $value) {
            if (in_array($key, ['password', 'token', 'csrf_token'], true)) {
                continue;
            }
            if (is_string($value)) {
                $clean[$key] = mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) . '…' : $value;
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitizeSnapshot($value);
            }
        }
        return $clean;
    }

    /** @return array<string,mixed> */
    private static function decodeSnapshot(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function comparable(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string) $value);
    }

    private static function sessionOperatorId(): ?int
    {
        $id = (int) ($_SESSION['user']['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function operatorName(mysqli $db, ?int $operatorId): ?string
    {
        if ($operatorId === null || $operatorId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT TRIM(CONCAT(nome, ' ', cognome)) AS name FROM utenti WHERE id = ? LIMIT 1");
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('i', $operatorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $name = trim((string) ($row['name'] ?? ''));
            return $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int,total:int} */
    private static function fetch(
        mysqli $db,
        int $page,
        int $perPage,
        ?int $bookId,
        ?string $type,
        ?int $operatorId,
        ?string $q = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $where = ["lm.tabella = 'libri'"];
        $types = '';
        $params = [];

        if ($bookId !== null) {
            $where[] = 'lm.record_id = ?';
            $types .= 'i';
            $params[] = $bookId;
        }
        if ($type !== null) {
            // COALESCE mirrors decodeRow(): legacy rows without _activity.type
            // render as 'edit' in the feed, so the SQL filter must classify
            // them the same way or filtering by 'edit' would hide them.
            $where[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(lm.dati_nuovi) THEN lm.dati_nuovi ELSE '{}' END, '$._activity.type')), 'edit') = ?";
            $types .= 's';
            $params[] = $type;
        }
        if ($operatorId !== null && $operatorId > 0) {
            $where[] = 'lm.utente_id = ?';
            $types .= 'i';
            $params[] = $operatorId;
        }
        $q = $q !== null ? trim($q) : '';
        if ($q !== '') {
            // Search over the snapshot metadata (book title / operator name).
            // LIKE wildcards in the user input are escaped so they match
            // literally instead of widening the pattern.
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '\\%_') . '%';
            $snapshot = "CASE WHEN JSON_VALID(lm.dati_nuovi) THEN lm.dati_nuovi ELSE '{}' END";
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT({$snapshot}, '$._activity.book_title')) LIKE ?"
                . " OR JSON_UNQUOTE(JSON_EXTRACT({$snapshot}, '$._activity.operator_name')) LIKE ?)";
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        try {
            $countStmt = $db->prepare("SELECT COUNT(*) AS total FROM log_modifiche lm WHERE {$whereSql}");
            if ($countStmt === false) {
                throw new \RuntimeException($db->error);
            }
            if ($types !== '') {
                $countStmt->bind_param($types, ...$params);
            }
            $countStmt->execute();
            $countRow = $countStmt->get_result()->fetch_assoc();
            $countStmt->close();
            $total = (int) ($countRow['total'] ?? 0);
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            // CI-SOFT-DELETE-EXEMPT: historical audit entries for archived books must remain visible to staff.
            $sql = "SELECT lm.*, TRIM(CONCAT(u.nome, ' ', u.cognome)) AS operator_name, b.titolo AS book_title "
                . 'FROM log_modifiche lm '
                . 'LEFT JOIN utenti u ON u.id = lm.utente_id '
                . 'LEFT JOIN libri b ON b.id = lm.record_id '
                . "WHERE {$whereSql} ORDER BY lm.data_modifica DESC, lm.id DESC LIMIT ? OFFSET ?";
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException($db->error);
            }
            $queryTypes = $types . 'ii';
            $queryParams = array_merge($params, [$perPage, $offset]);
            $stmt->bind_param($queryTypes, ...$queryParams);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = [];
            while ($row = $result->fetch_assoc()) {
                $items[] = self::decodeRow($row);
            }
            $stmt->close();

            $items = self::resolveUserReferences($db, $items);
            $items = self::resolveCopyReferences($db, $items);
            return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
        } catch (\Throwable $e) {
            SecureLogger::warning('ActivityLog read failed', ['error' => $e->getMessage()]);
            return ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        }
    }

    /**
     * Replace raw copia_id values with the copy's inventory number — "which
     * physical copy was picked up" is real information, a bare id is not.
     * Same batch pattern as resolveUserReferences; '#id' fallback for a
     * copy that no longer exists.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function resolveCopyReferences(mysqli $db, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (($item['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'copia_id') {
                    continue;
                }
                foreach (['before', 'after'] as $side) {
                    $value = $change[$side] ?? null;
                    if (is_numeric($value) && (int) $value > 0) {
                        $ids[(int) $value] = true;
                    }
                }
            }
        }
        if ($ids === []) {
            return $items;
        }

        $labels = [];
        try {
            $list = implode(',', array_keys($ids));
            $result = $db->query("SELECT id, numero_inventario FROM copie WHERE id IN ({$list})");
            while ($result && ($row = $result->fetch_assoc())) {
                $label = trim((string) ($row['numero_inventario'] ?? ''));
                if ($label !== '') {
                    $labels[(int) $row['id']] = $label;
                }
            }
        } catch (\Throwable) {
            return $items;
        }

        foreach ($items as &$item) {
            foreach ($item['changes'] as &$change) {
                if (($change['field'] ?? '') !== 'copia_id') {
                    continue;
                }
                foreach (['before', 'after'] as $side) {
                    $value = $change[$side] ?? null;
                    if (is_numeric($value) && (int) $value > 0) {
                        $change[$side] = $labels[(int) $value] ?? ('#' . (int) $value);
                    }
                }
            }
        }
        unset($item, $change);

        return $items;
    }

    /**
     * Replace raw utente_id values in the decoded change lists with the
     * user's display name ("Utente 5" tells the reader nothing). One query
     * for the whole page of items; a deleted account falls back to "#id".
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function resolveUserReferences(mysqli $db, array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach (($item['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'utente_id') {
                    continue;
                }
                foreach (['before', 'after'] as $side) {
                    $value = $change[$side] ?? null;
                    if (is_numeric($value) && (int) $value > 0) {
                        $ids[(int) $value] = true;
                    }
                }
            }
        }
        if ($ids === []) {
            return $items;
        }

        $names = [];
        try {
            $list = implode(',', array_keys($ids));
            $result = $db->query("SELECT id, TRIM(CONCAT(nome, ' ', cognome)) AS name FROM utenti WHERE id IN ({$list})");
            while ($result && ($row = $result->fetch_assoc())) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $names[(int) $row['id']] = $name;
                }
            }
        } catch (\Throwable) {
            return $items;
        }

        foreach ($items as &$item) {
            foreach ($item['changes'] as &$change) {
                if (($change['field'] ?? '') !== 'utente_id') {
                    continue;
                }
                foreach (['before', 'after'] as $side) {
                    $value = $change[$side] ?? null;
                    if (is_numeric($value) && (int) $value > 0) {
                        $change[$side] = $names[(int) $value] ?? ('#' . (int) $value);
                    }
                }
            }
        }
        unset($item, $change);

        return $items;
    }
}
