<?php

namespace App\Controllers;

use mysqli;
use App\Support\RouteTranslator;

/**
 * Manages book reservations and their conversion to loans
 *
 * Handles the reservation queue system, availability checking,
 * and automatic conversion of reservations to loans when books
 * become available and the requested date arrives.
 *
 * @package App\Controllers
 */
class ReservationManager
{
    /** @var mysqli Database connection */
    private $db;

    /**
     * Create a new ReservationManager instance
     *
     * @param mysqli $db Database connection
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /** @var bool Internal flag tracking whether we own the current transaction */
    private bool $inTransaction = false;

    /** @var bool Set by the caller when a transaction is already open externally */
    private bool $externalTransaction = false;

    /** @var array<int,array> Notifiche prenotazione da inviare dopo il commit esterno */
    private array $deferredReservationNotifications = [];

    /** @var array<int,array{email: string, variables: array}> Notifiche di scadenza coda (M11) da inviare dopo il commit esterno */
    private array $deferredExpiryNotifications = [];

    /**
     * Dichiara che il chiamante ha già aperto una transazione: in tal caso questo
     * manager NON aprirà una transazione propria (evita il commit implicito di una
     * begin_transaction() annidata). Da chiamare PRIMA di processBookAvailability().
     */
    public function setExternalTransaction(bool $external): void
    {
        $this->externalTransaction = $external;
    }

