<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DateHelper;
use App\Support\DbAtomic;
use App\Support\LibraryCalendar;
use mysqli;

/**
 * Patron ledger (movimenti_lettore): charges, payments, waivers, reversals.
 *
 * The table is append-only: a row is never updated or deleted by the
 * application; a mistake is corrected with a new row (condono / storno). The
 * balance is SUM(importo): positive = the patron owes money.
 *
 * Charges tied to a loan carry an idempotency key, so the same event can be
 * processed twice (a retried request, a repeated return) without charging
 * twice:
 *   late:<loan id>     late fine written when the loan is returned
 *   penalty:<loan id>  amount assessed when the copy is closed lost/damaged
 *                      (the same key the 0.9.0 migration uses for prestiti.sanzione)
 *   form:<32 hex>      one submitted desk form (payment, charge, waiver): a
 *                      repeated POST writes nothing more; the same key with
 *                      other values is refused (duplicate_request)
 *   storno:late:<loan id>:<n> / storno:waiver:<loan id>:<n>
 *                      the n-th undo of a return: the late fine and the
 *                      waivers written for it, reversed
 *
 * Methods that write take part in the caller's transaction when one is open
 * (the return paths); otherwise they open and commit their own.
 */
final class PatronLedger
{
    public const TYPES = ['multa_ritardo', 'addebito_perdita', 'addebito_danno', 'addebito_manuale', 'pagamento', 'condono', 'storno'];
    public const CHARGE_TYPES = ['multa_ritardo', 'addebito_perdita', 'addebito_danno', 'addebito_manuale'];
    public const PAYMENT_METHODS = ['contanti', 'pos', 'bonifico', 'altro'];

    /** Largest single amount an operator may type (DECIMAL(10,2) headroom). */
    public const MAX_AMOUNT_CENTS = 99999999;

    /** @var array<string, bool> one probe per connection and request (as CirculationPolicy::schemaReady()) */
    private static array $schemaReady = [];

    private mysqli $db;
    private FineCalculator $calculator;

    public function __construct(mysqli $db, ?FineCalculator $calculator = null)
    {
        $this->db = $db;
        $this->calculator = $calculator ?? new FineCalculator();
    }

    /**
     * The ledger every application path uses (return paths, reader record,
     * notifications, reports, Mobile API). Building it here is the single
     * place where the late-fine day count gets its "is the library open on
     * this day" rule, so closed days are honoured on every channel at once.
     */
    public static function forDb(mysqli $db): self
    {
        // Closed days (weekly closed days, closures, yearly closures, closures
        // of the copy's branch) are not late days (plan §13, decision 5). With
        // no calendar configured every day counts, exactly as before.
        $calendar = LibraryCalendar::forDb($db);
        if (!$calendar->hasRules()) {
            return new self($db, new FineCalculator());
        }
        return new self($db, new FineCalculator(
            static fn (string $ymd, ?int $branchId = null): bool => $calendar->isOpen($ymd, $branchId)
        ));
    }

    /**
     * Whether movimenti_lettore exists. Code ships before its migration runs
     * during an upgrade: until then there is no balance, so no debt block in
     * SQL or PHP, and nothing for the maintenance backfill to do.
     */
    public static function schemaReady(mysqli $db): bool
    {
        $key = (string) spl_object_id($db);
        if (!array_key_exists($key, self::$schemaReady)) {
            $ready = false;
            try {
                $result = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'");
                $ready = $result instanceof \mysqli_result && (int) ($result->fetch_row()[0] ?? 0) === 1;
            } catch (\Throwable) {
                $ready = false;
            }
            self::$schemaReady[$key] = $ready;
        }
        return self::$schemaReady[$key];
    }

    /**
     * Whether fines and the ledger apply: the table exists AND the fines
     * module is on (Settings -> Loans, features.fines; also off in catalogue
     * mode). Off, nothing is charged, nobody is blocked for debt and the
     * movements are not shown; the rows already written stay, and
     * prestiti.sanzione keeps being written as before 0.9.0.
     */
    public static function active(mysqli $db): bool
    {
        return \App\Support\Features::enabled('fines') && self::schemaReady($db);
    }

    /** Drop the per-request schema probe (tests that migrate a database mid-process). */
    public static function resetSchemaCache(): void
    {
        self::$schemaReady = [];
    }

