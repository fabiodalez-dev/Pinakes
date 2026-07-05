<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\ChallengeController;
use App\Plugins\BookClub\ChallengeRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../ChallengeRepo.php';
require_once __DIR__ . '/../ChallengeController.php';

/**
 * Challenges module — Reading Challenge (plan §7.16, Fase 3 — defaults OFF).
 *
 * Annual reading goals per club: club-wide challenges created by managers
 * (one progress row per active member, club total = SUM vs target) and
 * personal challenges any member creates for themselves. Progress is a
 * snapshot in bookclub_challenge_progress, recomputed from the Reading
 * module's tracker (bookclub_progress.finished_at) both by the maintenance
 * tick and lazily on page view — the formulas per metric are documented in
 * ChallengeRepo:
 *
 *  - books:   COUNT of the user's finished club books in year_ref
 *  - pages:   SUM(libri.numero_pagine) of those books (300-page fallback
 *             when the catalog has no page count for a book)
 *  - authors: COUNT DISTINCT autori of those books (via libri_autori)
 *
 * Tables: bookclub_challenges, bookclub_challenge_progress.
 */
class ChallengesModule extends AbstractModule
{
    public function slug(): string
    {
        return 'challenges';
    }

    public function label(): string
    {
        return __('Reading Challenge');
    }

    public function description(): string
    {
        return __('Obiettivi annuali di lettura, personali o di club, con avanzamento ricalcolato automaticamente');
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
            'bookclub_challenges' => "CREATE TABLE IF NOT EXISTS bookclub_challenges (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                user_id INT NULL,
                title VARCHAR(190) NOT NULL,
                metric ENUM('books','pages','authors') NOT NULL DEFAULT 'books',
                target INT NOT NULL DEFAULT 1,
                year_ref YEAR NOT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcchal_club_year (club_id, year_ref),
                KEY idx_bcchal_user (user_id),
                CONSTRAINT fk_bcchal_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcchal_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcchal_creator FOREIGN KEY (created_by)
                    REFERENCES utenti (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_challenge_progress' => "CREATE TABLE IF NOT EXISTS bookclub_challenge_progress (
                id INT NOT NULL AUTO_INCREMENT,
                challenge_id INT NOT NULL,
                user_id INT NOT NULL,
                `current` INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcchalprog (challenge_id, user_id),
                KEY idx_bcchalprog_user (user_id),
                CONSTRAINT fk_bcchalprog_chal FOREIGN KEY (challenge_id)
                    REFERENCES bookclub_challenges (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcchalprog_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new ChallengeController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/challenges',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/challenges',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->create($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/challenges/{challengeId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->delete($rq, $rs, (string) $a['slug'], (int) $a['challengeId'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panel
    // ------------------------------------------------------------------

    /**
     * "My active challenges": the viewing member's personal challenges plus
     * the club-wide ones for the current year, each with a progress bar and
     * a link to the full challenges page. Members only.
     */
    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club)) {
            return '';
        }
        if (empty($ctx['isMember'])) {
            return '';
        }
        $userId = isset($ctx['userId']) && $ctx['userId'] !== null ? (int) $ctx['userId'] : null;
        if ($userId === null) {
            return '';
        }
        $clubId = (int) $club['id'];
        $year = (int) date('Y');

        try {
            $challenges = new ChallengeRepo($this->db);
            $mine = $challenges->userProgressMap($clubId, $year, $userId);
            $items = [];
            foreach ($challenges->challengesForYear($clubId, $year) as $challenge) {
                $isClubWide = $challenge['user_id'] === null;
                $isOwn = !$isClubWide && (int) $challenge['user_id'] === $userId;
                if (!$isClubWide && !$isOwn) {
                    continue;
                }
                $items[] = [
                    'challenge' => $challenge,
                    'isClubWide' => $isClubWide,
                    // Club-wide bars show the club total, personal bars my snapshot.
                    'current' => $isClubWide
                        ? (int) $challenge['total_current']
                        : (int) ($mine[(int) $challenge['id']] ?? 0),
                ];
                if (count($items) >= 4) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:challenges] panel failed: ' . $e->getMessage());
            return '';
        }
        if ($items === []) {
            return '';
        }

        return $this->renderPartial('partials/challenges_panel', [
            'club' => $club,
            'items' => $items,
            'year' => $year,
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance tick: recompute current-year snapshots
    // ------------------------------------------------------------------

    /**
     * Refresh every current-year challenge of every active club with the
     * module enabled. Idempotent — re-running only rewrites the snapshots.
     */
    public function onMaintenanceTick(): void
    {
        try {
            $challenges = new ChallengeRepo($this->db);
            if (!$challenges->readingSchemaReady()) {
                return; // Reading module tables absent: nothing to compute.
            }
            $year = (int) date('Y');
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
                    $challenges->recomputeClub($clubId, $year);
                } catch (\Throwable $e) {
                    SecureLogger::warning('[BookClub:challenges] recompute failed for club ' . $clubId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:challenges] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
