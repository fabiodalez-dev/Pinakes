<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Opaque cursor codec for cursor-based pagination on catalog/list endpoints.
 *
 * The spec mandates `meta.next_cursor` (opaque) + `?cursor=...&limit=...`.
 * A cursor is an associative array (e.g. ['last_id' => 123]) encoded as
 * URL-safe base64 of its JSON. The opacity is a contract, not a security
 * boundary: every endpoint that decodes a cursor MUST still scope its query to
 * the authenticated user and apply `AND deleted_at IS NULL` on `libri`. Never
 * trust decoded values as authorization input.
 */
final class CursorCodec
{
    private function __construct()
    {
    }

    /**
     * Encode a cursor payload into an opaque, URL-safe string.
     *
     * @param array<string, scalar> $payload
     */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode an opaque cursor. Returns null when the cursor is empty, malformed,
     * or does not decode to an object — callers should treat null as "start".
     *
     * @return array<string, mixed>|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $b64 = strtr($cursor, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $json = base64_decode($b64, true);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
