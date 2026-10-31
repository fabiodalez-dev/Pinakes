<?php
declare(strict_types=1);

/**
 * The shared MARC library (App\Support\Marc) on the files committed in
 * tests/fixtures/marc (assembled by tests/helpers/marc-fixtures.php with a
 * serializer of its own): ISO 2709 and MARCXML reading and writing, MARC-8,
 * malformed records that must not stop the batch, round trips, format
 * detection, and the MARC 21 / UNIMARC / danMARC2 mappings field by field.
 *
 * Run: php tests/marc-library.unit.php   (exit 0 iff every check passes)
 */

use App\Support\Marc\BookMarcBuilder;
use App\Support\Marc\Danmarc2LibriMapper;
use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\Iso2709Writer;
use App\Support\Marc\Marc21LibriMapper;
use App\Support\Marc\MarcMediaType;
use App\Support\Marc\Marc8Decoder;
use App\Support\Marc\MarcBookData;
use App\Support\Marc\MarcFormat;
use App\Support\Marc\MarcParseException;
use App\Support\Marc\MarcReadError;
use App\Support\Marc\MarcRecord;
use App\Support\Marc\MarcXmlReader;
use App\Support\Marc\MarcXmlWriter;
use App\Support\Marc\UnimarcLibriMapper;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
// The module under test is on, whatever features.* says in the database
// this runs against (Settings → Modules / Loans).
\App\Support\Features::override('rda_types', true);
require_once $root . '/app/helpers.php';

$fx = __DIR__ . '/fixtures/marc';
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};
/** @return list<MarcRecord|MarcReadError> */
$all = static fn (iterable $reader): array => array_values(iterator_to_array($reader, false));
$records = static fn (array $items): array => array_values(array_filter($items, static fn ($r): bool => $r instanceof MarcRecord));
$errors = static fn (array $items): array => array_values(array_filter($items, static fn ($r): bool => $r instanceof MarcReadError));

echo "ISO 2709 — MARC 21 UTF-8\n";
$utf8 = $all(Iso2709Reader::fromPath("$fx/marc21-utf8.mrc"));
$check(count($utf8) === 3 && count($records($utf8)) === 3, '01 three records read');
/** @var MarcRecord $rosa */
$rosa = $utf8[0];
$check($rosa->controlField('001') === 'ocm00001' && $rosa->controlField('003') === 'OCoLC', '02 control fields');
$check($rosa->leader()[9] === 'a' && strlen($rosa->leader()) === 24, '03 leader kept, UTF-8 declared');
$check($rosa->subfield('245', 'a') === 'Il nome della rosa :' && $rosa->first('245')?->ind2 === '4', '04 data field value and indicators');
$check(count($rosa->fieldsByTag('700')) === 3 && $rosa->subfieldValues('020', 'a') === ['9788845292613 (brossura)', '8845292614'], '05 repeated fields and subfields in order');
$check(($utf8[1])->subfield('245', 'a') === 'Ökologie der Straße /', '06 UTF-8 multibyte values intact');

echo "Round trips\n";
foreach (['marc21-utf8.mrc', 'unimarc-utf8.mrc'] as $file) {
    $original = (string) file_get_contents("$fx/$file");
    $read = $records($all(Iso2709Reader::fromPath("$fx/$file")));
    $written = implode('', array_map([Iso2709Writer::class, 'serialize'], $read));
    $check($written === $original, "07 {$file}: read → write reproduces the file byte for byte");
    $reread = $records($all(Iso2709Reader::fromString($written)));
    $check(array_map(static fn ($r) => $r->toArray(), $reread) === array_map(static fn ($r) => $r->toArray(), $read), "08 {$file}: read → write → read is identical");
    $xmlWriter = new MarcXmlWriter($file === 'unimarc-utf8.mrc' ? MarcXmlWriter::NS_MARCXCHANGE : MarcXmlWriter::NS_MARC21);
    $xmlWriter->startCollection();
    foreach ($read as $r) {
        $xmlWriter->writeRecord($r);
    }
    $xmlWriter->endCollection();
    $fromXml = $records($all(MarcXmlReader::fromString($xmlWriter->output())));
    $strip = static fn (array $a): array => ['leader' => $a['leader'], 'fields' => $a['fields']];
    $check(array_map(static fn ($r) => $strip($r->toArray()), $fromXml) === array_map(static fn ($r) => $strip($r->toArray()), $read), "09 {$file}: ISO 2709 → MARCXML → read is identical");
}
$handle = fopen('php://memory', 'w+b');
$stream = new Iso2709Writer($handle);
foreach ($records($utf8) as $r) {
    $stream->write($r);
}
rewind($handle);
$check(stream_get_contents($handle) === file_get_contents("$fx/marc21-utf8.mrc"), '10 the streaming writer writes the same bytes');

echo "MARC-8\n";
$m8 = $all(Iso2709Reader::fromPath("$fx/marc21-marc8.mrc"));
$check(count($m8) === 4, '11 four records, the failing one included');
$check($m8[0] instanceof MarcRecord && $m8[0]->subfield('100', 'a') === 'García Márquez, Gabriel' && $m8[0]->subfield('245', 'a') === 'Cien años de soledad', '12 combining diacritics move after their letter and compose (NFC)');
$check($m8[0] instanceof MarcRecord && $m8[0]->leader()[9] === 'a', '13 a decoded record says UTF-8 in leader/09');
$check($m8[1] instanceof MarcRecord && $m8[1]->subfield('100', 'a') === 'Ørsted, Hans Christian' && $m8[1]->subfield('245', 'a') === 'Français et æsop © 2001 Łódź', '14 ANSEL spacing letters, cedilla and acute');
$check($m8[2] instanceof MarcReadError && $m8[2]->position === 3 && str_contains($m8[2]->message, 'not supported'), '15 a Cyrillic escape is a per-record error');
$check($m8[3] instanceof MarcRecord && $m8[3]->subfield('245', 'a') === 'Haüser und Gärten', '16 the record after the error is read');
$check(Marc8Decoder::decode("\x88The\x89 end") === 'The end', '17 non-sorting markers are dropped');
try {
    Marc8Decoder::decode("bad \xBF byte");
    $check(false, '18 an undefined ANSEL byte throws');
} catch (MarcParseException) {
    $check(true, '18 an undefined ANSEL byte throws');
}
$forced = $records($all(Iso2709Reader::fromPath("$fx/marc21-utf8.mrc", Iso2709Reader::ENCODING_UTF8)));
$check(count($forced) === 3, '19 forced UTF-8 reads a UTF-8 file');

