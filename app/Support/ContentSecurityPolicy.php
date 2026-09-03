<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Builds the application CSP and attaches its per-response nonce to inline
 * script/style elements.  Attribute-level inline CSS and legacy event
 * handlers remain isolated in their dedicated CSP directives; they no longer
 * make arbitrary inline <script> or <style> elements executable.
 */
final class ContentSecurityPolicy
{
    private const NONCE_PATTERN = '/^(?:[a-f0-9]{8}-){3}[a-f0-9]{8}$/D';

    /**
     * Generate a 128-bit per-response nonce without long decimal runs.
     *
     * Like the CSRF token (see Csrf::generateToken()), an unbroken hexadecimal
     * nonce occasionally contains a 13-16 digit Luhn-valid window that ZAP's
     * PII scanner mistakes for a payment-card number — the nonce is stamped on
     * every inline script/style of every page. Grouping the lossless hex
     * encoding caps decimal runs at eight characters while staying within the
     * CSP base64url nonce alphabet, which permits '-'.
     */
    public static function createNonce(): string
    {
        return implode('-', str_split(bin2hex(random_bytes(16)), 8));
    }

    public static function header(string $nonce, bool $upgradeInsecureRequests = false): string
    {
        if (!preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce');
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "style-src-attr 'unsafe-inline'",
            // Covers, author photos and plugin logos may be stored as remote
            // HTTPS URLs. They are passive image resources; scripts remain
            // constrained to the explicit allow-list above.
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' data: blob: https://www.google.com",
            // Digital Library supports externally hosted audio files.
            "media-src 'self' blob: https:",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            // The native PDF viewer can render a user-configured remote PDF.
            "frame-src 'self' data: blob: about: https:",
            "child-src 'self' data: blob: about:",
            "frame-ancestors 'self'",
        ];

        if ($upgradeInsecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    public static function addNonceAttributes(string $html, string $nonce): string
    {
        if (!preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce');
        }

        return preg_replace_callback(
            '/<(script|style)\b(?![^>]*\bnonce\s*=)([^>]*)>/i',
            static fn(array $match): string => sprintf(
                '<%s nonce="%s"%s>',
                $match[1],
                $nonce,
                $match[2]
            ),
            $html
        ) ?? $html;
    }

    /** Strip request-random nonces before a response is stored by shared cache. */
    public static function removeNonceAttributes(string $html): string
    {
        return preg_replace('/\s+nonce=(?:"[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
    }

    /**
     * Build a stable hash-based CSP for the exact cached HTML payload. This
     * avoids reusing a response nonce across cache hits while preserving the
     * same external-source policy as header().
     */
    public static function headerForCachedHtml(string $html, bool $upgradeInsecureRequests = false): string
    {
        $scriptHashes = self::inlineHashes($html, 'script');
        $styleHashes = self::inlineHashes($html, 'style');
        $directives = [
            "default-src 'self'",
            "script-src 'self' " . implode(' ', $scriptHashes) . " 'wasm-unsafe-eval' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' " . implode(' ', $styleHashes) . " https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' data: blob: https://www.google.com",
            "media-src 'self' blob: https:",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-src 'self' data: blob: about: https:",
            "child-src 'self' data: blob: about:",
            "frame-ancestors 'self'",
        ];
        if ($upgradeInsecureRequests) {
            $directives[] = 'upgrade-insecure-requests';
        }
        return implode('; ', $directives);
    }

    /** @return string[] */
    private static function inlineHashes(string $html, string $tag): array
    {
        preg_match_all('/<' . $tag . '\\b[^>]*>(.*?)<\/' . $tag . '>/is', $html, $matches);
        $hashes = [];
        foreach ($matches[1] ?? [] as $body) {
            if ($body !== '') {
                $hashes["'sha256-" . base64_encode(hash('sha256', $body, true)) . "'"] = true;
            }
        }
        return array_keys($hashes);
    }

    public static function isHtmlResponse(string $contentType, string $body): bool
    {
        $contentType = strtolower($contentType);
        if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml')) {
            return true;
        }

        // Several legacy view controllers write a complete document without
        // setting Content-Type. Detect only full HTML documents so JSON/text
        // API responses are never rewritten accidentally.
        return trim($contentType) === ''
            && preg_match('/^\s*(?:<!doctype\s+html\b|<html\b)/i', $body) === 1;
    }
}
