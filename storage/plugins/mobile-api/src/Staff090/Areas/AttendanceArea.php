<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Areas;

use App\Plugins\MobileApi\Staff090\Controllers\StaffAttendanceController as C;
use App\Plugins\MobileApi\Staff090\StaffArea;
use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * Visitor log (Pinakes 0.9.0 /admin/attendance) for the app: the phone scans
 * the patron card at the entrance. Behind the `visitor_log` module, a
 * circulation module (404 in catalogue mode too). Permissions maps
 * /api/v1/staff/attendance to loans.manage, as /admin/attendance. Rate limits
 * mirror the web routes (scan and guest 120/min).
 */
final class AttendanceArea implements StaffArea
{
    public static function area(): string
    {
        return 'attendance';
    }

    public static function feature(): string
    {
        return 'visitor_log';
    }

    public static function circulation(): bool
    {
        return true;
    }

    public static function routes(): array
    {
        $b = '/staff/attendance';

        return [
            ['GET', $b, static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->overview($q, $r)],
            ['POST', $b . '/check-ins', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->checkIn($q, $r), 'limit' => [120, 60, 'staff_attendance_scan']],
            ['POST', $b . '/guests', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->guest($q, $r), 'limit' => [120, 60, 'staff_attendance_guest']],
            ['GET', $b . '/export.csv', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->export($q, $r)],
            ['PUT', $b . '/settings', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->saveSettings($q, $r)],
            ['POST', $b . '/purge', static fn (\mysqli $db, $q, $r, array $a) => (new C($db))->purge($q, $r)],
        ];
    }

    public static function openApi(array $doc): array
    {
        $t = 'staff-attendance';
        $doc['tags'][] = ['name' => $t, 'description' => 'Visitor log (Mobile API 1.10.0, Pinakes 0.9.0 /admin/attendance): card check-ins at the entrance, guests, day and hour-slot counts, CSV export, GDPR retention. Every route answers 404 feature_disabled while the visitor_log module is off or the library is in catalogue mode. Operators with a custom role need loans.manage.'];
        $doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_attendance'] = [
            'type' => 'boolean', 'description' => 'The visitor log of Mobile API 1.10.0 is available (module visitor_log on).',
        ];

        $i = ['type' => 'integer'];
        $s = ['type' => 'string'];
        $b = ['type' => 'boolean'];
        $counts = O::object(['members' => $i, 'guests' => $i, 'total' => $i]);
        $retention = O::object([
            'days' => ['type' => 'integer', 'description' => 'Visits older than this many whole days are deleted by the maintenance run (0 = kept forever).'],
            'max_days' => ['type' => 'integer', 'description' => '3650.'],
            'default_days' => ['type' => 'integer', 'description' => '365, used while nothing is set.'],
            'continues_when_off' => ['type' => 'boolean', 'description' => 'Module attendance_retention: the purge keeps running while the visitor log is off or in catalogue mode.'],
        ]);
        $doc['components']['schemas']['AttendanceVisit'] = O::object([
            'id' => ['type' => 'integer', 'description' => 'Visit id.'],
            'type' => ['type' => 'string', 'enum' => ['member', 'guest']],
            'created_at' => ['type' => 'string', 'description' => 'Library wall-clock time, Y-m-d H:i:s.'],
            'branch' => ['type' => ['object', 'null'], 'properties' => ['id' => $i, 'name' => $s]],
            'member' => ['type' => ['object', 'null'], 'description' => 'type=member. Null for a guest, or when the account is outside the operator\'s reach.', 'properties' => [
                'id' => $i,
                'short_name' => ['type' => 'string', 'description' => '"Mario R.": the kiosk shows as little as possible.'],
                'card_code' => $s,
                'account_status' => ['type' => 'string', 'enum' => ['attivo', 'sospeso', 'scaduto']],
                'card_active' => ['type' => 'boolean', 'description' => 'account_status = attivo. The visit is recorded either way, as at the kiosk.'],
                'card_expires_at' => O::nullable('string', 'date'),
                'card_expired' => ['type' => 'boolean', 'description' => 'card_expires_at is before today (informative: the kiosk does not refuse it).'],
                'has_photo' => $b,
                'photo_url' => ['type' => ['string', 'null'], 'description' => 'Authorised GET /staff/users/{id}/photo URL (bearer token); null while patron_photos is off or there is no photo.'],
            ]],
            'guest' => ['type' => ['object', 'null'], 'properties' => ['name' => O::nullable('string'), 'reason' => O::nullable('string')]],
            'warning' => ['type' => ['string', 'null'], 'enum' => ['card_not_active', null], 'description' => 'card_not_active: recorded, but the account is suspended or expired (show it to the operator).'],
            'message' => ['type' => 'string', 'description' => 'The kiosk\'s localized confirmation.'],
        ]);
        $doc['components']['schemas']['AttendanceOverview'] = O::object([
            'days' => ['type' => 'integer', 'description' => 'Selected period.'],
            'periods' => ['type' => 'array', 'items' => $i, 'description' => '[7, 30, 90, 365].'],
            'today' => $counts,
            'totals' => array_merge($counts, ['description' => 'Over the period.']),
            'daily' => ['type' => 'array', 'description' => 'One row per day of the period, oldest first, zero rows included.', 'items' => O::object(['date' => ['type' => 'string', 'format' => 'date'], 'members' => $i, 'guests' => $i, 'total' => $i])],
            'hourly' => ['type' => 'array', 'description' => '24 rows, hour 0..23, over the period.', 'items' => O::object(['hour' => $i, 'total' => $i])],
            'recent' => ['type' => 'array', 'description' => 'Last 10 visits, newest first.', 'items' => O::object([
                'created_at' => $s, 'type' => ['type' => 'string', 'enum' => ['member', 'guest']],
                'label' => ['type' => 'string', 'description' => 'Member "Mario R." (empty outside the operator\'s reach) or the guest name.'],
            ])],
            'branches' => ['type' => 'array', 'description' => 'Active branches for the picker; empty on a single-site library.', 'items' => O::object(['id' => $i, 'name' => $s])],
            'retention' => $retention,
            'can_configure' => ['type' => 'boolean', 'description' => 'Administrator: may change the retention and purge.'],
        ]);

