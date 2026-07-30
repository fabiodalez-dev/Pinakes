<?php
declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use mysqli;
use App\Support\ConfigStore;

class EmailService {
    private const RAW_HTML_VARIABLES = ['sezione_verifica'];

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

        // Numeric fields
        'days_remaining' => 'giorni_rimasti',
        'days_overdue' => 'giorni_ritardo',
        'loan_days' => 'giorni_prestito',
        'loan_id' => 'prestito_id',
        'stars' => 'stelle',

        // Review fields
        'review_date' => 'data_recensione',
        'review_title' => 'titolo_recensione',
        'review_description' => 'descrizione_recensione',
        'approval_link' => 'link_approvazione',

        // Other fields
        'rejection_reason' => 'motivo_rifiuto',
        'verify_section' => 'sezione_verifica',
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
     */
    public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null): bool {
        try {
            $template = $this->getEmailTemplate($templateName, $locale);
            if (!$template) {
                throw new Exception("Template '{$templateName}' not found");
            }

            // Il subject è un header MIME, non HTML: niente escaping HTML
            // (verrebbe mostrato letteralmente, es. "L&#039;isola"), ma le
            // variabili vanno sanificate contro header injection (CR/LF).
            $subject = $this->replaceVariables($template['subject'], $variables, false);
            $body = $this->replaceVariables($template['body'], $variables);

            return $this->sendEmail($to, $subject, $body, '', $locale);

        } catch (\Throwable $e) {
            error_log("Failed to send template email '{$templateName}' to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send plain email
     */
    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null): bool {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $toName);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $this->wrapInBaseTemplate($body, $subject, $locale);
            $this->mailer->AltBody = EmailLayout::plainText($body);

            return $this->mailer->send();

        } catch (\Throwable $e) {
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

            foreach ($candidateLocales as $candidateLocale) {
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