    /**
     * Invia le notifiche di prenotazione accodate durante una transazione esterna.
     * Da chiamare dal proprietario della transazione DOPO il proprio commit (P2).
     */
    public function flushDeferredNotifications(): void
    {
        $pending = $this->deferredReservationNotifications;
        $this->deferredReservationNotifications = [];
        foreach ($pending as $reservation) {
            try {
                $this->sendReservationNotification($reservation);
            } catch (\Throwable $e) {
                \App\Support\SecureLogger::error('Invio notifica prenotazione differita fallito', [
                    'prenotazione_id' => $reservation['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Stesso pattern per le notifiche di scadenza coda accodate da
        // cancelExpiredReservations() in transazione esterna (M11).
        $pendingExpiry = $this->deferredExpiryNotifications;
        $this->deferredExpiryNotifications = [];
        if ($pendingExpiry !== []) {
            $notificationService = new \App\Support\NotificationService($this->db);
            foreach ($pendingExpiry as $expired) {
                try {
                    if (!$notificationService->sendQueueReservationExpiredNotification($expired['email'], $expired['variables'])) {
                        // Ritorno false = invio fallito senza eccezione: logga
                        // comunque, altrimenti la notifica si perde in silenzio.
                        \App\Support\SecureLogger::warning('Invio notifica scadenza prenotazione differita fallito (send=false)', [
                            'email_hash' => hash('sha256', (string) $expired['email']),
                        ]);
                    }
                } catch (\Throwable $e) {
                    \App\Support\SecureLogger::error('Invio notifica scadenza prenotazione differita fallito', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Begin transaction only if not already in one.
     *
     * Usa un flag esplicito invece di @@autocommit: begin_transaction()/START
     * TRANSACTION NON modifica @@autocommit in mysqli/MySQL, quindi quel
     * rilevamento era inaffidabile e poteva aprire una transazione annidata
     * dentro quella del chiamante (commit implicito) — TXN-003.
     *
     * @return bool True if we started a new transaction, false if already in one
     */
    private function beginTransactionIfNeeded(): bool
    {
        if ($this->inTransaction || $this->externalTransaction) {
            return false; // Already in transaction, don't start a new one
        }
        if (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Failed to start transaction');
        }
        $this->inTransaction = true;
        return true;
    }

    /**
     * Commit transaction only if we started it
     *
     * @param bool $ownTransaction Whether we started the transaction
     */
    private function commitIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->commit();
            $this->inTransaction = false;
        }
    }

    /**
     * Rollback transaction only if we started it
     *
     * @param bool $ownTransaction Whether we started the transaction
     */
    private function rollbackIfOwned(bool $ownTransaction): void
    {
        if ($ownTransaction) {
            $this->db->rollback();
            $this->inTransaction = false;
        }
    }

    /**
     * Process reservations when a book becomes available
     *
     * Finds the next date-eligible reservation (where start date <= today)
     * and attempts to convert it to a pending loan. Only processes one
     * reservation at a time to maintain queue order.
     *
     * Uses row-level locking (SELECT FOR UPDATE) to prevent race conditions
     * where multiple processes try to convert the same reservation.
     *
     * @param int $bookId The book ID to process reservations for
     * @return bool True if a reservation was successfully converted to a loan
     */
    public function processBookAvailability($bookId)
    {
        // App-timezone "today" (not PHP UTC) so MySQL-stored dates and the
        // eligibility comparison agree across the midnight boundary (#157).
        $today = \App\Support\DateHelper::today();
        $ownTransaction = $this->beginTransactionIfNeeded();

        try {
            // Lock the book row first to serialize all reservation processing for this book
            $lockBookStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
            $lockBookStmt->bind_param('i', $bookId);
            $lockBookStmt->execute();
            $lockBookResult = $lockBookStmt->get_result();
            $bookRow = $lockBookResult ? $lockBookResult->fetch_assoc() : null;
            $lockBookStmt->close();

            // Soft-deleted or non-existent book: stop before converting any
            // reservation into a loan for a title removed from the catalogue.
            // Without this guard the FOR UPDATE returns zero rows but the flow
            // proceeded anyway (libri queries MUST honour deleted_at IS NULL).
            if ($bookRow === null) {
                $this->commitIfOwned($ownTransaction);
                return false;
            }

            // Get the next date-eligible reservation in queue
            // Only process reservations where start date <= today (ready to convert to loan)
            // Note: Book-level lock above serializes all processing for this book,
            // so we don't need row-level lock on prenotazioni here
            $stmt = $this->db->prepare("
                SELECT r.*, u.email, u.nome, u.cognome
                FROM prenotazioni r
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.libro_id = ? AND r.stato = 'attiva'
                AND r.data_inizio_richiesta <= ?
                ORDER BY r.queue_position ASC
                LIMIT 1
            ");
            $stmt->bind_param('is', $bookId, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $nextReservation = $result->fetch_assoc();
            $stmt->close();

            if ($nextReservation) {
                // Check if the desired date range is available. Resolve the canonical
                // R_END once: a legacy prenotazione may have data_fine_richiesta NULL but
                // data_scadenza_prenotazione set — passing the raw NULL would make
                // isDateRangeAvailable() return false and the row would never promote.
                $startDate = $nextReservation['data_inizio_richiesta'];
                $endDate = $nextReservation['data_fine_richiesta']
                    ?: (!empty($nextReservation['data_scadenza_prenotazione'])
                        ? substr((string) $nextReservation['data_scadenza_prenotazione'], 0, 10)
                        : $startDate);
                // Feed the resolved end to createLoanFromReservation() too (it reads
                // $reservation['data_fine_richiesta'] for the loan period).
                $nextReservation['data_fine_richiesta'] = $endDate;

                // #157: pass the promoted reservation's queue_position so the
                // capacity gate ignores waitlist entries BEHIND it (they would
                // otherwise block the head when the waitlist fully subscribes the
                // copies — e.g. 1 copy + 2 queued reservations promoted 0).
                // isset() is false for both an absent key and a NULL value, so it
                // covers a legacy NULL queue_position without a redundant null check.
                $headQueuePos = isset($nextReservation['queue_position'])
                    ? (int) $nextReservation['queue_position']
                    : null;
                if ($this->isDateRangeAvailable($bookId, $startDate, $endDate, (int) $nextReservation['id'], $headQueuePos)) {
                    // Create the loan - check return value to handle race conditions
                    // Note: createLoanFromReservation() handles its own transaction internally
                    // when called standalone, but here we're already in a transaction
                    $loanCreated = $this->createLoanFromReservation($nextReservation);

                    if ($loanCreated === false) {
                        // Race condition detected - loan creation failed
                        $this->rollbackIfOwned($ownTransaction);
                        return false;
                    }

                    // Mark reservation as completed
                    $stmt = $this->db->prepare("UPDATE prenotazioni SET stato = 'completata' WHERE id = ?");
                    $stmt->bind_param('i', $nextReservation['id']);
                    $stmt->execute();
                    $stmt->close();

                    // BUG9/D4 double-subtraction fix: createLoanFromReservation() already
                    // recalc'd availability, but the source reservation was still 'attiva'
                    // then — so the new pendente+copy loan AND the waitlist slot were both
                    // counted. Recalc again now that the reservation is 'completata', so the
                    // commitment is counted exactly once.
                    $integrity = new \App\Support\DataIntegrity($this->db);
                    $integrity->recalculateBookAvailability($bookId, true);

                    // Update queue positions for remaining reservations.
                    // Pass the completed reservation's position: the converted
                    // reservation is the lowest *date-eligible* one, which is
                    // not necessarily queue_position = 1 (earlier positions may
                    // have a future start date). Decrementing everything > 1
                    // would collide positions when a non-head reservation is
                    // promoted. Legacy queue_position NULL: (int)NULL = 0 farebbe
                    // decrementare TUTTE le posizioni attive — ricompattiamo
                    // invece l'intera coda (L7).
                    if ($headQueuePos !== null) {
                        $this->updateQueuePositions($bookId, $headQueuePos);
                    } else {
                        $this->reorderQueuePositions($bookId);
                    }

                    $this->commitIfOwned($ownTransaction);

                    // Se possediamo la transazione (già committata sopra) inviamo subito.
                    // Con una transazione ESTERNA non possiamo inviare ora (il commit del
                    // chiamante non è ancora avvenuto): accodiamo e il chiamante invierà
                    // dopo il proprio commit via flushDeferredNotifications(). Senza questo,
                    // con setExternalTransaction(true) la notifica veniva persa (P2).
                    if ($ownTransaction) {
                        $this->sendReservationNotification($nextReservation);
                    } else {
                        $this->deferredReservationNotifications[] = $nextReservation;
                    }

                    return true;
                }
            }

            $this->commitIfOwned($ownTransaction);
            return false;

        } catch (\Throwable $e) {
            $this->rollbackIfOwned($ownTransaction);
            // Con una transazione del CHIAMANTE (external) non possiamo assorbire
            // l'errore ritornando false: il chiamante farebbe comunque commit() di
            // uno stato parziale (es. prestito creato ma prenotazione ancora
            // 'attiva'). Rilanciamo così è il proprietario a fare rollback. Quando
            // possediamo noi la transazione l'abbiamo già annullata sopra: logghiamo
            // e ritorniamo false come prima (CRITICAL #157).
            if ($this->externalTransaction) {
                throw $e;
            }
            \App\Support\SecureLogger::error('processBookAvailability error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if a date range has available copies (multi-copy aware)
     *
     * Counts loanable copies and overlapping loans to determine
     * if at least one copy is available for the requested period.
     * Note: Does not count reservations, as they use a queue system.
     *
     * @param int $bookId The book ID
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return bool True if at least one copy is available
     */
    private function isDateRangeAvailable($bookId, $startDate, $endDate, ?int $excludeReservationId = null, ?int $excludeReservationsAfterQueuePos = null)
    {
        if (!$startDate || !$endDate) {
            return false;
        }

        // Canonical capacity gate (CapacityService): OCC counts HOLDING loans AND
        // active reservations — the waitlist occupies one capacity unit for its
        // promised period. This is the promotion gate, so it excludes the
        // reservation being promoted (it would otherwise block its own conversion).
        // Same predicate as the admin creation gate and the overbooked auditor —
        // one source of truth, no drift.
        $capacity = new \App\Services\CapacityService($this->db);
        return $capacity->hasFreeCapacity(
            (int) $bookId,
            (string) $startDate,
            (string) $endDate,
            excludeReservationId: $excludeReservationId,
            excludeReservationsAfterQueuePos: $excludeReservationsAfterQueuePos
        );
    }

    /**
     * Create a pending loan from an approved reservation
     *
     * Finds an available copy, locks it to prevent race conditions,
     * and creates a loan with state 'pendente' and origin 'prenotazione'.
     * The loan requires admin confirmation of physical book pickup.
     *
     * @param array{libro_id: int, utente_id: int, data_inizio_richiesta: string, data_fine_richiesta: string} $reservation Reservation data
     * @return int|false Loan ID on success, false on failure (no copies available or race condition)
     */
    private function createLoanFromReservation($reservation)
    {
        $bookId = (int) $reservation['libro_id'];
        $startDate = $reservation['data_inizio_richiesta'];
        $endDate = $reservation['data_fine_richiesta'];

        // Always create as 'pendente' (attivo=0) - requires admin confirmation of physical pickup
        // Origin is 'prenotazione' to distinguish from manual requests
        $newState = 'pendente';
        $origine = 'prenotazione';

        // Start transaction only if not already in one (e.g., called from MaintenanceService)
        // This prevents nested transaction issues with MySQLi
        $ownTransaction = $this->beginTransactionIfNeeded();

        try {
            // Find an available copy for this date range (no overlapping loans)
            // Consider 'disponibile' and 'prenotato' copies (exclude perso/danneggiato/manutenzione)
            // The NOT EXISTS clause ensures no overlapping loans for the requested dates
            // Note: 'da_ritirare' copies are still 'disponibile' but have a loan reservation
            $copyStmt = $this->db->prepare("
                SELECT c.id FROM copie c
                WHERE c.libro_id = ?
                AND c.stato IN ('disponibile', 'prenotato')
                AND NOT EXISTS (
                    SELECT 1 FROM prestiti p
                    WHERE p.copia_id = c.id
                    AND p.data_prestito <= ?
                    AND (p.stato = 'in_ritardo' OR p.data_scadenza >= ?)
                    AND (
                        (p.attivo = 1 AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'))
                        OR (p.stato = 'pendente' AND p.copia_id IS NOT NULL)  -- pending conversion holds this copy (#157, model A-refined)
                    )
                )
                LIMIT 1
            ");
            $copyStmt->bind_param('iss', $bookId, $endDate, $startDate);
            $copyStmt->execute();
            $copyResult = $copyStmt->get_result();
            $copy = $copyResult->fetch_assoc();
            $copyStmt->close();

            $copyId = $copy ? (int) $copy['id'] : null;

            if (!$copyId) {
                // No copy available for the requested range – treat as failed allocation
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Lock copy and re-check overlap to prevent race conditions
            $lockCopyStmt = $this->db->prepare("SELECT id FROM copie WHERE id = ? FOR UPDATE");
            $lockCopyStmt->bind_param('i', $copyId);
            $lockCopyStmt->execute();
            $lockCopyStmt->close();

            $overlapCopyStmt = $this->db->prepare("
                SELECT 1 FROM prestiti
                WHERE copia_id = ?
                AND data_prestito <= ? AND (stato = 'in_ritardo' OR data_scadenza >= ?)
                AND (
                    (attivo = 1 AND stato IN ('in_corso','da_ritirare','prenotato','in_ritardo'))
                    OR (stato = 'pendente' AND copia_id IS NOT NULL)  -- pending conversion holds this copy (#157, model A-refined)
                )
                LIMIT 1
            ");
            $overlapCopyStmt->bind_param('iss', $copyId, $endDate, $startDate);
            $overlapCopyStmt->execute();
            $overlapCopy = $overlapCopyStmt->get_result()->fetch_assoc();
            $overlapCopyStmt->close();

            if ($overlapCopy) {
                // Abort if race detected
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Create loan with copia_id and origine='prenotazione'
            // Copy stays available until admin confirms physical pickup
            $stmt = $this->db->prepare("
                INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, origine, attivo)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->bind_param(
                'iiissss',
                $reservation['libro_id'],
                $reservation['utente_id'],
                $copyId,
                $startDate,
                $endDate,
                $newState,
                $origine
            );
            $stmt->execute();
            $loanId = $this->db->insert_id;
            $stmt->close();

            // Verify INSERT succeeded (insert_id = 0 means failure)
            if ($loanId <= 0) {
                $this->rollbackIfOwned($ownTransaction);
                return false;
            }

            // Note: Copy status is NOT updated here - it remains 'disponibile'
            // The copy will be marked as 'prestato' when admin approves the pickup
            // via LoanApprovalController::approveLoan()

            // Update book availability (inside transaction)
            $integrity = new \App\Support\DataIntegrity($this->db);
            $integrity->recalculateBookAvailability($bookId, true);

            $this->commitIfOwned($ownTransaction);
            return $loanId;

        } catch (\Throwable $e) {
            $this->rollbackIfOwned($ownTransaction);
            // In transazione esterna rilanciamo: assorbire qui lascerebbe il
            // chiamante a fare commit() di un INSERT prestiti parziale (CRITICAL #157).
            if ($this->externalTransaction) {
                throw $e;
            }
            error_log("Failed to create loan from reservation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrement queue positions after a reservation is completed
     *
     * Only positions strictly greater than the completed reservation's
     * position are shifted down, so promoting a non-head reservation (when
     * earlier positions are not yet date-eligible) does not corrupt the queue.
     *
     * @param int $bookId The book ID
     * @param int $completedPosition Queue position of the reservation just removed
     * @return void
     */
    private function updateQueuePositions($bookId, int $completedPosition)
    {
        $stmt = $this->db->prepare("
            UPDATE prenotazioni
            SET queue_position = queue_position - 1
            WHERE libro_id = ? AND stato = 'attiva' AND queue_position > ?
        ");
        $stmt->bind_param('ii', $bookId, $completedPosition);
        $stmt->execute();
    }

    /**
     * Send notification email when reservation book becomes available
     *
     * Sends the 'reservation_book_available' email template to the user.
     * Updates the notifica_inviata flag only on successful send.
     *
     * @param array{id: int, libro_id: int, email: string, nome: string, cognome: string, data_inizio_richiesta: string, data_fine_richiesta: string} $reservation Reservation data with user info
     * @return bool True if email was sent successfully
     */
    private function sendReservationNotification(array $reservation): bool
    {
        try {
            // Get book details
            $stmt = $this->db->prepare("
                SELECT l.titolo, COALESCE(l.isbn13, l.isbn10, '') as isbn,
                       GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " ORDER BY la.ruolo='principale' DESC, la.ordine_credito SEPARATOR ', ') AS autore
                FROM libri l
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale', 'co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE l.id = ? AND l.deleted_at IS NULL
                GROUP BY l.id, l.titolo, l.isbn13, l.isbn10
            ");
            $stmt->bind_param('i', $reservation['libro_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $book = $result->fetch_assoc();
            $stmt->close();

            if (!$book) {
                return false;
            }

            $bookLink = book_url([
                'id' => $reservation['libro_id'],
                'titolo' => $book['titolo'] ?? '',
                'autore' => $book['autore'] ?? ''
            ]);

            // Format dates according to installation locale for email templates
            $locale = \App\Support\I18n::getInstallationLocale();
            $isItalian = str_starts_with($locale, 'it');
            $dateFormat = $isItalian ? 'd-m-Y' : 'Y-m-d';

            $variables = [
                'utente_nome' => $reservation['nome'],
                'libro_titolo' => $book['titolo'],
                'libro_autore' => $book['autore'] ?: 'Autore non specificato',
                'libro_isbn' => $book['isbn'] ?: 'N/A',
                'data_inizio' => date($dateFormat, strtotime($reservation['data_inizio_richiesta'])),
                'data_fine' => date($dateFormat, strtotime($reservation['data_fine_richiesta'])),
                'book_url' => absoluteUrl($bookLink),
                'profile_url' => absoluteUrl(RouteTranslator::route('profile'))
            ];

            // Use NotificationService for consistent email handling
            $notificationService = new \App\Support\NotificationService($this->db);
            $success = $notificationService->sendReservationBookAvailable(
                $reservation['email'],
                $variables
            );

            // Only mark as notified if email was actually sent successfully:
            // le righe 'completata' con notifica_inviata=0 vengono riprese da
            // retryUnsentReservationNotifications() al run di manutenzione/cron
            // successivo (M4) — prima nessuno le rileggeva e l'email era persa.
            if ($success) {
                $stmt = $this->db->prepare("UPDATE prenotazioni SET notifica_inviata = 1 WHERE id = ?");
                $stmt->bind_param('i', $reservation['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                \App\Support\SecureLogger::warning('ReservationManager: email send failed, will be retried by retryUnsentReservationNotifications() on next maintenance run', [
                    'reservation_id' => (int) $reservation['id'],
                ]);
            }

            return $success;

        } catch (\Throwable $e) {
            error_log("Failed to send reservation notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ritenta l'invio delle email 'reservation_book_available' fallite (M4).
     *
     * sendReservationNotification() setta notifica_inviata solo a successo, ma la
     * prenotazione è già 'completata' PRIMA dell'invio: senza questo sweep un
     * hiccup SMTP al momento della promozione perdeva l'email per sempre (nessun
     * altro codice rilegge completata + notifica_inviata=0). Finestra di 7 giorni:
     * oltre, l'avviso non è più utile all'utente.
     *
     * Da chiamare FUORI da ogni transazione (invia email direttamente):
     * MaintenanceService::runAll() e il cron automatic-notifications.
     *
     * @param int $limit Massimo numero di prenotazioni da riprocessare per run
     * @return int Numero di email inviate con successo
     */
    public function retryUnsentReservationNotifications(int $limit = 20): int
    {
        $limit = max(1, $limit);
        // "Adesso" applicativo (M9) come base della finestra di recupero
        $cutoff = date('Y-m-d H:i:s', strtotime(\App\Support\DateHelper::now() . ' -7 days'));

        $stmt = $this->db->prepare("
            SELECT r.*, u.email, u.nome, u.cognome
            FROM prenotazioni r
            JOIN utenti u ON r.utente_id = u.id
            WHERE r.stato = 'completata'
            AND r.notifica_inviata = 0
            AND r.data_inizio_richiesta IS NOT NULL
            AND r.updated_at >= ?
            ORDER BY r.updated_at ASC
            LIMIT ?
        ");
        $stmt->bind_param('si', $cutoff, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $sentCount = 0;
        if ($reservations === []) {
            return 0;
        }

        // Claim-then-send (stesso pattern di warning/overdue): i tre percorsi
        // che invocano questo sweep (cron automatic-notifications, cron
        // full-maintenance e runIfNeeded() da login admin) usano lock diversi
        // e possono girare in overlap, quindi senza claim atomico due run
        // selezionerebbero la stessa riga e l'utente riceverebbe l'email doppia.
        $claimStmt = $this->db->prepare("UPDATE prenotazioni SET notifica_inviata = 1 WHERE id = ? AND notifica_inviata = 0");
        $revertStmt = $this->db->prepare("UPDATE prenotazioni SET notifica_inviata = 0 WHERE id = ?");

        foreach ($reservations as $reservation) {
            $reservationId = (int) $reservation['id'];

            // Claim atomico PRIMA dell'invio: se affected_rows è 0 un run
            // concorrente ha già preso in carico questa riga.
            $claimStmt->bind_param('i', $reservationId);
            $claimStmt->execute();
            if ($claimStmt->affected_rows < 1) {
                continue;
            }

            // Risolvi la data di fine con lo stesso coalesce canonico di
            // processBookAvailability(): una riga legacy può avere
            // data_fine_richiesta NULL ma data_scadenza_prenotazione valorizzata.
            $reservation['data_fine_richiesta'] = $reservation['data_fine_richiesta']
                ?: (!empty($reservation['data_scadenza_prenotazione'])
                    ? substr((string) $reservation['data_scadenza_prenotazione'], 0, 10)
                    : $reservation['data_inizio_richiesta']);

            if ($this->sendReservationNotification($reservation)) {
                $sentCount++;
            } else {
                // Invio fallito: rilascia il claim così la riga resta
                // eleggibile per il run successivo.
                $revertStmt->bind_param('i', $reservationId);
                $revertStmt->execute();
            }
        }
        $claimStmt->close();
        $revertStmt->close();

        return $sentCount;
    }

    /**
     * Check if book is available for loan (multi-copy aware)
     *
     * @param int $bookId The book ID
     * @param string|null $startDate Start date of the requested period (Y-m-d format). Defaults to today.
     * @param string|null $endDate End date of the requested period (Y-m-d format). Defaults to startDate.
     * @return bool True if at least one copy is available for the entire requested period
     */
    public function isBookAvailableForImmediateLoan($bookId, ?string $startDate = null, ?string $endDate = null, ?int $excludeUserId = null): bool
    {
        // Default to today (app timezone, M9): date('Y-m-d') usa la timezone del
        // processo e a cavallo della mezzanotte sbaglierebbe il giorno richiesto.
        $startDate = $startDate ?: \App\Support\DateHelper::today();
        $endDate = $endDate ?: $startDate;

        // Validate date format and ensure start <= end
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return false;
        }
        if ($startDate > $endDate) {
            return false;
        }

        // First check if ANY copies exist in the copie table for this book
        // This distinguishes between "no records" vs "all copies lost/damaged"
        $existStmt = $this->db->prepare("SELECT COUNT(*) as total FROM copie WHERE libro_id = ?");
        $existStmt->bind_param('i', $bookId);
        $existStmt->execute();
        $totalCopiesExist = (int) ($existStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $existStmt->close();

        if ($totalCopiesExist > 0) {
            // Copies exist in copie table - count only loanable ones.
            $totalStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM copie
                WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento')
            ");
            $totalStmt->bind_param('i', $bookId);
            $totalStmt->execute();
            $totalCopies = (int) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $totalStmt->close();

            // If all copies are lost/damaged, book is unavailable
            if ($totalCopies === 0) {
                return false;
            }
        } else {
            // No copies in copie table - fallback to libri.copie_totali for legacy data
            $fallbackStmt = $this->db->prepare("SELECT IFNULL(copie_totali, 1) AS copie_totali FROM libri WHERE id = ? AND deleted_at IS NULL");
            $fallbackStmt->bind_param('i', $bookId);
            $fallbackStmt->execute();
            $fallbackResult = $fallbackStmt->get_result();
            $fallbackRow = $fallbackResult !== false ? $fallbackResult->fetch_assoc() : null;
            $fallbackStmt->close();

            // If book doesn't exist or is soft-deleted, return false
            if ($fallbackRow === null) {
                return false;
            }
            $totalCopies = (int) $fallbackRow['copie_totali'];

            if ($totalCopies === 0) {
                return false;
            }
        }

        // Canonical OCC (CapacityService): per-day peak of HOLDING loans + active
        // reservations over the requested period, using the canonical 3-step
        // coalesce chain and the full HOLDING predicate (incl. the pendente+copy
        // holder). excludeUserId drops the requesting user's own commitments so
        // they are not blocked by themselves. Replaces the previous naive
        // loans+reservations sum and the 2-step coalesce.
        $capacity = new \App\Services\CapacityService($this->db);
        $occupied = $capacity->occupiedCount(
            (int) $bookId,
            (string) $startDate,
            (string) $endDate,
            excludeUserId: $excludeUserId
        );

        // Multi-copy: available if peak occupancy is below the (possibly legacy) capacity.
        return $occupied < $totalCopies;
    }

    /**
     * Cancel reservations that have passed their expiration date
     *
     * Marks reservations as 'annullata' when data_scadenza_prenotazione
     * is in the past, reorders queue positions for affected books and
     * notifies the affected users (M11: prima la scadenza era silenziosa).
     *
     * Uses transaction to ensure atomic update and queue reordering; le
     * notifiche partono solo DOPO il commit (differite via
     * flushDeferredNotifications() quando la transazione è esterna).
     *
     * @return int Number of reservations cancelled
     */
    public function cancelExpiredReservations(): int
    {
        $ownTransaction = $this->beginTransactionIfNeeded();
        $expiryNotifications = [];

        try {
            // "Adesso" nel timezone applicativo come parametro bound (M9): NOW()
            // dipende dalla session timezone del client DB (il cron forzava UTC,
            // il web non imposta nulla). Un unico valore condiviso da SELECT e
            // UPDATE garantisce anche che le due query vedano le stesse righe.
            $now = \App\Support\DateHelper::now();

            // Resolve affected books first, then lock them in deterministic order
            // BEFORE locking reservation rows. The previous reservations->libri
            // order crossed every create/cancel path (libri->prenotazioni).
            $booksStmt = $this->db->prepare("
                SELECT DISTINCT libro_id
                FROM prenotazioni
                WHERE stato = 'attiva'
                  AND data_scadenza_prenotazione IS NOT NULL
                  AND data_scadenza_prenotazione < ?
                ORDER BY libro_id
            ");
            $booksStmt->bind_param('s', $now);
            $booksStmt->execute();
            $booksResult = $booksStmt->get_result();
            $affectedBooks = [];
            while ($book = $booksResult->fetch_assoc()) {
                $affectedBooks[] = (int) $book['libro_id'];
            }
            $booksStmt->close();

            if ($affectedBooks === []) {
                $this->commitIfOwned($ownTransaction);
                return 0;
            }

            $bookLock = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            foreach ($affectedBooks as $bookId) {
                $bookLock->bind_param('i', $bookId);
                $bookLock->execute();
                $bookLock->get_result();
            }
            $bookLock->close();
            $bookIdsSql = implode(',', $affectedBooks);

            // First, lock the expiring rows BEFORE updating (FOR UPDATE, solo su
            // prenotazioni: nessuna JOIN qui per non estendere il lock ad altre
            // tabelle) and collect the data needed for queue reordering and for
            // the user notifications (M11).
            $selectStmt = $this->db->prepare("
                SELECT id, libro_id, utente_id, data_scadenza_prenotazione
                FROM prenotazioni
                WHERE stato = 'attiva'
                AND data_scadenza_prenotazione IS NOT NULL
                AND data_scadenza_prenotazione < ?
                AND libro_id IN ($bookIdsSql)
                FOR UPDATE
            ");
            $selectStmt->bind_param('s', $now);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $expiring = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $selectStmt->close();

            // Dati utente/titolo per le notifiche, letti ora (prima dell'UPDATE)
            // con le righe già lockate. Libri soft-deleted esclusi: la prenotazione
            // viene comunque annullata ma senza email (convenzione deleted_at).
            if ($expiring !== []) {
                $infoStmt = $this->db->prepare("
                    SELECT u.email, u.nome, l.titolo
                    FROM prenotazioni r
                    JOIN utenti u ON r.utente_id = u.id
                    JOIN libri l ON r.libro_id = l.id AND l.deleted_at IS NULL
                    WHERE r.id = ?
                ");
                foreach ($expiring as $row) {
                    $reservationId = (int) $row['id'];
                    $infoStmt->bind_param('i', $reservationId);
                    $infoStmt->execute();
                    $infoResult = $infoStmt->get_result();
                    $info = $infoResult ? $infoResult->fetch_assoc() : null;
                    if ($info && !empty($info['email'])) {
                        $expiryNotifications[] = [
                            'email' => $info['email'],
                            'variables' => [
                                'utente_nome' => $info['nome'],
                                'libro_titolo' => $info['titolo'],
                                // Raw: formattata da sendQueueReservationExpiredNotification()
                                'data_scadenza' => (string) $row['data_scadenza_prenotazione'],
                            ],
                        ];
                    }
                }
                $infoStmt->close();
            }

            // Now update the reservations (stesso predicato e stesso $now della
            // SELECT: annulla esattamente le righe raccolte sopra)
            $stmt = $this->db->prepare("
                UPDATE prenotazioni
                SET stato = 'annullata'
                WHERE stato = 'attiva'
                AND data_scadenza_prenotazione IS NOT NULL
                AND data_scadenza_prenotazione < ?
                AND libro_id IN ($bookIdsSql)
            ");
            $stmt->bind_param('s', $now);
            $stmt->execute();
            $cancelledCount = $this->db->affected_rows;
            $stmt->close();

            // Reorder queue positions for affected books and refresh their
            // availability (#157): a today-covering active reservation absorbs a
            // copy, so a book freed purely by an expired reservation would keep
            // a stale 'prenotato'/0-availability status until an unrelated event
            // recalculated it. Recalc here, inside the transaction.
            $integrity = new \App\Support\DataIntegrity($this->db);
            foreach ($affectedBooks as $bookId) {
                $this->reorderQueuePositions($bookId);
                $integrity->recalculateBookAvailability($bookId, true);
            }

            $this->commitIfOwned($ownTransaction);

            // Notifiche di scadenza (M11), MAI dentro la transazione: se la
            // possediamo l'abbiamo appena committata e inviamo subito; se è
            // esterna accodiamo e sarà flushDeferredNotifications() a inviare
            // dopo il commit del chiamante (stesso pattern P2).
            if ($expiryNotifications !== []) {
                if ($ownTransaction) {
                    $notificationService = new \App\Support\NotificationService($this->db);
                    foreach ($expiryNotifications as $expired) {
                        try {
                            $notificationService->sendQueueReservationExpiredNotification($expired['email'], $expired['variables']);
                        } catch (\Throwable $notifError) {
                            \App\Support\SecureLogger::warning('Invio notifica scadenza prenotazione fallito', [
                                'error' => $notifError->getMessage(),
                            ]);
                        }
                    }
                } else {
                    foreach ($expiryNotifications as $expired) {
                        $this->deferredExpiryNotifications[] = $expired;
                    }
                }
            }

            return $cancelledCount;

        } catch (\Throwable $e) {
            $this->rollbackIfOwned($ownTransaction);
            // In transazione esterna rilanciamo (stesso pattern di
            // processBookAvailability): assorbire qui lascerebbe il chiamante
            // committare uno stato parziale che rollbackIfOwned non ha annullato.
            if ($this->externalTransaction) {
                throw $e;
            }
            \App\Support\SecureLogger::error('cancelExpiredReservations error', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Reorder queue positions to be sequential starting from 1
     *
     * Called after cancellations to ensure no gaps in queue positions.
     *
     * @param int $bookId The book ID
     * @return void
     */
    private function reorderQueuePositions($bookId)
    {
        // Ordinamento deterministico (P2): le user-variable MySQL in un
        // UPDATE ... ORDER BY non garantiscono l'ordine di assegnazione su MySQL 8 /
        // MariaDB 10.3+. Leggiamo le righe ordinate e riscriviamo queue_position con
        // un loop esplicito (stesso pattern già usato in DataIntegrity).
        $sel = $this->db->prepare("
            SELECT id FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            ORDER BY queue_position ASC, id ASC
        ");
        $sel->bind_param('i', $bookId);
        $sel->execute();
        $res = $sel->get_result();
        $ids = [];
        while ($r = $res->fetch_assoc()) {
            $ids[] = (int) $r['id'];
        }
        $sel->close();

        $pos = 0;
        $upd = $this->db->prepare("UPDATE prenotazioni SET queue_position = ? WHERE id = ?");
        foreach ($ids as $id) {
            $pos++;
            $upd->bind_param('ii', $pos, $id);
            $upd->execute();
        }
        $upd->close();
    }
}
