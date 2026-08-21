<?php
declare(strict_types=1);

namespace App\Services;

use mysqli;
use App\Support\NotificationService;
use App\Support\RouteTranslator;
use App\Support\SecureLogger;

/**
 * Servizio per la riassegnazione automatica delle prenotazioni.
 * Gestisce i casi in cui una copia diventa disponibile/non disponibile
 * e deve essere riassegnata a un'altra prenotazione in coda.
 */
class ReservationReassignmentService
{
    private mysqli $db;
    private NotificationService $notificationService;
    private bool $externalTransaction = false;
    private bool $transactionOwned = false;

    /**
     * Notifiche da inviare dopo il commit della transazione esterna.
     * @var array<array{type: string, prestitoId: int, reason?: string}>
     */
    private array $deferredNotifications = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->notificationService = new NotificationService($db);
    }

    /**
     * Indica che le operazioni sono già dentro una transazione esterna.
     * Quando true, il servizio non aprirà/chiuderà transazioni proprie
     * e le notifiche vengono differite (da inviare dopo il commit esterno).
     */
    public function setExternalTransaction(bool $external): self
    {
        $this->externalTransaction = $external;
        return $this;
    }

    /**
     * Invia tutte le notifiche differite.
     * Da chiamare DOPO il commit della transazione esterna.
     */
    public function flushDeferredNotifications(): void
    {
        foreach ($this->deferredNotifications as $notification) {
            try {
                if ($notification['type'] === 'copy_available') {
                    $this->notifyUserCopyAvailable($notification['prestitoId']);
                } elseif ($notification['type'] === 'copy_unavailable') {
                    $this->notifyUserCopyUnavailable($notification['prestitoId'], $notification['reason'] ?? 'unknown');
                }
            } catch (\Throwable $e) {
                SecureLogger::error(__('Errore invio notifica differita'), [
                    'type' => $notification['type'],
                    'prestito_id' => $notification['prestitoId'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        $this->deferredNotifications = [];
    }

    /**
     * Verifica se ci sono notifiche differite in attesa.
     */
    public function hasDeferredNotifications(): bool
    {
        return !empty($this->deferredNotifications);
    }

    /**
     * Verifica se siamo già dentro una transazione.
     * Compatible with both MySQL and MariaDB.
     */
    private function isInTransaction(): bool
    {
        if ($this->externalTransaction) {
            return true;
        }
        return $this->transactionOwned;
    }

    /**
     * Inizia una transazione solo se non siamo già in una.
     */
    private function beginTransactionIfNeeded(): bool
    {
        if ($this->isInTransaction()) {
            return false; // Non abbiamo iniziato noi
        }
        if (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Failed to start transaction');
        }
        $this->transactionOwned = true;
        return true; // Abbiamo iniziato noi
    }

    /**
     * Commit solo se abbiamo iniziato noi la transazione.
     */
    private function commitIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->commit();
            $this->transactionOwned = false;
        }
    }

    /**
     * Rollback solo se abbiamo iniziato noi la transazione.
     */
    private function rollbackIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->rollback();
            $this->transactionOwned = false;
        }
    }

    /**
     * Riassegna prenotazioni (prestiti con stato='prenotato') a una nuova copia disponibile.
     * Da chiamare quando viene aggiunta una copia o una copia torna disponibile.
     */
    public function reassignOnNewCopy(int $libroId, int $newCopiaId): void
    {
        $ownTransaction = $this->beginTransactionIfNeeded();
        try {
            // CI-SOFT-DELETE-EXEMPT: an existing hold must be released/reassigned even if its book is deleted.
            $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            $lockBook->bind_param('i', $libroId);
            $lockBook->execute();
            $lockBook->close();

            // Cheap advisory pre-filter after the canonical book lock: only rows
            // that are currently copyless or pinned to an operationally unavailable
            // copy enter the locking batch. The authoritative state re-check happens
            // below after both prestiti and copie rows have been locked.
            $prefilterStmt = $this->db->prepare("
                SELECT p.id
                FROM prestiti p
                LEFT JOIN copie c ON p.copia_id = c.id
                WHERE p.libro_id = ?
                  AND ( (p.attivo = 1 AND p.stato IN ('prenotato', 'da_ritirare'))
                        OR (p.attivo = 0 AND p.stato = 'pendente' AND p.origine = 'prenotazione') )
                  AND (p.copia_id IS NULL
                       OR c.stato IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento'))
                ORDER BY p.created_at ASC, p.id ASC
            ");
            $prefilterStmt->bind_param('i', $libroId);
            $prefilterStmt->execute();
            $prefilterResult = $prefilterStmt->get_result();
            $candidateIds = [];
            while ($row = $prefilterResult->fetch_assoc()) {
                $candidateIds[] = (int) $row['id'];
            }
            $prefilterStmt->close();
            if ($candidateIds === []) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            // Lock the pre-filtered prestiti as one deterministic FIFO batch and
            // re-assert their lifecycle state. No copy row is locked yet.
            $candidatePlaceholders = implode(',', array_fill(0, count($candidateIds), '?'));
            $candidateLockStmt = $this->db->prepare("
                SELECT id, copia_id, utente_id, data_prestito, data_scadenza
                FROM prestiti
                WHERE libro_id = ? AND id IN ({$candidatePlaceholders})
                  AND ( (attivo = 1 AND stato IN ('prenotato', 'da_ritirare'))
                        OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione') )
                ORDER BY created_at ASC, id ASC
                FOR UPDATE
            ");
            $candidateParams = array_merge([$libroId], $candidateIds);
            $candidateTypes = str_repeat('i', count($candidateParams));
            $candidateLockStmt->bind_param($candidateTypes, ...$candidateParams);
            $candidateLockStmt->execute();
            $candidateResult = $candidateLockStmt->get_result();
            $candidates = $candidateResult ? $candidateResult->fetch_all(MYSQLI_ASSOC) : [];
            $candidateLockStmt->close();
            if ($candidates === []) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            // One policy-owned current read provides the canonical open-state
            // predicate and normalized user/copy identity for every candidate.
            // The result answers same-borrower and overlap checks without N+1.
            $multiplicityPolicy = new \App\Support\LoanMultiplicityPolicy($this->db);
            $targetCommitments = $multiplicityPolicy->lockOpenCopyCommitments(
                $libroId,
                copyId: $newCopiaId
            );

            // Lock the target and every candidate's currently pinned copy in one
            // ascending-ID batch. All prestiti locks above are therefore complete
            // before the first copie lock (libri -> prestiti -> copie).
            $copyIds = [$newCopiaId => true];
            foreach ($candidates as $candidate) {
                if ($candidate['copia_id'] !== null) {
                    $copyIds[(int) $candidate['copia_id']] = true;
                }
            }
            $copyIds = array_keys($copyIds);
            sort($copyIds, SORT_NUMERIC);
            $copyPlaceholders = implode(',', array_fill(0, count($copyIds), '?'));
            $copyLockStmt = $this->db->prepare("
                SELECT id, stato
                FROM copie
                WHERE libro_id = ? AND id IN ({$copyPlaceholders})
                ORDER BY id ASC
                FOR UPDATE
            ");
            $copyParams = array_merge([$libroId], $copyIds);
            $copyTypes = str_repeat('i', count($copyParams));
            $copyLockStmt->bind_param($copyTypes, ...$copyParams);
            $copyLockStmt->execute();
            $copyResult = $copyLockStmt->get_result();
            $copiesById = [];
            while ($copy = $copyResult->fetch_assoc()) {
                $copiesById[(int) $copy['id']] = (string) $copy['stato'];
            }
            $copyLockStmt->close();

            $targetCopyState = $copiesById[$newCopiaId] ?? null;
            if ($targetCopyState === null || !in_array($targetCopyState, ['disponibile', 'prenotato'], true)) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            $nonLendableStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
            $reservation = null;
            foreach ($candidates as $candidate) {
                // Authoritative blocked-state re-check from the locked copy batch.
                // A row whose original copy became usable is no longer a candidate.
                $currentCopyId = $candidate['copia_id'] !== null ? (int) $candidate['copia_id'] : null;
                if ($currentCopyId !== null) {
                    $currentCopyState = $copiesById[$currentCopyId] ?? null;
                    if ($currentCopyState === null || !in_array($currentCopyState, $nonLendableStates, true)) {
                        continue;
                    }
                }

                if (!\App\Support\LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
                    (int) $candidate['id'],
                    (int) $candidate['utente_id'],
                    (string) $candidate['data_prestito'],
                    (string) $candidate['data_scadenza'],
                    $targetCommitments
                )) {
                    $reservation = $candidate;
                    break;
                }
            }
            if ($reservation === null) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            // Aggiorna il prestito/prenotazione con la nuova copia
            $stmt = $this->db->prepare("
                UPDATE prestiti
                SET copia_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param('ii', $newCopiaId, $reservation['id']);
            $stmt->execute();
            $stmt->close();

            // Derive the physical-copy state from every commitment instead of
            // forcing 'prenotato'. This matters when a copy has another,
            // non-overlapping current loan: 'prestato' has priority until that
            // loan is returned, while the future hold remains linked correctly.
            if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($libroId, true)) {
                throw new \RuntimeException("Failed to recalculate availability for libro_id={$libroId}");
            }

            // Se la prenotazione aveva una vecchia copia assegnata, dobbiamo verificare
            // se quella copia ora deve cambiare stato?
            // Generalmente no, perché se era "bloccata" significa che la vecchia copia
            // era occupata (es. 'prestato') o danneggiata. Quindi il suo stato non cambia.
            // Se fosse stata 'disponibile', non avremmo selezionato la prenotazione come "bloccata".

            $this->commitIfOwned($ownTransaction);

            // Notifica l'utente DOPO il commit
            // Se siamo in transazione esterna, differisci la notifica
            if ($this->externalTransaction) {
                $this->deferredNotifications[] = [
                    'type' => 'copy_available',
                    'prestitoId' => (int) $reservation['id']
                ];
            } else {
                $this->notifyUserCopyAvailable((int) $reservation['id']);
            }

        } catch (\Throwable $e) {
            $this->rollbackIfOwned($ownTransaction);
            // In transazione esterna rilanciamo: altrimenti il proprietario farebbe
            // commit() di uno stato parziale (es. copia_id aggiornato ma stato copia
            // non impostato a 'prenotato') (CRITICAL #157).
            if ($this->externalTransaction) {
                throw $e;
            }
            SecureLogger::error(__('Errore riassegnazione copia'), [
                'libro_id' => $libroId,
                'copia_id' => $newCopiaId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gestisce la perdita di una copia (es. segnata come persa/danneggiata).
     * Cerca di riassegnare la prenotazione a un'altra copia se possibile.
     */
    public function reassignOnCopyLost(int $copiaId): void
    {
        // Trova un impegno HOLDING "futuro" su questa copia da riassegnare. Include
        // 'da_ritirare' (ritiro in attesa) oltre a 'prenotato' (BUG7b/D12): perdere
        // la copia di un ritiro in attesa deve riassegnarlo, non lasciarlo bloccato.
        $stmt = $this->db->prepare("
            SELECT id, libro_id, utente_id, data_prestito, data_scadenza
            FROM prestiti
            WHERE copia_id = ?
            AND ( (attivo = 1 AND stato IN ('prenotato', 'da_ritirare'))
                  OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione') )
            ORDER BY data_prestito ASC, id ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $copiaId);
        $stmt->execute();
        $reservation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            return;
        }

        $libroId = (int) $reservation['libro_id'];
        $reservationId = (int) $reservation['id'];
        $resStart = (string) $reservation['data_prestito'];
        $resEnd = (string) $reservation['data_scadenza'];
        $excludedCopies = [$copiaId]; // Copie da escludere dalla ricerca
        // The allocator pre-filters overlaps, so retries are only needed when a
        // concurrent transaction claims a candidate between lookup and lock.
        // A fixed limit of five used to give up even when a sixth physical copy
        // was free for the requested period.
        $maxRetries = 1000;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Cerca un'altra copia disponibile per questo libro
            $nextCopyId = $this->findAvailableCopyExcluding(
                $libroId,
                $excludedCopies,
                $reservationId,
                (int) $reservation['utente_id'],
                $resStart,
                $resEnd
            );

            if (!$nextCopyId) {
                // Nessuna copia disponibile
                $this->handleNoCopyAvailable($reservationId);
                return;
            }

            // Riassegna
            $ownTransaction = $this->beginTransactionIfNeeded();
            try {
                // CI-SOFT-DELETE-EXEMPT: retrying an existing hold must serialize a deleted book's circulation rows.
                $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
                $lockBook->bind_param('i', $libroId);
                $lockBook->execute();
                $lockBook->close();

                $lockReservation = $this->db->prepare("
                    SELECT id, copia_id, utente_id, data_prestito, data_scadenza
                    FROM prestiti
                    WHERE id = ? AND libro_id = ? AND copia_id = ?
                      AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare'))
                            OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione') )
                    FOR UPDATE
                ");
                $lockReservation->bind_param('iii', $reservationId, $libroId, $copiaId);
                $lockReservation->execute();
                $currentReservation = $lockReservation->get_result()->fetch_assoc();
                $lockReservation->close();
                if (!$currentReservation) {
                    $this->rollbackIfOwned($ownTransaction);
                    return;
                }
                $resStart = (string) $currentReservation['data_prestito'];
                $resEnd = (string) $currentReservation['data_scadenza'];

                $committedCopyIds = (new \App\Support\LoanMultiplicityPolicy($this->db))
                    ->committedCopyIds($libroId, (int) $currentReservation['utente_id'], $reservationId);
                if (in_array($nextCopyId, $committedCopyIds, true)) {
                    $this->rollbackIfOwned($ownTransaction);
                    $excludedCopies[] = $nextCopyId;
                    continue;
                }

                // Lock della nuova copia e verifica stato (race condition protection)
                $stmt = $this->db->prepare("SELECT id, stato FROM copie WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $nextCopyId);
                $stmt->execute();
                $copyStatus = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // A copy currently 'prestato' is a valid target for a disjoint
                // future hold. Operationally unavailable states never are.
                if (!$copyStatus || in_array($copyStatus['stato'], ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'], true)) {
                    $this->rollbackIfOwned($ownTransaction);
                    // Aggiungi questa copia alle escluse e riprova
                    $excludedCopies[] = $nextCopyId;
                    continue;
                }

                // Non riassegnare a una copia con un impegno HOLDING sovrapposto al
                // periodo della prenotazione: eviterebbe un SIGNAL del trigger di
                // overlap (BUG7b/D12). Overlap inclusivo.
                $ovl = $this->db->prepare("
                    SELECT 1 FROM prestiti
                    WHERE copia_id = ? AND id <> ?
                    AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                    AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                          OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                    LIMIT 1
                ");
                $ovl->bind_param('iiss', $nextCopyId, $reservationId, $resEnd, $resStart);
                $ovl->execute();
                $hasOverlap = (bool) $ovl->get_result()->fetch_row();
                $ovl->close();
                if ($hasOverlap) {
                    $this->rollbackIfOwned($ownTransaction);
                    $excludedCopies[] = $nextCopyId;
                    continue;
                }

                // Aggiorna prenotazione
                $stmt = $this->db->prepare("UPDATE prestiti SET copia_id = ? WHERE id = ?");
                $stmt->bind_param('ii', $nextCopyId, $reservationId);
                $stmt->execute();
                $stmt->close();

                // Recompute instead of forcing 'prenotato': if the replacement
                // is physically out on a non-overlapping current loan it must
                // remain 'prestato' until return.
                if (!(new \App\Support\DataIntegrity($this->db))->recalculateBookAvailability($libroId, true)) {
                    throw new \RuntimeException("Failed to recalculate availability for libro_id={$libroId}");
                }

                $this->commitIfOwned($ownTransaction);

                // Riassegnazione completata con successo
                return;

            } catch (\Throwable $e) {
                $this->rollbackIfOwned($ownTransaction);
                // In transazione esterna un'eccezione genuina (la race "copia non
                // più disponibile" usa 'continue', non il catch) avvelena la
                // transazione del chiamante: rilanciamo invece di proseguire i
                // tentativi, così il proprietario fa rollback (CRITICAL #157).
                if ($this->externalTransaction) {
                    throw $e;
                }
                SecureLogger::error(__('Errore riassegnazione copia persa'), [
                    'copia_id' => $copiaId,
                    'reservation_id' => $reservationId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                // Aggiungi questa copia alle escluse e riprova
                $excludedCopies[] = $nextCopyId;
            }
        }

        // Esauriti i tentativi
        SecureLogger::warning(__('Esauriti tentativi riassegnazione copia'), [
            'copia_id' => $copiaId,
            'reservation_id' => $reservationId,
            'attempts' => $maxRetries
        ]);
        $this->handleNoCopyAvailable($reservationId);
    }

    /**
     * Gestisce il caso in cui non ci sono copie disponibili per una prenotazione.
     */
    private function handleNoCopyAvailable(int $reservationId): void
    {
        // Meglio impostare copia_id a NULL per indicare "in coda senza copia" o "in attesa"
        // E notificare l'utente che è tornato in lista d'attesa
        $lookup = $this->db->prepare('SELECT libro_id FROM prestiti WHERE id = ?');
        $lookup->bind_param('i', $reservationId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$row) {
            return;
        }
        $libroId = (int) $row['libro_id'];

        $ownTransaction = $this->beginTransactionIfNeeded();
        try {
            // CI-SOFT-DELETE-EXEMPT: releasing an unassignable hold must work for a deleted book.
            $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            $lockBook->bind_param('i', $libroId);
            $lockBook->execute();
            $lockBook->close();

            $stmt = $this->db->prepare("
                UPDATE prestiti SET copia_id = NULL
                WHERE id = ? AND libro_id = ?
                  AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare'))
                        OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione') )
            ");
            $stmt->bind_param('ii', $reservationId, $libroId);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            if ($updated < 1) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            $this->commitIfOwned($ownTransaction);

            // Notifica DOPO il commit
            // Se siamo in transazione esterna, differisci la notifica
            if ($this->externalTransaction) {
                $this->deferredNotifications[] = [
                    'type' => 'copy_unavailable',
                    'prestitoId' => $reservationId,
                    'reason' => 'lost_copy'
                ];
            } else {
                $this->notifyUserCopyUnavailable($reservationId, 'lost_copy');
            }

        } catch (\Throwable $e) {
            $this->rollbackIfOwned($ownTransaction);
            // In transazione esterna rilanciamo per non far committare al chiamante
            // un copia_id azzerato a metà (CRITICAL #157).
            if ($this->externalTransaction) {
                throw $e;
            }
            SecureLogger::error(__('Errore gestione copia non disponibile'), [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Quando un libro viene restituito, controlla se ci sono prenotazioni in attesa
     * e assegna la copia restituita alla prossima prenotazione.
     */
    public function reassignOnReturn(int $copiaId): void
    {
        // 1. Trova il libro
        $stmt = $this->db->prepare("SELECT libro_id FROM copie WHERE id = ?");
        $stmt->bind_param('i', $copiaId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$res)
            return;
        $libroId = (int) $res['libro_id'];

        // 2. Cerca la prenotazione più vecchia SENZA copia assegnata (o assegnata a copia non disp)
        // Nota: reassignOnNewCopy fa esattamente questo logicamente: prende una copia disponibile (questa)
        // e cerca chi ne ha bisogno.
        $this->reassignOnNewCopy($libroId, $copiaId);
    }

    /**
     * Trova una copia disponibile escludendo una lista di copie.
     * @param int $libroId ID del libro
     * @param array<int> $excludeCopiaIds Array di ID copie da escludere
     */
    private function findAvailableCopyExcluding(
        int $libroId,
        array $excludeCopiaIds,
        int $reservationId,
        int $userId,
        string $startDate,
        string $endDate
    ): ?int
    {
        $sql = "
            SELECT c.id
            FROM copie c
            WHERE c.libro_id = ?
            AND c.stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
            AND NOT EXISTS (
                SELECT 1
                FROM prestiti own
                WHERE own.copia_id = c.id
                  AND own.libro_id = ?
                  AND own.utente_id = ?
                  AND own.id <> ?
                  AND (
                      (own.attivo = 0 AND own.stato = 'pendente')
                      OR (own.attivo = 1 AND own.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                  )
            )
            AND NOT EXISTS (
                SELECT 1
                FROM prestiti p
                WHERE p.copia_id = c.id
                  AND p.id <> ?
                  AND p.data_prestito <= ?
                  AND (p.stato = 'in_ritardo' OR p.data_scadenza >= ?)
                  AND (
                      (p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                      OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                  )
            )
        ";
        $params = [$libroId, $libroId, $userId, $reservationId, $reservationId, $endDate, $startDate];
        $types = 'iiiiiss';

        if (!empty($excludeCopiaIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeCopiaIds), '?'));
            $sql .= " AND c.id NOT IN ($placeholders)";
            foreach ($excludeCopiaIds as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        $sql .= " ORDER BY c.id ASC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $res ? (int) $res['id'] : null;
    }

    /**
     * Notifica l'utente che la copia prenotata è disponibile per il ritiro.
     */
    private function notifyUserCopyAvailable(int $prestitoId): void
    {
        // Recupera dati necessari per la notifica
        $stmt = $this->db->prepare("
            SELECT p.id, p.utente_id, p.libro_id, p.data_prestito, p.data_scadenza,
                   u.email, u.nome as utente_nome,
                   l.titolo as libro_titolo, l.isbn13, l.isbn10
            FROM prestiti p
            JOIN utenti u ON p.utente_id = u.id
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $prestitoId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data || empty($data['email'])) {
            SecureLogger::warning(__('Impossibile notificare utente: dati mancanti'), [
                'prestito_id' => $prestitoId
            ]);
            return;
        }

        // Recupera autore principale
        $authorStmt = $this->db->prepare("
            SELECT " . \App\Support\AuthorName::displaySql('a') . " AS nome
            FROM autori a
            JOIN libri_autori la ON a.id = la.autore_id
            WHERE la.libro_id = ? AND la.ruolo IN ('principale', 'co-autore')
            ORDER BY la.ruolo = 'principale' DESC
            LIMIT 1
        ");
        $authorStmt->bind_param('i', $data['libro_id']);
        $authorStmt->execute();
        $author = $authorStmt->get_result()->fetch_assoc();
        $authorStmt->close();

        $isbn = $data['isbn13'] ?: $data['isbn10'] ?: '';

        $bookLink = book_url(['id' => $data['libro_id'], 'titolo' => $data['libro_titolo'] ?? '', 'autore' => $author['nome'] ?? '']);

        $variables = [
            'utente_nome' => $data['utente_nome'] ?: __('Utente'),
            'libro_titolo' => $data['libro_titolo'] ?: __('Libro'),
            'libro_autore' => $author['nome'] ?? __('Autore sconosciuto'),
            'libro_isbn' => $isbn,
            'data_inizio' => $data['data_prestito'] ? date('d/m/Y', strtotime($data['data_prestito'])) : '',
            'data_fine' => $data['data_scadenza'] ? date('d/m/Y', strtotime($data['data_scadenza'])) : '',
            'book_url' => absoluteUrl($bookLink),
            'profile_url' => absoluteUrl(RouteTranslator::route('profile'))
        ];

        $sent = $this->notificationService->sendReservationBookAvailable($data['email'], $variables);

        if ($sent) {
            SecureLogger::info(__('Notifica prenotazione disponibile inviata'), [
                'prestito_id' => $prestitoId,
                'utente_id' => $data['utente_id']
            ]);
        } else {
            SecureLogger::warning(__('Invio notifica prenotazione fallito'), [
                'prestito_id' => $prestitoId,
                'utente_id' => $data['utente_id']
            ]);
        }
    }

    /**
     * Notifica l'utente che la copia prenotata non è più disponibile.
     */
    private function notifyUserCopyUnavailable(int $prestitoId, string $reason): void
    {
        // Recupera dati necessari
        $stmt = $this->db->prepare("
            SELECT p.id, p.utente_id, u.email, u.nome as utente_nome,
                   l.titolo as libro_titolo
            FROM prestiti p
            JOIN utenti u ON p.utente_id = u.id
            JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $prestitoId);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data || empty($data['email'])) {
            SecureLogger::warning(__('Impossibile notificare utente copia non disponibile'), [
                'prestito_id' => $prestitoId,
                'reason' => $reason
            ]);
            return;
        }

        $reasonText = match ($reason) {
            'lost_copy' => __('La copia assegnata è stata segnalata come persa o danneggiata'),
            'expired' => __('La prenotazione è scaduta'),
            default => __('La copia non è più disponibile')
        };

        // Email all'utente la cui copia è diventata indisponibile (GAP-3).
        // Eseguito in modo differito (questo metodo è chiamato da
        // flushDeferredNotifications dopo il commit), quindi nessuna I/O in transazione.
        try {
            // sendCopyUnavailableNotification reports soft failures by returning
            // false (not only by throwing): handle that case too, otherwise a
            // silently undelivered email leaves no operational trace.
            $sent = $this->notificationService->sendCopyUnavailableNotification($data['email'], [
                'utente_nome' => $data['utente_nome'],
                'libro_titolo' => $data['libro_titolo'],
                'motivo' => $reasonText,
            ]);
            if ($sent === false) {
                SecureLogger::warning(__('Email copia non disponibile non inviata'), [
                    'prestito_id' => $prestitoId,
                ]);
            }
        } catch (\Throwable $e) {
            SecureLogger::warning(__('Email copia non disponibile fallita'), [
                'prestito_id' => $prestitoId,
                'error' => $e->getMessage()
            ]);
        }

        // Crea notifica in-app per gli admin
        $this->notificationService->createNotification(
            'general',
            __('Prenotazione: copia non disponibile'),
            \sprintf(
                __('Prenotazione per "%s" (utente: %s) messa in attesa. %s.'),
                $data['libro_titolo'],
                $data['utente_nome'],
                $reasonText
            ),
            '/admin/prestiti',
            $prestitoId
        );

        SecureLogger::info(__('Notifica copia non disponibile creata'), [
            'prestito_id' => $prestitoId,
            'utente_id' => $data['utente_id'],
            'reason' => $reason
        ]);
    }

}
