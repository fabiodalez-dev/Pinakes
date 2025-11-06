<?php
declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use mysqli;
use App\Support\ConfigStore;

class EmailService {
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
                $this->mailer->SMTPAuth = true;
                $this->mailer->Username = $settings['smtp_username'];
                $this->mailer->Password = $settings['smtp_password'];
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

        } catch (Exception $e) {
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
            'from_name' => 'Sistema Biblioteca'
        ];

        // Try to get settings from database
        try {
            $result = $this->db->query("SELECT * FROM system_settings WHERE category = 'email'");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $defaults[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (Exception $e) {
            error_log('Failed to load email settings: ' . $e->getMessage());
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

            $subject = $this->replaceVariables($template['subject'], $variables);
            $body = $this->replaceVariables($template['body'], $variables);

            return $this->sendEmail($to, $subject, $body);

        } catch (Exception $e) {
            error_log("Failed to send template email '{$templateName}' to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send plain email
     */
    public function sendEmail(string $to, string $subject, string $body, string $toName = ''): bool {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $toName);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $this->wrapInBaseTemplate($body, $subject);

            return $this->mailer->send();

        } catch (Exception $e) {
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

        } catch (Exception $e) {
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

            // Try to get template in requested locale
            $stmt = $this->db->prepare("SELECT subject, body FROM email_templates WHERE name = ? AND locale = ? AND active = 1");
            $stmt->bind_param('ss', $templateName, $locale);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                return $row;
            }

            // Fallback to Italian if requested locale not found
            if ($locale !== 'it_IT') {
                $stmt = $this->db->prepare("SELECT subject, body FROM email_templates WHERE name = ? AND locale = 'it_IT' AND active = 1");
                $stmt->bind_param('s', $templateName);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    return $row;
                }
            }

            // Fallback to default templates
            return $this->getDefaultTemplate($templateName);

        } catch (Exception $e) {
            error_log("Failed to get template {$templateName}: " . $e->getMessage());
            return $this->getDefaultTemplate($templateName);
        }
    }

    /**
     * Default email templates
     */
    private function getDefaultTemplate(string $templateName): ?array {
        $templates = [
            'user_registration_pending' => [
                'subject' => 'Registrazione ricevuta - In attesa di approvazione',
                'body' => '
                    <h2>Benvenuto {{nome}} {{cognome}}!</h2>
                    <p>La tua richiesta di registrazione è stata ricevuta con successo.</p>
                    <p><strong>Dettagli account:</strong></p>
                    <ul>
                        <li>Email: {{email}}</li>
                        <li>Codice tessera: {{codice_tessera}}</li>
                        <li>Data registrazione: {{data_registrazione}}</li>
                    </ul>
                    {{verify_section}}
                    <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>⏳ Account in attesa di approvazione</strong></p>
                        <p>Il tuo account è in attesa di approvazione da parte di un amministratore.
                        Riceverai una email di conferma una volta che l\'account sarà stato attivato.</p>
                    </div>
                    <p>Grazie per aver scelto il nostro sistema biblioteca!</p>
                '
            ],
            'user_password_setup' => [
                'subject' => 'Imposta la tua password per {{app_name}}',
                'body' => '
                    <h2>Ciao {{nome}} {{cognome}}</h2>
                    <p>Abbiamo creato il tuo account su {{app_name}}.</p>
                    <p>Per accedere per la prima volta devi impostare una password cliccando sul pulsante qui sotto:</p>
                    <p style="margin: 20px 0;">
                        <a href="{{reset_url}}" style="background-color: #111827; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">Imposta password</a>
                    </p>
                    <p>Se non hai richiesto questo accesso, puoi ignorare questa email.</p>
                '
            ],
            'admin_invitation' => [
                'subject' => 'Accesso amministratore a {{app_name}}',
                'body' => '
                    <h2>Benvenuto nello staff di {{app_name}}</h2>
                    <p>Ciao {{nome}} {{cognome}},</p>
                    <p>È stato creato per te un account amministratore.</p>
                    <p>Clicca sul pulsante seguente per impostare la tua password e completare l\'attivazione:</p>
                    <p style="margin: 20px 0;">
                        <a href="{{reset_url}}" style="background-color: #111827; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px;">Imposta password amministratore</a>
                    </p>
                    <p>Dopo aver impostato la password potrai accedere alla dashboard: <a href="{{dashboard_url}}">{{dashboard_url}}</a></p>
                    <p>Se hai ricevuto questa email per errore, contatta immediatamente l\'amministrazione.</p>
                '
            ],
            'user_account_approved' => [
                'subject' => 'Account approvato - Benvenuto in biblioteca!',
                'body' => '
                    <h2>Il tuo account è stato approvato!</h2>
                    <p>Ciao {{nome}} {{cognome}},</p>
                    <p>Siamo lieti di informarti che il tuo account è stato approvato da un amministratore.</p>
                    <p>Ora puoi accedere al sistema e iniziare a prenotare libri!</p>
                    <p><strong>Dettagli del tuo account:</strong></p>
                    <ul>
                        <li>Email: {{email}}</li>
                        <li>Codice tessera: {{codice_tessera}}</li>
                    </ul>
                    <p><a href="{{login_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Accedi ora</a></p>
                    <p>Benvenuto nella nostra biblioteca digitale!</p>
                '
            ],
            'loan_request_notification' => [
                'subject' => '📚 Nuova richiesta di prestito',
                'body' => '
                    <h2>Nuova richiesta di prestito</h2>
                    <p>È stata ricevuta una nuova richiesta di prestito:</p>
                    <p><strong>Dettagli:</strong></p>
                    <ul>
                        <li>Libro: {{libro_titolo}}</li>
                        <li>Utente: {{utente_nome}} ({{utente_email}})</li>
                        <li>Data richiesta inizio: {{data_inizio}}</li>
                        <li>Data richiesta fine: {{data_fine}}</li>
                        <li>Data richiesta: {{data_richiesta}}</li>
                    </ul>
                    <p><a href="{{approve_url}}" style="background-color: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gestisci Richiesta</a></p>
                '
            ],
            'loan_expiring_warning' => [
                'subject' => '⚠️ Il tuo prestito sta per scadere',
                'body' => '
                    <h2>Promemoria scadenza prestito</h2>
                    <p>Ciao {{utente_nome}},</p>
                    <p>Ti ricordiamo che il tuo prestito sta per scadere:</p>
                    <p><strong>Dettagli prestito:</strong></p>
                    <ul>
                        <li>Libro: {{libro_titolo}}</li>
                        <li>Data scadenza: {{data_scadenza}}</li>
                        <li>Giorni rimasti: {{giorni_rimasti}}</li>
                    </ul>
                    <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>⏰ Azione richiesta</strong></p>
                        <p>Per evitare penali, restituisci il libro entro la data di scadenza o contatta la biblioteca per un eventuale rinnovo.</p>
                    </div>
                    <p>Grazie per la collaborazione!</p>
                '
            ],
            'loan_overdue_notification' => [
                'subject' => '🚨 Prestito scaduto - Azione richiesta',
                'body' => '
                    <h2>Prestito scaduto</h2>
                    <p>Ciao {{utente_nome}},</p>
                    <p>Il tuo prestito è scaduto e deve essere restituito immediatamente:</p>
                    <p><strong>Dettagli prestito:</strong></p>
                    <ul>
                        <li>Libro: {{libro_titolo}}</li>
                        <li>Data scadenza: {{data_scadenza}}</li>
                        <li>Giorni di ritardo: {{giorni_ritardo}}</li>
                    </ul>
                    <div style="background-color: #fef2f2; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ef4444;">
                        <p><strong>🚨 Azione urgente richiesta</strong></p>
                        <p>Il libro deve essere restituito immediatamente. Il ritardo nella restituzione può comportare sanzioni e la sospensione del servizio.</p>
                    </div>
                    <p>Contatta immediatamente la biblioteca per risolvere la situazione.</p>
                '
            ],
            'admin_new_registration' => [
                'subject' => '👤 Nuova richiesta di registrazione',
                'body' => '
                    <h2>Nuova richiesta di registrazione</h2>
                    <p>Un nuovo utente ha richiesto l\'accesso al sistema biblioteca:</p>
                    <p><strong>Dettagli utente:</strong></p>
                    <ul>
                        <li>Nome: {{nome}} {{cognome}}</li>
                        <li>Email: {{email}}</li>
                        <li>Codice tessera: {{codice_tessera}}</li>
                        <li>Data richiesta: {{data_registrazione}}</li>
                    </ul>
                    <p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Gestisci Utenti</a></p>
                '
            ],
            'wishlist_book_available' => [
                'subject' => '📖 Libro della tua wishlist ora disponibile!',
                'body' => '
                    <h2>Buone notizie!</h2>
                    <p>Ciao {{utente_nome}},</p>
                    <p>Il libro che hai aggiunto alla tua wishlist è ora disponibile per il prestito:</p>
                    <div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
                        <h3 style="color: #1e40af; margin: 0 0 10px 0;">{{libro_titolo}}</h3>
                        <p style="margin: 5px 0;"><strong>Autore:</strong> {{libro_autore}}</p>
                        <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
                        <p style="margin: 5px 0;"><strong>Disponibile da:</strong> {{data_disponibilita}}</p>
                    </div>
                    <div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>✨ Prenota subito!</strong></p>
                        <p>Il libro è ora disponibile per la prenotazione. Affrettati prima che qualcun altro lo prenoti!</p>
                    </div>
                    <p style="text-align: center;">
                        <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Prenota Ora</a>
                        <a href="{{wishlist_url}}" style="background-color: #6b7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">❤️ Gestisci Wishlist</a>
                    </p>
                    <p><em>Questo libro è stato automaticamente rimosso dalla tua wishlist.</em></p>
                '
            ],
            'reservation_book_available' => [
                'subject' => '📚 Il tuo libro prenotato è ora disponibile!',
                'body' => '
                    <h2>🎉 Buone notizie!</h2>
                    <p>Ciao {{utente_nome}},</p>
                    <p>Il libro che hai prenotato è ora disponibile per il prestito!</p>
                    
                    <div style="background-color: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;">
                        <h3 style="color: #1e40af; margin: 0 0 10px 0;">📖 {{libro_titolo}}</h3>
                        <p style="margin: 5px 0;"><strong>Autore:</strong> {{libro_autore}}</p>
                        <p style="margin: 5px 0;"><strong>ISBN:</strong> {{libro_isbn}}</p>
                        <p style="margin: 5px 0;"><strong>Periodo richiesto:</strong> {{data_inizio}} - {{data_fine}}</p>
                    </div>

                    <div style="background-color: #ecfdf5; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>✅ Cosa fare ora?</strong></p>
                        <p>Il libro è stato automaticamente prenotato per te. Puoi:</p>
                        <ul>
                            <li>Venire a ritirare il libro</li>
                            <li>Accedere al tuo account per confermare i dettagli</li>
                            <li>Contattare la biblioteca per organizzare il ritiro</li>
                        </ul>
                    </div>

                    <p style="text-align: center;">
                        <a href="{{book_url}}" style="background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">📚 Vedi Dettagli Libro</a>
                        <a href="{{profile_url}}" style="background-color: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; margin: 10px;">👤 Il Mio Profilo</a>
                    </p>

                    <div style="background-color: #fef3c7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <p><strong>⏰ Nota importante</strong></p>
                        <p>Hai 3 giorni di tempo per ritirare il libro prima che venga offerto al prossimo in coda.</p>
                    </div>

                    <p>Grazie per la pazienza! 😊</p>
                '
            ]
        ];

        return $templates[$templateName] ?? null;
    }

    /**
     * Replace template variables
     */
    private function replaceVariables(string $content, array $variables): string {
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', (string)$value, $content);
        }
        return $content;
    }

    /**
     * Wrap content in base HTML template
     */
    private function wrapInBaseTemplate(string $content, string $subject): string {
        $appName = ConfigStore::get('app.name', 'Biblioteca');
        $appLogo = (string)ConfigStore::get('app.logo', '');
        $logoHtml = '';
        if ($appLogo !== '') {
            $logoSrc = $appLogo;
            if (!preg_match('/^https?:\\/\\//i', $logoSrc)) {
                $logoSrc = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($logoSrc, '/');
            }
            $logoHtml = "<img src='" . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . "' alt='" . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . "' style='max-height:60px; margin-bottom: 10px;'>";
        } else {
            $logoHtml = "<h1 style='color: #1f2937; margin: 0;'>" . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . "</h1>";
        }

        return "
        <!DOCTYPE html>
        <html lang='it'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$subject}</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <div style='background-color: #f8fafc; border-radius: 10px; padding: 30px; margin-bottom: 20px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    {$logoHtml}
                </div>
                {$content}
            </div>
            <div style='text-align: center; font-size: 12px; color: #6b7280; margin-top: 20px;'>
                <p>Questa email è stata generata automaticamente da {$appName}.</p>
                <p>Per assistenza, contatta l'amministrazione della biblioteca.</p>
            </div>
        </body>
        </html>";
    }

    private function getBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $allowedHosts = [
            'localhost',
            'localhost:8000',
            'localhost:8001',
            'biblioteca.local',
            'biblioteca.fabiodalez.it',
        ];
        if (!in_array($host, $allowedHosts, true)) {
            $host = 'localhost:8000';
        }
        return $protocol . '://' . $host;
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

        } catch (Exception $e) {
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

        } catch (Exception $e) {
            error_log("Failed to create system_settings table: " . $e->getMessage());
            return false;
        }
    }
}
