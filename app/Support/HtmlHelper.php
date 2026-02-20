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

    /**
     * Sanitizza HTML ricco per prevenire XSS mantenendo formattazione sicura
     * Permette solo tag e attributi sicuri, rimuove JavaScript e contenuto maligno
     *
     * @param string|null $html HTML da sanitizzare
     * @return string HTML sanitizzato
     */
    public static function sanitizeHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Tag HTML permessi (whitelist)
        $allowedTags = [
            'p', 'br', 'span', 'div',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 's', 'mark',
            'ul', 'ol', 'li',
            'a', 'img',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'blockquote', 'pre', 'code',
            'hr'
        ];

        // Attributi permessi per tag (whitelist)
        $allowedAttributes = [
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'td' => ['colspan', 'rowspan'],
            'th' => ['colspan', 'rowspan'],
            'div' => ['class'],
            'span' => ['class'],
            'p' => ['class'],
            'h1' => ['class'],
            'h2' => ['class'],
            'h3' => ['class'],
            'h4' => ['class'],
            'h5' => ['class'],
            'h6' => ['class'],
        ];

        // Rimuovi tag script, style e altri pericolosi
        $html = preg_replace(
            '#<(script|style|iframe|frame|frameset|object|embed|applet|meta|link|base|form|input|button|textarea|select)[^>]*?>.*?</\1>#is',
            '',
            $html
        );

        // Rimuovi tag pericolosi auto-chiusi
        $html = preg_replace(
            '#<(script|style|iframe|frame|object|embed|applet|meta|link|base|input|button)[^>]*?/?>#is',
            '',
            $html
        );

        // Rimuovi event handlers (onclick, onerror, etc.)
        $html = preg_replace(
            '#\s*on\w+\s*=\s*["\'][^"\']*["\']#is',
            '',
            $html
        );
        $html = preg_replace(
            '#\s*on\w+\s*=\s*[^\s>]*#is',
            '',
            $html
        );

        // Rimuovi javascript: negli href e src
        $html = preg_replace(
            '#(href|src)\s*=\s*["\']?\s*javascript:#is',
            '$1="#blocked"',
            $html
        );

        // Rimuovi data: negli href (tranne data:image/ per immagini base64)
        $html = preg_replace(
            '#(href)\s*=\s*["\']?\s*data:(?!image/)[^"\'>\s]*#is',
            '$1="#blocked"',
            $html
        );

        // Strip tags non permessi mantenendo quelli sicuri
        $allowedTagsStr = '<' . implode('><', $allowedTags) . '>';
        $html = strip_tags($html, $allowedTagsStr);

        // Sanitizza attributi
        foreach ($allowedAttributes as $tag => $attrs) {
            $pattern = '#<(' . preg_quote($tag, '#') . ')([^>]*)>#is';
            $html = preg_replace_callback($pattern, function($matches) use ($attrs) {
                $tag = $matches[1];
                $attrString = $matches[2];

                // Estrai attributi
                preg_match_all('#(\w+)\s*=\s*["\']([^"\']*)["\']#', $attrString, $attrMatches, PREG_SET_ORDER);

                $safeAttrs = [];
                foreach ($attrMatches as $attr) {
                    $attrName = strtolower($attr[1]);
                    $attrValue = $attr[2];

                    // Permetti solo attributi whitelisted
                    if (in_array($attrName, $attrs, true)) {
                        // Sanitizza il valore
                        $attrValue = htmlspecialchars($attrValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                        // Validazioni specifiche
                        if ($attrName === 'href') {
                            // Permetti solo http, https, mailto, e path relativi
                            if (!preg_match('#^(https?://|mailto:|/|\#)#i', $attrValue)) {
                                continue; // Salta questo attributo
                            }
                        }

                        if ($attrName === 'src') {
                            // Permetti solo http, https, data:image, e path relativi
                            if (!preg_match('#^(https?://|data:image/|/)#i', $attrValue)) {
                                continue;
                            }
                        }

                        if ($attrName === 'target' && $attrValue === '_blank') {
                            // Aggiungi rel="noopener noreferrer" per sicurezza
                            $safeAttrs[] = 'target="_blank"';
                            $safeAttrs[] = 'rel="noopener noreferrer"';
                            continue;
                        }

                        $safeAttrs[] = $attrName . '="' . $attrValue . '"';
                    }
                }

                $safeAttrString = empty($safeAttrs) ? '' : ' ' . implode(' ', $safeAttrs);
                return '<' . $tag . $safeAttrString . '>';
            }, $html);
        }

        return $html;
    }

    /**
     * Ottiene il base path dell'applicazione (es. '/pinakes' se in sottocartella)
     * Restituisce stringa vuota se l'app è alla root del dominio.
     *
     * @return string Base path senza trailing slash, o stringa vuota
     */
    public static function getBasePath(): string
    {
        static $basePath = null;
        if ($basePath !== null) {
            return $basePath;
        }

        // Priorità 1: Estrai path da APP_CANONICAL_URL
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;
        $canonicalUrl = $canonicalUrl !== false ? (string) $canonicalUrl : '';
        if ($canonicalUrl !== '') {
            $path = parse_url($canonicalUrl, PHP_URL_PATH);
            if ($path === false) {
                error_log('[HtmlHelper] APP_CANONICAL_URL is malformed, cannot parse path: ' . $canonicalUrl);
                // Fall through to SCRIPT_NAME detection
            } elseif ($path !== null && $path !== '' && $path !== '/') {
                $basePath = rtrim($path, '/');
                return $basePath;
            }
        }

        // Priorità 2: Auto-detect da SCRIPT_NAME (skip in CLI)
        if (php_sapi_name() !== 'cli' && isset($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
            if ($scriptDir !== '/' && $scriptDir !== '\\' && $scriptDir !== '.') {
                $basePath = rtrim($scriptDir, '/');
                return $basePath;
            }
        }

        $basePath = '';
        return $basePath;
    }

    /**
     * Ottiene l'URL base sicuro dell'applicazione
     * Usa APP_CANONICAL_URL dalla configurazione per evitare Host header injection
     *
     * @return string URL base sicuro
     */
    public static function getBaseUrl(): string
    {
        // Usa sempre APP_CANONICAL_URL se configurato
        $canonicalUrl = $_ENV['APP_CANONICAL_URL'] ?? getenv('APP_CANONICAL_URL') ?: false;

        if ($canonicalUrl && $canonicalUrl !== '') {
            // Validate that URL has a scheme
            if (!preg_match('#^https?://#i', $canonicalUrl)) {
                error_log('[HtmlHelper] APP_CANONICAL_URL missing scheme, prepending https://: ' . $canonicalUrl);
                $canonicalUrl = 'https://' . $canonicalUrl;
            }
            return rtrim($canonicalUrl, '/');
        }

        // Fallback sicuro: valida l'host corrente
        $httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Separa host e porta
        $port = null;
        $host = $httpHost;
        if (strpos($httpHost, ':') !== false) {
            [$host, $portStr] = explode(':', $httpHost, 2);
            $port = is_numeric($portStr) ? (int)$portStr : null;
        }

        // Whitelist di host validi per sviluppo locale
        $allowedHosts = ['localhost', '127.0.0.1', '::1'];

        // Se l'host non è nella whitelist, validalo
        if (!in_array($host, $allowedHosts, true)) {
            // Valida che sia un hostname valido (RFC 1123)
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $host)) {
                $host = 'localhost'; // Fallback sicuro
                $port = null;
            }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        // Ricostruisci l'URL con la porta se presente e non standard
        $baseUrl = $protocol . '://' . $host;
        if ($port !== null) {
            $defaultPorts = ['http' => 80, 'https' => 443];
            if (!isset($defaultPorts[$protocol]) || $defaultPorts[$protocol] !== $port) {
                $baseUrl .= ':' . $port;
            }
        }

        return $baseUrl . self::getBasePath();
    }

    /**
     * Ottiene l'URL corrente completo in modo sicuro
     *
     * @return string URL corrente sanitizzato
     */
    public static function getCurrentUrl(): string
    {
        $baseUrl = self::getBaseUrl();
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Sanitizza REQUEST_URI rimuovendo caratteri potenzialmente pericolosi
        $requestUri = preg_replace('/[^\x20-\x7E]/', '', $requestUri);

        // getBaseUrl() includes basePath (e.g. http://host/pinakes)
        // REQUEST_URI also includes basePath (e.g. /pinakes/admin/dashboard)
        // Extract origin (protocol+host+port) to avoid duplication
        $basePath = self::getBasePath();
        if ($basePath !== '' && str_ends_with($baseUrl, $basePath)) {
            $origin = substr($baseUrl, 0, -strlen($basePath));
            return $origin . $requestUri;
        }

        return $baseUrl . $requestUri;
    }

    /**
     * Costruisce un URL assoluto sicuro da un path relativo
     *
     * @param string $path Path relativo
     * @return string URL assoluto sicuro
     */
    public static function absoluteUrl(string $path): string
    {
        // Don't modify already-absolute URLs
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }

        $baseUrl = self::getBaseUrl();

        // Strip basePath from path if already included (e.g. from book_url())
        // to avoid double prefix since getBaseUrl() already contains basePath
        $basePath = self::getBasePath();
        if ($basePath !== '' && (str_starts_with($path, $basePath . '/') || $path === $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = ltrim($path, '/');

        return $baseUrl . '/' . $path;
    }
}