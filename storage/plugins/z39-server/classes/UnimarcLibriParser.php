<?php

declare(strict_types=1);

namespace Z39Server;

/**
 * Bidirectional UNIMARC ↔ Pinakes `libri` mapper — issue #133 (REICAT/SBN).
 *
 * Two directions:
 *   • toUnimarcXml(): serialises a libri record to a standalone UNIMARC
 *     document in the MARCXchange container (ISO 25577). Reuses
 *     {@see UNIMARCXMLFormatter} for the bibliographic core, then appends the
 *     REICAT/SBN-specific fields (676 Dewey, 606 Nuovo Soggettario subjects).
 *   • fromUnimarcXml(): parses a UNIMARC/MARCXchange record (e.g. an SRU
 *     response or an SBN export) into the libri field shape consumed by the
 *     book form / persistence layer.
 *
 * Author intellectual-responsibility roles round-trip through the UNIMARC `$4`
 * relator subfield using the IFLA UNIMARC relator codes, mapped to/from the
 * core `libri_autori.ruolo` enum.
 */
class UnimarcLibriParser
{
    private const NS_MARCXCHANGE = 'info:lc/xmlns/marcxchange-v2';

    /**
     * Serialise a libri record to a standalone UNIMARC/MARCXchange document.
     *
     * @param array<string,mixed> $record  Fields expected by UNIMARCXMLFormatter:
     *        id, titolo, sottotitolo, autori ("Name; Name"), edizione, editore,
     *        anno_pubblicazione, numero_pagine, collana, numero_serie,
     *        descrizione, genere, parole_chiave, lingua, isbn13, isbn10.
     * @param list<array{termine:string,bncf_id?:?string,uri?:?string}> $subjects
     *        Nuovo Soggettario subject headings to emit as 606 fields.
     */
    public function toUnimarcXml(array $record, array $subjects = []): string
    {
        require_once __DIR__ . '/RecordFormatter.php';
        require_once __DIR__ . '/UNIMARCXMLFormatter.php';

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $collection = $doc->createElementNS(self::NS_MARCXCHANGE, 'collection');
        $doc->appendChild($collection);

        $formatter = new UNIMARCXMLFormatter($doc);
        $recordEl = $formatter->format($record);

        // 676 — Dewey Decimal Classification (not emitted by the base formatter).
        $dewey = trim((string) ($record['classificazione_dewey'] ?? ''));
        if ($dewey !== '') {
            $recordEl->appendChild($this->datafield($doc, '676', ' ', ' ', [['a', $dewey]]));
        }

        // 606 — Topical name used as subject (Nuovo Soggettario BNCF).
        // $2 carries the thesaurus code, $3 the BNCF authority identifier.
        foreach ($subjects as $subject) {
            $termine = trim($subject['termine']);
            if ($termine === '') {
                continue;
            }
            $subs = [['a', $termine], ['2', 'BNCF']];
            $bncfId = trim((string) ($subject['bncf_id'] ?? ''));
            if ($bncfId !== '') {
                $subs[] = ['3', $bncfId];
            }
            $recordEl->appendChild($this->datafield($doc, '606', ' ', ' ', $subs));
        }

        $collection->appendChild($recordEl);

        $xml = $doc->saveXML();
        return $xml !== false ? $xml : '';
    }

    /**
     * Parse a UNIMARC/MARCXchange record into the libri field shape.
     *
     * Namespace-agnostic: reads `datafield`/`controlfield`/`subfield` by local
     * name so it accepts both MARCXchange and bare MARC-slim serialisations.
     *
     * @return array<string,mixed> Keys: titolo, sottotitolo, isbn13, isbn10,
     *         anno_pubblicazione, editore, edizione, numero_pagine, collana,
     *         numero_serie, lingua, classificazione_dewey, parole_chiave,
     *         soggetti (list), authors (list of [name, ruolo]).
     */
    public function fromUnimarcXml(string $xml): array
    {
        $out = [];
        $authors = [];
        $subjects = [];

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return $out;
        }

