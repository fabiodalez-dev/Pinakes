<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\SettingsRepository;

/**
 * Title and subtitle of the public catalogue header, one pair per language.
 *
 * Each active language can override the two texts from Settings → CMS; a
 * language left empty keeps the shipped wording, translated as before. The
 * texts live in system_settings under category "catalog", keyed by locale
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

    /**
     * The header the visitor sees in $locale: the stored text, or the
     * shipped default translated in the current request language.
     *
     * @return array{title: string, subtitle: string}
     */
    public static function forLocale(SettingsRepository $repository, string $locale): array
    {
        $stored = self::stored($repository);
        $locale = I18n::normalizeLocaleCode($locale);

        return [
            'title' => ($stored[$locale]['title'] ?? '') !== '' ? $stored[$locale]['title'] : __(self::DEFAULT_TITLE),
            'subtitle' => ($stored[$locale]['subtitle'] ?? '') !== '' ? $stored[$locale]['subtitle'] : __(self::DEFAULT_SUBTITLE),
        ];
    }

    /**
     * Stored overrides, by locale. Languages without an override are absent.
     *
     * @return array<string, array{title: string, subtitle: string}>
     */
    public static function stored(SettingsRepository $repository): array
    {
        $texts = [];
        foreach ($repository->getCategory(self::CATEGORY) as $key => $value) {
            if (!preg_match('/^(title|subtitle)\.([A-Za-z]{2,3}_[A-Za-z]{2})$/', (string) $key, $m)) {
                continue;
            }
            $locale = I18n::normalizeLocaleCode($m[2]);
            $texts[$locale] ??= ['title' => '', 'subtitle' => ''];
            $texts[$locale][$m[1]] = trim($value);
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
     * Save the submitted texts of the active languages. An empty field
     * removes the override, so that language goes back to the default; a
     * language the form did not send is left as it is.
     *
     * @param array<string, string> $titles    locale => title
     * @param array<string, string> $subtitles locale => subtitle
     */
    public static function save(SettingsRepository $repository, array $titles, array $subtitles): void
    {
        foreach (array_keys(I18n::getAvailableLocales()) as $locale) {
            $locale = I18n::normalizeLocaleCode((string) $locale);
            foreach (['title' => [$titles, self::TITLE_MAX], 'subtitle' => [$subtitles, self::SUBTITLE_MAX]] as $field => [$values, $max]) {
                if (!array_key_exists($locale, $values)) {
                    continue;
                }
                $value = self::clean($values[$locale], $max);
                if ($value === '') {
                    $repository->delete(self::CATEGORY, $field . '.' . $locale);
                } else {
                    $repository->set(self::CATEGORY, $field . '.' . $locale, $value);
                }
            }
        }
    }

    /**
     * The shipped wording in each of $locales, shown as the placeholder of each
     * field so the admin sees what an empty field falls back to.
     *
     * Switching the locale empties the translation cache, so this switches
     * once per language and restores the admin's language once at the end,
     * instead of a switch and a restore around every language.
     *
     * @param list<string> $locales
     * @return array<string, array{title: string, subtitle: string}>
     */
    public static function defaultsFor(array $locales): array
    {
        $current = I18n::getLocale();
        $defaults = [];
        // finally: the settings page renders in the admin's language after this.
        try {
            foreach ($locales as $locale) {
                I18n::setLocale($locale);
                $defaults[$locale] = ['title' => __(self::DEFAULT_TITLE), 'subtitle' => __(self::DEFAULT_SUBTITLE)];
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
