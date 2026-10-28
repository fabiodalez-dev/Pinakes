<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * A Pinakes book (its `libri` row, its authors with their roles, its
 * publishers and genre) as a MARC record: MARC 21 or UNIMARC. One source for
 * the OAI-PMH server (MARCXML, UNIMARC/MARCXchange, the ISO 2709 download)
 * and for the bulk MARC export, so they can never disagree.
 *
 * With $extended the record also carries what an exchange between catalogues
 * needs and the OAI dissemination never sent: the EAN (MARC 21 024), the LC
 * classification (050), the Dewey number (UNIMARC 676) and the cover image
 * (856). Without it the output is exactly what the OAI server has always
 * disseminated.
 *
 * Expected shapes: $row is a `libri` row; $authors are rows with `nome` and
 * `ruolo` (libri_autori.ruolo) in credit order; $publishers rows with
 * `nome`; $genre a row with `nome`, or null.
 */
final class BookMarcBuilder
{
    /**
     * UNIMARC leader template (record length and base address are filled by
     * the ISO 2709 serializer): n a m, pos 8-9 blank (pos 9 undefined in
     * UNIMARC), indicator/subfield counts 2 2, pos 17-19 blank (full level,
     * pos 18 blank because ISBD punctuation is not emitted), entry map 4500.
     */
    public const UNIMARC_LEADER_TEMPLATE = '00000nam  2200000   4500';

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $authors
     * @param list<array<string, mixed>> $publishers
     * @param array<string, mixed>|null  $genre
     */
    public static function marc21(array $row, array $authors, array $publishers, ?array $genre, bool $extended = false): MarcRecord
    {
        $isSerial = self::isSerialRecord($row);
        $tipoMedia = isset($row['tipo_media']) ? (string) $row['tipo_media'] : null;
        // Leader: type of record from the media type (a language material,
        // i/j sound recording, g projected medium), bibliographic level 'm'
        // (monograph), 's' for a serial. Leader/18 stays blank: the record
        // carries no ISBD punctuation, so 'i' (ISBD) would be a false claim.
        $record = new MarcRecord('00000n' . MarcMediaType::leaderType($tipoMedia) . ($isSerial ? 's' : 'm') . ' a2200000   4500');

        // 001 — Control number (book id); 003 — MARC organization code
        $record->addControlField('001', (string) $row['id']);
        $record->addControlField('003', 'IT-Pinakes');
        // 005 — Date/time of latest transaction
        $ts = strtotime((string) ($row['updated_at'] ?? 'now')) ?: time();
        $record->addControlField('005', gmdate('YmdHis', $ts) . '.0');
        // 007 — Physical description: the category of material the media
        // type says (text, sound disc, videodisc); none for "altro".
        $f007 = MarcMediaType::field007($tipoMedia);
        if ($f007 !== null) {
            $record->addControlField('007', $f007);
        }
        // 008 — Fixed-length data elements (exactly 40 characters)
        $record->addControlField('008', self::marc21Field008($row));

        // 020 — ISBN
        if (!empty($row['isbn13'])) {
            $record->addDataField('020', ' ', ' ', [['a', (string) $row['isbn13']]]);
        }
        if (!empty($row['isbn10'])) {
            $record->addDataField('020', ' ', ' ', [['a', (string) $row['isbn10']]]);
        }

        // 022 — ISSN of the resource itself: serials only. On a monograph the
        // ISSN belongs to its series and is carried in 490 $x below.
        if (!empty($row['issn']) && $isSerial) {
            $record->addDataField('022', ' ', ' ', [['a', (string) $row['issn']]]);
        }

        // 024 — EAN (extended), when it is not the ISBN-13 again
        if ($extended) {
            $ean = trim((string) ($row['ean'] ?? ''));
            if ($ean !== '' && preg_replace('/\D/', '', $ean) !== preg_replace('/\D/', '', (string) ($row['isbn13'] ?? ''))) {
                $record->addDataField('024', '3', ' ', [['a', $ean]]);
            }
        }

        // 040 — Cataloging source
        $record->addDataField('040', ' ', ' ', [
            ['a', 'IT-Pinakes'],
            ['b', 'ita'],
            ['e', 'rda'],
            ['c', 'IT-Pinakes'],
        ]);

        // 041 — Languages: one $a per language ("inglese, italiano" is two)
        if (!empty($row['lingua'])) {
            $codes = [];
            foreach (MarcLanguage::listOf((string) $row['lingua']) as $language) {
                $code = MarcLanguage::codeFor($language);
                if ($code !== null && !in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
            if ($codes === []) {
                $codes = ['und'];
            }
            $record->addDataField('041', '0', ' ', array_map(static fn (string $c): array => ['a', $c], $codes));
        }

        // 050 — LC classification (extended)
        if ($extended && trim((string) ($row['lc_classification'] ?? '')) !== '') {
            $record->addDataField('050', ' ', '4', [['a', trim((string) $row['lc_classification'])]]);
        }

        // 082 — Dewey classification
        if (!empty($row['classificazione_dewey'])) {
            $record->addDataField('082', '0', '4', [
                ['a', (string) $row['classificazione_dewey']],
                ['2', '23'],
            ]);
        }

        // 100/700 — creators and role-aware contributors.
        $primaryCreatorIndex = self::primaryCreatorIndex($authors);
        foreach ($authors as $index => $a) {
            $name = (string) $a['nome'];
            $relator = match ((string) ($a['ruolo'] ?? '')) {
                'traduttore' => 'translator',
                'illustratore' => 'illustrator',
                'curatore' => 'editor',
                'colorista' => 'colorist',
                default => 'author',
            };
            $record->addDataField($index === $primaryCreatorIndex ? '100' : '700', '1', ' ', [
                ['a', $name],
                ['e', $relator],
            ]);
        }

        // 245 — Title statement
        $titleSubs = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $titleSubs[] = ['b', (string) $row['sottotitolo']];
        }
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        if (!empty($row['traduttore']) && !in_array('traduttore', $entityRoles, true)) {
            $titleSubs[] = ['c', 'traduzione di ' . (string) $row['traduttore']];
        }
        $record->addDataField('245', $primaryCreatorIndex !== null ? '1' : '0', '0', $titleSubs);

        // 250 — Edition
        if (!empty($row['edizione'])) {
            $record->addDataField('250', ' ', ' ', [['a', (string) $row['edizione']]]);
        }

        // 264 — Production/Publication (RDA). MARC21 $b (publisher name) is
        // repeatable, so multiple publishers become repeated $b in one 264.
        $pubSubs = [];
        if (!empty($row['luogo_pubblicazione'])) {
            $pubSubs[] = ['a', (string) $row['luogo_pubblicazione']];
        }
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $pubSubs[] = ['b', (string) $pub['nome']];
            }
        }
        if (!empty($row['anno_pubblicazione'])) {
            $pubSubs[] = ['c', (string) $row['anno_pubblicazione']];
        }
        if ($pubSubs !== []) {
            $record->addDataField('264', ' ', '1', $pubSubs);
        }

