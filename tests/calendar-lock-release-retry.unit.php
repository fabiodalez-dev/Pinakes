<?php
declare(strict_types=1);

/**
 * The calendar lock taken by the overdue notices and recalls
 * (NotificationService) and by the overdue/due-soon pushes (PushDispatcher)
 * is freed even when the first RELEASE_LOCK fails on a live session: a second
 * connection can take it right after the work, without waiting for the batch
 * (and its session) to end. The failure is injected by a mysqli subclass.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';

use App\Plugins\MobileApi\Push\NullProvider;
use App\Plugins\MobileApi\Push\PushDispatcher;
use App\Support\LibraryCalendar;
use App\Support\NotificationService;

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

/** Fails the first RELEASE_LOCK it is asked to run, as a flaky link would. */
final class FailFirstReleaseDb extends mysqli
{
    public int $releaseFailures = 1;

    public function query(string $query, int $resultMode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if (str_contains($query, 'RELEASE_LOCK') && $this->releaseFailures > 0) {
            $this->releaseFailures--;
            throw new mysqli_sql_exception('Injected release failure', 2013);
        }
        return parent::query($query, $resultMode);
    }
}

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$args = [
    getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'),
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null),
];

$other = new mysqli(...$args);
$lockName = $other->real_escape_string(LibraryCalendar::lockName($other));
$freeForOthers = static function () use ($other, $lockName): bool {
    $r = $other->query("SELECT IS_FREE_LOCK('{$lockName}')");
    return $r instanceof mysqli_result && (int) $r->fetch_row()[0] === 1;
};

foreach ([
    'NotificationService' => static fn (mysqli $db): object => new NotificationService($db),
    'PushDispatcher' => static fn (mysqli $db): object => new PushDispatcher($db, new NullProvider()),
] as $class => $make) {
    $db = new FailFirstReleaseDb(...$args);
    $service = $make($db);
    $method = new ReflectionMethod($service, 'underCalendarLock');
    $heldDuringWork = null;
    $result = $method->invoke($service, static function () use ($freeForOthers, &$heldDuringWork): string {
        $heldDuringWork = !$freeForOthers();
        return 'done';
    }, 'busy');
    $check($result === 'done' && $heldDuringWork === true, "{$class}: the work runs with the calendar lock held");
    $check($db->releaseFailures === 0, "{$class}: the first release failed (injected)");
    $check($freeForOthers(), "{$class}: another connection finds the lock free right after, the session still open");
    $db->close();
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
