<?php
declare(strict_types=1);

/**
 * MARC test files, assembled byte by byte with a serializer of its own (not
 * App\Support\Marc\Iso2709Writer), so the reader is never tested only against
 * what the writer produces.
 *
 *   php tests/helpers/marc-fixtures.php fixtures
 *       (re)writes the committed files in tests/fixtures/marc/
 *   php tests/helpers/marc-fixtures.php e2e <out-file> <run-id> <count> <mrc|xml|unimarc> [first-index]
 *       a file of <count> MARC 21 (or UNIMARC) books whose titles and ISBNs
 *       carry <run-id>, for the Playwright specs
 */

const FT = "\x1E";
const RT = "\x1D";
const SF = "\x1F";

/**
 * @param list<array{0:string,1?:string,2?:string,3?:list<array{0:string,1:string}>|string}> $fields
 *        [tag, data] for control fields, [tag, ind1, ind2, subfields] for data fields
 */
function iso(string $leader, array $fields): string
{
    $dir = '';
    $data = '';
    foreach ($fields as $f) {
        $tag = $f[0];
        $content = count($f) === 2 ? $f[1] : $f[1] . $f[2] . implode('', array_map(static fn ($s) => SF . $s[0] . $s[1], $f[3]));
        $content .= FT;
        $dir .= $tag . sprintf('%04d%05d', strlen($content), strlen($data));
        $data .= $content;
    }
    $dir .= FT;
    $base = 24 + strlen($dir);
    $len = $base + strlen($data) + 1;
    return sprintf('%05d', $len) . substr($leader, 5, 7) . sprintf('%05d', $base) . substr($leader, 17, 7) . $dir . $data . RT;
}

function isbn13(string $twelve): string
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $twelve . (string) ((10 - $sum % 10) % 10);
}

function xmlRecord(string $leader, array $fields, string $indent = '  '): string
{
    $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = $indent . "<record>\n" . $indent . "  <leader>" . $e($leader) . "</leader>\n";
    foreach ($fields as $f) {
        if (count($f) === 2) {
            $out .= $indent . '  <controlfield tag="' . $f[0] . '">' . $e($f[1]) . "</controlfield>\n";
            continue;
        }
        $out .= $indent . '  <datafield tag="' . $f[0] . '" ind1="' . $e($f[1]) . '" ind2="' . $e($f[2]) . "\">\n";
        foreach ($f[3] as $s) {
            $out .= $indent . '    <subfield code="' . $e($s[0]) . '">' . $e($s[1]) . "</subfield>\n";
        }
        $out .= $indent . "  </datafield>\n";
    }
    return $out . $indent . "</record>\n";
}

/** A MARC 21 book of the E2E series. */
function e2eMarc21(string $run, int $i, string $digits): array
{
    $isbn = isbn13('978' . str_pad($digits, 9, '0', STR_PAD_LEFT));
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $fields = [
        ['001', "MRC{$run}{$n}"],
        ['008', '240101s2019    it            000 0 ita d'],
        ['020', ' ', ' ', [['a', $isbn . ' (brossura)']]],
        ['041', '0', ' ', [['a', 'ita']]],
        ['082', '0', '4', [['a', '853.92'], ['2', '23']]],
        ['100', '1', ' ', [['a', "Autore{$run} {$n}, Mario,"], ['e', 'author']]],
        ['245', '1', '0', [['a', "Libro MARC {$run} {$n} :"], ['b', 'romanzo di prova /'], ['c', "Mario Autore{$run} {$n} ; traduzione di Anna Traduttrice{$run}"]]],
        ['250', ' ', ' ', [['a', '2. ed.']]],
        ['264', ' ', '1', [['a', 'Milano :'], ['b', "Editrice{$run}"], ['c', '2019.']]],
        ['300', ' ', ' ', [['a', (120 + $i) . ' p. ;'], ['c', '21 cm']]],
        ['490', '0', ' ', [['a', "Collana{$run}"], ['v', (string) $i]]],
        ['520', ' ', ' ', [['a', "Descrizione del libro {$n} della serie {$run}."]]],
        ['650', ' ', '4', [['a', "Soggetto{$run}"]]],
        ['700', '1', ' ', [['a', "Traduttrice{$run}, Anna"]]],
    ];
    return [$isbn, $fields];
}

$mode = $argv[1] ?? 'fixtures';

