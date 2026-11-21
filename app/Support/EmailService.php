<?php
declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use mysqli;
use App\Support\ConfigStore;

class EmailService {
    private const RAW_HTML_VARIABLES = ['sezione_verifica', 'sezione_calendario'];

    /**
     * Placeholder aliases: English -> Italian
     * This allows English-speaking operators to use English placeholder names
     * in email templates, which are mapped to the internal Italian names.
     */
    private const PLACEHOLDER_ALIASES = [
        // User fields
        'first_name' => 'nome',
        'last_name' => 'cognome',
        'user_name' => 'utente_nome',
        'user_email' => 'utente_email',
        'card_code' => 'codice_tessera',

        // Book fields
        'book_title' => 'libro_titolo',
        'book_author' => 'libro_autore',
        'book_isbn' => 'libro_isbn',

        // Date fields
        'due_date' => 'data_scadenza',
        'start_date' => 'data_inizio',
        'end_date' => 'data_fine',
        'loan_date' => 'data_prestito',
        'request_date' => 'data_richiesta',
        'registration_date' => 'data_registrazione',
        'availability_date' => 'data_disponibilita',
        'closed_at' => 'data_chiusura',

        // Numeric fields
        'days_remaining' => 'giorni_rimasti',
        'days_overdue' => 'giorni_ritardo',
        'loan_days' => 'giorni_prestito',
        'loan_id' => 'prestito_id',
        'stars' => 'stelle',
        'recall_number' => 'numero_sollecito',
        'penalty' => 'sanzione',

        // Review fields
        'review_date' => 'data_recensione',
        'review_title' => 'titolo_recensione',
        'review_description' => 'descrizione_recensione',
        'approval_link' => 'link_approvazione',

        // Other fields
        'reason' => 'motivo',
        'copy_outcome' => 'esito_copia',
        'rejection_reason' => 'motivo_rifiuto',
        'verify_section' => 'sezione_verifica',
        'calendar_links' => 'sezione_calendario',
    ];

    /**
     * Link placeholders whose URL is built with TrustedLink, and the plain-text
     * pointer (Italian source string, translated in the recipient's locale)
     * that replaces the button when the caller passes null for it because no
     * host the operator configured is available. A link in an email must never
     * take its host from the request.
     */
    public const LINK_FALLBACK_POINTERS = [
        'admin_users_url' => "Per gestire la richiesta apri l'area di amministrazione e vai su Utenti.",
        'approve_url' => "Per gestire la richiesta apri l'area di amministrazione e vai su Prestiti.",
        'link_approvazione' => "Per moderarla apri l'area di amministrazione e vai su Recensioni.",
        'login_url' => "Per accedere apri il sito della biblioteca e scegli Accedi.",
        'book_url' => 'Trovi il libro nel catalogo sul sito della biblioteca.',
        'wishlist_url' => 'La tua lista dei desideri è nel tuo profilo sul sito della biblioteca.',
        'profile_url' => 'Le tue prenotazioni sono nel tuo profilo sul sito della biblioteca.',
        'dashboard_url' => "Dopo aver impostato la password apri l'area di amministrazione dal sito della biblioteca.",
    ];