echo "Malformed records\n";
$bad = $all(Iso2709Reader::fromPath("$fx/malformed.mrc"));
$check(count($bad) === 5 && count($errors($bad)) === 3 && count($records($bad)) === 2, '20 five records: three errors, two read');
$check($bad[0] instanceof MarcRecord && $bad[4] instanceof MarcRecord && $bad[4]->subfield('245', 'a') === 'Il nome della rosa :', '21 the good records around the bad ones are intact');
$check($bad[1] instanceof MarcReadError && str_contains($bad[1]->message, 'past the end') && $bad[1]->position === 2, '22 a directory pointing past the record is reported at its position');
$check($bad[2] instanceof MarcReadError && $bad[3] instanceof MarcReadError && str_contains($bad[3]->message, 'multiple of 12'), '23 a short record and a broken directory are reported');
$check($bad[1] instanceof MarcReadError && $bad[1]->excerpt !== '' && mb_check_encoding($bad[1]->excerpt, 'UTF-8'), '24 the error carries a printable excerpt');

echo "Resuming at an offset\n";
$reader = Iso2709Reader::fromPath("$fx/marc21-utf8.mrc");
$first = [];
foreach ($reader as $pos => $r) {
    $first[] = $r;
    if ($pos === 2) {
        break;
    }
}
$resumed = $all(Iso2709Reader::fromPath("$fx/marc21-utf8.mrc", Iso2709Reader::ENCODING_AUTO, $reader->offset(), 2));
$check(count($resumed) === 1 && $resumed[0] instanceof MarcRecord && $resumed[0]->controlField('001') === 'ocm00003', '25 a reader resumed at offset() continues with the next record');
$positions = array_keys(iterator_to_array(Iso2709Reader::fromPath("$fx/marc21-utf8.mrc", Iso2709Reader::ENCODING_AUTO, $reader->offset(), 2)));
$check($positions === [3], '26 and numbers it as record 3');

echo "MARCXML\n";
$xml = $all(MarcXmlReader::fromPath("$fx/marc21-collection.xml"));
$check(count($xml) === 2 && $xml[1] instanceof MarcRecord && $xml[1]->subfield('245', 'a') === 'Ampersand & <angle> "quotes"', '27 a collection is streamed, entities decoded');
$sru = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><numberOfRecords>2</numberOfRecords><records>'
    . '<record><recordSchema>marcxml</recordSchema><recordData><record xmlns="http://www.loc.gov/MARC21/slim"><leader>00000nam a2200000 a 4500</leader><datafield tag="245" ind1="0" ind2="0"><subfield code="a">Uno</subfield></datafield></record></recordData></record>'
    . '<record><recordData><mx:record xmlns:mx="info:lc/xmlns/marcxchange-v2" format="danMARC2" type="Bibliographic"><mx:leader>00000n    2200000   4500</mx:leader><mx:datafield tag="245" ind1="0" ind2="0"><mx:subfield code="a">Due</mx:subfield></mx:datafield></mx:record></recordData></record>'
    . '</records></searchRetrieveResponse>';
$inSru = $records($all(MarcXmlReader::fromString($sru)));
$check(count($inSru) === 2 && $inSru[0]->subfield('245', 'a') === 'Uno' && $inSru[1]->subfield('245', 'a') === 'Due', '28 records inside an SRU envelope, envelope records skipped');
$check($inSru[1]->format() === 'danMARC2' && MarcFormat::detectFlavour($inSru[1]) === MarcFormat::DANMARC2, '29 the MARCXchange format attribute is kept and recognised');
$recordXml = '<record><leader>00000nam a2200000 a 4500</leader><datafield tag="245" ind1="0" ind2="0"><subfield code="a">' . str_repeat('x', 200) . '</subfield></datafield></record>';
$broken = $all(MarcXmlReader::fromString('<collection>' . str_repeat($recordXml, 300) . '<record><datafield tag="245"'));
$last = end($broken);
$check(count($records($broken)) >= 290 && count($errors($broken)) === 1 && $last instanceof MarcReadError && str_contains($last->message, 'not well-formed'), '30 XML that breaks off yields the records before it and one error');
$single = MarcXmlWriter::recordToXml($rosa);
$check(($records($all(MarcXmlReader::fromString($single)))[0] ?? null)?->toArray()['fields'] === $rosa->toArray()['fields'], '31 a standalone record document reads back identical');

