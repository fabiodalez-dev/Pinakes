<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\SettingsRepository;
use App\Support\DateHelper;
use App\Support\Holidays\HolidayCalculator;
use App\Support\Holidays\HolidayPresets;
use App\Support\LibraryCalendar;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Admin page /admin/calendar: the days the library is closed.
 *
 * Weekly closed days (loans.closed_weekdays), closure intervals with yearly
 * recurrence and optional branch, the national holiday presets (a country,
 * optionally a region, and the holidays the library ticks), the yearly
 * closures switched on and off, ICS import and a monthly preview. AdminAuthMiddleware also admits staff; like
 * the settings pages, every action is reserved to Permissions::mayConfigure().
 */
class LibraryCalendarController
{
    private const MAX_ICS_BYTES = 1048576;

    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $query = $request->getQueryParams();
        $today = DateHelper::today();

        $month = is_string($query['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $query['month']) === 1
            ? $query['month']
            : substr($today, 0, 7);
        $branches = $this->branches($db);
        $previewSede = isset($query['sede']) && is_numeric($query['sede']) && isset($branches[(int) $query['sede']]) ? (int) $query['sede'] : null;

        $calendar = LibraryCalendar::fromDatabase($db, configuration: true);
        $closedWeekdays = $calendar->closedWeekdays();
        $closures = $this->closures($db);

        $editId = isset($query['edit']) && is_numeric($query['edit']) ? (int) $query['edit'] : 0;
        $editing = null;
        foreach ($closures as $row) {
            // A preset row changes with the selection, never by hand.
            if ((int) $row['id'] === $editId && ($row['regola_festivita'] ?? null) === null) {
                $editing = $row;
            }
        }
        $yearlyClosures = array_values(array_filter(
            $closures,
            static fn (array $row): bool => (int) $row['ricorrente_annuale'] === 1 && ($row['regola_festivita'] ?? null) === null
        ));

        $monthStart = $month . '-01';
        $monthEnd = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        $monthClosed = array_flip($calendar->closedDatesBetween($monthStart, $monthEnd, $previewSede));
        $prevMonth = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
        $nextMonth = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('+1 month')->format('Y-m');
        $currentYear = (int) substr($today, 0, 4);
        $holidays = $this->holidayPreview($db, $query, $currentYear);

        ob_start();
        require __DIR__ . '/../../Views/admin/calendar.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function saveWeekdays(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        $raw = $data['closed_weekdays'] ?? [];
        $days = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value <= 6) {
                $days[(int) $value] = (int) $value;
            }
        }
        if (count($days) === 7) {
            $_SESSION['error_message'] = __('Almeno un giorno della settimana deve restare aperto.');
            return $this->back($response);
        }
        ksort($days);
        (new SettingsRepository($db))->set('loans', 'closed_weekdays', implode(',', $days));
        $this->calendarChanged($db);
        $_SESSION['success_message'] = __('Giorni di chiusura settimanale salvati.');
        return $this->back($response);
    }

    public function store(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $row = $this->validatedClosure($db, (array) $request->getParsedBody());
        if (is_string($row)) {
            $_SESSION['error_message'] = $row;
            return $this->back($response);
        }
        $this->insertClosure($db, $row);
        $this->calendarChanged($db);
        $_SESSION['success_message'] = __('Chiusura aggiunta.');
        return $this->back($response, substr($row['start'], 0, 7));
    }

    public function update(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        if (!$this->closureExists($db, $id)) {
            $_SESSION['error_message'] = __('Chiusura non trovata.');
            return $this->back($response);
        }
        if ($this->isPresetRow($db, $id)) {
            $_SESSION['error_message'] = __('Le festività si modificano dalla selezione delle festività nazionali.');
            return $this->back($response);
        }
        $row = $this->validatedClosure($db, (array) $request->getParsedBody());
        if (is_string($row)) {
            $_SESSION['error_message'] = $row;
            return $this->back($response, null, $id);
        }
        $sede = $row['sede'];
        $yearly = $row['yearly'] ? 1 : 0;
        $stmt = $db->prepare('UPDATE calendario_chiusure SET data_inizio = ?, data_fine = ?, descrizione = ?, ricorrente_annuale = ?, sede_id = ? WHERE id = ?');
        $stmt->bind_param('sssiii', $row['start'], $row['end'], $row['description'], $yearly, $sede, $id);
        $stmt->execute();
        $stmt->close();
        $this->calendarChanged($db);
        $_SESSION['success_message'] = __('Chiusura aggiornata.');
        return $this->back($response, substr($row['start'], 0, 7));
    }

    public function delete(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        if ($this->isPresetRow($db, $id)) {
            $_SESSION['error_message'] = __('Le festività si modificano dalla selezione delle festività nazionali.');
            return $this->back($response);
        }
        $stmt = $db->prepare('DELETE FROM calendario_chiusure WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        $this->calendarChanged($db);
        $_SESSION[$deleted ? 'success_message' : 'error_message'] = $deleted ? __('Chiusura eliminata.') : __('Chiusura non trovata.');
        return $this->back($response);
    }

    /**
     * Apply the holidays ticked for a country (and region): the selection is
     * saved, rows of unticked holidays are removed, missing ones added.
     * Closures added by hand or from an ICS file are not touched.
     */
    public function importPreset(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        $country = is_string($data['country'] ?? null) ? strtoupper(trim($data['country'])) : '';
        if (HolidayCalculator::country($country) === null) {
            $_SESSION['error_message'] = __('Paese non valido.');
            return $this->back($response);
        }
        $region = HolidayCalculator::normaliseRegion($country, is_string($data['region'] ?? null) ? trim($data['region']) : '');
        if (!HolidayPresets::schemaReady($db)) {
            $_SESSION['error_message'] = __("Esegui prima l'aggiornamento del database: manca la colonna delle festività.");
            return $this->backToPreset($response, $country, $region);
        }
        $ids = [];
        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        try {
            $counts = HolidayPresets::apply($db, $country, $region, $ids);
        } catch (\Throwable $e) {
            SecureLogger::error('Calendar: holiday preset not applied: ' . $e->getMessage());
            $_SESSION['error_message'] = __('Festività non applicate: riprova.');
            return $this->backToPreset($response, $country, $region);
        }
        $this->calendarChanged($db);
        $_SESSION['success_message'] = $counts['adopted'] > 0
            ? __('Festività applicate: %1$d aggiunte, %2$d rimosse, %3$d già presenti riconosciute.', $counts['added'], $counts['removed'], $counts['adopted'])
            : __('Festività applicate: %1$d aggiunte, %2$d rimosse.', $counts['added'], $counts['removed']);
        return $this->backToPreset($response, $country, $region);
    }

    /**
     * Switch the yearly closures added by hand (or from a file) on and off
     * without deleting them: a switched-off closure closes no day.
     */
    public function saveYearly(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        if (!HolidayPresets::schemaReady($db)) {
            $_SESSION['error_message'] = __("Esegui prima l'aggiornamento del database: manca la colonna delle festività.");
            return $this->back($response);
        }
        $data = (array) $request->getParsedBody();
        $ids = static function (mixed $raw): array {
            $out = [];
            foreach (is_array($raw) ? $raw : [] as $value) {
                if (is_scalar($value) && ctype_digit((string) $value)) {
                    $out[(int) $value] = (int) $value;
                }
            }
            return $out;
        };
        $listed = $ids($data['listed'] ?? []);
        $active = $ids($data['active'] ?? []);
        $changed = 0;
        $stmt = $db->prepare('UPDATE calendario_chiusure SET attiva = ? WHERE id = ? AND ricorrente_annuale = 1 AND regola_festivita IS NULL AND attiva <> ?');
        foreach ($listed as $id) {
            $flag = isset($active[$id]) ? 1 : 0;
            $stmt->bind_param('iii', $flag, $id, $flag);
            $stmt->execute();
            $changed += max(0, $stmt->affected_rows);
        }
        $stmt->close();
        $this->calendarChanged($db);
        $_SESSION['success_message'] = __('Chiusure annuali salvate: %d modificate.', $changed);
        $target = url('/admin/calendar') . '#calendar-yearly';
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function importIcs(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response)) {
            return $guard;
        }
        $files = $request->getUploadedFiles();
        $file = $files['ics_file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = __('Seleziona un file ICS da importare.');
            return $this->back($response);
        }
        if (($file->getSize() ?? 0) > self::MAX_ICS_BYTES) {
            $_SESSION['error_message'] = __('Il file ICS supera la dimensione massima di 1 MB.');
            return $this->back($response);
        }
        try {
            $ics = (string) $file->getStream()->getContents();
            // ICS is UTF-8 by the standard, but exports in Windows-1252 /
            // ISO-8859-1 exist: their accented SUMMARY bytes would fail the
            // utf8mb4 INSERT halfway through the import.
            // Unfold first (RFC 5545 3.1): a folded line may split one UTF-8
            // character in two, which the encoding check would take for
            // Windows-1252.
            $ics = (string) preg_replace("/\r?\n[ \t]/", '', $ics);
            if (!mb_check_encoding($ics, 'UTF-8')) {
                $ics = (string) mb_convert_encoding($ics, 'UTF-8', 'Windows-1252');
            }
        } catch (\Throwable $e) {
            SecureLogger::warning('Calendar ICS import: unreadable upload: ' . $e->getMessage());
            $ics = '';
        }
        if (!str_contains(strtoupper($ics), 'BEGIN:VCALENDAR')) {
            $_SESSION['error_message'] = __('Il file non è un calendario ICS valido.');
            return $this->back($response);
        }
        $data = (array) $request->getParsedBody();
        $sede = $this->branchFrom($db, $data['sede_id'] ?? null);
        $added = 0;
        $skipped = 0;
        $rejected = 0;
        foreach (LibraryCalendar::parseIcs($ics) as $event) {
            // The same limits as the form: a yearly event longer than a year
            // would otherwise be stored as a closure of every day.
            if ($this->spanError($event['start'], $event['end'], $event['yearly']) !== null) {
                $rejected++;
                continue;
            }
            $row = [
                'start' => $event['start'],
                'end' => $event['end'],
                'description' => $event['description'] !== '' ? $event['description'] : __('Chiusura'),
                'yearly' => $event['yearly'],
                'sede' => $sede,
            ];
            if ($this->alreadyClosed($db, $row)) {
                $skipped++;
                continue;
            }
            $this->insertClosure($db, $row);
            $added++;
        }
        $this->calendarChanged($db);
        $_SESSION['success_message'] = $rejected > 0
            ? __('File ICS importato: %1$d chiusure aggiunte, %2$d già presenti, %3$d scartate perché più lunghe del limite (un anno se annuali, dieci anni altrimenti).', $added, $skipped, $rejected)
            : __('File ICS importato: %1$d chiusure aggiunte, %2$d già presenti.', $added, $skipped);
        return $this->back($response);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Every write ends here: the request sees the new calendar, and pending
     * pickups whose deadline is now a closed day move to the next open day.
     */
    private function calendarChanged(\mysqli $db): void
    {
        LibraryCalendar::reset();
        try {
            LibraryCalendar::extendPickupDeadlines($db);
        } catch (\Throwable $e) {
            SecureLogger::warning('Calendar: pickup deadlines not extended: ' . $e->getMessage());
        }
    }

    private function guard(Response $response): ?Response
    {
        if (\App\Support\Permissions::mayConfigure()) {
            return null;
        }
        $_SESSION['error_message'] = __('Operazione riservata agli amministratori');
        return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
    }

    private function back(Response $response, ?string $month = null, ?int $editId = null): Response
    {
        $params = [];
        if ($month !== null) {
            $params['month'] = $month;
        }
        if ($editId !== null) {
            $params['edit'] = $editId;
        }
        $target = url('/admin/calendar') . ($params !== [] ? '?' . http_build_query($params) : '');
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * @param array<mixed> $data
     * @return array{start: string, end: string, description: string, yearly: bool, sede: ?int}|string
     *         the closure to store, or the error message
     */
    private function validatedClosure(\mysqli $db, array $data): array|string
    {
        $start = is_string($data['data_inizio'] ?? null) ? trim($data['data_inizio']) : '';
        $end = is_string($data['data_fine'] ?? null) ? trim($data['data_fine']) : '';
        if ($end === '') {
            $end = $start;
        }
        if (!DateHelper::isISODateFormat($start) || !DateHelper::isISODateFormat($end)) {
            return __('Data non valida: usa il formato AAAA-MM-GG.');
        }
        $yearly = !empty($data['ricorrente_annuale']);
        if (($error = $this->spanError($start, $end, $yearly)) !== null) {
            return $error;
        }
        $description = is_string($data['descrizione'] ?? null) ? trim($data['descrizione']) : '';
        $description = mb_substr(preg_replace('/\s+/u', ' ', $description) ?? '', 0, 255);
        $rawSede = $data['sede_id'] ?? null;
        $sede = $this->branchFrom($db, $rawSede);
        if ($sede === null && is_scalar($rawSede) && (string) $rawSede !== '' && (string) $rawSede !== '0') {
            return __('Sede non trovata.');
        }
        return ['start' => $start, 'end' => $end, 'description' => $description, 'yearly' => $yearly, 'sede' => $sede];
    }

    /**
     * The limits every closure meets, from the form or from a file: end not
     * before start, a yearly closure within a year, a one-off within ten.
     * Null when the span is fine, otherwise the message for the operator.
     */
    private function spanError(string $start, string $end, bool $yearly): ?string
    {
        if ($end < $start) {
            return __('La data di fine non può precedere la data di inizio');
        }
        $span = LibraryCalendar::spanDays($start, $end);
        if ($span < 0) {
            return __('Data non valida: usa il formato AAAA-MM-GG.');
        }
        if ($yearly && $span > LibraryCalendar::MAX_YEARLY_SPAN_DAYS) {
            return __('Una chiusura annuale non può durare più di un anno.');
        }
        if (!$yearly && $span > LibraryCalendar::MAX_ONCE_SPAN_DAYS) {
            return __('Una chiusura non può durare più di dieci anni.');
        }
        return null;
    }

    private function closureExists(\mysqli $db, int $id): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM calendario_chiusure WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    private function branchFrom(\mysqli $db, mixed $raw): ?int
    {
        if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
            return null;
        }
        $id = (int) $raw;
        return isset($this->branches($db)[$id]) ? $id : null;
    }

    /** @param array{start: string, end: string, description: string, yearly: bool, sede: ?int} $row */
    private function insertClosure(\mysqli $db, array $row): void
    {
        $yearly = $row['yearly'] ? 1 : 0;
        $sede = $row['sede'];
        $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssii', $row['start'], $row['end'], $row['description'], $yearly, $sede);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * An identical closure is already stored (same interval, recurrence and
     * branch; a yearly row matches the same month-day span in any year), so
     * importing a file twice adds nothing. Holiday preset rows do not count:
     * unticking that holiday later removes them, and the day the file
     * names must stay closed.
     *
     * @param array{start: string, end: string, description: string, yearly: bool, sede: ?int} $row
     */
    private function alreadyClosed(\mysqli $db, array $row): bool
    {
        $yearly = $row['yearly'] ? 1 : 0;
        $sede = $row['sede'] ?? 0;
        $manual = HolidayPresets::schemaReady($db) ? ' AND regola_festivita IS NULL' : '';
        if ($yearly === 1) {
            $from = substr($row['start'], 5);
            $to = substr($row['end'], 5);
            $stmt = $db->prepare("SELECT 1 FROM calendario_chiusure WHERE ricorrente_annuale = 1 AND DATE_FORMAT(data_inizio, '%m-%d') = ? AND DATE_FORMAT(data_fine, '%m-%d') = ? AND COALESCE(sede_id, 0) = ?{$manual} LIMIT 1");
            $stmt->bind_param('ssi', $from, $to, $sede);
        } else {
            $stmt = $db->prepare("SELECT 1 FROM calendario_chiusure WHERE ricorrente_annuale = 0 AND data_inizio = ? AND data_fine = ? AND COALESCE(sede_id, 0) = ?{$manual} LIMIT 1");
            $stmt->bind_param('ssi', $row['start'], $row['end'], $sede);
        }
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    /** @return list<array<string, mixed>> */
    private function closures(\mysqli $db): array
    {
        // Before the holiday-presets migration the two columns are missing:
        // every row is a manual, active one.
        $presetColumns = HolidayPresets::schemaReady($db) ? 'c.regola_festivita, c.attiva' : 'NULL AS regola_festivita, 1 AS attiva';
        $result = $db->query("SELECT c.id, c.data_inizio, c.data_fine, c.descrizione, c.ricorrente_annuale, c.sede_id, {$presetColumns}, s.nome AS sede_nome
            FROM calendario_chiusure c LEFT JOIN sedi s ON s.id = c.sede_id
            ORDER BY c.ricorrente_annuale DESC, c.data_inizio, c.data_fine, c.id");
        return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    }

    private function isPresetRow(\mysqli $db, int $id): bool
    {
        if (!HolidayPresets::schemaReady($db)) {
            return false;
        }
        $stmt = $db->prepare('SELECT 1 FROM calendario_chiusure WHERE id = ? AND regola_festivita IS NOT NULL LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    private function backToPreset(Response $response, string $country, string $region): Response
    {
        $params = ['holiday_country' => $country];
        if ($region !== '') {
            $params['holiday_region'] = $region;
        }
        $target = url('/admin/calendar') . '?' . http_build_query($params) . '#calendar-preset';
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * What the holiday card shows: the country / region / preview year asked
     * for in the query (the saved choice otherwise) and, for each holiday,
     * its date in that year and whether it is ticked. Ticked = the saved
     * choice for the saved country and region; for another one the
     * statutory holidays (optional ones stay unticked).
     *
     * @param array<mixed> $query
     * @return array{countries: array<string, string>, country: string, regions: array<string, string>, region: string, years: array{min: int, max: int}, year: int, list: list<array{id: string, name: string, date: ?string, dates: list<string>, yearly: bool, regional: bool, optional: bool, checked: bool}>, saved: bool, schema: bool}
     */
    private function holidayPreview(\mysqli $db, array $query, int $currentYear): array
    {
        $selection = HolidayPresets::selection($db);
        $country = is_string($query['holiday_country'] ?? null) ? strtoupper($query['holiday_country']) : '';
        if (HolidayCalculator::country($country) === null) {
            $country = $selection['country'];
        }
        $rawRegion = is_string($query['holiday_region'] ?? null)
            ? $query['holiday_region']
            : ($country === $selection['country'] ? $selection['region'] : '');
        $region = HolidayCalculator::normaliseRegion($country, $rawRegion);
        $years = ['min' => 1900, 'max' => 2199];
        $requestedYear = is_scalar($query['holiday_year'] ?? null) ? filter_var($query['holiday_year'], FILTER_VALIDATE_INT) : false;
        $year = $requestedYear !== false && $requestedYear >= $years['min'] && $requestedYear <= $years['max'] ? $requestedYear : $currentYear;
        $savedHere = $selection['saved'] && $selection['country'] === $country && $selection['region'] === $region;
        $ticked = array_flip($selection['rules']);
        // Before the first selection, a former Italian closure the library
        // suspended starts unticked: applying the defaults keeps it off.
        $suspended = !$selection['saved'] && $country === 'IT' ? array_flip(HolidayPresets::suspendedLegacyRules($db)) : [];
        $list = [];
        foreach (HolidayCalculator::forYear($country, $region, $year) as $holiday) {
            $holiday['checked'] = $savedHere ? isset($ticked[$holiday['id']]) : !$holiday['optional'] && !isset($suspended[$holiday['id']]);
            $list[] = $holiday;
        }
        return [
            'countries' => HolidayCalculator::countryNames(),
            'country' => $country,
            'regions' => HolidayCalculator::regions($country),
            'region' => $region,
            'years' => $years,
            'year' => $year,
            'list' => $list,
            'saved' => $savedHere,
            'schema' => HolidayPresets::schemaReady($db),
        ];
    }

    /** @var array<int, string>|null */
    private ?array $branchCache = null;

    /** @return array<int, string> id => name */
    private function branches(\mysqli $db): array
    {
        if ($this->branchCache !== null) {
            return $this->branchCache;
        }
        $this->branchCache = [];
        $result = $db->query('SELECT id, nome FROM sedi ORDER BY nome');
        if ($result !== false) {
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $this->branchCache[(int) $row['id']] = (string) $row['nome'];
            }
        }
        return $this->branchCache;
    }
}
