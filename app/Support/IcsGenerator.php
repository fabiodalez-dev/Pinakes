<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * ICS Calendar Generator for library loans and reservations
 *
 * Generates iCalendar (.ics) files compatible with Google Calendar,
 * Apple Calendar, Outlook and other calendar applications.
 * Includes active loans, scheduled loans, and pending reservations.
 *
 * @package App\Support
 */
class IcsGenerator
{
    private mysqli $db;
    private string $calendarName;
    private string $timezone;

    /**
     * Create a new ICS generator instance
     *
     * @param mysqli $db Database connection
     * @param string $calendarName Display name for the calendar
     * @param string $timezone IANA timezone identifier (e.g., 'Europe/Rome')
     */
    public function __construct(mysqli $db, string $calendarName = 'Biblioteca - Prestiti e Prenotazioni', string $timezone = 'Europe/Rome')
    {
        $this->db = $db;
        $this->calendarName = $calendarName;
        $this->timezone = $timezone;
    }

    /**
     * Generate ICS file content as a string
     *
     * Fetches all active loans and reservations from the database
     * and formats them as iCalendar events with proper escaping.
     *
     * @return string Complete ICS file content with VCALENDAR wrapper
     */
    public function generate(): string
    {
        $events = $this->fetchEvents();

        $ics = $this->icsLine('BEGIN:VCALENDAR');
        $ics .= $this->icsLine('VERSION:2.0');
        $ics .= $this->icsLine('PRODID:-//Pinakes Library//Calendar//IT');
        $ics .= $this->icsLine('CALSCALE:GREGORIAN');
        $ics .= $this->icsLine('METHOD:PUBLISH');
        $ics .= $this->icsLine('X-WR-CALNAME:' . $this->escapeIcs($this->calendarName));
        $ics .= $this->icsLine('X-WR-TIMEZONE:' . $this->timezone);

        foreach ($events as $event) {
            $ics .= $this->formatEvent($event);
        }

        $ics .= $this->icsLine('END:VCALENDAR');

        return $ics;
    }

    /**
     * Generate and save ICS file to storage
     *
     * Creates the directory structure if it doesn't exist,
     * then writes the generated ICS content to the specified path.
     *
     * @param string $path Absolute path where the .ics file will be saved
     * @return bool True if file was written successfully, false otherwise
     */
    public function saveToFile(string $path): bool
    {
        $content = $this->generate();

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($path, $content) !== false;
    }

    /**
     * Fetch all events (loans and reservations) from database
     *
     * Retrieves active loans (in_corso, da_ritirare, prenotato, in_ritardo)
     * and active reservations, filtering out past events except overdue loans.
     *
     * @return array<int, array{uid: string, title: string, description: string, start: string, end: string, type: string, status: string, updated: string}> Array of event data
     */
    private function fetchEvents(): array
    {
        $events = [];
        $today = date('Y-m-d');

        // Fetch active/scheduled loans
        $loanSql = "SELECT p.id, p.stato, p.data_prestito, p.data_scadenza, p.pickup_deadline,
                           l.titolo, CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                           u.email, p.updated_at
                    FROM prestiti p
                    JOIN libri l ON p.libro_id = l.id AND l.deleted_at IS NULL
                    JOIN utenti u ON p.utente_id = u.id
                    WHERE p.attivo = 1
                      AND p.stato IN ('in_corso', 'da_ritirare', 'prenotato', 'in_ritardo')
                      AND (
                          (CASE
                              WHEN p.stato = 'da_ritirare' AND p.pickup_deadline IS NOT NULL
                              THEN p.pickup_deadline
                              ELSE p.data_scadenza
                           END) >= ?
                          OR p.stato = 'in_ritardo'
                      )
                    ORDER BY p.data_prestito ASC";
        $stmt = $this->db->prepare($loanSql);
        if ($stmt === false) {
            return $events; // Return empty array on prepare failure
        }
        if (!$stmt->bind_param('s', $today) || !$stmt->execute()) {
            $stmt->close();
            return $events;
        }
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // For da_ritirare state, use pickup_deadline as end date (shows pickup window)
            // For other states, use data_scadenza (shows full loan period)
            $endDate = ($row['stato'] === 'da_ritirare' && !empty($row['pickup_deadline']))
                ? $row['pickup_deadline']
                : $row['data_scadenza'];

            $events[] = [
                'uid' => 'loan-' . $row['id'] . '@pinakes',
                'title' => $this->getLoanTitle($row['stato'], $row['titolo']),
                'description' => $this->getLoanDescription($row),
                'start' => $row['data_prestito'],
                'end' => $endDate,
                'type' => 'prestito',
                'status' => $row['stato'],
                'updated' => $row['updated_at'] ?? date('Y-m-d H:i:s')
            ];
        }
        $stmt->close();

