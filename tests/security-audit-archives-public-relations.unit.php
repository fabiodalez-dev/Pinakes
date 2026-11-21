<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * storage/plugins/archives/ArchivesPlugin.php:fetchRelationsForEntity:unpublished-endpoint-in-public-ric).
 *
 * The anonymous RiC-O JSON-LD exports (/archives/{id}/ric.json,
 * /archives/agents/{id}/ric.json, /archives/activities/{id}/ric.json,
 * /archives/places/{id}/ric.json) appended every archive_relations row that
 * touched the entity, so a public agent document carried the IRI, predicate,
 * qualifier and source reference of a link to a unit with published = 0.
 * Public exports must emit a relation only when both endpoints are publicly
 * visible (unit: live and published; agent/activity/place: live).
 *
 * Drives the REAL query against MySQL: fetchPublicRelationsForEntity() and the
 * private attachRelationsToRicDoc() that every public RiC endpoint calls.
 * Tables are hardcoded in the plugin, so the five tables it needs are created
 * from the plugin's own DDL only when missing (and dropped again afterwards);
 * every seeded row carries a run sentinel and is deleted at the end, on
 * success and on failure.
 *
 * Run:  php tests/security-audit-archives-public-relations.unit.php
 * DB:   E2E_DB_NAME / E2E_DB_USER / E2E_DB_PASS / E2E_DB_SOCKET (or .env).
 *       Without a reachable DB it prints SKIP and exits 0 (a failure under
 *       CI_STRICT_TESTS=1).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/archives/ArchivesPlugin.php';

use App\Plugins\Archives\ArchivesPlugin;
use App\Support\HookManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$skip = static function (string $why): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: {$why}\n";
    exit(0);
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
if ($name === '' || $user === '') {
    $skip('no database configured (E2E_DB_NAME/E2E_DB_USER or .env)');
}
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $dbPass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}
$db->set_charset('utf8mb4');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$tableExists = static function (string $t) use ($db): bool {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'");
    return $r instanceof mysqli_result && $r->num_rows > 0;
};

$createdTables = [];
$ids = ['units' => [], 'authority' => [], 'activity' => [], 'place' => [], 'relations' => []];
$RUN = 'SECAUD-' . substr(md5(uniqid('', true)), 0, 8);

$cleanup = static function () use ($db, &$ids, &$createdTables): void {
    $del = static function (string $table, array $list) use ($db): void {
        if ($list !== []) {
            $db->query("DELETE FROM `{$table}` WHERE id IN (" . implode(',', array_map('intval', $list)) . ')');
        }
    };
    try {
        $del('archive_relations', $ids['relations']);
        $del('archive_places', $ids['place']);
        $del('archive_activities', $ids['activity']);
        $del('authority_records', $ids['authority']);
        $del('archival_units', $ids['units']);
    } catch (\Throwable $e) {
        echo "  WARN cleanup: " . $e->getMessage() . "\n";
    }
    foreach (array_reverse($createdTables) as $t) {
        try { $db->query("DROP TABLE IF EXISTS `{$t}`"); } catch (\Throwable) {}
    }
};

