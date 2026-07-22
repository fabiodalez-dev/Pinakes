<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Plugins\BookClub\Modules\MobileModule;
use App\Plugins\BookClub\Modules\Registry;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * JSON endpoints for the Pinakes mobile app (routes owned by MobileModule,
 * bearer-token auth by the mobile-api plugin's AppAuthMiddleware, which
 * mirrors the token identity into $_SESSION['user'] — so every inherited
 * permission helper works exactly as on the web).
 *
 * Response envelope, matching the mobile-api conventions:
 *   200 {"success":true,"data":{...}}
 *   4xx {"success":false,"error":{"code":"...","message":"..."}}
 *
 * Action semantics deliberately MIRROR the web controllers (join privacy
 * rules, proposal eligibility/moderation, guest-vote block, RSVP seat
 * limit, progress clamp). Voting supports the simple/multi/weighted
 * ballots (options[]); the advanced ballots (stars/ranking/elimination)
 * return mode_not_supported so the app can deep-link to the web page.
 */
class MobileApiController extends BaseController
{
    private MobileModule $module;

    public function __construct(mysqli $db, Repo $repo, MobileModule $module)
    {
        parent::__construct($db, $repo);
        $this->module = $module;
    }

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $manifest = @json_decode((string) @file_get_contents(__DIR__ . '/../plugin.json'), true);
        return $this->ok($response, [
            'plugin' => 'book-club',
            'enabled' => true,
            // The mobile_api.enabled gate: with false every authenticated
            // endpoint answers 403 app_access_disabled, so a client can hide
            // the whole section from this single discovery call.
            'app_access_enabled' => $this->module->appAccessEnabled(),
            'version' => is_array($manifest) ? (string) ($manifest['version'] ?? '0') : '0',
            'requires' => ['mobile-api'],
            'endpoints' => [
                'GET /api/v1/bookclub/clubs',
                'GET /api/v1/bookclub/clubs/{slug}',
                'GET /api/v1/bookclub/me/dashboard',
                'POST /api/v1/bookclub/clubs/{slug}/join',
                'POST /api/v1/bookclub/clubs/{slug}/proposals',
                'POST /api/v1/bookclub/clubs/{slug}/polls/{pollId}/vote',
                'POST /api/v1/bookclub/clubs/{slug}/meetings/{meetingId}/rsvp',
                'POST /api/v1/bookclub/clubs/{slug}/books/{clubBookId}/progress',
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function clubs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $this->userId();
        $mine = [];
        foreach ($this->repo->listClubsForUser($userId) as $club) {
            if (!Registry::clubEnabled($this->repo->clubById((int) $club['id']) ?? $club, $this->module)) {
                continue;
            }
            $mine[] = [
                'id' => (int) $club['id'],
                'slug' => (string) $club['slug'],
                'name' => (string) $club['name'],
                'color' => (string) $club['color'],
                'privacy' => (string) $club['privacy'],
                'member_status' => (string) $club['member_status'],
                'role' => (string) ($club['role_slug'] ?? 'member'),
            ];
        }
        $directory = [];
        foreach ($this->repo->listVisibleClubs() as $club) {
            $club = $this->repo->clubById((int) $club['id']) ?? $club;
            if (!Registry::clubEnabled($club, $this->module)) {
                continue;
            }
            $directory[] = [
                'id' => (int) $club['id'],
                'slug' => (string) $club['slug'],
                'name' => (string) $club['name'],
                'description' => mb_substr((string) ($club['description'] ?? ''), 0, 300),
                'color' => (string) $club['color'],
                'privacy' => (string) $club['privacy'],
                'member_count' => $this->repo->countActiveMembers((int) $club['id']),
                'max_members' => $club['max_members'] !== null ? (int) $club['max_members'] : null,
            ];
        }
        return $this->ok($response, ['my_clubs' => $mine, 'directory' => $directory]);
    }

    public function clubDetail(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->clubForApi($slug, $error);
        if ($club === null) {
            return $error !== null ? $error($response) : $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        $userId = (int) $this->userId();
        $membership = $this->membership($club);
        $isMember = $membership !== null && $membership['status'] === 'active';
        $canManage = $this->canManage($club);
        $states = $this->repo->workflowStates($club);
        $stateIndex = [];
        foreach ($states as $s) {
            $stateIndex[$s['key']] = $s;
        }

        $books = [];
        foreach ($this->repo->clubBooks((int) $club['id']) as $book) {
            if ($book['state'] === BookClubPlugin::STATE_PENDING && !$canManage) {
                continue;
            }
            $st = $stateIndex[$book['state']] ?? null;
            $books[] = [
                'id' => (int) $book['id'],
                'libro_id' => (int) $book['libro_id'],
                'title' => (string) $book['titolo'],
                'authors' => (string) ($book['autori'] ?? ''),
                'cover_url' => (string) ($book['copertina_url'] ?? ''),
                'state' => (string) $book['state'],
                'state_label' => (string) ($st['label'] ?? $book['state']),
                'state_color' => (string) ($st['color'] ?? '#6b7280'),
                'is_current' => !empty($st['flags']['current']),
                'reading_starts' => $book['reading_starts'],
                'reading_ends' => $book['reading_ends'],
                'motivation' => (string) ($book['motivation'] ?? ''),
                'my_progress' => $this->myProgress((int) $book['id'], $userId),
            ];
        }

        $polls = [];
        foreach ($this->repo->clubPolls((int) $club['id']) as $poll) {
            $polls[] = [
                'id' => (int) $poll['id'],
                'title' => (string) $poll['title'],
                'mode' => (string) $poll['mode'],
                'status' => (string) $poll['status'],
                'closes_at' => self::isoUtc($poll['closes_at']),
                'votes_per_member' => (int) $poll['votes_per_member'],
                'voter_count' => (int) ($poll['voter_count'] ?? 0),
                'my_option_ids' => $this->repo->userVotes((int) $poll['id'], $userId),
                'options' => array_map(static fn(array $o): array => [
                    'id' => (int) $o['id'],
                    'club_book_id' => (int) $o['club_book_id'],
                    'title' => (string) $o['titolo'],
                    'score' => (float) $o['score'],
                ], $this->repo->pollOptions((int) $poll['id'])),
                'votable_in_app' => in_array((string) $poll['mode'], ['simple', 'multi', 'weighted'], true),
            ];
        }

        $meetings = [];
        foreach ($this->repo->clubMeetings((int) $club['id']) as $meeting) {
            $rsvp = $this->repo->userRsvp((int) $meeting['id'], $userId);
            $meetings[] = [
                'id' => (int) $meeting['id'],
                'title' => (string) $meeting['title'],
                'starts_at' => self::isoUtc($meeting['starts_at']) ?? (string) $meeting['starts_at'],
                'ends_at' => self::isoUtc($meeting['ends_at']),
                'kind' => (string) $meeting['kind'],
                'status' => (string) $meeting['status'],
                'location' => (string) ($meeting['location'] ?? ''),
                // video links are members-only, exactly like the web UI.
                'video_url' => $isMember || $canManage ? (string) ($meeting['video_url'] ?? '') : '',
                'agenda' => (string) ($meeting['agenda'] ?? ''),
                'book_title' => (string) ($meeting['book_title'] ?? ''),
                'yes_count' => (int) $meeting['yes_count'],
                'seats' => $meeting['seats'] !== null ? (int) $meeting['seats'] : null,
                'my_rsvp' => $rsvp !== null ? (string) $rsvp['response'] : null,
            ];
        }

        return $this->ok($response, [
            'club' => [
                'id' => (int) $club['id'],
                'slug' => (string) $club['slug'],
                'name' => (string) $club['name'],
                'description' => (string) ($club['description'] ?? ''),
                'rules' => $isMember || $canManage ? (string) ($club['rules'] ?? '') : '',
                'color' => (string) $club['color'],
                'privacy' => (string) $club['privacy'],
                'member_count' => $this->repo->countActiveMembers((int) $club['id']),
                'max_members' => $club['max_members'] !== null ? (int) $club['max_members'] : null,
            ],
            'my_membership' => $membership !== null ? [
                'status' => (string) $membership['status'],
                'role' => (string) ($membership['role_slug'] ?? 'member'),
            ] : null,
            'workflow' => array_map(static fn(array $s): array => [
                'key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'], 'flags' => (object) $s['flags'],
            ], $states),
            'books' => $books,
            'polls' => $polls,
            'meetings' => $meetings,
        ]);
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = (int) $this->userId();
        $cards = [];
        foreach ($this->repo->listClubsForUser($userId) as $clubRow) {
            $club = $this->repo->clubById((int) $clubRow['id']);
            if ($club === null || !Registry::clubEnabled($club, $this->module)) {
                continue;
            }
            $snapshot = $this->repo->clubSnapshot($club);
            $cards[] = [
                'club' => [
                    'id' => (int) $club['id'],
                    'slug' => (string) $club['slug'],
                    'name' => (string) $club['name'],
                    'color' => (string) $club['color'],
                    'member_status' => (string) $clubRow['member_status'],
                    'role' => (string) ($clubRow['role_slug'] ?? 'member'),
                ],
                'current_books' => array_map(fn(array $b): array => [
                    'id' => (int) $b['id'],
                    'title' => (string) $b['titolo'],
                    'authors' => (string) ($b['autori'] ?? ''),
                    'cover_url' => (string) ($b['copertina_url'] ?? ''),
                    'reading_ends' => $b['reading_ends'],
                    'my_progress' => $this->myProgress((int) $b['id'], $userId),
                ], $snapshot['current_books']),
                'next_meeting' => $snapshot['next_meeting'] !== null ? [
                    'id' => (int) $snapshot['next_meeting']['id'],
                    'title' => (string) $snapshot['next_meeting']['title'],
                    'starts_at' => self::isoUtc($snapshot['next_meeting']['starts_at']) ?? (string) $snapshot['next_meeting']['starts_at'],
                ] : null,
                'open_polls' => array_map(static fn(array $p): array => [
                    'id' => (int) $p['id'],
                    'title' => (string) $p['title'],
                    'closes_at' => self::isoUtc($p['closes_at']),
                ], $snapshot['open_polls']),
            ];
        }
        return $this->ok($response, ['clubs' => $cards]);
    }

    // ------------------------------------------------------------------
    // Actions (mirror the web controllers' rules)
    // ------------------------------------------------------------------

    public function join(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        // Web-parity resolution (PublicController::join): existence + active
        // + mobile module only, WITHOUT the canView gate — otherwise the
        // invite_only branch below is unreachable for invite/hidden clubs
        // and the documented 403 contract can never fire.
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || (int) $club['is_active'] !== 1
            || !Registry::clubEnabled($club, $this->module)) {
            return $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        $userId = (int) $this->userId();
        $existing = $this->repo->memberRow((int) $club['id'], $userId);
        if ($existing !== null && in_array($existing['status'], ['active', 'pending'], true)) {
            return $this->ok($response, ['status' => (string) $existing['status']]);
        }
        if ($existing !== null && $existing['status'] === 'banned') {
            return $this->fail($response, 'banned', __('Non puoi unirti a questo club.'), 403);
        }
        if (!in_array($club['privacy'], ['public', 'private'], true)) {
            return $this->fail($response, 'invite_only', __('Questo club è accessibile solo su invito.'), 403);
        }
        if ($club['max_members'] !== null && $this->repo->countActiveMembers((int) $club['id']) >= (int) $club['max_members']) {
            return $this->fail($response, 'club_full', __('Il club ha raggiunto il numero massimo di membri.'), 409);
        }
        $roleId = $this->repo->roleIdBySlug('member');
        if ($roleId === null) {
            return $this->fail($response, 'internal_error', __('Errore del server'), 500);
        }
        $status = $club['privacy'] === 'public' ? 'active' : 'pending';
        $this->repo->upsertMember((int) $club['id'], $userId, $roleId, $status);
        if (function_exists('do_action')) {
            do_action('bookclub.member.joined', (int) $club['id'], $userId, $status);
        }
        return $this->ok($response, ['status' => $status]);
    }

    public function propose(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->clubForApi($slug, $error);
        if ($club === null) {
            return $error !== null ? $error($response) : $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        if (!$this->can($club, 'proposals.create')) {
            return $this->fail($response, 'forbidden', __('Non hai i permessi per proporre libri in questo club.'), 403);
        }
        $body = $this->jsonBody($request);
        $libroId = isset($body['libro_id']) && is_numeric($body['libro_id']) ? (int) $body['libro_id'] : 0;
        $motivation = mb_substr(trim((string) ($body['motivation'] ?? '')), 0, 3000);
        if ($libroId <= 0 || $this->repo->searchCatalogById($libroId) === null) {
            return $this->fail($response, 'invalid_book', __('Libro non trovato in catalogo.'), 422);
        }
        if ($this->repo->bookAlreadyInClub((int) $club['id'], $libroId)) {
            return $this->fail($response, 'duplicate', __('Questo libro è già presente nel club.'), 409);
        }
        $states = $this->repo->workflowStates($club);
        $entryState = Repo::entryStateKey($states);
        $settings = $club['settings'];
        $userId = (int) $this->userId();

        $maxProposals = $settings['max_proposals_per_member'] ?? null;
        if (!$this->canManage($club) && $maxProposals !== null && (int) $maxProposals > 0) {
            $open = $this->repo->countOpenProposalsBy((int) $club['id'], $userId, $entryState);
            if ($open >= (int) $maxProposals) {
                return $this->fail($response, 'limit_reached', sprintf(__('Hai già %d proposte aperte: attendi che vengano votate.'), $open), 429);
            }
        }
        $moderated = !empty($settings['moderate_proposals']) && !$this->canManage($club);
        $state = $moderated ? BookClubPlugin::STATE_PENDING : $entryState;
        $clubBookId = $this->repo->createClubBook((int) $club['id'], $libroId, $state, $userId, $motivation);
        if ($clubBookId === null) {
            return $this->fail($response, 'internal_error', __('Proposta non salvata, riprova.'), 500);
        }
        if (function_exists('do_action')) {
            do_action('bookclub.book.proposed', $clubBookId);
        }
        return $this->ok($response, ['club_book_id' => $clubBookId, 'state' => $state, 'moderated' => $moderated]);
    }

    /**
     * Ballot for simple/multi/weighted polls: {"options":[optionId, ...]}.
     * Advanced modes return mode_not_supported so the app can deep-link the
     * web poll page instead.
     */
    public function vote(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $pollId): ResponseInterface
    {
        $club = $this->clubForApi($slug, $error);
        if ($club === null) {
            return $error !== null ? $error($response) : $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        if (!$this->isActiveMember($club)) {
            return $this->fail($response, 'forbidden', __('Solo i membri attivi possono votare.'), 403);
        }
        $membership = $this->membership($club);
        if (($membership['role_slug'] ?? '') === 'guest') {
            return $this->fail($response, 'forbidden', __('Gli ospiti non possono votare.'), 403);
        }
        $poll = $this->repo->poll($pollId);
        if ($poll === null || (int) $poll['club_id'] !== (int) $club['id']) {
            return $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        if ($poll['status'] === 'open' && $this->repo->pollDeadlinePassed($pollId)) {
            (new PollController($this->db, $this->repo))->closeExpiredPollById($pollId);
            return $this->fail($response, 'poll_closed', __('La votazione è chiusa.'), 409);
        }
        if ($poll['status'] !== 'open') {
            return $this->fail($response, 'poll_closed', __('La votazione è chiusa.'), 409);
        }
        $mode = (string) ($poll['mode'] ?? 'simple');
        if (!in_array($mode, ['simple', 'multi', 'weighted'], true)) {
            return $this->fail($response, 'mode_not_supported', __('Questa modalità di voto è disponibile solo dal sito.'), 422);
        }

        $body = $this->jsonBody($request);
        $picked = is_array($body['options'] ?? null) ? $body['options'] : [];
        $picked = array_values(array_unique(array_map('intval', $picked)));
        $max = $mode === 'simple' ? 1 : (int) $poll['votes_per_member'];
        if ($picked === []) {
            return $this->fail($response, 'empty_ballot', __('Seleziona almeno un libro.'), 422);
        }
        if (count($picked) > $max) {
            return $this->fail($response, 'too_many', sprintf(__n('Puoi esprimere al massimo %d voto.', 'Puoi esprimere al massimo %d voti.', $max), $max), 422);
        }
        $validIds = array_map(static fn(array $o): int => (int) $o['id'], $this->repo->pollOptions($pollId));
        foreach ($picked as $optionId) {
            if (!in_array($optionId, $validIds, true)) {
                return $this->fail($response, 'invalid_option', __('Opzione non valida.'), 422);
            }
        }

        // Same weight rule as the web weighted ballot (per-poll weights with
        // the legacy fallback).
        $value = 1.0;
        if ($mode === 'weighted') {
            $role = (string) ($membership['role_slug'] ?? '');
            if ($role === 'owner') {
                $value = isset($poll['weight_owner']) && is_numeric($poll['weight_owner'])
                    ? max(1.0, min(5.0, (float) $poll['weight_owner'])) : 2.0;
            } elseif ($role === 'moderator') {
                $value = isset($poll['weight_moderator']) && is_numeric($poll['weight_moderator'])
                    ? max(1.0, min(5.0, (float) $poll['weight_moderator'])) : 1.5;
            }
        }

        $userId = (int) $this->userId();
        $this->db->begin_transaction();
        try {
            $this->repo->clearUserVotes($pollId, $userId);
            foreach ($picked as $optionId) {
                $this->repo->castVote($pollId, $optionId, $userId, $value);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub:mobile] vote failed: ' . $e->getMessage());
            return $this->fail($response, 'internal_error', __('Voto non registrato, riprova.'), 500);
        }
        return $this->ok($response, ['poll_id' => $pollId, 'options' => $picked]);
    }

    public function rsvp(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $meetingId): ResponseInterface
    {
        $club = $this->clubForApi($slug, $error);
        if ($club === null) {
            return $error !== null ? $error($response) : $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        if (!$this->isActiveMember($club)) {
            return $this->fail($response, 'forbidden', __('Solo i membri attivi del club possono confermare la partecipazione.'), 403);
        }
        $meeting = $this->repo->meeting($meetingId);
        if ($meeting === null || (int) $meeting['club_id'] !== (int) $club['id'] || $meeting['status'] !== 'scheduled') {
            return $this->fail($response, 'not_found', __('Incontro non trovato.'), 404);
        }
        $body = $this->jsonBody($request);
        $answer = (string) ($body['response'] ?? '');
        if (!in_array($answer, ['yes', 'no', 'maybe'], true)) {
            return $this->fail($response, 'invalid_response', __('Opzione non valida.'), 422);
        }
        $userId = (int) $this->userId();
        if ($answer === 'yes' && $meeting['seats'] !== null) {
            $current = $this->repo->userRsvp($meetingId, $userId);
            $alreadyYes = $current !== null && $current['response'] === 'yes';
            if (!$alreadyYes && (int) $meeting['yes_count'] >= (int) $meeting['seats']) {
                return $this->fail($response, 'no_seats', __('Non ci sono più posti disponibili per questo incontro.'), 409);
            }
        }
        $this->repo->setRsvp($meetingId, $userId, $answer);
        return $this->ok($response, ['meeting_id' => $meetingId, 'response' => $answer]);
    }

    /**
     * Reading progress: {"percent":0-100, "finished":bool} — requires the
     * reading module enabled for the club and its tables in place.
     */
    public function progress(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $clubBookId): ResponseInterface
    {
        $club = $this->clubForApi($slug, $error);
        if ($club === null) {
            return $error !== null ? $error($response) : $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        if (!$this->isActiveMember($club)) {
            return $this->fail($response, 'forbidden', __('Solo i membri attivi del club possono registrare il proprio progresso.'), 403);
        }
        if (!$this->clubModuleEnabled($club, 'reading') || !class_exists(ReadingRepo::class)) {
            return $this->fail($response, 'module_disabled', __('Il modulo lettura non è attivo per questo club.'), 404);
        }
        $book = $this->repo->clubBook($clubBookId);
        if ($book === null || (int) $book['club_id'] !== (int) $club['id'] || $book['state'] === BookClubPlugin::STATE_PENDING) {
            return $this->fail($response, 'not_found', __('Club non trovato.'), 404);
        }
        $body = $this->jsonBody($request);
        $percent = isset($body['percent']) && is_numeric($body['percent'])
            ? max(0, min(100, (int) $body['percent'])) : 0;
        $finished = !empty($body['finished']) || $percent >= 100;
        if ($finished) {
            $percent = 100;
        }
        $userId = (int) $this->userId();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO bookclub_progress (club_book_id, user_id, percent, finished_at)
                 VALUES (?, ?, ?, IF(? = 1, NOW(), NULL))
                 ON DUPLICATE KEY UPDATE
                   percent = VALUES(percent),
                   finished_at = IF(? = 1, COALESCE(finished_at, NOW()), NULL)'
            );
            if ($stmt === false) {
                throw new \RuntimeException($this->db->error);
            }
            $flag = $finished ? 1 : 0;
            $stmt->bind_param('iiiii', $clubBookId, $userId, $percent, $flag, $flag);
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                throw new \RuntimeException('execute failed');
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:mobile] progress failed: ' . $e->getMessage());
            return $this->fail($response, 'internal_error', __('Impossibile salvare il progresso. Riprova.'), 500);
        }
        return $this->ok($response, ['club_book_id' => $clubBookId, 'percent' => $percent, 'finished' => $finished]);
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Resolve the club for an API call: must exist, be active, have the
     * mobile module enabled and be visible to the token user (canView).
     * On failure $error is set to a closure producing the JSON response.
     *
     * @param callable|null $error out-param
     * @return array<string, mixed>|null
     */
    private function clubForApi(string $slug, ?callable &$error): ?array
    {
        $error = null;
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || (int) $club['is_active'] !== 1
            || !Registry::clubEnabled($club, $this->module) || !$this->canView($club)) {
            $error = fn(ResponseInterface $r): ResponseInterface =>
                $this->fail($r, 'not_found', __('Club non trovato.'), 404);
            return null;
        }
        return $club;
    }

    /** @param array<string, mixed> $club */
    private function clubModuleEnabled(array $club, string $slug): bool
    {
        foreach (Registry::all($this->db) as $module) {
            if ($module->slug() === $slug) {
                return Registry::clubEnabled($club, $module);
            }
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    private function myProgress(int $clubBookId, int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT percent, finished_at FROM bookclub_progress WHERE club_book_id = ? AND user_id = ? LIMIT 1'
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->bind_param('ii', $clubBookId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row === null || $row === false) {
                return null;
            }
            return [
                'percent' => (int) $row['percent'],
                'finished' => $row['finished_at'] !== null,
            ];
        } catch (\Throwable $e) {
            // Reading tables absent (module never activated) — no progress.
            return null;
        }
    }

    /**
     * MySQL DATETIME → ISO-8601 UTC with Z suffix (MOBILE_API_SPEC: "dates
     * ISO-8601 UTC; the app formats locally"). DATE-only fields (e.g.
     * reading_starts/reading_ends) are passed through untouched by callers.
     */
    private static function isoUtc(mixed $datetime): ?string
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** @return array<string, mixed> */
    private function jsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $decoded = json_decode((string) $request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private function ok(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function fail(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            ['success' => false, 'error' => ['code' => $code, 'message' => $message]],
            JSON_UNESCAPED_UNICODE
        ));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
