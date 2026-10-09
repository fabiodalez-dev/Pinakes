<?php
declare(strict_types=1);

/**
 * Archives plugin — MARC21 export validity, danMARC2 dialect stability,
 * MARC21 → import round trip, EAD3 structure and Dublin Core dc:type.
 *
 * The MARC21 writer (writeArchivalUnitMarc21Record) serves every surface that
 * claims MARC21: /admin/archives/export.xml, OAI metadataPrefix=marcxml and
 * SRU recordSchema=marcxml. Its output must validate against the bundled
 * schemas/MARC21slim.xsd (checked with both xmllint and libxml), and the
 * importer behind /admin/archives/import must read it back. The danMARC2 /
 * ABA writer must stay byte-for-byte what it was before the split (golden
 * hash below, produced from the pre-split writer with the same fixture).
 *
 * Fixture units, files and authorities are inserted inside a transaction that
 * is rolled back, so the database is left unchanged. ensureSchema() runs first
 * (idempotent) so the `published` column exists.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/archives-marc21-xsd.unit.php
 * Exits 0 on success, non-zero on any failure.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/archives/ArchivesPlugin.php';

use App\Plugins\Archives\ArchivesPlugin;
use App\Support\HookManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(
            null,
            getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''),
            getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')),
            getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''),
            0,
            $socket
        )
        : new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''),
            getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')),
            getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''),
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        ++$passed;
        echo "  OK   {$label}\n";
    } else {
        ++$failed;
        echo "  FAIL {$label}\n";
    }
};

$xsd = $root . '/storage/plugins/archives/schemas/MARC21slim.xsd';
$plugin = new ArchivesPlugin($db, new HookManager($db));
$ref = new ReflectionClass($plugin);
$call = static function (string $method, mixed ...$args) use ($ref, $plugin): mixed {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($plugin, ...$args);
};
/** Render records inside a MARC Slim <collection>. */
$collection = static function (callable $body): string {
    $xw = new XMLWriter();
    $xw->openMemory();
    $xw->setIndent(true);
    $xw->startDocument('1.0', 'UTF-8');
    $xw->startElementNs(null, 'collection', 'http://www.loc.gov/MARC21/slim');
    $body($xw);
    $xw->endElement();
    $xw->endDocument();
    return $xw->outputMemory();
};
// xmllint when the host has it (developer machines); otherwise libxml's own
// validator through DOMDocument, the same engine (CI runners lack the CLI).
$xmllint = static function (string $xml) use ($xsd): array {
    exec('command -v xmllint 2>/dev/null', $which, $whichCode);
    if ($whichCode === 0 && $which !== []) {
        $tmp = tempnam(sys_get_temp_dir(), 'marc21-');
        file_put_contents($tmp, $xml);
        exec('xmllint --noout --schema ' . escapeshellarg($xsd) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        unlink($tmp);
        return [$code, implode("\n", $out)];
    }
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml) && $doc->schemaValidate($xsd);
    $errors = array_map(static fn(LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return [$ok ? 0 : 1, implode("\n", $errors)];
};
$xpath = static function (string $xml): DOMXPath {
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('m', 'http://www.loc.gov/MARC21/slim');
    $xp->registerNamespace('e', 'http://ead3.archivists.org/schema/');
    $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    return $xp;
};

// ── Schema (idempotent) — makes sure `published` exists ─────────────────────
$schema = $plugin->ensureSchema();
$check(($schema['failed'] ?? []) === [], 'ensureSchema() reports no failed step');

// ── 1. danMARC2 / ABA dialect unchanged ─────────────────────────────────────
echo "1. danMARC2 dialect (golden):\n";
$danRow = [
    'id' => 0, 'parent_id' => null, 'reference_code' => 'DK-ABA-0001', 'institution_code' => 'DK-ABA',
    'level' => 'fonds', 'formal_title' => 'Formel titel', 'constructed_title' => 'Arbejderforeningens arkiv',
    'date_start' => 1890, 'date_end' => 1950, 'predominant_dates' => '1900-1920', 'date_gaps' => '1915',
    'extent' => '12 hyldemeter', 'scope_content' => 'Protokoller og breve.', 'arrangement_system' => 'Kronologisk',
    'access_conditions' => 'Fri adgang', 'reproduction_rules' => 'Med kildeangivelse', 'language_codes' => 'dan',
    'finding_aids' => 'Registrant', 'originals_location' => 'ABA', 'copies_location' => 'Mikrofilm',
    'related_units' => 'DK-ABA-0002', 'archival_history' => 'Afleveret 1960.', 'acquisition_source' => 'Foreningen',
    'physical_location' => 'Magasin 3', 'material_status' => 'completed', 'registration_date' => '2024-05-01',
    'specific_material' => 'photograph', 'dimensions' => '18x24 cm', 'color_mode' => 'bw',
    'photographer' => 'Holger Damgaard', 'publisher' => 'Forlaget', 'collection_name' => 'Billedsamlingen',
    'local_classification' => '33.1',
];
$danAuth = [
    ['type' => 'person', 'authorised_form' => 'Stauning, Thorvald', 'dates_of_existence' => '1873-1942', 'role' => 'creator'],
    ['type' => 'corporate', 'authorised_form' => 'Socialdemokratiet', 'dates_of_existence' => '', 'role' => 'subject'],
];
$danXml = $collection(static fn(XMLWriter $xw) => $call('writeArchivalUnitDanmarcRecord', $xw, $danRow, $danAuth));
$check(
    hash('sha256', $danXml) === 'b785918115f88dc9a425284d834aa300e450b24b9b8fd343e17bd08166ecdcb4',
    'danMARC2 output is byte-identical to the pre-split writer'
);
$dx = $xpath($danXml);
$check($dx->query('//m:record[@type="Bibliographic"]')->length === 1, 'danMARC2 keeps type="Bibliographic"');
$check($dx->query('//m:leader | //m:controlfield')->length === 0, 'danMARC2 has no leader / controlfields');
$check($dx->query('//m:datafield[@tag="009"]')->length === 1 && $dx->query('//m:datafield[@tag="001"]/m:subfield[@code="a"]')->length === 1, 'danMARC2 keeps 001/009 as datafields');
$nsDan = new XMLWriter();
$nsDan->openMemory();
$call('writeArchivalUnitDanmarcRecord', $nsDan, $danRow, $danAuth, 'http://www.loc.gov/MARC21/slim');
$nsDoc = new DOMDocument();
$nsDoc->loadXML($nsDan->outputMemory());
$check(
    $nsDoc->documentElement !== null
        && $nsDoc->documentElement->namespaceURI === 'http://www.loc.gov/MARC21/slim'
        && $nsDoc->documentElement->getAttribute('type') === 'Bibliographic',
    'danMARC2 in an OAI/SRU envelope is qualified with the MARC Slim namespace'
);

// ── Fixture rows (rolled back at the end) ───────────────────────────────────
$db->begin_transaction();
$tag = 'E2EMARC21' . bin2hex(random_bytes(3));
try {
    $db->query("INSERT INTO archival_units (reference_code, institution_code, level, constructed_title, date_start, date_end, published)
                VALUES ('{$tag}-F', 'DK-ABA', 'fonds', 'Fondo di prova {$tag}', 1890, 1950, 1)");
    $fondsId = (int) $db->insert_id;
    $db->query("INSERT INTO archival_units
        (parent_id, reference_code, institution_code, level, formal_title, constructed_title, date_start, date_end,
         predominant_dates, date_gaps, extent, scope_content, arrangement_system, access_conditions, reproduction_rules,
         language_codes, finding_aids, originals_location, copies_location, related_units, archival_history,
         acquisition_source, physical_location, material_status, registration_date, specific_material, dimensions,
         color_mode, photographer, publisher, collection_name, local_classification, rights_statement_url,
         ark_identifier, appraisal, published)
        VALUES ({$fondsId}, '{$tag}-S', 'DK-ABA', 'series', 'Titolo formale', 'Serie di prova {$tag}', 1900, 1945,
         '1910-1920', '1915', '3 buste', 'Carteggio e verbali.', 'Cronologico', 'Libera consultazione', 'Citare la fonte',
         'ita;eng', 'Inventario 1990', 'Archivio di Stato', 'Microfilm', 'Fondo affine', 'Versato nel 1960.',
         'Donazione Rossi', 'Scaffale 3', 'completed', '2024-05-01', 'text', '30x40 cm',
         'bw', 'Mario Fotografo', 'Editore Srl', 'Collezione A', 'XII.4', 'https://rightsstatements.org/vocab/InC/1.0/',
         'ark:/12345/{$tag}', 'Scartati i duplicati.', 1)");
    $seriesId = (int) $db->insert_id;
    $db->query("INSERT INTO archival_units (parent_id, reference_code, institution_code, level, constructed_title, date_start, specific_material, published)
                VALUES ({$seriesId}, '{$tag}-I', 'DK-ABA', 'item', 'Fotografia {$tag}', 850, 'photograph', 0)");
    $itemId = (int) $db->insert_id;
    $db->query("INSERT INTO archival_unit_files (unit_id, file_path, file_mime, original_filename, sort_order)
                VALUES ({$seriesId}, '/uploads/archives/documents/{$tag}.pdf', 'application/pdf', 'verbale {$tag}.pdf', 1)");
    $fileId = (int) $db->insert_id;
    $db->query("INSERT INTO archival_unit_files (unit_id, file_path, file_mime, original_filename, sort_order)
                VALUES ({$itemId}, '/uploads/archives/documents/{$tag}-i.jpg', 'image/jpeg', 'foto.jpg', 1)");
    $db->query("INSERT INTO authority_records (type, authorised_form, dates_of_existence, history)
                VALUES ('person', 'Rossi, Mario {$tag}', '1880-1950', 'Sindacalista e archivista.')");
    $creatorId = (int) $db->insert_id;
    $db->query("INSERT INTO authority_records (type, authorised_form) VALUES ('corporate', 'Camera del lavoro {$tag}')");
    $subjectId = (int) $db->insert_id;
    $db->query("INSERT INTO authority_records (type, authorised_form) VALUES ('family', 'Famiglia Bianchi {$tag}')");
    $familyId = (int) $db->insert_id;
    $db->query("INSERT INTO archival_unit_authority (archival_unit_id, authority_id, role) VALUES
                ({$seriesId}, {$creatorId}, 'creator'), ({$seriesId}, {$subjectId}, 'subject'), ({$seriesId}, {$familyId}, 'custodian'),
                ({$itemId}, {$subjectId}, 'creator')");

    $findById = static fn(int $id): array => $call('findById', $id);
    $series = $findById($seriesId);
    $item = $findById($itemId);
    $seriesAuth = $plugin->fetchAuthoritiesForArchivalUnit($seriesId);
    $itemAuth = $plugin->fetchAuthoritiesForArchivalUnit($itemId);

    // ── 2. MARC21 validity ──────────────────────────────────────────────────
    echo "2. MARC21 record (series) — XSD + structure:\n";
    $marc = $collection(static function (XMLWriter $xw) use ($call, $series, $seriesAuth, $item, $itemAuth): void {
        $call('writeArchivalUnitMarc21Record', $xw, $series, $seriesAuth);
        $call('writeArchivalUnitMarc21Record', $xw, $item, $itemAuth);
    });
    [$code, $out] = $xmllint($marc);
    $check($code === 0, 'the MARC21 export validates against MARC21slim.xsd (xmllint or libxml)' . ($code === 0 ? '' : "\n" . $out));
    $check($call('validateMarcXmlSchema', $marc) === [], 'the importer\'s strict XSD gate accepts the MARC21 export');

    $x = $xpath($marc);
    $rec = '//m:record[1]';
    $leader = (string) $x->evaluate("string({$rec}/m:leader)");
    $check(strlen($leader) === 24, 'leader is 24 characters');
    $check($leader[5] === 'n' && $leader[6] === 'p' && $leader[7] === 'c' && $leader[9] === 'a', 'series: leader/05 n, /06 p (mixed), /07 c (collection), /09 a (UTF-8)');
    $check(substr($leader, 10, 2) === '22' && $leader[17] === ' ' && $leader[18] === 'i' && substr($leader, 20, 4) === '4500', 'leader/10-11 22, /17 blank, /18 i, /20-23 4500');
    $check($x->evaluate("string({$rec}/m:controlfield[@tag='001'])") === "{$tag}-S", '001 = reference code');
    $check($x->evaluate("string({$rec}/m:controlfield[@tag='003'])") === 'DK-ABA', '003 = institution code');
    $f008 = (string) $x->evaluate("string({$rec}/m:controlfield[@tag='008'])");
    $check(strlen($f008) === 40, '008 is 40 characters');
    $check(substr($f008, 0, 6) === '240501' && $f008[6] === 'i' && substr($f008, 7, 8) === '19001945', '008/00-05 entry date, /06 i, /07-14 1900 1945');
    $check(substr($f008, 15, 3) === 'xx ' && substr($f008, 35, 3) === 'ita' && substr($f008, 38, 2) === '  ', '008/15-17 xx, /35-37 ita, /38-39 blank');
    $check($x->query("{$rec}/m:datafield[@tag='041']/m:subfield[@code='a']")->length === 2, '041 lists both languages');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='040']/m:subfield[@code='a'])") === 'DK-ABA', '040 $a institution');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='100'][@ind1='1']/m:subfield[@code='a'])") === "Rossi, Mario {$tag}", '100 1_ creator (personal name, surname first)');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='245']/@ind1)") === '1' && $x->evaluate("string({$rec}/m:datafield[@tag='245']/@ind2)") === '0', '245 ind1 1 (1XX present), ind2 0');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='245']/m:subfield[@code='f'])") === '1900-1945', '245 $f inclusive dates');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='300']/m:subfield[@code='a'])") === '3 buste', '300 $a extent');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='351']/m:subfield[@code='c'])") === 'Series' && $x->evaluate("string({$rec}/m:datafield[@tag='351']/m:subfield[@code='b'])") === 'Cronologico', '351 $b arrangement, $c level');
    foreach (['506' => 'Libera consultazione', '520' => 'Carteggio e verbali.', '530' => 'Microfilm', '535' => 'Archivio di Stato', '540' => 'Citare la fonte', '541' => 'Donazione Rossi', '545' => 'Sindacalista e archivista.', '555' => 'Inventario 1990', '561' => 'Versato nel 1960.'] as $t => $v) {
        $check($x->evaluate("string({$rec}/m:datafield[@tag='{$t}']/m:subfield[1])") === $v, "{$t} carries '{$v}'");
    }
    $check($x->evaluate("string({$rec}/m:datafield[@tag='544']/m:subfield[@code='n'])") === 'Fondo affine', '544 related materials');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='524']/m:subfield[@code='a'])") !== '', '524 preferred citation');
    $check($x->query("{$rec}/m:datafield[@tag='583']")->length === 2, '583 processing + appraisal actions');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='610'][@ind1='2']/m:subfield[@code='a'])") === "Camera del lavoro {$tag}", '610 subject (corporate)');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='700'][@ind1='3']/m:subfield[@code='e'])") === 'custodian', '700 3_ family name with role');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='773']/m:subfield[@code='w'])") === "(DK-ABA){$tag}-F", '773 $w parent reference code');
    $check($x->evaluate("string({$rec}/m:datafield[@tag='856']/m:subfield[@code='u'])") === absoluteUrl("/archives/{$seriesId}/documents/{$fileId}"), '856 $u is the public document route');
    $check(!str_contains($marc, '/uploads/archives/documents/'), 'no direct /uploads link anywhere in the export');

    $irec = '//m:record[2]';
    $ileader = (string) $x->evaluate("string({$irec}/m:leader)");
    $check($ileader[6] === 'k' && $ileader[7] === 'd', 'photograph item: leader/06 k (image), /07 d (subunit)');
    $if008 = (string) $x->evaluate("string({$irec}/m:controlfield[@tag='008'])");
    $check($if008[6] === 's' && substr($if008, 7, 4) === '0850', 'single year 850 → 008/06 s, date1 0850');
    $check($x->evaluate("string({$irec}/m:datafield[@tag='110'][@ind1='2']/m:subfield[@code='a'])") === "Camera del lavoro {$tag}", '110 2_ corporate creator');
    $check($x->query("{$irec}/m:datafield[@tag='856']")->length === 0, 'unpublished unit: no 856 (its document route would 404)');
    $check($x->query("{$irec}/m:datafield[@tag='655']/m:subfield[.='Photographs']")->length === 1, '655 carries the specific material');

    // ── 3. MARC21 → import round trip ───────────────────────────────────────
    echo "3. Import round trip:\n";
    $parsed = $call('parseMarcXml', $marc);
    $records = $parsed['records'] ?? [];
    $check(count($records) === 2, 'importer reads both MARC21 records');
    $r = $records[0] ?? [];
    $check(($r['reference_code'] ?? null) === "{$tag}-S", 'reference code round-trips');
    $check(($r['institution_code'] ?? null) === 'DK-ABA', 'institution code round-trips');
    $check(($r['constructed_title'] ?? null) === "Serie di prova {$tag}", 'title round-trips');
    $check(($r['formal_title'] ?? null) === 'Titolo formale', 'formal title round-trips');
    $check(($r['level'] ?? null) === 'series', 'level round-trips');
    $check(($r['date_start'] ?? null) === 1900 && ($r['date_end'] ?? null) === 1945, 'dates round-trip');
    $check(($r['extent'] ?? null) === '3 buste', 'extent round-trips');
    $check(($r['scope_content'] ?? null) === 'Carteggio e verbali.', 'scope and content round-trips');
    $check(($r['language_codes'] ?? null) === 'ita,eng', 'languages round-trip');
    foreach (['archival_history' => 'Versato nel 1960.', 'acquisition_source' => 'Donazione Rossi', 'access_conditions' => 'Libera consultazione', 'reproduction_rules' => 'Citare la fonte', 'related_units' => 'Fondo affine', 'finding_aids' => 'Inventario 1990', 'dimensions' => '30x40 cm', 'color_mode' => 'bw', 'photographer' => 'Mario Fotografo', 'publisher' => 'Editore Srl', 'collection_name' => 'Collezione A', 'local_classification' => 'XII.4'] as $k => $v) {
        $check(($r[$k] ?? null) === $v, "{$k} round-trips");
    }
    $links = $r['authority_links'] ?? [];
    $creators = array_values(array_filter($links, static fn(array $l): bool => $l['role'] === 'creator'));
    $check(count($creators) === 1 && $creators[0]['authorised_form'] === "Rossi, Mario {$tag}" && $creators[0]['type'] === 'person', 'creator round-trips as a person');
    $check(in_array(['type' => 'family', 'authorised_form' => "Famiglia Bianchi {$tag}", 'dates_of_existence' => null, 'role' => 'custodian'], $links, true), 'family custodian round-trips');
    $r2 = $records[1] ?? [];
    $check(($r2['level'] ?? null) === 'item' && ($r2['specific_material'] ?? null) === 'photograph' && ($r2['date_start'] ?? null) === 850, 'item: level, material and year 850 round-trip');

    // ── 4. EAD3 structure ───────────────────────────────────────────────────
    echo "4. EAD3 structure:\n";
    $eadOf = static function (array $row, array $auth) use ($call): string {
        $xw = new XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');
        $call('writeEad3Document', $xw, $row, $auth);
        $xw->endDocument();
        return $xw->outputMemory();
    };
    $ead = $eadOf($series, $seriesAuth);
    $ex = $xpath($ead);
    $check($ex->evaluate('string(//e:control/e:maintenanceagency/e:agencycode)') === 'DK-ABA', 'agencycode is the institution ISIL, not PINAKES');
    $check($ex->query('//e:archdesc/e:did/e:origination/e:persname/e:part[.="Rossi, Mario ' . $tag . '"]')->length === 1, 'creator in archdesc/did/origination/persname/part');
    $check($ex->query('//e:controlaccess/*[@relator="creator"]')->length === 0, 'creator not repeated in controlaccess');
    $check($ex->query('//e:controlaccess/e:corpname/e:part')->length === 1 && $ex->query('//e:controlaccess/e:famname/e:part')->length === 1, 'other access points keep <part> children');
    $mixed = 0;
    foreach ($ex->query('//e:persname | //e:corpname | //e:famname') as $nameEl) {
        foreach ($nameEl->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
                $mixed++;
            }
        }
    }
    $check($mixed === 0, 'no mixed text inside persname/corpname/famname');
    $check($ex->query('//e:unitdatestructured//e:fromdate[@standarddate="1900"]')->length === 1 && $ex->query('//e:unitdatestructured//e:todate[@standarddate="1945"]')->length === 1, 'standarddate on a 1900-1945 range');
    $check($ex->evaluate('string(//e:did/e:unitdate)') === '1900-1945', 'human-readable unitdate kept');
    $check($ex->query('//e:dao[contains(@href, "/uploads/")]')->length === 0, 'no EAD3 dao links straight into /uploads');
    $check($ex->query('//e:daoset')->length === 0 && $ex->query('//e:did/e:dao')->length === 1, 'a single digital object is a bare <dao> (daoset needs two or more)');

    $old = $item;
    $old['date_start'] = 850;
    $old['date_end'] = null;
    $check($xpath($eadOf($old, []))->query('//e:datesingle[@standarddate="0850"]')->length === 1, 'year 850 → standarddate 0850');
    $neg = $item;
    $neg['date_start'] = -50;
    $neg['date_end'] = 12000;
    $nx = $xpath($eadOf($neg, []));
    $check($nx->query('//*[@standarddate]')->length === 0, 'years -50 and 12000 get no standarddate');
    $check($nx->query('//e:fromdate[.="-50"]')->length === 1 && $nx->evaluate('string(//e:did/e:unitdate)') === '-50-12000', 'out-of-range years keep their text');

    // ── 5. Dublin Core dc:type ──────────────────────────────────────────────
    echo "5. Dublin Core dc:type:\n";
    $dcTypes = static function (array $row) use ($call, $xpath): array {
        $xw = new XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');
        $call('writeDublinCoreRecord', $xw, $row, []);
        $xw->endDocument();
        $out = [];
        foreach ($xpath($xw->outputMemory())->query('//dc:type') as $n) {
            $out[] = $n->textContent;
        }
        return $out;
    };
    $check($dcTypes($series) === ['Collection', 'Text'], 'series of text → Collection + Text');
    $check($dcTypes($item) === ['StillImage'], 'photograph item → StillImage only (no Collection, no ucfirst level)');
    foreach (['audio' => 'Sound', 'video' => 'MovingImage', 'poster' => 'StillImage', 'other' => 'PhysicalObject'] as $mat => $type) {
        $row = $item;
        $row['specific_material'] = $mat;
        $check($dcTypes($row) === [$type], "{$mat} → {$type}");
    }
    $file = $series;
    $file['level'] = 'file';
    $check($dcTypes($file) === ['Text'], 'file level is not typed Collection');
} finally {
    $db->rollback();
}

