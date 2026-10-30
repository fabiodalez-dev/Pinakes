<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\SettingsRepository;
use App\Support\DateHelper;
use App\Support\LibraryCalendar;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Admin page /admin/calendar: the days the library is closed.
 *
 * Weekly closed days (loans.closed_weekdays), closure intervals with yearly
 * recurrence and optional branch, the Italian national holidays preset, ICS
 * import and a monthly preview. AdminAuthMiddleware also admits staff; like
 * the settings pages, every action is reserved to Permissions::mayConfigure().
 */
class LibraryCalendarController
{
    private const MAX_ICS_BYTES = 1048576;

    public function index(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        $query = $request->getQueryParams();
        $today = DateHelper::today();

        $month = is_string($query['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $query['month']) === 1
            ? $query['month']
            : substr($today, 0, 7);
        $branches = $this->branches($db);
        $previewSede = isset($query['sede']) && is_numeric($query['sede']) && isset($branches[(int) $query['sede']]) ? (int) $query['sede'] : null;

        $calendar = LibraryCalendar::fromDatabase($db);
        $closedWeekdays = $calendar->closedWeekdays();
        $closures = $this->closures($db);

        $editId = isset($query['edit']) && is_numeric($query['edit']) ? (int) $query['edit'] : 0;
        $editing = null;
        foreach ($closures as $row) {
            if ((int) $row['id'] === $editId) {
                $editing = $row;
            }
        }

        $monthStart = $month . '-01';
        $monthEnd = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        $monthClosed = array_flip($calendar->closedDatesBetween($monthStart, $monthEnd, $previewSede));
        $prevMonth = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
        $nextMonth = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('+1 month')->format('Y-m');
        $currentYear = (int) substr($today, 0, 4);

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
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        // No field (every box unticked) reopens the whole week; anything that
        // is not a list of weekdays 0-6 is refused whole, never half-saved.
        $raw = $data['closed_weekdays'] ?? [];
        if (!is_array($raw)) {
            $_SESSION['error_message'] = __('Giorno della settimana non valido.');
            return $this->back($response);
        }
        $days = [];
        foreach ($raw as $value) {
            if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value > 6) {
                $_SESSION['error_message'] = __('Giorno della settimana non valido.');
                return $this->back($response);
            }
            $days[(int) $value] = (int) $value;
        }
        if (count($days) === 7) {
            $_SESSION['error_message'] = __('Almeno un giorno della settimana deve restare aperto.');
            return $this->back($response);
        }
        ksort($days);
        $error = $this->commitChange($db, static function () use ($db, $days): ?string {
            (new SettingsRepository($db))->set('loans', 'closed_weekdays', implode(',', $days));
            return null;
        });
        $_SESSION[$error === null ? 'success_message' : 'error_message'] = $error ?? __('Giorni di chiusura settimanale salvati.');
        return $this->back($response);
    }

    public function store(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        $row = $this->validatedClosure($db, (array) $request->getParsedBody());
        if (is_string($row)) {
            $_SESSION['error_message'] = $row;
            return $this->back($response);
        }
        $error = $this->commitChange($db, function () use ($db, $row): ?string {
            $this->insertClosure($db, $row);
            return $this->yearCoverageError($db, $row);
        });
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
            return $this->back($response);
        }
        $_SESSION['success_message'] = __('Chiusura aggiunta.');
        return $this->back($response, substr($row['start'], 0, 7));
    }

