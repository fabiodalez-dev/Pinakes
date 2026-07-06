<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\ReadingController;
use App\Plugins\BookClub\ReadingRepo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../ReadingRepo.php';
require_once __DIR__ . '/../ReadingController.php';

/**
 * Reading module — Lettura condivisa + Reading Tracker (plan §7.6).
 *
 * Splits a club book into sections (chapters/parts/page ranges/custom, each
 * with an optional discussion-unlock date), lets every active member track
 * their own progress (percent + last completed section + finished flag) and
 * shows the club aggregate (average percent, finishers, per-section pass
 * counts).
 *
 * Tables: bookclub_sections, bookclub_progress.
 *
 * PUBLIC API for other modules (e.g. SpoilerGate in discussions):
 *  - ReadingRepo::userPassedSection(mysqli $db, int $userId, int $sectionId): bool
 *      `bookclub_progress.section_id` stores the LAST COMPLETED section;
 *      returns true when the user's progress row for that section's
 *      club_book has finished_at NOT NULL, or its section_id's sort is
 *      >= the target section's sort.
 *  - ReadingRepo::sectionsForBook(mysqli $db, int $clubBookId): array
 *      Ordered section rows for a club book.
 */
class ReadingModule extends AbstractModule
{
    public function slug(): string
    {
        return 'reading';
    }

    public function label(): string
    {
        return __('Lettura condivisa');
    }

    public function description(): string
    {
        return __('Sezioni del libro, tracker di avanzamento e progressi del club');
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
            'bookclub_sections' => "CREATE TABLE IF NOT EXISTS bookclub_sections (
                id INT NOT NULL AUTO_INCREMENT,
                club_book_id INT NOT NULL,
                title VARCHAR(190) NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                unit ENUM('chapter','part','pages','custom') NOT NULL DEFAULT 'chapter',
                range_from INT NULL,
                range_to INT NULL,
                discuss_from DATE NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcsect_book (club_book_id, sort),
                CONSTRAINT fk_bcsect_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_progress' => "CREATE TABLE IF NOT EXISTS bookclub_progress (
                id INT NOT NULL AUTO_INCREMENT,
                club_book_id INT NOT NULL,
                user_id INT NOT NULL,
                section_id INT NULL,
                percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
                page INT NULL,
                finished_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcprog (club_book_id, user_id),
                KEY idx_bcprog_user (user_id),
                KEY idx_bcprog_section (section_id),
                CONSTRAINT fk_bcprog_book FOREIGN KEY (club_book_id)
                    REFERENCES bookclub_books (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcprog_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcprog_section FOREIGN KEY (section_id)
                    REFERENCES bookclub_sections (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new ReadingController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/reading/{bookId:[0-9]+}',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->show($rq, $rs, (string) $a['slug'], (int) $a['bookId'])
        );
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/reading/{bookId:[0-9]+}/progress',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->updateProgress($rq, $rs, (string) $a['slug'], (int) $a['bookId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/reading/{bookId:[0-9]+}/sections',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->addSection($rq, $rs, (string) $a['slug'], (int) $a['bookId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/reading/{bookId:[0-9]+}/sections/{sectionId:[0-9]+}/update',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->updateSection($rq, $rs, (string) $a['slug'], (int) $a['bookId'], (int) $a['sectionId'])
        )->add($csrfMw)->add($authMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/reading/{bookId:[0-9]+}/sections/{sectionId:[0-9]+}/delete',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->deleteSection($rq, $rs, (string) $a['slug'], (int) $a['bookId'], (int) $a['sectionId'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panel
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null) {
            return '';
        }
        $states = is_array($ctx['states'] ?? null) ? $ctx['states'] : [];
        $currentKeys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['current'])) {
                $currentKeys[] = (string) $state['key'];
            }
        }
        $reading = new ReadingRepo($this->db);
        $books = $reading->currentBooks((int) $club['id'], $currentKeys);
        if ($books === []) {
            return '';
        }

        $userId = isset($ctx['userId']) ? (int) $ctx['userId'] : null;
        $memberCount = $this->repo->countActiveMembers((int) $club['id']);
        $items = [];
        foreach ($books as $book) {
            $bookId = (int) $book['id'];
            $items[] = [
                'book' => $book,
                'mine' => $userId !== null ? $reading->progressRow($bookId, $userId) : null,
                'aggregate' => $reading->aggregate($bookId, (int) $club['id']),
            ];
        }

        return $this->renderPartial('partials/reading_panel', [
            'club' => $club,
            'items' => $items,
            'memberCount' => $memberCount,
            'isMember' => !empty($ctx['isMember']),
        ]);
    }
}
