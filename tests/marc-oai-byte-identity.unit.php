<?php
declare(strict_types=1);

/**
 * The OAI-PMH plugin's MARC 21 (MARCXML), UNIMARC (MARCXchange) and ISO 2709
 * UNIMARC serializations, byte for byte, against golden files recorded before
 * the plugin moved onto the shared App\Support\Marc classes. A refactor that
 * changes one byte of what harvesters receive fails here. Deliberate changes
 * are re-recorded with --record: the MARCXML record declares xmlns:xsi itself,
 * and the UNIMARC genre 606 carries $2 local (review of #505).
 *
 * The only time-dependent value, UNIMARC 801 $c (today, UTC), is normalised.
 *
 * Run:    php tests/marc-oai-byte-identity.unit.php            (exit 0 iff identical)
 * Record: php tests/marc-oai-byte-identity.unit.php --record   (rewrites the goldens)
 */

use App\Plugins\OaiPmhServer\OaiPmhServerPlugin;
use App\Support\HookManager;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/app/helpers.php';
require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';

$record = in_array('--record', $argv, true);
$goldenDir = __DIR__ . '/fixtures/marc/oai-golden';

$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
if (!isset($_ENV['APP_CANONICAL_URL'])) {
    $_ENV['APP_CANONICAL_URL'] = 'https://library.example.org';
}

// The three serializations never touch the database: without one (no
// MySQL on the machine) the plugin is built without its constructor.
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $dbPass, $name, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
    $plugin = new OaiPmhServerPlugin($db, new HookManager($db));
} catch (Throwable $e) {
    echo "  (no database: " . $e->getMessage() . "; the plugin is built without one)\n";
    $plugin = (new ReflectionClass(OaiPmhServerPlugin::class))->newInstanceWithoutConstructor();
}
$call = static function (string $method, array $args) use ($plugin) {
    $m = new ReflectionMethod(OaiPmhServerPlugin::class, $method);
    return $m->invokeArgs($plugin, $args);
};
$xml = static function (string $method, array $args) use ($call): string {
    $xw = new XMLWriter();
    $xw->openMemory();
    $xw->startDocument('1.0', 'UTF-8');
    $call($method, array_merge([$xw], $args));
    $xw->endDocument();
    return $xw->outputMemory();
};

$cases = [
    'monograph' => [
        [
            'id' => 4711, 'titolo' => 'Il nome della rosa', 'sottotitolo' => 'Romanzo «storico»',
            'isbn13' => '9788845292613', 'isbn10' => '8845292614', 'ean' => '9788845292613', 'issn' => '',
            'lingua' => 'italiano', 'classificazione_dewey' => '853.914', 'edizione' => 'Seconda edizione',
            'luogo_pubblicazione' => 'Milano', 'anno_pubblicazione' => '1980', 'numero_pagine' => '503',
            'dimensioni' => '21 cm', 'collana' => 'Tascabili', 'numero_serie' => '12',
            'descrizione' => '<p>Un monaco &amp; un <b>delitto</b> nell’abbazia.</p>', 'descrizione_plain' => '',
            'parole_chiave' => 'medioevo, giallo,  abbazia', 'traduttore' => '', 'illustratore' => 'Ugo Disegni', 'curatore' => '',
            'tipo_media' => 'libro', 'created_at' => '2024-03-01 10:00:00', 'updated_at' => '2025-06-02 11:12:13',
        ],
        [
            ['nome' => 'Umberto Eco', 'ruolo' => 'principale'],
            ['nome' => 'Mario Rossi', 'ruolo' => 'co-autore'],
            ['nome' => 'William Weaver', 'ruolo' => 'traduttore'],
            ['nome' => 'Lùcia Ferré', 'ruolo' => 'curatore'],
        ],
        [['nome' => 'Bompiani'], ['nome' => 'Fabbri Editori']],
        ['id' => 3, 'nome' => 'Narrativa'],
    ],
    'serial' => [
        [
            'id' => 9, 'titolo' => 'Rivista di studi', 'sottotitolo' => '', 'isbn13' => '', 'isbn10' => '',
            'ean' => '', 'issn' => '1234-5679', 'lingua' => 'inglese, italiano', 'classificazione_dewey' => '',
            'edizione' => '', 'luogo_pubblicazione' => '', 'anno_pubblicazione' => '', 'numero_pagine' => '',
            'dimensioni' => '', 'collana' => '', 'numero_serie' => '', 'descrizione' => '', 'descrizione_plain' => '',
            'parole_chiave' => '', 'traduttore' => 'Ann Translator', 'illustratore' => '', 'curatore' => 'Ed Itor',
            'tipo_media' => 'rivista', 'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2023-01-02 00:00:00',
        ],
        [],
        [],
        null,
    ],
    'minimal' => [
        [
            'id' => 1, 'titolo' => 'Ægir — ein Ölbuch', 'isbn13' => '', 'isbn10' => '0306406152', 'ean' => '4006381333931',
            'lingua' => '', 'anno_pubblicazione' => 'n.d.', 'collana' => 'Serie', 'issn' => '0317-8471',
            'created_at' => '2022-02-02 02:02:02', 'updated_at' => '2022-02-03 02:02:02',
        ],
        [['nome' => 'Platone', 'ruolo' => 'principale'], ['nome' => 'Rossi, Mario', 'ruolo' => 'illustratore']],
        [['nome' => 'Laterza']],
        null,
    ],
];

$today = gmdate('Ymd');
$normalise = static fn (string $s): string => str_replace($today, 'YYYYMMDD', $s);

$pass = 0;
$fail = 0;
foreach ($cases as $label => [$row, $authors, $publishers, $genre]) {
    $outputs = [
        "{$label}.marcxml.xml" => $xml('writeBookMarcXml', [$row, $authors, $publishers, $genre]),
        "{$label}.unimarc.xml" => $xml('writeBookUnimarc', [$row, $authors, $publishers, $genre]),
        "{$label}.unimarc.mrc" => $call('bookToIso2709', [$row, $authors, $publishers, $genre]),
    ];
    foreach ($outputs as $file => $bytes) {
        $bytes = $normalise((string) $bytes);
        $path = $goldenDir . '/' . $file;
        if ($record) {
            file_put_contents($path, $bytes);
            echo "  REC  {$file} (" . strlen($bytes) . " bytes)\n";
            continue;
        }
        $golden = is_file($path) ? (string) file_get_contents($path) : null;
        if ($golden !== null && $golden === $bytes) {
            $pass++;
            echo "  OK   {$file} identical (" . strlen($bytes) . " bytes)\n";
        } else {
            $fail++;
            echo "  FAIL {$file} differs from the golden file\n";
        }
    }
}
if ($record) {
    exit(0);
}
echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