if ($mode === 'e2e') {
    [$out, $run, $count, $kind] = [$argv[2], $argv[3], (int) $argv[4], $argv[5] ?? 'mrc'];
    $first = (int) ($argv[6] ?? 1);
    $seed = abs(crc32($run)) % 900000;
    $bytes = $kind === 'xml' ? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<collection xmlns=\"http://www.loc.gov/MARC21/slim\">\n" : '';
    for ($i = $first; $i < $first + $count; $i++) {
        [, $fields] = e2eMarc21($run, $i, (string) ($seed * 100 + $i));
        if ($kind === 'malformed') {
            // every third record has a directory that points past its end
            $record = iso('00000nam a2200000 i 4500', $fields);
            $bytes .= $i % 3 === 0 ? substr_replace($record, '9999', 24 + 3, 4) : $record;
            continue;
        }
        if ($kind === 'marc8') {
            // MARC-8: "Città" with a combining grave before the a; record 2 switches to Cyrillic
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $title = $i === $first + 1 ? "Titolo \x1B(N{$run} {$n}" : "Citt\xE1a MARC8 {$run} {$n}";
            $bytes .= iso('00000nam  2200000   4500', [
                ['001', "M8{$run}{$n}"],
                ['020', ' ', ' ', [['a', isbn13('978' . str_pad((string) ($seed * 100 + 50 + $i), 9, '0', STR_PAD_LEFT))]]],
                ['100', '1', ' ', [['a', "M\xE8uller{$run}, J\xE8urgen"]]],
                ['245', '1', '0', [['a', $title]]],
                ['264', ' ', '1', [['b', "Edizioni{$run}"], ['c', '1999']]],
            ]);
            continue;
        }
        if ($kind === 'authors') {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $bytes .= iso('00000nam a2200000 i 4500', [
                ['020', ' ', ' ', [['a', isbn13('978' . str_pad((string) ($seed * 100 + 80 + $i), 9, '0', STR_PAD_LEFT))]]],
                ['100', '1', ' ', [['a', "Häffner{$run}, Jürgen"]]],
                ['245', '1', '0', [['a', "Libro esatto {$run} {$n}"]]],
                ['264', ' ', '1', [['b', "Röss{$run}"], ['c', '2020']]],
            ]);
            continue;
        }
        if ($kind === 'xml') {
            $bytes .= xmlRecord('00000nam a2200000 i 4500', $fields);
        } elseif ($kind === 'unimarc') {
            $isbn = isbn13('979' . str_pad((string) ($seed * 100 + $i), 9, '0', STR_PAD_LEFT));
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $bytes .= iso('00000nam  2200000   4500', [
                ['001', "UNI{$run}{$n}"],
                ['010', ' ', ' ', [['a', $isbn]]],
                ['101', '0', ' ', [['a', 'fre']]],
                ['200', '1', ' ', [['a', "Livre UNIMARC {$run} {$n}"], ['e', 'roman'], ['f', 'Jean Écrivain']]],
                ['210', ' ', ' ', [['a', 'Paris'], ['c', "Éditions{$run}"], ['d', '2021']]],
                ['215', ' ', ' ', [['a', '200 p.'], ['d', '22 cm']]],
                ['330', ' ', ' ', [['a', "Résumé {$n}."]]],
                ['700', ' ', '1', [['a', "Écrivain{$run}"], ['b', 'Jean'], ['4', '070']]],
                ['702', ' ', '1', [['a', "Traducteur{$run}"], ['b', 'Paul'], ['4', '730']]],
            ]);
        } else {
            $bytes .= iso('00000nam a2200000 i 4500', $fields);
        }
    }
    if ($kind === 'xml') {
        $bytes .= "</collection>\n";
    }
    file_put_contents($out, $bytes);
    exit(0);
}

$dir = dirname(__DIR__) . '/fixtures/marc';

