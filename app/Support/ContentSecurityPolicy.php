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
    public static function createNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function header(string $nonce, bool $upgradeInsecureRequests = false): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce');
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com",
            "script-src-attr 'unsafe-inline'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob: https://cdnjs.cloudflare.com https://www.gstatic.com",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' data: blob: https://www.google.com",
            "media-src 'self' blob:",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-src 'self' data: blob: about: https://www.google.com https://www.google.it https://maps.google.com https://www.openstreetmap.org",
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
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
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
}
