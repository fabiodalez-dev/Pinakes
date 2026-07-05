<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access + score computation for the Affinity module (plan §7.16):
 * reader-to-reader affinity (cosine similarity, opt-in only) and the
 * catalog suggestions ranking (books/authors the club has not read yet).
 *
 * SCORE FORMULA (also documented on AffinityModule)
 * --------------------------------------------------
 * For a pair of opted-in active members (a, b) of the same club, three
 * per-user preference vectors are built:
 *
 *   votes  (weight 0.40): dimension = bookclub_poll_options.id of the
 *          club's polls, value = bookclub_votes.value cast by the user;
 *   stars  (weight 0.40): dimension = libri.id restricted to books that
 *          appear in bookclub_books for the club, value = recensioni.stelle
 *          of the user's APPROVED core reviews;
 *   genres (weight 0.20): dimension = libri.genere_id, value = number of
 *          club books the user finished (bookclub_progress.finished_at
 *          NOT NULL, joined through bookclub_books to libri.genere_id).
 *
 * For every component where BOTH users have at least one entry and share
 * at least one dimension, cos_c(a,b) = dot(va, vb) / (|va| * |vb|) is
 * computed over each user's full component vector (missing dimensions are
 * zeros, so the dot product only spans the shared dimensions). All values
 * are non-negative, hence cos_c ∈ [0, 1].
 *
 * score(a,b) = round(100 * Σ_c w_c * cos_c / Σ_c w_c), summed over the
 * AVAILABLE components only (renormalisation: a pair with no shared votes
 * is scored on stars + genres alone, and so on). When the total number of
 * shared dimensions across all components is < 2 the pair has no reliable
 * signal and the stored score is NULL ("dati insufficienti").
 *
 * INVARIANT: rows in bookclub_affinity always satisfy user_a < user_b
 * (pairs are unordered; recomputeClub() sorts the ids before insert).
 *
 * mysqli prepared statements only, same style as App\Plugins\BookClub\Repo.
 */
class AffinityRepo
{
    /** Component weights of the combined cosine similarity. */
    public const WEIGHTS = ['votes' => 0.40, 'stars' => 0.40, 'genres' => 0.20];

    /** Pairs sharing fewer data points than this get a NULL score. */
    public const MIN_COMMON_POINTS = 2;

