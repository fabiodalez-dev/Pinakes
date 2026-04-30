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
     * Normalises legacy aliases (cycle/series/etc) before lookup so the view
     * can pass raw DB values directly.
     */
    public static function label(?string $tipo): string
    {
        $canonical = self::canonical($tipo);
        return self::types()[$canonical] ?? $canonical;
    }

    /**
     * i18n-5 (refactor): map any legacy/alias tipo (series, cycle, season,
     * spinoff, arc, …) onto its canonical Italian key. Mirrors
     * SeriesRepository::normalizeType but without a DB dependency.
     */
    public static function canonical(?string $tipo): string
    {
        $value = strtolower(trim((string) $tipo));
        if ($value === '') {
            return 'serie';
        }
        $value = str_replace([' ', '-', '/'], '_', $value);
        $map = [
            'series' => 'serie',
            'serie' => 'serie',
            'universe' => 'universo',
            'universo' => 'universo',
            'macroserie' => 'universo',
            'cycle' => 'ciclo',
            'ciclo' => 'ciclo',
            'season' => 'stagione',
            'stagione' => 'stagione',
            'spin_off' => 'spin_off',
            'spinoff' => 'spin_off',
            'arc' => 'arco',
            'arco' => 'arco',
            'publisher_collection' => 'collezione_editoriale',
            'collezione_editoriale' => 'collezione_editoriale',
            'altro' => 'altro',
            'other' => 'altro',
        ];
        return $map[$value] ?? 'altro';
    }
}
