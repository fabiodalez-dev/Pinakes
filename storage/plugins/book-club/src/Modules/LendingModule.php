<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\LendingController;
use App\Plugins\BookClub\LendingRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../LendingRepo.php';
require_once __DIR__ . '/../LendingController.php';

/**
 * Lending module — Prestito tra membri (plan §7.17).
 *
 * Members lend their PERSONAL copies of the club's books to each other.
 * This is intentionally separate from the library module: the core
 * `prestiti` table tracks the LIBRARY's copies, `bookclub_member_loans`
 * tracks copies the members own privately.
 *
 * Flow (all state changes are conditional UPDATEs in LendingRepo):
 *   offer → request (first requester wins; the lender may decline)
 *         → hand over (lent_at, optional due_on) → return.
 * A lender may cancel an offered/requested row; at most one open row
 * (offered/requested/active) exists per (club_book, lender).
 *
 * Table: bookclub_member_loans.
 */
class LendingModule extends AbstractModule
{
    public function slug(): string
    {
        return 'lending';
    }

    public function label(): string
    {
        return __('Prestito tra membri');
    }

    public function description(): string
    {
        return __('I membri prestano le proprie copie personali dei libri del club');
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
            'bookclub_member_loans' => "CREATE TABLE IF NOT EXISTS bookclub_member_loans (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                club_book_id INT NOT NULL,
                lender_id INT NOT NULL,
                borrower_id INT NULL,
                status ENUM('offered','requested','active','returned','cancelled') NOT NULL DEFAULT 'offered',
                notes VARCHAR(500) NULL,
                due_on DATE NULL,
                offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lent_at DATETIME NULL,
                returned_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_bcmloan_club_status (club_id, status),
                KEY idx_bcmloan_book (club_book_id),
                KEY idx_bcmloan_lender (lender_id),
                KEY idx_bcmloan_borrower (borrower_id),
                CONSTRAINT fk_bcmloan_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcmloan_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcmloan_lender FOREIGN KEY (lender_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new LendingController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/lending',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);

        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/offer',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->offer($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/{loanId:[0-9]+}/request',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->request($rq, $rs, (string) $a['slug'], (int) $a['loanId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/{loanId:[0-9]+}/decline',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->decline($rq, $rs, (string) $a['slug'], (int) $a['loanId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/{loanId:[0-9]+}/handover',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->handOver($rq, $rs, (string) $a['slug'], (int) $a['loanId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/{loanId:[0-9]+}/return',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->markReturned($rq, $rs, (string) $a['slug'], (int) $a['loanId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/lending/{loanId:[0-9]+}/cancel',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->cancel($rq, $rs, (string) $a['slug'], (int) $a['loanId'])
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
        // Member-to-member lending is club-internal: members/managers only.
        $isMember = !empty($ctx['isMember']);
        $canManage = !empty($ctx['canManage']);
        if (!$isMember && !$canManage) {
            return '';
        }
        $userId = isset($ctx['userId']) ? (int) $ctx['userId'] : 0;

        try {
            $lending = new LendingRepo($this->db);
            $openCount = $lending->countOpenOffers((int) $club['id']);
            $activeLoans = $userId > 0 ? $lending->myActiveLoans((int) $club['id'], $userId) : [];
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:lending] panel failed: ' . $e->getMessage());
            return '';
        }

        return $this->renderPartial('partials/lending_panel', [
            'club' => $club,
            'openCount' => $openCount,
            'activeLoans' => $activeLoans,
            'userId' => $userId,
        ]);
    }
}
