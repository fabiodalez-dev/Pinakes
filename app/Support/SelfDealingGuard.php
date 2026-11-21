<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Separation of duties at the loan desk.
 *
 * An operator who is not an administrator (Staff, or a reader account holding a
 * custom role with loans.manage) must not record circulation or ledger writes
 * whose borrower is their own account: approving or rejecting their own request,
 * creating a loan or a reservation for themselves, recording pickup, return,
 * renewal or extension of their own loan, or moving money on their own ledger
 * (payment, charge, waiver). Administrators are exempt.
 *
 * Only the operator paths call this guard. What a reader may do on their own
 * account from the user area (request a loan, cancel their reservation, an
 * automatic approval of their own request) never reaches it.
 *
 * The decision is taken on the identity in the session, which the authorising
 * middleware revalidates against the database on every backend request
 * (SessionRoleRevalidator), and on the target row read by the caller.
 */
final class SelfDealingGuard
{
    /** Error code carried by redirects (?error=), ledger redirects and API envelopes. */
    public const ERROR = 'self_dealing';

    private function __construct()
    {
    }

    /**
     * Whether $operator acting on $targetUserId's account is self-dealing.
     *
     * @param array<string, mixed>|null $operator defaults to the signed-in user
     */
    public static function blocks(int $targetUserId, ?array $operator = null): bool
    {
        $operator ??= (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : [];
        if (($operator['tipo_utente'] ?? '') === 'admin') {
            return false;
        }
        $operatorId = is_numeric($operator['id'] ?? null) ? (int) $operator['id'] : 0;
        return $operatorId > 0 && $targetUserId > 0 && $operatorId === $targetUserId;
    }

    /**
     * Throwing form for service-style callers.
     *
     * @param array<string, mixed>|null $operator
     * @throws SelfDealingException
     */
    public static function assertNotSelf(int $targetUserId, ?array $operator = null): void
    {
        if (self::blocks($targetUserId, $operator)) {
            throw new SelfDealingException(self::message());
        }
    }

    /**
     * Whether the borrower of loan $loanId is the operator. A loan that cannot be
     * read is not judged here: the caller's own lookup answers "not found".
     *
     * @param array<string, mixed>|null $operator
     */
    public static function blocksLoan(\mysqli $db, int $loanId, ?array $operator = null): bool
    {
        return self::blocksRow($db, 'SELECT utente_id FROM prestiti WHERE id = ?', $loanId, $operator);
    }

    /**
     * Whether the holder of reservation $reservationId is the operator.
     *
     * @param array<string, mixed>|null $operator
     */
    public static function blocksReservation(\mysqli $db, int $reservationId, ?array $operator = null): bool
    {
        return self::blocksRow($db, 'SELECT utente_id FROM prenotazioni WHERE id = ?', $reservationId, $operator);
    }

    /** Operator-facing explanation shown by every guarded path. */
    public static function message(): string
    {
        return __('Non puoi gestire i prestiti del tuo stesso account: chiedi a un altro operatore o a un amministratore.');
    }

    /** @param array<string, mixed>|null $operator */
    private static function blocksRow(\mysqli $db, string $sql, int $id, ?array $operator): bool
    {
        $operator ??= (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : [];
        // Cheap exits first: no query for an administrator or an anonymous context.
        if (($operator['tipo_utente'] ?? '') === 'admin' || !is_numeric($operator['id'] ?? null) || $id <= 0) {
            return false;
        }
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return is_array($row) && self::blocks((int) ($row[0] ?? 0), $operator);
    }
}
