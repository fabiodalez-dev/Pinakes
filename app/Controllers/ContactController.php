<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\ConfigStore;
use App\Support\Csrf;
use App\Support\CsrfHelper;
use App\Support\RouteTranslator;
use ReCaptcha\ReCaptcha;

class ContactController
{
    public function showPage(Request $request, Response $response): Response
    {
        $config = ConfigStore::get('contacts', []);

        $title = $config['page_title'] ?? 'Contattaci';
        $content = $config['page_content'] ?? '';
        $contactEmail = $config['contact_email'] ?? '';
        $contactPhone = $config['contact_phone'] ?? '';
        $googleMapsEmbed = $config['google_maps_embed'] ?? '';
        $privacyText = $config['privacy_text'] ?? '';
        $recaptchaSiteKey = $config['recaptcha_site_key'] ?? '';

        ob_start();
        include __DIR__ . '/../Views/frontend/contact.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    public function submitForm(Request $request, Response $response, mysqli $db): Response
    {
        $data = $request->getParsedBody();

        // Validazione CSRF
        if (!Csrf::validate($data['csrf_token'] ?? '')) {
            return $response
                ->withHeader('Location', RouteTranslator::route('contact')?error=csrf')
                ->withStatus(302);
        }

        // Validazione ReCAPTCHA v3
        $config = ConfigStore::get('contacts', []);
        $recaptchaSecret = $config['recaptcha_secret_key'] ?? '';

        if (!empty($recaptchaSecret)) {
            $recaptchaToken = $data['recaptcha_token'] ?? '';

            if (empty($recaptchaToken)) {
                return $response
                    ->withHeader('Location', RouteTranslator::route('contact')?error=recaptcha')
                    ->withStatus(302);
            }

            $recaptcha = new ReCaptcha($recaptchaSecret);
            $resp = $recaptcha->setExpectedAction('contact_form')
                             ->setScoreThreshold(0.5)
                             ->verify($recaptchaToken, $_SERVER['REMOTE_ADDR'] ?? '');

            if (!$resp->isSuccess()) {
                return $response
                    ->withHeader('Location', RouteTranslator::route('contact')?error=recaptcha')
                    ->withStatus(302);
            }
        }

        // Validazione campi
        $nome = trim($data['nome'] ?? '');
        $cognome = trim($data['cognome'] ?? '');
        $email = trim($data['email'] ?? '');
        $telefono = trim($data['telefono'] ?? '');
        $indirizzo = trim($data['indirizzo'] ?? '');
        $messaggio = trim($data['messaggio'] ?? '');
        $privacyAccepted = isset($data['privacy']) ? 1 : 0;

        if (empty($nome) || empty($cognome) || empty($email) || empty($messaggio)) {
            return $response
                ->withHeader('Location', RouteTranslator::route('contact')?error=required')
                ->withStatus(302);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $response
                ->withHeader('Location', RouteTranslator::route('contact')?error=email')
                ->withStatus(302);
        }

        if (!$privacyAccepted) {
            return $response
                ->withHeader('Location', RouteTranslator::route('contact')?error=privacy')
                ->withStatus(302);
        }

        // Salva nel database
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $db->prepare("
            INSERT INTO contact_messages
            (nome, cognome, email, telefono, indirizzo, messaggio, privacy_accepted, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'sssssssss',
            $nome,
            $cognome,
            $email,
            $telefono,
            $indirizzo,
            $messaggio,
            $privacyAccepted,
            $ipAddress,
            $userAgent
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return $response
                ->withHeader('Location', RouteTranslator::route('contact')?error=db')
                ->withStatus(302);
        }

        $messageId = $db->insert_id;
        $stmt->close();

        // Invia email di notifica all'admin
        $this->sendNotificationEmail($nome, $cognome, $email, $telefono, $indirizzo, $messaggio, $messageId);

        // Crea notifica in-app per l'admin
        $this->createInAppNotification($nome, $cognome, $email, $messageId, $db);

        return $response
            ->withHeader('Location', RouteTranslator::route('contact')?success=1')
            ->withStatus(302);
    }

    private function sendNotificationEmail(
        string $nome,
        string $cognome,
        string $email,
        string $telefono,
        string $indirizzo,
        string $messaggio,
        int $messageId
    ): void {
        try {
            $config = ConfigStore::get('contacts', []);
            $notificationEmail = $config['notification_email'] ?? '';

            if (empty($notificationEmail)) {
                return;
            }

            $appName = ConfigStore::get('app.name', 'Biblioteca');
            $nomeSafe = $this->sanitizeMailField($nome);
            $cognomeSafe = $this->sanitizeMailField($cognome);
            $emailSafe = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
            $telefonoSafe = htmlspecialchars($this->sanitizeMailField($telefono), ENT_QUOTES, 'UTF-8');
            $indirizzoSafe = htmlspecialchars($this->sanitizeMailField($indirizzo), ENT_QUOTES, 'UTF-8');
            $appNameSafe = $this->sanitizeMailField($appName);

            $subject = "Nuovo messaggio da {$nomeSafe} {$cognomeSafe} - {$appNameSafe}";

            $body = "
                <h2>Nuovo messaggio di contatto</h2>
                <p><strong>Da:</strong> " . htmlspecialchars($nomeSafe . ' ' . $cognomeSafe, ENT_QUOTES, 'UTF-8') . "</p>
                <p><strong>Email:</strong> " . ($emailSafe !== '' ? '<a href=\'mailto:' . htmlspecialchars($emailSafe, ENT_QUOTES, 'UTF-8') . '\'>' . htmlspecialchars($emailSafe, ENT_QUOTES, 'UTF-8') . '</a>' : 'N/D') . "</p>
                " . (!empty($telefonoSafe) ? "<p><strong>Telefono:</strong> {$telefonoSafe}</p>" : "") . "
                " . (!empty($indirizzoSafe) ? "<p><strong>Indirizzo:</strong> {$indirizzoSafe}</p>" : "") . "
                <p><strong>Messaggio:</strong></p>
                <p>" . nl2br(htmlspecialchars($messaggio, ENT_QUOTES, 'UTF-8')) . "</p>
                <hr>
                <p><small>Messaggio ID: #" . htmlspecialchars((string)$messageId, ENT_QUOTES, 'UTF-8') . "</small></p>
            ";

            $mailer = new \App\Support\Mailer();
            $mailer->send($notificationEmail, $subject, $body);

        } catch (\Exception $e) {
            error_log("Errore invio email contatto: " . $e->getMessage());
        }
    }

    private function createInAppNotification(
        string $nome,
        string $cognome,
        string $email,
        int $messageId,
        mysqli $db
    ): void {
        try {
            $notificationService = new \App\Support\NotificationService($db);
            $notificationService->notifyNewContactMessage(
                $messageId,
                $this->sanitizeMailField($nome . ' ' . $cognome),
                $this->sanitizeMailField($email)
            );
        } catch (\Exception $e) {
            error_log("Errore creazione notifica in-app: " . $e->getMessage());
        }
    }

    private function sanitizeMailField(string $value): string
    {
        $clean = str_replace(["\r", "\n"], ' ', $value);
        return trim($clean);
    }
}
