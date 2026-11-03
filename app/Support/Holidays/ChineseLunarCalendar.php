<?php
declare(strict_types=1);

namespace App\Support\Holidays;

/**
 * The Chinese lunisolar calendar (农历) from 1900 to 2100, from the month
 * table published by the Hong Kong Observatory (the same data as the widely
 * used "lunarInfo" table): Lunar New Year, Mid-Autumn, Dragon Boat and every
 * other date on the lunar calendar.
 *
 * ICU's astronomical Chinese calendar is a day off whenever a new moon falls
 * a few minutes from midnight in Beijing (6 February 2027 and 3 February 2030
 * come out a day late), so library closures are computed from this table.
 *
 * One entry per lunar year from 1900: bits 0-3 the leap month (0 = none),
 * bit 16 a leap month of 30 days, bits 4-15 months 12 … 1 (set = 30 days).
 */
final class ChineseLunarCalendar
{
    public const FIRST_YEAR = 1900;
    public const LAST_YEAR = 2100;

    /** Lunar 1900-01-01 is Gregorian 1900-01-31. */
    private const EPOCH = '1900-01-31';

    private const MONTHS = [
        0x04bd8, 0x04ae0, 0x0a570, 0x054d5, 0x0d260, 0x0d950, 0x16554, 0x056a0, 0x09ad0, 0x055d2, // 1900
        0x04ae0, 0x0a5b6, 0x0a4d0, 0x0d250, 0x1d255, 0x0b540, 0x0d6a0, 0x0ada2, 0x095b0, 0x14977, // 1910
        0x04970, 0x0a4b0, 0x0b4b5, 0x06a50, 0x06d40, 0x1ab54, 0x02b60, 0x09570, 0x052f2, 0x04970, // 1920
        0x06566, 0x0d4a0, 0x0ea50, 0x06e95, 0x05ad0, 0x02b60, 0x186e3, 0x092e0, 0x1c8d7, 0x0c950, // 1930
        0x0d4a0, 0x1d8a6, 0x0b550, 0x056a0, 0x1a5b4, 0x025d0, 0x092d0, 0x0d2b2, 0x0a950, 0x0b557, // 1940
        0x06ca0, 0x0b550, 0x15355, 0x04da0, 0x0a5b0, 0x14573, 0x052b0, 0x0a9a8, 0x0e950, 0x06aa0, // 1950
        0x0aea6, 0x0ab50, 0x04b60, 0x0aae4, 0x0a570, 0x05260, 0x0f263, 0x0d950, 0x05b57, 0x056a0, // 1960
        0x096d0, 0x04dd5, 0x04ad0, 0x0a4d0, 0x0d4d4, 0x0d250, 0x0d558, 0x0b540, 0x0b6a0, 0x195a6, // 1970
        0x095b0, 0x049b0, 0x0a974, 0x0a4b0, 0x0b27a, 0x06a50, 0x06d40, 0x0af46, 0x0ab60, 0x09570, // 1980
        0x04af5, 0x04970, 0x064b0, 0x074a3, 0x0ea50, 0x06b58, 0x05ac0, 0x0ab60, 0x096d5, 0x092e0, // 1990
        0x0c960, 0x0d954, 0x0d4a0, 0x0da50, 0x07552, 0x056a0, 0x0abb7, 0x025d0, 0x092d0, 0x0cab5, // 2000
        0x0a950, 0x0b4a0, 0x0baa4, 0x0ad50, 0x055d9, 0x04ba0, 0x0a5b0, 0x15176, 0x052b0, 0x0a930, // 2010
        0x07954, 0x06aa0, 0x0ad50, 0x05b52, 0x04b60, 0x0a6e6, 0x0a4e0, 0x0d260, 0x0ea65, 0x0d530, // 2020
        0x05aa0, 0x076a3, 0x096d0, 0x04afb, 0x04ad0, 0x0a4d0, 0x1d0b6, 0x0d250, 0x0d520, 0x0dd45, // 2030
        0x0b5a0, 0x056d0, 0x055b2, 0x049b0, 0x0a577, 0x0a4b0, 0x0aa50, 0x1b255, 0x06d20, 0x0ada0, // 2040
        0x14b63, 0x09370, 0x049f8, 0x04970, 0x064b0, 0x168a6, 0x0ea50, 0x06b20, 0x1a6c4, 0x0aae0, // 2050
        0x092e0, 0x0d2e3, 0x0c960, 0x0d557, 0x0d4a0, 0x0da50, 0x05d55, 0x056a0, 0x0a6d0, 0x055d4, // 2060
        0x052d0, 0x0a9b8, 0x0a950, 0x0b4a0, 0x0b6a6, 0x0ad50, 0x055a0, 0x0aba4, 0x0a5b0, 0x052b0, // 2070
        0x0b273, 0x06930, 0x07337, 0x06aa0, 0x0ad50, 0x14b55, 0x04b60, 0x0a570, 0x054e4, 0x0d160, // 2080
        0x0e968, 0x0d520, 0x0daa0, 0x16aa6, 0x056d0, 0x04ae0, 0x0a9d4, 0x0a4d0, 0x0d150, 0x0f252, // 2090
        0x0d520, // 2100
    ];

