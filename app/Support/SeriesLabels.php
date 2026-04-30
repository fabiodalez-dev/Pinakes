<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Central translatable labels for collane `tipo` values (issue #110).
 *
 * Pre-fix the same 8-entry map was duplicated across book_form.php,
 * collane/index.php, collane/dettaglio.php and libri/scheda_libro.php.
 * Adding a new tipo (e.g. `trilogia`) had to be done in 4 places and
 * one risked a missing translation in one locale.
 *
 * Use this helper from views and controllers as the single source of
 * truth for the (canonical key → translated label) map. Canonical
 * keys must match `SeriesRepository::normalizeType()`.
 */
final class SeriesLabels
{
    /**
     * Translated map of canonical type values.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'serie'                 => __('Serie'),
            'universo'              => __('Universo / macroserie'),
            'ciclo'                 => __('Ciclo'),
            'stagione'              => __('Stagione'),
            'spin_off'              => __('Spin-off'),
            'arco'                  => __('Arco narrativo'),
            'collezione_editoriale' => __('Collana editoriale'),
            'altro'                 => __('Altro'),
        ];
    }

    /**
     * Resolve a translated label for a given canonical (or legacy) tipo.
     * Falls back to the raw value when no map entry exists.
     */
    public static function label(?string $tipo): string
    {
        $tipo = trim((string) $tipo);
        if ($tipo === '') {
            return self::types()['serie'];
        }
        return self::types()[$tipo] ?? $tipo;
    }
}