echo "Format detection\n";
$check(MarcFormat::containerOfFile("$fx/marc21-utf8.mrc") === MarcFormat::CONTAINER_ISO2709 && MarcFormat::containerOfFile("$fx/marc21-collection.xml") === MarcFormat::CONTAINER_XML, '32 container told from the first bytes');
$check(MarcFormat::detectContainer("\xEF\xBB\xBF  <?xml") === MarcFormat::CONTAINER_XML && MarcFormat::detectContainer('titolo;autori') === null, '33 a BOM is skipped, a CSV is neither');
$uni = $records($all(Iso2709Reader::fromPath("$fx/unimarc-utf8.mrc")));
$check(MarcFormat::detectFlavour($rosa) === MarcFormat::MARC21 && MarcFormat::detectFlavour($uni[0]) === MarcFormat::UNIMARC, '34 MARC 21 by its 245, UNIMARC by its 200');
$dan = new MarcRecord();
$dan->addDataField('001', '0', '0', [['a', '12345678'], ['b', '870970']]);
$check(MarcFormat::detectFlavour($dan) === MarcFormat::DANMARC2, '35 a 001 data field means danMARC2');
$dan001 = Iso2709Reader::parse(substr(Iso2709Writer::serialize($dan), 0, -1));
$check($dan001->first('001') !== null && !$dan001->first('001')->isControl(), '36 an ISO 2709 001 with subfields reads as a data field');

echo "MARC 21 mapping\n";
$m = (new Marc21LibriMapper())->map($rosa);
$check($m['titolo'] === 'Il nome della rosa' && $m['sottotitolo'] === 'romanzo', '37 245 $a $b without ISBD punctuation');
$check($m['autori'] === ['Eco, Umberto', 'Rossi, Mario'], '38 100 and a 700 joint author are the authors');
$check($m['curatore'] === ['Ferré, Lùcia'] && $m['illustratore'] === ['Disegni, Ugo'], '39 700 roles from $4 (edt) and from the 245 $c statement (illustrazioni di)');
$check($m['isbn13'] === '9788845292613' && $m['isbn10'] === '8845292614' && $m['ean'] === '4006381333931', '40 020 qualifier dropped; 024 EAN');
$check($m['lingua'] === 'Italiano' && $m['classificazione_dewey'] === '853.914' && $m['lc_classification'] === 'PQ4865.C6 N6 1980', '41 041 language, 082 Dewey, 050 LC');
$check($m['edizione'] !== '' && $m['luogo_pubblicazione'] === 'Milano' && $m['editori'] === ['Bompiani'] && $m['anno_pubblicazione'] === '1980', '42 250, 264 place, publisher, year in brackets');
$check($m['numero_pagine'] === '503' && $m['dimensioni'] === '21 cm', '43 300 pages and dimensions');
$check($m['collana'] === 'Tascabili Bompiani' && $m['numero_serie'] === '12', '44 490 series and number');
$check(str_starts_with($m['descrizione'], 'Un monaco') && $m['parole_chiave'] === ['Monasteri', 'medioevo'], '45 520 summary, 650/653 keywords');
$check($m['copertina_url'] === 'https://covers.example.org/rosa.jpg', '46 856 cover');
$m2 = (new Marc21LibriMapper())->map($utf8[1]);
$check($m2['isbn13'] === '9783161484100' && $m2['editori'] === ['Verlag Grün', 'Edition Blau'] && $m2['anno_pubblicazione'] === '2015', '47 260 with two publishers, c2015, hyphenated ISBN');
$check($m2['traduttore'] === ['Øster, Zoë'] && $m2['autori'] === ['Müller, Jürgen'], '48 a translator named by the statement (übersetzt von) is not an author');
$check($m2['numero_pagine'] === '288' && $m2['collana'] === 'Studien zur Stadt' && $m2['numero_serie'] === '7' && $m2['lingua'] === 'Deutsch', '49 "288 S.", 830 series when no 490, 008 language');
$m3 = (new Marc21LibriMapper())->map($utf8[2]);
$check($m3['titolo'] === 'Untitled anthology' && $m3['autori'] === [] && $m3['anno_pubblicazione'] === '1999', '50 a bare record: 008 year');
$crime = (new Marc21LibriMapper())->map($xml[0]);
$check($crime['autori'] === ['Dostoyevsky, Fyodor'] && $crime['traduttore'] === ['McDuff, David'] && $crime['copertina_url'] === 'https://covers.example.org/crime.jpeg', '51 "translated with an introduction" → translator; 856 $q image/jpeg');

$deweyOf = static function (string $value): string {
    $r = new MarcRecord();
    $r->addDataField('082', '0', '4', [['a', $value], ['2', '23']]);
    return (new Marc21LibriMapper())->map($r)['classificazione_dewey'];
};
$check($deweyOf('891.73/3') === '891.733' && $deweyOf('813/.54') === '813.54' && $deweyOf("823'.912") === '823.912' && $deweyOf('[Fic]') === '' && $deweyOf('853.914') === '853.914', '51a 082 prime marks (/ and \') are dropped, [Fic] is no number');
$corp = new MarcRecord();
$corp->addDataField('110', '2', ' ', [['a', 'United States.'], ['b', 'Congress.'], ['b', 'Senate.']]);
$corp->addDataField('245', '1', '0', [['a', 'Report']]);
$corp->addDataField('710', '2', ' ', [['a', 'Istituto nazionale di statistica,'], ['e', 'editor.'], ['4', 'edt']]);
$corp->addDataField('711', '2', ' ', [['a', 'Convegno di studi'], ['d', '(1998 : Roma)']]);
$corp->addDataField('710', '2', ' ', [['a', 'Einaudi,'], ['e', 'publisher.'], ['4', 'pbl']]);
$corp->addDataField('710', '2', ' ', [['a', 'Some body'], ['t', 'A work']]);
$c = (new Marc21LibriMapper())->map($corp);
$check($c['autori'] === ['United States. Congress. Senate', 'Convegno di studi'], '51b 110 $a $b joined and a 711 meeting are authors; a publisher (710 $4 pbl) and a name-title entry are not');
$check($c['curatore'] === ['Istituto nazionale di statistica'], '51c a 710 with $4 edt is the editor');
$meet = new MarcRecord();
$meet->addDataField('111', '2', ' ', [['a', 'Congresso internazionale'], ['e', 'Comitato scientifico.'], ['d', '(2001 : Milano)']]);
$meet->addDataField('245', '1', '0', [['a', 'Atti']]);
$meet->addDataField('711', '2', ' ', [['a', 'Convegno di studi'], ['e', 'Sezione storica.'], ['j', 'editor.']]);
$mt = (new Marc21LibriMapper())->map($meet);
$check($mt['autori'] === ['Congresso internazionale. Comitato scientifico'] && $mt['curatore'] === ['Convegno di studi. Sezione storica'], '51d 111/711: $e is the subordinate unit (part of the name), $j the relator term');

