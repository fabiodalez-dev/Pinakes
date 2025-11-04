<?php
declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    /**
     * Genera o restituisce token CSRF per la sessione corrente
     * SECURITY: Token viene rigenerato automaticamente dopo un periodo di tempo
     */
    public static function ensureToken(): string
    {
        $needsRegeneration = false;

        // Genera nuovo token se non esiste
        if (empty($_SESSION['csrf_token'])) {
            $needsRegeneration = true;
        }

        // Rigenera token se troppo vecchio (ogni 30 minuti ± 5 minuti di randomizzazione)
        // Timeout più lungo per compatibilità con mobile e hosting gratuiti
        if (!empty($_SESSION['csrf_token_time'])) {
            $timeout = 1800 + random_int(-300, 300); // 30 minuti ± 5 minuti
            if (time() - $_SESSION['csrf_token_time'] > $timeout) {
                $needsRegeneration = true;
            }
        } else {
            $needsRegeneration = true;
        }

        if ($needsRegeneration) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Valida token CSRF
     */
    public static function validate(?string $token): bool
    {
        if (!$token || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Verifica se la sessione è scaduta (nessun token presente)
     * Questo indica che l'utente ha perso la sessione
     */
    public static function isSessionExpired(): bool
    {
        return empty($_SESSION['csrf_token']);
    }

    /**
     * Valida il token e restituisce un array con lo stato dettagliato
     *
     * @return array{valid: bool, reason: string}
     */
    public static function validateWithReason(?string $token): array
    {
        // Caso 1: Token non fornito dal client
        if (!$token) {
            return [
                'valid' => false,
                'reason' => 'missing_token'
            ];
        }

        // Caso 2: Sessione scaduta (nessun token in sessione)
        if (empty($_SESSION['csrf_token'])) {
            return [
                'valid' => false,
                'reason' => 'session_expired'
            ];
        }

        // Caso 3: Token non corrisponde
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            return [
                'valid' => false,
                'reason' => 'token_mismatch'
            ];
        }

        // Token valido
        return [
            'valid' => true,
            'reason' => 'valid'
        ];
    }

    /**
     * Forza rigenerazione del token (da chiamare dopo login/logout)
     */
    public static function regenerate(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
}

