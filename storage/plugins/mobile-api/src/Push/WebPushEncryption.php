<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

/**
 * Message encryption for Web Push (RFC 8291) in the aes128gcm content coding
 * (RFC 8188), the format standard Web Push endpoints and UnifiedPush
 * distributors bridging to Web Push require: a plaintext POST to them answers
 * 400, and after ten failures the device was silently disabled.
 *
 * Input: the subscription's `p256dh` public key (65-byte uncompressed P-256
 * point) and 16-byte `auth` secret, both base64url, as the app registers them.
 * Output: the request body (header + one record) to POST with
 * `Content-Encoding: aes128gcm`.
 */
final class WebPushEncryption
{
    private const RECORD_SIZE = 4096;

    /** DER prefix of a SubjectPublicKeyInfo for an uncompressed prime256v1 point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * @param string      $payload      the message (≤ 3993 bytes, one record)
     * @param string      $uaPublicB64  subscription p256dh, base64url
     * @param string      $authB64      subscription auth secret, base64url
     * @param string|null $asPrivatePem fixed sender key (tests only); random otherwise
     * @param string|null $salt         fixed 16-byte salt (tests only); random otherwise
     * @return string|null the encrypted body, or null when the keys are unusable
     */
    public static function encrypt(
        string $payload,
        string $uaPublicB64,
        string $authB64,
        ?string $asPrivatePem = null,
        ?string $salt = null
    ): ?string {
        $uaPublic = self::b64uDecode($uaPublicB64);
        $authSecret = self::b64uDecode($authB64);
        if ($uaPublic === null || strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04"
            || $authSecret === null || strlen($authSecret) !== 16
            || strlen($payload) > self::RECORD_SIZE - 16 - 1 - 86) {
            return null;
        }

        // Sender (application server) ephemeral key pair.
        $asKey = $asPrivatePem !== null
            ? openssl_pkey_get_private($asPrivatePem)
            : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($asKey === false) {
            return null;
        }
        $details = openssl_pkey_get_details($asKey);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            return null;
        }
        $asPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $uaKey = openssl_pkey_get_public(self::pointToPem($uaPublic));
        if ($uaKey === false) {
            return null;
        }
        $ecdhSecret = openssl_pkey_derive($uaKey, $asKey, 32);
        if ($ecdhSecret === false || strlen($ecdhSecret) !== 32) {
            return null;
        }

        $salt ??= random_bytes(16);

        // RFC 8291 §3.4: combine the ECDH secret with the auth secret.
        $prkKey = hash_hmac('sha256', $ecdhSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);

        // RFC 8188 §2.2-2.3: content-encryption key and nonce.
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // A single record: the payload followed by the last-record delimiter.
        $tag = '';
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            return null;
        }

        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublic)) . $asPublic . $ciphertext . $tag;
    }

    /**
     * Whether a p256dh/auth pair can encrypt a message: checked at registration
     * so a malformed pair is refused there, not at every send.
     */
    public static function keysAreUsable(string $uaPublicB64, string $authB64): bool
    {
        $uaPublic = self::b64uDecode($uaPublicB64);
        $authSecret = self::b64uDecode($authB64);
        if ($uaPublic === null || strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04"
            || $authSecret === null || strlen($authSecret) !== 16) {
            return false;
        }
        // The point must lie on the curve, or ECDH fails at send time.
        return openssl_pkey_get_public(self::pointToPem($uaPublic)) !== false;
    }

    private static function pointToPem(string $point): string
    {
        $der = (string) hex2bin(self::P256_SPKI_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function b64uDecode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-+\/]+=*$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/') . str_repeat('=', (4 - strlen(rtrim($value, '=')) % 4) % 4), true);
        return $decoded === false ? null : $decoded;
    }
}
