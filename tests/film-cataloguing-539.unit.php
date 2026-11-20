<?php
declare(strict_types=1);
/**
 * Film cataloguing (#539). A film is a record with tipo_media 'dvd' (the enum
 * value is kept; the label reads "Film e video"): its director is the
 * principal author, its studio the publisher, and five film facts live in
 * their own columns (durata_minuti, interpreti, sottotitoli,
 * classificazione_eta, codice_regione). No network or DB.
 *
 * Covers: media type aliases and film labels, FilmFields normalisation and
 * formatting, the citation input and the RIS record (TY MPCT), the five
 * citation styles for a film, and the MARC 21 / UNIMARC export; a book's
 * output is checked unchanged next to each.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
\App\Support\Features::override('rda_types', true);
require_once $root . '/app/helpers.php';

use App\Support\BookCitation;
use App\Support\CitationStyles;
use App\Support\FilmFields;
use App\Support\Marc\BookMarcBuilder;
use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\Iso2709Writer;
use App\Support\Marc\Marc21LibriMapper;
use App\Support\Marc\MarcRecord;
use App\Support\MediaLabels;

$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $count++;
    echo "OK $label\n";
};

// ── Media type ───────────────────────────────────────────────────────────
foreach (['dvd', 'DVD', 'Blu-ray', 'bluray', 'Blu-ray Disc', 'VHS', 'VHS videocassette', 'videocassetta PAL', 'videocassetta', '4K UHD', 'movie', 'Film', 'film e video'] as $alias) {
    $check(MediaLabels::resolveTipoMedia($alias, null) === 'dvd', "\"$alias\" is a film");
}
$check(MediaLabels::isFilm(null, 'dvd') && !MediaLabels::isFilm(null, 'libro') && !MediaLabels::isFilm(null, 'disco'), 'isFilm follows tipo_media');
$check(MediaLabels::isFilm('libro', 'dvd') && !MediaLabels::isFilm('DVD', 'libro'), 'an explicit tipo_media wins over the format');
foreach (['Audiobook CD' => 'audiolibro', 'audio cassette' => 'disco', 'audiocassetta' => 'disco', 'cdrom' => 'libro', 'CD' => 'disco', 'Vinile' => 'disco', 'Cartaceo' => 'libro', 'help' => 'libro', 'video game' => 'libro'] as $format => $expected) {
    $check(MediaLabels::inferTipoMedia($format) === $expected, "\"$format\" still infers $expected");
}
$check(MediaLabels::tipoMediaDisplayName('dvd') === 'Film e video', 'the dvd media type reads "Film e video"');
$check(MediaLabels::allTypes()['dvd']['schema'] === 'Movie' && MediaLabels::icon('dvd') === 'fa-film', 'schema.org Movie, film icon');
$check(MediaLabels::formatDisplayName('vhs') === 'VHS' && MediaLabels::formatDisplayName('blu-ray') === 'Blu-ray' && MediaLabels::formatDisplayName('4k') === '4K Ultra HD', 'carrier display names');

// ── Labels ───────────────────────────────────────────────────────────────
$check(MediaLabels::label('autore', null, 'dvd') === 'Regista' && MediaLabels::label('autori', null, 'dvd') === 'Regia', 'a film\'s author is its director');
$check(MediaLabels::label('editore', null, 'dvd') === 'Produzione / Distribuzione', 'a film\'s publisher is its studio or distributor');
$check(MediaLabels::label('descrizione', null, 'dvd') === 'Trama' && MediaLabels::label('ean', null, 'dvd') === 'Barcode', 'plot and barcode');
$check(MediaLabels::label('autore', null, 'libro') === 'Autore' && MediaLabels::label('editore', null, 'libro') === 'Editore', 'a book keeps its labels');
$check(MediaLabels::label('autore', null, 'disco') === 'Artista' && MediaLabels::label('descrizione', null, 'disco') === 'Tracklist', 'music keeps its labels');

// ── FilmFields ───────────────────────────────────────────────────────────
$n = FilmFields::normalize([
    'durata_minuti' => ' 95 ', 'interpreti' => "  Ana de Armas\r\nDaniel Craig\n\n\n\nRami Malek ",
    'sottotitoli' => "  italiano,\n inglese  ", 'classificazione_eta' => '', 'titolo' => 'not a film field',
]);
$check($n['durata_minuti'] === 95, 'runtime is an integer');
$check($n['interpreti'] === "Ana de Armas\nDaniel Craig\n\nRami Malek", 'the cast keeps one name per line, blank runs collapsed');
$check($n['sottotitoli'] === 'italiano, inglese', 'a one-line field is collapsed to one line');
$check(array_key_exists('classificazione_eta', $n) && $n['classificazione_eta'] === null, 'a blank field clears the value');
$check(!array_key_exists('codice_regione', $n) && !array_key_exists('titolo', $n), 'absent keys stay absent; other fields are ignored');
foreach (['0', '-5', '65536', '1.5', 'abc', '', null, []] as $bad) {
    $check(FilmFields::normalize(['durata_minuti' => $bad])['durata_minuti'] === null, 'runtime ' . json_encode($bad) . ' is rejected');
}
$check(mb_strlen((string) FilmFields::normalize(['classificazione_eta' => str_repeat('é', 80)])['classificazione_eta']) === 50, 'text is capped to the column size');
$check(FilmFields::iso8601(95) === 'PT1H35M' && FilmFields::iso8601(120) === 'PT2H' && FilmFields::iso8601(45) === 'PT45M' && FilmFields::iso8601(null) === null, 'ISO 8601 duration');
$check(FilmFields::marc306(95) === '013500' && FilmFields::marc306(6000) === null, 'MARC 306 HHMMSS');
$check(FilmFields::marc008Runtime(95) === '095' && FilmFields::marc008Runtime(1200) === '000' && FilmFields::marc008Runtime(null) === '---', 'MARC 008/18-20');
$check(FilmFields::castList("Ana de Armas; Daniel Craig,Rami Malek\nDaniel Craig") === ['Ana de Armas', 'Daniel Craig', 'Rami Malek'], 'the cast list splits and de-duplicates');
$check(FilmFields::runtimeLabel('95') === '95 min' && FilmFields::runtimeLabel('') === '', 'runtime label');

// ── Citation and RIS ─────────────────────────────────────────────────────
$film = [
    'id' => 9, 'titolo' => 'The end of Oak Street', 'sottotitolo' => '', 'anno_pubblicazione' => 2009,
    'editore' => 'Warner Bros.', 'luogo_pubblicazione' => 'Burbank', 'edizione' => '', 'isbn13' => '', 'isbn10' => '',
    'ean' => '5051889012345', 'lingua' => 'inglese', 'collana' => '', 'parole_chiave' => 'til voksne',
    'tipo_media' => 'dvd', 'formato' => 'blu-ray', 'durata_minuti' => 95,
    'interpreti' => "Ana de Armas\nDaniel Craig", 'sottotitoli' => 'danese', 'classificazione_eta' => '15',
    'codice_regione' => 'B',
];
$directors = [['nome' => 'Christopher Nolan', 'ruolo' => 'principale'], ['nome' => 'Some Translator', 'ruolo' => 'traduttore']];
$input = BookCitation::input($film, $directors);
$check($input['type'] === 'film' && $input['medium'] === 'Blu-ray', 'a film is cited as a film, with its carrier');
$check($input['authors'] === ['Nolan, Christopher'] && $input['publisher'] === 'Warner Bros.', 'director and studio');
$ris = BookCitation::ris($input, $film, 'https://example.org/film/9');
$check(str_starts_with($ris, "TY  - MPCT\r\n") && str_ends_with($ris, "ER  - \r\n"), 'RIS: TY MPCT ... ER');
foreach (["AU  - Nolan, Christopher", "TI  - The end of Oak Street", "PY  - 2009", "PB  - Warner Bros.", "M3  - Blu-ray", "SN  - 5051889012345", "KW  - til voksne", "N1  - Cast: Ana de Armas, Daniel Craig", "N1  - Running time: 95 min", "N1  - Age rating: 15", "N1  - Subtitles: danese", "UR  - https://example.org/film/9"] as $line) {
    $check(str_contains($ris, $line . "\r\n"), "RIS carries \"$line\"");
}
foreach ([
    ['5051889012345', '9788845292613', '8845292614', '5051889012345'],
    [' ', '9788845292613', '8845292614', '9788845292613'],
    [null, '', '8845292614', '8845292614'],
    ['', '', '', null],
] as [$ean, $isbn13, $isbn10, $expected]) {
    $withIds = compact('ean', 'isbn13', 'isbn10') + $film;
    $identifierLines = preg_grep('/^SN  - /', explode("\r\n", BookCitation::ris($input, $withIds)));
    $check(array_values($identifierLines) === ($expected === null ? [] : ['SN  - ' . $expected]), 'film RIS identifier fallback ' . ($expected ?? '(none)'));
}
$evil = BookCitation::ris(BookCitation::input(['interpreti' => "A\r\nER  - \r\nTY  - BOOK"] + $film, $directors), ['interpreti' => "A\r\nER  - \r\nTY  - BOOK"] + $film);
$check(substr_count($evil, "TY  - ") === 1 && substr_count($evil, "ER  - ") === 1, 'a line break in a film field cannot start a RIS tag');
$book = ['tipo_media' => 'libro', 'formato' => 'cartaceo', 'isbn13' => '9788845292613', 'interpreti' => 'x'] + $film;
$bookRis = BookCitation::ris(BookCitation::input($book, $directors), $book);
$check(str_starts_with($bookRis, "TY  - BOOK\r\n") && str_contains($bookRis, "SN  - 9788845292613\r\n") && !str_contains($bookRis, 'M3  -') && !str_contains($bookRis, 'N1  -'), 'a book is still TY BOOK, with its ISBN and no film notes');
$check(BookCitation::input($book, $directors)['type'] === 'book', 'a book is cited as a book');

$styles = [];
foreach (CitationStyles::all($input) as $style) {
    $styles[$style['key']] = $style;
}
$check($styles['apa']['text'] === 'Nolan, C. (Director). (2009). The end of Oak Street [Film]. Warner Bros.', 'APA film: ' . $styles['apa']['text']);
$check($styles['apa']['html'] === 'Nolan, C. (Director). (2009). <i>The end of Oak Street</i> [Film]. Warner Bros.', 'APA film italicises the title');
$check($styles['harvard']['text'] === 'The end of Oak Street (2009) Directed by Christopher Nolan [Blu-ray]. Burbank: Warner Bros.', 'Harvard film: ' . $styles['harvard']['text']);
$check($styles['chicago']['text'] === 'Nolan, Christopher, dir. 2009. The end of Oak Street. Burbank: Warner Bros. Blu-ray.', 'Chicago film: ' . $styles['chicago']['text']);
$check($styles['mla']['text'] === 'The end of Oak Street. Directed by Christopher Nolan, Warner Bros., 2009.', 'MLA film: ' . $styles['mla']['text']);
$check($styles['oxford']['text'] === 'Nolan, C. (dir.) The end of Oak Street [Blu-ray]. (Warner Bros., 2009).', 'Oxford film: ' . $styles['oxford']['text']);
$anon = CitationStyles::apa(['type' => 'film', 'title' => 'Untitled', 'year' => '']);
$check($anon['text'] === 'Untitled [Film]. (n.d.).', 'APA film without director or year: ' . $anon['text']);
$two = CitationStyles::chicago(['type' => 'film', 'title' => 'Avengers', 'year' => '2019', 'authors' => ['Russo, Anthony', 'Russo, Joe']]);
$check($two['text'] === 'Russo, Anthony, and Joe Russo, dirs. 2019. Avengers.', 'Chicago two directors: ' . $two['text']);
$bookApa = CitationStyles::apa(BookCitation::input($book, $directors))['text'];
$check(!str_contains($bookApa, '[Film]') && !str_contains($bookApa, '(Director)'), 'a book\'s APA has no film markers');

// ── MARC 21 ──────────────────────────────────────────────────────────────
$row = $film + ['numero_pagine' => 2, 'dimensioni' => '19 cm', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-02 00:00:00'];
$m = BookMarcBuilder::marc21($row, $directors, [['nome' => 'Warner Bros.']], null);
$f008 = (string) $m->controlField('008');
$check($m->leader()[6] === 'g' && strlen($f008) === 40, 'leader/06 g, 008 of 40 positions');
$check(substr($f008, 18, 3) === '095' && substr($f008, 33, 2) === 'v|', '008/18-20 running time, 33 videorecording');
$check($m->subfield('300', 'a') === '1 videodisc (95 min)' && $m->subfield('300', 'c') === '19 cm', '300: carrier and running time, not pages');
$check($m->subfield('306', 'a') === '013500', '306 playing time');
$check($m->subfield('100', 'a') === 'Christopher Nolan' && $m->subfield('100', 'e') === 'film director', '100 $e film director');
$check($m->subfield('700', 'e') === 'translator', 'other roles keep their relator term');
$check($m->subfield('511', 'a') === 'Ana de Armas, Daniel Craig' && $m->first('511')?->ind1 === '1', '511 cast');
$check($m->subfield('521', 'a') === '15' && $m->first('521')?->ind1 === '8', '521 age rating');
$check($m->subfield('538', 'a') === 'Region B', '538 region');
$check($m->subfield('546', 'a') === 'Subtitles: danese', '546 subtitles in words');
$check($m->subfieldValues('041', 'a') === ['eng'] && $m->subfieldValues('041', 'j') === ['dan'], '041 $a audio, $j subtitles');
$check($m->first('041')?->ind1 === '1', '041 ind1 1: Danish subtitles on an English soundtrack are a translation');
$captions = BookMarcBuilder::marc21(['sottotitoli' => 'inglese'] + $row, $directors, [], null);
$check($captions->first('041')?->ind1 === '0' && $captions->subfieldValues('041', 'j') === ['eng'], '041 ind1 0: same-language subtitles are captions, not a translation');
$check([$m->subfield('336', 'b'), $m->subfield('337', 'b'), $m->subfield('338', 'b')] === ['tdi', 'v', 'vd'], '336-338 tdi/v/vd');
$vhs = BookMarcBuilder::marc21(['formato' => 'VHS', 'durata_minuti' => null] + $row, $directors, [], null);
$check($vhs->subfield('300', 'a') === '1 videocassette' && $vhs->first('306') === null && substr((string) $vhs->controlField('008'), 18, 3) === '---', 'a VHS without running time');
foreach ([
    'DVD' => ['vd', 'v'], 'Blu-ray Disc' => ['vd', 's'], '4K Ultra HD Blu-ray' => ['vd', 't'],
    'VHS videocassette' => ['vf', 'b'], 'videocassetta PAL' => ['vf', 'u'], 'videodisc' => ['vd', 'u'],
] as $format => [$carrier, $code]) {
    $legacyRow = ['tipo_media' => null, 'formato' => $format] + $row;
    $marc = BookMarcBuilder::marc21($legacyRow, [], [], null);
    $extent = $carrier === 'vf' ? '1 videocassette (95 min)' : '1 videodisc (95 min)';
    $check($marc->leader()[6] === 'g' && $marc->controlField('007') === $carrier . ' |' . $code . '||||', "$format MARC 007");
    $check($marc->subfield('338', 'b') === $carrier && $marc->subfield('300', 'a') === $extent, "$format MARC carrier and extent");
    $check(BookMarcBuilder::unimarc($legacyRow, [], [], null)->subfield('215', 'a') === $extent, "$format UNIMARC extent");
}
$override = BookMarcBuilder::marc21(['rda_carrier_type' => 'cr'] + $row, [], [], null);
$check($override->subfield('338', 'b') === 'cr' && $override->subfield('300', 'a') === '1 online resource (95 min)', 'explicit RDA carrier override');
$onlySubs = BookMarcBuilder::marc21(['lingua' => ''] + $row, $directors, [], null);
$check($onlySubs->subfieldValues('041', 'a') === [] && $onlySubs->subfieldValues('041', 'j') === ['dan'] && $onlySubs->first('041')?->ind1 === ' ', '041 with subtitles only, ind1 blank: no soundtrack language to compare');
$unknownAudio = BookMarcBuilder::marc21(['lingua' => 'klingon'] + $row, $directors, [], null);
$check($unknownAudio->subfieldValues('041', 'a') === ['und'] && $unknownAudio->subfieldValues('041', 'j') === ['dan'] && $unknownAudio->first('041')?->ind1 === ' ', '041 with an unrecognised soundtrack language (und), ind1 blank: nothing to compare');
$mixedAudio = BookMarcBuilder::marc21(['lingua' => 'inglese'] + $row, $directors, [], null);
$check($mixedAudio->first('041')?->ind1 === '1', '041 ind1 1 still set when the soundtrack language is known');
$back = (new Marc21LibriMapper())->map(array_values(array_filter(iterator_to_array(Iso2709Reader::fromString(Iso2709Writer::serialize($m)), false), static fn ($r): bool => $r instanceof MarcRecord))[0]);
$check($back['tipo_media'] === 'dvd' && $back['autori'] === ['Christopher Nolan'], 'export → import: the film and its director come back');

$bookRow = ['tipo_media' => 'libro', 'formato' => 'cartaceo'] + $row;
$bm = BookMarcBuilder::marc21($bookRow, $directors, [], null);
$check($bm->subfield('300', 'a') === '2 pages' && $bm->subfield('100', 'e') === 'author', 'a book: 300 pages, 100 author');
foreach (['306', '511', '521', '538', '546'] as $tag) {
    $check($bm->first($tag) === null, "a book has no $tag");
}
$check($bm->subfieldValues('041', 'j') === [] && substr((string) $bm->controlField('008'), 18, 3) === '   ' && substr((string) $bm->controlField('008'), 33, 2) === '  ', 'a book: no 041 $j, 008/18-20 and 33-34 untouched');

// ── UNIMARC ──────────────────────────────────────────────────────────────
$u = BookMarcBuilder::unimarc($row, $directors, [], null);
$check($u->leader()[6] === 'g' && $u->subfield('215', 'a') === '1 videodisc (95 min)', 'UNIMARC 215 carrier and running time');
$check($u->subfield('323', 'a') === 'Ana de Armas, Daniel Craig' && $u->subfield('333', 'a') === '15', 'UNIMARC 323 cast, 333 audience');
$check($u->subfield('700', '4') === '300' && $u->subfield('702', '4') === '730', 'UNIMARC 700 $4 300 (director), translator unchanged');
$ub = BookMarcBuilder::unimarc($bookRow, $directors, [], null);
$check($ub->subfield('215', 'a') === '2 p.' && $ub->subfield('700', '4') === '070' && $ub->first('323') === null && $ub->first('333') === null, 'a UNIMARC book is unchanged');

// The per-record admin download uses the Z39 plugin and adds authority subjects.
require_once $root . '/storage/plugins/z39-server/classes/UnimarcLibriParser.php';
$xml = (new \Z39Server\UnimarcLibriParser())->toUnimarcXml(
    ['contributors' => $directors, 'classificazione_dewey' => '791.43'] + $row,
    [['termine' => 'Cinema', 'bncf_id' => '1930']]
);
$download = iterator_to_array(\App\Support\Marc\MarcXmlReader::fromString($xml), false)[0];
$check($download instanceof MarcRecord && $download->leader()[6] === 'g', 'individual UNIMARC export identifies the film');
foreach ([['215', 'a'], ['215', 'd'], ['323', 'a'], ['333', 'a'], ['700', '4'], ['702', '4']] as [$tag, $sub]) {
    $check($download->subfield($tag, $sub) === $u->subfield($tag, $sub), "individual and bulk UNIMARC agree on $tag $$sub");
}
$check($download->subfield('676', 'a') === '791.43' && $download->subfieldValues('606', '3') === ['1930'], 'individual export keeps Dewey and authority subjects');

// ── A missing column is reported, never silently dropped ─────────────────
// SchemaRepair writes its once-per-version marker even when an ALTER fails,
// so a database without the film columns stays that way until the next
// update. save() must then say which submitted values were not stored.
// The column cache is primed to "missing", so no query reaches the handle.
$cache = new ReflectionProperty(FilmFields::class, 'columnCache');
$savedCache = $cache->getValue();
$cache->setValue(null, array_fill_keys(array_keys(FilmFields::COLUMNS), false));
$noDb = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
try {
    $dropped = FilmFields::save($noDb, 1, ['durata_minuti' => '95', 'interpreti' => "Anna Magnani\nAldo Fabrizi", 'sottotitoli' => '', 'titolo' => 'Roma città aperta']);
    $check($dropped === ['durata_minuti', 'interpreti'], 'values that could not be stored are returned, in column order');
    $check(FilmFields::save($noDb, 1, ['sottotitoli' => '', 'codice_regione' => '  ']) === [], 'clearing a missing column loses nothing and reports nothing');
    $check(FilmFields::save($noDb, 1, ['titolo' => 'Un libro']) === [], 'a submission without film keys reports nothing');

    // A failed UPDATE reports every column it tried to change, cleared
    // ones included: the old value stays although the form removed it.
    // The uninitialised handle makes prepare() throw, as a lost connection
    // would.
    $cache->setValue(null, array_fill_keys(array_keys(FilmFields::COLUMNS), true));
    $check(FilmFields::save($noDb, 1, ['durata_minuti' => '', 'sottotitoli' => '  ']) === ['durata_minuti', 'sottotitoli'], 'a failed UPDATE that only clears values is still reported');
    $check(FilmFields::save($noDb, 1, ['durata_minuti' => '95', 'interpreti' => '']) === ['durata_minuti', 'interpreti'], 'a failed UPDATE reports written and cleared columns');
    // A schema check that throws reports every submitted film field and is
    // not cached as "column missing".
    $cache->setValue(null, []);
    $check(FilmFields::save($noDb, 1, ['codice_regione' => 'B', 'classificazione_eta' => '', 'titolo' => 'x']) === ['classificazione_eta', 'codice_regione'], 'a failing schema check reports every submitted film field');
    $check($cache->getValue() === [], 'a failed schema check is not cached');
    $cache->setValue(null, array_fill_keys(array_keys(FilmFields::COLUMNS), false));
    $message = FilmFields::droppedMessage($dropped);
    $check($message !== '' && str_contains($message, 'storage/.schema-repaired'), 'the cataloguer is told the film data was not saved and how to repair it');
    $check(FilmFields::droppedMessage([]) === '', 'no message when nothing was lost');
    $msgKey = "Il libro è stato salvato, ma i dati del film (durata, interpreti, sottotitoli, classificazione, regione) no. Se il database non ha ancora le colonne, ripeti l'aggiornamento o elimina il file storage/.schema-repaired per rilanciare la verifica dello schema; poi reinserisci i dati del film e salva di nuovo la scheda. Il motivo è nel log degli errori.";
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
        $strings = json_decode((string) file_get_contents($root . "/locale/{$locale}.json"), true);
        $check(is_array($strings) && is_string($strings[$msgKey] ?? null) && str_contains($strings[$msgKey], 'storage/.schema-repaired'), "the warning is translated in {$locale}");
    }
    $controller = (string) file_get_contents($root . '/app/Controllers/LibriController.php');
    $check(substr_count($controller, '$filmDropped = \\App\\Support\\FilmFields::save(') === 2
        && substr_count($controller, 'FilmFields::droppedMessage($filmDropped)') === 2, 'both the create and the edit form surface the loss');
} finally {
    $cache->setValue(null, $savedCache);
}

// ── Schema ───────────────────────────────────────────────────────────────
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
foreach (array_keys(FilmFields::COLUMNS) as $column) {
    $check(preg_match('/^\s+`' . $column . '` [^\n]*(DEFAULT NULL|text COLLATE)/m', $schema) === 1, "schema.sql declares libri.$column, nullable");
}
$parsed = \App\Support\SchemaRepair::parse($schema);
$check(array_keys(FilmFields::COLUMNS) === array_values(array_intersect(array_keys($parsed['libri']['columns']), array_keys(FilmFields::COLUMNS))), 'the schema check sees the film columns, so updated installs get them');

echo "SUCCESS $count film cataloguing checks\n";