// MARC 21, UTF-8 (leader/09 = a): three books, every mapped field.
$marc21 = [
    iso('00000nam a2200000 i 4500', [
        ['001', 'ocm00001'],
        ['003', 'OCoLC'],
        ['008', '200615s1980    it a          000 1 ita d'],
        ['020', ' ', ' ', [['a', '9788845292613 (brossura)'], ['q', 'paperback']]],
        ['020', ' ', ' ', [['a', '8845292614']]],
        ['024', '3', ' ', [['a', '4006381333931']]],
        ['041', '0', ' ', [['a', 'ita']]],
        ['050', ' ', '4', [['a', 'PQ4865.C6'], ['b', 'N6 1980']]],
        ['082', '0', '4', [['a', '853.914'], ['2', '23']]],
        ['100', '1', ' ', [['a', 'Eco, Umberto,'], ['e', 'author.']]],
        ['245', '1', '4', [['a', 'Il nome della rosa :'], ['b', 'romanzo /'], ['c', 'Umberto Eco ; a cura di Lùcia Ferré ; illustrazioni di Ugo Disegni.']]],
        ['250', ' ', ' ', [['a', 'Prima edizione.']]],
        ['264', ' ', '1', [['a', 'Milano :'], ['b', 'Bompiani,'], ['c', '[1980]']]],
        ['300', ' ', ' ', [['a', '503 pagine ;'], ['c', '21 cm']]],
        ['490', '1', ' ', [['a', 'Tascabili Bompiani ;'], ['v', '12']]],
        ['520', ' ', ' ', [['a', 'Un monaco indaga su una serie di delitti in un’abbazia.']]],
        ['650', ' ', '0', [['a', 'Monasteri'], ['v', 'Romanzi.']]],
        ['653', ' ', ' ', [['a', 'medioevo']]],
        ['700', '1', ' ', [['a', 'Ferré, Lùcia'], ['4', 'edt']]],
        ['700', '1', ' ', [['a', 'Disegni, Ugo.']]],
        ['700', '1', ' ', [['a', 'Rossi, Mario'], ['e', 'joint author']]],
        ['856', '4', '2', [['3', 'Cover image'], ['u', 'https://covers.example.org/rosa.jpg']]],
    ]),
    iso('00000nam a2200000 i 4500', [
        ['001', 'ocm00002'],
        ['008', '150101s2015    gw            000 0 ger d'],
        ['020', ' ', ' ', [['a', '978-3-16-148410-0']]],
        ['100', '1', ' ', [['a', 'Müller, Jürgen']]],
        ['245', '1', '0', [['a', 'Ökologie der Straße /'], ['c', 'Jürgen Müller ; übersetzt von Zoë Øster']]],
        ['260', ' ', ' ', [['a', 'Berlin :'], ['b', 'Verlag Grün ;'], ['b', 'Edition Blau,'], ['c', 'c2015.']]],
        ['300', ' ', ' ', [['a', 'xii, 288 S. :'], ['c', '24 cm']]],
        ['700', '1', ' ', [['a', 'Øster, Zoë']]],
        ['830', ' ', '0', [['a', 'Studien zur Stadt.'], ['v', '7']]],
    ]),
    iso('00000nam a2200000   4500', [
        ['001', 'ocm00003'],
        ['008', '990101s1999    xx            000 0 eng d'],
        ['245', '0', '0', [['a', 'Untitled anthology']]],
    ]),
];
file_put_contents("$dir/marc21-utf8.mrc", implode('', $marc21));

// MARC 21, MARC-8 (leader/09 blank): ANSEL spacing letters and combining
// diacritics written before their base letter; the third record switches to
// Cyrillic, which the reader must report as an error and skip.
$marc8 = [
    iso('00000nam  2200000   4500', [
        ['001', 'm8-1'],
        ['008', '880101s1988    sp            000 0 spa d'],
        ['100', '1', ' ', [['a', "Garc\xE2ia M\xE2arquez, Gabriel"]]],
        ['245', '1', '0', [['a', "Cien a\xE4nos de soledad"]]],
        ['264', ' ', '1', [['b', "Ediciones C\xE1atedra"], ['c', '1988']]],
    ]),
    iso('00000nam  2200000   4500', [
        ['001', 'm8-2'],
        ['100', '1', ' ', [['a', "\xA2rsted, Hans Christian"]]],
        ['245', '1', '0', [['a', "Fran\xF0cais et \xB5sop \xC3 2001 \xA1\xE2od\xE2z"]]],
    ]),
    iso('00000nam  2200000   4500', [
        ['001', 'm8-3'],
        ['245', '1', '0', [['a', "Title \x1B(NCyrillic"]]],
    ]),
    iso('00000nam  2200000   4500', [
        ['001', 'm8-4'],
        ['245', '1', '0', [['a', "Ha\xE8user und G\xE8arten"]]],
    ]),
];
file_put_contents("$dir/marc21-marc8.mrc", implode('', $marc8));

