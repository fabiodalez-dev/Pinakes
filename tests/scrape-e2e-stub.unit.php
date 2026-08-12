<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\ScrapeController;

$controller = new ScrapeController();
$enabled = new ReflectionMethod($controller, 'isE2eScraperStubEnabled');
$payload = new ReflectionMethod($controller, 'e2eScraperStubPayload');

$previousEnv = $_ENV['PINAKES_E2E_SCRAPER_STUB'] ?? null;
$previousProcessEnv = getenv('PINAKES_E2E_SCRAPER_STUB');
$results = ['passed' => 0, 'failed' => 0];

function check(bool $condition, string $message): void
{
    global $results;
    if ($condition) {
        echo "  OK  {$message}\n";
        $results['passed']++;
    } else {
        echo "  FAIL {$message}\n";
        $results['failed']++;
    }
}

try {
    unset($_ENV['PINAKES_E2E_SCRAPER_STUB']);
    putenv('PINAKES_E2E_SCRAPER_STUB');
    check($enabled->invoke($controller) === false, 'stub is disabled by default');

    putenv('PINAKES_E2E_SCRAPER_STUB=1');
    check($enabled->invoke($controller) === true, 'explicit process env enables the stub');

    $_ENV['PINAKES_E2E_SCRAPER_STUB'] = 'true';
    check($enabled->invoke($controller) === true, 'explicit dotenv value enables the stub');

    $expected = [
        '9780140328721' => ['Fantastic Mr. Fox', 'libro', 'https://openlibrary.org'],
        '9788804671664' => ['E2E Italian catalogue fixture', 'libro', 'https://openlibrary.org'],
        '0720642442524' => ['Nevermind', 'disco', 'discogs'],
        '5099902894225' => ['Meddle', 'disco', 'discogs'],
    ];
    foreach ($expected as $identifier => [$title, $mediaType, $source]) {
        $fixture = $payload->invoke($controller, $identifier);
        check(is_array($fixture), "fixture exists for {$identifier}");
        check(($fixture['title'] ?? '') === $title, "{$identifier} has stable title");
        check(($fixture['tipo_media'] ?? '') === $mediaType, "{$identifier} has stable media type");
        check(($fixture['source'] ?? '') === $source, "{$identifier} has stable source");
        check(!empty($fixture['image']), "{$identifier} has a deterministic cover");
    }
    check($payload->invoke($controller, 'unknown') === null, 'unknown identifiers still use real providers');
} finally {
    if ($previousEnv === null) {
        unset($_ENV['PINAKES_E2E_SCRAPER_STUB']);
    } else {
        $_ENV['PINAKES_E2E_SCRAPER_STUB'] = $previousEnv;
    }
    if ($previousProcessEnv === false) {
        putenv('PINAKES_E2E_SCRAPER_STUB');
    } else {
        putenv('PINAKES_E2E_SCRAPER_STUB=' . $previousProcessEnv);
    }
}

echo "\nCompleted {$results['passed']} passing scraper-stub checks.\n";
exit($results['failed']);
