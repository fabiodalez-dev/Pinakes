<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffStocktakeController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Staff090\Support\StocktakeOpenApi;

/**
 * Inventory stocktake (Pinakes 0.9.0 /admin/inventory) for the app: the
 * phone is the scanner. Behind the `stocktake` module; Permissions maps
 * /api/v1/staff/stocktake to inventory.manage, as /admin/inventory.
 * Rate limits mirror the web routes (scan 600/min, list/upload/reports 30/min).
 */
final class StocktakeArea implements StaffArea
{
    public static function area(): string
    {
        return 'stocktake';
    }

    public static function feature(): string
    {
        return 'stocktake';
    }

    public static function circulation(): bool
    {
        return false;
    }

    public static function routes(): array
    {
        $s = '/staff/stocktake/sessions';
        $id = '/{id:[0-9]+}';

        return [
            ['GET', '/staff/stocktake/options', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->options($q, $r)],
            ['GET', $s, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->sessions($q, $r), 'limit' => [30, 60, 'stocktake_list']],
            ['POST', $s, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->create($q, $r)],
            ['GET', $s . $id, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->show($q, $r, (int) $a['id'])],
            ['GET', $s . $id . '/state', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->state($q, $r, (int) $a['id'])],
            ['GET', $s . $id . '/scans', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->scans($q, $r, (int) $a['id'])],
            ['POST', $s . $id . '/scans', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->scan($q, $r, (int) $a['id']), 'limit' => [600, 60, 'stocktake_scan']],
            ['GET', $s . $id . '/report', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->report($q, $r, (int) $a['id'])],
            ['POST', $s . $id . '/upload', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->upload($q, $r, (int) $a['id']), 'limit' => [30, 60, 'stocktake_upload']],
            ['POST', $s . $id . '/close', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->close($q, $r, (int) $a['id'])],
            ['POST', $s . $id . '/cancel', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->cancel($q, $r, (int) $a['id'])],
            ['GET', $s . $id . '/report.csv', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->reportCsv($q, $r, (int) $a['id']), 'limit' => [30, 60, 'stocktake_report']],
            ['GET', $s . $id . '/report.pdf', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->reportPdf($q, $r, (int) $a['id']), 'limit' => [30, 60, 'stocktake_report']],
        ];
    }

    public static function openApi(array $doc): array
    {
        return StocktakeOpenApi::extend($doc);
    }
}
