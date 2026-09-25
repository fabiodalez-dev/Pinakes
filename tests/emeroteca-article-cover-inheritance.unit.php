<?php
declare(strict_types=1);

/**
 * An article with no image of its own shows the masthead's.
 *
 * WHY: a standalone article is usually a citation, and its image field — added
 * in plugin 1.6 — is optional and most often empty. Falling straight through to
 * the catalogue placeholder turned a list of results into a column of identical
 * grey rectangles that carried no information at all. The masthead's logo is a
 * true statement about the record and, in a list, does real work: it says at a
 * glance which publication each result came from.
 *
 * Deliberately NOT the issue's cover, even when the article is attached to one:
 * a per-issue photograph varies row by row and stops carrying that signal.
 *
 * THE SHAPE THIS GUARDS. The rule has ONE owner, ContributionService::coverUrl(),
 * because the previous arrangement — each view writing "own cover, else the
 * placeholder" inline — is exactly what produced two disagreeing definitions of
 * "empty" elsewhere in this plugin (the filter chip that dropped a sibling whose
 * value was the string "0"). A pure resolver is only half of it: it answers
 * correctly only if every read path actually SUPPLIES the masthead logo, so the
 * suppliers are asserted here too, against the real database. A resolver with no
 * supplier degrades silently — every article back to the placeholder, no error
 * anywhere — which is precisely the failure this file exists to catch.
 *
 * Writes are made inside a transaction and rolled back; no DDL, no real row is
 * touched.
 *
 * Run:  php tests/emeroteca-article-cover-inheritance.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/emeroteca/src/Services/ContributionService.php';

use App\Plugins\Emeroteca\Services\ContributionService;

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
// E2E_DB_NAME first, like every other suite here: in CI that is the database
// built for the run, and reading only .env would write to the installation's.
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

echo "A. The rule itself\n";

$logo = '/uploads/emeroteca/masthead-logo.jpg';
$own  = '/uploads/emeroteca/article-cover.jpg';

$check(ContributionService::coverUrl(['copertina_url' => $own, 'testata_logo_url' => $logo]) === $own,
    'an article with its own image keeps it');
$check(ContributionService::coverUrl(['copertina_url' => null, 'testata_logo_url' => $logo]) === $logo,
    'an article without one inherits the masthead logo');
$check(ContributionService::coverUrl(['copertina_url' => '', 'testata_logo_url' => '']) === '',
    'neither image present resolves to the empty string, not to a placeholder path');
$check(ContributionService::coverUrl([]) === '',
    'a row carrying neither key is answered, not fatal');
// The plugin has been bitten once by a falsy-but-real value; whitespace is the
// other end of the same mistake — a column holding " " is not an image.
$check(ContributionService::coverUrl(['copertina_url' => "  \t ", 'testata_logo_url' => $logo]) === $logo,
    'a whitespace-only value is empty, and inherits');
$check(ContributionService::coverUrl(['copertina_url' => '0']) === '0',
    'the string "0" is a path, not an absence — array_filter() would have dropped it');

echo "\nB. Every read path supplies the masthead logo\n";

$service = new ContributionService($db);
$db->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $db->query("INSERT INTO emeroteca_testate (titolo, logo_url) VALUES ('zz-cover-probe-{$suffix}', '{$logo}')");
    $testataId = (int) $db->insert_id;
    $db->query(
        "INSERT INTO emeroteca_contributi (reference_key, titolo, testata_id, pubblico, copertina_url)
         VALUES ('zz-cover-probe-{$suffix}', 'zz cover probe {$suffix}', {$testataId}, 1, NULL)"
    );
    $articleId = (int) $db->insert_id;

    $one = $service->get($articleId, true);
    $check(is_array($one) && array_key_exists('testata_logo_url', $one),
        'get() carries testata_logo_url — the single-article page and the mobile detail read this');
    $check(is_array($one) && ContributionService::coverUrl($one) === $logo,
        'and the article resolves to the masthead logo');

    $found = $service->search('zz cover probe ' . $suffix, 0, true, 1, []);
    $seeded = null;
    foreach ($found['rows'] as $row) {
        if ((int) $row['id'] === $articleId) {
            $seeded = $row;
        }
    }
    $check($seeded !== null, 'search() finds the seeded article');
    $check($seeded !== null && array_key_exists('testata_logo_url', $seeded),
        'search() carries testata_logo_url — every public list reads this');
    $check($seeded !== null && ContributionService::coverUrl($seeded) === $logo,
        'and the listed row resolves to the masthead logo');

    // An article that belongs to no masthead must still be answerable: testata_id
    // is nullable by design (Emeroteca "simple" mode catalogues an article without
    // owning the publication), and an inner join would make it vanish from its
    // own page rather than merely leave it without an image.
    $db->query(
        "INSERT INTO emeroteca_contributi (reference_key, titolo, testata_id, pubblico, copertina_url)
         VALUES ('zz-orphan-probe-{$suffix}', 'zz orphan probe {$suffix}', NULL, 1, NULL)"
    );
    $orphan = $service->get((int) $db->insert_id, true);
    $check(is_array($orphan), 'an article with no masthead is still returned by get()');
    $check(is_array($orphan) && ContributionService::coverUrl($orphan) === '',
        'and resolves to no image rather than to someone else’s logo');

    echo "\nC. What the reader actually sees\n";

    // Render the real view. Reading the emitted markup rather than the resolver's
    // return value is the point: the view is where the previous inline rule lived.
    $articleResults = ['rows' => [$seeded ?? []]];
    ob_start();
    require $root . '/storage/plugins/emeroteca/src/Views/public/article-results.php';
    $listHtml = (string) ob_get_clean();

    $check(str_contains($listHtml, $logo), 'the results list renders the masthead logo');
    $check(!str_contains($listHtml, 'placeholder.jpg"'),
        'and does not fall through to the placeholder for that row');

    $article = $one ?? [];
    ob_start();
    require $root . '/storage/plugins/emeroteca/src/Views/public/article.php';
    $pageHtml = (string) ob_get_clean();

    $check(str_contains($pageHtml, $logo), 'the article page renders the masthead logo');

    $ld = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $pageHtml, $m) === 1
        ? json_decode($m[1], true)
        : null;
    $check(is_array($ld) && isset($ld['image']) && str_contains((string) $ld['image'], $logo),
        'and declares it as the article’s image in the structured data');

    // The resolver answering "0" correctly is not enough: the bug was a view
    // testing that answer with `?:`, which reads "0" as absent. Render it.
    $zeroRow = ($seeded ?? []) + [];
    $zeroRow['copertina_url'] = '0';
    $zeroRow['testata_logo_url'] = $logo;
    $articleResults = ['rows' => [$zeroRow]];
    ob_start();
    require $root . '/storage/plugins/emeroteca/src/Views/public/article-results.php';
    $zeroHtml = (string) ob_get_clean();
    $check(!str_contains($zeroHtml, 'placeholder.jpg"'),
        'a cover stored as the string "0" is rendered, not mistaken for an absent one');

    $article = $zeroRow;
    ob_start();
    require $root . '/storage/plugins/emeroteca/src/Views/public/article.php';
    $zeroPage = (string) ob_get_clean();
    $check(!str_contains($zeroPage, 'placeholder.jpg"'),
        'and the article page agrees with the list about it');

    echo "\nD. With no image anywhere, the page says so honestly\n";

    $article = $orphan ?? [];
    ob_start();
    require $root . '/storage/plugins/emeroteca/src/Views/public/article.php';
    $bareHtml = (string) ob_get_clean();

    $check(str_contains($bareHtml, 'placeholder.jpg'),
        'an article with no image at all still draws the catalogue placeholder');
    $ldBare = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $bareHtml, $m) === 1
        ? json_decode($m[1], true)
        : null;
    $check(is_array($ldBare) && !isset($ldBare['image']),
        'but never declares the placeholder as its image — that would tell an aggregator every article looks alike');
    echo "\nE. The article block sits on the page's own margin\n";

    // Reported from a live library: on /emeroteca the Articoli block was inset
    // eighty-six pixels further than everything above it, because the shared
    // block carried a width container of its own (max-w-6xl) while the pages
    // use the site's `container emeroteca-public`. A shared block must not
    // decide how wide the page is — only where it sits vertically.
    $views = $root . '/storage/plugins/emeroteca/src/Views/public/';
    $block = (string) file_get_contents($views . 'article-results.php');
    $openingSection = preg_match('/<section class="([^"]*)"/', $block, $m) === 1 ? $m[1] : '(none)';

    $check(!str_contains($openingSection, 'max-w-'),
        "the shared article block imposes no width of its own (class=\"{$openingSection}\")");
    $check(!str_contains($openingSection, 'mx-auto'),
        'and does not centre itself independently of the page');

    // …which only works if every caller puts it INSIDE the container. It used
    // to be required after </main>, where it had no choice but to invent one.
    foreach (['index.php', 'testata.php', 'articles.php'] as $caller) {
        $src = (string) file_get_contents($views . $caller);
        $pos = strpos($src, 'article-results.php');
        $check($pos !== false, "{$caller} still includes the shared article block");
        if ($pos === false) {
            continue;
        }
        $before = substr($src, 0, $pos);
        $opens = substr_count($before, '<main');
        $closes = substr_count($before, '</main>');
        $check($opens > $closes,
            "{$caller} includes it inside the page container, not after </main> "
                . "({$opens} open, {$closes} closed before the include)");
    }

    // And the three shells agree on which container that is.
    foreach (['index.php', 'testata.php', 'articles.php'] as $shell) {
        $src = (string) file_get_contents($views . $shell);
        $check(str_contains($src, 'class="container emeroteca-public"'),
            "{$shell} uses the same page container as the rest of the emeroteca");
    }
} finally {
    $db->rollback();
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