// ── 6. SRU is rate limited (60 / 60 s per client) ────────────────────────────
echo "6. SRU rate limit:\n";
// Runs the real route stack in-process. The Apache vhost sets the E2E bypass
// flag, so this is the only place the limit can be observed; the CLI has no
// such flag. A TEST-NET-3 address keeps the counter away from real clients.
putenv('PINAKES_E2E_BYPASS_RATE_LIMIT');
unset($_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']);
$clientIp = '203.0.113.' . random_int(1, 254);
\App\Support\RateLimiter::reset($clientIp . ':archives_sru');
try {
    $app = \Slim\Factory\AppFactory::create();
    $app->addRoutingMiddleware();
    $plugin->registerRoutes($app);
    $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
    $statuses = [];
    for ($i = 1; $i <= 61; $i++) {
        $request = $factory->createServerRequest('GET', '/api/archives/sru?operation=explain', ['REMOTE_ADDR' => $clientIp])
            ->withQueryParams(['operation' => 'explain']);
        $statuses[] = $app->handle($request)->getStatusCode();
    }
    $check(count(array_filter(array_slice($statuses, 0, 60), static fn(int $c): bool => $c === 200)) === 60, 'the first 60 SRU requests are served');
    $check($statuses[60] === 429, 'the 61st SRU request within 60 s is refused with 429 (got ' . $statuses[60] . ')');
} finally {
    \App\Support\RateLimiter::reset($clientIp . ':archives_sru');
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
