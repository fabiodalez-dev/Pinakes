<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * One field of a MARC record: a control field (001-009) carries its value in
 * $data; a data field carries two indicators and its subfields, in order, as
 * [code, value] pairs (a code may repeat).
 */
final class MarcField
{
    /**
     * @param list<array{0: string, 1: string}> $subfields
     */
    public function __construct(
        public readonly string $tag,
        public readonly ?string $data = null,
        public readonly string $ind1 = ' ',
        public readonly string $ind2 = ' ',
        public readonly array $subfields = []
    ) {
    }

    public function isControl(): bool
    {
        return $this->data !== null;
    }

    /** The first value of a subfield, or null. */
    public function subfield(string $code): ?string
    {
        foreach ($this->subfields as [$c, $value]) {
            if ($c === $code) {
                return $value;
            }
        }
        return null;
    }

    /** @return list<string> every value of a subfield, in order */
    public function subfieldValues(string $code): array
    {
        $out = [];
        foreach ($this->subfields as [$c, $value]) {
            if ($c === $code) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Subfields keyed by code, as the catalogue parsers read them. Numeric
     * codes become integer keys, as PHP does with any numeric string.
     *
     * @return array<array-key, list<string>>
     */
    public function subfieldMap(): array
    {
        $out = [];
        foreach ($this->subfields as [$code, $value]) {
            $out[$code][] = $value;
        }
        return $out;
    }

    /** @return array{tag: string, data?: string, ind1?: string, ind2?: string, subfields?: list<array{0: string, 1: string}>} */
    public function toArray(): array
    {
        if ($this->data !== null) {
            return ['tag' => $this->tag, 'data' => $this->data];
        }
        return ['tag' => $this->tag, 'ind1' => $this->ind1, 'ind2' => $this->ind2, 'subfields' => $this->subfields];
    }
}
