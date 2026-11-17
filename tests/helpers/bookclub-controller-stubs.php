<?php
declare(strict_types=1);

/**
 * In-memory doubles for DB-free tests of the Book Club controllers
 * (security-audit-bookclub-*.unit.php). The REAL controller methods run; only
 * the persistence (Repo) and the governance lookup (can()) are replaced, so a
 * test drives the exact authorization branches without a database.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';
require_once $root . '/storage/plugins/book-club/src/Modules/MobileModule.php';

use App\Plugins\BookClub\BaseController;
use App\Plugins\BookClub\MobileApiController;
use App\Plugins\BookClub\Modules\MobileModule;
use App\Plugins\BookClub\PublicController;
use App\Plugins\BookClub\Repo;
use App\Plugins\BookClub\BookClubPlugin;

final class BcFakeRepo extends Repo
{
    /** @var array<string, array<string, mixed>> clubs by slug */
    public array $clubs = [];
    /** @var array<string, array<string, mixed>> membership rows keyed "clubId:userId" */
    public array $members = [];
    /** @var array<int, array<string, mixed>> club books by id */
    public array $books = [];
    /** @var array<string, mixed>|null */
    public ?array $invitation = null;
    /** @var list<list<mixed>> every write the controller attempted */
    public array $writes = [];

    public function clubBySlug(string $slug): ?array { return $this->clubs[$slug] ?? null; }

    public function clubById(int $id): ?array
    {
        foreach ($this->clubs as $club) {
            if ((int) $club['id'] === $id) {
                return $club;
            }
        }
        return null;
    }

    public function memberRow(int $clubId, int $userId): ?array { return $this->members[$clubId . ':' . $userId] ?? null; }

    public function roleIdBySlug(string $slug): ?int { return 3; }

    public function countActiveMembers(int $clubId): int { return 1; }

    public function clubManagerEmails(int $clubId): array { return []; }

    /** A status that lands on the row between the controller's check and its write (a concurrent ban). */
    public ?string $concurrentStatus = null;

    /** Like the real one: a settled row (active, suspended, banned) keeps its status unless overridden. */
    public function upsertMember(int $clubId, int $userId, int $roleId, string $status, ?int $invitedBy = null, bool $overrideSettled = false): bool
    {
        $this->writes[] = ['upsertMember', $clubId, $userId, $status];
        $key = $clubId . ':' . $userId;
        $row = $this->members[$key] ?? ['id' => 99, 'club_id' => $clubId, 'user_id' => $userId, 'role_slug' => 'member', 'status' => null];
        if ($this->concurrentStatus !== null) {
            $row['status'] = $this->concurrentStatus;
        }
        if ($overrideSettled || !in_array($row['status'], ['active', 'suspended', 'banned'], true)) {
            $row['status'] = $status;
        }
        $this->members[$key] = $row;
        return true;
    }

    /** A status another request writes first, so setMemberStatus()'s $onlyFrom no longer matches. */
    public ?string $statusChangedMeanwhile = null;
    /** Make setMemberStatus() fail as a database error would. */
    public bool $statusWriteFails = false;

    public function memberById(int $memberId): ?array
    {
        foreach ($this->members as $row) {
            if ((int) $row['id'] === $memberId) {
                return $row;
            }
        }
        return null;
    }

    public function setMemberStatus(int $memberId, string $status, ?array $onlyFrom = null): bool
    {
        $this->writes[] = ['setMemberStatus', $memberId, $status];
        foreach ($this->members as $key => $row) {
            if ((int) $row['id'] !== $memberId) {
                continue;
            }
            if ($this->statusChangedMeanwhile !== null) {
                $this->members[$key]['status'] = $row['status'] = $this->statusChangedMeanwhile;
            }
            if ($this->statusWriteFails || ($onlyFrom !== null && !in_array($row['status'], $onlyFrom, true))) {
                return false;
            }
            $this->members[$key]['status'] = $status;
            return true;
        }
        return false;
    }

    public function pendingInvitationByToken(string $token): ?array { return $this->invitation; }

    public function markInvitationAccepted(int $invitationId): bool
    {
        $this->writes[] = ['markInvitationAccepted', $invitationId];
        return true;
    }

    public function workflowStates(array $club): array
    {
        $out = [];
        foreach (BookClubPlugin::defaultWorkflowStates() as $s) {
            $out[] = ['key' => (string) $s['key'], 'label' => (string) $s['label'], 'color' => (string) $s['color'], 'flags' => $s['flags']];
        }
        return $out;
    }

    public function clubBook(int $clubBookId): ?array { return $this->books[$clubBookId] ?? null; }

    public function changeBookState(int $clubBookId, string $fromState, string $toState, ?int $changedBy): bool
    {
        $this->writes[] = ['changeBookState', $clubBookId, $fromState, $toState];
        $this->books[$clubBookId]['state'] = $toState;
        return true;
    }

    public function deleteClubBook(int $clubBookId): bool
    {
        $this->writes[] = ['deleteClubBook', $clubBookId];
        unset($this->books[$clubBookId]);
        return true;
    }

    public function setBookReadingDates(int $clubBookId, ?string $starts, ?string $ends): bool { return true; }

    /** @var list<array<string, mixed>> rows returned by listClubsForUser() (club + member_status) */
    public array $userClubs = [];
    /** @var list<int> club ids clubSnapshot() was asked for */
    public array $snapshotsTaken = [];

    public function listClubsForUser(int $userId): array { return $this->userClubs; }

    public function clubSnapshot(array $club): array
    {
        $this->snapshotsTaken[] = (int) $club['id'];
        return [
            'current_books' => [['id' => 1, 'titolo' => 'Members-only book ' . $club['slug']]],
            'next_meeting' => ['id' => 2, 'title' => 'Members-only meeting ' . $club['slug']],
            'open_polls' => [['id' => 3, 'title' => 'Members-only poll ' . $club['slug']]],
        ];
    }
}

