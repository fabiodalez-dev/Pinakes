<?php
declare(strict_types=1);

namespace App\Support {
    // Fail at exactly the constructor boundary used by the real controller.
    final class Updater {
        public function __construct(\mysqli $db) {
            throw new \RuntimeException('prerequisite unavailable');
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
    $passed = $failed = 0;
    $check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
        $ok ? $passed++ : $failed++;
    };
    try {
        foreach (['checkUpdates', 'performUpdate', 'getHistory', 'checkAvailable', 'installManualUpdate'] as $method) {
            $_SESSION['manual_update_path'] = $upload;
            $response = $controller->$method($request, new Slim\Psr7\Response(), $db);
            $body = json_decode((string) $response->getBody(), true);
            $check($response->getStatusCode() === 503, $method . ' returns service unavailable');
            $check(str_contains($response->getHeaderLine('Content-Type'), 'application/json')
                && ($body['success'] ?? null) === false && ($body['error'] ?? '') === 'prerequisite unavailable',
                $method . ' returns a controlled JSON error');
        }
        $_SESSION['user']['tipo_utente'] = 'staff';
        foreach (['performUpdate', 'installManualUpdate'] as $method) {
            $response = $controller->$method($request, new Slim\Psr7\Response(), $db);
            $check($response->getStatusCode() === 403, $method . ' still checks authorization first');
        }
        $_SESSION['user']['tipo_utente'] = 'admin';
        $request = $request->withParsedBody(['version' => '1.0.0', 'csrf_token' => 'invalid']);
        $response = $controller->performUpdate($request, new Slim\Psr7\Response(), $db);
        $check($response->getStatusCode() === 403, 'CSRF failure is still rejected before constructing Updater');
    } finally {
        rmdir($upload);
    }
    echo "Passed: $passed Failed: $failed\n";
    exit($failed ? 1 : 0);
}