echo "UNIMARC mapping\n";
$u = (new UnimarcLibriMapper())->map($uni[0]);
$check($u['titolo'] === 'Se questo è un uomo' && $u['sottotitolo'] === 'testimonianza' && $u['isbn13'] === '9788806219499' && $u['ean'] === '8001234567894', '52 200, 010, 073');
$check($u['autori'] === ['Primo Levi'] && $u['curatore'] === ['Marco Belpoliti'], '53 700 author; 702 $4 340 editor');
$check($u['editori'] === ['Einaudi'] && $u['luogo_pubblicazione'] === 'Torino' && $u['anno_pubblicazione'] === '2014' && $u['lingua'] === 'Italiano', '54 210 place/publisher/year, 101 language');
$check($u['numero_pagine'] === '220' && $u['dimensioni'] === '20 cm' && $u['collana'] === 'Super ET' && $u['numero_serie'] === '45', '55 215, 225');
$check($u['classificazione_dewey'] === '940.5318' && $u['parole_chiave'] === ['Olocausto'] && str_starts_with($u['descrizione'], 'Il racconto'), '56 676, 606, 330');
$check($u['copertina_url'] === 'https://covers.example.org/levi.png', '57 856 cover');
$u2 = (new UnimarcLibriMapper())->map($uni[1]);
$check($u2['isbn10'] === '2070360024' && $u2['editori'] === ['Gallimard'] && $u2['anno_pubblicazione'] === '1971' && $u2['traduttore'] === ['Matthew Ward'], '58 214 (UNIMARC 2014+), 702 $4 730 translator');

echo "danMARC2 mapping\n";
$dbc = $records($all(MarcXmlReader::fromPath(__DIR__ . '/fixtures/sru/dbc-opensearch-9788702272451.xml')));
$hp = null;
foreach ($dbc as $r) {
    $d = (new Danmarc2LibriMapper())->map($r);
    if ($d['titolo'] === 'Harry Potter og de vises sten') {
        $hp = $d;
        break;
    }
}
$check($hp !== null && $hp['autori'] === ['Rowling, Joanne K.'] && $hp['traduttore'] === ['Hanna Lützen'], '59 danMARC2 through the existing reader: author and 720 translator');

echo "Book → MARC → book\n";
$row = [
    'id' => 42, 'titolo' => 'Viaggio al centro', 'sottotitolo' => 'della terra', 'isbn13' => '9788845292613', 'isbn10' => '',
    'ean' => '4006381333931', 'lingua' => 'Italiano', 'classificazione_dewey' => '843.8', 'lc_classification' => 'PQ2469',
    'edizione' => 'Terza edizione', 'luogo_pubblicazione' => 'Roma', 'anno_pubblicazione' => 2001, 'numero_pagine' => 310,
    'dimensioni' => '20 cm', 'collana' => 'Classici', 'numero_serie' => '9', 'descrizione' => '<p>Un viaggio.</p>',
    'parole_chiave' => 'avventura, viaggi', 'copertina_url' => 'https://img.example.org/c.jpg',
    'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-02 00:00:00',
];
$authors = [['nome' => 'Jules Verne', 'ruolo' => 'principale'], ['nome' => 'Maria Traduce', 'ruolo' => 'traduttore']];
$back = (new Marc21LibriMapper())->map($records($all(Iso2709Reader::fromString(Iso2709Writer::serialize(BookMarcBuilder::marc21($row, $authors, [['nome' => 'Mursia']], null, true)))))[0]);
$check($back['titolo'] === 'Viaggio al centro' && $back['autori'] === ['Jules Verne'] && $back['traduttore'] === ['Maria Traduce'] && $back['editori'] === ['Mursia'], '60 MARC 21 export maps back: title, author, translator, publisher');
$check($back['isbn13'] === '9788845292613' && $back['ean'] === '4006381333931' && $back['lc_classification'] === 'PQ2469' && $back['copertina_url'] === 'https://img.example.org/c.jpg', '61 extended fields round trip: EAN, LC, cover');
$check($back['anno_pubblicazione'] === '2001' && $back['numero_pagine'] === '310' && $back['collana'] === 'Classici' && $back['descrizione'] === 'Un viaggio.', '62 year, pages, series, plain summary');
$backU = (new UnimarcLibriMapper())->map($records($all(Iso2709Reader::fromString(Iso2709Writer::serialize(BookMarcBuilder::unimarc($row, $authors, [['nome' => 'Mursia']], null, true)))))[0]);
$check($backU['titolo'] === 'Viaggio al centro' && $backU['autori'] === ['Jules Verne'] && $backU['traduttore'] === ['Maria Traduce'] && $backU['classificazione_dewey'] === '843.8' && $backU['copertina_url'] === 'https://img.example.org/c.jpg', '63 UNIMARC export maps back, with 676 and 856');
$plain = BookMarcBuilder::marc21($row, $authors, [], null, false);
$check($plain->first('024') === null && $plain->first('856') === null && $plain->first('050') === null, '64 without $extended the record is the OAI one (no 024/050/856)');

