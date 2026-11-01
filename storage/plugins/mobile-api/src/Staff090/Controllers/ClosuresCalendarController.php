<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Controllers\Admin\LibraryCalendarController;
use App\Plugins\MobileApi\Staff090\Support\CalendarDayReasons;
use App\Plugins\MobileApi\Staff090\Support\SettingsKit;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Support\Features;
use App\Support\Holidays\CustomHolidays;
use App\Support\Holidays\HolidayCalculator;
use App\Support\Holidays\WorldCalendars;
use App\Support\LibraryCalendar;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * The closures calendar (/admin/calendar) for the staff app (Mobile API
 * 1.10.0). Reads come from LibraryCalendarController::viewData(), what the
 * page renders; every write is the web action (LibraryCalendarController),
 * with its lock, transaction, whole-year guard and pickup-deadline move.
 *
 * Access mirrors the web routes: FeatureGateMiddleware('closures_calendar',
 * configuration: true), then the controller's mayConfigure() guard.
 */
final class ClosuresCalendarController
{
    private const FEATURE = 'closures_calendar';
    private const MAX_ICS_BYTES = 1048576;

    /** API custom holiday field => validator. */
    private const CUSTOM_FIELDS = [
        'name', 'kind', 'month', 'day', 'weekday', 'occurrence', 'offset_days', 'calendar', 'dates',
        'duration_days', 'weekend_rule', 'year_from', 'year_until', 'branch_id', 'active',
    ];

    public function __construct(private mysqli $db)
    {
    }

