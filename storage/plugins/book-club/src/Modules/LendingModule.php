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

    /**
     * The generated column + UNIQUE index enforce the "at most one OPEN loan per
     * (club_book_id, lender_id)" invariant atomically at the DB level: open_key is
     * the pair key while the loan is open and NULL otherwise (MySQL allows many
     * NULLs in a UNIQUE index), so any number of closed/cancelled/returned rows
     * coexist but a second concurrent open offer hits the constraint. This is the
     * backstop the INSERT … WHERE NOT EXISTS in LendingRepo::createOffer() can't
     * guarantee on its own under REPEATABLE READ.
     */
    private const OPEN_KEY_DEF =
        "VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN status IN ('offered','requested','active') "
        . "THEN CONCAT(club_book_id, ':', lender_id) ELSE NULL END) STORED";

    public function ensureSchema(): array
    {
        $result = $this->runDdl([
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
                open_key " . self::OPEN_KEY_DEF . ",
                PRIMARY KEY (id),
                KEY idx_bcmloan_club_status (club_id, status),
                KEY idx_bcmloan_book (club_book_id),
                KEY idx_bcmloan_lender (lender_id),
                KEY idx_bcmloan_borrower (borrower_id),
                UNIQUE KEY uq_bcmloan_open (open_key),
                CONSTRAINT fk_bcmloan_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcmloan_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcmloan_lender FOREIGN KEY (lender_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);

        // Migration for installs created before the open_key invariant existed
        // (e.g. book-club shipped in 0.7.29-rc.1/rc.2). Idempotent: guarded on the
        // column/index presence so it's a no-op on fresh installs and safe to re-run.
        if (!$this->columnExists('bookclub_member_loans', 'open_key')) {
            // Resolve any pre-existing duplicate OPEN loans first, else adding the
            // UNIQUE index would fail: keep the earliest open row per
            // (club_book_id, lender_id) and cancel the rest.
            $this->db->query(
                "UPDATE bookclub_member_loans m
                    JOIN (
                        SELECT club_book_id, lender_id, MIN(id) AS keep_id
                          FROM bookclub_member_loans
                         WHERE status IN ('offered','requested','active')
                         GROUP BY club_book_id, lender_id
                        HAVING COUNT(*) > 1
                    ) d ON d.club_book_id = m.club_book_id AND d.lender_id = m.lender_id
                    SET m.status = 'cancelled'
                  WHERE m.status IN ('offered','requested','active') AND m.id <> d.keep_id"
            );
            $this->addColumnIfMissing('bookclub_member_loans', 'open_key', self::OPEN_KEY_DEF);
        }
        $this->addUniqueIndexIfMissing('bookclub_member_loans', 'uq_bcmloan_open', 'open_key');

        return $result;
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
