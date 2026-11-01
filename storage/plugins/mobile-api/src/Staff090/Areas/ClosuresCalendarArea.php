<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\ClosuresCalendarController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Staff090\Support\LibrarySettingsOpenApi;

/**
 * The closures calendar (/admin/calendar): weekly closed days, closures,
 * national holiday presets, yearly switches, the library's own holidays and
 * ICS import.
 *
 * Like CirculationRulesArea, no area-level gate: the controller reproduces the
 * web's configuration-mode gate, then mayConfigure(). `staff_closures_calendar`
 * is always true; the module state is the `closures_calendar` health flag.
 */
final class ClosuresCalendarArea implements StaffArea
{
    public static function area(): string
    {
        return 'closures_calendar';
    }

    public static function feature(): ?string
    {
        return null;
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $closure = '/staff/calendar/closures/{id:[0-9]+}';
        $custom = '/staff/calendar/custom-holidays/{id:[0-9]+}';

        return [
            ['GET', '/staff/calendar', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->index($q, $r)],
            ['PUT', '/staff/calendar/weekdays', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->saveWeekdays($q, $r)],
            ['POST', '/staff/calendar/closures', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->createClosure($q, $r)],
            ['PUT', $closure, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateClosure($q, $r, (int) $a['id'])],
            ['DELETE', $closure, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->deleteClosure($q, $r, (int) $a['id'])],
            ['GET', '/staff/calendar/holidays/countries', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->countries($q, $r)],
            ['POST', '/staff/calendar/holidays/preset', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->applyPreset($q, $r)],
            ['PUT', '/staff/calendar/holidays/yearly', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->saveYearly($q, $r)],
            ['POST', '/staff/calendar/custom-holidays', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->createCustom($q, $r)],
            ['PUT', $custom, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateCustom($q, $r, (int) $a['id'])],
            ['DELETE', $custom, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->deleteCustom($q, $r, (int) $a['id'])],
            ['POST', $custom . '/toggle', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->toggleCustom($q, $r, (int) $a['id'])],
            ['POST', '/staff/calendar/import-ics', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->importIcs($q, $r), 'limit' => [10, 60, 'staff_calendar_ics']],
        ];
    }

    public static function openApi(array $doc): array
    {
        return LibrarySettingsOpenApi::calendar($doc);
    }
}