/** PublicController with the governance matrix replaced by a fixed grant list. */
final class BcTestPublicController extends PublicController
{
    /** @var list<string> */
    public array $grants = [];

    protected function can(array $club, string $perm): bool { return in_array($perm, $this->grants, true); }

    /** Expose the protected gate under test. */
    public function contentVisible(array $club): bool { return $this->canSeeContent($club); }
}

/**
 * Build a controller of $class wired to $repo without a database handle:
 * the methods under test never reach $this->db.
 *
 * @template T of BaseController
 * @param class-string<T> $class
 * @return T
 */
function bcController(string $class, BcFakeRepo $repo): BaseController
{
    $ctrl = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty(BaseController::class, 'repo');
    $prop->setValue($ctrl, $repo);
    if ($ctrl instanceof MobileApiController) {
        $module = new ReflectionProperty(MobileApiController::class, 'module');
        $module->setValue($ctrl, (new ReflectionClass(MobileModule::class))->newInstanceWithoutConstructor());
    }
    return $ctrl;
}

function bcFakeRepo(): BcFakeRepo
{
    return (new ReflectionClass(BcFakeRepo::class))->newInstanceWithoutConstructor();
}

/** @param array<string, mixed> $extra */
function bcClub(int $id, string $slug, string $privacy, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'slug' => $slug, 'name' => 'Audit ' . $slug, 'privacy' => $privacy,
        'is_active' => 1, 'max_members' => null, 'settings' => [], 'workflow_id' => null,
        'ics_token' => str_repeat('a', 32),
    ], $extra);
}

function bcLogin(int $userId, string $tipo = 'standard', string $email = 'reader@audit.invalid'): void
{
    $_SESSION = ['user' => ['id' => $userId, 'tipo_utente' => $tipo, 'email' => $email, 'nome' => 'Audit', 'cognome' => 'Reader']];
}

function bcRequest(string $method = 'POST', array $body = []): \Psr\Http\Message\ServerRequestInterface
{
    return (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, '/')->withParsedBody($body);
}

function bcResponse(): \Psr\Http\Message\ResponseInterface
{
    return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse();
}
