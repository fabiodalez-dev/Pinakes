<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffPatronsController;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * Patron record tools: patron types (features.circulation_rules), card
 * renewal, library card PDFs (features.patron_cards) and the patron photo
 * (features.patron_photos). Each route carries its own module; the area has
 * none, so `staff_patrons` is true whenever this server serves the area and
 * the app reads the module flags (circulation_rules, patron_cards,
 * patron_photos) for the individual tools.
 */
final class PatronsArea implements StaffArea
{
    public static function area(): string
    {
        return 'patrons';
    }

    public static function feature(): ?string
    {
        return null;
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $c = static fn (\mysqli $db): StaffPatronsController => new StaffPatronsController($db);

        return [
            ['GET', '/staff/patron-types', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->patronTypes($q, $r), 'feature' => 'circulation_rules'],
            ['PUT', '/staff/users/{id:[0-9]+}/patron-type', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->updatePatronType($q, $r, (int) $a['id']), 'feature' => 'circulation_rules'],
            ['POST', '/staff/users/{id:[0-9]+}/renew-card', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->renewCard($q, $r, (int) $a['id'])],
            ['GET', '/staff/users/cards.pdf', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->bulkCardPdf($q, $r), 'feature' => 'patron_cards', 'limit' => [15, 60, 'staff_cards_pdf']],
            ['GET', '/staff/users/{id:[0-9]+}/card.pdf', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->cardPdf($q, $r, (int) $a['id']), 'feature' => 'patron_cards', 'limit' => [30, 60, 'staff_card_pdf']],
            ['GET', '/staff/users/{id:[0-9]+}/photo', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->photo($q, $r, (int) $a['id']), 'feature' => 'patron_photos'],
            ['POST', '/staff/users/{id:[0-9]+}/photo', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->uploadPhoto($q, $r, (int) $a['id']), 'feature' => 'patron_photos', 'limit' => [30, 60, 'staff_photo_upload']],
            ['DELETE', '/staff/users/{id:[0-9]+}/photo', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->deletePhoto($q, $r, (int) $a['id']), 'feature' => 'patron_photos'],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-patrons';
        $doc['tags'][] = ['name' => $t, 'description' => 'Patron record tools (Mobile API 1.10.0): patron types, card renewal, library card PDFs, patron photo. Each route answers 404 feature_disabled while its own module is off (circulation_rules, patron_cards, patron_photos).'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_patrons'] = [
            'type' => 'boolean', 'description' => 'The patron tools of Mobile API 1.10.0 are served. The individual tools follow the module flags circulation_rules (patron types), patron_cards (card PDFs) and patron_photos (photo); card renewal is always available.',
        ];

        $i = ['type' => 'integer'];
        $s = ['type' => 'string'];
        $b = ['type' => 'boolean'];
        $doc['components']['schemas']['PatronType'] = O::object([
            'id' => $i, 'code' => $s, 'name' => $s, 'description' => O::nullable('string'),
            'is_default' => ['type' => 'boolean', 'description' => 'Accounts without a type of their own follow this one.'],
            'active' => ['type' => 'boolean', 'description' => 'Inactive types cannot be assigned anew; an account keeps the one it has.'],
            'card_duration_days' => ['type' => ['integer', 'null'], 'description' => 'Card validity the type sets (null = none: renewal uses 365 days).'],
        ]);
        $userId = O::path('id', 'User id');
        $user = O::ok('The user as it is now (StaffUser, with the 1.10.0 patron fields).', O::ref('StaffUser'));
        $format = O::query('format', 'string', 'single (one card per page) | sheet (A4 sheet); default: the library card settings.');
        $photoState = O::ok('Photo state after the change.', O::object([
            'user_id' => $i, 'has_photo' => $b, 'photo_url' => ['type' => ['string', 'null'], 'description' => 'Authorised GET /staff/users/{id}/photo URL (send the bearer token), versioned.'],
        ]));

        $doc['paths']['/staff/patron-types'] = ['get' => O::op($t, 'staffPatronTypes', 'Patron types for pickers (circulation_rules)', [
            '200' => O::ok('Every type in display order; meta = {count, default_id, can_assign}.', O::listOf('PatronType')),
        ])];
        $doc['paths']['/staff/users/{id}/patron-type'] = ['put' => O::op($t, 'staffSetPatronType', 'Assign a patron type (circulation_rules)', [
            '200' => array_merge($user, ['description' => 'Updated user; meta.card_expiry_recomputed tells whether the card expiry was set from the type.']),
            '403' => O::err('`not_permitted`: the web editor rule — an administrator edits every account, a Staff account only its own (StaffUser.can_edit).'),
            '422' => O::err('`validation` (meta.field patron_type_id: unknown or deactivated type; recompute_card_expiry).'),
        ] + O::notFound(), [
            'parameters' => [$userId],
            'requestBody' => O::inlineBody([
                'patron_type_id' => ['type' => ['integer', 'null'], 'description' => 'null (or 0) = the default type. An active type, or the one the account already has.'],
                'recompute_card_expiry' => ['type' => 'boolean', 'default' => false, 'description' => 'Reader accounts: set the card expiry to today + the type\'s card duration (the web form\'s "compute from type"); no change when the type sets none or automatic_card_expiry is off.'],
            ], ['patron_type_id']),
        ])];
        $doc['paths']['/staff/users/{id}/renew-card'] = ['post' => O::op($t, 'staffRenewCard', 'Renew the library card', [
            '201' => O::ok('Renewed: card_expires_at = base + the type\'s card duration (365 days when none; base = today or the current expiry per Settings → Loans); an expired account becomes active.', O::ref('StaffUser')),
            '200' => O::ok('Replay of the same Idempotency-Key (meta.replayed=true): nothing renewed twice.', O::ref('StaffUser')),
            '409' => O::err('`card_not_renewable`: the account has no card (administrator / staff); `concurrent_retry`.'),
            '422' => O::err('`validation` (meta.field idempotency_key: malformed key).'),
        ] + O::notFound(), ['parameters' => [$userId, ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
            'description' => 'Optional; recommended when the library renews from the current expiry (a retried renewal would otherwise add the duration twice).',
            'schema' => ['type' => 'string', 'maxLength' => 128]]]])];
        $doc['paths']['/staff/users/cards.pdf'] = ['get' => O::op($t, 'staffCardsPdf', 'Library cards of several users in one PDF (patron_cards, 15/min)', [
            '200' => O::file('application/pdf', 'Content-Disposition: attachment; filename="tessere.pdf".'),
            '422' => O::err('`validation` (meta.field ids: empty, malformed or more than 500; format).'),
        ] + O::notFound(), ['parameters' => [O::query('ids', 'string', 'Comma-separated user ids, at most 500.', true), $format]])];
        $doc['paths']['/staff/users/{id}/card.pdf'] = ['get' => O::op($t, 'staffCardPdf', 'Library card PDF of a user (patron_cards, 30/min)', [
            '200' => O::file('application/pdf', 'Content-Disposition: attachment; filename="tessera_{card number}.pdf".'),
        ] + O::notFound() + O::invalid(), ['parameters' => [$userId, $format]])];
        $doc['paths']['/staff/users/{id}/photo'] = [
            'get' => O::op($t, 'staffUserPhoto', 'The patron photo (patron_photos)', [
                '200' => O::file('image/jpeg', 'JPEG, at most 600 px on the longer side. Cache-Control: private, no-store.'),
            ] + O::notFound(), ['parameters' => [$userId], 'description' => '404 not_found when the user has no photo.']),
            'post' => O::op($t, 'staffUploadUserPhoto', 'Set the patron photo (patron_photos, 30/min)', [
                '200' => $photoState,
                '403' => O::err('`not_permitted`: same rule as the web editor (StaffUser.can_edit).'),
                '422' => O::err('`validation` (meta.field photo): missing, larger than 5 MB, or not a JPEG/PNG/WebP image.'),
            ] + O::notFound(), [
                'parameters' => [$userId],
                'requestBody' => O::multipart(['photo' => ['type' => 'string', 'format' => 'binary', 'description' => 'JPEG, PNG or WebP, at most 5 MB; re-encoded as a JPEG of at most 600 px, metadata removed.']], ['photo']),
            ]),
            'delete' => O::op($t, 'staffDeleteUserPhoto', 'Remove the patron photo (patron_photos)', [
                '200' => $photoState,
                '403' => O::err('`not_permitted`: same rule as the web editor (StaffUser.can_edit).'),
            ] + O::notFound(), ['parameters' => [$userId], 'description' => 'Idempotent: removing a photo that is not there answers 200 has_photo=false.']),
        ];

        return $doc;
    }
}
