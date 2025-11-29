<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

class IcsGenerator
{
    private mysqli $db;
    private string $calendarName;
    private string $timezone;

    public function __construct(mysqli $db, string $calendarName = 'Biblioteca - Prestiti e Prenotazioni', string $timezone = 'Europe/Rome')
    {
        $this->db = $db;
        $this->calendarName = $calendarName;
        $this->timezone = $timezone;
    }

    /**
     * Generate ICS file content
     */
    public function generate(): string
    {
        $events = $this->fetchEvents();

        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Pinakes Library//Calendar//IT\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:" . $this->escapeIcs($this->calendarName) . "\r\n";
        $ics .= "X-WR-TIMEZONE:" . $this->timezone . "\r\n";

        foreach ($events as $event) {
            $ics .= $this->formatEvent($event);
        }

        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Generate and save ICS file to storage
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
     * Fetch all events (loans and reservations)
     */
    private function fetchEvents(): array
    {
        $events = [];
        $today = date('Y-m-d');

        // Fetch active/scheduled loans
        $loanSql = "SELECT p.id, p.stato, p.data_prestito, p.data_scadenza,
                           l.titolo, CONCAT(u.nome, ' ', u.cognome) AS utente_nome,
                           u.email, p.updated_at
                    FROM prestiti p
                    JOIN libri l ON p.libro_id = l.id
                    JOIN utenti u ON p.utente_id = u.id
                    WHERE p.attivo = 1
                      AND p.stato IN ('in_corso', 'prenotato', 'in_ritardo', 'pendente')
                      AND p.data_scadenza >= ?
                    ORDER BY p.data_prestito ASC";
        $stmt = $this->db->prepare($loanSql);
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $events[] = [
                'uid' => 'loan-' . $row['id'] . '@pinakes',
                'title' => $this->getLoanTitle($row['stato'], $row['titolo']),
                'description' => $this->getLoanDescription($row),
                'start' => $row['data_prestito'],
                'end' => $row['data_scadenza'],
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
                   JOIN libri l ON r.libro_id = l.id
                   JOIN utenti u ON r.utente_id = u.id
                   WHERE r.stato = 'attiva'
                     AND COALESCE(r.data_fine_richiesta, r.data_scadenza_prenotazione) >= ?
                   ORDER BY COALESCE(r.data_inizio_richiesta, r.data_scadenza_prenotazione) ASC";
        $stmt = $this->db->prepare($resSql);
        $stmt->bind_param('s', $today);
        $stmt->execute();
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
     * Get loan title based on status
     */
    private function getLoanTitle(string $status, string $bookTitle): string
    {
        $prefix = match($status) {
            'in_corso' => '📖 ' . __('Prestito'),
            'prenotato' => '📋 ' . __('Prestito Programmato'),
            'in_ritardo' => '⚠️ ' . __('Prestito Scaduto'),
            'pendente' => '⏳ ' . __('Richiesta Pendente'),
            default => '📖 ' . __('Prestito')
        };
        return $prefix . ': ' . $bookTitle;
    }

    /**
     * Get loan description
     */
    private function getLoanDescription(array $row): string
    {
        $desc = __('Libro') . ': ' . $row['titolo'] . '\n';
        $desc .= __('Utente') . ': ' . $row['utente_nome'] . '\n';
        if (!empty($row['email'])) {
            $desc .= __('Email') . ': ' . $row['email'] . '\n';
        }
        $desc .= __('Stato') . ': ' . $this->translateStatus($row['stato']);
        return $desc;
    }

    /**
     * Get reservation description
     */
    private function getReservationDescription(array $row): string
    {
        $desc = __('Libro') . ': ' . $row['titolo'] . '\n';
        $desc .= __('Utente') . ': ' . $row['utente_nome'] . '\n';
        if (!empty($row['email'])) {
            $desc .= __('Email') . ': ' . $row['email'] . '\n';
        }
        $desc .= __('Tipo') . ': ' . __('Prenotazione');
        return $desc;
    }

    /**
     * Translate status
     */
    private function translateStatus(string $status): string
    {
        return match($status) {
            'in_corso' => __('In corso'),
            'prenotato' => __('Programmato'),
            'in_ritardo' => __('Scaduto'),
            'pendente' => __('In attesa'),
            'attiva' => __('Attiva'),
            default => $status
        };
    }

    /**
     * Format a single event as ICS
     */
    private function formatEvent(array $event): string
    {
        $uid = $event['uid'];
        $summary = $this->escapeIcs($event['title']);
        $description = $this->escapeIcs($event['description']);

        // All-day events
        $dtstart = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $event['start']);
        // ICS end date is exclusive, so add 1 day for all-day events
        $endDate = date('Ymd', strtotime($event['end'] . ' +1 day'));
        $dtend = 'DTEND;VALUE=DATE:' . $endDate;

        $dtstamp = date('Ymd\THis\Z');
        $lastmod = isset($event['updated'])
            ? date('Ymd\THis\Z', strtotime($event['updated']))
            : $dtstamp;

        // Color based on type/status
        $color = $this->getEventColor($event['type'], $event['status']);

        $ics = "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "\r\n";
        $ics .= "DTSTAMP:" . $dtstamp . "\r\n";
        $ics .= "LAST-MODIFIED:" . $lastmod . "\r\n";
        $ics .= $dtstart . "\r\n";
        $ics .= $dtend . "\r\n";
        $ics .= "SUMMARY:" . $summary . "\r\n";
        $ics .= "DESCRIPTION:" . $description . "\r\n";
        if ($color) {
            $ics .= "X-APPLE-CALENDAR-COLOR:" . $color . "\r\n";
        }
        $ics .= "TRANSP:TRANSPARENT\r\n";
        $ics .= "END:VEVENT\r\n";

        return $ics;
    }

    /**
     * Get event color based on type and status
     */
    private function getEventColor(string $type, string $status): string
    {
        if ($type === 'prenotazione') {
            return '#8B5CF6'; // Purple for reservations
        }

        return match($status) {
            'in_corso' => '#10B981', // Green
            'prenotato' => '#3B82F6', // Blue
            'in_ritardo' => '#EF4444', // Red
            'pendente' => '#F59E0B', // Amber
            default => '#6B7280' // Gray
        };
    }

    /**
     * Escape text for ICS format
     */
    private function escapeIcs(string $text): string
    {
        // Replace newlines with literal \n
        $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
        // Escape special characters
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
        return $text;
    }
}
