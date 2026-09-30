<?php
declare(strict_types=1);
namespace App\Plugins\Emeroteca\Support;
require_once __DIR__ . '/CitationFormatter.php';

/** MARC 21 analytic record; a host description never implies owned serial holdings. */
final class ArticleMarcXml
{
    /**
     * ISO 639-1 → MARC Code List for Languages (the ISO 639-2/B-equivalent codes).
     */
    private const LANGUAGE_639_1 = [
        'aa'=>'aar','ab'=>'abk','af'=>'afr','ak'=>'aka','am'=>'amh','an'=>'arg','ar'=>'ara','as'=>'asm','av'=>'ava',
        'ay'=>'aym','az'=>'aze','ba'=>'bak','be'=>'bel','bg'=>'bul','bi'=>'bis','bm'=>'bam','bn'=>'ben','bo'=>'tib',
        'br'=>'bre','bs'=>'bos','ca'=>'cat','ce'=>'che','ch'=>'cha','co'=>'cos','cr'=>'cre','cs'=>'cze','cu'=>'chu',
        'cv'=>'chv','cy'=>'wel','da'=>'dan','de'=>'ger','dv'=>'div','dz'=>'dzo','ee'=>'ewe','el'=>'gre','en'=>'eng',
        'eo'=>'epo','es'=>'spa','et'=>'est','eu'=>'baq','fa'=>'per','ff'=>'ful','fi'=>'fin','fj'=>'fij','fo'=>'fao',
        'fr'=>'fre','fy'=>'fry','ga'=>'gle','gd'=>'gla','gl'=>'glg','gn'=>'grn','gu'=>'guj','gv'=>'glv','ha'=>'hau',
        'he'=>'heb','hi'=>'hin','ho'=>'hmo','hr'=>'hrv','ht'=>'hat','hu'=>'hun','hy'=>'arm','hz'=>'her','ia'=>'ina',
        'id'=>'ind','ie'=>'ile','ig'=>'ibo','ii'=>'iii','ik'=>'ipk','io'=>'ido','is'=>'ice','it'=>'ita','iu'=>'iku',
        'ja'=>'jpn','jv'=>'jav','ka'=>'geo','kg'=>'kon','ki'=>'kik','kj'=>'kua','kk'=>'kaz','kl'=>'kal','km'=>'khm',
        'kn'=>'kan','ko'=>'kor','kr'=>'kau','ks'=>'kas','ku'=>'kur','kv'=>'kom','kw'=>'cor','ky'=>'kir','la'=>'lat',
        'lb'=>'ltz','lg'=>'lug','li'=>'lim','ln'=>'lin','lo'=>'lao','lt'=>'lit','lu'=>'lub','lv'=>'lav','mg'=>'mlg',
        'mh'=>'mah','mi'=>'mao','mk'=>'mac','ml'=>'mal','mn'=>'mon','mr'=>'mar','ms'=>'may','mt'=>'mlt','my'=>'bur',
        'na'=>'nau','nb'=>'nob','nd'=>'nde','ne'=>'nep','ng'=>'ndo','nl'=>'dut','nn'=>'nno','no'=>'nor','nr'=>'nbl',
        'nv'=>'nav','ny'=>'nya','oc'=>'oci','oj'=>'oji','om'=>'orm','or'=>'ori','os'=>'oss','pa'=>'pan','pi'=>'pli',
        'pl'=>'pol','ps'=>'pus','pt'=>'por','qu'=>'que','rm'=>'roh','rn'=>'run','ro'=>'rum','ru'=>'rus','rw'=>'kin',
        'sa'=>'san','sc'=>'srd','sd'=>'snd','se'=>'sme','sg'=>'sag','si'=>'sin','sk'=>'slo','sl'=>'slv','sm'=>'smo',
        'sn'=>'sna','so'=>'som','sq'=>'alb','sr'=>'srp','ss'=>'ssw','st'=>'sot','su'=>'sun','sv'=>'swe','sw'=>'swa',
        'ta'=>'tam','te'=>'tel','tg'=>'tgk','th'=>'tha','ti'=>'tir','tk'=>'tuk','tl'=>'tgl','tn'=>'tsn','to'=>'ton',
        'tr'=>'tur','ts'=>'tso','tt'=>'tat','tw'=>'twi','ty'=>'tah','ug'=>'uig','uk'=>'ukr','ur'=>'urd','uz'=>'uzb',
        've'=>'ven','vi'=>'vie','vo'=>'vol','wa'=>'wln','wo'=>'wol','xh'=>'xho','yi'=>'yid','yo'=>'yor','za'=>'zha',
        'zh'=>'chi','zu'=>'zul',
    ];

