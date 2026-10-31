<?php
declare(strict_types=1);

namespace App\Support\Holidays;

/**
 * Calendars other than the Gregorian one, for holidays a library defines
 * itself (Settings → Calendar → custom holidays): Islamic (Umm al-Qura),
 * Hebrew, Chinese lunisolar, Persian (Solar Hijri), Indian national (Saka),
 * Ethiopian and Coptic.
 *
 * The Chinese dates come from ChineseLunarCalendar (published month table,
 * 1900-2100). The others are converted by ICU (ext-intl): Hebrew, Persian,
 * Indian, Ethiopian and Coptic are arithmetic calendars; Umm al-Qura is the
 * official table of Saudi Arabia. Where the start of an Islamic month follows
 * the local sighting of the moon, the day can differ by one: the library then
 * sets the dates of that year by hand (a holiday on specific dates).
 */
final class WorldCalendars
{
    /** Month names, transliterated: proper nouns, the same in every interface language. */
    private const MONTHS = [
        'islamic-umalqura' => ['Muharram', 'Safar', "Rabi' al-awwal", "Rabi' al-thani", 'Jumada al-awwal', 'Jumada al-thani', 'Rajab', "Sha'ban", 'Ramadan', 'Shawwal', "Dhu al-Qa'dah", 'Dhu al-Hijjah'],
        // ICU numbering: Adar I is month 6 and exists in leap years only.
        'hebrew' => ['Tishrei', 'Cheshvan', 'Kislev', 'Tevet', 'Shevat', 'Adar I', 'Adar (Adar II)', 'Nisan', 'Iyar', 'Sivan', 'Tammuz', 'Av', 'Elul'],
        'chinese' => ['正月 (1)', '二月 (2)', '三月 (3)', '四月 (4)', '五月 (5)', '六月 (6)', '七月 (7)', '八月 (8)', '九月 (9)', '十月 (10)', '冬月 (11)', '腊月 (12)'],
        'persian' => ['Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar', 'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand'],
        'indian' => ['Chaitra', 'Vaishakha', 'Jyeshtha', 'Ashadha', 'Shravana', 'Bhadra', 'Ashvin', 'Kartika', 'Agrahayana', 'Pausha', 'Magha', 'Phalguna'],
        'ethiopic' => ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'],
        'coptic' => ['Thout', 'Paopi', 'Hathor', 'Koiak', 'Tobi', 'Meshir', 'Paremhat', 'Parmouti', 'Pashons', 'Paoni', 'Epip', 'Mesori', 'Pi Kogi Enavot'],
    ];

    /** @return list<string> calendar codes */
    public static function codes(): array
    {
        return array_keys(self::MONTHS);
    }

    public static function exists(string $code): bool
    {
        return isset(self::MONTHS[$code]);
    }

    /** Name of the calendar in the interface language. */
    public static function label(string $code): string
    {
        return match ($code) {
            'islamic-umalqura' => __('Islamico (Umm al-Qura)'),
            'hebrew' => __('Ebraico'),
            'chinese' => __('Cinese (lunisolare)'),
            'persian' => __('Persiano (Hijri solare)'),
            'indian' => __('Indiano (Saka)'),
            'ethiopic' => __('Etiope'),
            'coptic' => __('Copto'),
            default => $code,
        };
    }

    /** @return list<string> month names, month 1 first */
    public static function months(string $code): array
    {
        return self::MONTHS[$code] ?? [];
    }

    /** True when the calendar can be computed on this server (ICU calendars need ext-intl). */
    public static function available(string $code): bool
    {
        return $code === 'chinese' || (self::exists($code) && class_exists(\IntlCalendar::class));
    }

    /**
     * The days of Gregorian $year that are day $day of month $month (1-based)
     * of calendar $code. Usually one; a lunar year is shorter than a solar
     * one, so an Islamic date falls twice in some Gregorian years, and none
     * when the month has no such day that year (Adar I outside a leap year).
     *
     * @return list<string>
     */
    public static function datesIn(string $code, int $month, int $day, int $year): array
    {
        if (!self::exists($code) || $month < 1 || $month > count(self::MONTHS[$code]) || $day < 1 || $day > 30) {
            return [];
        }
        if ($code === 'chinese') {
            return ChineseLunarCalendar::datesIn($month, $day, $year);
        }
        if (!class_exists(\IntlCalendar::class)) {
            return [];
        }
        $calendar = \IntlCalendar::createInstance('UTC', 'en_US@calendar=' . $code);
        $gregorian = \IntlCalendar::createInstance('UTC', 'en_US@calendar=gregorian');
        $extendedYears = [];
        foreach ([[0, 1], [11, 31]] as [$gMonth, $gDay]) {
            $gregorian->clear();
            // Two-argument set(): setDate() needs PHP 8.3.
            $gregorian->set(\IntlCalendar::FIELD_YEAR, $year);
            $gregorian->set(\IntlCalendar::FIELD_MONTH, $gMonth);
            $gregorian->set(\IntlCalendar::FIELD_DATE, $gDay);
            $calendar->setTime($gregorian->getTime());
            $extendedYears[] = (int) $calendar->get(\IntlCalendar::FIELD_EXTENDED_YEAR);
        }
        $out = [];
        for ($extended = $extendedYears[0]; $extended <= $extendedYears[1]; $extended++) {
            $calendar->clear();
            $calendar->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $extended);
            $calendar->set(\IntlCalendar::FIELD_MONTH, $month - 1);
            $calendar->set(\IntlCalendar::FIELD_DATE, $day);
            $time = $calendar->getTime();
            // A lenient calendar rolls a missing day into the next month: skip it.
            $calendar->setTime($time);
            if ((int) $calendar->get(\IntlCalendar::FIELD_MONTH) !== $month - 1 || (int) $calendar->get(\IntlCalendar::FIELD_DATE) !== $day) {
                continue;
            }
            $gregorian->setTime($time);
            $ymd = sprintf('%04d-%02d-%02d', $gregorian->get(\IntlCalendar::FIELD_YEAR), $gregorian->get(\IntlCalendar::FIELD_MONTH) + 1, $gregorian->get(\IntlCalendar::FIELD_DATE));
            if ((int) substr($ymd, 0, 4) === $year) {
                $out[] = $ymd;
            }
        }
        sort($out);
        return $out;
    }
}
