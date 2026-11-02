<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffSuppliersController;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * Suppliers (Pinakes 0.9.0, module `suppliers`): /staff/suppliers. The
 * supplier of a book is read and written through /staff/books
 * (`supplier_id`), the list of suppliers for its picker is in /staff/lookups.
 */
final class SuppliersArea implements StaffArea
{
    public static function area(): string
    {
        return 'suppliers';
    }

    public static function feature(): string
    {
        return 'suppliers';
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $c = static fn (\mysqli $db): StaffSuppliersController => new StaffSuppliersController($db);

        return [
            ['GET', '/staff/suppliers', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->list($q, $r)],
            ['POST', '/staff/suppliers', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->create($q, $r)],
            ['GET', '/staff/suppliers/{id:[0-9]+}', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->detail($q, $r, (int) $a['id'])],
            ['PUT', '/staff/suppliers/{id:[0-9]+}', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->update($q, $r, (int) $a['id'])],
            ['DELETE', '/staff/suppliers/{id:[0-9]+}', static fn (\mysqli $db, $q, $r, array $a) => $c($db)->delete($q, $r, (int) $a['id'])],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-suppliers';
        $doc['tags'][] = ['name' => $t, 'description' => 'Suppliers books are acquired from (Pinakes 0.9.0, module `suppliers`; 404 feature_disabled while it is off). Same rules as /admin/suppliers; catalogue permission.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_suppliers'] = ['type' => 'boolean', 'description' => 'The /staff/suppliers routes are available (module `suppliers` on). Mobile API 1.10.0.'];

        $s = O::nullable('string');
        $doc['components']['schemas']['Supplier'] = O::object([
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'contact_person' => $s, 'email' => $s, 'phone' => $s,
            'website' => $s, 'address' => $s, 'vat_number' => $s, 'notes' => $s,
            'book_count' => ['type' => 'integer', 'description' => 'Non-deleted books linked to the supplier.'],
            'created_at' => O::nullable('string', 'date-time'), 'updated_at' => O::nullable('string', 'date-time'),
            'can_edit' => ['type' => 'boolean'], 'can_delete' => ['type' => 'boolean'],
        ]);
        $doc['components']['schemas']['SupplierWrite'] = ['type' => 'object', 'description' => 'name is required on POST. On PUT an absent key keeps its value and null clears it. A website without scheme gets https://. Over-long values are refused (422), not cut.', 'properties' => [
            'name' => ['type' => 'string', 'maxLength' => 255], 'contact_person' => ['type' => ['string', 'null'], 'maxLength' => 150],
            'email' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 255], 'phone' => ['type' => ['string', 'null'], 'maxLength' => 30],
            'website' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'http(s) URL'], 'address' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            'vat_number' => ['type' => ['string', 'null'], 'maxLength' => 20], 'notes' => ['type' => ['string', 'null'], 'maxLength' => 5000],
        ]];
        $id = ['parameters' => [O::path('id', 'Supplier id')]];
        $invalid = ['422' => O::err('`validation`: meta.fields = {field: message} (name required, email / website not valid, value too long).')];
        $schema = ['409' => O::err('`schema_missing`: the 0.9.0 database update has not run yet.')];
        $idem = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Client key (1..128 of A-Z a-z 0-9 . _ : -) stable across retries: same key + same payload → 200 with the supplier first created (meta.replayed=true); same key + another payload → 422 `idempotency_conflict`.', 'schema' => ['type' => 'string', 'maxLength' => 128]];

        $doc['paths']['/staff/suppliers'] = [
            'get' => O::op($t, 'staffSuppliers', 'Every supplier with its book count (name order)', [
                '200' => O::ok('Suppliers; meta = {count, can_create}.', O::listOf('Supplier')),
            ] + $schema, ['parameters' => [O::query('q', 'string', 'Only names containing this text.')]]),
            'post' => O::op($t, 'staffCreateSupplier', 'Create a supplier', [
                '201' => O::ok('Created.', O::ref('Supplier')),
                '200' => O::ok('Replay of an Idempotency-Key (meta.replayed=true).', O::ref('Supplier')),
            ] + $invalid + $schema, ['parameters' => [$idem], 'requestBody' => O::body('SupplierWrite')]),
        ];
        $doc['paths']['/staff/suppliers/{id}'] = [
            'get' => O::op($t, 'staffSupplier', 'One supplier', ['200' => O::ok('Supplier.', O::ref('Supplier'))] + O::notFound() + $schema, $id),
            'put' => O::op($t, 'staffUpdateSupplier', 'Update a supplier', ['200' => O::ok('Updated.', O::ref('Supplier'))] + O::notFound() + $invalid + $schema, $id + ['requestBody' => O::body('SupplierWrite')]),
            'delete' => O::op($t, 'staffDeleteSupplier', 'Delete a supplier; its books lose the link', [
                '200' => O::ok('Deleted.', O::object(['id' => ['type' => 'integer'], 'deleted' => ['type' => 'boolean'], 'books_unlinked' => ['type' => 'integer']])),
            ] + O::notFound() + $schema, $id),
        ];

        return $doc;
    }
}
