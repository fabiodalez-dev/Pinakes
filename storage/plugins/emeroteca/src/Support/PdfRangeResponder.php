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

        // Served straight from the file: a window over the open handle, no
        // copy of the slice (a large scan asked from byte N would otherwise be
        // duplicated on disk before the first byte goes out).
        return $response
            ->withStatus(206)
            ->withBody(new PdfSliceStream($handle, $start, $length))
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

/**
 * Read-only stream over bytes [$start, $start + $length) of an open file.
 * Positions are relative to the slice, so the emitter's rewind() lands on
 * $start and reads stop at the end of the range.
 */
final class PdfSliceStream implements \Psr\Http\Message\StreamInterface
{
    /** @var resource|null */
    private $handle;
    private int $position = 0;

    /** @param resource $handle */
    public function __construct($handle, private readonly int $start, private readonly int $length)
    {
        $this->handle = $handle;
        fseek($handle, $start);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;
        return $handle;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->handle === null || $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default  => $offset,
        };
        if ($this->handle === null || $target < 0 || $target > $this->length) {
            throw new \RuntimeException('Cannot seek outside the range');
        }
        fseek($this->handle, $this->start + $target);
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Read-only stream');
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function read(int $length): string
    {
        if ($this->handle === null) {
            throw new \RuntimeException('Stream is detached');
        }
        $length = min($length, $this->length - $this->position);
        if ($length <= 0) {
            return '';
        }
        $data = fread($this->handle, $length);
        if ($data === false) {
            throw new \RuntimeException('Cannot read the file');
        }
        $this->position += strlen($data);
        return $data;
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $chunk = $this->read(65536);
            if ($chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        return $out;
    }

    public function getMetadata(?string $key = null)
    {
        $meta = $this->handle !== null ? stream_get_meta_data($this->handle) : [];
        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}
