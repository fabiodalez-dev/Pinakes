<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class CopyRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Sort physical-copy rows by inventory code using human/natural ordering.
     * VARCHAR ordering puts C10 before C2; labels and the admin table must instead
     * follow the sequence staff see on the physical copies (#238).
     *
     * @param array<int, array<string, mixed>> $copies
     * @return array<int, array<string, mixed>>
     */
    public static function sortByInventoryNumber(array $copies): array
    {
        usort($copies, static function (array $left, array $right): int {
            $leftCode = (string) ($left['numero_inventario'] ?? '');
            $rightCode = (string) ($right['numero_inventario'] ?? '');
            $comparison = strnatcasecmp($leftCode, $rightCode);
            if ($comparison !== 0) {
                return $comparison;
            }

            // Make ties deterministic across collations/case variants and repeated
            // calls, even though inventory codes are normally globally unique.
            $comparison = strcmp($leftCode, $rightCode);
            if ($comparison !== 0) {
                return $comparison;
            }
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $copies;
    }

    /**
     * Ottiene tutte le copie di un libro
     */
    public function getByBookId(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM copie c
            WHERE c.libro_id = ?
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            // The physical-copy table must contain exactly one row per copy. Loan
            // schedule rows live separately (getScheduleByBookId); attach only the
            // most relevant commitment for the compact status columns.
            $row += [
                'prestito_id' => null,
                'utente_id' => null,
                'data_prestito' => null,
                'data_scadenza' => null,
                'prestito_stato' => null,
                'utente_nome' => null,
                'utente_cognome' => null,
                'utente_email' => null,
            ];
            $copie[] = $row;
        }

        $stmt->close();
        $copie = self::sortByInventoryNumber($copie);

        $copyIndexes = [];
        foreach ($copie as $index => $copy) {
            $copyIndexes[(int) $copy['id']] = $index;
        }
        foreach ($this->getScheduleByBookId($bookId) as $commitment) {
            $copyId = (int) $commitment['id'];
            if (!isset($copyIndexes[$copyId])) {
                continue;
            }
            $index = $copyIndexes[$copyId];
            if ($copie[$index]['prestito_id'] !== null) {
                continue;
            }
            foreach (['prestito_id', 'utente_id', 'data_prestito', 'data_scadenza', 'prestito_stato', 'utente_nome', 'utente_cognome', 'utente_email'] as $field) {
                $copie[$index][$field] = $commitment[$field];
            }
        }

        return $copie;
    }

    /**
     * All HOLDING commitments for the per-copy calendar. Kept separate from
     * getByBookId so two non-overlapping future loans never duplicate a physical
     * copy in the admin table or in copy-count/removal logic.
     */
    public function getScheduleByBookId(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.numero_inventario,
                   p.id AS prestito_id, p.utente_id, p.data_prestito,
                   p.data_scadenza, p.stato AS prestito_stato,
                   u.nome AS utente_nome, u.cognome AS utente_cognome,
                   u.email AS utente_email
            FROM copie c
            JOIN prestiti p ON p.copia_id = c.id
             AND ( (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                   OR (p.attivo = 0 AND p.stato = 'pendente') )
            LEFT JOIN utenti u ON u.id = p.utente_id
            WHERE c.libro_id = ?
            ORDER BY c.numero_inventario ASC,
                     FIELD(p.stato, 'in_ritardo','in_corso','da_ritirare','prenotato','pendente'),
                     p.data_prestito ASC, p.id ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Ottiene una copia specifica per ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   l.titolo as libro_titolo
            FROM copie c
            JOIN libri l ON c.libro_id = l.id AND l.deleted_at IS NULL
            WHERE c.id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $copia = $result->fetch_assoc();
        $stmt->close();

        return $copia ?: null;
    }

    /**
     * Crea una nuova copia
     */
    public function create(int $bookId, string $numeroInventario, string $stato = 'disponibile', ?string $note = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO copie (libro_id, numero_inventario, stato, note)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('isss', $bookId, $numeroInventario, $stato, $note);
        $stmt->execute();
        $insertId = $this->db->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * Create $howMany copies for a book in a single round-trip: pre-load the
     * existing codes of this base's family, generate collision-free
     * "{base}-C{N}" codes in memory, then batch-insert every row with one
     * prepared statement. A connection-level advisory lock serializes inventory
     * allocation even when a prefix's unique-index range is still empty (where
     * gap locks alone can deadlock). Candidate membership is evaluated by SQL,
     * using numero_inventario's case/accent-insensitive collation rather than a
     * case-sensitive PHP array. The locking current-read then sees rows committed
     * after the transaction snapshot. A bounded duplicate-key retry remains as a
     * final guard for external writers. A failed multi-row INSERT never leaves a
     * partial batch. Replaces the per-copy
     * inventoryCodeExists()+create() pair that turned a large copie_totali
     * import into thousands of queries.
     *
     * @param string|null $noteTemplate already-translated sprintf template with
     *                                   two %d (index, total); null = no note.
     * @return int number of copies inserted
     */
    public function createManyForBook(int $bookId, string $base, int $howMany, string $stato = 'disponibile', ?string $noteTemplate = null): int
    {
        return $this->createManyForBookResult($bookId, $base, $howMany, $stato, $noteTemplate, false)['count'];
    }

    /**
     * Create a batch and return every generated copy id. Circulation callers use
     * this variant so each new physical copy can repair a copy-less HOLDING row
     * before the reservation queue consumes the remaining capacity.
     *
     * @return list<int>
     */
    public function createManyForBookWithIds(int $bookId, string $base, int $howMany, string $stato = 'disponibile', ?string $noteTemplate = null): array
    {
        $result = $this->createManyForBookResult($bookId, $base, $howMany, $stato, $noteTemplate, true);
        return $result['ids'];
    }

    /**
     * Create a batch whose copies all share the same administrator-entered
     * note. This is deliberately separate from the sprintf-based import helper:
     * a literal '%' in an operator note must never be interpreted as a format
     * placeholder.
     *
     * @return list<int>
     */
    public function createManyForBookWithIdsAndNote(
        int $bookId,
        string $base,
        int $howMany,
        string $stato = 'disponibile',
        ?string $note = null
    ): array {
        $result = $this->createManyForBookResult(
            $bookId,
            $base,
            $howMany,
            $stato,
            null,
            true,
            $note
        );
        return $result['ids'];
    }

    /**
     * @return array{count: int, ids: list<int>}
     */
    private function createManyForBookResult(
        int $bookId,
        string $base,
        int $howMany,
        string $stato,
        ?string $noteTemplate,
        bool $resolveIds,
        ?string $sharedNote = null
    ): array
    {
        $howMany = max(0, $howMany);
        if ($howMany === 0) {
            return ['count' => 0, 'ids' => []];
        }
        // numero_inventario is VARCHAR(100); leave room for the "-C{N}" suffix.
        $base = mb_substr($base, 0, 90);

        // GET_LOCK() is server-wide and connection-scoped. It does not start,
        // commit or roll back the caller's transaction and is released after the
        // INSERT and id lookup, never held for hooks or external services.
        $lockName = 'pinakes-copy-inventory-allocation';
        $this->acquireInventoryAllocatorLock($lockName);
        try {
            $inserted = $this->insertAllocatedCopiesWhileLocked(
                $bookId,
                $base,
                $howMany,
                $stato,
                $noteTemplate,
                $sharedNote
            );
            $ids = [];
            if ($resolveIds) {
                $ids = $this->copyIdsForInventoryCodes($inserted['codes']);
                if (count($ids) !== $inserted['count']) {
                    throw new \RuntimeException('Unable to resolve every inserted physical-copy id.');
                }
            }
            return ['count' => $inserted['count'], 'ids' => $ids];
        } finally {
            $this->releaseInventoryAllocatorLock($lockName);
        }
    }

    /**
     * Insert allocated copies while the caller holds the inventory allocator
     * lock. Book creation and both single/batch copy forms use this path, so
     * code allocation, duplicate retries and INSERT behavior cannot drift apart.
     *
     * @return array{count: int, first_id: int, codes: list<string>}
     */
    private function insertAllocatedCopiesWhileLocked(
        int $bookId,
        string $base,
        int $howMany,
        string $stato,
        ?string $noteTemplate,
        ?string $sharedNote
    ): array {
        // Escape LIKE metacharacters in the base before appending the wildcard.
        // A base ending in a backslash would otherwise escape the trailing '%',
        // making the taken-set miss the whole -C{N} family and loop into 1062.
        $likeParam = addcslashes($base, '%_\\') . '%';
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $codes = $this->allocateInventoryCodesWithCurrentRead($base, $likeParam, $howMany);

            // Single multi-row INSERT. MySQL/MariaDB roll back the entire
            // statement if any unique inventory code collides.
            $total = count($codes);
            $placeholders = implode(',', array_fill(0, $total, '(?, ?, ?, ?)'));
            $stmt = $this->db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, note) VALUES {$placeholders}");
            if ($stmt === false) {
                throw new \RuntimeException('Unable to prepare copy batch insert: ' . $this->db->error);
            }
            $types = '';
            $params = [];
            foreach ($codes as $i => $code) {
                $note = $sharedNote !== null
                    ? $sharedNote
                    : ($noteTemplate !== null ? sprintf($noteTemplate, $i + 1, $total) : null);
                $types .= 'isss';
                $params[] = $bookId;
                $params[] = $code;
                $params[] = $stato;
                $params[] = $note;
            }
            $stmt->bind_param($types, ...$params);

            try {
                $inserted = $stmt->execute();
                $errno = $stmt->errno;
                $error = $stmt->error;
            } catch (\mysqli_sql_exception $e) {
                $stmt->close();
                if ($e->getCode() === 1062 && $attempt < $maxAttempts) {
                    continue;
                }
                throw $e;
            }
            $firstInsertId = (int) $this->db->insert_id;
            $stmt->close();

            if ($inserted) {
                return ['count' => $total, 'first_id' => $firstInsertId, 'codes' => $codes];
            }
            if ($errno === 1062 && $attempt < $maxAttempts) {
                continue;
            }
            throw new \RuntimeException('Unable to insert copy batch: ' . $error);
        }

        throw new \RuntimeException('Unable to allocate unique inventory codes after concurrent retries.');
    }

    /**
     * Create one copy with an automatically allocated inventory code and return
     * its id. Allocation and INSERT share the same advisory-lock critical
     * section, unlike allocateInventoryCodes() which is intentionally read-only.
     */
    public function createWithAllocatedInventoryCode(int $bookId, string $base, string $stato = 'disponibile', ?string $note = null): int
    {
        $base = mb_substr($base, 0, 90);
        $lockName = 'pinakes-copy-inventory-allocation';

        $this->acquireInventoryAllocatorLock($lockName);
        try {
            $result = $this->insertAllocatedCopiesWhileLocked(
                $bookId,
                $base,
                1,
                $stato,
                null,
                $note
            );
            if ($result['count'] === 1 && $result['first_id'] > 0) {
                return $result['first_id'];
            }
            throw new \RuntimeException('Allocated copy insert did not affect exactly one row.');
        } finally {
            $this->releaseInventoryAllocatorLock($lockName);
        }
    }

    /**
     * @return list<string>
     */
    private function allocateInventoryCodesWithCurrentRead(string $base, string $likeParam, int $howMany): array
    {
        // A locking current-read sees rows committed after this transaction's
        // snapshot and locks matching unique-index rows/ranges until commit.
        // Only the COUNT feeds the pigeonhole bound below, so COUNT(*) avoids
        // materialising every matching row; FOR UPDATE keeps the locking
        // current-read that gap-locks the base family until commit. The count
        // stays GLOBAL (no libro_id filter) because numero_inventario is globally
        // unique — scoping it to one book would undercount a base shared across
        // books and break the "at least howMany free among existingCount+howMany"
        // guarantee.
        $sel = $this->db->prepare(
            'SELECT COUNT(*) AS n FROM copie WHERE numero_inventario LIKE ? FOR UPDATE'
        );
        if ($sel === false) {
            throw new \RuntimeException('Unable to prepare inventory-code lookup: ' . $this->db->error);
        }
        $sel->bind_param('s', $likeParam);
        if (!$sel->execute()) {
            $error = $sel->error;
            $sel->close();
            throw new \RuntimeException('Unable to load inventory codes: ' . $error);
        }
        $existingCount = (int) ($sel->get_result()->fetch_assoc()['n'] ?? 0);
        $sel->close();

        $codes = [];
        $nextIndex = 1;
        // Among existingCount + howMany candidates there must be at least
        // howMany free values. Check them in bounded chunks so even a 9,999-copy
        // import does not create an enormous UNION or one query per candidate.
        $lastIndex = $existingCount + $howMany;
        while (count($codes) < $howMany && $nextIndex <= $lastIndex) {
            $candidates = [];
            while (count($candidates) < 250 && $nextIndex <= $lastIndex) {
                $candidates[] = "{$base}-C{$nextIndex}";
                $nextIndex++;
            }
            foreach ($this->inventoryCodesMissingUnderDatabaseCollation($candidates) as $candidate) {
                $codes[] = $candidate;
                if (count($codes) === $howMany) {
                    break;
                }
            }
        }
        if (count($codes) !== $howMany) {
            throw new \RuntimeException('Unable to allocate the requested inventory-code batch.');
        }
        return $codes;
    }

    /**
     * Return candidates that do not compare equal to an existing inventory code.
     * The JOIN deliberately delegates equality to the database column collation.
     *
     * @param list<string> $candidates
     * @return list<string>
     */
    private function inventoryCodesMissingUnderDatabaseCollation(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $rows = [];
        foreach (array_keys($candidates) as $ordinal) {
            $rows[] = 'SELECT ? AS inventory_code, ' . (int) $ordinal . ' AS ordinal';
        }
        $sql = 'SELECT candidates.ordinal FROM (' . implode(' UNION ALL ', $rows) . ') candidates '
            // FOR UPDATE is essential here: createBasic() may already have
            // established an older transaction snapshot. A plain JOIN could
            // miss a competing batch committed while GET_LOCK() was awaited
            // and select C1 again; a locking read sees the latest committed row.
            . 'JOIN copie c ON c.numero_inventario = candidates.inventory_code FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare collation-aware inventory lookup: ' . $this->db->error);
        }
        $stmt->bind_param(str_repeat('s', count($candidates)), ...$candidates);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Unable to compare inventory codes: ' . $error);
        }
        $taken = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as $row) {
            $taken[(int) $row[0]] = true;
        }
        $stmt->close();

        $missing = [];
        foreach ($candidates as $ordinal => $candidate) {
            if (!isset($taken[$ordinal])) {
                $missing[] = $candidate;
            }
        }
        return $missing;
    }

    /**
     * @param list<string> $codes
     * @return list<int>
     */
    private function copyIdsForInventoryCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->prepare(
            "SELECT id FROM copie WHERE numero_inventario IN ({$placeholders}) ORDER BY id"
        );
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare inserted-copy lookup: ' . $this->db->error);
        }
        $stmt->bind_param(str_repeat('s', count($codes)), ...$codes);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Unable to resolve inserted-copy ids: ' . $error);
        }
        $ids = array_map(
            static fn (array $row): int => (int) $row[0],
            $stmt->get_result()->fetch_all(MYSQLI_NUM)
        );
        $stmt->close();
        return $ids;
    }

    private function acquireInventoryAllocatorLock(string $lockName): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare inventory allocator lock: ' . $this->db->error);
        }
        $stmt->bind_param('s', $lockName);
        try {
            if (!$stmt->execute()) {
                throw new \RuntimeException('Unable to acquire inventory allocator lock: ' . $stmt->error);
            }
            $acquired = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        } finally {
            $stmt->close();
        }
        if ($acquired !== 1) {
            throw new \RuntimeException('Timed out while waiting to allocate inventory codes.');
        }
    }

    private function releaseInventoryAllocatorLock(string $lockName): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // A broken connection releases its advisory locks server-side. Do
            // not hide the original copy-allocation error from the caller.
        }
    }

    /**
     * True if a numero_inventario already exists anywhere (the uniq_numero_inventario
     * index is global, not per-book), so callers can avoid the duplicate-key crash.
     */
    public function inventoryCodeExists(string $numeroInventario): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM copie WHERE numero_inventario = ? LIMIT 1");
        $stmt->bind_param('s', $numeroInventario);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    /**
     * Allocate $howMany collision-free inventory codes of the form "{base}-C{N}".
     *
     * Fixes the copy-count bug (#238): the old code appended "-C{currentCount+1}",
     * which collided with a still-present higher code after copies were removed from
     * the wrong end (Duplicate entry '…-C2'). Here N walks up from 1, filling any
     * gap left by a removed copy, and every candidate is checked against BOTH the
     * codes generated in this batch and the whole `copie` table (global unique key),
     * so the returned codes can never duplicate an existing one. The "-C{N}" suffix
     * is applied uniformly (never a bare base), keeping the naming consistent.
     *
     * @return list<string>
     */
    public function allocateInventoryCodes(string $base, int $howMany): array
    {
        // numero_inventario is VARCHAR(100); reserve room for the "-C{N}" suffix so
        // a very long base can never overflow the column (worst case "-C9999" = 6).
        $base = mb_substr($base, 0, 90);
        $codes = [];
        $needed = max(0, $howMany);
        for ($index = 1; count($codes) < $needed; $index++) {
            $candidate = "{$base}-C{$index}";
            if (!in_array($candidate, $codes, true) && !$this->inventoryCodeExists($candidate)) {
                $codes[] = $candidate;
            }
        }
        return $codes;
    }

    /**
     * Removable (available, loan-free) copies of a book, ordered so the ones added
     * LAST come first — reducing the copy count must trim from the end of the
     * sequence, not the beginning (#238). Ordering by id DESC tracks creation order
     * robustly without parsing the "-C{N}" suffix.
     *
     * @return list<array{id:int, numero_inventario:string}>
     */
    public function getRemovableCopiesNewestFirst(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.numero_inventario
            FROM copie c
            WHERE c.libro_id = ?
              AND c.stato = 'disponibile'
              AND NOT EXISTS (
                  SELECT 1 FROM prestiti p
                  WHERE p.copia_id = c.id
                    AND ( (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                          OR (p.attivo = 0 AND p.stato = 'pendente') )
              )
            ORDER BY c.id DESC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = ['id' => (int) $row['id'], 'numero_inventario' => (string) $row['numero_inventario']];
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Aggiorna lo stato di una copia
     */
    public function updateStatus(int $id, string $stato): bool
    {
        $stmt = $this->db->prepare("UPDATE copie SET stato = ? WHERE id = ?");
        $stmt->bind_param('si', $stato, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Elimina una copia
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM copie WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Delete a copy ONLY if it is still removable (available, no active/pending
     * loan). Closes the race between selecting removable copies and deleting them
     * (#252): if a loan claimed the copy in between, the conditional DELETE removes
     * nothing and returns false, so the caller can try the next candidate instead
     * of miscounting. The prestiti.copia_id FK is ON DELETE RESTRICT, so this can
     * never orphan a loan even under the race — this just makes the outcome clean.
     *
     * @return bool True only if a row was actually deleted.
     */
    public function deleteIfRemovable(int $id): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM copie
            WHERE id = ?
              AND stato = 'disponibile'
              AND NOT EXISTS (
                  SELECT 1 FROM prestiti p
                  WHERE p.copia_id = copie.id
                    AND ( (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                          OR (p.attivo = 0 AND p.stato = 'pendente') )
              )
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows === 1;
        $stmt->close();
        return $deleted;
    }

    /**
     * Ottiene le copie disponibili di un libro (non assegnate a prestiti attivi o futuri)
     */
    public function getAvailableByBookId(int $bookId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM copie c
            LEFT JOIN prestiti p ON c.id = p.copia_id AND ((p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')) OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL))
            WHERE c.libro_id = ?
            AND c.stato = 'disponibile'
            AND p.id IS NULL
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            $copie[] = $row;
        }

        $stmt->close();
        return $copie;
    }

    /**
     * Ottiene le copie disponibili per un libro in un periodo specifico
     * Esclude copie con prestiti sovrapposti al periodo richiesto
     * @param int $bookId ID del libro
     * @param string $startDate Data inizio periodo (Y-m-d)
     * @param string $endDate Data fine periodo (Y-m-d)
     * @return array Array di copie disponibili per il periodo
     */
    public function getAvailableByBookIdForDateRange(int $bookId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*
            FROM copie c
            WHERE c.libro_id = ?
            AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
            AND NOT EXISTS (
                SELECT 1 FROM prestiti p
                WHERE p.copia_id = c.id
                AND p.data_prestito <= ?
                AND (p.stato = 'in_ritardo' OR p.data_scadenza >= ?)
                AND ((p.attivo = 1 AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                     OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL))
            )
            ORDER BY c.numero_inventario ASC
        ");
        $stmt->bind_param('iss', $bookId, $endDate, $startDate);
        $stmt->execute();
        $result = $stmt->get_result();

        $copie = [];
        while ($row = $result->fetch_assoc()) {
            $copie[] = $row;
        }

        $stmt->close();
        return $copie;
    }

    /**
     * Conta il numero di copie disponibili per un libro (non assegnate a prestiti attivi o futuri)
     */
    public function countAvailableByBookId(int $bookId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM copie c
            LEFT JOIN prestiti p ON c.id = p.copia_id AND ((p.attivo = 1 AND p.stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')) OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL))
            WHERE c.libro_id = ?
            AND c.stato = 'disponibile'
            AND p.id IS NULL
        ");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Conta il numero totale di copie per un libro
     */
    public function countByBookId(int $bookId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM copie WHERE libro_id = ?");
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Conta le copie IN CIRCOLAZIONE per un libro, cioè escludendo gli stati
     * fuori circolazione (perso, danneggiato, manutenzione, in_restauro,
     * in_trasferimento). Deve restare allineato a DataIntegrity::recalculate
     * BookAvailability, che calcola `libri.copie_totali` con la stessa
     * esclusione: il form di modifica pre-compila il campo "copie totali" da
     * quella colonna filtrata, quindi il conteggio di riferimento per decidere
     * quante copie aggiungere/rimuovere deve usare LO STESSO insieme di stati.
     * Usare invece countByBookId() (conteggio grezzo di TUTTE le copie) fa sì
     * che un salvataggio di routine, su un libro con anche una sola copia fuori
     * circolazione, veda current > form e cancelli una copia buona.
     */
    public function countInCirculationByBookId(int $bookId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM copie
             WHERE libro_id = ?
             AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')"
        );
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Genera numero inventario univoco per una nuova copia
     */
    public function generateInventoryNumber(int $bookId, string $baseNumber): string
    {
        // Conta quante copie esistono già per questo libro
        $count = $this->countByBookId($bookId);

        if ($count === 0) {
            return $baseNumber;
        }

        // Genera numero con suffisso incrementale
        return "{$baseNumber}-C" . ($count + 1);
    }

    /**
     * Aggiorna note di una copia
     */
    public function updateNotes(int $id, ?string $note): bool
    {
        $stmt = $this->db->prepare("UPDATE copie SET note = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}
