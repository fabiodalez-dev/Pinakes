<?php
declare(strict_types=1);

namespace App\Support;

final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $fromEmail = (string)ConfigStore::get('mail.from_email', 'no-reply@localhost');
        $fromName  = (string)ConfigStore::get('mail.from_name', 'Biblioteca');
        $driver    = (string)ConfigStore::get('mail.driver', 'mail');

        if ($driver === 'smtp') {
            return self::sendSmtp($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
        }
        if ($driver === 'phpmailer') {
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

    private static function sendSmtp(string $to, string $subject, string $htmlBody, ?string $textBody, string $fromEmail, string $fromName): bool
    {
        $host = (string)ConfigStore::get('mail.smtp.host', 'localhost');
        $port = (int)ConfigStore::get('mail.smtp.port', 587);
        $enc  = (string)ConfigStore::get('mail.smtp.encryption', 'tls');
        $user = (string)ConfigStore::get('mail.smtp.username', '');
        $pass = (string)ConfigStore::get('mail.smtp.password', '');

        $transport = ($enc === 'ssl') ? 'ssl://' . $host : $host;
        $remote = ($enc === 'tls') ? 'tcp://' . $host . ':' . $port : $transport . ':' . $port;

        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
        if (!$fp) return false;
        stream_set_timeout($fp, 10);

        $send = function(string $cmd) use ($fp) {
            fwrite($fp, $cmd . "\r\n");
            return fgets($fp, 512);
        };

        $read = function() use ($fp) {
            return fgets($fp, 512);
        };

        $read();
        $send('EHLO localhost');
        if ($enc === 'tls') {
            $send('STARTTLS');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp); return false;
            }
            $send('EHLO localhost');
        }
        if ($user !== '') {
            $send('AUTH LOGIN');
            $send(base64_encode($user));
            $send(base64_encode($pass));
        }
        // Validate email addresses to prevent CRLF injection
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strpos($fromEmail, "\r") !== false || strpos($fromEmail, "\n") !== false) {
            throw new \Exception('Invalid from email address');
        }
        $send('MAIL FROM: <' . $fromEmail . '>');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strpos($to, "\r") !== false || strpos($to, "\n") !== false) {
            throw new \Exception('Invalid recipient email address');
        }
        $send('RCPT TO: <' . $to . '>');
        $send('DATA');

        // Use more secure boundary generation
        $boundary = uniqid('mail_boundary_', true);
        $headers = [];
        $headers[] = 'From: ' . self::encodeHeader($fromName) . " <{$fromEmail}>";
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $text = $textBody ?: strip_tags($htmlBody);
        $msg  = implode("\r\n", $headers) . "\r\n\r\n";
        $msg .= "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$text}\r\n";
        $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\n\r\n{$htmlBody}\r\n";
        $msg .= "--{$boundary}--\r\n.";

        $send($msg);
        $send('QUIT');
        fclose($fp);
        return true;
    }

    private static function encodeHeader(string $s): string
    {
        if (!preg_match('/^[\x20-\x7E]*$/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }

    private static function sendPHPMailer(string $to, string $subject, string $htmlBody, ?string $textBody, string $fromEmail, string $fromName): bool
    {
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            // Fallback to mail()
            return self::sendMail($to, $subject, $htmlBody, $fromEmail, $fromName);
        }
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $textBody ?: strip_tags($htmlBody);

            // Use SMTP if configured
            $host = (string)ConfigStore::get('mail.smtp.host', '');
            if ($host !== '') {
                $mailer->isSMTP();
                $mailer->Host = $host;
                $mailer->Port = (int)ConfigStore::get('mail.smtp.port', 587);
                $enc  = (string)ConfigStore::get('mail.smtp.encryption', 'tls');
                if ($enc === 'ssl') { $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; }
                elseif ($enc === 'tls') { $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }
                else { $mailer->SMTPSecure = false; }
                $user = (string)ConfigStore::get('mail.smtp.username', '');
                $pass = (string)ConfigStore::get('mail.smtp.password', '');
                if ($user !== '') { $mailer->SMTPAuth = true; $mailer->Username = $user; $mailer->Password = $pass; }
            }
            return $mailer->send();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
