<?php
declare(strict_types=1);

namespace App\Support;

/**
 * A book as a reference (#412): the input CitationStyles formats, and the same
 * data as a RIS file for EndNote, Mendeley and Zotero. The Cite dialog and the
 * RIS download both start from input(), so the styles and the file never
 * describe the book differently.
 */
final class BookCitation
{
    /**
     * Authors and co-authors are cited as authors, editors (curatore) as
     * editors; the other roles (translator, illustrator...) are not part of a
     * reference.
     *
     * @param array<string,mixed> $book a libri row (editore joined as `editore`)
     * @param list<array<string,mixed>> $authors the book's credits, with `ruolo`
     * @return array<string,mixed>
     */
    public static function input(array $book, array $authors): array
    {
        $decode = static fn(mixed $value): string => trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
        $citeAuthors = [];
        $citeEditors = [];
        foreach ($authors as $author) {
            $name = $decode(AuthorName::citation($author));
            if ($name === '') {
                continue;
            }
            $role = (string) ($author['ruolo'] ?? 'principale');
            if ($role === 'principale' || $role === 'co-autore') {
                $citeAuthors[] = $name;
            } elseif ($role === 'curatore') {
                $citeEditors[] = $name;
            }
        }
        $title = $decode($book['titolo'] ?? '');
        $subtitle = $decode($book['sottotitolo'] ?? '');

        return [
            'type' => 'book',
            'authors' => array_values(array_unique($citeAuthors)),
            'editors' => array_values(array_unique($citeEditors)),
            'year' => !empty($book['anno_pubblicazione']) ? (string) (int) $book['anno_pubblicazione'] : '',
            'title' => $subtitle !== '' ? $title . ' : ' . $subtitle : $title,
            'publisher' => $decode($book['editore'] ?? ''),
            'place' => $decode($book['luogo_pubblicazione'] ?? ''),
            'edition' => $decode($book['edizione'] ?? ''),
        ];
    }

    /**
     * The RIS record (TY BOOK). Every value is collapsed to one line: in RIS a
     * line break starts a new tag.
     *
     * @param array<string,mixed> $input from input()
     * @param array<string,mixed> $book  the libri row, for the identifiers
     */
    public static function ris(array $input, array $book, string $recordUrl = ''): string
    {
        $clean = static fn(mixed $value): string => trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        $lines = [['TY', 'BOOK']];
        foreach ($input['authors'] ?? [] as $author) {
            $lines[] = ['AU', $author];
        }
        foreach ($input['editors'] ?? [] as $editor) {
            $lines[] = ['A2', $editor];
        }
        $isbn = $clean($book['isbn13'] ?? '') !== '' ? $book['isbn13'] : ($book['isbn10'] ?? '');
        foreach ([
            'TI' => $input['title'] ?? '',
            'PY' => $input['year'] ?? '',
            'PB' => $input['publisher'] ?? '',
            'CY' => $input['place'] ?? '',
            'ET' => $input['edition'] ?? '',
            'SN' => $isbn,
            'T2' => $book['collana'] ?? '',
            'LA' => $book['lingua'] ?? '',
            'UR' => $recordUrl,
        ] as $tag => $value) {
            if ($clean($value) !== '') {
                $lines[] = [$tag, $value];
            }
        }
        foreach (preg_split('/\s*[,;]\s*/u', $clean($book['parole_chiave'] ?? '')) ?: [] as $keyword) {
            if ($keyword !== '') {
                $lines[] = ['KW', $keyword];
            }
        }
        $lines[] = ['ER', ''];

        $out = '';
        foreach ($lines as [$tag, $value]) {
            $out .= $tag . '  - ' . $clean($value) . "\r\n";
        }
        return $out;
    }
}
