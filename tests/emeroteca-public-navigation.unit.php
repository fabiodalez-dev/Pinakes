<?php
declare(strict_types=1);

/**
 * Navigating the Emeroteca as a reader: issue contents in reading order,
 * previous/next within an issue, the breadcrumb and "back" link of an article,
 * paged article search, the /emeroteca listing's robots/canonical policy and
 * the shared 404.
 *
 * Every row is created inside a transaction and rolled back; no seed id is
 * relied on and nothing persists. The views and the controller are driven for
 * real, and the assertions read the emitted markup.
 *
 * Run:  php tests/emeroteca-public-navigation.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require_once $root . '/storage/plugins/emeroteca/src/Services/ContributionService.php';
require_once $root . '/storage/plugins/emeroteca/src/Controllers/PublicController.php';

use App\Plugins\Emeroteca\Services\ContributionService;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

$controllerClass = 'App\\Plugins\\Emeroteca\\Controllers\\PublicController';
if (!class_exists($controllerClass)) {
    fwrite(STDERR, "FAIL: {$controllerClass} was not found after require.\n");
    exit(1);
}

/** @return list<string> every href in the markup */
$hrefs = static function (string $html): array {
    preg_match_all('/<a\b[^>]*\shref="([^"]*)"/i', $html, $m);
    return array_map(static fn(string $h): string => html_entity_decode($h, ENT_QUOTES), $m[1]);
};
$hasHrefEnding = static function (string $html, string $suffix) use ($hrefs): bool {
    foreach ($hrefs($html) as $h) {
        if (str_ends_with($h, $suffix)) {
            return true;
        }
    }
    return false;
};

