<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

use App\Support\SecureLogger;

/**
 * VAPID (RFC 8292) request signing for Web Push / UnifiedPush.
 *
 * Web Push servers (Mozilla autopush, the Google WebPush gateway, and any
 * UnifiedPush distributor that bridges to standard Web Push) authenticate the
 * application server with VAPID: for each push the server signs a short-lived
 * ES256 JWT (ECDSA P-256 / SHA-256) and sends it as
 *
 *   Authorization: vapid t=<jwt>, k=<base64url uncompressed public key>
 *
 * This is implemented with raw OpenSSL — no Web Push library dependency. The one
 * subtlety: openssl_sign() emits a DER-encoded ECDSA signature, but JWS ES256
 * requires the raw fixed-width R||S concatenation (64 bytes), so the DER is
 * unwrapped here.
 *
 * Keys are a P-256 keypair generated once per instance (see MobileApiPlugin):
 *   - the public key is the uncompressed EC point (0x04 || X || Y), base64url,
 *     shared with the app/distributor (the `k=` value and Crypto-Key);
 *   - the private key is a PEM string, stored encrypted at rest.
 */
final class VapidSigner
{
    /** JWT lifetime. RFC 8292 caps `exp` at 24h from issuance; 12h is comfortable. */
    private const TOKEN_TTL = 43200;

    /**
     * Generate a fresh VAPID keypair.
     *
     * @return array{public:string, private:string}|null
     *         `public`  = base64url uncompressed EC point (65 bytes → 87 chars),
     *         `private` = PEM private key. Null if OpenSSL EC is unavailable.
     */
    public static function generateKeyPair(): ?array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($res === false) {
            return null;
        }

        $privatePem = '';
        if (openssl_pkey_export($res, $privatePem) === false) {
            return null;
        }

        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }

        $point = "\x04" . self::pad((string) $details['ec']['x']) . self::pad((string) $details['ec']['y']);

        return [
            'public'  => self::b64u($point),
            'private' => $privatePem,
        ];
    }

    /**
     * Build the `Authorization: vapid …` header value for a push to $endpoint.
     *
     * @return string|null The header value, or null if signing was not possible
     *                     (missing/invalid keys, bad endpoint, OpenSSL failure) —
     *                     callers MUST treat null as "send without VAPID", never fail.
     */
    public static function authorizationHeader(
        string $endpoint,
        string $subject,
        string $publicKeyB64u,
        string $privateKeyPem
    ): ?string {
        $audience = self::origin($endpoint);
        if ($audience === null || $publicKeyB64u === '' || $privateKeyPem === '') {
            return null;
        }

        // A subject is required by RFC 8292; default to a generic mailto if the
        // admin left it blank so the JWT is still well-formed.
        $sub = trim($subject);
        if ($sub === '') {
            $sub = 'mailto:admin@localhost';
        }

        $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims  = ['aud' => $audience, 'exp' => time() + self::TOKEN_TTL, 'sub' => $sub];

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $claimsJson = json_encode($claims, JSON_UNESCAPED_SLASHES);
        if ($headerJson === false || $claimsJson === false) {
            return null;
        }

        $signingInput = self::b64u($headerJson) . '.' . self::b64u($claimsJson);

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            return null;
        }

        $der = '';
        $ok  = openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256);
        if ($ok === false || $der === '') {
            return null;
        }

        $raw = self::derToRaw($der);
        if ($raw === null) {
            SecureLogger::warning('[MobileApi] VAPID: could not convert ECDSA signature to raw form');
            return null;
        }

        $jwt = $signingInput . '.' . self::b64u($raw);

        return 'vapid t=' . $jwt . ', k=' . $publicKeyB64u;
    }

    /** Origin (`scheme://host[:port]`) the JWT `aud` must equal, or null if invalid. */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    /**
     * Convert a DER-encoded ECDSA signature (SEQUENCE of two INTEGERs) to the
     * raw fixed-width R||S form (2×32 bytes) required by JWS ES256.
     */
    private static function derToRaw(string $der): ?string
    {
        // Unpack to a 1-indexed array of byte values so the cursor logic below
        // reads each byte independently (avoids the `$str[$i++]` form that static
        // analysis mis-narrows when consecutive bytes are checked).
        $bytes = array_values(unpack('C*', $der) ?: []);
        $len   = count($bytes);
        if ($len < 8 || $bytes[0] !== 0x30) {
            return null; // not a SEQUENCE
        }
        // Sequence length: short form only (an ES256 sig is well under 128 bytes).
        if ($bytes[1] >= 0x80) {
            return null;
        }

        $offset = 2;
        $r = self::readInteger($bytes, $offset);
        $s = self::readInteger($bytes, $offset);
        if ($r === null || $s === null) {
            return null;
        }

        return self::pad($r) . self::pad($s);
    }

    /**
     * Read a DER INTEGER from the byte array at $offset (advanced by reference),
     * returning its big-endian value bytes with DER sign padding stripped.
     *
     * @param list<int> $bytes
     */
    private static function readInteger(array $bytes, int &$offset): ?string
    {
        $len = count($bytes);
        if ($offset + 1 >= $len || $bytes[$offset] !== 0x02) {
            return null; // not an INTEGER
        }
        $intLen = $bytes[$offset + 1];
        $offset += 2;
        if ($intLen === 0 || $offset + $intLen > $len) {
            return null;
        }

        $value = '';
        for ($i = 0; $i < $intLen; $i++) {
            $value .= chr($bytes[$offset + $i]);
        }
        $offset += $intLen;

        // Strip a leading 0x00 that DER adds to keep the integer positive.
        $stripped = ltrim($value, "\x00");

        return $stripped === '' ? "\x00" : $stripped;
    }

    /** Left-pad (or trim) a big-endian integer to exactly 32 bytes. */
    private static function pad(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if (strlen($bytes) > 32) {
            return substr($bytes, -32);
        }

        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }

    /** URL-safe base64 without padding (base64url). */
    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
