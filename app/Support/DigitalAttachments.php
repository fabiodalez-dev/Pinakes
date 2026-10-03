<?php
declare(strict_types=1);
namespace App\Support;

/** Ordered digital editions, related documents and audio tracks on one record. */
final class DigitalAttachments
{
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
            // Accept HTTP resources and installation-relative uploads; never executable schemes,
            // protocol-relative URLs, backslashes or traversal paths.
            $local = str_starts_with($url, '/uploads/') && !str_contains(rawurldecode($url), '..')
                && !str_contains(rawurldecode($url), '\\') && !str_contains(rawurldecode($url), '//');
            if ((!$local && HtmlHelper::sanitizePublicHttpUrl($url) === '')
                || preg_match('/[\x00-\x20\x7f]/', $url) || strlen($url) > 2048
                || !in_array($kind, ['ebook', 'supplement', 'audio'], true)) {
                throw new \InvalidArgumentException(__('URL o tipo di allegato digitale non valido.'));
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

    /** Existing single-file records remain readable without a data rewrite.
     * @return list<array{url:string,label:string,kind:string}>
     */
    public static function fromBook(array $book): array
    {
        if (isset($book['digital_attachments'])) {
            try {
                $rows = json_decode((string) $book['digital_attachments'], true, 512, JSON_THROW_ON_ERROR);
                return self::normalize($rows);
            } catch (\JsonException | \InvalidArgumentException $e) {
                // A damaged legacy value must not hide the original single-file links.
            }
        }
        $rows = [];
        foreach (['file_url' => 'ebook', 'audio_url' => 'audio'] as $field => $kind) {
            if (!empty($book[$field]) && is_string($book[$field])) {
                try { $rows = array_merge($rows, self::normalize([['url' => $book[$field], 'kind' => $kind]])); }
                catch (\InvalidArgumentException $e) { /* Unsafe legacy URLs are not rendered. */ }
            }
        }
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
            if ($fields[$field] === '' && strlen($row['url']) <= 255) { $fields[$field] = $row['url']; }
        }
        return $fields;
    }
}
