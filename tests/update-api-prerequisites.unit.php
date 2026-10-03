<?php
declare(strict_types=1);

/**
 * What the update endpoints say when the updater cannot be built.
 *
 * A missing host precondition (UpdaterPreflightException) is the one failure
 * an administrator must see the cause of: it says what to fix. Anything else
 * is unexpected and may carry server paths, so it is logged and answered with
 * a generic message; and staff, who reach some of these endpoints, never see
 * a cause at all. Every failure is written to the application log.
 */

namespace App\Support {
    // Fail at exactly the constructor boundary used by the real controller.
    final class Updater {
        public static ?\Throwable $failure = null;

        public function __construct(\mysqli $db) {
            throw self::$failure ?? new \RuntimeException('no failure configured');
        }
    }
}
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $controller = new App\Controllers\UpdateController();
    $db = new mysqli();
    $_SESSION = ['user' => ['tipo_utente' => 'admin'], 'csrf_token' => 'test-token'];
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/updates')
        ->withParsedBody(['version' => '1.0.0', 'csrf_token' => 'test-token']);
    $root = dirname(__DIR__) . '/storage/tmp';
    if (!is_dir($root)) { mkdir($root, 0775, true); }
    $upload = $root . '/api_prerequisites_' . bin2hex(random_bytes(6));
    mkdir($upload);
    $logFile = dirname(__DIR__) . '/storage/logs/app.log';
    $passed = $failed = 0;
    $check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
        $ok ? $passed++ : $failed++;
    };
    $logSize = static fn(): int => is_file($logFile) ? (int) filesize($logFile) : 0;
    $loggedSince = static function (int $offset, string $needle) use ($logFile): bool {
        clearstatcache();
        $tail = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $offset) : '';
        return str_contains($tail, $needle);
    };

    $preflight = 'Controllo pre-aggiornamento fallito: storage/tmp non scrivibile';
    $internal = 'fopen(/home/account/secret/storage/tmp/x): failed to open stream';
    $generic = __("Il sistema di aggiornamento non è disponibile. Il dettaglio è nel registro dell'applicazione.");
    $jsonEndpoints = ['checkUpdates', 'performUpdate', 'getHistory', 'checkAvailable', 'installManualUpdate'];
    $call = static function (string $method) use ($controller, $request, $db, $upload): array {
        $_SESSION['manual_update_path'] = $upload;
        $response = $controller->$method($request, new Slim\Psr7\Response(), $db);
        return [$response, json_decode((string) $response->getBody(), true)];
    };

    try {
        // An administrator, and a missing host precondition: the cause is the answer.
        App\Support\Updater::$failure = new App\Support\UpdaterPreflightException($preflight);
        foreach ($jsonEndpoints as $method) {
            $offset = $logSize();
            [$response, $body] = $call($method);
            $check($response->getStatusCode() === 503, "{$method} returns service unavailable");
            $check(str_contains($response->getHeaderLine('Content-Type'), 'application/json')
                && ($body['success'] ?? null) === false && ($body['error'] ?? '') === $preflight,
                "{$method} tells an administrator which precondition is missing");
            $check($loggedSince($offset, "{$method}: updater unavailable"), "{$method} logs the failure");
        }

        // An unexpected exception: logged in full, never echoed.
        App\Support\Updater::$failure = new \RuntimeException($internal);
        foreach ($jsonEndpoints as $method) {
            $offset = $logSize();
            [$response, $body] = $call($method);
            $error = (string) ($body['error'] ?? '');
            $check($response->getStatusCode() === 503 && $error === $generic && !str_contains((string) $response->getBody(), '/home/account'),
                "{$method} answers an unexpected exception with the generic message, without the server path");
            $check($loggedSince($offset, '/home/account/secret'), "{$method} keeps the detail in the log");
        }

        // The settings page degrades the same way.
        $_SESSION['manual_update_path'] = $upload;
        ob_start();
        try {
            $page = $controller->index($request, new Slim\Psr7\Response(), $db);
        } finally {
            ob_end_clean();
        }
        $html = (string) $page->getBody();
        $check($page->getStatusCode() === 503 && str_contains($html, htmlspecialchars($generic, ENT_QUOTES, 'UTF-8')) && !str_contains($html, '/home/account'),
            'the updates page shows the generic message for an unexpected exception');

        // Staff reach checkAvailable: they get no cause, not even a precondition.
        $_SESSION['user']['tipo_utente'] = 'staff';
        App\Support\Updater::$failure = new App\Support\UpdaterPreflightException($preflight);
        [$response, $body] = $call('checkAvailable');
        $check($response->getStatusCode() === 503 && ($body['error'] ?? '') === $generic, 'checkAvailable gives staff the generic message');

        foreach (['performUpdate', 'installManualUpdate'] as $method) {
            [$response] = $call($method);
            $check($response->getStatusCode() === 403, "{$method} still checks authorization first");
        }
        $_SESSION['user']['tipo_utente'] = 'admin';
        $request = $request->withParsedBody(['version' => '1.0.0', 'csrf_token' => 'invalid']);
        $response = $controller->performUpdate($request, new Slim\Psr7\Response(), $db);
        $check($response->getStatusCode() === 403, 'CSRF failure is still rejected before constructing Updater');
    } finally {
        @rmdir($upload);
    }
    echo "Passed: $passed Failed: $failed\n";
    exit($failed ? 1 : 0);
}
