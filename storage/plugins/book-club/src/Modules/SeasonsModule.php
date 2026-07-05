<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\SeasonController;
use App\Plugins\BookClub\StatsRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php; StatsRepo is shared with StatsModule and
// require_once keeps the double include harmless).
require_once __DIR__ . '/../StatsRepo.php';
require_once __DIR__ . '/../SeasonController.php';

/**
 * Seasons module — Stagioni (plan §7.16).
 *
 * Groups club readings into named seasons ("2026 Primavera"): manager CRUD
 * from the club-page panel, a single `is_current` season per club, an
 * opportunistic sync that stamps season_id on books sitting in a
 * `current`-flagged workflow state, and a per-season historical archive of
 * the archived-flag books (storico).
 *
 * Table: bookclub_seasons. Also adds bookclub_books.season_id as a PLAIN
 * column (no FK) via addColumnIfMissing: the column lives on a core plugin
 * table that this optional module must extend idempotently — a named
 * FOREIGN KEY cannot be re-ADDed safely without extra INFORMATION_SCHEMA
 * guards, and referential integrity is already enforced in code (seasons
 * are only deletable while no book references them; a dangling season_id
 * would simply fall back to "no season" through the LEFT JOINs used
 * everywhere).
 */
class SeasonsModule extends AbstractModule
{
    public function slug(): string
    {
        return 'seasons';
    }

    public function label(): string
    {
        return __('Stagioni');
    }

    public function description(): string
    {
        return __('Organizza le letture in stagioni con archivio storico');
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
            'bookclub_seasons' => "CREATE TABLE IF NOT EXISTS bookclub_seasons (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                name VARCHAR(190) NOT NULL,
                starts_on DATE NULL,
                ends_on DATE NULL,
                books_target INT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcseasons_club (club_id, is_current),
                CONSTRAINT fk_bcseasons_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
        // Plain column, no FK — see class docblock for the rationale.
        $this->addColumnIfMissing('bookclub_books', 'season_id', 'INT NULL DEFAULT NULL');
        return $result;
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new SeasonController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/seasons/new',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->create($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/seasons/{seasonId:[0-9]+}/update',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->update($rq, $rs, (string) $a['slug'], (int) $a['seasonId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/seasons/{seasonId:[0-9]+}/current',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->setCurrent($rq, $rs, (string) $a['slug'], (int) $a['seasonId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/seasons/{seasonId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->delete($rq, $rs, (string) $a['slug'], (int) $a['seasonId'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Season assignment sync
    // ------------------------------------------------------------------

    /**
     * Opportunistic season assignment: books in a `current`-flagged state
     * that still lack a season_id get the club's current season. Called
     * from the club-page panel render and from the maintenance tick, so
     * books picked via poll auto-close get their season stamped too.
     * Idempotent (only NULL season_id rows are touched).
     *
     * @param array<string, mixed> $club hydrated club row
     */
    public function syncSeasonAssignments(array $club): void
    {
        try {
            $stats = new StatsRepo($this->db);
            $current = $stats->currentSeason((int) $club['id']);
            if ($current === null) {
                return;
            }
            $currentKeys = StatsRepo::currentStateKeys($this->repo->workflowStates($club));
            if ($currentKeys === []) {
                return;
            }
            $stats->assignSeasonToCurrentBooks((int) $club['id'], (int) $current['id'], $currentKeys);
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:seasons] sync failed for club ' . (int) ($club['id'] ?? 0) . ': ' . $e->getMessage());
        }
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
        $clubId = (int) $club['id'];
        $canManage = !empty($ctx['canManage']);
        $states = is_array($ctx['states'] ?? null) ? $ctx['states'] : [];

        try {
            $this->syncSeasonAssignments($club);
            $stats = new StatsRepo($this->db);
            $seasons = $stats->tableExists('bookclub_seasons') ? $stats->seasons($clubId) : [];
            $archived = $stats->archivedBooksBySeason($clubId, StatsRepo::archivedStateKeys($states));
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:seasons] panel failed: ' . $e->getMessage());
            return '';
        }

        // Nothing to show and nothing to manage → no panel.
        if ($seasons === [] && $archived === [] && !$canManage) {
            return '';
        }

        return $this->renderPartial('partials/seasons_panel', [
            'club' => $club,
            'seasons' => $seasons,
            'archivedBooks' => $archived,
            'canManage' => $canManage,
            'csrf' => (string) ($ctx['csrf'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance tick
    // ------------------------------------------------------------------

    public function onMaintenanceTick(): void
    {
        try {
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
                $this->syncSeasonAssignments($club);
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:seasons] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
