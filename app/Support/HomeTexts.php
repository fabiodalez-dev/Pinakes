<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The texts of the home page sections, one rule for the CMS form and the site.
 *
 * Every text the home shows comes from Admin → CMS → Homepage. A field never
 * saved (NULL) shows its default, and the form shows that same default in the
 * field, so what the administrator reads is what the visitor reads. A field
 * saved empty shows nothing: before, the site replaced it with a hidden
 * default the form never displayed ("Scopri, prenota e gestisci…"), which
 * nobody could find or remove.
 */
final class HomeTexts
{
    /**
     * Defaults, as translation keys (they go through __()).
     *
     * @var array<string, array<string, string>>
     */
    private const DEFAULTS = [
        'hero' => [
            'title' => 'La Tua Biblioteca Digitale',
            'subtitle' => 'Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.',
            'button_text' => 'Sfoglia Catalogo',
        ],
        'features_title' => [
            'title' => 'Perché Scegliere la Nostra Biblioteca',
            'subtitle' => "Un'esperienza di lettura moderna, intuitiva e sempre a portata di mano",
        ],
        'latest_books_title' => [
            'title' => 'Ultimi Libri Aggiunti',
            'subtitle' => 'Scopri le ultime novità della nostra collezione',
        ],
        'genre_carousel' => [
            'title' => 'Esplora i generi principali',
            'subtitle' => 'Scopri le nostre radici tematiche e lasciati ispirare dai titoli disponibili.',
        ],
        'events' => [
            'title' => 'Gli appuntamenti della biblioteca',
            'subtitle' => 'In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.',
        ],
        'cta' => [
            'title' => 'Inizia la Tua Avventura Letteraria',
            'subtitle' => 'Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna.',
            'button_text' => 'Registrati Ora',
        ],
    ];

    /** The translated default of a field ('' when it has none). */
    public static function default(string $section, string $field): string
    {
        $key = self::DEFAULTS[$section][$field] ?? '';
        return $key === '' ? '' : (string) __($key);
    }

    /**
     * The text to show, on the site and in the CMS field: the saved value,
     * or the default when the field was never saved. '' means "show nothing".
     *
     * @param array<string, mixed>|null $row the home_content row of the section
     */
    public static function text(?array $row, string $section, string $field): string
    {
        if (is_array($row) && array_key_exists($field, $row) && $row[$field] !== null) {
            return trim((string) $row[$field]);
        }
        return self::default($section, $field);
    }

    /**
     * A label that cannot be blank (a button): the saved value, or the
     * default when it is missing or empty.
     *
     * @param array<string, mixed>|null $row
     */
    public static function label(?array $row, string $section, string $field): string
    {
        $text = self::text($row, $section, $field);
        return $text !== '' ? $text : self::default($section, $field);
    }
}
