<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\ActivityLog;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\GenreTree;
use App\Support\SecureLogger;
use mysqli;

/**
 * Inventory stocktake sessions (plan §8).
 *
 * A session freezes, when it is opened, the list of copies it expects to find
 * (inventario_attese). Every scan is classified against that snapshot, never
 * against the live catalogue, so moving a book or adding a copy while the
 * stocktake runs cannot change what the session was looking for.
 *
 * The expected copies always end in exactly one bucket of the report:
 * found, misplaced, on loan (lent, or waiting on the pickup shelf) or missing.
 * Unknown codes, copies outside the filter and repeated scans are counted on
 * the side.
 *
 * While the session is open the bucket of an unscanned copy follows the loan
 * desk (a return makes it missing, a loan makes it on loan). Closing writes
 * the bucket of every expected copy into inventario_attese.esito_chiusura,
 * and from then on the report, its exports and the counters read that column:
 * what the stocktake found is what it keeps saying.
 */
final class StocktakeService
{
    public const OUTCOMES = ['trovata', 'fuori_posto', 'in_prestito', 'sconosciuta', 'duplicata', 'fuori_ambito'];
    /** inventario_attese.esito_chiusura: the bucket of an expected copy, frozen at close. */
    public const CLOSE_OUTCOMES = ['trovata', 'fuori_posto', 'in_prestito', 'mancante', 'persa'];
    public const MEDIA_TYPES = ['libro', 'disco', 'audiolibro', 'dvd', 'altro'];
    public const MAX_CODE_LENGTH = 100;
    public const MAX_UPLOAD_CODES = 5000;

    /**
     * Copy states that already explain why a copy is not on the shelf. A missing
     * copy in one of these states is reported, but closing with "mark as lost"
     * leaves it alone: it is lent or waiting on the pickup shelf, damaged, at
     * the restorer's, in transit, in maintenance, or already lost.
     */
    private const AWAY_STATES = ['prestato', 'prenotato', 'perso', 'danneggiato', 'in_restauro', 'in_trasferimento', 'manutenzione'];

    /**
     * The "on loan" bucket of the report: a copy lent to a reader or set aside
     * on the pickup shelf for one, by its own state or by an active loan row.
     * Such a copy is where the loan desk put it, so it is never declared lost
     * (a copy marked lost would also have its hold moved to another copy).
     * A scheduled loan (prestiti 'prenotato') is not in the bucket: the copy
     * stays on its shelf until MaintenanceService activates the pickup, and
     * only then does the copy itself become 'prenotato'.
     */
    private const ON_LOAN_COPY_STATES = ['prestato', 'prenotato'];
    private const ON_LOAN_LOAN_STATES = ['in_corso', 'in_ritardo', 'da_ritirare'];