        // 300 — Physical description
        $physSubs = [];
        if (!empty($row['numero_pagine'])) {
            $physSubs[] = ['a', (string) $row['numero_pagine'] . ' pages'];
        }
        if (!empty($row['dimensioni'])) {
            $physSubs[] = ['c', (string) $row['dimensioni']];
        }
        if ($physSubs !== []) {
            $record->addDataField('300', ' ', ' ', $physSubs);
        }

        // 336 / 337 / 338 — RDA content, media and carrier types (040 $e
        // says rda): what the media type implies, or the book's own codes.
        $rda = MarcMediaType::rdaCodes($row);
        foreach (['336' => ['content', 'rdacontent'], '337' => ['media', 'rdamedia'], '338' => ['carrier', 'rdacarrier']] as $tag => [$kind, $source]) {
            $subs = [];
            $term = MarcMediaType::term($kind, $rda[$kind]);
            if ($term !== null) {
                $subs[] = ['a', $term];
            }
            $subs[] = ['b', $rda[$kind]];
            $subs[] = ['2', $source];
            $record->addDataField((string) $tag, ' ', ' ', $subs);
        }

        // 490 — Series statement
        if (!empty($row['collana'])) {
            $serSubs = [['a', (string) $row['collana']]];
            if (!empty($row['issn']) && !$isSerial) {
                $serSubs[] = ['x', (string) $row['issn']];
            }
            if (!empty($row['numero_serie'])) {
                $serSubs[] = ['v', (string) $row['numero_serie']];
            }
            $record->addDataField('490', '0', ' ', $serSubs);
        }

        // 520 — Summary
        $desc = self::plainDescription($row);
        if ($desc !== null) {
            $record->addDataField('520', ' ', ' ', [['a', $desc]]);
        }

