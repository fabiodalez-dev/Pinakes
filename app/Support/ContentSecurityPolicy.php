<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Builds the application CSP and owns its per-request nonce.
 *
 * The nonce is never stamped on the response as a whole: only the
 * application's own templates carry it, through the csp_nonce_attr() /
 * csp_nonce() view helpers. A <script> or <style> smuggled in through stored
 * content (a description, a CMS page, a translation) is emitted without the
 * nonce and the browser refuses to run it. Attribute-level inline CSS and the
 * legacy event handlers remain isolated in their dedicated CSP directives.
 */
final class ContentSecurityPolicy
{
    private const NONCE_PATTERN = '/^(?:[a-f0-9]{8}-){3}[a-f0-9]{8}$/D';

    /** The nonce of the request being served; see beginRequest(). */
    private static ?string $requestNonce = null;

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

    /**
     * Start a request: generate the nonce the CSP header will announce and the
     * templates will stamp on their own script/style elements.
     */
    public static function beginRequest(): string
    {
        self::$requestNonce = self::createNonce();
        return self::$requestNonce;
    }

    /**
     * The nonce of the current request. Created on first use so a view
     * rendered outside the HTTP front controller (CLI, tests) still gets a
     * well-formed value; such output simply has no CSP header to match.
     */
    public static function requestNonce(): string
    {
        return self::$requestNonce ??= self::createNonce();
    }

    /** ` nonce="…"`, ready to be placed inside a <script>/<style> start tag. */
    public static function nonceAttribute(): string
    {
        return ' nonce="' . htmlspecialchars(self::requestNonce(), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Read the nonce back from a CSP header produced by header(); null when
     * the header carries none (a hand-built or foreign policy).
     */
    public static function nonceFromHeader(string $csp): ?string
    {
        if (preg_match("/'nonce-((?:[a-f0-9]{8}-){3}[a-f0-9]{8})'/", $csp, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * The Permissions-Policy header sent with every response.
     *
     * camera=(self): two same-origin features call getUserMedia — the copy
     * barcode scanner (zxing-wasm) on the loan/return pages and the patron
     * photo taken from the webcam in the user form. Denying it (camera=())
     * blocks both; no other origin (no embedded frame) gets the camera.
     * geolocation and microphone stay denied: nothing in the app uses them.
     * The CSP needs nothing for the photo: the live MediaStream is not a URL
     * that media-src governs, the captured frame is drawn on a canvas and sent
     * to the same origin (connect-src 'self'), and the stored photo is an
     * ordinary same-origin image (img-src 'self').
     */
    public static function permissionsPolicy(): string
    {
        return 'geolocation=(), microphone=(), camera=(self)';
    }

    public static function header(string $nonce, bool $upgradeInsecureRequests = false): string
    {
        if (!preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce');
        }

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'wasm-unsafe-eval' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com",
            "script-src-attr 'none'",
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

    /** Strip request-random nonces before a response is stored by shared cache. */
    public static function removeNonceAttributes(string $html): string
    {
        return preg_replace('/\s+nonce=(?:"[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
    }

    /**
     * Build a stable hash-based CSP for a page stored by the shared cache.
     * Reusing a response nonce across cache hits would hand it to every
     * visitor, so the cached copy whitelists its inline blocks by hash
     * instead, with the same external-source policy as header().
     *
     * $html is the response as rendered, BEFORE removeNonceAttributes():
     * only the inline blocks that carry $nonce (the templates' own, stamped
     * through csp_nonce_attr()) are hashed. A block injected by stored content
     * carries no nonce, was blocked on the live response, and must stay
     * blocked on the cached copy too.
     */
    public static function headerForCachedHtml(string $html, string $nonce, bool $upgradeInsecureRequests = false): string
    {
        if (!preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce');
        }
        $scriptHashes = self::inlineHashes($html, 'script', $nonce);
        $styleHashes = self::inlineHashes($html, 'style', $nonce);
        $directives = [
            "default-src 'self'",
            "script-src 'self' " . implode(' ', $scriptHashes) . " 'wasm-unsafe-eval' https://cdnjs.cloudflare.com https://www.google.com https://www.gstatic.com",
            "script-src-attr 'none'",
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

    /**
     * Hashes of the inline <$tag> blocks whose start tag carries $nonce.
     *
     * @return string[]
     */
    private static function inlineHashes(string $html, string $tag, string $nonce): array
    {
        preg_match_all('/<' . $tag . '\\b([^>]*)>(.*?)<\/' . $tag . '>/is', $html, $matches, PREG_SET_ORDER);
        $hashes = [];
        foreach ($matches as $match) {
            $body = $match[2];
            if ($body === '' || !self::attributesCarryNonce($match[1], $nonce)) {
                continue;
            }
            $hashes["'sha256-" . base64_encode(hash('sha256', $body, true)) . "'"] = true;
        }
        return array_keys($hashes);
    }

    private static function attributesCarryNonce(string $attributes, string $nonce): bool
    {
        if (preg_match('/(?:^|\s)nonce\s*=\s*(?:"(?<dq>[^"]*)"|\'(?<sq>[^\']*)\'|(?<bare>[^\s"\'>]+))/i', $attributes, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return false;
        }
        $value = $m['dq'] ?? $m['sq'] ?? $m['bare'] ?? '';
        return hash_equals($nonce, $value);
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
