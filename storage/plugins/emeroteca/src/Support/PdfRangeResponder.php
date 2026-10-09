<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * Streams a PDF with HTTP range support (RFC 9110 §14): the browser's PDF
 * viewer asks for byte ranges to show the first page of a large scan and to
 * seek without downloading the whole file first.
 *
 * One range per request (what PDF viewers send); a multi-range or malformed
 * header falls back to the whole file, an unsatisfiable one answers 416.
 *
 * No PSR-4 autoloader scope exists for plugin classes: callers require_once
 * this file directly.
 */
final class PdfRangeResponder
{
    /**
     * @param array<string, string> $headers Extra headers (Content-Disposition, Cache-Control, …)
     */
    public static function respond(
        ?ServerRequestInterface $request,
        ResponseInterface $response,
        string $path,
        array $headers
    ): ResponseInterface {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($size === false || $handle === false) {
            return $response->withStatus(404);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response = $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $range = self::parseRange($request !== null ? $request->getHeaderLine('Range') : '', $size);
        if ($range === null) {
            return $response
                ->withBody(new Stream($handle))
                ->withHeader('Content-Length', (string) $size);
        }
        if ($range === false) {
            fclose($handle);
            return $response
                ->withStatus(416)
                ->withHeader('Content-Range', 'bytes */' . $size)
                ->withHeader('Content-Length', '0');
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;
        // Copy just the requested slice into a temp stream: Slim's emitter
        // writes the whole body, so the original handle cannot be offset.
        $slice = fopen('php://temp', 'w+b');
        if ($slice === false) {
            fclose($handle);
            return $response->withStatus(500);
        }
        stream_copy_to_stream($handle, $slice, $length, $start);
        fclose($handle);
        rewind($slice);

        return $response
            ->withStatus(206)
            ->withBody(new Stream($slice))
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size))
            ->withHeader('Content-Length', (string) $length);
    }

    /**
     * @return array{0:int,1:int}|false|null [start, end] for a satisfiable
     *         single range, false for an unsatisfiable one, null for no
     *         (or an unsupported) Range header.
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '' || !str_starts_with(strtolower($header), 'bytes=')) {
            return null;
        }
        $spec = trim(substr($header, 6));
        if ($spec === '' || str_contains($spec, ',')) {
            return null; // multi-range: serve the whole file
        }
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($size <= 0) {
            return false;
        }
        if ($m[1] === '') {
            // Suffix range: the last N bytes.
            $suffix = (int) $m[2];
            if ($suffix <= 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return false;
        }
        return [$start, $end];
    }
}
