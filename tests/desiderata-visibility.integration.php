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
 * WHERE EACH SECTION RUNS
 *   A, B, B2, C, C2 run against the INSTALLATION's database. They are
 *   read-mostly and scoped to the handful of prefixed rows the suite inserts
 *   and deletes itself.
 *
 *   D does NOT. It exercises the plugin lifecycle, and onUninstall() clears
 *   is_desiderata across the WHOLE table by design — it cannot be scoped by
 *   the caller. It used to run here, snapshotting the other flagged ids and
 *   hand-restoring them afterwards: a compensating transaction with no
 *   durability, so a crash, a fatal or a Ctrl-C between the two lost real
 *   operator state for good. It now runs in a disposable sandbox database of
 *   its own ($DB_NAME . '_desiderata', or DESIDERATA_SANDBOX_DB), refused by
 *   name when it is the installation's, following tests/emeroteca-schema-140
 *   .unit.php. Like that suite it FAILS HARD rather than skipping when the
 *   sandbox is unreachable: a skip is not a pass.
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
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

// Build the schema instead of skipping on it. The condition that used to
// trigger the skip — libri.is_desiderata missing — is precisely the bug this
// suite exists to catch, so exiting 0 with a message reported success while
// executing none of the assertions below. ensureSchema() is idempotent, and
// section D re-runs it to prove exactly that.
//
// Order matters: BookVisibility::hasDesiderata() memoizes per connection, so
// the column must exist before anything asks.
require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';
try {
    (new DesiderataPlugin($db, new \App\Support\HookManager($db)))->ensureSchema();
} catch (\Throwable $e) {
    // A genuinely unusable database is the only remaining reason not to run,
    // and under CI it is a failure, never a pass.
    fwrite(STDERR, "SKIP: cannot prepare the desiderata schema: {$e->getMessage()}\n");
    exit(getenv('CI_STRICT_TESTS') === '1' ? 1 : 0);
}
if (!\App\Support\BookVisibility::hasDesiderata($db)) {
    fwrite(STDERR, "SKIP: libri.is_desiderata is still absent after ensureSchema()\n");
    exit(getenv('CI_STRICT_TESTS') === '1' ? 1 : 0);
}

$token = bin2hex(random_bytes(4));
$wantedTitle = "ZZVIS wanted {$token}";
$heldTitle = "ZZVIS held {$token}";

