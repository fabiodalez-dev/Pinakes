<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\GovernanceController;
use App\Support\EmailService;
use App\Support\NotificationService;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// The module system autoloads only src/Modules/*Module.php: pull in the
// controller and permission checker owned by this module (Repo/BaseController
// are already required by BookClubPlugin.php).
require_once __DIR__ . '/../Permissions.php';
require_once __DIR__ . '/../GovernanceController.php';

/**
 * Governance module — custom roles with granular permissions + per-club
 * automations (plan §6 and §7.15).
 *
 * Roles: bookclub_roles already stores per-club custom roles (club_id set,
 * is_system = 0, permissions JSON). This module adds the admin UI
 * (/admin/book-club/{id}/roles) and the App\Plugins\BookClub\Permissions
 * checker other modules can call.
 *
 * Automations: per-club reminders processed by the maintenance tick —
 *   reading.deadline  books in current-flagged states whose reading_ends
 *                     falls within offset_hours (email each active member
 *                     once per book);
 *   poll.closing      open polls whose closes_at falls within offset_hours
 *                     (email each active member once per poll);
 *   meeting.reminder  informational only: the plugin core already emails
 *                     24h before each meeting (MeetingController).
 * Channels: 'email' (per member), 'inapp' (one admin-stream notification per
 * entity via NotificationService) or 'both'. Idempotent via
 * bookclub_notification_log — the row is stamped BEFORE sending; the in-app
 * entity-level stamp uses user_id = 0.
 *
 * Tables: bookclub_automations, bookclub_notification_log.
 */
class GovernanceModule extends AbstractModule
{
    public const TRIGGER_READING = 'reading.deadline';
    public const TRIGGER_POLL = 'poll.closing';

    /** Editable triggers (meeting.reminder is core-owned, display only). */
    public const TRIGGERS = [self::TRIGGER_READING, self::TRIGGER_POLL];

    public const CHANNELS = ['email', 'inapp', 'both'];

    public const MIN_OFFSET_HOURS = 1;
    public const MAX_OFFSET_HOURS = 168;

    public function slug(): string
    {
        return 'governance';
    }

    public function label(): string
    {
        return __('Governance e automazioni');
    }

    public function description(): string
    {
        return __('Ruoli personalizzati con permessi granulari e promemoria automatici');
    }

