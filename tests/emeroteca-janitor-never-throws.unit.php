<?php
declare(strict_types=1);

/**
 * The two file janitors in AbstractAdminController must never propagate.
 *
 * THE BUG this pins down, and the reason it was invisible: `config/container.php`
 * arms `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, so a failing
 * statement THROWS instead of returning false — which makes every `=== false`
 * and `!$stmt->execute()` guard inside these helpers dead code. They read as
 * carefully defensive and were not defended at all.
 *
 * What that cost: ContributionController::save() calls the image janitor AFTER
 * the row is committed. A database hiccup there surfaced as "Salvataggio non
 * riuscito" over an article that had in fact been saved, the rollback deleted
 * the PDF the committed row still pointed at, and the re-rendered form carried
 * a stale revision so the retry was rejected as a phantom concurrent edit. The
 * same shape sits at eighteen other call sites across IssueAdminController and
 * PeriodicalAdminController, where it surfaced as a 500.
 *
 * The fix wraps the OUTER body of each janitor in `catch (\Throwable)` + log.
 * Deliberately not inside tableExists(): swallowing there would shrink the
 * UNION that looks for surviving references, and an image still in use would
 * be judged orphaned and deleted — data loss, strictly worse than the bug.
 * That distinction is asserted below.
 *
 * The failure is injected by closing the connection, which is the cheapest
 * honest way to make every statement on it throw.
 *
 * Run:  php tests/emeroteca-janitor-never-throws.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$connect = static function () use ($env, $socket): mysqli {
    return $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
};

try {
    $probe = $connect();
    $probe->close();
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

require_once $root . '/storage/plugins/emeroteca/src/Controllers/AbstractAdminController.php';

/**
 * Exposes the two protected janitors. It adds no behaviour of its own — the
 * methods under test are the real ones.
 */
final class JanitorProbe extends \App\Plugins\Emeroteca\Controllers\AbstractAdminController
{
    public function callImageJanitor(string $url): void
    {
        $this->deleteManagedImageIfUnreferenced($url);
    }

    public function callPdfJanitor(string $filename): void
    {
        $this->deleteManagedPdfIfUnreferenced($filename);
    }
}

$uploads = $root . '/public/uploads/emeroteca';
$madeUploads = false;
if (!is_dir($uploads)) {
    $madeUploads = @mkdir($uploads, 0775, true);
}

// A file with a name no production upload can collide with.
$probeName = 'zz-janitor-probe-' . bin2hex(random_bytes(8)) . '.jpg';
$probeFile = $uploads . '/' . $probeName;
$probeUrl  = '/uploads/emeroteca/' . $probeName;

$cleanup = static function () use ($probeFile, $uploads, $madeUploads): void {
    if (is_file($probeFile)) {
        @unlink($probeFile);
    }
    if ($madeUploads && is_dir($uploads)) {
        @rmdir($uploads);
    }
};
register_shutdown_function($cleanup);

try {
    echo "A. A dead connection does not escape the janitors\n";

    $db = $connect();
    $controller = new JanitorProbe($db, new \App\Support\HookManager($db));
    file_put_contents($probeFile, 'not really an image');

    // Every statement on this connection now throws — the condition the dead
    // `=== false` guards were written for and never saw.
    $db->close();

    $threw = null;
    try {
        $controller->callImageJanitor($probeUrl);
    } catch (\Throwable $e) {
        $threw = $e;
    }
    $check($threw === null,
        'the image janitor swallows a dead connection ('
            . ($threw === null ? 'no throw' : get_class($threw) . ': ' . $threw->getMessage()) . ')');

    $threw = null;
    try {
        $controller->callPdfJanitor('whatever.pdf');
    } catch (\Throwable $e) {
        $threw = $e;
    }
    $check($threw === null,
        'the PDF janitor swallows a dead connection ('
            . ($threw === null ? 'no throw' : get_class($threw) . ': ' . $threw->getMessage()) . ')');

    // The point of swallowing is to protect the caller, not to widen the
    // deletion: a janitor that cannot prove the file is unreferenced must
    // leave it alone. Deleting on a failed probe would be data loss.
    $check(is_file($probeFile),
        'a janitor that could not run its reference check does NOT delete the file');

    echo "\nB. With a working connection the janitor still does its job\n";

    $db2 = $connect();
    $controller2 = new JanitorProbe($db2, new \App\Support\HookManager($db2));

    // Nothing in the schema references this name, so it is genuinely orphaned
    // and must be removed.
    $check(is_file($probeFile), 'the probe file is in place before the live run');
    $controller2->callImageJanitor($probeUrl);
    $check(!is_file($probeFile),
        'an unreferenced managed image IS deleted when the reference check can actually run');

    // A path outside the managed directory is none of the janitor's business,
    // whatever the connection state.
    $outside = $root . '/public/uploads/zz-janitor-outside-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($outside, 'x');
    $controller2->callImageJanitor('/uploads/' . basename($outside));
    $check(is_file($outside), 'a file outside /uploads/emeroteca/ is never touched');
    @unlink($outside);

    $db2->close();
} finally {
    $cleanup();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);
