<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * A MARC record independent of its flavour (MARC 21, UNIMARC, danMARC2) and of
 * its container (ISO 2709, MARCXML, MARCXchange): a 24-character leader and
 * the fields in the order they were read or added. Values are UTF-8.
 */
final class MarcRecord
{
    public const DEFAULT_LEADER = '00000nam a2200000   4500';

    private string $leader;

    /** @var list<MarcField> */
    private array $fields = [];

    /** The MARCXchange `format` attribute ("danMARC2", "UNIMARC"), when given. */
    private string $format = '';

    public function __construct(string $leader = self::DEFAULT_LEADER)
    {
        $this->leader = self::normaliseLeader($leader);
    }

    /** A leader of exactly 24 characters: padded with blanks, or cut. */
    public static function normaliseLeader(string $leader): string
    {
        $leader = str_replace(["\r", "\n", "\t"], ' ', $leader);
        if (strlen($leader) < 24) {
            return str_pad($leader, 24, ' ');
        }
        return substr($leader, 0, 24);
    }

    public function leader(): string
    {
        return $this->leader;
    }

    public function setLeader(string $leader): void
    {
        $this->leader = self::normaliseLeader($leader);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function addControlField(string $tag, string $data): self
    {
        $this->fields[] = new MarcField($tag, $data);
        return $this;
    }

    /**
     * @param list<array{0: string, 1: string}> $subfields
     */
    public function addDataField(string $tag, string $ind1, string $ind2, array $subfields): self
    {
        $this->fields[] = new MarcField($tag, null, self::indicator($ind1), self::indicator($ind2), $subfields);
        return $this;
    }

    public function addField(MarcField $field): self
    {
        $this->fields[] = $field;
        return $this;
    }

    private static function indicator(string $value): string
    {
        return $value === '' ? ' ' : substr($value, 0, 1);
    }

    /** @return list<MarcField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<MarcField> */
    public function fieldsByTag(string $tag): array
    {
        return array_values(array_filter($this->fields, static fn (MarcField $f): bool => $f->tag === $tag));
    }

    public function first(string $tag): ?MarcField
    {
        foreach ($this->fields as $field) {
            if ($field->tag === $tag) {
                return $field;
            }
        }
        return null;
    }

    public function hasField(string $tag): bool
    {
        return $this->first($tag) !== null;
    }

    public function controlField(string $tag): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->tag === $tag && $field->data !== null) {
                return $field->data;
            }
        }
        return null;
    }

    /** The first value of a subfield across every field with the tag. */
    public function subfield(string $tag, string $code): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->tag === $tag && $field->data === null) {
                $value = $field->subfield($code);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @return list<string> */
    public function subfieldValues(string $tag, string $code): array
    {
        $out = [];
        foreach ($this->fields as $field) {
            if ($field->tag === $tag && $field->data === null) {
                foreach ($field->subfieldValues($code) as $value) {
                    $out[] = $value;
                }
            }
        }
        return $out;
    }

    /** @return array{leader: string, format: string, fields: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'leader' => $this->leader,
            'format' => $this->format,
            'fields' => array_map(static fn (MarcField $f): array => $f->toArray(), $this->fields),
        ];
    }

    /** @param array<string, mixed> $data the shape toArray() returns */
    public static function fromArray(array $data): self
    {
        $record = new self((string) ($data['leader'] ?? self::DEFAULT_LEADER));
        $record->setFormat((string) ($data['format'] ?? ''));
        foreach ((array) ($data['fields'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['tag'])) {
                continue;
            }
            $tag = (string) $field['tag'];
            if (array_key_exists('data', $field)) {
                $record->addControlField($tag, (string) $field['data']);
                continue;
            }
            $subfields = [];
            foreach ((array) ($field['subfields'] ?? []) as $sub) {
                if (is_array($sub) && count($sub) >= 2) {
                    $subfields[] = [(string) $sub[0], (string) $sub[1]];
                }
            }
            $record->addDataField($tag, (string) ($field['ind1'] ?? ' '), (string) ($field['ind2'] ?? ' '), $subfields);
        }
        return $record;
    }
}