    public function defaultEnabled(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    public function ensureSchema(): array
    {
        return $this->runDdl([
            'bookclub_automations' => "CREATE TABLE IF NOT EXISTS bookclub_automations (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                trigger_key VARCHAR(50) NOT NULL,
                channel ENUM('email','inapp','both') NOT NULL DEFAULT 'email',
                offset_hours INT NOT NULL DEFAULT 24,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcauto (club_id, trigger_key),
                CONSTRAINT fk_bcauto_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // user_id = 0 marks the entity-level in-app stamp, hence no FK
            // to utenti on purpose.
            'bookclub_notification_log' => "CREATE TABLE IF NOT EXISTS bookclub_notification_log (
                id INT NOT NULL AUTO_INCREMENT,
                club_id INT NOT NULL,
                trigger_key VARCHAR(50) NOT NULL,
                entity_id INT NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bcnlog (club_id, trigger_key, entity_id, user_id),
                KEY idx_bcnlog_user (user_id),
                CONSTRAINT fk_bcnlog_club FOREIGN KEY (club_id)
                    REFERENCES bookclub_clubs (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ]);
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    public function registerRoutes($app): void
    {
        $controller = new GovernanceController($this->db, $this->repo, $this);
        $adminMw = new \App\Middleware\AdminAuthMiddleware();
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        $app->get(
            '/admin/book-club/{id:[0-9]+}/roles',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->roles($rq, $rs, (int) $a['id'])
        )->add($adminMw);
        $app->post(
            '/admin/book-club/{id:[0-9]+}/roles',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->saveRole($rq, $rs, (int) $a['id'])
        )->add($csrfMw)->add($adminMw);
        $app->post(
            '/book-club/{slug:[a-z0-9\-]+}/automations',
            fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $controller->saveAutomations($rq, $rs, (string) $a['slug'])
        )->add($csrfMw)->add($authMw);
    }

    // ------------------------------------------------------------------
    // Club page panel (managers only)
    // ------------------------------------------------------------------

    public function renderClubPanel(array $ctx): string
    {
        $club = is_array($ctx['club'] ?? null) ? $ctx['club'] : null;
        if ($club === null || !$this->enabledFor($club) || empty($ctx['canManage'])) {
            return '';
        }
        return $this->renderPartial('partials/automations_panel', [
            'club' => $club,
            'automations' => $this->automationsFor((int) $club['id']),
            'csrf' => (string) ($ctx['csrf'] ?? \App\Support\Csrf::ensureToken()),
        ]);
    }

    // ------------------------------------------------------------------
    // Automations storage (shared with GovernanceController)
    // ------------------------------------------------------------------

    /**
     * Automation config per trigger, with inactive defaults for triggers
     * never configured by the club.
     *
     * @return array<string, array<string, mixed>> trigger_key → row
     */
    public function automationsFor(int $clubId): array
    {
        $out = [];
        foreach (self::TRIGGERS as $trigger) {
            $out[$trigger] = ['trigger_key' => $trigger, 'channel' => 'email', 'offset_hours' => 24, 'is_active' => 0];
        }
        $stmt = $this->db->prepare('SELECT trigger_key, channel, offset_hours, is_active FROM bookclub_automations WHERE club_id = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] automationsFor prepare failed: ' . $this->db->error);
            return $out;
        }
        $stmt->bind_param('i', $clubId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            foreach ($result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $row) {
                $key = (string) $row['trigger_key'];
                if (isset($out[$key])) {
                    $out[$key] = $row;
                }
            }
        }
        $stmt->close();
        return $out;
    }

    public function upsertAutomation(int $clubId, string $trigger, string $channel, int $offsetHours, int $isActive): bool
    {
        if (!in_array($trigger, self::TRIGGERS, true) || !in_array($channel, self::CHANNELS, true)) {
            return false;
        }
        $offsetHours = max(self::MIN_OFFSET_HOURS, min(self::MAX_OFFSET_HOURS, $offsetHours));
        $isActive = $isActive === 1 ? 1 : 0;
        $stmt = $this->db->prepare(
            'INSERT INTO bookclub_automations (club_id, trigger_key, channel, offset_hours, is_active)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE channel = VALUES(channel), offset_hours = VALUES(offset_hours), is_active = VALUES(is_active)'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] upsertAutomation prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('issii', $clubId, $trigger, $channel, $offsetHours, $isActive);
        $ok = $stmt->execute();
        if (!$ok) {
            SecureLogger::error('[BookClub:governance] upsertAutomation execute failed: ' . $stmt->error);
        }
        $stmt->close();
        return $ok;
    }

    // ------------------------------------------------------------------
    // Maintenance tick
    // ------------------------------------------------------------------

    public function onMaintenanceTick(): void
    {
        try {
            foreach ($this->repo->listAllClubs() as $club) {
                if ((int) ($club['is_active'] ?? 0) !== 1) {
                    continue;
                }
                // listAllClubs returns raw rows: decode settings so the
                // per-club enablement check sees the modules list.
                $settings = json_decode((string) ($club['settings'] ?? ''), true);
                $club['settings'] = is_array($settings) ? $settings : [];
                if (!$this->enabledFor($club)) {
                    continue;
                }
                $automations = $this->automationsFor((int) $club['id']);
                foreach (self::TRIGGERS as $trigger) {
                    $auto = $automations[$trigger];
                    if ((int) ($auto['is_active'] ?? 0) !== 1) {
                        continue;
                    }
                    $offset = max(self::MIN_OFFSET_HOURS, min(self::MAX_OFFSET_HOURS, (int) ($auto['offset_hours'] ?? 24)));
                    $channel = in_array($auto['channel'] ?? '', self::CHANNELS, true) ? (string) $auto['channel'] : 'email';
                    if ($trigger === self::TRIGGER_READING) {
                        $this->processReadingDeadline($club, $channel, $offset);
                    } else {
                        $this->processPollClosing($club, $channel, $offset);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let the module break the shared maintenance pass.
            SecureLogger::error('[BookClub:governance] onMaintenanceTick failed: ' . $e->getMessage());
        }
    }

    /**
     * reading.deadline — books in current-flagged states whose reading_ends
     * (a DATE) falls within the next $offset hours.
     *
     * @param array<string, mixed> $club
     */
    private function processReadingDeadline(array $club, string $channel, int $offset): void
    {
        $currentKeys = [];
        foreach ($this->repo->workflowStates($club) as $state) {
            if (!empty($state['flags']['current'])) {
                $currentKeys[] = (string) $state['key'];
            }
        }
        if ($currentKeys === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($currentKeys), '?'));
        $sql = "SELECT cb.id, cb.reading_ends, l.titolo
                  FROM bookclub_books cb
                  JOIN libri l ON l.id = cb.libro_id AND l.deleted_at IS NULL
                 WHERE cb.club_id = ? AND cb.state IN ($placeholders)
                   AND cb.reading_ends IS NOT NULL
                   AND cb.reading_ends >= CURDATE()
                   AND cb.reading_ends <= DATE(DATE_ADD(NOW(), INTERVAL ? HOUR))";
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] reading.deadline prepare failed: ' . $this->db->error);
            return;
        }
        $types = 'i' . str_repeat('s', count($currentKeys)) . 'i';
        $params = array_merge([(int) $club['id']], $currentKeys, [$offset]);
        $stmt->bind_param($types, ...$params);
        $books = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $books = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt->close();

        foreach ($books as $book) {
            $entityId = (int) $book['id'];
            $when = date('d/m/Y', (int) strtotime((string) $book['reading_ends']));
            $link = absoluteUrl('/book-club/' . $club['slug']);
            $subject = sprintf(
                __('La lettura di "%s" per il club "%s" si conclude a breve'),
                (string) $book['titolo'],
                (string) $club['name']
            );
            $bodyHtml = '<p>' . htmlspecialchars(sprintf(
                __('Il club "%s" concluderà la lettura di "%s" il %s.'),
                (string) $club['name'],
                (string) $book['titolo'],
                $when
            ), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('Vai alla pagina del club'), ENT_QUOTES, 'UTF-8') . '</a></p>';

            if ($channel === 'email' || $channel === 'both') {
                $this->emailMembers($club, self::TRIGGER_READING, $entityId, $subject, $bodyHtml);
            }
            if ($channel === 'inapp' || $channel === 'both') {
                $this->notifyOnce(
                    $club,
                    self::TRIGGER_READING,
                    $entityId,
                    __('Book Club: scadenza lettura'),
                    sprintf(
                        __('Nel club "%s" la lettura di "%s" termina il %s.'),
                        (string) $club['name'],
                        (string) $book['titolo'],
                        $when
                    ),
                    url('/book-club/' . $club['slug'])
                );
            }
        }
    }

    /**
     * poll.closing — open polls whose closes_at falls within the next
     * $offset hours.
     *
     * @param array<string, mixed> $club
     */
    private function processPollClosing(array $club, string $channel, int $offset): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, title, closes_at FROM bookclub_polls
              WHERE club_id = ? AND status = 'open' AND closes_at IS NOT NULL
                AND closes_at > NOW() AND closes_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)"
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] poll.closing prepare failed: ' . $this->db->error);
            return;
        }
        $clubId = (int) $club['id'];
        $stmt->bind_param('ii', $clubId, $offset);
        $polls = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $polls = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt->close();

        foreach ($polls as $poll) {
            $entityId = (int) $poll['id'];
            $when = date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']));
            $pollPath = '/book-club/' . $club['slug'] . '/polls/' . $entityId;
            $link = absoluteUrl($pollPath);
            $subject = sprintf(
                __('La votazione "%s" del club "%s" si chiude a breve'),
                (string) $poll['title'],
                (string) $club['name']
            );
            $bodyHtml = '<p>' . htmlspecialchars(sprintf(
                __('La votazione "%s" si chiude il %s: fai sentire la tua voce!'),
                (string) $poll['title'],
                $when
            ), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(__('Vai alla votazione'), ENT_QUOTES, 'UTF-8') . '</a></p>';

            if ($channel === 'email' || $channel === 'both') {
                $this->emailMembers($club, self::TRIGGER_POLL, $entityId, $subject, $bodyHtml);
            }
            if ($channel === 'inapp' || $channel === 'both') {
                $this->notifyOnce(
                    $club,
                    self::TRIGGER_POLL,
                    $entityId,
                    __('Book Club: votazione in chiusura'),
                    sprintf(
                        __('Nel club "%s" la votazione "%s" si chiude il %s.'),
                        (string) $club['name'],
                        (string) $poll['title'],
                        $when
                    ),
                    url($pollPath)
                );
            }
        }
    }

    /**
     * Email every active member exactly once per entity. The log row is
     * stamped BEFORE the SMTP call so a crashing pass never re-spams.
     *
     * @param array<string, mixed> $club
     */
    private function emailMembers(array $club, string $trigger, int $entityId, string $subject, string $bodyHtml): void
    {
        $emailService = new EmailService($this->db);
        foreach ($this->repo->activeMemberEmails((int) $club['id']) as $member) {
            if (!$this->stamp((int) $club['id'], $trigger, $entityId, (int) $member['id'])) {
                continue; // already notified (or stamp failed) — skip
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
                SecureLogger::error('[BookClub:governance] automation email failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * One in-app notification per entity (admin stream, not per member),
     * idempotent via the user_id = 0 stamp.
     *
     * @param array<string, mixed> $club
     */
    private function notifyOnce(array $club, string $trigger, int $entityId, string $title, string $message, string $link): void
    {
        if (!$this->stamp((int) $club['id'], $trigger, $entityId, 0)) {
            return;
        }
        try {
            (new NotificationService($this->db))->createNotification('general', $title, $message, $link, $entityId);
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub:governance] automation notification failed: ' . $e->getMessage());
        }
    }

    /**
     * INSERT IGNORE the idempotency stamp; true only when this call created
     * the row (i.e. the reminder has not been sent yet).
     */
    private function stamp(int $clubId, string $trigger, int $entityId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO bookclub_notification_log (club_id, trigger_key, entity_id, user_id) VALUES (?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[BookClub:governance] stamp prepare failed: ' . $this->db->error);
            return false;
        }
        $stmt->bind_param('isii', $clubId, $trigger, $entityId, $userId);
        $ok = $stmt->execute();
        $inserted = $ok && $stmt->affected_rows > 0;
        $stmt->close();
        return $inserted;
    }
}
