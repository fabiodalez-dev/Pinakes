<?php
declare(strict_types=1);

namespace App\Support;

class HtmlHelper
{
    /**
     * Decodifica le entità HTML in testo normale
     * 
     * @param string|null $text Il testo da decodificare
     * @return string Il testo decodificato
     */
    public static function decode(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Sanitizza il testo per la visualizzazione HTML sicura
     * (codifica le entità HTML per prevenire XSS)
     * 
     * @param string|null $text Il testo da sanitizzare
     * @return string Il testo sanitizzato
     */
    public static function escape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Decodifica e poi ri-codifica il testo per la visualizzazione sicura
     * Utile quando i dati sono già codificati nel database
     * 
     * @param string|null $text Il testo da processare
     * @return string Il testo processato per la visualizzazione sicura
     */
    public static function safe(?string $text): string
    {
        return self::escape(self::decode($text));
    }
    
    /**
     * Funzione di comodo per usare nel template che decodifica e mostra in modo sicuro
     * 
     * @param string|null $text Il testo da processare
     * @return string Il testo processato per la visualizzazione sicura
     */
    public static function e(?string $text): string
    {
        return self::safe($text);
    }
}