<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\SecureLogger;
use mysqli;

/**
 * Data access for the Surveys module (plan §7.13): survey CRUD, the frozen
 * question schema (schema_json, an ordered list of question objects), member
 * answers (one per member, UNIQUE (survey_id, user_id)) and the aggregate
 * counters used by the club-page panel and the results page.
 *
 * ANONYMITY MODEL — bookclub_survey_answers.user_id is ALWAYS recorded, even
 * for anonymous surveys: it is what enforces the one-answer-per-member rule
 * (the UNIQUE key would otherwise allow unlimited NULL user_id rows). For
 * anonymous surveys the identity is a WRITE-ONLY fact: no view, aggregate or
 * export may ever join it back to a name. Callers must check
 * bookclub_surveys.anonymous before displaying user_name.
 *
 * mysqli prepared statements only, same style as App\Plugins\BookClub\Repo.
 */
class SurveyRepo
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // Low-level helpers (same pattern as Repo / StatsRepo)
    // ------------------------------------------------------------------

    /**
     * @param array<int, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:surveys] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            SecureLogger::error('[BookClub:surveys] execute failed: ' . $stmt->error);
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
            SecureLogger::error('[BookClub:surveys] prepare failed: ' . $this->db->error . ' — ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:surveys] execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Question schema helpers
    // ------------------------------------------------------------------

    /**
     * Decode schema_json into a normalised, validated question list.
     * Malformed entries are dropped so a corrupt row can never break a view.
     *
     * @return list<array{key: string, type: string, label: string, options: list<string>, required: bool}>
     */
    public static function decodeSchema(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $q) {
            if (!is_array($q) || !isset($q['key'], $q['type'], $q['label'])) {
                continue;
            }
            $options = [];
            foreach (is_array($q['options'] ?? null) ? $q['options'] : [] as $option) {
                if (is_string($option) || is_numeric($option)) {
                    $options[] = (string) $option;
                }
            }
            $out[] = [
                'key' => (string) $q['key'],
                'type' => (string) $q['type'],
                'label' => (string) $q['label'],
                'options' => $options,
                'required' => !empty($q['required']),
            ];
        }
        return $out;
    }

    /**
     * Next stable question key ("q1", "q2", …). Keys never get reused after
     * a delete, so stored answers can never point at the wrong question.
     *
     * @param list<array{key: string, type: string, label: string, options: list<string>, required: bool}> $schema
     */
    public static function nextQuestionKey(array $schema): string
    {
        $max = 0;
        foreach ($schema as $q) {
            if (preg_match('/^q(\d+)$/', $q['key'], $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        return 'q' . ($max + 1);
    }

    // ------------------------------------------------------------------
    // Surveys
    // ------------------------------------------------------------------

    private const SURVEY_SELECT = "SELECT s.*, l.titolo AS book_title,
               (SELECT COUNT(*) FROM bookclub_survey_answers a WHERE a.survey_id = s.id) AS answer_count
          FROM bookclub_surveys s
          LEFT JOIN bookclub_books cb ON cb.id = s.club_book_id
          LEFT JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL";

    /** @return list<array<string, mixed>> open first, then drafts, then closed */
    public function listSurveys(int $clubId, bool $includeDrafts): array
    {
        $sql = self::SURVEY_SELECT . ' WHERE s.club_id = ?';
        if (!$includeDrafts) {
            $sql .= " AND s.status <> 'draft'";
        }
        $sql .= " ORDER BY FIELD(s.status, 'open', 'draft', 'closed'), s.created_at DESC, s.id DESC";
        return $this->rows($sql, 'i', [$clubId]);
    }

    /** @return array<string, mixed>|null */
    public function surveyById(int $surveyId): ?array
    {
        return $this->row(self::SURVEY_SELECT . ' WHERE s.id = ?', 'i', [$surveyId]);
    }

    public function createSurvey(int $clubId, ?int $clubBookId, string $title, int $anonymous, ?string $opensAt, ?string $closesAt, ?int $createdBy): ?int
    {
        $ok = $this->exec(
            "INSERT INTO bookclub_surveys (club_id, club_book_id, title, schema_json, status, anonymous, opens_at, closes_at, created_by)
             VALUES (?, ?, ?, '[]', 'draft', ?, ?, ?, ?)",
            'iisissi',
            [$clubId, $clubBookId, $title, $anonymous, $opensAt, $closesAt, $createdBy]
        );
        return $ok ? (int) $this->db->insert_id : null;
    }

    /**
     * Edit a draft's metadata (title, linked book, anonymity, opening and
     * closing dates). The WHERE status = 'draft' guard makes it a no-op on
     * published surveys, preserving the schema/settings freeze-on-publish.
     */
    public function updateDraftMeta(int $surveyId, ?int $clubBookId, string $title, int $anonymous, ?string $opensAt, ?string $closesAt): bool
    {
        return $this->exec(
            "UPDATE bookclub_surveys
                SET title = ?, club_book_id = ?, anonymous = ?, opens_at = ?, closes_at = ?
              WHERE id = ? AND status = 'draft'",
            'siissi',
            [$title, $clubBookId, $anonymous, $opensAt, $closesAt, $surveyId]
        );
    }

    /**
     * Hard-delete a draft. Drafts cannot have answers (answering requires
     * status = 'open'), so nothing else needs cleaning up; the status guard
     * protects published surveys against a racing publish. Returns true only
     * when a row was actually removed.
     */
    public function deleteDraft(int $surveyId): bool
    {
        $ok = $this->exec(
            "DELETE FROM bookclub_surveys WHERE id = ? AND status = 'draft'",
            'i',
            [$surveyId]
        );
        return $ok && (int) $this->db->affected_rows > 0;
    }

    /**
     * Scheduled opening gate: an 'open' survey whose opens_at lies in the
     * future is visible but does not accept answers yet. NULL opens_at
     * (or a past one) means the survey is answerable now.
     *
     * @param array<string, mixed> $survey
     */
    public static function notYetOpen(array $survey): bool
    {
        if ((string) ($survey['status'] ?? '') !== 'open') {
            return false;
        }
        $opensAt = $survey['opens_at'] ?? null;
        if ($opensAt === null || $opensAt === '') {
            return false;
        }
        $ts = strtotime((string) $opensAt);
        return $ts !== false && $ts > time();
    }

    /** Only drafts are editable: publishing freezes the schema by design. */
    public function updateSchemaJson(int $surveyId, string $json): bool
    {
        return $this->exec(
            "UPDATE bookclub_surveys SET schema_json = ? WHERE id = ? AND status = 'draft'",
            'si',
            [$json, $surveyId]
        );
    }

    /** draft → open; opens_at is stamped once. */
    public function publish(int $surveyId): bool
    {
        return $this->exec(
            "UPDATE bookclub_surveys SET status = 'open', opens_at = COALESCE(opens_at, NOW()) WHERE id = ? AND status = 'draft'",
            'i',
            [$surveyId]
        );
    }

    /** open → closed (manual, manager action). */
    public function closeSurvey(int $surveyId): bool
    {
        return $this->exec(
            "UPDATE bookclub_surveys SET status = 'closed' WHERE id = ? AND status = 'open'",
            'i',
            [$surveyId]
        );
    }

    /**
     * Lazy auto-close: every open survey of the club whose closes_at has
     * passed becomes closed. Idempotent; called on list/detail views and by
     * the maintenance tick. Returns the number of surveys closed.
     */
    public function closeExpired(int $clubId): int
    {
        $ok = $this->exec(
            "UPDATE bookclub_surveys SET status = 'closed'
              WHERE club_id = ? AND status = 'open' AND closes_at IS NOT NULL AND closes_at <= NOW()",
            'i',
            [$clubId]
        );
        return $ok ? max(0, (int) $this->db->affected_rows) : 0;
    }

    /** @return list<array<string, mixed>> open surveys with answer_count (club panel) */
    public function openSurveysWithCounts(int $clubId, int $limit = 5): array
    {
        return $this->rows(
            "SELECT s.id, s.title, s.status, s.opens_at, s.closes_at, s.anonymous,
                    (SELECT COUNT(*) FROM bookclub_survey_answers a WHERE a.survey_id = s.id) AS answer_count
               FROM bookclub_surveys s
              WHERE s.club_id = ? AND s.status = 'open'
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ?",
            'ii',
            [$clubId, max(1, min(25, $limit))]
        );
    }

    // ------------------------------------------------------------------
    // Answers
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null the member's own answer row */
    public function answerRow(int $surveyId, int $userId): ?array
    {
        return $this->row(
            'SELECT * FROM bookclub_survey_answers WHERE survey_id = ? AND user_id = ?',
            'ii',
            [$surveyId, $userId]
        );
    }

    /**
     * One row per member, enforced by UNIQUE uq_survey_user. user_id is
     * always recorded (see the anonymity note in the class docblock).
     */
    public function insertAnswer(int $surveyId, int $userId, string $answersJson): bool
    {
        return $this->exec(
            'INSERT INTO bookclub_survey_answers (survey_id, user_id, answers_json) VALUES (?, ?, ?)',
            'iis',
            [$surveyId, $userId, $answersJson]
        );
    }

    /**
     * All answers with the resolved member name. user_name MUST NOT be
     * displayed or exported when the survey is anonymous.
     *
     * @return list<array<string, mixed>>
     */
    public function answers(int $surveyId): array
    {
        return $this->rows(
            "SELECT a.id, a.user_id, a.answers_json, a.created_at,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name
               FROM bookclub_survey_answers a
               LEFT JOIN utenti u ON u.id = a.user_id
              WHERE a.survey_id = ?
              ORDER BY a.created_at ASC, a.id ASC",
            'i',
            [$surveyId]
        );
    }

    /**
     * IDs of the club's surveys the user has already answered (badges in
     * list/panel).
     *
     * @return list<int>
     */
    public function answeredSurveyIds(int $clubId, int $userId): array
    {
        $rows = $this->rows(
            'SELECT a.survey_id
               FROM bookclub_survey_answers a
               JOIN bookclub_surveys s ON s.id = a.survey_id
              WHERE s.club_id = ? AND a.user_id = ?',
            'ii',
            [$clubId, $userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row['survey_id'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Club books (builder "link a book" select)
    // ------------------------------------------------------------------

    /** @return list<array{id: int|string, titolo: string}> */
    public function clubBooks(int $clubId): array
    {
        return $this->rows(
            'SELECT cb.id, l.titolo
               FROM bookclub_books cb
               JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
              WHERE cb.club_id = ?
              ORDER BY cb.updated_at DESC, cb.id DESC
              LIMIT 100',
            'i',
            [$clubId]
        );
    }
}
