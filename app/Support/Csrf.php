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
        error_log('[DEBUG CSRF] ensureToken: START');
        error_log('[DEBUG CSRF] Session status: ' . session_status());
        error_log('[DEBUG CSRF] Session ID: ' . session_id());
        error_log('[DEBUG CSRF] Current csrf_token: ' . ($_SESSION['csrf_token'] ?? 'NOT SET'));

        $needsRegeneration = false;

        // Genera nuovo token se non esiste
        if (empty($_SESSION['csrf_token'])) {
            error_log('[DEBUG CSRF] Token is empty, needs regeneration');
            $needsRegeneration = true;
        }

        // Rigenera token se troppo vecchio (ogni 2 ore ± 10 minuti di randomizzazione)
        // Timeout lungo per permettere agli admin di compilare form complessi senza interruzioni
        if (!empty($_SESSION['csrf_token_time'])) {
            $timeout = 7200 + random_int(-600, 600); // 2 ore ± 10 minuti
            $age = time() - $_SESSION['csrf_token_time'];
            error_log('[DEBUG CSRF] Token age: ' . $age . ' seconds, timeout: ' . $timeout);
            if ($age > $timeout) {
                error_log('[DEBUG CSRF] Token is too old, needs regeneration');
                $needsRegeneration = true;
            }
        } else {
            error_log('[DEBUG CSRF] No token time set, needs regeneration');
            $needsRegeneration = true;
        }

        if ($needsRegeneration) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
            error_log('[DEBUG CSRF] Generated new token: ' . $_SESSION['csrf_token']);
        }

        error_log('[DEBUG CSRF] Returning token: ' . $_SESSION['csrf_token']);
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

