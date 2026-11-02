<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

/**
 * English keys for the copy cataloguing answers of the z39-server plugin
 * (CopyCataloging::servers(), search(), import()). Pure: unit-testable.
 */
final class CopyCatalogingMapper
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function server(string $key, array $server): array
    {
        return [
            'key'    => $key,
            'name'   => (string) ($server['name'] ?? $key),
            'kind'   => (string) ($server['kind'] ?? 'configured'),
            'url'    => (string) ($server['url'] ?? ''),
            'syntax' => (string) ($server['syntax'] ?? ''),
        ];
    }

    /**
     * One search result.
     *
     * @return array<string, mixed>
     */
    public static function result(mixed $row): array
    {
        $row = is_array($row) ? $row : [];
        $existing = (int) ($row['existing_id'] ?? 0);
        $detail = [];
        foreach (is_array($row['detail'] ?? null) ? $row['detail'] : [] as $line) {
            if (is_array($line)) {
                $detail[] = ['tag' => (string) ($line['tag'] ?? ''), 'ind' => (string) ($line['ind'] ?? ''), 'value' => (string) ($line['value'] ?? '')];
            }
        }

        return [
            'id'          => (string) ($row['id'] ?? ''),
            'server'      => (string) ($row['server'] ?? ''),
            'flavour'     => (string) ($row['flavour'] ?? ''),
            'title'       => (string) ($row['titolo'] ?? ''),
            'subtitle'    => self::nullable($row['sottotitolo'] ?? null),
            'authors'     => self::nullable($row['autori'] ?? null),
            'publisher'   => self::nullable($row['editore'] ?? null),
            'year'        => self::nullable($row['anno'] ?? null),
            'isbn'        => self::nullable($row['isbn'] ?? null),
            'cover_url'   => self::nullable($row['copertina'] ?? null),
            'existing_id' => $existing > 0 ? $existing : null,
            'detail'      => $detail,
        ];
    }

    /**
     * One server line of the search report.
     *
     * @return array<string, mixed>
     */
    public static function report(mixed $row): array
    {
        $row = is_array($row) ? $row : [];

        return [
            'key'   => (string) ($row['key'] ?? ''),
            'name'  => (string) ($row['name'] ?? ''),
            'total' => (int) ($row['total'] ?? 0),
            'count' => (int) ($row['count'] ?? 0),
            'error' => self::nullable($row['error'] ?? null),
        ];
    }

    /**
     * The import answer.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function importResult(array $json): array
    {
        $items = [];
        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $bookId = (int) ($item['book_id'] ?? 0);
            $items[] = [
                'id'       => (string) ($item['id'] ?? ''),
                'action'   => (string) ($item['action'] ?? 'failed'),
                'book_id'  => $bookId > 0 ? $bookId : null,
                'title'    => (string) ($item['title'] ?? ''),
                'warnings' => array_values(array_map('strval', is_array($item['warnings'] ?? null) ? $item['warnings'] : [])),
                'message'  => self::nullable($item['message'] ?? null),
            ];
        }

        return [
            'imported'  => (int) ($json['imported'] ?? 0),
            'updated'   => (int) ($json['updated'] ?? 0),
            'skipped'   => (int) ($json['skipped'] ?? 0),
            'failed'    => (int) ($json['failed'] ?? 0),
            'covers'    => (int) ($json['covers'] ?? 0),
            'import_id' => is_string($json['import_id'] ?? null) ? $json['import_id'] : null,
            'items'     => $items,
        ];
    }

    /**
     * The request fingerprint CopyCataloging::import() remembers with its
     * token: the same ids, duplicate mode and covers flag.
     *
     * @param list<string> $ids
     */
    public static function fingerprint(array $ids, string $mode, bool $covers): string
    {
        return hash('sha256', (string) json_encode([array_values(array_unique($ids)), $mode, $covers]));
    }

    private static function nullable(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text !== '' ? $text : null;
    }
}
