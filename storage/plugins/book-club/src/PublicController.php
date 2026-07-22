<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\EmailService;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public/member-facing pages: club directory, club home, membership,
 * invitations, proposals, personal dashboard.
 */
class PublicController extends BaseController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $clubs = $this->repo->listVisibleClubs();
        $userId = $this->userId();
        $mine = $userId !== null
            ? array_column($this->repo->listClubsForUser($userId), 'member_status', 'id')
            : [];
        return $this->renderPublic($response, 'public/index', [
            'clubs' => $clubs,
            'mine' => $mine,
            'loggedIn' => $userId !== null,
        ], __('Club di lettura'));
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $states = $this->repo->workflowStates($club);
        $membership = $this->membership($club);
        $canManage = $this->canManage($club);
        $isMember = $membership !== null && $membership['status'] === 'active';

        $books = $this->repo->clubBooks((int) $club['id']);
        // Proposals awaiting moderation are manager-only.
        if (!$canManage) {
            $books = array_values(array_filter(
                $books,
                static fn(array $b): bool => $b['state'] !== BookClubPlugin::STATE_PENDING
            ));
        }

        // Feature-module panels (reading tracker, discussions, stats, …).
        $ctx = [
            'club' => $club,
            'states' => $states,
            'membership' => $membership,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'loggedIn' => $this->userId() !== null,
            'userId' => $this->userId(),
            'csrf' => \App\Support\Csrf::ensureToken(),
        ];
        $modulePanelsMain = [];
        $modulePanelsSidebar = [];
        foreach (Modules\Registry::enabledForClub($this->db, $club) as $module) {
            try {
                $panel = $module->renderClubPanel($ctx);
                if ($panel !== '') {
                    $modulePanelsMain[] = $panel;
                }
                $side = $module->renderClubSidebar($ctx);
                if ($side !== '') {
                    $modulePanelsSidebar[] = $side;
                }
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] module ' . $module->slug() . ' panel failed: ' . $e->getMessage());
            }
        }

        return $this->renderPublic($response, 'public/show', [
            'club' => $club,
            'states' => $states,
            'membership' => $membership,
            'isMember' => $isMember,
            'canManage' => $canManage,
            'loggedIn' => $this->userId() !== null,
            'modulePanelsMain' => $modulePanelsMain,
            'modulePanelsSidebar' => $modulePanelsSidebar,
            'pollEligible' => $canManage ? $this->repo->pollEligibleBooks($club, $books) : [],
            'books' => $books,
            'polls' => $this->repo->clubPolls((int) $club['id']),
            'meetings' => $this->repo->clubMeetings((int) $club['id']),
            'members' => $isMember || $canManage ? $this->repo->listMembers((int) $club['id']) : [],
            'memberCount' => $this->repo->countActiveMembers((int) $club['id']),
            'nextMeeting' => $this->repo->nextMeeting((int) $club['id']),
            'roles' => $this->repo->systemRoles(),
        ], (string) $club['name']);
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    public function join(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || (int) $club['is_active'] !== 1) {
            return $this->notFound($response);
        }
        $userId = (int) $this->userId();
        $existing = $this->repo->memberRow((int) $club['id'], $userId);
        if ($existing !== null && in_array($existing['status'], ['active', 'pending'], true)) {
            return $this->redirect($response, '/book-club/' . $slug);
        }
        if ($existing !== null && $existing['status'] === 'banned') {
            $this->flash('error', __('Non puoi unirti a questo club.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        if (!in_array($club['privacy'], ['public', 'private'], true)) {
            $this->flash('error', __('Questo club è accessibile solo su invito.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        if ($club['max_members'] !== null && $this->repo->countActiveMembers((int) $club['id']) >= (int) $club['max_members']) {
            $this->flash('error', __('Il club ha raggiunto il numero massimo di membri.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $roleId = $this->repo->roleIdBySlug('member');
        if ($roleId === null) {
            return $this->notFound($response);
        }
        $status = $club['privacy'] === 'public' ? 'active' : 'pending';
        $this->repo->upsertMember((int) $club['id'], $userId, $roleId, $status);
        if (function_exists('do_action')) {
            do_action('bookclub.member.joined', (int) $club['id'], $userId, $status);
        }
        if ($status === 'pending') {
            $this->notifyClubEvent(
                $club,
                'new_user',
                sprintf(__('Richiesta di adesione al club "%s"'), (string) $club['name']),
                sprintf(__('%s ha chiesto di unirsi al club "%s". Approva o rifiuta la richiesta dalla pagina del club.'), $this->currentUserLabel(), (string) $club['name']),
                (string) $club['slug']
            );
        }
        $this->flash('success', $status === 'active'
            ? __('Benvenuto nel club!')
            : __('Richiesta inviata: un moderatore deve approvarla.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    public function leave(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $member = $this->membership($club);
        if ($member !== null && in_array($member['status'], ['active', 'pending'], true)) {
            $this->repo->setMemberStatus((int) $member['id'], 'left');
            if (function_exists('do_action')) {
                do_action('bookclub.member.left', (int) $club['id'], (int) $member['user_id']);
            }
            $this->flash('success', __('Hai lasciato il club.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * Approve or reject a pending join request.
     *
     * Permission: `members.approve` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function approveMember(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $memberId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'members.approve')) {
            return $this->notFound($response);
        }
        $member = $this->repo->memberById($memberId);
        if ($member === null || (int) $member['club_id'] !== (int) $club['id'] || $member['status'] !== 'pending') {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $action = self::str($body, 'action', 10);
        if ($action === 'reject') {
            $this->repo->setMemberStatus($memberId, 'left');
            $this->flash('success', __('Richiesta rifiutata.'));
        } else {
            if ($club['max_members'] !== null && $this->repo->countActiveMembers((int) $club['id']) >= (int) $club['max_members']) {
                $this->flash('error', __('Il club ha raggiunto il numero massimo di membri.'));
                return $this->redirect($response, '/book-club/' . $slug);
            }
            $this->repo->setMemberStatus($memberId, 'active');
            $this->flash('success', __('Membro approvato.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    // ------------------------------------------------------------------
    // Invitations
    // ------------------------------------------------------------------

    /**
     * Send an email invitation to join the club.
     *
     * Permission: `members.invite` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function sendInvite(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'members.invite')) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $email = self::str($body, 'email', 190);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', __('Indirizzo email non valido.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $roleId = $this->repo->roleIdBySlug('member');
        $token = $this->repo->createInvitation((int) $club['id'], $email, $roleId, (int) $this->userId());
        if ($token === null) {
            $this->flash('error', __('Invito non creato, riprova.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }

        $link = absoluteUrl('/book-club/invite/' . $token);
        $sent = false;
        try {
            $emailService = new EmailService($this->db);
            $subject = sprintf(__('Invito al club di lettura "%s"'), (string) $club['name']);
            $bodyHtml = '<p>' . htmlspecialchars(sprintf(
                __('Sei stato invitato a unirti al club di lettura "%s".'),
                (string) $club['name']
            ), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('Accetta l\'invito'), ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>' . htmlspecialchars(__('Se non hai ancora un account, registrati prima con questo indirizzo email.'), ENT_QUOTES, 'UTF-8') . '</p>';
            $sent = $emailService->sendEmail($email, $subject, $bodyHtml);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub] invite email failed: ' . $e->getMessage());
        }

        if ($sent) {
            $this->flash('success', sprintf(__('Invito inviato a %s.'), $email));
        } else {
            // Mail not configured / failed: hand the manager the link so the
            // invitation is still usable.
            $this->flash('warning', sprintf(__('Email non inviata. Condividi manualmente questo link di invito: %s'), $link));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    public function acceptInvite(ServerRequestInterface $request, ResponseInterface $response, string $token): ResponseInterface
    {
        $invitation = $this->repo->pendingInvitationByToken($token);
        if ($invitation === null) {
            $this->flash('error', __('Invito non valido o scaduto.'));
            return $this->redirect($response, '/book-club');
        }
        $club = $this->repo->clubById((int) $invitation['club_id']);
        if ($club === null || (int) $club['is_active'] !== 1) {
            $this->flash('error', __('Il club non è più disponibile.'));
            return $this->redirect($response, '/book-club');
        }
        if ($club['max_members'] !== null && $this->repo->countActiveMembers((int) $club['id']) >= (int) $club['max_members']) {
            $this->flash('error', __('Il club ha raggiunto il numero massimo di membri.'));
            return $this->redirect($response, '/book-club');
        }
        // The invitation is bound to the invited address: a forwarded or
        // leaked link must not let a different account into an invite-only
        // or hidden club.
        $sessionEmail = (string) ($this->sessionUser()['email'] ?? '');
        if ($sessionEmail === '' || strcasecmp($sessionEmail, (string) $invitation['email']) !== 0) {
            $this->flash('error', __('Questo invito è riservato a un altro indirizzo email.'));
            return $this->redirect($response, '/book-club');
        }
        $userId = (int) $this->userId();
        $existing = $this->repo->memberRow((int) $club['id'], $userId);
        if ($existing !== null && $existing['status'] === 'banned') {
            $this->flash('error', __('Non puoi unirti a questo club.'));
            return $this->redirect($response, '/book-club');
        }
        $roleId = $invitation['role_id'] !== null ? (int) $invitation['role_id'] : $this->repo->roleIdBySlug('member');
        if ($roleId === null) {
            return $this->notFound($response);
        }
        $this->repo->upsertMember((int) $club['id'], $userId, $roleId, 'active', $invitation['invited_by'] !== null ? (int) $invitation['invited_by'] : null);
        $this->repo->markInvitationAccepted((int) $invitation['id']);
        if (function_exists('do_action')) {
            do_action('bookclub.member.joined', (int) $club['id'], $userId, 'active');
        }
        $this->flash('success', sprintf(__('Benvenuto nel club "%s"!'), (string) $club['name']));
        return $this->redirect($response, '/book-club/' . $club['slug']);
    }

    // ------------------------------------------------------------------
    // Proposals & workflow transitions
    // ------------------------------------------------------------------

    /**
     * JSON catalog autocomplete for the proposal form (members only).
     */
    public function bookSearch(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || (!$this->isActiveMember($club) && !$this->canManage($club))) {
            return $this->json($response, ['results' => []], 403);
        }
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return $this->json($response, ['results' => []]);
        }
        $results = [];
        foreach ($this->repo->searchCatalog($q) as $book) {
            $results[] = [
                'id' => (int) $book['id'],
                'label' => $book['titolo'] . ($book['autori'] ? ' — ' . $book['autori'] : '')
                    . ($book['anno_pubblicazione'] ? ' (' . $book['anno_pubblicazione'] . ')' : ''),
            ];
        }
        return $this->json($response, ['results' => $results]);
    }

    /**
     * Propose a catalog book to the club.
     *
     * Permission: `proposals.create` (granular matrix — system 'member'
     * role holds it, guests do not; owner/moderator and Pinakes admin/staff
     * always pass, custom club roles per their JSON). The per-member open
     * proposal limit and the moderation bypass below intentionally keep
     * using canManage(): only real club managers skip them.
     */
    /**
     * The user to attribute a proposal to. Defaults to the current user; a
     * manager may attribute it to another ACTIVE member via `proposed_by`
     * (Uwe #138 — a manager entering proposals on behalf of members).
     *
     * @param array<string, mixed> $club
     * @param mixed $body
     */
    private function resolveProposer(array $club, $body, int $userId): int
    {
        if ($this->canManage($club)) {
            $chosen = self::intOrNull($body, 'proposed_by');
            if ($chosen !== null && $chosen > 0 && $this->repo->isActiveMember((int) $club['id'], $chosen)) {
                return $chosen;
            }
        }
        return $userId;
    }

    public function propose(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'proposals.create')) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();

        // External proposal: a book NOT in the catalogue. Kept entirely in the
        // plugin's own table so it never requires (or creates) a `libri` row.
        if (self::str($body, 'source', 20) === 'external') {
            return $this->proposeExternal($response, $club, $slug, $body);
        }

        $libroId = self::intOrNull($body, 'libro_id');
        $motivation = self::str($body, 'motivation', 3000);
        if ($libroId === null) {
            $this->flash('error', __('Seleziona un libro dal catalogo.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        if ($this->repo->bookAlreadyInClub((int) $club['id'], $libroId)) {
            $this->flash('error', __('Questo libro è già presente nel club.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        // The catalog row must exist and not be soft-deleted.
        $catBook = $this->repo->searchCatalogById($libroId);
        if ($catBook === null) {
            $this->flash('error', __('Libro non trovato in catalogo.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }

        $states = $this->repo->workflowStates($club);
        $entryState = Repo::entryStateKey($states);
        $settings = $club['settings'];
        $userId = (int) $this->userId();

        $maxProposals = $settings['max_proposals_per_member'] ?? null;
        if (!$this->canManage($club) && $maxProposals !== null && $maxProposals > 0) {
            $open = $this->repo->countOpenProposalsBy((int) $club['id'], $userId, $entryState);
            if ($open >= (int) $maxProposals) {
                $this->flash('error', sprintf(__('Hai già %d proposte aperte: attendi che vengano votate.'), $open));
                return $this->redirect($response, '/book-club/' . $slug);
            }
        }

        $moderated = !empty($settings['moderate_proposals']) && !$this->canManage($club);
        $state = $moderated ? BookClubPlugin::STATE_PENDING : $entryState;
        $proposer = $this->resolveProposer($club, $body, $userId);
        $clubBookId = $this->repo->createClubBook((int) $club['id'], $libroId, $state, $proposer, $motivation);
        if ($clubBookId === null) {
            $this->flash('error', __('Proposta non salvata, riprova.'));
        } else {
            if (function_exists('do_action')) {
                do_action('bookclub.book.proposed', $clubBookId);
            }
            $this->notifyProposal($club, (string) ($catBook['titolo'] ?? ''), $slug, $proposer);
            $this->flash('success', $moderated
                ? __('Proposta inviata: sarà visibile dopo l\'approvazione di un moderatore.')
                : __('Proposta aggiunta al club.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /** Notify the club managers that a new book was proposed. */
    private function notifyProposal(array $club, string $title, string $slug, int $proposerId): void
    {
        $proposerLabel = $this->repo->userLabel($proposerId) ?? $this->currentUserLabel();
        $this->notifyClubEvent(
            $club,
            'general',
            sprintf(__('Nuova proposta nel club "%s"'), (string) $club['name']),
            sprintf(__('%s ha proposto "%s" nel club "%s".'), $proposerLabel, $title, (string) $club['name']),
            $slug
        );
    }

    /**
     * Propose a book that is NOT in the catalogue (external proposal). Same
     * quota/moderation rules as a catalogue proposal, but the metadata is
     * stored in the plugin's own table — the library catalog is untouched.
     *
     * @param array<string, mixed> $club
     * @param mixed $body
     */
    private function proposeExternal(ResponseInterface $response, array $club, string $slug, $body): ResponseInterface
    {
        $titolo = self::str($body, 'ext_titolo', 500);
        $motivation = self::str($body, 'motivation', 3000);
        if ($titolo === '') {
            $this->flash('error', __('Inserisci almeno il titolo del libro.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }

        $states = $this->repo->workflowStates($club);
        $entryState = Repo::entryStateKey($states);
        $settings = $club['settings'];
        $userId = (int) $this->userId();

        $maxProposals = $settings['max_proposals_per_member'] ?? null;
        if (!$this->canManage($club) && $maxProposals !== null && $maxProposals > 0) {
            $open = $this->repo->countOpenProposalsBy((int) $club['id'], $userId, $entryState);
            if ($open >= (int) $maxProposals) {
                $this->flash('error', sprintf(__('Hai già %d proposte aperte: attendi che vengano votate.'), $open));
                return $this->redirect($response, '/book-club/' . $slug);
            }
        }

        $moderated = !empty($settings['moderate_proposals']) && !$this->canManage($club);
        $state = $moderated ? BookClubPlugin::STATE_PENDING : $entryState;

        $proposer = $this->resolveProposer($club, $body, $userId);
        $clubBookId = $this->repo->proposeExternalBook((int) $club['id'], [
            'titolo'  => $titolo,
            'autori'  => self::str($body, 'ext_autori', 500),
            'isbn'    => self::str($body, 'ext_isbn', 20),
            'anno'    => self::str($body, 'ext_anno', 10),
            'editore' => self::str($body, 'ext_editore', 255),
        ], $state, $proposer, $motivation);

        if ($clubBookId === null) {
            $this->flash('error', __('Proposta non salvata, riprova.'));
        } else {
            if (function_exists('do_action')) {
                do_action('bookclub.book.proposed', $clubBookId);
            }
            $this->notifyProposal($club, $titolo, $slug, $proposer);
            $this->flash('success', $moderated
                ? __('Proposta inviata: sarà visibile dopo l\'approvazione di un moderatore.')
                : __('Proposta aggiunta al club.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * Acquire an external proposal into the catalogue (managers only): creates
     * the real `libri` row and repoints the club-book to it. This is the only
     * path by which an external proposal enters the library.
     */
    public function acquireBook(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $book = $this->repo->clubBook($bookId);
        if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if (empty($book['is_external'])) {
            $this->flash('error', __('Questo libro è già in catalogo.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $acquireReason = null;
        $libroId = $this->repo->acquireExternalBook($bookId, $acquireReason);
        if ($libroId !== null) {
            $this->flash('success', __('Libro acquisito e aggiunto al catalogo.'));
        } elseif ($acquireReason === 'already_linked') {
            $this->flash('error', __('Questo libro è già collegato a un\'altra proposta di questo club: non è stato acquisito di nuovo.'));
        } else {
            $this->flash('error', __('Acquisizione non riuscita, riprova.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * Manual workflow transition. Also used to approve or reject moderated
     * proposals ('pending' → entry state / delete).
     *
     * Permissions (granular matrix, two concerns gated separately):
     * - proposal moderation — approving a book still in the 'pending'
     *   moderation state, or state='reject-proposal' — requires
     *   `proposals.approve`;
     * - every other workflow move requires `workflow.transition`.
     */
    public function changeBookState(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        $book = $this->repo->clubBook($bookId);
        if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $toState = self::str($body, 'state', 50);

        // Moderation branch (approve a pending proposal / reject it) is
        // gated by proposals.approve; plain transitions by workflow.transition.
        $isModeration = $toState === 'reject-proposal'
            || $book['state'] === BookClubPlugin::STATE_PENDING;
        if (!$this->can($club, $isModeration ? 'proposals.approve' : 'workflow.transition')) {
            return $this->notFound($response);
        }
        $states = $this->repo->workflowStates($club);

        if ($toState === 'reject-proposal') {
            // Hard-delete a rejected moderated proposal: it never entered the
            // workflow, so there is no history to preserve.
            if ($book['state'] === BookClubPlugin::STATE_PENDING) {
                $this->repo->deleteClubBook($bookId);
                $this->flash('success', __('Proposta rifiutata.'));
            }
            return $this->redirect($response, '/book-club/' . $slug);
        }

        if (Repo::stateByKey($states, $toState) === null) {
            $this->flash('error', __('Stato non valido.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $this->repo->changeBookState($bookId, (string) $book['state'], $toState, $this->userId());

        // Optional reading dates, saved together with a transition.
        $starts = self::str($body, 'reading_starts', 10);
        $ends = self::str($body, 'reading_ends', 10);
        if ($starts !== '' || $ends !== '') {
            $validDate = static fn(string $d): ?string =>
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? $d : null;
            $this->repo->setBookReadingDates($bookId, $validDate($starts), $validDate($ends));
        }

        $this->flash('success', __('Stato del libro aggiornato.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * Remove a book from the club's list entirely (managers only). Uwe #138:
     * a dedicated "remove", distinct from moving a book to an archive-like
     * state. The club-book row is deleted (state history / poll options cascade
     * away, meetings keep their record with a NULL book) and, for a
     * never-acquired external proposal, its metadata is cleaned up too.
     */
    public function removeBook(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $bookId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $book = $this->repo->clubBook($bookId);
        if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ($this->repo->deleteClubBook($bookId)) {
            $this->flash('success', __('Libro rimosso dal club.'));
        } else {
            $this->flash('error', __('Impossibile rimuovere il libro, riprova.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * PDF of the club's reading list, grouped by workflow state (Uwe #138).
     * Available to any active member or manager; pending proposals are only
     * included for managers.
     */
    public function booksPdf(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $membership = $this->membership($club);
        $isMember = $membership !== null && $membership['status'] === 'active';
        $canManage = $this->canManage($club);
        if (!$isMember && !$canManage) {
            return $this->notFound($response);
        }

        $states = $this->repo->workflowStates($club);
        $books = $this->repo->clubBooks((int) $club['id']);
        if ($canManage) {
            array_unshift($states, [
                'key' => BookClubPlugin::STATE_PENDING,
                'label' => __('In attesa di approvazione'),
            ]);
        } else {
            $books = array_values(array_filter(
                $books,
                static fn(array $book): bool => $book['state'] !== BookClubPlugin::STATE_PENDING
            ));
        }
        $booksByState = [];
        foreach ($books as $b) {
            $booksByState[(string) $b['state']][] = $b;
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $appName = \App\Support\ConfigStore::get('app.name', 'Biblioteca');
        $pdf->SetCreator($appName);
        $pdf->SetAuthor($appName);
        $pdf->SetTitle((string) $club['name'] . ' — ' . __('I libri del club'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->Cell(0, 10, (string) $club['name'], 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->Cell(0, 7, __('I libri del club'), 0, 1, 'L');
        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 6, __('Documento generato il %s alle %s', date('d/m/Y'), date('H:i')), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        foreach ($states as $state) {
            $stateBooks = $booksByState[(string) $state['key']] ?? [];
            if ($stateBooks === []) {
                continue;
            }
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(0, 8, (string) $state['label'] . '  (' . count($stateBooks) . ')', 0, 1, 'L', true);
            $pdf->Ln(1);
            $pdf->SetFont('dejavusans', '', 10);
            foreach ($stateBooks as $b) {
                $line = '• ' . \App\Support\HtmlHelper::decode((string) ($b['titolo'] ?? ''));
                if (!empty($b['autori'])) {
                    $line .= ' — ' . \App\Support\HtmlHelper::decode((string) $b['autori']);
                }
                if (!empty($b['is_external'])) {
                    $line .= ' [' . __('Proposta esterna') . ']';
                }
                $pdf->MultiCell(0, 6, $line, 0, 'L');
            }
            $pdf->Ln(2);
        }
        if ($books === []) {
            $pdf->SetFont('dejavusans', 'I', 10);
            $pdf->MultiCell(0, 6, __('Nessun libro nel club.'), 0, 'L');
        }

        $filename = 'book-club-' . $slug . '-' . date('Ymd') . '.pdf';
        $response->getBody()->write($pdf->Output('', 'S'));
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $this->userId();
        $clubs = $this->repo->listClubsForUser($userId);
        $cards = [];
        foreach ($clubs as $club) {
            $cards[] = [
                'club' => $club,
                'snapshot' => $this->repo->clubSnapshot($club),
            ];
        }
        return $this->renderPublic($response, 'public/dashboard', [
            'cards' => $cards,
        ], __('I miei club di lettura'));
    }
}
