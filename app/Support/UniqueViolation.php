<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maps a mysqli duplicate-key exception (errno 1062) to the logical `utenti`
 * field that collided, so user-creation flows can show a precise message
 * ("email already registered" vs "codice fiscale already registered") instead
 * of assuming it was always the email.
 *
 * The UNIQUE indexes on `utenti` are named after their column (email,
 * codice_tessera, cod_fiscale). MySQL reports the offending index in the driver
 * message — "Duplicate entry '…' for key 'email'" (5.7) or "… for key
 * 'utenti.email'" (8.0) — which this parses. Anything that isn't a recognised
 * 1062 collision returns 'other' so the caller falls back to a generic error.
 */
final class UniqueViolation
{
    /** @return 'email'|'codice_tessera'|'cod_fiscale'|'other' */
    public static function fieldFor(\mysqli_sql_exception $e): string
    {
        if ($e->getCode() !== 1062) {
            return 'other';
        }
        // "... for key 'email'" or "... for key 'utenti.email'"
        if (preg_match("/for key '(?:[^'.]+\\.)?(email|codice_tessera|cod_fiscale)'/i", $e->getMessage(), $m) === 1) {
            return strtolower($m[1]);
        }
        return 'other';
    }

    /**
     * The `?error=` code a controller should redirect with for a given field.
     * 'other' → 'db_error' (generic), so an unexpected duplicate never masquerades
     * as a specific field.
     */
    public static function errorCode(\mysqli_sql_exception $e): string
    {
        return match (self::fieldFor($e)) {
            'email' => 'email_exists',
            'cod_fiscale' => 'cf_exists',
            'codice_tessera' => 'tessera_exists',
            default => 'db_error',
        };
    }
}
