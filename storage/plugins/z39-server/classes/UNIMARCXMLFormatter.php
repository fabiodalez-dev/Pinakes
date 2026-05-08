<?php

declare(strict_types=1);

namespace Z39Server;

/**
 * UNIMARC/XML formatter for the SRU endpoint.
 *
 * Produces UNIMARC Bibliographic records serialised in the MARC21slim XML
 * container (same namespace as MARCXML) following the IFLA UNIMARC 2008 field
 * codes.  Mirrors the UNIMARC output of OaiPmhServerPlugin::writeBookUnimarc()
 * but works with the DOMDocument API used by the SRU record pipeline.
 *
 * recordSchema identifier: info:srw/schema/8/unimarcxml-v0.1
 */
class UNIMARCXMLFormatter extends RecordFormatter
{
    private const NS_MARC = 'http://www.loc.gov/MARC21/slim';

    public function format(array $record): \DOMElement
    {
        $recordEl = $this->doc->createElementNS(self::NS_MARC, 'record');
        $recordEl->setAttribute('type', 'Bibliographic');

        // Leader — 'nam': text language material, monograph
        $recordEl->appendChild($this->doc->createElement('leader', '00000nam a2200000 u 4500'));

        // 001 — Control number (local book ID)
        $recordEl->appendChild($this->cf('001', (string) ($record['id'] ?? '')));

        // 003 — Identifier source
        $recordEl->appendChild($this->cf('003', 'IT-Pinakes'));

        // 005 — Date/time of last modification
        $recordEl->appendChild($this->cf('005', gmdate('YmdHis') . '.0'));

        // 100 — General processing data (UNIMARC fixed 36-char field)
        // Layout: 0-7 date entered, 8 type of date, 9-12 year1, 13-16 year2,
        //         17 audience, 18-21 illustrations, 22-24 language, 25 transliteration, 26-35 charset
        $langCode = $this->langCode((string) ($record['lingua'] ?? ''));
        $year     = (string) ($record['anno_pubblicazione'] ?? '');
        $date1    = str_pad(
            (strlen($year) === 4 && ctype_digit($year)) ? $year : '',
            4,
            '0',
            STR_PAD_LEFT
        );
        $f100 = gmdate('Ymd')   // 0-7:  date entered
              . 'a'              // 8:    type of date = single known date
              . $date1           // 9-12: year 1
              . '    '           // 13-16: year 2
              . ' '              // 17:   target audience
              . '    '           // 18-21: illustrations
              . $langCode        // 22-24: language code
              . ' '              // 25:   transliteration
              . '          ';    // 26-35: character set (10 spaces)
        $recordEl->appendChild($this->cf('100', $f100));

        // 010 — ISBN
        $isbn13 = trim((string) ($record['isbn13'] ?? ''));
        $isbn10 = trim((string) ($record['isbn10'] ?? ''));
        $isbn   = $isbn13 !== '' ? $isbn13 : $isbn10;
        if ($isbn !== '') {
            $recordEl->appendChild($this->df('010', ' ', ' ', [['a', $isbn]]));
        }

        // 101 — Language of document
        $recordEl->appendChild($this->df('101', '0', ' ', [['a', $langCode]]));

        // 102 — Country of publication
        $recordEl->appendChild($this->df('102', ' ', ' ', [['a', 'IT']]));

        // 200 — Title and statement of responsibility
        $authorList = $this->parseAuthors((string) ($record['autori'] ?? ''));
        $subs200    = [['a', (string) ($record['titolo'] ?? '')]];
        if (!empty($record['sottotitolo'])) {
            $subs200[] = ['e', (string) $record['sottotitolo']];
        }
        if ($authorList !== []) {
            $subs200[] = ['f', $authorList[0]];
        }
        $recordEl->appendChild($this->df('200', '1', ' ', $subs200));

        // 205 — Edition statement
        if (!empty($record['edizione'])) {
            $recordEl->appendChild($this->df('205', ' ', ' ', [['a', (string) $record['edizione']]]));
        }

        // 210 — Publication, distribution, manufacture
        $subs210 = [];
        if (!empty($record['editore'])) {
            $subs210[] = ['c', (string) $record['editore']];
        }
        if ($year !== '') {
            $subs210[] = ['d', $year];
        }
        if ($subs210 !== []) {
            $recordEl->appendChild($this->df('210', ' ', ' ', $subs210));
        }

        // 215 — Physical description
        if (!empty($record['numero_pagine'])) {
            $recordEl->appendChild($this->df('215', ' ', ' ', [
                ['a', $record['numero_pagine'] . ' p.'],
            ]));
        }

        // 225 — Collection / series
        if (!empty($record['collana'])) {
            $subs225 = [['a', (string) $record['collana']]];
            if (!empty($record['numero_serie'])) {
                $subs225[] = ['v', (string) $record['numero_serie']];
            }
            $recordEl->appendChild($this->df('225', '0', ' ', $subs225));
        }

        // 330 — Abstract / summary
        if (!empty($record['descrizione'])) {
            $recordEl->appendChild($this->df('330', ' ', ' ', [
                ['a', strip_tags((string) $record['descrizione'])],
            ]));
        }

        // 606 — Subject — genre
        if (!empty($record['genere'])) {
            $recordEl->appendChild($this->df('606', ' ', ' ', [['a', (string) $record['genere']]]));
        }

        // 606 — Subject — keywords
        if (!empty($record['parole_chiave'])) {
            foreach (explode(',', (string) $record['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $recordEl->appendChild($this->df('606', ' ', ' ', [['a', $kw]]));
                }
            }
        }

        // 700/701 — Personal name (primary / alternative intellectual responsibility)
        // FIX 7: UNIMARC ind1=undefined (space), ind2=form of name: 1=surname entry
        foreach ($authorList as $i => $name) {
            $tag = ($i === 0) ? '700' : '701';
            $recordEl->appendChild($this->df($tag, ' ', '1', [
                ['a', $name],
                ['4', '070'],
            ]));
        }

        // 801 — Originating source
        $recordEl->appendChild($this->df('801', ' ', '0', [
            ['a', 'IT'],
            ['b', 'Pinakes'],
            ['c', gmdate('Ymd')],
        ]));

        return $recordEl;
    }

    // ── DOM helpers ───────────────────────────────────────────────────────────

    private function cf(string $tag, string $value): \DOMElement
    {
        $field = $this->doc->createElement('controlfield', $this->escapeXml($value));
        $field->setAttribute('tag', $tag);
        return $field;
    }

    /**
     * @param list<array{0:string,1:string}> $subfields
     */
    private function df(string $tag, string $ind1, string $ind2, array $subfields): \DOMElement
    {
        $field = $this->doc->createElement('datafield');
        $field->setAttribute('tag', $tag);
        $field->setAttribute('ind1', $ind1);
        $field->setAttribute('ind2', $ind2);
        foreach ($subfields as [$code, $value]) {
            $sub = $this->doc->createElement('subfield', $this->escapeXml($value));
            $sub->setAttribute('code', $code);
            $field->appendChild($sub);
        }
        return $field;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function parseAuthors(string $concatenated): array
    {
        if ($concatenated === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode('; ', $concatenated))));
    }

    private function langCode(string $italianName): string
    {
        return match (strtolower(trim($italianName))) {
            'italiano', 'italian'      => 'ita',
            'inglese', 'english'       => 'eng',
            'tedesco', 'german'        => 'ger',
            'francese', 'french'       => 'fre',
            'spagnolo', 'spanish'      => 'spa',
            'portoghese', 'portuguese' => 'por',
            'russo', 'russian'         => 'rus',
            'cinese', 'chinese'        => 'chi',
            'giapponese', 'japanese'   => 'jpn',
            'arabo', 'arabic'          => 'ara',
            default                    => 'und',
        };
    }
}