        // Fetch active reservations
        $resSql = "SELECT r.id, r.stato, r.data_scadenza_prenotazione,
                          r.data_inizio_richiesta, r.data_fine_richiesta,
                          l.titolo, CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                          u.email, r.updated_at
                   FROM prenotazioni r
                   JOIN libri l ON r.libro_id = l.id AND l.deleted_at IS NULL
                   JOIN utenti u ON r.utente_id = u.id
                   WHERE r.stato = 'attiva'
                     AND COALESCE(r.data_fine_richiesta, r.data_scadenza_prenotazione) >= ?
                   ORDER BY COALESCE(r.data_inizio_richiesta, r.data_scadenza_prenotazione) ASC";
        $stmt = $this->db->prepare($resSql);
        if ($stmt === false) {
            return $events;
        }
        if (!$stmt->bind_param('s', $today) || !$stmt->execute()) {
            $stmt->close();
            return $events;
        }
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $startDate = $row['data_inizio_richiesta'] ?? $row['data_scadenza_prenotazione'];
            $endDate = $row['data_fine_richiesta'] ?? $row['data_scadenza_prenotazione'];
            $events[] = [
                'uid' => 'reservation-' . $row['id'] . '@pinakes',
                'title' => '📅 ' . __('Prenotazione') . ': ' . $row['titolo'],
                'description' => $this->getReservationDescription($row),
                'start' => $startDate,
                'end' => $endDate,
                'type' => 'prenotazione',
                'status' => $row['stato'],
                'updated' => $row['updated_at'] ?? date('Y-m-d H:i:s')
            ];
        }
        $stmt->close();

        return $events;
    }

    /**
     * Get loan title with emoji prefix based on status
     *
     * @param string $status Loan status (in_corso, da_ritirare, prenotato, in_ritardo)
     * @param string $bookTitle Book title to include
     * @return string Formatted title with emoji and localized prefix
     */
    private function getLoanTitle(string $status, string $bookTitle): string
    {
        $prefix = match($status) {
            'in_corso' => '📖 ' . __('Prestito'),
            'da_ritirare' => '📦 ' . __('Da Ritirare'),
            'prenotato' => '📋 ' . __('Prestito Programmato'),
            'in_ritardo' => '⚠️ ' . __('Prestito Scaduto'),
            'pendente' => '⏳ ' . __('Richiesta Pendente'),
            default => '📖 ' . __('Prestito')
        };
        return $prefix . ': ' . $bookTitle;
    }

    /**
     * Build loan description with book, user and status info
     *
     * @param array{titolo: string, utente_nome: string, email?: string, stato: string} $row Loan data from database
     * @return string Multi-line description for ICS event
     */
    private function getLoanDescription(array $row): string
    {
        $desc = __('Libro') . ': ' . $row['titolo'] . "\n";
        $desc .= __('Utente') . ': ' . $row['utente_nome'] . "\n";
        if (!empty($row['email'])) {
            $desc .= __('Email') . ': ' . $row['email'] . "\n";
        }
        $desc .= __('Stato') . ': ' . $this->translateStatus($row['stato']);
        return $desc;
    }

    /**
     * Build reservation description with book and user info
     *
     * @param array{titolo: string, utente_nome: string, email?: string} $row Reservation data from database
     * @return string Multi-line description for ICS event
     */
    private function getReservationDescription(array $row): string
    {
        $desc = __('Libro') . ': ' . $row['titolo'] . "\n";
        $desc .= __('Utente') . ': ' . $row['utente_nome'] . "\n";
        if (!empty($row['email'])) {
            $desc .= __('Email') . ': ' . $row['email'] . "\n";
        }
        $desc .= __('Tipo') . ': ' . __('Prenotazione');
        return $desc;
    }

    /**
     * Translate loan/reservation status to localized string
     *
     * @param string $status Status code from database
     * @return string Localized status label
     */
    private function translateStatus(string $status): string
    {
        return match($status) {
            'in_corso' => __('In corso'),
            'da_ritirare' => __('Da Ritirare'),
            'prenotato' => __('Programmato'),
            'in_ritardo' => __('Scaduto'),
            'pendente' => __('In attesa'),
            'attiva' => __('Attiva'),
            default => $status
        };
    }

    /**
     * Format a single event as ICS VEVENT component
     *
     * Generates RFC 5545 compliant VEVENT with all-day dates,
     * proper escaping, and calendar color hints.
     *
     * @param array{uid: string, title: string, description: string, start: string, end: string, type: string, status: string, updated?: string} $event Event data
     * @return string ICS VEVENT block with CRLF line endings
     */
    private function formatEvent(array $event): string
    {
        $uid = $event['uid'];
        $summary = $this->escapeIcs($event['title']);
        $description = $this->escapeIcs($event['description']);

        // All-day events - use strtotime for robust date parsing
        $dtstart = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($event['start']));
        // ICS end date is exclusive, so add 1 day for all-day events
        $endDate = date('Ymd', strtotime($event['end'] . ' +1 day'));
        $dtend = 'DTEND;VALUE=DATE:' . $endDate;

        // Use gmdate for UTC timestamps (Z suffix means UTC)
        $dtstamp = gmdate('Ymd\THis\Z');
        // DB timestamps (updated_at via NOW()) live in the application timezone,
        // NOT the PHP process one: interpret them there and convert to UTC for
        // the Z-suffixed format, otherwise the offset drifts (e.g. UTC process).
        $lastmod = $dtstamp;
        if (isset($event['updated'])) {
            try {
                $dt = new \DateTime((string) $event['updated'], $this->appTimezone());
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $lastmod = $dt->format('Ymd\THis\Z');
            } catch (\Throwable $e) {
                // Malformed timestamp: fall back to DTSTAMP
                $lastmod = $dtstamp;
            }
        }

        // Color based on type/status
        $color = $this->getEventColor($event['type'], $event['status']);

        $ics = $this->icsLine('BEGIN:VEVENT');
        $ics .= $this->icsLine('UID:' . $uid);
        $ics .= $this->icsLine('DTSTAMP:' . $dtstamp);
        $ics .= $this->icsLine('LAST-MODIFIED:' . $lastmod);
        $ics .= $this->icsLine($dtstart);
        $ics .= $this->icsLine($dtend);
        $ics .= $this->icsLine('SUMMARY:' . $summary);
        $ics .= $this->icsLine('DESCRIPTION:' . $description);
        if ($color) {
            $ics .= $this->icsLine('X-APPLE-CALENDAR-COLOR:' . $color);
        }
        $ics .= $this->icsLine('TRANSP:TRANSPARENT');
        $ics .= $this->icsLine('END:VEVENT');

        return $ics;
    }

    /**
     * Application timezone for interpreting DB timestamps
     *
     * Resolves ConfigStore 'app.timezone' like DateHelper does, falling back to
     * the constructor timezone and finally to Europe/Rome if invalid.
     *
     * @return \DateTimeZone Resolved timezone object
     */
    private function appTimezone(): \DateTimeZone
    {
        $tz = (string) ConfigStore::get('app.timezone', $this->timezone);
        try {
            return new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            // 'app.timezone' presente ma invalido: prova PRIMA il timezone del
            // costruttore (che il chiamante ha scelto apposta) e solo come
            // ultima spiaggia Europe/Rome.
            try {
                return new \DateTimeZone($this->timezone);
            } catch (\Throwable $e2) {
                return new \DateTimeZone('Europe/Rome');
            }
        }
    }

    /**
     * Emit a single ICS content line, folded per RFC 5545 and CRLF-terminated
     *
     * @param string $line Unfolded content line (no trailing CRLF)
     * @return string Folded line(s) ending with CRLF
     */
    private function icsLine(string $line): string
    {
        return $this->foldLine($line) . "\r\n";
    }

    /**
     * Fold a content line to max 75 octets per physical line (RFC 5545 §3.1)
     *
     * Continuation lines start with a single space that counts toward the
     * budget (74 usable octets). Multibyte-safe: mb_strcut only cuts at UTF-8
     * character boundaries, so no byte sequence is ever split.
     *
     * @param string $line Unfolded content line
     * @return string Line folded with CRLF + space continuations
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = mb_strcut($line, 0, 75, 'UTF-8');
        $rest = substr($line, strlen($out));
        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74, 'UTF-8');
            if ($chunk === '') {
                // Defensive: invalid byte sequence mb_strcut refuses to cut —
                // take one raw byte to guarantee progress.
                $chunk = substr($rest, 0, 1);
            }
            $out .= "\r\n " . $chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $out;
    }

    /**
     * Get event color hex code based on type and status
     *
     * Returns Apple Calendar compatible color hints.
     * Purple for reservations, status-based colors for loans.
     *
     * @param string $type Event type ('prestito' or 'prenotazione')
     * @param string $status Loan/reservation status
     * @return string Hex color code (e.g., '#10B981')
     */
    private function getEventColor(string $type, string $status): string
    {
        if ($type === 'prenotazione') {
            return '#8B5CF6'; // Purple for reservations
        }

        return match($status) {
            'in_corso' => '#10B981', // Green
            'da_ritirare' => '#F97316', // Orange (ready for pickup)
            'prenotato' => '#3B82F6', // Blue
            'in_ritardo' => '#EF4444', // Red
            'pendente' => '#F59E0B', // Amber
            default => '#6B7280' // Gray
        };
    }

    /**
     * Escape text for ICS format per RFC 5545
     *
     * Escapes backslash, semicolon, comma and converts newlines
     * to literal \n sequences as required by the iCalendar spec.
     *
     * @param string $text Raw text to escape
     * @return string Escaped text safe for ICS properties
     */
    private function escapeIcs(string $text): string
    {
        // Escape special characters FIRST (before converting newlines)
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
        // Then replace real newlines with literal \n for ICS format
        $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
        return $text;
    }
}