    private PHPMailer $mailer;
    private mysqli $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
        $this->mailer = new PHPMailer(true);
        $this->setupMailer();
    }

    private function setupMailer(): void {
        try {
            // Get email settings from database
            $settings = $this->getEmailSettings();

            if ($settings['type'] === 'smtp') {
                $this->mailer->isSMTP();
                $this->mailer->Host = $settings['smtp_host'];
                // Only authenticate when a username is configured. Forcing
                // SMTPAuth=true breaks auth-less relays (local MTAs, internal
                // smarthosts, Mailpit) — they reject the AUTH command. When the
                // username is empty, send unauthenticated.
                $smtpUsername = (string) $settings['smtp_username'];
                if ($smtpUsername !== '') {
                    $this->mailer->SMTPAuth = true;
                    $this->mailer->Username = $smtpUsername;
                    $this->mailer->Password = (string) $settings['smtp_password'];
                } else {
                    $this->mailer->SMTPAuth = false;
                    // Auth-less relays (local MTAs, internal smarthosts, Mailpit)
                    // usually accept plaintext: disable opportunistic STARTTLS so
                    // PHPMailer doesn't try to upgrade the connection and fail.
                    // Explicit encryption is still honoured via smtp_security below.
                    $this->mailer->SMTPAutoTLS = false;
                }
                $this->mailer->SMTPSecure = $settings['smtp_security'];
                $this->mailer->Port = (int)$settings['smtp_port'];
                // PHPMailer otherwise inherits a very generous socket timeout.
                // Recall batches run in an HTTP request, so a dead SMTP peer
                // must release the worker within a predictable bound.
                $smtpTimeout = min(10, max(1, (int) ConfigStore::get('mail.smtp.timeout', 10)));
                $this->mailer->Timeout = $smtpTimeout;
            } else {
                $this->mailer->isMail();
            }

            $this->mailer->setFrom($settings['from_email'], $settings['from_name']);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64'; // UTF-8 + base64 prevents charset mismatch (Ã¨ → è)

            // Force proper MIME headers
            $this->mailer->XMailer = ' '; // Hide X-Mailer header
            $this->mailer->ContentType = 'text/html'; // Explicit HTML content type

        } catch (\Throwable $e) {
            error_log('Email setup failed: ' . $e->getMessage());
        }
    }

    private function getEmailSettings(): array {
        $defaults = [
            'type' => 'mail',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_security' => 'tls',
            'from_email' => 'noreply@biblioteca.local',
            'from_name' => 'Pinakes'
        ];

        // Try to get settings from database
        try {
            $result = $this->db->query("SELECT * FROM system_settings WHERE category = 'email'");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $defaults[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (\Throwable $e) {
            error_log('Failed to load email settings: ' . $e->getMessage());
        }

        // Decrypt SMTP password if encrypted; keep plaintext as-is, clear broken ciphertext
        if (isset($defaults['smtp_password']) && is_string($defaults['smtp_password'])) {
            $rawPassword = $defaults['smtp_password'];
            $decrypted = SettingsEncryption::decrypt($rawPassword);
            if ($decrypted !== null) {
                $defaults['smtp_password'] = $decrypted;
            } elseif (str_starts_with($rawPassword, SettingsEncryption::PREFIX)) {
                SecureLogger::error('EmailService: failed to decrypt smtp_password');
                $defaults['smtp_password'] = '';
            }
        }

        return $defaults;
    }

    /**
     * Send email using template
     *
     * @param string $to Recipient email
     * @param string $templateName Template name
     * @param array $variables Variables to replace in template
     * @param string|null $locale Locale (it_IT, en_US). If null, uses current user's locale
     * @param array $attachments Each entry: ['content' => binary string, 'filename' => string, 'type' => MIME type]
     */
    public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool {
        try {
            $template = $this->getEmailTemplate($templateName, $locale);
            if (!$template) {
                throw new Exception("Template '{$templateName}' not found");
            }

            // Il subject è un header MIME, non HTML: niente escaping HTML
            // (verrebbe mostrato letteralmente, es. "L&#039;isola"), ma le
            // variabili vanno sanificate contro header injection (CR/LF).
            $body = self::dropUnavailableLinks((string) $template['body'], $variables, $locale);
            $subject = $this->replaceVariables($template['subject'], $variables, false);
            $body = $this->replaceVariables($body, $variables);

            return $this->sendEmail($to, $subject, $body, '', $locale, $attachments);

        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send template email '{$templateName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * For every link placeholder of LINK_FALLBACK_POINTERS whose variable is
     * null (no trusted host to build it from), replace its button in the raw
     * template body with the plain-text pointer, translated in $locale, and set
     * the variable to ''. Runs before substitution, so it sees placeholders,
     * never user data. A null survives the email outbox (JSON), so a retried
     * email is rendered the same way.
     *
     * @param array<string, mixed> $variables
     */
    public static function dropUnavailableLinks(string $body, array &$variables, ?string $locale): string
    {
        foreach ($variables as $key => $value) {
            if ($value !== null) {
                continue;
            }
            $variables[$key] = '';
            $name = self::PLACEHOLDER_ALIASES[$key] ?? (string) $key;
            if (!isset(self::LINK_FALLBACK_POINTERS[$name])) {
                continue;
            }
            $names = [$name];
            $alias = array_search($name, self::PLACEHOLDER_ALIASES, true);
            if (is_string($alias)) {
                $names[] = $alias;
            }
            $current = I18n::getLocale();
            try {
                if ($locale !== null && $locale !== '') {
                    I18n::setLocale($locale);
                }
                $pointer = __(self::LINK_FALLBACK_POINTERS[$name]);
            } finally {
                I18n::setLocale($current);
            }
            $body = self::replaceLinkPlaceholder($body, $names, $pointer);
        }
        return $body;
    }

    /**
     * Replace the link(s) whose href carries one of the $names placeholders
     * with the plain-text $pointer (escaped) — once, at the first link; later
     * links to the same placeholder are dropped. A stray placeholder elsewhere
     * is emptied; a template without such a link gets the pointer appended.
     *
     * @param list<string> $names
     */
    public static function replaceLinkPlaceholder(string $templateBody, array $names, string $pointer): string
    {
        $text = htmlspecialchars($pointer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $quoted = implode('|', array_map(static fn (string $n): string => preg_quote($n, '#'), $names));
        $placeholder = '(?:\{\{|%7B%7B)\s*(?:' . $quoted . ')\s*(?:\}\}|%7D%7D)';
        $count = 0;
        $body = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*["\']?[^"\'>]*' . $placeholder . '[^>]*>.*?</a>#is',
            static function () use (&$count, $text): string {
                return $count++ === 0 ? $text : '';
            },
            $templateBody
        );
        if (!is_string($body)) {
            $body = $templateBody;
            $count = 0;
        }
        $body = (string) preg_replace('#' . $placeholder . '#i', '', $body);
        if ($count === 0 && $text !== '') {
            $body .= '<p>' . $text . '</p>';
        }
        return $body;
    }

    /**
     * sendTemplate() with $bodyFilter applied to the template body BEFORE the
     * variables are substituted, so the filter sees the placeholders (e.g. to
     * drop a link whose URL cannot be built) and never user data.
     *
     * @param callable(string): string $bodyFilter
     */
    public function sendTemplateWithBodyFilter(string $to, string $templateName, array $variables, ?string $locale, callable $bodyFilter): bool {
        try {
            $template = $this->getEmailTemplate($templateName, $locale);
            if (!$template) {
                throw new Exception("Template '{$templateName}' not found");
            }
            $body = self::dropUnavailableLinks($bodyFilter((string) $template['body']), $variables, $locale);
            $subject = $this->replaceVariables($template['subject'], $variables, false);
            $body = $this->replaceVariables($body, $variables);

            return $this->sendEmail($to, $subject, $body, '', $locale);
        } catch (\Throwable $e) {
            SecureLogger::error("Failed to send template email '{$templateName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send plain email
     *
     * @param array $attachments Each entry: ['content' => binary string, 'filename' => string, 'type' => MIME type].
     *                           In-memory attachments (addStringAttachment): no temp files on disk.
     */
    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool {
        try {
            $this->mailer->clearAddresses();
            // The PHPMailer instance is reused across sends (e.g. sendToAdmins
            // loops): clear attachments from any previous message so they don't
            // leak into this one.
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($to, $toName);
            foreach ($attachments as $attachment) {
                $content = (string) ($attachment['content'] ?? '');
                if ($content === '') {
                    continue;
                }
                $this->mailer->addStringAttachment(
                    $content,
                    (string) ($attachment['filename'] ?? 'allegato'),
                    PHPMailer::ENCODING_BASE64,
                    (string) ($attachment['type'] ?? 'application/octet-stream')
                );
            }
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $this->wrapInBaseTemplate($body, $subject, $locale);
            $this->mailer->AltBody = EmailLayout::plainText($body);

            $sent = $this->mailer->send();
            $this->mailer->clearAttachments();
            return $sent;

        } catch (\Throwable $e) {
            $this->mailer->clearAttachments();
            error_log("Failed to send email to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email to all admins
     */
    public function sendToAdmins(string $subject, string $body): int {
        $sentCount = 0;

        try {
            $result = $this->db->query("SELECT email, CONCAT(nome, ' ', cognome) as name FROM utenti WHERE tipo_utente IN ('admin', 'staff') AND stato = 'attivo'");

            if (!$result) {
                return 0;
            }

            while ($row = $result->fetch_assoc()) {
                if ($this->sendEmail($row['email'], $subject, $body, $row['name'])) {
                    $sentCount++;
                }
            }

        } catch (\Throwable $e) {
            error_log("Failed to send admin emails: " . $e->getMessage());
        }

        return $sentCount;
    }

    /**
     * Get email template from database
     *
     * @param string $templateName Template name
     * @param string|null $locale Locale (it_IT, en_US). If null, uses current user's locale from I18n
     * @return array|null Array with 'subject' and 'body' keys, or null if not found
     */
    private function getEmailTemplate(string $templateName, ?string $locale = null): ?array {
        try {
            // Use current user's locale if not specified
            if ($locale === null) {
                $locale = \App\Support\I18n::getLocale();
            }

            // Catena di fallback: locale richiesto → fallback di variante
            // (it_* → it_IT, altrimenti en_US) → it_IT come ultima spiaggia.
            // L'ultimo tentativo su it_IT è necessario perché alcuni percorsi
            // storici (editor admin, migrazioni, ensureEmailTemplates) hanno
            // seminato/aggiornato righe solo con locale='it_IT': senza questo
            // passo un'installazione en_US/de_DE/fr_FR non troverebbe MAI quei
            // template e l'email andrebbe persa silenziosamente.
            $candidateLocales = [$locale];
            if ($locale !== 'it_IT' && $locale !== 'en_US') {
                $candidateLocales[] = str_starts_with($locale, 'it_') ? 'it_IT' : 'en_US';
            }
            if (!in_array('it_IT', $candidateLocales, true)) {
                $candidateLocales[] = 'it_IT';
            }

            foreach ($candidateLocales as $index => $candidateLocale) {
                // #360: after missing a stored row for the EXACT requested
                // locale, prefer the shipped default translated in that locale
                // over another locale's stored row. Installs seed
                // email_templates only for the installation language, so
                // without this the chain always landed on the installation row
                // and per-recipient localization never materialized (a German
                // recipient got the seeded Italian template). Cross-locale
                // admin customizations still win for locales with no shipped
                // texts (hasShippedLocale gate), and an exact-locale stored
                // row — customized or seeded — always wins above.
                // When the next candidate is a same-language variant (it_CH ->
                // it_IT) the stored row IS the recipient's language: let it
                // win, customizations included. Only a language change (en_US
                // recipient falling toward an it_IT row) triggers the shipped
                // default.
                if ($index === 1 && substr($candidateLocale, 0, 2) !== substr($locale, 0, 2) && \App\Support\SettingsMailTemplates::hasShippedLocale($locale)) {
                    $shipped = \App\Support\SettingsMailTemplates::get($templateName, $locale);
                    if ($shipped !== null) {
                        return ['subject' => (string) $shipped['subject'], 'body' => (string) $shipped['body']];
                    }
                }

                $stmt = $this->db->prepare("SELECT subject, body FROM email_templates WHERE name = ? AND locale = ? AND active = 1");
                $stmt->bind_param('ss', $templateName, $candidateLocale);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    return $row;
                }
            }

            // Fallback to default templates
            return $this->getDefaultTemplate($templateName, $locale);

        } catch (\Throwable $e) {
            error_log("Failed to get template {$templateName}: " . $e->getMessage());
            return $this->getDefaultTemplate($templateName, $locale);
        }
    }

    /**
     * Default email templates
     */
    private function getDefaultTemplate(string $templateName, ?string $locale = null): ?array {
        // Single source of truth: the same templates the settings UI manages
        // and ensureEmailTemplates seeds, resolved for $locale — so this
        // last-resort fallback is translated too. Previously it was a second,
        // Italian-only hardcoded copy that drifted from SettingsMailTemplates.
        $tpl = \App\Support\SettingsMailTemplates::get($templateName, $locale);
        if ($tpl === null) {
            return null;
        }
        return ['subject' => (string) $tpl['subject'], 'body' => (string) $tpl['body']];
    }

    /**
     * Replace template variables
     *
     * Supports both Italian placeholder names (e.g., {{libro_titolo}})
     * and English aliases (e.g., {{book_title}}) for international operators.
     * Variables can be passed with either Italian or English keys.
     *
     * Con $escapeHtml = false (usato per il Subject, che è un header MIME e
     * non HTML) i valori non vengono HTML-escaped ma vengono ripuliti da
     * CR/LF per impedire header injection.
     */
    /**
     * #299: repair a template corrupted by a WYSIWYG editor that resolved a
     * placeholder URL against the admin page — e.g. `href="{{login_url}}"` was
     * saved as `href="https://host/admin/{{login_url}}"`, so substituting the
     * (already absolute) URL produced a double-prefixed link. Strip an absolute
     * `…/admin/` prefix sitting directly in front of a `{{placeholder}}`. The
     * editor no longer does this (convert_urls:false); this heals values already
     * saved, both at render time and when repairing stored templates in the DB.
     * A legitimate template never puts a host+`/admin/` in front of a token, so
     * this is safe. Returns the input unchanged when nothing matches.
     */
    public static function healPlaceholderUrls(string $content): string
    {
        return preg_replace(
            '#https?://[^"\'\s<>]*?/admin/(\{\{[a-zA-Z0-9_]+\}\})#',
            '$1',
            $content
        ) ?? $content;
    }

    public function replaceVariables(string $content, array $variables, bool $escapeHtml = true): string {
        // Normalize English variable keys to Italian names for consistent processing
        // This ensures RAW_HTML_VARIABLES check works regardless of input key language
        $normalizedVariables = [];
        foreach ($variables as $key => $value) {
            $italianKey = self::PLACEHOLDER_ALIASES[$key] ?? $key;
            $normalizedVariables[$italianKey] = $value;
        }

        // Cache the inverted map (Italian → English) for O(1) lookups
        static $italianToEnglish = null;
        if ($italianToEnglish === null) {
            $italianToEnglish = array_flip(self::PLACEHOLDER_ALIASES);
        }

        // #299: heal templates corrupted by a WYSIWYG editor that resolved a
        // placeholder URL against the admin page (see healPlaceholderUrls).
        $content = self::healPlaceholderUrls($content);

        foreach ($normalizedVariables as $key => $value) {
            if ($escapeHtml) {
                $replacement = in_array($key, self::RAW_HTML_VARIABLES, true)
                    ? (string)$value
                    : htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                // Contesto header (Subject): niente entità HTML, ma nessun
                // CR/LF deve arrivare a PHPMailer da input utente.
                $replacement = str_replace(["\r", "\n"], '', (string)$value);
            }

            // Replace the Italian placeholder
            $content = str_replace('{{' . $key . '}}', $replacement, $content);

            // Also replace the English alias if one exists
            if (isset($italianToEnglish[$key])) {
                $content = str_replace('{{' . $italianToEnglish[$key] . '}}', $replacement, $content);
            }
        }
        return $content;
    }

    /**
     * Wrap content in base HTML template
     */
    private function wrapInBaseTemplate(string $content, string $subject, ?string $locale = null): string {
        return EmailLayout::render($content, $subject, $locale);
    }

    /**
     * Create email templates table if not exists
     */
    public function createEmailTemplatesTable(): bool {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS email_templates (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    subject VARCHAR(255) NOT NULL,
                    body TEXT NOT NULL,
                    description TEXT,
                    active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ";
            return $this->db->query($sql) !== false;

        } catch (\Throwable $e) {
            error_log("Failed to create email_templates table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create system settings table if not exists
     */
    public function createSystemSettingsTable(): bool {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS system_settings (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    category VARCHAR(50) NOT NULL,
                    setting_key VARCHAR(100) NOT NULL,
                    setting_value TEXT,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_setting (category, setting_key)
                )
            ";
            return $this->db->query($sql) !== false;

        } catch (\Throwable $e) {
            error_log("Failed to create system_settings table: " . $e->getMessage());
            return false;
        }
    }
}
