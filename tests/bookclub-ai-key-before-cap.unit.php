<?php
declare(strict_types=1);

/**
 * Book club AI: a generation without an API key must not use up a daily slot.
 *
 * reserveAttempt() books a row against the per-club daily cap before the paid
 * call. When no key is set, generate() returns null without calling anyone, so
 * booking first burned a slot for nothing. Both generators now check apiKey()
 * before booking. The controller runs here against stubs (no database): the
 * service says "configured" at the gate but has no key at call time, which is
 * the window the check closes.
 *
 * Run: php tests/bookclub-ai-key-before-cap.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';
require_once $root . '/storage/plugins/book-club/src/AiService.php';
require_once $root . '/storage/plugins/book-club/src/AiController.php';
require_once $root . '/storage/plugins/book-club/src/Modules/AiModule.php';

use App\Plugins\BookClub\AiController;
use App\Plugins\BookClub\AiService;
use App\Plugins\BookClub\Modules\AiModule;
use App\Plugins\BookClub\Repo;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$db = mysqli_init();

$repo = new class extends Repo {
    public function __construct() {}
    public function clubBySlug(string $slug): ?array
    {
        return ['id' => 1, 'slug' => $slug, 'name' => 'Club', 'privacy' => 'public'];
    }
    public function clubBook(int $clubBookId): ?array
    {
        return ['id' => $clubBookId, 'club_id' => 1, 'titolo' => 'Titolo', 'autori' => '', 'libro_id' => 9];
    }
    public function meeting(int $meetingId): ?array
    {
        return ['id' => $meetingId, 'club_id' => 1, 'title' => 'Incontro', 'minutes' => 'Verbale.'];
    }
};

$module = new class ($db, $repo) extends AiModule {
    public function enabledFor(array $club): bool
    {
        return true;
    }
};

$makeService = static function (string $key) use ($db): AiService {
    return new class ($db, $key) extends AiService {
        /** @var list<string> */
        public array $calls = [];
        public function __construct(\mysqli $db, private string $key)
        {
            parent::__construct($db);
        }
        public function isConfigured(): bool { return true; }
        public function underDailyCap(int $clubId): bool { return true; }
        public function apiKey(): string { return $this->key; }
        public function model(): string { return 'test-model'; }
        public function bookDescription(int $libroId): string { return ''; }
        public function promptLanguageName(): string { return 'italiano'; }
        public function reserveAttempt(int $clubId, string $kind, int $entityId, ?int $createdBy): ?int
        {
            $this->calls[] = 'reserve:' . $kind;
            return 77;
        }
        public function generate(string $system, string $user): ?string
        {
            $this->calls[] = 'generate';
            return $this->key === '' ? null : 'ok';
        }
        public function completeAttempt(int $outputId, string $content): bool
        {
            $this->calls[] = 'complete';
            return true;
        }
    };
};

$makeController = static function (AiService $service) use ($db, $repo, $module): AiController {
    $controller = new class ($db, $repo, $module) extends AiController {
        protected function canView(array $club): bool { return true; }
        protected function canManage(array $club): bool { return true; }
        protected function userId(): ?int { return 5; }
    };
    $prop = new ReflectionProperty(AiController::class, 'ai');
    $prop->setValue($controller, $service);
    return $controller;
};

$request = static fn (array $body) => (new ServerRequestFactory())
    ->createServerRequest('POST', '/book-club/club/ai')
    ->withParsedBody($body);

foreach ([
    ['generateQuestions', ['club_book_id' => '5'], 'questions'],
    ['generateMinutes', ['meeting_id' => '6'], 'minutes'],
] as [$method, $body, $kind]) {
    $_SESSION = [];
    $noKey = $makeService('');
    $response = $makeController($noKey)->{$method}($request($body), new Response(), 'club');
    $check($noKey->calls === [], "{$method}: without an API key no daily slot is booked and nothing is called");
    $check($response->getStatusCode() === 302 && ($_SESSION['bookclub_flash']['type'] ?? '') === 'error',
        "{$method}: the manager is sent back with an error");

    $_SESSION = [];
    $withKey = $makeService('sk-test');
    $makeController($withKey)->{$method}($request($body), new Response(), 'club');
    $check($withKey->calls === ['reserve:' . $kind, 'generate', 'complete'],
        "{$method}: with a key the slot is booked, then the call runs");
}

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
