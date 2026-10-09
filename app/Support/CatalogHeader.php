<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;

/**
 * Title and subtitle of a public listing page header (the catalogue, the
 * events), one pair per language, edited in Settings → CMS.
 *
 * What the field holds is what the page shows: a field never saved is filled
 * with the shipped wording (in that language), and a field saved empty shows
 * nothing; no text is ever substituted behind the administrator's back. The
 * texts live in system_settings under the page's category, keyed by locale
 * ("title.en_US", "subtitle.en_US"), so adding a language needs no schema
 * change.
 */
final class CatalogHeader
{
    public const CATEGORY = 'catalog';
    public const DEFAULT_TITLE = 'Catalogo';
    public const DEFAULT_SUBTITLE = 'Scopri migliaia di titoli nella nostra collezione digitale';
    public const TITLE_MAX = 255;
    public const SUBTITLE_MAX = 500;

    /** @var array<string, array{category: string, title: string, subtitle: string}> */
    public const PAGES = [
        'catalog' => ['category' => 'catalog', 'title' => self::DEFAULT_TITLE, 'subtitle' => self::DEFAULT_SUBTITLE],
        'events' => ['category' => 'events_page', 'title' => 'Eventi', 'subtitle' => 'In questa pagina trovi tutti gli eventi, gli incontri e i laboratori organizzati dalla biblioteca.'],
    ];

    /** @return array{category: string, title: string, subtitle: string} */
    private static function page(string $page): array
    {
        return self::PAGES[$page] ?? self::PAGES['catalog'];
    }

    /**
     * The header the visitor sees in $locale: the stored text, or the
     * shipped default translated in the current request language.
     *
     * @return array{title: string, subtitle: string}
     */
    public static function forLocale(SettingsRepository $repository, string $locale, string $page = 'catalog'): array
    {
        $stored = self::stored($repository, $page);
        $locale = I18n::normalizeLocaleCode($locale);
        $config = self::page($page);

        return [
            'title' => $stored[$locale]['title'] ?? __($config['title']),
            'subtitle' => $stored[$locale]['subtitle'] ?? __($config['subtitle']),
        ];
    }

    /**
     * Saved texts, by locale; a field never saved is null, a field saved
     * empty is ''.
     *
     * @return array<string, array{title: ?string, subtitle: ?string}>
     */
    public static function stored(SettingsRepository $repository, string $page = 'catalog'): array
    {
        $texts = [];
        foreach ($repository->getCategory(self::page($page)['category']) as $key => $value) {
            if (!preg_match('/^(title|subtitle)\.([A-Za-z]{2,3}_[A-Za-z]{2})$/', (string) $key, $m)) {
                continue;
            }
            $locale = I18n::normalizeLocaleCode($m[2]);
            $texts[$locale] ??= ['title' => null, 'subtitle' => null];
            $texts[$locale][$m[1]] = trim((string) $value);
        }

        return $texts;
    }

    /**
     * True for what the settings form posts: a map of locale => text.
     *
     * @phpstan-assert-if-true array<string, string> $value
     */
    public static function isTextMap(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $locale => $text) {
            if (!is_string($locale) || !is_string($text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save the submitted texts of the active languages, as typed: an empty
     * field is saved empty and the page then shows nothing there. A language
     * the form did not send is left as it is.
     *
     * @param array<string, string> $titles    locale => title
     * @param array<string, string> $subtitles locale => subtitle
     */
    public static function save(SettingsRepository $repository, array $titles, array $subtitles, string $page = 'catalog'): void
    {
        $category = self::page($page)['category'];
        foreach (array_keys(I18n::getAvailableLocales()) as $locale) {
            $locale = I18n::normalizeLocaleCode((string) $locale);
            foreach (['title' => [$titles, self::TITLE_MAX], 'subtitle' => [$subtitles, self::SUBTITLE_MAX]] as $field => [$values, $max]) {
                if (!array_key_exists($locale, $values)) {
                    continue;
                }
                $repository->set($category, $field . '.' . $locale, self::clean($values[$locale], $max));
            }
        }
    }

    /**
     * The shipped wording in each of $locales: the text a field shows until
     * it is first saved.
     *
     * Switching the locale empties the translation cache, so this switches
     * once per language and restores the admin's language once at the end,
     * instead of a switch and a restore around every language.
     *
     * @param list<string> $locales
     * @return array<string, array{title: string, subtitle: string}>
     */
    public static function defaultsFor(array $locales, string $page = 'catalog'): array
    {
        $config = self::page($page);
        $current = I18n::getLocale();
        $defaults = [];
        // finally: the settings page renders in the admin's language after this.
        try {
            foreach ($locales as $locale) {
                I18n::setLocale($locale);
                $defaults[$locale] = ['title' => __($config['title']), 'subtitle' => __($config['subtitle'])];
            }
        } finally {
            I18n::setLocale($current);
        }

        return $defaults;
    }

    /**
     * Plain text on one line: the header is not a rich-text area, so markup
     * tags are dropped. Only real tags, though: strip_tags() treats any "<" as
     * the start of one and would cut "Leggi <3 libri" down to "Leggi". A bare
     * "<" is safe here, since every place that prints the header escapes it.
     */
    private static function clean(mixed $value, int $max): string
    {
        $text = is_string($value) ? $value : '';
        $text = (string) preg_replace('~</?[A-Za-z][^<>]*>~', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }
}
