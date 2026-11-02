<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Services\FineParameters;
use App\Services\PatronLedger;

/**
 * JSON shape of one patron-ledger movement (movimenti_lettore row from
 * PatronLedger::movements()).
 *
 * item() is the shape the reader already receives from GET /me/movements;
 * staffItem() is the same object plus the operator-only fields, so the app
 * decodes both lists with one model.
 */
final class LedgerMovementPayload
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function item(array $row): array
    {
        $cents = FineParameters::decimalToCents($row['importo'] ?? 0);

        return [
            'id'             => (int) $row['id'],
            'type'           => (string) $row['tipo'],
            'type_label'     => PatronLedger::typeLabel((string) $row['tipo']),
            'amount_cents'   => $cents,
            'amount'         => FineParameters::centsToDecimal($cents),
            'description'    => PatronLedger::describe($row),
            'payment_method' => self::nullableString($row['metodo_pagamento'] ?? null),
            'loan_id'        => isset($row['prestito_id']) ? (int) $row['prestito_id'] : null,
            'book_title'     => self::nullableString($row['libro_titolo'] ?? null),
            'created_at'     => (string) $row['created_at'],
        ];
    }

    /**
     * item() plus what the desk sees: the payment method label, who recorded
     * the movement, and whether a payment receipt can be downloaded.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function staffItem(array $row, bool $canManage): array
    {
        $method = self::nullableString($row['metodo_pagamento'] ?? null);
        $operatorName = self::nullableString($row['operatore'] ?? null);
        $operatorId = isset($row['creato_da']) ? (int) $row['creato_da'] : null;

        return self::item($row) + [
            'payment_method_label' => $method !== null ? PatronLedger::methodLabel($method) : null,
            'operator'             => $operatorId !== null ? ['id' => $operatorId, 'name' => $operatorName ?? ''] : null,
            'receipt_available'    => $canManage && (string) $row['tipo'] === 'pagamento',
        ];
    }

    private static function nullableString(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $s = trim((string) $raw);

        return $s !== '' ? $s : null;
    }
}