try {
    // ── Schema: only what the query touches, from the plugin's own DDL ──────
    foreach ([
        'archival_units'     => ArchivesPlugin::ddlArchivalUnits(),
        'authority_records'  => ArchivesPlugin::ddlAuthorityRecords(),
        'archive_activities' => ArchivesPlugin::ddlArchiveActivities(),
        'archive_places'     => ArchivesPlugin::ddlArchivePlaces(),
        'archive_relations'  => ArchivesPlugin::ddlArchiveRelations(),
    ] as $table => $ddl) {
        if (!$tableExists($table)) {
            $db->query($ddl);
            $createdTables[] = $table;
        }
    }

    $insertUnit = static function (string $ref, int $published, bool $deleted) use ($db, $RUN, &$ids): int {
        $stmt = $db->prepare(
            "INSERT INTO archival_units (reference_code, institution_code, level, constructed_title, published, deleted_at)
             VALUES (?, 'SECAUD', 'item', ?, ?, " . ($deleted ? 'NOW()' : 'NULL') . ')'
        );
        $code = $RUN . '-' . $ref;
        $title = 'Audit unit ' . $ref;
        $stmt->bind_param('ssi', $code, $title, $published);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $ids['units'][] = $id;
        return $id;
    };
    $insertSimple = static function (string $sql, string $label, string $bucket) use ($db, $RUN, &$ids): int {
        $stmt = $db->prepare($sql);
        $value = $RUN . ' ' . $label;
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $ids[$bucket][] = $id;
        return $id;
    };
    $relate = static function (string $st, int $sid, string $tt, int $tid, string $pred, string $note) use ($db, &$ids): int {
        $stmt = $db->prepare(
            'INSERT INTO archive_relations (source_type, source_id, target_type, target_id, ric_predicate, qualifier, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $src = $note . '-SRC';
        $stmt->bind_param('sisisss', $st, $sid, $tt, $tid, $pred, $note, $src);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $ids['relations'][] = $id;
        return $id;
    };

    $pub    = $insertUnit('PUB', 1, false);
    $hidden = $insertUnit('HIDDEN', 0, false);
    $gone   = $insertUnit('GONE', 1, true);
    $agent  = $insertSimple("INSERT INTO authority_records (type, authorised_form) VALUES ('person', ?)", 'agent', 'authority');
    $act    = $insertSimple("INSERT INTO archive_activities (title) VALUES (?)", 'activity', 'activity');
    $place  = $insertSimple("INSERT INTO archive_places (name) VALUES (?)", 'place', 'place');
    $deadPl = $insertSimple("INSERT INTO archive_places (name, deleted_at) VALUES (?, NOW())", 'dead place', 'place');

    $rPubAgent    = $relate('archival_unit', $pub, 'authority_record', $agent, 'ric:hasCreator', 'VISIBLE-NOTE');
    $rHiddenAgent = $relate('archival_unit', $hidden, 'authority_record', $agent, 'ric:hasCreator', 'HIDDEN-NOTE-A');
    $rGoneAgent   = $relate('archival_unit', $gone, 'authority_record', $agent, 'ric:hasCreator', 'GONE-NOTE');
    $rAgentDeadPl = $relate('authority_record', $agent, 'archive_place', $deadPl, 'ric:hasOrHadLocation', 'DEADPLACE-NOTE');
    $rActHidden   = $relate('archive_activity', $act, 'archival_unit', $hidden, 'ric:resultsOrResultedIn', 'HIDDEN-NOTE-T');
    $rPlaceHidden = $relate('archive_place', $place, 'archival_unit', $hidden, 'ric:isOrWasLocationOf', 'HIDDEN-NOTE-L');
    $rPubHidden   = $relate('archival_unit', $pub, 'archival_unit', $hidden, 'ric:isAssociatedWith', 'HIDDEN-NOTE-U');
    $rPubPlace    = $relate('archival_unit', $pub, 'archive_place', $place, 'ric:hasOrHadLocation', 'VISIBLE-PLACE');

    $plugin = new ArchivesPlugin($db, (new ReflectionClass(HookManager::class))->newInstanceWithoutConstructor());
    $relIds = static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows);

    echo "A. The admin fetch still returns everything (unchanged)\n";
    $all = $relIds($plugin->fetchRelationsForEntity('authority_record', $agent));
    sort($all);
    $expectAll = [$rPubAgent, $rHiddenAgent, $rGoneAgent, $rAgentDeadPl];
    sort($expectAll);
    $check($all === $expectAll, 'fetchRelationsForEntity(agent) lists all four rows');

    echo "B. The public RiC documents (attachRelationsToRicDoc) carry no trace of the hidden unit\n";
    $attach = new ReflectionMethod(ArchivesPlugin::class, 'attachRelationsToRicDoc');
    $attach->setAccessible(true);
    $builder = new \App\Plugins\Archives\RicJsonLdBuilder('https://audit.invalid', 'en');
    foreach ([
        ['authority_record', $agent, '/archives/agents/' . $agent],
        ['archive_activity', $act, '/archives/activities/' . $act],
        ['archive_place', $place, '/archives/places/' . $place],
        ['archival_unit', $pub, '/archives/' . $pub],
    ] as [$type, $id, $iri]) {
        $doc = ['@context' => $builder->context(), '@id' => 'https://audit.invalid' . $iri, '@type' => 'ric:Thing'];
        $json = (string) json_encode($attach->invoke($plugin, $doc, $type, $id, $builder), JSON_UNESCAPED_SLASHES);
        $check(!str_contains($json, '/archives/' . $hidden . '"'), "{$type}: no IRI of the unpublished unit");
        $check(!str_contains($json, 'HIDDEN-NOTE'), "{$type}: no qualifier of a hidden relation");
        $check(!str_contains($json, '/archives/' . $gone . '"') && !str_contains($json, 'GONE-NOTE'), "{$type}: no soft-deleted unit");
        $check(!str_contains($json, 'DEADPLACE-NOTE'), "{$type}: no soft-deleted place");
    }
    $agentJson = (string) json_encode($attach->invoke($plugin, ['@id' => 'x'], 'authority_record', $agent, $builder), JSON_UNESCAPED_SLASHES);
    $check(str_contains($agentJson, 'VISIBLE-NOTE') && str_contains($agentJson, '/archives/' . $pub . '"'), 'agent: the relation to the published unit is still exported');

    echo "C. The public fetch keeps only fully visible relations\n";
    $check($relIds($plugin->fetchPublicRelationsForEntity('authority_record', $agent)) === [$rPubAgent], 'agent: only the relation to the published unit');
    $check($relIds($plugin->fetchPublicRelationsForEntity('archive_activity', $act)) === [], 'activity: relation to the unpublished unit dropped');
    $check($relIds($plugin->fetchPublicRelationsForEntity('archive_place', $place)) === [$rPubPlace], 'place: relation to the unpublished unit dropped, published one kept');
    $pubRels = $relIds($plugin->fetchPublicRelationsForEntity('archival_unit', $pub));
    sort($pubRels);
    $expectPub = [$rPubAgent, $rPubPlace];
    sort($expectPub);
    $check($pubRels === $expectPub, 'published unit: unit-to-hidden-unit relation dropped');
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    $cleanup();
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
