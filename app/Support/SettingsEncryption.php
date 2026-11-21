<?php
declare(strict_types=1);

namespace App\Support;

/**
 * AES-256-GCM encryption for sensitive settings (SMTP passwords, API keys, etc.)
 *
 * Two stored formats, both starting with the ENC: prefix (so a caller's
 * `str_starts_with($v, SettingsEncryption::PREFIX)` test keeps recognising
 * either one as ciphertext):
 *
 *  - v2 (written since 0.9.6): "ENC:v2:" + base64(iv[12] | tag[16] | ciphertext),
 *    with the owning setting (category + key) bound as GCM additional
 *    authenticated data. A ciphertext copied into another setting's row fails
 *    the tag check instead of decrypting there, so someone with DB write access
 *    cannot move e.g. an SMTP password into a field that is echoed back.
 *  - legacy: "ENC:" + base64(iv[12] | tag[16] | ciphertext), no AAD. Still
 *    accepted on read so stored values keep working; it is replaced by the v2
 *    form the next time the setting is written (no bulk migration). The
 *    legacy base64 payload never contains ':', so the two cannot be confused.
 *
 * Key is derived from PLUGIN_ENCRYPTION_KEY or APP_KEY environment variable.
 * Callers MUST pass the category and key of the value they read or write.
 */
final class SettingsEncryption
{
    public const PREFIX = 'ENC:';
    public const PREFIX_V2 = 'ENC:v2:';

    private const AAD_DOMAIN = 'pinakes-settings-v2';

    private static ?string $cachedKey = null;
    private static bool $keyResolved = false;

    /**
     * Encrypt $value for the setting identified by $category / $key (v2 format).
     */
    public static function encrypt(string $value, string $category, string $key): string
    {
        $encKey = self::getEncryptionKey();

        if ($value === '') {
            return '';
        }

        if ($encKey === null) {
            throw new \RuntimeException('SettingsEncryption: encryption key not configured');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag, self::aad($category, $key));

        if ($ciphertext === false) {
            throw new \RuntimeException('SettingsEncryption: openssl_encrypt failed');
        }

        return self::PREFIX_V2 . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a value stored for the setting $category / $key.
     *
     * Non-ciphertext input is returned unchanged (legacy plaintext rows);
     * null means the value looked encrypted but could not be decrypted (no
     * key, tampered payload, or a v2 ciphertext that belongs to another setting).
     */
    public static function decrypt(?string $value, string $category, string $key): ?string
    {
        if ($value === null || $value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $encKey = self::getEncryptionKey();
        if ($encKey === null) {
            SecureLogger::error('SettingsEncryption: encryption key missing — cannot decrypt');
            return null;
        }

        if (str_starts_with($value, self::PREFIX_V2)) {
            $encoded = substr($value, strlen(self::PREFIX_V2));
            $aad = self::aad($category, $key);
        } else {
            $encoded = substr($value, strlen(self::PREFIX));
            $aad = '';
        }

        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= 28) {
            SecureLogger::error('SettingsEncryption: invalid encrypted payload');
            return null;
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag, $aad);
            if ($plaintext === false) {
                SecureLogger::error('SettingsEncryption: decryption failed');
                return null;
            }
            return $plaintext;
        } catch (\Throwable $e) {
            SecureLogger::error('SettingsEncryption::decrypt exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Reset cached key (useful for testing or after env changes in long-running processes)
     */
    public static function resetKeyCache(): void
    {
        self::$cachedKey = null;
        self::$keyResolved = false;
    }

    /**
     * Associated data binding a ciphertext to its setting. NUL separators keep
     * ("a.b", "c") and ("a", "b.c") distinct.
     */
    private static function aad(string $category, string $key): string
    {
        return self::AAD_DOMAIN . "\0" . $category . "\0" . $key;
    }

    private static function getEncryptionKey(): ?string
    {
        if (self::$keyResolved) {
            return self::$cachedKey;
        }

        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY'] ?? (getenv('PLUGIN_ENCRYPTION_KEY') ?: null);
        if ($rawKey === null || $rawKey === '') {
            $rawKey = $_ENV['APP_KEY'] ?? (getenv('APP_KEY') ?: null);
        }
        if ($rawKey === '') {
            $rawKey = null;
        }

        if ($rawKey) {
            self::$cachedKey = hash('sha256', (string)$rawKey, true);
        } else {
            self::$cachedKey = null;
        }

        self::$keyResolved = true;
        return self::$cachedKey;
    }
}
