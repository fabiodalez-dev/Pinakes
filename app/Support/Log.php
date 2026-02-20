<?php
declare(strict_types=1);

namespace App\Support;

class Log
{
    /**
     * In-memory buffer for async logging
     */
    private static array $buffer = [];
    private static bool $shutdownRegistered = false;
    private static int $lastFlush = 0;

    /**
     * Write a debug line to storage/app.log and PHP error_log.
     * Only logs if APP_DEBUG=true or APP_ENV=development
     */
    public static function debug(string $tag, array $data = []): void
    {
        // Skip debug logs in production unless debug mode is enabled
        if (!self::shouldLogDebug()) {
            return;
        }
        self::write('DEBUG', $tag, $data);
    }

    /**
     * Write an info line to storage/app.log and PHP error_log.
     * Always logs regardless of environment
     */
    public static function info(string $tag, array $data = []): void
    {
        self::write('INFO', $tag, $data);
    }

    /**
     * Write a warning line to storage/app.log and PHP error_log.
     * Always logs regardless of environment
     */
    public static function warning(string $tag, array $data = []): void
    {
        self::write('WARNING', $tag, $data);
    }

    /**
     * Write an error line to storage/app.log and PHP error_log.
     * Always logs regardless of environment
     */
    public static function error(string $tag, array $data = []): void
    {
        self::write('ERROR', $tag, $data);
    }

    /**
     * Log security events to a separate file for auditing
     * ALWAYS logs regardless of environment (security is critical)
     */
    public static function security(string $tag, array $data = []): void
    {
        // Sanitize sensitive data in production
        $sanitizedData = self::sanitizeSecurityData($data);

        // Write ONLY to security.log (not app.log) to avoid double-write
        $line = date('c') . " [SECURITY:$tag] " . json_encode($sanitizedData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Use buffered write for better performance
        self::writeBuffered('security.log', $line, true); // true = force flush for critical security events
    }

    /**
     * Check if debug logging is enabled
     */
    private static function shouldLogDebug(): bool
    {
        // Check APP_DEBUG environment variable
        $appDebug = getenv('APP_DEBUG');
        if ($appDebug !== false && strtolower($appDebug) === 'true') {
            return true;
        }

        // Check APP_ENV environment variable
        $appEnv = getenv('APP_ENV');
        if ($appEnv !== false && strtolower($appEnv) === 'development') {
            return true;
        }

        return false;
    }

    /**
     * Sanitize sensitive data from security logs in production
     */
    private static function sanitizeSecurityData(array $data): array
    {
        // In development/debug mode, log everything
        if (self::shouldLogDebug()) {
            return $data;
        }

        // In production, sanitize sensitive fields
        $sanitized = $data;

        // Truncate tokens to prevent leaking in logs
        if (isset($sanitized['token_received']) && is_string($sanitized['token_received'])) {
            $sanitized['token_received'] = substr($sanitized['token_received'], 0, 8) . '***';
        }
        if (isset($sanitized['token_in_session']) && is_string($sanitized['token_in_session'])) {
            $sanitized['token_in_session'] = substr($sanitized['token_in_session'], 0, 8) . '***';
        }

        // Keep user_agent but truncate if too long
        if (isset($sanitized['user_agent']) && is_string($sanitized['user_agent']) && strlen($sanitized['user_agent']) > 150) {
            $sanitized['user_agent'] = substr($sanitized['user_agent'], 0, 150) . '...';
        }

        return $sanitized;
    }

    /**
     * Internal write method - uses buffering for better performance
     */
    private static function write(string $level, string $tag, array $data = []): void
    {
        $line = date('c') . " [$level:$tag] " . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Determine if we should force immediate write (for critical logs)
        $forceFlush = ($level === 'ERROR' || $level === 'SECURITY');

        // Use buffered write for better performance
        self::writeBuffered('app.log', $line, $forceFlush);

        // Also to PHP error log only in specific cases
        if (self::shouldLogDebug()) {
            @error_log($line);
        } elseif ($forceFlush && !self::isAsyncLoggingEnabled()) {
            // In production with sync logging, still log critical events to error_log
            @error_log($line);
        }
    }

    /**
     * Buffered write method for performance optimization
     */
    private static function writeBuffered(string $filename, string $line, bool $forceFlush = false): void
    {
        // Check if async logging is enabled
        $asyncEnabled = self::isAsyncLoggingEnabled();

        if (!$asyncEnabled || $forceFlush) {
            // Immediate write for critical events or when async disabled
            self::writeImmediate($filename, $line);
            return;
        }

        // Add to buffer
        if (!isset(self::$buffer[$filename])) {
            self::$buffer[$filename] = [];
        }
        self::$buffer[$filename][] = $line;

        // Register shutdown handler on first write
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'flushAll']);
            self::$shutdownRegistered = true;
        }

        // Check if we need to flush based on buffer size
        $bufferSize = self::getBufferSize();
        if (count(self::$buffer[$filename]) >= $bufferSize) {
            self::flush($filename);
            return;
        }

        // Check if we need to flush based on timeout
        $timeout = self::getBufferTimeout();
        if ($timeout > 0 && (time() - self::$lastFlush) >= $timeout) {
            self::flushAll();
        }
    }

    /**
     * Immediate write to file (bypasses buffer)
     */
    private static function writeImmediate(string $filename, string $line): void
    {
        $logFile = __DIR__ . '/../../storage/' . $filename;

        // Ensure directory exists (cached after first call)
        static $dirsCreated = [];
        $dir = dirname($logFile);
        if (!isset($dirsCreated[$dir])) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $dirsCreated[$dir] = true;
        }

        // Best-effort file write
        try {
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // ignore - logging should never crash the app
        }
    }

    /**
     * Flush specific log file buffer
     */
    private static function flush(string $filename): void
    {
        if (empty(self::$buffer[$filename])) {
            return;
        }

        $lines = implode('', self::$buffer[$filename]);
        self::writeImmediate($filename, $lines);
        self::$buffer[$filename] = [];
        self::$lastFlush = time();
    }

    /**
     * Flush all buffers (called on shutdown)
     */
    public static function flushAll(): void
    {
        foreach (array_keys(self::$buffer) as $filename) {
            self::flush($filename);
        }
    }

    /**
     * Check if async logging is enabled
     */
    private static function isAsyncLoggingEnabled(): bool
    {
        // In production, enable async by default for better performance
        // In development, disable async for immediate debugging
        if (self::shouldLogDebug()) {
            return false; // Sync logging in development for easier debugging
        }

        // Check environment variable
        $asyncEnabled = getenv('LOG_ASYNC_ENABLED');
        if ($asyncEnabled !== false) {
            return strtolower($asyncEnabled) === 'true';
        }

        // Default: enabled in production (we already returned false for debug mode above)
        return true;
    }

    /**
     * Get buffer size from environment or default
     */
    private static function getBufferSize(): int
    {
        $size = getenv('LOG_BUFFER_SIZE');
        if ($size !== false && is_numeric($size)) {
            return max(1, (int)$size);
        }
        return 50; // Default: flush every 50 log entries
    }

    /**
     * Get buffer timeout from environment or default
     */
    private static function getBufferTimeout(): int
    {
        $timeout = getenv('LOG_BUFFER_TIMEOUT');
        if ($timeout !== false && is_numeric($timeout)) {
            return max(0, (int)$timeout);
        }
        return 5; // Default: flush every 5 seconds
    }
}
