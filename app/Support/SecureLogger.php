<?php
declare(strict_types=1);

namespace App\Support;

class SecureLogger
{
    private static array $sensitiveKeys = [
        'password', 'token', 'key', 'secret', 'auth', 'csrf_token', 
        'session_id', 'cookie', 'authorization', 'x-api-key', 'api_key'
    ];

    public static function log(string $level, string $message, array $context = []): void
    {
        // Sanitize sensitive data from context
        $sanitizedContext = self::sanitizeContext($context);
        
        // Sanitize the message itself
        $sanitizedMessage = self::sanitizeString($message);
        
        // Format the log entry
        $logEntry = [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'level' => strtoupper($level),
            'message' => $sanitizedMessage,
            'context' => $sanitizedContext,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $_SESSION['user']['id'] ?? null
        ];

        // Write to log file
        $logLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents(__DIR__ . '/../../storage/logs/app.log', $logLine, FILE_APPEND | LOCK_EX);
    }

    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            $sanitizedKey = self::sanitizeString((string)$key);
            
            if (is_array($value)) {
                $sanitized[$sanitizedKey] = self::sanitizeContext($value);
            } elseif (is_string($value)) {
                // Check if key contains sensitive terms
                $lowerKey = strtolower($sanitizedKey);
                foreach (self::$sensitiveKeys as $sensitive) {
                    if (str_contains($lowerKey, $sensitive)) {
                        $sanitized[$sanitizedKey] = '[REDACTED]';
                        continue 2;
                    }
                }
                $sanitized[$sanitizedKey] = self::sanitizeString($value);
            } else {
                $sanitized[$sanitizedKey] = $value;
            }
        }
        
        return $sanitized;
    }

    private static function sanitizeString(string $string): string
    {
        // Remove potential XSS content
        $string = htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        
        // Remove null bytes and control characters
        $string = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
        
        // Limit string length
        if (strlen($string) > 1000) {
            $string = substr($string, 0, 997) . '...';
        }
        
        return $string;
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (getenv('APP_DEBUG') !== 'true') {
            return;
        }
        self::log('debug', $message, $context);
    }
}