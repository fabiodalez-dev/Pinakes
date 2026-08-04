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
     * existing codes of this base's family ONCE, generate collision-free
     * "{base}-C{N}" codes in memory, then batch-insert every row with one
     * prepared statement. Replaces the per-copy inventoryCodeExists()+create()
     * pair that turned a large copie_totali import into thousands of queries
     * inside the per-row transaction (holding locks on `copie`).
     *
     * @param string|null $noteTemplate already-translated sprintf template with
     *                                   two %d (index, total); null = no note.
     * @return int number of copies inserted
     */
    public function createManyForBook(int $bookId, string $base, int $howMany, string $stato = 'disponibile', ?string $noteTemplate = null): int
    {
        $howMany = max(0, $howMany);
        if ($howMany === 0) {
            return 0;
        }
        // numero_inventario is VARCHAR(100); leave room for the "-C{N}" suffix.
        $base = mb_substr($base, 0, 90);

        // Pre-load, once, every existing code that could collide with this family.
        $taken = [];
        $likeParam = $base . '%';
        $sel = $this->db->prepare("SELECT numero_inventario FROM copie WHERE numero_inventario LIKE ?");
        if ($sel === false) {
            throw new \RuntimeException('Unable to prepare inventory-code lookup: ' . $this->db->error);
        }
        $sel->bind_param('s', $likeParam);
        if (!$sel->execute()) {
            $error = $sel->error;
            $sel->close();
            throw new \RuntimeException('Unable to load inventory codes: ' . $error);
        }
        $res = $sel->get_result();
        while ($row = $res->fetch_assoc()) {
            $taken[$row['numero_inventario']] = true;
        }
        $sel->close();

        // Generate collision-free codes in memory (walk up, filling gaps).
        $codes = [];
        for ($index = 1; count($codes) < $howMany; $index++) {
            $candidate = "{$base}-C{$index}";
            if (!isset($taken[$candidate])) {
                $taken[$candidate] = true;
                $codes[] = $candidate;
            }
        }

        // Single multi-row INSERT.
        $total = count($codes);
        $placeholders = implode(',', array_fill(0, $total, '(?, ?, ?, ?)'));
        $stmt = $this->db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, note) VALUES {$placeholders}");
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare copy batch insert: ' . $this->db->error);
        }
        $types = '';
        $params = [];
        foreach ($codes as $i => $code) {
            $note = ($noteTemplate !== null && $total > 1) ? sprintf($noteTemplate, $i + 1, $total) : null;
            $types .= 'isss';
            $params[] = $bookId;
            $params[] = $code;
            $params[] = $stato;
            $params[] = $note;
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Unable to insert copy batch: ' . $error);
        }
        $stmt->close();

        return $total;
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
