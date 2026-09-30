<?php
declare(strict_types=1);
namespace App\Plugins\Emeroteca\Support;
require_once __DIR__ . '/ArticleMarcXml.php';

/**
 * The language and country pickers of the article form (#412).
 *
 * The columns hold ISO codes, not names, so that a record reads as "Danish"
 * to one user and "danese" to the next. Nobody should have to know those
 * codes by heart, though: the form lists the names, in the language of whoever
 * is cataloguing, and stores the code of the one they pick.
 */
final class CodeLists
{
    /**
     * Languages a library catalogues older material in, which have no
     * two-letter code and so are missing from ISO 639-1: Old Norse for a
     * Danish collection, Ancient Greek, the medieval forms of English, French
     * and German. "mul" is the code for a text in several languages.
     */
    private const HISTORIC_LANGUAGES = ['grc','ang','enm','fro','frm','gmh','goh','non','got','sga','mul'];

    /**
     * Two-letter codes in ICU's region list that are not countries: CLDR's
     * groupings (EU, EZ, UN), its private and unknown codes (QO, XA, XB, ZZ)
     * and the exceptional reservations for places ISO 3166 folds into a
     * country (Ascension, Canary Islands, Diego Garcia, …). The list of
     * countries itself comes from ICU, like their names, so a new country
     * arrives with the PHP/ICU update rather than with an edit here.
     */
    private const NOT_COUNTRIES = ['AC','CP','CQ','DG','EA','EU','EZ','IC','QO','TA','UN','XA','XB','ZZ'];

    /**
     * Language code => name in $locale, sorted by name. The codes are the
     * three-letter ISO 639-2/T ones the form has always asked for ("dan",
     * "deu"); ArticleMarcXml maps them to the MARC forms ("ger") on export.
     *
     * @return array<string,string>
     */
    public static function languages(string $locale): array
    {
        $bibliographicToTerminology = array_flip(ArticleMarcXml::LANGUAGE_639_2T);
        $codes = [];
        foreach (ArticleMarcXml::LANGUAGE_639_1 as $bibliographic) {
            $codes[] = $bibliographicToTerminology[$bibliographic] ?? $bibliographic;
        }
        $names = [];
        foreach (array_merge($codes, self::HISTORIC_LANGUAGES) as $code) {
            $names[$code] = self::name($code, $locale, false);
        }
        return self::sorted($names, $locale);
    }

    /**
     * Country code => name in $locale, sorted by name.
     *
     * @return array<string,string>
     */
    public static function countries(string $locale): array
    {
        $names = [];
        foreach (self::countryCodes() as $code) {
            $names[$code] = self::name($code, $locale, true);
        }
        return self::sorted($names, $locale);
    }

    /**
     * The two-letter country codes ICU knows, or [] without intl (the form
     * then falls back to a plain text box for the code).
     *
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        if (!class_exists(\ResourceBundle::class)) {
            return [];
        }
        $regions = \ResourceBundle::create('en', 'ICUDATA-region');
        $table = $regions instanceof \ResourceBundle ? $regions->get('Countries') : null;
        if (!$table instanceof \ResourceBundle) {
            return [];
        }
        $codes = [];
        foreach ($table as $code => $_name) {
            $code = (string) $code;
            if (preg_match('/^[A-Z]{2}$/D', $code) === 1 && !in_array($code, self::NOT_COUNTRIES, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * The display name of one code, capitalised as a list entry. Without intl
     * the code stands for itself, which still lets the list work.
     */
    public static function name(string $code, string $locale, bool $region): string
    {
        if (!class_exists(\Locale::class)) {
            return $code;
        }
        $name = $region ? \Locale::getDisplayRegion('und_' . $code, $locale) : \Locale::getDisplayLanguage($code, $locale);
        if ($name === '' || $name === $code) {
            return $code;
        }
        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * @param array<string,string> $names
     * @return array<string,string>
     */
    private static function sorted(array $names, string $locale): array
    {
        if (class_exists(\Collator::class)) {
            $collator = new \Collator($locale);
            uasort($names, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));
        } else {
            asort($names);
        }
        return $names;
    }
}