        $days = O::query('days', 'integer', '7 | 30 | 90 | 365 (default 30).');
        $key = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
            'description' => 'Recommended: a retried request with the same key and body replays the visit (200, meta.replayed=true) instead of recording it twice; same key with another body = 422 idempotency_conflict.',
            'schema' => ['type' => 'string', 'maxLength' => 128]];
        $unavailable = ['503' => O::err('`not_available`: the 0.9.0 database update has not run yet.')];
        $visit = static fn (string $what): array => [
            '201' => O::ok($what, O::ref('AttendanceVisit')),
            '200' => O::ok('Replay of the same Idempotency-Key (meta.replayed=true).', O::ref('AttendanceVisit')),
        ];

        $doc['paths']['/staff/attendance'] = ['get' => O::op($t, 'staffAttendance', 'Visitor log dashboard (visitor_log)', [
            '200' => O::ok('Counters, the day / hour-slot series, the latest visits, branches and the retention.', O::ref('AttendanceOverview')),
            '422' => O::err('`validation` (meta.fields.days: not one of the periods).'),
        ] + $unavailable, ['parameters' => [$days]])];
        $doc['paths']['/staff/attendance/check-ins'] = ['post' => O::op($t, 'staffAttendanceCheckIn', 'Record a member visit from the scanned card (visitor_log, 120/min)', $visit('Recorded. member.card_active=false / warning=card_not_active when the account is suspended or expired: the kiosk records it with a warning.') + [
            '404' => O::err('`card_not_found`: no account with this card code in the operator\'s reach (nothing recorded; offer the guest form). `feature_disabled`.'),
            '409' => O::err('`concurrent_retry`: the same key is being processed.'),
            '422' => O::err('`validation` (meta.fields: card_code empty; branch_id not an active branch; idempotency_key malformed); `idempotency_conflict`.'),
        ] + $unavailable, [
            'parameters' => [$key],
            'requestBody' => O::inlineBody([
                'card_code' => ['type' => 'string', 'description' => 'The library card code as scanned (Code128 / QR on the card); surrounding blanks ignored, exact match.'],
                'branch_id' => ['type' => ['integer', 'null'], 'description' => 'Optional: an id from branches.'],
            ], ['card_code']),
        ])];
        $doc['paths']['/staff/attendance/guests'] = ['post' => O::op($t, 'staffAttendanceGuest', 'Record a guest visit (visitor_log, 120/min)', $visit('Recorded (type=guest).') + [
            '409' => O::err('`concurrent_retry`.'),
            '422' => O::err('`validation` (meta.fields: name / reason not a string; branch_id not an active branch; idempotency_key); `idempotency_conflict`.'),
        ] + $unavailable, [
            'parameters' => [$key],
            'requestBody' => O::inlineBody([
                'name' => ['type' => ['string', 'null'], 'description' => 'Optional, kept to 150 characters.'],
                'reason' => ['type' => ['string', 'null'], 'description' => 'Optional, kept to 255 characters.'],
                'branch_id' => ['type' => ['integer', 'null']],
            ]),
        ])];
        $doc['paths']['/staff/attendance/export.csv'] = ['get' => O::op($t, 'staffAttendanceExport', 'Daily counts of the period as CSV (visitor_log)', [
            '200' => O::file('text/csv', 'UTF-8 with BOM, ";" separated: date, members, guests, total. Content-Disposition: attachment; filename="presenze_{days}g.csv".'),
            '422' => O::err('`validation` (meta.fields.days).'),
        ] + $unavailable, ['parameters' => [$days]])];
        $doc['paths']['/staff/attendance/settings'] = ['put' => O::op($t, 'staffAttendanceSettings', 'Set the retention (administrators, visitor_log)', [
            '200' => O::ok('Saved.', O::object(['retention' => $retention, 'message' => $s])),
            '403' => O::err('`not_permitted`: Staff account (the web setting is administrator-only; see can_configure).'),
            '422' => O::err('`validation` (meta.fields.retention_days: not an integer 0..3650).'),
        ], ['requestBody' => O::inlineBody(['retention_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650, 'description' => '0 = keep forever.']], ['retention_days'])])];
        $doc['paths']['/staff/attendance/purge'] = ['post' => O::op($t, 'staffAttendancePurge', 'Delete the visits older than the retention now (administrators, visitor_log)', [
            '200' => O::ok('Done (0 with retention 0). Not idempotent in effect: a second call deletes what has aged since.', O::object(['deleted' => $i, 'retention' => $retention, 'message' => $s])),
            '403' => O::err('`not_permitted`: Staff account.'),
        ] + $unavailable)];

        return $doc;
    }
}
