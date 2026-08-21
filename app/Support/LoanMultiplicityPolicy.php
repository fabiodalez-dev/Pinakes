<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;
use mysqli;

/**
 * Centralises the borrower/title duplicate rule for copy-bound staff loans.
 *
 * Callers must already hold the canonical book lock. This service never relaxes
 * pending or legacy copyless commitments: only open rows tied to physical copies
 * can coexist, and the existing per-copy overlap checks remain authoritative.
 *
 * @phpstan-type OpenCopyCommitment array{
 *     loanId: int,
 *     copyId: int,
 *     userId: int,
 *     startDate: string,
 *     endDate: string,
 *     state: string
 * }
 */
final class LoanMultiplicityPolicy
{
    private const OPEN_ACTIVE_STATES_SQL = "'prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'";

    private const OPEN_COMMITMENT_PREDICATE = "(
        (attivo = 0 AND stato = 'pendente')
        OR (attivo = 1 AND stato IN (" . self::OPEN_ACTIVE_STATES_SQL . "))
    )";

    private SettingsRepository $settings;

    public function __construct(private mysqli $db, ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository($db);
    }

    public function isEnabled(): bool
    {
        return $this->settings->allowsMultipleLoansSameBook();
    }

    /**
     * Whether another open loan blocks the proposed borrower/title assignment.
     *
     * The relaxed rule is available only when the caller guarantees that the
     * operation will finish with a physical copy. Otherwise the historical,
     * title-level uniqueness rule is retained.
     */
    public function hasBlockingLoan(
        int $bookId,
        int $userId,
        bool $operationWillBindCopy,
        ?int $excludeLoanId = null
    ): bool {
        $strict = !$operationWillBindCopy || !$this->isEnabled();
        $excludeSql = $excludeLoanId !== null ? ' AND id <> ?' : '';
        // A promoted reservation remains a queue commitment until pickup, even
        // when it already has a physical copy. The relaxed rule applies only to
        // physical loans; it must not create a second queue position for the
        // same borrower/title. Once picked up (`in_corso`/`in_ritardo`), the row
        // is a copy-bound physical loan and can coexist like a direct checkout.
        $activeBlockingPredicate = $strict
            ? ''
            : " AND (copia_id IS NULL OR (origine = 'prenotazione' AND stato IN ('prenotato', 'da_ritirare')))";

        $stmt = $this->db->prepare("
            SELECT id
            FROM prestiti
            WHERE libro_id = ? AND utente_id = ?{$excludeSql} AND (
                (attivo = 0 AND stato = 'pendente')
                OR (
                    attivo = 1{$activeBlockingPredicate}
                    AND stato IN (" . self::OPEN_ACTIVE_STATES_SQL . ")
                )
            )
            LIMIT 1
            FOR UPDATE
        ");

        if ($excludeLoanId !== null) {
            $stmt->bind_param('iii', $bookId, $userId, $excludeLoanId);
        } else {
            $stmt->bind_param('ii', $bookId, $userId);
        }
        $stmt->execute();
        $hasBlockingLoan = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $hasBlockingLoan;
    }

    /**
     * Physical copies already committed by this borrower for the same title.
     *
     * The list ignores date overlap on purpose: two open same-borrower/title rows
     * represent two physical items even when their scheduled windows do not
     * overlap. It remains authoritative after the setting is switched off so
     * lifecycle jobs cannot corrupt duplicates that were validly created while
     * enabled. Callers use it to reject or skip an already committed copy.
     *
     * @return list<int>
     */
    public function committedCopyIds(
        int $bookId,
        int $userId,
        ?int $excludeLoanId = null
    ): array {
        $copyIds = [];
        foreach ($this->lockOpenCopyCommitments(
            $bookId,
            userId: $userId,
            excludeLoanId: $excludeLoanId
        ) as $commitment) {
            $copyIds[$commitment['copyId']] = true;
        }

        $copyIds = array_keys($copyIds);
        sort($copyIds, SORT_NUMERIC);
        return $copyIds;
    }

    /**
     * Lock and normalize copy-bound open commitments in one deterministic batch.
     *
     * At least one selector is required. The copy selector is used by lifecycle
     * allocation; the user selector powers committedCopyIds(). Keeping both on
     * this API gives every caller the same open-state predicate and typed identity.
     * Callers must already hold the canonical book lock.
     *
     * @return list<OpenCopyCommitment>
     */
    public function lockOpenCopyCommitments(
        int $bookId,
        ?int $copyId = null,
        ?int $userId = null,
        ?int $excludeLoanId = null
    ): array {
        if ($copyId === null && $userId === null) {
            throw new \InvalidArgumentException('A copy or user selector is required.');
        }

        $where = [
            'libro_id = ?',
            'copia_id IS NOT NULL',
            self::OPEN_COMMITMENT_PREDICATE,
        ];
        $types = 'i';
        $params = [$bookId];

        if ($copyId !== null) {
            $where[] = 'copia_id = ?';
            $types .= 'i';
            $params[] = $copyId;
        }
        if ($userId !== null) {
            $where[] = 'utente_id = ?';
            $types .= 'i';
            $params[] = $userId;
        }
        if ($excludeLoanId !== null) {
            $where[] = 'id <> ?';
            $types .= 'i';
            $params[] = $excludeLoanId;
        }

        $stmt = $this->db->prepare("
            SELECT id, copia_id, utente_id, data_prestito, data_scadenza, stato
            FROM prestiti
            WHERE " . implode(' AND ', $where) . "
            ORDER BY id ASC
            FOR UPDATE
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $commitments = [];
        while ($row = $result->fetch_assoc()) {
            $commitments[] = [
                'loanId' => (int) $row['id'],
                'copyId' => (int) $row['copia_id'],
                'userId' => (int) $row['utente_id'],
                'startDate' => (string) $row['data_prestito'],
                'endDate' => (string) $row['data_scadenza'],
                'state' => (string) $row['stato'],
            ];
        }
        $stmt->close();

        return $commitments;
    }

    /**
     * Pure conflict check for assigning one candidate to a locked copy batch.
     *
     * Same-borrower identity is stronger than dates: two open rows for a title
     * must represent distinct physical items. Other borrowers block only when
     * their inclusive date windows overlap; overdue commitments are open-ended.
     *
     * @param list<OpenCopyCommitment> $commitments
     */
    public static function candidateConflictsWithOpenCommitments(
        int $candidateLoanId,
        int $candidateUserId,
        string $candidateStartDate,
        string $candidateEndDate,
        array $commitments
    ): bool {
        foreach ($commitments as $commitment) {
            if ($commitment['loanId'] === $candidateLoanId) {
                continue;
            }

            if ($commitment['userId'] === $candidateUserId) {
                return true;
            }

            $startsBeforeCandidateEnds = $commitment['startDate'] <= $candidateEndDate;
            $endsAfterCandidateStarts = $commitment['state'] === 'in_ritardo'
                || $commitment['endDate'] >= $candidateStartDate;
            if ($startsBeforeCandidateEnds && $endsAfterCandidateStarts) {
                return true;
            }
        }

        return false;
    }
}
