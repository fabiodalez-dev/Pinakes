<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * RFC 5545 calendar of ONE user's own loans, served by the token-protected
 * personal feed (GET /api/v1/calendar/feed/{token}.ics).
 *
 * Kept in the plugin (not in core IcsGenerator) so the plugin runs on the core
 * version it declares. It mirrors IcsGenerator's event rules — same loan
 * states, same end-date rule (pickup deadline for da_ritirare, at least today
 * for overdue loans), same `loan-{id}@pinakes` UID so a client that also
 * subscribed to the library calendar de-duplicates — with three differences:
 *
 *  - no personal data: the description holds the title and the state only,
 *    never a name or an e-mail address;
 *  - SEQUENCE = UNIX_TIMESTAMP(updated_at), monotonic per loan, so clients
 *    replace an event after a renewal or a state change;
 *  - DTSTAMP is the loan's last modification, not "now", so unchanged loans
 *    and usable calendar dates produce stable output (cheap 304 polling).
 *
 * Lines are CRLF-terminated, TEXT values escaped (\\ ; , newline) and folded at
 * 75 octets on UTF-8 boundaries.
 */
final class UserLoanCalendar
{
    /** Loan states shown in the feed (approved and still relevant). */
    public const STATES = ['in_corso', 'da_ritirare', 'prenotato', 'in_ritardo'];

    public function __construct(private \mysqli $db)
    {
    }