$service = new ContributionService($db);
$db->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $insert = static function (string $sql) use ($db): int {
        $db->query($sql);
        return (int) $db->insert_id;
    };
    $logo = '/uploads/emeroteca/nav-logo-' . $suffix . '.jpg';
    $issueCover = '/uploads/emeroteca/nav-issue-' . $suffix . '.jpg';
    $own = '/uploads/emeroteca/nav-own-' . $suffix . '.jpg';

    $testata = $insert("INSERT INTO emeroteca_testate (titolo, logo_url) VALUES ('zz Nav Testata {$suffix}', '{$logo}')");
    $annata = $insert("INSERT INTO emeroteca_annate (testata_id, anno, volume) VALUES ({$testata}, 2001, '')");
    $fascA = $insert("INSERT INTO emeroteca_fascicoli (annata_id, numero, copertina_url) VALUES ({$annata}, '7', '{$issueCover}')");
    $fascB = $insert("INSERT INTO emeroteca_fascicoli (annata_id, numero) VALUES ({$annata}, '8')");

    $article = static function (string $tag, ?int $testataId, ?int $fascId, ?string $pagine, int $public = 1, ?string $cover = null) use ($insert, $db, $suffix): int {
        $t = $testataId === null ? 'NULL' : (string) $testataId;
        $f = $fascId === null ? 'NULL' : (string) $fascId;
        $p = $pagine === null ? 'NULL' : "'" . $db->real_escape_string($pagine) . "'";
        $c = $cover === null ? 'NULL' : "'" . $db->real_escape_string($cover) . "'";
        return $insert(
            "INSERT INTO emeroteca_contributi (reference_key, titolo, testata_id, fascicolo_id, pagine, pubblico, copertina_url)
             VALUES ('zz-nav-{$tag}-{$suffix}', 'zz nav {$tag} {$suffix}', {$t}, {$f}, {$p}, {$public}, {$c})"
        );
    };
    // Inserted out of reading order on purpose: ids must not decide the order.
    $a45   = $article('p45', $testata, $fascA, '45-60');
    $a3    = $article('p3', $testata, $fascA, 'pp. 3-20', 1, $own);
    $a12   = $article('p12', $testata, $fascA, '12');
    $aNone = $article('pnull', $testata, $fascA, null);
    $aDraft = $article('draft', $testata, $fascA, '1', 0);
    $aMast = $article('mast', $testata, null, null);
    $aBare = $article('bare', null, null, null);

    echo "A. The cover resolver\n";

    $r = ['copertina_url' => $own, 'fascicolo_copertina_url' => $issueCover, 'testata_logo_url' => $logo];
    $check(ContributionService::coverUrl($r) === $own, 'own cover wins');
    $check(ContributionService::coverUrl(['copertina_url' => null] + $r) === $issueCover, 'then the issue cover');
    $check(ContributionService::coverUrl(['copertina_url' => '  ', 'fascicolo_copertina_url' => ''] + $r) === $logo,
        'then the masthead logo (empty and whitespace-only values are skipped)');
    $check(ContributionService::coverUrl(['testata_logo_url' => '']) === '', 'and nothing at all is the empty string');
    $check(ContributionService::coverUrl(['fascicolo_copertina_url' => '0', 'testata_logo_url' => $logo]) === '0',
        'the string "0" is a real value at every level');

    echo "\nB. Where the read paths place an article\n";

    $got = $service->get($a45, true);
    $check(is_array($got), 'get() returns the issue-placed article');
    $check(is_array($got) && (string) $got['fascicolo_numero'] === '7' && (int) $got['fascicolo_anno'] === 2001,
        'get() exposes fascicolo_numero and fascicolo_anno');
    $check(is_array($got) && (int) $got['fascicolo_annata_id'] === $annata && $got['fascicolo_copertina_url'] === $issueCover
        && $got['testata_titolo'] === "zz Nav Testata {$suffix}" && $got['testata_logo_url'] === $logo,
        'and the issue cover, annata and masthead columns');
    $check(is_array($got) && ContributionService::coverUrl($got) === $issueCover, 'an article with no cover of its own gets its issue cover, not the logo');
    $mast = $service->get($aMast, true);
    $check(is_array($mast) && $mast['fascicolo_numero'] === null && ContributionService::coverUrl($mast) === $logo,
        'a masthead-only article has no issue columns and falls to the logo');
    $bare = $service->get($aBare, true);
    $check(is_array($bare) && $bare['testata_titolo'] === null && ContributionService::coverUrl($bare) === '',
        'an article placed nowhere is still answered, with no cover');
    $check($service->get($aDraft, true) === null, 'get() hides an unpublished article from the public');

    echo "\nC. Issue contents in reading order\n";

    $contents = $service->issueContents($fascA);
    $order = array_map(static fn(array $r): int => (int) $r['id'], $contents);
    $check($order === [$a3, $a12, $a45, $aNone], 'order is 3-20, 12, 45-60, then unnumbered (got ' . implode(',', $order) . ')');
    $check(!in_array($aDraft, $order, true), 'the unpublished article is excluded');
    $check($service->issueContents($fascB) === [] && $service->issueContents(0) === [], 'an empty issue and a zero id give an empty list');
    $check(ContributionService::firstPage(['pagine' => 'pp. 3-20']) === 3 && ContributionService::firstPage(['pagine' => null]) === PHP_INT_MAX,
        'firstPage reads the first number and ranks unnumbered last');

    $tie1 = $article('tie1', $testata, $fascB, '5');
    $tie2 = $article('tie2', $testata, $fascB, '5');
    $tieOrder = array_map(static fn(array $r): int => (int) $r['id'], $service->issueContents($fascB));
    $check($tieOrder === [$tie1, $tie2], 'equal first pages fall back to id');

    echo "\nD. Neighbours inside the issue\n";

    $n12 = $service->neighboursInIssue(['id' => $a12, 'fascicolo_id' => $fascA]);
    $check((int) ($n12['prev']['id'] ?? 0) === $a3 && (int) ($n12['next']['id'] ?? 0) === $a45, 'the middle article sits between its neighbours');
    $n45 = $service->neighboursInIssue(['id' => $a45, 'fascicolo_id' => $fascA]);
    $check((int) ($n45['prev']['id'] ?? 0) === $a12 && (int) ($n45['next']['id'] ?? 0) === $aNone, 'the next-to-last has the unnumbered one after it');
    $nFirst = $service->neighboursInIssue(['id' => $a3, 'fascicolo_id' => $fascA]);
    $check($nFirst['prev'] === null && (int) $nFirst['next']['id'] === $a12, 'the first has no previous');
    $nLast = $service->neighboursInIssue(['id' => $aNone, 'fascicolo_id' => $fascA]);
    $check($nLast['next'] === null && (int) $nLast['prev']['id'] === $a45, 'the last has no next');
    $nBare = $service->neighboursInIssue(['id' => $aBare, 'fascicolo_id' => null]);
    $check($nBare === ['prev' => null, 'next' => null], 'an article with no issue has no neighbours');

    $rel = $service->relatedInTestata($testata, $a45, 10);
    $relIds = array_map(static fn(array $r): int => (int) $r['id'], $rel);
    $check(!in_array($a45, $relIds, true) && !in_array($aDraft, $relIds, true) && in_array($a3, $relIds, true) && in_array($aMast, $relIds, true),
        'relatedInTestata excludes the article itself and unpublished ones, includes siblings');
    $check($service->relatedInTestata(0, 1) === [], 'relatedInTestata with no masthead is empty');

    echo "\nE. Paged search within an issue\n";

    $p1 = $service->search('', 0, true, 1, [], $fascA, 2);
    $check($p1['total'] === 4 && $p1['pages'] === 2 && count($p1['rows']) === 2 && $p1['page'] === 1,
        "page 1: total 4, 2 pages, 2 rows (got total={$p1['total']} pages={$p1['pages']} rows=" . count($p1['rows']) . ')');
    $p2 = $service->search('', 0, true, 2, [], $fascA, 2);
    $check($p2['total'] === 4 && count($p2['rows']) === 2 && $p2['page'] === 2, 'page 2 has the remaining 2 rows');
    $ids = array_merge(array_column($p1['rows'], 'id'), array_column($p2['rows'], 'id'));
    $check(count(array_unique($ids)) === 4 && !in_array((string) $aDraft, array_map('strval', $ids), true), 'no overlap between pages and no unpublished row');
    $p9 = $service->search('', 0, true, 99, [], $fascA, 2);
    $check($p9['page'] === 2, 'a page past the end is clamped to the last');
    $check($service->search('', 0, false, 1, [], $fascA, 2)['total'] === 5, 'the admin view (public=false) also counts the unpublished article');

    echo "\nF. The article page\n";

    $views = $root . '/storage/plugins/emeroteca/src/Views/public/';
    $renderArticle = static function (array $row, array $extra = []) use ($views): string {
        $article = $row;
        $neighbours = $extra['neighbours'] ?? ['prev' => null, 'next' => null];
        ob_start();
        require $views . 'article.php';
        return (string) ob_get_clean();
    };

    $html = $renderArticle($service->get($a12, true) ?? [], ['neighbours' => $n12]);
    $check($hasHrefEnding($html, '/emeroteca/' . $testata), 'the breadcrumb links to the masthead');
    $check($hasHrefEnding($html, '/emeroteca/fascicolo/' . $fascA), 'and to the issue');
    $check(str_contains($html, 'aria-current="page"'), 'and marks the current page');
    $check(str_contains($html, 'Torna al fascicolo n. 7 (2001)'), 'the back link reads "Torna al fascicolo n. 7 (2001)"');
    $check(!str_contains($html, 'Torna alla testata') && !str_contains($html, 'Torna agli articoli'), 'and is the only back link');
    $check($hasHrefEnding($html, '/emeroteca/articolo/' . $a3) && $hasHrefEnding($html, '/emeroteca/articolo/' . $a45),
        'the previous and next articles of the issue are linked');

    $html = $renderArticle($service->get($aMast, true) ?? []);
    $check(str_contains($html, 'Torna alla testata') && !str_contains($html, 'Torna al fascicolo'), 'a masthead-only article goes back to the masthead');

    $html = $renderArticle($service->get($aBare, true) ?? []);
    $check(str_contains($html, 'Torna agli articoli') && $hasHrefEnding($html, '/emeroteca/articoli'), 'a bare article goes back to /emeroteca/articoli');
    $check(!$hasHrefEnding($html, '/emeroteca/' . $testata) && !str_contains($html, 'Torna al fascicolo'), 'and links to no masthead or issue');

    echo "\nG. The controller\n";

    $controller = new $controllerClass($db, new \App\Support\HookManager($db));
    $call = static function (string $method, string $path, array $query, array $args = []) use ($controller): array {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query);
        $response = $controller->$method($request, (new ResponseFactory())->createResponse(), $args);
        $html = (string) $response->getBody();
        return [
            'status' => $response->getStatusCode(),
            'html' => $html,
            'robots' => preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $m) === 1 ? $m[1] : '(none)',
            'canonical' => preg_match('/<link\s+rel="canonical"\s+href="([^"]*)"/i', $html, $m) === 1 ? $m[1] : '(none)',
        ];
    };

    $fas = $call('showFascicolo', '/emeroteca/fascicolo/' . $fascA, [], ['id' => (string) $fascA]);
    $check($fas['status'] === 200, 'the issue page renders (status ' . $fas['status'] . ')');
    $check($hasHrefEnding($fas['html'], '/emeroteca/articolo/' . $a3) && $hasHrefEnding($fas['html'], '/emeroteca/articolo/' . $a12)
        && $hasHrefEnding($fas['html'], '/emeroteca/articolo/' . $a45) && $hasHrefEnding($fas['html'], '/emeroteca/articolo/' . $aNone),
        'it links every published article placed in the issue');
    $check(!$hasHrefEnding($fas['html'], '/emeroteca/articolo/' . $aDraft), 'and not the unpublished one');
    $pos = [];
    foreach ([$a3, $a12, $a45] as $id) {
        $pos[] = strpos($fas['html'], '/emeroteca/articolo/' . $id . '"');
    }
    $check(!in_array(false, $pos, true) && $pos === [min($pos), $pos[1], max($pos)] && $pos[0] < $pos[1] && $pos[1] < $pos[2],
        'in reading order');

    $art = $call('article', '/emeroteca/articolo/' . $a12, [], ['id' => (string) $a12]);
    $check($art['status'] === 200 && $hasHrefEnding($art['html'], '/emeroteca/fascicolo/' . $fascA) && str_contains($art['html'], 'Torna al fascicolo'),
        'the article route renders the issue back link inside the site layout');

    echo "\nH. /emeroteca robots and canonical\n";

    $idx = static fn(array $q): array => $call('index', '/emeroteca', $q);
    $bareList = $idx([]);
    $check($bareList['robots'] === 'index,follow', "the bare listing is index,follow (got '{$bareList['robots']}')");
    $check(str_ends_with($bareList['canonical'], '/emeroteca'), "and canonicalises to /emeroteca (got '{$bareList['canonical']}')");

    $total = (int) $db->query('SELECT COUNT(*) n FROM emeroteca_testate')->fetch_assoc()['n'];
    if ($total > $controllerClass::PER_PAGE) {
        $two = $idx(['page' => '2']);
        $check(str_ends_with($two['canonical'], '/emeroteca?page=2'), "page 2 canonicalises to itself (got '{$two['canonical']}')");
        $check($two['robots'] === 'index,follow', "and stays indexable (got '{$two['robots']}')");
    } else {
        echo "  NOTE only {$total} testate: not more than one page, page=2 assertions skipped\n";
    }
    foreach (['lettera=A' => ['lettera' => 'A'], 'lettera=#' => ['lettera' => '#'], 'vista=editore' => ['vista' => 'editore'], 'tipo' => ['tipo' => 'rivista'], 'q' => ['q' => 'zz']] as $label => $q) {
        $seo = $idx($q);
        $check($seo['robots'] === 'noindex,follow', "?{$label} is noindex,follow (got '{$seo['robots']}')");
    }

    echo "\nI. The shared 404\n";

    $missing = $call('article', '/emeroteca/articolo/999999999', [], ['id' => '999999999']);
    $check($missing['status'] === 404, "a missing article answers 404 (got {$missing['status']})");
    $check(str_contains($missing['html'], 'error-404'), 'with the site\'s own 404 markup');
    $check($missing['robots'] === 'noindex,follow', "and noindex (got '{$missing['robots']}')");
    $check(str_contains($missing['html'], 'Contenuto non trovato'), 'naming what was missing');
    $draft = $call('article', '/emeroteca/articolo/' . $aDraft, [], ['id' => (string) $aDraft]);
    $check($draft['status'] === 404, 'an unpublished article is a 404 for the public');
    $missingIssue = $call('showFascicolo', '/emeroteca/fascicolo/999999999', [], ['id' => '999999999']);
    $check($missingIssue['status'] === 404, 'a missing issue answers 404 too');
} finally {
    $db->rollback();
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
