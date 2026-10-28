<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;

/**
 * Error mapping for the delegated circulation actions (Mobile API 1.7.0).
 *
 * PrestitiController and ReservationsAdminController answer a form POST with a
 * 302 carrying `?error=<code>`; LoanApprovalController answers JSON with an
 * HTTP status. These tables turn each core code into the stable API envelope
 * code (frozen contract §2.1). A code that appears in none of them is a 500
 * `internal_error` with the core code in the log — tests/mobile-api-circulation.unit.php
 * pins every `?error=` literal of the core controllers against these tables,
 * so a new core code fails there instead of silently becoming a 500.
 *
 * Each entry: [http status, api code, meta.field|null, pass the core code as meta.reason].
 */
final class CirculationOutcome
{
    /** ① POST /staff/loans (PrestitiController::store). */
    public const CREATE = [
        'invalid_date_format'   => [422, 'validation_error', 'loaned_at', false],
        'invalid_dates'         => [422, 'validation_error', 'due_at', false],
        'missing_fields'        => [422, 'validation_error', 'user_id', false],
        'expired_window'        => [422, 'past_date', 'due_at', false],
        'book_not_found'        => [404, 'not_found', 'book_id', false],
        'user_not_found'        => [404, 'not_found', 'user_id', false],
        'copy_not_found'        => [404, 'not_found', 'copy_code', false],
        'copy_wrong_book'       => [422, 'validation_error', 'copy_code', false],
        'duplicate_reservation' => [409, 'duplicate', null, true],
        'user_suspended'        => [409, 'not_eligible', null, true],
        'card_expired'          => [409, 'not_eligible', null, true],
        'max_loans_reached'     => [409, 'max_loans_reached', null, false],
        'media_limit_reached'   => [409, 'media_limit_reached', null, false],
        'not_loanable'          => [409, 'not_loanable', null, false],
        'copy_not_available'    => [409, 'not_available', null, true],
        'no_copies_available'   => [409, 'not_available', null, true],
        'libro_in_prestito'     => [409, 'not_available', null, true],
        'duplicate_submission'  => [500, 'internal_error', null, false],
    ];

    /** ② PUT /staff/loans/{id} (PrestitiController::update). */
    public const UPDATE = self::CREATE + [
        'loan_closed'        => [409, 'loan_closed', null, false],
        'concurrent_retry'   => [409, 'concurrent_retry', null, false],
        'loan_copy_conflict' => [409, 'not_available', null, true],
        'loan_update_failed' => [500, 'internal_error', null, false],
    ];

    /** ④ return / return-by-code (PrestitiController::processReturn). */
    public const RETURN = [
        'invalid_status'   => [422, 'validation_error', 'outcome', false],
        'invalid_penalty'  => [422, 'validation_error', 'penalty', false],
        'not_returnable'   => [409, 'not_returnable', null, false],
        'concurrent_retry' => [409, 'concurrent_retry', null, false],
        'update_failed'    => [500, 'internal_error', null, false],
    ];

    /** ⑤ renew (PrestitiController::renew). */
    public const RENEW = [
        'invalid_date_format' => [422, 'validation_error', 'new_due_date', false],
        'invalid_renewal_date' => [422, 'validation_error', 'new_due_date', false],
        'loan_not_found'      => [404, 'not_found', null, false],
        'loan_not_active'     => [409, 'loan_not_active', null, true],
        'loan_not_picked_up'  => [409, 'loan_not_active', null, true],
        'loan_overdue'        => [409, 'loan_overdue', null, false],
        'max_renewals'        => [409, 'max_renewals', null, false],
        'extension_conflicts' => [409, 'not_available', null, true],
        'user_not_found'      => [409, 'not_eligible', null, true],
        'user_suspended'      => [409, 'not_eligible', null, true],
        'card_expired'        => [409, 'not_eligible', null, true],
        'renewal_failed'      => [500, 'internal_error', null, false],
        'book_not_found'      => [404, 'not_found', null, false],
    ];

    /** Bulk extend / bulk recall (PrestitiController::bulkExtend, bulkRecall). */
    public const BULK = [
        'bulk_extend_invalid'  => [422, 'validation_error', 'ids', true],
        'bulk_extend_conflict' => [409, 'not_available', null, true],
        'bulk_extend_failed'   => [500, 'internal_error', null, false],
        'bulk_recall_invalid'  => [422, 'validation_error', 'ids', true],
    ];

