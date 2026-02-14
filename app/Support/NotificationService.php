<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use Exception;
use App\Support\ConfigStore;
use App\Support\RouteTranslator;
use App\Support\SettingsMailTemplates;

class NotificationService {
    private mysqli $db;
    private EmailService $emailService;

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->emailService = new EmailService($db);
    }

    /**
     * Format date for email templates using installation locale
     *
     * @param string $dateString Date string parseable by strtotime
     * @param bool $includeTime Include time (H:i) in output
     * @return string Formatted date
     */
    private function formatEmailDate(string $dateString, bool $includeTime = false): string
    {
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return $dateString;
        }

        $locale = I18n::getInstallationLocale();
        $isItalian = str_starts_with($locale, 'it');

        if ($isItalian) {
            $format = 'd-m-Y';
        } else {
            $format = 'Y-m-d';
        }

        if ($includeTime) {
            $format .= ' H:i';
        }

        return date($format, $timestamp);
    }

    /**
     * Invia notifica per nuova registrazione agli admin
     */
    public function notifyNewUserRegistration(int $userId): bool {
        try {
            // Get user details
            $stmt = $this->db->prepare("
                SELECT nome, cognome, email, codice_tessera, created_at, token_verifica_email
                FROM utenti
                WHERE id = ?
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                return false;
            }
            $stmt->close();

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'data_registrazione' => $this->formatEmailDate($user['created_at'], true),
                'admin_users_url' => $this->getBaseUrl() . '/admin/utenti'
            ];

            // Send to admins
            return $this->sendToAdmins('admin_new_registration', $variables);

        } catch (Exception $e) {
            error_log("Failed to notify new user registration: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di benvenuto all'utente appena registrato
     */
    public function sendUserRegistrationPending(int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT nome, cognome, email, codice_tessera, created_at, token_verifica_email
                FROM utenti
                WHERE id = ?
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                return false;
            }
            $stmt->close();

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();

            $verifySection = '';
            if (!empty($user['token_verifica_email'])) {
                // Temporarily switch locale for button translation
                $currentLocale = \App\Support\I18n::getLocale();
                \App\Support\I18n::setLocale($locale);

                // Use /verify-email (English route) as it's supported in all languages
                $verifyUrl = $this->getBaseUrl() . '/verify-email?token=' . urlencode((string)$user['token_verifica_email']);
                $buttonText = __('Conferma la tua email');
                $verifySection = '<p style="margin: 20px 0;"><a href="' . $verifyUrl . '" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a></p>';

                // Restore original locale
                \App\Support\I18n::setLocale($currentLocale);
            }

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'data_registrazione' => $this->formatEmailDate($user['created_at'], true),
                'sezione_verifica' => $verifySection,
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];
            return $this->emailService->sendTemplate($user['email'], 'user_registration_pending', $variables, $locale);

        } catch (Exception $e) {
            error_log("Failed to send user registration pending email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di approvazione account
     */
    public function sendUserAccountApproved(int $userId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT nome, cognome, email, codice_tessera
                FROM utenti
                WHERE id = ?
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                return false;
            }
            $stmt->close();

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'login_url' => rtrim($this->getBaseUrl(), '/') . RouteTranslator::route('login')
            ];

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();
            return $this->emailService->sendTemplate($user['email'], 'user_account_approved', $variables, $locale);

        } catch (Exception $e) {
            error_log("Failed to send user account approved email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di attivazione con link di verifica
     * Usato quando admin approva e vuole che utente verifichi autonomamente
     */
    public function sendUserActivationWithVerification(int $userId, string $token): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT nome, cognome, email, codice_tessera
                FROM utenti
                WHERE id = ?
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                return false;
            }
            $stmt->close();

            $verificationUrl = $this->getBaseUrl() . '/verify-email?token=' . urlencode($token);

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'verification_url' => $verificationUrl,
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();
            return $this->emailService->sendTemplate($user['email'], 'user_activation_with_verification', $variables, $locale);

        } catch (Exception $e) {
            error_log("Failed to send user activation with verification email: " . $e->getMessage());
            return false;
        }
    }

    public function sendUserPasswordSetup(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT id, nome, cognome, email, token_reset_password FROM utenti WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $token = $user['token_reset_password'];
            if (!$token) {
                $token = bin2hex(random_bytes(32));
                $now = gmdate('Y-m-d H:i:s');
                $update = $this->db->prepare("UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?");
                $update->bind_param('ssi', $token, $now, $userId);
                $update->execute();
                $update->close();
            }

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'reset_url' => $this->getBaseUrl() . '/reset-password?token=' . urlencode((string)$token),
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();
            return $this->emailService->sendTemplate($user['email'], 'user_password_setup', $variables, $locale);

        } catch (Exception $e) {
            error_log('Failed to send user password setup email: ' . $e->getMessage());
            return false;
        }
    }

    public function sendAdminInvitation(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT id, nome, cognome, email, token_reset_password FROM utenti WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$user = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $token = $user['token_reset_password'];
            if (!$token) {
                $token = bin2hex(random_bytes(32));
                $now = gmdate('Y-m-d H:i:s');
                $update = $this->db->prepare("UPDATE utenti SET token_reset_password = ?, data_token_reset = ? WHERE id = ?");
                $update->bind_param('ssi', $token, $now, $userId);
                $update->execute();
                $update->close();
            }

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'app_name' => ConfigStore::get('app.name', 'Biblioteca'),
                'reset_url' => $this->getBaseUrl() . '/reset-password?token=' . urlencode((string)$token),
                'dashboard_url' => $this->getBaseUrl() . '/admin/dashboard'
            ];

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();
            return $this->emailService->sendTemplate($user['email'], 'admin_invitation', $variables, $locale);

        } catch (Exception $e) {
            error_log('Failed to send admin invitation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica agli admin per nuova richiesta di prestito
     */
    public function notifyLoanRequest(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $variables = [
                'libro_titolo' => $loan['libro_titolo'],
                'utente_nome' => $loan['utente_nome'],
                'utente_email' => $loan['utente_email'],
                'data_inizio' => $this->formatEmailDate($loan['data_prestito']),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza']),
                'data_richiesta' => $this->formatEmailDate($loan['created_at'], true),
                'approve_url' => $this->getBaseUrl() . '/admin/loans/pending'
            ];

            // Send email to admins
            $emailSent = $this->sendToAdmins('loan_request_notification', $variables);

            // Create in-app notification (uses session locale)
            $notificationTitle = __('Nuova richiesta di prestito');
            $notificationMessage = sprintf(
                __("Richiesta di prestito per \"%s\" da %s dal %s al %s"),
                $loan['libro_titolo'],
                $loan['utente_nome'],
                format_date($loan['data_prestito'], false, '/'),
                format_date($loan['data_scadenza'], false, '/')
            );
            $notificationLink = '/admin/prestiti';

            $this->createNotification(
                'new_loan_request',
                $notificationTitle,
                $notificationMessage,
                $notificationLink,
                $loanId
            );

            return $emailSent;

        } catch (Exception $e) {
            error_log("Failed to notify loan request: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia avvisi di scadenza prestiti (3 giorni prima)
     * Uses atomic mark-then-send pattern to prevent duplicate notifications
     */
    public function sendLoanExpirationWarnings(): int {
        $sentCount = 0;

        try {
            // Get configured days before expiry warning (default: 3)
            $daysBeforeWarning = (int)ConfigStore::get('advanced.days_before_expiry_warning', 3);

            // Get loans expiring in X days
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(p.data_scadenza, CURDATE()) as giorni_rimasti
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato = 'in_corso'
                  AND p.data_scadenza = DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND (p.warning_sent IS NULL OR p.warning_sent = 0)
            ");
            $stmt->bind_param('i', $daysBeforeWarning);
            $stmt->execute();
            $result = $stmt->get_result();

            // Collect all loans first to avoid result set issues
            $loans = [];
            while ($loan = $result->fetch_assoc()) {
                $loans[] = $loan;
            }
            $stmt->close();

            foreach ($loans as $loan) {
                // ATOMIC: Mark warning as sent BEFORE sending email
                // Only proceed if we successfully claimed this loan (affected_rows == 1)
                $updateStmt = $this->db->prepare("UPDATE prestiti SET warning_sent = 1 WHERE id = ? AND (warning_sent IS NULL OR warning_sent = 0)");
                $updateStmt->bind_param('i', $loan['id']);
                $updateStmt->execute();
                $claimed = $updateStmt->affected_rows === 1;
                $updateStmt->close();

                if (!$claimed) {
                    // Another process already claimed this loan, skip
                    continue;
                }

                $variables = [
                    'utente_nome' => $loan['utente_nome'],
                    'libro_titolo' => $loan['libro_titolo'],
                    'data_scadenza' => $this->formatEmailDate($loan['data_scadenza']),
                    'giorni_rimasti' => $loan['giorni_rimasti']
                ];

                $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_expiring_warning', $variables);

                if ($emailSent) {
                    // Create in-app notification for expiring loan
                    $this->createNotification(
                        'general',
                        'Prestito in scadenza',
                        sprintf('"%s" prestato a %s scade fra %d giorni', $loan['libro_titolo'], $loan['utente_nome'], (int)$loan['giorni_rimasti']),
                        '/admin/prestiti',
                        (int)$loan['id']
                    );
                    $sentCount++;
                } else {
                    // Email failed after retries, revert the flag so it can be retried next run
                    $revertStmt = $this->db->prepare("UPDATE prestiti SET warning_sent = 0 WHERE id = ?");
                    $revertStmt->bind_param('i', $loan['id']);
                    $revertStmt->execute();
                    $revertStmt->close();
                    error_log("Failed to send expiration warning for loan {$loan['id']} after retries, flag reverted");
                }
            }

        } catch (Exception $e) {
            error_log("Failed to send loan expiration warnings: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * Invia notifiche per prestiti scaduti
     * Uses atomic mark-then-send pattern to prevent duplicate notifications
     */
    public function sendOverdueLoanNotifications(): int {
        $sentCount = 0;

        try {
            // Get overdue loans
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(CURDATE(), p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato IN ('in_corso', 'in_ritardo')
                  AND p.data_scadenza < CURDATE()
                  AND (p.overdue_notification_sent IS NULL OR p.overdue_notification_sent = 0)
            ");
            $stmt->execute();
            $result = $stmt->get_result();

            // Collect all loans first to avoid result set issues
            $loans = [];
            while ($loan = $result->fetch_assoc()) {
                $loans[] = $loan;
            }
            $stmt->close();

            foreach ($loans as $loan) {
                // ATOMIC: Mark notification as sent BEFORE sending email
                // Only proceed if we successfully claimed this loan (affected_rows == 1)
                $updateStmt = $this->db->prepare("UPDATE prestiti SET overdue_notification_sent = 1, stato = 'in_ritardo' WHERE id = ? AND (overdue_notification_sent IS NULL OR overdue_notification_sent = 0)");
                $updateStmt->bind_param('i', $loan['id']);
                $updateStmt->execute();
                $claimed = $updateStmt->affected_rows === 1;
                $updateStmt->close();

                if (!$claimed) {
                    // Another process already claimed this loan, skip
                    continue;
                }

                $variables = [
                    'utente_nome' => $loan['utente_nome'],
                    'libro_titolo' => $loan['libro_titolo'],
                    'data_scadenza' => $this->formatEmailDate($loan['data_scadenza']),
                    'giorni_ritardo' => $loan['giorni_ritardo']
                ];

                $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_overdue_notification', $variables);

                if ($emailSent) {
                    $this->notifyAdminsOverdue((int)$loan['id']);

                    // Create in-app notification for overdue loan
                    $this->notifyOverdueLoanInApp(
                        (int)$loan['id'],
                        $loan['utente_nome'],
                        $loan['libro_titolo'],
                        (int)$loan['giorni_ritardo']
                    );
                    $sentCount++;
                } else {
                    // Email failed after retries, revert both flag and stato so it can be retried next run
                    $revertStmt = $this->db->prepare("UPDATE prestiti SET overdue_notification_sent = 0, stato = 'in_corso' WHERE id = ?");
                    $revertStmt->bind_param('i', $loan['id']);
                    $revertStmt->execute();
                    $revertStmt->close();
                    error_log("Failed to send overdue notification for loan {$loan['id']} after retries, flags reverted");
                }
            }

        } catch (Exception $e) {
            error_log("Failed to send overdue loan notifications: " . $e->getMessage());
        }

        return $sentCount;
    }

    public function notifyAdminsOverdue(int $loanId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.data_prestito, l.titolo AS libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email AS utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return;
            }
            $stmt->close();

            $variables = [
                'prestito_id' => $loan['id'],
                'libro_titolo' => $loan['libro_titolo'],
                'utente_nome' => $loan['utente_nome'],
                'utente_email' => $loan['utente_email'],
                'data_scadenza' => $loan['data_scadenza'],
                'data_prestito' => $loan['data_prestito'],
            ];

            $template = SettingsMailTemplates::get('loan_overdue_admin');
            if (!$template) {
                return;
            }

            $subject = $this->emailService->replaceVariables($template['subject'], $variables);
            $body = $this->emailService->replaceVariables($template['body'], $variables);

            $this->emailService->sendToAdmins($subject, $body);

        } catch (Exception $e) {
            error_log('Failed to notify admins about overdue loan: ' . $e->getMessage());
        }
    }

    /**
     * Notifica utenti quando libri nella loro wishlist diventano disponibili
     * Uses atomic mark-then-send pattern to prevent duplicate notifications
     */
    public function notifyWishlistBookAvailability(int $bookId): int {
        $sentCount = 0;

        try {
            // Get all users who have this book in their wishlist
            $stmt = $this->db->prepare("
                SELECT w.utente_id, w.id as wishlist_id,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email,
                       l.titolo, COALESCE(l.isbn13, l.isbn10, '') as isbn,
                       GROUP_CONCAT(a.nome ORDER BY la.ruolo='principale' DESC, a.nome SEPARATOR ', ') AS autore
                FROM wishlist w
                JOIN utenti u ON w.utente_id = u.id
                JOIN libri l ON w.libro_id = l.id
                LEFT JOIN libri_autori la ON l.id = la.libro_id
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE w.libro_id = ?
                  AND u.stato = 'attivo'
                  AND w.notified = 0
                GROUP BY w.id, w.utente_id, u.nome, u.cognome, u.email, l.titolo, l.isbn13, l.isbn10
            ");
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();

            // Collect all wishlist entries first to avoid result set issues
            $wishlistEntries = [];
            while ($wishlist = $result->fetch_assoc()) {
                $wishlistEntries[] = $wishlist;
            }
            $stmt->close();

            foreach ($wishlistEntries as $wishlist) {
                // ATOMIC: Mark as notified BEFORE sending email
                // Only proceed if we successfully claimed this entry (affected_rows == 1)
                $updateStmt = $this->db->prepare("UPDATE wishlist SET notified = 1 WHERE id = ? AND notified = 0");
                $updateStmt->bind_param('i', $wishlist['wishlist_id']);
                $updateStmt->execute();
                $claimed = $updateStmt->affected_rows === 1;
                $updateStmt->close();

                if (!$claimed) {
                    // Another process already claimed this entry, skip
                    continue;
                }

                $bookLink = book_url([
                    'id' => $bookId,
                    'titolo' => $wishlist['titolo'] ?? '',
                    'autore' => $wishlist['autore'] ?? ''
                ]);
                $variables = [
                    'utente_nome' => $wishlist['utente_nome'],
                    'libro_titolo' => $wishlist['titolo'],
                    'libro_autore' => $wishlist['autore'] ?: 'Autore non specificato',
                    'libro_isbn' => $wishlist['isbn'] ?: 'N/A',
                    'data_disponibilita' => $this->formatEmailDate('now', true),
                    'book_url' => absoluteUrl($bookLink),
                    'wishlist_url' => absoluteUrl(RouteTranslator::route('wishlist'))
                ];

                $emailSent = $this->sendWithRetry($wishlist['email'], 'wishlist_book_available', $variables);

                if ($emailSent) {
                    $sentCount++;
                } else {
                    // Email failed after retries, revert the flag so it can be retried next run
                    $revertStmt = $this->db->prepare("UPDATE wishlist SET notified = 0 WHERE id = ?");
                    $revertStmt->bind_param('i', $wishlist['wishlist_id']);
                    $revertStmt->execute();
                    $revertStmt->close();
                    error_log("Failed to send wishlist notification for entry {$wishlist['wishlist_id']} after retries, flag reverted");
                }
            }

        } catch (Exception $e) {
            error_log("Failed to notify wishlist book availability: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * Esegue tutte le notifiche automatiche
     */
    public function runAutomaticNotifications(): array {
        $results = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'expiration_warnings' => 0,
            'overdue_notifications' => 0,
            'wishlist_notifications' => 0,
            'errors' => []
        ];

        try {
            // Add notification columns if they don't exist
            $this->addNotificationColumns();
            $this->addWishlistNotificationColumn();

            $results['expiration_warnings'] = $this->sendLoanExpirationWarnings();
            $results['overdue_notifications'] = $this->sendOverdueLoanNotifications();
            $results['wishlist_notifications'] = $this->checkAndNotifyWishlistAvailability();

        } catch (Exception $e) {
            $results['errors'][] = 'Error running automatic notifications: ' . $e->getMessage();
            error_log('Error running automatic notifications: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Controlla e notifica disponibilità libri in wishlist
     * Uses comprehensive check for actual physical copy availability
     */
    private function checkAndNotifyWishlistAvailability(): int {
        $totalNotified = 0;

        try {
            // Get books that have users in wishlist waiting for notification
            $stmt = $this->db->prepare("
                SELECT DISTINCT w.libro_id
                FROM wishlist w
                JOIN libri l ON w.libro_id = l.id
                JOIN utenti u ON w.utente_id = u.id
                WHERE l.copie_disponibili > 0
                  AND l.stato = 'disponibile'
                  AND u.stato = 'attivo'
                  AND w.notified = 0
            ");
            $stmt->execute();
            $result = $stmt->get_result();

            $bookIds = [];
            while ($row = $result->fetch_assoc()) {
                $bookIds[] = (int)$row['libro_id'];
            }
            $stmt->close();

            // For each book, verify actual physical copy availability before notifying
            foreach ($bookIds as $bookId) {
                if ($this->hasActualAvailableCopy($bookId)) {
                    $notified = $this->notifyWishlistBookAvailability($bookId);
                    $totalNotified += $notified;
                }
            }

        } catch (Exception $e) {
            error_log("Failed to check wishlist availability: " . $e->getMessage());
        }

        return $totalNotified;
    }

    /**
     * Checks if a book has at least one actual physical copy available TODAY
     * Considers:
     * - Physical copy state ('disponibile' in copie table)
     * - Active loans overlapping with today
     * - Active reservations overlapping with today
     */
    public function hasActualAvailableCopy(int $bookId): bool {
        $today = date('Y-m-d');

        // Count total loanable copies (exclude perso, danneggiato, manutenzione)
        // This matches the logic in ReservationManager and other availability checks
        $totalStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM copie
            WHERE libro_id = ? AND stato NOT IN ('perso', 'danneggiato', 'manutenzione')
        ");
        $totalStmt->bind_param('i', $bookId);
        $totalStmt->execute();
        $totalCopies = (int)($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalStmt->close();

        if ($totalCopies === 0) {
            return false;
        }

        // Count active loans overlapping with today
        $loanStmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM prestiti
            WHERE libro_id = ? AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
            AND data_prestito <= ? AND data_scadenza >= ?
        ");
        $loanStmt->bind_param('iss', $bookId, $today, $today);
        $loanStmt->execute();
        $activeLoans = (int)($loanStmt->get_result()->fetch_assoc()['count'] ?? 0);
        $loanStmt->close();

        // Count active reservations overlapping with today
        $resStmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            AND data_inizio_richiesta IS NOT NULL
            AND data_inizio_richiesta <= ?
            AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione), data_inizio_richiesta) >= ?
        ");
        $resStmt->bind_param('iss', $bookId, $today, $today);
        $resStmt->execute();
        $activeReservations = (int)($resStmt->get_result()->fetch_assoc()['count'] ?? 0);
        $resStmt->close();

        // Available if total occupied < total copies
        $totalOccupied = $activeLoans + $activeReservations;
        return $totalOccupied < $totalCopies;
    }

    /**
     * Calculate the next availability date for a book
     * Returns the earliest date when a copy will become available
     * @return string|null Date in Y-m-d format, or null if no loans/reservations
     */
    public function getNextAvailabilityDate(int $bookId): ?string {
        $today = date('Y-m-d');

        // First check if book is already available
        if ($this->hasActualAvailableCopy($bookId)) {
            return $today;
        }

        // Find the earliest end date among active loans (approved states only)
        $loanStmt = $this->db->prepare("
            SELECT MIN(data_scadenza) as earliest_end
            FROM prestiti
            WHERE libro_id = ? AND attivo = 1
            AND stato IN ('in_corso', 'in_ritardo', 'da_ritirare', 'prenotato')
            AND data_scadenza >= ?
        ");
        $loanStmt->bind_param('is', $bookId, $today);
        $loanStmt->execute();
        $loanResult = $loanStmt->get_result()->fetch_assoc();
        $earliestLoanEnd = $loanResult['earliest_end'] ?? null;
        $loanStmt->close();

        // Find the earliest end date among active reservations
        $resStmt = $this->db->prepare("
            SELECT MIN(COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione), data_inizio_richiesta)) as earliest_end
            FROM prenotazioni
            WHERE libro_id = ? AND stato = 'attiva'
            AND data_inizio_richiesta IS NOT NULL
            AND COALESCE(data_fine_richiesta, DATE(data_scadenza_prenotazione), data_inizio_richiesta) >= ?
        ");
        $resStmt->bind_param('is', $bookId, $today);
        $resStmt->execute();
        $resResult = $resStmt->get_result()->fetch_assoc();
        $earliestResEnd = $resResult['earliest_end'] ?? null;
        $resStmt->close();

        // Return the earliest of the two (the day after it ends, the book becomes available)
        $dates = array_filter([$earliestLoanEnd, $earliestResEnd]);
        if (empty($dates)) {
            return null;
        }

        $earliestEnd = min($dates);
        // Return the day after the earliest end date
        return date('Y-m-d', strtotime($earliestEnd . ' +1 day'));
    }

    /**
     * Aggiunge colonne per tracking notifiche se non esistono
     */
    private function addNotificationColumns(): void {
        try {
            // Check if columns exist
            $result = $this->db->query("SHOW COLUMNS FROM prestiti LIKE 'warning_sent'");
            if ($result->num_rows === 0) {
                $this->db->query("ALTER TABLE prestiti ADD COLUMN warning_sent BOOLEAN DEFAULT 0");
            }

            $result = $this->db->query("SHOW COLUMNS FROM prestiti LIKE 'overdue_notification_sent'");
            if ($result->num_rows === 0) {
                $this->db->query("ALTER TABLE prestiti ADD COLUMN overdue_notification_sent BOOLEAN DEFAULT 0");
            }

        } catch (Exception $e) {
            error_log("Failed to add notification columns: " . $e->getMessage());
        }
    }

    /**
     * Aggiunge colonna per tracking notifiche wishlist
     */
    private function addWishlistNotificationColumn(): void {
        try {
            $result = $this->db->query("SHOW COLUMNS FROM wishlist LIKE 'notified'");
            if ($result->num_rows === 0) {
                $this->db->query("ALTER TABLE wishlist ADD COLUMN notified BOOLEAN DEFAULT 0");
            }

        } catch (Exception $e) {
            error_log("Failed to add wishlist notification column: " . $e->getMessage());
        }
    }

    /**
     * Invia template agli admin
     */
    private function sendToAdmins(string $templateName, array $variables): bool {
        try {
            $result = $this->db->query("SELECT email FROM utenti WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'");

            if (!$result || $result->num_rows === 0) {
                error_log("No active admin/staff users found for notification");
                return false;
            }

            // Use installation locale for email template
            $locale = \App\Support\I18n::getInstallationLocale();

            $sentCount = 0;
            while ($row = $result->fetch_assoc()) {
                if ($this->emailService->sendTemplate($row['email'], $templateName, $variables, $locale)) {
                    $sentCount++;
                }
            }

            error_log("Sent notification to $sentCount admins");
            return $sentCount > 0;

        } catch (Exception $e) {
            error_log("Failed to send to admins: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di approvazione prestito all'utente
     */
    public function sendLoanApprovedNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Calculate number of days
            $startDate = new \DateTime($loan['data_prestito']);
            $endDate = new \DateTime($loan['data_scadenza']);
            $days = $endDate->diff($startDate)->days;

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_inizio' => $this->formatEmailDate($loan['data_prestito']),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza']),
                'giorni_prestito' => $days,
                'pickup_instructions' => 'Recati in biblioteca durante gli orari di apertura per ritirare il libro.'
            ];

            return $this->emailService->sendTemplate($loan['utente_email'], 'loan_approved', $variables);

        } catch (Exception $e) {
            error_log("Failed to send loan approved notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di rifiuto prestito all'utente
     */
    public function sendLoanRejectedNotification(int $loanId, string $reason = ''): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'motivo_rifiuto' => $reason ?: __('Nessun motivo specificato')
            ];

            return $this->emailService->sendTemplate($loan['utente_email'], 'loan_rejected', $variables);

        } catch (Exception $e) {
            error_log("Failed to send loan rejected notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di rifiuto prestito con dati pre-caricati (per quando il prestito è già eliminato)
     *
     * @param string $userEmail Email dell'utente
     * @param string $userName Nome completo dell'utente
     * @param string $bookTitle Titolo del libro
     * @param string $reason Motivo del rifiuto
     * @return bool True se l'email è stata inviata
     */
    public function sendLoanRejectedNotificationDirect(
        string $userEmail,
        string $userName,
        string $bookTitle,
        string $reason = ''
    ): bool {
        try {
            $variables = [
                'utente_nome' => $userName,
                'libro_titolo' => $bookTitle,
                'motivo_rifiuto' => $reason ?: __('Nessun motivo specificato')
            ];

            return $this->emailService->sendTemplate($userEmail, 'loan_rejected', $variables);

        } catch (Exception $e) {
            error_log("Failed to send loan rejected notification (direct): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica quando un prestito è pronto per il ritiro (stato da_ritirare)
     */
    public function sendPickupReadyNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Calculate number of days for loan
            $startDate = new \DateTime($loan['data_prestito']);
            $endDate = new \DateTime($loan['data_scadenza']);
            $days = $endDate->diff($startDate)->days;

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_inizio' => $this->formatEmailDate($loan['data_prestito']),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza']),
                'giorni_prestito' => $days,
                'scadenza_ritiro' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline']) : '',
                'pickup_instructions' => __('Recati in biblioteca durante gli orari di apertura per ritirare il libro.')
            ];

            return $this->emailService->sendTemplate($loan['utente_email'], 'loan_pickup_ready', $variables);

        } catch (Exception $e) {
            error_log("Failed to send pickup ready notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica quando la scadenza del ritiro è passata (prestito scaduto)
     */
    public function sendPickupExpiredNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'scadenza_ritiro' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline']) : ''
            ];

            return $this->emailService->sendTemplate($loan['utente_email'], 'loan_pickup_expired', $variables);

        } catch (Exception $e) {
            error_log("Failed to send pickup expired notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica quando un ritiro viene annullato dall'admin
     */
    public function sendPickupCancelledNotification(int $loanId, string $reason = ''): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$loan = $result->fetch_assoc()) {
                $stmt->close();
                return false;
            }
            $stmt->close();

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'motivo' => $reason ?: __('Ritiro non effettuato entro la scadenza')
            ];

            return $this->emailService->sendTemplate($loan['utente_email'], 'loan_pickup_cancelled', $variables);

        } catch (Exception $e) {
            error_log("Failed to send pickup cancelled notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send reservation book available notification
     */
    public function sendReservationBookAvailable(string $email, array $variables): bool {
        return $this->emailService->sendTemplate($email, 'reservation_book_available', $variables);
    }

    /**
     * Send email with retry mechanism for transient SMTP errors
     * @param string $email Recipient email
     * @param string $template Template name
     * @param array $variables Template variables
     * @param int $maxRetries Maximum retry attempts (default: 3)
     * @param int $retryDelayMs Delay between retries in milliseconds (default: 1000)
     * @return bool True if email was sent successfully
     */
    private function sendWithRetry(string $email, string $template, array $variables, int $maxRetries = 3, int $retryDelayMs = 1000): bool {
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($this->emailService->sendTemplate($email, $template, $variables)) {
                    if ($attempt > 1) {
                        error_log("Email to {$email} succeeded on attempt {$attempt}");
                    }
                    return true;
                }
                $lastError = 'sendTemplate returned false';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                error_log("Email attempt {$attempt}/{$maxRetries} to {$email} failed: {$lastError}");
            }

            // Don't delay after the last attempt
            if ($attempt < $maxRetries) {
                usleep($retryDelayMs * 1000); // Convert ms to microseconds
            }
        }

        error_log("Email to {$email} failed after {$maxRetries} attempts. Last error: {$lastError}");
        return false;
    }

    /**
     * Get base URL
     *
     * @throws \RuntimeException If unable to determine base URL
     */
    private function getBaseUrl(): string {
        // PRIORITY 1: Use APP_CANONICAL_URL from .env if configured
        // This ensures emails always use the production URL even when sent from CLI/localhost
        // This is the recommended approach for production environments
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        if ($canonicalUrl !== false) {
            $canonicalUrl = trim((string)$canonicalUrl);
            if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL)) {
                return rtrim($canonicalUrl, '/');
            }
        }

        // PRIORITY 2: Fallback to HTTP_HOST with security validation
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? null;

        // Validate hostname format to prevent Host Header Injection attacks
        // Accepts: domain.com, subdomain.domain.com, localhost, localhost:8000, IP:port
        if ($host !== null && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*(:[0-9]{1,5})?$/', $host)) {
            return $protocol . '://' . $host;
        }

        // CRITICAL: Cannot determine base URL - configuration required
        // This prevents the application from using hardcoded localhost on production
        throw new \RuntimeException(
            'Cannot determine application base URL. ' .
            'Please configure APP_CANONICAL_URL in your .env file. ' .
            'Example: APP_CANONICAL_URL=https://yourdomain.com'
        );
    }

    /**
     * ========================================
     * IN-APP NOTIFICATIONS METHODS
     * ========================================
     */

    /**
     * Create an in-app notification
     */
    public function createNotification(
        string $type,
        string $title,
        string $message,
        ?string $link = null,
        ?int $relatedId = null
    ): bool {
        $allowedTypes = ['new_message', 'new_reservation', 'new_user', 'overdue_loan', 'new_loan_request', 'new_review', 'general'];

        if (!in_array($type, $allowedTypes, true)) {
            error_log("Invalid notification type: $type");
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO admin_notifications (type, title, message, link, related_id)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Failed to prepare notification insert: " . $this->db->error);
            return false;
        }

        $stmt->bind_param('ssssi', $type, $title, $message, $link, $relatedId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadCount(): int
    {
        $result = $this->db->query("SELECT COUNT(*) as count FROM admin_notifications WHERE is_read = 0");

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return (int)($row['count'] ?? 0);
    }

    /**
     * Get recent notifications
     */
    public function getRecentNotifications(int $limit = 10, bool $unreadOnly = false): array
    {
        $limit = max(1, min(100, $limit));

        $sql = "SELECT id, type, title, message, link, related_id, is_read, created_at
                FROM admin_notifications ";

        if ($unreadOnly) {
            $sql .= "WHERE is_read = 0 ";
        }

        $sql .= "ORDER BY created_at DESC LIMIT ?";

        $stmt = $this->db->prepare($sql);

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => (int)$row['id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'message' => $row['message'],
                'link' => $row['link'],
                'related_id' => $row['related_id'] ? (int)$row['related_id'] : null,
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'relative_time' => $this->formatRelativeTime($row['created_at']),
            ];
        }

        $result->free();
        $stmt->close();

        return $notifications;
    }

    private function formatRelativeTime(?string $timestamp): string
    {
        if (empty($timestamp)) {
            return '';
        }

        try {
            $date = new \DateTime($timestamp);
        } catch (\Exception $e) {
            return '';
        }

        $now = new \DateTime('now', $date->getTimezone());
        $diffSeconds = $now->getTimestamp() - $date->getTimestamp();

        if ($diffSeconds < 60) {
            return __('Adesso');
        }

        if ($diffSeconds < 3600) {
            $minutes = max(1, (int)floor($diffSeconds / 60));
            return __n('%d minuto fa', '%d minuti fa', $minutes, $minutes);
        }

        if ($diffSeconds < 86400) {
            $hours = max(1, (int)floor($diffSeconds / 3600));
            return __n('%d ora fa', '%d ore fa', $hours, $hours);
        }

        if ($diffSeconds < 172800) {
            return __('Ieri');
        }

        // Fallback to formatted date (uses session locale)
        return format_date($date->format('Y-m-d H:i:s'), true, '/');
    }

    /**
     * Mark notification as read
     */
    public function markNotificationAsRead(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Mark all notifications as read
     */
    public function markAllNotificationsAsRead(): bool
    {
        return $this->db->query("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0") !== false;
    }

    /**
     * Delete notification
     */
    public function deleteNotification(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM admin_notifications WHERE id = ?");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Helper: Create notification for new contact message
     */
    public function notifyNewContactMessage(int $messageId, string $senderName, string $senderEmail): bool
    {
        return $this->createNotification(
            'new_message',
            __('Nuovo messaggio di contatto'),
            sprintf(__('Da %s (%s)'), $senderName, $senderEmail),
            '/admin/settings?tab=messages',
            $messageId
        );
    }

    /**
     * Helper: Create notification for new user registration
     */
    public function notifyNewUserInApp(int $userId, string $username, string $email): bool
    {
        return $this->createNotification(
            'new_user',
            __('Nuova registrazione utente'),
            sprintf(__('Utente %s (%s) si è registrato'), $username, $email),
            '/admin/utenti',
            $userId
        );
    }

    /**
     * Helper: Create notification for new reservation
     */
    public function notifyNewReservationInApp(int $reservationId, string $username, string $bookTitle): bool
    {
        return $this->createNotification(
            'new_reservation',
            __('Nuova prenotazione'),
            sprintf(__('%s ha prenotato "%s"'), $username, $bookTitle),
            '/admin/prenotazioni',
            $reservationId
        );
    }

    /**
     * Helper: Create notification for overdue loan
     */
    public function notifyOverdueLoanInApp(int $loanId, string $username, string $bookTitle, int $daysOverdue): bool
    {
        return $this->createNotification(
            'overdue_loan',
            __('Prestito in ritardo'),
            sprintf(__('"%s" prestato a %s è in ritardo di %d giorni'), $bookTitle, $username, $daysOverdue),
            '/admin/prestiti',
            $loanId
        );
    }

    /**
     * Notifica admin per nuova recensione
     */
    public function notifyNewReview(int $reviewId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM recensioni r
                JOIN libri l ON r.libro_id = l.id
                JOIN utenti u ON r.utente_id = u.id
                WHERE r.id = ?
            ");
            $stmt->bind_param('i', $reviewId);
            $stmt->execute();
            $result = $stmt->get_result();

            if (!$review = $result->fetch_assoc()) {
                return false;
            }
            $stmt->close();

            $variables = [
                'libro_titolo' => $review['libro_titolo'],
                'utente_nome' => $review['utente_nome'],
                'utente_email' => $review['utente_email'],
                'stelle' => $review['stelle'],
                'titolo_recensione' => $review['titolo'] ?? '',
                'descrizione_recensione' => $review['descrizione'] ?? '',
                'data_recensione' => $this->formatEmailDate($review['created_at'], true),
                'link_approvazione' => $this->getBaseUrl() . '/admin/recensioni'
            ];

            // Send email to admins
            $emailSent = $this->sendToAdmins('admin_new_review', $variables);

            // Create in-app notification
            $stelle_text = str_repeat('⭐', (int)$review['stelle']);
            $notificationTitle = __('Nuova recensione da approvare');
            $notificationMessage = sprintf(
                __('Recensione per "%s" da %s - %s'),
                $review['libro_titolo'],
                $review['utente_nome'],
                $stelle_text
            );
            $notificationLink = '/admin/recensioni';

            $this->createNotification(
                'new_review',
                $notificationTitle,
                $notificationMessage,
                $notificationLink,
                $reviewId
            );

            return $emailSent;

        } catch (Exception $e) {
            error_log("Failed to notify new review: " . $e->getMessage());
            return false;
        }
    }
}
