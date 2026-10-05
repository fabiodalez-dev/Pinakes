<?php
declare(strict_types=1);
/**
 * Issue #450: an automatic update that died on the server reached the page as
 * "The server returned an invalid response" and nothing else. A fatal error
 * during an update (memory, a time limit the host enforces) now answers as
 * JSON with PHP's own message. This runs a child PHP that registers the guard
 * the update endpoints register, then exhausts its memory, and reads what it
 * sent: JSON, success false, the memory error named.
 */
$root = dirname(__DIR__);
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

$child = tempnam(sys_get_temp_dir(), 'pinakes-fatal-') . '.php';
file_put_contents($child, '<?php
require ' . var_export($root . '/vendor/autoload.php', true) . ';
if (!function_exists("__")) { function __(string $s): string { return $s; } }
$controller = (new ReflectionClass(App\Controllers\UpdateController::class))->newInstanceWithoutConstructor();
$guard = new ReflectionMethod($controller, "answerJsonOnFatal");
$guard->setAccessible(true);
$guard->invoke($controller);
ob_start();
echo "<html>partial page</html>";
$hog = [];
while (true) { $hog[] = str_repeat("x", 1024 * 1024); }
');
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M -d display_errors=0 -d log_errors=0 ' . escapeshellarg($child) . ' 2>/dev/null');
@unlink($child);

$json = json_decode(trim($out), true);
check(is_array($json), 'a fatal error answers as JSON, not as an error page: ' . substr($out, 0, 120));
check(($json['success'] ?? null) === false, 'the answer says the update did not succeed');
check(str_contains((string) ($json['error'] ?? ''), 'Allowed memory size'), "PHP's own message reaches the page");
check(!str_contains($out, 'partial page'), 'output buffered before the fatal is dropped, so the JSON stands alone');

$controllerSrc = (string) file_get_contents($root . '/app/Controllers/UpdateController.php');
check(substr_count($controllerSrc, '$this->answerJsonOnFatal();') === 3, 'the guard covers download, install-manual and the single-request perform');

echo "SUCCESS $checks checks\n";
