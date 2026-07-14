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
        // 1. Trova prenotazioni che sono "bloccate" (assegnate a copie non disponibili o senza copia)
        // Ordina per data creazione (FIFO)
        // Solo righe GENUINAMENTE bloccate: senza copia, oppure con una copia in
        // stato NON prestabile. NON 'c.stato != disponibile' — una copia 'prenotato'
        // o 'prestato' per un periodo NON sovrapposto è legittimamente assegnata e
        // non va strappata (BUG6/D11).
        $stmt = $this->db->prepare("
            SELECT p.id, p.copia_id, p.utente_id, p.data_prestito, p.data_scadenza
            FROM prestiti p
            LEFT JOIN copie c ON p.copia_id = c.id
            WHERE p.libro_id = ?
            AND ( (p.attivo = 1 AND p.stato IN ('prenotato', 'da_ritirare'))
                  OR (p.attivo = 0 AND p.stato = 'pendente' AND p.origine = 'prenotazione') )
            AND ( p.copia_id IS NULL
                  OR c.stato IN ('perso','danneggiato','manutenzione','in_restauro','in_trasferimento') )
            ORDER BY p.created_at ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservation = $result->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            return;
        }

        // 2. Se abbiamo trovato una prenotazione da sbloccare, proviamo ad assegnarla alla nuova copia
        $ownTransaction = $this->beginTransactionIfNeeded();
        try {
            $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            $lockBook->bind_param('i', $libroId);
            $lockBook->execute();
            $lockBook->close();

            // The initial lookup was intentionally non-locking to preserve book
            // first ordering. Revalidate the chosen hold now, under the book lock.
            $lockedReservationStmt = $this->db->prepare("
                SELECT id, copia_id, utente_id, data_prestito, data_scadenza
                FROM prestiti
                WHERE id = ? AND libro_id = ?
                  AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare'))
                        OR (attivo = 0 AND stato = 'pendente' AND origine = 'prenotazione') )
                FOR UPDATE
            ");
            $lockedReservationStmt->bind_param('ii', $reservation['id'], $libroId);
            $lockedReservationStmt->execute();
            $lockedReservation = $lockedReservationStmt->get_result()->fetch_assoc();
            $lockedReservationStmt->close();
            if (!$lockedReservation) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }
            $reservation = $lockedReservation;

            // Verifica che la nuova copia sia effettivamente disponibile (lock)
            $stmt = $this->db->prepare("SELECT id, stato FROM copie WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $newCopiaId);
            $stmt->execute();
            $copyStatus = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$copyStatus || !in_array($copyStatus['stato'], ['disponibile', 'prenotato'], true)) {
                $this->rollbackIfOwned($ownTransaction);
                return;
            }

            // Non riassegnare se la copia target ha un impegno HOLDING sovrapposto
            // al periodo della prenotazione: eviterebbe un SIGNAL del trigger di
            // overlap che avvelenerebbe la transazione (BUG6/D11). Overlap inclusivo.
            $ovl = $this->db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ? AND id <> ?
                AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                AND ( (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                      OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                LIMIT 1
            ");
            $ovl->bind_param('iiss', $newCopiaId, $reservation['id'], $reservation['data_scadenza'], $reservation['data_prestito']);
            $ovl->execute();
            $hasOverlap = (bool) $ovl->get_result()->fetch_row();
            $ovl->close();
            if ($hasOverlap) {
                // La nuova copia non è libera per l'intero periodo: lascia la
                // prenotazione bloccata, verrà riassegnata da un'altra copia/ritorno.
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

            // Block the copy for the reserved loan period
            $copyRepo = new \App\Models\CopyRepository($this->db);
            if (!$copyRepo->updateStatus($newCopiaId, 'prenotato')) {
                throw new \RuntimeException("Failed to update copy status for copia_id={$newCopiaId}");
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
        $maxRetries = 5; // Limite tentativi per evitare loop infiniti

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            // Cerca un'altra copia disponibile per questo libro
            $nextCopyId = $this->findAvailableCopyExcluding($libroId, $excludedCopies);

            if (!$nextCopyId) {
                // Nessuna copia disponibile
                $this->handleNoCopyAvailable($reservationId);
                return;
            }

            // Riassegna
            $ownTransaction = $this->beginTransactionIfNeeded();
            try {
                $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
                $lockBook->bind_param('i', $libroId);
                $lockBook->execute();
                $lockBook->close();

                $lockReservation = $this->db->prepare("
                    SELECT id, copia_id, data_prestito, data_scadenza
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

                // Lock della nuova copia e verifica stato (race condition protection)
                $stmt = $this->db->prepare("SELECT id, stato FROM copie WHERE id = ? FOR UPDATE");
                $stmt->bind_param('i', $nextCopyId);
                $stmt->execute();
                $copyStatus = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // Verifica che la copia sia ancora disponibile (potrebbe essere cambiata)
                if (!$copyStatus || !in_array($copyStatus['stato'], ['disponibile', 'prenotato'], true)) {
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

                // Block the copy for the reserved loan period
                $copyRepo = new \App\Models\CopyRepository($this->db);
                if (!$copyRepo->updateStatus($nextCopyId, 'prenotato')) {
                    throw new \RuntimeException("Failed to update copy status for copia_id={$nextCopyId}");
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
    private function findAvailableCopyExcluding(int $libroId, array $excludeCopiaIds): ?int
    {
        $sql = "
            SELECT id
            FROM copie
            WHERE libro_id = ?
            AND stato IN ('disponibile', 'prenotato')
        ";
        $params = [$libroId];
        $types = "i";

        if (!empty($excludeCopiaIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeCopiaIds), '?'));
            $sql .= " AND id NOT IN ($placeholders)";
            foreach ($excludeCopiaIds as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        $sql .= " LIMIT 1";

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
