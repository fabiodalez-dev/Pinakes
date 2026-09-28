<?php
declare(strict_types=1);
namespace App\Support;

/** Accept a GND identifier or its canonical provider URL; never infer an identity. */
final class GndIdentifier
{
    public static function normalize(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) { throw new \InvalidArgumentException(__('Valore non valido.')); }
        $value = trim((string)$value);
        if ($value === '') { return null; }
        $value = preg_replace('~^https?://d-nb\.info/gnd/~i', '', $value) ?? '';
        if (preg_match('/^[0-9]{2,12}(?:-?[0-9X])$/D', $value) !== 1) {
            throw new \InvalidArgumentException(__('Identificativo GND non valido.'));
        }
        return $value;
    }
}