        // 650 — Subject: the keywords (source not specified)
        foreach (self::keywords($row) as $kw) {
            $record->addDataField('650', ' ', '4', [['a', $kw]]);
        }
        // 655 — Genre, as the catalogue's own term (ind2 7, $2 local), so an
        // import tells it from a keyword.
        if ($genre !== null && !empty($genre['nome'])) {
            $record->addDataField('655', ' ', '7', [['a', (string) $genre['nome']], ['2', 'local']]);
        }

        // 700 — legacy contributors not already represented by entities.
        foreach (['traduttore' => 'translator', 'illustratore' => 'illustrator', 'curatore' => 'editor'] as $col => $role) {
            if (!empty($row[$col]) && !in_array($col, $entityRoles, true)) {
                $record->addDataField('700', '1', ' ', [
                    ['a', (string) $row[$col]],
                    ['e', $role],
                ]);
            }
        }

        // 856 — cover image (extended)
        if ($extended) {
            $cover = self::coverUrl($row);
            if ($cover !== '') {
                $record->addDataField('856', '4', '2', [['3', 'Cover image'], ['u', $cover]]);
            }
        }

        return $record;
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $authors
     * @param list<array<string, mixed>> $publishers
     * @param array<string, mixed>|null  $genre
     */
    public static function unimarc(array $row, array $authors, array $publishers, ?array $genre, bool $extended = false): MarcRecord
    {
        // Label/06 (type of record) from the media type, as in MARC 21.
        $record = new MarcRecord(substr_replace(self::UNIMARC_LEADER_TEMPLATE, MarcMediaType::leaderType(isset($row['tipo_media']) ? (string) $row['tipo_media'] : null), 6, 1));
        $record->setFormat('UNIMARC');

        // 001 — Record identifier
        $record->addControlField('001', (string) $row['id']);
        // 003 — Persistent record identifier source (repository base URL)
        $record->addControlField('003', function_exists('absoluteUrl') ? \absoluteUrl('/') : '/');
        // 005 — Version identifier: date/time of the latest modification
        $updated = strtotime((string) ($row['updated_at'] ?? '')) ?: time();
        $record->addControlField('005', gmdate('YmdHis', $updated) . '.0');

        // 010 — ISBN only. 073 — EAN (an EAN that is just the ISBN-13 again
        // is not repeated).
        foreach (['isbn13', 'isbn10'] as $col) {
            if (!empty($row[$col])) {
                $record->addDataField('010', ' ', ' ', [['a', (string) $row[$col]]]);
                break;
            }
        }
        $ean = trim((string) ($row['ean'] ?? ''));
        $isbn13Digits = preg_replace('/\D/', '', (string) ($row['isbn13'] ?? ''));
        if ($ean !== '' && preg_replace('/\D/', '', $ean) !== $isbn13Digits) {
            $record->addDataField('073', ' ', ' ', [['a', $ean]]);
        }

        // 100 — General processing data
        $langCode = MarcLanguage::marcCodeOf((string) ($row['lingua'] ?? 'italiano'));
        $record->addDataField('100', ' ', ' ', [['a', self::unimarcField100a($row)]]);

        // 101 — Language of the item; 102 — Country of publication
        $record->addDataField('101', '0', ' ', [['a', $langCode]]);
        $record->addDataField('102', ' ', ' ', [['a', 'IT']]);

        // 200 — Title and statement of responsibility
        $subs200 = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $subs200[] = ['e', (string) $row['sottotitolo']];
        }
        $primaryCreatorIndex = self::primaryCreatorIndex($authors);
        if ($primaryCreatorIndex !== null) {
            $subs200[] = ['f', (string) $authors[$primaryCreatorIndex]['nome']];
        }
        $record->addDataField('200', '1', ' ', $subs200);

        // 205 — Edition statement
        if (!empty($row['edizione'])) {
            $record->addDataField('205', ' ', ' ', [['a', (string) $row['edizione']]]);
        }

