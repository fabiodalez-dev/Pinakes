<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Models\SupplierRepository;

/**
 * The English JSON contract of /staff/suppliers mapped onto the supplier
 * form (`fornitori`). Pure: the controller passes the stored row for an
 * update, so the merge and the validation are unit-testable.
 *
 * The rules are the web form's (SupplierRepository::normalize() and
 * invalidFields(), SuppliersController::validationError()): the name is
 * required, an e-mail or website that is not one is refused, a website
 * without a scheme becomes https://. One difference on purpose: a value
 * longer than its column is refused with 422 instead of being cut.
 */
final class SupplierPayload
{
    /** JSON key => form/column key, in form order. */
    public const FIELDS = [
        'name'           => 'nome',
        'contact_person' => 'referente',
        'email'          => 'email',
        'phone'          => 'telefono',
        'website'        => 'sito_web',
        'address'        => 'indirizzo',
        'vat_number'     => 'partita_iva',
        'notes'          => 'note',
    ];

    /** Column sizes (the website is checked by invalidFields(), scheme included). */
    private const LIMITS = ['nome' => 255, 'referente' => 150, 'email' => 255, 'telefono' => 30, 'partita_iva' => 20, 'indirizzo' => 2000, 'note' => 5000];

    private function __construct()
    {
    }

    /**
     * Validate a create (no $current) or an update (the stored row: an absent
     * JSON key keeps its value, null or '' clears it).
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current stored `fornitori` row
     * @return array{data: array<string, ?string>, errors: array<string, string>}
     */
    public static function validate(array $json, ?array $current = null): array
    {
        $errors = [];
        $form = [];
        foreach (self::FIELDS as $key => $column) {
            if (!array_key_exists($key, $json)) {
                $stored = $current[$column] ?? null;
                $form[$column] = $stored === null ? '' : (string) $stored;
                continue;
            }
            $value = $json[$key];
            if ($value === null) {
                $form[$column] = '';
                continue;
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                $errors[$key] = __('Valore non valido.');
                $form[$column] = '';
                continue;
            }
            $text = trim((string) $value);
            $max = self::LIMITS[$column] ?? 0;
            if ($max > 0 && mb_strlen($text) > $max) {
                $errors[$key] = sprintf(__('Valore troppo lungo (massimo %d caratteri).'), $max);
            }
            $form[$column] = $text;
        }
        if (!isset($errors['name']) && trim($form['nome']) === '') {
            $errors['name'] = __('Il nome del fornitore è obbligatorio.');
        }
        foreach (SupplierRepository::invalidFields($form) as $column) {
            $key = (string) array_search($column, self::FIELDS, true);
            if (isset($errors[$key])) {
                continue;
            }
            $errors[$key] = $column === 'email'
                ? __('L\'email del fornitore non è un indirizzo valido.')
                : __('Il sito web del fornitore non è un indirizzo valido: indica un URL http(s), ad esempio https://www.esempio.it.');
        }

        return ['data' => SupplierRepository::normalize($form), 'errors' => $errors];
    }

    /**
     * The API resource of a supplier row.
     *
     * @param array<string, mixed> $row `fornitori` row (+ libri_count from listWithCounts())
     * @return array<string, mixed>
     */
    public static function map(array $row, int $bookCount, bool $canManage): array
    {
        $out = ['id' => (int) $row['id']];
        foreach (self::FIELDS as $key => $column) {
            $value = $row[$column] ?? null;
            $out[$key] = $value === null || trim((string) $value) === '' ? null : (string) $value;
        }
        $out['name'] = (string) ($row['nome'] ?? '');
        $out['book_count'] = $bookCount;
        $out['created_at'] = self::iso($row['created_at'] ?? null);
        $out['updated_at'] = self::iso($row['updated_at'] ?? null);
        $out['can_edit'] = $canManage;
        $out['can_delete'] = $canManage;

        return $out;
    }

    /** A MySQL datetime as ISO 8601 with the server's offset, or null. */
    public static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
