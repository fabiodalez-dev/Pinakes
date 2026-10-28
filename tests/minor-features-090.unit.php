<?php
declare(strict_types=1);
/**
 * 0.9.0 minor features, pure logic: RDA derivation/overrides, the ISBD
 * catalogue card assembly, supplier input normalisation, the visitor-log
 * helpers, the bulk-card id parser and the statistics extension-point shape.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
if (!function_exists('__')) { function __(string $s, mixed ...$a): string { return $a ? sprintf($s, ...$a) : $s; } }

use App\Controllers\CatalogueCardController;
use App\Models\AttendanceRepository;
use App\Models\SupplierRepository;
use App\Support\CatalogueCard;
use App\Support\RdaTypes;
use App\Support\StatisticsSections;

$count = 0;
function checkMinor(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAIL ' . $label); } $count++; echo "OK $label\n"; }

// ── RDA ──
checkMinor(RdaTypes::derive('libro') === ['content' => 'txt', 'media' => 'n', 'carrier' => 'nc'], 'a book derives text / unmediated / volume');
checkMinor(RdaTypes::derive('dvd') === ['content' => 'tdi', 'media' => 'v', 'carrier' => 'vd'], 'a DVD derives moving image / video / videodisc');
checkMinor(RdaTypes::derive('audiolibro')['content'] === 'spw' && RdaTypes::derive('disco')['content'] === 'prm', 'audiobook is spoken word, a record is performed music');
checkMinor(RdaTypes::derive(null) === RdaTypes::derive('libro') && RdaTypes::derive('') === RdaTypes::derive('libro'), 'an empty media type derives as a book');
checkMinor(RdaTypes::normalize('carrier', ' CR ') === 'cr', 'an override is trimmed and lower-cased');
checkMinor(RdaTypes::normalize('carrier', 'nope') === null && RdaTypes::normalize('carrier', '') === null && RdaTypes::normalize('media', ['n']) === null, 'unknown, blank or non-string overrides are NULL');
checkMinor(RdaTypes::normalize('content', 'nc') === null, 'a code from another vocabulary is rejected');
$book = ['tipo_media' => 'libro', 'rda_carrier_type' => 'cr', 'rda_media_type' => 'c', 'rda_content_type' => null];
checkMinor(RdaTypes::overrides($book) === ['media' => 'c', 'carrier' => 'cr'], 'overrides() lists only the stored overrides');
checkMinor(RdaTypes::effective($book) === ['content' => 'txt', 'media' => 'c', 'carrier' => 'cr'], 'effective() mixes overrides over the derived triple');
checkMinor(RdaTypes::overrides(['tipo_media' => 'dvd']) === [], 'a record without overrides has none');
checkMinor(RdaTypes::effective(['tipo_media' => 'dvd']) === ['content' => 'tdi', 'media' => 'v', 'carrier' => 'vd'] && RdaTypes::effective(['tipo_media' => 'libro']) === ['content' => 'txt', 'media' => 'n', 'carrier' => 'nc'], 'without an override BIBFRAME emits the derived triple: a book keeps txt/n/nc, a DVD gets its real types');
checkMinor(RdaTypes::codes($book) === RdaTypes::effective($book) && array_keys(RdaTypes::codes(['tipo_media' => 'dvd'])) === ['content', 'media', 'carrier'], 'codes() is the effective triple under the content/media/carrier keys the MARC 336/337/338 builder reads');
checkMinor(RdaTypes::uri('carrier', 'cr') === 'http://id.loc.gov/vocabulary/carriers/cr' && RdaTypes::uri('content', 'txt') === 'http://id.loc.gov/vocabulary/contentTypes/txt', 'codes map to id.loc.gov URIs');
foreach (RdaTypes::kinds() as $kind) {
    foreach (array_keys(MediaLabelsKeys()) as $media) {
        checkMinor(isset(RdaTypes::options($kind)[RdaTypes::derive($media)[$kind]]), "derived {$kind} of {$media} is a code of its vocabulary");
    }
}
function MediaLabelsKeys(): array { return \App\Support\MediaLabels::allTypes(); }

// ── ISBD catalogue card ──
$card = CatalogueCard::lines([
    'titolo' => 'Il sistema periodico', 'sottotitolo' => 'racconti',
    'autori' => [['nome' => 'Primo Levi', 'pseudonimo' => null]],
    'traduttori' => [['nome' => 'Ann Goldstein', 'pseudonimo' => null]],
    'edizione' => '3. ed.', 'luogo_pubblicazione' => 'Torino', 'editori' => [['id' => 1, 'nome' => 'Einaudi']],
    'anno_pubblicazione' => 1975, 'numero_pagine' => 245, 'dimensioni' => '21 cm',
    'collana' => 'Nuovi coralli', 'numero_serie' => '16', 'isbn13' => '9788806172268',
    'genere_nome' => 'Narrativa', 'parole_chiave' => 'Chimica, Memorie, chimica',
    'collocazione' => 'A.1.3', 'classificazione_dewey' => '853.914', 'numero_inventario' => 'INV-77',
]);
checkMinor($card['heading'] === 'Levi, Primo', 'the main entry is the principal author, surname first');
checkMinor(str_starts_with($card['body'], 'Il sistema periodico : racconti / Primo Levi ; traduzione di Ann Goldstein'), 'area 1: title : subtitle / responsibility ; translator');
checkMinor(str_contains($card['body'], '. – 3. ed. – Torino : Einaudi, 1975. – 245 p. ; 21 cm. – (Nuovi coralli ; 16).'), 'areas 2, 4, 5 and 6 follow with ISBD punctuation');
checkMinor($card['isbn'] === 'ISBN 9788806172268', 'the standard number line');
checkMinor($card['tracings'] === '1. Narrativa. 2. Chimica. 3. Memorie. I. Titolo.', 'tracings: genre and keywords once each (case-insensitive), then the title entry');
checkMinor($card['call'] === "A.1.3\n853.914" && $card['inventory'] === 'INV-77', 'call number carries shelf mark and Dewey, inventory number kept');
$anon = CatalogueCard::lines(['titolo' => 'Anonimo &amp; basta', 'autori' => [], 'editori' => [], 'editore_nome' => 'Sellerio']);
checkMinor($anon['heading'] === 'Anonimo & basta' && $anon['body'] === 'Anonimo & basta. – Sellerio.', 'without an author the title is the heading; HTML entities are decoded');
checkMinor($anon['tracings'] === '', 'no subjects and no author: no tracings');
$pdf = CatalogueCard::render([['titolo' => 'Uno', 'autori' => []], ['titolo' => 'Due', 'autori' => []]], 'Biblioteca');
checkMinor(str_starts_with($pdf, '%PDF-'), 'render() produces a PDF');
checkMinor(preg_match_all('#/Type\s*/Page[^s]#', $pdf) === 2, 'one card per book (two pages)');
checkMinor(preg_match('#/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+(354\.\d+)\s+(212\.\d+)\s*\]#', $pdf, $m) === 1, 'the page is 125 × 75 mm landscape (354.33 × 212.60 pt)');
$keywords = implode(', ', array_map(static fn (int $n): string => 'Soggetto lungo numero ' . $n, range(1, 40)));
$crowded = ['titolo' => str_repeat('Titolo molto lungo ', 12), 'sottotitolo' => str_repeat('sottotitolo esteso ', 10), 'autori' => [], 'editore_nome' => 'Sellerio', 'parole_chiave' => $keywords, 'numero_inventario' => 'INV-999', 'isbn13' => '9788804668619'];
$pdf = CatalogueCard::render([$crowded, ['titolo' => 'Due', 'autori' => []]], 'Biblioteca');
checkMinor(preg_match_all('#/Type\s*/Page[^s]#', $pdf) === 2, 'a card with 40 keywords and a long title still takes exactly one page');

