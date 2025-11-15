<?php

declare(strict_types=1);

namespace App\Support;

class DateHelper
{
    private static array $monthNames = [
        'gennaio' => '01', 'febbraio' => '02', 'marzo' => '03', 'aprile' => '04',
        'maggio' => '05', 'giugno' => '06', 'luglio' => '07', 'agosto' => '08',
        'settembre' => '09', 'ottobre' => '10', 'novembre' => '11', 'dicembre' => '12'
    ];

    /**
     * Converte una data in formato italiano "8 gennaio 2025" in formato YYYY per il database
     *
     * @param string $italianDate Data in formato italiano (es. "8 gennaio 2025")
     * @return int|null Anno come intero per il campo year del database
     */
    public static function convertItalianDateToYear(?string $italianDate): ?int
    {
        if (empty($italianDate)) {
            return null;
        }

        // Pulisce la stringa
        $italianDate = trim($italianDate);

        // Pattern per matchare formato "8 gennaio 2025" o "08 gennaio 2025"
        if (preg_match('/(\d{1,2})\s+(\w+)\s+(\d{4})/i', $italianDate, $matches)) {
            $year = (int)$matches[3];

            // Verifica che l'anno sia ragionevole (tra 1900 e 2100)
            if ($year >= 1900 && $year <= 2100) {
                return $year;
            }
        }

        return null;
    }

    /**
     * Converte una data in formato italiano "8 gennaio 2025" in formato ISO YYYY-MM-DD
     *
     * @param string $italianDate Data in formato italiano (es. "8 gennaio 2025")
     * @return string|null Data in formato ISO YYYY-MM-DD o null se conversione fallisce
     */
    public static function convertItalianDateToISO(?string $italianDate): ?string
    {
        if (empty($italianDate)) {
            return null;
        }

        // Pulisce la stringa
        $italianDate = trim($italianDate);

        // Pattern per matchare formato "8 gennaio 2025" o "08 gennaio 2025"
        if (preg_match('/(\d{1,2})\s+(\w+)\s+(\d{4})/i', $italianDate, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $monthName = strtolower(trim($matches[2]));
            $year = $matches[3];

            // Cerca il mese nell'array dei nomi italiani
            $month = self::$monthNames[$monthName] ?? null;

            if ($month) {
                return "{$year}-{$month}-{$day}";
            }
        }

        return null;
    }

    /**
     * Converte una data ISO YYYY-MM-DD in formato italiano "8 gennaio 2025"
     *
     * @param string $isoDate Data in formato ISO YYYY-MM-DD
     * @return string|null Data in formato italiano o null se conversione fallisce
     */
    public static function convertISOToItalianDate(?string $isoDate): ?string
    {
        if (empty($isoDate)) {
            return null;
        }

        // Pattern per matchare formato YYYY-MM-DD
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $isoDate, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = (int)$matches[3]; // Rimuove zero iniziali

            // Trova il nome del mese italiano
            $monthName = array_search($month, self::$monthNames);

            if ($monthName) {
                return "{$day} {$monthName} {$year}";
            }
        }

        return null;
    }

    /**
     * Verifica se una stringa è in formato data italiana
     *
     * @param string $date Data da verificare
     * @return bool True se è in formato italiano valido
     */
    public static function isItalianDateFormat(?string $date): bool
    {
        if (empty($date)) {
            return false;
        }

        return (bool)preg_match('/^\d{1,2}\s+\w+\s+\d{4}$/', trim($date));
    }

    /**
     * Verifica se una stringa è in formato ISO YYYY-MM-DD
     *
     * @param string $date Data da verificare
     * @return bool True se è in formato ISO valido
     */
    public static function isISODateFormat(?string $date): bool
    {
        if (empty($date)) {
            return false;
        }

        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date));
    }
}