    /** The leap month of lunar $year (0 = none). */
    public static function leapMonth(int $year): int
    {
        return self::MONTHS[$year - self::FIRST_YEAR] & 0xf;
    }

    /** Days of lunar month $month (1-12) of $year; $leap for its leap month. */
    public static function monthDays(int $year, int $month, bool $leap = false): int
    {
        $info = self::MONTHS[$year - self::FIRST_YEAR];
        if ($leap) {
            return self::leapMonth($year) === $month ? (($info & 0x10000) !== 0 ? 30 : 29) : 0;
        }
        return ($info & (0x10000 >> $month)) !== 0 ? 30 : 29;
    }

    /** Days in lunar $year. */
    public static function yearDays(int $year): int
    {
        $days = 0;
        for ($m = 1; $m <= 12; $m++) {
            $days += self::monthDays($year, $m);
        }
        $leap = self::leapMonth($year);
        return $days + ($leap > 0 ? self::monthDays($year, $leap, true) : 0);
    }

    /**
     * The Gregorian date of lunar $year-$month-$day; null outside the table
     * or for a day the month does not have (the 30th of a 29-day month).
     */
    public static function toGregorian(int $year, int $month, int $day, bool $leap = false): ?string
    {
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR || $month < 1 || $month > 12 || $day < 1
            || $day > self::monthDays($year, $month, $leap)) {
            return null;
        }
        $offset = 0;
        for ($y = self::FIRST_YEAR; $y < $year; $y++) {
            $offset += self::yearDays($y);
        }
        $leapMonth = self::leapMonth($year);
        for ($m = 1; $m < $month; $m++) {
            $offset += self::monthDays($year, $m);
            if ($m === $leapMonth) {
                $offset += self::monthDays($year, $m, true);
            }
        }
        if ($leap) {
            $offset += self::monthDays($year, $month);
        }
        $offset += $day - 1;
        return (new \DateTimeImmutable(self::EPOCH, new \DateTimeZone('UTC')))->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    /**
     * The days of Gregorian $year that are lunar $month/$day (regular month,
     * not the leap one): the lunar year starting in $year and the one before,
     * whose last months reach into January and February.
     *
     * @return list<string>
     */
    public static function datesIn(int $month, int $day, int $year): array
    {
        $out = [];
        foreach ([$year - 1, $year] as $lunarYear) {
            $date = self::toGregorian($lunarYear, $month, $day);
            if ($date !== null && (int) substr($date, 0, 4) === $year) {
                $out[] = $date;
            }
        }
        return $out;
    }
}
