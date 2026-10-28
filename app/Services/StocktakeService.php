<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\ActivityLog;
use App\Support\DataIntegrity;
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
 */
final class StocktakeService
{
    public const OUTCOMES = ['trovata', 'fuori_posto', 'in_prestito', 'sconosciuta', 'duplicata', 'fuori_ambito'];
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
     */
    private const ON_LOAN_COPY_STATES = ['prestato', 'prenotato'];
    private const ON_LOAN_LOAN_STATES = ['in_corso', 'in_ritardo', 'prenotato', 'da_ritirare'];

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
     * Keep only the filter keys the snapshot understands, with valid values.
     *
     * @param array<string,mixed> $input
     * @return array{scaffale_id?:int,mensola_id?:int,genere_id?:int,tipo_media?:string,collocazione_da?:string,collocazione_a?:string}
     */
    public static function normalizeFilter(array $input): array
    {
        $filter = [];
        foreach (['scaffale_id', 'mensola_id', 'genere_id'] as $key) {
            $value = $input[$key] ?? null;
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $filter[$key] = (int) $value;
            }
        }
        $media = $input['tipo_media'] ?? null;
        if (is_string($media) && in_array($media, self::MEDIA_TYPES, true)) {
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

    /** A scanned or typed code, without the control characters some wedge scanners append. */
    public static function normalizeCode(string $code): string
    {
        $code = preg_replace('/[\x00-\x1F\x7F]+/u', '', $code) ?? '';
        $code = trim($code);
        return mb_substr($code, 0, self::MAX_CODE_LENGTH);
    }

    /**
     * Codes from a .txt (one per line) or .csv (first column) export. Order and
     * repetitions are kept: a code read twice by an offline scanner is a duplicate.
     *
     * @return list<string>
     */
    public static function parseCodeList(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        $codes = [];
        $first = true;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $cells = preg_split('/[,;\t]/', $line) ?: [];
            $cell = '';
            foreach ($cells as $candidate) {
                $candidate = trim($candidate, " \"'");
                if ($candidate !== '') {
                    $cell = $candidate;
                    break;
                }
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

            $sql = 'INSERT INTO inventario_attese (sessione_id, copia_id, scaffale_atteso, stato_all_apertura) '
                . 'SELECT ?, c.id, l.scaffale_id, c.stato FROM copie c '
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
        $result = $this->db->query(
            'SELECT s.id, s.nome, s.stato, s.totale_attese, s.aperta_il, s.chiusa_il, se.nome AS sede_nome, '
            . '(SELECT COUNT(*) FROM inventario_scansioni sc WHERE sc.sessione_id = s.id) AS scansioni, '
            . "TRIM(CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, ''))) AS aperta_da_nome "
            . 'FROM inventario_sessioni s '
            . 'LEFT JOIN sedi se ON se.id = s.sede_id '
            . 'LEFT JOIN utenti ua ON ua.id = s.aperta_da '
            . "ORDER BY FIELD(s.stato, 'aperta', 'chiusa', 'annullata'), s.aperta_il DESC, s.id DESC LIMIT " . $limit
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
     * @return array{esito:string,codice:string,copia_id:int|null,libro_id:int|null,titolo:string,collocazione:string,scaffale_atteso:string,stato_copia:string}
     */
    public function scan(int $sessionId, string $rawCode, ?int $operatorId, ?int $scanShelf = null): array
    {
        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            throw new \InvalidArgumentException(__('Codice mancante.'));
        }
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
        $this->assertOpen($sessionId);
        $outcomes = array_fill_keys(self::OUTCOMES, 0);
        $total = 0;
        foreach ($codes as $code) {
            $code = self::normalizeCode($code);
            if ($code === '') {
                continue;
            }
            $result = $this->record($sessionId, $code, $operatorId, $scanShelf);
            $outcomes[$result['esito']]++;
            $total++;
        }
        return ['total' => $total, 'outcomes' => $outcomes];
    }

    /**
     * @return array{esito:string,codice:string,copia_id:int|null,libro_id:int|null,titolo:string,collocazione:string,scaffale_atteso:string,stato_copia:string}
     */
    private function record(int $sessionId, string $code, ?int $operatorId, ?int $scanShelf): array
    {
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

            $onLoan = in_array((string) ($copy['stato'] ?? ''), self::ON_LOAN_COPY_STATES, true) || $this->isOnLoan($copyId);
        }

        $expectedShelf = is_array($expected) && $expected['scaffale_atteso'] !== null ? (int) $expected['scaffale_atteso'] : null;
        $outcome = self::classify($copyId !== null, $alreadyScanned, is_array($expected), $onLoan, $expectedShelf, $scanShelf);

        $storedCopyId = in_array($outcome, ['sconosciuta', 'duplicata'], true) ? null : $copyId;
        // The scan is written under a shared lock on the session row. close()
        // and cancel() flip the state with an UPDATE that waits for that lock,
        // so a scan either lands before the session leaves 'aperta' (and close()
        // sees it before marking copies lost) or is refused afterwards.
        $this->db->begin_transaction();
        try {
            $this->lockOpenSession($sessionId);
            try {
                $this->insertScan($sessionId, $storedCopyId, $code, $outcome, $operatorId);
            } catch (\mysqli_sql_exception $e) {
                // Two operators scanned the same copy at the same instant: the UNIQUE
                // (sessione_id, copia_id) let one win, the other is a duplicate.
                if ($e->getCode() !== 1062 || $storedCopyId === null) {
                    throw $e;
                }
                $outcome = 'duplicata';
                $this->insertScan($sessionId, null, $code, $outcome, $operatorId);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
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
    private function claimTransition(int $sessionId, string $state, ?int $operatorId): void
    {
        $stmt = $this->db->prepare("UPDATE inventario_sessioni SET stato = ?, chiusa_da = ?, chiusa_il = NOW() WHERE id = ? AND stato = 'aperta'");
        if ($stmt === false) {
            throw new \RuntimeException($this->db->error);
        }
        $stmt->bind_param('sii', $state, $operatorId, $sessionId);
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

    /** Lent, or set aside on the pickup shelf: see ON_LOAN_LOAN_STATES. */
    private function isOnLoan(int $copyId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM prestiti WHERE copia_id = ? AND attivo = 1 AND stato IN (' . self::sqlList(self::ON_LOAN_LOAN_STATES) . ') LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $copyId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
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
     * Live counters. The four buckets of the expected copies always add up to
     * the snapshot size: found + misplaced + on loan + missing = expected.
     *
     * @return array{attese:int,trovate:int,fuori_posto:int,in_prestito:int,mancanti:int,sconosciute:int,fuori_ambito:int,duplicate:int,scansioni:int,mie:int}
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
        $missing = (int) ($row['n'] ?? 0) - $unscannedOnLoan;

        return [
            'attese' => $this->expectedCount($sessionId),
            'trovate' => $counts['trovata'],
            'fuori_posto' => $counts['fuori_posto'],
            'in_prestito' => $counts['in_prestito'] + $unscannedOnLoan,
            'mancanti' => $missing,
            'sconosciute' => $counts['sconosciuta'],
            'fuori_ambito' => $counts['fuori_ambito'],
            'duplicate' => $counts['duplicata'],
            'scansioni' => array_sum($counts),
            'mie' => $mine,
        ];
    }

    /**
     * Expected copies nobody scanned, with their current loan status.
     *
     * @return list<array<string,mixed>>
     */
    private function unscannedExpected(int $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.copia_id, a.scaffale_atteso, a.stato_all_apertura, c.numero_inventario, c.stato AS stato_attuale, c.libro_id, '
            . 'l.titolo, l.collocazione, '
            . self::onLoanSql() . ' AS in_prestito '
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
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Full report rows: every expected copy with its bucket, plus the scans
     * that are not about an expected copy (unknown, out of scope, duplicate).
     *
     * @return list<array{categoria:string,codice:string,titolo:string,collocazione:string,scaffale_atteso:string,stato_copia:string,operatore:string,quando:string}>
     */
    public function reportRows(int $sessionId): array
    {
        $rows = [];
        foreach ($this->unscannedExpected($sessionId) as $row) {
            $rows[] = [
                'categoria' => (int) $row['in_prestito'] === 1 ? 'in_prestito' : 'mancante',
                'codice' => (string) ($row['numero_inventario'] ?? ('#' . (int) $row['copia_id'])),
                'titolo' => (string) ($row['titolo'] ?? ''),
                'collocazione' => (string) ($row['collocazione'] ?? ''),
                'scaffale_atteso' => $row['scaffale_atteso'] !== null ? $this->shelfLabel((int) $row['scaffale_atteso']) : '',
                'stato_copia' => (string) ($row['stato_attuale'] ?? ''),
                'operatore' => '',
                'quando' => '',
            ];
        }
        foreach ($this->scans($sessionId, null, 100000) as $scan) {
            $rows[] = [
                'categoria' => (string) $scan['esito'],
                'codice' => (string) $scan['codice_letto'],
                'titolo' => (string) ($scan['titolo'] ?? ''),
                'collocazione' => (string) ($scan['collocazione'] ?? ''),
                'scaffale_atteso' => $scan['scaffale_atteso'] !== null ? $this->shelfLabel((int) $scan['scaffale_atteso']) : '',
                'stato_copia' => (string) ($scan['stato_copia'] ?? ''),
                'operatore' => (string) ($scan['operatore'] ?? ''),
                'quando' => (string) ($scan['scansionato_il'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Most recent scans first; with $operatorId only that operator's.
     *
     * @return list<array<string,mixed>>
     */
    public function scans(int $sessionId, ?int $operatorId = null, int $limit = 200): array
    {
        $limit = max(1, min(100000, $limit));
        $sql = 'SELECT s.id, s.codice_letto, s.esito, s.scansionato_il, s.scansionato_da, s.copia_id, '
            . 'COALESCE(c.numero_inventario, s.codice_letto) AS codice, l.titolo, l.collocazione, c.stato AS stato_copia, a.scaffale_atteso, '
            . "TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS operatore "
            . 'FROM inventario_scansioni s '
            . 'LEFT JOIN copie c ON c.id = s.copia_id '
            . 'LEFT JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL '
            . 'LEFT JOIN inventario_attese a ON a.sessione_id = s.sessione_id AND a.copia_id = s.copia_id '
            . 'LEFT JOIN utenti u ON u.id = s.scansionato_da '
            . 'WHERE s.sessione_id = ?'
            . ($operatorId !== null ? ' AND s.scansionato_da = ?' : '')
            . ' ORDER BY s.id DESC LIMIT ' . $limit;
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
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Close the session. With $markLost every missing copy that is not already
     * explained by its state (lent or waiting for pickup, damaged, lost, at the
     * restorer's, in transit, in maintenance) becomes 'perso', one book per transaction, with the same
     * hold reassignment and availability recalculation as a manual change of
     * a copy's state, and an ActivityLog entry per copy.
     *
     * @return array{marked:int,failed:int}
     */
    public function close(int $sessionId, bool $markLost, ?int $operatorId): array
    {
        // Claim the session first: from here on scans are refused, and a
        // concurrent cancel or second close cannot also succeed. Marking copies
        // lost reads inventario_scansioni, not the session state.
        $this->claimTransition($sessionId, 'chiusa', $operatorId);
        $marked = 0;
        $failed = 0;

        if ($markLost) {
            $byBook = [];
            foreach ($this->unscannedExpected($sessionId) as $row) {
                if ((int) $row['in_prestito'] === 1 || $row['libro_id'] === null || $row['titolo'] === null) {
                    continue; // lent or held for pickup, or the copy/book left the catalogue meanwhile
                }
                if (in_array((string) $row['stato_attuale'], self::AWAY_STATES, true)) {
                    continue;
                }
                $byBook[(int) $row['libro_id']][] = $row;
            }
            foreach ($byBook as $bookId => $copies) {
                try {
                    $marked += $this->markBookCopiesLost($bookId, $copies, $sessionId, $operatorId);
                } catch (\Throwable $e) {
                    $failed += count($copies);
                    SecureLogger::error('[Stocktake] marking missing copies as lost failed', [
                        'sessione_id' => $sessionId,
                        'libro_id' => $bookId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $summary = $this->summary($sessionId);

        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.closed', [], [
            'azione_mancanti' => $markLost ? 'perso' : 'nessuna',
            'copie_perse' => $marked,
            'copie_non_aggiornate' => $failed,
        ] + $summary, 'aggiornamento', 'inventory', $operatorId);

        return ['marked' => $marked, 'failed' => $failed];
    }

    /**
     * @param list<array<string,mixed>> $copies
     */
    private function markBookCopiesLost(int $bookId, array $copies, int $sessionId, ?int $operatorId): int
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
                return 0;
            }

            foreach ($copies as $copy) {
                $copyId = (int) $copy['copia_id'];
                // Re-read under the book lock: a return or a loan may have happened since the report.
                $stmt = $this->db->prepare('SELECT stato, numero_inventario, note FROM copie WHERE id = ? AND libro_id = ? FOR UPDATE');
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
                for ($guard = 0; $guard < 1000; $guard++) {
                    $held = $this->db->prepare(
                        "SELECT 1 FROM prestiti WHERE copia_id = ? AND ((attivo = 1 AND stato IN ('prenotato','da_ritirare')) OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione')) LIMIT 1"
                    );
                    if ($held === false) {
                        throw new \RuntimeException($this->db->error);
                    }
                    $held->bind_param('i', $copyId);
                    $held->execute();
                    $stillHeld = $held->get_result()->fetch_row() !== null;
                    $held->close();
                    if (!$stillHeld) {
                        break;
                    }
                    $reassignment->reassignOnCopyLost($copyId);
                }
                $changed[] = ['id' => $copyId] + $current;
            }

            if ($changed !== [] && !(new DataIntegrity($this->db))->recalculateBookAvailability($bookId, insideTransaction: true)) {
                throw new \RuntimeException('Book availability recalculation failed');
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

        foreach ($changed as $copy) {
            ActivityLog::recordBookEvent(
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
        }
        return count($changed);
    }

    public function cancel(int $sessionId, ?int $operatorId): void
    {
        $this->claimTransition($sessionId, 'annullata', $operatorId);
        ActivityLog::recordEntityEvent($this->db, 'inventario_sessioni', $sessionId, 'inventory.cancelled', [], [], 'aggiornamento', 'inventory', $operatorId);
    }
}