    // ─── Reads ─────────────────────────────────────────────────────────────

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $query = $request->getQueryParams();
        $month = $query['month'] ?? null;
        if ($month !== null && (!is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1)) {
            return SettingsKit::invalid($response, ['month' => __('Valore non valido.')]);
        }
        $branch = $query['branch_id'] ?? null;
        if ($branch !== null && $branch !== '' && (!is_string($branch) || !ctype_digit($branch))) {
            return SettingsKit::invalid($response, ['branch_id' => __('Valore non valido.')]);
        }
        try {
            $data = LibraryCalendarController::viewData($this->db, [
                'month' => $month,
                'sede' => $branch,
                'holiday_country' => $query['holiday_country'] ?? null,
                'holiday_region' => $query['holiday_region'] ?? null,
                'holiday_year' => $query['holiday_year'] ?? null,
            ]);
            if ($branch !== null && $branch !== '' && $data['previewSede'] === null) {
                return SettingsKit::notFound($response, __('Sede non trovata.'));
            }

            return ResponseEnvelope::success($response, $this->calendarPayload($data));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar', $e);
        }
    }

    public function countries(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $query = $request->getQueryParams();
        try {
            $data = LibraryCalendarController::viewData($this->db, [
                'holiday_country' => $query['country'] ?? null,
                'holiday_region' => $query['region'] ?? null,
                'holiday_year' => $query['year'] ?? null,
            ]);
            $countries = [];
            foreach ($data['holidays']['countries'] as $code => $name) {
                $countries[] = ['code' => (string) $code, 'name' => (string) $name];
            }

            return ResponseEnvelope::success($response, ['countries' => $countries] + $this->presetPayload($data['holidays']));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'holiday countries', $e);
        }
    }

    // ─── Weekly closed days ────────────────────────────────────────────────

    public function saveWeekdays(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $days = $json['closed_weekdays'] ?? null;
        if (!is_array($days) || !array_is_list($days)) {
            return SettingsKit::invalid($response, ['closed_weekdays' => __('Giorno della settimana non valido.')]);
        }
        foreach ($days as $day) {
            if (!is_int($day) || $day < 0 || $day > 6) {
                return SettingsKit::invalid($response, ['closed_weekdays' => __('Giorno della settimana non valido.')]);
            }
        }
        try {
            $outcome = $this->delegate('saveWeekdays', '/admin/calendar/weekdays', ['closed_weekdays' => array_map('strval', $days)]);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'closed_weekdays');
            }

            return ResponseEnvelope::success($response, $this->calendarPayload(LibraryCalendarController::viewData($this->db, [])));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar weekdays', $e);
        }
    }

    // ─── Closures ──────────────────────────────────────────────────────────

    public function createClosure(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $raw = JsonBody::parse($request);
        $json = $raw;
        unset($json['idempotency_key']);
        if (($errors = $this->closureErrors($json, true)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $db = $this->db;
            $form = $this->closureForm($json, null);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $raw,
                'calendar_closure_create',
                SettingsKit::userId($request),
                0,
                $json,
                function () use ($form, $response): ResponseInterface|int {
                    $before = $this->closureIds();
                    $outcome = $this->delegate('store', '/admin/calendar/closures', $form);
                    if (($error = SettingsKit::errorOf($outcome)) !== null) {
                        return $this->calendarError($response, $error, 'start_date');
                    }
                    $new = array_diff($this->closureIds(), $before);
                    if ($new === []) {
                        throw new \RuntimeException('closure not created');
                    }

                    return (int) max($new);
                },
                fn (int $id): ?array => $this->loadClosure($id)
            );
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar closure create', $e);
        }
    }

    public function updateClosure(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        if (($errors = $this->closureErrors($json, false)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $current = $this->loadClosure($id);
            if ($current === null) {
                return SettingsKit::notFound($response, __('Chiusura non trovata.'));
            }
            if (!$current['editable']) {
                return ResponseEnvelope::error($response, 'preset_closure', __('Le festività si modificano dalla selezione delle festività nazionali.'), 409);
            }
            $outcome = $this->delegate('update', '/admin/calendar/closures/' . $id . '/update', $this->closureForm($json, $current), $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'start_date');
            }

            return ResponseEnvelope::success($response, $this->loadClosure($id));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar closure update', $e);
        }
    }

    public function deleteClosure(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        try {
            $current = $this->loadClosure($id);
            if ($current === null) {
                return SettingsKit::notFound($response, __('Chiusura non trovata.'));
            }
            if (!$current['editable']) {
                return ResponseEnvelope::error($response, 'preset_closure', __('Le festività si modificano dalla selezione delle festività nazionali.'), 409);
            }
            $outcome = $this->delegate('delete', '/admin/calendar/closures/' . $id . '/delete', [], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'id');
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar closure delete', $e);
        }
    }

    // ─── Holiday presets and yearly switches ───────────────────────────────

    public function applyPreset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $errors = [];
        if (!is_string($json['country'] ?? null) || HolidayCalculator::country(strtoupper(trim($json['country']))) === null) {
            $errors['country'] = __('Paese non valido.');
        }
        if (array_key_exists('region', $json) && $json['region'] !== null && !is_string($json['region'])) {
            $errors['region'] = __('Valore non valido.');
        }
        $rules = $json['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules) || array_filter($rules, static fn ($r): bool => !is_string($r)) !== []) {
            $errors['rules'] = __('Valore non valido.');
        }
        if ($errors !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $country = strtoupper(trim((string) $json['country']));
            $region = is_string($json['region'] ?? null) ? trim($json['region']) : '';
            $outcome = $this->delegate('importPreset', '/admin/calendar/preset', ['country' => $country, 'region' => $region, 'rules' => $rules]);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'country');
            }
            $data = LibraryCalendarController::viewData($this->db, ['holiday_country' => $country, 'holiday_region' => $region]);

            return ResponseEnvelope::success($response, [
                'message' => (string) $outcome->flash('success_message'),
                'holiday_preset' => $this->presetPayload($data['holidays']),
            ]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar preset', $e);
        }
    }

    public function saveYearly(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        $items = $json['closures'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            return SettingsKit::invalid($response, ['closures' => __('Valore non valido.')]);
        }
        try {
            $yearly = [];
            foreach (LibraryCalendarController::viewData($this->db, [])['yearlyClosures'] as $row) {
                $yearly[(int) $row['id']] = (int) ($row['attiva'] ?? 1) === 1;
            }
            $listed = [];
            $active = [];
            $errors = [];
            foreach ($items as $i => $item) {
                if (!is_array($item) || !is_int($item['id'] ?? null) || !isset($yearly[$item['id']])) {
                    $errors['closures.' . $i . '.id'] = __('Chiusura non trovata.');
                    continue;
                }
                if (!is_bool($item['active'] ?? null)) {
                    $errors['closures.' . $i . '.active'] = __('Valore non valido.');
                    continue;
                }
                $listed[] = (string) $item['id'];
                if ($item['active']) {
                    $active[] = (string) $item['id'];
                }
            }
            if ($errors !== []) {
                return SettingsKit::invalid($response, $errors);
            }
            $outcome = $this->delegate('saveYearly', '/admin/calendar/yearly', ['listed' => $listed, 'active' => $active]);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'closures');
            }
            $after = [];
            $changed = 0;
            foreach (LibraryCalendarController::viewData($this->db, [])['yearlyClosures'] as $row) {
                $after[] = $this->closureResource($row);
                $changed += ((int) ($row['attiva'] ?? 1) === 1) !== ($yearly[(int) $row['id']] ?? true) ? 1 : 0;
            }

            return ResponseEnvelope::success($response, ['changed' => $changed, 'yearly_closures' => $after]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar yearly', $e);
        }
    }

    // ─── Custom holidays ───────────────────────────────────────────────────

    public function createCustom(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $raw = JsonBody::parse($request);
        $json = $raw;
        unset($json['idempotency_key']);
        if (($errors = $this->customErrors($json)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $db = $this->db;
            $form = $this->customForm($json, null);

            return (new StaffIdempotency($db))->run(
                $request,
                $response,
                $raw,
                'calendar_custom_holiday_create',
                SettingsKit::userId($request),
                0,
                $json,
                function () use ($form, $response): ResponseInterface|int {
                    $before = $this->customIds();
                    $outcome = $this->delegate('storeCustom', '/admin/calendar/custom-holidays', $form);
                    if (($error = SettingsKit::errorOf($outcome)) !== null) {
                        return $this->customError($response, $error);
                    }
                    $new = array_diff($this->customIds(), $before);
                    if ($new === []) {
                        throw new \RuntimeException('custom holiday not created');
                    }

                    return (int) max($new);
                },
                fn (int $id): ?array => $this->loadCustom($id)
            );
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'custom holiday create', $e);
        }
    }

    public function updateCustom(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $json = JsonBody::parse($request);
        if (($errors = $this->customErrors($json)) !== []) {
            return SettingsKit::invalid($response, $errors);
        }
        try {
            $current = $this->loadCustom($id);
            if ($current === null) {
                return SettingsKit::notFound($response, __('Festività non trovata.'));
            }
            $outcome = $this->delegate('updateCustom', '/admin/calendar/custom-holidays/' . $id . '/update', $this->customForm($json, $current), $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->customError($response, $error);
            }

            return ResponseEnvelope::success($response, $this->loadCustom($id));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'custom holiday update', $e);
        }
    }

    public function toggleCustom(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        try {
            if ($this->loadCustom($id) === null) {
                return SettingsKit::notFound($response, __('Festività non trovata.'));
            }
            $outcome = $this->delegate('toggleCustom', '/admin/calendar/custom-holidays/' . $id . '/toggle', [], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->customError($response, $error);
            }

            return ResponseEnvelope::success($response, $this->loadCustom($id));
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'custom holiday toggle', $e);
        }
    }

    public function deleteCustom(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        try {
            if ($this->loadCustom($id) === null) {
                return SettingsKit::notFound($response, __('Festività non trovata.'));
            }
            $outcome = $this->delegate('deleteCustom', '/admin/calendar/custom-holidays/' . $id . '/delete', [], $id);
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->customError($response, $error);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'custom holiday delete', $e);
        }
    }

    // ─── ICS import ────────────────────────────────────────────────────────

    public function importIcs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($gate = $this->gate($response)) {
            return $gate;
        }
        $file = $request->getUploadedFiles()['ics_file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return SettingsKit::invalid($response, ['ics_file' => __('Seleziona un file ICS da importare.')]);
        }
        if (($file->getSize() ?? 0) > self::MAX_ICS_BYTES) {
            return ResponseEnvelope::error($response, 'file_too_large', __('Il file ICS supera la dimensione massima di 1 MB.'), 413, ['fields' => ['ics_file' => __('Il file ICS supera la dimensione massima di 1 MB.')]]);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $branch = $body['branch_id'] ?? '';
        if (!is_string($branch) || ($branch !== '' && !ctype_digit($branch))) {
            return SettingsKit::invalid($response, ['branch_id' => __('Valore non valido.')]);
        }
        try {
            $before = $this->closureIds();
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibraryCalendarController())->importIcs(
                Delegation::request('POST', '/admin/calendar/import-ics', ['sede_id' => $branch])->withUploadedFiles(['ics_file' => $file]),
                new SlimResponse(),
                $db
            ));
            if (($error = SettingsKit::errorOf($outcome)) !== null) {
                return $this->calendarError($response, $error, 'ics_file');
            }
            $added = array_values(array_diff($this->closureIds(), $before));
            sort($added);

            return ResponseEnvelope::success($response, [
                'added' => count($added),
                'added_ids' => $added,
                'message' => (string) $outcome->flash('success_message'),
            ]);
        } catch (\Throwable $e) {
            return SettingsKit::fail($response, 'calendar ICS import', $e);
        }
    }

    // ─── Payloads ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data LibraryCalendarController::viewData()
     * @return array<string, mixed>
     */
    private function calendarPayload(array $data): array
    {
        $reasons = new CalendarDayReasons($this->db, $data['closedWeekdays'], $data['closures'], $data['previewSede']);
        $days = [];
        for ($day = $data['monthStart']; $day <= $data['monthEnd']; $day = LibraryCalendar::shift($day, 1)) {
            $closed = isset($data['monthClosed'][$day]);
            $days[] = [
                'date' => $day,
                'weekday' => (int) (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->format('w'),
                'closed' => $closed,
                'reasons' => $closed ? $reasons->for($day) : [],
            ];
        }
        $branches = [];
        foreach ($data['branches'] as $id => $name) {
            $branches[] = ['id' => (int) $id, 'name' => (string) $name];
        }
        $customs = [];
        foreach ($data['customHolidays'] as $row) {
            $customs[] = $this->customResource($row, $data['today']);
        }

        return [
            'can_configure' => SettingsKit::mayConfigure(),
            'module_enabled' => Features::enabled(self::FEATURE),
            'today' => $data['today'],
            'month' => $data['month'],
            'prev_month' => $data['prevMonth'],
            'next_month' => $data['nextMonth'],
            'branch_id' => $data['previewSede'],
            'days' => $days,
            'closed_weekdays' => array_values(array_map('intval', $data['closedWeekdays'])),
            'closures' => array_map(fn (array $row): array => $this->closureResource($row), $data['closures']),
            'custom_holidays_available' => (bool) $data['customSchema'],
            'custom_holidays' => $customs,
            'custom_holiday_options' => $this->customOptions(),
            'holiday_preset' => $this->presetPayload($data['holidays']),
            'branches' => $branches,
            'limits' => [
                'yearly_span_days' => LibraryCalendar::MAX_YEARLY_SPAN_DAYS,
                'once_span_days' => LibraryCalendar::MAX_ONCE_SPAN_DAYS,
                'ics_max_bytes' => self::MAX_ICS_BYTES,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $holidays LibraryCalendarController holiday preview
     * @return array<string, mixed>
     */
    private function presetPayload(array $holidays): array
    {
        $regions = [];
        foreach ($holidays['regions'] as $code => $name) {
            $regions[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        return [
            'country' => (string) $holidays['country'],
            'country_name' => (string) ($holidays['countries'][$holidays['country']] ?? $holidays['country']),
            'region' => (string) $holidays['region'],
            'regions' => $regions,
            'year' => (int) $holidays['year'],
            'years' => $holidays['years'],
            'data_years' => $holidays['data_years'],
            'saved' => (bool) $holidays['saved'],
            'schema_ready' => (bool) $holidays['schema'],
            'missing' => array_values($holidays['missing'] ?? []),
            'unknown_country' => (string) $holidays['unknown_country'],
            'holidays' => array_map(static fn (array $h): array => [
                'id' => (string) $h['id'],
                'name' => (string) $h['name'],
                'name_en' => (string) $h['name_en'],
                'date' => $h['date'],
                'dates' => $h['dates'],
                'yearly' => (bool) $h['yearly'],
                'regional' => (bool) $h['regional'],
                'optional' => (bool) $h['optional'],
                'category' => (string) $h['category'],
                'estimated' => (bool) $h['estimated'],
                'checked' => (bool) $h['checked'],
            ], $holidays['list']),
        ];
    }

    /**
     * @param array<string, mixed> $row calendario_chiusure row (+ sede_nome)
     * @return array<string, mixed>
     */
    private function closureResource(array $row): array
    {
        $preset = $row['regola_festivita'] ?? null;

        return [
            'id' => (int) $row['id'],
            'start_date' => (string) $row['data_inizio'],
            'end_date' => (string) $row['data_fine'],
            'description' => (string) ($row['descrizione'] ?? ''),
            'yearly' => (int) $row['ricorrente_annuale'] === 1,
            'branch_id' => SettingsKit::intOrNull($row['sede_id'] ?? null),
            'branch_name' => isset($row['sede_nome']) ? (string) $row['sede_nome'] : null,
            'preset_rule' => $preset !== null ? (string) $preset : null,
            'active' => (int) ($row['attiva'] ?? 1) === 1,
            'editable' => $preset === null,
        ];
    }

    /**
     * @param array<string, mixed> $row festivita_personalizzate row
     * @return array<string, mixed>
     */
    private function customResource(array $row, string $today): array
    {
        $calLocale = \App\Support\I18n::getLocale();
        $format = static function (string $pattern, string $ymd) use ($calLocale): string {
            if (class_exists('IntlDateFormatter')) {
                $f = new \IntlDateFormatter($calLocale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, $pattern);
                $out = $f->format(new \DateTimeImmutable($ymd . ' 12:00:00', new \DateTimeZone('UTC')));
                if (is_string($out) && $out !== '') {
                    return $out;
                }
            }

            return $ymd;
        };
        $kind = (string) $row['tipo'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['nome'],
            'kind' => $kind,
            'month' => SettingsKit::intOrNull($row['mese'] ?? null),
            'day' => SettingsKit::intOrNull($row['giorno'] ?? null),
            'weekday' => SettingsKit::intOrNull($row['giorno_settimana'] ?? null),
            'occurrence' => SettingsKit::intOrNull($row['occorrenza'] ?? null),
            'offset_days' => SettingsKit::intOrNull($row['scarto_giorni'] ?? null),
            'calendar' => isset($row['calendario']) && $row['calendario'] !== '' ? (string) $row['calendario'] : null,
            'dates' => $kind === 'dates' ? CustomHolidays::parseDates((string) ($row['date_specifiche'] ?? '')) : [],
            'duration_days' => max(1, (int) ($row['durata_giorni'] ?? 1)),
            'weekend_rule' => isset($row['fine_settimana']) && $row['fine_settimana'] !== '' ? (string) $row['fine_settimana'] : null,
            'year_from' => SettingsKit::intOrNull($row['anno_inizio'] ?? null) ?: null,
            'year_until' => SettingsKit::intOrNull($row['anno_fine'] ?? null) ?: null,
            'branch_id' => SettingsKit::intOrNull($row['sede_id'] ?? null),
            'branch_name' => isset($row['sede_nome']) ? (string) $row['sede_nome'] : null,
            'active' => (int) $row['attiva'] === 1,
            'description' => CustomHolidays::describe($row, $format),
            'next_dates' => CustomHolidays::nextDates($row, $today),
        ];
    }

    /** @return array<string, mixed> */
    private function customOptions(): array
    {
        $calendars = [];
        foreach (WorldCalendars::codes() as $code) {
            $calendars[] = [
                'code' => $code,
                'label' => WorldCalendars::label($code),
                'available' => WorldCalendars::available($code),
                'months' => WorldCalendars::months($code),
            ];
        }

        return [
            'kinds' => CustomHolidays::KINDS,
            'weekend_rules' => CustomHolidays::WEEKEND_MODES,
            'occurrences' => [1, 2, 3, 4, 5, -1],
            'calendars' => $calendars,
            'max_duration_days' => HolidayCalculator::MAX_DAYS,
            'max_dates' => CustomHolidays::MAX_DATES,
        ];
    }

    /** @return array<string, mixed>|null */
    private function loadClosure(int $id): ?array
    {
        foreach (LibraryCalendarController::viewData($this->db, [])['closures'] as $row) {
            if ((int) $row['id'] === $id) {
                return $this->closureResource($row);
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function loadCustom(int $id): ?array
    {
        foreach (CustomHolidays::all($this->db) as $row) {
            if ((int) $row['id'] === $id) {
                return $this->customResource($row, \App\Support\DateHelper::today());
            }
        }

        return null;
    }

    /** @return list<int> */
    private function closureIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], LibraryCalendarController::viewData($this->db, [])['closures']);
    }

    /** @return list<int> */
    private function customIds(): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], CustomHolidays::all($this->db));
    }

    // ─── Forms, checks and errors ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $json
     * @return array<string, string>
     */
    private function closureErrors(array $json, bool $create): array
    {
        $errors = [];
        if ($create && !array_key_exists('start_date', $json)) {
            $errors['start_date'] = __('Data non valida: usa il formato AAAA-MM-GG.');
        }
        foreach (['start_date' => false, 'end_date' => true] as $key => $nullable) {
            if (!array_key_exists($key, $json) || ($nullable && $json[$key] === null)) {
                continue;
            }
            if (!is_string($json[$key]) || !\App\Support\DateHelper::isISODateFormat($json[$key])) {
                $errors[$key] = __('Data non valida: usa il formato AAAA-MM-GG.');
            }
        }
        if ($e = SettingsKit::checkString($json, 'description', 255)) {
            $errors['description'] = $e;
        }
        if ($e = SettingsKit::checkBool($json, 'yearly')) {
            $errors['yearly'] = $e;
        }
        if ($e = SettingsKit::checkInt($json, 'branch_id', 0, PHP_INT_MAX)) {
            $errors['branch_id'] = $e;
        }
        foreach (array_diff(array_keys($json), ['start_date', 'end_date', 'description', 'yearly', 'branch_id', 'idempotency_key']) as $key) {
            $errors[(string) $key] = __('Valore non valido.');
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current closure resource
     * @return array<string, string>
     */
    private function closureForm(array $json, ?array $current): array
    {
        $base = $current ?? ['start_date' => '', 'end_date' => null, 'description' => '', 'yearly' => false, 'branch_id' => null];
        $values = array_merge($base, array_intersect_key($json, ['start_date' => 1, 'end_date' => 1, 'description' => 1, 'yearly' => 1, 'branch_id' => 1]));
        $form = [
            'data_inizio' => (string) $values['start_date'],
            'data_fine' => (string) ($values['end_date'] ?? ''),
            'descrizione' => (string) ($values['description'] ?? ''),
            'sede_id' => $values['branch_id'] === null ? '' : (string) $values['branch_id'],
        ];
        if ($values['yearly'] === true) {
            $form['ricorrente_annuale'] = '1';
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, string>
     */
    private function customErrors(array $json): array
    {
        $errors = [];
        if ($e = SettingsKit::checkString($json, 'name', 255, false)) {
            $errors['name'] = $e;
        }
        if (array_key_exists('kind', $json) && !in_array($json['kind'], CustomHolidays::KINDS, true)) {
            $errors['kind'] = __('Scegli come si calcola la festività.');
        }
        foreach (['month' => [1, 13], 'day' => [1, 31], 'weekday' => [0, 6], 'occurrence' => [-1, 5], 'offset_days' => [-200, 200],
            'duration_days' => [1, HolidayCalculator::MAX_DAYS], 'year_from' => [1900, 2199], 'year_until' => [1900, 2199], 'branch_id' => [0, PHP_INT_MAX]] as $key => [$min, $max]) {
            if ($e = SettingsKit::checkInt($json, $key, $min, $max)) {
                $errors[$key] = $e;
            }
        }
        if ($e = SettingsKit::checkString($json, 'calendar', 40)) {
            $errors['calendar'] = $e;
        }
        if (array_key_exists('dates', $json) && $json['dates'] !== null
            && (!is_array($json['dates']) || !array_is_list($json['dates']) || array_filter($json['dates'], static fn ($d): bool => !is_string($d)) !== [])) {
            $errors['dates'] = __('Indica almeno una data nel formato AAAA-MM-GG.');
        }
        if (array_key_exists('weekend_rule', $json) && $json['weekend_rule'] !== null && !in_array($json['weekend_rule'], CustomHolidays::WEEKEND_MODES, true)) {
            $errors['weekend_rule'] = __('Valore non valido.');
        }
        if ($e = SettingsKit::checkBool($json, 'active')) {
            $errors['active'] = $e;
        }
        foreach (array_diff(array_keys($json), array_merge(self::CUSTOM_FIELDS, ['idempotency_key'])) as $key) {
            $errors[(string) $key] = __('Valore non valido.');
        }

        return $errors;
    }

    /**
     * The custom holiday form (CustomHolidays::validate() field names).
     *
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $current custom holiday resource
     * @return array<string, string>
     */
    private function customForm(array $json, ?array $current): array
    {
        $base = $current ?? [
            'name' => '', 'kind' => '', 'month' => null, 'day' => null, 'weekday' => null, 'occurrence' => null,
            'offset_days' => null, 'calendar' => null, 'dates' => [], 'duration_days' => 1, 'weekend_rule' => null,
            'year_from' => null, 'year_until' => null, 'branch_id' => null, 'active' => true,
        ];
        $v = array_merge($base, array_intersect_key($json, array_flip(self::CUSTOM_FIELDS)));
        $str = static fn (mixed $x): string => $x === null ? '' : (string) $x;
        $form = [
            'nome' => $str($v['name']),
            'tipo' => $str($v['kind']),
            'fixed_mese' => $str($v['month']),
            'fixed_giorno' => $str($v['day']),
            'nth_mese' => $str($v['month']),
            'nth_giorno_settimana' => $str($v['weekday']),
            'nth_occorrenza' => $str($v['occurrence']),
            'scarto' => $str($v['offset_days']),
            'calendario' => $str($v['calendar']),
            'cal_giorno' => $str($v['day']),
            'date_specifiche' => implode(',', is_array($v['dates']) ? $v['dates'] : []),
            'durata_giorni' => $str($v['duration_days']),
            'fine_settimana' => $str($v['weekend_rule']),
            'anno_inizio' => $str($v['year_from']),
            'anno_fine' => $str($v['year_until']),
            'sede_id' => $str($v['branch_id']),
        ];
        if (is_string($v['calendar']) && $v['calendar'] !== '') {
            $form['cal_mese_' . $v['calendar']] = $str($v['month']);
        }
        if ($v['active'] === true) {
            $form['attiva'] = '1';
        }

        return $form;
    }

    /** The web's refusal of a custom holiday form, with the field it names. */
    private function customError(ResponseInterface $response, string $error): ResponseInterface
    {
        $fields = [
            __('Indica il nome della festività.') => 'name',
            __('Scegli come si calcola la festività.') => 'kind',
            __('La data indicata non esiste.') => 'day',
            __('Indica mese, giorno della settimana e quale occorrenza.') => 'occurrence',
            __('I giorni rispetto alla Pasqua vanno da -200 a 200.') => 'offset_days',
            __('Scegli il calendario.') => 'calendar',
            __('Questo calendario richiede l’estensione PHP intl sul server.') => 'calendar',
            __('Indica mese e giorno del calendario scelto.') => 'day',
            __('Indica almeno una data nel formato AAAA-MM-GG.') => 'dates',
            sprintf(__('Al massimo %d date.'), CustomHolidays::MAX_DATES) => 'dates',
            sprintf(__('La durata va da 1 a %d giorni.'), HolidayCalculator::MAX_DAYS) => 'duration_days',
            __('Gli anni vanno dal 1900 al 2199.') => 'year_from',
            __('L’ultimo anno non può precedere il primo.') => 'year_until',
            __('Sede non trovata.') => 'branch_id',
        ];
        if ($error === __('Festività non trovata.')) {
            return SettingsKit::notFound($response, $error);
        }
        if (isset($fields[$error])) {
            return SettingsKit::invalid($response, [$fields[$error] => $error]);
        }

        return $this->calendarError($response, $error, 'name');
    }

    /** The web's refusal of a calendar write, as the API status it means. */
    private function calendarError(ResponseInterface $response, string $error, string $defaultField): ResponseInterface
    {
        $fields = [
            __('Data non valida: usa il formato AAAA-MM-GG.') => 'start_date',
            __('La data di fine non può precedere la data di inizio') => 'end_date',
            __('Una chiusura annuale non può durare più di un anno.') => 'end_date',
            __('Una chiusura non può durare più di dieci anni.') => 'end_date',
            __('Sede non trovata.') => 'branch_id',
            __('Paese non valido.') => 'country',
            __('Giorno della settimana non valido.') => 'closed_weekdays',
            __('Almeno un giorno della settimana deve restare aperto.') => 'closed_weekdays',
            __('Seleziona un file ICS da importare.') => 'ics_file',
            __('Il file ICS supera la dimensione massima di 1 MB.') => 'ics_file',
            __('Il file non è un calendario ICS valido.') => 'ics_file',
        ];
        if (isset($fields[$error])) {
            return SettingsKit::invalid($response, [$fields[$error] => $error]);
        }
        $conflicts = [
            __('Con questa chiusura annuale la biblioteca resterebbe chiusa tutto l\'anno: almeno un giorno deve restare aperto.') => 'calendar_always_closed',
            __('Con questi giorni di chiusura settimanale e le chiusure annuali la biblioteca resterebbe chiusa tutto l\'anno: almeno un giorno deve restare aperto.') => 'calendar_always_closed',
            __('Un\'altra modifica del calendario è in corso: riprova tra poco.') => 'concurrent_retry',
            __('Le festività si modificano dalla selezione delle festività nazionali.') => 'preset_closure',
            __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.') => 'schema_not_ready',
            __("Esegui prima l'aggiornamento del database: manca la colonna delle festività.") => 'schema_not_ready',
            __('Le tabelle del calendario non sono ancora state create: completa l\'aggiornamento del database.') => 'schema_not_ready',
        ];
        if (isset($conflicts[$error])) {
            return ResponseEnvelope::error($response, $conflicts[$error], $error, 409);
        }
        if ($error === __('Chiusura non trovata.')) {
            return SettingsKit::notFound($response, $error);
        }
        if ($error === __('Operazione riservata agli amministratori')) {
            return SettingsKit::forbidden($response);
        }
        if ($error === __('Errore di salvataggio. Riprova più tardi.')) {
            return ResponseEnvelope::error($response, 'internal_error', $error, 500);
        }

        return SettingsKit::invalid($response, [$defaultField => $error]);
    }

    private function gate(ResponseInterface $response): ?ResponseInterface
    {
        if ($gate = SettingsKit::adminArea(self::FEATURE, $response)) {
            return $gate;
        }
        // LibraryCalendarController::guard(): the closures table comes with
        // the 0.9.0 migration; until it ran every page and action refuses.
        $res = $this->db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure'");
        if (!$res instanceof \mysqli_result || $res->num_rows === 0) {
            return ResponseEnvelope::error($response, 'schema_not_ready', __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.'), 409);
        }

        return null;
    }

    /** @param array<string, mixed> $form */
    private function delegate(string $action, string $path, array $form, ?int $id = null): DelegationOutcome
    {
        $db = $this->db;

        return Delegation::run(static function () use ($action, $path, $form, $id, $db): ResponseInterface {
            $controller = new LibraryCalendarController();
            $request = Delegation::request('POST', $path, $form);

            return $id === null
                ? $controller->{$action}($request, new SlimResponse(), $db)
                : $controller->{$action}($request, new SlimResponse(), $db, $id);
        });
    }
}