// UNIMARC, UTF-8 (leader/09 undefined, blank).
$unimarc = [
    iso('00000nam  2200000   4500', [
        ['001', 'IT\\ICCU\\RAV\\0001'],
        ['010', ' ', ' ', [['a', '9788806219499']]],
        ['073', ' ', ' ', [['a', '8001234567894']]],
        ['100', ' ', ' ', [['a', '20200101d1947    y0itay50      ba']]],
        ['101', '0', ' ', [['a', 'ita']]],
        ['200', '1', ' ', [['a', 'Se questo è un uomo'], ['e', 'testimonianza'], ['f', 'Primo Levi']]],
        ['205', ' ', ' ', [['a', 'Nuova ed.']]],
        ['210', ' ', ' ', [['a', 'Torino'], ['c', 'Einaudi'], ['d', '2014']]],
        ['215', ' ', ' ', [['a', '220 p.'], ['d', '20 cm']]],
        ['225', '1', ' ', [['a', 'Super ET'], ['v', '45']]],
        ['330', ' ', ' ', [['a', 'Il racconto della deportazione ad Auschwitz.']]],
        ['606', ' ', ' ', [['a', 'Olocausto'], ['2', 'FIR']]],
        ['676', ' ', ' ', [['a', '940.5318']]],
        ['700', ' ', '1', [['a', 'Levi'], ['b', 'Primo'], ['4', '070']]],
        ['702', ' ', '1', [['a', 'Belpoliti'], ['b', 'Marco'], ['4', '340']]],
        ['856', '4', ' ', [['u', 'https://covers.example.org/levi.png']]],
    ]),
    iso('00000nam  2200000   4500', [
        ['001', 'FRBNF0002'],
        ['010', ' ', ' ', [['a', '2070360024']]],
        ['101', '0', ' ', [['a', 'fre']]],
        ['200', '1', ' ', [['a', "L'Étranger"], ['f', 'Albert Camus']]],
        ['214', ' ', '0', [['a', 'Paris'], ['c', 'Gallimard'], ['d', 'DL 1971']]],
        ['700', ' ', '1', [['a', 'Camus'], ['b', 'Albert'], ['4', '070']]],
        ['702', ' ', '1', [['a', 'Ward'], ['b', 'Matthew'], ['4', '730']]],
    ]),
];
file_put_contents("$dir/unimarc-utf8.mrc", implode('', $unimarc));

// Malformed records between good ones: a directory that points past the
// record, a record cut short, a directory whose length is not a multiple of 12.
$good = $marc21[2];
$bad1 = substr_replace($marc21[1], '9999', 24 + 3, 4); // first entry's length -> 9999
$bad2 = '00042nam a22000   4500' . RT;
$bad3 = substr($marc21[0], 0, 24) . '24500100000' . FT . '  ' . SF . 'aBroken' . FT . RT;
file_put_contents("$dir/malformed.mrc", $good . $bad1 . "\n" . $bad2 . $bad3 . $marc21[0]);

// MARCXML collection, with an SRU envelope around the second half.
$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<collection xmlns=\"http://www.loc.gov/MARC21/slim\">\n"
    . xmlRecord('00000nam a2200000 i 4500', [
        ['001', 'x1'],
        ['020', ' ', ' ', [['a', '9780140449136']]],
        ['100', '1', ' ', [['a', 'Dostoyevsky, Fyodor,']]],
        ['245', '1', '0', [['a', 'Crime and punishment /'], ['c', 'Fyodor Dostoyevsky ; translated with an introduction and notes by David McDuff.']]],
        ['264', ' ', '1', [['a', 'London :'], ['b', 'Penguin,'], ['c', '2003.']]],
        ['700', '1', ' ', [['a', 'McDuff, David,']]],
        ['856', '4', '2', [['u', 'https://covers.example.org/crime.jpeg'], ['q', 'image/jpeg']]],
    ])
    . xmlRecord('00000nam a2200000 i 4500', [
        ['001', 'x2'],
        ['245', '0', '0', [['a', 'Ampersand & <angle> "quotes"']]],
    ])
    . "</collection>\n";
file_put_contents("$dir/marc21-collection.xml", $xml);

echo "fixtures written to $dir\n";