echo "Media type and RDA types\n";
$typed = static fn (string $tipo, array $extra = []): MarcRecord => BookMarcBuilder::marc21($row + ['tipo_media' => $tipo] + $extra, $authors, [], null);
$rdaOf = static fn (MarcRecord $r): array => [$r->subfield('336', 'b'), $r->subfield('337', 'b'), $r->subfield('338', 'b')];
$book = $typed('libro');
$check($book->leader()[6] === 'a' && $book->controlField('007') === 'ta' && $rdaOf($book) === ['txt', 'n', 'nc'], '67a a book: leader/06 a, 007 text, 336-338 txt/n/nc');
$check($book->subfield('336', 'a') === 'text' && $book->subfield('336', '2') === 'rdacontent' && $book->subfield('337', 'a') === 'unmediated' && $book->subfield('338', 'a') === 'volume' && $book->subfield('338', '2') === 'rdacarrier', '67b the RDA term in $a, the vocabulary in $2');
$dvd = $typed('dvd');
$check($dvd->leader()[6] === 'g' && str_starts_with((string) $dvd->controlField('007'), 'vd') && $rdaOf($dvd) === ['tdi', 'v', 'vd'], '67c a DVD: leader/06 g, 007 videodisc, tdi/v/vd');
$check($typed('disco')->leader()[6] === 'j' && $rdaOf($typed('disco')) === ['prm', 's', 'sd'] && $typed('audiolibro')->leader()[6] === 'i' && $rdaOf($typed('audiolibro')) === ['spw', 's', 'sd'], '67d a disco is a musical sound recording (j, prm), an audiolibro a non-musical one (i, spw)');
$check($typed('altro')->controlField('007') === null && $rdaOf($typed('altro')) === ['xxx', 'x', 'zu'], '67e altro: no 007, RDA other/other/unspecified');
$own = $typed('libro', ['rda_content_type' => 'ntm', 'rda_carrier_type' => 'nb']);
$check($rdaOf($own) === ['ntm', 'n', 'nb'] && $own->subfield('336', 'a') === 'notated music' && $own->subfield('338', 'a') === 'sheet', '67f the book\'s own RDA codes override what the media type implies');
$check(BookMarcBuilder::unimarc($row + ['tipo_media' => 'dvd'], $authors, [], null)->leader()[6] === 'g' && BookMarcBuilder::unimarc($row, $authors, [], null)->leader()[6] === 'a', '67g UNIMARC label/06 follows the media type too');
$viaIso = static fn (MarcRecord $r): array => (new Marc21LibriMapper())->map($records($all(Iso2709Reader::fromString(Iso2709Writer::serialize($r))))[0]);
$backDvd = $viaIso($dvd);
$check($backDvd['tipo_media'] === 'dvd' && !isset($backDvd['rda_content_type']) && !isset($backDvd['rda_media_type']) && !isset($backDvd['rda_carrier_type']), '67h export → import: a DVD comes back a DVD, the implied RDA codes are not stored as its own');
$check($viaIso($typed('disco'))['tipo_media'] === 'disco' && $viaIso($typed('audiolibro'))['tipo_media'] === 'audiolibro' && $viaIso($book)['tipo_media'] === 'libro', '67i disco, audiolibro and libro round trip');
$backOwn = $viaIso($own);
$check($backOwn['tipo_media'] === 'libro' && ($backOwn['rda_content_type'] ?? '') === 'ntm' && ($backOwn['rda_carrier_type'] ?? '') === 'nb' && !isset($backOwn['rda_media_type']), '67j only the codes the media type does not imply come back as the book\'s own');
$music = new MarcRecord('00000nim a2200000   4500');
$music->addDataField('245', '0', '0', [['a', 'Sonate']]);
$music->addDataField('336', ' ', ' ', [['b', 'prm'], ['2', 'rdacontent']]);
$check((new Marc21LibriMapper())->map($music)['tipo_media'] === 'disco', '67k leader/06 i with 336 prm (performed music) is a disco');
$map = new MarcRecord('00000nem a2200000   4500');
$map->addDataField('245', '0', '0', [['a', 'Atlas']]);
$map->addDataField('336', ' ', ' ', [['b', 'cri'], ['2', 'rdacontent']]);
$mapped = (new Marc21LibriMapper())->map($map);
$check($mapped['tipo_media'] === '' && ($mapped['rda_content_type'] ?? '') === 'cri', '67l a kind the catalogue has no media type for leaves it unset, its content type kept');
$check((new UnimarcLibriMapper())->map($records($all(Iso2709Reader::fromString(Iso2709Writer::serialize(BookMarcBuilder::unimarc($row + ['tipo_media' => 'audiolibro'], $authors, [], null)))))[0])['tipo_media'] === 'audiolibro', '67m UNIMARC export → import keeps the media type');
$check(MarcBookData::toImportRow($backDvd)['tipo_media'] === 'dvd', '67n the import row carries tipo_media');
$backOther = $viaIso($typed('altro'));
$check($backOther['tipo_media'] === 'altro' && !isset($backOther['rda_content_type']) && !isset($backOther['rda_media_type']) && !isset($backOther['rda_carrier_type']), '67n2 export → import: an altro (leader a, RDA xxx) comes back an altro, not a book');
$check(MarcMediaType::tipoMediaOf('a', 'txt') === 'libro' && MarcMediaType::tipoMediaOf('a', null) === 'libro', '67n3 language material without RDA "other" is still a book');

