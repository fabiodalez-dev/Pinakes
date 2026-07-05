<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\DiscussionController;
use App\Plugins\BookClub\DiscussionRepo;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Controller/repo are not in the composer autoload map: load them eagerly,
// exactly like BookClubPlugin.php does for the core controllers.
require_once __DIR__ . '/../DiscussionRepo.php';
require_once __DIR__ . '/../DiscussionController.php';

/**
 * Discussions module (plan §7.7): threads per book/section, posts with one
 * reply level, SpoilerGate (mild/full, optionally tied to a reading
 * section), emoji reactions, @mentions and manager moderation.
 */
class DiscussionsModule extends AbstractModule
{
    public function slug(): string
    {
        return 'discussions';
    }

    public function label(): string
    {
        return __('Discussioni');
    }

    public function description(): string
    {
        return __('Thread per libro e capitolo con spoiler protetti, reazioni e menzioni');
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
            'bookclub_threads' => "CREATE TABLE IF NOT EXISTS bookclub_threads (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                club_book_id INT NULL,
                section_id INT NULL,
                kind ENUM('general','chapter','character','free','announcement') NOT NULL DEFAULT 'free',
                title VARCHAR(190) NOT NULL,
                created_by INT NULL,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bcthreads_club (club_id, is_pinned),
                KEY idx_bcthreads_book (club_book_id),
                KEY idx_bcthreads_section (section_id),
                CONSTRAINT fk_bcthreads_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_posts' => "CREATE TABLE IF NOT EXISTS bookclub_posts (
                id INT NOT NULL AUTO_INCREMENT,
                thread_id INT NOT NULL,
                parent_id INT NULL,
                user_id INT NOT NULL,
                body MEDIUMTEXT NOT NULL,
                spoiler ENUM('none','mild','full') NOT NULL DEFAULT 'none',
                spoiler_section_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                edited_at DATETIME NULL,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_bcposts_thread (thread_id, created_at),
                KEY idx_bcposts_parent (parent_id),
                KEY idx_bcposts_user (user_id),
                CONSTRAINT fk_bcposts_thread FOREIGN KEY (thread_id)
                    REFERENCES bookclub_threads (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcposts_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_reactions' => "CREATE TABLE IF NOT EXISTS bookclub_reactions (
                id INT NOT NULL AUTO_INCREMENT,
                post_id INT NOT NULL,
                user_id INT NOT NULL,
                emoji VARCHAR(16) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcreact (post_id, user_id, emoji),
                KEY idx_bcreact_user (user_id),
                CONSTRAINT fk_bcreact_post FOREIGN KEY (post_id)
                    REFERENCES bookclub_posts (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcreact_user FOREIGN KEY (user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'bookclub_mentions' => "CREATE TABLE IF NOT EXISTS bookclub_mentions (
                id INT NOT NULL AUTO_INCREMENT,
                post_id INT NOT NULL,
                mentioned_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcment (post_id, mentioned_user_id),
                KEY idx_bcment_user (mentioned_user_id),
                CONSTRAINT fk_bcment_post FOREIGN KEY (post_id)
                    REFERENCES bookclub_posts (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcment_user FOREIGN KEY (mentioned_user_id)
                    REFERENCES utenti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new DiscussionController($this->db, $this->repo, new DiscussionRepo($this->db), $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get('/book-club/{slug:[a-z0-9\-]+}/discussions', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->index($rq, $rs, (string) $a['slug']));
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/new', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->createThread($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->get('/book-club/{slug:[a-z0-9\-]+}/discussions/{threadId:[0-9]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->showThread($rq, $rs, (string) $a['slug'], (int) $a['threadId']));
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/{threadId:[0-9]+}/posts', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->createPost($rq, $rs, (string) $a['slug'], (int) $a['threadId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/{threadId:[0-9]+}/lock', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->toggleLock($rq, $rs, (string) $a['slug'], (int) $a['threadId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/{threadId:[0-9]+}/pin', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->togglePin($rq, $rs, (string) $a['slug'], (int) $a['threadId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/posts/{postId:[0-9]+}/react', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->react($rq, $rs, (string) $a['slug'], (int) $a['postId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/discussions/posts/{postId:[0-9]+}/delete', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->deletePost($rq, $rs, (string) $a['slug'], (int) $a['postId']))->add($csrfMw)->add($authMw);
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
        try {
            $threads = (new DiscussionRepo($this->db))->listThreads((int) $club['id'], 5);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:discussions] panel failed: ' . $e->getMessage());
            return '';
        }
        return $this->renderPartial('partials/discussions_panel', [
            'club' => $club,
            'threads' => $threads,
            'isMember' => (bool) ($ctx['isMember'] ?? false),
            'canManage' => (bool) ($ctx['canManage'] ?? false),
            'csrf' => (string) ($ctx['csrf'] ?? ''),
        ]);
    }
}
