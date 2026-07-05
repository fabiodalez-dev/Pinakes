<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\ExtensionsRepo;
use App\Plugins\BookClub\SprintController;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php). ExtensionsRepo is shared with the
// buddy module — require_once dedupes.
require_once __DIR__ . '/../ExtensionsRepo.php';
require_once __DIR__ . '/../SprintController.php';

/**
 * Sprints module — Reading Sprint cronometrati (plan §7.17, Fase 4).
 *
 * Timed group reading sessions: a member schedules a sprint (title,
 * optional club book, start datetime, 5–480 minutes), members join or
 * leave before the start, the status is DERIVED FROM THE CLOCK at read
 * time (scheduled → running → done; only 'cancelled' — by the creator or
 * a manager — and the lazily persisted 'done' are stored) and after the
 * end each participant logs the pages read for the results board.
 *
 * Tables: bookclub_sprints, bookclub_sprint_participants.
 * Opt-in per club (defaultEnabled = false).
 */
class SprintsModule extends AbstractModule
{
    public function slug(): string
    {
        return 'sprints';
    }

    public function label(): string
    {
        return __('Reading Sprint');
    }

    public function description(): string
    {
        return __('Sessioni di lettura cronometrate con partecipanti e classifica delle pagine lette');
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
            'bookclub_sprints' => "CREATE TABLE IF NOT EXISTS bookclub_sprints (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                club_book_id INT NULL,
                title VARCHAR(190) NOT NULL,
                starts_at DATETIME NOT NULL,
                duration_min INT NOT NULL DEFAULT 30,
                created_by INT NULL,
                status ENUM('scheduled','running','done','cancelled') NOT NULL DEFAULT 'scheduled',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcsprint_club (club_id, starts_at),
                CONSTRAINT fk_bcsprint_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcsprint_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE SET NULL,
                CONSTRAINT fk_bcsprint_user FOREIGN KEY (created_by)
                    REFERENCES utenti (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_sprint_participants' => "CREATE TABLE IF NOT EXISTS bookclub_sprint_participants (
                id INT NOT NULL AUTO_INCREMENT,
                sprint_id INT NOT NULL,
                user_id INT NOT NULL,
                pages_read INT NULL,
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcsprintp (sprint_id, user_id),
                KEY idx_bcsprintp_user (user_id),
                CONSTRAINT fk_bcsprintp_sprint FOREIGN KEY (sprint_id)
                    REFERENCES bookclub_sprints (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcsprintp_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new SprintController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/sprints',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->index($rq, $rs, (string) $a['slug'])
        );
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/sprints',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->create($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/sprints/{id:[0-9]+}/join',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->join($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/sprints/{id:[0-9]+}/leave',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->leave($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/sprints/{id:[0-9]+}/cancel',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->cancel($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/sprints/{id:[0-9]+}/pages',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->logPages($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
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
        $userId = isset($ctx['userId']) && $ctx['userId'] !== null ? (int) $ctx['userId'] : null;

        try {
            $ext = new ExtensionsRepo($this->db);
            $next = $ext->nextSprint((int) $club['id']);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:sprints] panel failed: ' . $e->getMessage());
            return '';
        }
        // Nothing scheduled and the viewer cannot schedule one: no panel.
        if ($next === null && !$isMember) {
            return '';
        }
        $joined = false;
        if ($next !== null && $userId !== null) {
            $joined = $ext->participantRow((int) $next['id'], $userId) !== null;
        }

        return $this->renderPartial('partials/sprints_panel', [
            'club' => $club,
            'next' => $next,
            'nextStatus' => $next !== null ? ExtensionsRepo::effectiveStatus($next) : null,
            'joined' => $joined,
            'isMember' => $isMember,
            'csrf' => (string) ($ctx['csrf'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------------
    // Maintenance
    // ------------------------------------------------------------------

    /**
     * Lazily persist the derived 'done' status on ended sprints (cosmetic:
     * effectiveStatus() derives it at read time anyway). Never throws.
     */
    public function onMaintenanceTick(): void
    {
        try {
            if (!$this->tableExists('bookclub_sprints')) {
                return;
            }
            (new ExtensionsRepo($this->db))->markEndedSprintsDone();
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:sprints] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }
}
