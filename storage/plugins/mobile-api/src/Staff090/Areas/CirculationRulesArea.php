<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\CirculationRulesController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Staff090\Support\LibrarySettingsOpenApi;

/**
 * Patron types and the rules matrix (Settings → Circulation).
 *
 * No area-level feature gate and no catalogue-mode guard: the web route uses
 * FeatureGateMiddleware in configuration mode (an administrator configures
 * the module while it is off, catalogue mode included), so the controller
 * reproduces that gate itself, then mayConfigure(). The `staff_circulation_rules`
 * health flag is therefore always true; the module state is the
 * `circulation_rules` flag.
 */
final class CirculationRulesArea implements StaffArea
{
    public static function area(): string
    {
        return 'circulation_rules';
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
        $id = '/staff/circulation/types/{id:[0-9]+}';

        return [
            ['GET', '/staff/circulation/types', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->types($q, $r)],
            ['POST', '/staff/circulation/types', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->createType($q, $r)],
            ['GET', $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->type($q, $r, (int) $a['id'])],
            ['PUT', $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateType($q, $r, (int) $a['id'])],
            ['DELETE', $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->deleteType($q, $r, (int) $a['id'])],
            ['POST', $id . '/default', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->setDefault($q, $r, (int) $a['id'])],
            ['POST', $id . '/move', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->moveType($q, $r, (int) $a['id'])],
            ['GET', '/staff/circulation/rules', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->rules($q, $r)],
            ['PUT', '/staff/circulation/rules', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateRules($q, $r)],
        ];
    }

    public static function openApi(array $doc): array
    {
        return LibrarySettingsOpenApi::circulation($doc);
    }
}
