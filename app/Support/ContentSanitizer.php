<?php
declare(strict_types=1);

namespace App\Support;

final class ContentSanitizer
{
    /**
     * Normalizza URL esterni noti per rispettare HTTPS e la CSP.
     */
    public static function normalizeExternalAssets(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $content = strtr($content, [
            'http://fonts.googleapis.com' => 'https://fonts.googleapis.com',
            'http://fonts.gstatic.com' => 'https://fonts.gstatic.com',
            '//fonts.googleapis.com' => 'https://fonts.googleapis.com',
            '//fonts.gstatic.com' => 'https://fonts.gstatic.com',
        ]);

        // Rimuove completamente qualsiasi inclusione esterna ai Google Fonts.
        $patterns = [
            '#<link[^>]+fonts\.googleapis\.com[^>]*>#i',
            '#<link[^>]+fonts\.gstatic\.com[^>]*>#i',
            '#@import\s+url\((?:"|\'|)(?:https?:)?//fonts\.googleapis\.com[^)]*\)\s*;?#i',
            '#https?://fonts\.googleapis\.com/[^\"\'\s>]+#i',
            '#https?://fonts\.gstatic\.com/[^\"\'\s>]+#i',
        ];

        return preg_replace($patterns, '', $content) ?? $content;
    }

    /**
     * Sanitizza CSS destinato a un blocco inline <style>…</style>.
     *
     * Il contenuto di un <style> è "raw text" per il parser HTML: l'unico
     * modo per uscirne ed arrivare all'esecuzione di script è il tag di
     * chiusura `</style` (ASCII case-insensitive). Un CSS valido non contiene
     * mai quella sequenza né `<script`, quindi neutralizzarle chiude il
     * vettore di breakout→XSS senza toccare alcun foglio di stile reale.
     * Applicata SIA in salvataggio (i valori stored restano puliti) SIA in
     * rendering (protegge i valori già presenti nel DB o iniettati altrove).
     */
    public static function sanitizeCustomCss(string $css): string
    {
        if ($css === '') {
            return $css;
        }

        // Prima la normalizzazione degli asset esterni (Google Fonts → HTTPS).
        $css = self::normalizeExternalAssets($css);

        // Rimuove ogni apertura/chiusura di <style>/<script>: sequenze che
        // non compaiono mai in CSS legittimo ma che consentirebbero di
        // rompere il contesto raw-text ed eseguire JavaScript.
        $css = preg_replace('#<\s*/?\s*(?:style|script)\b[^>]*>?#i', '', $css) ?? $css;

        // Difesa aggiuntiva: annulla i marcatori di commento HTML che
        // potrebbero mascherare un tag in scenari di parsing anomali.
        return str_ireplace(['<!--', '-->', '<![cdata[', ']]>'], '', $css);
    }
}