    /** ⑥ reservations (ReservationsAdminController::store/update). */
    public const RESERVATION = [
        'missing_data'         => [422, 'validation_error', 'user_id', false],
        'invalid_date'         => [422, 'validation_error', 'reserved_at', false],
        'invalid_status'       => [422, 'validation_error', 'status', false],
        'book_not_found'       => [404, 'not_found', 'book_id', false],
        'not_found'            => [404, 'not_found', null, false],
        'user_not_found'       => [404, 'not_found', 'user_id', false],
        'duplicate'            => [409, 'duplicate', null, false],
        'capacity_full'        => [409, 'not_available', null, true],
        'promoted_loan_active' => [409, 'conflict', null, true],
        'user_suspended'       => [409, 'not_eligible', null, true],
        'card_expired'         => [409, 'not_eligible', null, true],
        'not_loanable'         => [409, 'not_loanable', null, false],
        'reservations_disabled' => [409, 'reservations_disabled', null, false],
        'reservation_limit_reached' => [409, 'reservation_limit_reached', null, false],
        'save_failed'          => [500, 'internal_error', null, false],
        'update_failed'        => [500, 'internal_error', null, false],
    ];

    /** Copy states a return can leave the copy in (processReturn whitelist). */
    public const RETURN_OUTCOMES = ['restituito', 'manutenzione', 'in_restauro', 'perso', 'danneggiato'];

    private function __construct()
    {
    }

    /**
     * Error envelope for a core `?error=` code, using one of the tables above.
     *
     * @param array<string, array{0:int, 1:string, 2:?string, 3:bool}> $table
     * @param array<string, string> $details Redirect parameters beside the
     *        error code: a renewal or extension refused for a later commitment
     *        carries the longest renewal still possible (LoanWindow), surfaced
     *        as the message and as meta so the app can offer "renew until D".
     */
    public static function error(ResponseInterface $response, array $table, string $coreCode, string $context, array $details = []): ResponseInterface
    {
        $entry = $table[$coreCode] ?? null;
        if ($entry === null) {
            SecureLogger::error('[MobileApi] ' . $context . ': unmapped core outcome "' . $coreCode . '"');
            return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
        }
        [$status, $code, $field, $withReason] = $entry;
        if ($status >= 500) {
            SecureLogger::error('[MobileApi] ' . $context . ': core outcome "' . $coreCode . '"');
        }
        $meta = [];
        if ($field !== null) {
            $meta['field'] = $field;
        }
        if ($withReason) {
            $meta['reason'] = $coreCode;
        }
        $message = self::message($coreCode);
        // The loan that stopped a bulk extension is named even when no window can be
        // explained (OpenAPI documents meta.loan_id for bulk_extend_conflict).
        if (isset($details['loan_id']) && is_numeric($details['loan_id'])) {
            $meta['loan_id'] = (int) $details['loan_id'];
        }
        $explained = \App\Support\LoanWindow::explainRenewalFromParams($details);
        if ($explained !== null) {
            $message = $explained;
            $meta['max_extension_days'] = (int) $details['max_extension_days'];
            $meta['max_due_date'] = $details['max_due_date'];
            $meta['first_conflict_date'] = $details['blocked_from'];
        }

        return ResponseEnvelope::error($response, $code, $message, $status, $meta);
    }

    /**
     * ③ LoanApprovalController JSON passthrough: 400 / 409 → 409 `conflict`,
     * 403 → 409 `not_eligible`, anything else ≥ 300 → 500. The core message is
     * shown as is (its 400s are polymorphic: no copy, deadline passed, …).
     */
    public static function approvalError(ResponseInterface $response, int $coreStatus, string $coreMessage, string $context): ResponseInterface
    {
        $message = trim($coreMessage) !== '' ? $coreMessage : __('Operazione non consentita nello stato attuale.');
        if ($coreStatus === 400 || $coreStatus === 409) {
            return ResponseEnvelope::error($response, 'conflict', $message, 409);
        }
        if ($coreStatus === 403) {
            return ResponseEnvelope::error($response, 'not_eligible', $message, 409);
        }
        if ($coreStatus === 404) {
            return ResponseEnvelope::error($response, 'not_found', $message, 404);
        }
        SecureLogger::error('[MobileApi] ' . $context . ': core answered HTTP ' . $coreStatus);

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }

