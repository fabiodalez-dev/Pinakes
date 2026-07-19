<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SearchIndexBuilder;
use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the Book Club plugin. Hand-written mysqli prepared
 * statements, same style as app/Models/*Repository.
 */
class Repo
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub] execute failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
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
            SecureLogger::error('[BookClub] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function execAffected(string $sql, string $types = '', array $params = []): ?int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub] prepare failed: ' . $this->db->error . ' - ' . $sql);
            return null;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub] execute failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * @return list<string>
     */
    private function externalAuthorNames(?string $value): array
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*[|;]\s*/u', $raw) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name === '') {
                continue;
            }
            $name = mb_substr($name, 0, 255, 'UTF-8');
            $key = mb_strtolower($name, 'UTF-8');
            $names[$key] = $name;
        }
        return array_values($names);
    }

    private function findOrCreateAuthor(string $name): int
    {
        // External catalog feeds provide canonical names. Deliberately do not
        // match against pseudonyms: that could merge two different people.
        $authors = new \App\Models\AuthorRepository($this->db);
        return $authors->findByCanonicalName($name) ?? $authors->create(['nome' => $name]);
    }

    private function attachExternalAuthors(int $libroId, ?string $authors): void
    {
        $order = 1;
        foreach ($this->externalAuthorNames($authors) as $name) {
            $authorId = $this->findOrCreateAuthor($name);
            if (!$this->exec(
                "INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (?, ?, 'principale', ?)",
                'iii',
                [$libroId, $authorId, $order]
            )) {
                throw new \RuntimeException('author link failed');
            }
            $order++;
        }
    }

    private function findOrCreatePublisher(?string $name): ?int
    {
        $name = mb_substr(trim((string) $name), 0, 255, 'UTF-8');
        if ($name === '') {
            return null;
        }

        // Insert-if-absent in a SINGLE statement, then resolve the id. This
        // removes the application-level check-then-insert TOCTOU that let two
        // near-simultaneous imports of the same publisher create duplicate rows.
        // A UNIQUE index on editori.nome would make it fully atomic, but editori
        // is a CORE table that legitimately allows homonyms (the app dedups by
        // canonical name, not raw name) and may already hold duplicate names, so
        // a plugin must not add one; the trailing SELECT always returns the
        // lowest existing id regardless of who won an insert race.
        $ins = $this->db->prepare(
            'INSERT INTO editori (nome) SELECT ? FROM DUAL '
            . 'WHERE NOT EXISTS (SELECT 1 FROM editori WHERE nome = ?)'
        );
        if ($ins === false) {
            throw new \RuntimeException('publisher upsert prepare failed');
        }
        $ins->bind_param('ss', $name, $name);
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new \RuntimeException('publisher upsert failed: ' . $err);
        }
        $ins->close();

        $sel = $this->db->prepare('SELECT id FROM editori WHERE nome = ? ORDER BY id ASC LIMIT 1');
        if ($sel === false) {
            throw new \RuntimeException('publisher lookup prepare failed');
        }
        $sel->bind_param('s', $name);
        if (!$sel->execute()) {
            $err = $sel->error;
            $sel->close();
            throw new \RuntimeException('publisher lookup failed: ' . $err);
        }
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        return $row !== null ? (int) $row['id'] : null;
    }

    private function attachPrimaryPublisher(int $libroId, ?int $publisherId): void
    {
        if ($publisherId === null || $publisherId <= 0) {
            return;
        }
        if (!$this->exec(
            'INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE ordine = 0',
            'ii',
            [$libroId, $publisherId]
        )) {
            throw new \RuntimeException('publisher link failed');
        }
    }

    // ------------------------------------------------------------------
    // Clubs
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function clubById(int $id): ?array
    {
        $club = $this->row('SELECT * FROM bookclub_clubs WHERE id = ? AND deleted_at IS NULL', 'i', [$id]);
        return $club === null ? null : $this->hydrateClub($club);
    }

    /** @return array<string, mixed>|null */
    public function clubBySlug(string $slug): ?array
    {
        $club = $this->row('SELECT * FROM bookclub_clubs WHERE slug = ? AND deleted_at IS NULL', 's', [$slug]);
        return $club === null ? null : $this->hydrateClub($club);
    }

    /** @param array<string, mixed> $club
     *  @return array<string, mixed> */
    private function hydrateClub(array $club): array
    {
        $settings = json_decode((string) ($club['settings'] ?? ''), true);
        $club['settings'] = is_array($settings) ? $settings : [];
        return $club;
    }

    /**
     * Clubs visible in the public directory: hidden clubs never appear;
     * inactive clubs appear only in the admin area.
     *
     * @return list<array<string, mixed>>
     */
    public function listVisibleClubs(): array
    {
        return $this->rows(
            "SELECT c.*, (SELECT COUNT(*) FROM bookclub_members m
                           WHERE m.club_id = c.id AND m.status = 'active') AS member_count
               FROM bookclub_clubs c
              WHERE c.deleted_at IS NULL AND c.is_active = 1 AND c.privacy <> 'hidden'
              ORDER BY c.name ASC"
        );
    }

    /** @return list<array<string, mixed>> */
    public function listAllClubs(): array
    {
        return $this->rows(
            "SELECT c.*, (SELECT COUNT(*) FROM bookclub_members m
                           WHERE m.club_id = c.id AND m.status = 'active') AS member_count
               FROM bookclub_clubs c
              WHERE c.deleted_at IS NULL
              ORDER BY c.name ASC"
        );
    }

    /** @return list<array<string, mixed>> */
    public function listClubsForUser(int $userId): array
    {
        return $this->rows(
            "SELECT c.*, m.status AS member_status, r.slug AS role_slug, r.name AS role_name
               FROM bookclub_members m
               JOIN bookclub_clubs c ON c.id = m.club_id AND c.deleted_at IS NULL AND c.is_active = 1
               JOIN bookclub_roles r ON r.id = m.role_id
              WHERE m.user_id = ? AND m.status IN ('active','pending')
              ORDER BY c.name ASC",
            'i',
            [$userId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createClub(array $data, int $createdBy): ?int
    {
        $slug = $this->uniqueSlug((string) $data['name']);
        $settings = json_encode($data['settings'] ?? [], JSON_UNESCAPED_UNICODE);
        $icsToken = bin2hex(random_bytes(16));
        $ok = $this->exec(
            'INSERT INTO bookclub_clubs (slug, name, description, rules, color, privacy, max_members, settings, ics_token, created_by, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssssissii',
            [
                $slug,
                (string) $data['name'],
                (string) ($data['description'] ?? ''),
                (string) ($data['rules'] ?? ''),
                (string) ($data['color'] ?? '#4f46e5'),
                (string) ($data['privacy'] ?? 'public'),
                $data['max_members'] !== null ? (int) $data['max_members'] : null,
                $settings === false ? '{}' : $settings,
                $icsToken,
                $createdBy,
                (int) ($data['is_active'] ?? 1),
            ]
        );
        if (!$ok) {
            return null;
        }
        $clubId = (int) $this->db->insert_id;

        // Every club gets its own editable copy of the default workflow.
        $template = $this->row('SELECT states FROM bookclub_workflows WHERE club_id IS NULL ORDER BY id ASC LIMIT 1');
        $states = $template['states'] ?? json_encode(BookClubPlugin::defaultWorkflowStates(), JSON_UNESCAPED_UNICODE);
        $this->exec(
            'INSERT INTO bookclub_workflows (club_id, name, states) VALUES (?, ?, ?)',
            'iss',
            [$clubId, (string) $data['name'], (string) $states]
        );
        $workflowId = (int) $this->db->insert_id;
        $this->exec('UPDATE bookclub_clubs SET workflow_id = ? WHERE id = ?', 'ii', [$workflowId, $clubId]);

        return $clubId;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateClub(int $clubId, array $data): bool
    {
        $settings = json_encode($data['settings'] ?? [], JSON_UNESCAPED_UNICODE);
        return $this->exec(
            'UPDATE bookclub_clubs
                SET name = ?, description = ?, rules = ?, color = ?, privacy = ?, max_members = ?, settings = ?, is_active = ?
              WHERE id = ? AND deleted_at IS NULL',
            'sssssisii',
            [
                (string) $data['name'],
                (string) ($data['description'] ?? ''),
                (string) ($data['rules'] ?? ''),
                (string) ($data['color'] ?? '#4f46e5'),
                (string) ($data['privacy'] ?? 'public'),
                $data['max_members'] !== null ? (int) $data['max_members'] : null,
                $settings === false ? '{}' : $settings,
                (int) ($data['is_active'] ?? 1),
                $clubId,
            ]
        );
    }

    public function softDeleteClub(int $clubId): bool
    {
        return $this->exec('UPDATE bookclub_clubs SET deleted_at = NOW() WHERE id = ?', 'i', [$clubId]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = slugify_text($name);
        if ($base === '') {
            $base = 'club';
        }
        $slug = $base;
        $i = 2;
        while ($this->row('SELECT id FROM bookclub_clubs WHERE slug = ?', 's', [$slug]) !== null) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    // ------------------------------------------------------------------
    // Roles & members
    // ------------------------------------------------------------------

    public function roleIdBySlug(string $slug): ?int
    {
        $row = $this->row('SELECT id FROM bookclub_roles WHERE club_id IS NULL AND slug = ?', 's', [$slug]);
        return $row === null ? null : (int) $row['id'];
    }

    /** @return list<array<string, mixed>> */
    public function systemRoles(): array
    {
        return $this->rows('SELECT id, slug, name FROM bookclub_roles WHERE club_id IS NULL AND is_system = 1 ORDER BY id ASC');
    }

    /** @return array<string, mixed>|null */
    public function memberRow(int $clubId, int $userId): ?array
    {
        return $this->row(
            'SELECT m.*, r.slug AS role_slug, r.name AS role_name
               FROM bookclub_members m
               JOIN bookclub_roles r ON r.id = m.role_id
              WHERE m.club_id = ? AND m.user_id = ?',
            'ii',
            [$clubId, $userId]
        );
    }

    /** True if $userId is an ACTIVE member of the club (used to validate a
     *  manager-chosen "proposed_by"). */
    public function isActiveMember(int $clubId, int $userId): bool
    {
        return $this->row(
            "SELECT 1 FROM bookclub_members WHERE club_id = ? AND user_id = ? AND status = 'active'",
            'ii',
            [$clubId, $userId]
        ) !== null;
    }

    /** Display label for a user, used when a manager acts on their behalf. */
    public function userLabel(int $userId): ?string
    {
        $user = $this->row('SELECT nome, cognome, email FROM utenti WHERE id = ?', 'i', [$userId]);
        if ($user === null) {
            return null;
        }
        $name = trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
        return $name !== '' ? $name : (string) ($user['email'] ?? '');
    }

    /** @return list<array<string, mixed>> */
    public function listMembers(int $clubId, ?string $status = null): array
    {
        $sql = "SELECT m.*, r.slug AS role_slug, r.name AS role_name,
                       u.nome, u.cognome, u.email
                  FROM bookclub_members m
                  JOIN bookclub_roles r ON r.id = m.role_id
                  JOIN utenti u ON u.id = m.user_id
                 WHERE m.club_id = ?";
        if ($status !== null) {
            return $this->rows($sql . ' AND m.status = ? ORDER BY u.cognome, u.nome', 'is', [$clubId, $status]);
        }
        return $this->rows($sql . " ORDER BY FIELD(m.status,'pending','active','suspended','left','banned'), u.cognome, u.nome", 'i', [$clubId]);
    }

    public function countActiveMembers(int $clubId): int
    {
        $row = $this->row("SELECT COUNT(*) AS n FROM bookclub_members WHERE club_id = ? AND status = 'active'", 'i', [$clubId]);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Insert or revive a membership. A previously-left row is reused so the
     * UNIQUE(club_id, user_id) key never blocks a re-join.
     */
    public function upsertMember(int $clubId, int $userId, int $roleId, string $status, ?int $invitedBy = null): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_members (club_id, user_id, role_id, status, invited_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), status = VALUES(status), invited_by = VALUES(invited_by)',
            'iiisi',
            [$clubId, $userId, $roleId, $status, $invitedBy]
        );
    }

    public function setMemberStatus(int $memberId, string $status): bool
    {
        return $this->exec('UPDATE bookclub_members SET status = ? WHERE id = ?', 'si', [$status, $memberId]);
    }

    public function setMemberRole(int $memberId, int $roleId): bool
    {
        return $this->exec('UPDATE bookclub_members SET role_id = ? WHERE id = ?', 'ii', [$roleId, $memberId]);
    }

    /** @return array<string, mixed>|null */
    public function memberById(int $memberId): ?array
    {
        return $this->row(
            'SELECT m.*, r.slug AS role_slug FROM bookclub_members m JOIN bookclub_roles r ON r.id = m.role_id WHERE m.id = ?',
            'i',
            [$memberId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        return $this->row('SELECT id, nome, cognome, email, locale FROM utenti WHERE email = ?', 's', [$email]);
    }

    // ------------------------------------------------------------------
    // Invitations
    // ------------------------------------------------------------------

    public function createInvitation(int $clubId, string $email, ?int $roleId, int $invitedBy, int $ttlDays = 14): ?string
    {
        $token = bin2hex(random_bytes(32));
        $ok = $this->exec(
            'INSERT INTO bookclub_invitations (club_id, email, token, role_id, invited_by, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            'issiii',
            [$clubId, $email, $token, $roleId, $invitedBy, $ttlDays]
        );
        return $ok ? $token : null;
    }

    /** @return array<string, mixed>|null */
    public function pendingInvitationByToken(string $token): ?array
    {
        return $this->row(
            'SELECT * FROM bookclub_invitations
              WHERE token = ? AND accepted_at IS NULL AND expires_at > NOW()',
            's',
            [$token]
        );
    }

    public function markInvitationAccepted(int $invitationId): bool
    {
        return $this->exec('UPDATE bookclub_invitations SET accepted_at = NOW() WHERE id = ?', 'i', [$invitationId]);
    }

    // ------------------------------------------------------------------
    // Workflow
    // ------------------------------------------------------------------

    /**
     * Ordered workflow states for a club, falling back to the shipped
     * default when the club row predates the workflow table or the JSON is
     * corrupt.
     *
     * @param array<string, mixed> $club
     * @return list<array{key: string, label: string, color: string, flags: array<string, bool>}>
     */
    public function workflowStates(array $club): array
    {
        $states = null;
        if (!empty($club['workflow_id'])) {
            $row = $this->row('SELECT states FROM bookclub_workflows WHERE id = ?', 'i', [(int) $club['workflow_id']]);
            if ($row !== null) {
                $decoded = json_decode((string) $row['states'], true);
                if (is_array($decoded) && $decoded !== []) {
                    $states = $decoded;
                }
            }
        }
        if ($states === null) {
            $states = BookClubPlugin::defaultWorkflowStates();
        }
        $out = [];
        foreach ($states as $state) {
            if (!is_array($state) || !isset($state['key'])) {
                continue;
            }
            $out[] = [
                'key' => (string) $state['key'],
                'label' => (string) ($state['label'] ?? $state['key']),
                'color' => (string) ($state['color'] ?? '#6b7280'),
                'flags' => is_array($state['flags'] ?? null) ? $state['flags'] : [],
            ];
        }
        return $out;
    }

    /**
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     */
    public function saveWorkflowStates(array $club, array $states): bool
    {
        $json = json_encode($states, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        if (!empty($club['workflow_id'])) {
            return $this->exec('UPDATE bookclub_workflows SET states = ? WHERE id = ?', 'si', [$json, (int) $club['workflow_id']]);
        }
        $ok = $this->exec(
            'INSERT INTO bookclub_workflows (club_id, name, states) VALUES (?, ?, ?)',
            'iss',
            [(int) $club['id'], (string) $club['name'], $json]
        );
        if ($ok) {
            $this->exec('UPDATE bookclub_clubs SET workflow_id = ? WHERE id = ?', 'ii', [(int) $this->db->insert_id, (int) $club['id']]);
        }
        return $ok;
    }

    /** @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states */
    public static function stateByKey(array $states, string $key): ?array
    {
        foreach ($states as $state) {
            if ($state['key'] === $key) {
                return $state;
            }
        }
        return null;
    }

    /**
     * First state of the workflow — where proposals land and where poll
     * losers return.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     */
    public static function entryStateKey(array $states): string
    {
        return $states[0]['key'] ?? 'proposed';
    }

    /**
     * The state books sit in while a poll is running: the one flagged
     * `voting`, else the key literally named 'voting', else the entry state.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     */
    public static function votingStateKey(array $states): string
    {
        foreach ($states as $state) {
            if (!empty($state['flags']['voting'])) {
                return $state['key'];
            }
        }
        return self::stateByKey($states, 'voting') !== null ? 'voting' : self::entryStateKey($states);
    }

    /**
     * State immediately after $key in the ordered list (poll winners advance
     * here); null when $key is the last state or unknown.
     *
     * @param list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
     */
    public static function nextStateKey(array $states, string $key): ?string
    {
        foreach ($states as $i => $state) {
            if ($state['key'] === $key) {
                return $states[$i + 1]['key'] ?? null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Club books
    // ------------------------------------------------------------------

    // A club book is EITHER a catalogue book (cb.libro_id → libri) OR an
    // external proposal (cb.external_book_id → bookclub_external_books, a book
    // not in the library). Both are LEFT JOINed and the display fields are
    // COALESCEd so one SELECT serves both; is_external tells them apart.
    private const BOOK_SELECT = "SELECT cb.*,
                       COALESCE(l.titolo, ext.titolo) AS titolo,
                       COALESCE(l.copertina_url, ext.copertina_url) AS copertina_url,
                       COALESCE(l.anno_pubblicazione, ext.anno) AS anno_pubblicazione,
                       COALESCE(
                           (SELECT GROUP_CONCAT(" . \App\Support\AuthorName::DISPLAY_SQL_A . "
                                                ORDER BY la.ordine_credito SEPARATOR ', ')
                              FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                             WHERE la.libro_id = l.id
                               AND la.ruolo IN ('principale', 'co-autore')),
                           ext.autori
                       ) AS autori,
                       (cb.external_book_id IS NOT NULL) AS is_external,
                       ext.isbn AS external_isbn,
                       up.nome AS proposer_nome, up.cognome AS proposer_cognome
                  FROM bookclub_books cb
                  LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
                  LEFT JOIN bookclub_external_books ext ON ext.id = cb.external_book_id
                  LEFT JOIN utenti up ON up.id = cb.proposed_by";

    // Guard shared by the list methods: keep the old behaviour of hiding a
    // catalogue book whose libri row is missing/soft-deleted, while still
    // showing external proposals (which have no libri row by design).
    private const BOOK_PRESENT = ' (l.id IS NOT NULL OR cb.external_book_id IS NOT NULL) ';

    /** @return list<array<string, mixed>> */
    public function clubBooks(int $clubId): array
    {
        return $this->rows(
            self::BOOK_SELECT . ' WHERE cb.club_id = ? AND' . self::BOOK_PRESENT . 'ORDER BY cb.position ASC, cb.created_at DESC',
            'i',
            [$clubId]
        );
    }

    /** @return array<string, mixed>|null */
    public function clubBook(int $clubBookId): ?array
    {
        return $this->row(self::BOOK_SELECT . ' WHERE cb.id = ? AND' . self::BOOK_PRESENT, 'i', [$clubBookId]);
    }

    public function bookAlreadyInClub(int $clubId, int $libroId): bool
    {
        return $this->row('SELECT id FROM bookclub_books WHERE club_id = ? AND libro_id = ?', 'ii', [$clubId, $libroId]) !== null;
    }

    public function countOpenProposalsBy(int $clubId, int $userId, string $entryState): int
    {
        $pending = BookClubPlugin::STATE_PENDING;
        $row = $this->row(
            'SELECT COUNT(*) AS n FROM bookclub_books
              WHERE club_id = ? AND proposed_by = ? AND state IN (?, ?)',
            'iiss',
            [$clubId, $userId, $entryState, $pending]
        );
        return (int) ($row['n'] ?? 0);
    }

    public function createClubBook(int $clubId, int $libroId, string $state, ?int $proposedBy, string $motivation): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_books (club_id, libro_id, state, proposed_by, motivation) VALUES (?, ?, ?, ?, ?)',
            'iisis',
            [$clubId, $libroId, $state, $proposedBy, $motivation]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    /**
     * Propose a book that is NOT in the catalogue. The metadata lives in
     * bookclub_external_books (never in `libri`); the bookclub_books row points
     * at it via external_book_id. Returns the new club-book id, or null.
     *
     * @param array{titolo:string, autori?:?string, isbn?:?string, anno?:?string, editore?:?string} $data
     */
    public function proposeExternalBook(int $clubId, array $data, string $state, ?int $proposedBy, string $motivation): ?int
    {
        $titolo = trim((string) $data['titolo']);
        if ($titolo === '') {
            return null;
        }
        $autori  = isset($data['autori']) && trim((string) $data['autori']) !== '' ? trim((string) $data['autori']) : null;
        $isbn    = isset($data['isbn']) && trim((string) $data['isbn']) !== '' ? trim((string) $data['isbn']) : null;
        $anno    = isset($data['anno']) && trim((string) $data['anno']) !== '' ? trim((string) $data['anno']) : null;
        $editore = isset($data['editore']) && trim((string) $data['editore']) !== '' ? trim((string) $data['editore']) : null;

        $ok = $this->exec(
            'INSERT INTO bookclub_external_books (club_id, titolo, autori, isbn, anno, editore, proposed_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'isssssi',
            [$clubId, $titolo, $autori, $isbn, $anno, $editore, $proposedBy]
        );
        if (!$ok) {
            return null;
        }
        $externalId = (int) $this->db->insert_id;

        $ok2 = $this->exec(
            'INSERT INTO bookclub_books (club_id, external_book_id, state, proposed_by, motivation) VALUES (?, ?, ?, ?, ?)',
            'iisis',
            [$clubId, $externalId, $state, $proposedBy, $motivation]
        );
        if (!$ok2) {
            // Roll back the orphaned external row (best-effort; CASCADE-safe).
            $this->exec('DELETE FROM bookclub_external_books WHERE id = ?', 'i', [$externalId]);
            return null;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * Acquire an external proposal into the catalogue: create the real `libri`
     * row from its metadata (this is the ONLY moment the book enters the
     * library), repoint the club-book to it, and stamp the external row.
     * Returns the new libro_id, or null if the club-book is not external / fails.
     */
    public function acquireExternalBook(int $clubBookId): ?int
    {
        $this->db->begin_transaction();
        try {
            $row = $this->row(
                'SELECT cb.external_book_id, ext.titolo, ext.autori, ext.isbn, ext.anno, ext.editore, ext.copertina_url
                   FROM bookclub_books cb
                   JOIN bookclub_external_books ext ON ext.id = cb.external_book_id
                  WHERE cb.id = ? AND cb.external_book_id IS NOT NULL
                  FOR UPDATE',
                'i',
                [$clubBookId]
            );
            if ($row === null || trim((string) $row['titolo']) === '') {
                $this->db->rollback();
                return null;
            }
            $extId  = (int) $row['external_book_id'];
            $titolo = (string) $row['titolo'];
            $anno   = ($row['anno'] !== null && $row['anno'] !== '') ? (int) $row['anno'] : null;
            $digits = $row['isbn'] !== null ? preg_replace('/[^0-9Xx]/', '', (string) $row['isbn']) : '';
            $isbn13 = strlen((string) $digits) === 13 ? (string) $digits : null;
            $isbn10 = strlen((string) $digits) === 10 ? (string) $digits : null;
            $cover  = ($row['copertina_url'] !== null && $row['copertina_url'] !== '') ? substr((string) $row['copertina_url'], 0, 255) : null;
            $publisherId = $this->findOrCreatePublisher(isset($row['editore']) ? (string) $row['editore'] : null);

            // Only `titolo` is mandatory in `libri`; everything else is optional.
            $inserted = $publisherId !== null
                ? $this->exec(
                    'INSERT INTO libri (titolo, anno_pubblicazione, isbn13, isbn10, copertina_url, editore_id) VALUES (?, ?, ?, ?, ?, ?)',
                    'sisssi',
                    [$titolo, $anno, $isbn13, $isbn10, $cover, $publisherId]
                )
                : $this->exec(
                    'INSERT INTO libri (titolo, anno_pubblicazione, isbn13, isbn10, copertina_url) VALUES (?, ?, ?, ?, ?)',
                    'sisss',
                    [$titolo, $anno, $isbn13, $isbn10, $cover]
                );
            if (!$inserted) {
                throw new \RuntimeException('catalog insert failed');
            }
            $libroId = (int) $this->db->insert_id;
            $this->attachExternalAuthors($libroId, isset($row['autori']) ? (string) $row['autori'] : null);
            $this->attachPrimaryPublisher($libroId, $publisherId);
            SearchIndexBuilder::rebuild($this->db, $libroId);

            // Give the acquired book one physical copy, matching how the normal
            // catalogue-creation flow (LibriController) seeds copies. `libri`
            // defaults copie_totali/copie_disponibili to 1, so WITHOUT a matching
            // `copie` row the book would claim one available copy but have none
            // to lend — and the per-copy features (labels, loan/return by code)
            // would find nothing. Inventory number LIB-{id}, same as a single
            // manually-created copy; the whole thing is inside this transaction.
            if (!$this->exec(
                "INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')",
                'is',
                [$libroId, 'LIB-' . $libroId]
            )) {
                throw new \RuntimeException('catalog copy creation failed');
            }

            $bookRows = $this->execAffected(
                'UPDATE bookclub_books SET libro_id = ?, external_book_id = NULL WHERE id = ? AND external_book_id = ?',
                'iii',
                [$libroId, $clubBookId, $extId]
            );
            $externalRows = $this->execAffected(
                'UPDATE bookclub_external_books SET acquired_libro_id = ? WHERE id = ? AND acquired_libro_id IS NULL',
                'ii',
                [$libroId, $extId]
            );
            if ($bookRows !== 1 || $externalRows !== 1) {
                throw new \RuntimeException('catalog acquisition repoint failed');
            }

            $this->db->commit();
            return $libroId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub] external acquisition rolled back: ' . $e->getMessage());
            return null;
        }
    }

    public function changeBookState(int $clubBookId, string $fromState, string $toState, ?int $changedBy): bool
    {
        $ok = $this->exec('UPDATE bookclub_books SET state = ? WHERE id = ?', 'si', [$toState, $clubBookId]);
        if ($ok) {
            $this->exec(
                'INSERT INTO bookclub_book_state_log (club_book_id, from_state, to_state, changed_by) VALUES (?, ?, ?, ?)',
                'issi',
                [$clubBookId, $fromState, $toState, $changedBy]
            );
            if (function_exists('do_action')) {
                do_action('bookclub.book.state_changed', $clubBookId, $fromState, $toState);
            }
        }
        return $ok;
    }

    public function setBookReadingDates(int $clubBookId, ?string $starts, ?string $ends): bool
    {
        return $this->exec(
            'UPDATE bookclub_books SET reading_starts = ?, reading_ends = ? WHERE id = ?',
            'ssi',
            [$starts, $ends, $clubBookId]
        );
    }

    /**
     * Catalog autocomplete for the proposal form.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCatalog(string $q, int $limit = 10): array
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        return $this->rows(
            "SELECT l.id, l.titolo, l.anno_pubblicazione, l.copertina_url,
                    (SELECT GROUP_CONCAT(" . \App\Support\AuthorName::DISPLAY_SQL_A . " SEPARATOR ', ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id
                        AND la.ruolo IN ('principale', 'co-autore')) AS autori
               FROM libri l
              WHERE l.deleted_at IS NULL
                AND (l.titolo LIKE ? OR l.isbn13 LIKE ? OR l.isbn10 LIKE ?)
              ORDER BY l.titolo ASC
              LIMIT ?",
            'sssi',
            [$like, $like, $like, max(1, min(25, $limit))]
        );
    }

    /** @return list<int> club_book ids that are options of a still-open poll */
    public function bookIdsInOpenPolls(int $clubId): array
    {
        $rows = $this->rows(
            "SELECT DISTINCT o.club_book_id
               FROM bookclub_poll_options o
               JOIN bookclub_polls p ON p.id = o.poll_id
              WHERE p.club_id = ? AND p.status = 'open'",
            'i',
            [$clubId]
        );
        return array_map(static fn(array $r): int => (int) $r['club_book_id'], $rows);
    }

    /**
     * Books allowed into a NEW poll: their state must be the entry state or
     * carry the `votable` flag (so books already in voting, currently being
     * read or archived stay out), and they must not be options of another
     * open poll — double-booking corrupts the post-close transitions.
     *
     * @param array<string, mixed> $club hydrated club row
     * @param list<array<string, mixed>> $books rows from clubBooks()
     * @return list<array<string, mixed>>
     */
    public function pollEligibleBooks(array $club, array $books): array
    {
        $states = $this->workflowStates($club);
        $allowedStates = [self::entryStateKey($states) => true];
        foreach ($states as $state) {
            if (!empty($state['flags']['votable'])) {
                $allowedStates[$state['key']] = true;
            }
        }
        $inOpenPoll = array_flip($this->bookIdsInOpenPolls((int) $club['id']));
        return array_values(array_filter(
            $books,
            static fn(array $b): bool =>
                isset($allowedStates[(string) $b['state']]) && !isset($inOpenPoll[(int) $b['id']])
        ));
    }

    /** @return array<string, mixed>|null */
    public function searchCatalogById(int $libroId): ?array
    {
        return $this->row('SELECT id, titolo FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$libroId]);
    }

    public function deleteClubBook(int $clubBookId): bool
    {
        $this->db->begin_transaction();
        try {
            // Serialize removal with acquireExternalBook(), which locks the same
            // club-book row before repointing an external proposal to `libri`.
            $row = $this->row(
                'SELECT external_book_id FROM bookclub_books WHERE id = ? FOR UPDATE',
                'i',
                [$clubBookId]
            );
            if ($row === null) {
                $this->db->rollback();
                return false;
            }

            $externalId = (int) ($row['external_book_id'] ?? 0);
            if ($this->execAffected('DELETE FROM bookclub_books WHERE id = ?', 'i', [$clubBookId]) !== 1) {
                throw new \RuntimeException('club-book delete failed');
            }
            if ($externalId > 0 && $this->execAffected(
                'DELETE FROM bookclub_external_books WHERE id = ? AND acquired_libro_id IS NULL',
                'i',
                [$externalId]
            ) !== 1) {
                throw new \RuntimeException('external-book cleanup failed');
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[BookClub] club-book removal rolled back: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Polls
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function poll(int $pollId): ?array
    {
        return $this->row('SELECT * FROM bookclub_polls WHERE id = ?', 'i', [$pollId]);
    }

    /** @return list<array<string, mixed>> */
    public function clubPolls(int $clubId): array
    {
        return $this->rows(
            "SELECT p.*,
                    (SELECT COUNT(DISTINCT v.user_id) FROM bookclub_votes v WHERE v.poll_id = p.id) AS voter_count
               FROM bookclub_polls p
              WHERE p.club_id = ?
              ORDER BY FIELD(p.status,'open','closed'), p.created_at DESC",
            'i',
            [$clubId]
        );
    }

    /**
     * Options with book info and aggregated score.
     *
     * @return list<array<string, mixed>>
     */
    public function pollOptions(int $pollId): array
    {
        return $this->rows(
            "SELECT o.id, o.club_book_id, cb.state, cb.created_at AS proposed_at,
                    COALESCE(l.titolo, ext.titolo) AS titolo,
                    COALESCE(l.copertina_url, ext.copertina_url) AS copertina_url,
                    COALESCE(
                        (SELECT GROUP_CONCAT(" . \App\Support\AuthorName::DISPLAY_SQL_A . " SEPARATOR ', ')
                           FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                          WHERE la.libro_id = l.id
                            AND la.ruolo IN ('principale', 'co-autore')),
                        ext.autori
                    ) AS autori,
                    (cb.external_book_id IS NOT NULL) AS is_external,
                    ext.isbn AS external_isbn,
                    COALESCE(SUM(v.value), 0) AS score,
                    COUNT(v.id) AS vote_count
               FROM bookclub_poll_options o
               JOIN bookclub_books cb ON cb.id = o.club_book_id
               LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
               LEFT JOIN bookclub_external_books ext ON ext.id = cb.external_book_id
               LEFT JOIN bookclub_votes v ON v.option_id = o.id
              WHERE o.poll_id = ?
                AND (l.id IS NOT NULL OR cb.external_book_id IS NOT NULL)
              GROUP BY o.id, o.club_book_id, cb.state, cb.created_at, l.titolo, l.copertina_url, l.id,
                       ext.titolo, ext.copertina_url, ext.autori, cb.external_book_id, ext.isbn
              ORDER BY score DESC, cb.created_at ASC, o.id ASC",
            'i',
            [$pollId]
        );
    }

    public function createPoll(int $clubId, string $title, string $description, string $mode, int $votesPerMember, string $anonymity, ?string $closesAt, int $createdBy): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_polls (club_id, title, description, mode, votes_per_member, anonymity, closes_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            'isssissi',
            [$clubId, $title, $description, $mode, $votesPerMember, $anonymity, $closesAt, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    public function addPollOption(int $pollId, int $clubBookId): bool
    {
        return $this->exec(
            'INSERT IGNORE INTO bookclub_poll_options (poll_id, club_book_id) VALUES (?, ?)',
            'ii',
            [$pollId, $clubBookId]
        );
    }

    /** @return list<int> option ids the user already voted for */
    public function userVotes(int $pollId, int $userId): array
    {
        $rows = $this->rows('SELECT option_id FROM bookclub_votes WHERE poll_id = ? AND user_id = ?', 'ii', [$pollId, $userId]);
        return array_map(static fn(array $r): int => (int) $r['option_id'], $rows);
    }

    public function clearUserVotes(int $pollId, int $userId): bool
    {
        return $this->exec('DELETE FROM bookclub_votes WHERE poll_id = ? AND user_id = ?', 'ii', [$pollId, $userId]);
    }

    public function castVote(int $pollId, int $optionId, int $userId, float $value = 1.0): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_votes (poll_id, option_id, user_id, value) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            'iiid',
            [$pollId, $optionId, $userId, $value]
        );
    }

    /**
     * Voters per option — only exposed for public polls.
     *
     * @return array<int, list<string>> option_id → display names
     */
    public function pollVoters(int $pollId): array
    {
        $rows = $this->rows(
            'SELECT v.option_id, u.nome, u.cognome
               FROM bookclub_votes v JOIN utenti u ON u.id = v.user_id
              WHERE v.poll_id = ?
              ORDER BY u.cognome, u.nome',
            'i',
            [$pollId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['option_id']][] = trim((string) $row['nome'] . ' ' . (string) $row['cognome']);
        }
        return $out;
    }

    public function closePoll(int $pollId, ?int $winnerClubBookId): bool
    {
        // Idempotency guard: return true ONLY when this call actually flipped the
        // row open → closed. exec() returns true even when the WHERE status='open'
        // clause matches nothing, so two concurrent resolvePoll() readers could both
        // pass the `if (!closePoll(...))` gate and double-run transitionBooks() + the
        // poll.closed hook. Checking affected_rows makes the loser see false → 'noop'.
        $stmt = $this->db->prepare(
            "UPDATE bookclub_polls SET status = 'closed', closed_at = NOW(), winner_club_book_id = ? WHERE id = ? AND status = 'open'"
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub] closePoll prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('ii', $winnerClubBookId, $pollId);
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub] closePoll execute failed: ' . $stmt->error);
            $stmt->close();
            return false;
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

    /** @return list<array<string, mixed>> */
    public function expiredOpenPolls(): array
    {
        return $this->rows(
            "SELECT * FROM bookclub_polls WHERE status = 'open' AND closes_at IS NOT NULL AND closes_at <= NOW()"
        );
    }

    /**
     * Expired-but-still-open polls of ONE club — the per-club lazy-close
     * sweep the read paths (club page, mobile detail, dashboards) run so
     * correctness never depends on the maintenance cron.
     *
     * @return list<array<string, mixed>>
     */
    public function expiredOpenPollsForClub(int $clubId): array
    {
        return $this->rows(
            "SELECT * FROM bookclub_polls
              WHERE club_id = ? AND status = 'open'
                AND closes_at IS NOT NULL AND closes_at <= NOW()",
            'i',
            [$clubId]
        );
    }

    // ------------------------------------------------------------------
    // Meetings
    // ------------------------------------------------------------------

    private const MEETING_SELECT = "SELECT mt.*, COALESCE(l.titolo, ext.titolo) AS book_title,
                       (SELECT COUNT(*) FROM bookclub_meeting_rsvps r WHERE r.meeting_id = mt.id AND r.response = 'yes') AS yes_count,
                       (SELECT COUNT(*) FROM bookclub_meeting_rsvps r WHERE r.meeting_id = mt.id AND r.response = 'maybe') AS maybe_count
                  FROM bookclub_meetings mt
                  LEFT JOIN bookclub_books cb ON cb.id = mt.club_book_id
                  LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
                  LEFT JOIN bookclub_external_books ext ON ext.id = cb.external_book_id";

    /** @return list<array<string, mixed>> */
    public function clubMeetings(int $clubId): array
    {
        return $this->rows(
            self::MEETING_SELECT . ' WHERE mt.club_id = ? ORDER BY mt.starts_at DESC',
            'i',
            [$clubId]
        );
    }

    /** @return array<string, mixed>|null */
    public function meeting(int $meetingId): ?array
    {
        return $this->row(self::MEETING_SELECT . ' WHERE mt.id = ?', 'i', [$meetingId]);
    }

    /** @return array<string, mixed>|null */
    public function nextMeeting(int $clubId): ?array
    {
        return $this->row(
            self::MEETING_SELECT . " WHERE mt.club_id = ? AND mt.status = 'scheduled' AND mt.starts_at >= NOW()
              ORDER BY mt.starts_at ASC LIMIT 1",
            'i',
            [$clubId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createMeeting(int $clubId, array $data, int $createdBy): ?int
    {
        $ok = $this->exec(
            'INSERT INTO bookclub_meetings (club_id, club_book_id, title, agenda, starts_at, ends_at, kind, location, video_url, seats, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iisssssssii',
            [
                $clubId,
                $data['club_book_id'] !== null ? (int) $data['club_book_id'] : null,
                (string) $data['title'],
                (string) ($data['agenda'] ?? ''),
                (string) $data['starts_at'],
                $data['ends_at'] !== null ? (string) $data['ends_at'] : null,
                (string) ($data['kind'] ?? 'in_person'),
                (string) ($data['location'] ?? ''),
                (string) ($data['video_url'] ?? ''),
                $data['seats'] !== null ? (int) $data['seats'] : null,
                $createdBy,
            ]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    /**
     * Update an existing meeting's editable fields. Scoped by club_id so a
     * crafted meetingId from another club can never be edited through a club's
     * own form. Status/minutes have their own setters and are not touched here.
     *
     * @param array<string, mixed> $data
     */
    public function updateMeeting(int $meetingId, int $clubId, array $data): bool
    {
        return $this->exec(
            'UPDATE bookclub_meetings
                SET club_book_id = ?, title = ?, agenda = ?, starts_at = ?, ends_at = ?,
                    kind = ?, location = ?, video_url = ?, seats = ?
              WHERE id = ? AND club_id = ?',
            'isssssssiii',
            [
                $data['club_book_id'] !== null ? (int) $data['club_book_id'] : null,
                (string) $data['title'],
                (string) ($data['agenda'] ?? ''),
                (string) $data['starts_at'],
                $data['ends_at'] !== null ? (string) $data['ends_at'] : null,
                (string) ($data['kind'] ?? 'in_person'),
                (string) ($data['location'] ?? ''),
                (string) ($data['video_url'] ?? ''),
                $data['seats'] !== null ? (int) $data['seats'] : null,
                $meetingId,
                $clubId,
            ]
        );
    }

    public function setMeetingStatus(int $meetingId, string $status): bool
    {
        return $this->exec('UPDATE bookclub_meetings SET status = ? WHERE id = ?', 'si', [$status, $meetingId]);
    }

    public function setMeetingMinutes(int $meetingId, string $minutes): bool
    {
        return $this->exec('UPDATE bookclub_meetings SET minutes = ? WHERE id = ?', 'si', [$minutes, $meetingId]);
    }

    /** @return array<string, mixed>|null */
    public function userRsvp(int $meetingId, int $userId): ?array
    {
        return $this->row('SELECT * FROM bookclub_meeting_rsvps WHERE meeting_id = ? AND user_id = ?', 'ii', [$meetingId, $userId]);
    }

    public function setRsvp(int $meetingId, int $userId, string $response): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_meeting_rsvps (meeting_id, user_id, response) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE response = VALUES(response)',
            'iis',
            [$meetingId, $userId, $response]
        );
    }

    /** @return list<array<string, mixed>> meetings needing a reminder (starting within $hours, not yet reminded) */
    public function meetingsNeedingReminder(int $hours = 24): array
    {
        return $this->rows(
            "SELECT mt.* FROM bookclub_meetings mt
              WHERE mt.status = 'scheduled' AND mt.reminder_sent_at IS NULL
                AND mt.starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)",
            'i',
            [$hours]
        );
    }

    public function markReminderSent(int $meetingId): bool
    {
        return $this->exec('UPDATE bookclub_meetings SET reminder_sent_at = NOW() WHERE id = ?', 'i', [$meetingId]);
    }

    /** @return list<array<string, mixed>> active members with email + locale */
    public function activeMemberEmails(int $clubId): array
    {
        return $this->rows(
            "SELECT u.id, u.nome, u.cognome, u.email, u.locale
               FROM bookclub_members m JOIN utenti u ON u.id = m.user_id
              WHERE m.club_id = ? AND m.status = 'active' AND u.email IS NOT NULL AND u.email <> ''",
            'i',
            [$clubId]
        );
    }

    /**
     * Active owner/moderator members with a usable email — the people who
     * moderate THIS club. Notified alongside the Pinakes admins so a club
     * owner who isn't a Pinakes admin still hears about join requests/proposals.
     *
     * @return list<array<string, mixed>>
     */
    public function clubManagerEmails(int $clubId): array
    {
        return $this->rows(
            "SELECT u.nome, u.cognome, u.email, u.locale
               FROM bookclub_members m
               JOIN bookclub_roles r ON r.id = m.role_id
               JOIN utenti u ON u.id = m.user_id
              WHERE m.club_id = ? AND m.status = 'active'
                AND r.slug IN ('owner','moderator')
                AND u.email IS NOT NULL AND u.email <> ''",
            'i',
            [$clubId]
        );
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    /**
     * Per-club snapshot for /my/book-clubs: current books (states flagged
     * `current`), next meeting, open polls.
     *
     * @param array<string, mixed> $club
     * @return array{current_books: list<array<string, mixed>>, next_meeting: array<string, mixed>|null, open_polls: list<array<string, mixed>>}
     */
    public function clubSnapshot(array $club): array
    {
        $states = $this->workflowStates($club);
        $currentKeys = [];
        foreach ($states as $state) {
            if (!empty($state['flags']['current'])) {
                $currentKeys[] = $state['key'];
            }
        }
        $currentBooks = [];
        if ($currentKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($currentKeys), '?'));
            $types = 'i' . str_repeat('s', count($currentKeys));
            $currentBooks = $this->rows(
                self::BOOK_SELECT . " WHERE cb.club_id = ? AND cb.state IN ($placeholders)
                  ORDER BY cb.position ASC, cb.updated_at DESC LIMIT 5",
                $types,
                array_merge([(int) $club['id']], $currentKeys)
            );
        }
        $openPolls = $this->rows(
            "SELECT id, title, closes_at FROM bookclub_polls WHERE club_id = ? AND status = 'open' ORDER BY created_at DESC",
            'i',
            [(int) $club['id']]
        );
        return [
            'current_books' => $currentBooks,
            'next_meeting' => $this->nextMeeting((int) $club['id']),
            'open_polls' => $openPolls,
        ];
    }
}