echo "Genre round trip\n";
$withGenre = BookMarcBuilder::marc21($row, $authors, [], ['id' => 3, 'nome' => 'Narrativa'], true);
$check($withGenre->first('655')?->ind2 === '7' && $withGenre->subfield('655', 'a') === 'Narrativa' && $withGenre->subfield('655', '2') === 'local' && $withGenre->subfieldValues('650', 'a') === ['avventura', 'viaggi'], '67o the genre is a 655 _7 $2 local, the keywords stay 650 _4');
$xmlOut = new MarcXmlWriter(MarcXmlWriter::NS_MARC21);
$xmlOut->startCollection();
$xmlOut->writeRecord($withGenre);
$xmlOut->endCollection();
$backGenre = (new Marc21LibriMapper())->map($records($all(MarcXmlReader::fromString($xmlOut->output())))[0]);
$check($backGenre['genere'] === 'Narrativa' && $backGenre['parole_chiave'] === ['avventura', 'viaggi'], '67p builder → MARCXML → reader → mapper: the genre comes back as the genre, not as a keyword');
$check(MarcBookData::toImportRow($backGenre)['genere'] === 'Narrativa', '67q the import row carries the genre');
$lcgft = new MarcRecord();
$lcgft->addDataField('245', '0', '0', [['a', 'A novel']]);
$lcgft->addDataField('655', ' ', '7', [['a', 'Detective and mystery fiction.'], ['2', 'lcgft']]);
$lcgft->addDataField('655', ' ', '4', [['a', 'Thrillers.']]);
$check((new Marc21LibriMapper())->map($lcgft)['genere'] === '', '67r a 655 of another scheme, or without $2, is not imported as the genre');
$withGenreU = BookMarcBuilder::unimarc($row, $authors, [], ['id' => 3, 'nome' => 'Narrativa'], true);
$check($withGenreU->subfield('606', 'a') === 'Narrativa' && $withGenreU->subfield('606', '2') === 'local', '67r2 UNIMARC writes the genre as a 606 $2 local');
$backGenreU = (new UnimarcLibriMapper())->map($records($all(Iso2709Reader::fromString(Iso2709Writer::serialize($withGenreU))))[0]);
$check($backGenreU['genere'] === 'Narrativa' && $backGenreU['parole_chiave'] === ['avventura', 'viaggi'], '67r3 UNIMARC export → import: the genre comes back as the genre, the keywords as keywords');
$uniOne = static function (array $fields): array {
    $r = new MarcRecord('00000nam  2200000   4500');
    $r->addDataField('200', '1', ' ', [['a', 'Titolo']]);
    foreach ($fields as [$tag, $ind1, $ind2, $subs]) {
        $r->addDataField($tag, $ind1, $ind2, $subs);
    }
    return (new UnimarcLibriMapper())->map($r);
};
$check($uniOne([['606', ' ', ' ', [['a', 'Storia'], ['2', 'FI']]]])['genere'] === '' && $uniOne([['606', ' ', ' ', [['a', 'Storia'], ['2', 'FI']]]])['parole_chiave'] === ['Storia'], '67r4 a 606 of another scheme stays a keyword');
$check($uniOne([['101', '0', ' ', [['a', 'swa']]]])['lingua'] === '' && $uniOne([['101', '0', ' ', [['a', 'deu']]]])['lingua'] !== '', '67r5 an unknown UNIMARC language code stays empty instead of being stored as a name');
$onlyTranslator = $uniOne([['700', ' ', '1', [['a', 'Weaver'], ['b', 'William'], ['4', '730']]]]);
$check($onlyTranslator['traduttore'] === ['William Weaver'] && $onlyTranslator['autori'] === [], '67r6 a 700 whose relator is a translator stays a translator');
$firstAuthor = $uniOne([['700', ' ', '1', [['a', 'Weaver'], ['b', 'William'], ['4', '730']]], ['701', ' ', '1', [['a', 'Eco'], ['b', 'Umberto']]]]);
$check($firstAuthor['autori'] === ['Umberto Eco'] && $firstAuthor['traduttore'] === ['William Weaver'], '67r7 the author after a translator in 700 is still read as an author');

$entities = BookMarcBuilder::marc21(['descrizione' => '<p>Tom &amp; Jerry</p><p>Vol. &laquo;2&raquo;</p>'] + $row, $authors, [], null);
$check($entities->subfield('520', 'a') === "Tom & Jerry\n\nVol. «2»", '67s the summary decodes the entities of the rich description');
$check(BookMarcBuilder::marc21(['descrizione' => '<p>x</p>', 'descrizione_plain' => 'Plain &amp; kept'] + $row, $authors, [], null)->subfield('520', 'a') === 'Plain &amp; kept', '67t the stored plain projection is taken as it is');

