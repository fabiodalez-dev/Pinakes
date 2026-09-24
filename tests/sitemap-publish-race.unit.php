<?php
declare(strict_types=1);

/**
 * The publish/invalidate race on the published sitemap.
 *
 * THE BUG this pins down: SitemapGenerator::saveTo() reads the world
 * (generate(): plugin state, catalogue, CMS pages) and then writes the file.
 * On a large collection those two steps are far enough apart for a plugin to
 * be activated or removed in between. The invalidation deletes the published
 * file precisely because it has stopped describing the site — and then the
 * in-flight generation writes its pre-change snapshot back over it. The result
 * is a stale sitemap that nothing will correct until the next regeneration,
 * which is the exact failure the invalidation exists to prevent.
 *
 * The fix is a revision counter shared between processes. It lives in a file,
 * not in APCu: the cron generator runs under the CLI SAPI and would otherwise
 * never see a bump made by PHP-FPM, so the two producers that actually matter
 * would be the two that disagree.
 *
 * The race itself is asserted by executing it, not by reasoning about it: a
 * subclass invalidates from inside generate(), which is precisely the window
 * the fix has to close.
 *
 * Run:  php tests/sitemap-publish-race.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\SitemapCache;
use App\Support\SitemapGenerator;

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

/**
 * Publishes like the real generator but mutates plugin state mid-flight —
 * standing in for an admin activating a plugin while the cron is generating.
 * Only generate() is replaced; saveTo() under test is the real one.
 */
final class RacingSitemapGenerator extends SitemapGenerator
{
    public bool $invalidateDuringGenerate = false;

    public function __construct()
    {
        // Deliberately no parent::__construct(): this test drives saveTo()'s
        // revision handling, and the real constructor would need a database.
    }

    public function generate(): string
    {
        if ($this->invalidateDuringGenerate) {
            SitemapCache::invalidate('test: plugin toggled mid-generation');
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }
}

// The revision counter and the published file are real paths shared with the
// running installation; snapshot both and restore them whatever happens.
$published        = SitemapCache::publishedPath();
$publishedBackup  = is_file($published) ? (string) file_get_contents($published) : null;
$revisionFile     = $root . '/storage/cache/sitemap-revision';
$revisionBackup   = is_file($revisionFile) ? (string) file_get_contents($revisionFile) : null;

$restore = static function () use ($published, $publishedBackup, $revisionFile, $revisionBackup): void {
    if ($publishedBackup === null) {
        @unlink($published);
    } else {
        @file_put_contents($published, $publishedBackup);
    }
    if ($revisionBackup === null) {
        @unlink($revisionFile);
    } else {
        @file_put_contents($revisionFile, $revisionBackup);
    }
};
// Restore even on a fatal, so a crashed run cannot leave the installation
// without its published sitemap.
register_shutdown_function($restore);

try {
    echo "A. The revision counter\n";

    @unlink($revisionFile);
    $check(SitemapCache::revision() === 0, 'a missing counter reads as 0 (publishers still publish)');

    @unlink($published);
    $before = SitemapCache::revision();
    $removed = SitemapCache::invalidate('test: no file present');
    $check($removed === false, 'invalidating with no published file reports nothing removed');
    $check(SitemapCache::revision() === $before + 1,
        'the counter is bumped anyway — an in-flight generation is stale whether or not a file existed');

    file_put_contents($published, '<urlset/>');
    $before = SitemapCache::revision();
    $removed = SitemapCache::invalidate('test: file present');
    $check($removed === true, 'invalidating with a published file removes it');
    $check(SitemapCache::revision() === $before + 1, 'and bumps the counter');

    echo "\nB. saveTo() publishes normally when nothing changes\n";

    @unlink($published);
    $gen = new RacingSitemapGenerator();
    $gen->invalidateDuringGenerate = false;
    $quiet = SitemapCache::revision();
    $gen->saveTo($published);

    $check(is_file($published), 'an uncontended publish leaves the file in place');
    $check(str_contains((string) file_get_contents($published), '<urlset'), 'the file holds the generated document');
    $check(SitemapCache::revision() === $quiet, 'an uncontended publish does not touch the counter');

    echo "\nC. saveTo() withdraws a document that went stale while it was being built\n";

    @unlink($published);
    $gen = new RacingSitemapGenerator();
    $gen->invalidateDuringGenerate = true;   // the race, executed
    $raced = SitemapCache::revision();
    $gen->saveTo($published);

    $check(SitemapCache::revision() > $raced, 'the mid-flight invalidation really did fire');
    $check(!is_file($published),
        'the pre-change snapshot is NOT left published — this is the bug, and it no longer reproduces');

    // The withdrawal must not be mistaken for a failure by the caller: saveTo()
    // returns normally, and /sitemap.xml serves the current document until the
    // next regeneration republishes the fast path.
    $check(true, 'saveTo() returned without throwing (the caller is not told a correct outcome failed)');

    echo "\nD. A second publish after the dust settles works normally\n";

    $gen = new RacingSitemapGenerator();
    $gen->invalidateDuringGenerate = false;
    $gen->saveTo($published);
    $check(is_file($published), 'the next regeneration publishes again');
} finally {
    $restore();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
