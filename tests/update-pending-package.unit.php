<?php
declare(strict_types=1);

/**
 * The install request installs the package its own page downloaded or
 * uploaded, and no other. A download in one tab and an upload in another
 * share the session; the download answers with an opaque id, the install
 * request sends it back, and a package that took its place in between is
 * refused without being consumed, so its own install request can still take
 * it. A replaced package is deleted, and a downloaded package is checked
 * against its digest again right before it is installed.
 *
 * The updater is replaced by a recorder: what matters here is which package
 * the controller hands it.
 */

namespace App\Support {
    final class Updater {
        /** @var list<string> */
        public static array $installed = [];
        /** @var list<string> */
        public static array $discarded = [];

        public function __construct(\mysqli $db) {}

        public function checkRequirements(): array
        {
            return ['met' => true, 'requirements' => []];
        }

        public function setAttemptId(string $attemptId): void {}

        public function performUpdateFromFile(string $path): array
        {
            self::$installed[] = $path;
            return ['success' => true, 'error' => null, 'backup_path' => null];
        }

        public function discardPendingPackage(string $path): void
        {
            self::$discarded[] = $path;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    if (!function_exists('__')) {
        function __(string $s): string { return $s; }
    }

    $passed = 0;
    $check = static function (bool $ok, string $label) use (&$passed): void {
        if (!$ok) {
            fwrite(STDERR, "FAIL $label\n");
            exit(1);
        }
        $passed++;
        echo "OK $label\n";
    };

    $controller = new App\Controllers\UpdateController();
    $updater = new App\Support\Updater(new mysqli());
    $db = new mysqli();
    $hold = new ReflectionMethod($controller, 'holdPendingPackage');
    $hold->setAccessible(true);

    $tmp = dirname(__DIR__) . '/storage/tmp';
    if (!is_dir($tmp)) { mkdir($tmp, 0775, true); }
    $made = [];
    $package = static function (string $content) use ($tmp, &$made): string {
        $dir = $tmp . '/manual_update_' . bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir . '/update.zip', $content);
        $made[] = $dir;
        return $dir;
    };
    $install = static function (string $packageId) use ($controller, $db): array {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/updates/install-manual')
            ->withParsedBody(['csrf_token' => 'test-token', 'package' => $packageId]);
        $response = $controller->installManualUpdate($request, new Slim\Psr7\Response(), $db);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    };
    $_SESSION = ['user' => ['tipo_utente' => 'admin'], 'csrf_token' => 'test-token'];

    try {
        // A tab downloads; another tab uploads before the first installs.
        $downloaded = $package('downloaded');
        $downloadId = $hold->invoke($controller, $updater, $downloaded, hash('sha256', 'downloaded'));
        $uploaded = $package('uploaded');
        $uploadId = $hold->invoke($controller, $updater, $uploaded, null);

        $check($downloadId !== $uploadId && strlen($downloadId) === 32, 'each package gets its own opaque id');
        $check(App\Support\Updater::$discarded === [$downloaded], 'the package that was replaced is deleted');

        [$status, $body] = $install($downloadId);
        $check($status === 409 && ($body['success'] ?? null) === false, 'the download tab is refused: its package was replaced');
        $check(App\Support\Updater::$installed === [], 'nothing is installed on a refused request');
        $check(($_SESSION['manual_update_path'] ?? '') === $uploaded, 'the other package is left for its own install request');

        [$status, $body] = $install($uploadId);
        $check($status === 200 && ($body['success'] ?? null) === true, 'the upload tab installs');
        $check(App\Support\Updater::$installed === [realpath($uploaded)], 'and installs its own package');
        $check(!isset($_SESSION['manual_update_path'], $_SESSION['manual_update_id']), 'the package is consumed once');

        [$status] = $install($uploadId);
        $check($status === 409, 'the same id does not install twice');

        // A downloaded package changed on disk after its verification.
        $changed = $package('downloaded');
        $changedId = $hold->invoke($controller, $updater, $changed, hash('sha256', 'downloaded'));
        file_put_contents($changed . '/update.zip', 'tampered');
        [$status, $body] = $install($changedId);
        $check($status === 400 && str_contains((string) ($body['error'] ?? ''), 'checksum'), 'a downloaded package is checked against its digest again before the install');
        $check(count(App\Support\Updater::$installed) === 1, 'and is not installed when it no longer matches');

        // The same, intact.
        $intact = $package('downloaded');
        $intactId = $hold->invoke($controller, $updater, $intact, hash('sha256', 'downloaded'));
        [$status] = $install($intactId);
        $check($status === 200 && end(App\Support\Updater::$installed) === realpath($intact), 'an intact downloaded package installs');
    } finally {
        foreach ($made as $dir) {
            @unlink($dir . '/update.zip');
            @rmdir($dir);
        }
    }

    echo "\nAll $passed checks passed\n";
}
