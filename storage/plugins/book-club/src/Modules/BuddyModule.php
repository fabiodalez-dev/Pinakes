<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\BuddyController;
use App\Plugins\BookClub\ExtensionsRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php). ExtensionsRepo is shared with the
// sprints module — require_once dedupes.
require_once __DIR__ . '/../ExtensionsRepo.php';
require_once __DIR__ . '/../BuddyController.php';

/**
 * Buddy module — Buddy Reading (plan §7.17, Fase 4).
 *
 * Pairs two active members on a club book in a current-flagged workflow
 * state: any member proposes a pairing (book + partner), the invited member
 * accepts (status → active) or declines (row deleted), either side marks it
 * done. Rows keep the user_a < user_b invariant so the
 * UNIQUE(club_id, club_book_id, user_a, user_b) key blocks mirrored
 * duplicates. Both sides see their pairings in the club-page panel.
 *
 * Table: bookclub_buddies. Opt-in per club (defaultEnabled = false).
 */
class BuddyModule extends AbstractModule
{
    public function slug(): string
    {
        return 'buddy';
    }

    public function label(): string
    {
        return __('Buddy Reading');
    }

    public function description(): string
    {
        return __('Letture in coppia sui libri del club, con proposta e conferma tra membri');
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
            'bookclub_buddies' => "CREATE TABLE IF NOT EXISTS bookclub_buddies (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                club_book_id INT NOT NULL,
                user_a INT NOT NULL,
                user_b INT NOT NULL,
                status ENUM('proposed','active','done') NOT NULL DEFAULT 'proposed',
                created_by INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcbuddy (club_id, club_book_id, user_a, user_b),
                KEY idx_bcbuddy_a (user_a),
                KEY idx_bcbuddy_b (user_b),
                CONSTRAINT fk_bcbuddy_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcbuddy_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcbuddy_usera FOREIGN KEY (user_a)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcbuddy_userb FOREIGN KEY (user_b)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new BuddyController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/buddy/propose',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->propose($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/buddy/{id:[0-9]+}/accept',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->accept($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/buddy/{id:[0-9]+}/decline',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->decline($rq, $rs, (string) $a['slug'], (int) $a['id'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/buddy/{id:[0-9]+}/done',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->markDone($rq, $rs, (string) $a['slug'], (int) $a['id'])
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
        // Pairings are personal: the panel exists for active members only.
        $isMember = !empty($ctx['isMember']);
        $userId = isset($ctx['userId']) ? (int) $ctx['userId'] : null;
        if (!$isMember || $userId === null) {
            return '';
        }
        $clubId = (int) $club['id'];
        $states = is_array($ctx['states'] ?? null) ? $ctx['states'] : [];

        try {
            $ext = new ExtensionsRepo($this->db);
            $pairings = $ext->buddiesForUser($clubId, $userId);
            $books = $ext->currentBooks($clubId, ExtensionsRepo::currentStateKeys($states));
            $partners = [];
            foreach ($this->repo->listMembers($clubId, 'active') as $member) {
                if ((int) $member['user_id'] === $userId) {
                    continue;
                }
                $partners[] = [
                    'user_id' => (int) $member['user_id'],
                    'name' => trim((string) ($member['nome'] ?? '') . ' ' . (string) ($member['cognome'] ?? '')),
                ];
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:buddy] panel failed: ' . $e->getMessage());
            return '';
        }
        // No current book to pair on and nothing pending: no panel.
        if ($pairings === [] && ($books === [] || $partners === [])) {
            return '';
        }

        return $this->renderPartial('partials/buddy_panel', [
            'club' => $club,
            'pairings' => $pairings,
            'books' => $books,
            'partners' => $partners,
            'userId' => $userId,
            'csrf' => (string) ($ctx['csrf'] ?? ''),
        ]);
    }
}
