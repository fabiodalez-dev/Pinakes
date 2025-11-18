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

        $env = parse_ini_file($envFile);

        if ($env === false) {
            throw new RuntimeException("Failed to parse configuration file: {$envFile}");
        }

        self::$config = array_merge(self::$config, $env);

        // Also set in $_ENV for backward compatibility
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
        }

        self::$loaded = true;
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
