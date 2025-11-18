<?php
/**
 * Configuration Manager
 * Handles loading and accessing configuration from .env file
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Load configuration from .env file
     */
    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($envFile)) {
            throw new RuntimeException("Configuration file not found: {$envFile}");
        }

        // Try parse_ini_file first
        $env = @parse_ini_file($envFile);

        // If it fails, parse manually (handles edge cases)
        if ($env === false) {
            $env = self::parseEnvManually($envFile);
        }

        self::$config = array_merge(self::$config, $env);

        // Also set in $_ENV for backward compatibility
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        self::$loaded = true;
    }

    /**
     * Manually parse .env file (fallback for edge cases)
     */
    private static function parseEnvManually(string $envFile): array
    {
        $env = [];
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Check if configuration key exists
     */
    public static function has(string $key): bool
    {
        return isset(self::$config[$key]);
    }

    /**
     * Set configuration value (for testing)
     */
    public static function set(string $key, mixed $value): void
    {
        self::$config[$key] = $value;
    }

    /**
     * Get all configuration
     */
    public static function all(): array
    {
        return self::$config;
    }

    /**
     * Reset configuration (for testing)
     */
    public static function reset(): void
    {
        self::$config = [];
        self::$loaded = false;
    }
}
