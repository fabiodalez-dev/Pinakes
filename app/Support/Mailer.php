<?php
declare(strict_types=1);

namespace App\Support;

final class Mailer
{
    /** Per-request cache of the SMTP reachability probe (null = not yet checked). */
    private static ?bool $smtpReachable = null;

    /**
     * Whether the configured SMTP server accepts a TCP connection. Used as a circuit-breaker
     * before a notification BATCH so a down/misconfigured SMTP costs one short probe instead
     * of hammering it with a full retry cycle for every recipient. Cached per request, and
     * always true for the `mail` driver (no host to probe). Logs once when it trips.
     */
    public static function isSmtpReachable(): bool
    {
        if (self::$smtpReachable !== null) {
            return self::$smtpReachable;
        }
        $driver = (string)ConfigStore::get('mail.driver', 'mail');
        if ($driver !== 'smtp' && $driver !== 'phpmailer') {
            return self::$smtpReachable = true;
        }
        $host = (string)ConfigStore::get('mail.smtp.host', '');
        if ($host === '') {
            return self::$smtpReachable = true; // no host configured — let the send path decide
        }
        $port = (int)ConfigStore::get('mail.smtp.port', 587);
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 4, STREAM_CLIENT_CONNECT);
        if ($fp) {
            fclose($fp);
            return self::$smtpReachable = true;
        }
        SecureLogger::warning("SMTP host {$host}:{$port} unreachable ({$errstr}) — skipping email sends this run");
        return self::$smtpReachable = false;
    }

    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $fromEmail = (string)ConfigStore::get('mail.from_email', 'no-reply@localhost');
        $fromName  = (string)ConfigStore::get('mail.from_name', 'Biblioteca');
        $driver    = (string)ConfigStore::get('mail.driver', 'mail');

        // Both 'smtp' and 'phpmailer' go through PHPMailer. It verifies the SMTP
        // handshake, AUTH and recipient and fails loudly — unlike the old hand-
        // rolled SMTP path, which never checked server replies and could report
        // success on a silently-rejected message (the "SMTP looks fine but no mail
        // arrives" class of bug).
        if ($driver === 'smtp' || $driver === 'phpmailer') {
            return self::sendPHPMailer($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
        }
        return self::sendMail($to, $subject, $htmlBody, $fromEmail, $fromName);
    }

    private static function sendMail(string $to, string $subject, string $htmlBody, string $fromEmail, string $fromName): bool
    {
        // Use more secure boundary generation
        $boundary = uniqid('mail_boundary_', true);
        $headers = [];
        $headers[] = 'From: ' . self::encodeHeader($fromName) . " <{$fromEmail}>";
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $textPart = strip_tags($htmlBody);
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n{$textPart}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=utf-8\r\n\r\n{$htmlBody}\r\n";
        $body .= "--{$boundary}--\r\n";

        return @mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers));
    }

    private static function encodeHeader(string $s): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }

    /**
     * Configure a PHPMailer instance from the stored mail settings (SMTP when a
     * host is set, otherwise PHP mail()). Shared by sendPHPMailer() and sendTest().
     */
    private static function configureMailer(\PHPMailer\PHPMailer\PHPMailer $mailer, string $fromEmail, string $fromName): void
    {
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->isHTML(true);

        $host = (string) ConfigStore::get('mail.smtp.host', '');
        if ($host !== '') {
            $mailer->isSMTP();
            // PHPMailer's default Timeout is 300s: if the SMTP host accepts the TCP
            // connection but then stalls, a single send would block the (cron) request
            // for 5 minutes. Cap it.
            $mailer->Timeout = (int) ConfigStore::get('mail.smtp.timeout', 10);
            $mailer->Host = $host;
            $mailer->Port = (int) ConfigStore::get('mail.smtp.port', 587);
            $enc = (string) ConfigStore::get('mail.smtp.encryption', 'tls');
            if ($enc === 'ssl') { $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; }
            elseif ($enc === 'tls') { $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }
            else { $mailer->SMTPSecure = ''; $mailer->SMTPAutoTLS = false; }
            $user = (string) ConfigStore::get('mail.smtp.username', '');
            $pass = (string) ConfigStore::get('mail.smtp.password', '');
            if ($user !== '') { $mailer->SMTPAuth = true; $mailer->Username = $user; $mailer->Password = $pass; }
        }
    }

    private static function sendPHPMailer(string $to, string $subject, string $htmlBody, ?string $textBody, string $fromEmail, string $fromName): bool
    {
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            // Fallback to mail()
            return self::sendMail($to, $subject, $htmlBody, $fromEmail, $fromName);
        }
        $result = self::dispatchPHPMailer($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
        if (!$result['ok']) {
            SecureLogger::warning('Email send failed: ' . $result['error']);
        }
        return $result['ok'];
    }

    /**
     * Build and send one PHPMailer message. Single source of truth for the real
     * send path, so the diagnostic sendTest() exercises exactly what production
     * uses — if CC/BCC/AltBody/etc. change here they can't silently drift apart.
     * Returns the real outcome (the specific error on failure), never throws.
     *
     * @return array{ok: bool, error: string}
     */
    private static function dispatchPHPMailer(string $to, string $subject, string $htmlBody, ?string $textBody, string $fromEmail, string $fromName): array
    {
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            self::configureMailer($mailer, $fromEmail, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $textBody ?: strip_tags($htmlBody);
            $mailer->send();
            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a diagnostic test email with the CURRENT mail settings and return the
     * real outcome — the specific error on failure, not just a boolean — so the
     * admin can tell a bad SMTP config from a working one. Verifies the SMTP
     * handshake/auth/recipient via PHPMailer.
     *
     * @return array{ok: bool, error: string}
     */
    public static function sendTest(string $to): array
    {
        $fromEmail = (string) ConfigStore::get('mail.from_email', 'no-reply@localhost');
        $fromName  = (string) ConfigStore::get('mail.from_name', 'Biblioteca');
        $driver    = (string) ConfigStore::get('mail.driver', 'mail');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => __('Indirizzo email del destinatario non valido.')];
        }

        $subject = __('Email di prova da Pinakes');
        $html = '<p>' . __('Questa è un\'email di prova inviata dalle impostazioni di Pinakes. Se la ricevi, la configurazione email funziona.') . '</p>';

        // SMTP / PHPMailer path: reuse the exact real send path so the test is a
        // faithful diagnostic, and capture its real handshake/auth/recipient error.
        if (($driver === 'smtp' || $driver === 'phpmailer') && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return self::dispatchPHPMailer($to, $subject, $html, null, $fromEmail, $fromName);
        }

        // Plain mail() path: no detailed error is available, only success/failure.
        $ok = self::send($to, $subject, $html);
        return $ok
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => __('La funzione mail() di PHP ha restituito un errore. Verifica la configurazione del server di posta.')];
    }

}
