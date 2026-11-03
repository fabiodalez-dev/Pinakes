<?php
declare(strict_types=1);

/**
 * Read a MARC file with the shared library and print its records as JSON,
 * for the Playwright specs that check what an export produced:
 *   php tests/helpers/marc-inspect.php <file>
 * → {"container": "iso2709"|"xml", "errors": n, "records": [{flavour, titolo, isbn13, autori, cover, leader9, has856}]}
 */

use App\Support\Marc\Iso2709Reader;
use App\Support\Marc\MarcFormat;
use App\Support\Marc\MarcRecord;
use App\Support\Marc\MarcXmlReader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$file = $argv[1] ?? '';
$container = MarcFormat::containerOfFile($file);
$reader = $container === MarcFormat::CONTAINER_XML ? MarcXmlReader::fromPath($file) : Iso2709Reader::fromPath($file);
$out = ['container' => $container, 'errors' => 0, 'records' => []];
foreach ($reader as $item) {
    if (!$item instanceof MarcRecord) {
        $out['errors']++;
        continue;
    }
    $flavour = MarcFormat::detectFlavour($item);
    $data = MarcFormat::mapperFor($flavour)->map($item);
    $out['records'][] = [
        'flavour' => $flavour,
        'titolo' => $data['titolo'],
        'isbn13' => $data['isbn13'],
        'autori' => $data['autori'],
        'editori' => $data['editori'],
        'cover' => $data['copertina_url'],
        'leader9' => $item->leader()[9],
        'id' => $item->controlField('001'),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
