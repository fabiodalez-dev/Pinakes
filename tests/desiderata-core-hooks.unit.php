<?php
declare(strict_types=1);

/**
 * T16 — the core extension points the desiderata plugin hangs from must be
 * INERT with no handler registered.
 *
 * This is the contract that makes rule 8 true for this feature: deactivating
 * the plugin deletes its plugin_hooks rows, and from that moment the core
 * must behave exactly as it did before the feature existed. The dangerous
 * half is BookVisibility::discoverable(): its return value is concatenated
 * straight into WHERE clauses, so a filter that could return an arbitrary
 * string would be an SQL-injection channel with a plugin row as its key.
 * Core therefore accepts one single widening value, '1=1', and discards
 * everything else — including a plausible-looking "1=1 OR 1=1".
 *
 * Why this needs a database even though it is a unit test: catalogue() is
 * only DIFFERENT from '1=1' when libri.is_desiderata exists. On a schema
 * without the column every assertion below would compare '1=1' to '1=1' and
 * pass while proving nothing, so the column is built (idempotently) first and
 * its presence is asserted before anything else runs.
 *
 * Run:  php tests/desiderata-core-hooks.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';

use App\Support\BookVisibility;
use App\Support\HookManager;
use App\Support\Hooks;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** .env is parsed by hand: this file must run as a bare `php tests/...` process. */
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

$fatalError = null;