    /** ISO 639-2/T codes whose MARC (bibliographic) code differs. */
    private const LANGUAGE_639_2T = [
        'bod'=>'tib','ces'=>'cze','cym'=>'wel','deu'=>'ger','ell'=>'gre','eus'=>'baq','fas'=>'per','fra'=>'fre',
        'hye'=>'arm','isl'=>'ice','kat'=>'geo','mkd'=>'mac','mri'=>'mao','msa'=>'may','mya'=>'bur','nld'=>'dut',
        'ron'=>'rum','slk'=>'slo','sqi'=>'alb','zho'=>'chi',
    ];

    /**
     * The MARC language code for a stored ISO 639 code, or '' when there is
     * none. The stored value is left alone on purpose: it is also rendered by
     * ICU and published as schema.org inLanguage (BCP 47), where the MARC /B
     * forms such as `ger` would be wrong — so the mapping happens here only.
     */
    public static function marcLanguage(string $code): string
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z]{2}$/D', $code) === 1) {
            return self::LANGUAGE_639_1[$code] ?? '';
        }
        if (preg_match('/^[a-z]{3}$/D', $code) === 1) {
            return self::LANGUAGE_639_2T[$code] ?? $code;
        }
        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @param string $hostControlNumber 773 $w — only a control number an exported host record really carries
     * @param bool $includeInternal true for the admin export only: adds the shelf mark (852 $c)
     */
    public static function format(array $row, string $recordUrl = '', string $hostControlNumber = '', bool $includeInternal = false): string
    {
        $parts = CitationFormatter::parts($row);
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNs(null, 'record', 'http://www.loc.gov/MARC21/slim');
        // LDR/06 language material, /07 monographic component (a single article).
        $xml->writeElement('leader', '00000naa a22000007  4500');
        $xml->startElement('controlfield');
        $xml->writeAttribute('tag', '001');
        $xml->text('article:' . (string)($row['reference_key'] ?? $row['id'] ?? ''));
        $xml->endElement();
        $xml->startElement('controlfield');
        $xml->writeAttribute('tag', '008');
        $xml->text(self::fixedField($row, $parts['year'], self::marcLanguage($parts['language'])));
        $xml->endElement();

        /** @var list<array{tag:string,ind1:string,ind2:string,values:array<string,mixed>}> $fields */
        $fields = [];
        // $required: subfield codes that must survive filtering, or a list of
        // codes of which at least one must — otherwise the whole datafield is
        // dropped, so no field is ever emitted without its defining subfield.
        $field = static function(string $tag, array $values, string $ind1 = ' ', string $ind2 = ' ', array $required = []) use (&$fields): void {
            $values = array_filter($values, static fn($value) => is_array($value) ? $value !== [] : ($value !== null && trim((string)$value) !== ''));
            if ($values === []) { return; }
            foreach ($required as $code) {
                $any = is_array($code) ? $code : [$code];
                if (array_intersect(array_map('strval', $any), array_map('strval', array_keys($values))) === []) { return; }
            }
            $fields[] = ['tag'=>$tag, 'ind1'=>$ind1, 'ind2'=>$ind2, 'values'=>$values];
        };

        $credits = $row['author_credits'] ?? [];
        if ($credits === []) {
            $credits = array_map(static fn($name) => ['nome_credito'=>$name], $parts['authors']);
        }
        $primary = null;
        foreach ($credits as $i => $credit) {
            if (($credit['ruolo'] ?? '') === 'principale') { $primary = $i; break; }
        }
        $primary ??= $credits === [] ? null : array_key_first($credits);
        foreach ($credits as $i => $credit) {
            // nome_credito is the citation form ("Surname, Forename") for
            // linked credits and the verbatim text for free-text ones: a comma
            // means an inverted personal name (ind1 1), no comma a forename or
            // single-token name (ind1 0).
            $author = trim((string)($credit['nome_credito'] ?? ''));
            $field($i === $primary ? '100' : '700', ['a'=>$author, '0'=>$credit['identifiers'] ?? []], str_contains($author, ',') ? '1' : '0', ' ', ['a']);
        }
        $field('245', ['a'=>$row['titolo'] ?? '', 'b'=>$row['sottotitolo'] ?? '', 'c'=>$row['autori'] ?? ''], $parts['authors'] === [] ? '0' : '1', '0');
        $field('300', ['a'=>$row['pagine'] ?? '']);
        $enumeration = array_filter([
            $parts['volume'] !== '' ? 'Vol. '.$parts['volume'] : '',
            $parts['issue'] !== '' ? 'No. '.$parts['issue'] : '',
            !empty($row['data_pubblicazione_testo']) ? $row['data_pubblicazione_testo'] : $parts['year'],
            !empty($row['pagine']) ? 'pp. '.$row['pagine'] : '',
        ], static fn($v) => $v !== '');
        // $w only with a control number that an exported host record carries;
        // never $w alone, which would describe no host at all.
        // A chapter's host is a book (#412): its imprint goes to 773 $d and its
        // ISBN to $z, where a periodical carries its ISSN in $x.
        $imprint = '';
        if ($parts['isAnthology']) {
            $imprint = trim(($parts['place'] !== '' && $parts['publisher'] !== '' ? $parts['place'].' : '.$parts['publisher'] : $parts['place'].$parts['publisher'])
                .($parts['year'] !== '' ? ', '.$parts['year'] : ''), ', ');
        }
        $field('773', ['t'=>$parts['container'], 'd'=>$imprint, 'x'=>$parts['isAnthology'] ? '' : $parts['issn'],
            'z'=>$parts['isAnthology'] ? $parts['isbn'] : '', 'g'=>implode(', ', $enumeration), 'w'=>$hostControlNumber], '0', ' ', [['t', 'x', 'z', 'g']]);
        $language = self::marcLanguage($parts['language']);
        $field('041', ['a'=>$language], '0');
        // ISO 3166 alpha-2 belongs in 044 $c; 008/15-17 needs MARC country
        // codes, which are a different list, so it stays unspecified.
        $country = strtoupper(trim((string)($row['paese'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/D', $country) === 1) {
            $field('044', ['c'=>$country]);
        }
        self::classification($field, trim((string)($row['classificazione'] ?? '')), (string)($row['classificazione_schema'] ?? ''));
        $field('520', ['a'=>$parts['abstract']]);
        foreach ($parts['keywords'] as $keyword) { $field('653', ['a'=>$keyword]); }
        if ($parts['doi'] !== '') { $field('024', ['a'=>$parts['doi'], '2'=>'doi'], '7'); }
        // Holdings: the note (copy / offprint only) is public data; the shelf
        // mark is not, and appears only in the admin export.
        $field('852', ['c'=>$includeInternal ? ($row['collocazione'] ?? '') : '', 'z'=>$row['nota_possesso'] ?? '']);
        // The catalogue's own page is a related resource (ind2 2), not the article.
        $field('856', ['u'=>$recordUrl, 'y'=>$recordUrl !== '' ? 'Catalogue record' : ''], '4', '2', ['u']);
        // The electronic article itself (ind2 0) only when published AND an
        // http(s) address: a UNC share, a file: URI or a document-management
        // identifier is an internal reference and is never exported.
        $resourceUrl = trim((string)($row['risorsa_url'] ?? ''));
        if (!empty($row['risorsa_pubblica']) && preg_match('~^https?://~i', $resourceUrl) === 1) {
            $field('856', ['u'=>$resourceUrl, 'y'=>$row['risorsa_testo'] ?? '', 'z'=>$row['risorsa_accesso'] ?? ''], '4', '0', ['u']);
        }

        // Variable fields in ascending tag order; the sort is stable, so
        // repeated tags keep their order (100 before the 700s, 653s as written).
        $order = array_keys($fields);
        usort($order, static fn(int $a, int $b): int => [$fields[$a]['tag'], $a] <=> [$fields[$b]['tag'], $b]);
        foreach ($order as $index) {
            $datafield = $fields[$index];
            $xml->startElement('datafield');
            $xml->writeAttribute('tag', $datafield['tag']);
            $xml->writeAttribute('ind1', $datafield['ind1']);
            $xml->writeAttribute('ind2', $datafield['ind2']);
            foreach ($datafield['values'] as $code => $value) {
                foreach (is_array($value) ? $value : [$value] as $part) {
                    $xml->startElement('subfield');
                    $xml->writeAttribute('code', (string)$code);
                    $xml->text((string)$part);
                    $xml->endElement();
                }
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        return $xml->outputMemory();
    }

    /**
     * 008, exactly forty positions. 00-05 is the date entered on file (the
     * record's created_at, else today); 06-14 a single known year, or
     * "no dates" (n + uuuuuuuu) when none is known; 35-37 the MARC language.
     * Everything else, 15-17 (place) included, stays unspecified.
     */
    private static function fixedField(array $row, string $year, string $language): string
    {
        $entered = '';
        $created = trim((string)($row['created_at'] ?? ''));
        if ($created !== '') {
            try {
                $entered = (new \DateTimeImmutable($created))->format('ymd');
            } catch (\Throwable) {
                $entered = '';
            }
        }
        if ($entered === '') {
            $entered = date('ymd');
        }
        $fixed = $entered . str_repeat('|', 34);
        $fixed = preg_match('/^\d{4}$/D', $year) === 1
            ? substr_replace($fixed, 's' . $year . '    ', 6, 9)
            : substr_replace($fixed, 'nuuuuuuuu', 6, 9);
        if ($language !== '') {
            $fixed = substr_replace($fixed, $language, 35, 3);
        }
        return $fixed;
    }

    /**
     * LCC, DDC and UDC have their own MARC 21 fields (050, 082, 080); 084
     * with $2 is only for the schemes that do not (DK5, RVK, ...).
     *
     * @param callable(string,array<int|string,mixed>,string=,string=,array<mixed>=):void $field
     */
    private static function classification(callable $field, string $notation, string $scheme): void
    {
        if ($notation === '') { return; }
        $scheme = strtoupper(trim($scheme));
        if (preg_match('/^(?:DDC|DEWEY|CDD)[\s-]*\d*$/D', $scheme) === 1) {
            $field('082', ['a'=>$notation], '0', '4', ['a']);
        } elseif (preg_match('/^(?:UDC|CDU|UDK)$/D', $scheme) === 1) {
            $field('080', ['a'=>$notation], ' ', ' ', ['a']);
        } elseif (preg_match('/^(?:LCC|LC)$/D', $scheme) === 1) {
            $field('050', ['a'=>$notation], ' ', '4', ['a']);
        } else {
            // An unnamed scheme keeps its notation in 084 $a without $2
            // rather than losing it from the export.
            $field('084', ['a'=>$notation, '2'=>strtolower($scheme)], ' ', ' ', ['a']);
        }
    }
}