    /** SQL expression for a patron's balance; $userIdExpr is a fixed column reference. */
    public static function balanceSql(string $userIdExpr): string
    {
        return "(SELECT COALESCE(SUM(ml.importo), 0) FROM movimenti_lettore ml WHERE ml.utente_id = {$userIdExpr})";
    }

    public function balanceCents(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(importo), 0) FROM movimenti_lettore WHERE utente_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $value = $stmt->get_result()->fetch_row()[0] ?? 0;
        $stmt->close();
        return FineParameters::decimalToCents($value);
    }

    /** Balance right after movement $movementId (receipts show the balance at that moment). */
    public function balanceAfterCents(int $userId, int $movementId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(importo), 0) FROM movimenti_lettore WHERE utente_id = ? AND id <= ?');
        $stmt->bind_param('ii', $userId, $movementId);
        $stmt->execute();
        $value = $stmt->get_result()->fetch_row()[0] ?? 0;
        $stmt->close();
        return FineParameters::decimalToCents($value);
    }

    /**
     * Debt block (plan §7): the patron owes more than the threshold of their
     * patron type (else the global one). See CirculationPolicy::debtBlockError();
     * the SQL twin is LoanEligibility::debtBlockSql().
     */
    public static function isDebtBlocked(mysqli $db, int $userId): bool
    {
        return (new CirculationPolicy($db))->debtBlockError($userId) !== null;
    }

    /**
     * Movements of a patron, newest first, with the loan's title and the operator.
     *
     * @return list<array<string, mixed>>
     */
    public function movements(int $userId, int $limit = 500, int $offset = 0): array
    {
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $stmt = $this->db->prepare(
            "SELECT m.id, m.utente_id, m.prestito_id, m.tipo, m.importo, m.descrizione, m.metodo_pagamento,
                    m.creato_da, m.created_at, m.chiave_idempotenza,
                    l.titolo AS libro_titolo,
                    TRIM(CONCAT(COALESCE(op.nome, ''), ' ', COALESCE(op.cognome, ''))) AS operatore
               FROM movimenti_lettore m
               LEFT JOIN prestiti p ON p.id = m.prestito_id
               LEFT JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
               LEFT JOIN utenti op ON op.id = m.creato_da
              WHERE m.utente_id = ?
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Number of ledger rows of $userId (the reader record pages through them). */
    public function movementCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $count;
    }

    /** @return array<string, mixed>|null */
    public function movement(int $movementId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, TRIM(CONCAT(COALESCE(op.nome, ''), ' ', COALESCE(op.cognome, ''))) AS operatore
               FROM movimenti_lettore m
               LEFT JOIN utenti op ON op.id = m.creato_da
              WHERE m.id = ?"
        );
        $stmt->bind_param('i', $movementId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Write the late fine of a returned loan (idempotent).
     *
     * Called inside the return transaction, right after the loan row is closed.
     * Only an actual return ('restituito') is fined: a copy closed lost or
     * damaged carries the assessed penalty instead.
     *
     * @return int cents charged by THIS call (0 when nothing is due or the
     *             fine was already written)
     */
    public function chargeLateReturn(int $loanId, ?int $staffId = null): int
    {
        if (!self::active($this->db)) {
            return 0;
        }
        $loan = $this->loanRow($loanId);
        if ($loan === null || (string) $loan['stato'] !== 'restituito' || empty($loan['data_restituzione'])) {
            return 0;
        }
        $params = FineParameters::forLoan($this->db, $loanId);
        $branch = self::branchOf($loan);
        $cents = $this->calculator->lateFineCents($params, (string) $loan['data_scadenza'], (string) $loan['data_restituzione'], $branch);
        if ($cents <= 0) {
            return 0;
        }
        $days = $this->calculator->daysLate((string) $loan['data_scadenza'], (string) $loan['data_restituzione'], $branch);
        $id = $this->insert(
            (int) $loan['utente_id'],
            $loanId,
            'multa_ritardo',
            $cents,
            sprintf('%d|%s|%s', $days, (string) $loan['data_scadenza'], (string) $loan['data_restituzione']),
            null,
            $staffId,
            $this->lateKey($loanId)
        );
        return $id === null ? 0 : $cents;
    }

    /**
     * Mirror the amount assessed for a lost/damaged copy (prestiti.sanzione)
     * into the ledger (idempotent, key penalty:<loan id>).
     */
    public function chargeLossPenalty(int $loanId, ?int $staffId = null): int
    {
        // Fines off: prestiti.sanzione alone, as before 0.9.0. The same key
        // as the legacy backfill, which mirrors it once the module is back on.
        if (!self::active($this->db)) {
            return 0;
        }
        $loan = $this->loanRow($loanId);
        if ($loan === null) {
            return 0;
        }
        $cents = FineParameters::decimalToCents($loan['sanzione'] ?? 0);
        if ($cents <= 0) {
            return 0;
        }
        $type = (string) $loan['stato'] === 'perso' ? 'addebito_perdita' : 'addebito_danno';
        $id = $this->insert((int) $loan['utente_id'], $loanId, $type, $cents, null, null, $staffId, 'penalty:' . $loanId);
        return $id === null ? 0 : $cents;
    }

    /**
     * A return was undone: reverse the late fine with a storno row, and the
     * waivers written for it with a second one, so the balance is back where
     * it was before the return. A later return of the same loan writes a new
     * late fine (the key carries the reversal count, so the history stays one
     * row per event).
     *
     * @return int cents of late fine reversed (0 when no late fine is outstanding)
     */
    public function reverseLateFine(int $loanId, string $reason, ?int $staffId = null): int
    {
        $charged = $this->loanSum($loanId, "tipo = 'multa_ritardo' OR (tipo = 'storno' AND chiave_idempotenza LIKE 'storno:late:%')");
        if ($charged <= 0) {
            return 0;
        }
        $loan = $this->loanRow($loanId);
        if ($loan === null) {
            return 0;
        }
        $waived = -$this->loanSum($loanId, "tipo = 'condono' OR (tipo = 'storno' AND chiave_idempotenza LIKE 'storno:waiver:%')");
        $generation = $this->reversalCount($loanId) + 1;
        $id = null;
        $this->inTransaction(function () use ($loan, $loanId, $charged, $waived, $reason, $staffId, $generation, &$id): void {
            $id = $this->insert(
                (int) $loan['utente_id'],
                $loanId,
                'storno',
                -$charged,
                self::clip($reason),
                null,
                $staffId,
                'storno:late:' . $loanId . ':' . $generation
            );
            if ($id !== null && $waived > 0) {
                $this->insert(
                    (int) $loan['utente_id'],
                    $loanId,
                    'storno',
                    $waived,
                    self::clip($reason),
                    null,
                    $staffId,
                    'storno:waiver:' . $loanId . ':' . $generation
                );
            }
        });
        return $id === null ? 0 : $charged;
    }

    /**
     * Late fine still standing for a loan: late charges minus their reversals
     * and minus the waivers written for that loan (the desk waiver at return
     * carries the loan id), never below zero. The return email, the fine
     * email and the loan page all read this figure.
     */
    public function lateFineNetCents(int $loanId): int
    {
        return max(0, $this->loanSum(
            $loanId,
            "tipo = 'multa_ritardo' OR tipo = 'condono'
             OR (tipo = 'storno' AND (chiave_idempotenza LIKE 'storno:late:%' OR chiave_idempotenza LIKE 'storno:waiver:%'))"
        ));
    }

    /** SUM(importo) in cents of the movements of a loan matching $where (fixed SQL from this class). */
    private function loanSum(int $loanId, string $where): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(importo), 0) FROM movimenti_lettore WHERE prestito_id = ? AND ({$where})");
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $value = $stmt->get_result()->fetch_row()[0] ?? 0;
        $stmt->close();
        return FineParameters::decimalToCents($value);
    }

    /** Days late recorded with the most recent late fine of a loan (0 if none). */
    public function lateFineDays(int $loanId): int
    {
        $stmt = $this->db->prepare("SELECT descrizione FROM movimenti_lettore WHERE prestito_id = ? AND tipo = 'multa_ritardo' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $text = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
        $stmt->close();
        return preg_match('/^(\d+)\|/', $text, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Payment received at the desk (negative movement). $requestKey (one per
     * submitted form) makes a repeated submission return the movement it
     * already wrote instead of recording a second payment.
     */
    public function recordPayment(int $userId, int $cents, string $method, ?string $note, ?int $staffId, ?string $requestKey = null): int
    {
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new \InvalidArgumentException('invalid_method');
        }
        return $this->credit($userId, 'pagamento', $cents, $note, $method, $staffId, null, $requestKey);
    }

    /** Waiver (condono) of part or all of the debt; the reason is mandatory. */
    public function waive(int $userId, int $cents, string $reason, ?int $staffId, ?int $loanId = null, ?string $requestKey = null): int
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('reason_required');
        }
        return $this->credit($userId, 'condono', $cents, $reason, null, $staffId, $loanId, $requestKey);
    }

    /** Manual charge with a mandatory description ($requestKey: see recordPayment()). */
    public function manualCharge(int $userId, int $cents, string $description, ?int $staffId, ?string $requestKey = null): int
    {
        if ($cents <= 0 || $cents > self::MAX_AMOUNT_CENTS) {
            throw new \InvalidArgumentException('invalid_amount');
        }
        if (trim($description) === '') {
            throw new \InvalidArgumentException('description_required');
        }
        $id = null;
        $this->inTransaction(function () use ($userId, $cents, $description, $staffId, $requestKey, &$id): void {
            $this->lockUser($userId);
            $id = $this->replayedMovement($userId, $requestKey, 'addebito_manuale', $cents, null, self::clip($description))
                ?? $this->insert($userId, null, 'addebito_manuale', $cents, self::clip($description), null, $staffId, $requestKey);
        });
        return (int) $id;
    }

    /**
     * Fine a loan would carry if it were returned on $returnDate (default today).
     *
     * @return array{days: int, cents: int, enabled: bool}
     */
    public function previewLateFine(int $loanId, ?string $returnDate = null): array
    {
        if (!self::active($this->db)) {
            return ['days' => 0, 'cents' => 0, 'enabled' => false];
        }
        $loan = $this->loanRow($loanId);
        $params = FineParameters::forLoan($this->db, $loanId);
        if ($loan === null) {
            return ['days' => 0, 'cents' => 0, 'enabled' => $params->finesEnabled()];
        }
        $date = $returnDate ?? DateHelper::today();
        $branch = self::branchOf($loan);
        return [
            'days' => $this->calculator->daysLate((string) $loan['data_scadenza'], $date, $branch),
            'cents' => $this->calculator->lateFineCents($params, (string) $loan['data_scadenza'], $date, $branch),
            'enabled' => $params->finesEnabled(),
        ];
    }

    /**
     * Fines accruing on the patron's open overdue loans, computed for today and
     * never written (plan §7: shown, charged only at return).
     *
     * @return list<array{loan_id: int, titolo: string, data_scadenza: string, days: int, cents: int}>
     */
    public function accruedFines(int $userId): array
    {
        if (!self::active($this->db)) {
            return [];
        }
        $today = DateHelper::today();
        $stmt = $this->db->prepare(
            "SELECT p.id, p.data_scadenza, l.titolo, c.sede_id
               FROM prestiti p
               LEFT JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
               LEFT JOIN copie c ON c.id = p.copia_id
              WHERE p.utente_id = ? AND p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo')
                AND p.data_scadenza < ?
              ORDER BY p.data_scadenza ASC"
        );
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $out = [];
        foreach ($rows as $row) {
            $loanId = (int) $row['id'];
            $params = FineParameters::forLoan($this->db, $loanId);
            $branch = self::branchOf($row);
            $cents = $this->calculator->lateFineCents($params, (string) $row['data_scadenza'], $today, $branch);
            if ($cents <= 0) {
                continue;
            }
            $out[] = [
                'loan_id' => $loanId,
                'titolo' => (string) ($row['titolo'] ?? ''),
                'data_scadenza' => (string) $row['data_scadenza'],
                'days' => $this->calculator->daysLate((string) $row['data_scadenza'], $today, $branch),
                'cents' => $cents,
            ];
        }
        return $out;
    }

    /**
     * Convert prestiti.sanzione amounts the migration did not reach (archives
     * past its batch limit) into ledger rows, $batch loans per transaction.
     * Same key as the migration, so it never doubles an amount.
     *
     * @return int|null movements written; null when the ledger table does not
     *                  exist yet (the 0.9.0 migration has not run), so the
     *                  caller does not record the scan as completed
     */
    public function backfillLegacyPenalties(int $batch = 500, int $maxBatches = 1000): ?int
    {
        // null also while the fines module is off: the scan is not recorded
        // as done and runs when the module is switched back on.
        if (!self::active($this->db)) {
            return null;
        }
        $written = 0;
        $batch = max(1, min(5000, $batch));
        $sql = "INSERT INTO movimenti_lettore (utente_id, prestito_id, tipo, importo, descrizione, creato_da, created_at, chiave_idempotenza)
                SELECT p.utente_id, p.id, IF(p.stato = 'perso', 'addebito_perdita', 'addebito_danno'), p.sanzione,
                       'Importo registrato alla chiusura del prestito', p.processed_by,
                       COALESCE(CAST(p.data_restituzione AS DATETIME), p.updated_at, p.created_at, NOW()), CONCAT('penalty:', p.id)
                  FROM prestiti p
                 WHERE p.sanzione > 0
                   AND NOT EXISTS (SELECT 1 FROM movimenti_lettore m WHERE m.chiave_idempotenza = CONCAT('penalty:', p.id))
                 ORDER BY p.id
                 LIMIT {$batch}
                ON DUPLICATE KEY UPDATE movimenti_lettore.id = movimenti_lettore.id";
        for ($i = 0; $i < $maxBatches; $i++) {
            if (!$this->db->query($sql)) {
                // Not "done": the caller must not record the scan as completed.
                throw new \RuntimeException('Legacy penalty backfill failed: ' . $this->db->error);
            }
            $affected = $this->db->affected_rows;
            if ($affected <= 0) {
                break;
            }
            $written += $affected;
        }
        return $written;
    }

    /**
     * Charged / collected / waived / reversed per month in [$from, $to].
     *
     * @return list<array{mese: string, addebitato: int, incassato: int, condonato: int, stornato: int}>
     */
    public function periodTotals(string $from, string $to): array
    {
        if (!self::active($this->db)) {
            return [];
        }
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS mese,
                    SUM(CASE WHEN tipo IN ('multa_ritardo','addebito_perdita','addebito_danno','addebito_manuale') THEN importo ELSE 0 END) AS addebitato,
                    SUM(CASE WHEN tipo = 'pagamento' THEN -importo ELSE 0 END) AS incassato,
                    SUM(CASE WHEN tipo = 'condono' THEN -importo ELSE 0 END) AS condonato,
                    SUM(CASE WHEN tipo = 'storno' THEN -importo ELSE 0 END) AS stornato
               FROM movimenti_lettore
              WHERE created_at BETWEEN ? AND ?
              GROUP BY DATE_FORMAT(created_at, '%Y-%m')
              ORDER BY mese ASC"
        );
        $stmt->bind_param('ss', $fromTs, $toTs);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(static fn (array $r): array => [
            'mese' => (string) $r['mese'],
            'addebitato' => FineParameters::decimalToCents($r['addebitato']),
            'incassato' => FineParameters::decimalToCents($r['incassato']),
            'condonato' => FineParameters::decimalToCents($r['condonato']),
            'stornato' => FineParameters::decimalToCents($r['stornato']),
        ], $rows);
    }

    /** Payment or waiver: never more than the patron currently owes. */
    private function credit(int $userId, string $type, int $cents, ?string $text, ?string $method, ?int $staffId, ?int $loanId, ?string $requestKey = null): int
    {
        if ($cents <= 0 || $cents > self::MAX_AMOUNT_CENTS) {
            throw new \InvalidArgumentException('invalid_amount');
        }
        $id = null;
        $this->inTransaction(function () use ($userId, $type, $cents, $text, $method, $staffId, $loanId, $requestKey, &$id): void {
            $this->lockUser($userId);
            // A repeated form submission: the movement is already there (and the
            // balance check would now refuse it), so hand back the same row.
            $id = $this->replayedMovement($userId, $requestKey, $type, -$cents, $method, $text !== null ? self::clip($text) : null);
            if ($id !== null) {
                return;
            }
            if ($cents > $this->balanceCents($userId)) {
                throw new \InvalidArgumentException('amount_exceeds_balance');
            }
            $id = $this->insert($userId, $loanId, $type, -$cents, $text !== null ? self::clip($text) : null, $method, $staffId, $requestKey);
        });
        return (int) $id;
    }

    /**
     * The movement of $userId already written under $requestKey, if any.
     *
     * The key identifies one rendered form, so a replay carries the same
     * type, signed amount, payment method and text as the stored row. A key
     * sent again with different values (the page restored from the cache
     * and submitted with another amount) is not the same event: it is
     * refused as 'duplicate_request' instead of being answered with the
     * old movement as if the new one had been recorded.
     */
    private function replayedMovement(int $userId, ?string $requestKey, string $type, int $signedCents, ?string $method, ?string $text): ?int
    {
        if ($requestKey === null) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, tipo, importo, metodo_pagamento, descrizione FROM movimenti_lettore WHERE chiave_idempotenza = ? AND utente_id = ?');
        $stmt->bind_param('si', $requestKey, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $same = (string) $row['tipo'] === $type
            && FineParameters::decimalToCents($row['importo']) === $signedCents
            && (string) ($row['metodo_pagamento'] ?? '') === (string) ($method ?? '')
            && (string) ($row['descrizione'] ?? '') === (string) ($text ?? '');
        if (!$same) {
            throw new \InvalidArgumentException('duplicate_request');
        }
        return (int) $row['id'];
    }

    /** Serialise balance-dependent writes of one patron on the utenti row. */
    private function lockUser(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($found === null) {
            throw new \InvalidArgumentException('user_not_found');
        }
    }

    /**
     * Run $work in the caller's transaction, or in a new one when none is open.
     * An explicit begin_transaction() leaves @@autocommit at 1, so the check is
     * DbAtomic's (autocommit flag plus a SAVEPOINT probe): a waiver written
     * inside the return transaction must never commit that return halfway.
     *
     * @param callable():void $work
     */
    private function inTransaction(callable $work): void
    {
        DbAtomic::run($this->db, $work, 'PatronLedger');
    }

    /**
     * Insert one movement. Returns the new id, or null when the idempotency key
     * already exists (the event was recorded before).
     */
    private function insert(int $userId, ?int $loanId, string $type, int $cents, ?string $description, ?string $method, ?int $staffId, ?string $key): ?int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid_type');
        }
        $amount = FineParameters::centsToDecimal($cents);
        $now = DateHelper::now();
        $stmt = $this->db->prepare(
            'INSERT INTO movimenti_lettore (utente_id, prestito_id, tipo, importo, descrizione, metodo_pagamento, creato_da, created_at, chiave_idempotenza)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $stmt->bind_param('iissssiss', $userId, $loanId, $type, $amount, $description, $method, $staffId, $now, $key);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        return $affected === 1 ? $newId : null;
    }

    /**
     * Branch of the loaned copy (its branch closures do not count as late days).
     *
     * @param array<string, mixed> $loan
     */
    private static function branchOf(array $loan): ?int
    {
        return isset($loan['sede_id']) ? (int) $loan['sede_id'] : null;
    }

    /** @return array<string, mixed>|null */
    private function loanRow(int $loanId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.id, p.utente_id, p.stato, p.data_scadenza, p.data_restituzione, p.sanzione, c.sede_id
               FROM prestiti p LEFT JOIN copie c ON c.id = p.copia_id
              WHERE p.id = ?'
        );
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function reversalCount(int $loanId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM movimenti_lettore WHERE prestito_id = ? AND tipo = 'storno' AND chiave_idempotenza LIKE 'storno:late:%'");
        $stmt->bind_param('i', $loanId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $count;
    }

    /** late:<id> for the first return, late:<id>:<n> after n reversals. */
    private function lateKey(int $loanId): string
    {
        $reversals = $this->reversalCount($loanId);
        return 'late:' . $loanId . ($reversals > 0 ? ':' . ($reversals + 1) : '');
    }

    private static function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_substr($text, 0, 255);
    }

    /**
     * Readable description of a movement for lists, receipts and the API.
     *
     * @param array<string, mixed> $row
     */
    public static function describe(array $row): string
    {
        $type = (string) ($row['tipo'] ?? '');
        $text = (string) ($row['descrizione'] ?? '');
        if ($type === 'multa_ritardo' && preg_match('/^(\d+)\|/', $text, $m) === 1) {
            return sprintf(__('Multa per %d giorni di ritardo'), (int) $m[1]);
        }
        return $text;
    }

    /** Localised label of a movement type. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'multa_ritardo' => __('Multa per ritardo'),
            'addebito_perdita' => __('Addebito copia persa'),
            'addebito_danno' => __('Addebito copia danneggiata'),
            'addebito_manuale' => __('Addebito manuale'),
            'pagamento' => __('Pagamento'),
            'condono' => __('Condono'),
            'storno' => __('Storno'),
            default => $type,
        };
    }

    /** Localised label of a payment method. */
    public static function methodLabel(?string $method): string
    {
        return match ((string) $method) {
            'contanti' => __('Contanti'),
            'pos' => __('POS'),
            'bonifico' => __('Bonifico'),
            'altro' => __('Altro'),
            default => '',
        };
    }
}
