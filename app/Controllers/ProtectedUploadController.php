<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Psr7\Stream;

/**
 * Serves PRIVATE uploaded files (digital-library content, archive documents,
 * generic storage) through PHP so the global middleware stack — notably
 * PrivateModeMiddleware — governs access to them.
 *
 * Background: public/.htaccess serves most of `/uploads/` directly from the
 * web server (so it never reaches PHP). That blanket rule also exposed the
 * private subtrees below, letting anyone with the URL fetch ebooks/audio and
 * archive documents even while "private mode" was on (issue #160). Those
 * prefixes are now routed here instead and pass through PrivateModeMiddleware
 * first; in private mode a logged-out request is redirected/401'd before this
 * controller ever runs.
 *
 * Security:
 *   - the requested path is realpath-resolved and MUST stay inside one of the
 *     allowed private roots (defence against `..` traversal and symlinks);
 *   - dotfiles and empty segments are rejected.
 *
 * Streaming honours single HTTP Range requests so audio/video seeking keeps
 * working.
 */
class ProtectedUploadController
{
    /** Allowed private upload roots, relative to public/uploads/. */
    private const ALLOWED_ROOTS = ['digital', 'archives/documents', 'storage'];

    /** Read chunk size for bounded (closed-range) responses. */
    private const CHUNK = 262144; // 256 KiB

    public function serve(Request $request, Response $response, array $args): Response
    {
        $rel = (string) ($args['path'] ?? '');

        // Reject obviously hostile inputs early (traversal, NUL, dotfiles).
        if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '..')) {
            return new SlimResponse(404);
        }

        $uploadsRoot = realpath(dirname(__DIR__, 2) . '/public/uploads');
        if ($uploadsRoot === false) {
            return new SlimResponse(404);
        }

        $candidate = realpath($uploadsRoot . '/' . $rel);
        if ($candidate === false || !is_file($candidate)) {
            return new SlimResponse(404);
        }

        // Containment: the resolved file must live under an allowed private root.
        $contained = false;
        foreach (self::ALLOWED_ROOTS as $root) {
            $base = realpath($uploadsRoot . '/' . $root);
            if ($base !== false && str_starts_with($candidate, $base . DIRECTORY_SEPARATOR)) {
                $contained = true;
                break;
            }
        }
        if (!$contained) {
            return new SlimResponse(404);
        }

        $size = filesize($candidate);
        $handle = fopen($candidate, 'rb');
        if ($size === false || $handle === false) {
            return new SlimResponse(404);
        }

        $headers = [
            'Content-Type' => $this->contentType($candidate),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="' . rawurlencode(basename($candidate)) . '"',
        ];

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = $request->getHeaderLine('Range');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            $reqStart = $m[1] === '' ? null : (int) $m[1];
            $reqEnd = $m[2] === '' ? null : (int) $m[2];

            if ($reqStart === null && $reqEnd !== null) {
                // Suffix range: the last N bytes.
                $start = $reqEnd >= $size ? 0 : $size - $reqEnd;
            } else {
                $start = $reqStart ?? 0;
                if ($reqEnd !== null) {
                    $end = min($reqEnd, $size - 1);
                }
            }

            if ($start > $end || $start >= $size || $start < 0) {
                fclose($handle);
                return (new SlimResponse(416))->withHeader('Content-Range', "bytes */{$size}");
            }

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        $body = $this->buildBody($handle, $start, $end, $size);

        $res = new SlimResponse($status, null, $body);
        foreach ($headers as $name => $value) {
            $res = $res->withHeader($name, $value);
        }
        return $res;
    }

    /**
     * Whole-file and open-ended ranges stream straight from the file handle.
     * A closed range is copied (bounded) into a php://temp stream so exactly
     * the requested bytes are emitted (php://temp spills to disk past 2 MiB).
     *
     * @param resource $handle
     */
    private function buildBody($handle, int $start, int $end, int $size): Stream
    {
        // Open-ended (or full file): the body is the handle from $start to EOF.
        if ($end === $size - 1) {
            if ($start > 0) {
                fseek($handle, $start);
            }
            return new Stream($handle);
        }

        // Closed range: emit exactly [$start, $end].
        $temp = fopen('php://temp', 'w+b');
        if ($temp === false) {
            // Degrade gracefully to streaming from $start.
            if ($start > 0) {
                fseek($handle, $start);
            }
            return new Stream($handle);
        }
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $buf = fread($handle, (int) min(self::CHUNK, $remaining));
            if ($buf === false || $buf === '') {
                break;
            }
            fwrite($temp, $buf);
            $remaining -= strlen($buf);
        }
        fclose($handle);
        rewind($temp);
        return new Stream($temp);
    }

    private function contentType(string $path): string
    {
        $map = [
            'pdf' => 'application/pdf',
            'epub' => 'application/epub+zip',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain; charset=utf-8',
            'zip' => 'application/zip',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        $detected = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }
}
