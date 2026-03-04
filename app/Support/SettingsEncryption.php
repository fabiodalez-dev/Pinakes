<?php
declare(strict_types=1);

namespace App\Support;

/**
 * AES-256-GCM encryption for sensitive settings (SMTP passwords, API keys, etc.)
 *
 * Uses the same encryption scheme as PluginManager: ENC: prefix + base64(iv + tag + ciphertext).
 * Key is derived from PLUGIN_ENCRYPTION_KEY or APP_KEY environment variable.
 */
final class SettingsEncryption
{
    private static ?string $cachedKey = null;
    private static bool $keyResolved = false;

    public static function encrypt(string $value): string
    {
        $key = self::getEncryptionKey();

        if ($key === null || $value === '') {
            return $value;
        }

        try {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($ciphertext === false) {
                return $value;
            }

            return 'ENC:' . base64_encode($iv . $tag . $ciphertext);
        } catch (\Throwable $e) {
            SecureLogger::error('SettingsEncryption::encrypt failed: ' . $e->getMessage());
            return $value;
        }
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || strpos($value, 'ENC:') !== 0) {
            return $value;
        }

        $key = self::getEncryptionKey();
        if ($key === null) {
            SecureLogger::error('SettingsEncryption: encryption key missing — cannot decrypt');
            return null;
        }

        $payload = base64_decode(substr($value, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            SecureLogger::error('SettingsEncryption: invalid encrypted payload');
            return null;
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
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

    private static function getEncryptionKey(): ?string
    {
        if (self::$keyResolved) {
            return self::$cachedKey;
        }

        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? (getenv('PLUGIN_ENCRYPTION_KEY') ?: null)
            ?? $_ENV['APP_KEY']
            ?? (getenv('APP_KEY') ?: null);

        if ($rawKey) {
            self::$cachedKey = hash('sha256', (string)$rawKey, true);
        } else {
            self::$cachedKey = null;
        }

        self::$keyResolved = true;
        return self::$cachedKey;
    }
}
