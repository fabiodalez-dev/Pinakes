<?php
declare(strict_types=1);
namespace App\Support;

/** Ordered digital editions, related documents and audio tracks on one record. */
final class DigitalAttachments
{
    /** The single-file columns older clients, imports and the API still read and write. */
    private const LEGACY_FIELDS = ['file_url' => 'ebook', 'audio_url' => 'audio'];

    /** Size of libri.file_url / libri.audio_url: a longer edition or track could not be mirrored there. */
    private const LEGACY_COLUMN_LENGTH = 255;

    /** @return list<array{url:string,label:string,kind:string}> */
    public static function normalize(mixed $rows): array
    {
        if (!is_array($rows) || count($rows) > 100) {
            throw new \InvalidArgumentException(__('Elenco degli allegati digitali non valido.'));
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null)
                || !is_string($row['label'] ?? '') || !is_string($row['kind'] ?? 'ebook')) {
                throw new \InvalidArgumentException(__('Allegato digitale non valido.'));
            }
            $url = trim($row['url']);
            if ($url === '') { continue; }
            $kind = $row['kind'] ?? 'ebook';
            // Accept HTTP resources and paths on this site (uploads, but also any other
            // root-relative path a record held before the list existed, which the old
            // single-file field stored and showed as is); never executable schemes,
            // protocol-relative URLs, backslashes or traversal paths.
            $local = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains(rawurldecode($url), '..')
                && !str_contains(rawurldecode($url), '\\') && !str_contains(rawurldecode($url), '//');
            if ((!$local && HtmlHelper::sanitizePublicHttpUrl($url) === '')
                || preg_match('/[\x00-\x20\x7f]/', $url) || strlen($url) > 2048
                || !in_array($kind, ['ebook', 'supplement', 'audio'], true)) {
                throw new \InvalidArgumentException(__('URL o tipo di allegato digitale non valido.'));
            }
            if ($kind !== 'supplement' && strlen($url) > self::LEGACY_COLUMN_LENGTH) {
                throw new \InvalidArgumentException(__('L’URL di un’edizione digitale o di un audiobook non può superare 255 caratteri.'));
            }
            $label = trim($row['label'] ?? '');
            if (mb_strlen($label) > 255) {
                throw new \InvalidArgumentException(__('Il titolo dell’allegato non può superare 255 caratteri.'));
            }
            if ($label === '') {
                $label = mb_substr(rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), 0, 255);
                if ($label === '') { $label = __('Allegato digitale'); }
            }
            $key = $kind . "\0" . $url;
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = ['url' => $url, 'label' => $label, 'kind' => $kind];
        }
        return $out;
    }

    /**
     * The attachments of a record, read from the stored list and kept in step with the
     * single-file columns.
     *
     * Records saved before the list existed are read from file_url / audio_url. A column
     * changed outside the editor (CSV import, API, plugin disabled) wins over the stored
     * list for that one slot, so the record never shows a file it no longer has.
     *
     * With $forEditing, a legacy value that cannot be read is returned as an `invalid`
     * row instead of being dropped: the editor shows it, and the next save either fixes
     * it or refuses, rather than silently blanking the column.
     *
     * @return list<array{url:string,label:string,kind:string,invalid?:true}>
     */
    public static function fromBook(array $book, bool $forEditing = false): array
    {
        $rows = null;
        if (isset($book['digital_attachments'])) {
            try {
                $rows = self::normalize(json_decode((string) $book['digital_attachments'], true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException | \InvalidArgumentException $e) {
                // A damaged list must not hide the original single-file links.
                $rows = null;
            }
        }
        if ($rows === null) {
            $rows = [];
            foreach (self::LEGACY_FIELDS as $field => $kind) {
                $value = is_string($book[$field] ?? null) ? trim($book[$field]) : '';
                if ($value !== '') {
                    $rows = self::withLegacyValue($rows, $kind, $value, $forEditing, count($rows));
                }
            }
            return $rows;
        }
        foreach (self::LEGACY_FIELDS as $field => $kind) {
            if (!array_key_exists($field, $book)) {
                continue;
            }
            $value = is_string($book[$field]) ? trim($book[$field]) : '';
            $primary = null;
            foreach ($rows as $i => $row) {
                if ($row['kind'] === $kind) { $primary = $i; break; }
            }
            $current = $primary === null ? '' : $rows[$primary]['url'];
            if ($value === $current || ($value !== '' && self::repairLegacyUrl($value) === $current)) {
                continue;
            }
            $at = $primary ?? count($rows);
            if ($primary !== null) {
                array_splice($rows, $primary, 1);
            }
            if ($value !== '') {
                $rows = self::withLegacyValue($rows, $kind, $value, $forEditing, $at);
            }
        }
        return $rows;
    }

    /**
     * Older versions stored these columns as free text, so a valid file can still be
     * written in a form the list rejects: no leading slash on an upload path, or a raw
     * space or accented letter. Percent-encode those bytes; the URL points at the same file.
     */
    private static function repairLegacyUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, 'uploads/')) {
            $url = '/' . $url;
        }
        return (string) preg_replace_callback('/[^\x21-\x7e]/', static fn (array $m): string => rawurlencode($m[0]), $url);
    }

    /**
     * @param list<array{url:string,label:string,kind:string,invalid?:true}> $rows
     * @return list<array{url:string,label:string,kind:string,invalid?:true}>
     */
    private static function withLegacyValue(array $rows, string $kind, string $value, bool $forEditing, int $at): array
    {
        try {
            $row = self::normalize([['url' => self::repairLegacyUrl($value), 'kind' => $kind]]);
        } catch (\InvalidArgumentException $e) {
            if (!$forEditing) {
                return $rows; // Unsafe legacy URLs are never rendered to readers.
            }
            $row = [['url' => $value, 'label' => '', 'kind' => $kind, 'invalid' => true]];
        }
        if ($row === []) {
            return $rows;
        }
        foreach ($rows as $i => $existing) {
            if ($existing['kind'] === $kind && $existing['url'] === $row[0]['url']) {
                array_splice($rows, $i, 1);
                $at = min($at, count($rows));
                break;
            }
        }
        array_splice($rows, min($at, count($rows)), 0, $row);
        return $rows;
    }

    /** Keep old integrations' first-file fields in sync, inside the book transaction. */
    public static function applySubmission(array $fields, array $data): array
    {
        if (!array_key_exists('digital_attachments_present', $data)) { return $fields; }
        $rows = self::normalize($data['digital_attachments'] ?? []);
        $fields['digital_attachments'] = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $fields['file_url'] = '';
        $fields['audio_url'] = '';
        foreach ($rows as $row) {
            $field = $row['kind'] === 'audio' ? 'audio_url' : 'file_url';
            // Supplementary documents are related material, not the digital edition itself.
            if ($row['kind'] === 'supplement') { continue; }
            if ($fields[$field] === '') { $fields[$field] = $row['url']; }
        }
        return $fields;
    }
}
