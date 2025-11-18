<?php
/**
 * HTTP Response Handler
 * Manages JSON responses and HTTP status codes
 */
class Response
{
    /**
     * Send JSON response
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send success response
     */
    public static function success(array $data, array $meta = []): never
    {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        self::json($response, 200);
    }

    /**
     * Send error response
     */
    public static function error(string $message, int $status = 400, array $context = []): never
    {
        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ];

        if (!empty($context)) {
            $response['context'] = $context;
        }

        self::json($response, $status);
    }

    /**
     * Send validation error response
     */
    public static function validationError(array $errors): never
    {
        self::json([
            'success' => false,
            'error' => 'Validation failed',
            'errors' => $errors,
            'timestamp' => date('c')
        ], 422);
    }

    /**
     * Send not found response
     */
    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error($message, 404);
    }

    /**
     * Send unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    /**
     * Send forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    /**
     * Send rate limit exceeded response
     */
    public static function tooManyRequests(string $message = 'Rate limit exceeded'): never
    {
        self::error($message, 429);
    }

    /**
     * Send internal server error response
     */
    public static function serverError(string $message = 'Internal server error'): never
    {
        self::error($message, 500);
    }
}