try {
    // Built, never skipped: a missing column is precisely the condition that
    // would make every assertion below vacuous.
    (new DesiderataPlugin($db, new HookManager($db)))->ensureSchema();
    if (!BookVisibility::hasDesiderata($db)) {
        throw new RuntimeException('libri.is_desiderata is absent after ensureSchema(): the predicate checks would compare 1=1 with 1=1');
    }

    /**
     * A hook manager that has NOT read the database. setPluginsLoadedRuntime()
     * is what core's PluginManager calls once it has loaded plugins itself;
     * here it stands in for "this installation has no plugin registered on
     * these hooks", which is the state a deactivated plugin leaves behind.
     * Without it, loadHooks() would pick up the desiderata rows of the
     * development database and the "inert" assertions would be testing the
     * plugin instead of the core.
     */
    $freshManager = static function (mysqli $db): HookManager {
        $manager = new HookManager($db);
        $manager->setPluginsLoadedRuntime();

        return $manager;
    };

    echo "A. BookVisibility::discoverable() with no handler\n";
    Hooks::init($freshManager($db));
    $catalogue = BookVisibility::catalogue($db, 'l');
    $check($catalogue === 'l.is_desiderata = 0', 'the catalogue predicate really filters (not degraded to 1=1)');
    $check(BookVisibility::discoverable($db, 'l') === $catalogue, 'discoverable() is byte-identical to catalogue() with no handler');
    $check(BookVisibility::discoverable($db) === BookVisibility::catalogue($db), 'the default alias behaves the same way');

    echo "B. A handler may widen it to everything, and to nothing else\n";
    Hooks::init($freshManager($db));
    Hooks::add('book.visibility.discoverable', static fn(): string => '1=1');
    $check(BookVisibility::discoverable($db, 'l') === '1=1', 'a handler returning 1=1 widens the predicate');

    // Each hostile handler gets a manager of its own: addHook() appends, so
    // reusing one would leave the accepted '1=1' handler in the chain and the
    // rejection could never be observed.
    $rejected = [
        '1=1 OR 1=1' => 'a second, redundant disjunction',
        '1=1 OR l.id > 0' => 'a plausible-looking widening',
        "1=1 UNION SELECT password FROM utenti" => 'an outright injection',
        ' 1=1' => 'the accepted value with leading whitespace',
        '1=1 ' => 'the accepted value with trailing whitespace',
    ];
    foreach ($rejected as $payload => $description) {
        Hooks::init($freshManager($db));
        Hooks::add('book.visibility.discoverable', static fn(): string => $payload);
        $check(
            BookVisibility::discoverable($db, 'l') === $catalogue,
            'the predicate stays catalogue() when a handler returns ' . $description
        );
    }

    // A handler that returns something that is not a string at all — the shape
    // a careless plugin actually ships — must not reach the WHERE clause either.
    foreach ([['1=1'], null, 1, true] as $index => $nonString) {
        Hooks::init($freshManager($db));
        Hooks::add('book.visibility.discoverable', static fn(): mixed => $nonString);
        $check(
            BookVisibility::discoverable($db, 'l') === $catalogue,
            'the predicate stays catalogue() when a handler returns a non-string (case ' . ($index + 1) . ')'
        );
    }

    echo "C. The CMS hooks are pass-through with no handler\n";
    Hooks::init($freshManager($db));
    $check(Hooks::apply('cms.home.save', ['x'], [[]]) === ['x'], 'cms.home.save returns the incoming errors untouched');
    $check(Hooks::apply('cms.home.save', [], [['desiderata' => ['texts' => []]]]) === [], 'an empty error list survives a payload nobody handles');
    $check(Hooks::apply('cms.home.section_name', 'Desiderata', ['desiderata']) === 'Desiderata', 'cms.home.section_name returns the default label');

    // The two action hooks must print nothing at all: the acceptance criterion
    // for WP1/WP2 is a byte-identical render, and an action that emitted so
    // much as a newline would break it.
    ob_start();
    Hooks::do('frontend.home.section', ['desiderata', []]);
    Hooks::do('cms.home.section.fields', [[]]);
    Hooks::do('admin.dashboard.sections');
    $printed = (string) ob_get_clean();
    $check($printed === '', 'the three action hooks emit no output with no handler');

    echo "D. getSectionDisplayName() actually consults the filter\n";
    // The helper is declared inside a view that cannot be included on its own
    // (it renders a whole page and wants a CSRF token and a routed request).
    // Its source is lifted out and evaluated instead, so what is exercised is
    // the REAL core code: replicating the call here by hand would assert
    // nothing about the file that ships.
    $viewPath = $root . '/app/Views/cms/edit-home.php';
    $source = (string) @file_get_contents($viewPath);
    $start = strpos($source, 'function getSectionDisplayName');
    if ($start === false) {
        throw new RuntimeException('getSectionDisplayName() is gone from ' . $viewPath);
    }
    $open = strpos($source, '{', $start);
    if ($open === false) {
        throw new RuntimeException('cannot find the body of getSectionDisplayName()');
    }
    $depth = 0;
    $end = null;
    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
    }
    if ($end === null) {
        throw new RuntimeException('unbalanced braces while extracting getSectionDisplayName()');
    }
    // phpcs:ignore -- see the comment above: the alternative is not testing the shipped code.
    eval(substr($source, $start, $end - $start + 1));
    if (!function_exists('getSectionDisplayName')) {
        throw new RuntimeException('getSectionDisplayName() did not define itself');
    }

    Hooks::init($freshManager($db));
    $check(getSectionDisplayName('desiderata') === 'Desiderata', 'an unknown section falls back to the humanised key');
    $check(getSectionDisplayName('hero') === __('Hero - Testata principale'), 'a core section keeps its own label');

    Hooks::init($freshManager($db));
    Hooks::add('cms.home.section_name', static fn(string $label, string $key): string => $key === 'desiderata' ? 'Desiderata e donazioni' : $label);
    $check(getSectionDisplayName('desiderata') === 'Desiderata e donazioni', 'a handler renames its own row');
    $check(getSectionDisplayName('hero') === __('Hero - Testata principale'), 'and leaves every other row alone');

    Hooks::init($freshManager($db));
    Hooks::add('cms.home.section_name', static fn(): array => ['not', 'a', 'label']);
    $check(getSectionDisplayName('desiderata') === 'Desiderata', 'a handler returning a non-string is discarded instead of being echoed');
} catch (\Throwable $thrown) {
    // Recorded rather than rethrown so the connection is closed and the exit
    // code is the suite's own, not an uncaught-error stack trace.
    $fatalError = $thrown;
} finally {
    // Nothing was written to any table: this suite only reads schema metadata.
    Hooks::init(new HookManager($db));
    $db->close();
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo $fail === 0 ? "\nALL {$pass} PASS\n" : "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
