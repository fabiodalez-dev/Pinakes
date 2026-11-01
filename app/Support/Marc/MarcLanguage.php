<?php
declare(strict_types=1);

namespace App\Support\Marc;

/**
 * MARC language codes (ISO 639-2/B, as 008/35-37, 041 and UNIMARC 101 carry
 * them) to the language names the catalogue stores, and back.
 */
final class MarcLanguage
{
    /** ISO 639-2/B (and the /T variants catalogues also send) → stored name. */
    private const NAMES = [
        'ita' => 'Italiano', 'eng' => 'English', 'fre' => 'Français', 'fra' => 'Français',
        'ger' => 'Deutsch', 'deu' => 'Deutsch', 'spa' => 'Español', 'por' => 'Português',
        'rus' => 'Русский', 'chi' => '中文', 'zho' => '中文', 'jpn' => '日本語', 'ara' => 'العربية',
        'dut' => 'Nederlands', 'nld' => 'Nederlands', 'swe' => 'Svenska', 'nor' => 'Norsk',
        'nob' => 'Norsk', 'nno' => 'Norsk', 'dan' => 'Dansk', 'fin' => 'Suomi', 'pol' => 'Polski',
        'cze' => 'Čeština', 'ces' => 'Čeština', 'hun' => 'Magyar', 'rum' => 'Română', 'ron' => 'Română',
        'gre' => 'Ελληνικά', 'ell' => 'Ελληνικά', 'tur' => 'Türkçe', 'heb' => 'עברית', 'hin' => 'हिन्दी',
        'kor' => '한국어', 'tha' => 'ไทย', 'lat' => 'Latina', 'cat' => 'Català', 'grc' => 'Greco antico',
        'ice' => 'Íslenska', 'isl' => 'Íslenska',
    ];

    /** Codes that name no language. */
    private const NONE = ['und', 'mul', 'zxx', 'mis', '|||', '   ', 'xxx'];

    /**
     * The stored name for a code; '' for "undetermined" and for a code this
     * table does not know (a raw "swa" in the language field would be no
     * language to the catalogue).
     */
    public static function nameFor(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '' || in_array($code, self::NONE, true) || strlen($code) !== 3) {
            return '';
        }
        return self::NAMES[$code] ?? '';
    }
    /**
     * ISO 639-2/B code for one stored language (Italian/English/native name,
     * ISO 639-1 or 639-2 code), or null when it is not recognised.
     */
    public static function codeFor(string $lang): ?string
    {
        static $map = [
            'italiano' => 'ita', 'italian' => 'ita', 'it' => 'ita', 'ita' => 'ita',
            'inglese' => 'eng', 'english' => 'eng', 'en' => 'eng', 'eng' => 'eng',
            'francese' => 'fre', 'français' => 'fre', 'french' => 'fre', 'fr' => 'fre', 'fre' => 'fre', 'fra' => 'fre',
            'tedesco' => 'ger', 'deutsch' => 'ger', 'german' => 'ger', 'de' => 'ger', 'ger' => 'ger', 'deu' => 'ger',
            'spagnolo' => 'spa', 'español' => 'spa', 'spanish' => 'spa', 'es' => 'spa', 'spa' => 'spa',
            'portoghese' => 'por', 'português' => 'por', 'portuguese' => 'por', 'pt' => 'por', 'por' => 'por',
            'danese' => 'dan', 'dansk' => 'dan', 'danish' => 'dan', 'da' => 'dan', 'dan' => 'dan',
            'olandese' => 'dut', 'nederlands' => 'dut', 'dutch' => 'dut', 'nl' => 'dut', 'dut' => 'dut', 'nld' => 'dut',
            'svedese' => 'swe', 'svenska' => 'swe', 'swedish' => 'swe', 'sv' => 'swe', 'swe' => 'swe',
            'norvegese' => 'nor', 'norsk' => 'nor', 'norwegian' => 'nor', 'no' => 'nor', 'nor' => 'nor',
            'russo' => 'rus', 'russian' => 'rus', 'ru' => 'rus', 'rus' => 'rus',
            'polacco' => 'pol', 'polski' => 'pol', 'polish' => 'pol', 'pl' => 'pol', 'pol' => 'pol',
            'greco' => 'gre', 'greek' => 'gre', 'el' => 'gre', 'gre' => 'gre', 'ell' => 'gre',
            'greco antico' => 'grc', 'grc' => 'grc',
            'latino' => 'lat', 'latin' => 'lat', 'la' => 'lat', 'lat' => 'lat',
            'cinese' => 'chi', 'chinese' => 'chi', 'zh' => 'chi', 'chi' => 'chi', 'zho' => 'chi',
            'giapponese' => 'jpn', 'japanese' => 'jpn', 'ja' => 'jpn', 'jpn' => 'jpn',
            'arabo' => 'ara', 'arabic' => 'ara', 'ar' => 'ara', 'ara' => 'ara',
            'catalano' => 'cat', 'català' => 'cat', 'catalan' => 'cat', 'ca' => 'cat', 'cat' => 'cat',
        ];
        $key = mb_strtolower(trim($lang));
        if (isset($map[$key])) {
            return $map[$key];
        }
        // Every name nameFor() stores (Русский, 中文, Suomi, Latina...) maps
        // back: NAMES lists the B form of a B/T pair first.
        foreach (self::NAMES as $code => $name) {
            if (mb_strtolower($name) === $key) {
                return $code;
            }
        }
        return null;
    }

    /**
     * The individual languages of a stored lingua value ("italiano, inglese").
     *
     * @return list<string>
     */
    public static function listOf(string $lang): array
    {
        $parts = preg_split('/\s*[,;\/]\s*/', trim($lang)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * The MARC code of a stored lingua value ('und' when unknown). A
     * multi-language value uses its first language.
     */
    public static function marcCodeOf(string $lang): string
    {
        $first = self::listOf($lang)[0] ?? '';
        return self::codeFor($first) ?? 'und';
    }
}
