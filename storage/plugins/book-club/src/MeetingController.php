<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\EmailService;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Meetings: creation (club managers), RSVP with optional seat limit, per-club
 * iCal feed (subscribable from Google Calendar / Apple Calendar / Outlook)
 * and the 24h email reminder sent by the maintenance tick.
 */
class MeetingController extends BaseController
{
    /**
     * Schedule a meeting.
     *
     * Permission: `meetings.create` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'meetings.create')) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        $startsAt = self::dateTimeOrNull(self::str($body, 'starts_at', 30));
        if ($title === '' || $startsAt === null) {
            $this->flash('error', __('Titolo e data di inizio sono obbligatori.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $kind = self::str($body, 'kind', 20);
        if (!in_array($kind, ['in_person', 'online', 'hybrid'], true)) {
            $kind = 'in_person';
        }
        $videoUrl = self::str($body, 'video_url', 500);
        if ($videoUrl !== '' && !preg_match('#^https?://#i', $videoUrl)) {
            $videoUrl = '';
        }
        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
                $clubBookId = null;
            }
        }

        $meetingId = $this->repo->createMeeting((int) $club['id'], [
            'club_book_id' => $clubBookId,
            'title' => $title,
            'agenda' => self::str($body, 'agenda', 5000),
            'starts_at' => $startsAt,
            'ends_at' => self::dateTimeOrNull(self::str($body, 'ends_at', 30)),
            'kind' => $kind,
            'location' => self::str($body, 'location', 255),
            'video_url' => $videoUrl,
            'seats' => self::intOrNull($body, 'seats'),
        ], (int) $this->userId());

        if ($meetingId === null) {
            $this->flash('error', __('Incontro non creato, riprova.'));
        } else {
            if (function_exists('do_action')) {
                do_action('bookclub.meeting.created', $meetingId);
            }
            $this->flash('success', __('Incontro pianificato.'));
        }
        return $this->redirect($response, '/book-club/' . $slug);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $meetingId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'meetings.create')) {
            return $this->notFound($response);
        }
        $meeting = $this->repo->meeting($meetingId);
        if ($meeting === null || (int) $meeting['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $title = self::str($body, 'title', 190);
        $startsAt = self::dateTimeOrNull(self::str($body, 'starts_at', 30));
        if ($title === '' || $startsAt === null) {
            $this->flash('error', __('Titolo e data di inizio sono obbligatori.'));
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $kind = self::str($body, 'kind', 20);
        if (!in_array($kind, ['in_person', 'online', 'hybrid'], true)) {
            $kind = 'in_person';
        }
        $videoUrl = self::str($body, 'video_url', 500);
        if ($videoUrl !== '' && !preg_match('#^https?://#i', $videoUrl)) {
            $videoUrl = '';
        }
        $clubBookId = self::intOrNull($body, 'club_book_id');
        if ($clubBookId !== null) {
            $book = $this->repo->clubBook($clubBookId);
            if ($book === null || (int) $book['club_id'] !== (int) $club['id']) {
                $clubBookId = null;
            }
        }

        $ok = $this->repo->updateMeeting($meetingId, (int) $club['id'], [
            'club_book_id' => $clubBookId,
            'title' => $title,
            'agenda' => self::str($body, 'agenda', 5000),
            'starts_at' => $startsAt,
            'ends_at' => self::dateTimeOrNull(self::str($body, 'ends_at', 30)),
            'kind' => $kind,
            'location' => self::str($body, 'location', 255),
            'video_url' => $videoUrl,
            'seats' => self::intOrNull($body, 'seats'),
        ]);

        $this->flash($ok ? 'success' : 'error', $ok ? __('Incontro aggiornato.') : __('Incontro non aggiornato, riprova.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    public function rsvp(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $meetingId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->isActiveMember($club)) {
            return $this->notFound($response);
        }
        $meeting = $this->repo->meeting($meetingId);
        if ($meeting === null || (int) $meeting['club_id'] !== (int) $club['id'] || $meeting['status'] !== 'scheduled') {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $answer = self::str($body, 'response', 10);
        if (!in_array($answer, ['yes', 'no', 'maybe'], true)) {
            return $this->redirect($response, '/book-club/' . $slug);
        }
        $userId = (int) $this->userId();

        // Seat limit applies to "yes" answers only, unless the member is
        // already confirmed (switching yes → yes is idempotent).
        if ($answer === 'yes' && $meeting['seats'] !== null) {
            $current = $this->repo->userRsvp($meetingId, $userId);
            $alreadyYes = $current !== null && $current['response'] === 'yes';
            if (!$alreadyYes && (int) $meeting['yes_count'] >= (int) $meeting['seats']) {
                $this->flash('error', __('Non ci sono più posti disponibili per questo incontro.'));
                return $this->redirect($response, '/book-club/' . $slug);
            }
        }

        $this->repo->setRsvp($meetingId, $userId, $answer);
        $this->flash('success', __('Partecipazione registrata.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    /**
     * Mark a meeting done/cancelled and optionally attach the minutes.
     *
     * Permission: `meetings.minutes` (granular matrix — owner/moderator and
     * Pinakes admin/staff always pass, custom club roles per their JSON).
     */
    public function changeStatus(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $meetingId): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || !$this->can($club, 'meetings.minutes')) {
            return $this->notFound($response);
        }
        $meeting = $this->repo->meeting($meetingId);
        if ($meeting === null || (int) $meeting['club_id'] !== (int) $club['id']) {
            return $this->notFound($response);
        }
        $body = $request->getParsedBody();
        $status = self::str($body, 'status', 20);
        if (in_array($status, ['scheduled', 'done', 'cancelled'], true)) {
            $this->repo->setMeetingStatus($meetingId, $status);
        }
        $minutes = self::str($body, 'minutes', 20000);
        if ($minutes !== '') {
            $this->repo->setMeetingMinutes($meetingId, $minutes);
        }
        $this->flash('success', __('Incontro aggiornato.'));
        return $this->redirect($response, '/book-club/' . $slug);
    }

    // ------------------------------------------------------------------
    // iCal feed
    // ------------------------------------------------------------------

    /**
     * GET /book-club/{slug}/calendar.ics — public clubs serve the feed
     * openly; any other privacy requires ?token= matching the club's
     * ics_token (shown to members on the club page). Calendar apps cannot
     * send session cookies, hence the token-in-URL design.
     */
    public function icsFeed(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $club = $this->repo->clubBySlug($slug);
        if ($club === null || (int) $club['is_active'] !== 1) {
            return $this->notFound($response);
        }
        // The token is accepted for public clubs too: it proves membership
        // and unlocks the members-only fields (video-conference links).
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $tokenValid = $token !== '' && hash_equals((string) $club['ics_token'], $token);
        if ($club['privacy'] !== 'public' && !$tokenValid) {
            return $this->notFound($response);
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Pinakes//BookClub//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::icsEscape((string) $club['name']),
        ];
        foreach ($this->repo->clubMeetings((int) $club['id']) as $meeting) {
            if ($meeting['status'] === 'cancelled') {
                continue;
            }
            $start = strtotime((string) $meeting['starts_at']);
            if ($start === false) {
                continue;
            }
            $end = $meeting['ends_at'] !== null ? strtotime((string) $meeting['ends_at']) : false;
            if ($end === false || $end <= $start) {
                $end = $start + 7200; // default 2h
            }
            $summary = (string) $meeting['title'];
            if (!empty($meeting['book_title'])) {
                $summary .= ' — ' . (string) $meeting['book_title'];
            }
            $description = trim((string) ($meeting['agenda'] ?? ''));
            // video_url is members-only everywhere else (club page, REST
            // API): include it only when the feed URL carried the club
            // token, never in the anonymous public-club feed.
            if ($tokenValid && !empty($meeting['video_url'])) {
                // Real newline here — icsEscape() turns it into the literal
                // "\n" sequence required by RFC 5545.
                $description .= ($description !== '' ? "\n" : '') . (string) $meeting['video_url'];
            }
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:bookclub-meeting-' . (int) $meeting['id'] . '@pinakes';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $end);
            $lines[] = 'SUMMARY:' . self::icsEscape($summary);
            if ($description !== '') {
                $lines[] = 'DESCRIPTION:' . self::icsEscape($description);
            }
            if (!empty($meeting['location'])) {
                $lines[] = 'LOCATION:' . self::icsEscape((string) $meeting['location']);
            }
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        $response->getBody()->write(implode("\r\n", $lines) . "\r\n");
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . $slug . '.ics"');
    }

    private static function icsEscape(string $value): string
    {
        $value = str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $value);
        return $value;
    }

    // ------------------------------------------------------------------
    // Reminders (maintenance tick)
    // ------------------------------------------------------------------

    /**
     * Email every active member about meetings starting within 24 hours.
     * Idempotent via bookclub_meetings.reminder_sent_at.
     */
    public function sendDueReminders(): void
    {
        $due = $this->repo->meetingsNeedingReminder(24);
        if ($due === []) {
            return;
        }
        $emailService = new EmailService($this->db);
        foreach ($due as $meeting) {
            // Stamp first: a crashing SMTP pass must not re-spam on retry.
            if (!$this->repo->markReminderSent((int) $meeting['id'])) {
                continue;
            }
            $club = $this->repo->clubById((int) $meeting['club_id']);
            if ($club === null || (int) $club['is_active'] !== 1) {
                continue;
            }
            $when = date('d/m/Y H:i', (int) strtotime((string) $meeting['starts_at']));
            $subject = sprintf(__('Promemoria: "%s" — incontro del club "%s"'), (string) $meeting['title'], (string) $club['name']);
            $where = (string) ($meeting['location'] ?? '');
            if (!empty($meeting['video_url'])) {
                $where .= ($where !== '' ? ' / ' : '') . (string) $meeting['video_url'];
            }
            $link = absoluteUrl('/book-club/' . $club['slug']);
            $bodyHtml = '<p>' . htmlspecialchars(sprintf(
                __('Il club "%s" si incontra il %s.'),
                (string) $club['name'],
                $when
            ), ENT_QUOTES, 'UTF-8') . '</p>'
                . ($where !== '' ? '<p>' . htmlspecialchars(__('Dove:') . ' ' . $where, ENT_QUOTES, 'UTF-8') . '</p>' : '')
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('Vai alla pagina del club'), ENT_QUOTES, 'UTF-8') . '</a></p>';

            foreach ($this->repo->activeMemberEmails((int) $club['id']) as $member) {
                $rsvp = $this->repo->userRsvp((int) $meeting['id'], (int) $member['id']);
                if ($rsvp !== null && $rsvp['response'] === 'no') {
                    continue; // declined — no reminder
                }
                try {
                    $emailService->sendEmail(
                        (string) $member['email'],
                        $subject,
                        $bodyHtml,
                        trim((string) $member['nome'] . ' ' . (string) $member['cognome']),
                        $member['locale'] ?? null
                    );
                } catch (\Throwable $e) {
                    SecureLogger::error('[BookClub] reminder email failed: ' . $e->getMessage());
                }
            }
            if (function_exists('do_action')) {
                do_action('bookclub.meeting.reminded', (int) $meeting['id']);
            }
        }
    }
}
