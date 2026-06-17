<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared JSON envelope for the Mobile API.
 *
 * Every response is one of:
 *   { "data": <payload>, "meta": {...}, "error": null }
 *   { "data": null,      "meta": {...}, "error": { "code": "...", "message": "..." } }
 *
 * Error bodies NEVER leak internals (stack traces, SQL, exception messages from
 * \Throwable). Callers pass a stable machine code + a safe, translatable message.
 */
final class ResponseEnvelope
{
    private function __construct()
    {
    }

    /**
     * Emit a success envelope.
     *
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    public static function success(
        ResponseInterface $response,
        $data,
        array $meta = [],
        int $status = 200
    ): ResponseInterface {
        $payload = [
            'data'  => $data,
            'meta'  => (object) $meta,
            'error' => null,
        ];

        return self::write($response, $payload, $status);
    }

    /**
     * Emit an error envelope. The message must already be safe for clients.
     *
     * @param array<string, mixed> $meta
     */
    public static function error(
        ResponseInterface $response,
        string $code,
        string $message,
        int $status = 400,
        array $meta = []
    ): ResponseInterface {
        $payload = [
            'data'  => null,
            'meta'  => (object) $meta,
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        return self::write($response, $payload, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function write(
        ResponseInterface $response,
        array $payload,
        int $status
    ): ResponseInterface {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            // Last-resort fallback that can never itself leak internals.
            $json = '{"data":null,"meta":{},"error":{"code":"encoding_error","message":"Response encoding failed."}}';
            $status = 500;
        }

        $response->getBody()->write($json);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
