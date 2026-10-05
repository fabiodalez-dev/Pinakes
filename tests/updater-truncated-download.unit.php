<?php
declare(strict_types=1);
/**
 * Issue #450: on a slow link the package download can break off partway, for
 * instance on a timeout. The partial file used to reach the checksum, and the
 * operator read "the archive does not match the expected checksum", which
 * points at a tampered release. A transfer that breaks off now fails as a
 * download, with the transport's own reason.
 *
 * A local server announces 100000 bytes, sends 5000 and closes; the updater's
 * streaming download is pointed at it, through cURL and through the PHP stream
 * it falls back to on hosts without cURL.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (!function_exists('__')) {
    function __(string $s): string { return $s; }
}
$checks = 0;
$check = static function (bool $ok, string $label): void {
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    $checks++;
    echo "OK $label\n";
};

// The server runs in a child PHP: it accepts one request, announces 100000
// bytes, sends 5000 and closes the connection
$serverCode = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
echo substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
$conn = stream_socket_accept($server, 15);
if ($conn !== false) {
    fread($conn, 8192);
    fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: 100000\r\nConnection: close\r\n\r\n");
    fwrite($conn, str_repeat('x', 5000));
    fclose($conn);
}
PHP;
$updater = (new ReflectionClass(App\Support\Updater::class))->newInstanceWithoutConstructor();

/** Point $download at a fresh truncating server and return the error it raised. */
$attempt = static function (callable $download) use ($serverCode, $check): ?string {
    $proc = proc_open([PHP_BINARY, '-r', $serverCode], [1 => ['pipe', 'w']], $pipes);
    $check(is_resource($proc), 'local server starts');
    $port = (int) trim((string) fgets($pipes[1]));
    $check($port > 0, 'local server listens');
    $path = tempnam(sys_get_temp_dir(), 'pinakes-part-');
    $error = null;
    try {
        $download("http://127.0.0.1:$port/pinakes.zip", $path);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    } finally {
        fclose($pipes[1]);
        proc_close($proc);
        @unlink($path);
    }
    return $error;
};

$streamToFile = new ReflectionMethod($updater, 'streamToFile');
$streamToFile->setAccessible(true);
$streamWithPhp = new ReflectionMethod($updater, 'streamWithPhp');
$streamWithPhp->setAccessible(true);

$paths = [
    'cURL' => static fn(string $url, string $path) => $streamToFile->invoke($updater, $url, $path, false),
    // Hosts without cURL: the PHP stream the updater falls back to
    'PHP stream' => static function (string $url, string $path) use ($streamWithPhp, $updater): void {
        $out = fopen($path, 'wb');
        try {
            $streamWithPhp->invoke($updater, $url, ['User-Agent: Pinakes-Updater/1.0'], $out);
        } finally {
            fclose($out);
        }
    },
];
foreach ($paths as $name => $download) {
    $error = $attempt($download);
    $check($error !== null, "$name: a transfer that breaks off is an error, not a downloaded file");
    $check(str_starts_with((string) $error, 'Download fallito: '), "$name: it is reported as a failed download");
    $check(!str_contains((string) $error, 'checksum'), "$name: it is not reported as a checksum mismatch");
    $check(strlen((string) $error) > strlen('Download fallito: '), "$name: the reason is named: " . $error);
}

echo "\nAll $checks checks passed\n";
