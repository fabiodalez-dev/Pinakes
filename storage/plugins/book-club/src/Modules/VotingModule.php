<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\PollController;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// PollController (and Repo/BaseController) are already required eagerly by
// BookClubPlugin.php — this module extends the existing poll engine and
// owns no additional controller/repo files.

/**
 * Advanced voting module — plan §7.4 complete (slug `voting2`).
 *
 * Extends the core poll engine (PollController + bookclub_polls /
 * bookclub_poll_options / bookclub_votes) with four extra ballot modes,
 * quorum and configurable tie-breaking. `simple` and `multi` stay exactly
 * as shipped in Fase 1 and keep working even when this module is disabled
 * for a club.
 *
 * Modes
 * -----
 * - `stars`       Each member rates any subset of the options with 1–5
 *                 stars (one <select> per option); the vote row value is
 *                 the star count and the option score is SUM(value).
 *                 Re-voting replaces all of the member's rows.
 * - `ranking`     Each member orders ALL options (a full permutation
 *                 1..N is validated server-side); Borda count: the vote
 *                 value stored for rank r is N - r + 1.
 * - `elimination` Ballots work like `simple` but are scoped to the poll's
 *                 current round (bookclub_votes.round = bookclub_polls.round).
 *                 Closing acts per round: the last-place option receives
 *                 eliminated_in_round = round; while more than two options
 *                 remain the round counter advances and the poll STAYS open;
 *                 with two options left the loser is eliminated and the
 *                 winner resolves as usual. The ballot UI hides eliminated
 *                 options. An expired closes_at resolves rounds repeatedly
 *                 in one pass until a winner emerges.
 * - `weighted`    Like simple/multi, but the stored vote value for club
 *                 owners and moderators is configurable per poll
 *                 (bookclub_polls.weight_owner / weight_moderator,
 *                 DECIMAL(4,2), clamped 1.0–5.0 at creation; NULL falls
 *                 back to the legacy fixed 2.0 / 1.5, documented on the
 *                 poll page); everyone else counts 1.0.
 *
 * Quorum & tie-break (any mode)
 * -----------------------------
 * - `quorum_pct`  On ANY close, when set and the distinct voters of the
 *                 final round are fewer than ceil(quorum_pct% of the active
 *                 members), the poll closes WITHOUT a winner and every
 *                 option book returns to the workflow entry state.
 * - `tiebreak`    On an equal top score: `oldest_proposal` keeps the Fase 1
 *                 behaviour, `random` picks deterministically via
 *                 crc32(poll_id . '-' . sorted tied option ids) % count,
 *                 `admin` closes with a NULL winner and lets a club manager
 *                 proclaim the winner among the tied options
 *                 (POST …/polls/{pollId}/pick-winner/{optionId}).
 *
 * Routes owned by this module (per-club enablement re-checked in the
 * handlers): GET /book-club/{slug}/polls (poll list + advanced creation
 * form for managers) and the pick-winner POST above.
 *
 * Schema: no new tables — only guarded, idempotent ALTERs on the three
 * core poll tables (mode ENUM widening, quorum_pct/tiebreak/round on
 * polls, round on votes + widened unique key, eliminated_in_round on
 * options).
 */
class VotingModule extends AbstractModule
{
    public function slug(): string
    {
        return 'voting2';
    }

    public function label(): string
    {
        return __('Votazioni avanzate');
    }

