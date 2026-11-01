<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Plugins\MobileApi\Support\OpenApiParts as O;

/**
 * OpenAPI fragments of the library settings areas (Mobile API 1.10.0):
 * tags `staff-settings`, `staff-circulation-rules`, `staff-calendar`.
 * Every path has one row in tests/mobile-api-idempotency.spec.js.
 */
final class LibrarySettingsOpenApi
{
    private function __construct()
    {
    }

    // ─── Shared bits ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function int(?string $description = null, bool $nullable = false): array
    {
        $schema = $nullable ? O::nullable('integer') : ['type' => 'integer'];

        return $description === null ? $schema : $schema + ['description' => $description];
    }

    /** @return array<string, mixed> */
    private static function bool(?string $description = null): array
    {
        return $description === null ? ['type' => 'boolean'] : ['type' => 'boolean', 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function str(?string $description = null, bool $nullable = false): array
    {
        $schema = $nullable ? O::nullable('string') : ['type' => 'string'];

        return $description === null ? $schema : $schema + ['description' => $description];
    }

    /** @return array<string, mixed> */
    private static function cents(string $description): array
    {
        return O::nullable('integer') + ['minimum' => 0, 'maximum' => 999999, 'description' => $description . ' Integer cents in `currency`; null = not set (inherit / disabled).'];
    }

    /** @return array<string, mixed> */
    private static function limits(): array
    {
        return ['type' => 'object', 'description' => 'field => {min, max} of the integer fields; `money_max_cents` caps every amount.',
            'additionalProperties' => O::object(['min' => ['type' => 'integer'], 'max' => ['type' => 'integer']])];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, string> $flags
     */
    private static function healthFlags(array &$doc, array $flags): void
    {
        foreach ($flags as $flag => $description) {
            $doc['components']['schemas']['HealthPayload']['properties']['features']['properties'][$flag] = ['type' => 'boolean', 'description' => $description];
        }
    }

    /** @return array<string, mixed> */
    private static function idemHeader(): array
    {
        return ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
            'description' => 'Client-generated key (1..128 of A-Z a-z 0-9 . _ : -), stable across retries of the same create. Also accepted as body `idempotency_key`. Remembered 24 h per operator.',
            'schema' => ['type' => 'string', 'maxLength' => 128]];
    }

    /** @return array<int|string, mixed> */
    private static function invalid(): array
    {
        return ['422' => O::err('`validation`: meta.fields = {field: message} (dotted paths for nested items, e.g. `cells.0.max_loans`). A malformed Idempotency-Key answers `validation_error` (meta.field) or `idempotency_conflict`.')];
    }

    /** @return array<int|string, mixed> */
    private static function adminGate(string $module): array
    {
        return [
            '403' => O::err('`not_permitted`: the caller may not configure the library (only administrators, or a custom role with settings.manage; Staff accounts are refused, as on the web).'),
            '404' => O::err('`feature_disabled`: the `' . $module . '` module is off and the caller may not configure it (an administrator still gets 200: configuration mode). `not_found` for an unknown id.'),
        ];
    }

    // ─── Settings ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function settings(array $doc): array
    {
        $t = 'staff-settings';
        $doc['tags'][] = ['name' => $t, 'description' => 'Settings → Modules (feature switches), → Loans and → Cards of the 0.9.0 modules. `can_configure` tells whether the caller may change library policy.'];
        self::healthFlags($doc, ['staff_library_settings' => 'The /staff/settings/modules, /loans and /cards routes are mounted (always true on Mobile API 1.10.0+).']);

        $doc['components']['schemas']['StaffModulesSettings'] = O::object([
            'can_configure' => self::bool('True for administrators: only they may PUT.'),
            'catalogue_mode' => self::bool('Catalogue mode is on: every circulation module is effectively off whatever its switch.'),
            'attendance_retention_days' => self::int('Visitor log retention (0 = keep forever).'),
            'attendance_retention_max_days' => self::int(),
            'modules' => ['type' => 'array', 'items' => O::object([
                'key' => self::str('Features key (also a /health flag).'),
                'label' => self::str('Localized label.'),
                'help' => self::str('Localized help text.'),
                'group' => ['type' => 'string', 'enum' => ['modules', 'loans'], 'description' => 'The web tab that shows the switch.'],
                'stored' => self::bool('The saved switch.'),
                'effective' => self::bool('Whether the module works now (catalogue mode and parent module applied).'),
                'parent' => self::str('Module this control also requires, if any.', true),
                'inherits_from' => self::str('Former parent whose value a never-saved control reproduces.', true),
                'circulation' => self::bool('Circulation module: off in catalogue mode.'),
            ])],
        ]);
        $loanSettings = O::object([
            'loan_duration_days' => self::int(), 'pickup_expiry_days' => self::int(), 'pickup_reminder_days' => self::int('0 = off.'),
            'card_expiry_warning_days' => self::int('0 = off.'), 'max_renewals' => self::int('0 = no renewals.'),
            'max_active_loans_per_user' => self::int('0 = unlimited.'), 'max_loan_duration_days' => self::int(),
            'auto_approve_requests' => self::bool(), 'allow_multiple_loans_same_book' => self::bool(),
            'recall_auto_enabled' => self::bool(), 'recall_interval_days' => self::int(), 'recall_max_count' => self::int(),
            'app_timezone' => self::str('IANA identifier.'),
            'daily_fine_cents' => self::cents('Daily fine (admin only).'),
            'fine_max_per_loan_cents' => self::cents('Fine cap per loan (admin only).'),
            'debt_block_threshold_cents' => self::cents('Debt that blocks new loans (admin only).'),
            'fine_grace_days' => self::int('Admin only.'),
            'fine_grace_mode' => ['type' => 'string', 'enum' => ['threshold', 'deduct'], 'description' => 'Admin only.'],
            'card_renewal_base' => ['type' => 'string', 'enum' => ['today', 'expiry'], 'description' => 'Admin only.'],
            'fine_charged_email' => self::bool('Admin only.'),
        ]);
        $doc['components']['schemas']['StaffLoanSettingsValues'] = $loanSettings;
        $doc['components']['schemas']['StaffLoanSettings'] = O::object([
            'can_configure' => self::bool(),
            'currency' => self::str('ISO 4217 code of the *_cents amounts.'),
            'settings' => O::ref('StaffLoanSettingsValues'),
            'editable_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Fields this caller may PUT.'],
            'admin_only_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            'limits' => self::limits(),
            'options' => O::object(['fine_grace_mode' => ['type' => 'array', 'items' => ['type' => 'string']], 'card_renewal_base' => ['type' => 'array', 'items' => ['type' => 'string']]]),
        ]);
        $cardValues = O::object([
            'layout' => ['type' => 'string', 'enum' => ['classic', 'minimal', 'portrait']],
            'code_type' => ['type' => 'string', 'enum' => ['code128', 'qr']],
            'format' => ['type' => 'string', 'enum' => ['single', 'sheet']],
            'title' => ['type' => 'string', 'maxLength' => 60],
            'back_text' => ['type' => 'string', 'maxLength' => 600],
            'show_photo' => self::bool(), 'show_logo' => self::bool(), 'show_expiry' => self::bool(),
        ]);
        $doc['components']['schemas']['StaffCardSettingsValues'] = $cardValues;
        $doc['components']['schemas']['StaffCardSettings'] = O::object([
            'can_configure' => self::bool('Always true when the route answers 200.'),
            'module_enabled' => self::bool('patron_cards is on (an administrator may edit while it is off).'),
            'settings' => O::ref('StaffCardSettingsValues'),
            'options' => O::object(['layout' => ['type' => 'array', 'items' => ['type' => 'string']], 'code_type' => ['type' => 'array', 'items' => ['type' => 'string']], 'format' => ['type' => 'array', 'items' => ['type' => 'string']]]),
            'limits' => O::object(['title_max' => self::int(), 'back_text_max' => self::int()]),
        ]);

        $doc['paths']['/staff/settings/modules'] = [
            'get' => O::op($t, 'staffSettingsModules', 'Every 0.9.0 module switch: saved value, effective value, group, parent.', ['200' => O::ok('The switches.', O::ref('StaffModulesSettings'))]),
            'put' => O::op($t, 'staffSettingsModulesUpdate', 'Change some switches (administrators). Keys left out keep their value; a Loans-tab key is saved through the loans form with every other loan setting kept.', [
                '200' => O::ok('The switches after the save.', O::ref('StaffModulesSettings')),
                '403' => O::err('`not_permitted`: Staff accounts may not change modules.'),
            ] + self::invalid(), ['requestBody' => O::inlineBody([
                'features' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean'], 'description' => 'Partial map Features key => on/off.'],
                'attendance_retention_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650],
            ])]),
        ];
        $doc['paths']['/staff/settings/loans'] = [
            'get' => O::op($t, 'staffSettingsLoans', 'Settings → Loans values, with the fields this caller may change.', ['200' => O::ok('The loan settings.', O::ref('StaffLoanSettings'))]),
            'put' => O::op($t, 'staffSettingsLoansUpdate', 'Partial save of Settings → Loans. Staff may change every field except the admin-only ones (money policy, grace, card renewal base, fine email); omitted fields keep their stored value.', [
                '200' => O::ok('The loan settings after the save.', O::ref('StaffLoanSettings')),
                '403' => O::err('`not_permitted`: an admin-only field was sent by a Staff account (meta.fields names them); nothing is saved.'),
            ] + self::invalid(), ['requestBody' => O::body('StaffLoanSettingsValues')]),
        ];
        $doc['paths']['/staff/settings/cards'] = [
            'get' => O::op($t, 'staffSettingsCards', 'Library card print settings (Settings → Cards).', [
                '200' => O::ok('The card settings.', O::ref('StaffCardSettings')),
                '404' => O::err('`feature_disabled`: patron_cards is off and the caller is not an administrator.'),
            ]),
            'put' => O::op($t, 'staffSettingsCardsUpdate', 'Partial save of the card settings (Staff may save while patron_cards is on).', [
                '200' => O::ok('The card settings after the save.', O::ref('StaffCardSettings')),
                '404' => O::err('`feature_disabled`: patron_cards is off and the caller is not an administrator.'),
            ] + self::invalid(), ['requestBody' => O::body('StaffCardSettingsValues')]),
        ];

        return $doc;
    }

    // ─── Patron types and rules ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function circulation(array $doc): array
    {
        $t = 'staff-circulation-rules';
        $doc['tags'][] = ['name' => $t, 'description' => 'Patron types and the rules matrix patron type × material (Settings → Circulation). Administrators only; reachable by them even while circulation_rules is off (configuration mode).'];
        self::healthFlags($doc, ['staff_circulation_rules' => 'The /staff/circulation/types and /rules routes are mounted (always true on Mobile API 1.10.0+). They answer administrators even while the module is off (configuration mode); whether the module is on is the `circulation_rules` flag. Staff accounts get 403, or 404 feature_disabled while the module is off.']);

        $ints = [
            'max_loans' => self::int('Max active loans (0..1000); null = inherit.', true),
            'loan_duration_days' => self::int('1..3650; null = inherit.', true),
            'max_request_duration_days' => self::int('1..3650; null = inherit.', true),
            'max_renewals' => self::int('0..100; null = inherit.', true),
            'renewal_days' => self::int('1..3650; null = inherit.', true),
            'max_reservations' => self::int('0..1000; null = inherit.', true),
            'card_duration_days' => self::int('1..36500; null = no automatic expiry from the type.', true),
            'grace_days' => self::int('0..365; null = inherit.', true),
            'daily_fine_cents' => self::cents('Daily fine.'),
            'max_fine_per_loan_cents' => self::cents('Fine cap per loan.'),
            'debt_block_threshold_cents' => self::cents('Debt that blocks loans.'),
        ];
        $effective = O::object([
            'max_loans' => self::int(), 'loan_duration_days' => self::int(), 'max_request_duration_days' => self::int(),
            'max_renewals' => self::int(), 'renewal_days' => self::int(), 'reservations_enabled' => self::bool(),
            'max_reservations' => self::int(), 'card_duration_days' => self::int(null, true), 'grace_days' => self::int(null, true),
            'daily_fine_cents' => self::int(null, true), 'max_fine_per_loan_cents' => self::int(null, true), 'debt_block_threshold_cents' => self::int(null, true),
        ]);
        $doc['components']['schemas']['StaffPatronType'] = O::object([
            'id' => self::int(), 'code' => self::str(), 'name' => self::str(), 'description' => self::str(null, true),
            'reservations_enabled' => self::bool(), 'active' => self::bool(), 'is_default' => self::bool('New patrons get the default type; it cannot be deleted.'),
            'order' => self::int(), 'user_count' => self::int('Patrons assigned to this type.'),
        ] + $ints + ['effective' => $effective + ['description' => 'What applies to a patron of this type, every layer resolved.']]);
        $doc['components']['schemas']['StaffPatronTypeInput'] = O::object([
            'name' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Required on create.'],
            'code' => ['type' => ['string', 'null'], 'maxLength' => 40, 'pattern' => '^[a-z0-9][a-z0-9_-]{0,39}$', 'description' => 'Derived from the name when empty.'],
            'description' => self::str(null, true), 'reservations_enabled' => self::bool('Default true on create.'),
            'active' => self::bool('Default true; the default type stays active.'), 'is_default' => self::bool('true makes it the default type (false has no effect).'),
            'idempotency_key' => self::str('Alternative to the Idempotency-Key header (create only).'),
        ] + $ints);
        $doc['components']['schemas']['StaffPatronTypes'] = O::object([
            'can_configure' => self::bool(), 'module_enabled' => self::bool(), 'currency' => self::str(),
            'default_type_id' => self::int(null, true),
            'globals' => $effective + ['description' => 'Settings → Loans values every empty field inherits.'],
            'limits' => self::limits(),
            'types' => O::listOf('StaffPatronType'),
        ]);
        $cellValues = [
            'loanable' => O::nullable('boolean') + ['description' => 'null = inherit.'],
            'max_loans' => self::int('0..1000; null = inherit.', true),
            'loan_duration_days' => self::int('1..3650; null = inherit.', true),
            'max_renewals' => self::int('0..100; null = inherit.', true),
            'renewal_days' => self::int('1..3650; null = inherit.', true),
            'grace_days' => self::int('0..365; null = inherit.', true),
            'daily_fine_cents' => self::cents('Daily fine.'),
            'max_fine_per_loan_cents' => self::cents('Fine cap per loan.'),
        ];
        $doc['components']['schemas']['StaffRuleCell'] = O::object([
            'type_id' => self::int('Patron type; 0 = every type.'), 'media' => self::str('Material type key.'),
            'has_rule' => self::bool('A rule row is stored for this cell.'),
        ] + $cellValues + ['inherited' => O::object([
            'loanable' => self::bool(), 'max_loans' => self::int(), 'loan_duration_days' => self::int(), 'max_renewals' => self::int(),
            'renewal_days' => self::int(), 'grace_days' => self::int(), 'daily_fine_cents' => self::int(null, true), 'max_fine_per_loan_cents' => self::int(null, true),
        ]) + ['description' => 'What an empty value of this cell resolves to.']]);
        $doc['components']['schemas']['StaffCirculationRules'] = O::object([
            'can_configure' => self::bool(), 'module_enabled' => self::bool(), 'currency' => self::str(),
            'media' => ['type' => 'array', 'items' => O::object(['key' => self::str(), 'label' => self::str()])],
            'rows' => ['type' => 'array', 'items' => O::object(['type_id' => self::int('0 = every type.'), 'name' => self::str()])],
            'limits' => self::limits(),
            'cells' => O::listOf('StaffRuleCell'),
        ]);

        $id = ['parameters' => [O::path('id', 'Patron type id')]];
        $gate = self::adminGate('circulation_rules');
        $doc['paths']['/staff/circulation/types'] = [
            'get' => O::op($t, 'staffPatronTypes', 'Patron types with user counts, effective values and the inherited globals.', ['200' => O::ok('The patron types.', O::ref('StaffPatronTypes'))] + $gate),
            'post' => O::op($t, 'staffPatronTypeCreate', 'Create a patron type (idempotent with Idempotency-Key).', [
                '201' => O::ok('The new type.', O::ref('StaffPatronType')),
                '200' => O::ok('Replay of a create with the same Idempotency-Key and payload (meta.replayed=true).', O::ref('StaffPatronType')),
            ] + $gate + self::invalid(), ['parameters' => [self::idemHeader()], 'requestBody' => O::body('StaffPatronTypeInput')]),
        ];
        $doc['paths']['/staff/circulation/types/{id}'] = [
            'get' => O::op($t, 'staffPatronType', 'One patron type.', ['200' => O::ok('The type.', O::ref('StaffPatronType'))] + $gate, $id),
            'put' => O::op($t, 'staffPatronTypeUpdate', 'Partial update: omitted fields keep their value, null clears (inherit).', ['200' => O::ok('The type.', O::ref('StaffPatronType'))] + $gate + self::invalid(), $id + ['requestBody' => O::body('StaffPatronTypeInput')]),
            'delete' => O::op($t, 'staffPatronTypeDelete', 'Delete a type: its patrons fall back to the default type, its rules are removed.', [
                '200' => O::ok('Deleted.', O::object(['id' => self::int(), 'deleted' => self::bool(), 'users_reassigned' => self::int()])),
                '409' => O::err('`default_type`: the default type cannot be deleted (choose another default first).'),
            ] + $gate, $id),
        ];
        $doc['paths']['/staff/circulation/types/{id}/default'] = [
            'post' => O::op($t, 'staffPatronTypeDefault', 'Make this the default type (it becomes active).', ['200' => O::ok('The type.', O::ref('StaffPatronType'))] + $gate, $id),
        ];
        $doc['paths']['/staff/circulation/types/{id}/move'] = [
            'post' => O::op($t, 'staffPatronTypeMove', 'Move a type one place up or down (no-op at either end).', ['200' => O::ok('Every type in the new order.', O::ref('StaffPatronTypes'))] + $gate + self::invalid(),
                $id + ['requestBody' => O::inlineBody(['direction' => ['type' => 'string', 'enum' => ['up', 'down']]], ['direction'])]),
        ];
        $doc['paths']['/staff/circulation/rules'] = [
            'get' => O::op($t, 'staffCirculationRules', 'The complete rules matrix (every type incl. 0 = all types × every material), with inherited values.', ['200' => O::ok('The matrix.', O::ref('StaffCirculationRules'))] + $gate),
            'put' => O::op($t, 'staffCirculationRulesUpdate', 'Change some cells. Cells and fields left out keep their stored value; null clears a value; a cell whose values are all null is removed.', ['200' => O::ok('The matrix after the save.', O::ref('StaffCirculationRules'))] + $gate + self::invalid(),
                ['requestBody' => O::inlineBody(['cells' => ['type' => 'array', 'items' => O::object(['type_id' => self::int(), 'media' => self::str()] + $cellValues)]], ['cells'])]),
        ];

        return $doc;
    }

    // ─── Closures calendar ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function calendar(array $doc): array
    {
        $t = 'staff-calendar';
        $doc['tags'][] = ['name' => $t, 'description' => 'Closures calendar: weekly closed days, closures, national holidays, the library\'s own holidays, ICS import. Administrators only; reachable by them even while closures_calendar is off (configuration mode).'];
        self::healthFlags($doc, ['staff_closures_calendar' => 'The /staff/calendar routes are mounted (always true on Mobile API 1.10.0+). They answer administrators even while the module is off (configuration mode); whether the calendar is applied is the `closures_calendar` flag. Staff accounts get 403, or 404 feature_disabled while the module is off.']);

        $closure = O::object([
            'id' => self::int(), 'start_date' => self::str('YYYY-MM-DD'), 'end_date' => self::str('YYYY-MM-DD'),
            'description' => self::str(), 'yearly' => self::bool('Repeats its month-day span every year.'),
            'branch_id' => self::int('null = every branch.', true), 'branch_name' => self::str(null, true),
            'preset_rule' => self::str('National holiday rule id of a preset row (changed only through the preset).', true),
            'active' => self::bool('A switched-off yearly closure closes no day.'), 'editable' => self::bool('false for preset rows.'),
        ]);
        $doc['components']['schemas']['StaffCalendarClosure'] = $closure;
        $customValues = [
            'name' => ['type' => 'string', 'maxLength' => 255],
            'kind' => ['type' => 'string', 'enum' => ['fixed', 'nth_weekday', 'easter', 'orthodox_easter', 'calendar', 'dates']],
            'month' => self::int('fixed / nth_weekday: 1..12; calendar: month of that calendar.', true),
            'day' => self::int('fixed: day of month; calendar: 1..30.', true),
            'weekday' => self::int('nth_weekday: 0 = Sunday … 6 = Saturday.', true),
            'occurrence' => self::int('nth_weekday: 1..5, or -1 = last.', true),
            'offset_days' => self::int('easter / orthodox_easter: -200..200.', true),
            'calendar' => self::str('calendar: a code of custom_holiday_options.calendars.', true),
            'dates' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'dates: YYYY-MM-DD list (max 400).'],
            'duration_days' => self::int('1..14, default 1.'),
            'weekend_rule' => O::nullable('string') + ['enum' => ['next_monday', 'us', null], 'description' => 'Observed on a weekday when it falls on a weekend (only with duration 1).'],
            'year_from' => self::int('1900..2199.', true), 'year_until' => self::int('1900..2199.', true),
            'branch_id' => self::int('null / 0 = every branch.', true),
            'active' => self::bool('Default true on create.'),
        ];
        $doc['components']['schemas']['StaffCustomHolidayInput'] = O::object($customValues + ['idempotency_key' => self::str('Alternative to the Idempotency-Key header (create only).')]);
        $doc['components']['schemas']['StaffCustomHoliday'] = O::object(['id' => self::int()] + $customValues + [
            'branch_name' => self::str(null, true),
            'description' => self::str('How the date is computed, localized.'),
            'next_dates' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The next three closed days.'],
        ]);
        $holiday = O::object([
            'id' => self::str('Rule id (COUNTRY:rule).'), 'name' => self::str(), 'name_en' => self::str(), 'date' => self::str('Date in the preview year; null when it does not occur.', true),
            'dates' => ['type' => 'array', 'items' => ['type' => 'string']], 'yearly' => self::bool(), 'regional' => self::bool(),
            'optional' => self::bool(), 'category' => self::str(), 'estimated' => self::bool(), 'checked' => self::bool('Ticked in the saved selection (statutory holidays for a country not saved yet).'),
        ]);
        $preset = O::object([
            'country' => self::str(), 'country_name' => self::str(), 'region' => self::str(),
            'regions' => ['type' => 'array', 'items' => O::object(['code' => self::str(), 'name' => self::str()])],
            'year' => self::int(), 'years' => O::object(['min' => self::int(), 'max' => self::int()]),
            'data_years' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer'], 'description' => '[first, last] year of a dataset country.'],
            'saved' => self::bool('This country / region is the saved selection.'), 'schema_ready' => self::bool(),
            'missing' => ['type' => 'array', 'items' => ['type' => 'string']], 'unknown_country' => self::str(),
            'holidays' => ['type' => 'array', 'items' => $holiday],
        ]);
        $doc['components']['schemas']['StaffHolidayPreset'] = $preset;
        $doc['components']['schemas']['StaffCalendar'] = O::object([
            'can_configure' => self::bool(), 'module_enabled' => self::bool(), 'today' => self::str(),
            'month' => self::str('YYYY-MM'), 'prev_month' => self::str(), 'next_month' => self::str(),
            'branch_id' => self::int('Branch the grid is computed for; null = the library.', true),
            'days' => ['type' => 'array', 'items' => O::object([
                'date' => self::str(), 'weekday' => self::int('0 = Sunday.'), 'closed' => self::bool(),
                'reasons' => ['type' => 'array', 'items' => O::object([
                    'type' => ['type' => 'string', 'enum' => ['weekly', 'closure', 'holiday', 'custom_holiday']],
                    'id' => ['type' => ['integer', 'string', 'null']], 'name' => self::str(),
                ])],
            ])],
            'closed_weekdays' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'closures' => O::listOf('StaffCalendarClosure'),
            'custom_holidays_available' => self::bool(),
            'custom_holidays' => O::listOf('StaffCustomHoliday'),
            'custom_holiday_options' => O::object([
                'kinds' => ['type' => 'array', 'items' => ['type' => 'string']], 'weekend_rules' => ['type' => 'array', 'items' => ['type' => 'string']],
                'occurrences' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'calendars' => ['type' => 'array', 'items' => O::object(['code' => self::str(), 'label' => self::str(), 'available' => self::bool(), 'months' => ['type' => 'array', 'items' => ['type' => 'string']]])],
                'max_duration_days' => self::int(), 'max_dates' => self::int(),
            ]),
            'holiday_preset' => O::ref('StaffHolidayPreset'),
            'branches' => ['type' => 'array', 'items' => O::object(['id' => self::int(), 'name' => self::str()])],
            'limits' => O::object(['yearly_span_days' => self::int(), 'once_span_days' => self::int(), 'ics_max_bytes' => self::int()]),
        ]);

        $gate = self::adminGate('closures_calendar');
        $conflict = ['409' => O::err('`calendar_always_closed` (the change would leave no open day in a year), `concurrent_retry` (another calendar change is running: retry), `preset_closure` (a preset row), `schema_not_ready`.')];
        $closureId = ['parameters' => [O::path('id', 'Closure id')]];
        $customId = ['parameters' => [O::path('id', 'Custom holiday id')]];
        $closureBody = O::inlineBody([
            'start_date' => self::str('YYYY-MM-DD; required on create.'), 'end_date' => self::str('YYYY-MM-DD; default = start_date.', true),
            'description' => ['type' => 'string', 'maxLength' => 255], 'yearly' => self::bool(), 'branch_id' => self::int('null / 0 = every branch.', true),
            'idempotency_key' => self::str('Create only.'),
        ]);
        $calendarOk = O::ok('The calendar.', O::ref('StaffCalendar'));

        $doc['paths']['/staff/calendar'] = [
            'get' => O::op($t, 'staffCalendar', 'Month grid with closed days and their reasons, weekly closed days, closures, custom holidays, holiday preset state and branches.', ['200' => $calendarOk] + $gate + self::invalid(), ['parameters' => [
                O::query('month', 'string', 'YYYY-MM (default: this month).'),
                O::query('branch_id', 'integer', 'Compute the grid for this branch.'),
                O::query('holiday_country', 'string', 'Preview the preset of this country (default: the saved one).'),
                O::query('holiday_region', 'string', 'Region code of the previewed country.'),
                O::query('holiday_year', 'integer', 'Year of the preset preview.'),
            ]]),
        ];
        $doc['paths']['/staff/calendar/weekdays'] = [
            'put' => O::op($t, 'staffCalendarWeekdays', 'Replace the weekly closed days (empty list = open every day; at least one day must stay open).', ['200' => $calendarOk] + $gate + self::invalid() + $conflict,
                ['requestBody' => O::inlineBody(['closed_weekdays' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6], 'description' => '0 = Sunday … 6 = Saturday.']], ['closed_weekdays'])]),
        ];
        $doc['paths']['/staff/calendar/closures'] = [
            'post' => O::op($t, 'staffCalendarClosureCreate', 'Add a closure (idempotent with Idempotency-Key). Pending pickups whose deadline falls on it move to the next open day.', [
                '201' => O::ok('The closure.', O::ref('StaffCalendarClosure')),
                '200' => O::ok('Replay (meta.replayed=true).', O::ref('StaffCalendarClosure')),
            ] + $gate + self::invalid() + $conflict, ['parameters' => [self::idemHeader()], 'requestBody' => $closureBody]),
        ];
        $doc['paths']['/staff/calendar/closures/{id}'] = [
            'put' => O::op($t, 'staffCalendarClosureUpdate', 'Partial update of a closure (not a preset row).', ['200' => O::ok('The closure.', O::ref('StaffCalendarClosure'))] + $gate + self::invalid() + $conflict, $closureId + ['requestBody' => $closureBody]),
            'delete' => O::op($t, 'staffCalendarClosureDelete', 'Delete a closure (not a preset row).', ['200' => O::ok('Deleted.', O::object(['id' => self::int(), 'deleted' => self::bool()]))] + $gate + $conflict, $closureId),
        ];
        $doc['paths']['/staff/calendar/holidays/countries'] = [
            'get' => O::op($t, 'staffCalendarHolidayCountries', 'Countries with holiday presets, and the preview of one country / region / year.', ['200' => O::ok('Countries and preview.', ['allOf' => [O::ref('StaffHolidayPreset')], 'properties' => [
                'countries' => ['type' => 'array', 'items' => O::object(['code' => self::str(), 'name' => self::str()])],
            ]])] + $gate, ['parameters' => [
                O::query('country', 'string', 'ISO country code (default: the saved one).'),
                O::query('region', 'string', 'Region code.'),
                O::query('year', 'integer', 'Preview year.'),
            ]]),
        ];
        $doc['paths']['/staff/calendar/holidays/preset'] = [
            'post' => O::op($t, 'staffCalendarHolidayPreset', 'Save the national holiday selection: unticked holidays are removed, ticked ones added; manual and ICS closures are untouched.', [
                '200' => O::ok('Applied.', O::object(['message' => self::str('Localized summary (added / removed / adopted).'), 'holiday_preset' => O::ref('StaffHolidayPreset')])),
            ] + $gate + self::invalid() + $conflict, ['requestBody' => O::inlineBody([
                'country' => self::str('ISO code.'), 'region' => self::str(null, true),
                'rules' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ticked holiday ids (holidays[].id).'],
            ], ['country', 'rules'])]),
        ];
        $doc['paths']['/staff/calendar/holidays/yearly'] = [
            'put' => O::op($t, 'staffCalendarYearly', 'Switch yearly closures added by hand (or from a file) on and off without deleting them.', [
                '200' => O::ok('Saved.', O::object(['changed' => self::int(), 'yearly_closures' => O::listOf('StaffCalendarClosure')])),
            ] + $gate + self::invalid() + $conflict, ['requestBody' => O::inlineBody([
                'closures' => ['type' => 'array', 'items' => O::object(['id' => self::int(), 'active' => self::bool()])],
            ], ['closures'])]),
        ];
        $doc['paths']['/staff/calendar/custom-holidays'] = [
            'post' => O::op($t, 'staffCalendarCustomHolidayCreate', 'Add a holiday of the library\'s own (idempotent with Idempotency-Key).', [
                '201' => O::ok('The holiday.', O::ref('StaffCustomHoliday')),
                '200' => O::ok('Replay (meta.replayed=true).', O::ref('StaffCustomHoliday')),
            ] + $gate + self::invalid() + $conflict, ['parameters' => [self::idemHeader()], 'requestBody' => O::body('StaffCustomHolidayInput')]),
        ];
        $doc['paths']['/staff/calendar/custom-holidays/{id}'] = [
            'put' => O::op($t, 'staffCalendarCustomHolidayUpdate', 'Partial update of a custom holiday.', ['200' => O::ok('The holiday.', O::ref('StaffCustomHoliday'))] + $gate + self::invalid() + $conflict, $customId + ['requestBody' => O::body('StaffCustomHolidayInput')]),
            'delete' => O::op($t, 'staffCalendarCustomHolidayDelete', 'Delete a custom holiday.', ['200' => O::ok('Deleted.', O::object(['id' => self::int(), 'deleted' => self::bool()]))] + $gate, $customId),
        ];
        $doc['paths']['/staff/calendar/custom-holidays/{id}/toggle'] = [
            'post' => O::op($t, 'staffCalendarCustomHolidayToggle', 'Suspend or reactivate a custom holiday.', ['200' => O::ok('The holiday.', O::ref('StaffCustomHoliday'))] + $gate, $customId),
        ];
        $doc['paths']['/staff/calendar/import-ics'] = [
            'post' => O::op($t, 'staffCalendarImportIcs', 'Import closures from an ICS file (≤ 1 MB). Events already present are skipped; over-long events and those that would close a whole year are discarded.', [
                '200' => O::ok('Imported.', O::object(['added' => self::int(), 'added_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'message' => self::str('Localized summary with the skipped / discarded counts.')])),
                '413' => O::err('`file_too_large`: the file exceeds 1 MB.'),
            ] + $gate + self::invalid() + $conflict, ['requestBody' => O::multipart([
                'ics_file' => ['type' => 'string', 'format' => 'binary'],
                'branch_id' => ['type' => 'integer', 'description' => 'Closures for this branch only (empty = every branch).'],
            ], ['ics_file'])]),
        ];

        return $doc;
    }
}
