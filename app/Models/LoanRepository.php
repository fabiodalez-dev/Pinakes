<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;
use App\Controllers\ReservationManager;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\SecureLogger;

class LoanRepository
{
    public function __construct(private mysqli $db) {}

    public function listRecent(int $limit = 100): array
    {
        $rows = [];
        $sql = "SELECT p.id, l.titolo AS libro_titolo, CONCAT(u.nome,' ',u.cognome) AS utente_nome,
                       u.email as utente_email, p.data_prestito, p.data_scadenza, p.data_restituzione, p.stato, p.attivo
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                LEFT JOIN utenti u ON p.utente_id = u.id
                ORDER BY p.id DESC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getById(int $id): ?array
    {
        $sql = "SELECT p.*, l.titolo AS libro, l.sottotitolo AS libro_sottotitolo, l.isbn13, l.isbn10,
                       c.numero_inventario AS copia_inventario,
                       CONCAT(u.nome,' ',u.cognome) AS utente,
                       u.email AS utente_email,
                       u.codice_tessera AS utente_tessera,
                       u.telefono AS utente_telefono,
                       u.indirizzo AS utente_indirizzo,
                       (SELECT GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " ORDER BY a.nome SEPARATOR ', ')
                        FROM libri_autori la
                        JOIN autori a ON la.autore_id = a.id
                        WHERE la.libro_id = p.libro_id AND la.ruolo IN ('principale','co-autore')) AS autori
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                LEFT JOIN copie c ON p.copia_id = c.id
                LEFT JOIN utenti u ON p.utente_id = u.id
                WHERE p.id=? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res->fetch_assoc() ?: null;
    }

    /**
     * @deprecated Inserimento prestito senza copia_id/stato/data_scadenza né
     * controllo di overlap/disponibilità: bypassa tutta la logica di prestito e
     * può creare doppi prestiti sulla stessa copia (CONC-04). Usa invece
     * PrestitiController::store() (creazione admin) o
     * ReservationManager::createLoanFromReservation() (da prenotazione), che
     * applicano lock, overlap check e selezione copia.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        throw new \LogicException(
            'LoanRepository::create() è disabilitato (CONC-04): bypassa overlap e '
            . 'selezione copia. Usa PrestitiController::store() o '
            . 'ReservationManager::createLoanFromReservation().'
        );
    }

    /**
     * Edit ONLY the user-editable fields of a loan. It deliberately never writes
     * the lifecycle columns (attivo / stato / data_restituzione / restituito_in_ritardo)
     * nor libro_id / copia_id — editing a loan must not resurrect a returned one
     * (BUG1/I1) nor decouple the copy from its book (BUG2/I7). Lifecycle changes
     * go through the dedicated return/approve/cancel paths, never through here.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE prestiti SET utente_id=?, data_prestito=?, data_scadenza=?, processed_by=? WHERE id=?";
        $stmt = $this->db->prepare($sql);
        $utente_id = (int) ($data['utente_id'] ?? 0);
        // "Oggi" nel timezone applicativo (M9): mai date() (TZ processo, spesso UTC).
        $data_prestito = $data['data_prestito'] ?? DateHelper::today();
        $data_scadenza = $data['data_scadenza'] ?? date('Y-m-d', strtotime(DateHelper::today() . ' +14 days'));
        $processed_by = $data['processed_by'] ?? null;
        $stmt->bind_param('issii', $utente_id, $data_prestito, $data_scadenza, $processed_by, $id);
        return $stmt->execute();
    }

    public function getActiveLoanByBook(int $bookId): ?array
    {
        $sql = "SELECT p.*, 
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                       u.email AS utente_email,
                       u.id AS utente_id,
                       CONCAT(staff.nome, ' ', staff.cognome) AS processed_by_name
                FROM prestiti p
                LEFT JOIN utenti u ON p.utente_id = u.id
                LEFT JOIN utenti staff ON p.processed_by = staff.id
                WHERE p.libro_id = ?
                  AND p.attivo = 1
                  AND p.stato IN ('in_corso','in_ritardo')
                ORDER BY p.data_prestito DESC
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $loan = $res->fetch_assoc();
        $stmt->close();

        return $loan ?: null;
    }

    /**
     * Chiude un prestito APERTO registrandolo come 'restituito'.
     *
     * Guardia di stato (H1): opera SOLO su prestiti con attivo=1 e stato
     * in_corso/in_ritardo. Richiudere un prestito già 'restituito'
     * sovrascriverebbe data_restituzione a oggi e ricalcolerebbe
     * restituito_in_ritardo contro oggi (falso ritardo che inquina storico e
     * statistiche); chiudere un prenotato/da_ritirare/pendente lo
     * registrerebbe come consegnato — quei casi passano dai percorsi dedicati
     * (annullamento/scadenza ritiro), che gestiscono anche la riassegnazione.
     *
     * @return bool false se il prestito non esiste o non è chiudibile.
     */
    public function close(int $id): bool
    {
        $this->db->begin_transaction();

        $bookId = null;

        try {
            // ORDINE DI LOCK CANONICO (P3): determina il libro del prestito con una
            // lettura NON bloccante, poi blocca la riga `libri` PRIMA e infine quella del
            // prestito — stesso ordine di store/approveLoan/renew per evitare deadlock.
            $lookup = $this->db->prepare('SELECT libro_id FROM prestiti WHERE id=?');
            $lookup->bind_param('i', $id);
            $lookup->execute();
            $lrow = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if (!$lrow) {
                $this->db->rollback();
                return false;
            }
            $bookId = (int) $lrow['libro_id'];

            // Lock della riga `libri` per serializzare il ricalcolo della
            // disponibilità. NB: NIENTE filtro deleted_at qui (e nessun bail) —
            // la RESTITUZIONE di un prestito deve sempre poter procedere anche se
            // il libro è stato soft-deleted nel frattempo: la regola soft-delete
            // governa prestabilità/visibilità, non i rientri. Bloccare il close
            // lascerebbe il prestito attivo e la copia occupata per sempre.
            $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id=? FOR UPDATE');
            $lockBook->bind_param('i', $bookId);
            $lockBook->execute();
            $lockBook->close();

            // Poi lock della riga del prestito. Rileggiamo libro_id DALLA RIGA
            // ORA BLOCCATA e lo confrontiamo con quello su cui abbiamo preso il
            // lock di `libri`: la lettura iniziale (126-135) è non bloccante per
            // preservare l'ordine di lock canonico (libri prima di prestiti), ma
            // se libro_id fosse cambiato nel frattempo (TOCTOU) avremmo bloccato
            // e ricalcolato il libro sbagliato. In quel caso (rarissimo: il
            // libro di un prestito non viene riassegnato) abortiamo invece di
            // operare su dati incoerenti.
            $stmt = $this->db->prepare('SELECT libro_id, copia_id, data_scadenza, attivo, stato FROM prestiti WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $lockedRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$lockedRow) {
                $this->db->rollback();
                return false;
            }
            if ((int) $lockedRow['libro_id'] !== $bookId) {
                $this->db->rollback();
                return false;
            }

            // Guardia di stato (H1): solo un prestito ancora aperto è chiudibile
            // (vedi docblock). Verificata QUI, sotto lock, così una restituzione
            // concorrente già committata non viene rielaborata.
            if ((int) $lockedRow['attivo'] !== 1 || !in_array($lockedRow['stato'], ['in_corso', 'in_ritardo'], true)) {
                $this->db->rollback();
                return false;
            }
            $closedCopiaId = $lockedRow['copia_id'] !== null ? (int) $lockedRow['copia_id'] : null;

            // Chiude il prestito (restituito + flag ritardo se oltre scadenza, I4/BUG5).
            // "Oggi" nel timezone applicativo (M9), non UTC: a cavallo della
            // mezzanotte gmdate() registrerebbe il giorno sbagliato.
            $today = DateHelper::today();
            $scadenza = (string) ($lockedRow['data_scadenza'] ?? '');
            $ritardo = ($scadenza !== '' && $scadenza < $today) ? 1 : 0;
            $stmt = $this->db->prepare('UPDATE prestiti SET attivo=0, data_restituzione=?, stato="restituito", restituito_in_ritardo=? WHERE id=? AND attivo=1');
            $stmt->bind_param('sii', $today, $ritardo, $id);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Impossibile aggiornare lo stato del prestito.');
            }
            $closeAffected = $stmt->affected_rows;
            $stmt->close();
            if ($closeAffected < 1) {
                // La guardia sotto lock rende il caso teorico, ma se l'UPDATE non
                // tocca righe il prestito non era più chiudibile: nessun effetto.
                $this->db->rollback();
                return false;
            }

            // Determina se il libro ha altri prestiti attivi (include 'prenotato' for scheduled future loans)
            $activeCount = 0;
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM prestiti WHERE libro_id=? AND attivo=1 AND stato IN ('in_corso','in_ritardo','da_ritirare','prenotato')");
            $countStmt->bind_param('i', $bookId);
            $countStmt->execute();
            $activeCount = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();

            $newStatus = $activeCount > 0 ? 'prestato' : 'disponibile';
            $updateBookStmt = $this->db->prepare('UPDATE libri SET stato = ? WHERE id = ?');
            $updateBookStmt->bind_param('si', $newStatus, $bookId);
            $updateBookStmt->execute();
            $updateBookStmt->close();

            // Recalculate availability and process reservations INSIDE the transaction
            // This ensures FOR UPDATE locks in processBookAvailability are effective
            $integrity = new DataIntegrity($this->db);
            if (!$integrity->recalculateBookAvailability($bookId, true)) {
                throw new \RuntimeException(__('Impossibile ricalcolare la disponibilità del libro.'));
            }

            // Layer 1: reassign any copy-less 'prenotato' hold to the freed copy
            // (the inverse-gap fix — close() previously promoted only the waitlist).
            $reassignmentService = null;
            if ($closedCopiaId !== null) {
                $reassignmentService = new \App\Services\ReservationReassignmentService($this->db);
                $reassignmentService->setExternalTransaction(true);
                $reassignmentService->reassignOnReturn($closedCopiaId);
            }

            // Layer 2: promote queued waitlist reservations. Loop until none convert
            // (a multi-copy free can promote several). Both queues (D5/BUG10).
            $reservationManager = new ReservationManager($this->db);
            $reservationManager->setExternalTransaction(true); // TXN-003: siamo già in transazione
            for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($bookId); $promoGuard++) {
                // keep promoting while freed capacity converts the next queued reservation
            }

            // Final recalc so libri.copie_disponibili reflects the copy states AFTER
            // Layer-1 reassignment (which can re-bind the freed copy to a 'prenotato'
            // hold without a further recalc when Layer 2 promotes nothing). Without
            // this, the post-commit wishlist gate in PrestitiController::close reads a
            // stale counter and can fire a false "book available" notification.
            // No existence guard here: the book was already locked FOR UPDATE and
            // recalculated successfully above in this same transaction, so it cannot
            // have vanished (recalculateBookAvailability only returns false when the
            // book row is missing).
            $integrity->recalculateBookAvailability($bookId, true);

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // P2: invia le notifiche accodate durante la transazione esterna (solo sul
        // percorso di successo, post-commit). Ordine: prima il reassignment (Layer 1,
        // copia assegnata a una prenotazione copy-less), poi la promozione waitlist
        // (Layer 2) — stesso ordine temporale in cui sono avvenuti.
        if ($reassignmentService !== null) {
            try {
                $reassignmentService->flushDeferredNotifications();
            } catch (\Throwable $e) {
                SecureLogger::warning('LoanRepository::close reassign flush warning', ['error' => $e->getMessage()]);
            }
        }
        try {
            $reservationManager->flushDeferredNotifications();
        } catch (\Throwable $e) {
            SecureLogger::warning('LoanRepository::close flush notifications warning', ['error' => $e->getMessage()]);
        }

        // validateAndUpdateLoan has its own transaction, call it after main transaction completes
        try {
            $integrity = new DataIntegrity($this->db);
            $integrity->validateAndUpdateLoan($id);
        } catch (\Throwable $e) {
            SecureLogger::warning('DataIntegrity warning (validateAndUpdateLoan)', ['error' => $e->getMessage()]);
        }

        return true;
    }
}