    public function generate(int $userId, string $calendarName, bool $withAlarm = true, bool $includeReservations = false): string
    {
        $tz = $this->timezone();
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $body = '';
        foreach ($this->loans($userId, $today) as $loan) {
            $body .= $this->loanEvent($loan, $today, $tz, $withAlarm);
        }
        if ($includeReservations) {
            foreach ($this->reservations($userId, $today) as $reservation) {
                $body .= $this->reservationEvent($reservation, $tz);
            }
        }

        $ics = self::line('BEGIN:VCALENDAR');
        $ics .= self::line('VERSION:2.0');
        $ics .= self::line('PRODID:-//Pinakes Library//Personal Loans//IT');
        $ics .= self::line('CALSCALE:GREGORIAN');
        $ics .= self::line('METHOD:PUBLISH');
        $ics .= self::line('X-WR-CALNAME:' . self::escape($calendarName));
        $ics .= self::line('X-WR-TIMEZONE:' . $tz->getName());
        $ics .= self::line('REFRESH-INTERVAL;VALUE=DURATION:PT6H');
        $ics .= self::line('X-PUBLISHED-TTL:PT6H');
        $ics .= $body;
        $ics .= self::line('END:VCALENDAR');

        return $ics;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loans(int $userId, string $today): array
    {
        $in = "'" . implode("','", self::STATES) . "'";
        $stmt = $this->db->prepare(
            "SELECT p.id, p.stato, p.copia_id, p.data_prestito, p.data_scadenza, p.pickup_deadline,
                    DATE_FORMAT(p.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
                    UNIX_TIMESTAMP(p.updated_at) AS seq, l.titolo
               FROM prestiti p
               JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
              WHERE p.utente_id = ?
                AND p.attivo = 1
                AND p.stato IN ({$in})
                AND ((CASE WHEN p.stato = 'da_ritirare' AND p.pickup_deadline IS NOT NULL
                           THEN p.pickup_deadline ELSE p.data_scadenza END) >= ?
                     OR p.stato IN ('in_corso', 'in_ritardo'))
              ORDER BY p.data_prestito ASC, p.id ASC"
        );
        if ($stmt === false) {
            throw new \RuntimeException('loan calendar prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = $r;
        }
        $stmt->close();

        \App\Support\LibraryCalendar::primeCopyBranches($this->db, array_map(static fn (array $r): int => (int) ($r['copia_id'] ?? 0), $rows));

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reservations(int $userId, string $today): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id, r.data_prenotazione, r.data_scadenza_prenotazione, r.data_inizio_richiesta,
                    r.data_fine_richiesta, DATE_FORMAT(r.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
                    UNIX_TIMESTAMP(r.updated_at) AS seq, l.titolo
               FROM prenotazioni r
               JOIN libri l ON l.id = r.libro_id AND l.deleted_at IS NULL
              WHERE r.utente_id = ? AND r.stato = 'attiva'
                AND COALESCE(r.data_fine_richiesta, DATE(r.data_scadenza_prenotazione), r.data_inizio_richiesta, DATE(r.data_prenotazione)) >= ?
              ORDER BY r.id"
        );
        if ($stmt === false) {
            throw new \RuntimeException('reservation calendar prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = $r;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * @param array<string, mixed> $loan
     */
    private function loanEvent(array $loan, string $today, \DateTimeZone $tz, bool $withAlarm): string
    {
        $state = (string) $loan['stato'];
        $dates = \App\Support\LibraryCalendar::loanDates($this->db, $loan, $today);
        if (in_array($state, ['in_corso', 'in_ritardo'], true)) {
            $state = $dates['is_overdue'] ? 'in_ritardo' : 'in_corso';
        }
        $end = ($state === 'da_ritirare' && !empty($loan['pickup_deadline']))
            ? (string) $loan['pickup_deadline']
            : (string) ($dates['effective_due_at'] ?? $loan['data_scadenza']);
        if ($state === 'in_ritardo') {
            // Still physically out today: never end the event on the missed date.
            $end = max($end, $today);
        }

        $title = (string) $loan['titolo'];
        $summary = match ($state) {
            'da_ritirare' => '📦 ' . __('Da Ritirare'),
            'prenotato'   => '📋 ' . __('Prestito Programmato'),
            'in_ritardo'  => '⚠️ ' . __('Prestito Scaduto'),
            default       => '📖 ' . __('Prestito'),
        } . ': ' . $title;
        $description = __('Libro') . ': ' . $title . "\n" . __('Stato') . ': ' . translate_loan_status($state);

        $alarm = '';
        if ($withAlarm && in_array($state, ['in_corso', 'prenotato'], true)) {
            // in_corso: the day before the event ends (= the due date, DTEND is
            // exclusive); prenotato: the day before the loan starts.
            $trigger = $state === 'in_corso' ? 'TRIGGER;RELATED=END:-P1D' : 'TRIGGER:-P1D';
            $alarm = self::line('BEGIN:VALARM')
                . self::line('ACTION:DISPLAY')
                . self::line('DESCRIPTION:' . self::escape($summary))
                . self::line($trigger)
                . self::line('END:VALARM');
        }

        return $this->event(
            'loan-' . (int) $loan['id'] . '@pinakes',
            (string) $loan['data_prestito'],
            $end,
            $summary,
            $description,
            (string) ($loan['updated_at'] ?? ''),
            (int) ($loan['seq'] ?? 0),
            $tz,
            $alarm
        );
    }

    /**
     * @param array<string, mixed> $r
     */
    private function reservationEvent(array $r, \DateTimeZone $tz): string
    {
        $start = $r['data_inizio_richiesta']
            ?? (!empty($r['data_prenotazione']) ? substr((string) $r['data_prenotazione'], 0, 10) : null)
            ?? substr((string) $r['data_scadenza_prenotazione'], 0, 10);
        $end = $r['data_fine_richiesta']
            ?? (!empty($r['data_scadenza_prenotazione']) ? substr((string) $r['data_scadenza_prenotazione'], 0, 10) : null)
            ?? $start;
        $title = (string) $r['titolo'];

        return $this->event(
            'reservation-' . (int) $r['id'] . '@pinakes',
            (string) $start,
            (string) $end,
            '📅 ' . __('Prenotazione') . ': ' . $title,
            __('Libro') . ': ' . $title . "\n" . __('Tipo') . ': ' . __('Prenotazione'),
            (string) ($r['updated_at'] ?? ''),
            (int) ($r['seq'] ?? 0),
            $tz,
            ''
        );
    }

    private function event(
        string $uid,
        string $start,
        string $end,
        string $summary,
        string $description,
        string $updatedAt,
        int $sequence,
        \DateTimeZone $tz,
        string $alarm
    ): string {
        $startDate = new \DateTimeImmutable($start, $tz);
        $endDate = (new \DateTimeImmutable($end, $tz))->modify('+1 day');
        if ($endDate <= $startDate) {
            $endDate = $startDate->modify('+1 day');
        }

        $stamp = self::utcStamp($updatedAt, $tz);

        $ics = self::line('BEGIN:VEVENT');
        $ics .= self::line('UID:' . $uid);
        $ics .= self::line('DTSTAMP:' . $stamp);
        $ics .= self::line('LAST-MODIFIED:' . $stamp);
        $ics .= self::line('SEQUENCE:' . max(0, $sequence));
        $ics .= self::line('DTSTART;VALUE=DATE:' . $startDate->format('Ymd'));
        $ics .= self::line('DTEND;VALUE=DATE:' . $endDate->format('Ymd'));
        $ics .= self::line('SUMMARY:' . self::escape($summary));
        $ics .= self::line('DESCRIPTION:' . self::escape($description));
        $ics .= self::line('TRANSP:TRANSPARENT');
        $ics .= $alarm;
        $ics .= self::line('END:VEVENT');

        return $ics;
    }

    /**
     * A DB timestamp (application timezone) as an RFC 5545 UTC date-time.
     */
    public static function utcStamp(string $local, \DateTimeZone $tz): string
    {
        try {
            $dt = $local !== '' ? new \DateTimeImmutable($local, $tz) : new \DateTimeImmutable('@0');
        } catch (\Throwable $e) {
            $dt = new \DateTimeImmutable('@0');
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * RFC 5545 §3.3.11 TEXT escaping.
     */
    public static function escape(string $text): string
    {
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    }

    /**
     * One content line, folded at 75 octets (RFC 5545 §3.1) without ever
     * splitting a UTF-8 sequence, terminated by CRLF.
     */
    public static function line(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line . "\r\n";
        }
        $out = mb_strcut($line, 0, 75, 'UTF-8');
        $rest = substr($line, strlen($out));
        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74, 'UTF-8');
            if ($chunk === '') {
                $chunk = substr($rest, 0, 1);
            }
            $out .= "\r\n " . $chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $out . "\r\n";
    }

    private function timezone(): \DateTimeZone
    {
        $name = (string) \App\Support\ConfigStore::get('app.timezone', 'Europe/Rome');
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable $e) {
            return new \DateTimeZone('Europe/Rome');
        }
    }
}
