<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\BuddyModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Buddy module (plan §7.17 — Buddy Reading): pairs two active members on a
 * club book in a current-flagged workflow state. Any member proposes a
 * pairing, the invited member accepts (→ active) or declines (row deleted),
 * either side marks it done. Storage keeps the user_a < user_b invariant so
 * the UNIQUE key blocks mirrored duplicates.
 *
 * The UI lives in the club-page panel (buddy_panel), so every action
 * redirects back to /book-club/{slug}. Every handler re-checks per-club
 * module enablement (routes are global).
 */
class BuddyController extends BaseController
{
    private BuddyModule $module;
    private ExtensionsRepo $ext;

    public function __construct(\mysqli $db, Repo $repo, BuddyModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
        $this->ext = new ExtensionsRepo($db);
    }

    private function clubPath(string $slug): string
    {
        return '/book-club/' . $slug;
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/buddy/propose
    // ------------------------------------------------------------------

    public function propose(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club) || !$this->canView($club)) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi del club possono proporre una lettura in coppia.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        $clubId = (int) $club['id'];
        $userId = (int) $this->userId();
        $body = $request->getParsedBody();

        // Book: must belong to the club and sit in a current-flagged state.
        $clubBookId = self::intOrNull($body, 'club_book_id');
        $book = $clubBookId !== null ? $this->repo->clubBook($clubBookId) : null;
        $currentKeys = ExtensionsRepo::currentStateKeys($this->repo->workflowStates($club));
        if (
            $book === null
            || (int) $book['club_id'] !== $clubId
            || !in_array((string) $book['state'], $currentKeys, true)
        ) {
            $this->flash('error', __('Scegli un libro attualmente in lettura nel club.'));
            return $this->redirect($response, $this->clubPath($slug));
        }

        // Partner: another ACTIVE member of the same club.
        $partnerId = self::intOrNull($body, 'partner_id');
        if ($partnerId === null || $partnerId === $userId) {
            $this->flash('error', __('Scegli un altro membro del club come compagno di lettura.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        $partner = $this->repo->memberRow($clubId, $partnerId);
        if ($partner === null || $partner['status'] !== 'active') {
            $this->flash('error', __('Il compagno scelto non è un membro attivo del club.'));
            return $this->redirect($response, $this->clubPath($slug));
        }

        if ($this->ext->buddyExists($clubId, (int) $clubBookId, $userId, $partnerId)) {
            $this->flash('warning', __('Esiste già una lettura in coppia con questo membro su questo libro.'));
            return $this->redirect($response, $this->clubPath($slug));
        }

        if ($this->ext->createBuddy($clubId, (int) $clubBookId, $userId, $partnerId, $userId) !== null) {
            $this->flash('success', __('Proposta di lettura in coppia inviata: in attesa di conferma.'));
        } else {
            $this->flash('error', __('Impossibile creare la lettura in coppia. Riprova.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/buddy/{id}/accept
    // ------------------------------------------------------------------

    public function accept(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $buddyId): ResponseInterface
    {
        $context = $this->resolveBuddy($slug, $buddyId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [, $buddy] = $context;
        $userId = (int) $this->userId();
        // Only the INVITED side (participant who did not create the row) accepts.
        if (!$this->isParticipant($buddy, $userId) || (int) $buddy['created_by'] === $userId) {
            return $this->notFound($response);
        }
        if ((string) $buddy['status'] !== 'proposed') {
            $this->flash('warning', __('Questa proposta è già stata gestita.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        if ($this->ext->setBuddyStatus($buddyId, 'active')) {
            $this->flash('success', __('Lettura in coppia avviata. Buona lettura!'));
        } else {
            $this->flash('error', __('Impossibile accettare la proposta. Riprova.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/buddy/{id}/decline
    // ------------------------------------------------------------------

    /**
     * Decline (invited side), withdraw (proposer) or remove (manager):
     * the 'proposed' row is deleted, as per plan — no declined state kept.
     */
    public function decline(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $buddyId): ResponseInterface
    {
        $context = $this->resolveBuddy($slug, $buddyId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [$club, $buddy] = $context;
        $userId = (int) $this->userId();
        if (!$this->isParticipant($buddy, $userId) && !$this->canManage($club)) {
            return $this->notFound($response);
        }
        if ((string) $buddy['status'] !== 'proposed') {
            $this->flash('warning', __('Questa proposta è già stata gestita.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        if ($this->ext->deleteBuddy($buddyId)) {
            $this->flash('success', __('Proposta di lettura in coppia rimossa.'));
        } else {
            $this->flash('error', __('Impossibile rimuovere la proposta. Riprova.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // POST /book-club/{slug}/buddy/{id}/done
    // ------------------------------------------------------------------

    public function markDone(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $buddyId): ResponseInterface
    {
        $context = $this->resolveBuddy($slug, $buddyId);
        if ($context === null) {
            return $this->notFound($response);
        }
        [, $buddy] = $context;
        $userId = (int) $this->userId();
        if (!$this->isParticipant($buddy, $userId)) {
            return $this->notFound($response);
        }
        if ((string) $buddy['status'] !== 'active') {
            $this->flash('warning', __('Solo una lettura in coppia attiva può essere conclusa.'));
            return $this->redirect($response, $this->clubPath($slug));
        }
        if ($this->ext->setBuddyStatus($buddyId, 'done')) {
            $this->flash('success', __('Lettura in coppia conclusa.'));
        } else {
            $this->flash('error', __('Impossibile concludere la lettura in coppia. Riprova.'));
        }
        return $this->redirect($response, $this->clubPath($slug));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Club + pairing for the POST routes, with pairing-belongs-to-club check.
     * Requires a logged-in user (all buddy actions are personal).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function resolveBuddy(string $slug, int $buddyId): ?array
    {
        if ($this->userId() === null) {
            return null;
        }
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->module->enabledFor($club) || !$this->canView($club)) {
            return null;
        }
        $buddy = $this->ext->buddyById($buddyId);
        if ($buddy === null || (int) $buddy['club_id'] !== (int) $club['id']) {
            return null;
        }
        return [$club, $buddy];
    }

    /** @param array<string, mixed> $buddy */
    private function isParticipant(array $buddy, int $userId): bool
    {
        return (int) $buddy['user_a'] === $userId || (int) $buddy['user_b'] === $userId;
    }
}