    /**
     * Presentation hint: the actions an operator can try on this loan. The
     * delegated controller remains the authority; the app only uses this to
     * decide which buttons to show.
     *
     * @param array<string, mixed> $row prestiti row (stato, data_prestito, data_scadenza, renewals, attivo)
     * @return list<string>
     */
    public static function actionsFor(array $row, int $maxRenewals, string $today): array
    {
        $status = (string) ($row['stato'] ?? '');
        $start = (string) ($row['data_prestito'] ?? '');
        $due = (string) ($row['data_scadenza'] ?? '');
        $renewals = (int) ($row['renewals'] ?? 0);

        switch ($status) {
            case 'pendente':
                return ['approve', 'reject', 'edit'];
            case 'da_ritirare':
                return ['confirm_pickup', 'cancel_pickup', 'edit', 'receipt'];
            case 'prenotato':
                if ($start !== '' && $start <= $today) {
                    return ['confirm_pickup', 'cancel_pickup', 'edit', 'receipt'];
                }
                return ['edit', 'receipt'];
            case 'in_corso':
                $actions = ['return'];
                if ($renewals < $maxRenewals && ($due === '' || $due >= $today)) {
                    $actions[] = 'renew';
                }
                return array_merge($actions, ['edit', 'receipt', 'email_receipt']);
            case 'in_ritardo':
                return ['return', 'edit', 'recall', 'receipt', 'email_receipt'];
            default:
                return ['receipt'];
        }
    }

    /**
     * Presentation hint for a reservation row.
     *
     * @return list<string>
     */
    public static function reservationActionsFor(string $status): array
    {
        return match ($status) {
            'attiva'    => ['edit', 'cancel'],
            'annullata' => ['reactivate'],
            default     => [],
        };
    }

    /** Client-safe message for a core outcome code. */
    public static function message(string $coreCode): string
    {
        if (in_array($coreCode, ['user_not_found', 'user_suspended', 'card_expired'], true)) {
            return \App\Support\LoanEligibility::errorMessage($coreCode);
        }

        return match ($coreCode) {
            'invalid_date_format', 'invalid_date' => __('Formato data non valido. Usa AAAA-MM-GG.'),
            'invalid_renewal_date'  => __('La data scelta non rientra nel periodo di rinnovo consentito. Aggiorna la pagina e riprova.'),
            'invalid_dates'         => __('La data di scadenza deve essere successiva alla data di inizio.'),
            'missing_fields', 'missing_data' => __('Utente e libro sono obbligatori.'),
            'expired_window'        => __('Il periodo indicato è già terminato.'),
            'book_not_found'        => __('Libro non trovato.'),
            'copy_not_found'        => __('Nessuna copia con questo codice.'),
            'copy_wrong_book'       => __('La copia indicata appartiene a un altro libro.'),
            'duplicate_reservation', 'duplicate' => __('Esiste già un prestito o una prenotazione attiva per questo utente e questo libro.'),
            'max_loans_reached'     => __('L\'utente ha raggiunto il numero massimo di prestiti attivi.'),
            'media_limit_reached'   => __('L\'utente ha raggiunto il numero massimo di prestiti per questo tipo di materiale.'),
            'not_loanable'          => __('Questo materiale è solo in consultazione per la tipologia del lettore.'),
            'reservations_disabled' => __('Le prenotazioni non sono consentite per la tipologia del lettore.'),
            'reservation_limit_reached' => __('Il lettore ha raggiunto il numero massimo di prenotazioni.'),
            'copy_not_available', 'no_copies_available', 'libro_in_prestito', 'loan_copy_conflict',
            'extension_conflicts', 'capacity_full', 'bulk_extend_conflict' => __('Nessuna copia disponibile per il periodo richiesto.'),
            'loan_closed'           => __('Il prestito è chiuso e non può essere modificato.'),
            'concurrent_retry'      => __('Operazione in conflitto con un\'altra in corso. Riprova.'),
            'invalid_status'        => __('Stato non valido.'),
            'invalid_penalty'       => __('Importo della sanzione non valido.'),
            'not_returnable'        => __('Il prestito non può essere restituito nello stato attuale.'),
            'loan_not_found', 'not_found' => __('Elemento non trovato.'),
            'loan_not_active', 'loan_not_picked_up' => __('Il prestito non è attivo.'),
            'loan_overdue'          => __('Un prestito scaduto non può essere rinnovato.'),
            'max_renewals'          => __('Numero massimo di rinnovi raggiunto.'),
            'promoted_loan_active'  => __('La prenotazione è già diventata un prestito attivo.'),
            'bulk_extend_invalid', 'bulk_recall_invalid' => __('Selezione non valida.'),
            default                 => __('Errore interno.'),
        };
    }
}