        // 210 — Publication, distribution. $c (publisher name) is repeatable,
        // so multiple publishers become repeated $c in one 210.
        $subs210 = [];
        if (!empty($row['luogo_pubblicazione'])) {
            $subs210[] = ['a', (string) $row['luogo_pubblicazione']];
        }
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $subs210[] = ['c', (string) $pub['nome']];
            }
        }
        $year = (string) ($row['anno_pubblicazione'] ?? '');
        if ($year !== '') {
            $subs210[] = ['d', $year];
        }
        if ($subs210 !== []) {
            $record->addDataField('210', ' ', ' ', $subs210);
        }

        // 215 — Physical description
        $subs215 = [];
        if (!empty($row['numero_pagine'])) {
            $subs215[] = ['a', $row['numero_pagine'] . ' p.'];
        }
        if (!empty($row['dimensioni'])) {
            $subs215[] = ['d', (string) $row['dimensioni']];
        }
        if ($subs215 !== []) {
            $record->addDataField('215', ' ', ' ', $subs215);
        }

        // 225 — Series (UNIMARC equivalent of MARC21 490)
        if (!empty($row['collana'])) {
            $subs225 = [['a', (string) $row['collana']]];
            if (!empty($row['numero_serie'])) {
                $subs225[] = ['v', (string) $row['numero_serie']];
            }
            $record->addDataField('225', '0', ' ', $subs225);
        }

        // 330 — Summary / abstract
        $desc = self::plainDescription($row);
        if ($desc !== null) {
            $record->addDataField('330', ' ', ' ', [['a', $desc]]);
        }

        // 606 — Subject: genre, then keywords
        if ($genre !== null && !empty($genre['nome'])) {
            $record->addDataField('606', ' ', ' ', [['a', (string) $genre['nome']]]);
        }
        foreach (self::keywords($row) as $kw) {
            $record->addDataField('606', ' ', ' ', [['a', $kw]]);
        }

        // 676 — Dewey (extended)
        if ($extended && !empty($row['classificazione_dewey'])) {
            $record->addDataField('676', ' ', ' ', [['a', (string) $row['classificazione_dewey']]]);
        }

        // 700/701 — primary and alternative intellectual responsibility.
        // 702 — secondary responsibility (translator, illustrator, etc.).
        $responsibility = [];
        foreach ($authors as $index => $a) {
            $role = (string) ($a['ruolo'] ?? '');
            $isCreator = in_array($role, ['principale', 'co-autore'], true);
            $tag = $isCreator ? ($index === $primaryCreatorIndex ? '700' : '701') : '702';
            $relCode = match ($role) {
                'traduttore'   => '730',
                'curatore'     => '340',
                'illustratore' => '440',
                'colorista'    => '410',
                default        => '070',
            };
            [$ind2, $nameSubs] = self::unimarcPersonalName((string) $a['nome']);
            $nameSubs[] = ['4', $relCode];
            $responsibility[$tag][] = [$ind2, $nameSubs];
        }
        foreach (['700', '701', '702'] as $tag) {
            foreach ($responsibility[$tag] ?? [] as [$ind2, $nameSubs]) {
                $record->addDataField($tag, ' ', $ind2, $nameSubs);
            }
        }

        // 801 — Originating source
        $record->addDataField('801', ' ', '0', [
            ['a', 'IT'],
            ['b', 'Pinakes'],
            ['c', gmdate('Ymd')],
        ]);

        // 856 — cover image (extended)
        if ($extended) {
            $cover = self::coverUrl($row);
            if ($cover !== '') {
                $record->addDataField('856', '4', ' ', [['u', $cover], ['2', 'http'], ['z', 'Cover image']]);
            }
        }

        return $record;
    }

    /**
     * The index of the book's primary creator: the first 'principale', else
     * the first 'co-autore'; null when the book has neither.
     *
     * @param list<array<string, mixed>> $authors
     */
    public static function primaryCreatorIndex(array $authors): ?int
    {
        $coauthorIndex = null;
        foreach ($authors as $index => $author) {
            $role = (string) ($author['ruolo'] ?? '');
            if ($role === 'principale') {
                return $index;
            }
            if ($role === 'co-autore' && $coauthorIndex === null) {
                $coauthorIndex = $index;
            }
        }
        return $coauthorIndex;
    }

    /** Whether a libri row describes a serial (MARC bibliographic level 's'). */
    public static function isSerialRecord(array $row): bool
    {
        return in_array(strtolower((string) ($row['tipo_media'] ?? '')), ['periodical', 'serial', 'periodico', 'rivista'], true);
    }

    /**
     * MARC 21 008 for books/serials: exactly 40 positions.
     *   00-05 date entered (YYMMDD), 06 date type, 07-10 date 1, 11-14 date 2,
     *   15-17 place of publication, 35-37 language, 39 cataloguing source.
     *
     * @param array<string, mixed> $row
     */
    public static function marc21Field008(array $row): string
    {
        $field = str_repeat(' ', 40);
        $created = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $field = substr_replace($field, date('ymd', $created), 0, 6);

        $isSerial = self::isSerialRecord($row);
        $field = substr_replace($field, $isSerial ? 'c' : 's', 6, 1);

        $year = trim((string) ($row['anno_pubblicazione'] ?? ''));
        if ($year !== '' && ctype_digit($year) && strlen($year) <= 4) {
            $field = substr_replace($field, str_pad($year, 4, '0', STR_PAD_LEFT), 7, 4);
        }
        if ($isSerial) {
            // Continuing resource still published: date 2 = 9999.
            $field = substr_replace($field, '9999', 11, 4);
        }

        // Place of publication unknown/unspecified.
        $field = substr_replace($field, 'xx ', 15, 3);

        if (trim((string) ($row['lingua'] ?? '')) !== '') {
            $field = substr_replace($field, MarcLanguage::marcCodeOf((string) $row['lingua']), 35, 3);
        }

        // 39 — cataloguing source: 'd' (other than a national agency).
        return substr_replace($field, 'd', 39, 1);
    }

    /**
     * UNIMARC 100 $a — general processing data, exactly 36 positions:
     *   0-7 date entered (YYYYMMDD) · 8 type of publication date (d single
     *   known date, u unknown) · 9-12 date 1 · 13-16 date 2 · 17-19 target
     *   audience · 20 government publication (y not a government
     *   publication) · 21 modified record (0) · 22-24 language of
     *   cataloguing · 25 transliteration (y none) · 26-29 character set
     *   (50 = ISO 10646) · 30-33 additional character sets · 34-35 script
     *   of title (ba = Latin).
     *
     * @param array<string, mixed> $row
     */
    public static function unimarcField100a(array $row): string
    {
        $entered = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $year    = trim((string) ($row['anno_pubblicazione'] ?? ''));
        $known   = strlen($year) === 4 && ctype_digit($year);

        return date('Ymd', $entered)
            . ($known ? 'd' : 'u')
            . ($known ? $year : '    ')
            . '    '
            . '   '
            . 'y'
            . '0'
            . self::cataloguingLanguage()
            . 'y'
            . '50  '
            . '    '
            . 'ba';
    }

    /** ISO 639-2/B language of cataloguing, from the installation locale. */
    public static function cataloguingLanguage(): string
    {
        try {
            $locale = \App\Support\I18n::getInstallationLocale();
        } catch (\Throwable) {
            $locale = 'it_IT';
        }
        return match (strtolower(substr($locale, 0, 2))) {
            'en'    => 'eng',
            'de'    => 'ger',
            'fr'    => 'fre',
            'da'    => 'dan',
            default => 'ita',
        };
    }

    /**
     * UNIMARC personal name (7XX): [ind2, subfields]. A name that can be
     * inverted ("Surname, Forename", or a multi-word name whose last word is
     * taken as the surname) is entered under the surname — ind2 1, $a surname
     * $b forename; a single-word name stays in direct order — ind2 0, $a.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>}
     */
    public static function unimarcPersonalName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (str_contains($name, ',')) {
            [$surname, $forename] = array_map('trim', explode(',', $name, 2));
            if ($surname !== '' && $forename !== '') {
                return ['1', [['a', $surname], ['b', $forename]]];
            }
        }
        $words = $name === '' ? [] : explode(' ', $name);
        if (count($words) >= 2) {
            $surname = (string) array_pop($words);
            return ['1', [['a', $surname], ['b', implode(' ', $words)]]];
        }
        return ['0', [['a', $name]]];
    }

    /**
     * The summary as plain text: the stored plain projection, else the rich
     * description with its markup removed and its entities decoded ("Tom
     * &amp; Jerry" is Tom & Jerry); null when the book has none (a
     * description that is only markup still yields the empty summary it
     * always did).
     *
     * @param array<string, mixed> $row
     */
    private static function plainDescription(array $row): ?string
    {
        if (!empty($row['descrizione_plain'])) {
            return (string) $row['descrizione_plain'];
        }
        $desc = (string) ($row['descrizione'] ?? '');
        return $desc !== '' ? (string) \App\Support\DescriptionText::toPlain($desc) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function keywords(array $row): array
    {
        $out = [];
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $out[] = $kw;
                }
            }
        }
        return $out;
    }

    /**
     * The absolute URL of the book's cover (stored as a site path such as
     * /uploads/copertine/x.jpg, or already absolute).
     *
     * @param array<string, mixed> $row
     */
    public static function coverUrl(array $row): string
    {
        $url = trim((string) ($row['copertina_url'] ?? ''));
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        return function_exists('absoluteUrl') ? \absoluteUrl('/' . ltrim($url, '/')) : $url;
    }
}
