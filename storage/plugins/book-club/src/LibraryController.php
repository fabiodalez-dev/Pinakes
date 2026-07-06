<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\LibraryModule;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Library module (plan §7.9/§7.10) — review bridge over the core
 * `recensioni` table: active club members submit a review for a finished
 * club book; the row lands in the core moderation queue (stato 'pendente')
 * and the book-club extras (spoiler flag, strengths/weaknesses) go to
 * `bookclub_review_meta`.
 *
 * Every handler re-checks per-club module enablement (routes are global).
 */
class LibraryController extends BaseController
{
    private LibraryModule $module;
    private LibraryRepo $library;

    public function __construct(\mysqli $db, Repo $repo, LibraryModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->library = new LibraryRepo($db);
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/reviews
    // ------------------------------------------------------------------

    public function submitReview(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club)) {
            return $this->notFound($response);
        }
        $clubPath = '/book-club/' . $slug;

        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono inviare recensioni.'));
            return $this->redirect($response, $clubPath);
        }
        $userId = (int) $this->userId();
        $body = $request->getParsedBody();

        // The book must be one of the club's finished/archived reads.
        $libroId = self::intOrNull($body, 'libro_id') ?? 0;
        $states = $this->repo->workflowStates($club);
        $finishedBooks = $this->library->booksInStates((int) $club['id'], $this->module->finishedStateKeys($states));
        $allowed = false;
        foreach ($finishedBooks as $book) {
            if ((int) $book['libro_id'] === $libroId) {
                $allowed = true;
                break;
            }
        }
        if ($libroId <= 0 || !$allowed) {
            $this->flash('error', __('Puoi recensire solo i libri che il club ha concluso.'));
            return $this->redirect($response, $clubPath);
        }

        // Same validation rules as the core RecensioniController.
        $stelle = self::intOrNull($body, 'stelle') ?? 0;
        $titolo = self::str($body, 'titolo', 255);
        $descrizione = self::str($body, 'descrizione', 5000);
        if ($stelle < 1 || $stelle > 5) {
            $this->flash('error', __('Valutazione non valida (1-5 stelle)'));
            return $this->redirect($response, $clubPath);
        }
        if (mb_strlen($titolo) > 255) {
            $this->flash('error', __('Titolo troppo lungo (max 255 caratteri)'));
            return $this->redirect($response, $clubPath);
        }
        if (mb_strlen($descrizione) > 2000) {
            $this->flash('error', __('Descrizione troppo lunga (max 2000 caratteri)'));
            return $this->redirect($response, $clubPath);
        }

        // Core UNIQUE(libro_id, utente_id): one review per user per book.
        if ($this->library->hasReviewed($userId, $libroId)) {
            $this->flash('warning', __('Hai già recensito questo libro.'));
            return $this->redirect($response, $clubPath);
        }

        $reviewId = $this->library->createReview($libroId, $userId, $stelle, $titolo, $descrizione);
        if ($reviewId === null) {
            $this->flash('error', __('Errore nella creazione della recensione'));
            return $this->redirect($response, $clubPath);
        }

        $hasSpoiler = is_array($body) && !empty($body['has_spoiler']);
        $strengths = self::str($body, 'strengths', 2000);
        $weaknesses = self::str($body, 'weaknesses', 2000);
        $this->library->insertMeta(
            $reviewId,
            (int) $club['id'],
            $hasSpoiler,
            $strengths !== '' ? $strengths : null,
            $weaknesses !== '' ? $weaknesses : null
        );

        // Same admin notification the core review flow sends; never blocking.
        try {
            $notifications = new \App\Support\NotificationService($this->db);
            $notifications->notifyNewReview($reviewId);
        } catch (\Throwable $e) {
            SecureLogger::warning('[BookClub:library] notifyNewReview failed: ' . $e->getMessage());
        }

        $this->flash('success', __('Recensione inviata! Sarà visibile dopo l\'approvazione di un amministratore.'));
        return $this->redirect($response, $clubPath);
    }
}
