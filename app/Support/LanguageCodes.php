<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ISO 639 language codes to the names the catalogue stores in `libri.lingua`
 * (free text, by convention the Italian name: "Italiano", "Inglese"), and
 * back to ISO 639-2/B for the exports.
 *
 * One table for every importer and scraper, so a source that answers "ja",
 * "jpn" or "rus" produces "Giapponese" / "Russo" instead of "JA" or "Rus".
 */
final class LanguageCodes
{
    /**
     * ISO 639-2/B code => [Italian name, ISO 639-1, ISO 639-2/T when different].
     *
     * @var array<string, array{0:string,1:string,2?:string}>
     */
    private const LANGUAGES = [
        'ita' => ['Italiano', 'it'],
        'eng' => ['Inglese', 'en'],
        'fre' => ['Francese', 'fr', 'fra'],
        'ger' => ['Tedesco', 'de', 'deu'],
        'spa' => ['Spagnolo', 'es'],
        'por' => ['Portoghese', 'pt'],
        'dan' => ['Danese', 'da'],
        'dut' => ['Olandese', 'nl', 'nld'],
        'swe' => ['Svedese', 'sv'],
        'nor' => ['Norvegese', 'no'],
        'fin' => ['Finlandese', 'fi'],
        'ice' => ['Islandese', 'is', 'isl'],
        'pol' => ['Polacco', 'pl'],
        'cze' => ['Ceco', 'cs', 'ces'],
        'slo' => ['Slovacco', 'sk', 'slk'],
        'slv' => ['Sloveno', 'sl'],
        'hrv' => ['Croato', 'hr'],
        'srp' => ['Serbo', 'sr'],
        'hun' => ['Ungherese', 'hu'],
        'rum' => ['Rumeno', 'ro', 'ron'],
        'bul' => ['Bulgaro', 'bg'],
        'gre' => ['Greco moderno', 'el', 'ell'],
        'grc' => ['Greco antico', ''],
        'lat' => ['Latino', 'la'],
        'rus' => ['Russo', 'ru'],
        'ukr' => ['Ucraino', 'uk'],
        'tur' => ['Turco', 'tr'],
        'ara' => ['Arabo', 'ar'],
        'heb' => ['Ebraico', 'he'],
        'per' => ['Persiano', 'fa', 'fas'],
        'hin' => ['Hindi', 'hi'],
        'chi' => ['Cinese', 'zh', 'zho'],
        'jpn' => ['Giapponese', 'ja'],
        'kor' => ['Coreano', 'ko'],
        'cat' => ['Catalano', 'ca'],
        'baq' => ['Basco', 'eu', 'eus'],
        'glg' => ['Galiziano', 'gl'],
        'wel' => ['Gallese', 'cy', 'cym'],
        'gle' => ['Irlandese', 'ga'],
        'est' => ['Estone', 'et'],
        'lav' => ['Lettone', 'lv'],
        'lit' => ['Lituano', 'lt'],
        'alb' => ['Albanese', 'sq', 'sqi'],
        'epo' => ['Esperanto', 'eo'],
        'fur' => ['Friulano', ''],
        'srd' => ['Sardo', 'sc'],
        'lad' => ['Ladino', ''],
        'mul' => ['Multilingue', ''],
    ];

    /**
     * The catalogue name for an ISO 639-1 or 639-2 (B or T) code, or null when
     * the code is not known (the caller keeps whatever it had).
     */
    public static function nameFor(string $code): ?string
    {
        $key = self::toIso6392b($code);
        return $key !== null ? self::LANGUAGES[$key][0] : null;
    }

    /**
     * The ISO 639-2/B code for an ISO 639-1/639-2 code or a stored name
     * ("italiano", "Inglese"), or null.
     */
    public static function toIso6392b(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        if (isset(self::LANGUAGES[$v])) {
            return $v;
        }
        foreach (self::LANGUAGES as $b => $row) {
            if ($v === $row[1] || $v === ($row[2] ?? '') || $v === mb_strtolower($row[0])) {
                return $b;
            }
        }
        return null;
    }
}
