<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Support\Holidays\CustomHolidays;
use App\Support\Holidays\HolidayPresets;
use App\Support\LibraryCalendar;
use App\Support\SecureLogger;

/**
 * Why a day of the calendar is closed, for the month grid of the staff app.
 *
 * The web page only colours a day closed or open. The app also names the
 * cause, and each cause is asked to LibraryCalendar itself: a calendar built
 * with that one rule (the weekly closed days, one closure row, one holiday
 * rule) says whether it closes the day, so the answer always agrees with the
 * due-date engine.
 */
final class CalendarDayReasons
{
    /** @var list<array{type: string, id: int|string|null, name: string, calendar: LibraryCalendar}> */
    private array $sources = [];

    /**
     * @param list<int> $closedWeekdays
     * @param list<array<string, mixed>> $closures calendario_chiusure rows
     */
    public function __construct(\mysqli $db, array $closedWeekdays, array $closures, private ?int $branchId)
    {
        if ($closedWeekdays !== []) {
            $this->sources[] = ['type' => 'weekly', 'id' => null, 'name' => __('Chiusura settimanale'), 'calendar' => new LibraryCalendar($closedWeekdays, [])];
        }
        try {
            $presetRules = HolidayPresets::calendarRules($db);
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] calendar reasons: holiday selection not read: ' . $e->getMessage());
            $presetRules = null;
        }
        foreach ($closures as $row) {
            // With a saved holiday selection the preset rows are replaced by
            // the selection's rules (LibraryCalendar::fromDatabase()).
            if ((int) ($row['attiva'] ?? 1) !== 1 || ($presetRules !== null && ($row['regola_festivita'] ?? null) !== null)) {
                continue;
            }
            $this->sources[] = [
                'type' => 'closure',
                'id' => (int) $row['id'],
                'name' => trim((string) ($row['descrizione'] ?? '')) !== '' ? (string) $row['descrizione'] : __('Chiusura'),
                'calendar' => new LibraryCalendar([], [$row]),
            ];
        }
        foreach ($presetRules ?? [] as $rule) {
            $this->sources[] = ['type' => 'holiday', 'id' => (string) ($rule['id'] ?? ''), 'name' => (string) ($rule['name'] ?? ''), 'calendar' => new LibraryCalendar([], [], [$rule])];
        }
        try {
            $customRules = CustomHolidays::calendarRules($db);
        } catch (\Throwable $e) {
            SecureLogger::warning('[MobileApi] calendar reasons: custom holidays not read: ' . $e->getMessage());
            $customRules = [];
        }
        foreach ($customRules as $rule) {
            $this->sources[] = ['type' => 'custom_holiday', 'id' => (int) substr((string) $rule['id'], 7), 'name' => (string) $rule['name'], 'calendar' => new LibraryCalendar([], [], [$rule])];
        }
    }

    /** @return list<array{type: string, id: int|string|null, name: string}> */
    public function for(string $ymd): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            if (!$source['calendar']->isOpen($ymd, $this->branchId)) {
                $out[] = ['type' => $source['type'], 'id' => $source['id'], 'name' => $source['name']];
            }
        }

        return $out;
    }
}
