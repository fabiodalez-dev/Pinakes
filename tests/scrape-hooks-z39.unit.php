<?php
declare(strict_types=1);

/**
 * Unit checks for two hook-system fixes (run: `php tests/scrape-hooks-z39.unit.php`).
 *
 *  FIX 1 — The core (ScrapeController) now emits `scrape.data.modify` on the
 *          assembled payload in BOTH the plugin-result branch and the built-in
 *          fallback branch, just before `scrape.response`. Previously only the
 *          scraping-pro plugin fired it, so a single-source setup (discogs
 *          alone, or the Google Books / Open Library fallback) never reached
 *          plugin enrichment such as the discogs cover fill. `enrichWithDiscogsData`
 *          is idempotent, so a double-fire when scraping-pro is also active is safe.
 *
 *  FIX 2 — The z39-server plugin used to register `admin.menu.items`, a hook
 *          name the core never emits (the core only fires `admin.menu.render`,
 *          an action whose callbacks echo their sidebar markup — see archives).
 *          The dead registration + its `addAdminMenuItem` method are removed;
 *          z39-server settings remain reachable through the standard plugin
 *          manager like every other plugin.
 *
 * Style mirrors loan-reservation-consistency.unit.php: mostly source-content
 * assertions (no DB/server), plus three behavioural checks against
 * DiscogsPlugin (its constructor accepts a null mysqli and the exercised paths
 * touch no database).
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$passed = 0;
function ok(string $msg): void { global $passed; $passed++; fwrite(STDOUT, "PASS: {$msg}\n"); }
function fail(string $msg, string $detail = ''): void {
    fwrite(STDERR, "FAIL: {$msg}\n" . ($detail !== '' ? "  {$detail}\n" : ''));
    exit(1);
}
function readOrFail(string $path): string {
    $c = file_get_contents($path);
    if ($c === false) { fail("cannot read {$path}"); }
    return $c;
}
function aContains(string $needle, string $hay, string $msg): void {
    str_contains($hay, $needle) ? ok($msg) : fail($msg, "missing: {$needle}");
}
function aNotContains(string $needle, string $hay, string $msg): void {
    !str_contains($hay, $needle) ? ok($msg) : fail($msg, "unexpected: {$needle}");
}
function aSame($exp, $act, string $msg): void {
    $exp === $act ? ok($msg) : fail($msg, 'expected ' . var_export($exp, true) . ' got ' . var_export($act, true));
}
function aTrue(bool $cond, string $msg): void { $cond ? ok($msg) : fail($msg); }

/** Recursively collect every .php file under the given roots. */
function phpFilesUnder(array $dirs): array {
    $out = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $out[] = $f->getPathname(); }
        }
    }
    return $out;
}

$scrape      = readOrFail($root . '/app/Controllers/ScrapeController.php');
$z39         = readOrFail($root . '/storage/plugins/z39-server/Z39ServerPlugin.php');
$layout      = readOrFail($root . '/app/Views/layout.php');
$discogsSrc  = readOrFail($root . '/storage/plugins/discogs/DiscogsPlugin.php');
$archives    = readOrFail($root . '/storage/plugins/archives/ArchivesPlugin.php');
// scraping-pro is a PREMIUM plugin — NOT bundled in the repo / CI checkout.
// Read it only when present so T8 (cross-plugin enrichment path) runs on a
// developer box but is skipped (not failed) in the standalone CI run that
// ships no premium code.
$scrapingProPath = $root . '/storage/plugins/scraping-pro/ScrapingProPlugin.php';
// Read defensively: a TOCTOU race (file removed between is_file() and the read)
// makes file_get_contents() return false — treat that as "absent" (null → skip
// T8), NOT "" which would slip into aContains() and mis-assert. readOrFail() is
// deliberately avoided here: it exit(1)s on a read miss, which would re-break the
// CI run this guard exists to keep green.
$scrapingProRaw = is_file($scrapingProPath) ? file_get_contents($scrapingProPath) : false;
$scrapingPro    = ($scrapingProRaw === false) ? null : $scrapingProRaw;

// ─── Group A — core emits scrape.data.modify (FIX 1) ────────────────────────
aContains("Hooks::apply('scrape.data.modify'", $scrape,
    'T1  ScrapeController emits scrape.data.modify');

aSame(2, substr_count($scrape, "Hooks::apply('scrape.data.modify'"),
    'T2  scrape.data.modify emitted in BOTH branches (plugin result + fallback)');

aSame(2, substr_count($scrape, "Hooks::apply('scrape.response'"),
    'T3  scrape.response still emitted in both branches (not regressed)');