// ── Bulk ids ──
checkMinor(CatalogueCardController::parseIds('3, 1,3,x,-2,0,2') === [3, 1, 2], 'bulk ids: positive integers, first-seen order, no duplicates');
checkMinor(count(CatalogueCardController::parseIds(implode(',', range(1, 500)))) === CatalogueCardController::MAX_BULK, 'bulk print is capped');

// ── Suppliers ──
$s = SupplierRepository::normalize(['nome' => '  Libreria Rossi  ', 'email' => 'not-an-email', 'sito_web' => 'www.rossi.it', 'telefono' => '', 'note' => ['x']]);
checkMinor($s['nome'] === 'Libreria Rossi' && $s['telefono'] === null && $s['note'] === null, 'supplier fields are trimmed, blanks and non-scalars become NULL');
checkMinor($s['email'] === null && $s['sito_web'] === 'https://www.rossi.it', 'invalid email dropped, scheme-less website gets https');
checkMinor(SupplierRepository::normalize(['sito_web' => 'javascript:alert(1)'])['sito_web'] === null, 'a non-http website is rejected');
checkMinor(array_keys($s) === SupplierRepository::FIELDS, 'normalize() returns exactly the writable fields');
checkMinor(SupplierRepository::invalidFields(['email' => 'not-an-email', 'sito_web' => 'not a url']) === ['email', 'sito_web'], 'the form is refused on an e-mail or website that is not one');
checkMinor(SupplierRepository::invalidFields(['email' => ' ordini@example.org ', 'sito_web' => 'www.rossi.it']) === [] && SupplierRepository::invalidFields(['email' => '', 'sito_web' => null]) === [], 'a valid or blank e-mail and website pass');
checkMinor(SupplierRepository::invalidFields(['sito_web' => 'javascript:alert(1)']) === ['sito_web'], 'a non-http website is refused, not silently dropped');

// ── Visitor log helpers ──
checkMinor(AttendanceRepository::shortName('Mario', 'rossi') === 'Mario R.' && AttendanceRepository::shortName('Cher', '') === 'Cher', 'kiosk names are shortened to the initial');
checkMinor(AttendanceRepository::normalizeRetention('30') === 30 && AttendanceRepository::normalizeRetention('0') === 0, 'retention accepts days and 0 (keep forever)');
checkMinor(AttendanceRepository::normalizeRetention('-5') === AttendanceRepository::DEFAULT_RETENTION_DAYS && AttendanceRepository::normalizeRetention('999999') === AttendanceRepository::MAX_RETENTION_DAYS, 'retention is clamped; a negative value falls back to the default, never to "keep forever"');
checkMinor(AttendanceRepository::normalizeRetention('abc') === AttendanceRepository::DEFAULT_RETENTION_DAYS, 'non-numeric retention falls back to the default');
checkMinor(AttendanceRepository::clampDays(0) === 1 && AttendanceRepository::clampDays(10000) === 366, 'statistics period is clamped');

// ── Statistics extension points ──
checkMinor(StatisticsSections::tableSection(null) === null && StatisticsSections::tableSection(['x' => 1]) === null, 'no provider data hides the section');
checkMinor(StatisticsSections::tableSection(['rows' => [['label' => 'A', 'value' => 3], ['label' => 'B'], 'junk', ['label' => 'C', 'value' => [1]]], 'note' => 'n'])
    === ['rows' => [['label' => 'A', 'value' => 3]], 'note' => 'n'], 'malformed rows are dropped, the note is kept');

echo "ALL {$count} PASS\n";