    /**
     * Copies nobody expects on a shelf when the session opens: they are left
     * out of the snapshot, otherwise they would be "missing" for the whole
     * session with nothing the close could do about them. Lent copies and
     * copies waiting for pickup stay in: they have a bucket of their own.
     */
    private const OFF_SHELF_STATES = ['perso', 'danneggiato', 'in_restauro', 'in_trasferimento', 'manutenzione'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * The outcome of one scan. Pure: every input is already resolved.
     *
     * @param bool     $copyExists     the code matches a copy of a book still in the catalogue
     * @param bool     $alreadyScanned this session already recorded a scan of that copy
     * @param bool     $expected       the copy is in the session's opening snapshot
     * @param bool     $onLoan         the copy is out on an active loan or waiting on the pickup shelf right now
     * @param int|null $expectedShelf  shelf recorded in the snapshot (null: the book had none)
     * @param int|null $scanShelf      shelf the operator says they are working on (null: not given)
     */
    public static function classify(
        bool $copyExists,
        bool $alreadyScanned,
        bool $expected,
        bool $onLoan,
        ?int $expectedShelf,
        ?int $scanShelf
    ): string {
        if (!$copyExists) {
            return 'sconosciuta';
        }
        if ($alreadyScanned) {
            return 'duplicata';
        }
        if (!$expected) {
            return 'fuori_ambito';
        }
        if ($onLoan) {
            return 'in_prestito';
        }
        if ($scanShelf !== null && $scanShelf > 0 && $expectedShelf !== $scanShelf) {
            return 'fuori_posto';
        }
        return 'trovata';
    }

    /**
     * Keep only the filter keys the snapshot understands. An absent or empty
     * value means "no restriction"; a value that is present but invalid is
     * refused, never dropped: dropping it would widen the snapshot to the whole
     * catalogue and a mark-lost close would then reach copies of other shelves.
     *
     * @param array<string,mixed> $input
     * @return array{scaffale_id?:int,mensola_id?:int,genere_id?:int,tipo_media?:string,collocazione_da?:string,collocazione_a?:string}
     * @throws \InvalidArgumentException on a present but invalid id or media type
     */
    public static function normalizeFilter(array $input): array
    {
        $filter = [];
        foreach (['scaffale_id', 'mensola_id', 'genere_id'] as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) {
                throw new \InvalidArgumentException(__('Filtri non validi.'));
            }
            $filter[$key] = (int) $value;
        }
        $media = $input['tipo_media'] ?? null;
        if ($media !== null && $media !== '') {
            if (!is_string($media) || !in_array($media, self::MEDIA_TYPES, true)) {
                throw new \InvalidArgumentException(__('Filtri non validi.'));
            }
            $filter['tipo_media'] = $media;
        }
        foreach (['collocazione_da', 'collocazione_a'] as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value)) {
                $value = mb_substr(trim($value), 0, 50);
                if ($value !== '') {
                    $filter[$key] = $value;
                }
            }
        }
        return $filter;
    }

    /**
     * A scanned or typed code, without the control characters some wedge
     * scanners append. Never shortened: a code longer than MAX_CODE_LENGTH is
     * refused by assertCodeLength(), since its first characters could be the
     * whole code of another copy.
     */
    public static function normalizeCode(string $code): string
    {
        $code = preg_replace('/[\x00-\x1F\x7F]+/u', '', $code) ?? '';
        return trim($code);
    }

    /** @throws \InvalidArgumentException when the code does not fit the column */
    private static function assertCodeLength(string $code): void
    {
        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(__('Codice troppo lungo: al massimo %d caratteri.'), self::MAX_CODE_LENGTH));
        }
    }

    /**
     * Codes from a .txt (one per line) or .csv (first column) export. Order and
     * repetitions are kept: a code read twice by an offline scanner is a duplicate.
     *
     * A .txt line is the code itself: only a tab (which no code can hold, see
     * normalizeCode()) separates it from trailing scanner columns, so a code
     * such as "INV,001" survives. A .csv is read record by record with a real
     * CSV parser, using the delimiter of its first record, so a quoted "INV,001"
     * survives too, and so does a quoted cell that spans several lines. Only
     * its first column is the code: a row whose first cell is empty has none.
     *
     * @return list<string>
     */
    public static function parseCodeList(string $text, bool $csv = false): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $codes = [];
        $first = true;
        foreach ($csv ? self::csvFirstCells($text) : self::txtFirstCells($text) as $cell) {
            $cell = trim($cell);
            // fgetcsv() already removed the CSV quoting, so a quote left in a
            // .csv cell is part of the code. A .txt line has no quoting rules:
            // only one pair of quotes wrapping the whole line is dropped.
            if (!$csv && preg_match('/^(["\'])(.*)\1$/su', $cell, $m) === 1) {
                $cell = $m[2];
            }
            $cell = self::normalizeCode($cell);
            if ($cell === '') {
                continue;
            }
            if ($first && preg_match('/^(codice|code|codes|barcode|numero_inventario|numero inventario|inventario|inventory|stregkode|code-barres)$/iu', $cell) === 1) {
                $first = false;
                continue;
            }
            $first = false;
            $codes[] = $cell;
        }
        return $codes;
    }

    /**
     * The first cell of every .txt line: what comes before its first tab. A
     * line that starts with a tab has no code, as a .csv row whose first cell
     * is empty: the next cell is a scanner column (a title, a date), never a code.
     *
     * @return list<string>
     */
    private static function txtFirstCells(string $text): array
    {
        $cells = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $cells[] = explode("\t", $line, 2)[0];
        }
        return $cells;
    }

    /**
     * The first cell of every .csv record. Records are read whole, so a line
     * break inside a quoted cell does not start a new record.
     *
     * @return list<string>
     */
    private static function csvFirstCells(string $text): array
    {
        // fgetcsv() does not split on a bare CR (old Mac exports).
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a memory stream for the CSV file');
        }
        try {
            fwrite($stream, $text);
            rewind($stream);
            $delimiter = self::csvDelimiter($text);
            $cells = [];
            while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $cells[] = (string) ($record[0] ?? '');
            }
            return $cells;
        } finally {
            fclose($stream);
        }
    }

    /**
     * The delimiter of a CSV export: the most frequent of , ; and tab in its
     * first non-blank record, counting only those outside quoted cells (comma
     * when the record has none, a one-column file). The record ends at the
     * first line break outside quotes, so a quoted first cell that spans
     * several lines does not hide the delimiter that follows it.
     */
    private static function csvDelimiter(string $text): string
    {
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $quoted = false;
        $blank = true;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $char = $text[$i];
            if ($char === '"') {
                $quoted = !$quoted; // an escaped "" flips twice
                $blank = false;
            } elseif (!$quoted && $char === "\n") {
                if (!$blank) {
                    break;
                }
                $counts = [',' => 0, ';' => 0, "\t" => 0]; // a blank line: keep looking
            } elseif (!$quoted && isset($counts[$char])) {
                $counts[$char]++;
                $blank = false;
            } elseif (!ctype_space($char)) {
                $blank = false;
            }
        }
        $best = ',';
        $bestCount = 0;
        foreach ($counts as $candidate => $count) {
            if ($count > $bestCount) {
                $best = (string) $candidate;
                $bestCount = $count;
            }
        }
        return $best;
    }

    /**
     * Open a session and freeze the copies it expects to find: those matching
     * the filter, of books still in the catalogue, that are not already out of
     * circulation (OFF_SHELF_STATES).
     *
     * @param array<string,mixed> $filter
     */
    public function open(string $name, ?int $sedeId, array $filter, ?int $operatorId, string $note = ''): int
    {
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '') {
            throw new \InvalidArgumentException(__('Indica un nome per la revisione.'));
        }
        $filter = self::normalizeFilter($filter);
        $sedeId = ($sedeId !== null && $sedeId > 0) ? $sedeId : null;
        $note = trim($note);
        [$where, $types, $params] = $this->snapshotWhere($sedeId, $filter);
        $filterJson = json_encode((object) $filter, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO inventario_sessioni (nome, sede_id, filtro, stato, aperta_da, aperta_il, note) VALUES (?, ?, ?, 'aperta', ?, NOW(), ?)"
            );
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $noteValue = $note !== '' ? $note : null;
            $stmt->bind_param('sisis', $name, $sedeId, $filterJson, $operatorId, $noteValue);
            $stmt->execute();
            $sessionId = (int) $this->db->insert_id;
            $stmt->close();

            // The code and title are frozen too: a copy deleted during the
            // stocktake must still be identifiable on the shelf from the report.
            $sql = 'INSERT INTO inventario_attese (sessione_id, copia_id, scaffale_atteso, stato_all_apertura, codice_atteso, titolo_atteso) '
                . 'SELECT ?, c.id, l.scaffale_id, c.stato, c.numero_inventario, l.titolo FROM copie c '
                . 'JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL '
                . 'WHERE c.stato NOT IN (' . self::sqlList(self::OFF_SHELF_STATES) . ')' . $where;
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('i' . $types, $sessionId, ...$params);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->db->prepare('UPDATE inventario_sessioni SET totale_attese = (SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = ?) WHERE id = ?');
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('ii', $sessionId, $sessionId);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.opened', [], [
            'nome' => $name,
            'filtro' => $filter,
            'totale_attese' => $this->expectedCount($sessionId),
        ], 'inserimento', 'inventory', $operatorId);

        return $sessionId;
    }

    /**
     * @param array<string,int|string> $filter
     * @return array{0:string,1:string,2:list<int|string>}
     */
    private function snapshotWhere(?int $sedeId, array $filter): array
    {
        $where = '';
        $types = '';
        $params = [];
        if ($sedeId !== null) {
            $where .= ' AND c.sede_id = ?';
            $types .= 'i';
            $params[] = $sedeId;
        }
        if (isset($filter['scaffale_id'])) {
            $where .= ' AND l.scaffale_id = ?';
            $types .= 'i';
            $params[] = (int) $filter['scaffale_id'];
        }
        if (isset($filter['mensola_id'])) {
            $where .= ' AND l.mensola_id = ?';
            $types .= 'i';
            $params[] = (int) $filter['mensola_id'];
        }
        if (isset($filter['genere_id'])) {
            $family = GenreTree::withDescendants($this->db, (int) $filter['genere_id']);
            $marks = implode(',', array_fill(0, count($family), '?'));
            $where .= " AND (l.genere_id IN ($marks) OR l.sottogenere_id IN ($marks))";
            $types .= str_repeat('i', count($family) * 2);
            array_push($params, ...$family, ...$family);
        }
        if (isset($filter['tipo_media'])) {
            $where .= ' AND l.tipo_media = ?';
            $types .= 's';
            $params[] = (string) $filter['tipo_media'];
        }
        if (isset($filter['collocazione_da'])) {
            $where .= ' AND l.collocazione >= ?';
            $types .= 's';
            $params[] = (string) $filter['collocazione_da'];
        }
        if (isset($filter['collocazione_a'])) {
            // The upper bound includes everything that starts with it: up to "B" takes "B.9.99" too.
            $upper = (string) $filter['collocazione_a'];
            $where .= " AND (l.collocazione <= ? OR l.collocazione LIKE ?)";
            $types .= 'ss';
            $params[] = $upper;
            $params[] = addcslashes($upper, '%_\\') . '%';
        }
        return [$where, $types, $params];
    }

    /** @return array<string,mixed>|null */
    public function find(int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, se.nome AS sede_nome, '
            . "TRIM(CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, ''))) AS aperta_da_nome, "
            . "TRIM(CONCAT(COALESCE(uc.nome, ''), ' ', COALESCE(uc.cognome, ''))) AS chiusa_da_nome "
            . 'FROM inventario_sessioni s '
            . 'LEFT JOIN sedi se ON se.id = s.sede_id '
            . 'LEFT JOIN utenti ua ON ua.id = s.aperta_da '
            . 'LEFT JOIN utenti uc ON uc.id = s.chiusa_da '
            . 'WHERE s.id = ?'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) ($row['filtro'] ?? '{}'), true);
        $row['filtro'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listSessions(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $select = 'SELECT s.id, s.nome, s.stato, s.totale_attese, s.aperta_il, s.chiusa_il, se.nome AS sede_nome, '
            . '(SELECT COUNT(*) FROM inventario_scansioni sc WHERE sc.sessione_id = s.id) AS scansioni, '
            . "TRIM(CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, ''))) AS aperta_da_nome "
            . 'FROM inventario_sessioni s '
            . 'LEFT JOIN sedi se ON se.id = s.sede_id '
            . 'LEFT JOIN utenti ua ON ua.id = s.aperta_da ';
        // Every open session is listed, however many: the limit only trims the
        // history of closed and cancelled ones, so an open one never drops off.
        $result = $this->db->query(
            '(' . $select . "WHERE s.stato = 'aperta') "
            . 'UNION ALL '
            . '(' . $select . "WHERE s.stato <> 'aperta' ORDER BY s.aperta_il DESC, s.id DESC LIMIT " . $limit . ') '
            . "ORDER BY FIELD(stato, 'aperta', 'chiusa', 'annullata'), aperta_il DESC, id DESC"
        );
        if ($result === false) {
            return [];
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        return $rows;
    }

    private function expectedCount(int $sessionId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM inventario_attese WHERE sessione_id = ?');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int) ($row[0] ?? 0);
    }

    private function assertOpen(int $sessionId): void
    {
        $stmt = $this->db->prepare('SELECT stato FROM inventario_sessioni WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null) {
            throw new \DomainException(__('Revisione non trovata.'));
        }
        if ($row[0] !== 'aperta') {
            throw new \DomainException(__('La revisione non è più aperta: non si possono registrare altre scansioni.'));
        }
    }

    /**
     * Record one scanned or typed code.
     *
     * @return array{esito:string,codice:string,copia_id:int|null,libro_id:int|null,titolo:string,collocazione:string,scaffale_atteso:string,stato_copia:string,fuori_circolazione:bool}
     */
    public function scan(int $sessionId, string $rawCode, ?int $operatorId, ?int $scanShelf = null): array
    {
        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            throw new \InvalidArgumentException(__('Codice mancante.'));
        }
        self::assertCodeLength($code);
        $this->assertOpen($sessionId);
        return $this->record($sessionId, $code, $operatorId, $scanShelf);
    }

    /**
     * Record a list of codes, one scan each, in file order.
     *
     * @param list<string> $codes
     * @return array{total:int,outcomes:array<string,int>}
     */
    public function importCodes(int $sessionId, array $codes, ?int $operatorId, ?int $scanShelf = null): array
    {
        if (count($codes) > self::MAX_UPLOAD_CODES) {
            throw new \InvalidArgumentException(sprintf(__('Il file contiene troppi codici: al massimo %d per caricamento.'), self::MAX_UPLOAD_CODES));
        }
        // Checked before anything is recorded: a file refused halfway would
        // leave its first codes behind, and the retry would find them duplicate.
        foreach ($codes as $code) {
            self::assertCodeLength(self::normalizeCode($code));
        }
        $this->assertOpen($sessionId);
        $outcomes = array_fill_keys(self::OUTCOMES, 0);
        $total = 0;
        // One transaction for the whole file: a file that fails halfway records
        // nothing, so uploading it again does not turn its first codes into
        // duplicates. The session lock is taken once, as record() would.
        $this->db->begin_transaction();
        try {
            $this->lockOpenSession($sessionId);
            foreach ($codes as $code) {
                $code = self::normalizeCode($code);
                if ($code === '') {
                    continue;
                }
                $result = $this->record($sessionId, $code, $operatorId, $scanShelf, true);
                $outcomes[$result['esito']]++;
                $total++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return ['total' => $total, 'outcomes' => $outcomes];
    }

    /**
     * `fuori_circolazione` flags a copy recorded as lost, damaged or away
     * (OFF_SHELF_STATES): such a copy is left out of the snapshot, so a scan of
     * it is out of scope, but the operator must be told it turned up rather
     * than that it is outside the filter. An expected copy that got one of
     * those states after the opening is found as usual, and flagged too.
     *
     * With $insideTransaction the caller already holds a transaction and the
     * session lock (importCodes()): the scan is written inside it, never in a
     * nested one.
     *
     * @return array{esito:string,codice:string,copia_id:int|null,libro_id:int|null,titolo:string,collocazione:string,scaffale_atteso:string,stato_copia:string,fuori_circolazione:bool}
     */
    private function record(int $sessionId, string $code, ?int $operatorId, ?int $scanShelf, bool $insideTransaction = false): array
    {
        // The scan is written under a shared lock on the session row. close()
        // and cancel() flip the state with an UPDATE that waits for that lock,
        // so a scan either lands before the session leaves 'aperta' (and close()
        // sees it before marking copies lost) or is refused afterwards.
        if (!$insideTransaction) {
            $this->db->begin_transaction();
        }
        try {
            if (!$insideTransaction) {
                $this->lockOpenSession($sessionId);
            }
            // `copie` has no deleted_at: a copy of a book removed from the catalogue is unknown.
            $stmt = $this->db->prepare(
                'SELECT c.id, c.libro_id, c.stato, l.titolo, l.collocazione FROM copie c '
                . 'JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL '
                . 'WHERE c.numero_inventario = ? LIMIT 1'
            );
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $copy = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $copyId = is_array($copy) ? (int) $copy['id'] : null;
            $expected = null;
            $alreadyScanned = false;
            $onLoan = false;
            if ($copyId !== null) {
                $stmt = $this->db->prepare('SELECT scaffale_atteso FROM inventario_attese WHERE sessione_id = ? AND copia_id = ?');
                if ($stmt === false) {
                    throw new \RuntimeException($this->db->error);
                }
                $stmt->bind_param('ii', $sessionId, $copyId);
                $stmt->execute();
                $expected = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $this->db->prepare('SELECT 1 FROM inventario_scansioni WHERE sessione_id = ? AND copia_id = ? LIMIT 1');
                if ($stmt === false) {
                    throw new \RuntimeException($this->db->error);
                }
                $stmt->bind_param('ii', $sessionId, $copyId);
                $stmt->execute();
                $alreadyScanned = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();

                if ($insideTransaction) {
                    // A file upload holds one transaction for the whole file:
                    // locking reads would keep loans and returns of every copy
                    // already recorded waiting until the last code, and deadlock
                    // with a loan of a copy further down the file. It reads the
                    // committed state, as the upload found it.
                    $onLoan = $this->isOnLoan($copyId);
                } else {
                    // A single scan reads the loan status inside its short
                    // transaction with locking reads, in the order a return
                    // takes its locks (the loan, then the copy): a return in
                    // progress is waited for, so the scan never records
                    // 'in_prestito' for a copy already back on the shelf.
                    $onLoan = $this->isOnLoan($copyId, true);
                    $copy['stato'] = $this->lockedCopyState($copyId) ?? (string) ($copy['stato'] ?? '');
                }
                $onLoan = $onLoan || in_array($copy['stato'], self::ON_LOAN_COPY_STATES, true);
            }

            $expectedShelf = is_array($expected) && $expected['scaffale_atteso'] !== null ? (int) $expected['scaffale_atteso'] : null;
            $outcome = self::classify($copyId !== null, $alreadyScanned, is_array($expected), $onLoan, $expectedShelf, $scanShelf);

            $storedCopyId = in_array($outcome, ['sconosciuta', 'duplicata'], true) ? null : $copyId;
            try {
                $this->insertScan($sessionId, $storedCopyId, $code, $outcome, $operatorId);
            } catch (\mysqli_sql_exception $e) {
                // Two operators scanned the same copy at the same instant: the UNIQUE
                // (sessione_id, copia_id) let one win, the other is a duplicate.
                // A failed INSERT undoes only itself, not the surrounding transaction.
                if ($e->getCode() !== 1062 || $storedCopyId === null) {
                    throw $e;
                }
                $outcome = 'duplicata';
                $this->insertScan($sessionId, null, $code, $outcome, $operatorId);
            }
            if (!$insideTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if (!$insideTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }

        return [
            'esito' => $outcome,
            'codice' => $code,
            'copia_id' => $copyId,
            'libro_id' => is_array($copy) ? (int) $copy['libro_id'] : null,
            'titolo' => is_array($copy) ? (string) $copy['titolo'] : '',
            'collocazione' => is_array($copy) ? (string) ($copy['collocazione'] ?? '') : '',
            'scaffale_atteso' => $expectedShelf !== null ? $this->shelfLabel($expectedShelf) : '',
            'stato_copia' => is_array($copy) ? (string) $copy['stato'] : '',
            'fuori_circolazione' => is_array($copy) && in_array((string) $copy['stato'], self::OFF_SHELF_STATES, true),
        ];
    }

    private function lockOpenSession(int $sessionId): void
    {
        $stmt = $this->db->prepare('SELECT stato FROM inventario_sessioni WHERE id = ? LOCK IN SHARE MODE');
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null || $row[0] !== 'aperta') {
            throw new \DomainException(__('La revisione non è più aperta: non si possono registrare altre scansioni.'));
        }
    }

    /**
     * Move an open session to $state in one conditional UPDATE. Only the caller
     * whose UPDATE changes the row may go on: a concurrent close or cancel that
     * lost the race gets a DomainException and must not touch anything.
     */
    private function claimTransition(int $sessionId, string $state, ?int $operatorId, ?string $missingAction = null): void
    {
        $stmt = $this->db->prepare("UPDATE inventario_sessioni SET stato = ?, chiusa_da = ?, chiusa_il = NOW(), azione_mancanti = ? WHERE id = ? AND stato = 'aperta'");
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('sisi', $state, $operatorId, $missingAction, $sessionId);
        $stmt->execute();
        $claimed = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$claimed) {
            $this->assertOpen($sessionId); // "not found" when the row is gone
            throw new \DomainException(__('La revisione non è più aperta: non si possono registrare altre scansioni.'));
        }
    }

    private function insertScan(int $sessionId, ?int $copyId, string $code, string $outcome, ?int $operatorId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventario_scansioni (sessione_id, copia_id, codice_letto, esito, scansionato_da, scansionato_il) VALUES (?, ?, ?, ?, ?, NOW())'
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        try {
            $stmt->bind_param('iissi', $sessionId, $copyId, $code, $outcome, $operatorId);
            $stmt->execute();
        } finally {
            $stmt->close();
        }
    }

    /** @param list<string> $values */
    private static function sqlList(array $values): string
    {
        return "'" . implode("','", $values) . "'";
    }

    /**
     * SQL predicate: the expected copy `a` (joined as `c`) is in the "on loan"
     * bucket. NULL when the copy no longer exists.
     */
    private static function onLoanSql(): string
    {
        return '(c.stato IN (' . self::sqlList(self::ON_LOAN_COPY_STATES) . ') OR EXISTS ('
            . 'SELECT 1 FROM prestiti p WHERE p.copia_id = a.copia_id AND p.attivo = 1 AND p.stato IN (' . self::sqlList(self::ON_LOAN_LOAN_STATES) . ')))';
    }

    /**
     * Lent, or set aside on the pickup shelf: see ON_LOAN_LOAN_STATES. With
     * $lock the read is a locking one (inside a transaction), which waits for
     * a return of the copy still in progress and reads what it committed.
     */
    private function isOnLoan(int $copyId, bool $lock = false): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM prestiti WHERE copia_id = ? AND attivo = 1 AND stato IN (' . self::sqlList(self::ON_LOAN_LOAN_STATES) . ') LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    /** The copy's state, read under a shared lock (inside a transaction); null when it is gone. */
    private function lockedCopyState(int $copyId): ?string
    {
        $stmt = $this->db->prepare('SELECT stato FROM copie WHERE id = ? LOCK IN SHARE MODE');
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row === null ? null : (string) $row[0];
    }

    /** @var array<int,string> */
    private array $shelfLabels = [];

    public function shelfLabel(int $shelfId): string
    {
        if (!isset($this->shelfLabels[$shelfId])) {
            $stmt = $this->db->prepare('SELECT codice, nome FROM scaffali WHERE id = ?');
            $label = '#' . $shelfId;
            if ($stmt !== false) {
                $stmt->bind_param('i', $shelfId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (is_array($row)) {
                    $label = trim('[' . (string) ($row['codice'] ?? '') . '] ' . (string) ($row['nome'] ?? ''));
                }
            }
            $this->shelfLabels[$shelfId] = $label;
        }
        return $this->shelfLabels[$shelfId];
    }

    /**
     * A closed session reports the buckets frozen at close. A session closed
     * before esito_chiusura existed still has NULL rows and keeps the live
     * computation, as does an open or cancelled one.
     */
    private function isFrozen(int $sessionId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM inventario_sessioni s WHERE s.id = ? AND s.stato <> 'aperta' "
            . 'AND NOT EXISTS (SELECT 1 FROM inventario_attese a WHERE a.sessione_id = s.id AND a.esito_chiusura IS NULL)'
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $frozen = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $frozen;
    }

    /**
     * Counters. The four buckets of the expected copies always add up to the
     * snapshot size: found + misplaced + on loan + missing = expected. They
     * are live while the session is open and frozen once it is closed; `perse`
     * is the part of `mancanti` the close marked lost (0 while open).
     *
     * @return array{attese:int,trovate:int,fuori_posto:int,in_prestito:int,mancanti:int,perse:int,sconosciute:int,fuori_ambito:int,duplicate:int,scansioni:int,mie:int}
     */
    public function summary(int $sessionId, ?int $operatorId = null): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        $mine = 0;
        $stmt = $this->db->prepare('SELECT esito, COUNT(*) AS n, SUM(scansionato_da <=> ?) AS mine FROM inventario_scansioni WHERE sessione_id = ? GROUP BY esito');
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $mineOf = $operatorId ?? 0;
        $stmt->bind_param('ii', $mineOf, $sessionId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $counts[(string) $row['esito']] = (int) $row['n'];
            $mine += (int) $row['mine'];
        }
        $stmt->close();

        if ($this->isFrozen($sessionId)) {
            $frozen = array_fill_keys(self::CLOSE_OUTCOMES, 0);
            $stmt = $this->db->prepare('SELECT esito_chiusura, COUNT(*) AS n FROM inventario_attese WHERE sessione_id = ? GROUP BY esito_chiusura');
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $outcome = (string) $row['esito_chiusura'];
                if (isset($frozen[$outcome])) {
                    $frozen[$outcome] = (int) $row['n'];
                }
            }
            $stmt->close();
            $found = $frozen['trovata'];
            $misplaced = $frozen['fuori_posto'];
            $onLoan = $frozen['in_prestito'];
            $lost = $frozen['persa'];
            $missing = $frozen['mancante'] + $lost;
        } else {
            // Counted in SQL: the scan page asks for this on every scan and every
            // poll, so it must not pull the unscanned rows into PHP. A deleted copy
            // (c.stato NULL) is not on loan and stays among the missing ones.
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(t.in_prestito), 0) AS lent FROM ('
                . 'SELECT COALESCE(' . self::onLoanSql() . ', 0) AS in_prestito '
                . 'FROM inventario_attese a '
                . 'LEFT JOIN inventario_scansioni s ON s.sessione_id = a.sessione_id AND s.copia_id = a.copia_id '
                . 'LEFT JOIN copie c ON c.id = a.copia_id '
                . 'WHERE a.sessione_id = ? AND s.id IS NULL) t'
            );
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $unscannedOnLoan = (int) ($row['lent'] ?? 0);
            $found = $counts['trovata'];
            $misplaced = $counts['fuori_posto'];
            $onLoan = $counts['in_prestito'] + $unscannedOnLoan;
            $lost = 0;
            $missing = (int) ($row['n'] ?? 0) - $unscannedOnLoan;
        }

        return [
            'attese' => $this->expectedCount($sessionId),
            'trovate' => $found,
            'fuori_posto' => $misplaced,
            'in_prestito' => $onLoan,
            'mancanti' => $missing,
            'perse' => $lost,
            'sconosciute' => $counts['sconosciuta'],
            'fuori_ambito' => $counts['fuori_ambito'],
            'duplicate' => $counts['duplicata'],
            'scansioni' => array_sum($counts),
            'mie' => $mine,
        ];
    }

    /**
     * Expected copies nobody scanned. `in_prestito` is their loan status right
     * now, or with $frozen the one recorded at close (esito_chiusura); every
     * other column is the copy as it is today.
     *
     * @return list<array<string,mixed>>
     */
    private function unscannedExpected(int $sessionId, bool $frozen = false): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->unscannedExpectedResult($sessionId, $frozen)->fetch_all(MYSQLI_ASSOC);
        return $rows;
    }

    /** The unscanned expected copies as a buffered result, read one row at a time by the report. */
    private function unscannedExpectedResult(int $sessionId, bool $frozen): \mysqli_result
    {
        $stmt = $this->db->prepare(
            'SELECT a.copia_id, a.scaffale_atteso, a.stato_all_apertura, a.esito_chiusura, '
            . 'COALESCE(c.numero_inventario, a.codice_atteso) AS numero_inventario, c.stato AS stato_attuale, c.libro_id, c.updated_at AS copia_aggiornata_il, '
            . 'COALESCE(l.titolo, a.titolo_atteso) AS titolo, l.id AS libro_attivo, l.collocazione, l.tipo_media, '
            . ($frozen ? "(a.esito_chiusura = 'in_prestito')" : self::onLoanSql()) . ' AS in_prestito '
            . 'FROM inventario_attese a '
            . 'LEFT JOIN inventario_scansioni s ON s.sessione_id = a.sessione_id AND s.copia_id = a.copia_id '
            . 'LEFT JOIN copie c ON c.id = a.copia_id '
            . 'LEFT JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL '
            . 'WHERE a.sessione_id = ? AND s.id IS NULL '
            . 'ORDER BY l.collocazione, l.titolo, c.numero_inventario'
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result === false) {
            throw new \RuntimeException($this->db->error);
        }
        return $result;
    }

    /**
     * Full report rows: every expected copy with its bucket, plus the scans
     * that are not about an expected copy (unknown, out of scope, duplicate).
     * On a closed session the bucket is the one frozen at close; a copy the
     * close marked lost stays a `mancante` row, with `stato_copia` saying
     * 'perso'. `stato_copia` is always the copy's state today.
     *
     * With $limit at most that many rows are read: the PDF export asks for one
     * more than it can hold, to tell a report that grew past its limit.
     *
     * @return list<array{categoria:string,codice:string,titolo:string,tipo_media:string,collocazione:string,scaffale_atteso:string,stato_copia:string,operatore:string,quando:string}>
     */
    public function reportRows(int $sessionId, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->eachReportRow($sessionId) as $row) {
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The rows of reportRows() one at a time, for an export that writes them
     * as they come (the CSV) instead of holding the whole report: the database
     * results are buffered by the driver, but never turned into one PHP array.
     *
     * @return \Generator<int,array{categoria:string,codice:string,titolo:string,tipo_media:string,collocazione:string,scaffale_atteso:string,stato_copia:string,operatore:string,quando:string}>
     */
    public function eachReportRow(int $sessionId): \Generator
    {
        $result = $this->unscannedExpectedResult($sessionId, $this->isFrozen($sessionId));
        try {
            while (is_array($row = $result->fetch_assoc())) {
                yield [
                    'categoria' => (int) $row['in_prestito'] === 1 ? 'in_prestito' : 'mancante',
                    'codice' => (string) ($row['numero_inventario'] ?? ('#' . (int) $row['copia_id'])),
                    'titolo' => (string) ($row['titolo'] ?? ''),
                    'tipo_media' => (string) ($row['tipo_media'] ?? ''),
                    'collocazione' => (string) ($row['collocazione'] ?? ''),
                    'scaffale_atteso' => $row['scaffale_atteso'] !== null ? $this->shelfLabel((int) $row['scaffale_atteso']) : '',
                    'stato_copia' => (string) ($row['stato_attuale'] ?? ''),
                    'operatore' => '',
                    'quando' => '',
                ];
            }
        } finally {
            $result->free();
        }
        // Every scan, not the latest N: the summary counts them all, so must the report.
        $result = $this->scansResult($sessionId, null, null);
        try {
            while (is_array($scan = $result->fetch_assoc())) {
                yield [
                    'categoria' => (string) $scan['esito'],
                    'codice' => (string) $scan['codice_letto'],
                    'titolo' => (string) ($scan['titolo'] ?? ''),
                    'tipo_media' => (string) ($scan['tipo_media'] ?? ''),
                    'collocazione' => (string) ($scan['collocazione'] ?? ''),
                    'scaffale_atteso' => $scan['scaffale_atteso'] !== null ? $this->shelfLabel((int) $scan['scaffale_atteso']) : '',
                    'stato_copia' => (string) ($scan['stato_copia'] ?? ''),
                    'operatore' => (string) ($scan['operatore'] ?? ''),
                    'quando' => (string) ($scan['scansionato_il'] ?? ''),
                ];
            }
        } finally {
            $result->free();
        }
    }

    /** How many rows reportRows() returns, counted without loading them. */
    public function reportRowCount(int $sessionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM inventario_attese a '
            . 'LEFT JOIN inventario_scansioni s ON s.sessione_id = a.sessione_id AND s.copia_id = a.copia_id '
            . 'WHERE a.sessione_id = ? AND s.id IS NULL) '
            . '+ (SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id = ?)'
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('ii', $sessionId, $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return (int) ($row[0] ?? 0);
    }

    /**
     * Most recent scans first; with $operatorId only that operator's. A null
     * $limit returns them all (the report); the live list asks for a page.
     *
     * @return list<array<string,mixed>>
     */
    public function scans(int $sessionId, ?int $operatorId = null, ?int $limit = 200): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->scansResult($sessionId, $operatorId, $limit)->fetch_all(MYSQLI_ASSOC);
        return $rows;
    }

    private function scansResult(int $sessionId, ?int $operatorId, ?int $limit): \mysqli_result
    {
        $limit = $limit === null ? null : max(1, min(100000, $limit));
        $sql = 'SELECT s.id, s.codice_letto, s.esito, s.scansionato_il, s.scansionato_da, s.copia_id, '
            . 'COALESCE(c.numero_inventario, a.codice_atteso, s.codice_letto) AS codice, COALESCE(l.titolo, a.titolo_atteso) AS titolo, '
            . 'l.collocazione, l.tipo_media, c.stato AS stato_copia, a.scaffale_atteso, '
            . "TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS operatore "
            . 'FROM inventario_scansioni s '
            . 'LEFT JOIN copie c ON c.id = s.copia_id '
            . 'LEFT JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL '
            . 'LEFT JOIN inventario_attese a ON a.sessione_id = s.sessione_id AND a.copia_id = s.copia_id '
            . 'LEFT JOIN utenti u ON u.id = s.scansionato_da '
            . 'WHERE s.sessione_id = ?'
            . ($operatorId !== null ? ' AND s.scansionato_da = ?' : '')
            . ' ORDER BY s.id DESC' . ($limit !== null ? ' LIMIT ' . $limit : '');
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        if ($operatorId !== null) {
            $stmt->bind_param('ii', $sessionId, $operatorId);
        } else {
            $stmt->bind_param('i', $sessionId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        if ($result === false) {
            throw new \RuntimeException($this->db->error);
        }
        return $result;
    }

    /**
     * Close the session: claim it, freeze the bucket of every expected copy
     * in inventario_attese.esito_chiusura, then with $markLost turn every
     * missing copy that is not already explained by its state (lent or waiting
     * for pickup, damaged, lost, at the restorer's, in transit, in maintenance)
     * into 'perso', one book per transaction, with the same hold reassignment
     * and availability recalculation as a manual change of a copy's state and
     * an ActivityLog entry per copy. Those copies are frozen as 'persa'.
     *
     * @return array{marked:int,failed:int}
     */
    public function close(int $sessionId, bool $markLost, ?int $operatorId): array
    {
        // Publish the closed state and its frozen report together. Scans and
        // competing transitions wait on the session lock until commit; if the
        // snapshot fails, rollback leaves the session open for a retry.
        $this->db->begin_transaction();
        try {
            $this->claimTransition($sessionId, 'chiusa', $operatorId, $markLost ? 'perso' : 'nessuna');
            $this->freezeOutcomes($sessionId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        ['marked' => $marked, 'failed' => $failed] = $markLost
            ? $this->markMissingLost($sessionId, $operatorId)
            : ['marked' => 0, 'failed' => 0];

        $summary = $this->summary($sessionId);

        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.closed', [], [
            'azione_mancanti' => $markLost ? 'perso' : 'nessuna',
            'copie_perse' => $marked,
            'copie_non_aggiornate' => $failed,
        ] + $summary, 'aggiornamento', 'inventory', $operatorId);

        return ['marked' => $marked, 'failed' => $failed];
    }

    /**
     * Finish a mark-lost close that did not reach every copy (the request died
     * or some books failed): the session is already closed, so close() cannot
     * be repeated. Only a session closed with "mark as lost" qualifies, and the
     * marking skips every copy already lost, away, lent or found meanwhile, so
     * running it again is harmless.
     *
     * @return array{marked:int,failed:int}
     */
    public function resumeMarkLost(int $sessionId, ?int $operatorId): array
    {
        $session = $this->find($sessionId);
        if ($session === null) {
            throw new \DomainException(__('Revisione non trovata.'));
        }
        if ($session['stato'] !== 'chiusa' || ($session['azione_mancanti'] ?? null) !== 'perso') {
            throw new \DomainException(__('Azione non valida.'));
        }
        $result = $this->markMissingLost($sessionId, $operatorId);
        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.lost_resumed', [], [
            'copie_perse' => $result['marked'],
            'copie_non_aggiornate' => $result['failed'],
        ], 'aggiornamento', 'inventory', $operatorId);
        return $result;
    }

    /**
     * Missing copies a mark-lost close still has to mark: the unscanned, not
     * lent copies whose book is still in the catalogue and whose state is not
     * already lost or away. Meaningful for a session closed with "mark as
     * lost" (azione_mancanti = 'perso'), which the caller checks.
     */
    public function pendingLostCount(int $sessionId): int
    {
        return array_sum(array_map('count', $this->missingToMark($sessionId)));
    }

    /**
     * The unscanned expected copies a mark-lost close applies to, by book.
     * Only rows the close froze as missing ('mancante') qualify: a row it
     * already marked ('persa') is never marked again. A copy changed after
     * the close (found and put back, repaired, lent) is someone's later
     * decision, not a copy the close failed to reach, so it is left alone
     * (changedSinceClose()).
     *
     * @return array<int,list<array<string,mixed>>>
     */
    private function missingToMark(int $sessionId): array
    {
        $closedAt = $this->closedAt($sessionId);
        $byBook = [];
        foreach ($this->unscannedExpected($sessionId, true) as $row) {
            if (($row['esito_chiusura'] ?? null) !== 'mancante') {
                continue; // already marked lost by the close, or not missing at close
            }
            if ((int) $row['in_prestito'] === 1 || $row['libro_id'] === null || $row['libro_attivo'] === null) {
                continue; // lent or held for pickup, or the copy/book left the catalogue meanwhile
            }
            if (self::changedSinceClose($row['copia_aggiornata_il'] ?? null, $closedAt)) {
                continue; // handled by someone after the close
            }
            if (in_array((string) $row['stato_attuale'], self::AWAY_STATES, true)) {
                continue;
            }
            $byBook[(int) $row['libro_id']][] = $row;
        }
        return $byBook;
    }

    /**
     * The copy was changed at or after the close. Both columns hold whole
     * seconds, so a change in the very second of the close cannot be told
     * from one just before it: it counts as later, and the copy is left
     * alone rather than declared lost over someone's edit. The close itself
     * never touches a copy it has not marked, so it cannot trip this.
     */
    private static function changedSinceClose(mixed $changedAt, ?string $closedAt): bool
    {
        return $closedAt !== null && is_string($changedAt) && $changedAt >= $closedAt;
    }

    /** When the session was closed (MySQL DATETIME text), or NULL. */
    private function closedAt(int $sessionId): ?string
    {
        $stmt = $this->db->prepare('SELECT chiusa_il FROM inventario_sessioni WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $value = $stmt->get_result()->fetch_column();
        $stmt->close();
        return is_string($value) ? $value : null;
    }

    /** @return array{marked:int,failed:int} */
    private function markMissingLost(int $sessionId, ?int $operatorId): array
    {
        $marked = 0;
        $failed = 0;
        foreach ($this->missingToMark($sessionId) as $bookId => $copies) {
            try {
                $lostInBook = $this->markBookCopiesLost($bookId, $copies, $sessionId, $operatorId);
                $marked += count($lostInBook);
            } catch (\Throwable $e) {
                $failed += count($copies);
                SecureLogger::error('[Stocktake] marking missing copies as lost failed', [
                    'sessione_id' => $sessionId,
                    'libro_id' => $bookId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return ['marked' => $marked, 'failed' => $failed];
    }

    /**
     * Write each expected copy's bucket into esito_chiusura in one statement:
     * the scan outcome for a scanned copy (one scan per copy: UNIQUE
     * sessione_id, copia_id), else on loan or missing as of this instant. A
     * copy that no longer exists (c.stato NULL) is missing. close() marks the
     * copies it declares lost afterwards.
     */
    private function freezeOutcomes(int $sessionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE inventario_attese a '
            . 'LEFT JOIN inventario_scansioni s ON s.sessione_id = a.sessione_id AND s.copia_id = a.copia_id '
            . 'LEFT JOIN copie c ON c.id = a.copia_id '
            . 'SET a.esito_chiusura = CASE '
            . "WHEN s.esito IN ('trovata','fuori_posto','in_prestito') THEN s.esito "
            . 'WHEN COALESCE(' . self::onLoanSql() . ", 0) = 1 THEN 'in_prestito' "
            . "ELSE 'mancante' END "
            . 'WHERE a.sessione_id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * The copies this close turned into 'perso' leave the frozen 'mancante'
     * bucket for 'persa'; the report keeps counting them among the missing.
     *
     * @param list<int> $copyIds
     */
    private function markFrozenLost(int $sessionId, array $copyIds): void
    {
        foreach (array_chunk($copyIds, 500) as $chunk) {
            $ids = implode(',', array_map('intval', $chunk));
            $stmt = $this->db->prepare("UPDATE inventario_attese SET esito_chiusura = 'persa' WHERE sessione_id = ? AND copia_id IN ({$ids})");
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @param list<array<string,mixed>> $copies
     * @return list<int> ids of the copies now marked lost
     */
    private function markBookCopiesLost(int $bookId, array $copies, int $sessionId, ?int $operatorId): array
    {
        $reassignment = new ReservationReassignmentService($this->db);
        $reassignment->setExternalTransaction(true);
        $changed = [];

        $this->db->begin_transaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            if ($lock === false) {
                throw new \RuntimeException($this->db->error);
            }
            $lock->bind_param('i', $bookId);
            $lock->execute();
            $bookLocked = $lock->get_result()->fetch_row() !== null;
            $lock->close();
            if (!$bookLocked) {
                $this->db->rollback();
                return [];
            }
            $closedAt = $this->closedAt($sessionId);

            foreach ($copies as $copy) {
                $copyId = (int) $copy['copia_id'];
                // Re-read under the book lock: a return, a loan or any other
                // edit of the copy may have happened since missingToMark().
                $stmt = $this->db->prepare('SELECT stato, numero_inventario, note, updated_at FROM copie WHERE id = ? AND libro_id = ? FOR UPDATE');
                if ($stmt === false) {
                    throw new \RuntimeException($this->db->error);
                }
                $stmt->bind_param('ii', $copyId, $bookId);
                $stmt->execute();
                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!is_array($current) || in_array((string) $current['stato'], self::AWAY_STATES, true) || $this->isOnLoan($copyId)) {
                    continue;
                }
                if (self::changedSinceClose($current['updated_at'] ?? null, $closedAt)) {
                    continue; // edited after the close: someone's later decision
                }
                unset($current['updated_at']);
                $scanned = $this->db->prepare('SELECT 1 FROM inventario_scansioni WHERE sessione_id = ? AND copia_id = ? LIMIT 1');
                if ($scanned === false) {
                    throw new \RuntimeException($this->db->error);
                }
                $scanned->bind_param('ii', $sessionId, $copyId);
                $scanned->execute();
                $foundMeanwhile = $scanned->get_result()->fetch_row() !== null;
                $scanned->close();
                if ($foundMeanwhile) {
                    continue;
                }

                $stmt = $this->db->prepare("UPDATE copie SET stato = 'perso', updated_at = NOW() WHERE id = ?");
                if ($stmt === false) {
                    throw new \RuntimeException($this->db->error);
                }
                $stmt->bind_param('i', $copyId);
                $stmt->execute();
                $stmt->close();

                // A copy may carry several future holds: move every one of them elsewhere.
                // Same predicate as reassignOnCopyLost(), window still valid included:
                // an expired hold is left to the expiry cleanup, and counting it here
                // would spin the guard without the reassignment ever taking it.
                // A hold still there after the guard rolls the whole book back:
                // committing would leave a reader's hold on a lost copy.
                $today = DateHelper::today();
                for ($guard = 0; ; $guard++) {
                    $held = $this->db->prepare(
                        "SELECT 1 FROM prestiti WHERE copia_id = ? AND data_scadenza >= ? AND ((attivo = 1 AND stato IN ('prenotato','da_ritirare')) OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione')) LIMIT 1"
                    );
                    if ($held === false) {
                        throw new \RuntimeException($this->db->error);
                    }
                    $held->bind_param('is', $copyId, $today);
                    $held->execute();
                    $stillHeld = $held->get_result()->fetch_row() !== null;
                    $held->close();
                    if (!$stillHeld) {
                        break;
                    }
                    if ($guard >= 1000) {
                        throw new \RuntimeException('Holds still on copy ' . $copyId . ' after reassignment');
                    }
                    $reassignment->reassignOnCopyLost($copyId);
                }
                $changed[] = ['id' => $copyId] + $current;
            }

            if ($changed !== [] && !(new DataIntegrity($this->db))->recalculateBookAvailability($bookId, insideTransaction: true)) {
                throw new \RuntimeException('Book availability recalculation failed');
            }
            // Keep the historical outcome in the same transaction as the copy
            // state, hold reassignment and availability it describes.
            $this->markFrozenLost($sessionId, array_map(static fn (array $copy): int => (int) $copy['id'], $changed));
            // The audit entry too: written after the commit, a request cut off
            // in between left a 'persa' copy that a resume skips, so its
            // copy.inventory_lost event was never written.
            foreach ($changed as $copy) {
                $logged = ActivityLog::recordBookEvent(
                    $this->db,
                    $bookId,
                    'aggiornamento',
                    'copy',
                    'copy.inventory_lost',
                    ['numero_inventario' => $copy['numero_inventario'] ?? null, 'stato' => $copy['stato'] ?? null],
                    ['numero_inventario' => $copy['numero_inventario'] ?? null, 'stato' => 'perso', 'inventario_sessione' => $sessionId],
                    $operatorId,
                    (int) $copy['id'],
                    null,
                    'inventory'
                );
                if (!$logged) {
                    throw new \RuntimeException('Audit entry for the lost copy ' . (int) $copy['id'] . ' could not be written');
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        try {
            $reassignment->flushDeferredNotifications();
        } catch (\Throwable $e) {
            SecureLogger::warning('[Stocktake] reassignment notification failed', ['error' => $e->getMessage()]);
        }
        return array_map(static fn (array $copy): int => (int) $copy['id'], $changed);
    }

    public function cancel(int $sessionId, ?int $operatorId): void
    {
        $this->claimTransition($sessionId, 'annullata', $operatorId);
        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.cancelled', [], [], 'aggiornamento', 'inventory', $operatorId);
    }
}
