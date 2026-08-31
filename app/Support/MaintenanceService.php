<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use App\Models\SettingsRepository;

/**
 * Service for running background maintenance tasks
 *
 * Handles scheduled loan activation, overdue status updates,
 * automatic notifications, and ICS calendar generation.
 * Can be triggered by cron job or automatically on admin login
 * with a configurable cooldown period.
 *
 * @package App\Support
 */
class MaintenanceService
{
    /** @var string Path to ICS calendar file */
    private const ICS_PATH = __DIR__ . '/../../storage/calendar/library-calendar.ics';

    /** @var mysqli Database connection */
    private mysqli $db;

    /**
     * Create a new MaintenanceService instance
     *
     * @param mysqli $db Database connection
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Run all maintenance tasks (if not run recently)
     *
     * Returns early if already run within the cooldown period.
     * Il cooldown vero è un claim atomico su system_settings (L8): la sessione
     * resta solo come fast-path per evitare la query a ogni request — da sola
     * non basta, due admin in sessioni diverse eseguirebbero entrambi runAll().
     *
     * @param int $cooldownMinutes Minimum minutes between runs (default: 60)
     * @return array{skipped?: bool, reason?: string, scheduled_loans_activated?: int, invalid_ready_pickups_repaired?: int, expired_waitlist_reservations?: int, reservations_converted?: int, expired_reservations?: int, expired_pickups?: int, overdue_loans_updated?: int, expiration_warnings?: int, overdue_notifications?: int, wishlist_notifications?: int, reservation_notifications_retried?: int, pickup_notifications_retried?: int, ics_generated?: bool, errors?: array} Results or skip status
     */
    public function runIfNeeded(int $cooldownMinutes = 60): array
    {
        $cacheKey = 'maintenance_last_run';
        $now = time();
        $cooldownSeconds = $cooldownMinutes * 60;

        // Fast-path: la stessa sessione non ripete nemmeno la query di claim
        if (isset($_SESSION[$cacheKey]) && ($now - $_SESSION[$cacheKey]) < $cooldownSeconds) {
            return ['skipped' => true, 'reason' => 'cooldown'];
        }

        // Claim atomico cross-sessione su system_settings (UNIQUE category+setting_key):
        // il timestamp avanza solo se il cooldown è trascorso, quindi tra N processi
        // concorrenti solo uno "vince". affected_rows: 1 = INSERT (prima esecuzione),
        // 2 = UPDATE effettivo (claim riuscito), 0 = valore invariato (claim già
        // preso da un altro processo dentro il cooldown).
        try {
            $nowValue = (string) $now;
            $threshold = $now - $cooldownSeconds;
            $stmt = $this->db->prepare("
                INSERT INTO system_settings (category, setting_key, setting_value)
                VALUES ('maintenance', 'last_run', ?)
                ON DUPLICATE KEY UPDATE setting_value = IF(CAST(setting_value AS UNSIGNED) < ?, VALUES(setting_value), setting_value)
            ");
            if ($stmt) {
                $stmt->bind_param('si', $nowValue, $threshold);
                $stmt->execute();
                $claimed = $stmt->affected_rows > 0;
                $stmt->close();

                if (!$claimed) {
                    // Un altro processo ha già eseguito la manutenzione di recente:
                    // memorizza il cooldown anche in sessione per il fast-path.
                    $_SESSION[$cacheKey] = $now;
                    return ['skipped' => true, 'reason' => 'cooldown'];
                }
            }
        } catch (\Throwable $e) {
            // Claim non determinabile (es. tabella mancante durante l'installazione):
            // fail-open sul solo guard di sessione, la manutenzione è idempotente.
            SecureLogger::warning(__('MaintenanceService claim cooldown fallito'), ['error' => $e->getMessage()]);
        }

        // Mark as running
        $_SESSION[$cacheKey] = $now;

        return $this->runAll();
    }

    /**
     * Run all maintenance tasks immediately
     *
     * Executes scheduled loan activation, reservation processing,
     * overdue loan updates, expired pickups, notifications, and ICS calendar generation.
     * Each task is wrapped in try-catch to prevent failures from blocking others.
     *
     * @return array{scheduled_loans_activated: int, invalid_ready_pickups_repaired: int, expired_waitlist_reservations: int, reservations_converted: int, expired_reservations: int, expired_pickups: int, overdue_loans_updated: int, expiration_warnings: int, overdue_notifications: int, wishlist_notifications: int, reservation_notifications_retried: int, pickup_notifications_retried: int, ics_generated: bool, errors: array} Results for each maintenance task
     */
    public function runAll(): array
    {
        $results = [
            'scheduled_loans_activated' => 0,
            'invalid_ready_pickups_repaired' => 0,
            'expired_waitlist_reservations' => 0,
            'reservations_converted' => 0,
            'expired_reservations' => 0,
            'expired_pickups' => 0,
            'overdue_loans_updated' => 0,
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'reservation_notifications_retried' => 0,
            'pickup_notifications_retried' => 0,
            'ics_generated' => false,
            'errors' => []
        ];

        // One-time backfill of legacy free-text contributor columns into author
        // entities (issue #237). Self-guarded by a system_settings marker, so it
        // does real work only on the first post-upgrade pass and is a no-op after.
        try {
            if (!\App\Support\ContributorBackfill::run($this->db)) {
                $results['errors'][] = 'contributorBackfill: incomplete';
            }
        } catch (\Throwable $e) {
            $results['errors'][] = 'contributorBackfill: ' . $e->getMessage();
        }

        // Overdue flip FIRST (#366 residual): flip date-overdue 'in_corso' loans
        // to 'in_ritardo' BEFORE expiries/activations/promotions, so every gate
        // in this same pass that special-cases 'in_ritardo' (capacity clamps,
        // overlap predicates, renew()'s state check) sees the truthful state.
        // With the old order the flip ran LAST: on the day a reservation's start
        // arrived its unreturned predecessor still sat in 'in_corso' with a past
        // due date, defeating those gates for one whole pass.
        try {
            $results['overdue_loans_updated'] = $this->updateOverdueLoans();
        } catch (\Throwable $e) {
            $results['errors'][] = 'updateOverdueLoans: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore prestiti in ritardo'), ['error' => $e->getMessage()]);
        }