$db->query("INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, search_index) VALUES ('{$wantedTitle}', 1, 0, 0, '{$wantedTitle}')");
$wantedId = (int) $db->insert_id;
$db->query("INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, search_index) VALUES ('{$heldTitle}', 0, 1, 1, '{$heldTitle}')");
$heldId = (int) $db->insert_id;
// Section B2 inserts a third row; declared here so the finally can always
// clean up, even when the section never reaches its INSERT.
$transitionId = 0;

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

    echo "B2. A withdrawn holding is REPORTED as deleted, not silently dropped\n";
    // The sections above only prove that a book flagged from birth never
    // appears, which was already true before the filters and stays true after
    // them. The bug is the TRANSITION: a book that was harvested and is later
    // flagged as a request stops being published, and a harvester that hears
    // nothing keeps its stale copy forever. Both protocols must say "deleted".
    //
    // The flag is flipped with a direct UPDATE, exactly as section C2 does for
    // the receipt path: what matters here is the transition of the column the
    // protocols read, and updated_at bumps by itself (ON UPDATE
    // CURRENT_TIMESTAMP), which is what dates the de-listing.
    $transitionTitle = "ZZVIS transition {$token}";
    $db->query("INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, search_index) VALUES ('{$transitionTitle}', 0, 1, 1, '{$transitionTitle}')");
    $transitionId = (int) $db->insert_id;
    // Datestamp a harvester would hold after taking the record: everything the
    // incremental window below returns happened at or after this instant.
    $mark = (string) $db->query('SELECT NOW() AS n')->fetch_assoc()['n'];
    $db->query('UPDATE libri SET is_desiderata = 1 WHERE id = ' . $transitionId);

    require_once $root . '/storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php';
    $oaiClass = 'App\\Plugins\\OaiPmhServer\\OaiPmhServerPlugin';
    $oaiPlugin = new $oaiClass($db, new \App\Support\HookManager($db));

    // GetRecord: a deleted header, never idDoesNotExist.
    $resolved = $callPrivate($oaiPlugin, 'resolveIdentifier', ["oai:pinakes:book:{$transitionId}", 'pinakes']);
    $check(
        is_array($resolved) && ($resolved['_status'] ?? '') === 'deleted'
            && (int) ($resolved['entity_id'] ?? 0) === $transitionId,
        'OAI-PMH GetRecord answers a deleted header for a withdrawn holding'
    );
    $stillHeld = $callPrivate($oaiPlugin, 'resolveIdentifier', ["oai:pinakes:book:{$heldId}", 'pinakes']);
    $check(
        is_array($stillHeld) && ($stillHeld['_status'] ?? '') === 'active',
        'OAI-PMH GetRecord still serves a real holding as active'
    );

    // ListRecords: the tombstone arm shares the page with the active arm, so
    // it needs the plugin's own deletion table to be present.
    if ($db->query("SHOW TABLES LIKE 'oai_deleted_records'")->num_rows === 1) {
        /** @var list<array<string,mixed>> $page */
        $page = $callPrivate($oaiPlugin, 'fetchRecordsPage', ['books', $mark, null, 0, 200, 'oai_dc']);
        $deletedHere = array_filter(
            $page,
            static fn (array $r): bool => ($r['_status'] ?? '') === 'deleted'
                && (int) ($r['entity_id'] ?? 0) === $transitionId
        );
        $activeHere = array_filter(
            $page,
            static fn (array $r): bool => ($r['_status'] ?? '') === 'active'
                && (int) ($r['id'] ?? 0) === $transitionId
        );
        $check(count($deletedHere) === 1, 'an incremental ListRecords carries exactly one deleted header for it');
        $check($activeHere === [], 'the same identifier is not ALSO listed as active in that response');

        // Un-flagging self-heals: updated_at bumps again, so the record comes
        // back as active and the tombstone arm stops matching it.
        $db->query('UPDATE libri SET is_desiderata = 0 WHERE id = ' . $transitionId);
        /** @var list<array<string,mixed>> $back */
        $back = $callPrivate($oaiPlugin, 'fetchRecordsPage', ['books', $mark, null, 0, 200, 'oai_dc']);
        $backActive = array_filter(
            $back,
            static fn (array $r): bool => ($r['_status'] ?? '') === 'active'
                && (int) ($r['id'] ?? 0) === $transitionId
        );
        $backDeleted = array_filter(
            $back,
            static fn (array $r): bool => ($r['_status'] ?? '') === 'deleted'
                && (int) ($r['entity_id'] ?? 0) === $transitionId
        );
        $check(count($backActive) === 1 && $backDeleted === [], 'a received donation brings the record back exactly once, with no lingering tombstone');
        $db->query('UPDATE libri SET is_desiderata = 1 WHERE id = ' . $transitionId);
    } else {
        echo "     NOTE: oai_deleted_records absent (plugin never installed), skipping the ListRecords checks\n";
    }

    // ResourceSync: the change list must report the withdrawal, and the
    // resource list must not carry the record at all.
    require_once $root . '/storage/plugins/resource-sync/ResourceSyncPlugin.php';
    $rsClass = 'App\\Plugins\\ResourceSync\\ResourceSyncPlugin';
    if (class_exists($rsClass)) {
        $rs = new $rsClass($db, new \App\Support\HookManager($db));
        $sinceDay = date('Y-m-d');
        /** @var list<array<string,mixed>> $changed */
        $changed = $callPrivate($rs, 'fetchChangedBooks', [$sinceDay, 0]);
        $row = null;
        foreach ($changed as $candidate) {
            if ((int) $candidate['id'] === $transitionId) { $row = $candidate; break; }
        }
        $check(
            is_array($row) && !empty($row['is_delisted']),
            'the ResourceSync change list still reaches a withdrawn holding, flagged as de-listed'
        );

        /** @var list<array<string,mixed>> $listed */
        $listed = $callPrivate($rs, 'fetchBooks', [0]);
        $inList = array_filter($listed, static fn (array $r): bool => (int) $r['id'] === $transitionId);
        $check($inList === [], 'the ResourceSync resource list does NOT carry it');

        if (is_array($row)) {
            $xml = $callPrivate($rs, 'buildChangeList', ['http://example.invalid', [$row], $sinceDay, 0]);
            $check(
                is_string($xml) && str_contains($xml, 'change="deleted"'),
                'the change list entry says change="deleted"'
            );
        } else {
            $check(false, 'no change-list row to render for the withdrawn holding');
        }

        // The path ResourceSync used to lose entirely: a flagged book that is
        // then soft-deleted produced no deletion event at all, because the
        // visibility filter was ANDed over the tombstone arm too.
        $db->query('UPDATE libri SET deleted_at = NOW() WHERE id = ' . $transitionId);
        /** @var list<array<string,mixed>> $afterDelete */
        $afterDelete = $callPrivate($rs, 'fetchChangedBooks', [$sinceDay, 0]);
        $tomb = array_filter($afterDelete, static fn (array $r): bool => (int) $r['id'] === $transitionId);
        $check($tomb !== [], 'soft-deleting a still-flagged book is reported as a deletion');
        $db->query('UPDATE libri SET deleted_at = NULL WHERE id = ' . $transitionId);
    } else {
        $check(false, 'resource-sync plugin class not found');
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

    echo "C2. Favourites are HIDDEN, never deleted\n";
    // The receipt path clears the flag, so a favourite on a wanted title must
    // come back by itself once the donation arrives. Deleting wishlist rows
    // would destroy user data and break that round trip, so the last assertion
    // here is the one that pins the fix as a read filter rather than a purge.
    // Own reader, so the suite does not depend on a seeded account: a fresh CI
    // database has no rows in utenti at all.
    $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password)
                VALUES ('ZZVIS{$token}', 'ZZVIS', 'Reader', 'zzvis-{$token}@example.invalid', 'x')");
    $probeUserId = (int) $db->insert_id;
    if ($probeUserId > 0) {
        $userId = $probeUserId;
        $db->query('DELETE FROM wishlist WHERE utente_id = ' . $userId . ' AND libro_id IN (' . $wantedId . ', ' . $heldId . ')');
        $db->query('INSERT INTO wishlist (utente_id, libro_id) VALUES (' . $userId . ', ' . $wantedId . '), (' . $userId . ', ' . $heldId . ')');

        $wishlist = new \App\Controllers\UserWishlistController();
        $status = static function (int $bookId) use ($wishlist, $db, $userId): bool {
            $_SESSION = ['user' => ['id' => $userId]];
            $request = (new Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', '/api/wishlist/status')
                ->withQueryParams(['libro_id' => (string) $bookId]);
            $response = $wishlist->status($request, new Slim\Psr7\Response(), $db);
            $_SESSION = [];
            return (bool) (json_decode((string) $response->getBody(), true)['favorite'] ?? false);
        };
        $listed = static function (int $bookId) use ($db, $userId): bool {
            $sql = 'SELECT 1 FROM wishlist w JOIN libri l ON l.id = w.libro_id
                    WHERE w.utente_id = ' . $userId . ' AND w.libro_id = ' . $bookId . '
                      AND l.deleted_at IS NULL AND ' . \App\Support\BookVisibility::catalogue($db, 'l');
            return $db->query($sql)->num_rows > 0;
        };

        $check($status($wantedId) === false, 'the favourite status endpoint does not confirm a requested book');
        $check($status($heldId) === true, 'a favourite on a real holding is still confirmed');
        $check($listed($wantedId) === false, 'the favourites list hides the requested book');
        $check($listed($heldId) === true, 'the favourites list still shows the holding');

        $stillThere = (int) $db->query('SELECT COUNT(*) c FROM wishlist WHERE utente_id = ' . $userId . ' AND libro_id = ' . $wantedId)->fetch_assoc()['c'];
        $check($stillThere === 1, 'the wishlist row itself is never deleted, only hidden');

        // What the receipt does: clear the flag. The favourite must reappear.
        $db->query('UPDATE libri SET is_desiderata = 0 WHERE id = ' . $wantedId);
        $check($status($wantedId) === true && $listed($wantedId) === true, 'clearing the flag brings the favourite back, list and status together');
        $db->query('UPDATE libri SET is_desiderata = 1 WHERE id = ' . $wantedId);
    } else {
        $check(false, 'could not create the probe reader for the favourites round trip');
    }

    echo "D. Plugin lifecycle (SANDBOX database)\n";
    // onUninstall() is table-wide by design — it clears is_desiderata on every
    // row, because once the plugin is gone there is no checkbox left to clear a
    // hidden record with. There is no way to scope that to the suite's own row,
    // so it runs on a table nobody else owns.
    require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';

    // Every failure below THROWS rather than exiting: exit() skips finally, and
    // the suite's own rows are sitting in the live catalogue at this point.
    $sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
        throw new \RuntimeException("invalid sandbox database name '{$sandboxName}'");
    }
    if (strcasecmp($sandboxName, (string) $dbName) === 0) {
        // One environment variable pointed at the installation reproduces the
        // very destruction this section was moved out to avoid, with the guard
        // agreeing, because DATABASE() would indeed be this name.
        fwrite(STDERR, <<<TXT
            FAIL: DESIDERATA_SANDBOX_DB is set to '{$sandboxName}', which is the installation's own database.
                  Section D runs onUninstall(), which clears is_desiderata on EVERY row, so it must never point there.
                  Leave it unset to use '{$dbName}_desiderata', or name a disposable database.

            TXT);
        throw new \RuntimeException('the sandbox database is the installation\'s own');
    }
    // Created when the account may (CI runs as root); otherwise it has to exist
    // already — say exactly what to do rather than skipping the lifecycle.
    // Both statements are caught, not suppressed: this suite runs with
    // MYSQLI_REPORT_STRICT, so an unprivileged account throws here instead of
    // returning false, and only the probe below is allowed to decide.
    $probe = null;
    try {
        $db->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (\Throwable) {
        // Not fatal by itself: the database may already exist and simply not be
        // creatable by this account.
    }
    try {
        $probe = $db->query(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $db->real_escape_string($sandboxName) . "'"
        );
    } catch (\Throwable) {
        $probe = null;
    }
    if (!($probe instanceof \mysqli_result) || $probe->num_rows !== 1) {
        fwrite(STDERR, <<<TXT
            FAIL: the sandbox database '{$sandboxName}' does not exist and this account cannot create it.
                  Section D rewrites is_desiderata across a whole table, so it needs a database of its own.
                  Create it once, as an administrator:
                    CREATE DATABASE `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                    GRANT ALL PRIVILEGES ON `{$sandboxName}`.* TO '{$dbUser}'@'localhost';
                  Or point DESIDERATA_SANDBOX_DB at a disposable database you already own.

            TXT);
        throw new \RuntimeException("the sandbox database '{$sandboxName}' is unreachable");
    }
    $probe->free();

    // A SECOND connection, so the installation handle keeps its own DATABASE()
    // and its own memoized BookVisibility probe.
    $sandbox = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $sandboxName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $sandboxName, (int) ($env['DB_PORT'] ?? 3306));
    $sandbox->set_charset('utf8mb4');

    try {
        // A minimal libri of the plugin's own making: no rows can reach the
        // catalogue from here. deleted_at is not optional — the plugin's
        // composite index is (is_desiderata, deleted_at), and without the column
        // the schema half of this section would silently stop being exercised.
        $sandbox->query('DROP TABLE IF EXISTS desiderata_offers');
        $sandbox->query('DROP TABLE IF EXISTS libri');
        $sandbox->query('CREATE TABLE libri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            titolo VARCHAR(255) NOT NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $sandboxPlugin = new DesiderataPlugin($sandbox, new \App\Support\HookManager($sandbox));

        $before = $sandbox->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'")->num_rows;
        $sandboxPlugin->ensureSchema();
        $middle = $sandbox->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'")->num_rows;
        $sandboxPlugin->ensureSchema();
        $after = $sandbox->query("SHOW COLUMNS FROM libri LIKE 'is\\_desiderata'")->num_rows;
        $check($before === 0 && $middle === 1 && $after === 1, 'ensureSchema() adds the column once and is idempotent: a second run changes nothing');
        $check($sandbox->query("SHOW TABLES LIKE 'desiderata_offers'")->num_rows === 1, 'the offers table survives a repeated ensureSchema()');
        $check(
            $sandbox->query("SHOW INDEX FROM libri WHERE Key_name = 'idx_desiderata'")->num_rows > 0,
            'the composite index is created alongside the column'
        );

        // TWO flagged rows, not one. onUninstall() is a table-wide UPDATE, and a
        // suite that can only observe its own row would keep passing if the
        // method ever regressed to a single-row update — leaving every OTHER
        // wanted title permanently hidden after a real uninstall, which is the
        // failure the method exists to prevent.
        $sandbox->query("INSERT INTO libri (titolo, is_desiderata) VALUES ('sandbox wanted A', 1), ('sandbox wanted B', 1), ('sandbox held', 0)");
        $flaggedBefore = (int) $sandbox->query('SELECT COUNT(*) c FROM libri WHERE is_desiderata = 1')->fetch_assoc()['c'];
        $check($flaggedBefore === 2, 'the sandbox starts with two flagged rows');

        // NOTE: onUninstall() also defers a catalogue cache invalidation through
        // a shutdown function. It is a generation bump on the installation's
        // cache, not a write, and it is the same one a real uninstall performs.
        $sandboxPlugin->onUninstall();

        $flaggedAfter = (int) $sandbox->query('SELECT COUNT(*) c FROM libri WHERE is_desiderata = 1')->fetch_assoc()['c'];
        $check($flaggedAfter === 0, 'onUninstall() clears the flag on EVERY row, so no record stays hidden for good');
        $check(
            (int) $sandbox->query('SELECT COUNT(*) c FROM libri')->fetch_assoc()['c'] === 3,
            'the books themselves are preserved'
        );
    } finally {
        $sandbox->close();
    }
} catch (\Throwable $fatal) {
    // Recorded, not rethrown: the cleanup below must run first, and the suite
    // has to report a non-zero exit rather than an uncaught-error stack trace.
    $fatalError = $fatal;
} finally {
    // Runs whatever happened, including a failure inside the sandbox setup:
    // the rows below live in the real catalogue, and the wanted one is flagged,
    // so a leftover would be invisible in the UI that could delete it.
    $ids = implode(', ', array_filter([$wantedId, $heldId, $transitionId]));
    if ($ids !== '') {
        $db->query('DELETE FROM wishlist WHERE libro_id IN (' . $ids . ')');
        $db->query("DELETE FROM utenti WHERE codice_tessera = 'ZZVIS{$token}'");
        $db->query('DELETE FROM copie WHERE libro_id IN (' . $ids . ')');
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (" . $ids . ')');
        $db->query('DELETE FROM libri WHERE id IN (' . $ids . ')');
    }
    $db->close();
}

if (isset($fatalError)) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