echo "Series ISSN, languages, extents, parts\n";
$mapOne = static function (array $fields, string $leader = MarcRecord::DEFAULT_LEADER): array {
    $r = new MarcRecord($leader);
    foreach ($fields as [$tag, $ind1, $ind2, $subs]) {
        $r->addDataField($tag, $ind1, $ind2, $subs);
    }
    return (new Marc21LibriMapper())->map($r);
};
$check($mapOne([['490', '0', ' ', [['a', 'Serie'], ['x', '0317-8471']]]])['issn'] === '0317-8471' && $mapOne([['830', ' ', '0', [['a', 'Serie'], ['x', '1234-5679']]]])['issn'] === '1234-5679', '67u the series ISSN in 490 $x / 830 $x is read when 022 is absent');
$check($mapOne([['022', ' ', ' ', [['a', '1111-2222']]], ['490', '0', ' ', [['a', 'Serie'], ['x', '0317-8471']]]])['issn'] === '1111-2222', '67v 022 wins over the series ISSN');
$bilingual = BookMarcBuilder::marc21(['lingua' => 'inglese, italiano'] + $row, $authors, [], null);
$check($bilingual->subfieldValues('041', 'a') === ['eng', 'ita'], '67w one 041 $a per language');
$check($viaIso($bilingual)['lingua'] === 'English, Italiano', '67x every 041 $a comes back, joined as the catalogue stores them');
$check($mapOne([['041', '0', ' ', [['a', 'gerfre']]]])['lingua'] === 'Deutsch, Français' && $mapOne([['041', '0', ' ', [['a', 'swa']]]])['lingua'] === '', '67y a packed 041 is split; an unknown code is no language');
$check(\App\Support\Marc\MarcLanguage::nameFor('swa') === '' && \App\Support\Marc\MarcLanguage::nameFor('ita') === 'Italiano', '67z nameFor: unknown code → empty');
$check($mapOne([['300', ' ', ' ', [['a', '2 v.']]]])['numero_pagine'] === '' && $mapOne([['300', ' ', ' ', [['a', '24 cm']]]])['numero_pagine'] === '' && $mapOne([['300', ' ', ' ', [['a', 'xii, 503 p. :']]]])['numero_pagine'] === '503', '68a "2 v." and "24 cm" are not page counts, "503 p." is');
$check($mapOne([['245', '1', '0', [['a', 'Harry Potter.'], ['n', 'Vol. 1,'], ['p', 'The philosopher\'s stone /'], ['c', 'J. K. Rowling.']]]])['titolo'] === 'Harry Potter. Vol. 1. The philosopher\'s stone', '68b 245 $n and $p are appended to the title, ISBD-stripped');
$uniPart = new MarcRecord(BookMarcBuilder::UNIMARC_LEADER_TEMPLATE);
$uniPart->setFormat('UNIMARC');
$uniPart->addDataField('200', '1', ' ', [['a', 'Storia d\'Italia'], ['h', 'Vol. 2'], ['i', 'Il Medioevo :'], ['e', 'dal V al XV secolo']]);
$check((new UnimarcLibriMapper())->map($uniPart)['titolo'] === 'Storia d\'Italia. Vol. 2. Il Medioevo', '68c UNIMARC 200 $h and $i are appended to the title');
$twoSubs = new MarcRecord();
$twoSubs->addDataField('520', ' ', ' ', [['a', 'Short summary'], ['b', str_repeat('x', 12000)], ['c', 'after']]);
$cutWarnings = [];
$cutRecord = Iso2709Reader::parse(substr(Iso2709Writer::serialize($twoSubs, $cutWarnings), 0, -1));
$check($cutRecord->first('520')?->subfields === [['a', 'Short summary']] && count($cutWarnings) === 1 && str_contains($cutWarnings[0], 'field 520'), '68d a field over the limit is cut at the last subfield boundary that fits and the cut is reported');
$noCut = [];
Iso2709Writer::serialize($rosa, $noCut);
$check($noCut === [], '68e nothing is reported for a record that fits');

echo "Import row\n";
$importRow = MarcBookData::toImportRow($m);
$check($importRow['autori'] === 'Eco, Umberto;Rossi, Mario' && $importRow['editore'] === 'Bompiani' && $importRow['curatore'] === 'Ferré, Lùcia' && !array_key_exists('traduttore', $importRow), '65 names joined with ";", an absent role is not a column');
$check($importRow['parole_chiave'] === 'Monasteri, medioevo' && $importRow['isbn13'] === '9788845292613', '66 keywords joined with ", "');

echo "Writer limits\n";
$long = new MarcRecord();
$long->addDataField('520', ' ', ' ', [['a', str_repeat('è', 6000)]]);
$serialized = Iso2709Writer::serialize($long);
$reparsed = Iso2709Reader::parse(substr($serialized, 0, -1));
$value = (string) $reparsed->subfield('520', 'a');
$check(mb_check_encoding($value, 'UTF-8') && strlen($value) <= 9999 && strlen($value) > 9000, '67 a field over 9999 bytes is cut on a character boundary');

echo "Field terminators\n";
// Rewrite one directory entry (tag, length, start) of a serialized record.
$withEntry = static function (string $raw, int $index, ?int $length, ?int $start): string {
    $at = 24 + 12 * $index;
    if ($length !== null) {
        $raw = substr_replace($raw, sprintf('%04d', $length), $at + 3, 4);
    }
    if ($start !== null) {
        $raw = substr_replace($raw, sprintf('%05d', $start), $at + 7, 5);
    }
    return $raw;
};
$ft = new MarcRecord();
$ft->addControlField('001', 'REC1');
$ft->addDataField('245', '0', '0', [['a', 'Titolo giusto']]);
$ft->addDataField('500', ' ', ' ', [['a', 'Nota']]);
$ftRaw = Iso2709Writer::serialize($ft);
$check(Iso2709Reader::parse($ftRaw)->subfield('245', 'a') === 'Titolo giusto', '19a a well-formed record parses');
$len001 = (int) substr($ftRaw, 24 + 3, 4);
// The legacy off-by-one: every length counted without its terminator.
$short = $ftRaw;
for ($i = 0; $i < 3; $i++) {
    $short = $withEntry($short, $i, (int) substr($short, 24 + 12 * $i + 3, 4) - 1, null);
}
$parsedShort = Iso2709Reader::parse($short);
$check($parsedShort->controlField('001') === 'REC1' && $parsedShort->subfield('245', 'a') === 'Titolo giusto' && $parsedShort->subfield('500', 'a') === 'Nota', '19b lengths counted without the terminator are still read');
$refusedParse = static function (string $raw): bool {
    try {
        Iso2709Reader::parse($raw);
        return false;
    } catch (MarcParseException) {
        return true;
    }
};
$check($refusedParse($withEntry($ftRaw, 0, $len001 + 6, null)), '19c an entry whose length runs into the next field is refused');
$check($refusedParse($withEntry($ftRaw, 2, null, (int) substr($ftRaw, 24 + 12 + 7, 5) + 3)), '19d an entry that starts inside another field is refused, not read as that field\'s bytes');

