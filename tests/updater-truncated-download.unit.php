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
 * streaming download is pointed at it.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (!function_exists('__')) {
    function __(string $s): string { return $s; }
}
$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    $checks++;
    echo "OK $label\n";
}

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
$proc = proc_open([PHP_BINARY, '-r', $serverCode], [1 => ['pipe', 'w']], $pipes);
check(is_resource($proc), 'local server starts');
$port = (int) trim((string) fgets($pipes[1]));
check($port > 0, 'local server listens');

$updater = (new ReflectionClass(App\Support\Updater::class))->newInstanceWithoutConstructor();
$stream = new ReflectionMethod($updater, 'streamToFile');
$stream->setAccessible(true);
$path = tempnam(sys_get_temp_dir(), 'pinakes-part-');

$error = null;
try {
    $stream->invoke($updater, "http://127.0.0.1:$port/pinakes.zip", $path, false);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
fclose($pipes[1]);
proc_close($proc);
@unlink($path);

check($error !== null, 'a transfer that breaks off is an error, not a downloaded file');
check(str_starts_with((string) $error, 'Download fallito: '), 'it is reported as a failed download');
check(!str_contains((string) $error, 'checksum'), 'it is not reported as a checksum mismatch');
check(strlen((string) $error) > strlen('Download fallito: '), "the transport's reason is named: " . $error);

echo "\nAll $checks checks passed\n";