// Verify ordering in BOTH emit sites (plugin-result branch + fallback branch),
// not just the first global occurrence: a regression in one branch must not be
// masked by the other staying correct. T2/T3 already assert each hook appears
// exactly twice, so we pair them positionally — i-th data.modify before i-th
// response.
// Anchor on the Hooks::apply( call (like T2/T3) so comment mentions of the
// hook names (e.g. "// Hook: scrape.data.modify …") are not counted.
$dataNeedle = "Hooks::apply('scrape.data.modify'";
$respNeedle = "Hooks::apply('scrape.response'";
$dataPositions = [];
$respPositions = [];
$off = 0;
while (($p = strpos($scrape, $dataNeedle, $off)) !== false) { $dataPositions[] = $p; $off = $p + 1; }
$off = 0;
while (($p = strpos($scrape, $respNeedle, $off)) !== false) { $respPositions[] = $p; $off = $p + 1; }
aTrue(
    count($dataPositions) === 2 && count($respPositions) === 2
        && $dataPositions[0] < $respPositions[0]
        && $dataPositions[1] < $respPositions[1],
    'T4  scrape.data.modify fires before scrape.response in BOTH branches (enrichment precedes final response hook)');

// Emitted in a multi-line Hooks::apply( ... ) call, so match the hook-name argument.
aContains("'scrape.isbn.validate',", $scrape,
    'T5  core still emits scrape.isbn.validate (discogs validateBarcode is a LIVE hook, issue #101)');

// ─── Group B — plugin registrations against those hooks are all live ────────
aContains("Hooks::add('scrape.data.modify', [\$this, 'enrichWithDiscogsData']", $discogsSrc,
    'T6  discogs registers enrichWithDiscogsData on scrape.data.modify');

aContains("Hooks::add('scrape.isbn.validate', [\$this, 'validateBarcode']", $discogsSrc,
    'T7  discogs registers validateBarcode on scrape.isbn.validate');

if ($scrapingPro === null) {
    fwrite(STDOUT, "SKIP: T8  scraping-pro premium plugin not present (not bundled) — cross-plugin emit check skipped\n");
} else {
    aContains("Hooks::apply('scrape.data.modify'", $scrapingPro,
        'T8  scraping-pro still emits scrape.data.modify (cross-plugin enrichment path preserved)');
}

// ─── Group C — z39 dead hook removed (FIX 2) ────────────────────────────────
aNotContains('admin.menu.items', $z39,
    'T9  z39-server no longer registers the never-emitted admin.menu.items');

aNotContains('function addAdminMenuItem', $z39,
    'T10 z39-server addAdminMenuItem method removed');

aContains('admin.menu.render', $layout,
    'T11 core emits admin.menu.render (the real sidebar hook)');

// admin.menu.items must be emitted NOWHERE in the core — it was always dead.
$coreReferencesItems = false;
foreach (phpFilesUnder([$root . '/app', $root . '/public']) as $php) {
    $body = (string) file_get_contents($php);
    if (str_contains($body, 'admin.menu.items')) { $coreReferencesItems = true; break; }
}
aTrue($coreReferencesItems === false,
    'T12 admin.menu.items is referenced nowhere in app/ or public/ (confirms it was a dead hook)');

aContains("registerHookInDb('admin.menu.render'", $archives,
    'T13 archives demonstrates the correct pattern: registers admin.menu.render with an echo callback');

// ─── Group D — behavioural (DiscogsPlugin, null DB) ─────────────────────────
require_once $root . '/storage/plugins/discogs/DiscogsPlugin.php';
$discogs = new \App\Plugins\Discogs\DiscogsPlugin(null);

// Idempotency: a payload that already has a cover is returned untouched — this
// is WHY emitting scrape.data.modify from the core (possibly a second time
// after scraping-pro) cannot clobber data or double-fetch.
$withCover = ['title' => 'X', 'image' => 'http://example/cover.jpg', 'discogs_id' => 999];
$out1 = $discogs->enrichWithDiscogsData($withCover, '978', ['source' => 'core'], $withCover);
aSame('http://example/cover.jpg', $out1['image'] ?? null,
    'T14 enrichWithDiscogsData is idempotent when a cover already exists (core double-fire is safe)');

// A plain book payload (no discogs_id, non-music, no cover) must pass straight
// through with no spurious music cover and no exception / DB access.
$book = ['title' => 'Un libro', 'isbn13' => '9788812345678'];
$out2 = $discogs->enrichWithDiscogsData($book, '9788812345678', ['source' => 'core'], $book);
aTrue(empty($out2['image']) && ($out2['title'] ?? '') === 'Un libro',
    'T15 enrichWithDiscogsData leaves a non-music book payload untouched (no spurious cover, fields preserved)');

fwrite(STDOUT, "\nAll {$passed} assertions passed.\n");
