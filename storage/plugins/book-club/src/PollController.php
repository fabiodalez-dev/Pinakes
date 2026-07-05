<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Voting: poll creation from proposals, ballot casting, deadline-driven
 * auto close and workflow transition of winner/losers.
 *
 * Core modes (Fase 1, always available):
 *  - simple  one vote per member;
 *  - multi   N votes per member (votes_per_member) — Discussion #138.
 *
 * Advanced modes (module `voting2`, plan §7.4 — the poll list page and the
 * pick-winner route live on VotingModule; creation of these modes is
 * blocked when the module is disabled for the club):
 *  - stars       each member rates any subset of options 1–5; the vote
 *                value is the star count, score = SUM(value); re-voting
 *                replaces all of the member's rows;
 *  - ranking     each member orders ALL options (validated permutation
 *                1..N); Borda count: value = N - rank + 1;
 *  - elimination simple-style ballots scoped to bookclub_polls.round
 *                (bookclub_votes.round). Closing acts per round: the
 *                last-place option gets eliminated_in_round = round; with
 *                more than two survivors the round advances and the poll
 *                stays open, with two the loser is eliminated and the
 *                winner resolves as usual. Expired polls resolve rounds
 *                repeatedly in one pass (vote-less rounds inherit the
 *                standing of the last voted round);
 *  - weighted    like simple/multi but the vote value is fixed at 2.0 for
 *                owners and 1.5 for moderators (1.0 otherwise).
 *
 * Cross-mode close rules:
 *  - quorum_pct  when set and distinct voters of the final round are fewer
 *                than ceil(quorum_pct% of active members), the poll closes
 *                WITHOUT a winner and every option book returns to the
 *                workflow entry state;
 *  - tiebreak    on an equal top score: oldest_proposal (Fase 1 default),
 *                random (deterministic crc32 pick) or admin (close with
 *                NULL winner; a manager proclaims the winner among the
 *                tied options via pickWinner()).
 */
