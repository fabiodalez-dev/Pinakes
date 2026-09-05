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

    /**
     * #360: memoize the notification-column self-heal so a bulk path (bulkRecall
     * loops sendManualRecall up to 50 times) doesn't re-run four SHOW COLUMNS
     * per loan. The schema doesn't change mid-request.
     */
    private bool $notificationColumnsEnsured = false;

    /** Templates already backed by their own durable sent/claim state. */
    private const DEDICATED_RETRY_TEMPLATES = [
        'loan_expiring_warning',
        'loan_overdue_notification',
        'loan_recall_notification',
        'wishlist_book_available',
        'reservation_awaiting_approval',
        'loan_pickup_ready',
    ];

    /**
     * Righe outbox oltre questa soglia vengono scartate: senza tetto una
     * consegna permanentemente impossibile (destinatario inesistente,
     * variables_json corrotto) resterebbe in coda per sempre, occupando il
     * batch e conservando dati personali a tempo indeterminato.
     */
    private const OUTBOX_MAX_ATTEMPTS = 10;

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
    public function formatEmailDate(string $dateString, bool $includeTime = false, ?string $locale = null): string
    {
        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return $dateString;
        }

        $locale = $locale ?? I18n::getInstallationLocale();

        // Explicit per-language date conventions for the shipped locales;
        // English and anything unknown keep the unambiguous ISO form (the
        // historical behaviour for every non-Italian locale).
        $formats = [
            'it' => 'd-m-Y',
            'de' => 'd.m.Y',
            'fr' => 'd/m/Y',
            'da' => 'd.m.Y',
        ];
        $format = $formats[substr($locale, 0, 2)] ?? 'Y-m-d';

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
    public function resolveRecipientLocale(string $email): string
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
        $stmt = null;
        try {
            $stmt = $this->db->prepare("SELECT locale FROM utenti WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            $raw = trim((string) ($row['locale'] ?? ''));
            if ($raw !== '' && isset(I18n::getAvailableLocales()[$raw])) {
                $locale = $raw;
            }
        } catch (\Throwable $e) {
            // Lookup failure must never block the send: keep the fallback.
        } finally {
            // Close in finally so a throw between prepare() and close() can't
            // leak the statement handle.
            if ($stmt instanceof \mysqli_stmt) {
                $stmt->close();
            }
        }

        return $this->recipientLocaleCache[$email] = $locale;
    }

    /**
     * Resolve a __()-translated label in the given locale without leaking the
     * switch to the caller's session (used for per-recipient email wording).
     * Public so email-composing collaborators (ReservationReassignmentService)
     * can localize their fallback texts in the RECIPIENT's language too.
     */
    public function translateInLocale(string $message, string $locale): string
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
                'admin_users_url' => absoluteUrl('/admin/users')
            ];

            // Send to admins — data_registrazione formatted per admin locale.
            return $this->sendToAdmins('admin_new_registration', $variables, [
                'data_registrazione' => [$user['created_at'], true],
            ]);

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
                // Temporarily switch locale for the button URL + label; the
                // finally guarantees the process-wide locale is restored even
                // when RouteTranslator/__() throw (the outer catch would
                // otherwise leave the whole request in the recipient's locale).
                $currentLocale = \App\Support\I18n::getLocale();
                try {
                    \App\Support\I18n::setLocale($locale);
                    $verifyUrl = absoluteUrl(RouteTranslator::route('verify_email') . '?token=' . urlencode((string)$user['token_verifica_email']));
                    $buttonText = __('Conferma la tua email');
                    $verifySection = '<p style="margin: 20px 0;"><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a></p>';
                } finally {
                    \App\Support\I18n::setLocale($currentLocale);
                }
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
                'approve_url' => absoluteUrl('/admin/loans/pending')
            ];

            // Send email to admins — dates formatted per admin locale.
            $emailSent = $this->sendToAdmins('loan_request_notification', $variables, [
                'data_inizio' => [$loan['data_prestito'], false],
                'data_fine' => [$loan['data_scadenza'], false],
                'data_richiesta' => [$loan['created_at'], true],
            ]);

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
            // CI-SOFT-DELETE-EXEMPT: the expiry warning must keep firing for active loans whose book was archived — the copy is still out and the borrower must be chased (same rationale as sendLoanRecalls).
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(p.data_scadenza, ?) as giorni_rimasti
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
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
                $notificationMessage = $daysRemaining === 0
                    ? sprintf(__('"%s" prestato a %s scade oggi'), $loan['libro_titolo'], $loan['utente_nome'])
                    : sprintf(__('"%s" prestato a %s scade fra %d giorni'), $loan['libro_titolo'], $loan['utente_nome'], $daysRemaining);

                // Utente senza email: niente da inviare né da ritentare. Senza
                // questo ramo il claim veniva revertito a ogni run (retry SMTP a
                // vuoto per sempre) e la notifica in-app non nasceva mai. Il flag
                // resta claimato e lo staff viene avvisato in-app comunque.
                if (trim((string) $loan['utente_email']) === '') {
                    $this->createNotification(
                        'general',
                        __('Prestito in scadenza'),
                        $notificationMessage,
                        '/admin/loans',
                        (int)$loan['id']
                    );
                    SecureLogger::info("Expiration warning for loan {$loan['id']}: user has no email, in-app notice only");
                    continue;
                }

                $variables = [
                    'utente_nome' => $loan['utente_nome'],
                    'libro_titolo' => $loan['libro_titolo'],
                    'data_scadenza' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                    'giorni_rimasti' => $daysRemaining === 0 ? $todayLabels[$recipientLocale] : (string)$daysRemaining
                ];

                $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_expiring_warning', $variables);

                if ($emailSent) {
                    // Create in-app notification for expiring loan
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
            // CI-SOFT-DELETE-EXEMPT: the first overdue notice must keep firing for active loans whose book was archived — soft-deleting the book silenced this notice AND, since sendLoanRecalls() requires overdue_notification_sent=1, every automatic recall after it.
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
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

                // Utente senza email: il claim resta (nulla da ritentare — prima
                // veniva revertito a ogni run, retry SMTP a vuoto per sempre) e
                // soprattutto gli ADMIN vengono avvisati comunque: erano dentro
                // if ($emailSent), quindi per i prestatari senza indirizzo il
                // ritardo non arrivava mai né via email admin né in-app.
                if (trim((string) $loan['utente_email']) === '') {
                    $this->notifyAdminsOverdue((int)$loan['id']);
                    $this->notifyOverdueLoanInApp(
                        (int)$loan['id'],
                        $loan['utente_nome'],
                        $loan['libro_titolo'],
                        (int)$loan['giorni_ritardo']
                    );
                    SecureLogger::info("Overdue notification for loan {$loan['id']}: user has no email, admins notified only");
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
            // Self-healing like sendManualRecall(): this is a public method and
            // the SELECT below reads recall_count / last_recall_at, which a
            // not-yet-migrated install doesn't have — without this the query
            // throws, the catch swallows it and automatic recalls silently
            // no-op. runAutomaticNotifications() heals only its own path.
            $this->addNotificationColumns();
            // Same clamps as SettingsController::updateLoansSettings — the
            // stored value is trusted but a hand-edited row must not produce a
            // zero/negative interval (division-like schedule) or a runaway cap.
            $intervalDays = min(365, max(1, (int) ($settings->get('loans', 'recall_interval_days', '7') ?? 7)));
            $maxRecalls = min(50, max(1, (int) ($settings->get('loans', 'recall_max_count', '3') ?? 3)));

            // "Oggi" nel timezone applicativo come parametro bound (M9).
            $today = DateHelper::today();

            // CI-SOFT-DELETE-EXEMPT: an active overdue loan remains physically
            // owed to the library even when its catalog record is archived.
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.recall_count, p.last_recall_at,
                       l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
                JOIN utenti u ON p.utente_id = u.id
                WHERE p.stato IN ('in_corso', 'in_ritardo')
                  AND p.attivo = 1
                  AND u.email IS NOT NULL
                  AND TRIM(u.email) <> ''
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
                $sentCount += $this->claimAndSendRecall($loan) ? 1 : 0;
            }

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan recalls: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * #360: manual recall for a single loan, triggered by staff from the loan
     * detail page or the loans-list bulk action. Skips the automatic interval /
     * max-count schedule, but still requires the loan to be genuinely overdue,
     * the user to have an email address, and no recall to have already gone out
     * today (a per-loan daily cooldown that bounds abuse of the manual path).
     *
     * @return array{success: bool, message: string}
     */
    public function sendManualRecall(int $loanId, int $maxRetries = 3, int $retryDelayMs = 1000): array {
        try {
            $this->addNotificationColumns();

            $today = DateHelper::today();
            // CI-SOFT-DELETE-EXEMPT: staff must be able to recall an outstanding
            // physical loan after the related catalog record is archived.
            $stmt = $this->db->prepare("
                SELECT p.id, p.data_scadenza, p.recall_count, p.last_recall_at,
                       p.stato, p.attivo,
                       l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email,
                       DATEDIFF(?, p.data_scadenza) as giorni_ritardo
                FROM prestiti p
                JOIN libri l ON p.libro_id = l.id
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
            // Per-loan daily cooldown: at most one recall per loan per day,
            // matching the automatic scheduler's DATE(last_recall_at) < today
            // throttle. Prevents a staff session (or a repeated bulk submit)
            // from re-emailing the same patron many times in a row while still
            // allowing a manual recall on a later day.
            if ($loan['last_recall_at'] !== null
                && substr((string) $loan['last_recall_at'], 0, 10) === $today) {
                return ['success' => false, 'message' => __('Un sollecito è già stato inviato oggi per questo prestito.')];
            }

            if ($this->claimAndSendRecall($loan, $maxRetries, $retryDelayMs)) {
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
    private function claimAndSendRecall(array $loan, int $maxRetries = 3, int $retryDelayMs = 1000): bool {
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

        $emailSent = $this->sendWithRetry(
            $loan['utente_email'],
            'loan_recall_notification',
            $variables,
            max(1, $maxRetries),
            max(0, $retryDelayMs)
        );

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

            // #360: cover message in the recipient's language, like every other
            // user-facing email. The PDF generator uses the process-wide I18n
            // locale too, so switch only for generation and always restore the
            // staff request locale afterwards.
            $recipientLocale = $this->resolveRecipientLocale($email);
            $requestLocale = I18n::getLocale();
            try {
                I18n::setLocale($recipientLocale);
                $pdfContent = (new LoanPdfGenerator($this->db))->generate($loanId);
            } finally {
                I18n::setLocale($requestLocale);
            }

            // A generator that returns an empty (or non-PDF) string without
            // throwing would otherwise be sent as a message with no attachment
            // (EmailService drops empty attachments) yet still report success —
            // the patron gets an email with no receipt while the operator reads
            // "Ricevuta inviata con successo". Refuse when the bytes aren't a PDF.
            if (!str_starts_with((string) $pdfContent, '%PDF')) {
                SecureLogger::error("Loan receipt PDF generation returned no valid PDF for loan {$loanId}");
                return ['success' => false, 'message' => __('Impossibile generare la ricevuta PDF.')];
            }

            $variables = [
                'prestito_id' => $loanId,
                'utente_nome' => (string) ($loan['utente'] ?? ''),
                'libro_titolo' => (string) ($loan['libro'] ?? ''),
                'data_prestito' => $this->formatEmailDate((string) ($loan['data_prestito'] ?? ''), false, $recipientLocale),
                'data_scadenza' => $this->formatEmailDate((string) ($loan['data_scadenza'] ?? ''), false, $recipientLocale),
            ];
            $attachment = [
                'content' => $pdfContent,
                'filename' => 'prestito_' . $loanId . '_' . str_replace('-', '', DateHelper::today()) . '.pdf',
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
            // CI-SOFT-DELETE-EXEMPT: the borrower's overdue notice is exempt, so
            // the admin alert for the SAME overdue loan must be too — an archived
            // title's outstanding loan is exactly the case staff must chase.
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
            ];

            // Usa sendToAdmins (template dal DB email_templates con fallback) come tutti
            // gli altri invii: così la personalizzazione admin del template viene
            // rispettata, a differenza del precedente SettingsMailTemplates::get() che
            // leggeva solo il PHP hardcoded (GAP-4). Le date sono formattate per la
            // lingua di ogni admin.
            $this->sendToAdmins('loan_overdue_admin', $variables, [
                'data_scadenza' => [$loan['data_scadenza'], false],
                'data_prestito' => [$loan['data_prestito'], false],
            ]);

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
                  AND u.email IS NOT NULL AND TRIM(u.email) <> ''
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
                $recipientLocale = $this->resolveRecipientLocale((string) $wishlist['email']);
                $variables = [
                    'utente_nome' => $wishlist['utente_nome'],
                    'libro_titolo' => $wishlist['titolo'],
                    // Fallback nel locale del DESTINATARIO, non italiano hardcoded.
                    'libro_autore' => $wishlist['autore'] ?: $this->translateInLocale('Autore non specificato', $recipientLocale),
                    'libro_isbn' => $wishlist['isbn'] ?: 'N/A',
                    'data_disponibilita' => $this->formatEmailDate('now', true, $recipientLocale),
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
        $today = DateHelper::today();
        return (new \App\Services\CapacityService($this->db))
            ->firstAvailableDate($bookId, $today);
    }

    /**
     * Aggiunge colonne per tracking notifiche se non esistono
     */
    /**
     * Public: anche i chiamanti esterni che aggiornano i flag di notifica
     * direttamente (es. il claim pickup in LoanApprovalController) devono
     * poter garantire le colonne prima dell'UPDATE sugli install legacy.
     *
     * @return bool true se le colonne sono garantite; false se l'inizializzazione
     *              è fallita (install legacy con ALTER negato) — in quel caso i
     *              chiamanti NON devono eseguire UPDATE sui flag di notifica.
     */
    public function addNotificationColumns(): bool {
        if ($this->notificationColumnsEnsured) {
            return true;
        }
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

            // Claim/retry dell'email "pronto al ritiro": schema + backfill
            // resumable are centralized so controller, cron and maintenance
            // cannot drift or run DDL inside a circulation transaction.
            // Un fallimento qui NON deve saltare la creazione delle colonne
            // recall qui sotto: sendLoanRecalls() le interroga ignorando il
            // valore di ritorno, e un early-return lascerebbe i solleciti
            // automatici silenziosamente a zero per una causa non correlata.
            $pickupSchemaReady = PickupNotificationSchema::ensure($this->db);

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

            if (!$pickupSchemaReady) {
                return false;
            }

            // Only memoize once the checks completed without throwing, so a
            // transient failure retries on the next call.
            $this->notificationColumnsEnsured = true;
        } catch (\Throwable $e) {
            SecureLogger::error("Failed to add notification columns: " . $e->getMessage());
        }
        return $this->notificationColumnsEnsured;
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
    /**
     * @param array<string,mixed>            $variables     non-date template variables, shared by every admin
     * @param array<string,array{0:string,1?:bool}> $dateVariables raw dates keyed by template variable —
     *        [rawDate, includeTime] — formatted per recipient inside the loop
     */
    private function sendToAdmins(string $templateName, array $variables, array $dateVariables = []): bool {
        try {
            $result = $this->db->query("SELECT email FROM utenti WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'");

            if (!$result || $result->num_rows === 0) {
                SecureLogger::warning("No active admin/staff users found for notification");
                return false;
            }

            $sentCount = 0;
            while ($row = $result->fetch_assoc()) {
                // #360: each admin gets the template AND its date variables in
                // their own language (utenti.locale, installation locale as
                // fallback). Dates arrive raw in $dateVariables and are
                // formatted per recipient here, so a differently-localized
                // admin no longer sees installation-format dates.
                $locale = $this->resolveRecipientLocale((string) $row['email']);
                $recipientVars = $variables;
                foreach ($dateVariables as $name => $spec) {
                    $recipientVars[$name] = $this->formatEmailDate((string) $spec[0], (bool) ($spec[1] ?? false), $locale);
                }
                if ($this->emailService->sendTemplate($row['email'], $templateName, $recipientVars, $locale)) {
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
            // CI-SOFT-DELETE-EXEMPT: the renewal confirmation must reach the borrower even when the title was archived after the loan went out — renew()/update()/bulkExtend all extend such loans (soft-delete governs lendability, not running loans).
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id
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

            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim((string) $loan['utente_email']) === '') {
                SecureLogger::info("Loan renewed notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

            $remaining = max(0, $maxRenewals - (int) ($loan['renewals'] ?? 0));
            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                // Fallback per il caso limite di riga libri assente (hard delete):
                // per i titoli archiviati la LEFT JOIN riporta comunque il titolo.
                'libro_titolo' => trim((string) ($loan['libro_titolo'] ?? '')) !== ''
                    ? $loan['libro_titolo']
                    : $this->translateInLocale('Non disponibile', $recipientLocale),
                'data_fine' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
                'rinnovi_rimanenti' => (string) $remaining,
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_renewed', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan renewed notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * B1: conferma di ritiro — il lettore ha ritirato fisicamente il libro e il
     * prestito è ufficialmente iniziato. Stesso pattern di
     * sendLoanRenewedNotification (locale del destinatario, date formattate
     * per destinatario, consegna durevole via outbox in sendWithRetry).
     */
    public function sendLoanPickedUpNotification(int $loanId): bool {
        try {
            // CI-SOFT-DELETE-EXEMPT: the pickup confirmation must reach the borrower even when the title was archived after approval — the copy is already in the user's hands, soft-delete governs lendability, not running loans (same convention as sendLoanRenewedNotification).
            $stmt = $this->db->prepare("
                SELECT p.*, l.titolo as libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) as utente_nome, u.email as utente_email
                FROM prestiti p
                LEFT JOIN libri l ON p.libro_id = l.id
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

            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim((string) $loan['utente_email']) === '') {
                SecureLogger::info("Loan picked-up notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'] ?? $this->translateInLocale('Non disponibile', $recipientLocale),
                'data_prestito' => $this->formatEmailDate($loan['data_prestito'], false, $recipientLocale),
                'data_scadenza' => $this->formatEmailDate($loan['data_scadenza'], false, $recipientLocale),
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_picked_up', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan picked-up notification: " . $e->getMessage());
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

            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim((string) $loan['utente_email']) === '') {
                SecureLogger::info("Loan approved notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

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
                'pickup_instructions' => $this->translateInLocale('Recati in biblioteca durante gli orari di apertura per ritirare il libro.', $recipientLocale),
                'sezione_calendario' => $this->buildCalendarSection((int)$loan['id'], (string)$loan['libro_titolo'], (string)$loan['data_prestito'], (string)$loan['data_scadenza'], $recipientLocale)
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_approved', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send loan approved notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sezione "aggiungi al calendario" ({{sezione_calendario}}) per le email
     * di conferma prestito: link Google Calendar + download .ics tokenizzato.
     * Le etichette sono risolte nel locale di installazione — le email escono
     * in quel locale (vedi sendWithRetry), non in quello di sessione.
     * Best-effort: qualsiasi errore produce sezione vuota, mai un'email persa.
     */
    private function buildCalendarSection(int $loanId, string $bookTitle, string $startDate, string $endDate, string $locale): string
    {
        if ($loanId <= 0 || $startDate === '' || $endDate === '') {
            return '';
        }
        $sessionLocale = \App\Support\I18n::getLocale();
        try {
            // Render the calendar block in the recipient's locale, matching the
            // rest of the email (pickup_instructions, dates), not the install one.
            \App\Support\I18n::setLocale($locale !== '' ? $locale : \App\Support\I18n::getInstallationLocale());
            return (new LoanCalendarLinks($this->db))->emailSection($loanId, $bookTitle, $startDate, $endDate);
        } catch (\Throwable $e) {
            SecureLogger::warning("Failed to build calendar links for loan {$loanId}: " . $e->getMessage());
            return '';
        } finally {
            \App\Support\I18n::setLocale($sessionLocale);
        }
    }

    // NOTE (0.7.81): the loan-id based sendLoanRejectedNotification(int) was
    // removed as dead code — every rejection path deletes/closes the loan first
    // and calls sendLoanRejectedNotificationDirect() below with preloaded data.

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
            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim($userEmail) === '') {
                SecureLogger::info("Loan rejected notification: user has no email, skipped");
                return false;
            }

            // The fallback reason must render in the RECIPIENT's locale, not the
            // acting admin's session locale (same pattern as the other senders).
            $recipientLocale = $this->resolveRecipientLocale($userEmail);
            $variables = [
                'utente_nome' => $userName,
                'libro_titolo' => $bookTitle,
                // !== '' (not ?:) so a literal "0" reason is preserved — same
                // criterion as sendPickupCancelledNotification's reason handling.
                'motivo_rifiuto' => $reason !== '' ? $reason : $this->translateInLocale('Nessun motivo specificato', $recipientLocale)
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
        $claimToken = null;

        try {
            // A restricted legacy DB may not be able to self-heal before the
            // updater runs. In that case preserve the historical one-shot send
            // instead of losing the email because the claim column is absent.
            $claimSchemaAvailable = PickupNotificationSchema::ensure($this->db);

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

            // Claim atomico come warning/overdue: marca l'avviso PRIMA di
            // inviare, così un doppio trigger non duplica l'email e un
            // fallimento SMTP viene ritentato dallo sweep
            // retryUnsentPickupNotifications() invece di perdersi per sempre.
            // Solo per righe 'da_ritirare' (l'unico stato che ha un ritiro da
            // annunciare); per stati diversi mantiene il comportamento storico.
            $isReadyPickup = ($loan['stato'] ?? '') === 'da_ritirare' && (int) ($loan['attivo'] ?? 0) === 1;
            if ($isReadyPickup && $claimSchemaAvailable) {
                $claimToken = bin2hex(random_bytes(16));
                $claimWindow = PickupNotificationSchema::claimLeaseWindow();
                $attemptedAt = $claimWindow['attemptedAt'];
                $staleBefore = $claimWindow['staleBefore'];
                $recipientUserId = (int) $loan['utente_id'];
                $claimStmt = $this->db->prepare("
                    UPDATE prestiti
                       SET pickup_notification_sent = 1,
                           pickup_notification_claim_token = ?,
                           pickup_notification_last_attempt_at = ?
                    WHERE id = ? AND utente_id = ?
                      AND attivo = 1 AND stato = 'da_ritirare'
                      AND (
                            pickup_notification_sent IS NULL
                            OR pickup_notification_sent = 0
                            OR (
                                pickup_notification_sent = 1
                                AND pickup_notification_claim_token IS NOT NULL
                                AND pickup_notification_last_attempt_at < ?
                            )
                      )
                ");
                $claimStmt->bind_param('ssiis', $claimToken, $attemptedAt, $loanId, $recipientUserId, $staleBefore);
                $claimStmt->execute();
                $claimAcquired = $claimStmt->affected_rows === 1;
                $claimStmt->close();
                if (!$claimAcquired) {
                    $claimToken = null;
                    // Già annunciato/claimato, oppure il destinatario è cambiato
                    // dopo la lettura. In quest'ultimo caso il reset effettuato
                    // dalla riassegnazione lascia la riga al retry successivo.
                    return false;
                }
            }

            // Utente senza email: il claim resta (nulla da ritentare), niente churn.
            if (trim((string) $loan['utente_email']) === '') {
                if ($claimToken !== null) {
                    $ownedToken = $claimToken;
                    $claimToken = null;
                    $this->finalizePickupClaim($loanId, $ownedToken);
                }
                SecureLogger::info("Pickup ready notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

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
                'pickup_instructions' => $this->translateInLocale('Recati in biblioteca durante gli orari di apertura per ritirare il libro.', $recipientLocale),
                'sezione_calendario' => $this->buildCalendarSection((int)$loan['id'], (string)$loan['libro_titolo'], (string)$loan['data_prestito'], (string)$loan['data_scadenza'], $recipientLocale)
            ];

            $emailSent = $this->sendWithRetry($loan['utente_email'], 'loan_pickup_ready', $variables);

            if ($emailSent) {
                if ($claimToken !== null) {
                    $ownedToken = $claimToken;
                    // Once delivery succeeded, never let a cleanup failure re-arm
                    // the email. Relinquish local ownership before cleanup.
                    $claimToken = null;
                    $this->finalizePickupClaim($loanId, $ownedToken);
                }
            } elseif ($claimToken !== null) {
                // Il claim è certamente riuscito (il ramo !claimAcquired esce):
                // revert del flag così lo sweep di manutenzione ritenta al
                // prossimo run (stesso pattern di warning/overdue).
                $ownedToken = $claimToken;
                $claimToken = null;
                $this->releasePickupClaim($loanId, $ownedToken);
                SecureLogger::warning("Failed to send pickup ready notification for loan {$loanId} after retries, flag reverted");
            }

            return $emailSent;

        } catch (\Throwable $e) {
            if ($claimToken !== null) {
                try {
                    $ownedToken = $claimToken;
                    $claimToken = null;
                    $this->releasePickupClaim($loanId, $ownedToken);
                } catch (\Throwable $revertError) {
                    SecureLogger::error("Failed to release pickup notification claim for loan {$loanId}: " . $revertError->getMessage());
                }
            }
            SecureLogger::error("Failed to send pickup ready notification: " . $e->getMessage());
            return false;
        }
    }

    private function releasePickupClaim(int $loanId, string $claimToken): void
    {
        $revertStmt = $this->db->prepare("
            UPDATE prestiti
               SET pickup_notification_sent = 0,
                   pickup_notification_claim_token = NULL
             WHERE id = ? AND pickup_notification_claim_token = ?
        ");
        $revertStmt->bind_param('is', $loanId, $claimToken);
        $revertStmt->execute();
        $revertStmt->close();
    }

    private function finalizePickupClaim(int $loanId, string $claimToken): void
    {
        $finalizeStmt = $this->db->prepare("
            UPDATE prestiti
               SET pickup_notification_claim_token = NULL
             WHERE id = ? AND pickup_notification_claim_token = ?
        ");
        $finalizeStmt->bind_param('is', $loanId, $claimToken);
        $finalizeStmt->execute();
        $finalizeStmt->close();
    }

    /**
     * Recupero delle email "pronto al ritiro" il cui invio è fallito
     * (stato ancora 'da_ritirare', pickup_notification_sent = 0), oppure claim
     * rimasti orfani oltre la lease. Chiamato dallo sweep di manutenzione; il
     * token vive in sendPickupReadyNotification() e limita la consegna a un
     * proprietario alla volta. Dopo un crash il protocollo è at-least-once:
     * non può sapere se SMTP abbia accettato il messaggio prima della morte del
     * worker, ma privilegia il recupero rispetto alla perdita silenziosa.
     */
    public function retryUnsentPickupNotifications(): int {
        $sentCount = 0;
        try {
            if (!PickupNotificationSchema::ensure($this->db)) {
                return 0;
            }
            $today = DateHelper::today();
            $staleBefore = PickupNotificationSchema::claimLeaseWindow()['staleBefore'];

            // Solo ritiri ancora validi (deadline non passata: quelli scaduti li
            // culla checkExpiredPickups) e utenti con un indirizzo email.
            // Il JOIN su libri rispecchia sendPickupReadyNotification(): senza,
            // un ritiro il cui titolo è stato archiviato (soft-delete) verrebbe
            // selezionato qui ma scartato PRIMA del claim dall'invio — nessun
            // last_attempt_at scritto, riga sempre in testa all'ORDER BY, e con
            // 20 righe così il LIMIT si satura e nessun ritiro sano viene più
            // notificato.
            $stmt = $this->db->prepare("
                SELECT p.id
                FROM prestiti p
                JOIN utenti u ON p.utente_id = u.id
                JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                WHERE p.attivo = 1 AND p.stato = 'da_ritirare'
                  AND (
                        p.pickup_notification_sent IS NULL
                        OR p.pickup_notification_sent = 0
                        OR (
                            p.pickup_notification_sent = 1
                            AND p.pickup_notification_claim_token IS NOT NULL
                            AND p.pickup_notification_last_attempt_at < ?
                        )
                  )
                  AND (p.pickup_deadline IS NULL OR p.pickup_deadline >= ?)
                  AND u.email IS NOT NULL AND TRIM(u.email) <> ''
                ORDER BY p.pickup_notification_last_attempt_at IS NULL DESC,
                         p.pickup_notification_last_attempt_at ASC,
                         p.id ASC
                LIMIT 20
            ");
            $stmt->bind_param('ss', $staleBefore, $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $loanIds = [];
            while ($row = $result->fetch_assoc()) {
                $loanIds[] = (int) $row['id'];
            }
            $stmt->close();

            foreach ($loanIds as $loanId) {
                if ($this->sendPickupReadyNotification($loanId)) {
                    $sentCount++;
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error("Failed to retry unsent pickup notifications: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * Invia notifica quando la scadenza del ritiro è passata (prestito scaduto)
     */
    public function sendPickupExpiredNotification(int $loanId, ?string $pickupDeadline = null): bool {
        try {
            // CI-SOFT-DELETE-EXEMPT: this terminal pickup notice must reach the
            // borrower even after the book was soft-deleted — checkExpiredPickups()
            // deliberately expires pickups on archived titles (its sweep is
            // exempt), so filtering deleted_at here silently swallowed the email
            // while the loan still expired and the copy was freed (#381 class).
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

            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim((string) $loan['utente_email']) === '') {
                SecureLogger::info("Pickup expired notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);
            // The sweep NULLs pickup_deadline on the terminal 'scaduto' row (kept
            // consistent with both cancel paths), so the caller passes the elapsed
            // deadline it captured under lock; the row value is only a fallback.
            $deadline = $pickupDeadline ?? $loan['pickup_deadline'];
            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                'scadenza_ritiro' => $deadline ? $this->formatEmailDate($deadline, false, $recipientLocale) : '',
                // #304: alias under the DB column name, see sendPickupReadyNotification.
                'pickup_deadline' => $deadline ? $this->formatEmailDate($deadline, false, $recipientLocale) : ''
            ];

            return $this->sendWithRetry($loan['utente_email'], 'loan_pickup_expired', $variables);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send pickup expired notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia la notifica coerente con la chiusura manuale del ritiro.
     *
     * Il metodo mantiene il nome storico, ma `cancelPickup` può chiudere anche un
     * ritiro la cui deadline è già trascorsa. In quel caso usa il template di
     * scadenza, non quello di annullamento volontario.
     */
    public function sendPickupCancelledNotification(
        int $loanId,
        string $reason = '',
        string $terminalState = 'annullato',
        ?string $pickupDeadline = null
    ): bool {
        try {
            // CI-SOFT-DELETE-EXEMPT: this terminal pickup notice must reach the borrower even after the book was soft-deleted — cancelPickup() deliberately locks and closes the loan without a deleted_at filter, so the expired/cancelled email must still fire (same rationale as the overdue/expiry notices above).
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

            // Utente senza email: niente invio né retry (stesso criterio dei
            // guard warning/overdue).
            if (trim((string) $loan['utente_email']) === '') {
                SecureLogger::info("Pickup cancelled notification for loan {$loanId}: user has no email, skipped");
                return false;
            }

            $recipientLocale = $this->resolveRecipientLocale((string) $loan['utente_email']);

            if ($terminalState === 'scaduto') {
                $effectiveDeadline = $pickupDeadline ?: ($loan['pickup_deadline'] ?? null);
                $formattedDeadline = $effectiveDeadline
                    ? $this->formatEmailDate((string) $effectiveDeadline, false, $recipientLocale)
                    : '';
                return $this->sendWithRetry($loan['utente_email'], 'loan_pickup_expired', [
                    'utente_nome' => $loan['utente_nome'],
                    'libro_titolo' => $loan['libro_titolo'],
                    'scadenza_ritiro' => $formattedDeadline,
                    'pickup_deadline' => $formattedDeadline,
                ]);
            }

            $variables = [
                'utente_nome' => $loan['utente_nome'],
                'libro_titolo' => $loan['libro_titolo'],
                // Il motivo passa SEMPRE da translateInLocale nel locale del
                // destinatario: le chiavi di catalogo (stringhe base italiane)
                // vengono tradotte, il testo già reso passa invariato. Così i
                // chiamanti CLI/cron non iniettano il locale di sessione.
                'motivo' => $this->translateInLocale(
                    $reason !== '' ? $reason : 'Ritiro annullato',
                    $recipientLocale
                ),
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
        // Promotion creates a pending request; it is not yet collectible. Use a
        // distinct template so legacy/custom "ready for pickup" text cannot send
        // the reader to the library before an administrator approves the loan.
        return $this->sendWithRetry($email, 'reservation_awaiting_approval', $variables);
    }

    /**
     * Invia conferma di restituzione all'utente (GAP-1)
     */
    public function sendLoanReturnedNotification(int $loanId): bool {
        try {
            // CI-SOFT-DELETE-EXEMPT: the return-confirmation must reach the
            // borrower even when the title was archived while the loan was out —
            // the return path itself is exempt and closes the loan regardless.
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

    /** Notify the borrower when a loan is closed as lost or damaged. */
    public function sendLoanCopyOutcomeNotification(int $loanId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT p.stato, p.data_restituzione, p.note, p.sanzione,
                       l.titolo AS libro_titolo,
                       CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                       u.email AS utente_email
                FROM prestiti p
                -- CI-SOFT-DELETE-EXEMPT: terminal outcome notices include archived titles.
                JOIN libri l ON l.id = p.libro_id
                JOIN utenti u ON u.id = p.utente_id
                WHERE p.id = ? AND p.stato IN ('perso','danneggiato')
            ");
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $loan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$loan || empty($loan['utente_email'])) {
                return false;
            }

            $email = (string) $loan['utente_email'];
            $locale = $this->resolveRecipientLocale($email);
            $language = substr($locale, 0, 2);
            $amount = (float) ($loan['sanzione'] ?? 0);
            $outcomes = [
                'it' => ['perso' => 'Perso', 'danneggiato' => 'Danneggiato'],
                'en' => ['perso' => 'Lost', 'danneggiato' => 'Damaged'],
                'de' => ['perso' => 'Verloren', 'danneggiato' => 'Beschädigt'],
                'fr' => ['perso' => 'Perdu', 'danneggiato' => 'Endommagé'],
                'da' => ['perso' => 'Bortkommet', 'danneggiato' => 'Beskadiget'],
            ];
            $noNotes = ['it' => 'Nessuna', 'en' => 'None', 'de' => 'Keine', 'fr' => 'Aucune', 'da' => 'Ingen'];
            $variables = [
                'utente_nome' => (string) $loan['utente_nome'],
                'libro_titolo' => (string) $loan['libro_titolo'],
                'esito_copia' => $outcomes[$language][(string) $loan['stato']]
                    ?? $outcomes['it'][(string) $loan['stato']],
                // data_restituzione is the canonical close date for every
                // terminal circulation outcome, including lost/damaged.
                'data_chiusura' => $this->formatEmailDate(
                    (string) ($loan['data_restituzione'] ?: DateHelper::today()),
                    false,
                    $locale
                ),
                'note' => trim((string) ($loan['note'] ?? '')) ?: ($noNotes[$language] ?? $noNotes['it']),
                // Importo zero: nessuna cifra "0,00 €" né inviti a saldare —
                // il placeholder diventa un testo localizzato esplicito.
                'sanzione' => $amount <= 0.0
                    ? $this->translateInLocale('Nessun addebito', $locale)
                    : number_format(
                        $amount,
                        2,
                        in_array(substr($locale, 0, 2), ['it', 'de', 'fr', 'da'], true) ? ',' : '.',
                        ''
                        // Valuta configurata (app.currency, come i prezzi dei
                        // libri): simbolo per EUR, codice ISO per il resto.
                    ) . (strtoupper((string) ConfigStore::get('app.currency', 'EUR')) === 'EUR'
                        ? ' €'
                        : ' ' . strtoupper((string) ConfigStore::get('app.currency', 'EUR'))),
            ];

            return $this->sendWithRetry($email, 'loan_copy_outcome', $variables);
        } catch (\Throwable $e) {
            SecureLogger::error('Failed to send lost/damaged copy notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invia notifica di prenotazione scaduta all'utente (GAP-2).
     * Il prestito schedulato (stato 'prenotato') è scaduto senza essere ritirato/convertito.
     */
    public function sendReservationExpiredNotification(int $loanId): bool {
        try {
            // CI-SOFT-DELETE-EXEMPT: this terminal expiry notice must reach the
            // borrower even after the book was soft-deleted — checkExpiredReservations()
            // deliberately expires scheduled loans on archived titles, so the
            // deleted_at filter here silently swallowed the email (#381 class).
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
    private function sendWithRetry(
        string $email,
        string $template,
        array $variables,
        int $maxRetries = 3,
        int $retryDelayMs = 1000,
        bool $persistFailure = true
    ): bool {
        // #360: render every attempt in the recipient's preferred language.
        $recipientLocale = $this->resolveRecipientLocale($email);
        $outboxId = null;
        $outboxClaimToken = null;
        if (
            $persistFailure
            && !in_array($template, self::DEDICATED_RETRY_TEMPLATES, true)
            && EmailOutboxSchema::ensure($this->db)
        ) {
            try {
                $outboxClaimToken = bin2hex(random_bytes(16));
                $claimedAt = gmdate('Y-m-d H:i:s');
                $json = json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $queue = $this->db->prepare("
                    INSERT INTO email_delivery_outbox
                        (recipient_email, template_name, variables_json, available_at, claim_token, claimed_at)
                    VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?)
                ");
                $queue->bind_param('sssss', $email, $template, $json, $outboxClaimToken, $claimedAt);
                $queue->execute();
                $outboxId = (int) $this->db->insert_id;
                $queue->close();
            } catch (\Throwable $e) {
                SecureLogger::warning('Unable to persist email before delivery', [
                    'template' => $template,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Circuit-breaker: if the SMTP server is unreachable, don't run the full retry cycle
        // (maxRetries attempts + 1s sleeps) for every recipient in a batch — they would all
        // fail the same way, hanging the run. Mailer::isSmtpReachable() probes once per
        // request (cached) and logs a single warning, so a down SMTP costs one short probe
        // instead of N × maxRetries connection timeouts.
        if (!\App\Support\Mailer::isSmtpReachable()) {
            if ($outboxId !== null && $outboxClaimToken !== null) {
                $this->releaseQueuedEmail($outboxId, $outboxClaimToken, 'SMTP unreachable', 300);
            }
            return false;
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($this->emailService->sendTemplate($email, $template, $variables, $recipientLocale)) {
                    if ($outboxId !== null) {
                        try {
                            $delete = $this->db->prepare('DELETE FROM email_delivery_outbox WHERE id = ? AND claim_token = ?');
                            $delete->bind_param('is', $outboxId, $outboxClaimToken);
                            $delete->execute();
                            $delete->close();
                        } catch (\Throwable $cleanupError) {
                            // Delivery already happened: never retry in this
                            // request merely because acknowledgement cleanup
                            // failed (that would create an immediate duplicate).
                            SecureLogger::warning('Delivered email outbox cleanup failed', [
                                'outbox_id' => $outboxId,
                                'error' => $cleanupError->getMessage(),
                            ]);
                        }
                    }
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
        if ($outboxId !== null && $outboxClaimToken !== null) {
            $this->releaseQueuedEmail($outboxId, $outboxClaimToken, $lastError, 300);
        }
        return false;
    }

    private function releaseQueuedEmail(int $outboxId, string $claimToken, string $error, int $delaySeconds): void
    {
        try {
            $retryAt = gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds));
            $update = $this->db->prepare("
                UPDATE email_delivery_outbox
                SET attempts = attempts + 1, available_at = ?, last_error = ?,
                    claim_token = NULL, claimed_at = NULL
                WHERE id = ? AND claim_token = ?
            ");
            $update->bind_param('ssis', $retryAt, $error, $outboxId, $claimToken);
            $update->execute();
            $update->close();
        } catch (\Throwable $e) {
            SecureLogger::warning('Unable to release failed email outbox row', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retry terminal circulation emails persisted by sendWithRetry().
     * Claims are leased, so hourly and daily maintenance may safely overlap.
     */
    public function retryQueuedEmailDeliveries(int $limit = 50): int
    {
        if ($limit <= 0 || !EmailOutboxSchema::ensure($this->db)) {
            return 0;
        }

        $limit = min($limit, 250);
        $staleBefore = gmdate('Y-m-d H:i:s', time() - 900);
        $select = $this->db->prepare("
            SELECT id
            FROM email_delivery_outbox
            WHERE available_at <= UTC_TIMESTAMP()
              AND (claim_token IS NULL OR claimed_at < ?)
            ORDER BY available_at, id
            LIMIT ?
        ");
        $select->bind_param('si', $staleBefore, $limit);
        $select->execute();
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $select->get_result()->fetch_all(MYSQLI_ASSOC)
        );
        $select->close();

        $sent = 0;
        foreach ($ids as $id) {
            $token = bin2hex(random_bytes(16));
            $claimedAt = gmdate('Y-m-d H:i:s');
            $claim = $this->db->prepare("
                UPDATE email_delivery_outbox
                SET claim_token = ?, claimed_at = ?
                WHERE id = ? AND available_at <= UTC_TIMESTAMP()
                  AND (claim_token IS NULL OR claimed_at < ?)
            ");
            $claim->bind_param('ssis', $token, $claimedAt, $id, $staleBefore);
            $claim->execute();
            $claimed = $claim->affected_rows === 1;
            $claim->close();
            if (!$claimed) {
                continue;
            }

            $rowStmt = $this->db->prepare("
                SELECT recipient_email, template_name, variables_json, attempts
                FROM email_delivery_outbox
                WHERE id = ? AND claim_token = ?
            ");
            $rowStmt->bind_param('is', $id, $token);
            $rowStmt->execute();
            $row = $rowStmt->get_result()->fetch_assoc();
            $rowStmt->close();
            if (!$row) {
                continue;
            }

            $variables = json_decode((string) $row['variables_json'], true);
            $ok = is_array($variables) && $this->sendWithRetry(
                (string) $row['recipient_email'],
                (string) $row['template_name'],
                $variables,
                1,
                0,
                false
            );
            if ($ok) {
                $delete = $this->db->prepare('DELETE FROM email_delivery_outbox WHERE id = ? AND claim_token = ?');
                $delete->bind_param('is', $id, $token);
                $delete->execute();
                $delete->close();
                $sent++;
                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= self::OUTBOX_MAX_ATTEMPTS) {
                $drop = $this->db->prepare('DELETE FROM email_delivery_outbox WHERE id = ? AND claim_token = ?');
                $drop->bind_param('is', $id, $token);
                $drop->execute();
                $drop->close();
                SecureLogger::error('Email outbox row discarded after max attempts', [
                    'outbox_id' => $id,
                    'template' => (string) $row['template_name'],
                    'attempts' => $attempts,
                ]);
                continue;
            }
            $delay = min(86400, 300 * (2 ** min($attempts, 8)));
            $retryAt = gmdate('Y-m-d H:i:s', time() + $delay);
            $error = is_array($variables) ? 'delivery failed' : 'invalid variables_json';
            $release = $this->db->prepare("
                UPDATE email_delivery_outbox
                SET attempts = ?, available_at = ?, claim_token = NULL,
                    claimed_at = NULL, last_error = ?
                WHERE id = ? AND claim_token = ?
            ");
            $release->bind_param('issis', $attempts, $retryAt, $error, $id, $token);
            $release->execute();
            $release->close();
        }

        return $sent;
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
                'link_approvazione' => absoluteUrl('/admin/reviews')
            ];

            // Send email to admins — data_recensione formatted per admin locale.
            $emailSent = $this->sendToAdmins('admin_new_review', $variables, [
                'data_recensione' => [$review['created_at'], true],
            ]);

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