    public function description(): string
    {
        return __('Stelle, classifica, eliminazione progressiva, voto ponderato, quorum e spareggi');
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Schema (guarded ALTERs only — runs on every install AND activation)
    // ------------------------------------------------------------------

    public function ensureSchema(): array
    {
        $failed = [];

        if ($this->tableExists('bookclub_polls')) {
            // Widen the mode ENUM once: guard on the current COLUMN_TYPE.
            $type = $this->columnType('bookclub_polls', 'mode');
            if ($type !== null && strpos($type, 'stars') === false) {
                $ok = $this->db->query(
                    "ALTER TABLE bookclub_polls MODIFY mode
                     ENUM('simple','multi','stars','ranking','elimination','weighted')
                     NOT NULL DEFAULT 'simple'"
                );
                if ($ok === false) {
                    $failed[] = 'bookclub_polls.mode';
                    SecureLogger::warning('[BookClub:voting2] MODIFY bookclub_polls.mode failed: ' . $this->db->error);
                }
            }
            $this->addColumnIfMissing('bookclub_polls', 'quorum_pct', 'TINYINT NULL');
            $this->addColumnIfMissing('bookclub_polls', 'tiebreak', "ENUM('oldest_proposal','random','admin') NOT NULL DEFAULT 'oldest_proposal'");
            $this->addColumnIfMissing('bookclub_polls', 'round', 'INT NOT NULL DEFAULT 1');
            // Why the poll closed ('winner','no_winner','quorum','admin_tie'):
            // persisted at close time so the displayed outcome cannot flip
            // when the active-member count changes afterwards.
            $this->addColumnIfMissing('bookclub_polls', 'closed_reason', 'VARCHAR(20) NULL');
            // Per-poll ballot weights for `weighted` polls (NULL → legacy
            // fixed 2.0 owner / 1.5 moderator fallback in PollController).
            $this->addColumnIfMissing('bookclub_polls', 'weight_owner', 'DECIMAL(4,2) NULL');
            $this->addColumnIfMissing('bookclub_polls', 'weight_moderator', 'DECIMAL(4,2) NULL');
        }

        if ($this->tableExists('bookclub_votes')) {
            $this->addColumnIfMissing('bookclub_votes', 'round', 'INT NOT NULL DEFAULT 1');
            $this->ensureVotesUniqueKey();
        }

        if ($this->tableExists('bookclub_poll_options')) {
            $this->addColumnIfMissing('bookclub_poll_options', 'eliminated_in_round', 'INT NULL');
        }

        return ['created' => [], 'failed' => $failed];
    }

    /** COLUMN_TYPE from INFORMATION_SCHEMA (e.g. "enum('simple','multi')"), or null. */
    private function columnType(string $table, string $column): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        return isset($row['COLUMN_TYPE']) ? (string) $row['COLUMN_TYPE'] : null;
    }

    /**
     * Recreate uq_bcvotes as UNIQUE (poll_id, option_id, user_id, round) —
     * guarded on the CURRENT column list of the index so it never touches
     * an already-migrated table.
     */
    private function ensureVotesUniqueKey(): void
    {
        $stmt = $this->db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookclub_votes' AND INDEX_NAME = 'uq_bcvotes'
              ORDER BY SEQ_IN_INDEX"
        );
        if ($stmt === false) {
            SecureLogger::warning('[BookClub:voting2] uq_bcvotes introspection prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        if ($result !== false) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $columns[] = (string) $row['COLUMN_NAME'];
            }
        }
        $stmt->close();

        $wanted = ['poll_id', 'option_id', 'user_id', 'round'];
        if ($columns === $wanted) {
            return; // already migrated
        }
        if ($columns !== []) {
            if ($this->db->query('ALTER TABLE bookclub_votes DROP INDEX uq_bcvotes') === false) {
                SecureLogger::warning('[BookClub:voting2] DROP INDEX uq_bcvotes failed: ' . $this->db->error);
                return;
            }
        }
        if ($this->db->query('ALTER TABLE bookclub_votes ADD UNIQUE KEY uq_bcvotes (poll_id, option_id, user_id, round)') === false) {
            SecureLogger::warning('[BookClub:voting2] ADD UNIQUE uq_bcvotes failed: ' . $this->db->error);
        }
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new PollController($this->db, $this->repo);
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        // Poll list + advanced creation form (managers). The handler
        // re-checks per-club enablement of this module and 404s otherwise.
        $app->get(
            '/book-club/{slug:[a-z0-9\-]+}/polls',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->pollsPage($rq, $rs, (string) $a['slug'])
        );

        // Manual winner proclamation for polls closed on an `admin` tie.
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}/pick-winner/{optionId:[0-9]+}',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->pickWinner($rq, $rs, (string) $a['slug'], (int) $a['pollId'], (int) $a['optionId'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page sidebar (link to the poll list, since show.php is frozen)
    // ------------------------------------------------------------------

    public function renderClubSidebar(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null) {
            return '';
        }
        $open = 0;
        foreach ($this->repo->clubPolls((int) $club['id']) as $poll) {
            if (($poll['status'] ?? '') === 'open') {
                $open++;
            }
        }
        return $this->renderPartial('partials/voting_sidebar', [
            'club' => $club,
            'openCount' => $open,
        ]);
    }
}
