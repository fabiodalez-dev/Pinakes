<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\LibraryController;
use App\Plugins\BookClub\LibraryRepo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller/repo owned by this module (Repo/BaseController are already
// required by BookClubPlugin.php).
require_once __DIR__ . '/../LibraryRepo.php';
require_once __DIR__ . '/../LibraryController.php';

/**
 * Library module — Integrazione biblioteca + ponte recensioni
 * (plan §7.9 and §7.10).
 *
 * AVAILABILITY: for the club books currently being read (states flagged
 * `current`, plus the 'selected' state when the workflow has one) it shows
 * the core catalog counters (copie_disponibili/copie_totali), the active
 * reservation queue length, which CLUB members hold a copy on loan (names
 * for managers, a bare count for everyone else) and a link to the core book
 * page to reserve a copy. Managers also get an amber "N partecipanti,
 * M copie" warning when a scheduled meeting linked to the book has more
 * yes-RSVPs than total copies.
 *
 * REVIEWS: no parallel system — reviews are core `recensioni` rows (stato
 * 'pendente', moderated in the core admin queue); the club page lists the
 * APPROVED ones written by active members for finished/archived club books,
 * enriched with `bookclub_review_meta` (spoiler flag → body wrapped in
 * <details>, strengths/weaknesses).
 *
 * Table: bookclub_review_meta.
 */
class LibraryModule extends AbstractModule
{
    public function slug(): string
    {
        return 'library';
    }

    public function label(): string
    {
        return __('Biblioteca e recensioni');
    }

    public function description(): string
    {
        return __('Disponibilità copie, prestiti dei membri, lista d\'attesa e recensioni del club');
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
            'bookclub_review_meta' => "CREATE TABLE IF NOT EXISTS bookclub_review_meta (
                id INT NOT NULL AUTO_INCREMENT,
                recensione_id INT NOT NULL,
                club_id INT NOT NULL,
                has_spoiler TINYINT(1) NOT NULL DEFAULT 0,
                strengths TEXT NULL,
                weaknesses TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcrevmeta_review (recensione_id),
                KEY idx_bcrevmeta_club (club_id),
                CONSTRAINT fk_bcrevmeta_review FOREIGN KEY (recensione_id)
                    REFERENCES recensioni (id) ON DELETE CASCADE,
                CONSTRAINT fk_bcrevmeta_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new LibraryController($this->db, $this->repo, $this);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/reviews',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->submitReview($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Workflow state selectors (shared with LibraryController)
    // ------------------------------------------------------------------

    /**
     * States whose books appear in the availability panel: every state
     * flagged `current`, plus the literal 'selected' key when the workflow
     * still has one.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public function availabilityStateKeys(array $states): array
    {
        $keys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['current']) || $state['key'] === 'selected') {
                $keys[] = (string) $state['key'];
            }
        }
        return $keys;
    }

    /**
     * States whose books are reviewable: every state flagged `archived`,
     * plus the literal 'finished' key when the workflow still has one.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public function finishedStateKeys(array $states): array
    {
        $keys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['archived']) || $state['key'] === 'finished') {
                $keys[] = (string) $state['key'];
            }
        }
        return $keys;
    }

    // ------------------------------------------------------------------
    // Club page panel (availability section + reviews section)
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null) {
            return '';
        }
        $clubId = (int) $club['id'];
        $states = is_array($ctx['states'] ?? null) ? $ctx['states'] : [];
        $isMember = !empty($ctx['isMember']);
        $canManage = !empty($ctx['canManage']);
        $userId = isset($ctx['userId']) && $ctx['userId'] !== null ? (int) $ctx['userId'] : null;
        $library = new LibraryRepo($this->db);

        // ---- Availability (§7.10) ----
        $availabilityBooks = $library->booksInStates($clubId, $this->availabilityStateKeys($states));
        foreach ($availabilityBooks as $i => $book) {
            $loans = $library->memberLoans($clubId, (int) $book['libro_id']);
            // Privacy: only managers see WHO has the book, others get a count.
            $availabilityBooks[$i]['member_loans'] = $canManage ? $loans : [];
            $availabilityBooks[$i]['member_loan_count'] = count($loans);
            $availabilityBooks[$i]['max_yes_rsvps'] = $canManage
                ? $library->maxYesRsvpsForBook((int) $book['id'])
                : 0;
        }

        // ---- Reviews bridge (§7.9) ----
        $finishedBooks = $library->booksInStates($clubId, $this->finishedStateKeys($states));
        $libroIds = array_map(static fn(array $b): int => (int) $b['libro_id'], $finishedBooks);
        $reviews = $library->clubReviews($clubId, $libroIds);
        $reviewableBooks = [];
        if ($isMember && $userId !== null && $finishedBooks !== []) {
            $reviewed = $library->reviewedLibroIds($userId, $libroIds);
            foreach ($finishedBooks as $book) {
                if (!in_array((int) $book['libro_id'], $reviewed, true)) {
                    $reviewableBooks[] = $book;
                }
            }
        }

        $html = '';
        if ($availabilityBooks !== []) {
            $html .= $this->renderPartial('partials/library_panel', [
                'club' => $club,
                'books' => $availabilityBooks,
                'isMember' => $isMember,
                'canManage' => $canManage,
            ]);
        }
        if ($finishedBooks !== []) {
            $html .= $this->renderPartial('partials/reviews_panel', [
                'club' => $club,
                'reviews' => $reviews,
                'reviewableBooks' => $reviewableBooks,
                'isMember' => $isMember,
            ]);
        }
        return $html;
    }
}
