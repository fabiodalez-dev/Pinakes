<?php
declare(strict_types=1);

/**
 * Regression test for fabiodalez-dev/Pinakes-Android#29.
 *
 * Private mode protects browser routes with a web session, but `/api/v1` has
 * its own route-level policy: discovery/auth endpoints are public and all
 * other endpoints are protected by the Mobile API bearer middleware. The
 * global private-mode middleware must therefore delegate this entire surface.
 *
 * Run: php tests/private-mode-mobile-api.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Middleware\PrivateModeMiddleware;
use App\Support\ConfigStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }

    $failed++;
    echo "  FAIL {$label}\n";
};

// Exercise process() without a database by installing the private-mode value
// into ConfigStore's request-local cache.
$runtimeCache = new ReflectionProperty(ConfigStore::class, 'runtimeCache');
$runtimeCache->setValue(null, ['advanced' => ['private_mode' => '1']]);
$_SESSION = [];

$handler = new class implements RequestHandlerInterface {
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return new Response(204);
    }
};

$middleware = new PrivateModeMiddleware();
$factory = new ServerRequestFactory();

echo "Private mode delegates Mobile API policy\n";
foreach (['/api/v1/health', '/api/v1/auth/login', '/api/v1/catalog/search'] as $path) {
    $before = $handler->calls;
    $response = $middleware->process($factory->createServerRequest('GET', $path), $handler);
    $check($response->getStatusCode() === 204 && $handler->calls === $before + 1,
        "{$path} reaches the Mobile API route stack");
}

echo "Private mode keeps unrelated APIs private\n";
foreach (['/api/books/1/availability', '/api/v10/health', '/api/publicity/books'] as $path) {
    $before = $handler->calls;
    $response = $middleware->process($factory->createServerRequest('GET', $path), $handler);
    $payload = json_decode((string) $response->getBody(), true);
    $check(
        $response->getStatusCode() === 401
        && $handler->calls === $before
        && ($payload['error'] ?? null) === true,
        "{$path} is rejected by the global private-mode gate"
    );
}

ConfigStore::clearCache();
unset($_SESSION);

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
