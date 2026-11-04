<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\LibrarySettingsController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Staff090\Support\LibrarySettingsOpenApi;

/**
 * Library settings of the 0.9.0 modules: Settings → Modules (feature
 * switches), → Loans and → Cards. Always mounted: the controllers apply the
 * web's own rules (modules = administrators; loans = Staff saves the
 * non-money fields; cards = the patron_cards configuration gate).
 */
final class LibrarySettingsArea implements StaffArea
{
    public static function area(): string
    {
        return 'library_settings';
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
        return [
            ['GET', '/staff/settings/modules', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->modules($q, $r)],
            ['PUT', '/staff/settings/modules', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateModules($q, $r)],
            ['GET', '/staff/settings/loans', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->loans($q, $r)],
            ['PUT', '/staff/settings/loans', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateLoans($q, $r)],
            ['GET', '/staff/settings/cards', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->cards($q, $r)],
            ['PUT', '/staff/settings/cards', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->updateCards($q, $r)],
        ];
    }

    public static function openApi(array $doc): array
    {
        return LibrarySettingsOpenApi::settings($doc);
    }
}
