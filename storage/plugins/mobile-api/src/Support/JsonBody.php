<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Body parser for the Mobile API.
 *
 * Slim's getParsedBody() only decodes form-urlencoded / multipart bodies, NOT
 * application/json. The native app sends JSON, so we decode it ourselves and
 * fall back to the already-parsed body for form posts (used by the E2E suite and
 * for resilience). Returns an associative array; non-object JSON yields [].
 */
final class JsonBody
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(ServerRequestInterface $request): array
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'application/json')) {
            $raw = (string) $request->getBody();
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