    public function update(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        if (!$this->closureExists($db, $id)) {
            $_SESSION['error_message'] = __('Chiusura non trovata.');
            return $this->back($response);
        }
        $row = $this->validatedClosure($db, (array) $request->getParsedBody());
        if (is_string($row)) {
            $_SESSION['error_message'] = $row;
            return $this->back($response, null, $id);
        }
        $error = $this->commitChange($db, function () use ($db, $row, $id): ?string {
            $sede = $row['sede'];
            $yearly = $row['yearly'] ? 1 : 0;
            $stmt = $db->prepare('UPDATE calendario_chiusure SET data_inizio = ?, data_fine = ?, descrizione = ?, ricorrente_annuale = ?, sede_id = ? WHERE id = ?');
            $stmt->bind_param('sssiii', $row['start'], $row['end'], $row['description'], $yearly, $sede, $id);
            $stmt->execute();
            $stmt->close();
            return $this->yearCoverageError($db, $row);
        });
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
            return $this->back($response, null, $id);
        }
        $_SESSION['success_message'] = __('Chiusura aggiornata.');
        return $this->back($response, substr($row['start'], 0, 7));
    }

    public function delete(Request $request, Response $response, \mysqli $db, int $id): Response
    {
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        $deleted = false;
        $error = $this->commitChange($db, static function () use ($db, $id, &$deleted): ?string {
            $stmt = $db->prepare('DELETE FROM calendario_chiusure WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            return null;
        });
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
            return $this->back($response);
        }
        $_SESSION[$deleted ? 'success_message' : 'error_message'] = $deleted ? __('Chiusura eliminata.') : __('Chiusura non trovata.');
        return $this->back($response);
    }

    public function importPreset(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response, $db)) {
            return $guard;
        }
        $data = (array) $request->getParsedBody();
        $currentYear = (int) substr(DateHelper::today(), 0, 4);
        $year = is_scalar($data['year'] ?? null) ? (int) $data['year'] : $currentYear;
        if ($year < $currentYear - 1 || $year > $currentYear + 10) {
            $_SESSION['error_message'] = __('Anno non valido.');
            return $this->back($response);
        }
        $added = 0;
        // Under the calendar lock: two operators importing the same year
        // cannot both pass alreadyClosed() and insert the same holiday.
        $error = $this->commitChange($db, function () use ($db, $year, &$added): ?string {
            foreach (LibraryCalendar::italianHolidays($year) as $holiday) {
                $row = ['start' => $holiday['start'], 'end' => $holiday['end'], 'description' => $holiday['description'], 'yearly' => $holiday['yearly'], 'sede' => null];
                if (!$this->alreadyClosed($db, $row)) {
                    $this->insertClosure($db, $row);
                    $added++;
                }
            }
            return null;
        });
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
            return $this->back($response);
        }
        $_SESSION['success_message'] = __('Festività nazionali importate: %d nuove chiusure.', $added);
        return $this->back($response);
    }

    public function importIcs(Request $request, Response $response, \mysqli $db): Response
    {
        if ($guard = $this->guard($response, $db)) {
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
        $sede = $this->branchFromInput($db, $data['sede_id'] ?? null);
        if ($sede === false) {
            $_SESSION['error_message'] = __('Sede non trovata.');
            return $this->back($response);
        }
        $added = 0;
        $skipped = 0;
        $rejected = 0;
        $events = LibraryCalendar::parseIcs($ics);
        $error = $this->commitChange($db, function () use ($db, $events, $sede, &$added, &$skipped, &$rejected): ?string {
            foreach ($events as $event) {
                // The same limits as the form: a yearly event longer than a
                // year would otherwise be stored as a closure of every day.
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
                $newId = $this->insertClosure($db, $row);
                // Like the form: yearly rows that together close every day
                // of the year are refused, the event is not kept.
                if ($this->yearCoverageError($db, $row) !== null) {
                    $this->deleteClosure($db, $newId);
                    $rejected++;
                    continue;
                }
                $added++;
            }
            return null;
        });
        if ($error !== null) {
            $_SESSION['error_message'] = $error;
            return $this->back($response);
        }
        $_SESSION['success_message'] = $rejected > 0
            ? __('File ICS importato: %1$d chiusure aggiunte, %2$d già presenti, %3$d scartate perché più lunghe del limite (un anno se annuali, dieci anni altrimenti).', $added, $skipped, $rejected)
            : __('File ICS importato: %1$d chiusure aggiunte, %2$d già presenti.', $added, $skipped);
        return $this->back($response);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Every write goes through here, under one named lock (two operators
     * importing at once cannot both pass alreadyClosed()) and one
     * transaction: the write, then the pending pickups whose deadline is now
     * a closed day move to the next open day. A write that returns an error
     * message, or any failure (the deadline move included), rolls the whole
     * change back, so the expiry sweep never reads a calendar whose pickup
     * deadlines were not moved. Null on success, otherwise the message.
     *
     * @param callable(): ?string $write
     */
    private function commitChange(\mysqli $db, callable $write): ?string
    {
        $lockName = $this->lockName($db);
        try {
            $stmt = $db->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->bind_param('s', $lockName);
            $stmt->execute();
            $acquired = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) === 1;
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('Calendar: lock not available: ' . $e->getMessage());
            return __('Errore di salvataggio. Riprova più tardi.');
        }
        if (!$acquired) {
            return __('Un\'altra modifica del calendario è in corso: riprova tra poco.');
        }
        try {
            $db->begin_transaction();
            try {
                $error = $write();
                if ($error === null) {
                    // The deadline move reads the new calendar.
                    LibraryCalendar::reset();
                    LibraryCalendar::extendPickupDeadlines($db);
                    $db->commit();
                    return null;
                }
                $db->rollback();
                return $error;
            } catch (\Throwable $e) {
                $db->rollback();
                SecureLogger::error('Calendar: change not saved: ' . $e->getMessage());
                return __('Errore di salvataggio. Riprova più tardi.');
            }
        } finally {
            // Committed or rolled back, this request reads the stored state.
            LibraryCalendar::reset();
            \App\Support\ConfigStore::clearCache();
            try {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->get_result();
                $release->close();
            } catch (\Throwable) {
                // Released with the connection anyway.
            }
        }
    }

    /** Schema-scoped and under GET_LOCK()'s 64 characters (see CopyRepository). */
    private function lockName(\mysqli $db): string
    {
        $dbName = '';
        try {
            $res = $db->query('SELECT DATABASE()');
            if ($res instanceof \mysqli_result) {
                $dbName = (string) ($res->fetch_row()[0] ?? '');
                $res->free();
            }
        } catch (\Throwable) {
        }
        return 'pinakes-calendar:' . md5($dbName);
    }

    /**
     * Yearly closures repeat every year, so two rows each shorter than a year
     * can still close every day together; nextOpenDay() would then run to its
     * bound and a due date would land on a closed day. Checked after $row is
     * written (inside the transaction), on the yearly rows that apply to its
     * branch, or to the library and to every branch for a row without one.
     * Null when some day of a common year stays open.
     *
     * @param array{start: string, end: string, description: string, yearly: bool, sede: ?int} $row
     */
    private function yearCoverageError(\mysqli $db, array $row): ?string
    {
        if (!$row['yearly']) {
            return null;
        }
        $result = $db->query('SELECT data_inizio, data_fine, ricorrente_annuale, sede_id FROM calendario_chiusure WHERE ricorrente_annuale = 1');
        $rows = $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $calendar = new LibraryCalendar([], $rows);
        $branches = [$row['sede']];
        if ($row['sede'] === null) {
            foreach ($rows as $stored) {
                if ($stored['sede_id'] !== null) {
                    $branches[] = (int) $stored['sede_id'];
                }
            }
        }
        foreach (array_unique($branches, SORT_REGULAR) as $branch) {
            // A common year: Feb 29 open alone does not count.
            $day = '2025-01-01';
            $open = false;
            for ($i = 0; $i < 365 && !$open; $i++) {
                $open = $calendar->isOpen($day, $branch);
                $day = LibraryCalendar::shift($day, 1);
            }
            if (!$open) {
                return __('Con questa chiusura annuale la biblioteca resterebbe chiusa tutto l\'anno: almeno un giorno deve restare aperto.');
            }
        }
        return null;
    }

    private function deleteClosure(\mysqli $db, int $id): void
    {
        $stmt = $db->prepare('DELETE FROM calendario_chiusure WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function guard(Response $response, \mysqli $db): ?Response
    {
        if (!\App\Support\Permissions::mayConfigure()) {
            $_SESSION['error_message'] = __('Operazione riservata agli amministratori');
            return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
        }
        // Files updated before the 0.9.0 migration ran: the closures table is
        // not there yet, and every page and action of the calendar reads it.
        try {
            $res = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure'");
            $ready = $res instanceof \mysqli_result && $res->num_rows > 0;
        } catch (\Throwable) {
            $ready = false;
        }
        if (!$ready) {
            $_SESSION['error_message'] = __('Questa funzione sarà disponibile al termine dell\'aggiornamento del database.');
            return $response->withHeader('Location', url('/admin/dashboard'))->withStatus(302);
        }
        return null;
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
        $sede = $this->branchFromInput($db, $data['sede_id'] ?? null);
        if ($sede === false) {
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

    /**
     * The branch a form field names: null for "every branch" (field absent,
     * empty or 0), false for anything else that is not an existing branch
     * (an unknown or deleted id, garbage, an array), which the caller refuses
     * instead of storing a closure for every branch.
     */
    private function branchFromInput(\mysqli $db, mixed $raw): int|false|null
    {
        if ($raw === null || $raw === '' || $raw === '0' || $raw === 0) {
            return null;
        }
        return $this->branchFrom($db, $raw) ?? false;
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
    private function insertClosure(\mysqli $db, array $row): int
    {
        $yearly = $row['yearly'] ? 1 : 0;
        $sede = $row['sede'];
        $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssii', $row['start'], $row['end'], $row['description'], $yearly, $sede);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * An identical closure is already stored (same interval, recurrence and
     * branch; a yearly row matches the same month-day span in any year), so
     * importing a preset or a file twice adds nothing.
     *
     * @param array{start: string, end: string, description: string, yearly: bool, sede: ?int} $row
     */
    private function alreadyClosed(\mysqli $db, array $row): bool
    {
        $yearly = $row['yearly'] ? 1 : 0;
        $sede = $row['sede'] ?? 0;
        if ($yearly === 1) {
            $from = substr($row['start'], 5);
            $to = substr($row['end'], 5);
            $stmt = $db->prepare("SELECT 1 FROM calendario_chiusure WHERE ricorrente_annuale = 1 AND DATE_FORMAT(data_inizio, '%m-%d') = ? AND DATE_FORMAT(data_fine, '%m-%d') = ? AND COALESCE(sede_id, 0) = ? LIMIT 1");
            $stmt->bind_param('ssi', $from, $to, $sede);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM calendario_chiusure WHERE ricorrente_annuale = 0 AND data_inizio = ? AND data_fine = ? AND COALESCE(sede_id, 0) = ? LIMIT 1');
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
        $result = $db->query('SELECT c.id, c.data_inizio, c.data_fine, c.descrizione, c.ricorrente_annuale, c.sede_id, s.nome AS sede_nome
            FROM calendario_chiusure c LEFT JOIN sedi s ON s.id = c.sede_id
            ORDER BY c.ricorrente_annuale DESC, c.data_inizio, c.data_fine, c.id');
        return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
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