        // Repair legacy #366 rows before pickup expiry can cancel them. Releases
        // prior to 0.7.62 could announce a scheduled loan as ready even though
        // its copy was still out; upgrading fixed future promotions but left the
        // already-corrupt da_ritirare row (and its stale deadline) untouched.
        try {
            $results['invalid_ready_pickups_repaired'] = $this->repairInvalidReadyPickups();
        } catch (\Throwable $e) {
            $results['errors'][] = 'repairInvalidReadyPickups: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore ripristino ritiri non disponibili'), ['error' => $e->getMessage()]);
        }

        // Expire next (BUG8/D13 ordering): cull dead-period reservations and
        // unclaimed pickups before activating scheduled loans, so a reservation
        // whose window has already passed is never promoted to 'da_ritirare'.
        try {
            $results['expired_reservations'] = $this->checkExpiredReservations();
        } catch (\Throwable $e) {
            $results['errors'][] = 'checkExpiredReservations: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore prenotazioni scadute'), ['error' => $e->getMessage()]);
        }

        try {
            $results['expired_pickups'] = $this->checkExpiredPickups();
        } catch (\Throwable $e) {
            $results['errors'][] = 'checkExpiredPickups: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore ritiri scaduti'), ['error' => $e->getMessage()]);
        }

        try {
            $results['scheduled_loans_activated'] = $this->activateScheduledLoans();
        } catch (\Throwable $e) {
            $results['errors'][] = 'activateScheduledLoans: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore attivazione prestiti'), ['error' => $e->getMessage()]);
        }

        try {
            $reservationManager = new \App\Controllers\ReservationManager($this->db);
            $results['expired_waitlist_reservations'] = $reservationManager->cancelExpiredReservations();
        } catch (\Throwable $e) {
            $results['errors'][] = 'cancelExpiredReservations: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore prenotazioni in coda scadute'), ['error' => $e->getMessage()]);
        }

        try {
            $results['reservations_converted'] = $this->processScheduledReservations();
        } catch (\Throwable $e) {
            $results['errors'][] = 'processScheduledReservations: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore conversione prenotazioni'), ['error' => $e->getMessage()]);
        }

        // Run automatic notifications
        try {
            $notificationResults = $this->runNotifications();
            $results['expiration_warnings'] = $notificationResults['expiration_warnings'];
            $results['overdue_notifications'] = $notificationResults['overdue_notifications'];
            $results['wishlist_notifications'] = $notificationResults['wishlist_notifications'];
        } catch (\Throwable $e) {
            $results['errors'][] = 'runNotifications: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore notifiche'), ['error' => $e->getMessage()]);
        }

        // M4: recupero delle email 'reservation_book_available' il cui invio era
        // fallito (prenotazione già 'completata' + notifica_inviata=0: nessun
        // altro codice le rilegge). FUORI da ogni transazione: invia direttamente.
        try {
            $reservationManager = new \App\Controllers\ReservationManager($this->db);
            $results['reservation_notifications_retried'] = $reservationManager->retryUnsentReservationNotifications();
        } catch (\Throwable $e) {
            $results['errors'][] = 'retryUnsentReservationNotifications: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore recupero notifiche prenotazione'), ['error' => $e->getMessage()]);
        }

        // Recupero delle email "pronto al ritiro" fallite (stesso razionale M4):
        // il claim su pickup_notification_sent vive in NotificationService,
        // quindi lo sweep non può mai duplicare un invio riuscito.
        try {
            $results['pickup_notifications_retried'] = (new NotificationService($this->db))->retryUnsentPickupNotifications();
        } catch (\Throwable $e) {
            $results['errors'][] = 'retryUnsentPickupNotifications: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore recupero notifiche ritiro'), ['error' => $e->getMessage()]);
        }

        // Best-effort plugin push dispatch (Mobile API): fire AFTER the email
        // reminders on the same cron pass. Plugins hook 'mobile_api.dispatch_push'
        // to deliver native push for the same events. No-op when no plugin is
        // listening; a plugin failure is swallowed by HookManager and can never
        // abort the maintenance run.
        try {
            (new HookManager($this->db))->doAction('mobile_api.dispatch_push');
        } catch (\Throwable $e) {
            $results['errors'][] = 'dispatchPush: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore push'), ['error' => $e->getMessage()]);
        }

        // Generate ICS calendar file
        try {
            $results['ics_generated'] = $this->generateIcsCalendar();
            if ($results['ics_generated'] === false) {
                $results['errors'][] = 'generateIcsCalendar: ICS file not generated';
                SecureLogger::warning(__('MaintenanceService ICS non generato'));
            }
        } catch (\Throwable $e) {
            $results['errors'][] = 'generateIcsCalendar: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore generazione ICS'), ['error' => $e->getMessage()]);
        }

        // Purge expired / long-revoked "Remember me" sessions so user_sessions
        // does not grow unbounded. This previously lived only in the orphaned
        // scripts/maintenance.php, which no documented cron calls — so on a
        // standard install expired remember-me tokens were never cleaned up.
        try {
            $results['expired_sessions_purged'] = (new RememberMeService($this->db))->cleanupExpiredSessions();
        } catch (\Throwable $e) {
            $results['errors'][] = 'cleanupExpiredSessions: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore pulizia sessioni scadute'), ['error' => $e->getMessage()]);
        }

        // Generic post-maintenance hook, fired as the TRUE last step so
        // listeners (e.g. Book Club poll auto-close + reminders) only run
        // once the whole maintenance pass — ICS generation included — is
        // done. Failures are swallowed per hook and can never abort runAll.
        try {
            (new HookManager($this->db))->doAction('maintenance.after_run');
        } catch (\Throwable $e) {
            $results['errors'][] = 'maintenanceAfterRun: ' . $e->getMessage();
            SecureLogger::error(__('MaintenanceService errore hook post-run'), ['error' => $e->getMessage()]);
        }

        // Aggiorna il marker di cooldown cross-sessione anche quando runAll() è
        // chiamato direttamente (i cron entrypoint non passano da runIfNeeded):
        // senza, l'admin che logga un minuto dopo il cron vince il claim e
        // riesegue l'intera manutenzione sincrona nella request di login.
        try {
            $completedAt = (string) time();
            $stmt = $this->db->prepare("
                INSERT INTO system_settings (category, setting_key, setting_value)
                VALUES ('maintenance', 'last_run', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            if ($stmt) {
                $stmt->bind_param('s', $completedAt);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Tabella assente (installazione in corso): fail-open, solo cooldown perso.
            SecureLogger::warning(__('MaintenanceService aggiornamento marker cooldown fallito'), ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Garantisce la colonna prestiti.pickup_notification_sent sugli upgrade
     * (stesso pattern runtime-add di NotificationService::addNotificationColumns):
     * gli UPDATE di attivazione/riparazione la referenziano e girerebbero prima
     * che NotificationService abbia mai eseguito l'ALTER.
     */
    private ?bool $pickupNotificationColumnAvailable = null;

    private function ensurePickupNotificationColumn(): bool
    {
        if ($this->pickupNotificationColumnAvailable !== null) {
            return $this->pickupNotificationColumnAvailable;
        }
        return $this->pickupNotificationColumnAvailable = PickupNotificationSchema::ensure($this->db);
    }

    /**
     * Generate ICS calendar file for loans and reservations
     *
     * Creates an iCalendar (.ics) file in storage/calendar/ containing
     * all active loans, scheduled loans, and pending reservations.
     * Ensures the storage directory exists before writing.
     *
     * @return bool True if file was generated successfully, false otherwise
     */
    public function generateIcsCalendar(): bool
    {
        $icsGenerator = new IcsGenerator($this->db);
        // IcsGenerator::saveToFile() creates the directory if needed
        return $icsGenerator->saveToFile(self::ICS_PATH);
    }

    /**
     * Run automatic notifications (expiration warnings, overdue, wishlist)
     *
     * Delegates to NotificationService to send loan expiration warnings,
     * overdue loan notifications, and wishlist availability alerts.
     *
     * @return array{expiration_warnings: int, overdue_notifications: int, wishlist_notifications: int, errors: array} Notification counts and any errors
     */
    public function runNotifications(): array
    {
        $results = [
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'errors' => []
        ];

        try {
            $notificationService = new NotificationService($this->db);
            $notifResults = $notificationService->runAutomaticNotifications();

            $results['expiration_warnings'] = $notifResults['expiration_warnings'] ?? 0;
            $results['overdue_notifications'] = $notifResults['overdue_notifications'] ?? 0;
            $results['wishlist_notifications'] = $notifResults['wishlist_notifications'] ?? 0;

            if (!empty($notifResults['errors'])) {
                $results['errors'] = $notifResults['errors'];
            }
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Repair ready-pickup rows created by the pre-0.7.62 #366 bug.
     *
     * A `da_ritirare` row is not truthful when there is no physical copy that
     * can be handed to its patron now. Demote it to `prenotato` and clear the
     * pickup deadline instead of cancelling it: the scheduled loan remains the
     * user's place in circulation and a later activation pass can promote it
     * again after the outstanding copy returns.
     *
     * @return int Number of stale ready-pickup rows repaired.
     */
    public function repairInvalidReadyPickups(): int
    {
        $pickupColumnReset = $this->ensurePickupNotificationColumn()
            ? ",\n                        pickup_notification_sent = 0,
                        pickup_notification_claim_token = NULL,
                        pickup_notification_last_attempt_at = NULL"
            : '';
        $stmt = $this->db->prepare("
            SELECT id, libro_id
            FROM prestiti
            WHERE stato = 'da_ritirare' AND attivo = 1
            ORDER BY libro_id, id
        ");
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare invalid ready-pickup query');
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $candidates = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $integrity = new DataIntegrity($this->db);
        $repaired = 0;

        foreach ($candidates as $candidate) {
            $bookId = (int) $candidate['libro_id'];
            $loanId = (int) $candidate['id'];
            $this->db->begin_transaction();

            try {
                // Canonical circulation lock order: book, loan, then copy.
                // CI-SOFT-DELETE-EXEMPT: an archived title's open circulation
                // state must still be repairable, just like return/cancel.
                $bookLock = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
                $bookLock->bind_param('i', $bookId);
                $bookLock->execute();
                $bookExists = (bool) $bookLock->get_result()->fetch_assoc();
                $bookLock->close();
                if (!$bookExists) {
                    $this->db->rollback();
                    continue;
                }

                $loanLock = $this->db->prepare("
                    SELECT id, libro_id, copia_id
                    FROM prestiti
                    WHERE id = ? AND stato = 'da_ritirare' AND attivo = 1
                    FOR UPDATE
                ");
                $loanLock->bind_param('i', $loanId);
                $loanLock->execute();
                $loan = $loanLock->get_result()->fetch_assoc();
                $loanLock->close();
                if (!$loan || (int) $loan['libro_id'] !== $bookId) {
                    $this->db->rollback();
                    continue;
                }

                // Judge each row ONLY on its own pinned copy — mirroring the
                // migrate_0.7.63-rc.1.sql selection. A book-level occupancy
                // count would demote a truthful pickup whose own copy is free
                // just because a sibling corrupt row inflates the count, and
                // activateScheduledLoans() would then re-promote it in the same
                // runAll() with an extended deadline and a duplicate email.
                $invalid = false;

                $copyId = $loan['copia_id'] !== null ? (int) $loan['copia_id'] : 0;
                $copyStateNow = null;
                if ($copyId <= 0) {
                    // confirmPickup() cannot hand out a loan with no copy. It
                    // must return to prenotato and be assigned atomically by
                    // activateScheduledLoans() below.
                    $invalid = true;
                } else {
                    $copyLock = $this->db->prepare('SELECT stato FROM copie WHERE id = ? AND libro_id = ? FOR UPDATE');
                    $copyLock->bind_param('ii', $copyId, $bookId);
                    $copyLock->execute();
                    $copy = $copyLock->get_result()->fetch_assoc();
                    $copyLock->close();
                    $copyStateNow = $copy !== null ? (string) $copy['stato'] : null;
                    if (!$copy || !in_array($copyStateNow, ['disponibile', 'prenotato'], true)) {
                        $invalid = true;
                    }

                    // Do not trust only copie.stato: old releases could change
                    // it to prenotato while another loan still physically held
                    // the same copy. The loan rows are the source of truth.
                    $copyHolder = $this->db->prepare("
                        SELECT 1
                        FROM prestiti
                        WHERE copia_id = ? AND id <> ?
                          AND ( (attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare'))
                                OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                        LIMIT 1
                    ");
                    $copyHolder->bind_param('ii', $copyId, $loanId);
                    $copyHolder->execute();
                    $copyAlreadyHeld = (bool) $copyHolder->get_result()->fetch_row();
                    $copyHolder->close();
                    if ($copyAlreadyHeld) {
                        $invalid = true;
                    }
                }

                if (!$invalid) {
                    $this->db->rollback();
                    continue;
                }

                $repair = $this->db->prepare("
                    UPDATE prestiti
                    SET stato = 'prenotato', pickup_deadline = NULL, copia_id = NULL{$pickupColumnReset}
                    WHERE id = ? AND stato = 'da_ritirare' AND attivo = 1
                ");
                $repair->bind_param('i', $loanId);
                $repair->execute();
                $changed = $repair->affected_rows;
                $repair->close();
                if ($changed !== 1) {
                    $this->db->rollback();
                    continue;
                }

                // The demotion just unpinned the copy: without a committing loan
                // a circulation state (prenotato/prestato) on the copie row is
                // stale and would keep the copy off the shelf forever. Release
                // it in the SAME transaction, before the availability recompute.
                // Non-circulation states (manutenzione, perso, ...) are curated
                // by staff and are never touched here.
                if ($copyId > 0 && in_array($copyStateNow, ['prenotato', 'prestato'], true)) {
                    $committer = $this->db->prepare("
                        SELECT 1
                        FROM prestiti
                        WHERE copia_id = ? AND id <> ?
                          AND ( (attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare','prenotato'))
                                OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                        LIMIT 1
                    ");
                    $committer->bind_param('ii', $copyId, $loanId);
                    $committer->execute();
                    $stillCommitted = (bool) $committer->get_result()->fetch_row();
                    $committer->close();
                    if (!$stillCommitted) {
                        $release = $this->db->prepare("
                            UPDATE copie
                            SET stato = 'disponibile'
                            WHERE id = ? AND libro_id = ? AND stato IN ('prenotato', 'prestato')
                        ");
                        $release->bind_param('ii', $copyId, $bookId);
                        $release->execute();
                        $release->close();
                    }
                }

                if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
                    throw new \RuntimeException('Failed to recalculate availability while repairing a ready pickup.');
                }
                $this->db->commit();
                $repaired++;
                SecureLogger::info(__('Ritiro non disponibile ripristinato come prenotato'), [
                    'prestito_id' => $loanId,
                    'libro_id' => $bookId,
                    'copia_id' => $copyId > 0 ? $copyId : null,
                ]);
            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error(__('Errore ripristino ritiro non disponibile'), [
                    'prestito_id' => $loanId,
                    'libro_id' => $bookId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $repaired;
    }

    /**
     * Activate scheduled loans (prenotato -> da_ritirare) when their start date arrives
     *
     * Finds all active loans with status 'prenotato' where data_prestito <= today,
     * updates their status to 'da_ritirare' (ready for pickup), sets the pickup_deadline,
     * and recalculates book availability. Uses transactions for data integrity.
     *
     * Note: The copy remains 'prenotato' during 'da_ritirare' state (blocked for the user).
     * It will be marked 'prestato' only when admin confirms the pickup via confirmPickup().
     *
     * @return int Number of loans activated (moved to da_ritirare)
     * @throws \RuntimeException If query preparation fails
     */
    public function activateScheduledLoans(): int
    {
        $pickupColumnReset = $this->ensurePickupNotificationColumn()
            ? ",\n                        pickup_notification_sent = 0,
                        pickup_notification_claim_token = NULL,
                        pickup_notification_last_attempt_at = NULL"
            : '';

        // "Oggi" nel timezone applicativo come parametro bound (M9): CURDATE()
        // dipende dalla session timezone del client DB, che differiva tra cron
        // (UTC forzato) e web (nessuna impostazione).
        $today = DateHelper::today();

        // Find all scheduled loans that should be activated. data_scadenza >= today
        // guard (BUG8/D13): never promote a reservation whose whole window is already
        // past into 'da_ritirare' — its expiry cron culls it instead.
        $stmt = $this->db->prepare("
            SELECT id, copia_id, libro_id, utente_id, data_scadenza FROM prestiti
            WHERE stato = 'prenotato'
            AND data_prestito <= ?
            AND data_scadenza >= ?
            AND attivo = 1
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare scheduled loans query');
        }

        $stmt->bind_param('ss', $today, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $scheduledLoans = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $activatedCount = 0;
        // Instantiate DataIntegrity once outside the loop to reduce overhead
        $integrity = new DataIntegrity($this->db);
        // Capacity ceiling authority for the #366 copy-free guard below
        $capacity = new \App\Services\CapacityService($this->db);

        // Get pickup expiry days from settings
        $settingsRepo = new SettingsRepository($this->db);
        $pickupDays = (int) $settingsRepo->get('loans', 'pickup_expiry_days', '3');

        foreach ($scheduledLoans as $loan) {
            $this->db->begin_transaction();

            try {
                $bookId = (int) $loan['libro_id'];
                $loanId = (int) $loan['id'];

                // Same lock order as web writes: book first, then loan/copy.
                // The old cron updated prestiti first and later touched libri via
                // DataIntegrity, crossing approve/return paths and risking a deadlock.
                $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
                $lockBook->bind_param('i', $bookId);
                $lockBook->execute();
                $bookExists = (bool) $lockBook->get_result()->fetch_assoc();
                $lockBook->close();
                if (!$bookExists) {
                    $this->db->rollback();
                    continue;
                }

                $lockLoan = $this->db->prepare("
                    SELECT id, copia_id, libro_id, utente_id, data_prestito, data_scadenza
                    FROM prestiti
                    WHERE id = ? AND stato = 'prenotato' AND attivo = 1
                      AND data_prestito <= ? AND data_scadenza >= ?
                    FOR UPDATE
                ");
                $lockLoan->bind_param('iss', $loanId, $today, $today);
                $lockLoan->execute();
                $lockedLoan = $lockLoan->get_result()->fetch_assoc();
                $lockLoan->close();
                if (!$lockedLoan || (int) $lockedLoan['libro_id'] !== $bookId) {
                    $this->db->rollback();
                    continue;
                }
                $loan = $lockedLoan;
                $committedCopyIds = (new LoanMultiplicityPolicy($this->db))->committedCopyIds(
                    $bookId,
                    (int) $loan['utente_id'],
                    $loanId
                );

                // #366 guard: a reservation may only become 'da_ritirare' (and get
                // the pickup-ready email) when a physical copy is genuinely free
                // RIGHT NOW. The date window alone is not enough: the preceding
                // loan may still be out — overdue included. 'in_corso'/'in_ritardo'
                // rows are counted with NO date predicate because an unreturned
                // copy is out regardless of its contractual dates (runAll() now
                // flips overdue loans BEFORE this sweep, but a standalone call or
                // a mid-pass write can still leave one in 'in_corso' here — keep
                // the state-agnostic count). Sibling 'da_ritirare' pickups and
                // copy-holding 'pendente' rows each pin a copy on the shelf too.
                // Future 'prenotato' rows are NOT counted: they hold capacity for
                // a later window, not a copy today. If nothing is free the
                // reservation simply stays 'prenotato' — no state change, no email
                // — and a later run promotes it once the copy actually comes back.
                $occStmt = $this->db->prepare("
                    SELECT COUNT(*) AS occupied
                    FROM prestiti
                    WHERE libro_id = ?
                      AND id <> ?
                      AND ( (attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare'))
                            OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL) )
                ");
                $occStmt->bind_param('ii', $bookId, $loanId);
                $occStmt->execute();
                $occRow = $occStmt->get_result()->fetch_assoc();
                $occStmt->close();
                $occupied = (int) ($occRow['occupied'] ?? 0);

                if ($occupied >= $capacity->totalCopies($bookId)) {
                    $this->db->rollback();
                    SecureLogger::info(__('Attivazione prestito rinviata: nessuna copia libera'), [
                        'prestito_id' => $loanId,
                        'libro_id' => $bookId,
                        'occupied' => $occupied
                    ]);
                    continue;
                }

                // Resolve and lock the physical copy that can actually be handed
                // out. Legacy/unpinned scheduled loans must be assigned here;
                // promoting them with copia_id=NULL only moves the failure to
                // confirmPickup(), which correctly refuses a copy-less loan.
                $copiaId = !empty($loan['copia_id']) ? (int) $loan['copia_id'] : 0;
                if ($copiaId > 0 && in_array($copiaId, $committedCopyIds, true)) {
                    // Repair legacy/reassigned rows that share a copy with another
                    // open loan for this borrower/title: activation must choose a
                    // distinct physical item, regardless of date overlap.
                    $copiaId = 0;
                }
                if ($copiaId > 0) {
                    $copyStmt = $this->db->prepare('SELECT stato FROM copie WHERE id = ? AND libro_id = ? FOR UPDATE');
                    $copyStmt->bind_param('ii', $copiaId, $bookId);
                    $copyStmt->execute();
                    $copyRow = $copyStmt->get_result()->fetch_assoc();
                    $copyStmt->close();
                    $copyState = $copyRow['stato'] ?? null;

                    // Check the loan rows too: pre-0.7.62 data may say the copy
                    // is `prenotato` even though another loan still has it out.
                    $copyConflict = $this->db->prepare("
                        SELECT 1
                        FROM prestiti
                        WHERE copia_id = ? AND id <> ?
                          AND ( (attivo = 1 AND stato IN ('in_corso','in_ritardo','da_ritirare'))
                                OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL)
                                OR (attivo = 1 AND stato = 'prenotato'
                                    AND data_prestito <= ? AND data_scadenza >= ?) )
                        LIMIT 1
                    ");
                    $copyConflict->bind_param('iiss', $copiaId, $loanId, $loan['data_scadenza'], $loan['data_prestito']);
                    $copyConflict->execute();
                    $copyHeld = (bool) $copyConflict->get_result()->fetch_row();
                    $copyConflict->close();

                    if (!in_array($copyState, ['disponibile', 'prenotato'], true) || $copyHeld) {
                        // The originally pinned copy may still be out while a
                        // sibling copy is already back on the shelf (#366).
                        // Fall through to the same allocator used by legacy
                        // copyless rows instead of leaving the reservation
                        // stuck on the unavailable physical item forever.
                        SecureLogger::info(__('Attivazione prestito: copia assegnata non in sede, ricerca alternativa'), [
                            'prestito_id' => $loanId,
                            'libro_id' => $bookId,
                            'copia_id' => $copiaId,
                            'copia_stato' => $copyState,
                            'copia_impegnata' => $copyHeld,
                        ]);
                        $copiaId = 0;
                    }
                }
                if ($copiaId <= 0) {
                    $freeCopy = $this->db->prepare("
                        SELECT c.id
                        FROM copie c
                        WHERE c.libro_id = ?
                          AND c.stato IN ('disponibile', 'prenotato')
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
                              WHERE p.copia_id = c.id AND p.id <> ?
                                AND ( (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','da_ritirare'))
                                      OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL)
                                      OR (p.attivo = 1 AND p.stato = 'prenotato'
                                          AND p.data_prestito <= ? AND p.data_scadenza >= ?) )
                          )
                        ORDER BY c.id
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $freeCopy->bind_param(
                        'iiiiiss',
                        $bookId,
                        $bookId,
                        $loan['utente_id'],
                        $loanId,
                        $loanId,
                        $loan['data_scadenza'],
                        $loan['data_prestito']
                    );
                    $freeCopy->execute();
                    $freeCopyRow = $freeCopy->get_result()->fetch_assoc();
                    $freeCopy->close();
                    $copiaId = (int) ($freeCopyRow['id'] ?? 0);
                    if ($copiaId <= 0) {
                        $this->db->rollback();
                        SecureLogger::info(__('Attivazione prestito rinviata: nessuna copia fisica assegnabile'), [
                            'prestito_id' => $loanId,
                            'libro_id' => $bookId,
                        ]);
                        continue;
                    }
                }

                // Calculate pickup deadline dal "oggi" applicativo, cappata a
                // data_scadenza (L1): senza il cap un prestito con finestra corta
                // restava ritirabile (e la copia bloccata) oltre la fine del
                // prestito stesso.
                $pickupDeadline = date('Y-m-d', strtotime($today . " +{$pickupDays} days"));
                if (!empty($loan['data_scadenza']) && $loan['data_scadenza'] < $pickupDeadline) {
                    $pickupDeadline = $loan['data_scadenza'];
                }

                // Update loan status to da_ritirare with pickup deadline
                // State guard: only update if still in 'prenotato' state (prevents race with confirmPickup)
                $updateStmt = $this->db->prepare("
                    UPDATE prestiti
                    SET stato = 'da_ritirare', pickup_deadline = ?, copia_id = ?{$pickupColumnReset}
                    WHERE id = ? AND stato = 'prenotato' AND attivo = 1 AND data_scadenza >= ?
                ");
                $updateStmt->bind_param('siis', $pickupDeadline, $copiaId, $loan['id'], $today);
                $updateStmt->execute();
                $affectedRows = $updateStmt->affected_rows;
                $updateStmt->close();

                // Check if the update actually happened (row may have been modified by concurrent request)
                if ($affectedRows === 0) {
                    $this->db->rollback();
                    SecureLogger::debug(__('Prestito già modificato da altra richiesta'), [
                        'prestito_id' => $loan['id']
                    ]);
                    continue;
                }

                // Copy remains 'prenotato' until pickup is confirmed.
                $holdCopy = $this->db->prepare("UPDATE copie SET stato = 'prenotato' WHERE id = ? AND stato = 'disponibile'");
                $holdCopy->bind_param('i', $copiaId);
                $holdCopy->execute();
                $holdCopy->close();

                // Recalculate book availability using DataIntegrity for consistency
                // (da_ritirare counts as "slot occupied" even if copy is available)
                if (!$integrity->recalculateBookAvailability((int) $loan['libro_id'], true)) {
                    throw new \RuntimeException('Failed to recalculate availability while activating a scheduled loan.');
                }

                $this->db->commit();
                $activatedCount++;

                // Send pickup ready notification to user (outside transaction)
                try {
                    $notificationService = new NotificationService($this->db);
                    $notificationService->sendPickupReadyNotification((int)$loan['id']);
                } catch (\Throwable $notifError) {
                    SecureLogger::warning(__('Errore invio notifica ritiro pronto'), [
                        'prestito_id' => $loan['id'],
                        'error' => $notifError->getMessage()
                    ]);
                }

            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error(__('Errore attivazione prestito schedulato'), [
                    'prestito_id' => $loan['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $activatedCount;
    }

    /**
     * Process scheduled reservations - convert reservations to loans when:
     * 1. Their start date (data_inizio_richiesta) is today or in the past
     * 2. A copy is actually available for that book
     *
     * This handles the case where a user creates a reservation for a future date
     * and the book is already available - without this, the reservation would
     * sit in queue forever waiting for a "book returned" event that never comes.
     *
     * @return int Number of reservations converted to loans
     * @throws \RuntimeException If query preparation fails
     */
    public function processScheduledReservations(): int
    {
        $today = DateHelper::today();

        // Find all active reservations where the requested start date has arrived
        // Process them in queue order (queue_position ASC)
        $stmt = $this->db->prepare("
            SELECT p.id, p.libro_id, p.utente_id, p.data_inizio_richiesta, p.data_fine_richiesta,
                   u.email, u.nome, u.cognome
            FROM prenotazioni p
            JOIN utenti u ON p.utente_id = u.id
            WHERE p.stato = 'attiva'
            AND " . \App\Support\LoanEligibility::promotableReservationWhere('p') . "
            AND " . \App\Support\LoanEligibility::eligibleUserWhere('u') . "
            ORDER BY p.libro_id, p.queue_position ASC
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare scheduled reservations query');
        }

        $stmt->bind_param('sss', $today, $today, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $convertedCount = 0;
        $processedBooks = []; // Track which books we've already processed in this run

        foreach ($reservations as $reservation) {
            $bookId = (int)$reservation['libro_id'];

            // Each book is handled once per run: the inner loop below promotes
            // every eligible reservation for it, so later rows are redundant
            if (isset($processedBooks[$bookId])) {
                continue;
            }
            $processedBooks[$bookId] = true;

            $this->db->begin_transaction();

            try {
                // Lock the book row (skip deleted books)
                $lockStmt = $this->db->prepare("SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
                $lockStmt->bind_param('i', $bookId);
                $lockStmt->execute();
                $lockResult = $lockStmt->get_result();
                if (!$lockResult->fetch_assoc()) {
                    $lockStmt->close();
                    $this->db->rollback();
                    continue; // Skip deleted books
                }
                $lockStmt->close();

                // Use ReservationManager to process the reservations: loop "finché
                // converte" come negli altri release-path (checkExpiredPickups, L2).
                // Prima si convertiva al massimo una prenotazione per libro per run:
                // con 3 copie libere e 3 prenotazioni eleggibili servivano 3 giorni.
                $reservationManager = new \App\Controllers\ReservationManager($this->db);
                $reservationManager->setExternalTransaction(true); // TXN-003: siamo già in transazione
                $bookConverted = 0;
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($bookId); $promoGuard++) {
                    $bookConverted++;
                }

                if ($bookConverted > 0) {
                    $this->db->commit();
                    $convertedCount += $bookConverted;

                    // P2: invia le notifiche reservation_book_available accodate durante
                    // la transazione esterna, ora che il commit è avvenuto.
                    try {
                        $reservationManager->flushDeferredNotifications();
                    } catch (\Throwable $e) {
                        SecureLogger::warning('Flush notifica prenotazione fallito', ['libro_id' => $bookId, 'error' => $e->getMessage()]);
                    }

                    SecureLogger::info(__('MaintenanceService prenotazione convertita in prestito'), [
                        'libro_id' => $bookId,
                        'convertite' => $bookConverted
                    ]);
                } else {
                    // No copy available yet, rollback and continue
                    $this->db->rollback();
                }

            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error(__('MaintenanceService errore elaborazione prenotazione'), [
                    'libro_id' => $bookId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $convertedCount;
    }

    /**
     * Check and expire reservations past their due date (Case 4)
     *
     * Finds prestiti with stato='prenotato' where data_scadenza < today,
     * marks them as 'scaduto', frees assigned copies, and triggers
     * reassignment to next user in queue.
     *
     * @return int Number of reservations expired
     * @throws \RuntimeException If query preparation fails
     */
    public function checkExpiredReservations(): int
    {
        $today = DateHelper::today();

        // Find expired reservations
        $stmt = $this->db->prepare("
            SELECT id, libro_id, copia_id, utente_id
            FROM prestiti
            WHERE stato = 'prenotato'
            AND attivo = 1
            AND data_scadenza < ?
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare expired reservations query');
        }

        $stmt->bind_param('s', $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $expiredReservations = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $expiredCount = 0;
        $integrity = new DataIntegrity($this->db);

        foreach ($expiredReservations as $reservation) {
            // Fresh reassignment service per iteration: it buffers deferred
            // notifications, so reusing one instance across iterations would let
            // a notification queued in an iteration that subsequently rolls back
            // leak into the next iteration's flushDeferredNotifications() and be
            // emailed for work that was never committed.
            $reassignmentService = new \App\Services\ReservationReassignmentService($this->db);
            // External transaction: the service must not open nested transactions
            $reassignmentService->setExternalTransaction(true);

            $this->db->begin_transaction();

            try {
                $id = (int) $reservation['id'];
                $copiaId = $reservation['copia_id'] ? (int) $reservation['copia_id'] : null;
                $libroId = (int) $reservation['libro_id'];

                // CI-SOFT-DELETE-EXEMPT: expiry maintenance must clean existing reservations for deleted books.
                $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
                $lockBook->bind_param('i', $libroId);
                $lockBook->execute();
                $bookExists = (bool) $lockBook->get_result()->fetch_assoc();
                $lockBook->close();
                if (!$bookExists) {
                    $this->db->rollback();
                    continue;
                }

                $lockLoan = $this->db->prepare("
                    SELECT id, libro_id, copia_id, utente_id
                    FROM prestiti
                    WHERE id = ? AND stato = 'prenotato' AND attivo = 1 AND data_scadenza < ?
                    FOR UPDATE
                ");
                $lockLoan->bind_param('is', $id, $today);
                $lockLoan->execute();
                $lockedReservation = $lockLoan->get_result()->fetch_assoc();
                $lockLoan->close();
                if (!$lockedReservation || (int) $lockedReservation['libro_id'] !== $libroId) {
                    $this->db->rollback();
                    continue;
                }
                $copiaId = $lockedReservation['copia_id'] ? (int) $lockedReservation['copia_id'] : null;

                // Build note suffix safely with bound parameter. Data nel fuso
                // applicativo (P4): la decisione di scadenza usa $today
                // (DateHelper::today()), mentre date('d/m/Y') userebbe la TZ del
                // processo — a cavallo della mezzanotte la nota citava un giorno
                // diverso da quello effettivamente deciso.
                $noteSuffix = "\n[System] " . __('Scaduta il') . ' ' . implode('/', array_reverse(explode('-', $today)));

                // Mark as expired. Re-assert stato='prenotato' + check affected_rows
                // (D14): a concurrent confirmPickup/activateScheduledLoans may have
                // advanced this row between the SELECT and here — don't stomp it.
                $updateStmt = $this->db->prepare("
                    UPDATE prestiti
                    SET stato = 'scaduto',
                        attivo = 0,
                        updated_at = NOW(),
                        note = CONCAT(COALESCE(note, ''), ?)
                    WHERE id = ? AND stato = 'prenotato' AND attivo = 1
                ");
                $updateStmt->bind_param('si', $noteSuffix, $id);
                $updateStmt->execute();
                $expiredAffected = $updateStmt->affected_rows;
                $updateStmt->close();
                if ($expiredAffected === 0) {
                    $this->db->rollback();
                    continue;
                }

                // If a copy was assigned, make it available (if currently 'prenotato')
                if ($copiaId) {
                    $checkCopy = $this->db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                    $checkCopy->bind_param('i', $copiaId);
                    $checkCopy->execute();
                    $copyResult = $checkCopy->get_result();
                    $copyState = $copyResult ? $copyResult->fetch_assoc() : null;
                    $checkCopy->close();

                    if ($copyState && $copyState['stato'] === 'prenotato') {
                        // Update copy to available
                        $updateCopy = $this->db->prepare("UPDATE copie SET stato = 'disponibile' WHERE id = ?");
                        $updateCopy->bind_param('i', $copiaId);
                        $updateCopy->execute();
                        $updateCopy->close();

                        // Trigger reassignment logic for this copy (inside same transaction)
                        $reassignmentService->reassignOnReturn($copiaId);
                    }
                }

                // Layer 2: promote queued waitlist reservations freed by this expiry
                // (loop until none convert). Both queues on every release path (D5/BUG10).
                $reservationManager = new \App\Controllers\ReservationManager($this->db);
                $reservationManager->setExternalTransaction(true);
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                    // keep promoting while freed capacity converts the next queued reservation
                }

                // Recalculate book availability (inside transaction)
                if (!$integrity->recalculateBookAvailability($libroId, true)) {
                    throw new \RuntimeException('Failed to recalculate availability after reservation expiry.');
                }

                $this->db->commit();
                $expiredCount++;

                // Invia notifiche differite DOPO il commit della transazione.
                // Isolata in try/catch: un errore di invio post-commit non deve
                // entrare nel catch esterno (che tenterebbe un rollback su una
                // transazione già committata).
                try {
                    $reassignmentService->flushDeferredNotifications();
                    $reservationManager->flushDeferredNotifications();
                } catch (\Throwable $flushErr) {
                    \App\Support\SecureLogger::warning('Flush notifiche differite fallito', ['error' => $flushErr->getMessage()]);
                }

                // Notifica l'utente che la sua prenotazione è scaduta (GAP-2),
                // stesso pattern di checkExpiredPickups (email fuori transazione).
                try {
                    $notificationService = new NotificationService($this->db);
                    $notificationService->sendReservationExpiredNotification($id);
                } catch (\Throwable $e) {
                    SecureLogger::warning('Reservation expired notification failed', ['prestito_id' => $id, 'error' => $e->getMessage()]);
                }

                SecureLogger::info(__('MaintenanceService prenotazione scaduta'), [
                    'prestito_id' => $id,
                    'libro_id' => $libroId,
                    'copia_id' => $copiaId
                ]);

            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error(__('MaintenanceService errore scadenza prenotazione'), [
                    'prestito_id' => $reservation['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expiredCount;
    }

    /**
     * Check and expire pickups past their pickup_deadline (da_ritirare -> scaduto)
     *
     * Finds prestiti with stato='da_ritirare' where pickup_deadline < today,
     * marks them as 'scaduto', frees assigned copies, and triggers
     * reassignment to next user in queue.
     *
     * @return int Number of pickups expired
     * @throws \RuntimeException If query preparation fails
     */
    public function checkExpiredPickups(): int
    {
        $today = DateHelper::today();

        // Find expired pickups (da_ritirare with pickup_deadline passed)
        $stmt = $this->db->prepare("
            SELECT id, libro_id, copia_id, utente_id
            FROM prestiti
            WHERE stato = 'da_ritirare'
            AND attivo = 1
            AND pickup_deadline IS NOT NULL
            AND pickup_deadline < ?
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare expired pickups query');
        }

        $stmt->bind_param('s', $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $expiredPickups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $expiredCount = 0;
        $integrity = new DataIntegrity($this->db);

        foreach ($expiredPickups as $pickup) {
            // Fresh reassignment service per iteration (see checkExpiredReservations):
            // a shared instance would leak a rolled-back iteration's buffered
            // notifications into the next iteration's flush.
            $reassignmentService = new \App\Services\ReservationReassignmentService($this->db);
            // External transaction: the service must not open nested transactions
            $reassignmentService->setExternalTransaction(true);

            $this->db->begin_transaction();

            try {
                $id = (int) $pickup['id'];
                $copiaId = $pickup['copia_id'] ? (int) $pickup['copia_id'] : null;
                $libroId = (int) $pickup['libro_id'];

                // CI-SOFT-DELETE-EXEMPT: expired pickup cleanup must free copies for deleted books.
                $lockBook = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
                $lockBook->bind_param('i', $libroId);
                $lockBook->execute();
                $bookExists = (bool) $lockBook->get_result()->fetch_assoc();
                $lockBook->close();
                if (!$bookExists) {
                    $this->db->rollback();
                    continue;
                }

                $lockLoan = $this->db->prepare("
                    SELECT id, libro_id, copia_id, utente_id, pickup_deadline
                    FROM prestiti
                    WHERE id = ? AND stato = 'da_ritirare' AND attivo = 1
                      AND pickup_deadline IS NOT NULL AND pickup_deadline < ?
                    FOR UPDATE
                ");
                $lockLoan->bind_param('is', $id, $today);
                $lockLoan->execute();
                $lockedPickup = $lockLoan->get_result()->fetch_assoc();
                $lockLoan->close();
                if (!$lockedPickup || (int) $lockedPickup['libro_id'] !== $libroId) {
                    $this->db->rollback();
                    continue;
                }
                $copiaId = $lockedPickup['copia_id'] ? (int) $lockedPickup['copia_id'] : null;

                // Build note suffix safely with bound parameter. Data nel fuso
                // applicativo (P4), come sopra: stessa data della decisione
                // basata su $today, non la TZ del processo.
                $noteSuffix = "\n[System] " . __('Ritiro scaduto il') . ' ' . implode('/', array_reverse(explode('-', $today)));

                // Mark as expired with state guard (prevents TOCTOU with concurrent confirmPickup)
                $updateStmt = $this->db->prepare("
                    UPDATE prestiti
                    SET stato = 'scaduto',
                        attivo = 0,
                        pickup_deadline = NULL,
                        updated_at = NOW(),
                        note = CONCAT(COALESCE(note, ''), ?)
                    WHERE id = ? AND stato = 'da_ritirare' AND attivo = 1
                ");
                $updateStmt->bind_param('si', $noteSuffix, $id);
                $updateStmt->execute();
                $affectedRows = $updateStmt->affected_rows;
                $updateStmt->close();

                // Check if the update actually happened (row may have been picked up concurrently)
                if ($affectedRows === 0) {
                    $this->db->rollback();
                    SecureLogger::debug(__('Ritiro già confermato o modificato'), [
                        'prestito_id' => $id
                    ]);
                    continue;
                }

                // Copy should already be 'prenotato' during da_ritirare state
                // But let's ensure it's available for reassignment
                if ($copiaId) {
                    $checkCopy = $this->db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
                    $checkCopy->bind_param('i', $copiaId);
                    $checkCopy->execute();
                    $copyResult = $checkCopy->get_result();
                    $copyState = $copyResult ? $copyResult->fetch_assoc() : null;
                    $checkCopy->close();

                    // Ensure copy is available (but don't resurrect non-restorable copies)
                    // Skip if copy is in a non-lendable operational state.
                    $nonRestorableStates = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];
                    if ($copyState && !in_array($copyState['stato'], $nonRestorableStates, true) && $copyState['stato'] !== 'disponibile') {
                        $updateCopy = $this->db->prepare("UPDATE copie SET stato = 'disponibile' WHERE id = ?");
                        $updateCopy->bind_param('i', $copiaId);
                        $updateCopy->execute();
                        $updateCopy->close();
                    }

                    // Trigger reassignment logic for this copy (inside same transaction)
                    $reassignmentService->reassignOnReturn($copiaId);
                }

                // Layer 2: promote queued waitlist reservations freed by this expired
                // pickup (loop until none convert). Both queues (D5/BUG10).
                $reservationManager = new \App\Controllers\ReservationManager($this->db);
                $reservationManager->setExternalTransaction(true);
                for ($promoGuard = 0; $promoGuard < 1000 && $reservationManager->processBookAvailability($libroId); $promoGuard++) {
                    // keep promoting while freed capacity converts the next queued reservation
                }

                // Recalculate book availability (inside transaction)
                if (!$integrity->recalculateBookAvailability($libroId, true)) {
                    throw new \RuntimeException('Failed to recalculate availability after pickup expiry.');
                }

                $this->db->commit();
                $expiredCount++;

                // Invia notifiche differite DOPO il commit della transazione.
                // Isolata in try/catch: un errore di invio post-commit non deve
                // entrare nel catch esterno (che tenterebbe un rollback su una
                // transazione già committata).
                try {
                    $reassignmentService->flushDeferredNotifications();
                    $reservationManager->flushDeferredNotifications();
                } catch (\Throwable $flushErr) {
                    \App\Support\SecureLogger::warning('Flush notifiche differite fallito', ['error' => $flushErr->getMessage()]);
                }

                // Send pickup expired notification to user. The terminal UPDATE
                // above NULLed pickup_deadline, so pass the elapsed deadline
                // captured under lock for the email body.
                try {
                    $notificationService = new NotificationService($this->db);
                    $notificationService->sendPickupExpiredNotification(
                        $id,
                        isset($lockedPickup['pickup_deadline']) ? (string) $lockedPickup['pickup_deadline'] : null
                    );
                } catch (\Throwable $notifError) {
                    SecureLogger::warning(__('Errore invio notifica ritiro scaduto'), [
                        'prestito_id' => $id,
                        'error' => $notifError->getMessage()
                    ]);
                }

                SecureLogger::info(__('MaintenanceService ritiro scaduto'), [
                    'prestito_id' => $id,
                    'libro_id' => $libroId,
                    'copia_id' => $copiaId
                ]);

            } catch (\Throwable $e) {
                $this->db->rollback();
                SecureLogger::error(__('MaintenanceService errore scadenza ritiro'), [
                    'prestito_id' => $pickup['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expiredCount;
    }

    /**
     * Update overdue loans status (in_corso -> in_ritardo)
     *
     * Bulk updates all active loans that have passed their due date,
     * changing status from 'in_corso' to 'in_ritardo'.
     *
     * @return int Number of loans marked as overdue
     * @throws \RuntimeException If query preparation fails
     */
    public function updateOverdueLoans(): int
    {
        // "Oggi" nel timezone applicativo come parametro bound (M9): con CURDATE()
        // lo stesso runAll() valutava le scadenze prenotazione con l'oggi
        // applicativo e i ritardi con l'oggi della session timezone DB.
        $today = DateHelper::today();

        $stmt = $this->db->prepare("
            UPDATE prestiti
            SET stato = 'in_ritardo'
            WHERE stato = 'in_corso'
            AND data_scadenza < ?
            AND attivo = 1
        ");

        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare overdue loans query');
        }

        $stmt->bind_param('s', $today);
        $stmt->execute();
        $affected = $this->db->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Static method to run maintenance on admin login via hook
     *
     * Executes maintenance tasks with a 60-minute cooldown when an admin
     * or staff user logs in. Creates its own database connection if needed.
     *
     * @param int $_userId User ID (unused, kept for hook signature compatibility)
     * @param array $userData User data array containing tipo_utente
     * @param mixed $_request Request object (unused, kept for hook signature compatibility)
     * @return void
     */
    public static function onAdminLogin(int $_userId, array $userData, $_request): void
    {
        // Only run for admin/staff users
        if (!in_array($userData['tipo_utente'] ?? '', ['admin', 'staff'], true)) {
            return;
        }

        $createdConnection = false;

        try {
            // Get database connection from global container or settings
            global $app;
            $db = null;

            if (isset($app) && method_exists($app, 'getContainer')) {
                $container = $app->getContainer();
                if ($container && $container->has('db')) {
                    $db = $container->get('db');
                }
            }

            if (!$db) {
                // Fallback: create new connection from settings
                $createdConnection = true;
                $settings = require __DIR__ . '/../../config/settings.php';
                $cfg = $settings['db'];
                $db = new \mysqli(
                    $cfg['hostname'],
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['database'],
                    $cfg['port'],
                    $cfg['socket'] ?? null
                );

                if ($db->connect_error) {
                    SecureLogger::error(__('MaintenanceService connessione database fallita'), [
                        'error' => $db->connect_error
                    ]);
                    return;
                }

                $db->set_charset($cfg['charset']);
                DateHelper::synchronizeDatabaseSession($db);
            }

            $service = new self($db);
            $result = $service->runIfNeeded(60); // Run if not run in last 60 minutes

            // Close connection if we created it
            if ($createdConnection) {
                $db->close();
            }

            if (!($result['skipped'] ?? false)) {
                SecureLogger::info(__('MaintenanceService eseguito al login admin'), [
                    'scheduled_loans_activated' => $result['scheduled_loans_activated'],
                    'invalid_ready_pickups_repaired' => $result['invalid_ready_pickups_repaired'],
                    'overdue_loans_updated' => $result['overdue_loans_updated'],
                    'reservations_converted' => $result['reservations_converted'] ?? 0,
                    'expired_reservations' => $result['expired_reservations'] ?? 0,
                    'expired_pickups' => $result['expired_pickups'] ?? 0,
                    'ics_generated' => $result['ics_generated'] ? 'ok' : 'failed'
                ]);
            }

        } catch (\Throwable $e) {
            // Close connection on error if we created it
            if ($createdConnection && isset($db) && $db instanceof mysqli) {
                $db->close();
            }
            SecureLogger::error(__('MaintenanceService errore durante hook login admin'), [
                'error' => $e->getMessage()
            ]);
        }
    }
}