    /** Recompute at most once per day per club (maintenance tick + lazy page view). */
    public const STALE_SECONDS = 86400;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same pattern as Repo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:affinity] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:affinity] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $out = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $out;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function row(string $sql, string $types = '', array $params = []): ?array
    {
        $rows = $this->rows($sql, $types, $params);
        return $rows[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function exec(string $sql, string $types = '', array $params = []): bool
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:affinity] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:affinity] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    private function tableExists(string $table): bool
    {
        $row = $this->row(
            'SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's',
            [$table]
        );
        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: string} [placeholders, type string]
     */
    private static function intInClause(array $ids): array
    {
        return [implode(',', array_fill(0, count($ids), '?')), str_repeat('i', count($ids))];
    }

    /**
     * @param list<string> $keys
     * @return array{0: string, 1: string} [placeholders, type string]
     */
    private static function strInClause(array $keys): array
    {
        return [implode(',', array_fill(0, count($keys), '?')), str_repeat('s', count($keys))];
    }

    /**
     * Keys of "finished" states: everything flagged `archived`, plus the
     * literal 'finished' key (same rule as the Stats module).
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     * @return list<string>
     */
    public static function finishedStateKeys(array $states): array
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
    // Opt-in (privacy)
    // ------------------------------------------------------------------

    public function isOptedIn(int $clubId, int $userId): bool
    {
        return $this->row(
            'SELECT id FROM bookclub_affinity_optin WHERE club_id = ? AND user_id = ?',
            'ii',
            [$clubId, $userId]
        ) !== null;
    }

    public function optIn(int $clubId, int $userId): bool
    {
        return $this->exec(
            'INSERT IGNORE INTO bookclub_affinity_optin (club_id, user_id) VALUES (?, ?)',
            'ii',
            [$clubId, $userId]
        );
    }

    /**
     * Opt out AND immediately purge every stored pair involving the user,
     * so the member disappears from everyone's list without waiting for
     * the next recompute.
     */
    public function optOut(int $clubId, int $userId): bool
    {
        $ok = $this->exec(
            'DELETE FROM bookclub_affinity_optin WHERE club_id = ? AND user_id = ?',
            'ii',
            [$clubId, $userId]
        );
        $this->exec(
            'DELETE FROM bookclub_affinity WHERE club_id = ? AND (user_a = ? OR user_b = ?)',
            'iii',
            [$clubId, $userId, $userId]
        );
        return $ok;
    }

    /**
     * Opted-in users who are still ACTIVE members of the club (suspended,
     * banned or departed members are excluded even if their opt-in row
     * survives), sorted ascending — recomputeClub() relies on the order
     * for the user_a < user_b invariant.
     *
     * @return list<int>
     */
    public function optedInActiveMemberIds(int $clubId): array
    {
        $rows = $this->rows(
            "SELECT o.user_id
               FROM bookclub_affinity_optin o
               JOIN bookclub_members m
                 ON m.club_id = o.club_id AND m.user_id = o.user_id AND m.status = 'active'
              WHERE o.club_id = ?
              ORDER BY o.user_id ASC",
            'i',
            [$clubId]
        );
        return array_map(static fn(array $r): int => (int) $r['user_id'], $rows);
    }

    public function optedInCount(int $clubId): int
    {
        return count($this->optedInActiveMemberIds($clubId));
    }

    // ------------------------------------------------------------------
    // Affinity scores
    // ------------------------------------------------------------------

    /**
     * Daily-recompute guard: true when the club has no stored pair yet or
     * the newest one is older than $seconds. Clubs with < 2 opted-in
     * members store no rows, so they always read as stale — the recompute
     * is a no-op there (one DELETE + one SELECT), which is acceptable.
     */
    public function isStale(int $clubId, int $seconds = self::STALE_SECONDS): bool
    {
        $row = $this->row(
            'SELECT MAX(computed_at) AS last_run FROM bookclub_affinity WHERE club_id = ?',
            'i',
            [$clubId]
        );
        $last = $row['last_run'] ?? null;
        if ($last === null) {
            return true;
        }
        $ts = strtotime((string) $last);
        return $ts === false || (time() - $ts) >= $seconds;
    }

    /**
     * Full rebuild of the club's affinity matrix, restricted to opted-in
     * active members. Every stored pair is dropped first so members who
     * opted out or left never linger. Pairs are stored with user_a < user_b.
     */
    public function recomputeClub(int $clubId): void
    {
        $this->exec('DELETE FROM bookclub_affinity WHERE club_id = ?', 'i', [$clubId]);

        $userIds = $this->optedInActiveMemberIds($clubId);
        if (count($userIds) < 2) {
            return;
        }

        $vectors = [
            'votes' => $this->voteVectors($clubId, $userIds),
            'stars' => $this->starVectors($clubId, $userIds),
            'genres' => $this->genreVectors($clubId, $userIds),
        ];

        $n = count($userIds);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                // $userIds is sorted ascending → user_a < user_b invariant.
                $userA = $userIds[$i];
                $userB = $userIds[$j];
                $score = $this->pairScore($vectors, $userA, $userB);
                $this->exec(
                    'INSERT INTO bookclub_affinity (club_id, user_a, user_b, score, computed_at)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE score = VALUES(score), computed_at = NOW()',
                    'iiii',
                    [$clubId, $userA, $userB, $score]
                );
            }
        }
    }

    /**
     * Ranked affinity list for one opted-in member: every stored pair the
     * user belongs to, with the OTHER member's display name. Since pairs
     * are only stored between opted-in active members, non-opted members
     * can never appear here.
     *
     * @return list<array{name: string, score: int|null, computed_at: string}>
     */
    public function affinitiesFor(int $clubId, int $userId): array
    {
        $rows = $this->rows(
            'SELECT af.score, af.computed_at, u.nome, u.cognome
               FROM bookclub_affinity af
               JOIN utenti u ON u.id = CASE WHEN af.user_a = ? THEN af.user_b ELSE af.user_a END
              WHERE af.club_id = ? AND ? IN (af.user_a, af.user_b)
              ORDER BY (af.score IS NULL) ASC, af.score DESC, u.cognome ASC, u.nome ASC',
            'iii',
            [$userId, $clubId, $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => trim((string) $row['nome'] . ' ' . (string) $row['cognome']),
                'score' => $row['score'] !== null ? (int) $row['score'] : null,
                'computed_at' => (string) $row['computed_at'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Preference vectors (cosine components)
    // ------------------------------------------------------------------

    /**
     * Votes component: dimension = poll option id, value = vote value.
     *
     * @param list<int> $userIds
     * @return array<int, array<int, float>> user_id → dim → value
     */
    private function voteVectors(int $clubId, array $userIds): array
    {
        [$ph, $types] = self::intInClause($userIds);
        $rows = $this->rows(
            "SELECT v.user_id, v.option_id AS dim, v.value
               FROM bookclub_votes v
               JOIN bookclub_polls p ON p.id = v.poll_id
              WHERE p.club_id = ? AND v.user_id IN ($ph)",
            'i' . $types,
            array_merge([$clubId], $userIds)
        );
        return self::toVectors($rows);
    }

    /**
     * Stars component: dimension = libro id (club books only), value =
     * stelle of the user's approved core review.
     *
     * @param list<int> $userIds
     * @return array<int, array<int, float>> user_id → dim → value
     */
    private function starVectors(int $clubId, array $userIds): array
    {
        [$ph, $types] = self::intInClause($userIds);
        $rows = $this->rows(
            "SELECT r.utente_id AS user_id, r.libro_id AS dim, r.stelle AS value
               FROM recensioni r
              WHERE r.stato = 'approvata'
                AND r.utente_id IN ($ph)
                AND EXISTS (SELECT 1 FROM bookclub_books cb
                             WHERE cb.libro_id = r.libro_id AND cb.club_id = ?)",
            $types . 'i',
            array_merge($userIds, [$clubId])
        );
        return self::toVectors($rows);
    }

    /**
     * Genres component: dimension = genere_id, value = finished club books
     * of that genre. Empty when the Reading module tables are not installed.
     *
     * @param list<int> $userIds
     * @return array<int, array<int, float>> user_id → dim → value
     */
    private function genreVectors(int $clubId, array $userIds): array
    {
        if (!$this->tableExists('bookclub_progress')) {
            return [];
        }
        [$ph, $types] = self::intInClause($userIds);
        $rows = $this->rows(
            "SELECT pr.user_id, l.genere_id AS dim, COUNT(*) AS value
               FROM bookclub_progress pr
               JOIN bookclub_books cb ON cb.id = pr.club_book_id
               JOIN libri l ON l.id = cb.libro_id
              WHERE cb.club_id = ? AND pr.finished_at IS NOT NULL AND l.genere_id IS NOT NULL
                AND pr.user_id IN ($ph)
              GROUP BY pr.user_id, l.genere_id",
            'i' . $types,
            array_merge([$clubId], $userIds)
        );
        return self::toVectors($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows rows with user_id, dim, value
     * @return array<int, array<int, float>>
     */
    private static function toVectors(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']][(int) $row['dim']] = (float) $row['value'];
        }
        return $out;
    }

    /**
     * Weighted cosine similarity of the pair — see the class docblock for
     * the exact formula. NULL when fewer than MIN_COMMON_POINTS dimensions
     * are shared across all components.
     *
     * @param array<string, array<int, array<int, float>>> $vectors component → user_id → dim → value
     */
    private function pairScore(array $vectors, int $userA, int $userB): ?int
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;
        $commonPoints = 0;

        foreach (self::WEIGHTS as $component => $weight) {
            $va = $vectors[$component][$userA] ?? [];
            $vb = $vectors[$component][$userB] ?? [];
            if ($va === [] || $vb === []) {
                continue; // component has no data for the pair → renormalize
            }
            $shared = array_intersect_key($va, $vb);
            if ($shared === []) {
                continue;
            }
            $normA = self::norm($va);
            $normB = self::norm($vb);
            if ($normA <= 0.0 || $normB <= 0.0) {
                continue;
            }
            $dot = 0.0;
            foreach ($shared as $dim => $unused) {
                $dot += $va[$dim] * $vb[$dim];
            }
            $commonPoints += count($shared);
            $weightedSum += $weight * ($dot / ($normA * $normB));
            $weightTotal += $weight;
        }

        if ($commonPoints < self::MIN_COMMON_POINTS || $weightTotal <= 0.0) {
            return null;
        }
        return max(0, min(100, (int) round(100.0 * $weightedSum / $weightTotal)));
    }

    /** @param array<int, float> $vector */
    private static function norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        return sqrt($sum);
    }

    // ------------------------------------------------------------------
    // Suggestions (books / authors the club never read)
    // ------------------------------------------------------------------

    /**
     * Top genres of the club's finished books (current state in a
     * finished-flag state), most read first.
     *
     * @param list<string> $finishedKeys
     * @return list<array{id: int, nome: string, n: int}>
     */
    public function topFinishedGenres(int $clubId, array $finishedKeys, int $limit = 3): array
    {
        if ($finishedKeys === []) {
            return [];
        }
        [$ph, $types] = self::strInClause($finishedKeys);
        $rows = $this->rows(
            "SELECT l.genere_id AS id, g.nome, COUNT(DISTINCT cb.id) AS n
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               JOIN generi g ON g.id = l.genere_id
              WHERE cb.club_id = ? AND cb.state IN ($ph)
              GROUP BY l.genere_id, g.nome
              ORDER BY n DESC, g.nome ASC
              LIMIT ?",
            'i' . $types . 'i',
            array_merge([$clubId], $finishedKeys, [max(1, min(10, $limit))])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'nome' => (string) $row['nome'], 'n' => (int) $row['n']];
        }
        return $out;
    }

    /**
     * Catalog books the club NEVER had (not in bookclub_books, any state)
     * matching the given genres, best-rated first (NULL ratings last).
     *
     * @param list<int> $genreIds
     * @return list<array<string, mixed>>
     */
    public function suggestedBooks(int $clubId, array $genreIds, int $limit = 10): array
    {
        if ($genreIds === []) {
            return [];
        }
        [$ph, $types] = self::intInClause($genreIds);
        return $this->rows(
            "SELECT l.id, l.titolo, l.copertina_url, l.anno_pubblicazione, l.rating,
                    g.nome AS genere,
                    (SELECT GROUP_CONCAT(a.nome ORDER BY la.ordine_credito SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id) AS autori
               FROM libri l
               JOIN generi g ON g.id = l.genere_id
              WHERE l.deleted_at IS NULL
                AND l.genere_id IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM bookclub_books cb
                                 WHERE cb.club_id = ? AND cb.libro_id = l.id)
              ORDER BY (l.rating IS NULL) ASC, l.rating DESC, l.titolo ASC
              LIMIT ?",
            $types . 'ii',
            array_merge($genreIds, [$clubId, max(1, min(25, $limit))])
        );
    }

    /**
     * "Similar authors": authors of the club's finished books who have
     * OTHER catalog books the club never read, ranked by how many unread
     * titles they offer.
     *
     * @param list<string> $finishedKeys
     * @return list<array{id: int, nome: string, unread_count: int}>
     */
    public function similarAuthors(int $clubId, array $finishedKeys, int $limit = 5): array
    {
        if ($finishedKeys === []) {
            return [];
        }
        [$ph, $types] = self::strInClause($finishedKeys);
        $rows = $this->rows(
            "SELECT a.id, a.nome, COUNT(DISTINCT l2.id) AS unread_count
               FROM bookclub_books cb
               JOIN libri_autori la ON la.libro_id = cb.libro_id
               JOIN autori a ON a.id = la.autore_id
               JOIN libri_autori la2 ON la2.autore_id = a.id
               JOIN libri l2 ON l2.id = la2.libro_id AND l2.deleted_at IS NULL
              WHERE cb.club_id = ? AND cb.state IN ($ph)
                AND NOT EXISTS (SELECT 1 FROM bookclub_books cb2
                                 WHERE cb2.club_id = ? AND cb2.libro_id = l2.id)
              GROUP BY a.id, a.nome
              ORDER BY unread_count DESC, a.nome ASC
              LIMIT ?",
            'i' . $types . 'ii',
            array_merge([$clubId], $finishedKeys, [$clubId, max(1, min(25, $limit))])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'unread_count' => (int) $row['unread_count'],
            ];
        }
        return $out;
    }
}
