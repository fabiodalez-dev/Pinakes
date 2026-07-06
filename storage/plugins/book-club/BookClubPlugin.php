<?php

declare(strict_types=1);

namespace App\Plugins\BookClub;

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Loaded eagerly: plugin files are included via require_once from the
// wrapper, outside the composer autoload map (same pattern as Archives).
require_once __DIR__ . '/src/Repo.php';
require_once __DIR__ . '/src/BaseController.php';
require_once __DIR__ . '/src/AdminController.php';
require_once __DIR__ . '/src/PublicController.php';
require_once __DIR__ . '/src/PollController.php';
require_once __DIR__ . '/src/MeetingController.php';
require_once __DIR__ . '/src/Permissions.php';
require_once __DIR__ . '/src/Modules/ModuleInterface.php';
require_once __DIR__ . '/src/Modules/AbstractModule.php';
require_once __DIR__ . '/src/Modules/Registry.php';

/**
 * Book Club — collaborative reading engine (Fase 1 / MVP of
 * docs/BOOK_CLUB_PLUGIN_PLAN.md, Discussion #138).
 *
 * Clubs + members/roles, per-club configurable book workflow, catalog-backed
 * proposals, simple/multi voting with deadline + auto close, meetings with
 * RSVP + iCal feed, personal multi-club dashboard.
 */
class BookClubPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /**
     * Reserved state key used for proposals awaiting moderation. Never part
     * of the editable workflow; only club managers see books in this state.
     */
    public const STATE_PENDING = 'pending';

    /** System role slugs seeded as global templates (club_id NULL). */
    public const SYSTEM_ROLES = ['owner', 'moderator', 'member', 'guest'];

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function getHookManager(): HookManager
    {
        return $this->hookManager;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Registration-time hook. Calls ensureSchema() like onActivate() does —
     * the project-wide plugin rule: not every upgrade path re-runs
     * onActivate() for already-active plugins, so a schema that exists only
     * behind activation can go silently missing after an update.
     * ensureSchema() is CREATE TABLE IF NOT EXISTS throughout, so this is
     * idempotent (same pattern as the Archives plugin). Failures are logged,
     * not thrown: registration of a disabled optional plugin must never
     * abort an install/upgrade.
     */
    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            SecureLogger::error('[BookClub] Schema install failed for: ' . implode(', ', $result['failed']));
        }
    }

    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[BookClub] Schema activation failed for: ' . implode(', ', $result['failed'])
                . '. See app.log for the mysqli error emitted during each CREATE TABLE.'
            );
        }
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 10);
            $this->registerHookInDb('admin.menu.render', 'renderAdminMenuEntry', 10);
            // Fired by MaintenanceService::runAll() after the core email
            // reminders — drives poll auto-close + meeting reminders even on
            // installs whose only "cron" is the on-admin-login fallback.
            $this->registerHookInDb('maintenance.after_run', 'onMaintenanceTick', 10);
            // Public quotes of the quotes module on the core book detail page.
            $this->registerHookInDb('book.frontend.details', 'renderBookQuotes', 10);
            // Documents the /api/v1/bookclub bridge inside mobile-api's
            // /api/v1/openapi.json, so the add-endpoint => add-manifest-row
            // guard sees the whole surface.
            $this->registerHookInDb('mobile_api.openapi', 'extendMobileOpenApi', 10);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Deactivation keeps every bookclub_* table in place (reading history is
     * more valuable than a clean uninstall); hooks are removed so the routes
     * stop responding.
     */
    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    /**
     * Data is intentionally preserved on uninstall as well: the plugin rows
     * in plugin_settings/plugin_data/plugin_logs are cascaded away by the
     * core, while club history survives a reinstall.
     */
    public function onUninstall(): void
    {
        SecureLogger::debug('[BookClub] Plugin uninstalled (bookclub_* tables preserved)');
    }

    // ------------------------------------------------------------------
    // Hook registration (plugin_hooks table)
    // ------------------------------------------------------------------

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[BookClub] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
        $del = $this->db->prepare(
            'DELETE FROM plugin_hooks WHERE plugin_id = ? AND hook_name = ? AND callback_method = ?'
        );
        if ($del !== false) {
            $del->bind_param('iss', $this->pluginId, $hookName, $method);
            $del->execute();
            $del->close();
        }
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())'
        );
        if ($stmt === false) {
            throw new \RuntimeException('[BookClub] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        // PluginManager instantiates the global proxy class (no namespace).
        $callbackClass = 'BookClubPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[BookClub] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            SecureLogger::error('[BookClub] deleteHooksFromDb prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    /**
     * Idempotent DDL (CREATE TABLE IF NOT EXISTS) + idempotent seeding of
     * the system roles and the default workflow template. Called from BOTH
     * onInstall() and onActivate() per the Plugin Schema Rule (upgrades do
     * not re-run onActivate on already-active plugins).
     *
     * JSON payloads (workflow states, role permissions, club settings) are
     * stored in TEXT columns for MariaDB compatibility — the core schema
     * does not use the JSON column type either.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array
    {
        $steps = [
            'bookclub_workflows'     => self::ddlWorkflows(),
            'bookclub_roles'         => self::ddlRoles(),
            'bookclub_clubs'         => self::ddlClubs(),
            'bookclub_members'       => self::ddlMembers(),
            'bookclub_invitations'   => self::ddlInvitations(),
            'bookclub_books'         => self::ddlBooks(),
            'bookclub_book_state_log' => self::ddlBookStateLog(),
            'bookclub_polls'         => self::ddlPolls(),
            'bookclub_poll_options'  => self::ddlPollOptions(),
            'bookclub_votes'         => self::ddlVotes(),
            'bookclub_meetings'      => self::ddlMeetings(),
            'bookclub_meeting_rsvps' => self::ddlMeetingRsvps(),
        ];
        $created = [];
        $failed = [];
        foreach ($steps as $table => $ddl) {
            try {
                if ($this->db->query($ddl) === false) {
                    $failed[] = $table;
                    SecureLogger::warning('[BookClub] CREATE TABLE failed for ' . $table . ': ' . $this->db->error);
                } else {
                    $created[] = $table;
                }
            } catch (\Throwable $e) {
                $failed[] = $table;
                SecureLogger::error('[BookClub] Exception during CREATE TABLE ' . $table . ': ' . $e->getMessage());
            }
        }

        if (empty($failed)) {
            $this->seedSystemRoles();
            $this->seedDefaultWorkflowTemplate();
        }

        // Module schemas run after the core tables they reference.
        foreach (Modules\Registry::all($this->db) as $module) {
            $result = $module->ensureSchema();
            $created = array_merge($created, $result['created']);
            $failed = array_merge($failed, $result['failed']);
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private static function ddlWorkflows(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_workflows (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NULL,
            name VARCHAR(190) NOT NULL,
            states TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bcwf_club (club_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlRoles(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_roles (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NULL,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(190) NOT NULL,
            permissions TEXT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcroles_scope (club_id, slug),
            KEY idx_bcroles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlClubs(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_clubs (
            id INT NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            rules TEXT NULL,
            color CHAR(7) NOT NULL DEFAULT '#4f46e5',
            privacy ENUM('public','private','invite','hidden') NOT NULL DEFAULT 'public',
            max_members INT NULL,
            settings TEXT NULL,
            workflow_id INT NULL,
            ics_token CHAR(32) NOT NULL,
            created_by INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcclubs_slug (slug),
            KEY idx_bcclubs_privacy (privacy),
            KEY idx_bcclubs_workflow (workflow_id),
            CONSTRAINT fk_bcclubs_workflow FOREIGN KEY (workflow_id)
                REFERENCES bookclub_workflows (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlMembers(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_members (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NOT NULL,
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            status ENUM('pending','active','suspended','left','banned') NOT NULL DEFAULT 'active',
            invited_by INT NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcmembers (club_id, user_id),
            KEY idx_bcmembers_user (user_id),
            KEY idx_bcmembers_role (role_id),
            CONSTRAINT fk_bcmembers_club FOREIGN KEY (club_id)
                REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcmembers_user FOREIGN KEY (user_id)
                REFERENCES utenti (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcmembers_role FOREIGN KEY (role_id)
                REFERENCES bookclub_roles (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlInvitations(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_invitations (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NOT NULL,
            email VARCHAR(190) NOT NULL,
            token CHAR(64) NOT NULL,
            role_id INT NULL,
            invited_by INT NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcinv_token (token),
            KEY idx_bcinv_club (club_id),
            KEY idx_bcinv_email (email),
            CONSTRAINT fk_bcinv_club FOREIGN KEY (club_id)
                REFERENCES bookclub_clubs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlBooks(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_books (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NOT NULL,
            libro_id INT NOT NULL,
            state VARCHAR(50) NOT NULL DEFAULT 'proposed',
            proposed_by INT NULL,
            motivation TEXT NULL,
            reading_starts DATE NULL,
            reading_ends DATE NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcbooks (club_id, libro_id),
            KEY idx_bcbooks_state (club_id, state),
            KEY idx_bcbooks_libro (libro_id),
            CONSTRAINT fk_bcbooks_club FOREIGN KEY (club_id)
                REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcbooks_libro FOREIGN KEY (libro_id)
                REFERENCES libri (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlBookStateLog(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_book_state_log (
            id INT NOT NULL AUTO_INCREMENT,
            club_book_id INT NOT NULL,
            from_state VARCHAR(50) NOT NULL,
            to_state VARCHAR(50) NOT NULL,
            changed_by INT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bclog_book (club_book_id),
            CONSTRAINT fk_bclog_book FOREIGN KEY (club_book_id)
                REFERENCES bookclub_books (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlPolls(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_polls (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NOT NULL,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            mode ENUM('simple','multi') NOT NULL DEFAULT 'simple',
            votes_per_member INT NOT NULL DEFAULT 1,
            anonymity ENUM('secret','public') NOT NULL DEFAULT 'public',
            closes_at DATETIME NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            winner_club_book_id INT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_bcpolls_club (club_id, status),
            CONSTRAINT fk_bcpolls_club FOREIGN KEY (club_id)
                REFERENCES bookclub_clubs (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlPollOptions(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_poll_options (
            id INT NOT NULL AUTO_INCREMENT,
            poll_id INT NOT NULL,
            club_book_id INT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcopts (poll_id, club_book_id),
            KEY idx_bcopts_book (club_book_id),
            CONSTRAINT fk_bcopts_poll FOREIGN KEY (poll_id)
                REFERENCES bookclub_polls (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcopts_book FOREIGN KEY (club_book_id)
                REFERENCES bookclub_books (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlVotes(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_votes (
            id INT NOT NULL AUTO_INCREMENT,
            poll_id INT NOT NULL,
            option_id INT NOT NULL,
            user_id INT NOT NULL,
            value DECIMAL(5,2) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcvotes (poll_id, option_id, user_id),
            KEY idx_bcvotes_user (poll_id, user_id),
            CONSTRAINT fk_bcvotes_poll FOREIGN KEY (poll_id)
                REFERENCES bookclub_polls (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcvotes_option FOREIGN KEY (option_id)
                REFERENCES bookclub_poll_options (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcvotes_user FOREIGN KEY (user_id)
                REFERENCES utenti (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlMeetings(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_meetings (
            id INT NOT NULL AUTO_INCREMENT,
            club_id INT NOT NULL,
            club_book_id INT NULL,
            title VARCHAR(190) NOT NULL,
            agenda TEXT NULL,
            minutes TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NULL,
            kind ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'in_person',
            location VARCHAR(255) NULL,
            video_url VARCHAR(500) NULL,
            seats INT NULL,
            status ENUM('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
            reminder_sent_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bcmeet_club (club_id, starts_at),
            KEY idx_bcmeet_book (club_book_id),
            CONSTRAINT fk_bcmeet_club FOREIGN KEY (club_id)
                REFERENCES bookclub_clubs (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcmeet_book FOREIGN KEY (club_book_id)
                REFERENCES bookclub_books (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private static function ddlMeetingRsvps(): string
    {
        return "CREATE TABLE IF NOT EXISTS bookclub_meeting_rsvps (
            id INT NOT NULL AUTO_INCREMENT,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            response ENUM('yes','no','maybe') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bcrsvp (meeting_id, user_id),
            KEY idx_bcrsvp_user (user_id),
            CONSTRAINT fk_bcrsvp_meeting FOREIGN KEY (meeting_id)
                REFERENCES bookclub_meetings (id) ON DELETE CASCADE,
            CONSTRAINT fk_bcrsvp_user FOREIGN KEY (user_id)
                REFERENCES utenti (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Seed the four system roles as global templates (club_id NULL).
     * Idempotent via INSERT … WHERE NOT EXISTS on (club_id IS NULL, slug).
     */
    private function seedSystemRoles(): void
    {
        $roles = [
            ['owner',     __('Fondatore'),  ['club.manage' => true, 'members.manage' => true, 'books.manage' => true, 'polls.manage' => true, 'meetings.manage' => true]],
            ['moderator', __('Moderatore'), ['members.manage' => true, 'books.manage' => true, 'polls.manage' => true, 'meetings.manage' => true]],
            ['member',    __('Membro'),     ['proposals.create' => true, 'votes.cast' => true, 'rsvp' => true]],
            ['guest',     __('Ospite'),     []],
        ];
        $stmt = $this->db->prepare(
            "INSERT INTO bookclub_roles (club_id, slug, name, permissions, is_system)
             SELECT NULL, ?, ?, ?, 1 FROM DUAL
              WHERE NOT EXISTS (
                    SELECT 1 FROM bookclub_roles WHERE club_id IS NULL AND slug = ?
              )"
        );
        if ($stmt === false) {
            SecureLogger::warning('[BookClub] seedSystemRoles prepare failed: ' . $this->db->error);
            return;
        }
        foreach ($roles as [$slug, $name, $perms]) {
            $permsJson = json_encode($perms, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('ssss', $slug, $name, $permsJson, $slug);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * The default workflow shipped with the plugin — the exact pipeline from
     * Discussion #138. Each club gets its own editable copy on creation; this
     * template row (club_id NULL) only feeds the clone.
     *
     * @return list<array{key: string, label: string, color: string, flags: array<string, bool>}>
     */
    public static function defaultWorkflowStates(): array
    {
        return [
            ['key' => 'proposed',   'label' => __('Proposto'),           'color' => '#6b7280', 'flags' => ['entry' => true, 'votable' => true]],
            ['key' => 'voting',     'label' => __('In votazione'),       'color' => '#8b5cf6', 'flags' => ['voting' => true]],
            ['key' => 'selected',   'label' => __('Scelto'),             'color' => '#0ea5e9', 'flags' => []],
            ['key' => 'reading',    'label' => __('In lettura'),         'color' => '#10b981', 'flags' => ['current' => true]],
            ['key' => 'discussion', 'label' => __('Discussione aperta'), 'color' => '#f59e0b', 'flags' => ['current' => true]],
            ['key' => 'finished',   'label' => __('Concluso'),           'color' => '#374151', 'flags' => []],
            ['key' => 'archived',   'label' => __('Archivio'),           'color' => '#9ca3af', 'flags' => ['archived' => true]],
        ];
    }

    private function seedDefaultWorkflowTemplate(): void
    {
        $states = json_encode(self::defaultWorkflowStates(), JSON_UNESCAPED_UNICODE);
        if ($states === false) {
            return;
        }
        $name = __('Workflow predefinito');
        $stmt = $this->db->prepare(
            "INSERT INTO bookclub_workflows (club_id, name, states)
             SELECT NULL, ?, ? FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM bookclub_workflows WHERE club_id IS NULL)"
        );
        if ($stmt === false) {
            SecureLogger::warning('[BookClub] seedDefaultWorkflowTemplate prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('ss', $name, $states);
        $stmt->execute();
        $stmt->close();
    }

    // ------------------------------------------------------------------
    // Routes (hook: app.routes.register)
    // ------------------------------------------------------------------

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface|null> $app
     */
    public function registerRoutes($app): void
    {
        $repo = new Repo($this->db);
        $admin = new AdminController($this->db, $repo);
        $public = new PublicController($this->db, $repo);
        $polls = new PollController($this->db, $repo);
        $meetings = new MeetingController($this->db, $repo);

        $adminMw = new \App\Middleware\AdminAuthMiddleware();
        $csrfMw = new \App\Middleware\CsrfMiddleware();
        // Any authenticated Pinakes user may participate in clubs.
        $authMw = new \App\Middleware\AuthMiddleware(['admin', 'staff', 'standard', 'premium']);

        // ---- Admin ----
        $app->get('/admin/book-club', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $admin->index($rq, $rs))->add($adminMw);
        $app->get('/admin/book-club/new', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $admin->form($rq, $rs, null))->add($adminMw);
        $app->post('/admin/book-club/new', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $admin->save($rq, $rs, null))->add($csrfMw)->add($adminMw);
        $app->get('/admin/book-club/{id:[0-9]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->show($rq, $rs, (int) $a['id']))->add($adminMw);
        $app->get('/admin/book-club/{id:[0-9]+}/edit', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->form($rq, $rs, (int) $a['id']))->add($adminMw);
        $app->post('/admin/book-club/{id:[0-9]+}/edit', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->save($rq, $rs, (int) $a['id']))->add($csrfMw)->add($adminMw);
        $app->post('/admin/book-club/{id:[0-9]+}/delete', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->delete($rq, $rs, (int) $a['id']))->add($csrfMw)->add($adminMw);
        $app->post('/admin/book-club/{id:[0-9]+}/workflow', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->saveWorkflow($rq, $rs, (int) $a['id']))->add($csrfMw)->add($adminMw);
        $app->post('/admin/book-club/{id:[0-9]+}/members/add', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->addMember($rq, $rs, (int) $a['id']))->add($csrfMw)->add($adminMw);
        $app->post('/admin/book-club/{id:[0-9]+}/members/{memberId:[0-9]+}/update', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $admin->updateMember($rq, $rs, (int) $a['id'], (int) $a['memberId']))->add($csrfMw)->add($adminMw);

        // ---- Public / member area ----
        $app->get('/book-club', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $public->index($rq, $rs));
        $app->get('/my/book-clubs', fn(ServerRequestInterface $rq, ResponseInterface $rs): ResponseInterface => $public->dashboard($rq, $rs))->add($authMw);
        $app->get('/book-club/invite/{token:[a-f0-9]{64}}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->acceptInvite($rq, $rs, (string) $a['token']))->add($authMw);
        $app->get('/book-club/{slug:[a-z0-9\-]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->show($rq, $rs, (string) $a['slug']));
        $app->get('/book-club/{slug:[a-z0-9\-]+}/calendar.ics', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $meetings->icsFeed($rq, $rs, (string) $a['slug']));
        $app->post('/book-club/{slug:[a-z0-9\-]+}/join', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->join($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/leave', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->leave($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/invite', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->sendInvite($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/members/{memberId:[0-9]+}/approve', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->approveMember($rq, $rs, (string) $a['slug'], (int) $a['memberId']))->add($csrfMw)->add($authMw);

        // Proposals
        $app->get('/book-club/{slug:[a-z0-9\-]+}/book-search', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->bookSearch($rq, $rs, (string) $a['slug']))->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/proposals', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->propose($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/books/{bookId:[0-9]+}/state', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $public->changeBookState($rq, $rs, (string) $a['slug'], (int) $a['bookId']))->add($csrfMw)->add($authMw);

        // Polls
        $app->get('/book-club/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $polls->show($rq, $rs, (string) $a['slug'], (int) $a['pollId']));
        $app->post('/book-club/{slug:[a-z0-9\-]+}/polls/new', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $polls->create($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}/vote', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $polls->vote($rq, $rs, (string) $a['slug'], (int) $a['pollId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/polls/{pollId:[0-9]+}/close', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $polls->close($rq, $rs, (string) $a['slug'], (int) $a['pollId']))->add($csrfMw)->add($authMw);

        // Meetings
        $app->post('/book-club/{slug:[a-z0-9\-]+}/meetings/new', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $meetings->create($rq, $rs, (string) $a['slug']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/meetings/{meetingId:[0-9]+}/rsvp', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $meetings->rsvp($rq, $rs, (string) $a['slug'], (int) $a['meetingId']))->add($csrfMw)->add($authMw);
        $app->post('/book-club/{slug:[a-z0-9\-]+}/meetings/{meetingId:[0-9]+}/status', fn(ServerRequestInterface $rq, ResponseInterface $rs, array $a): ResponseInterface => $meetings->changeStatus($rq, $rs, (string) $a['slug'], (int) $a['meetingId']))->add($csrfMw)->add($authMw);

        // Feature modules (reading, discussions, stats, …) attach their own
        // routes; per-club enablement is re-checked inside each handler.
        foreach (Modules\Registry::all($this->db) as $module) {
            try {
                $module->registerRoutes($app);
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] module ' . $module->slug() . ' registerRoutes failed: ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Admin sidebar (hook: admin.menu.render)
    // ------------------------------------------------------------------

    public function renderAdminMenuEntry(): void
    {
        $href = htmlspecialchars(url('/admin/book-club'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars(__('Book Club'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars(__('Club di lettura'), ENT_QUOTES, 'UTF-8');
        echo <<<HTML

          <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900"
            href="$href">
            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
              <i class="fas fa-book-reader text-gray-600"></i>
            </div>
            <div class="ml-3">
              <div class="font-medium">$title</div>
              <div class="text-xs text-gray-500">$subtitle</div>
            </div>
          </a>

        HTML;
    }

    // ------------------------------------------------------------------
    // Book detail page (hook: book.frontend.details — action, echoes HTML)
    // ------------------------------------------------------------------

    /**
     * Echo the club members' PUBLIC quotes for this book on the core book
     * detail page (app/Views/frontend/book-detail.php fires the action with
     * [$book, $bookId]). Delegates to the quotes module; a plain no-op when
     * the module or its table is missing.
     *
     * @param array<string, mixed>|mixed $book
     * @param mixed $bookId
     */
    /**
     * Filter target for mobile-api's 'mobile_api.openapi' hook: appends the
     * /api/v1/bookclub bridge paths when the bridge is actually mounted.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function extendMobileOpenApi(array $doc): array
    {
        return \App\Plugins\BookClub\Modules\MobileModule::extendOpenApi($doc, $this->db);
    }

    public function renderBookQuotes($book = null, $bookId = null): void
    {
        $libroId = is_numeric($bookId) ? (int) $bookId : (int) (is_array($book) ? ($book['id'] ?? 0) : 0);
        if ($libroId <= 0) {
            return;
        }
        try {
            foreach (Modules\Registry::all($this->db) as $module) {
                if ($module->slug() === 'quotes' && method_exists($module, 'renderBookDetailQuotes')) {
                    echo $module->renderBookDetailQuotes($libroId);
                    return;
                }
            }
        } catch (\Throwable $e) {
            SecureLogger::error('[BookClub] renderBookQuotes failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Automation (hook: maintenance.after_run)
    // ------------------------------------------------------------------

    /**
     * Scheduled sweep, invoked by MaintenanceService::runAll() (system cron
     * or the on-admin-login fallback):
     *  1. close every open poll whose deadline has passed (winner advances
     *     in the workflow, losers return to the entry state);
     *  2. email a reminder for meetings starting within the next 24 hours.
     * Both steps are idempotent (status flip / reminder_sent_at stamp).
     */
    public function onMaintenanceTick(): void
    {
        try {
            $repo = new Repo($this->db);
            $polls = new PollController($this->db, $repo);
            $polls->closeExpiredPolls();
            (new MeetingController($this->db, $repo))->sendDueReminders();
        } catch (\Throwable $e) {
            // Never let the plugin break the shared maintenance pass.
            SecureLogger::error('[BookClub] onMaintenanceTick failed: ' . $e->getMessage());
        }
        foreach (Modules\Registry::all($this->db) as $module) {
            try {
                $module->onMaintenanceTick();
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] module ' . $module->slug() . ' tick failed: ' . $e->getMessage());
            }
        }
    }
}
