<?php
declare(strict_types=1);

/**
 * A desiderata is a book the library WANTS, not one it holds. The web catalogue
 * hides it; this suite covers the surfaces that speak to someone else's software
 * — the mobile app and the interoperability protocols — because that is where a
 * wish quietly becomes a holding in another catalogue's eyes.
 *
 * Every check drives the real class or the real HTTP endpoint. A test that only
 * grepped the sources for the filter would pass on a filter placed inside a
 * single-quoted string, which ships as literal text into the SQL.
 *
 * Run:  php tests/desiderata-visibility.integration.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

if (!\App\Support\BookVisibility::hasDesiderata($db)) {
    echo "SKIP: libri.is_desiderata is absent — activate the desiderata plugin first\n";
    exit(0);
}

$token = bin2hex(random_bytes(4));
$wantedTitle = "ZZVIS wanted {$token}";
$heldTitle = "ZZVIS held {$token}";

$db->query("INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, search_index) VALUES ('{$wantedTitle}', 1, 0, 0, '{$wantedTitle}')");
$wantedId = (int) $db->insert_id;
$db->query("INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, search_index) VALUES ('{$heldTitle}', 0, 1, 1, '{$heldTitle}')");
$heldId = (int) $db->insert_id;

/** Call a private/protected method on an instance without changing its visibility. */
$callPrivate = static function (object $object, string $method, array $args = []): mixed {
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($object, $args);
};

try {
    echo "A. The mobile app is the same public catalogue\n";
    require_once $root . '/storage/plugins/mobile-api/src/Controllers/CatalogController.php';
    $mobileClass = 'App\\Plugins\\MobileApi\\Controllers\\CatalogController';
    if (class_exists($mobileClass)) {
        $mobile = (new ReflectionClass($mobileClass))->newInstanceWithoutConstructor();
        $dbProp = new ReflectionProperty($mobileClass, 'db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($mobile, $db);
        $core = $callPrivate($mobile, 'fetchBookCore', [$wantedId]);
        $check($core === null || $core === [] || $core === false, 'the app cannot open a requested book by id');
        $held = $callPrivate($mobile, 'fetchBookCore', [$heldId]);
        $check(is_array($held) && $held !== [], 'a real holding is still served to the app');
    } else {
        $check(false, "mobile-api CatalogController class not found ({$mobileClass})");
    }

    echo "B. Harvesting and search protocols\n";
    // OAI-PMH and SRU answer over HTTP on the dev server; drive them as a
    // harvester would rather than trusting the query text.
    $base = getenv('E2E_BASE_URL') ?: 'http://localhost:8081';
    $http = static function (string $url): string {
        $context = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        return (string) @file_get_contents($url, false, $context);
    };

    $oai = $http($base . '/oai?verb=ListRecords&metadataPrefix=oai_dc');
    if ($oai !== '' && !str_contains($oai, 'badVerb')) {
        $check(!str_contains($oai, $wantedTitle), 'OAI-PMH ListRecords does not harvest a requested book');
        $check(str_contains($oai, $heldTitle) || str_contains($oai, 'resumptionToken'), 'OAI-PMH still harvests the collection');
    } else {
        echo "     NOTE: /oai unreachable, skipping the harvesting checks\n";
    }

    $sru = $http($base . '/api/sru?operation=searchRetrieve&version=1.2&query=' . rawurlencode("dc.title=ZZVIS"));
    if ($sru !== '') {
        $check(!str_contains($sru, $wantedTitle), 'SRU searchRetrieve does not return a requested book');
        $check(str_contains($sru, $heldTitle), 'SRU searchRetrieve still returns the holding');
    } else {
        echo "     NOTE: /api/sru unreachable, skipping the SRU checks\n";
    }

    echo "C. Resolvers and item lookups\n";
    $plugins = [
        ['openurl-resolver/OpenUrlResolverPlugin.php', 'App\\Plugins\\OpenUrlResolver\\OpenUrlResolverPlugin', 'fetchBook', 'an OpenURL cannot resolve to a requested book'],
        ['bibframe-linked-data/BibframeLinkedDataPlugin.php', 'App\\Plugins\\BibframeLinkedData\\BibframeLinkedDataPlugin', 'fetchBook', 'no linked-data description is published for a requested book'],
        ['ncip-server/NcipServerPlugin.php', 'App\\Plugins\\NcipServer\\NcipServerPlugin', 'fetchBook', 'NCIP treats a requested book as an unknown item'],
    ];
    foreach ($plugins as [$file, $class, $method, $label]) {
        $path = $root . '/storage/plugins/' . $file;
        if (!is_file($path)) {
            $check(false, "{$class}: file missing");
            continue;
        }
        require_once $path;
        if (!class_exists($class) || !method_exists($class, $method)) {
            $check(false, "{$class}::{$method}() not found");
            continue;
        }
        $instance = new $class($db, new \App\Support\HookManager($db));
        $wantedRow = $callPrivate($instance, $method, [$wantedId]);
        $heldRow = $callPrivate($instance, $method, [$heldId]);
        $check(empty($wantedRow), $label);
        $check(!empty($heldRow), substr(strrchr($class, "\\") ?: $class, 1) . " still serves a real holding");
    }

    echo "D. Plugin lifecycle\n";
    require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';
    $hooks = new \App\Support\HookManager($db);
    $plugin = new DesiderataPlugin($db, $hooks);

    $before = $db->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'")->num_rows;
    $plugin->ensureSchema();
    $plugin->ensureSchema();
    $after = $db->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'")->num_rows;
    $check($before === 1 && $after === 1, 'ensureSchema() is idempotent: a second run changes nothing');
    $check($db->query("SHOW TABLES LIKE 'desiderata_offers'")->num_rows === 1, 'the offers table survives a repeated ensureSchema()');

    // Uninstalling keeps the records but must not leave them invisible: the flag
    // hides a book for as long as the column exists, and once the plugin is gone
    // there is no checkbox left to clear it with.
    $others = [];
    $rows = $db->query('SELECT id FROM libri WHERE is_desiderata = 1 AND id <> ' . $wantedId);
    while ($row = $rows->fetch_assoc()) {
        $others[] = (int) $row['id'];
    }
    $plugin->onUninstall();
    $stillFlagged = (int) $db->query('SELECT COUNT(*) c FROM libri WHERE id = ' . $wantedId . ' AND is_desiderata = 1')->fetch_assoc()['c'];
    $check($stillFlagged === 0, 'onUninstall() clears the flag so no record stays hidden for good');
    $check((int) $db->query('SELECT COUNT(*) c FROM libri WHERE id = ' . $wantedId)->fetch_assoc()['c'] === 1, 'the book itself is preserved');
    foreach ($others as $id) {
        $db->query('UPDATE libri SET is_desiderata = 1 WHERE id = ' . $id);
    }
} finally {
    $db->query('DELETE FROM copie WHERE libro_id IN (' . $wantedId . ', ' . $heldId . ')');
    $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (" . $wantedId . ', ' . $heldId . ')');
    $db->query('DELETE FROM libri WHERE id IN (' . $wantedId . ', ' . $heldId . ')');
    $db->close();
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
