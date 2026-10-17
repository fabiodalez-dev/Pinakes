<?php
declare(strict_types=1);

/**
 * Digital Library Plugin
 *
 * Enables eBook (PDF/ePub) and audiobook management with integrated
 * Green Audio Player for audiobook playback.
 *
 * Features:
 * - File upload via Uppy (reusing existing infrastructure)
 * - eBook download buttons (PDF/ePub)
 * - Audiobook player with Green Audio Player
 * - Status badge icons showing digital content availability
 * - Optional and fully disableable
 *
 * @package Pinakes\Plugins\DigitalLibrary
 * @version 1.5.0
 */
class DigitalLibraryPlugin
{
    private ?\mysqli $db = null;
    private int $pluginId = 0;
    private static bool $routesRegistered = false;

    /**
     * Constructor
     *
     * @param \mysqli|null $db Database connection
     * @param object|null $hookManager Hook manager instance (part of plugin API contract)
     *
     * @phpstan-ignore constructor.unusedParameter
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
    }

    /**
     * Set plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->ensureHooksRegistered();
        $this->ensureStorageMigrated();
    }

    /**
     * Register hooks only when none are present for this plugin (self-heal).
     * setPluginId() runs on EVERY request via PluginManager::instantiatePlugin,
     * so the previous unconditional registerHooks() re-upserted every hook row
     * on every page view. One cheap COUNT replaces those writes; onActivate()
     * still forces a full re-sync.
     */
    private function ensureHooksRegistered(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            \App\Support\SecureLogger::error('[Digital Library] Hook self-heal count prepare failed', [
                'db_error' => $this->db->error,
            ]);
            return;
        }

        $stmt->bind_param('i', $this->pluginId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if (!$result instanceof \mysqli_result) {
                \App\Support\SecureLogger::error('[Digital Library] Hook self-heal count get_result failed', [
                    'stmt_error' => $stmt->error,
                ]);
                $stmt->close();
                return;
            }
            $row = $result->fetch_assoc();
            if ((int)($row['total'] ?? 0) === 0) {
                // Self-heal path (runs on EVERY request): registerHooks() now
                // throws on a write failure, but a plugin_hooks glitch must not
                // break page bootstrap. Log and continue; onActivate() still
                // propagates so activation surfaces the error.
                try {
                    $this->registerHooks();
                } catch (\Throwable $e) {
                    \App\Support\SecureLogger::error('[Digital Library] Hook self-heal registerHooks failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            \App\Support\SecureLogger::error('[Digital Library] Hook self-heal count execute failed', [
                'stmt_error' => $stmt->error,
            ]);
        }
        $stmt->close();
    }

    /**
     * Plugin activation hook
     */
    public function onActivate(): void
    {
        // Ensure the schema columns exist. Upgrades re-call onActivate() (not
        // onInstall()) for an already-active plugin, so the schema logic must
        // run here too — per the project's Plugin Schema Rule.
        $schema = $this->ensureSchema();
        if ($schema['failed'] !== []) {
            throw new \RuntimeException('[Digital Library] schema incomplete: ' . implode(', ', $schema['failed']));
        }

        // Files live in storage/digital/, outside the web root (issue #480). A folder that
        // cannot be created yet is logged, not fatal: the files are still served, checked,
        // from where they are, and the next request tries again.
        try {
            $this->storageDir();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Digital Library] storage/digital not available', ['error' => $e->getMessage()]);
        }
        $this->releaseLegacyDir();
        $this->migrateOnActivation();

