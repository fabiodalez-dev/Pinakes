<?php
declare(strict_types=1);

/** Web and in-process scraping share validation, normalization, final hooks and fallback. Offline fixtures only. */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\ScrapeController;
use App\Support\HookManager;
use App\Support\Hooks;
use App\Support\HttpClient;
use App\Support\ScrapingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as RemoteResponse;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

// Start before printing assertions. The service must leave the caller's session
// open, so writes after metadata lookup are persisted by the caller as usual.
session_id('scrape-pipeline-' . bin2hex(random_bytes(8)));
session_start();
$checks = 0;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) { $failures++; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$events = [];
$seenIdentifiers = [];
$calls = 0;
$fetch = static fn() => ['title' => "  A\u{0088}   title  ", 'image' => 'https://example.test/cover.jpg', 'classificazione_dewey' => '000'];
$manager = (new ReflectionClass(HookManager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(HookManager::class, 'loadedHooks'))->setValue($manager, true);
$previousHooks = Hooks::getInstance();
Hooks::init($manager);
$httpProperty = new ReflectionProperty(HttpClient::class, 'client');
$previousClient = $httpProperty->getValue();
// Empty queue makes any unexpected outbound request fail locally.
$remote = new MockHandler();
$httpProperty->setValue(null, new Client(['handler' => HandlerStack::create($remote)]));

Hooks::add('scrape.isbn.validate', static function ($valid, $raw) use (&$events) {
    $events[] = 'validate';
    return $valid || in_array($raw, ['CDP 7912682', '0720642442524', 'CDP 9780306406157'], true);
});
Hooks::add('scrape.sources', static function ($sources, $identifier) use (&$seenIdentifiers) {
    $seenIdentifiers[] = $identifier;
    $sources['fixture'] = ['name' => 'Fixture', 'enabled' => true];
    return $sources;
});
Hooks::add('scrape.fetch.custom', static function ($value, $sources, $identifier) use (&$events, &$calls, &$fetch) {
    $events[] = 'fetch';
    $calls++;
    return $fetch($calls, $identifier);
});
Hooks::add('scrape.data.modify', static function ($data) use (&$events) {
    $events[] = 'modify';
    $data['editor'] = ['  Jane   Doe '];
    $data['classificazione_dewey'] = '000'; // Avoid optional SBN enrichment.
    return $data;
});
Hooks::add('scrape.response', static function ($data) use (&$events) {
    $events[] = 'response';
    $data['final_hook_seen'] = true;
    return $data;
});

try {
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/scrape/isbn')->withQueryParams(['isbn' => '0-306-40615-2']);
    $response = (new ScrapeController())->byIsbn($request, new Response());
    $web = json_decode((string) $response->getBody(), true);
    $webEvents = $events;
    $events = []; $calls = 0;
    $service = ScrapingService::scrapeBookData('0-306-40615-2', 1);
    $check($service === $web, 'web and service return identical finalized plugin metadata');
    $check($events === ['validate', 'fetch', 'modify', 'response'] && $events === $webEvents, 'both paths run validation and final hooks once in the same order');
    $check(($service['title'] ?? null) === 'A title' && ($service['editor'] ?? null) === ['Jane Doe'], 'text and plugin-added contributors are normalized');
    $check(($service['isbn13'] ?? null) === '9780306406157' && ($service['isbn10'] ?? null) === '0306406152', 'service derives both ISBN variants');
    $check($seenIdentifiers === ['0306406152', '0306406152'], 'source hooks receive the same canonical identifier');
    $_SESSION['after_scrape'] = 'retained';
    $check(session_status() === PHP_SESSION_ACTIVE && $_SESSION['after_scrape'] === 'retained', 'in-process scraping leaves the caller session writable');

    $events = []; $calls = 0;
    $fetch = static fn(int $attempt) => $attempt === 1 ? null : ['title' => ' Retry  success ', 'image' => 'https://example.test/retry.jpg'];
    $retried = ScrapingService::scrapeBookData('9780306406157', 2);
    $check($calls === 2 && ($retried['title'] ?? null) === 'Retry success', 'a transient plugin miss is retried before finalization');
    $check($events === ['validate', 'fetch', 'fetch', 'modify', 'response'], 'retries do not duplicate validation or final hooks');

    $events = []; $calls = 0;
    $fetch = static function (int $attempt): array {
        if ($attempt === 1) { throw new RuntimeException('fixture transient provider failure'); }
        return ['title' => 'Recovered', 'image' => 'https://example.test/recovered.jpg'];
    };
    $recovered = ScrapingService::scrapeBookData('9780306406157', 2);
    $check($calls === 2 && ($recovered['title'] ?? null) === 'Recovered' && ($recovered['final_hook_seen'] ?? false), 'a provider exception can recover on the next attempt and is finalized');

    $events = []; $calls = 0;
    $fetch = static fn() => ['title' => 'Plugin without cover'];
    $remote->append(new RemoteResponse(200, [], json_encode(['items' => [['volumeInfo' => ['title' => 'Cover fixture', 'imageLinks' => ['thumbnail' => 'https://example.test/builtin.jpg']]]]])));
    $withCover = ScrapingService::scrapeBookData('9780306406157', 1);
    $check(($withCover['title'] ?? null) === 'Plugin without cover' && ($withCover['image'] ?? null) === 'https://example.test/builtin.jpg', 'complete plugin metadata still gets the built-in cover fallback');
    $check($events === ['validate', 'fetch', 'modify', 'response'], 'cover fallback does not duplicate final hooks');

    $events = []; $calls = 0;
    $fetch = static fn() => ['image' => 'https://example.test/partial.jpg'];
    $remote->append(new RemoteResponse(200, [], json_encode(['items' => [['volumeInfo' => ['title' => ' Built-in  fallback ']]]])));
    $fallback = ScrapingService::scrapeBookData('0306406152', 2);
    $check($calls === 2, 'exhausting retries does not fetch plugins an extra time during fallback');
    $check(($fallback['title'] ?? null) === 'Built-in fallback' && ($fallback['image'] ?? null) === 'https://example.test/partial.jpg', 'built-in fallback retains the partial plugin cover');
    $check(($fallback['ean'] ?? null) === '9780306406157', 'a built-in ISBN-10 result returns the 13-digit EAN');
    $check(($fallback['final_hook_seen'] ?? false) && $events === ['validate', 'fetch', 'fetch', 'modify', 'response'], 'fallback uses the same final hooks once');

    $events = []; $calls = 0;
    $fetch = static fn(int $attempt) => $attempt === 1
        ? ['title' => '   ', 'image' => 'https://example.test/first.jpg']
        : ['title' => ' Late  title ', 'image' => '   '];
    $merged = ScrapingService::scrapeBookData('9780306406157', 2);
    $check(($merged['title'] ?? null) === 'Late title' && ($merged['image'] ?? null) === 'https://example.test/first.jpg', 'a later attempt keeps the cover an earlier attempt found');

    $events = []; $calls = 0;
    $fetch = static fn(int $attempt) => $attempt === 1 ? ['image' => 'https://example.test/kept.jpg'] : [];
    $remote->append(new RemoteResponse(200, [], json_encode(['items' => [['volumeInfo' => ['title' => 'Kept fallback']]]])));
    $kept = ScrapingService::scrapeBookData('9780306406157', 2);
    $check(($kept['image'] ?? null) === 'https://example.test/kept.jpg' && ($kept['title'] ?? null) === 'Kept fallback', 'an empty retry does not drop the partial cover before the fallback');

    $events = []; $calls = 0;
    $check(ScrapingService::scrapeBookData('not-an-isbn', 1) === [] && $calls === 0, 'invalid input is rejected before any provider is called');

    $events = []; $calls = 0;
    $fetch = static fn(int $attempt, string $identifier) => ['title' => ' Music ', 'format' => 'CD', 'image' => 'https://example.test/music.jpg', 'queried_identifier' => $identifier];
    $music = ScrapingService::scrapeBookData('CDP 7912682', 1);
    $check(($music['queried_identifier'] ?? null) === 'CDP 7912682' && ($music['tipo_media'] ?? null) === 'disco', 'plugin-accepted catalogue numbers retain their original identifier and media type');
    $check(!isset($music['isbn10']) && !isset($music['isbn13']), 'non-book identifiers are not converted to ISBNs');

    $fetch = static fn(int $attempt, string $identifier) => ['title' => 'No media hint', 'ean' => $identifier];
    $barcode = ScrapingService::scrapeBookData('0720642442524', 1);
    $check(($barcode['ean'] ?? null) === '0720642442524' && !isset($barcode['isbn13']), 'a bare EAN is not restored as an ISBN by the shared pipeline');
    $catalogueCode = ScrapingService::scrapeBookData('CDP 9780306406157', 1);
    $check(end($seenIdentifiers) === 'CDP 9780306406157' && !isset($catalogueCode['isbn13']), 'a catalogue code with a checksum-valid numeric suffix remains opaque');

    $events = []; $calls = 0;
    $fetch = static fn() => null;
    $remote->append(new RemoteResponse(404), new RemoteResponse(404));
    $check(ScrapingService::scrapeBookData('9780306406157', 1) === [], 'a complete provider miss returns an empty array');
    $check($calls === 1 && $events === ['validate', 'fetch'], 'a miss does not run final hooks or duplicate the plugin request');

    $events = []; $calls = 0;
    $registered = new ReflectionProperty(HookManager::class, 'hooks');
    $hooks = $registered->getValue($manager);
    unset($hooks['scrape.fetch.custom']);
    $registered->setValue($manager, $hooks);
    $remote->append(new RemoteResponse(200, [], json_encode(['items' => [['volumeInfo' => ['title' => 'No plugin fallback']]]])));
    $withoutPlugin = ScrapingService::scrapeBookData('9780306406157');
    $check(($withoutPlugin['title'] ?? null) === 'No plugin fallback' && ($withoutPlugin['final_hook_seen'] ?? false), 'built-in metadata is available without any fetch plugin');
    $check($calls === 0 && $events === ['validate', 'modify', 'response'], 'no-plugin fallback skips retry waits and finalizes once');
} finally {
    $httpProperty->setValue(null, $previousClient);
    (new ReflectionProperty(Hooks::class, 'instance'))->setValue(null, $previousHooks);
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
}
echo "$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