        foreach ($this->elementsByLocalName($doc, 'datafield') as $field) {
            $tag = $field->getAttribute('tag');
            $subs = $this->subfieldsOf($field);

            switch ($tag) {
                case '010': // ISBN
                    $isbn = preg_replace('/[^0-9X]/i', '', $subs['a'][0] ?? '') ?? '';
                    if ($isbn !== '') {
                        if (strlen($isbn) === 13) {
                            $out['isbn13'] = $isbn;
                        } else {
                            $out['isbn10'] = $isbn;
                        }
                    }
                    break;
                case '101': // Language of document
                    if (isset($subs['a'][0])) {
                        $out['lingua'] = $this->langName($subs['a'][0]);
                    }
                    break;
                case '200': // Title + statement of responsibility
                    if (isset($subs['a'][0])) {
                        $out['titolo'] = $subs['a'][0];
                    }
                    if (isset($subs['e'][0])) {
                        $out['sottotitolo'] = $subs['e'][0];
                    }
                    break;
                case '205': // Edition
                    if (isset($subs['a'][0])) {
                        $out['edizione'] = $subs['a'][0];
                    }
                    break;
                case '210': // Publication (UNIMARC pre-2014)
                case '214': // Publication (UNIMARC 2014+)
                    if (isset($subs['c'][0]) && !isset($out['editore'])) {
                        $out['editore'] = $subs['c'][0];
                    }
                    if (isset($subs['d'][0]) && !isset($out['anno_pubblicazione'])) {
                        $year = $this->extractYear($subs['d'][0]);
                        if ($year !== null) {
                            $out['anno_pubblicazione'] = $year;
                        }
                    }
                    break;
                case '215': // Physical description
                    if (isset($subs['a'][0])) {
                        $pages = $this->extractPages($subs['a'][0]);
                        if ($pages > 0) {
                            $out['numero_pagine'] = $pages;
                        }
                    }
                    break;
                case '225': // Series
                    if (isset($subs['a'][0])) {
                        $out['collana'] = $subs['a'][0];
                    }
                    if (isset($subs['v'][0])) {
                        $out['numero_serie'] = $subs['v'][0];
                    }
                    break;
                case '606': // Subject
                    if (isset($subs['a'][0])) {
                        $subjects[] = [
                            'termine' => $subs['a'][0],
                            'bncf_id' => $subs['3'][0] ?? null,
                        ];
                    }
                    break;
                case '676': // Dewey
                    if (isset($subs['a'][0])) {
                        $out['classificazione_dewey'] = trim($subs['a'][0]);
                    }
                    break;
                case '700': // Primary author
                case '701': // Alternative author
                case '702': // Secondary intellectual responsibility
                    $name = $this->composeName($subs);
                    if ($name !== '') {
                        $relator = $subs['4'][0] ?? '070';
                        $ruolo = $this->roleForRelator($relator);
                        if ($tag === '700' && $authors === []) {
                            $ruolo = 'principale';
                        }
                        $authors[] = ['name' => $name, 'ruolo' => $ruolo];
                    }
                    break;
            }
        }

        if ($authors !== []) {
            $out['authors'] = $authors;
            $out['autori'] = implode('; ', array_map(static fn(array $a): string => $a['name'], $authors));
        }
        if ($subjects !== []) {
            $out['soggetti'] = $subjects;
            $out['parole_chiave'] = implode(', ', array_map(
                static fn(array $s): string => (string) $s['termine'],
                $subjects
            ));
        }

        return $out;
    }

    /**
     * Map a core libri_autori.ruolo to its UNIMARC $4 relator code.
     */
    public static function relatorForRole(string $ruolo): string
    {
        return match ($ruolo) {
            'traduttore'   => '730', // Translator
            'curatore'     => '340', // Editor
            'illustratore' => '440', // Illustrator
            default        => '070', // Author (principale / co-autore / unknown)
        };
    }

    /**
     * Map a UNIMARC $4 relator code to a core libri_autori.ruolo.
     */
    private function roleForRelator(string $relator): string
    {
        return match ($relator) {
            '730' => 'traduttore',
            '340' => 'curatore',
            '440' => 'illustratore',
            default => 'co-autore',
        };
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param list<array{0:string,1:string}> $subfields
     */
    private function datafield(\DOMDocument $doc, string $tag, string $ind1, string $ind2, array $subfields): \DOMElement
    {
        $field = $doc->createElement('datafield');
        $field->setAttribute('tag', $tag);
        $field->setAttribute('ind1', $ind1);
        $field->setAttribute('ind2', $ind2);
        foreach ($subfields as [$code, $value]) {
            $sub = $doc->createElement('subfield');
            $sub->setAttribute('code', $code);
            $sub->appendChild($doc->createTextNode($value));
            $field->appendChild($sub);
        }
        return $field;
    }

    /**
     * @return list<\DOMElement>
     */
    private function elementsByLocalName(\DOMDocument $doc, string $localName): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName('*') as $node) {
            if ($node->localName === $localName) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Collect subfields of a datafield keyed by code (a code may repeat).
     *
     * Numeric subfield codes ('3', '4', ...) become int array keys in PHP, so
     * the key type is int|string.
     *
     * @return array<int|string,list<string>>
     */
    private function subfieldsOf(\DOMElement $field): array
    {
        $subs = [];
        foreach ($field->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'subfield') {
                $code = $child->getAttribute('code');
                $subs[$code][] = trim($child->textContent);
            }
        }
        return $subs;
    }

    /**
     * Compose a personal name from $a (entry element) + $b (rest of name).
     *
     * @param array<int|string,list<string>> $subs
     */
    private function composeName(array $subs): string
    {
        $a = trim($subs['a'][0] ?? '');
        $b = trim($subs['b'][0] ?? '');
        if ($a === '') {
            return '';
        }
        // UNIMARC entry under surname: $a=surname, $b=forename → "Forename Surname"
        if ($b !== '') {
            $a = rtrim($a, ', ');
            return trim($b . ' ' . $a);
        }
        // Single $a may itself be "Surname, Forename".
        if (str_contains($a, ',')) {
            [$surname, $forename] = array_map('trim', explode(',', $a, 2));
            if ($surname !== '' && $forename !== '') {
                return $forename . ' ' . $surname;
            }
        }
        return $a;
    }

    private function extractYear(string $value): ?int
    {
        if (preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $value, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function extractPages(string $desc): int
    {
        if (preg_match('/(\d+)\s*p/i', $desc, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function langName(string $code): string
    {
        return match (strtolower(trim($code))) {
            'ita' => 'italiano',
            'eng' => 'inglese',
            'ger', 'deu' => 'tedesco',
            'fre', 'fra' => 'francese',
            'spa' => 'spagnolo',
            'por' => 'portoghese',
            'rus' => 'russo',
            'lat' => 'latino',
            default => $code,
        };
    }
}