class PollController extends BaseController
{
    /** Modes selectable only while the voting2 module is enabled for the club. */
    private const ADVANCED_MODES = ['stars', 'ranking', 'elimination', 'weighted'];
    private const ALL_MODES = ['simple', 'multi', 'stars', 'ranking', 'elimination', 'weighted'];
    private const TIEBREAKS = ['oldest_proposal', 'random', 'admin'];
    /** Fixed ballot weights for `weighted` polls (documented on the poll page). */
    private const WEIGHTS = ['owner' => 2.0, 'moderator' => 1.5];
    /** Float comparison tolerance for DECIMAL(5,2) scores. */
    private const EPS = 0.0001;

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $pollId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canView($club)) {
            return $this->notFound($response);
        }
        $poll = $this->repo->poll($pollId);
        if ($poll === null || (int) $poll['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        // Lazy close: correctness does not depend on the cron. Elimination
        // polls resolve their rounds repeatedly until a winner emerges.
        if ($poll['status'] === 'open' && $poll['closes_at'] !== null && strtotime((string) $poll['closes_at']) <= time()) {
            $this->resolvePoll($poll, true);
            $poll = $this->repo->poll($pollId) ?? $poll;
        }

        $userId = $this->userId();
        $mode = (string) ($poll['mode'] ?? 'simple');
        $round = $this->displayRound($poll);
        [$options, $eliminated] = $this->optionsForDisplay($poll);

        $myVoteValues = $userId !== null
            ? $this->myVoteValues($pollId, $userId, $mode === 'elimination' ? $round : null)
            : [];

        $isClosedNoWinner = $poll['status'] === 'closed' && $poll['winner_club_book_id'] === null;
        $quorumFailed = $isClosedNoWinner && $this->quorumMissed($poll, $round);
        $adminTiedIds = [];
        if ($isClosedNoWinner && !$quorumFailed && (string) ($poll['tiebreak'] ?? 'oldest_proposal') === 'admin') {
            $adminTiedIds = $this->adminTiedIds($options, $eliminated);
        }

        return $this->renderPublic($response, 'public/poll', [
            'club' => $club,
            'poll' => $poll,
            'options' => $options,
            'voters' => $poll['anonymity'] === 'public' ? $this->repo->pollVoters($pollId) : [],
            'myVotes' => array_keys($myVoteValues),
            'myVoteValues' => $myVoteValues,
            'eliminated' => $eliminated,
            'quorumFailed' => $quorumFailed,
            'adminTiedIds' => $adminTiedIds,
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
        ], (string) $poll['title']);
    }

    /**
     * Poll list + advanced creation form (route owned by VotingModule).
     * Managers see the form with every mode/quorum/tiebreak field; members
     * see the list. 404 when the voting2 module is disabled for the club.
     */
    public function pollsPage(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canView($club) || !$this->advancedVotingEnabled($club)) {
            return $this->notFound($response);
        }
        $books = $this->repo->clubBooks((int) $club['id']);
        $eligible = array_values(array_filter(
            $books,
            static fn(array $b): bool => $b['state'] !== BookClubPlugin::STATE_PENDING
        ));
        return $this->renderPublic($response, 'public/polls', [
            'club' => $club,
            'polls' => $this->repo->clubPolls((int) $club['id']),
            'eligible' => $eligible,
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
        ], __('Votazioni') . ' — ' . (string) $club['name']);
    }

    /**
     * Create a poll from selected proposals (club managers). Option books
     * move to the workflow's voting state. The advanced fields (extra
     * modes, quorum_pct, tiebreak) are optional with safe defaults, so the
     * simple form on the club page keeps working unchanged.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        if ($title === '') {
            $title = __('Votazione della prossima lettura');
        }
        $modeRaw = self::str($body, 'mode', 20);
        $mode = in_array($modeRaw, self::ALL_MODES, true) ? $modeRaw : 'simple';

        $quorumPct = null;
        $tiebreak = 'oldest_proposal';
        if ($this->advancedVotingEnabled($club)) {
            $q = self::intOrNull($body, 'quorum_pct');
            if ($q !== null && $q > 0) {
                $quorumPct = min(100, $q);
            }
            $tb = self::str($body, 'tiebreak', 20);
            if (in_array($tb, self::TIEBREAKS, true)) {
                $tiebreak = $tb;
            }
        } elseif (in_array($mode, self::ADVANCED_MODES, true)) {
            $this->flash('error', __('Le votazioni avanzate non sono attive per questo club.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }

        $votesPerMember = 1;
        if ($mode === 'multi') {
            $votesPerMember = max(1, min(20, (int) (self::intOrNull($body, 'votes_per_member') ?? 3)));
        } elseif ($mode === 'weighted') {
            $votesPerMember = max(1, min(20, (int) (self::intOrNull($body, 'votes_per_member') ?? 1)));
        }
        $anonymity = self::str($body, 'anonymity', 10) === 'secret' ? 'secret' : 'public';
        $closesAt = self::dateTimeOrNull(self::str($body, 'closes_at', 30));

        $optionIds = $body['options'] ?? [];
        if (!is_array($optionIds)) {
            $optionIds = [];
        }
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        if (count($optionIds) < 2) {
            $this->flash('error', __('Seleziona almeno due proposte da mettere in votazione.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }

        $states = $this->repo->workflowStates($club);
        $votingState = Repo::votingStateKey($states);

        // Validate every option: must belong to this club and not be pending.
        $books = [];
        foreach ($optionIds as $clubBookId) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id'] || $book['state'] === BookClubPlugin::STATE_PENDING) {
                $this->flash('error', __('Una delle proposte selezionate non è valida.'));
                return $this->redirect($response, '/book-club/' . $slug);
            }
            $books[] = $book;
        }

        $pollId = $this->repo->createPoll(
            (int) $club['id'],
            $title,
            self::str($body, 'description', 3000),
            $mode,
            $votesPerMember,
            $anonymity,
            $closesAt,
            (int) $this->userId()
        );
        if ($pollId === null) {
            $this->flash('error', __('Votazione non creata, riprova.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        if ($quorumPct !== null || $tiebreak !== 'oldest_proposal') {
            $this->setPollExtras($pollId, $quorumPct, $tiebreak);
        }
        foreach ($books as $book) {
            $this->repo->addPollOption($pollId, (int) $book['id']);
            if ($book['state'] !== $votingState) {
                $this->repo->changeBookState((int) $book['id'], (string) $book['state'], $votingState, $this->userId());
            }
        }
        if (function_exists('do_action')) {
            do_action('bookclub.poll.opened', $pollId);
        }
        $this->flash('success', __('Votazione aperta.'));
        return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
    }

    /**
     * Cast (or replace) the member's ballot. simple/multi post options[]
     * exactly as in Fase 1; the advanced modes dispatch to their own
     * handlers (stars[], ranks[], round-scoped single pick).
     */
    public function vote(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $pollId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null) {
            return $this->notFound($response);
        }
        if (!$this->isActiveMember($club)) {
            $this->flash('error', __('Solo i membri attivi possono votare.'));
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }
        $poll = $this->repo->poll($pollId);
        if ($poll === null || (int) $poll['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ($poll['status'] !== 'open' || ($poll['closes_at'] !== null && strtotime((string) $poll['closes_at']) <= time())) {
            $this->flash('error', __('La votazione è chiusa.'));
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }

        $mode = (string) ($poll['mode'] ?? 'simple');
        if ($mode === 'stars') {
            return $this->voteStars($request, $response, $slug, $poll);
        }
        if ($mode === 'ranking') {
            return $this->voteRanking($request, $response, $slug, $poll);
        }
        if ($mode === 'elimination') {
            return $this->voteElimination($request, $response, $slug, $poll);
        }

        // simple / multi (Fase 1 behaviour, untouched) + weighted (same
        // ballot, role-weighted vote value).
        $body = $request->getParsedBody();
        $picked = $body['options'] ?? [];
        if (!is_array($picked)) {
            $picked = [$picked];
        }
        $picked = array_values(array_unique(array_map('intval', $picked)));

        $max = in_array($mode, ['multi', 'weighted'], true) ? (int) $poll['votes_per_member'] : 1;
        if (count($picked) === 0) {
            $this->flash('error', __('Seleziona almeno un libro.'));
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }
        if (count($picked) > $max) {
            $this->flash('error', sprintf(__n('Puoi esprimere al massimo %d voto.', 'Puoi esprimere al massimo %d voti.', $max), $max));
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }

        // Only options belonging to this poll are acceptable.
        $validIds = array_map(static fn(array $o): int => (int) $o['id'], $this->repo->pollOptions($pollId));
        foreach ($picked as $optionId) {
            if (!in_array($optionId, $validIds, true)) {
                $this->flash('error', __('Opzione non valida.'));
                return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
            }
        }

        $value = $mode === 'weighted' ? $this->voterWeight($club) : 1.0;

        // Replace the previous ballot atomically.
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
            SecureLogger::error('[BookClub] vote failed: ' . $e->getMessage());
            $this->flash('error', __('Voto non registrato, riprova.'));
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }

        $this->flash('success', __('Voto registrato. Puoi modificarlo finché la votazione è aperta.'));
        return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
    }

    public function close(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $pollId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $poll = $this->repo->poll($pollId);
        if ($poll === null || (int) $poll['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        if ($poll['status'] !== 'open') {
            return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
        }
        $result = $this->resolvePoll($poll, false);
        switch ($result) {
            case 'next_round':
                $fresh = $this->repo->poll($pollId);
                $this->flash('success', sprintf(
                    __('Turno concluso: il libro ultimo classificato è stato eliminato. Si apre il turno %d.'),
                    max(1, (int) ($fresh['round'] ?? 1))
                ));
                break;
            case 'closed_quorum':
                $this->flash('warning', __('Quorum non raggiunto: la votazione si è chiusa senza vincitore.'));
                break;
            case 'admin_tie':
                $this->flash('warning', __('Parità in testa: proclama il vincitore tra le opzioni evidenziate.'));
                break;
            default:
                $this->flash('success', __('Votazione chiusa.'));
        }
        return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
    }

    /**
     * Manager proclamation of the winner for a poll closed on an `admin`
     * tie (route owned by VotingModule). The option must be one of the
     * tied top options; the winner then advances in the workflow and every
     * other option book returns to the entry state.
     */
    public function pickWinner(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $pollId, int $optionId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->advancedVotingEnabled($club) || !$this->canManage($club)) {
            return $this->notFound($response);
        }
        $poll = $this->repo->poll($pollId);
        if ($poll === null || (int) $poll['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $back = '/book-club/' . $slug . '/polls/' . $pollId;
        if ($poll['status'] !== 'closed'
            || $poll['winner_club_book_id'] !== null
            || (string) ($poll['tiebreak'] ?? 'oldest_proposal') !== 'admin') {
            return $this->redirect($response, $back);
        }
        if ($this->quorumMissed($poll, $this->displayRound($poll))) {
            $this->flash('error', __('Quorum non raggiunto'));
            return $this->redirect($response, $back);
        }
        [$options, $eliminated] = $this->optionsForDisplay($poll);
        $tiedIds = $this->adminTiedIds($options, $eliminated);
        if (!in_array($optionId, $tiedIds, true)) {
            $this->flash('error', __('Questa opzione non era tra quelle in parità.'));
            return $this->redirect($response, $back);
        }
        $winner = null;
        foreach ($options as $option) {
            if ((int) $option['id'] === $optionId) {
                $winner = $option;
                break;
            }
        }
        if ($winner === null || !$this->setPickedWinner($pollId, (int) $winner['club_book_id'])) {
            $this->flash('error', __('Vincitore non salvato, riprova.'));
            return $this->redirect($response, $back);
        }
        $this->transitionBooks($poll, $optionId);
        if (function_exists('do_action')) {
            do_action('bookclub.poll.closed', $pollId, (int) $winner['club_book_id']);
        }
        $this->flash('success', __('Vincitore proclamato: il libro avanza nel workflow.'));
        return $this->redirect($response, $back);
    }

    /**
     * Cron sweep — close every open poll whose deadline has passed.
     * Elimination polls resolve rounds repeatedly in one pass.
     */
    public function closeExpiredPolls(): void
    {
        foreach ($this->repo->expiredOpenPolls() as $poll) {
            try {
                $this->resolvePoll($poll, true);
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] auto-close failed for poll ' . $poll['id'] . ': ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Advanced ballots
    // ------------------------------------------------------------------

    /**
     * stars: stars[option_id] = 1..5 (0/absent = not rated). Any subset is
     * allowed but at least one rating is required. Replaces the member's
     * previous rows entirely.
     *
     * @param array<string, mixed> $poll
     */
    private function voteStars(ServerRequestInterface $request, ResponseInterface $response, string $slug, array $poll): ResponseInterface
    {
        $pollId = (int) $poll['id'];
        $back = '/book-club/' . $slug . '/polls/' . $pollId;
        $body = $request->getParsedBody();
        $stars = is_array($body) && isset($body['stars']) && is_array($body['stars']) ? $body['stars'] : [];

        $validIds = array_map(static fn(array $o): int => (int) $o['id'], $this->repo->pollOptions($pollId));
        $ratings = [];
        foreach ($stars as $optionId => $value) {
            $optionId = (int) $optionId;
            $value = is_numeric($value) ? (int) $value : 0;
            if ($value === 0) {
                continue; // not rated
            }
            if (!in_array($optionId, $validIds, true)) {
                $this->flash('error', __('Opzione non valida.'));
                return $this->redirect($response, $back);
            }
            if ($value < 1 || $value > 5) {
                $this->flash('error', __('Le stelle vanno da 1 a 5.'));
                return $this->redirect($response, $back);
            }
            $ratings[$optionId] = $value;
        }
        if ($ratings === []) {
            $this->flash('error', __('Assegna almeno una valutazione in stelle.'));
            return $this->redirect($response, $back);
        }

        $userId = (int) $this->userId();
        $this->db->begin_transaction();
        try {
            $this->repo->clearUserVotes($pollId, $userId);
            foreach ($ratings as $optionId => $value) {
                $this->repo->castVote($pollId, $optionId, $userId, (float) $value);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub] stars vote failed: ' . $e->getMessage());
            $this->flash('error', __('Voto non registrato, riprova.'));
            return $this->redirect($response, $back);
        }
        $this->flash('success', __('Voto registrato. Puoi modificarlo finché la votazione è aperta.'));
        return $this->redirect($response, $back);
    }

    /**
     * ranking: ranks[option_id] = 1..N must be a full permutation over ALL
     * options. Borda: the stored vote value is N - rank + 1.
     *
     * @param array<string, mixed> $poll
     */
    private function voteRanking(ServerRequestInterface $request, ResponseInterface $response, string $slug, array $poll): ResponseInterface
    {
        $pollId = (int) $poll['id'];
        $back = '/book-club/' . $slug . '/polls/' . $pollId;
        $body = $request->getParsedBody();
        $ranks = is_array($body) && isset($body['ranks']) && is_array($body['ranks']) ? $body['ranks'] : [];

        $options = $this->repo->pollOptions($pollId);
        $n = count($options);
        $byRank = [];
        foreach ($options as $option) {
            $optionId = (int) $option['id'];
            $rank = isset($ranks[$optionId]) && is_numeric($ranks[$optionId]) ? (int) $ranks[$optionId] : 0;
            if ($rank < 1 || $rank > $n || isset($byRank[$rank])) {
                $this->flash('error', sprintf(__('Ordina tutti i libri assegnando a ciascuno una posizione diversa da 1 a %d.'), $n));
                return $this->redirect($response, $back);
            }
            $byRank[$rank] = $optionId;
        }

        $userId = (int) $this->userId();
        $this->db->begin_transaction();
        try {
            $this->repo->clearUserVotes($pollId, $userId);
            foreach ($byRank as $rank => $optionId) {
                $this->repo->castVote($pollId, $optionId, $userId, (float) ($n - $rank + 1));
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub] ranking vote failed: ' . $e->getMessage());
            $this->flash('error', __('Voto non registrato, riprova.'));
            return $this->redirect($response, $back);
        }
        $this->flash('success', __('Voto registrato. Puoi modificarlo finché la votazione è aperta.'));
        return $this->redirect($response, $back);
    }

    /**
     * elimination: exactly one pick among the surviving options, stored
     * with the poll's current round. Re-voting replaces the current-round
     * ballot only — previous rounds stay untouched.
     *
     * @param array<string, mixed> $poll
     */
    private function voteElimination(ServerRequestInterface $request, ResponseInterface $response, string $slug, array $poll): ResponseInterface
    {
        $pollId = (int) $poll['id'];
        $back = '/book-club/' . $slug . '/polls/' . $pollId;
        $round = max(1, (int) ($poll['round'] ?? 1));
        $body = $request->getParsedBody();
        $picked = is_array($body) ? ($body['options'] ?? []) : [];
        if (!is_array($picked)) {
            $picked = [$picked];
        }
        $picked = array_values(array_unique(array_map('intval', $picked)));
        if (count($picked) !== 1) {
            $this->flash('error', __('Seleziona un solo libro per questo turno.'));
            return $this->redirect($response, $back);
        }
        $optionId = $picked[0];

        $eliminated = $this->eliminatedMap($pollId);
        $validIds = [];
        foreach ($this->repo->pollOptions($pollId) as $option) {
            if (!isset($eliminated[(int) $option['id']])) {
                $validIds[] = (int) $option['id'];
            }
        }
        if (!in_array($optionId, $validIds, true)) {
            $this->flash('error', __('Opzione non valida.'));
            return $this->redirect($response, $back);
        }

        $userId = (int) $this->userId();
        $this->db->begin_transaction();
        try {
            $this->clearUserVotesRound($pollId, $userId, $round);
            $this->castVoteRound($pollId, $optionId, $userId, 1.0, $round);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub] elimination vote failed: ' . $e->getMessage());
            $this->flash('error', __('Voto non registrato, riprova.'));
            return $this->redirect($response, $back);
        }
        $this->flash('success', __('Voto registrato. Puoi modificarlo finché la votazione è aperta.'));
        return $this->redirect($response, $back);
    }

    // ------------------------------------------------------------------
    // Tally & close
    // ------------------------------------------------------------------

    /**
     * Tally and close (or, for elimination polls, advance the round).
     *
     * @param array<string, mixed> $poll
     * @param bool $exhaustRounds elimination only: keep resolving rounds in
     *                            one pass until a winner emerges (expired
     *                            deadline / cron sweep).
     * @return string 'closed_winner' | 'closed_no_winner' | 'closed_quorum'
     *                | 'admin_tie' | 'next_round' | 'noop'
     */
    private function resolvePoll(array $poll, bool $exhaustRounds = false): string
    {
        if ((string) ($poll['mode'] ?? 'simple') === 'elimination') {
            return $this->resolveElimination($poll, $exhaustRounds);
        }
        $pollId = (int) $poll['id'];
        $round = max(1, (int) ($poll['round'] ?? 1));

        // Quorum gate: any close with too few distinct voters ends without
        // a winner and every option book returns to the entry state.
        if ($this->quorumMissed($poll, $round)) {
            if (!$this->repo->closePoll($pollId, null)) {
                return 'noop';
            }
            $this->transitionBooks($poll, null);
            if (function_exists('do_action')) {
                do_action('bookclub.poll.closed', $pollId, null);
            }
            return 'closed_quorum';
        }

        // pollOptions() orders by score DESC, proposed_at ASC, id ASC —
        // the Fase 1 deterministic order the oldest_proposal tiebreak uses.
        $options = $this->repo->pollOptions($pollId);
        [$winner, $adminTie] = $this->tallyWinner($poll, $options);

        if ($adminTie) {
            if (!$this->repo->closePoll($pollId, null)) {
                return 'noop';
            }
            // Books stay in the voting state until a manager picks the winner.
            if (function_exists('do_action')) {
                do_action('bookclub.poll.closed', $pollId, null);
            }
            return 'admin_tie';
        }

        $winnerBookId = $winner !== null ? (int) $winner['club_book_id'] : null;
        if (!$this->repo->closePoll($pollId, $winnerBookId)) {
            return 'noop'; // already closed by a concurrent request/cron pass
        }
        $this->transitionBooks($poll, $winner !== null ? (int) $winner['id'] : null);
        if (function_exists('do_action')) {
            do_action('bookclub.poll.closed', $pollId, $winnerBookId);
        }
        return $winner !== null ? 'closed_winner' : 'closed_no_winner';
    }

    /**
     * Elimination close: one round step per manual close; with
     * $exhaustRounds the loop keeps eliminating until two options remain
     * and a winner resolves. Rounds without ballots (auto-close catching
     * up) inherit the standing of the last voted round, so the outcome
     * stays deterministic.
     *
     * @param array<string, mixed> $poll
     */
    private function resolveElimination(array $poll, bool $exhaustRounds): string
    {
        $pollId = (int) $poll['id'];
        $round = max(1, (int) ($poll['round'] ?? 1));
        $prevScores = null;
        $prevRound = $round;

        while (true) {
            $eliminatedMap = $this->eliminatedMap($pollId);
            $active = [];
            foreach ($this->repo->pollOptions($pollId) as $option) {
                if (!isset($eliminatedMap[(int) $option['id']])) {
                    $active[] = $option;
                }
            }

            $scores = $this->roundScores($pollId, $round);
            $scoreRound = $round;
            $roundVotes = 0;
            foreach ($active as $option) {
                $roundVotes += $scores[(int) $option['id']]['votes'] ?? 0;
            }
            if ($roundVotes === 0 && $prevScores !== null) {
                $scores = $prevScores;
                $scoreRound = $prevRound;
            }
            foreach ($active as $i => $option) {
                $active[$i]['score'] = $scores[(int) $option['id']]['score'] ?? 0.0;
            }
            usort($active, static fn(array $a, array $b): int =>
                [-(float) $a['score'], (string) $a['proposed_at'], (int) $a['id']]
                <=> [-(float) $b['score'], (string) $b['proposed_at'], (int) $b['id']]);

            if (count($active) > 2) {
                // Eliminate the last place (ties at the bottom drop the
                // most recent proposal, mirroring oldest_proposal).
                $loser = $active[count($active) - 1];
                $this->setEliminated((int) $loser['id'], $round);
                $this->bumpRound($pollId);
                if (!$exhaustRounds) {
                    return 'next_round';
                }
                unset($scores[(int) $loser['id']]);
                $prevScores = $scores;
                $prevRound = $scoreRound;
                $round++;
                continue;
            }

            // Final round (two or fewer survivors).
            if ($this->quorumMissed($poll, $scoreRound)) {
                if (!$this->repo->closePoll($pollId, null)) {
                    return 'noop';
                }
                $this->transitionBooks($poll, null);
                if (function_exists('do_action')) {
                    do_action('bookclub.poll.closed', $pollId, null);
                }
                return 'closed_quorum';
            }

            [$winner, $adminTie] = $this->tallyWinner($poll, $active);
            if ($adminTie) {
                if (!$this->repo->closePoll($pollId, null)) {
                    return 'noop';
                }
                if (function_exists('do_action')) {
                    do_action('bookclub.poll.closed', $pollId, null);
                }
                return 'admin_tie';
            }
            if ($winner !== null) {
                foreach ($active as $option) {
                    if ((int) $option['id'] !== (int) $winner['id']) {
                        $this->setEliminated((int) $option['id'], $round);
                    }
                }
            }
            $winnerBookId = $winner !== null ? (int) $winner['club_book_id'] : null;
            if (!$this->repo->closePoll($pollId, $winnerBookId)) {
                return 'noop';
            }
            $this->transitionBooks($poll, $winner !== null ? (int) $winner['id'] : null);
            if (function_exists('do_action')) {
                do_action('bookclub.poll.closed', $pollId, $winnerBookId);
            }
            return $winner !== null ? 'closed_winner' : 'closed_no_winner';
        }
    }

    /**
     * Highest score wins; a tied top resolves per the poll's tiebreak.
     * $options must be ordered score DESC, proposed_at ASC, id ASC.
     *
     * @param array<string, mixed> $poll
     * @param list<array<string, mixed>> $options
     * @return array{0: array<string, mixed>|null, 1: bool} [winner, adminTie]
     */
    private function tallyWinner(array $poll, array $options): array
    {
        $top = $options[0] ?? null;
        if ($top === null || (float) $top['score'] <= 0) {
            return [null, false];
        }
        $topScore = (float) $top['score'];
        $tied = [];
        foreach ($options as $option) {
            if (abs((float) $option['score'] - $topScore) < self::EPS) {
                $tied[] = $option;
            }
        }
        if (count($tied) === 1) {
            return [$tied[0], false];
        }
        switch ((string) ($poll['tiebreak'] ?? 'oldest_proposal')) {
            case 'random':
                usort($tied, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
                $ids = array_map(static fn(array $o): int => (int) $o['id'], $tied);
                $idx = crc32((string) (int) $poll['id'] . '-' . implode(',', $ids)) % count($tied);
                return [$tied[$idx], false];
            case 'admin':
                return [null, true];
            default: // oldest_proposal — Fase 1 behaviour, first in order
                return [$tied[0], false];
        }
    }

    /**
     * Post-close workflow transitions: the winner option's book advances
     * to the next state, every other option book returns to the entry
     * state. With a NULL winner (no votes / quorum missed) all books
     * return to the entry state.
     *
     * @param array<string, mixed> $poll
     */
    private function transitionBooks(array $poll, ?int $winnerOptionId): void
    {
        $club = $this->repo->clubById((int) $poll['club_id']);
        if ($club === null) {
            return;
        }
        $states = $this->repo->workflowStates($club);
        $entryState = Repo::entryStateKey($states);
        foreach ($this->repo->pollOptions((int) $poll['id']) as $option) {
            $book = $this->repo->clubBook((int) $option['club_book_id']);
            if ($book === null) {
                continue;
            }
            if ($winnerOptionId !== null && (int) $option['id'] === $winnerOptionId) {
                $next = Repo::nextStateKey($states, (string) $book['state']) ?? (string) $book['state'];
                if ($next !== (string) $book['state']) {
                    $this->repo->changeBookState((int) $book['id'], (string) $book['state'], $next, null);
                }
            } elseif ((string) $book['state'] !== $entryState) {
                $this->repo->changeBookState((int) $book['id'], (string) $book['state'], $entryState, null);
            }
        }
    }

    /**
     * Whether the poll misses its quorum: distinct voters of $round below
     * ceil(quorum_pct% of the club's active members). No quorum set → false.
     *
     * @param array<string, mixed> $poll
     */
    private function quorumMissed(array $poll, int $round): bool
    {
        $pct = isset($poll['quorum_pct']) && $poll['quorum_pct'] !== null ? (int) $poll['quorum_pct'] : 0;
        if ($pct <= 0) {
            return false;
        }
        $activeMembers = $this->repo->countActiveMembers((int) $poll['club_id']);
        $needed = (int) ceil($pct / 100 * $activeMembers);
        return $this->distinctVoters((int) $poll['id'], $round) < $needed;
    }

    // ------------------------------------------------------------------
    // View data helpers
    // ------------------------------------------------------------------

    /**
     * Options for the poll page. For elimination polls the score/vote_count
     * are re-scoped to the current round, eliminated_in_round is attached
     * and eliminated options sort last.
     *
     * @param array<string, mixed> $poll
     * @return array{0: list<array<string, mixed>>, 1: array<int, int>}
     */
    private function optionsForDisplay(array $poll): array
    {
        $pollId = (int) $poll['id'];
        $options = $this->repo->pollOptions($pollId);
        if ((string) ($poll['mode'] ?? 'simple') !== 'elimination') {
            return [$options, []];
        }
        $round = $this->displayRound($poll);
        $eliminated = $this->eliminatedMap($pollId);
        $scores = $this->roundScores($pollId, $round);
        foreach ($options as $i => $option) {
            $optId = (int) $option['id'];
            $options[$i]['score'] = $scores[$optId]['score'] ?? 0.0;
            $options[$i]['vote_count'] = $scores[$optId]['votes'] ?? 0;
            $options[$i]['eliminated_in_round'] = $eliminated[$optId] ?? null;
        }
        usort($options, static fn(array $a, array $b): int =>
            [$a['eliminated_in_round'] !== null ? 1 : 0, -(float) $a['score'], (string) $a['proposed_at'], (int) $a['id']]
            <=> [$b['eliminated_in_round'] !== null ? 1 : 0, -(float) $b['score'], (string) $b['proposed_at'], (int) $b['id']]);
        return [$options, $eliminated];
    }

    /**
     * Tied top option ids (surviving options only) — the choices a manager
     * may proclaim after an `admin` tie close. Empty when there is a clear
     * top or no votes at all.
     *
     * @param list<array<string, mixed>> $options
     * @param array<int, int> $eliminated
     * @return list<int>
     */
    private function adminTiedIds(array $options, array $eliminated): array
    {
        $best = 0.0;
        $tied = [];
        foreach ($options as $option) {
            if (isset($eliminated[(int) $option['id']])) {
                continue;
            }
            $score = (float) $option['score'];
            if ($score > $best + self::EPS) {
                $best = $score;
                $tied = [(int) $option['id']];
            } elseif ($best > 0 && abs($score - $best) < self::EPS) {
                $tied[] = (int) $option['id'];
            }
        }
        return count($tied) > 1 ? $tied : [];
    }

    /**
     * Round whose ballots are shown/checked. Normally the poll's current
     * round; for a CLOSED elimination poll whose last round received no
     * ballots (auto-close resolving several rounds in one pass), fall back
     * to the most recent round that was actually voted — the one that
     * decided the outcome.
     *
     * @param array<string, mixed> $poll
     */
    private function displayRound(array $poll): int
    {
        $round = max(1, (int) ($poll['round'] ?? 1));
        if ((string) ($poll['mode'] ?? 'simple') !== 'elimination' || ($poll['status'] ?? '') !== 'closed') {
            return $round;
        }
        if ($this->roundScores((int) $poll['id'], $round) !== []) {
            return $round;
        }
        $last = $this->maxVotedRound((int) $poll['id']);
        return $last !== null ? min($round, $last) : $round;
    }

    /** Highest round of the poll that has at least one ballot, or null. */
    private function maxVotedRound(int $pollId): ?int
    {
        $stmt = $this->db->prepare('SELECT MAX(round) AS r FROM bookclub_votes WHERE poll_id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $pollId);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return isset($row['r']) && $row['r'] !== null ? (int) $row['r'] : null;
    }

    /** Fixed weight of the current member's ballot in `weighted` polls. */
    private function voterWeight(array $club): float
    {
        $membership = $this->membership($club);
        return self::WEIGHTS[(string) ($membership['role_slug'] ?? '')] ?? 1.0;
    }

    /** Whether the voting2 module is enabled for $club (Registry lookup). */
    private function advancedVotingEnabled(array $club): bool
    {
        foreach (Modules\Registry::all($this->db) as $module) {
            if ($module->slug() === 'voting2') {
                return Modules\Registry::clubEnabled($club, $module);
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Round-aware vote queries (columns owned by the voting2 module; the
    // core simple/multi paths never touch them)
    // ------------------------------------------------------------------

    /** @return array<int, array{score: float, votes: int}> option_id → round tally */
    private function roundScores(int $pollId, int $round): array
    {
        $stmt = $this->db->prepare(
            'SELECT option_id, COALESCE(SUM(value), 0) AS score, COUNT(*) AS n
               FROM bookclub_votes WHERE poll_id = ? AND round = ? GROUP BY option_id'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] roundScores prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param('ii', $pollId, $round);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        if ($result !== false) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $out[(int) $row['option_id']] = ['score' => (float) $row['score'], 'votes' => (int) $row['n']];
            }
        }
        $stmt->close();
        return $out;
    }

    /** @return array<int, int> option_id → eliminated_in_round */
    private function eliminatedMap(int $pollId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, eliminated_in_round FROM bookclub_poll_options
              WHERE poll_id = ? AND eliminated_in_round IS NOT NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] eliminatedMap prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param('i', $pollId);
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        if ($result !== false) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $out[(int) $row['id']] = (int) $row['eliminated_in_round'];
            }
        }
        $stmt->close();
        return $out;
    }

    /**
     * The current user's votes as option_id → value, optionally restricted
     * to one round (elimination ballots).
     *
     * @return array<int, float>
     */
    private function myVoteValues(int $pollId, int $userId, ?int $round = null): array
    {
        if ($round === null) {
            $stmt = $this->db->prepare('SELECT option_id, value FROM bookclub_votes WHERE poll_id = ? AND user_id = ?');
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('ii', $pollId, $userId);
        } else {
            $stmt = $this->db->prepare('SELECT option_id, value FROM bookclub_votes WHERE poll_id = ? AND user_id = ? AND round = ?');
            if ($stmt === false) {
                return [];
            }
            $stmt->bind_param('iii', $pollId, $userId, $round);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $out = [];
        if ($result !== false) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $out[(int) $row['option_id']] = (float) $row['value'];
            }
        }
        $stmt->close();
        return $out;
    }

    private function distinctVoters(int $pollId, int $round): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT user_id) AS n FROM bookclub_votes WHERE poll_id = ? AND round = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] distinctVoters prepare failed: ' . $this->db->error);
            return 0;
        }
        $stmt->bind_param('ii', $pollId, $round);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }

    private function clearUserVotesRound(int $pollId, int $userId, int $round): void
    {
        $stmt = $this->db->prepare('DELETE FROM bookclub_votes WHERE poll_id = ? AND user_id = ? AND round = ?');
        if ($stmt === false) {
            throw new \RuntimeException('[BookClub:voting2] clearUserVotesRound prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('iii', $pollId, $userId, $round);
        $stmt->execute();
        $stmt->close();
    }

    private function castVoteRound(int $pollId, int $optionId, int $userId, float $value, int $round): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bookclub_votes (poll_id, option_id, user_id, value, round) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[BookClub:voting2] castVoteRound prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('iiidi', $pollId, $optionId, $userId, $value, $round);
        $stmt->execute();
        $stmt->close();
    }

    private function setEliminated(int $optionId, int $round): void
    {
        $stmt = $this->db->prepare('UPDATE bookclub_poll_options SET eliminated_in_round = ? WHERE id = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] setEliminated prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('ii', $round, $optionId);
        $stmt->execute();
        $stmt->close();
    }

    private function bumpRound(int $pollId): void
    {
        $stmt = $this->db->prepare("UPDATE bookclub_polls SET round = round + 1 WHERE id = ? AND status = 'open'");
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] bumpRound prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('i', $pollId);
        $stmt->execute();
        $stmt->close();
    }

    private function setPollExtras(int $pollId, ?int $quorumPct, string $tiebreak): void
    {
        $stmt = $this->db->prepare('UPDATE bookclub_polls SET quorum_pct = ?, tiebreak = ? WHERE id = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] setPollExtras prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('isi', $quorumPct, $tiebreak, $pollId);
        $stmt->execute();
        $stmt->close();
    }

    /** Late winner proclamation (admin tie): only fills a NULL winner once. */
    private function setPickedWinner(int $pollId, int $winnerClubBookId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE bookclub_polls SET winner_club_book_id = ?
              WHERE id = ? AND status = 'closed' AND winner_club_book_id IS NULL"
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:voting2] setPickedWinner prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('ii', $winnerClubBookId, $pollId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
}
