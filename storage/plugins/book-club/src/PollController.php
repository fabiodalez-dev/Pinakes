<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Voting: poll creation from proposals, ballot casting (simple = one vote,
 * multi = N votes per member as in Discussion #138), deadline-driven auto
 * close and workflow transition of winner/losers.
 */
class PollController extends BaseController
{
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
        // Lazy close: correctness does not depend on the cron.
        if ($poll['status'] === 'open' && $poll['closes_at'] !== null && strtotime((string) $poll['closes_at']) <= time()) {
            $this->resolvePoll($poll);
            $poll = $this->repo->poll($pollId) ?? $poll;
        }

        $userId = $this->userId();
        return $this->renderPublic($response, 'public/poll', [
            'club' => $club,
            'poll' => $poll,
            'options' => $this->repo->pollOptions($pollId),
            'voters' => $poll['anonymity'] === 'public' ? $this->repo->pollVoters($pollId) : [],
            'myVotes' => $userId !== null ? $this->repo->userVotes($pollId, $userId) : [],
            'isMember' => $this->isActiveMember($club),
            'canManage' => $this->canManage($club),
        ], (string) $poll['title']);
    }

    /**
     * Create a poll from selected proposals (club managers). Option books
     * move to the workflow's voting state.
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
        $mode = self::str($body, 'mode', 10) === 'multi' ? 'multi' : 'simple';
        $votesPerMember = $mode === 'multi' ? max(1, min(20, (int) (self::intOrNull($body, 'votes_per_member') ?? 3))) : 1;
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
     * Cast (or replace) the member's ballot. The form posts options[] —
     * exactly 1 option in simple mode, up to votes_per_member in multi mode.
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

        $body = $request->getParsedBody();
        $picked = $body['options'] ?? [];
        if (!is_array($picked)) {
            $picked = [$picked];
        }
        $picked = array_values(array_unique(array_map('intval', $picked)));

        $max = $poll['mode'] === 'multi' ? (int) $poll['votes_per_member'] : 1;
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

        // Replace the previous ballot atomically.
        $userId = (int) $this->userId();
        $this->db->begin_transaction();
        try {
            $this->repo->clearUserVotes($pollId, $userId);
            foreach ($picked as $optionId) {
                $this->repo->castVote($pollId, $optionId, $userId);
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
        $this->resolvePoll($poll);
        $this->flash('success', __('Votazione chiusa.'));
        return $this->redirect($response, '/book-club/' . $slug . '/polls/' . $pollId);
    }

    /**
     * Cron sweep — close every open poll whose deadline has passed.
     */
    public function closeExpiredPolls(): void
    {
        foreach ($this->repo->expiredOpenPolls() as $poll) {
            try {
                $this->resolvePoll($poll);
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] auto-close failed for poll ' . $poll['id'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Tally and close: highest score wins; ties break deterministically in
     * favour of the oldest proposal (pollOptions() already orders by
     * score DESC, proposed_at ASC, id ASC). The winner advances to the state
     * after its current one in the club workflow; losers return to the entry
     * state.
     *
     * @param array<string, mixed> $poll
     */
    private function resolvePoll(array $poll): void
    {
        $pollId = (int) $poll['id'];
        $options = $this->repo->pollOptions($pollId);
        $winner = null;
        foreach ($options as $option) {
            if ((float) $option['score'] > 0) {
                $winner = $option;
                break;
            }
        }

        $winnerBookId = $winner !== null ? (int) $winner['club_book_id'] : null;
        if (!$this->repo->closePoll($pollId, $winnerBookId)) {
            return; // already closed by a concurrent request/cron pass
        }

        $club = $this->repo->clubById((int) $poll['club_id']);
        if ($club !== null) {
            $states = $this->repo->workflowStates($club);
            $entryState = Repo::entryStateKey($states);
            foreach ($options as $option) {
                $book = $this->repo->clubBook((int) $option['club_book_id']);
                if ($book === null) {
                    continue;
                }
                if ($winner !== null && (int) $option['id'] === (int) $winner['id']) {
                    $next = Repo::nextStateKey($states, (string) $book['state']) ?? (string) $book['state'];
                    if ($next !== (string) $book['state']) {
                        $this->repo->changeBookState((int) $book['id'], (string) $book['state'], $next, null);
                    }
                } elseif ((string) $book['state'] !== $entryState) {
                    $this->repo->changeBookState((int) $book['id'], (string) $book['state'], $entryState, null);
                }
            }
        }

        if (function_exists('do_action')) {
            do_action('bookclub.poll.closed', $pollId, $winnerBookId);
        }
    }
}
