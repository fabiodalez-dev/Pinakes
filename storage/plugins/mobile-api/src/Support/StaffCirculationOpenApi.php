<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragment for the Mobile API 1.7.0 staff circulation desk (tag
 * `staff-circulation`). Every path here has a row in
 * tests/mobile-api-idempotency.spec.js (its guard fails otherwise).
 */
final class StaffCirculationOpenApi
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extend(array $doc): array
    {
        $doc['tags'][] = ['name' => 'staff-circulation', 'description' => 'Circulation desk for admin and staff accounts: loans, returns, renewals, reservations (404 feature_disabled in catalogue mode).'];

        $features = &$doc['components']['schemas']['HealthPayload']['properties']['features']['properties'];
        foreach ([
            'staff_circulation'     => 'The /staff circulation routes are available (false in catalogue mode).',
            'staff_copies'          => 'Per-copy management (/staff/copies/*).',
            'staff_series'          => 'Series management (/staff/series*); false on installs without the collane table.',
            'staff_merges'          => 'Author / publisher / genre merges.',
            'staff_labels'          => 'Label PDFs and label settings.',
            'staff_imports'         => 'CSV import jobs (/staff/imports*).',
            'staff_covers'          => 'Missing covers and bulk enrichment.',
            'staff_desiderata'      => 'Desiderata staff workflow (plugin active).',
            'staff_archives'        => 'Archives editing (plugin active).',
            'staff_periodicals'     => 'Periodicals desk bridge (emeroteca active).',
            'staff_bookclub'        => 'Book club staff bridge (book-club active).',
            'staff_frbr'            => 'FRBR works (frbr-lrm active).',
            'staff_digital_library' => 'Digital attachments upload (digital-library active).',
            'staff_librarything'    => 'LibraryThing TSV import (librarything plugin active).',
        ] as $flag => $description) {
            $features[$flag] = ['type' => 'boolean', 'description' => $description
                . ' True only when this server registers the area\'s routes and the prerequisite holds; false while the area is not available on this build. Omitted by servers older than Mobile API 1.7.0.'];
        }
        unset($features);

        if (!StaffAreas::implemented('circulation')) {
            return $doc;
        }
        foreach (self::schemas() as $name => $schema) {
            $doc['components']['schemas'][$name] = $schema;
        }
        foreach (self::paths() as $path => $operations) {
            $doc['paths'][$path] = $operations;
        }

        return $doc;
    }

    /** @return array<string, mixed> */
    private static function paths(): array
    {
        $t = 'staff-circulation';
        $loanId = ['parameters' => [O::path('id', 'Loan id')]];
        $loan = O::ok('The loan as it is now.', O::ref('StaffLoan'));
        $approvalErrors = O::notFound() + O::conflict('`conflict` (state changed, no copy, deadline passed — `message` is the server text) or `not_eligible` (user not eligible).');
        $returnBody = ['outcome' => ['type' => 'string', 'enum' => CirculationOutcome::RETURN_OUTCOMES, 'default' => 'restituito'],
            'notes' => ['type' => 'string'], 'penalty' => ['type' => 'number', 'minimum' => 0, 'maximum' => 99999999.99, 'description' => 'Only for perso / danneggiato.']];
        $returnErrors = O::invalid() + O::conflict('`not_returnable`, `concurrent_retry` (retry once).');
        $reason = O::inlineBody(['reason' => ['type' => 'string', 'maxLength' => 500]]);
        $idemHeader = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
            'description' => 'Client-generated key (1..128 of A-Z a-z 0-9 . _ : -), stable across retries of the same create. Also accepted as body `idempotency_key`. Remembered 24 h per operator.',
            'schema' => ['type' => 'string', 'maxLength' => 128]];
        $idemResponses = [
            '200' => O::ok('Replay: this key already created the resource with the same payload; it is returned as it is now (meta.replayed=true), nothing new is created.'),
            '422' => O::err('`validation_error` (meta.field) or `idempotency_conflict`: the key was already used with a different payload.'),
        ];
        $idemDescription = ' Send an Idempotency-Key so a retry after a timeout never creates a second record: same key + same payload → 200 with the original (meta.replayed=true); same key + different payload → 422 `idempotency_conflict`; a concurrent duplicate waits for the first and then replays. 409 `concurrent_retry` when the server could not take the lock in time (retry).';
        $sent = O::ok('Send outcome; an SMTP failure is still 200 with sent=false.', O::object(['sent' => ['type' => 'boolean'], 'message' => ['type' => 'string']]));

        return [
            '/staff/circulation/summary' => ['get' => O::op($t, 'staffCirculationSummary', 'Desk counters and loan settings (Cache-Control: no-store)', [
                '200' => O::ok('Counters.', O::ref('CirculationSummary')),
            ])],
            '/staff/loans' => [
                'get' => O::op($t, 'staffLoans', 'Loans by desk bucket', [
                    '200' => O::ok('Page of loans; meta = {count, limit, next_cursor, has_more, total_count}.', O::listOf('StaffLoan')),
                ] + O::invalid(), ['parameters' => array_merge([
                    O::query('bucket', 'string', 'pending|pickup|scheduled|active|overdue|history|all (default all).'),
                    O::query('q', 'string', 'Title, user name / email / card, or copy code.'),
                    O::query('user_id', 'integer', 'Only this user.'),
                    O::query('book_id', 'integer', 'Only this title.'),
                    O::query('from', 'string', 'data_prestito >= (Y-m-d).'),
                    O::query('to', 'string', 'data_prestito <= (Y-m-d).'),
                ], O::paging())]),
                'post' => O::op($t, 'staffCreateLoan', 'Create a loan through the admin loan form', [
                    '201' => O::ok('Created.', O::ref('StaffLoan')),
                ] + $idemResponses + O::notFound() + O::conflict('`duplicate`, `not_eligible` (meta.reason), `max_loans_reached`, `not_available` (meta.reason), `concurrent_retry`.'), [
                    'parameters'  => [$idemHeader],
                    'requestBody' => O::body('LoanCreate'),
                    'description' => 'immediate=true hands the copy over now (in_corso); otherwise a loan starting today waits for pickup (da_ritirare) and a future one is scheduled (prenotato). 422 `past_date` when the whole window is over.' . $idemDescription,
                ]),
            ],
            '/staff/loans/return-by-code' => ['post' => O::op($t, 'staffReturnByCode', 'Return the active loan of a scanned copy code', [
                '200' => $loan,
            ] + O::notFound() + $returnErrors + ['409' => O::err('`ambiguous` (several active loans for the code), `not_returnable`, `concurrent_retry`.')], [
                'requestBody' => O::inlineBody(['code' => ['type' => 'string', 'maxLength' => 100]] + $returnBody, ['code']),
            ])],
            '/staff/loans/bulk-extend' => ['post' => O::op($t, 'staffBulkExtend', 'Push the due date of several out-loans by N days', [
                '200' => O::ok('Extended.', O::object(['extended' => ['type' => 'integer'], 'days' => ['type' => 'integer']])),
            ] + O::invalid() + O::conflict('`not_available`: a capacity conflict rolled back the whole batch. meta names the loan that stopped it (`loan_id`) and, when a later commitment bounds it, the longest extension still possible (`max_extension_days`, `max_due_date`, `first_conflict_date`, Mobile API 1.8.2).'), [
                'requestBody' => O::inlineBody(['ids' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'integer']], 'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365]], ['ids', 'days']),
            ])],
            '/staff/loans/bulk-recall' => ['post' => O::op($t, 'staffBulkRecall', 'Send the overdue reminder to several loans (5/min)', [
                '200' => O::ok('Outcome.', O::object(['sent' => ['type' => 'integer'], 'skipped' => ['type' => 'integer']])),
            ] + O::invalid(), [
                'requestBody' => O::inlineBody(['ids' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'integer']]], ['ids']),
            ])],
            '/staff/loans/{id}' => [
                'get' => O::op($t, 'staffLoan', 'One loan', ['200' => $loan] + O::notFound(), $loanId),
                'put' => O::op($t, 'staffUpdateLoan', 'Edit borrower or dates (merged over the current row)', [
                    '200' => $loan,
                ] + O::notFound() + O::invalid() + O::conflict('`loan_closed`, `concurrent_retry`, `not_available`, `not_eligible`, `duplicate`, `max_loans_reached`.'), $loanId + [
                    'requestBody' => O::inlineBody(['user_id' => ['type' => 'integer'], 'loaned_at' => ['type' => 'string', 'format' => 'date'], 'due_at' => ['type' => 'string', 'format' => 'date']]),
                ]),
            ],
            '/staff/loans/{id}/approve' => ['post' => O::op($t, 'staffApproveLoan', 'Approve a pending request', ['200' => $loan] + $approvalErrors, $loanId)],
            '/staff/loans/{id}/reject' => ['post' => O::op($t, 'staffRejectLoan', 'Reject a pending request', ['200' => $loan] + $approvalErrors, $loanId + ['requestBody' => $reason])],
            '/staff/loans/{id}/pickup' => ['post' => O::op($t, 'staffConfirmPickup', 'Confirm the pickup (copy handed over)', ['200' => $loan] + $approvalErrors, $loanId)],
            '/staff/loans/{id}/cancel-pickup' => ['post' => O::op($t, 'staffCancelPickup', 'Cancel a loan waiting for pickup', ['200' => $loan] + $approvalErrors, $loanId + ['requestBody' => $reason])],
            '/staff/loans/{id}/return' => ['post' => O::op($t, 'staffReturnLoan', 'Record the return with the copy outcome', ['200' => $loan] + O::notFound() + $returnErrors, $loanId + [
                'requestBody' => O::inlineBody($returnBody),
            ])],
            '/staff/loans/{id}/renew' => ['post' => O::op($t, 'staffRenewLoan', 'Renew by the configured loan duration, or until an earlier date', [
                '200' => $loan,
            ] + O::notFound() + O::invalid() + O::conflict('`loan_not_active`, `loan_overdue`, `max_renewals`, `not_available`, `not_eligible`. For `not_available`, when a later commitment bounds the renewal, the message names the longest renewal still possible and meta carries `max_extension_days`, `max_due_date` and `first_conflict_date` (Mobile API 1.8.2): send that date as `new_due_date` to renew until it.'), $loanId + [
                'requestBody' => O::inlineBody(['new_due_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Optional. Renew until this day instead of the configured duration: after the current due date, not past the standard renewal.']], []),
            ])],
            '/staff/loans/{id}/recall' => ['post' => O::op($t, 'staffRecallLoan', 'Send the overdue reminder now (10/min)', ['200' => $sent] + O::notFound(), $loanId)],
            '/staff/loans/{id}/receipt.pdf' => ['get' => O::op($t, 'staffLoanReceipt', 'Loan receipt PDF (30/min)', [
                '200' => O::file('application/pdf', 'Content-Disposition: attachment; filename="prestito_{id}_{Ymd}.pdf".'),
            ] + O::notFound(), $loanId)],
            '/staff/loans/{id}/receipt/email' => ['post' => O::op($t, 'staffEmailReceipt', 'Email the receipt PDF to the borrower (10/min)', ['200' => $sent] + O::notFound(), $loanId)],
            '/staff/reservations' => [
                'get' => O::op($t, 'staffReservations', 'Reservations (queue order)', [
                    '200' => O::ok('Page of reservations.', O::listOf('StaffReservation')),
                ] + O::invalid(), ['parameters' => array_merge([
                    O::query('status', 'string', 'attiva|completata|annullata|all (default attiva).'),
                    O::query('q', 'string', 'Title or user.'),
                    O::query('book_id', 'integer', 'Only this title.'),
                    O::query('user_id', 'integer', 'Only this user.'),
                ], O::paging())]),
                'post' => O::op($t, 'staffCreateReservation', 'Queue a reservation for a user', [
                    '201' => O::ok('Created.', O::ref('StaffReservation')),
                ] + $idemResponses + O::notFound() + O::conflict('`duplicate`, `not_available` (capacity), `not_eligible`, `concurrent_retry`.'), [
                    'parameters' => [$idemHeader], 'requestBody' => O::body('ReservationWrite'), 'description' => trim($idemDescription),
                ]),
            ],
            '/staff/reservations/{id}' => [
                'get' => O::op($t, 'staffReservation', 'One reservation', ['200' => O::ok('Reservation.', O::ref('StaffReservation'))] + O::notFound(), ['parameters' => [O::path('id', 'Reservation id')]]),
                'put' => O::op($t, 'staffUpdateReservation', 'Edit dates or status (merged); status attiva on a cancelled row reactivates it', [
                    '200' => O::ok('Updated.', O::ref('StaffReservation')),
                ] + O::notFound() + O::invalid() + O::conflict('`duplicate`, `not_available`, `not_eligible`, `conflict` (meta.reason promoted_loan_active).'), [
                    'parameters' => [O::path('id', 'Reservation id')], 'requestBody' => O::body('ReservationWrite'),
                ]),
            ],
            '/staff/reservations/{id}/cancel' => ['post' => O::op($t, 'staffCancelReservation', 'Cancel an active reservation (the user is emailed)', [
                '200' => O::ok('Cancelled.', O::ref('StaffReservation')),
            ] + $approvalErrors, ['parameters' => [O::path('id', 'Reservation id')], 'requestBody' => $reason])],
            '/staff/books/{id}/queue' => ['get' => O::op($t, 'staffBookQueue', 'Reservations, open loans and copies of a title', [
                '200' => O::ok('Queue.', O::object([
                    'reservations' => O::listOf('StaffReservation'), 'loans' => O::listOf('StaffLoan'), 'copies' => O::listOf('StaffCopy'),
                ])),
            ] + O::notFound(), ['parameters' => [O::path('id', 'Book id')]])],
            '/staff/books/{id}/availability' => ['get' => O::op($t, 'staffBookAvailability', 'Availability calendar of a title for a given borrower', [
                '200' => O::ok('Same shape as /catalog/books/{id}/availability.'),
            ] + O::notFound() + O::invalid(), ['parameters' => [
                O::path('id', 'Book id'), O::query('user_id', 'integer', 'Borrower whose own commitments are excluded.'), O::query('days', 'integer', '1..365, default 180.'),
            ]])],
            '/staff/users' => ['get' => O::op($t, 'staffUsers', 'Search borrowers (name, email, card, phone)', [
                '200' => O::ok('Users.', O::listOf('StaffUser')),
            ] + O::invalid(), ['parameters' => [O::query('q', 'string', 'At least 2 characters.', true), O::query('limit', 'integer', '1..50, default 20.')]])],
            '/staff/users/{id}' => ['get' => O::op($t, 'staffUser', 'A borrower with open loans and active reservations', [
                '200' => O::ok('User.', ['allOf' => [O::ref('StaffUser'), O::object(['loans' => O::listOf('StaffLoan'), 'reservations' => O::listOf('StaffReservation')])]]),
            ] + O::notFound(), ['parameters' => [O::path('id', 'User id')]])],
            '/staff/copies/by-code' => ['get' => O::op($t, 'staffCopyByCode', 'Resolve a scanned copy code (60/min)', [
                '200' => O::ok('found=false → copy=null.', O::object([
                    'found' => ['type' => 'boolean'],
                    'copy'  => ['oneOf' => [['type' => 'null'], ['allOf' => [O::ref('StaffCopy'), O::object([
                        'available' => ['type' => 'boolean'], 'loan' => ['oneOf' => [['type' => 'null'], O::ref('StaffLoan')]],
                    ])]]]],
                ])),
            ] + O::invalid(), ['parameters' => [O::query('code', 'string', 'Inventory number (the Code128 on the copy label).', true)]])],
        ];
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $s = ['type' => 'string'];
        $ns = O::nullable('string');
        $nd = O::nullable('string', 'date');
        $i = ['type' => 'integer'];
        $b = ['type' => 'boolean'];
        $person = O::object(['id' => $i, 'name' => $s, 'email' => $s, 'card_number' => $ns, 'status' => $s]);

        return [
            'CirculationSummary' => O::object([
                'pending' => $i, 'pickup_ready' => $i, 'scheduled' => $i, 'active' => $i, 'overdue' => $i,
                'reservations' => $i, 'due_today' => $i, 'returned_today' => $i, 'today' => ['type' => 'string', 'format' => 'date'],
                'settings' => O::object(['loan_duration_days' => $i, 'pickup_expiry_days' => $i, 'max_renewals' => $i,
                    'max_active_loans_per_user' => $i, 'allow_multiple_loans_same_book' => $b]),
            ]),
            'StaffLoan' => O::object([
                'id' => $i,
                'status' => ['type' => 'string', 'enum' => ['pendente', 'prenotato', 'da_ritirare', 'in_corso', 'restituito', 'in_ritardo', 'perso', 'danneggiato', 'annullato', 'scaduto']],
                'status_label' => $s,
                'origin' => ['type' => 'string', 'enum' => ['richiesta', 'prenotazione', 'diretto', 'ncip']],
                'book' => O::object(['id' => $i, 'title' => $s, 'cover_url' => $ns, 'isbn13' => $ns]),
                'copy' => ['oneOf' => [['type' => 'null'], O::object(['id' => $i, 'inventory_number' => $s, 'status' => $s])]],
                'user' => $person,
                'processed_by' => ['oneOf' => [['type' => 'null'], O::object(['id' => $i, 'name' => $s])]],
                'requested_at' => $nd, 'loaned_at' => $nd, 'due_at' => $nd, 'pickup_deadline' => $nd, 'returned_at' => $nd,
                'returned_late' => $b, 'days_overdue' => $i, 'renewals' => $i, 'max_renewals' => $i,
                'penalty' => O::nullable('number'), 'notes' => $ns, 'recall_count' => $i, 'last_recall_at' => $ns,
                'created_at' => $ns, 'updated_at' => $ns,
                'actions' => ['type' => 'array', 'description' => 'Presentation hint; the server re-checks every action. Unknown values must be ignored.',
                    'items' => ['type' => 'string', 'enum' => ['approve', 'reject', 'confirm_pickup', 'cancel_pickup', 'return', 'renew', 'edit', 'recall', 'receipt', 'email_receipt']]],
            ]),
            'StaffReservation' => O::object([
                'id' => $i, 'status' => ['type' => 'string', 'enum' => ['attiva', 'completata', 'annullata']], 'status_label' => $s,
                'book' => O::object(['id' => $i, 'title' => $s, 'cover_url' => $ns]), 'user' => $person,
                'queue_position' => O::nullable('integer'), 'reserved_at' => $nd, 'expires_at' => $nd,
                'requested_from' => $nd, 'requested_to' => $nd, 'notified' => $b, 'created_at' => $ns,
                'actions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['edit', 'cancel', 'reactivate']]],
            ]),
            'StaffUser' => O::object([
                'id' => $i, 'name' => $s, 'email' => $s, 'card_number' => $ns,
                'status' => ['type' => 'string', 'enum' => ['attivo', 'sospeso', 'scaduto']], 'card_expires_at' => $nd, 'role' => $s,
                'active_loans' => $i, 'active_reservations' => $i, 'eligible' => $b,
                'eligibility_reason' => ['type' => ['string', 'null'], 'description' => 'user_suspended | card_expired | debt_block | …'],
            ]),
            'StaffCopy' => O::object([
                'id' => $i, 'inventory_number' => $s, 'status' => $s, 'status_label' => $s, 'notes' => $ns,
                'book' => O::object(['id' => $i, 'title' => $s, 'cover_url' => $ns]),
                'held' => $b, 'deletable' => $b,
                'consultation_only' => ['type' => 'boolean', 'description' => 'Kept for in-house consultation, never lent (Pinakes 0.9.0). False with features.consultation_only off.'],
                'on_loan' => ['oneOf' => [['type' => 'null'], O::object(['loan_id' => $i, 'user_name' => $s, 'due_at' => $nd])]],
                'created_at' => $ns, 'updated_at' => $ns,
            ]),
            'LoanCreate' => ['type' => 'object', 'required' => ['user_id', 'book_id'], 'properties' => [
                'user_id' => $i, 'book_id' => $i, 'copy_code' => ['type' => 'string', 'maxLength' => 100],
                'loaned_at' => ['type' => 'string', 'format' => 'date'], 'due_at' => ['type' => 'string', 'format' => 'date'],
                'notes' => $s, 'immediate' => ['type' => 'boolean', 'default' => false],
                'idempotency_key' => ['type' => 'string', 'maxLength' => 128, 'description' => 'Alternative to the Idempotency-Key header.'],
            ]],
            'ReservationWrite' => O::object([
                'user_id' => $i, 'book_id' => $i, 'status' => ['type' => 'string', 'enum' => ['attiva', 'completata', 'annullata'], 'description' => 'PUT only.'],
                'reserved_at' => ['type' => 'string', 'format' => 'date'], 'expires_at' => ['type' => 'string', 'format' => 'date'],
                'requested_from' => ['type' => 'string', 'format' => 'date'], 'requested_to' => ['type' => 'string', 'format' => 'date'],
                'idempotency_key' => ['type' => 'string', 'maxLength' => 128, 'description' => 'POST only; alternative to the Idempotency-Key header.'],
            ]),
        ];
    }
}
