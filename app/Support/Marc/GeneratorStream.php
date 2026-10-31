<?php
declare(strict_types=1);

namespace App\Support\Marc;

use Psr\Http\Message\StreamInterface;

/**
 * A response body produced while it is sent: each read() pulls the next
 * chunks from a generator, so a whole-catalogue MARC export never sits in
 * memory. Not seekable and of unknown size: Slim's emitter then reads it to
 * the end in chunks.
 */
final class GeneratorStream implements StreamInterface
{
    private string $buffer = '';
    private bool $started = false;
    private bool $finished = false;
    private int $position = 0;

    /** @param \Generator<int, string> $generator */
    public function __construct(private \Generator $generator)
    {
    }

    private function pull(int $length): void
    {
        while (!$this->finished && strlen($this->buffer) < $length) {
            if ($this->started) {
                $this->generator->next();
            }
            $this->started = true;
            if (!$this->generator->valid()) {
                $this->finished = true;
                break;
            }
            $this->buffer .= (string) $this->generator->current();
        }
    }

    public function read(int $length): string
    {
        $this->pull($length);
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = (string) substr($this->buffer, strlen($chunk));
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function eof(): bool
    {
        $this->pull(1);
        return $this->finished && $this->buffer === '';
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(65536);
        }
        return $out;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->finished = true;
        $this->buffer = '';
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('GeneratorStream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('GeneratorStream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('GeneratorStream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
