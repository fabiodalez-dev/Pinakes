<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffCatalogueCardsController;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * ISBD catalogue cards (Pinakes 0.9.0, module `catalogue_cards`) as PDF.
 */
final class CatalogueCardsArea implements StaffArea
{
    public static function area(): string
    {
        return 'catalogue_cards';
    }

    public static function feature(): string
    {
        return 'catalogue_cards';
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        return [
            ['GET', '/staff/books/{id:[0-9]+}/catalogue-card.pdf', static fn (\mysqli $db, $q, $r, array $a) => (new StaffCatalogueCardsController($db))->single($q, $r, (int) $a['id']), 'limit' => [30, 60, 'staff_catalogue_card']],
            ['GET', '/staff/books/catalogue-cards.pdf', static fn (\mysqli $db, $q, $r, array $a) => (new StaffCatalogueCardsController($db))->bulk($q, $r), 'limit' => [30, 60, 'staff_catalogue_card']],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-catalogue-cards';
        $doc['tags'][] = ['name' => $t, 'description' => 'ISBD catalogue cards, 125 × 75 mm, as PDF (Pinakes 0.9.0, module `catalogue_cards`; 404 feature_disabled while it is off). Catalogue permission; 30 requests per minute, as on the web.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_catalogue_cards'] = ['type' => 'boolean', 'description' => 'The catalogue card PDFs are available (module `catalogue_cards` on). Mobile API 1.10.0.'];
        $pdf = O::file('application/pdf', 'The PDF, as an attachment (Content-Disposition carries the file name).');

        $doc['paths']['/staff/books/{id}/catalogue-card.pdf'] = ['get' => O::op($t, 'staffCatalogueCard', 'The catalogue card of one book', [
            '200' => $pdf,
        ] + O::notFound(), ['parameters' => [O::path('id', 'Book id')], 'description' => 'File name scheda_{id}.pdf. 404 not_found for a missing or deleted book.'])];
        $doc['paths']['/staff/books/catalogue-cards.pdf'] = ['get' => O::op($t, 'staffCatalogueCards', 'The catalogue cards of several books, one PDF', [
            '200' => $pdf,
            '422' => O::err('`validation`: no valid id in `ids` (meta.fields.ids).'),
        ] + O::notFound(), [
            'parameters' => [O::query('ids', 'string', 'Comma-separated book ids, printed in this order; duplicates and non-ids are dropped and only the first 200 are printed (web rule).', true)],
            'description' => 'File name schede_catalografiche.pdf. Deleted or missing ids are skipped; 404 not_found when none is left.',
        ])];

        return $doc;
    }
}
