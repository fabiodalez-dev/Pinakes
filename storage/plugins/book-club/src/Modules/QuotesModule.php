<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\QuoteController;
use App\Plugins\BookClub\QuoteRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../QuoteRepo.php';
require_once __DIR__ . '/../QuoteController.php';

/**
 * Quotes module — Citazioni e annotazioni (plan §7.8).
 *
 * Quotes from the club's books with page number, personal note and
 * private/club/public visibility; personal annotations per club book
 * (private or shared with the club); Markdown/CSV export of the member's
 * OWN quotes + notes. Club page panel with the three most recent
 * club-visible quotes.
 *
 * Tables: bookclub_quotes, bookclub_notes.
 *
 * NOTE on visibility='public': the plan foresees surfacing public quotes on
 * the core book detail page via the `book.frontend.details` hook. This
 * module deliberately does NOT touch core files or hook into that page yet;
 * public quotes are stored with the correct visibility so a future
 * integration only needs to read `bookclub_quotes WHERE libro_id = ? AND
 * visibility = 'public'`.
 */
class QuotesModule extends AbstractModule
{
    public function slug(): string
    {
        return 'quotes';
    }

    public function label(): string
    {
        return __('Citazioni e annotazioni');
    }

    public function description(): string
    {
        return __('Citazioni dai libri del club con pagina e nota, annotazioni personali ed export dei propri dati');
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
            'bookclub_quotes' => "CREATE TABLE IF NOT EXISTS bookclub_quotes (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                libro_id INT NOT NULL,
                club_id INT NULL,
                quote TEXT NOT NULL,
                page INT NULL,
                note TEXT NULL,
                visibility ENUM('private','club','public') NOT NULL DEFAULT 'club',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcquote_club (club_id, visibility, created_at),
                KEY idx_bcquote_libro (libro_id, visibility),
                KEY idx_bcquote_user (user_id),
                CONSTRAINT fk_bcquote_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcquote_libro FOREIGN KEY (libro_id)
                    REFERENCES libri (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcquote_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_notes' => "CREATE TABLE IF NOT EXISTS bookclub_notes (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                club_book_id INT NOT NULL,
                body MEDIUMTEXT NOT NULL,
                visibility ENUM('private','club') NOT NULL DEFAULT 'private',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcnote_book (club_book_id, visibility),
                KEY idx_bcnote_user (user_id),
                CONSTRAINT fk_bcnote_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcnote_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new QuoteController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/quotes',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/export.md',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->exportMarkdown($rq, $rs, (string) $a['slug'])
        )->add($authMw);
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/export.csv',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->exportCsv($rq, $rs, (string) $a['slug'])
        )->add($authMw);

        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->addQuote($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/{quoteId:[0-9]+}/visibility',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->updateQuoteVisibility($rq, $rs, (string) $a['slug'], (int) $a['quoteId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/{quoteId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->deleteQuote($rq, $rs, (string) $a['slug'], (int) $a['quoteId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/notes',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->addNote($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/notes/{noteId:[0-9]+}/update',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->updateNote($rq, $rs, (string) $a['slug'], (int) $a['noteId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/quotes/notes/{noteId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->deleteNote($rq, $rs, (string) $a['slug'], (int) $a['noteId'])
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
        $canManage = !empty($ctx['canManage']);
        // Quotes are club-internal content: members/managers only.
        if (!$isMember && !$canManage) {
            return '';
        }

        try {
            $quotes = (new QuoteRepo($this->db))->recentClubQuotes((int) $club['id'], 3);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:quotes] panel failed: ' . $e->getMessage());
            return '';
        }

        return $this->renderPartial('partials/quotes_panel', [
            'club' => $club,
            'quotes' => $quotes,
            'isMember' => $isMember,
        ]);
    }
}
