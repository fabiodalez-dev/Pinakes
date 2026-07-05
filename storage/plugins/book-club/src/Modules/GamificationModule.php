<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\GamificationController;
use App\Plugins\BookClub\GamificationRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../GamificationRepo.php';
require_once __DIR__ . '/../GamificationController.php';

/**
 * Gamification module — XP, levels, badges, leaderboard (plan §7.12).
 * OFF by default ("Biblioteca" / "Circolo semplice" presets).
 *
 * DESIGN: the module never hooks into the other modules' code. XP and
 * badges are RECOMPUTED from their source tables (bookclub_progress,
 * bookclub_books, bookclub_votes, bookclub_meeting_rsvps, bookclub_posts,
 * recensioni, bookclub_meetings) with cheap COUNT/GROUP BY queries — see
 * GamificationRepo for the documented XP formula and level curve. The
 * recompute runs on the maintenance tick AND lazily when the leaderboard
 * page is viewed, throttled to at most once per hour per club via
 * MAX(bookclub_xp_snapshot.computed_at).
 *
 * Tables: bookclub_badges (data-driven rules, seeded here),
 * bookclub_user_badges (awards, INSERT IGNORE), bookclub_xp_snapshot.
 */
class GamificationModule extends AbstractModule
{
    /** Snapshot/award refresh throttle: at most once per hour per club. */
    public const REFRESH_INTERVAL_SECONDS = 3600;

    public function slug(): string
    {
        return 'gamification';
    }

    public function label(): string
    {
        return __('Gamification');
    }

    public function description(): string
    {
        return __('Punti esperienza, livelli, badge e classifica dei membri del club');
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function ensureSchema(): array
    {
        $result = $this->runDdl([
            'bookclub_badges' => "CREATE TABLE IF NOT EXISTS bookclub_badges (
                id INT NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(190) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                icon VARCHAR(50) NOT NULL DEFAULT 'fa-medal',
                rule TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcbadge_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_user_badges' => "CREATE TABLE IF NOT EXISTS bookclub_user_badges (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                badge_id INT NOT NULL,
                club_id INT NOT NULL,
                awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcubadge (user_id, badge_id, club_id),
                KEY idx_bcubadge_club (club_id, user_id),
                CONSTRAINT fk_bcubadge_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcubadge_badge FOREIGN KEY (badge_id)
                    REFERENCES bookclub_badges (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcubadge_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_xp_snapshot' => "CREATE TABLE IF NOT EXISTS bookclub_xp_snapshot (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                user_id INT NOT NULL,
                xp INT NOT NULL DEFAULT 0,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcxp (club_id, user_id),
                KEY idx_bcxp_user (user_id),
                CONSTRAINT fk_bcxp_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcxp_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);

        try {
            $this->seedBadges();
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:gamification] badge seeding failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Seed the default badge catalogue, idempotent by slug
     * (INSERT … WHERE NOT EXISTS). rule JSON: {"metric":"…","gte":N}.
     */
    private function seedBadges(): void
    {
        $gam = new GamificationRepo($this->db);
        $badges = [
            ['primo-libro', __('Primo libro'), __('Hai concluso il tuo primo libro con il club'), 'fa-book-open', ['metric' => 'finished', 'gte' => 1]],
            ['lettore-10', __('Lettore instancabile'), __('Hai concluso 10 libri con il club'), 'fa-book-reader', ['metric' => 'finished', 'gte' => 10]],
            ['lettore-50', __('Leggenda del club'), __('Hai concluso 50 libri con il club'), 'fa-crown', ['metric' => 'finished', 'gte' => 50]],
            ['recensionista', __('Recensionista'), __('Hai pubblicato 5 recensioni approvate di libri del club'), 'fa-star', ['metric' => 'reviews', 'gte' => 5]],
            ['votante', __('Votante'), __('Hai espresso 10 voti nelle votazioni del club'), 'fa-vote-yea', ['metric' => 'votes', 'gte' => 10]],
            ['organizzatore', __('Organizzatore'), __('Hai organizzato 3 incontri del club'), 'fa-calendar-plus', ['metric' => 'meetings_created', 'gte' => 3]],
        ];
        foreach ($badges as [$slug, $name, $description, $icon, $rule]) {
            $ruleJson = json_encode($rule, JSON_UNESCAPED_UNICODE);
            $gam->seedBadge($slug, $name, $description, $icon, $ruleJson === false ? '{}' : $ruleJson);
        }
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new GamificationController($this->db, $this->repo, $this);
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/leaderboard',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panel
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club)) {
            return '';
        }
        $isMember = !empty($ctx['isMember']);
        $canManage = !empty($ctx['canManage']);
        // Like the stats panel: members/managers only.
        if (!$isMember && !$canManage) {
            return '';
        }
        $clubId = (int) $club['id'];
        $userId = isset($ctx['userId']) && $ctx['userId'] !== null ? (int) $ctx['userId'] : null;

        try {
            $gam = new GamificationRepo($this->db);
            // Same hourly throttle as the leaderboard page / tick.
            if ($gam->isStale($clubId, self::REFRESH_INTERVAL_SECONDS)) {
                $gam->refreshClub($clubId, $this->activeMemberIds($clubId));
            }

            $badgesByUser = $gam->badgesByUser($clubId);

            $top = [];
            foreach ($gam->leaderboard($clubId, 3) as $row) {
                $xp = (int) $row['xp'];
                $top[] = [
                    'name' => trim((string) $row['nome'] . ' ' . (string) $row['cognome']),
                    'xp' => $xp,
                    'level' => GamificationRepo::level($xp),
                ];
            }

            $mine = null;
            if ($isMember && $userId !== null) {
                $xp = $gam->xpFor($clubId, $userId);
                $level = GamificationRepo::level($xp);
                $mine = [
                    'xp' => $xp,
                    'level' => $level,
                    'level_start' => GamificationRepo::levelStartXp($level),
                    'next_level_xp' => GamificationRepo::levelStartXp($level + 1),
                    'badges' => $badgesByUser[$userId] ?? [],
                ];
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:gamification] panel failed: ' . $e->getMessage());
            return '';
        }

        return $this->renderPartial('partials/gamification_panel', [
            'club' => $club,
            'mine' => $mine,
            'top' => $top,
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance tick (background recompute)
    // ------------------------------------------------------------------

    /**
     * Refresh snapshots + awards of every active club with the module
     * enabled, skipping clubs recomputed less than an hour ago.
     */
    public function onMaintenanceTick(): void
    {
        try {
            $gam = new GamificationRepo($this->db);
            foreach ($this->repo->listAllClubs() as $club) {
                if ((int) ($club['is_active'] ?? 0) !== 1) {
                    continue;
                }
                // listAllClubs returns raw rows: decode settings so the
                // per-club enablement check sees the modules list.
                $settings = json_decode((string) ($club['settings'] ?? ''), true);
                $club['settings'] = is_array($settings) ? $settings : [];
                if (!$this->enabledFor($club)) {
                    continue;
                }
                $clubId = (int) $club['id'];
                try {
                    if (!$gam->isStale($clubId, self::REFRESH_INTERVAL_SECONDS)) {
                        continue;
                    }
                    $gam->refreshClub($clubId, $this->activeMemberIds($clubId));
                } catch (\Throwable $e) {
                    SecureLogger::warning('[BookClub:gamification] refresh failed for club ' . $clubId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:gamification] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }

    /** @return list<int> */
    private function activeMemberIds(int $clubId): array
    {
        $ids = [];
        foreach ($this->repo->listMembers($clubId, 'active') as $member) {
            $ids[] = (int) $member['user_id'];
        }
        return $ids;
    }
}
