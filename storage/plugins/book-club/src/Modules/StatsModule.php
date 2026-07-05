<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\StatsController;
use App\Plugins\BookClub\StatsRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../StatsRepo.php';
require_once __DIR__ . '/../StatsController.php';

/**
 * Stats module — Statistiche + export (plan §7.11 and §7.17).
 *
 * Club-page panel with the club headline numbers and the viewing member's
 * personal activity (votes cast, yes-RSVPs, posts when the discussions
 * module is installed), a full stats page for members
 * (/book-club/{slug}/stats) mirrored in the admin area, a daily metric
 * rollup into bookclub_stats_daily on the maintenance tick, and a
 * manager-only full-history export (JSON/CSV).
 *
 * Table: bookclub_stats_daily.
 */
class StatsModule extends AbstractModule
{
    /** Metrics written by the daily rollup. */
    public const METRICS = ['members_active', 'books_total', 'books_finished_total', 'polls_open', 'posts_total'];

    public function slug(): string
    {
        return 'stats';
    }

    public function label(): string
    {
        return __('Statistiche');
    }

    public function description(): string
    {
        return __('Statistiche di lettura del club e dei membri, con export dei dati');
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function ensureSchema(): array
    {
        return $this->runDdl([
            'bookclub_stats_daily' => "CREATE TABLE IF NOT EXISTS bookclub_stats_daily (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                stat_date DATE NOT NULL,
                metric VARCHAR(50) NOT NULL,
                value INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcstats (club_id, stat_date, metric),
                CONSTRAINT fk_bcstats_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new StatsController($this->db, $this->repo, $this);
        $adminMw = new \App\Middleware\AdminAuthMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/stats',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/export.json',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->exportJson($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/export.csv',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->exportCsv($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->get(
            '/admin/book-club/{id:[0-9]+}/stats',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->adminShow($rq, $rs, (int) $a['id'])
        )->add($adminMw);
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
        // The panel (like the stats page) is members/managers-only.
        if (!$isMember && !$canManage) {
            return '';
        }
        $clubId = (int) $club['id'];
        $states = is_array($ctx['states'] ?? null) ? $ctx['states'] : [];
        $userId = isset($ctx['userId']) && $ctx['userId'] !== null ? (int) $ctx['userId'] : null;

        try {
            $stats = new StatsRepo($this->db);
            $headline = [
                'books_total' => $stats->totalBookCount($clubId),
                'finished' => $stats->finishedBookCount($clubId, StatsRepo::finishedStateKeys($states)),
                'members_active' => $this->repo->countActiveMembers($clubId),
                'meetings_done' => $stats->meetingsHeld($clubId),
            ];
            $mine = null;
            if ($isMember && $userId !== null) {
                $mine = [
                    'votes_cast' => $stats->votesCastBy($clubId, $userId),
                    'rsvp_yes' => $stats->yesRsvpsBy($clubId, $userId),
                    // Null when the discussions module tables are absent.
                    'posts_written' => $stats->postsWrittenBy($clubId, $userId),
                ];
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:stats] panel failed: ' . $e->getMessage());
            return '';
        }

        return $this->renderPartial('partials/stats_panel', [
            'club' => $club,
            'headline' => $headline,
            'mine' => $mine,
            'canManage' => $canManage,
        ]);
    }

    // ------------------------------------------------------------------
    // Daily rollup (maintenance tick)
    // ------------------------------------------------------------------

    /**
     * INSERT … ON DUPLICATE KEY UPDATE today's metrics for every active club
     * with the module enabled. Cheap COUNTs only; re-running the tick on the
     * same day just refreshes the values.
     */
    public function onMaintenanceTick(): void
    {
        try {
            $stats = new StatsRepo($this->db);
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
                    $states = $this->repo->workflowStates($club);
                    $stats->upsertDailyMetric($clubId, 'members_active', $this->repo->countActiveMembers($clubId));
                    $stats->upsertDailyMetric($clubId, 'books_total', $stats->totalBookCount($clubId));
                    $stats->upsertDailyMetric($clubId, 'books_finished_total', $stats->finishedBookCount($clubId, StatsRepo::finishedStateKeys($states)));
                    $stats->upsertDailyMetric($clubId, 'polls_open', $stats->openPollCount($clubId));
                    $posts = $stats->totalPostCount($clubId); // null → discussions tables absent
                    if ($posts !== null) {
                        $stats->upsertDailyMetric($clubId, 'posts_total', $posts);
                    }
                } catch (\Throwable $e) {
                    SecureLogger::warning('[BookClub:stats] rollup failed for club ' . $clubId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:stats] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
