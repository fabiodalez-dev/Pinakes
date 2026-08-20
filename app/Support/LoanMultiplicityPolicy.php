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
 */
final class LoanMultiplicityPolicy
{
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
        $activeCopyPredicate = $strict ? '' : ' AND copia_id IS NULL';

        $stmt = $this->db->prepare("
            SELECT id
            FROM prestiti
            WHERE libro_id = ? AND utente_id = ?{$excludeSql} AND (
                (attivo = 0 AND stato = 'pendente')
                OR (
                    attivo = 1{$activeCopyPredicate}
                    AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo')
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
        $excludeSql = $excludeLoanId !== null ? ' AND id <> ?' : '';
        $stmt = $this->db->prepare("
            SELECT copia_id
            FROM prestiti
            WHERE libro_id = ? AND utente_id = ?{$excludeSql}
              AND copia_id IS NOT NULL
              AND (
                  (attivo = 0 AND stato = 'pendente')
                  OR (attivo = 1 AND stato IN ('prenotato', 'da_ritirare', 'in_corso', 'in_ritardo'))
              )
            FOR UPDATE
        ");

        if ($excludeLoanId !== null) {
            $stmt->bind_param('iii', $bookId, $userId, $excludeLoanId);
        } else {
            $stmt->bind_param('ii', $bookId, $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $copyIds = [];
        while ($row = $result->fetch_assoc()) {
            $copyIds[(int) $row['copia_id']] = true;
        }
        $stmt->close();

        return array_keys($copyIds);
    }
}
