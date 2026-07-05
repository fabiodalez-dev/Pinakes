<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\AffinityController;
use App\Plugins\BookClub\AffinityRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../AffinityRepo.php';
require_once __DIR__ . '/../AffinityController.php';

/**
 * Affinity module — Affinità tra lettori + Suggerimenti automatici
 * (plan §7.16). Disabled by default; a club opts in via its module list.
 *
 * READER AFFINITY ("Affinità con Marco: 92%")
 * -------------------------------------------
 * A 0–100 score between two ACTIVE members of the same club: cosine
 * similarity over combined preference vectors, with three components —
 *
 *   votes  (weight 0.40): the member's votes in the club's polls
 *          (bookclub_votes joined via bookclub_poll_options; dimension =
 *          option id, value = vote value);
 *   stars  (weight 0.40): approved core reviews (recensioni.stelle) on the
 *          club's books (dimension = libro id, value = stelle);
 *   genres (weight 0.20): genres of the books the member finished
 *          (bookclub_progress.finished_at joined to libri.genere_id;
 *          dimension = genere id, value = finished-book count).
 *
 * score(a,b) = round(100 · Σ_c w_c·cos_c(a,b) / Σ_c w_c) where the sums run
 * over the components AVAILABLE for the pair (both users have data and
 * share ≥ 1 dimension) — i.e. the weights are renormalised when a
 * component is missing. cos_c is the standard cosine over each user's full
 * component vector; all values are non-negative so cos_c ∈ [0, 1]. When
 * the pair shares fewer than 2 data points across all components the score
 * is NULL and rendered as "dati insufficienti". Full details in
 * AffinityRepo's docblock.
 *
 * PRIVACY (opt-in): scores are computed and shown ONLY between members
 * listed in bookclub_affinity_optin. Non-opted members never appear, and
 * opting out purges their stored pairs immediately.
 *
 * Recompute runs at most once per day per club — MAX(computed_at) guard —
 * both from the maintenance tick and lazily on page view.
 *
 * SUGGESTIONS (same page): catalog books the club never had (NOT IN
 * bookclub_books) matching the club's top-3 finished genres, best-rated
 * first (libri.rating DESC, NULLs last, limit 10), plus authors of
 * finished books with other catalog titles the club has not read (limit 5).
 * No cross-module coupling: proposing a suggested title happens from the
 * club page (link only).
 *
 * Tables: bookclub_affinity (INVARIANT: user_a < user_b),
 * bookclub_affinity_optin.
 */
class AffinityModule extends AbstractModule
{
    public function slug(): string
    {
        return 'affinity';
    }

    public function label(): string
    {
        return __('Affinità e suggerimenti');
    }

    public function description(): string
    {
        return __('Affinità di lettura tra membri (opt-in) e suggerimenti automatici dal catalogo');
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
        return $this->runDdl([
            // INVARIANT: user_a < user_b (unordered pairs, enforced by
            // AffinityRepo::recomputeClub which sorts ids before insert).
            // score NULL = insufficient shared data ("dati insufficienti").
            'bookclub_affinity' => "CREATE TABLE IF NOT EXISTS bookclub_affinity (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                user_a INT NOT NULL,
                user_b INT NOT NULL,
                score TINYINT UNSIGNED NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcaff_pair (club_id, user_a, user_b),
                KEY idx_bcaff_user_b (user_b),
                CONSTRAINT fk_bcaff_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcaff_user_a FOREIGN KEY (user_a)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcaff_user_b FOREIGN KEY (user_b)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_affinity_optin' => "CREATE TABLE IF NOT EXISTS bookclub_affinity_optin (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcaffopt (club_id, user_id),
                KEY idx_bcaffopt_user (user_id),
                CONSTRAINT fk_bcaffopt_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcaffopt_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new AffinityController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/affinity',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/affinity/optin',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->toggleOptIn($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panels
    // ------------------------------------------------------------------

    /** Main column stays clean: everything lives on the module page. */
    public function renderClubPanel(array $ctx): string
    {
        return '';
    }

    /** Small members-only teaser linking to the affinity page. */
    public function renderClubSidebar(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club)) {
            return '';
        }
        if (empty($ctx['isMember']) && empty($ctx['canManage'])) {
            return '';
        }
        return $this->renderPartial('partials/affinity_panel', [
            'club' => $club,
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance tick (background recompute, once per day per club)
    // ------------------------------------------------------------------

    public function onMaintenanceTick(): void
    {
        try {
            $affinity = new AffinityRepo($this->db);
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
                    if (!$affinity->isStale($clubId)) {
                        continue;
                    }
                    $affinity->recomputeClub($clubId);
                } catch (\Throwable $e) {
                    SecureLogger::warning('[BookClub:affinity] recompute failed for club ' . $clubId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:affinity] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