        // Register hooks in database
        $this->registerHooks();
    }

    /**
     * Ensure the digital-content columns exist on `libri`.
     *
     * Idempotent (SHOW COLUMNS guards make it safe to call repeatedly) and
     * called from BOTH onActivate() and onInstall() so the schema is never
     * skipped on an upgrade of an already-active plugin. Widths match the
     * canonical installer/database/schema.sql definition (VARCHAR(255)).
     *
     * Public with the {created, failed} shape of the other schema-owning plugins, so
     * the schema guard and the boot self-heal can run it and read the outcome.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array
    {
        $outcome = ['created' => [], 'failed' => []];
        if (!$this->db) {
            return $outcome;
        }

        foreach (self::columnDefs() as $column => $alterSql) {
            $this->ensureColumn($column, $alterSql);
        }

        // Which records list which file: a cache DigitalAccess fills on first use, so
        // a range request of an audiobook is one key lookup instead of a catalogue scan.
        try {
            self::ensureIndexTable($this->db);
            $outcome['created'][] = 'digital_library_files';
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Digital Library] CREATE TABLE digital_library_files failed: ' . $e->getMessage());
            $outcome['failed'][] = 'digital_library_files';
        }
        return $outcome;
    }

    /**
     * The index table DigitalAccess reads (in core, so it answers with the plugin off too).
     * verified_at is a Unix time, like UNIX_TIMESTAMP(libri.updated_at): a DATETIME written
     * with NOW() would follow the connection's time_zone, which RememberMeService switches
     * to UTC in the middle of some requests, so two requests would disagree on "newer".
     * A table of the earlier DATETIME shape (development builds only) is a cache: rebuilt.
     */
    public static function ensureIndexTable(\mysqli $db): void
    {
        $type = null;
        $result = $db->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'digital_library_files' AND COLUMN_NAME = 'verified_at'");
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_row();
            $type = is_array($row) ? strtolower((string) $row[0]) : null;
            $result->free();
        }
        if ($type !== null && $type !== 'bigint' && $db->query('DROP TABLE digital_library_files') === false) {
            throw new \RuntimeException('digital_library_files rebuild failed: ' . $db->error);
        }
        $sql = 'CREATE TABLE IF NOT EXISTS digital_library_files (
            file_name VARCHAR(255) NOT NULL,
            libro_id INT NOT NULL,
            verified_at BIGINT NOT NULL,
            PRIMARY KEY (file_name, libro_id),
            KEY idx_dlf_libro (libro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if ($db->query($sql) === false) {
            throw new \RuntimeException('CREATE TABLE digital_library_files failed: ' . $db->error);
        }
    }

    /**
     * Declared to PluginManager::bundledSchemaIncomplete(): an already-active plugin
     * upgraded in place (stale-class trap) gets its index table on the next boot.
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return ['digital_library_files'];
    }

    /**
     * Single source of truth for the columns digital-library adds to `libri`,
     * shared by ensureSchema() (which adds them) and expectedColumns() (which
     * lets PluginManager's boot-time self-heal detect them missing after a
     * stale-class upgrade). Keep the two in sync via this list.
     *
     * @return array<string,string> column => ADD COLUMN DDL
     */
    private static function columnDefs(): array
    {
        return [
            'file_url'  => "ALTER TABLE libri ADD COLUMN file_url VARCHAR(255) DEFAULT NULL COMMENT 'eBook file URL' AFTER note_varie",
            'audio_url' => "ALTER TABLE libri ADD COLUMN audio_url VARCHAR(255) DEFAULT NULL COMMENT 'Audiobook file URL' AFTER file_url",
            'digital_attachments' => "ALTER TABLE libri ADD COLUMN digital_attachments LONGTEXT DEFAULT NULL AFTER audio_url",
        ];
    }

    /**
     * Declared to PluginManager::bundledSchemaIncomplete() so an already-active
     * digital-library at the same version whose columns are missing (stale-class
     * admin-UI upgrade) self-heals on the next boot by re-running onActivate.
     *
     * @return list<array{table:string, column:string}>
     */
    public function expectedColumns(): array
    {
        $out = [];
        foreach (array_keys(self::columnDefs()) as $column) {
            $out[] = ['table' => 'libri', 'column' => $column];
        }
        return $out;
    }

    /**
     * Add a column to `libri` if it is missing, failing loudly on any DDL
     * error. Returning silently would let onActivate() complete with a partial
     * schema (missing file_url/audio_url), so a later book save then fails.
     */
    private function ensureColumn(string $column, string $alterSql): void
    {
        if (!$this->db) {
            return;
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE '" . $this->db->real_escape_string($column) . "'");
        if ($result === false) {
            $err = "[Digital Library] SHOW COLUMNS for {$column} failed: " . $this->db->error;
            \App\Support\SecureLogger::error($err);
            throw new \RuntimeException($err);
        }

        if ($result instanceof \mysqli_result && $result->num_rows === 0) {
            if ($this->db->query($alterSql) === false) {
                $err = "[Digital Library] ALTER TABLE adding {$column} failed: " . $this->db->error;
                \App\Support\SecureLogger::error($err);
                throw new \RuntimeException($err);
            }
        }
    }

    /**
     * Plugin deactivation hook
     */
    public function onDeactivate(): void
    {
        // Remove registered hooks from database
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param("i", $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Plugin installation hook
     */
    public function onInstall(): void
    {
        // Verify database columns exist (shared idempotent schema logic).
        $this->ensureSchema();
        try {
            $this->storageDir();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Digital Library] storage/digital not available', ['error' => $e->getMessage()]);
        }
        $this->migrateOnActivation();
    }

    /**
     * Plugin uninstallation hook
     */
    public function onUninstall(): void
    {
        // Clean up hooks
        $this->onDeactivate();

        // Note: We don't drop the columns as they might contain data
        // Administrator can manually remove them if needed
    }

    // ========================================================================
    // Protected storage (issue #480)
    // ========================================================================

    /** storage/digital, created with the writable-folder mode and shut to direct requests. */
    private function storageDir(): string
    {
        return \App\Support\DigitalStorage::ensureDir(\App\Support\DigitalStorage::targetDir());
    }

    private function releaseLegacyDir(): void
    {
        \App\Support\DigitalStorage::releaseLegacyDir(\App\Support\DigitalStorage::legacyDir());
    }

    /**
     * Move the files still in public/uploads/digital/ into storage/digital/ on ordinary
     * requests after an upgrade. Runs from the NEW class (an upgrade through the admin UI
     * still executes the old one), within a small budget so no page waits; the large
     * copies across disks are left to the maintenance cron and to "Sposta ora". Until a
     * file is moved it is served, through the same permission check, from where it was.
     */
    private function ensureStorageMigrated(): void
    {
        $legacy = \App\Support\DigitalStorage::legacyDir();
        $target = \App\Support\DigitalStorage::targetDir();
        if (\App\Support\DigitalStorage::migrationDone($legacy, $target)) {
            return;
        }
        try {
            // retryFailed false: a file that could not be moved is not retried (and
            // re-hashed) on every page; the cron and "Sposta ora" try it again.
            \App\Support\DigitalStorage::migrate($legacy, $target, 200, 3.0, \App\Support\DigitalStorage::REQUEST_COPY_BUDGET, false);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[Digital Library] storage migration deferred', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Activation (and installation) check: a plugin that was switched off during the
     * upgrade to 0.8.6 never ran the move on page requests, so its files are still in
     * public/uploads/digital/. Move them now, failed ones included, within a budget an
     * admin request can afford; whatever is left is logged and finished by page requests,
     * the cron and "Sposta ora". Never fatal: until moved, files are served, checked,
     * from the old folder.
     */
    private function migrateOnActivation(): void
    {
        $legacy = \App\Support\DigitalStorage::legacyDir();
        $target = \App\Support\DigitalStorage::targetDir();
        try {
            if (\App\Support\DigitalStorage::migrationDone($legacy, $target) && \App\Support\DigitalStorage::knownFailures($target) === 0) {
                return;
            }
            $result = \App\Support\DigitalStorage::migrate($legacy, $target, PHP_INT_MAX, 20.0, 4 * \App\Support\DigitalStorage::REQUEST_COPY_BUDGET);
            if ($result['remaining'] > 0 || $result['failed'] > 0) {
                \App\Support\SecureLogger::warning('[Digital Library] files still in public/uploads/digital after activation', $result);
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[Digital Library] storage migration on activation deferred', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Hook maintenance.after_run (daily cron, and the maintenance pass the web runs):
     * finish the move, including files too large for a page request.
     */
    public function onMaintenanceTick(): void
    {
        $legacy = \App\Support\DigitalStorage::legacyDir();
        $target = \App\Support\DigitalStorage::targetDir();
        // Files a page request gave up on are retried here, after the move is marked done.
        if (\App\Support\DigitalStorage::migrationDone($legacy, $target) && \App\Support\DigitalStorage::knownFailures($target) === 0) {
            return;
        }
        $cli = PHP_SAPI === 'cli';
        if ($cli && function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        try {
            \App\Support\DigitalStorage::migrate($legacy, $target, PHP_INT_MAX, $cli ? 1800.0 : 10.0,
                $cli ? PHP_INT_MAX : 4 * \App\Support\DigitalStorage::REQUEST_COPY_BUDGET);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[Digital Library] storage migration (maintenance) deferred', ['error' => $e->getMessage()]);
        }
    }

    /**
     * "Sposta ora" in the settings page: everything, without the page-request budget.
     *
     * @return array{moved:int, remaining:int, failed:int}
     */
    public function migrateLegacyFiles(int $maxFiles, float $maxSeconds, int $copyBudget = PHP_INT_MAX): array
    {
        return \App\Support\DigitalStorage::migrate(\App\Support\DigitalStorage::legacyDir(), \App\Support\DigitalStorage::targetDir(),
            $maxFiles, $maxSeconds, $copyBudget);
    }

    /** @return array{public:int, storage:int, failed:int} files in the old folder, in storage/digital, and those that cannot be moved */
    public function storageCounts(): array
    {
        return [
            'public' => \App\Support\DigitalStorage::countFiles(\App\Support\DigitalStorage::legacyDir()),
            'storage' => \App\Support\DigitalStorage::countFiles(\App\Support\DigitalStorage::targetDir()),
            'failed' => \App\Support\DigitalStorage::knownFailures(\App\Support\DigitalStorage::targetDir()),
        ];
    }

    // ========================================================================
    // Settings page
    // ========================================================================

    public function hasSettingsPage(): bool
    {
        return true;
    }

    public function getSettingsViewPath(): string
    {
        return __DIR__ . '/views/settings.php';
    }

    /** @return array{default_visibility:string, show_locked_entries:string} */
    public function getSettings(): array
    {
        if ($this->db === null) {
            return ['default_visibility' => 'public', 'show_locked_entries' => '1'];
        }
        return [
            'default_visibility' => \App\Support\DigitalAccess::defaultVisibility($this->db),
            'show_locked_entries' => \App\Support\DigitalAccess::showLockedEntries($this->db) ? '1' : '0',
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): bool
    {
        if ($this->db === null) {
            return false;
        }
        $visibility = (string) ($settings['default_visibility'] ?? 'public');
        if (!in_array($visibility, ['public', 'users', 'staff', 'loan'], true)) {
            return false;
        }
        try {
            $repo = new \App\Models\SettingsRepository($this->db);
            $repo->set(\App\Support\DigitalAccess::CATEGORY, \App\Support\DigitalAccess::DEFAULT_KEY, $visibility);
            $repo->set(\App\Support\DigitalAccess::CATEGORY, \App\Support\DigitalAccess::SHOW_LOCKED_KEY,
                ($settings['show_locked_entries'] ?? '0') === '1' ? '1' : '0');
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Digital Library] saveSettings failed', ['error' => $e->getMessage()]);
            return false;
        }
        \App\Support\DigitalAccess::forgetDefault();
        return true;
    }

    /**
     * Register plugin hooks in database
     */
    private function registerHooks(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $hooks = [
            [
                'hook_name' => 'book.form.digital_fields',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAdminFormFields',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_buttons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderFrontendButtons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_player',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAudioPlayer',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_player',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderPdfViewer',
                'priority' => 20,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.badge.digital_icons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderBadgeIcons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'app.routes.register',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'registerRoutes',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'assets.head',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'enqueueAssets',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                // Finishes moving old files into storage/digital (issue #480).
                'hook_name' => 'maintenance.after_run',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'onMaintenanceTick',
                'priority' => 10,
                'is_active' => 1
            ]
        ];

        // Participate in a caller transaction without committing it. The
        // savepoint keeps this hook set atomic even if the caller catches the
        // propagated exception; otherwise this method owns the transaction.
        $ownsTransaction = !$this->hasActiveTransaction();
        $savepoint = 'pinakes_digital_hooks_' . $this->pluginId . '_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            if (!$this->db->begin_transaction()) {
                throw new \RuntimeException('Unable to begin Digital Library hook transaction');
            }
        } elseif (!$this->db->query("SAVEPOINT {$savepoint}")) {
            throw new \RuntimeException('Unable to create Digital Library hook savepoint');
        }
        try {
            foreach ($hooks as $hook) {
                // Atomic upsert — relies on UNIQUE KEY uk_plugin_hook_callback
                // (plugin_id, hook_name, callback_method)
                $stmt = $this->db->prepare("
                    INSERT INTO plugin_hooks
                    (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        priority  = VALUES(priority),
                        is_active = VALUES(is_active)
                ");
                if (!$stmt) {
                    throw new \RuntimeException("Hook upsert prepare failed for {$hook['hook_name']}: {$this->db->error}");
                }
                $stmt->bind_param(
                    "isssii",
                    $this->pluginId,
                    $hook['hook_name'],
                    $hook['callback_class'],
                    $hook['callback_method'],
                    $hook['priority'],
                    $hook['is_active']
                );
                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    throw new \RuntimeException("Hook upsert failed for {$hook['hook_name']}: {$error}");
                }
                $stmt->close();
            }
            if ($ownsTransaction) {
                if (!$this->db->commit()) {
                    throw new \RuntimeException("Digital Library hook commit failed: {$this->db->error}");
                }
            } else {
                if (!$this->db->query("RELEASE SAVEPOINT {$savepoint}")) {
                    throw new \RuntimeException("Digital Library hook savepoint release failed: {$this->db->error}");
                }
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollback();
            } else {
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->query("RELEASE SAVEPOINT {$savepoint}");
            }
            \App\Support\SecureLogger::error('[Digital Library] Hook registration failed, rolled back', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detect both autocommit(false) and an explicit begin_transaction().
     * The latter leaves @@autocommit at 1, so a disposable savepoint probe is
     * required to avoid silently committing the caller's transaction.
     *
     * Contract: a caller that disables autocommit owns the resulting unit of
     * work and must eventually commit or roll it back. Core bootstrap keeps
     * autocommit enabled, so standalone self-heal still owns and commits its
     * transaction; committing an autocommit(false) session here would instead
     * violate deliberate caller transaction boundaries.
     */
    private function hasActiveTransaction(): bool
    {
        $db = $this->db;
        if ($db === null) {
            return false;
        }

        $result = $db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int)($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        $probe = 'pinakes_digital_probe_' . bin2hex(random_bytes(6));
        $probeCreated = false;
        try {
            if (!$db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            $probeCreated = true;
            if (!$db->query("ROLLBACK TO SAVEPOINT {$probe}")) {
                return false;
            }
            return true;
        } catch (\mysqli_sql_exception) {
            return false;
        } finally {
            if ($probeCreated) {
                try {
                    $db->query("RELEASE SAVEPOINT {$probe}");
                } catch (\mysqli_sql_exception) {
                    // In autocommit mode SAVEPOINT may not persist. Cleanup was
                    // still attempted; there is no caller transaction to alter.
                }
            }
        }
    }

    /**
     * Register plugin routes (file upload endpoint)
     * Hook: app.routes.register
     *
     * @param \Slim\App $app
     * @return void
     */
    public function registerRoutes($app): void
    {
        // Prevent duplicate registration
        if (self::$routesRegistered) {
            return;
        }
        self::$routesRegistered = true;

        // Capture plugin instance for use in closures
        $plugin = $this;

        // Register upload endpoint.
        //
        // AdminAuthMiddleware, like every other /admin route: the handler's own
        // check reads $_SESSION['user']['tipo_utente'], which is the role as it
        // was at sign-in. Without the middleware an administrator suspended or
        // demoted mid-session kept uploading from the session they already had
        // — this was the one administrative endpoint in the application mounted
        // without the re-validation, and the CSRF check beside it does not
        // answer an authorisation question. The handler's check stays: it is
        // the defence that survives someone mounting this route differently.
        $app->post('/admin/plugins/digital-library/upload', function ($request, $response) use ($plugin) {
            return $plugin->handleUploadRequest($request, $response, []);
        })->add(new \App\Middleware\AdminAuthMiddleware());

        // Serve plugin-specific assets without exposing storage/ directly
        $app->get('/plugins/digital-library/assets/{type}/{filename}', function ($request, $response, array $args) use ($plugin) {
            return $plugin->serveAsset($request, $response, $args);
        });

        \App\Support\SecureLogger::debug('[Digital Library Plugin] Routes registered', [
            'routes' => ['/admin/plugins/digital-library/upload', '/plugins/digital-library/assets/{type}/{filename}']
        ]);
    }

    // ========================================================================
    // Hook Callback Methods
    // ========================================================================

    /**
     * Render admin form fields for digital content upload
     * Hook: book.form.digital_fields
     */
    public function renderAdminFormFields(array $book): void
    {
        $defaultVisibility = $this->getSettings()['default_visibility'];
        include __DIR__ . '/views/admin-form-fields.php';
    }

    /** Use validated attachments for the legacy one-file players too. */
    private function withPrimaryAttachment(array $book, array $attachments): array
    {
        $book['file_url'] = '';
        $book['audio_url'] = '';
        foreach ($attachments as $attachment) {
            $field = $attachment['kind'] === 'audio' ? 'audio_url' : 'file_url';
            if ($attachment['kind'] !== 'supplement' && $book[$field] === '') {
                $book[$field] = $attachment['url'];
            }
        }
        return $book;
    }

    /**
     * The attachments this reader may see on the page, each with its `access`: the ones
     * they may open, plus (unless the settings hide them) the ones that need a login or
     * a loan, listed with the reason. Staff-only files are never shown to anyone else.
     *
     * @return list<array<string, mixed>>
     */
    private function visibleAttachments(array $book): array
    {
        $attachments = \App\Support\DigitalAttachments::fromBook($book);
        if ($this->db === null || $attachments === []) {
            return array_map(static fn (array $a): array => $a + ['access' => \App\Support\DigitalAccess::GRANTED], $attachments);
        }
        $viewer = isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
        $showLocked = \App\Support\DigitalAccess::showLockedEntries($this->db);
        $out = [];
        foreach (\App\Support\DigitalAccess::forViewer($book, $attachments, $viewer, $this->db) as $attachment) {
            if ($attachment['access'] === \App\Support\DigitalAccess::GRANTED
                || ($showLocked && $attachment['access'] !== \App\Support\DigitalAccess::STAFF_ONLY)) {
                $out[] = $attachment;
            }
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $attachments */
    private static function granted(array $attachments): array
    {
        return array_values(array_filter($attachments, static fn (array $a): bool => $a['access'] === \App\Support\DigitalAccess::GRANTED));
    }

    /**
     * The card list (every file in its own card, players inside) instead of the
     * single-file buttons: several files, a related document, or a closed entry.
     *
     * @param list<array<string, mixed>> $attachments
     */
    private static function usesList(array $attachments): bool
    {
        return count($attachments) > 1 || ($attachments[0]['kind'] ?? '') === 'supplement'
            || ($attachments !== [] && self::granted($attachments) === []);
    }

    /**
     * Render frontend download buttons
     * Hook: book.detail.digital_buttons
     */
    public function renderFrontendButtons(array $book): void
    {
        $attachments = $this->visibleAttachments($book);
        if ($attachments === []) {
            return;
        }
        if (self::usesList($attachments)) {
            include __DIR__ . '/views/frontend-attachments.php';
            return;
        }
        $book = $this->withPrimaryAttachment($book, $attachments);
        include __DIR__ . '/views/frontend-buttons.php';
    }

    /**
     * Render audio player
     * Hook: book.detail.digital_player
     */
    public function renderAudioPlayer(array $book): void
    {
        $attachments = $this->visibleAttachments($book);
        if ($attachments === [] || self::usesList($attachments)) { return; }
        $book = $this->withPrimaryAttachment($book, $attachments);
        if (!empty($book['audio_url'])) {
            $this->enqueueAudioPlayerAssets();
            include __DIR__ . '/views/frontend-player.php';
        }
    }

    /**
     * Render fullscreen PDF reader
     * Hook: book.detail.digital_player (priority 20, after audio player)
     */
    public function renderPdfViewer(array $book): void
    {
        $attachments = $this->visibleAttachments($book);
        if ($attachments === [] || self::usesList($attachments)) { return; }
        $book = $this->withPrimaryAttachment($book, $attachments);
        $fileUrl = (string) ($book['file_url'] ?? '');
        $filePath = (string) (parse_url($fileUrl, PHP_URL_PATH) ?? '');
        if ($filePath !== '' && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf') {
            include __DIR__ . '/views/frontend-pdf-viewer.php';
        }
    }

    /**
     * Render badge icons
     * Hook: book.badge.digital_icons
     */
    public function renderBadgeIcons(array $book): void
    {
        // Staff-only files do not advertise themselves on catalogue cards.
        $attachments = $this->visibleAttachments($book);
        include __DIR__ . '/views/badge-icons.php';
    }

    /**
     * Enqueue CSS assets
     * Hook: assets.head
     */
    public function enqueueAssets(): void
    {
        // Digital Library CSS carries the digital badges rendered on catalog and
        // home cards, so it must stay on every frontend page. The Green Audio
        // Player CSS/JS, by contrast, are needed ONLY where an audiobook actually
        // renders — those are emitted on demand by renderAudioPlayer() so the
        // home, catalog and non-audio book pages drop two render-blocking
        // requests (Lighthouse: render-blocking resources).
        $pluginCssPath = __DIR__ . '/assets/css/digital-library.css';
        if (file_exists($pluginCssPath)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars(url('/plugins/digital-library/assets/css/digital-library.css?v=' . (string) filemtime($pluginCssPath)), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
    }

    /**
     * Emit the Green Audio Player CSS/JS once, only where an audiobook renders.
     * Kept out of the global assets.head hook so pages without an audio player
     * do not pay two render-blocking requests. `defer` is honoured on a body
     * script too, so the player-init handler (which runs on DOMContentLoaded and
     * falls back to native controls) always sees GreenAudioPlayer defined.
     */
    private function enqueueAudioPlayerAssets(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;

        $css = htmlspecialchars(url('/assets/vendor/green-audio-player/css/green-audio-player.min.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(url('/assets/vendor/green-audio-player/js/green-audio-player.min.js'), ENT_QUOTES, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $css . '">' . "\n";
        echo '<script src="' . $js . '" defer></script>' . "\n";
    }

    // ========================================================================
    // Upload Handler
    // ========================================================================

    /**
     * Handle AJAX upload request
     */
    public function handleUploadRequest($request, $response, array $args = [])
    {
        // Require admin/staff session
        $user = $_SESSION['user'] ?? null;
        $role = $user['tipo_utente'] ?? '';
        if (!$user || !in_array($role, ['admin', 'staff'], true)) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: No valid admin/staff session');
            return $this->json($response, ['success' => false, 'message' => __('Accesso negato.')], 403);
        }

        // CSRF validation (using standard App\Support\Csrf class)
        // Read from both parsed body and POST superglobal (for multipart/form-data compatibility)
        $params = (array)$request->getParsedBody();
        $postParams = $_POST;
        $csrfToken = $request->getHeaderLine('X-CSRF-Token') ?: ($params['csrf_token'] ?? $postParams['csrf_token'] ?? '');

        \App\Support\SecureLogger::debug('[Digital Library] Upload request', [
            'user' => $user['username'] ?? 'unknown',
            'type' => $params['digital_type'] ?? $postParams['digital_type'] ?? 'unspecified'
        ]);

        if (!\App\Support\Csrf::validate($csrfToken)) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: Invalid CSRF token');
            return $this->json($response, ['success' => false, 'message' => __('Token CSRF non valido.')], 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: No file in request');
            return $this->json($response, ['success' => false, 'message' => __('Nessun file caricato.')], 400);
        }

        $type = $params['digital_type']
            ?? $postParams['digital_type']
            ?? $params['type']
            ?? $postParams['type']
            ?? 'ebook';

        if (!in_array($type, ['audio', 'ebook'], true)) {
            $type = 'ebook';
        }
        $file = $uploadedFiles['file'];
        \App\Support\SecureLogger::debug('[Digital Library] Upload processing', [
            'type' => $type,
            'filename' => $file->getClientFilename()
        ]);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            $errorCode = $file->getError();
            \App\Support\SecureLogger::warning('[Digital Library] Upload failed', [
                'error_code' => $errorCode,
                'filename' => $file->getClientFilename(),
                'php_upload_max' => ini_get('upload_max_filesize') ?: 'unknown',
                'php_post_max' => ini_get('post_max_size') ?: 'unknown'
            ]);

            $message = match ($errorCode) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                    __('Il file supera il limite di upload di PHP (%s). Aumenta upload_max_filesize e post_max_size nella configurazione PHP.'),
                    ini_get('upload_max_filesize') ?: '?'
                ),
                UPLOAD_ERR_PARTIAL => __('Il file è stato caricato solo parzialmente. Riprova.'),
                UPLOAD_ERR_NO_FILE => __('Nessun file caricato.'),
                UPLOAD_ERR_NO_TMP_DIR => __('Cartella temporanea mancante sul server. Contatta l\'amministratore di sistema.'),
                UPLOAD_ERR_CANT_WRITE => __('Impossibile scrivere il file su disco. Controlla i permessi della cartella temporanea.'),
                default => __('Errore durante il caricamento del file.') . ' (code: ' . $errorCode . ')',
            };

            $status = match ($errorCode) {
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 500,
                default => 400,
            };

            return $this->json($response, ['success' => false, 'message' => $message], $status);
        }

        // Validate size / mime
        $maxSize = ($type === 'audio') ? (500 * 1024 * 1024) : (100 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            return $this->json($response, ['success' => false, 'message' => __('File troppo grande.')], 400);
        }

        $allowedMime = ($type === 'audio')
            ? ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/wave']
            : ['application/pdf', 'application/epub+zip', 'application/zip'];

        // Always validate extension regardless of reported MIME type
        $clientFilename = $file->getClientFilename();
        if ($clientFilename === null || $clientFilename === '') {
            return $this->json($response, ['success' => false, 'message' => __('Nome file non valido.')], 400);
        }
        $filename = strtolower($clientFilename);
        $validExt = ($type === 'audio')
            ? ['mp3', 'm4a', 'ogg', 'wav']
            : ['pdf', 'epub'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, $validExt, true)) {
            return $this->json($response, ['success' => false, 'message' => __('Formato file non supportato.')], 400);
        }

        // Check the content BEFORE the file reaches the uploads folder:
        // moved first and checked after, a rejected file was briefly reachable
        // at its URL. The magic bytes are read from the upload's temp stream.
        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $head = $stream->read(65536);
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => __('Impossibile salvare il file.')], 500);
        }
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
        $contentOk = $detectedMime !== false && in_array($detectedMime, $allowedMime, true);
        if ($contentOk && $type !== 'audio') {
            // The extension must match the content: a .pdf is a PDF, and an
            // .epub is an EPUB container (OCF: the first entry is an
            // uncompressed "mimetype" file holding application/epub+zip), not
            // any ZIP renamed.
            $contentOk = $ext === 'pdf'
                ? $detectedMime === 'application/pdf'
                : (strncmp($head, "PK\x03\x04", 4) === 0 && substr($head, 30, 28) === 'mimetypeapplication/epub+zip');
        }
        if (!$contentOk) {
            \App\Support\SecureLogger::warning('[Digital Library] Upload rejected: content does not match', [
                'expected' => $allowedMime,
                'detected' => $detectedMime ?: 'unknown',
                'filename' => $clientFilename,
            ]);
            return $this->json($response, ['success' => false, 'message' => __('Formato file non supportato.')], 400);
        }

        // Outside the web root: the URL below reaches it only through ProtectedUploadController.
        try {
            $uploadsDir = $this->storageDir();
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => __('Impossibile salvare il file.')], 500);
        }

        $safeName = $this->generateSafeFilename($clientFilename, $type);
        $targetPath = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;

        try {
            $file->moveTo($targetPath);
            // Readable by the web server, never executable, whatever the host's umask.
            @chmod($targetPath, 0644);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => __('Impossibile salvare il file.')], 500);
        }

        $publicUrl = '/uploads/digital/' . $safeName;
        return $this->json($response, [
            'success' => true,
            'uploadURL' => $publicUrl,
            'filename' => $safeName,
            'type' => $type
        ]);
    }

    // ========================================================================
    // Asset Serving
    // ========================================================================

    /**
     * Serve plugin assets (CSS/JS) from storage safely
     */
    public function serveAsset($request, $response, array $args = [])
    {
        $type = $args['type'] ?? '';
        $filename = $args['filename'] ?? '';

        if (!in_array($type, ['css', 'js'], true)) {
            return $response->withStatus(404);
        }

        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$filename)) {
            return $response->withStatus(404);
        }

        $baseDir = __DIR__ . '/assets/' . $type;
        $baseRealPath = realpath($baseDir);
        if ($baseRealPath === false) {
            return $response->withStatus(404);
        }

        $filePath = realpath($baseRealPath . DIRECTORY_SEPARATOR . $filename);
        if ($filePath === false || !str_starts_with($filePath, $baseRealPath . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            return $response->withStatus(404);
        }

        $mime = $type === 'css' ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8';
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return $response->withStatus(500);
        }
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=31536000');
    }

    // ========================================================================
    // Private Helpers
    // ========================================================================

    /**
     * Generate safe filename
     */
    private function generateSafeFilename(string $original, string $type): string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = ($type === 'audio') ? 'mp3' : 'pdf';
        }

        $base = bin2hex(random_bytes(8));
        return date('YmdHis') . '_' . $base . '.' . $ext;
    }

    /**
     * Helper to output JSON response
     */
    private function json($response, array $data, int $status = 200)
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            \App\Support\SecureLogger::error('[Digital Library] JSON encode failed', ['message' => $e->getMessage()]);
            $json = json_encode(['success' => false, 'message' => 'Internal error']) ?: '{"success":false,"message":"Internal error"}';
        }
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