echo "Encoding of ISO 2709 records\n";
// A record labelled UTF-8 (leader/09 = a) whose bytes are MARC-8: the title
// "Caffe" with ANSEL 0xE2 (acute) before the last letter, as a MARC-8 catalogue writes it.
$mislabelled = new MarcRecord();
$mislabelled->addDataField('245', '0', '0', [['a', 'CaffXe']]);
$raw = substr(Iso2709Writer::serialize($mislabelled), 0, -1);
$raw[9] = 'a';
$raw = str_replace('CaffXe', "Caff\xE2e", $raw);
$threw = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (MarcParseException) {
        return true;
    }
};
$check($threw(static fn () => Iso2709Reader::parse($raw)), '68 auto: leader/09 = a with invalid UTF-8 is reported, not passed through');
$check($threw(static fn () => Iso2709Reader::parse($raw, Iso2709Reader::ENCODING_UTF8)), '69 forced UTF-8 refuses invalid UTF-8');
$asMarc8 = null;
try {
    $asMarc8 = Iso2709Reader::parse($raw, Iso2709Reader::ENCODING_MARC8);
} catch (MarcParseException) {
}
$check($asMarc8 !== null && $asMarc8->subfield('245', 'a') === 'Caffé', '70 forced MARC-8 reads a record mislabelled leader/09 = a');
$good = new MarcRecord();
$good->addDataField('245', '0', '0', [['a', 'Caffè']]);
$check(Iso2709Reader::parse(substr(Iso2709Writer::serialize($good), 0, -1))->subfield('245', 'a') === 'Caffè', '71 auto: valid UTF-8 labelled a is read as is');

echo "Export selection and oversized records\n";
$check(\App\Controllers\MarcExportController::parseIds('12abc, 7,7, 0,-3, 9 ,x') === [7, 9], '72 export ids: only all-digit tokens, positive, unique ("12abc" is not book 12)');
$huge = ['id' => 1, 'titolo' => 'T', 'descrizione' => str_repeat('parola ', 3000),
    'parole_chiave' => implode(', ', array_map(static fn (int $i): string => 'soggetto ' . str_repeat('x', 200) . " {$i}", range(1, 600)))];
$check($threwOverflow = (static function () use ($huge): bool {
    try {
        Iso2709Writer::serialize(BookMarcBuilder::marc21($huge, [], [], null, true));
        return false;
    } catch (\OverflowException) {
        return true;
    }
})(), '73 a book with huge free texts does not fit one ISO 2709 record');
$fits = true;
try {
    Iso2709Writer::serialize(BookMarcBuilder::marc21(\App\Controllers\MarcExportController::shortenTexts($huge), [], [], null, true));
} catch (\OverflowException) {
    $fits = false;
}
$check($fits, '74 with its long texts shortened the same book is exported instead of dropped');
$hugeCut = $huge + [];
$hugeCut['descrizione'] = str_repeat('lunga ', 3500); // a 520 over the 9,999-byte field limit
$cutsSeen = ['stale'];
$bytesShort = \App\Controllers\MarcExportController::serializeIso('marc21', $hugeCut, [], [], null, $cutsSeen, $levelShort);
$check($bytesShort !== null && $levelShort === 'shortened' && $cutsSeen === [], '74a the field cuts of the rejected full record are not reported for the shortened one that is written');
$crowd = array_map(static fn (int $i): array => ['nome' => 'Autore ' . str_repeat('n', 200) . " {$i}", 'ruolo' => 'principale'], range(1, 700));
$cutsCrowd = [];
$bytesCrowd = \App\Controllers\MarcExportController::serializeIso('marc21', $huge, $crowd, [], null, $cutsCrowd, $levelCrowd);
$bytesCrowdUni = \App\Controllers\MarcExportController::serializeIso('unimarc', $huge, $crowd, [], null, $cutsCrowd, $levelCrowdUni);
$check($bytesCrowd !== null && $levelCrowd === 'reduced' && Iso2709Reader::parse(substr($bytesCrowd, 0, -1))->subfield('245', 'a') === 'T'
    && $bytesCrowdUni !== null && $levelCrowdUni === 'reduced', '74b a book that overflows even shortened (700 authors) is exported as a reduced record, never skipped');

echo "Language names round trip\n";
$roundTrip = true;
foreach (['ita', 'eng', 'fre', 'ger', 'rus', 'chi', 'jpn', 'fin', 'gre', 'lat', 'ice', 'cze', 'hun', 'rum', 'tur', 'heb', 'kor', 'tha', 'grc', 'nor'] as $code) {
    $back = \App\Support\Marc\MarcLanguage::codeFor(\App\Support\Marc\MarcLanguage::nameFor($code));
    if ($back !== $code) {
        $roundTrip = false;
        echo "       {$code} -> " . var_export($back, true) . "\n";
    }
}
$check($roundTrip, '75 every stored language name maps back to its MARC code (import -> export keeps the language)');
$check(\App\Support\Marc\MarcLanguage::marcCodeOf('Русский') === 'rus' && \App\Support\Marc\MarcLanguage::codeFor('Klingon') === null, '76 a native name is found, an unknown one stays unknown');

echo "MARCXML reader and the global libxml error buffer\n";
$xmlDoc = '<?xml version="1.0" encoding="UTF-8"?><collection xmlns="http://www.loc.gov/MARC21/slim">'
    . str_repeat('<record><leader>00000nam a2200000 a 4500</leader><datafield tag="245" ind1="0" ind2="0"><subfield code="a">Uno</subfield></datafield></record>', 2)
    . '</collection>';
$items = [];
foreach (MarcXmlReader::fromString($xmlDoc) as $item) {
    $items[] = $item;
    // A consumer that parses broken XML of its own between two records.
    @(new DOMDocument())->loadXML('<broken>');
}
$check(count($items) === 2 && !array_filter($items, static fn ($i): bool => $i instanceof MarcReadError), '77 a well-formed file stays error-free when the consumer leaves libxml errors behind');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
