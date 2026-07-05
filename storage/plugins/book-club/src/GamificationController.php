<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\GamificationModule;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gamification module (plan §7.12): the public per-club leaderboard page.
 *
 * The page is members/managers only (same gate as the stats page). Before
 * rendering, the XP snapshot + badge awards are refreshed lazily when older
 * than one hour (GamificationRepo::isStale) — the maintenance tick performs
 * the same refresh in the background, so viewing the page merely guarantees
 * freshness on installs without a working cron.
 */
class GamificationController extends BaseController
{
    private GamificationModule $module;
    private GamificationRepo $gam;

    public function __construct(\mysqli $db, Repo $repo, GamificationModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->gam = new GamificationRepo($db);
    }

    /**
     * Resolve a club by slug enforcing module enablement (→ null = 404).
     *
     * @return array<string, mixed>|null
     */
    private function resolve(string $slug): ?array
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return null;
        }
        return $club;
    }

    // ------------------------------------------------------------------
    // GET /book-club/{slug}/leaderboard  (active members + managers)
    // ------------------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->resolve($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $canManage = $this->canManage($club);
        if (!$this->isActiveMember($club) && !$canManage) {
            return $this->notFound($response);
        }
        $clubId = (int) $club['id'];

        // Lazy refresh, throttled to once per hour per club. A failure here
        // must not break the page: we still render the last snapshot.
        try {
            if ($this->gam->isStale($clubId, GamificationModule::REFRESH_INTERVAL_SECONDS)) {
                $memberIds = [];
                foreach ($this->repo->listMembers($clubId, 'active') as $member) {
                    $memberIds[] = (int) $member['user_id'];
                }
                $this->gam->refreshClub($clubId, $memberIds);
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:' . $slug . '] gamification lazy refresh failed: ' . $e->getMessage());
        }

        $badgesByUser = $this->gam->badgesByUser($clubId);
        $userId = $this->userId();

        $ranking = [];
        $me = null;
        foreach ($this->gam->leaderboard($clubId, 100) as $i => $row) {
            $rowUserId = (int) $row['user_id'];
            $xp = (int) $row['xp'];
            $entry = [
                'rank' => $i + 1,
                'user_id' => $rowUserId,
                'name' => trim((string) $row['nome'] . ' ' . (string) $row['cognome']),
                'xp' => $xp,
                'level' => GamificationRepo::level($xp),
                'badges' => $badgesByUser[$rowUserId] ?? [],
                'is_me' => $userId !== null && $rowUserId === $userId,
            ];
            if ($entry['is_me']) {
                $me = $entry;
            }
            $ranking[] = $entry;
        }

        return $this->renderPublic($response, 'public/leaderboard', [
            'club' => $club,
            'ranking' => $ranking,
            'me' => $me,
            'allBadges' => $this->gam->allBadges(),
            'canManage' => $canManage,
        ], __('Classifica') . ' — ' . (string) $club['name']);
    }
}
