<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\RouteTranslator;
use App\Support\SettingsMailTemplates;

class NotificationService {
    private mysqli $db;
    private EmailService $emailService;

    /** #360: per-request cache of recipient email -> resolved email locale. */
    private array $recipientLocaleCache = [];

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->emailService = new EmailService($db);
    }

    /**
     * Format date for email templates.
     *
     * @param string $dateString Date string parseable by strtotime
     * @param bool $includeTime Include time (H:i) in output
     * @param string|null $locale Locale driving the date format; null keeps the
     *                            historical behaviour (installation locale)
     * @return string Formatted date
     */
    private function formatEmailDate(string $dateString, bool $includeTime = false, ?string $locale = null): string
    {
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return $dateString;
        }

        $locale = $locale ?? I18n::getInstallationLocale();
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
     * #360: user-facing emails render in the recipient's preferred language.
     *
     * Resolves utenti.locale for the given address when it is a locale this
     * installation actually ships, falling back to the installation locale —
     * the historical behaviour — for empty, unknown or unsupported values and
     * for addresses that don't belong to a user. Trusting the column mirrors
     * the app itself: the same value already drives the recipient's UI
     * language (login, profile, language switcher keep it up to date).
     */
    private function resolveRecipientLocale(string $email): string
    {
        $email = trim($email);
        $fallback = I18n::getInstallationLocale();
        if ($email === '') {
            return $fallback;
        }
        if (isset($this->recipientLocaleCache[$email])) {
            return $this->recipientLocaleCache[$email];
        }

        $locale = $fallback;
        try {
            $stmt = $this->db->prepare("SELECT locale FROM utenti WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $raw = trim((string) ($row['locale'] ?? ''));
            if ($raw !== '' && isset(I18n::getAvailableLocales()[$raw])) {
                $locale = $raw;
            }
        } catch (\Throwable $e) {
            // Lookup failure must never block the send: keep the fallback.
        }

        return $this->recipientLocaleCache[$email] = $locale;
    }

    /**
     * Resolve a __()-translated label in the given locale without leaking the
     * switch to the caller's session (used for per-recipient email wording).
     */
    private function translateInLocale(string $message, string $locale): string
    {
        $sessionLocale = I18n::getLocale();
        I18n::setLocale($locale);
        // finally: a throw inside __() must never leave the process-wide locale
        // switched — the automatic-notification cron translates for many
        // recipients in one run, and a leaked locale would mistranslate every
        // subsequent one.
        try {
            return __($message);
        } finally {
            I18n::setLocale($sessionLocale);
        }
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
                'admin_users_url' => absoluteUrl('/admin/users')
            ];

            // Send to admins
            return $this->sendToAdmins('admin_new_registration', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to notify new user registration: " . $e->getMessage());
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

            // #360: recipient's preferred language (registration stores the
            // registrant's session locale into utenti.locale, so this matches
            // the language they signed up in).
            $locale = $this->resolveRecipientLocale((string) $user['email']);

            $verifySection = '';
            if (!empty($user['token_verifica_email'])) {
                // Temporarily switch locale for button translation
                $currentLocale = \App\Support\I18n::getLocale();
                \App\Support\I18n::setLocale($locale);

                $verifyUrl = absoluteUrl(RouteTranslator::route('verify_email') . '?token=' . urlencode((string)$user['token_verifica_email']));
                $buttonText = __('Conferma la tua email');
                $verifySection = '<p style="margin: 20px 0;"><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a></p>';

                // Restore original locale
                \App\Support\I18n::setLocale($currentLocale);
            }

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'data_registrazione' => $this->formatEmailDate($user['created_at'], true, $locale),
                'sezione_verifica' => $verifySection,
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];
            $template = (bool) ConfigStore::get('registration.require_admin_approval', true)
                ? 'user_registration_pending'
                : 'user_registration_verification';
            return $this->emailService->sendTemplate($user['email'], $template, $variables, $locale);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send user registration pending email: " . $e->getMessage());
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
                'login_url' => absoluteUrl(RouteTranslator::route('login'))
            ];

            // #360: recipient's preferred language (installation locale fallback)
            $locale = $this->resolveRecipientLocale((string) $user['email']);
            return $this->emailService->sendTemplate($user['email'], 'user_account_approved', $variables, $locale);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send user account approved email: " . $e->getMessage());
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

            $verificationUrl = absoluteUrl(RouteTranslator::route('verify_email') . '?token=' . urlencode($token));

            $variables = [
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'email' => $user['email'],
                'codice_tessera' => $user['codice_tessera'],
                'verification_url' => $verificationUrl,
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];

            // #360: recipient's preferred language (installation locale fallback)
            $locale = $this->resolveRecipientLocale((string) $user['email']);
            return $this->emailService->sendTemplate($user['email'], 'user_activation_with_verification', $variables, $locale);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send user activation with verification email: " . $e->getMessage());
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
                'reset_url' => absoluteUrl(RouteTranslator::route('reset_password') . '?token=' . urlencode((string)$token)),
                'app_name' => ConfigStore::get('app.name', 'Biblioteca')
            ];

            // #360: recipient's preferred language (installation locale fallback)
            $locale = $this->resolveRecipientLocale((string) $user['email']);
            return $this->emailService->sendTemplate($user['email'], 'user_password_setup', $variables, $locale);

        } catch (\Throwable $e) {
            SecureLogger::error('Failed to send user password setup email: ' . $e->getMessage());
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
                'reset_url' => absoluteUrl(RouteTranslator::route('reset_password') . '?token=' . urlencode((string)$token)),
                'dashboard_url' => absoluteUrl('/admin/dashboard')
            ];

            // #360: recipient's preferred language (installation locale fallback)
            $locale = $this->resolveRecipientLocale((string) $user['email']);
            return $this->emailService->sendTemplate($user['email'], 'admin_invitation', $variables, $locale);

        } catch (\Throwable $e) {
            SecureLogger::error('Failed to send admin invitation: ' . $e->getMessage());
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
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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
                'approve_url' => absoluteUrl('/admin/loans/pending')
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
            $notificationLink = '/admin/loans';

            $this->createNotification(
                'new_loan_request',
                $notificationTitle,
                $notificationMessage,
                $notificationLink,
                $loanId
            );

            return $emailSent;

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to notify loan request: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia avvisi per prestiti in scadenza entro la finestra configurata.
     * Uses atomic mark-then-send pattern to prevent duplicate notifications
     */
    public function sendLoanExpirationWarnings(): int {
        $sentCount = 0;

        try {
            // Get configured days before expiry warning (default: 3)
            $daysBeforeWarning = max(0, (int)ConfigStore::get('advanced.days_before_expiry_warning', 3));

            // "Oggi" nel timezone applicativo come parametro bound (M9): CURDATE()
            // dipende dalla session timezone del client DB, che differiva tra cron
            // (UTC forzato) e web (nessuna impostazione).
            $today = DateHelper::today();

            // Include every still-unnotified loan from today through the configured
            // warning horizon. An exact "today + X" match misses short loans created
            // inside that horizon (especially loans created with due date today).
            // Overdue loans remain handled separately below by the overdue workflow.
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(p.data_scadenza, ?) as giorni_rimasti
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato = 'in_corso'
                  AND p.attivo = 1
                  AND p.data_scadenza BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
                  AND (p.warning_sent IS NULL OR p.warning_sent = 0)
            ");
            $stmt->bind_param('sssi', $today, $today, $today, $daysBeforeWarning);
            $stmt->execute();
            $result = $stmt->get_result();

            // Collect all loans first to avoid result set issues
            $loans = [];
            while ($loan = $result->fetch_assoc()) {
                $loans[] = $loan;
            }
            $stmt->close();

            // #360: the email renders in each recipient's language (see
            // sendWithRetry), so the "oggi" label must match that locale too —
            // resolved per recipient locale, cached per batch. Not the caller's
            // session locale (this runs from the admin-login maintenance path
            // as well as cron).
            $todayLabels = [];

            foreach ($loans as $loan) {
                $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);
                $todayLabels[$recipientLocale] ??= $this->translateInLocale('oggi', $recipientLocale);
                // ATOMIC: Mark warning as sent BEFORE sending email
                // Only proceed if we successfully claimed this loan (affected_rows == 1)
                // Re-assert data_scadenza too: renew()/update() may have moved the
                // due date between the SELECT and this claim; without it we'd send a
                // warning quoting a now-stale date (#252).
                $updateStmt = $this->db->prepare("UPDATE prestiti SET warning_sent = 1 WHERE id = ? AND attivo = 1 AND stato = 'in_corso' AND data_scadenza = ? AND (warning_sent IS NULL OR warning_sent = 0)");
                $updateStmt->bind_param('is', $loan['id'], $loan['data_scadenza']);
                $updateStmt->execute();
                $claimed = $updateStmt->affected_rows === 1;
                $updateStmt->close();

                if (!$claimed) {
                    // Another process already claimed this loan, skip
                    continue;
                }

                // "Giorni rimasti: 0" reads wrong in the email — show "oggi" for a
                // due-today loan, the number of days otherwise (matches the in-app
                // notification wording below).
                $daysRemaining = (int)$loan['giorni_rimasti'];
                $variables = [
                    'utente_nome' => $loan['utente_nome'],
                    'libro_titolo' => $loan['libro_titolo'],
                    'data_scadenza' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                    'giorni_rimasti' => $daysRemaining === 0 ? $todayLabels[$recipientLocale] : (string)$daysRemaining
                ];

                $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_expiring_warning', $variables);

                if ($emailSent) {
                    // Create in-app notification for expiring loan
                    $notificationMessage = $daysRemaining === 0
                        ? sprintf(__('"%s" prestato a %s scade oggi'), $loan['libro_titolo'], $loan['utente_nome'])
                        : sprintf(__('"%s" prestato a %s scade fra %d giorni'), $loan['libro_titolo'], $loan['utente_nome'], $daysRemaining);
                    $this->createNotification(
                        'general',
                        __('Prestito in scadenza'),
                        $notificationMessage,
                        '/admin/loans',
                        (int)$loan['id']
                    );
                    $sentCount++;
                } else {
                    // Email failed after retries, revert the flag so it can be retried next run
                    $revertStmt = $this->db->prepare("UPDATE prestiti SET warning_sent = 0 WHERE id = ? AND attivo = 1 AND stato = 'in_corso'");
                    $revertStmt->bind_param('i', $loan['id']);
                    $revertStmt->execute();
                    $revertStmt->close();
                    SecureLogger::warning("Failed to send expiration warning for loan {$loan['id']} after retries, flag reverted");
                }
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan expiration warnings: " . $e->getMessage());
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
            // "Oggi" nel timezone applicativo come parametro bound (M9): CURDATE()
            // dipende dalla session timezone del client DB, che differiva tra cron
            // (UTC forzato) e web (nessuna impostazione).
            $today = DateHelper::today();

            // Get overdue loans
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato IN ('in_corso', 'in_ritardo')
                  AND p.attivo = 1
                  AND p.data_scadenza < ?
                  AND (p.overdue_notification_sent IS NULL OR p.overdue_notification_sent = 0)
            ");
            $stmt->bind_param('ss', $today, $today);
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
                // Only proceed if we successfully claimed this loan (affected_rows == 1).
                // Guardia di stato (M3): il loop di invio (retry SMTP con sleep) può
                // durare minuti dopo la SELECT — senza il filtro su attivo/stato il
                // claim riporterebbe in 'in_ritardo' un prestito restituito nel
                // frattempo (attivo=0 + stato in_ritardo: combinazione invalida).
                // Re-assert data_scadenza (#252): a renew() between the SELECT and this
                // claim can push the due date into the future, so the loan is no longer
                // overdue and must NOT be transitioned to 'in_ritardo' with a stale date.
                $updateStmt = $this->db->prepare("UPDATE prestiti SET overdue_notification_sent = 1, stato = 'in_ritardo' WHERE id = ? AND data_scadenza = ? AND (overdue_notification_sent IS NULL OR overdue_notification_sent = 0) AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')");
                $updateStmt->bind_param('is', $loan['id'], $loan['data_scadenza']);
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
                    'data_scadenza' => $this->formatEmailDate($loan['data_scadenza'], false, $this->resolveRecipientLocale((string) $loan['utente_email'])),
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
                    // Email failed after retries: revert ONLY the flag so the next run
                    // retries the send. Lo stato resta 'in_ritardo' (il prestito è
                    // genuinamente in ritardo) e la guardia di stato evita di toccare
                    // un prestito restituito nel frattempo (M3).
                    $revertStmt = $this->db->prepare("UPDATE prestiti SET overdue_notification_sent = 0 WHERE id = ? AND attivo = 1 AND stato = 'in_ritardo'");
                    $revertStmt->bind_param('i', $loan['id']);
                    $revertStmt->execute();
                    $revertStmt->close();
                    SecureLogger::warning("Failed to send overdue notification for loan {$loan['id']} after retries, flag reverted");
                }
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send overdue loan notifications: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * #360: automatic recalls (solleciti) for overdue loans.
     *
     * Unlike sendOverdueLoanNotifications() — one-shot via the boolean
     * overdue_notification_sent — recalls repeat: recall N is due once the loan
     * is at least N * interval days overdue (interval and max count come from
     * the loans settings), so a loan that stays out keeps being chased up to
     * loans.recall_max_count times. Uses the same atomic claim-then-send
     * pattern as the other senders; the DATE(last_recall_at) guard caps sends
     * at one per loan per day even if the schedule would allow more.
     */
    public function sendLoanRecalls(): int {
        $sentCount = 0;

        try {
            // Read the recall schedule the same way SettingsController writes and
            // reads it — via SettingsRepository. ConfigStore can NOT serve loans
            // keys: loadDatabaseSettings() has no 'loans' category mapping, so it
            // always returns the code default (recall_auto_enabled would resolve
            // to '0' regardless of the admin toggle, making this a permanent
            // no-op). Every other loans setting is read via SettingsRepository
            // for exactly this reason.
            $settings = new SettingsRepository($this->db);
            if ((string) ($settings->get('loans', 'recall_auto_enabled', '0') ?? '0') !== '1') {
                return 0;
            }
            // Same clamps as SettingsController::updateLoansSettings — the
            // stored value is trusted but a hand-edited row must not produce a
            // zero/negative interval (division-like schedule) or a runaway cap.
            $intervalDays = min(365, max(1, (int) ($settings->get('loans', 'recall_interval_days', '7') ?? 7)));
            $maxRecalls = min(50, max(1, (int) ($settings->get('loans', 'recall_max_count', '3') ?? 3)));

            // "Oggi" nel timezone applicativo come parametro bound (M9).
            $today = DateHelper::today();

            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.recall_count, p.last_recall_at,
                       l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato IN ('in_corso', 'in_ritardo')
                  AND p.attivo = 1
                  AND p.data_scadenza < ?
                  AND p.overdue_notification_sent = 1
                  AND p.recall_count < ?
                  AND DATEDIFF(?, p.data_scadenza) >= ? * (p.recall_count + 1)
                  AND (p.last_recall_at IS NULL OR DATE(p.last_recall_at) < ?)
            ");
            $stmt->bind_param('ssisis', $today, $today, $maxRecalls, $today, $intervalDays, $today);
            $stmt->execute();
            $result = $stmt->get_result();

            $loans = [];
            while ($loan = $result->fetch_assoc()) {
                $loans[] = $loan;
            }
            $stmt->close();

            foreach ($loans as $loan) {
                $sentCount += $this->claimAndSendRecall($loan, $today) ? 1 : 0;
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan recalls: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * #360: manual recall for a single loan, triggered by staff from the loan
     * detail page or the loans-list bulk action. Skips the automatic schedule
     * (interval / max count): an explicit staff action always sends — but the
     * loan must genuinely be overdue and the user must have an email address.
     *
     * @return array{success: bool, message: string}
     */
    public function sendManualRecall(int $loanId): array {
        try {
            $this->addNotificationColumns();

            $today = DateHelper::today();
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.recall_count, p.last_recall_at,
                       p.stato, p.attivo,
                       l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.id = ?
            ");
            $stmt->bind_param('si', $today, $loanId);
            $stmt->execute();
            $loan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                return ['success' => false, 'message' => __('Prestito non trovato')];
            }
            if ((int) $loan['attivo'] !== 1 || !in_array((string) $loan['stato'], ['in_corso', 'in_ritardo'], true)) {
                return ['success' => false, 'message' => __('Il sollecito è disponibile solo per prestiti attivi con libro in mano all\'utente.')];
            }
            if ((int) $loan['giorni_ritardo'] < 1) {
                return ['success' => false, 'message' => __('Il prestito non è scaduto: nessun sollecito da inviare.')];
            }
            if (trim((string) $loan['utente_email']) === '') {
                return ['success' => false, 'message' => __('L\'utente non ha un indirizzo email.')];
            }

            if ($this->claimAndSendRecall($loan, $today)) {
                return ['success' => true, 'message' => __('Sollecito inviato con successo.')];
            }
            return ['success' => false, 'message' => __('Invio del sollecito non riuscito. Controlla la configurazione email e riprova.')];

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send manual recall for loan {$loanId}: " . $e->getMessage());
            return ['success' => false, 'message' => __('Invio del sollecito non riuscito. Controlla la configurazione email e riprova.')];
        }
    }

    /**
     * Shared claim-then-send for one recall (automatic and manual paths).
     * Expects a row carrying id, data_scadenza, recall_count, last_recall_at,
     * libro_titolo, utente_nome, utente_email, giorni_ritardo. Returns true iff
     * the recall email actually went out; on failure the claim is reverted so a
     * later run can retry.
     */
    private function claimAndSendRecall(array $loan, string $today): bool {
        // ATOMIC: bump the counter BEFORE sending. Re-assert data_scadenza and
        // recall_count so a concurrent renew()/recall claims at most once
        // (same #252/M3 rationale as the overdue sender).
        $now = DateHelper::now();
        $expectedCount = (int) $loan['recall_count'];
        $updateStmt = $this->db->prepare("UPDATE prestiti SET recall_count = recall_count + 1, last_recall_at = ? WHERE id = ? AND data_scadenza = ? AND recall_count = ? AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')");
        $updateStmt->bind_param('sisi', $now, $loan['id'], $loan['data_scadenza'], $expectedCount);
        $updateStmt->execute();
        $claimed = $updateStmt->affected_rows === 1;
        $updateStmt->close();

        if (!$claimed) {
            return false;
        }

        $recallNumber = $expectedCount + 1;
        $variables = [
            'utente_nome' => $loan['utente_nome'],
            'libro_titolo' => $loan['libro_titolo'],
            'data_scadenza' => $this->formatEmailDate($loan['data_scadenza'], false, $this->resolveRecipientLocale((string) $loan['utente_email'])),
            'giorni_ritardo' => $loan['giorni_ritardo'],
            'numero_sollecito' => $recallNumber,
        ];

        $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_recall_notification', $variables);

        if ($emailSent) {
            $this->createNotification(
                'general',
                __('Sollecito inviato'),
                sprintf(__('Sollecito n. %d inviato a %s per "%s"'), $recallNumber, $loan['utente_nome'], $loan['libro_titolo']),
                '/admin/loans',
                (int) $loan['id']
            );
            return true;
        }

        // Email failed after retries: restore counter and timestamp so the next
        // run (or a retried manual send) claims the same recall again.
        $previousRecallAt = $loan['last_recall_at'] !== null ? (string) $loan['last_recall_at'] : null;
        $revertStmt = $this->db->prepare("UPDATE prestiti SET recall_count = recall_count - 1, last_recall_at = ? WHERE id = ? AND recall_count = ? AND attivo = 1 AND stato IN ('in_corso', 'in_ritardo')");
        $revertStmt->bind_param('sii', $previousRecallAt, $loan['id'], $recallNumber);
        $revertStmt->execute();
        $revertStmt->close();
        SecureLogger::warning("Failed to send recall for loan {$loan['id']} after retries, claim reverted");
        return false;
    }

    /**
     * #360: email the loan receipt PDF (the same document downloadPdf serves)
     * to the loan's user, attached to the loan_receipt_email template.
     *
     * @return array{success: bool, message: string}
     */
    public function sendLoanReceiptEmail(int $loanId): array {
        try {
            $repo = new \App\Models\LoanRepository($this->db);
            $loan = $repo->getById($loanId);
            if (!$loan) {
                return ['success' => false, 'message' => __('Prestito non trovato')];
            }
            $email = trim((string) ($loan['utente_email'] ?? ''));
            if ($email === '') {
                return ['success' => false, 'message' => __('L\'utente non ha un indirizzo email.')];
            }

            // Same circuit breaker as sendWithRetry, checked BEFORE generating
            // the PDF: when SMTP is plainly unreachable the attachment would be
            // wasted work on a synchronous request path.
            if (!\App\Support\Mailer::isSmtpReachable()) {
                return ['success' => false, 'message' => __('Invio email non riuscito. Controlla la configurazione email e riprova.')];
            }

            $pdfContent = (new LoanPdfGenerator($this->db))->generate($loanId);

            // #360: cover message in the recipient's language, like every other
            // user-facing email.
            $recipientLocale = $this->resolveRecipientLocale($email);

            $variables = [
                'prestito_id' => $loanId,
                'utente_nome' => (string) ($loan['utente'] ?? ''),
                'libro_titolo' => (string) ($loan['libro'] ?? ''),
                'data_prestito' => $this->formatEmailDate((string) ($loan['data_prestito'] ?? ''), false, $recipientLocale),
                'data_scadenza' => $this->formatEmailDate((string) ($loan['data_scadenza'] ?? ''), false, $recipientLocale),
            ];
            $attachment = [
                'content' => $pdfContent,
                'filename' => 'prestito_' . $loanId . '_' . date('Ymd') . '.pdf',
                'type' => 'application/pdf',
            ];

            $sent = $this->emailService->sendTemplate(
                $email,
                'loan_receipt_email',
                $variables,
                $recipientLocale,
                [$attachment]
            );

            if ($sent) {
                return ['success' => true, 'message' => __('Ricevuta inviata via email con successo.')];
            }
            return ['success' => false, 'message' => __('Invio email non riuscito. Controlla la configurazione email e riprova.')];

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to email loan receipt for loan {$loanId}: " . $e->getMessage());
            return ['success' => false, 'message' => __('Invio email non riuscito. Controlla la configurazione email e riprova.')];
        }
    }

    public function notifyAdminsOverdue(int $loanId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.data_prestito, l.titolo AS libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome, u.email AS utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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
                'data_scadenza' => $this->formatEmailDate($loan['data_scadenza']),
                'data_prestito' => $this->formatEmailDate($loan['data_prestito']),
            ];

            // Usa sendToAdmins (template dal DB email_templates con fallback) come tutti
            // gli altri invii: così la personalizzazione admin del template viene
            // rispettata, a differenza del precedente SettingsMailTemplates::get() che
            // leggeva solo il PHP hardcoded (GAP-4).
            $this->sendToAdmins('loan_overdue_admin', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error('Failed to notify admins about overdue loan: ' . $e->getMessage());
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
                       GROUP_CONCAT(" . \App\Support\AuthorName::displaySql('a') . " ORDER BY la.ruolo='principale' DESC, la.ordine_credito SEPARATOR ', ') AS autore
                FROM wishlist w
                JOIN utenti u ON w.utente_id = u.id
                JOIN libri l ON w.libro_id = l.id AND l.deleted_at IS NULL
                LEFT JOIN libri_autori la ON l.id = la.libro_id AND la.ruolo IN ('principale', 'co-autore')
                LEFT JOIN autori a ON la.autore_id = a.id
                WHERE w.libro_id = ?
                  AND u.stato = 'attivo'
                  AND w.notified = 0
                  AND l.copie_disponibili > 0
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
                    'data_disponibilita' => $this->formatEmailDate('now', true, $this->resolveRecipientLocale((string) $wishlist['email'])),
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
                    SecureLogger::warning("Failed to send wishlist notification for entry {$wishlist['wishlist_id']} after retries, flag reverted");
                }
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to notify wishlist book availability: " . $e->getMessage());
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
            'loan_recalls' => 0,
            'wishlist_notifications' => 0,
            'errors' => []
        ];

        try {
            // Add notification columns if they don't exist
            $this->addNotificationColumns();
            $this->addWishlistNotificationColumn();

            $results['expiration_warnings'] = $this->sendLoanExpirationWarnings();
            $results['overdue_notifications'] = $this->sendOverdueLoanNotifications();
            // #360: repeated recalls come after the first overdue notice — the
            // recall query requires overdue_notification_sent = 1.
            $results['loan_recalls'] = $this->sendLoanRecalls();
            $results['wishlist_notifications'] = $this->checkAndNotifyWishlistAvailability();

        } catch (\Throwable $e) {
            $results['errors'][] = 'Error running automatic notifications: ' . $e->getMessage();
            SecureLogger::error('Error running automatic notifications: ' . $e->getMessage());
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
                JOIN libri l ON w.libro_id = l.id AND l.deleted_at IS NULL
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

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to check wishlist availability: " . $e->getMessage());
        }

        return $totalNotified;
    }

    /**
     * Checks if a book has at least one actual physical copy available TODAY.
     * Delegates to CapacityService::hasFreeCapacity — the single authority used
     * by loan creation, approval, renewal and promotion — so wishlist
     * "now available" notifications apply the same rules: overdue loans still
     * occupy today, and legacy titles with no rows in `copie` fall back to
     * libri.copie_totali.
     */
    public function hasActualAvailableCopy(int $bookId): bool {
        // Use the same capacity authority as creation, approval, renewal and
        // promotion. The previous hand-written count treated an overdue loan as
        // free once its contractual due date passed and rejected every legacy
        // title without rows in `copie`, causing false wishlist notifications in
        // the first case and suppressing valid ones in the second.
        $today = DateHelper::today();
        return (new \App\Services\CapacityService($this->db))
            ->hasFreeCapacity($bookId, $today, $today);
    }

    /**
     * Calculate the next availability date for a book
     * Returns the earliest date when a copy will become available
     * @return string|null Date in Y-m-d format, or null if no loans/reservations
     */
    public function getNextAvailabilityDate(int $bookId): ?string {
        // App-timezone "today" (M9), come hasActualAvailableCopy().
        $today = DateHelper::today();

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
            AND COALESCE(data_inizio_richiesta, DATE(data_scadenza_prenotazione)) IS NOT NULL
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

            // #360: recall (sollecito) tracking — how many recalls went out and
            // when the last one did, so automatic recalls can repeat at the
            // configured interval instead of being one-shot like
            // overdue_notification_sent.
            $result = $this->db->query("SHOW COLUMNS FROM prestiti LIKE 'recall_count'");
            if ($result->num_rows === 0) {
                $this->db->query("ALTER TABLE prestiti ADD COLUMN recall_count INT NOT NULL DEFAULT 0");
            }

            $result = $this->db->query("SHOW COLUMNS FROM prestiti LIKE 'last_recall_at'");
            if ($result->num_rows === 0) {
                $this->db->query("ALTER TABLE prestiti ADD COLUMN last_recall_at DATETIME NULL DEFAULT NULL");
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to add notification columns: " . $e->getMessage());
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

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to add wishlist notification column: " . $e->getMessage());
        }
    }

    /**
     * Invia template agli admin
     */
    private function sendToAdmins(string $templateName, array $variables): bool {
        try {
            $result = $this->db->query("SELECT email FROM utenti WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'");

            if (!$result || $result->num_rows === 0) {
                SecureLogger::warning("No active admin/staff users found for notification");
                return false;
            }

            $sentCount = 0;
            while ($row = $result->fetch_assoc()) {
                // #360: each admin gets the template in their own language
                // (utenti.locale, installation locale as fallback).
                $locale = $this->resolveRecipientLocale((string) $row['email']);
                if ($this->emailService->sendTemplate($row['email'], $templateName, $variables, $locale)) {
                    $sentCount++;
                }
            }

            SecureLogger::info("Sent notification to $sentCount admins");
            return $sentCount > 0;

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send to admins: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia email di approvazione prestito all'utente
     */
    /**
     * Renewal confirmation with the NEW due date. Without it the borrower —
     * especially when the librarian renews at the desk — had no way to know
     * the deadline moved.
     */
    public function sendLoanRenewedNotification(int $loanId, int $maxRenewals): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            $remaining = max(0, $maxRenewals - (int) ($loan['renewals'] ?? 0));
            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_fine' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                'rinnovi_rimanenti' => (string) $remaining,
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_renewed', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan renewed notification: " . $e->getMessage());
            return false;
        }
    }

    public function sendLoanApprovedNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);
            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_inizio' => $this->formatEmailDate($loan['data_prestito'], false, $recipientLocale),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                'giorni_prestito' => $days,
                'pickup_instructions' => $this->translateInLocale('Recati in biblioteca durante gli orari di apertura per ritirare il libro.', $recipientLocale)
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_approved', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan approved notification: " . $e->getMessage());
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
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            return $this->sendWithRetry($loan['utente_email'], 'loan_rejected', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan rejected notification: " . $e->getMessage());
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

            return $this->sendWithRetry($userEmail, 'loan_rejected', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan rejected notification (direct): " . $e->getMessage());
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
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);
            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_inizio' => $this->formatEmailDate($loan['data_prestito'], false, $recipientLocale),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                'giorni_prestito' => $days,
                'scadenza_ritiro' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline'], false, $recipientLocale) : '',
                // #304: alias under the DB column name so a customised template using
                // {{pickup_deadline}} (the natural name a user copies from the schema)
                // resolves as well as the canonical {{scadenza_ritiro}}.
                'pickup_deadline' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline'], false, $recipientLocale) : '',
                'pickup_instructions' => $this->translateInLocale('Recati in biblioteca durante gli orari di apertura per ritirare il libro.', $recipientLocale)
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_pickup_ready', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send pickup ready notification: " . $e->getMessage());
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
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);
            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'scadenza_ritiro' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline'], false, $recipientLocale) : '',
                // #304: alias under the DB column name, see sendPickupReadyNotification.
                'pickup_deadline' => $loan['pickup_deadline'] ? $this->formatEmailDate($loan['pickup_deadline'], false, $recipientLocale) : ''
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_pickup_expired', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send pickup expired notification: " . $e->getMessage());
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
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            return $this->sendWithRetry($loan['utente_email'], 'loan_pickup_cancelled', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send pickup cancelled notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send reservation book available notification
     */
    public function sendReservationBookAvailable(string $email, array $variables): bool {
        return $this->sendWithRetry($email, 'reservation_book_available', $variables);
    }

    /**
     * Invia conferma di restituzione all'utente (GAP-1)
     */
    public function sendLoanReturnedNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            if (empty($loan['utente_email'])) {
                return false;
            }

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_restituzione' => $this->formatEmailDate($loan['data_restituzione'] ?? DateHelper::today(), false, $this->resolveRecipientLocale((string) $loan['utente_email'])),
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_returned', $variables);
        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan returned notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica di prenotazione scaduta all'utente (GAP-2).
     * Il prestito schedulato (stato 'prenotato') è scaduto senza essere ritirato/convertito.
     */
    public function sendReservationExpiredNotification(int $loanId): bool {
        try {
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
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

            if (empty($loan['utente_email'])) {
                return false;
            }

            $scadenza = $loan['data_scadenza'] ?? ($loan['pickup_deadline'] ?? DateHelper::today());
            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'data_scadenza' => $this->formatEmailDate($scadenza, false, $this->resolveRecipientLocale((string) $loan['utente_email'])),
            ];

            return $this->sendWithRetry($loan['utente_email'], 'reservation_expired', $variables);
        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send reservation expired notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia all'utente la notifica che la copia riservata non è più disponibile (GAP-3).
     */
    public function sendCopyUnavailableNotification(string $email, array $variables): bool {
        if (empty($email)) {
            return false;
        }
        return $this->sendWithRetry($email, 'copy_unavailable_user', $variables);
    }

    /**
     * Notifica all'utente la scadenza automatica di una prenotazione in coda (M11):
     * prima l'annullamento da cancelExpiredReservations() era del tutto silenzioso.
     * Variabili attese: utente_nome, libro_titolo, data_scadenza (raw: viene
     * formattata qui con formatEmailDate).
     */
    public function sendQueueReservationExpiredNotification(string $email, array $variables): bool {
        if (empty($email)) {
            return false;
        }
        if (!empty($variables['data_scadenza'])) {
            $variables['data_scadenza'] = $this->formatEmailDate((string)$variables['data_scadenza'], false, $this->resolveRecipientLocale($email));
        }
        return $this->sendWithRetry($email, 'reservation_expired', $variables);
    }

    /**
     * Notifica all'utente l'annullamento di una prenotazione da parte dell'admin (M11).
     * Variabili attese: utente_nome, libro_titolo, motivo.
     */
    public function sendReservationCancelledNotification(string $email, array $variables): bool {
        if (empty($email)) {
            return false;
        }
        return $this->sendWithRetry($email, 'reservation_cancelled', $variables);
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
        // Circuit-breaker: if the SMTP server is unreachable, don't run the full retry cycle
        // (maxRetries attempts + 1s sleeps) for every recipient in a batch — they would all
        // fail the same way, hanging the run. Mailer::isSmtpReachable() probes once per
        // request (cached) and logs a single warning, so a down SMTP costs one short probe
        // instead of N × maxRetries connection timeouts.
        if (!\App\Support\Mailer::isSmtpReachable()) {
            return false;
        }

        $lastError = '';

        // #360: recipient's preferred language (utenti.locale) instead of the
        // installation locale; resolveRecipientLocale falls back to the
        // installation locale, so nothing changes for users without one.
        $recipientLocale = $this->resolveRecipientLocale($email);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($this->emailService->sendTemplate($email, $template, $variables, $recipientLocale)) {
                    if ($attempt > 1) {
                        SecureLogger::info("Email to {$email} succeeded on attempt {$attempt}");
                    }
                    return true;
                }
                $lastError = 'sendTemplate returned false';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                SecureLogger::warning("Email attempt {$attempt}/{$maxRetries} to {$email} failed: {$lastError}");
            }

            // Don't delay after the last attempt
            if ($attempt < $maxRetries) {
                usleep($retryDelayMs * 1000); // Convert ms to microseconds
            }
        }

        SecureLogger::warning("Email to {$email} failed after {$maxRetries} attempts. Last error: {$lastError}");
        return false;
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
            SecureLogger::warning("Invalid notification type: $type");
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO admin_notifications (type, title, message, link, related_id)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            SecureLogger::error("Failed to prepare notification insert: " . $this->db->error);
            return false;
        }

        $stmt->bind_param('ssssi', $type, $title, $message, $link, $relatedId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Generic admin notification for an app/plugin event that has no dedicated
     * email template: creates the in-app admin-bell notification AND emails
     * every active admin/staff — the same two channels every other admin
     * notification uses (createNotification + the admin-email query in
     * sendToAdmins), so callers don't hand-roll recipient selection or mail.
     * The email body is wrapped in EmailService's branded base template like
     * the rest of Pinakes. Best-effort: failures are logged, never thrown.
     *
     * @param string $type one of createNotification()'s allowed types
     * @param list<array<string, mixed>> $extraRecipients extra people to email
     *        besides the admins — each ['email','nome','cognome','locale'].
     *        Merged and de-duplicated by email so nobody is emailed twice.
     */
    public function notifyAdmins(string $type, string $title, string $message, ?string $link = null, ?int $relatedId = null, array $extraRecipients = []): void
    {
        // Both channels are guarded by one try/catch so a failure in either the
        // in-app bell or the email step is logged, never thrown (best-effort).
        // $phase tags which channel a caught throwable came from.
        $phase = 'in-app';
        try {
            // 1. In-app admin bell.
            $this->createNotification($type, $title, $message, $link, $relatedId);

            // 2. Email the active admins/staff (same recipient query as sendToAdmins)
            //    plus any extra recipients, de-duplicated by lower-cased email.
            $phase = 'email';
            $recipients = [];
            $result = $this->db->query(
                "SELECT email, nome, cognome, locale FROM utenti
                  WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'
                    AND email IS NOT NULL AND email <> ''"
            );
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $recipients[strtolower(trim((string) $row['email']))] = $row;
                }
            }
            foreach ($extraRecipients as $extra) {
                $key = strtolower(trim((string) ($extra['email'] ?? '')));
                if ($key !== '' && !isset($recipients[$key])) {
                    $recipients[$key] = $extra;
                }
            }
            if ($recipients === []) {
                return;
            }
            // Circuit-breaker: this method is called synchronously from user-facing
            // request handlers (join-club, propose-book, create-meeting) and loops a
            // blocking sendEmail() per recipient. If the SMTP server is unreachable,
            // every send would time out the same way, hanging the acting user's request
            // for N sequential connection timeouts. Mailer::isSmtpReachable() probes once
            // per request (cached, and always true for the non-SMTP `mail` driver so it
            // never blocks phpmail sends) and logs a single warning — same guard
            // sendWithRetry() uses. The in-app admin bell above still fired regardless.
            if (!\App\Support\Mailer::isSmtpReachable()) {
                SecureLogger::warning('notifyAdmins: SMTP unreachable — skipping email to ' . count($recipients) . ' recipient(s), in-app notification still created');
                return;
            }
            $bodyHtml = '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
            if ($link !== null && $link !== '') {
                $bodyHtml .= '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>';
            }
            foreach ($recipients as $row) {
                $this->emailService->sendEmail(
                    (string) $row['email'],
                    $title,
                    $bodyHtml,
                    trim((string) ($row['nome'] ?? '') . ' ' . (string) ($row['cognome'] ?? '')),
                    $row['locale'] ?? null
                );
            }
        } catch (\Throwable $e) {
            $reason = $phase === 'in-app'
                ? 'notifyAdmins: in-app notification failed: '
                : 'notifyAdmins: email failed: ';
            SecureLogger::error($reason . $e->getMessage());
        }
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
        } catch (\Throwable $e) {
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
            '/admin/users',
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
            '/admin/reservations',
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
            '/admin/loans',
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
                JOIN libri l ON r.libro_id = l.id AND l.deleted_at IS NULL
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
                'link_approvazione' => absoluteUrl('/admin/reviews')
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
            $notificationLink = '/admin/reviews';

            $this->createNotification(
                'new_review',
                $notificationTitle,
                $notificationMessage,
                $notificationLink,
                $reviewId
            );

            return $emailSent;

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to notify new review: " . $e->getMessage());
            return false;
        }
    }
}
