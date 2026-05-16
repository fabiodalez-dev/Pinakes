<?php

declare(strict_types=1);

namespace App\Plugins\Archives;

// Phase 1 of issue #122 (RiC-CM): pure helper class for JSON-LD output.
// Loaded eagerly because the plugin file is included via require_once from
// wrapper.php and there is no PSR-4 autoloader scope for plugin classes.
require_once __DIR__ . '/RicJsonLdBuilder.php';

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Archives plugin — ISAD(G) / ISAAR(CPF) support for Pinakes.
 *
 * Phase 1a (issue #103). Introduces three tables:
 *   - archival_units             : hierarchical archival records (fonds/series/file/item)
 *   - authority_records          : persons, corporate bodies, families (ISAAR(CPF))
 *   - archival_unit_authority    : M:N link between the two with a role enum
 *
 * The design mirrors the ABA (Copenhagen) mapping of ISAD(G) onto an extended
 * danMARC2 — see README.md for the ISAD(G) → column crosswalk.
 *
 * Activation creates the schema via ensureSchema() executed against the host's
 * mysqli connection. Deactivation is a no-op: the tables stay in place because
 * archival records are typically more valuable than a clean uninstall.
 */
class ArchivesPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    /**
     * Logical archival-unit levels, per ISAD(G) 3.1.4.
     * Ordered from most-inclusive (1) to least (4).
     */
    public const LEVELS = [
        'fonds'  => 1, // archive collection — whole of a creator's output
        'series' => 2, // grouping by provenance/function/form
        'file'   => 3, // organic unit (case file, volume)
        'item'   => 4, // smallest indivisible unit (single letter, memo)
    ];

    /**
     * Specific material types, per ABA billedmarc 009*g. Default 'text'
     * so existing archival_units keep their MARC 009*g='bf' bibliographic
     * semantics unchanged on phase 5 migration.
     *
     * The set covers the full ABA billedmarc pictorial (h-prefix), audio-
     * visual (l-prefix) and bibliographic (b-prefix) material-form codes
     * plus MARC21 336/338 content/carrier types used in real archival
     * collections. New values are appended after the original phase-5
     * eight so ENUM ordinal positions 1-8 stay stable — this lets MySQL
     * treat the ENUM extension as a metadata-only ALTER.
     */
    public const SPECIFIC_MATERIALS = [
        'text'       => 'Text / manuscript (bf)',
        'photograph' => 'Photograph (hf)',
        'poster'     => 'Poster (hp)',
        'postcard'   => 'Postcard (hm)',
        'drawing'    => 'Drawing / artwork (hd)',
        'audio'      => 'Audio recording (lm)',
        'video'      => 'Video (vm)',
        'other'      => 'Other',
        // Phase 5+ additions — append-only (new ENUM ordinals).
        'map'        => 'Map / cartographic (hk)',
        'picture'    => 'Picture / print / painting (hb)',
        'object'     => 'Three-dimensional object / realia (ho)',
        'film'       => 'Motion-picture film (lf)',
        'microform'  => 'Microform (bm)',
        'electronic' => 'Electronic resource / born-digital (le)',
        'mixed'      => 'Mixed materials (zz)',
    ];

    /**
     * Color mode, per ABA billedmarc 300*b.
     */
    public const COLOR_MODES = [
        'bw'    => 'Black and white',
        'color' => 'Colour',
        'mixed' => 'Mixed',
    ];

    /**
     * Authority-record types, per ISAAR(CPF).
     */
    public const AUTHORITY_TYPES = [
        'person'    => 'Single person (biographical authority)',
        'corporate' => 'Corporate body (organisation, union, party)',
        'family'    => 'Family (genealogical authority)',
    ];

    /**
     * Roles an authority can play relative to an archival_unit.
     * Mirrors MARC 100/600/700/710 semantics in the ABA format.
     */
    public const AUTHORITY_ROLES = [
        'creator'    => 'Creator (provenienza / 710)',
        'subject'    => 'Subject (soggetto / 610)',
        'recipient'  => 'Recipient (destinatario)',
        'custodian'  => 'Custodian (conservatore)',
        'associated' => 'Associated (correlato)',
    ];

    /**
     * PluginManager::runPluginMethod() instantiates every plugin with
     * ($this->db, $this->hookManager) — see PluginManager.php:878. The plugin
     * must match this signature even if the hooks aren't wired yet.
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Called by PluginManager when the plugin is activated via the admin UI.
     * Creates the archival schema if missing, then registers the plugin's
     * runtime hooks (`app.routes.register` + `admin.menu.render`).
     * Idempotent: the DDLs use CREATE TABLE IF NOT EXISTS and each hook
     * insert is preceded by a targeted DELETE (see registerHookInDb).
     *
     * Throws on partial-schema failure so PluginManager does not mark the
     * plugin active with missing tables and so the hooks are not registered
     * against a broken schema. The exception bubbles up to the admin UI
     * where it surfaces as a red flash; SecureLogger has already captured
     * the per-table reason inside ensureSchema().
     */
    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Archives] Schema activation failed for: ' . implode(', ', $result['failed'])
                . '. See app.log for the mysqli error emitted during each CREATE TABLE.'
            );
        }
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register',              'registerRoutes',          10);
            $this->registerHookInDb('admin.menu.render',              'renderAdminMenuEntry',    10);
            $this->registerHookInDb('search.unified.sources',         'addArchivalSources',      10);
            $this->registerHookInDb('frontend.catalog.archive_results', 'getPublicArchiveResults', 10);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Archives] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }
    }

    /**
     * Called when deactivated. Keeps the tables in place — dropping them
     * would delete archival records, which are probably more valuable than
     * a clean uninstall. Hooks are removed so routes stop responding.
     */
    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    /**
     * Register a hook for this plugin in the `plugin_hooks` table.
     * Pattern borrowed from DeweyEditorPlugin — see storage/plugins/dewey-editor.
     */
    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[Archives] pluginId not set; cannot register hook ' . $hookName);
            return;
        }
        // Clear existing entries for this (plugin, hook, method) to avoid
        // duplicates on re-activation.
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
            $err = $this->db->error;
            SecureLogger::error('[Archives] prepare() failed: ' . $err);
            throw new \RuntimeException('[Archives] prepare() failed for hook ' . $hookName . ': ' . $err);
        }
        // PluginManager instantiates the global proxy class (no namespace).
        $callbackClass = 'ArchivesPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            SecureLogger::error('[Archives] hook insert failed: ' . $err);
            throw new \RuntimeException('[Archives] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    /**
     * Remove every hook registration this plugin owns. Called from onDeactivate()
     * so routes + filters stop being invoked once the plugin goes inactive.
     */
    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) {
            return;
        }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) {
            SecureLogger::error('[Archives] deleteHooksFromDb prepare failed: ' . $this->db->error);
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Execute the DDL for archival_units, authority_records, and the
     * M:N link table. CREATE TABLE failures are logged and reported
     * via the returned 'failed' list without throwing. However, the
     * subsequent migrateImageColumns() and migrateArchivalUnitFilesFK()
     * calls throw RuntimeException on failure, which aborts activation.
     *
     * @return array{created: list<string>, failed: list<string>}
     * @throws \RuntimeException If a schema migration step fails.
     */
    public function ensureSchema(): array
    {
        $steps = [
            'archival_units'             => self::ddlArchivalUnits(),
            'authority_records'          => self::ddlAuthorityRecords(),
            'archival_unit_authority'    => self::ddlArchivalAuthorityLinks(),
            'autori_authority_link'      => self::ddlAutoriAuthorityLink(),
            'archival_unit_files'        => self::ddlArchivalUnitFiles(),
            // RiC-CM Phase 2 (v0.7.8 — issue #122) — agents as first-class entities.
            'archive_agent_identifiers'  => self::ddlAgentIdentifiers(),
            'archive_agent_relations'    => self::ddlAgentRelations(),
            // RiC-CM Phase 3 (v0.7.9 — issue #122) — Activity entities + M:N to units.
            'archive_activities'         => self::ddlArchiveActivities(),
            'archive_unit_activities'    => self::ddlArchiveUnitActivities(),
            // RiC-CM Phase 4 (v0.7.10 — issue #122) — Place + polymorphic relations.
            'archive_places'             => self::ddlArchivePlaces(),
            'archive_relations'          => self::ddlArchiveRelations(),
        ];
        $created = [];
        $failed = [];

        foreach ($steps as $table => $ddl) {
            try {
                $result = $this->db->query($ddl);
                if ($result === false) {
                    $failed[] = $table;
                    SecureLogger::warning(
                        '[Archives] CREATE TABLE failed for ' . $table . ': ' . $this->db->error
                    );
                } else {
                    $created[] = $table;
                }
            } catch (\Throwable $e) {
                $failed[] = $table;
                SecureLogger::error(
                    '[Archives] Exception during CREATE TABLE ' . $table . ': ' . $e->getMessage()
                );
            }
        }

        // Phase 5+ migration: ALTER TABLE to add columns missing from older
        // installs. Must run AFTER CREATE TABLE so the table exists.
        $this->migrateImageColumns();

        // If archival_unit_files was created by migrate_0.7.4 without the FK
        // (because archival_units didn't exist yet), add the FK now.
        $this->migrateArchivalUnitFilesFK();

        // RiC-CM Phase 2 (v0.7.8): backfill ric_type / birth_date /
        // death_date / place_of_origin on installs that activated the
        // plugin pre-0.7.8 (the DDL above only fires CREATE TABLE IF
        // NOT EXISTS, so a pre-existing authority_records table needs
        // separate ALTER passes).
        $this->migrateAuthorityRecordsRicColumns();

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Ensures fk_archival_unit_files_unit exists on archival_unit_files.
     * The v0.7.4 migration creates the table without FK when Archives was
     * not yet activated; this method adds the FK idempotently at activation.
     */
    private function migrateArchivalUnitFilesFK(): void
    {
        $cnt = $this->db->query(
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA    = DATABASE()
                AND TABLE_NAME      = 'archival_unit_files'
                AND CONSTRAINT_NAME = 'fk_archival_unit_files_unit'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        if (!($cnt instanceof \mysqli_result)) {
            throw new \RuntimeException(
                '[Archives] Unable to inspect fk_archival_unit_files_unit: ' . $this->db->error
            );
        }
        $row = $cnt->fetch_assoc();
        $cnt->free();
        if ($row === null) {
            throw new \RuntimeException('[Archives] Unable to read FK inspection result');
        }
        if ((int) ($row['n'] ?? 1) > 0) {
            return;
        }
        if ($this->db->query(
            'ALTER TABLE archival_unit_files
               ADD CONSTRAINT fk_archival_unit_files_unit
               FOREIGN KEY (unit_id) REFERENCES archival_units(id) ON DELETE CASCADE'
        ) === false) {
            throw new \RuntimeException(
                '[Archives] Unable to add fk_archival_unit_files_unit: ' . $this->db->error
            );
        }
    }

    // FIX F028: docblock rewritten to match current behavior — migrates
    // descriptive, image-item, IIIF, rights, ARK and version columns, and
    // ensures the uq_ark_identifier UNIQUE KEY exists; throws RuntimeException
    // on ALTER failure so callers can abort activation.
    /**
     * Additive migration for archival_units. Checks information_schema for
     * each column listed below and runs ALTER TABLE ADD COLUMN when missing;
     * also ensures the UNIQUE KEY uq_ark_identifier exists on in-place
     * upgrades (fresh installs get it from ddlArchivalUnits()).
     *
     * Columns migrated include the original image-item set plus
     * iiif_manifest_url, rights_statement_url, ark_identifier and
     * version_note added in later phases.
     *
     * MySQL 5.7 / MariaDB 10.x don't support ADD COLUMN IF NOT EXISTS
     * consistently, which is why we pre-check instead.
     *
     * Throws \RuntimeException if any ALTER fails — callers should let it
     * propagate so activation aborts rather than leaving a half-migrated
     * schema behind.
     */
    private function migrateImageColumns(): void
    {
        $columns = [
            'specific_material'    => "ENUM('text','photograph','poster','postcard','drawing','audio','video','other','map','picture','object','film','microform','electronic','mixed') NOT NULL DEFAULT 'text'",
            'dimensions'           => 'VARCHAR(100) NULL',
            'color_mode'           => "ENUM('bw','color','mixed') NULL",
            'photographer'         => 'VARCHAR(255) NULL',
            'publisher'            => 'VARCHAR(255) NULL',
            'collection_name'      => 'VARCHAR(255) NULL',
            'local_classification' => 'VARCHAR(64) NULL',
            'cover_image_path'     => 'VARCHAR(500) NULL',
            'document_path'        => 'VARCHAR(500) NULL',
            'document_mime'        => 'VARCHAR(100) NULL',
            'document_filename'    => 'VARCHAR(255) NULL',
            'iiif_manifest_url'    => 'VARCHAR(2000) NULL',
            'rights_statement_url' => 'VARCHAR(500) NULL',
            'ark_identifier'       => 'VARCHAR(255) NULL',
            'version_note'         => 'VARCHAR(500) NULL',
        ];

        // Fetch existing columns once.
        $existing = [];
        $result = $this->db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
        );
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $existing[(string) $row['COLUMN_NAME']] = true;
            }
            $result->free();
        } else {
            // archival_units itself might not exist yet (fresh install path);
            // ensureSchema's first step will create it with all columns.
            return;
        }

        $failures = [];
        foreach ($columns as $col => $definition) {
            if (isset($existing[$col])) {
                continue;
            }
            // Column names are hard-coded above; definition comes from our
            // own source. Not user-supplied → safe to interpolate.
            $sql = 'ALTER TABLE archival_units ADD COLUMN ' . $col . ' ' . $definition;
            try {
                if ($this->db->query($sql) === false) {
                    $failures[] = $col . ': ' . $this->db->error;
                }
            } catch (\Throwable $e) {
                $failures[] = $col . ': ' . $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                '[Archives] migrateImageColumns failed: ' . implode('; ', $failures)
            );
        }

        // Ensure the UNIQUE KEY on ark_identifier exists (ddlArchivalUnits creates it
        // on fresh installs; in-place upgrades need this idempotent guard).
        $idxResult = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'archival_units'
                AND INDEX_NAME = 'uq_ark_identifier'"
        );
        if ($idxResult instanceof \mysqli_result) {
            $idxRow = $idxResult->fetch_assoc();
            $idxResult->free();
            if ((int) ($idxRow['cnt'] ?? 0) === 0) {
                try {
                    $result = $this->db->query(
                        'ALTER TABLE archival_units ADD UNIQUE KEY uq_ark_identifier (ark_identifier)'
                    );
                    if ($result === false) {
                        $failures[] = 'uq_ark_identifier: ' . $this->db->error;
                    }
                } catch (\Throwable $e) {
                    $failures[] = 'uq_ark_identifier: ' . $e->getMessage();
                }
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                '[Archives] migrateImageColumns failed: ' . implode('; ', $failures)
            );
        }
    }

    /**
     * RiC-CM Phase 2 (v0.7.8): add ric_type / birth_date / death_date /
     * place_of_origin columns to authority_records on in-place upgrades.
     *
     * ddlAuthorityRecords() uses CREATE TABLE IF NOT EXISTS, so an
     * installation that activated the plugin pre-0.7.8 already has the
     * table without these columns; this method walks the existing
     * schema and ALTERs each missing column individually. Idempotent:
     * pre-checking via INFORMATION_SCHEMA skips columns already present
     * so the migration can re-run after a partial failure.
     *
     * @throws \RuntimeException If any ALTER fails — callers should let
     *                           it propagate so activation aborts.
     */
    private function migrateAuthorityRecordsRicColumns(): void
    {
        // Each entry: column name → MySQL DDL fragment (without the
        // ALTER TABLE … ADD COLUMN prefix). The order matters: the
        // backfill UPDATE on `type` runs only after `ric_type` exists.
        $columns = [
            'ric_type'        => "ENUM('Person','CorporateBody','Family','Position','Group') NOT NULL DEFAULT 'Person' AFTER type",
            'birth_date'      => 'VARCHAR(20) NULL AFTER dates_of_existence',
            'death_date'      => 'VARCHAR(20) NULL AFTER birth_date',
            'place_of_origin' => 'VARCHAR(255) NULL AFTER death_date',
        ];

        // Snapshot existing columns so we issue at most one ALTER per
        // missing one. Bail silently if the parent table doesn't exist
        // yet — that's the fresh-install path where the CREATE TABLE
        // step above already produced the full Phase-2 shape.
        $existing = [];
        $result = $this->db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authority_records'"
        );
        if (!($result instanceof \mysqli_result)) {
            return;
        }
        while ($row = $result->fetch_assoc()) {
            $existing[(string) $row['COLUMN_NAME']] = true;
        }
        $result->free();

        $failures = [];
        foreach ($columns as $col => $definition) {
            if (isset($existing[$col])) {
                continue;
            }
            $sql = 'ALTER TABLE authority_records ADD COLUMN ' . $col . ' ' . $definition;
            try {
                if ($this->db->query($sql) === false) {
                    $failures[] = $col . ': ' . $this->db->error;
                }
            } catch (\Throwable $e) {
                $failures[] = $col . ': ' . $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                '[Archives] migrateAuthorityRecordsRicColumns failed: ' . implode('; ', $failures)
            );
        }

        // Backfill ric_type from the ISAAR `type` column. Only touches
        // rows still on the default 'Person' so a curator override
        // (Position / Group) is preserved across re-runs.
        try {
            $this->db->query(
                "UPDATE authority_records
                    SET ric_type = CASE type
                        WHEN 'person'    THEN 'Person'
                        WHEN 'corporate' THEN 'CorporateBody'
                        WHEN 'family'    THEN 'Family'
                        ELSE 'Person'
                    END
                  WHERE ric_type = 'Person'
                    AND type IN ('corporate', 'family')"
            );
        } catch (\Throwable $e) {
            SecureLogger::warning(
                '[Archives] migrateAuthorityRecordsRicColumns backfill failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Expose the injected HookManager. Kept as a public accessor rather than
     * a private unused property so static analysis is happy and tests can
     * verify the DI wiring without reflection.
     */
    public function getHookManager(): HookManager
    {
        return $this->hookManager;
    }

    // ── Phase 1b — route registration + CRUD handlers ─────────────────

    /**
     * Hook callback for `app.routes.register`. Attaches /admin/archives routes
     * to the Slim app. Kept in the plugin itself (not in app/Controllers) so
     * deactivating the plugin cleanly removes the routes.
     *
     * @param \Slim\App<\Psr\Container\ContainerInterface|null> $app
     */
    public function registerRoutes($app): void
    {
        $plugin = $this;
        $adminMiddleware = new \App\Middleware\AdminAuthMiddleware();
        $csrfMiddleware  = new \App\Middleware\CsrfMiddleware();

        // GET /admin/archives — list
        $app->get('/admin/archives', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->indexAction($request, $response);
        })->add($adminMiddleware);

        // GET /admin/archives/new — blank create form
        $app->get('/admin/archives/new', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->newAction($request, $response);
        })->add($adminMiddleware);

        // POST /admin/archives/new — validate + INSERT + redirect
        $app->post('/admin/archives/new', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->storeAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // GET /admin/archives/{id:\d+} — read-only detail
        $app->get('/admin/archives/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->showAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        // GET /admin/archives/{id}/edit — edit form pre-populated
        $app->get('/admin/archives/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->editAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        // POST /admin/archives/{id}/edit — validate + UPDATE
        $app->post('/admin/archives/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->updateAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/archives/{id}/delete — soft-delete
        $app->post('/admin/archives/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->destroyAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/archives/{id}/upload-cover — cover image (jpg/png/webp)
        $app->post('/admin/archives/{id:[0-9]+}/upload-cover', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->uploadCoverAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/archives/{id}/upload-document — PDF, ePub, audio/video
        $app->post('/admin/archives/{id:[0-9]+}/upload-document', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->uploadDocumentAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/archives/{id}/remove-asset — drops cover or document
        $app->post('/admin/archives/{id:[0-9]+}/remove-asset', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->removeAssetAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/archives/{id}/files/{fileId}/delete — remove one file from archival_unit_files
        $app->post('/admin/archives/{id:[0-9]+}/files/{fileId:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->removeFileAction($request, $response, (int) $args['id'], (int) $args['fileId']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Phase 2 — authority_records CRUD ────────────────────────────────
        // Deliberately nested under /admin/archives/ so the whole archival
        // area is behind a single mental prefix and the plugin owns its
        // URL-space cleanly.

        $app->get('/admin/archives/authorities', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityIndexAction($request, $response);
        })->add($adminMiddleware);

        $app->get('/admin/archives/authorities/new', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityNewAction($request, $response);
        })->add($adminMiddleware);

        $app->post('/admin/archives/authorities/new', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityStoreAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->get('/admin/archives/authorities/{id:[0-9]+}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityShowAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->get('/admin/archives/authorities/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityEditAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->post('/admin/archives/authorities/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityUpdateAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/admin/archives/authorities/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityDestroyAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Phase 2 — link authority ↔ archival_unit ───────────────────────
        // Nested under the archival_unit so the URL captures the subject.

        $app->post('/admin/archives/{id:[0-9]+}/authorities/attach', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->attachAuthorityAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post(
            '/admin/archives/{id:[0-9]+}/authorities/{authority_id:[0-9]+}/detach',
            function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($plugin): ResponseInterface {
                return $plugin->detachAuthorityAction(
                    $request,
                    $response,
                    (int) $args['id'],
                    (int) $args['authority_id']
                );
            }
        )->add($csrfMiddleware)->add($adminMiddleware);

        // ── Phase 2b — link authority ↔ libri.autori ───────────────────────
        // Reconciles a bibliographic `autori` row with an ISAAR authority so
        // books + archives of the same entity surface together in search.

        $app->post('/admin/archives/authorities/{id:[0-9]+}/autori/link', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->linkAutoreAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post(
            '/admin/archives/authorities/{id:[0-9]+}/autori/{autori_id:[0-9]+}/unlink',
            function (
                ServerRequestInterface $request,
                ResponseInterface $response,
                array $args
            ) use ($plugin): ResponseInterface {
                return $plugin->unlinkAutoreAction(
                    $request,
                    $response,
                    (int) $args['id'],
                    (int) $args['autori_id']
                );
            }
        )->add($csrfMiddleware)->add($adminMiddleware);

        // ── Phase 3 — unified cross-entity search ──────────────────────────
        // Single-page search that returns archival_units + authority_records
        // (+ reconciled autori) in one view. Uses FULLTEXT indexes on the
        // archival tables we created in phase 1a; the autori side joins
        // via the phase-2b link table so books + archives of the same
        // authority cluster together.

        $app->get('/admin/archives/search', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->unifiedSearchAction($request, $response);
        })->add($adminMiddleware);

        // Type-ahead JSON endpoint for the authority attach <select>.
        $app->get('/admin/archives/api/authorities/search', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->authorityTypeaheadAction($request, $response);
        })->add($adminMiddleware);

        // ── Phase 4 — MARCXML import / export ──────────────────────────────
        // Uses the ABA archive-format crosswalk documented in README.md so
        // the output round-trips with existing archival systems (Reindex,
        // ARKIS II) without any bespoke translation layer.

        $app->get('/admin/archives/export.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->exportCollectionAction($request, $response);
        })->add($adminMiddleware);

        $app->get('/admin/archives/{id:[0-9]+}/export.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportArchivalUnitAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->get('/admin/archives/authorities/{id:[0-9]+}/export.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportAuthorityAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        // ── Interoperability — Dublin Core, EAD3, OAI-PMH ─────────────────────

        // Dublin Core XML for one archival_unit. The admin route is useful for
        // staff testing; the public route powers machine discovery links.
        $app->get('/admin/archives/{id:[0-9]+}/dc.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportDublinCoreAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);
        $app->get('/archives/{id:[0-9]+}/dc.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportDublinCoreAction($request, $response, (int) $args['id']);
        });

        // EAD3 per-unit export (public + admin).
        $app->get('/admin/archives/{id:[0-9]+}/ead.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportEad3Action($request, $response, (int) $args['id']);
        })->add($adminMiddleware);
        $app->get('/archives/{id:[0-9]+}/ead.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportEad3Action($request, $response, (int) $args['id']);
        });

        // METS per-unit export (public + admin).
        $app->get('/admin/archives/{id:[0-9]+}/mets.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportMetsAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);
        $app->get('/archives/{id:[0-9]+}/mets.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportMetsAction($request, $response, (int) $args['id']);
        });

        // EAD3 bulk export (same admin-only access as MARCXML export).
        $app->get('/admin/archives/export.ead3', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->exportEad3CollectionAction($request, $response);
        })->add($adminMiddleware);

        // OAI-PMH 2.0 endpoint — intentionally PUBLIC (no admin auth).
        // Aggregators (Europeana, DPLA, national portals) harvest it without
        // authentication. Both GET and POST are required by the spec.
        $app->get('/archives/oai', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->oaiPmhAction($request, $response);
        });
        $app->post('/archives/oai', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->oaiPmhAction($request, $response);
        });

        // ── IIIF Presentation 3.0 manifest ──────────────────────────────────
        // Admin route (auth via AdminAuthMiddleware applied to /admin/*).
        // Public route: consumed by Mirador, Universal Viewer, aggregators.
        $app->get('/admin/archives/{id:[0-9]+}/manifest.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->iiifManifestAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->get('/archives/{id:[0-9]+}/manifest.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->iiifManifestAction($request, $response, (int) $args['id']);
        });

        // IIIF Collection — root (all fondi) + per-unit sub-collections
        $app->get('/archives/collection.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->iiifCollectionAction($request, $response, null);
        });

        $app->get('/archives/{id:[0-9]+}/collection.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->iiifCollectionAction($request, $response, (int) $args['id']);
        });

        // ── RiC-O (Records in Contexts Ontology) JSON-LD ────────────────────
        // Phase 1 of issue #122 — three public, read-only endpoints that
        // expose the existing ISAD(G) tree as RiC-CM entities for harvesting
        // by Europeana, ArchivesPortalEurope and the ICA aggregator.
        // No DB changes: the data is the same as MARCXML/EAD3, only the
        // serialisation vocabulary differs.

        $app->get('/archives/collection.ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->ricCollectionAction($request, $response);
        });

        $app->get('/archives/{id:[0-9]+}/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->ricUnitAction($request, $response, (int) $args['id']);
        });

        $app->get('/archives/agents/{id:[0-9]+}/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->ricAgentAction($request, $response, (int) $args['id']);
        });

        // RiC-CM Phase 3 (v0.7.9 — issue #122) — Activity entities.
        // Public, no auth: harvested by Europeana / ICA aggregators
        // alongside the unit + agent endpoints.

        $app->get('/archives/activities/{id:[0-9]+}/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->ricActivityAction($request, $response, (int) $args['id']);
        });

        $app->get('/archives/activities/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->ricActivitiesListAction($request, $response);
        });

        // RiC-CM Phase 4 (v0.7.10 — issue #122) — Place entities +
        // polymorphic relations graph.

        $app->get('/archives/places/{id:[0-9]+}/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->ricPlaceAction($request, $response, (int) $args['id']);
        });

        $app->get('/archives/places/ric.json', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->ricPlacesListAction($request, $response);
        });

        // ── RiC-CM Phase 5 (target v0.8.0 — issue #122) — Admin UI ───
        // CRUD for archive_activities, archive_places, and the
        // polymorphic archive_relations + the cross-entity autocomplete
        // helper. All routes behind AdminAuthMiddleware; writes also
        // protected by CsrfMiddleware.

        // Activities CRUD.
        $app->get('/admin/archives/activities', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->activityIndexAction($request, $response);
        })->add($adminMiddleware);

        $app->get('/admin/archives/activities/new', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->activityNewAction($request, $response);
        })->add($adminMiddleware);

        $app->post('/admin/archives/activities/new', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->activityStoreAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->get('/admin/archives/activities/{id:[0-9]+}', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->activityShowAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->get('/admin/archives/activities/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->activityEditAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->post('/admin/archives/activities/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->activityUpdateAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/admin/archives/activities/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->activityDestroyAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // Places CRUD.
        $app->get('/admin/archives/places', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->placeIndexAction($request, $response);
        })->add($adminMiddleware);

        $app->get('/admin/archives/places/new', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->placeNewAction($request, $response);
        })->add($adminMiddleware);

        $app->post('/admin/archives/places/new', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->placeStoreAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->get('/admin/archives/places/{id:[0-9]+}', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->placeShowAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->get('/admin/archives/places/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->placeEditAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        $app->post('/admin/archives/places/{id:[0-9]+}/edit', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->placeUpdateAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/admin/archives/places/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->placeDestroyAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // Polymorphic relations attach/detach.
        $app->post('/admin/archives/relations/attach', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->relationAttachAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        $app->post('/admin/archives/relations/{id:[0-9]+}/detach', function (
            ServerRequestInterface $request, ResponseInterface $response, array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->relationDetachAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // Cross-entity autocomplete (used by the predicate-picker JS).
        $app->get('/api/archives/entities', function (
            ServerRequestInterface $request, ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->entitiesAutocompleteAction($request, $response);
        })->add($adminMiddleware);

        // Import form (GET) + submit (POST multipart/form-data)
        $app->get('/admin/archives/import', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->importFormAction($request, $response);
        })->add($adminMiddleware);

        $app->post('/admin/archives/import', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->importSubmitAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // ── Phase 6 — SRU endpoint for archival_units + authority_records ──
        // Public (no AdminAuthMiddleware) because SRU is a read-only
        // interoperability protocol consumed by external catalogues
        // (Reindex, Koha, ARKIS). Rate-limiting lives inside the handler.
        // This endpoint ONLY exists while the plugin is active: deactivate
        // → the hook row vanishes from plugin_hooks → registerRoutes never
        // runs → the Slim app returns 404. Zero regression on the rest of
        // the app because no core file is touched.
        $app->get('/api/archives/sru', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->sruAction($request, $response);
        });

        // ── Public frontend — read-only browsable view of archival_units ──
        // Lets the public catalogue expose the archive alongside the book
        // catalogue. No auth — archives are public cultural material. The
        // route is localised via RouteTranslator so /archivio (it) /archive
        // (en) /archiv (de) all resolve to the same action.
        $publicRouteFor = static function (string $locale) {
            return \App\Support\RouteTranslator::getRouteForLocale('archives', $locale);
        };
        $publicIndex = function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->publicIndexAction($request, $response);
        };
        $publicShow = function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->publicShowAction($request, $response, (int) $args['id'], (string) ($args['slug'] ?? ''));
        };
        // English fallback routes + localised variants. Follows the same
        // pattern used for /libri, /autori etc. in app/Routes/web.php.
        // Two URL shapes for the detail page:
        //   - /archivio/{slug}-{id}      SEO-friendly, canonical
        //   - /archivio/{id}             legacy/short form, 301-redirects to the slug form
        $app->get('/archive', $publicIndex);
        $app->get('/archive/{slug:[a-z0-9-]+}-{id:[0-9]+}', $publicShow);
        $app->get('/archive/{id:[0-9]+}', $publicShow);
        // FIX F029: include fr_FR so the localised /archives route registers for French installs.
        foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR'] as $locale) {
            $base = $publicRouteFor($locale);
            if (!empty($base) && $base !== '/archive') {
                $app->get($base, $publicIndex);
                $app->get($base . '/{slug:[a-z0-9-]+}-{id:[0-9]+}', $publicShow);
                $app->get($base . '/{id:[0-9]+}', $publicShow);
            }
        }

        // Serve plugin-local CSS/JS so the inline-style blocks can be
        // replaced by a real stylesheet. Same pattern as the
        // digital-library plugin. `/plugins/archives/assets/css/<file>.css`.
        $app->get('/plugins/archives/assets/{type}/{filename}', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->serveAsset($request, $response, $args);
        });
    }

    /**
     * Serve plugin-local static assets (CSS / JS) with a realpath check
     * to reject path traversal. Mirrors DigitalLibraryPlugin::serveAsset.
     */
    public function serveAsset(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $type = (string) ($args['type'] ?? '');
        $filename = (string) ($args['filename'] ?? '');
        if (!in_array($type, ['css', 'js'], true)) {
            return $response->withStatus(404);
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return $response->withStatus(404);
        }
        $baseDir = realpath(__DIR__ . '/assets/' . $type);
        if ($baseDir === false) {
            return $response->withStatus(404);
        }
        $filePath = realpath($baseDir . DIRECTORY_SEPARATOR . $filename);
        if ($filePath === false
            || !str_starts_with($filePath, $baseDir . DIRECTORY_SEPARATOR)
            || !is_file($filePath)) {
            return $response->withStatus(404);
        }
        $mime = $type === 'css'
            ? 'text/css; charset=UTF-8'
            : 'application/javascript; charset=UTF-8';
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return $response->withStatus(500);
        }
        $response->getBody()->write($contents);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=31536000');
    }

    /**
     * Hook callback for `admin.menu.render`. Echoes a sidebar nav entry
     * matching the Tailwind pattern used by the core menu items in
     * `app/Views/layout.php`. Action-style hook — output goes to the
     * response buffer directly, no return value needed.
     */
    public function renderAdminMenuEntry(): void
    {
        // Guard the base path via url() just like every other sidebar item.
        $href = htmlspecialchars(url('/admin/archives'), ENT_QUOTES, 'UTF-8');
        $title = function_exists('__') ? __('Archivi') : 'Archivi';
        $subtitle = function_exists('__') ? __('Materiale archivistico (ISAD(G))') : 'Materiale archivistico (ISAD(G))';
        echo <<<HTML

          <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900"
            href="$href">
            <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
              <i class="fas fa-archive text-gray-600"></i>
            </div>
            <div class="ml-3">
              <div class="font-medium">$title</div>
              <div class="text-xs text-gray-500">$subtitle</div>
            </div>
          </a>

        HTML;
    }

    /**
     * GET /admin/archives — render the list of archival_units as a tree-flavoured
     * table (parent rows before children, visual indent by level).
     */
    public function indexAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params   = $request->getQueryParams();
        $rawQuery = $params['q'] ?? '';
        $rawLevel = $params['level'] ?? '';
        $q        = is_string($rawQuery) ? trim($rawQuery) : '';
        $level    = is_string($rawLevel) && isset(self::LEVELS[$rawLevel]) ? $rawLevel : '';

        $rows = [];
        if ($q !== '' || $level !== '') {
            $whereParts = ['deleted_at IS NULL'];
            $bindTypes  = '';
            $bindValues = [];

            if ($q !== '') {
                $pattern = $this->archiveSearchPattern($q);
                $whereParts[] = '(reference_code LIKE ? OR constructed_title LIKE ? OR formal_title LIKE ? OR scope_content LIKE ?)';
                $bindTypes  .= 'ssss';
                $bindValues = array_merge($bindValues, [$pattern, $pattern, $pattern, $pattern]);
            }
            if ($level !== '') {
                $whereParts[] = 'level = ?';
                $bindTypes  .= 's';
                $bindValues[] = $level;
            }

            $sql  = "SELECT id, parent_id, reference_code, level, constructed_title, formal_title,
                            date_start, date_end, extent, language_codes, created_at
                       FROM archival_units
                      WHERE " . implode(' AND ', $whereParts) . "
                      ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC
                      LIMIT 500";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param($bindTypes, ...$bindValues);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
                    $result->free();
                }
                $stmt->close();
            }
        } else {
            // Order by level first so fonds appear before their series/files/items,
            // then by reference_code for stable ordering inside a level.
            $result = $this->db->query(
                "SELECT id, parent_id, reference_code, level, constructed_title, formal_title,
                        date_start, date_end, extent, language_codes, created_at
                   FROM archival_units
                  WHERE deleted_at IS NULL
                  ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC
                  LIMIT 500"
            );
            if ($result instanceof \mysqli_result) {
                while ($row = $result->fetch_assoc()) { $rows[] = $row; }
                $result->free();
            } else {
                SecureLogger::warning('[Archives] index query failed: ' . $this->db->error);
            }
        }

        return $this->renderView($response, 'index', [
            'rows'  => $rows,
            'q'     => $q,
            'level' => $level,
        ]);
    }

    /**
     * GET /admin/archives/new — blank create form. Supplies the LEVELS constant
     * so the view can render the level dropdown without hardcoding.
     */
    public function newAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderArchivalForm($response, 'create', null, [], []);
    }

    /**
     * POST /admin/archives/new — validate + INSERT new archival_unit.
     *
     * Validation rules (minimum viable for phase 1b):
     *   - reference_code    required, <=64 chars, unique within institution
     *   - level             required, must be one of LEVELS
     *   - constructed_title required, <=500 chars
     *   - date_start / date_end optional, SMALLINT range (-32768..32767)
     *   - parent_id         optional, if set must point to an existing non-deleted row
     *
     * On failure re-renders the form with errors + previously-entered values.
     * On success redirects to /admin/archives (303 See Other).
     */
    public function storeAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $values = $this->extractArchivalUnitPayload($request);
        $errors = $this->validateArchivalUnit($values);

        if (!empty($errors)) {
            return $this->renderArchivalForm($response, 'create', null, $values, $errors);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO archival_units
                (parent_id, reference_code, institution_code, level,
                 formal_title, constructed_title, date_start, date_end,
                 extent, scope_content, language_codes,
                 specific_material, dimensions, color_mode,
                 photographer, publisher, collection_name, local_classification,
                 iiif_manifest_url, rights_statement_url,
                 ark_identifier, version_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] store prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderArchivalForm($response, 'create', null, $values, $errors);
        }

        $p = $this->nullableArchivalParams($values);
        // 22 params: parent_id(i) ref(s) inst(s) level(s) formal(s) constructed(s)
        //            date_start(i) date_end(i) extent(s) scope(s) lang(s)
        //            spec_material(s) dim(s) color(s) photographer(s)
        //            publisher(s) collection(s) local_class(s) iiif_manifest(s) rights(s)
        //            ark(s) version_note(s)
        //            = 'isssssiissssssssssssss' (22 chars)
        $stmt->bind_param(
            'isssssiissssssssssssss',
            $values['parent_id'],
            $values['reference_code'],
            $values['institution_code'],
            $values['level'],
            $p['formal_title'],
            $values['constructed_title'],
            $values['date_start'],
            $values['date_end'],
            $p['extent'],
            $p['scope_content'],
            $values['language_codes'],
            $values['specific_material'],
            $p['dimensions'],
            $p['color_mode'],
            $p['photographer'],
            $p['publisher'],
            $p['collection_name'],
            $p['local_classification'],
            $p['iiif_manifest_url'],
            $p['rights_statement_url'],
            $p['ark_identifier'],
            $p['version_note']
        );

        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] INSERT failed: ' . $stmt->error);
            $stmt->close();
            $errors['_global'] = 'Insert failed. ' . ($this->db->errno === 1062 ? 'Reference code already exists.' : 'Check the log.');
            return $this->renderArchivalForm($response, 'create', null, $values, $errors);
        }
        $stmt->close();

        return $response
            ->withHeader('Location', '/admin/archives')
            ->withStatus(303);
    }

    /**
     * Extract the archival_unit form payload into a normalised array.
     * Handles both phase 1 core fields and phase 5 image fields in one shot.
     *
     * @return array<string, mixed>
     */
    private function extractArchivalUnitPayload(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $str = static fn(string $k, string $default = ''): string => trim((string) ($body[$k] ?? $default));
        $int = static fn(string $k): ?int => isset($body[$k]) && $body[$k] !== '' ? (int) $body[$k] : null;

        $specific = $str('specific_material', 'text');
        if (!array_key_exists($specific, self::SPECIFIC_MATERIALS)) {
            $specific = 'text';
        }
        $color = $str('color_mode');
        if ($color !== '' && !array_key_exists($color, self::COLOR_MODES)) {
            $color = '';
        }

        return [
            'reference_code'       => $str('reference_code'),
            'institution_code'     => $str('institution_code', 'PINAKES'),
            'level'                => $str('level'),
            'formal_title'         => $str('formal_title'),
            'constructed_title'    => $str('constructed_title'),
            'date_start'           => $int('date_start'),
            'date_end'             => $int('date_end'),
            'extent'               => $str('extent'),
            'scope_content'        => $str('scope_content'),
            'language_codes'       => $str('language_codes', 'ita'),
            'parent_id'            => $int('parent_id'),
            // Phase 5 image fields
            'specific_material'    => $specific,
            'dimensions'           => $str('dimensions'),
            'color_mode'           => $color,
            'photographer'         => $str('photographer'),
            'publisher'            => $str('publisher'),
            'collection_name'      => $str('collection_name'),
            'local_classification' => $str('local_classification'),
            'iiif_manifest_url'    => $str('iiif_manifest_url'),
            'rights_statement_url' => $str('rights_statement_url'),
            'ark_identifier'       => $str('ark_identifier'),
            'version_note'         => $str('version_note'),
        ];
    }

    /**
     * Convert the nullable text fields from '' to null so mysqli doesn't
     * persist empty strings (keeps COALESCE working downstream).
     *
     * @param array<string, mixed> $values
     * @return array<string, string|null>
     */
    private function nullableArchivalParams(array $values): array
    {
        $nullable = [
            'formal_title', 'extent', 'scope_content',
            'dimensions', 'color_mode', 'photographer',
            'publisher', 'collection_name', 'local_classification',
            'iiif_manifest_url', 'rights_statement_url',
            'ark_identifier', 'version_note',
        ];
        $out = [];
        foreach ($nullable as $k) {
            $v = (string) ($values[$k] ?? '');
            $out[$k] = $v === '' ? null : $v;
        }
        return $out;
    }

    /**
     * Render the archival-unit create/edit form. Thin wrapper around
     * renderView() that always supplies the select-options + specific
     * material / color enum constants.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderArchivalForm(
        ResponseInterface $response,
        string $mode,
        ?int $id,
        array $values,
        array $errors
    ): ResponseInterface {
        return $this->renderView($response, 'form', [
            'mode'                => $mode,
            'id'                  => $id,
            'levels'              => array_keys(self::LEVELS),
            'specific_materials'  => array_keys(self::SPECIFIC_MATERIALS),
            'color_modes'         => array_keys(self::COLOR_MODES),
            'values'              => $values,
            'errors'              => $errors,
        ]);
    }

    /**
     * GET /admin/archives/{id} — read-only detail view.
     */
    public function showAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        // Resolve parent title (if any) so the detail view can show the hierarchy.
        $parentTitle = null;
        if ($row['parent_id'] !== null) {
            $parent = $this->findById((int) $row['parent_id']);
            $parentTitle = $parent !== null ? (string) $parent['constructed_title'] : null;
        }
        return $this->renderView($response, 'show', [
            'row'                  => $row,
            'parent_title'         => $parentTitle,
            'unit_files'           => $this->fetchUnitFiles($id),
            'linked_authorities'   => $this->fetchAuthoritiesForArchivalUnit($id),
            'available_authorities'=> $this->listAllAuthorities(),
            'authority_roles'      => array_keys(self::AUTHORITY_ROLES),
            'headLinks'            => [
                ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'Dublin Core (OAI-DC)',
                 'href' => absoluteUrl('/archives/' . $id . '/dc.xml')],
                ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'EAD3 Finding Aid',
                 'href' => absoluteUrl('/archives/' . $id . '/ead.xml')],
                ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'METS Package',
                 'href' => absoluteUrl('/archives/' . $id . '/mets.xml')],
                ['rel' => 'alternate', 'type' => 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"',
                 'title' => 'IIIF Manifest',
                 'href' => absoluteUrl('/archives/' . $id . '/manifest.json')],
                ['rel' => 'alternate', 'type' => 'application/ld+json',
                 'title' => 'RiC-O (Records in Contexts)',
                 'href' => absoluteUrl('/archives/' . $id . '/ric.json')],
            ],
        ]);
    }

    /**
     * GET /admin/archives/{id}/edit — pre-populated edit form.
     */
    public function editAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        return $this->renderArchivalForm($response, 'edit', $id, $row, []);
    }

    /**
     * POST /admin/archives/{id}/edit — validate + UPDATE.
     */
    public function updateAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $existing = $this->findById($id);
        if ($existing === null) {
            return $this->renderNotFound($response, $id);
        }

        $values = $this->extractArchivalUnitPayload($request);
        $errors = $this->validateArchivalUnit($values, $id);

        // Extra safeguard: prevent cycles in the hierarchy. Besides the
        // direct self-parent case, an editor could still pick a *descendant*
        // as parent — e.g. A→B→C then assign A.parent_id = C, producing a
        // A→B→C→A cycle. Walk up from the proposed parent; if we meet $id
        // we would be creating a loop. The tree is shallow in practice so a
        // plain PHP loop is fine (capped at 100 hops to harden against
        // pathological data).
        if ($values['parent_id'] === $id) {
            $errors['parent_id'] = 'An archival unit cannot be its own parent.';
        } elseif ($values['parent_id'] !== null && $this->parentWouldCreateCycle($id, (int) $values['parent_id'])) {
            $errors['parent_id'] = 'An archival unit cannot be moved under one of its own descendants.';
        }

        if (!empty($errors)) {
            return $this->renderArchivalForm($response, 'edit', $id, $values, $errors);
        }

        $stmt = $this->db->prepare(
            'UPDATE archival_units SET
                parent_id = ?, reference_code = ?, institution_code = ?, level = ?,
                formal_title = ?, constructed_title = ?, date_start = ?, date_end = ?,
                extent = ?, scope_content = ?, language_codes = ?,
                specific_material = ?, dimensions = ?, color_mode = ?,
                photographer = ?, publisher = ?, collection_name = ?, local_classification = ?,
                iiif_manifest_url = ?, rights_statement_url = ?,
                ark_identifier = ?, version_note = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] update prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderArchivalForm($response, 'edit', $id, $values, $errors);
        }

        $p = $this->nullableArchivalParams($values);
        // 23 params: 22 SET-columns + id(i). Type string:
        //   parent_id(i) ref(s) inst(s) level(s) formal(s) constructed(s)
        //   date_start(i) date_end(i) extent(s) scope(s) lang(s)
        //   spec_material(s) dim(s) color(s) photographer(s)
        //   publisher(s) collection(s) local_class(s) iiif_manifest(s) rights(s)
        //   ark(s) version_note(s) id(i)
        //   = 'isssssiissssssssssssssi' (23 chars)
        $stmt->bind_param(
            'isssssiissssssssssssssi',
            $values['parent_id'],
            $values['reference_code'],
            $values['institution_code'],
            $values['level'],
            $p['formal_title'],
            $values['constructed_title'],
            $values['date_start'],
            $values['date_end'],
            $p['extent'],
            $p['scope_content'],
            $values['language_codes'],
            $values['specific_material'],
            $p['dimensions'],
            $p['color_mode'],
            $p['photographer'],
            $p['publisher'],
            $p['collection_name'],
            $p['local_classification'],
            $p['iiif_manifest_url'],
            $p['rights_statement_url'],
            $p['ark_identifier'],
            $p['version_note'],
            $id
        );

        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] UPDATE failed: ' . $stmt->error);
            $errors['_global'] = $this->db->errno === 1062
                ? 'Reference code already exists for this institution.'
                : 'Update failed. Check the log.';
            $stmt->close();
            return $this->renderArchivalForm($response, 'edit', $id, $values, $errors);
        }
        $stmt->close();

        return $response
            ->withHeader('Location', '/admin/archives/' . $id)
            ->withStatus(303);
    }

    /**
     * POST /admin/archives/{id}/delete — soft-delete.
     *
     * Aligns with the `libri` table's soft-delete convention: sets
     * deleted_at = NOW() and never physically drops the row. Children are
     * NOT cascade-soft-deleted — fonds-level deletion with active series
     * below is a destructive operation that needs explicit UI confirmation
     * (roadmapped for phase 2).
     */
    public function destroyAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'UPDATE archival_units SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] delete prepare failed: ' . $this->db->error, ['id' => $id]);
            return $response->withHeader('Location', url('/admin/archives/' . $id) /* FIX F032 */)->withStatus(303);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] destroyAction execute failed: ' . $stmt->error, ['id' => $id]);
            $stmt->close();
            return $response->withHeader('Location', url('/admin/archives/' . $id) /* FIX F032 */)
                ->withStatus(303);
        }
        $stmt->close();
        return $response->withHeader('Location', url('/admin/archives') /* FIX F032 */)->withStatus(303);
    }

    // ── Phase 5+ — cover + document asset upload ─────────────────────────

    /**
     * Accept a JPEG/PNG/WebP cover image and persist the relative URL
     * in `archival_units.cover_image_path`. Files land under
     * `public/uploads/archives/covers/<id>.<ext>` so nginx serves them
     * directly. Size cap 8 MB; mime checked via finfo (not just the
     * browser-provided type) to reject masqueraded uploads.
     */
    public function uploadCoverAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        return $this->handleAssetUpload(
            $request,
            $response,
            $id,
            'cover',
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            8 * 1024 * 1024
        );
    }

    /**
     * Accept a PDF / ePub / audio / video document and persist
     * `document_path`, `document_mime`, `document_filename`. Size
     * cap 200 MB. Mime whitelist covers the common archival formats.
     */
    public function uploadDocumentAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        return $this->handleAssetUpload(
            $request,
            $response,
            $id,
            'document',
            [
                'application/pdf'         => 'pdf',
                'application/epub+zip'    => 'epub',
                'audio/mpeg'              => 'mp3',
                'audio/mp4'               => 'm4a',
                'audio/ogg'               => 'ogg',
                'audio/wav'               => 'wav',
                'audio/x-wav'             => 'wav',
                'video/mp4'               => 'mp4',
                'video/webm'              => 'webm',
                'image/tiff'              => 'tiff',
                'image/jpeg'              => 'jpg',
                'image/png'               => 'png',
            ],
            200 * 1024 * 1024
        );
    }

    /**
     * Remove the cover or document asset referenced by the row —
     * unlinks the file and nulls the path columns. Expects POST with
     * type=cover|document in the body.
     */
    public function removeAssetAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $parsed = (array) $request->getParsedBody();
        $type = (string) ($parsed['type'] ?? '');
        if (!in_array($type, ['cover', 'document'], true)) {
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }

        if ($type === 'cover') {
            $currentPath = (string) ($row['cover_image_path'] ?? '');
            $sql = 'UPDATE archival_units SET cover_image_path = NULL WHERE id = ?';
        } else {
            $currentPath = (string) ($row['document_path'] ?? '');
            $sql = 'UPDATE archival_units SET document_path = NULL, document_mime = NULL, document_filename = NULL WHERE id = ?';
        }

        if ($currentPath !== '') {
            // Harden against traversal on a tampered row: the upload
            // pipeline only writes under /uploads/archives/{covers,
            // documents}/ — refuse to unlink anything else, no matter
            // what the DB says. Cheap prefix check, belt + suspenders.
            $allowedPrefix = $type === 'cover'
                ? '/uploads/archives/covers/'
                : '/uploads/archives/documents/';
            if (str_starts_with($currentPath, $allowedPrefix)) {
                $fsPath = __DIR__ . '/../../../public' . $currentPath;
                if (is_file($fsPath)) {
                    @unlink($fsPath);
                }
            } else {
                SecureLogger::warning('[Archives] skip unlink — disallowed path prefix', [
                    'type' => $type,
                    'path' => $currentPath,
                ]);
            }
        }
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Archives] removeAssetAction prepare failed: ' . $this->db->error, ['id' => $id]);
            return $response->withHeader('Location', url('/admin/archives/' . $id) /* FIX F032 */)->withStatus(303);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] removeAssetAction execute failed after file deletion: ' . $stmt->error, ['id' => $id, 'note' => 'physical file may be orphaned']);
        }
        $stmt->close();
        return $response->withHeader('Location', url('/admin/archives/' . $id) /* FIX F032 */)->withStatus(303);
    }

    /**
     * Shared upload pipeline used by uploadCoverAction + uploadDocumentAction.
     *
     * @param array<string, string> $mimeToExt   allowed mime → extension map
     * @param int                   $maxBytes    per-file size cap
     */
    private function handleAssetUpload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        string $kind,
        array $mimeToExt,
        int $maxBytes
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        $files = (array) $request->getUploadedFiles();
        $key = $kind === 'cover' ? 'cover' : 'document';
        $upload = $files[$key] ?? null;
        if (!($upload instanceof \Psr\Http\Message\UploadedFileInterface)) {
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            SecureLogger::warning('[Archives] upload error', ['kind' => $kind, 'err' => $upload->getError()]);
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        if ($upload->getSize() !== null && $upload->getSize() > $maxBytes) {
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }

        // Detect mime via finfo — the browser-provided Content-Type is
        // user-controlled and unreliable. Two-step detection:
        //   1. finfo_file on the temp path (normal PSR-7 uploaded files
        //      land in $_FILES / moveTo sources on disk).
        //   2. finfo_buffer fallback for PSR-7 implementations that
        //      keep the upload body in php://temp — finfo_file cannot
        //      open those and would return false, giving a false reject.
        $stream = $upload->getStream();
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $tmpPath = $stream->getMetadata('uri');
                if (is_string($tmpPath) && is_file($tmpPath)) {
                    $detected = @finfo_file($finfo, $tmpPath);
                    if (is_string($detected)) {
                        $mime = $detected;
                    }
                }
                if ($mime === '') {
                    $contents = (string) $stream;
                    if ($contents !== '') {
                        $detected = @finfo_buffer($finfo, $contents);
                        if (is_string($detected)) {
                            $mime = $detected;
                        }
                    }
                }
                finfo_close($finfo);
            }
        }
        if ($mime === '' || !isset($mimeToExt[$mime])) {
            SecureLogger::warning('[Archives] upload rejected — mime not in allow-list', [
                'kind' => $kind, 'mime' => $mime,
            ]);
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        $ext = $mimeToExt[$mime];

        // FIX F031: defer legacy file removal until after the new file is
        // successfully recorded. For 'cover' the UPDATE overwrites the same
        // column so we can still unlink the predecessor here. For 'document'
        // the new entry lives in archival_unit_files (a separate table) and
        // the legacy document_path file must NOT be removed until the INSERT
        // succeeds — otherwise an INSERT failure would also clean up the new
        // file (further down) and orphan no reference, resulting in net data
        // loss. The deferred unlink is performed below after a confirmed INSERT.
        $existingPathField = $kind === 'cover' ? 'cover_image_path' : 'document_path';
        $existingPath = (string) ($row[$existingPathField] ?? '');
        if ($kind === 'cover' && $existingPath !== '') {
            $oldFs = __DIR__ . '/../../../public' . $existingPath;
            if (is_file($oldFs)) {
                @unlink($oldFs);
            }
        }

        $targetDirRel = $kind === 'cover'
            ? '/uploads/archives/covers'
            : '/uploads/archives/documents';
        $targetDirFs = __DIR__ . '/../../../public' . $targetDirRel;
        if (!is_dir($targetDirFs) && !@mkdir($targetDirFs, 0755, true) && !is_dir($targetDirFs)) {
            // Surface the FS error — otherwise the next moveTo() would
            // throw with a less informative "unable to move" message.
            SecureLogger::error('[Archives] failed to create upload dir', [
                'kind' => $kind,
                'dir'  => $targetDirFs,
            ]);
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        $basename = $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        try {
            $upload->moveTo($targetDirFs . '/' . $basename);
        } catch (\Throwable $e) {
            SecureLogger::error('[Archives] moveTo failed — aborting without DB update', [
                'kind' => $kind,
                'dest' => $targetDirFs . '/' . $basename,
                'err'  => $e->getMessage(),
            ]);
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        $relPath = $targetDirRel . '/' . $basename;

        if ($kind === 'cover') {
            $stmt = $this->db->prepare('UPDATE archival_units SET cover_image_path = ? WHERE id = ?');
            if ($stmt !== false) {
                $stmt->bind_param('si', $relPath, $id);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $clientName = (string) ($upload->getClientFilename() ?: ('archive-' . $id . '.' . $ext));
            $clientName = basename($clientName);
            // Insert into archival_unit_files (multi-document table).
            // sort_order = max existing + 1 so new files appear last.
            $stmt = $this->db->prepare(
                'INSERT INTO archival_unit_files (unit_id, file_path, file_mime, original_filename, sort_order)
                 SELECT ?, ?, ?, ?,
                        COALESCE((SELECT MAX(sort_order) FROM archival_unit_files WHERE unit_id = ?), -1) + 1'
            );
            if ($stmt !== false) {
                $stmt->bind_param('isssi', $id, $relPath, $mime, $clientName, $id);
                $inserted = $stmt->execute();
                $insertErr = $stmt->error;
                $stmt->close();
                if ($inserted) {
                    // Null out legacy single-document columns only after a confirmed
                    // INSERT, so we don't orphan the existing reference on failure.
                    $nullStmt = $this->db->prepare(
                        'UPDATE archival_units SET document_path = NULL, document_mime = NULL, document_filename = NULL WHERE id = ?'
                    );
                    if ($nullStmt !== false) {
                        $nullStmt->bind_param('i', $id);
                        $nullStmt->execute();
                        $nullStmt->close();
                    }
                    // FIX F031: now that the new archival_unit_files row is
                    // committed and the legacy columns are cleared, the old
                    // file on disk is orphaned and safe to unlink. Done last
                    // so an earlier failure leaves the legacy file intact.
                    if ($existingPath !== '') {
                        $oldFs = __DIR__ . '/../../../public' . $existingPath;
                        if (is_file($oldFs)) {
                            @unlink($oldFs);
                        }
                    }
                } else {
                    $movedPath = $targetDirFs . '/' . $basename;
                    if (is_file($movedPath)) {
                        unlink($movedPath);
                    }
                    \App\Support\SecureLogger::error('[Archives] archival_unit_files INSERT failed', [
                        'unit_id' => $id,
                        'path'    => $relPath,
                        'error'   => $insertErr,
                    ]);
                }
            }
        }
        return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
    }

    /**
     * Delete a single file from archival_unit_files and unlink it from disk.
     * Requires the file to belong to the given unit (ownership check).
     */
    public function removeFileAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $unitId,
        int $fileId
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'SELECT file_path FROM archival_unit_files WHERE id = ? AND unit_id = ?'
        );
        if ($stmt === false) {
            return $response->withHeader('Location', '/admin/archives/' . $unitId)->withStatus(303);
        }
        $stmt->bind_param('ii', $fileId, $unitId);
        $stmt->execute();
        $result = $stmt->get_result();
        $file   = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($file === null) {
            return $response->withHeader('Location', '/admin/archives/' . $unitId)->withStatus(303);
        }

        $filePath = (string) $file['file_path'];

        $del = $this->db->prepare('DELETE FROM archival_unit_files WHERE id = ? AND unit_id = ?');
        if ($del !== false) {
            $del->bind_param('ii', $fileId, $unitId);
            $deleted = $del->execute() && $del->affected_rows === 1;
            $del->close();
            if ($deleted && str_starts_with($filePath, '/uploads/archives/documents/')) {
                $fsPath  = __DIR__ . '/../../../public' . $filePath;
                $baseDir = realpath(__DIR__ . '/../../../public/uploads/archives/documents');
                if ($baseDir !== false) {
                    $real = realpath($fsPath);
                    if ($real !== false && str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR)) {
                        @unlink($real);
                    }
                }
            }
        }

        return $response->withHeader('Location', '/admin/archives/' . $unitId)->withStatus(303);
    }

    /**
     * Return all files for a unit ordered by sort_order, id.
     *
     * @return list<array{id:int,file_path:string,file_mime:string,original_filename:string,sort_order:int}>
     */
    /**
     * Bulk-fetch unit files for multiple units in a single query.
     * Returns a map keyed by unit_id.
     *
     * @param list<int> $unitIds
     * @return array<int, list<array{id:int,file_path:string,file_mime:string,original_filename:string,sort_order:int}>>
     */
    private function fetchUnitFilesForUnits(array $unitIds): array
    {
        if (empty($unitIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, unit_id, file_path, file_mime, original_filename, sort_order
               FROM archival_unit_files
              WHERE unit_id IN ($placeholders)
              ORDER BY unit_id, sort_order, id"
        );
        if ($stmt === false) {
            return [];
        }
        $types = str_repeat('i', count($unitIds));
        $stmt->bind_param($types, ...$unitIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map    = [];
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) {
                $uid = (int) $r['unit_id'];
                $map[$uid][] = [
                    'id'                => (int)    $r['id'],
                    'file_path'         => (string) $r['file_path'],
                    'file_mime'         => (string) $r['file_mime'],
                    'original_filename' => (string) $r['original_filename'],
                    'sort_order'        => (int)    $r['sort_order'],
                ];
            }
        }
        $stmt->close();
        return $map;
    }

    private function fetchUnitFiles(int $unitId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, file_path, file_mime, original_filename, sort_order
               FROM archival_unit_files
              WHERE unit_id = ?
              ORDER BY sort_order, id'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $unitId);
        $stmt->execute();
        $result = $stmt->get_result();
        /** @var list<array{id:int,file_path:string,file_mime:string,original_filename:string,sort_order:int}> $rows */
        $rows = [];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    // ── Phase 2 — authority_records handlers ────────────────────────────

    /**
     * GET /admin/archives/authorities — list authority_records, newest first.
     */
    public function authorityIndexAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $rows = [];
        $result = $this->db->query(
            "SELECT id, type, authorised_form, dates_of_existence, created_at
               FROM authority_records
              WHERE deleted_at IS NULL
              ORDER BY authorised_form ASC
              LIMIT 500"
        );
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        } else {
            SecureLogger::warning('[Archives] authority index query failed: ' . $this->db->error);
        }
        return $this->renderView($response, 'authorities/index', ['rows' => $rows]);
    }

    /**
     * GET /admin/archives/authorities/new — blank authority form.
     */
    public function authorityNewAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderView($response, 'authorities/form', [
            'types'  => array_keys(self::AUTHORITY_TYPES),
            'values' => [],
            'errors' => [],
        ]);
    }

    /**
     * POST /admin/archives/authorities/new — validate + INSERT.
     *
     * Minimum viable fields:
     *   - type                ∈ AUTHORITY_TYPES (required)
     *   - authorised_form     required, ≤500 chars (ISAAR 5.1.2)
     *   - dates_of_existence  optional, ≤255 chars (ISAAR 5.2.1 — free text,
     *     e.g. "1888–1976" or "fl. 1920s")
     *   - history             optional (ISAAR 5.2.2)
     *   - functions           optional (ISAAR 5.2.5)
     */
    public function authorityStoreAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $values = $this->extractAuthorityPayload($request);
        $errors = $this->validateAuthority($values);

        if (!empty($errors)) {
            return $this->renderView($response, 'authorities/form', [
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO authority_records
                (type, authorised_form, parallel_forms, other_forms, identifiers,
                 dates_of_existence, history, places, legal_status, functions,
                 mandates, internal_structure, general_context)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] authority store prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderView($response, 'authorities/form', [
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }

        $p = $this->nullableAuthorityParams($values);
        // 13 strings (type + authorised_form + 11 nullable)
        $stmt->bind_param(
            'sssssssssssss',
            $values['type'],
            $values['authorised_form'],
            $p['parallel_forms'],
            $p['other_forms'],
            $p['identifiers'],
            $p['dates_of_existence'],
            $p['history'],
            $p['places'],
            $p['legal_status'],
            $p['functions'],
            $p['mandates'],
            $p['internal_structure'],
            $p['general_context']
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] authority INSERT failed: ' . $stmt->error);
            $stmt->close();
            $errors['_global'] = 'Insert failed. Check the log.';
            return $this->renderView($response, 'authorities/form', [
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }
        $stmt->close();

        return $response
            ->withHeader('Location', '/admin/archives/authorities')
            ->withStatus(303);
    }

    /**
     * GET /admin/archives/authorities/{id} — read-only detail view.
     * Also renders the list of archival_units this authority is linked to
     * so reviewers can see the provenance at a glance.
     */
    public function authorityShowAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findAuthorityById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        $links = $this->fetchArchivalUnitsForAuthority($id);
        return $this->renderView($response, 'authorities/show', [
            'row'             => $row,
            'links'           => $links,
            'linked_autori'   => $this->fetchAutoriForAuthority($id),
            'available_autori'=> $this->searchAutori('', 100),
            'headLinks'       => [
                ['rel' => 'alternate', 'type' => 'application/ld+json',
                 'title' => 'RiC-O (Records in Contexts)',
                 'href' => absoluteUrl('/archives/agents/' . $id . '/ric.json')],
            ],
        ]);
    }

    /**
     * GET /admin/archives/authorities/{id}/edit — pre-populated form.
     */
    public function authorityEditAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findAuthorityById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        return $this->renderView($response, 'authorities/form', [
            'mode'   => 'edit',
            'id'     => $id,
            'types'  => array_keys(self::AUTHORITY_TYPES),
            'values' => $row,
            'errors' => [],
        ]);
    }

    /**
     * POST /admin/archives/authorities/{id}/edit — validate + UPDATE.
     */
    public function authorityUpdateAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $existing = $this->findAuthorityById($id);
        if ($existing === null) {
            return $this->renderNotFound($response, $id);
        }
        $values = $this->extractAuthorityPayload($request);
        $errors = $this->validateAuthority($values);

        if (!empty($errors)) {
            return $this->renderView($response, 'authorities/form', [
                'mode'   => 'edit',
                'id'     => $id,
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }

        $stmt = $this->db->prepare(
            'UPDATE authority_records
                SET type = ?, authorised_form = ?, parallel_forms = ?, other_forms = ?,
                    identifiers = ?, dates_of_existence = ?, history = ?, places = ?,
                    legal_status = ?, functions = ?, mandates = ?, internal_structure = ?,
                    general_context = ?
              WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] authority update prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderView($response, 'authorities/form', [
                'mode'   => 'edit',
                'id'     => $id,
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }
        $p = $this->nullableAuthorityParams($values);
        // 13 strings (type + authorised_form + 11 nullable) + 1 int (id) = 'sssssssssssssi'
        $stmt->bind_param(
            'sssssssssssssi',
            $values['type'],
            $values['authorised_form'],
            $p['parallel_forms'],
            $p['other_forms'],
            $p['identifiers'],
            $p['dates_of_existence'],
            $p['history'],
            $p['places'],
            $p['legal_status'],
            $p['functions'],
            $p['mandates'],
            $p['internal_structure'],
            $p['general_context'],
            $id
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] authority UPDATE failed: ' . $stmt->error);
            $stmt->close();
            $errors['_global'] = 'Update failed. Check the log.';
            return $this->renderView($response, 'authorities/form', [
                'mode'   => 'edit',
                'id'     => $id,
                'types'  => array_keys(self::AUTHORITY_TYPES),
                'values' => $values,
                'errors' => $errors,
            ]);
        }
        $stmt->close();

        return $response
            ->withHeader('Location', '/admin/archives/authorities/' . $id)
            ->withStatus(303);
    }

    /**
     * POST /admin/archives/authorities/{id}/delete — soft-delete.
     * Link rows in archival_unit_authority are kept intact because we
     * keep the data; they simply won't surface since the join filters
     * on deleted_at IS NULL.
     */
    public function authorityDestroyAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'UPDATE authority_records SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] authority delete prepare failed: ' . $this->db->error, ['id' => $id]);
            return $response->withHeader('Location', url('/admin/archives/authorities/' . $id) /* FIX F032 */)->withStatus(303);
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] authorityDestroyAction execute failed: ' . $stmt->error, ['id' => $id]);
            $stmt->close();
            return $response->withHeader('Location', url('/admin/archives/authorities/' . $id) /* FIX F032 */)
                ->withStatus(303);
        }
        $stmt->close();
        return $response->withHeader('Location', url('/admin/archives/authorities') /* FIX F032 */)->withStatus(303);
    }

    /**
     * POST /admin/archives/{id}/authorities/attach — link an existing authority
     * to an archival_unit with a role. Idempotent: INSERT IGNORE via the
     * composite PK on (archival_unit_id, authority_id, role).
     *
     * Body: authority_id (int, required), role (∈ AUTHORITY_ROLES, required).
     */
    public function attachAuthorityAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $archivalUnitId
    ): ResponseInterface {
        if ($this->findById($archivalUnitId) === null) {
            return $this->renderNotFound($response, $archivalUnitId);
        }
        $body = (array) $request->getParsedBody();
        $authorityId = isset($body['authority_id']) ? (int) $body['authority_id'] : 0;
        $role = (string) ($body['role'] ?? '');

        if ($authorityId > 0 && array_key_exists($role, self::AUTHORITY_ROLES)) {
            if ($this->findAuthorityById($authorityId) !== null) {
                $stmt = $this->db->prepare(
                    'INSERT IGNORE INTO archival_unit_authority
                        (archival_unit_id, authority_id, role)
                     VALUES (?, ?, ?)'
                );
                if ($stmt !== false) {
                    $stmt->bind_param('iis', $archivalUnitId, $authorityId, $role);
                    if (!$stmt->execute()) {
                        SecureLogger::error('[Archives] attachAuthorityAction execute failed: ' . $stmt->error, ['archival_unit_id' => $archivalUnitId, 'authority_id' => $authorityId]);
                    }
                    $stmt->close();
                } else {
                    SecureLogger::error('[Archives] attachAuthorityAction prepare failed: ' . $this->db->error, ['archival_unit_id' => $archivalUnitId, 'authority_id' => $authorityId]);
                }
            }
        }

        return $response
            ->withHeader('Location', url('/admin/archives/' . $archivalUnitId) /* FIX F032 */)
            ->withStatus(303);
    }

    /**
     * POST /admin/archives/{id}/authorities/{authority_id}/detach — remove
     * every role-link between the unit and the authority. A finer-grained
     * per-role detach is possible later via a querystring, but the UI only
     * needs "remove this authority from this unit" at this stage.
     */
    public function detachAuthorityAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $archivalUnitId,
        int $authorityId
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'DELETE FROM archival_unit_authority
              WHERE archival_unit_id = ? AND authority_id = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $archivalUnitId, $authorityId);
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', '/admin/archives/' . $archivalUnitId)
            ->withStatus(303);
    }

    /**
     * POST /admin/archives/authorities/{id}/autori/link — link an existing
     * bibliographic `autori` row to the authority record. Idempotent via
     * composite PK on (autori_id, authority_id).
     *
     * Body: autori_id (int, required). Missing/invalid → no-op + 303.
     */
    public function linkAutoreAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $authorityId
    ): ResponseInterface {
        if ($this->findAuthorityById($authorityId) === null) {
            return $this->renderNotFound($response, $authorityId);
        }
        $body = (array) $request->getParsedBody();
        $autoreId = isset($body['autori_id']) ? (int) $body['autori_id'] : 0;

        if ($autoreId > 0 && $this->autoreExists($autoreId)) {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO autori_authority_link (autori_id, authority_id) VALUES (?, ?)'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ii', $autoreId, $authorityId);
                if (!$stmt->execute()) {
                    SecureLogger::error('[Archives] linkAutoreAction execute failed: ' . $stmt->error, ['autori_id' => $autoreId, 'authority_id' => $authorityId]);
                }
                $stmt->close();
            } else {
                SecureLogger::error('[Archives] linkAutoreAction prepare failed: ' . $this->db->error, ['autori_id' => $autoreId, 'authority_id' => $authorityId]);
            }
        }
        return $response
            ->withHeader('Location', url('/admin/archives/authorities/' . $authorityId) /* FIX F032 */)
            ->withStatus(303);
    }

    /**
     * POST /admin/archives/authorities/{id}/autori/{autori_id}/unlink —
     * remove the (autori_id, authority_id) link row.
     */
    public function unlinkAutoreAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $authorityId,
        int $autoreId
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'DELETE FROM autori_authority_link WHERE authority_id = ? AND autori_id = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $authorityId, $autoreId);
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', '/admin/archives/authorities/' . $authorityId)
            ->withStatus(303);
    }

    /**
     * True when an `autori` row with this id exists in the core schema.
     * Core autori currently hard-delete, so no deleted_at filter needed.
     */
    private function autoreExists(int $autoreId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM autori WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $autoreId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result instanceof \mysqli_result && $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    /**
     * Return all `autori` rows linked to the given authority_id + a count
     * of their books so the UI can show "Stauning (12 libri)" at a glance.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAutoriForAuthority(int $authorityId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT a.id, a.nome, a.data_nascita, a.data_morte,
                    (SELECT COUNT(*) FROM libri_autori la WHERE la.autore_id = a.id) AS book_count
               FROM autori_authority_link aal
               JOIN autori a ON a.id = aal.autori_id
              WHERE aal.authority_id = ?
              ORDER BY a.nome'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('i', $authorityId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Search `autori` by name fragment. Returns up to $limit rows. Used
     * by the "link library author" UI in authorities/show.php — MVP is a
     * flat <select> populated with all autori but that's unbounded, so
     * we cap and hint a type-ahead for phase 3.
     *
     * @return list<array<string, mixed>>
     */
    public function searchAutori(string $q, int $limit = 50): array
    {
        $rows = [];
        $q = trim($q);
        if ($q === '') {
            $result = $this->db->query('SELECT id, nome FROM autori ORDER BY nome LIMIT ' . (int) $limit);
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                $result->free();
            }
            return $rows;
        }
        $like = '%' . $q . '%';
        $stmt = $this->db->prepare('SELECT id, nome FROM autori WHERE nome LIKE ? ORDER BY nome LIMIT ?');
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) { $rows[] = $row; }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch one non-deleted authority_record by id, or null.
     *
     * @return array<string, mixed>|null
     */
    private function findAuthorityById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM authority_records WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] findAuthorityById prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * Return all archival_units currently linked to the given authority_id.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchArchivalUnitsForAuthority(int $authorityId): array
    {
        $rows = [];
        // adamsreview F002: formal_title is the fallback preferTitle() reads
        // when constructed_title is empty — must be selected so the fallback
        // is not dead code on units lacking a constructed title.
        //
        // CodeRabbit R4: shared helper — silent-degrade on DB error (the
        // pre-RiC contract used by admin HTML views, MARCXML/EAD3/METS
        // exporters, the OAI provider, and the IIIF manifest builder).
        // The R2 strict-throw behaviour broke those non-RiC callers by
        // surfacing raw exceptions to Slim's default error handler when
        // each format had its own error-envelope semantics. RiC callers
        // detect the failure via mysqli error state immediately after
        // the call (see fetchSharedAuthoritiesForRic helpers below).
        $stmt = $this->db->prepare(
            'SELECT au.id, au.reference_code, au.level, au.constructed_title, au.formal_title, aua.role
               FROM archival_unit_authority aua
               JOIN archival_units au ON au.id = aua.archival_unit_id AND au.deleted_at IS NULL
              WHERE aua.authority_id = ?
              ORDER BY FIELD(au.level,\'fonds\',\'series\',\'file\',\'item\'), au.reference_code'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] fetchArchivalUnitsForAuthority prepare failed: ' . $this->db->error);
            return $rows;
        }
        $stmt->bind_param('i', $authorityId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] fetchArchivalUnitsForAuthority execute failed: ' . $stmt->error);
            $stmt->close();
            return $rows;
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Return all authorities linked to the given archival_unit_id.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAuthoritiesForArchivalUnit(int $archivalUnitId): array
    {
        $rows = [];
        // CodeRabbit R4: shared helper — silent-degrade on DB error. See
        // fetchArchivalUnitsForAuthority above for the rationale. The RiC
        // endpoints use the *ForRic strict wrappers further down.
        $stmt = $this->db->prepare(
            'SELECT ar.id, ar.type, ar.authorised_form, ar.dates_of_existence,
                    ar.parallel_forms, ar.other_forms, ar.history, ar.functions,
                    ar.places, aua.role
               FROM archival_unit_authority aua
               JOIN authority_records ar ON ar.id = aua.authority_id AND ar.deleted_at IS NULL
              WHERE aua.archival_unit_id = ?
              ORDER BY ar.authorised_form'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] fetchAuthoritiesForArchivalUnit prepare failed: ' . $this->db->error);
            return $rows;
        }
        $stmt->bind_param('i', $archivalUnitId);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] fetchAuthoritiesForArchivalUnit execute failed: ' . $stmt->error);
            $stmt->close();
            return $rows;
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Batch-fetch authorities for multiple archival units in a single query.
     * Eliminates N+1 queries in export loops that iterate over many units.
     *
     * @param int[] $unitIds
     * @return array<int, list<array<string, mixed>>>  unit_id → list of authority rows
     */
    private function fetchAuthoritiesForUnits(array $unitIds): array
    {
        if (empty($unitIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $types = str_repeat('i', count($unitIds));
        $sql = 'SELECT aua.archival_unit_id, ar.id, ar.type, ar.authorised_form, ar.dates_of_existence, aua.role
                FROM archival_unit_authority aua
                JOIN authority_records ar ON ar.id = aua.authority_id AND ar.deleted_at IS NULL
                WHERE aua.archival_unit_id IN (' . $placeholders . ')
                ORDER BY aua.archival_unit_id, ar.authorised_form';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Archives] fetchAuthoritiesForUnits prepare failed: ' . $this->db->error);
            return [];
        }
        $stmt->bind_param($types, ...$unitIds);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $unitId = (int) $row['archival_unit_id'];
                unset($row['archival_unit_id']);
                $map[$unitId][] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $map;
    }

    /**
     * Return every non-deleted authority as a flat list, so the attach
     * form's <select> can offer them. Limited to 500 for safety; phase 3
     * will introduce a type-ahead search instead.
     *
     * @return list<array<string, mixed>>
     */
    public function listAllAuthorities(): array
    {
        $rows = [];
        $result = $this->db->query(
            "SELECT id, type, authorised_form
               FROM authority_records
              WHERE deleted_at IS NULL
              ORDER BY authorised_form
              LIMIT 500"
        );
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        return $rows;
    }

    // ── Phase 6 — SRU endpoint (archival_units + authority_records) ────

    /**
     * GET /api/archives/sru — SRU 1.2 endpoint for archival data.
     *
     * Supported operations:
     *   - explain         → server description + supported indexes
     *   - searchRetrieve  → CQL-driven query against archival_units
     *                       with MARCXML record format
     *   - scan            → stub (returns "scan not supported" diagnostic)
     *
     * Not admin-gated: SRU is a public interoperability protocol. Exposes
     * only non-deleted rows. The whole endpoint only exists while the
     * plugin is active — deactivate and the hook row vanishes from
     * plugin_hooks, registerRoutes never runs, Slim returns 404 for the
     * path, and the rest of the app is unaffected. No core file is
     * touched, so archives=disabled is guaranteed zero-regression.
     */

    // ── Public frontend actions ──────────────────────────────────────────

    /**
     * GET /archivio (it) / /archive (en) / /archiv (de) — public index.
     * Lists root-level archival_units, or full-text search results when ?q= is set.
     */
    public function publicIndexAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params    = $request->getQueryParams();
        $rawQ      = $params['q'] ?? '';
        $rawLevel  = $params['level'] ?? '';
        $q         = is_string($rawQ) ? trim($rawQ) : '';
        $level     = is_string($rawLevel) && isset(self::LEVELS[$rawLevel]) ? $rawLevel : '';
        $dateFrom  = (string) ($params['date_from'] ?? '');
        $dateTo    = (string) ($params['date_to'] ?? '');

        $rows = [];
        $isSearch = $q !== '' || $level !== '' || $dateFrom !== '' || $dateTo !== '';

        if ($isSearch) {
            $whereParts = ['deleted_at IS NULL'];
            $bindTypes  = '';
            $bindValues = [];

            if ($q !== '') {
                $pattern = $this->archiveSearchPattern($q);
                $whereParts[] = '(reference_code LIKE ? OR constructed_title LIKE ? OR formal_title LIKE ? OR scope_content LIKE ?)';
                $bindTypes  .= 'ssss';
                $bindValues = array_merge($bindValues, [$pattern, $pattern, $pattern, $pattern]);
            }
            if ($level !== '') {
                $whereParts[] = 'level = ?';
                $bindTypes  .= 's';
                $bindValues[] = $level;
            }
            if ($dateFrom !== '' && ctype_digit($dateFrom)) {
                $whereParts[] = '(date_end IS NULL OR date_end >= ?)';
                $bindTypes  .= 'i';
                $bindValues[] = (int) $dateFrom;
            }
            if ($dateTo !== '' && ctype_digit($dateTo)) {
                $whereParts[] = '(date_start IS NULL OR date_start <= ?)';
                $bindTypes  .= 'i';
                $bindValues[] = (int) $dateTo;
            }

            $sql  = "SELECT id, reference_code, level, formal_title, constructed_title,
                            date_start, date_end, extent, scope_content, specific_material
                       FROM archival_units
                      WHERE " . implode(' AND ', $whereParts) . "
                      ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC
                      LIMIT 200";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                if ($bindTypes !== '') {
                    $stmt->bind_param($bindTypes, ...$bindValues);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                    $result->free();
                }
                $stmt->close();
            }
        } else {
            $stmt = $this->db->prepare(
                "SELECT id, reference_code, level, formal_title, constructed_title,
                        date_start, date_end, extent, scope_content, specific_material
                   FROM archival_units
                  WHERE deleted_at IS NULL AND parent_id IS NULL
                  ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC
                  LIMIT 500"
            );
            if ($stmt !== false) {
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                    $result->free();
                }
                $stmt->close();
            }
        }

        $viewPath = __DIR__ . '/views/public/index.php';
        return $this->renderPublic($response, $viewPath, [
            'rows'      => $rows,
            'total'     => count($rows),
            'q'         => $q,
            'level'     => $level,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'isSearch'  => $isSearch,
        ]);
    }

    /**
     * Detail page. Two URL shapes accepted:
     *   - /archivio/{slug}-{id}  (canonical, SEO-friendly)
     *   - /archivio/{id}          (legacy → 301 to canonical form)
     * When the client used the legacy shape — or a slug that no longer
     * matches the current title — we emit a 301 to the canonical URL so
     * search engines converge on a single URL per archival unit.
     */
    public function publicShowAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        string $slugFromUrl = ''
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }

        $expectedSlug = slugify_text((string) ($row['constructed_title'] ?? ''));
        if ($expectedSlug !== '' && $slugFromUrl !== $expectedSlug) {
            $base = \App\Support\RouteTranslator::route('archives') ?: '/archive';
            $canonical = $base . '/' . $expectedSlug . '-' . $id;
            return $response->withHeader('Location', url($canonical))->withStatus(301);
        }
        // Children (direct descendants only — deeper hierarchy needs CTE).
        $children = [];
        $stmt = $this->db->prepare(
            "SELECT id, reference_code, level, constructed_title, date_start, date_end
               FROM archival_units
              WHERE parent_id = ? AND deleted_at IS NULL
              ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) {
                    $children[] = $r;
                }
                $result->free();
            }
            $stmt->close();
        }
        // Linked authorities.
        $authorities = [];
        $stmt = $this->db->prepare(
            "SELECT ar.id, ar.type, ar.authorised_form, ar.dates_of_existence, aua.role
               FROM archival_unit_authority aua
               JOIN authority_records ar ON ar.id = aua.authority_id
              WHERE aua.archival_unit_id = ? AND ar.deleted_at IS NULL
              ORDER BY FIELD(aua.role, 'creator','subject','custodian','recipient','associated'),
                       ar.authorised_form ASC"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) {
                    $authorities[] = $r;
                }
                $result->free();
            }
            $stmt->close();
        }
        // Breadcrumb trail (parent chain up to root).
        $breadcrumb = [];
        $current = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $safetyCap = 20;
        while ($current > 0 && $safetyCap-- > 0) {
            $parent = $this->findById($current);
            if ($parent === null) {
                break;
            }
            array_unshift($breadcrumb, [
                'id'    => (int) $parent['id'],
                'title' => (string) ($parent['constructed_title'] ?? ''),
            ]);
            $current = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : 0;
        }
        $viewPath = __DIR__ . '/views/public/show.php';
        return $this->renderPublic($response, $viewPath, [
            'row'         => $row,
            'children'    => $children,
            'authorities' => $authorities,
            'breadcrumb'  => $breadcrumb,
            'unit_files'  => $this->fetchUnitFiles($id),
        ]);
    }

    /**
     * Render a public view wrapped in the site's public layout (the
     * same header/footer used by /catalogo, /autore, etc.). Falls back
     * to a minimal HTML shell if the layout isn't available.
     *
     * @param array<string, mixed> $data
     */
    private function renderPublic(ResponseInterface $response, string $viewPath, array $data): ResponseInterface
    {
        if (!is_file($viewPath)) {
            $response->getBody()->write('<h1>Archive view not found</h1>');
            return $response->withStatus(500);
        }
        // Two-pass render: inner view → frontend layout. Same pattern as
        // UserDashboardController + UserWishlistController. The layout
        // consumes $content + $title and wraps them in the public shell
        // (header/nav/footer shared with /catalogo, /autore, etc.).
        ob_start();
        extract($data, EXTR_SKIP);
        include $viewPath;
        $content = (string) ob_get_clean();

        $title = ($data['row']['constructed_title'] ?? null)
            ? ((string) $data['row']['constructed_title'] . ' — ' . __('Archivio'))
            : __('Archivio');

        // Shortcut the layout's plugin-activation probe — we're inside
        // a live archive page, so the menu must display the Archivio
        // entry without a second DB round-trip.
        $archivesAvailable = true;
        $archivesRoute = \App\Support\RouteTranslator::route('archives') ?: '/archive';

        // Expose SEO variables to the layout's <head> — canonical URL,
        // description, and the optional Schema.org JSON-LD block
        // produced by the inner view (see public/show.php).
        $seoTitle = $title;
        if (!empty($data['row']['scope_content'])) {
            $seoDescription = mb_substr(
                (string) $data['row']['scope_content'],
                0,
                160
            );
        } elseif (!empty($data['row']['constructed_title'])) {
            $seoDescription = (string) $data['row']['constructed_title'] . ' — ' . __('Archivio');
        } else {
            $seoDescription = __('Consulta i fondi archivistici e le collezioni documentarie.');
        }
        if (isset($data['row']['id']) && isset($data['row']['constructed_title'])) {
            $seoCanonical = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/')
                . $archivesRoute . '/'
                . slugify_text((string) $data['row']['constructed_title'])
                . '-' . (int) $data['row']['id'];
        } else {
            $seoCanonical = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/') . $archivesRoute;
        }
        // $archiveSchema is populated by show.php (Schema.org JSON-LD).
        $archiveSchema = $archiveSchema ?? null;
        $headLinks = [];
        if (isset($data['row']['id'])) {
            $unitId = (int) $data['row']['id'];
            $headLinks[] = ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'Dublin Core (OAI-DC)',
                            'href' => absoluteUrl('/archives/' . $unitId . '/dc.xml')];
            $headLinks[] = ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'EAD3 Finding Aid',
                            'href' => absoluteUrl('/archives/' . $unitId . '/ead.xml')];
            $headLinks[] = ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'METS Package',
                            'href' => absoluteUrl('/archives/' . $unitId . '/mets.xml')];
            $headLinks[] = ['rel' => 'alternate',
                            'type' => 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"',
                            'title' => 'IIIF Manifest',
                            'href' => absoluteUrl('/archives/' . $unitId . '/manifest.json')];
            // CodeRabbit #1: the public archive page was missing the RiC-O
            // alternate link that the admin detail page already exposes.
            // Without it, an HTTP harvester crawling the public URL cannot
            // discover the JSON-LD serialisation via <link> tags.
            $headLinks[] = ['rel' => 'alternate',
                            'type' => 'application/ld+json',
                            'title' => 'RiC-O (Records in Contexts)',
                            'href' => absoluteUrl('/archives/' . $unitId . '/ric.json')];
        }

        $layoutPath = __DIR__ . '/../../../app/Views/frontend/layout.php';
        if (!is_file($layoutPath)) {
            $response->getBody()->write($content);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        ob_start();
        include $layoutPath;
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function sruAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $operation = (string) ($params['operation'] ?? 'explain');
        $version = (string) ($params['version'] ?? '1.2');

        $xml = match ($operation) {
            'searchRetrieve' => $this->sruSearchRetrieve($params, $version),
            'scan'           => $this->sruDiagnostic($version, 4, 'scan not supported'),
            default          => $this->sruExplain($version),
        };

        $response = $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
        $response->getBody()->write($xml);
        return $response;
    }

    /**
     * SRU `explain` response — describes the server + the supported indexes.
     */
    private function sruExplain(string $version): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs('sru', 'explainResponse', 'http://www.loc.gov/zing/srw/');
        $xw->writeElementNs('sru', 'version', null, $version);
        $xw->startElementNs('sru', 'record', null);
        $xw->writeElementNs('sru', 'recordPacking', null, 'xml');
        $xw->writeElementNs('sru', 'recordSchema', null, 'http://explain.z3950.org/dtd/2.1/');
        $xw->startElementNs('sru', 'recordData', null);
        $xw->startElementNs('zr', 'explain', 'http://explain.z3950.org/dtd/2.1/');
        $xw->startElementNs('zr', 'serverInfo', null);
        $xw->writeAttribute('protocol', 'SRU');
        $xw->writeAttribute('version', '1.2');
        $xw->writeElementNs('zr', 'host', null, (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $xw->writeElementNs('zr', 'port', null, (string) ($_SERVER['SERVER_PORT'] ?? '80'));
        $xw->writeElementNs('zr', 'database', null, 'archives');
        $xw->endElement();
        $xw->startElementNs('zr', 'databaseInfo', null);
        $xw->writeElementNs('zr', 'title', null, 'Pinakes Archival Catalogue (ISAD(G))');
        $xw->writeElementNs('zr', 'description', null,
            'Archival units and authority records per ISAD(G) + ISAAR(CPF).');
        $xw->endElement();
        $xw->startElementNs('zr', 'indexInfo', null);
        foreach (['title', 'anywhere', 'reference', 'level'] as $idx) {
            $xw->startElementNs('zr', 'index', null);
            $xw->startElementNs('zr', 'title', null);
            $xw->text($idx);
            $xw->endElement();
            $xw->startElementNs('zr', 'map', null);
            $xw->startElementNs('zr', 'name', null);
            $xw->writeAttribute('set', 'archives');
            $xw->text($idx);
            $xw->endElement();
            $xw->endElement();
            $xw->endElement();
        }
        $xw->endElement();
        $xw->startElementNs('zr', 'schemaInfo', null);
        $xw->startElementNs('zr', 'schema', null);
        $xw->writeAttribute('identifier', 'info:srw/schema/1/marcxml-v1.1');
        $xw->writeAttribute('name', 'marcxml');
        $xw->writeElementNs('zr', 'title', null, 'MARC21 Slim XML (ABA crosswalk)');
        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        return (string) $xw->outputMemory();
    }

    /**
     * SRU `searchRetrieve` — CQL query + MARCXML-packed result set.
     *
     * @param array<string, mixed> $params
     */
    private function sruSearchRetrieve(array $params, string $version): string
    {
        $cqlQuery = trim((string) ($params['query'] ?? ''));
        if ($cqlQuery === '') {
            return $this->sruDiagnostic($version, 7, 'mandatory parameter not supplied: query');
        }
        $startRecord = max(1, (int) ($params['startRecord'] ?? 1));
        $maximumRecords = min(50, max(0, (int) ($params['maximumRecords'] ?? 10)));

        $where = $this->cqlToWhere($cqlQuery);
        if ($where === null) {
            return $this->sruDiagnostic($version, 10, 'CQL query unsupported or unparseable');
        }

        $total = 0;
        $countSql = 'SELECT COUNT(*) FROM archival_units WHERE deleted_at IS NULL AND (' . $where['sql'] . ')';
        $countStmt = $this->db->prepare($countSql);
        if ($countStmt !== false) {
            if ($where['types'] !== '') {
                $countStmt->bind_param($where['types'], ...$where['params']);
            }
            $countStmt->execute();
            $r = $countStmt->get_result();
            if ($r instanceof \mysqli_result) {
                $row = $r->fetch_row();
                if (is_array($row)) { $total = (int) $row[0]; }
            }
            $countStmt->close();
        }

        $rows = [];
        if ($maximumRecords > 0 && $total > 0) {
            $offset = $startRecord - 1;
            $dataSql = 'SELECT * FROM archival_units WHERE deleted_at IS NULL AND (' . $where['sql'] . ')'
                . " ORDER BY FIELD(level,'fonds','series','file','item'), reference_code"
                . ' LIMIT ' . $maximumRecords . ' OFFSET ' . $offset;
            $dataStmt = $this->db->prepare($dataSql);
            if ($dataStmt !== false) {
                if ($where['types'] !== '') {
                    $dataStmt->bind_param($where['types'], ...$where['params']);
                }
                $dataStmt->execute();
                $r = $dataStmt->get_result();
                if ($r instanceof \mysqli_result) {
                    while ($row = $r->fetch_assoc()) { $rows[] = $row; }
                    $r->free();
                }
                $dataStmt->close();
            }
        }

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs('sru', 'searchRetrieveResponse', 'http://www.loc.gov/zing/srw/');
        $xw->writeElementNs('sru', 'version', null, $version);
        $xw->writeElementNs('sru', 'numberOfRecords', null, (string) $total);

        if (!empty($rows)) {
            $sruUnitIds = array_map(fn(array $r): int => (int) $r['id'], $rows);
            $sruAuthoritiesMap = $this->fetchAuthoritiesForUnits($sruUnitIds);
            $xw->startElementNs('sru', 'records', null);
            $position = $startRecord;
            foreach ($rows as $row) {
                $xw->startElementNs('sru', 'record', null);
                $xw->writeElementNs('sru', 'recordSchema', null, 'info:srw/schema/1/marcxml-v1.1');
                $xw->writeElementNs('sru', 'recordPacking', null, 'xml');
                $xw->startElementNs('sru', 'recordData', null);
                $authorities = $sruAuthoritiesMap[(int) $row['id']] ?? [];
                $this->writeArchivalUnitMarcRecord($xw, $row, $authorities);
                $xw->endElement();
                $xw->writeElementNs('sru', 'recordPosition', null, (string) $position);
                $xw->endElement();
                $position++;
            }
            $xw->endElement();
        }

        $xw->endElement();
        return (string) $xw->outputMemory();
    }

    /**
     * Emit a minimal SRU diagnostic envelope. Codes per
     * https://www.loc.gov/standards/sru/diagnostics/diagnosticsList.html.
     */
    private function sruDiagnostic(string $version, int $code, string $details): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs('sru', 'searchRetrieveResponse', 'http://www.loc.gov/zing/srw/');
        $xw->writeElementNs('sru', 'version', null, $version);
        $xw->writeElementNs('sru', 'numberOfRecords', null, '0');
        $xw->startElementNs('sru', 'diagnostics', null);
        $xw->startElementNs('diag', 'diagnostic', 'http://www.loc.gov/zing/srw/diagnostic/');
        $xw->writeElementNs('diag', 'uri', null, 'info:srw/diagnostic/1/' . $code);
        $xw->writeElementNs('diag', 'details', null, $details);
        $xw->endElement();
        $xw->endElement();
        $xw->endElement();
        return (string) $xw->outputMemory();
    }

    /**
     * Translate a minimal CQL subset to a parameterised WHERE fragment.
     *
     * Supported:
     *   anywhere="phrase"   — FULLTEXT match across title/scope/history
     *   title="x"           — LIKE on constructed_title
     *   reference="x"       — LIKE on reference_code
     *   level="fonds"       — exact match on level enum
     *   clauseA AND clauseB — conjunction (only AND for phase 6)
     *
     * @return array{sql: string, params: list<string>, types: string}|null
     */
    private function cqlToWhere(string $cql): ?array
    {
        $cql = trim($cql);
        if ($cql === '') { return null; }
        if (preg_match('/\b(OR|NOT|PROX)\b/i', $cql) === 1) {
            return null;
        }
        $clauses = preg_split('/\s+AND\s+/i', $cql) ?: [];

        $sqlParts = [];
        $params = [];
        $types = '';
        foreach ($clauses as $clause) {
            $parsed = $this->parseCqlClause(trim($clause));
            if ($parsed === null) { return null; }
            $sqlParts[] = $parsed['sql'];
            foreach ($parsed['params'] as $p) {
                $params[] = $p;
                $types .= 's';
            }
        }
        if (empty($sqlParts)) { return null; }
        return [
            'sql'    => '(' . implode(') AND (', $sqlParts) . ')',
            'params' => $params,
            'types'  => $types,
        ];
    }

    /**
     * Parse a single CQL clause `<index>=<value>` into a SQL fragment.
     *
     * @return array{sql: string, params: list<string>}|null
     */
    private function parseCqlClause(string $clause): ?array
    {
        if ($clause === '') { return null; }
        if (preg_match('/^([a-z_][a-z0-9_]*)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|(\S+))\s*$/i', $clause, $m) !== 1) {
            return null;
        }
        $index = strtolower($m[1]);
        $value = isset($m[2]) && $m[2] !== '' ? stripcslashes($m[2]) : (string) ($m[3] ?? '');

        $columnMap = [
            'title'     => 'constructed_title',
            'reference' => 'reference_code',
        ];

        if ($index === 'anywhere') {
            return [
                'sql'    => 'MATCH(formal_title, constructed_title, scope_content, archival_history) '
                          . 'AGAINST (? IN NATURAL LANGUAGE MODE)',
                'params' => [$value],
            ];
        }
        if ($index === 'level') {
            if (!array_key_exists($value, self::LEVELS)) {
                return null;
            }
            return [
                'sql'    => 'level = ?',
                'params' => [$value],
            ];
        }
        if (!isset($columnMap[$index])) {
            return null;
        }
        return [
            'sql'    => $columnMap[$index] . ' LIKE ?',
            'params' => ['%' . $value . '%'],
        ];
    }

    // ── Phase 4 — MARCXML import / export ──────────────────────────────

    /**
     * GET /admin/archives/{id}/export.xml — MARCXML for one archival_unit.
     * Streams the document via XMLWriter (memory-safe even for large fonds
     * with many 248 repeats).
     */
    public function exportArchivalUnitAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        $authorities = $this->fetchAuthoritiesForArchivalUnit($id);

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'collection', 'http://www.loc.gov/MARC21/slim');
        $this->writeArchivalUnitMarcRecord($xw, $row, $authorities);
        $xw->endElement();
        $xw->endDocument();

        // Sanitize the reference_code before putting it in Content-Disposition:
        // reference codes can legitimately contain `/`, `.`, spaces, and even
        // quotes, which would produce malformed headers or path traversal.
        // Same slug pattern used by the authority export below.
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $row['reference_code']) ?: 'archival_unit';
        return $this->xmlResponse($response, $xw->outputMemory(), $slug . '.xml');
    }

    /**
     * GET /admin/archives/authorities/{id}/export.xml — MARCXML for one
     * authority record.
     */
    public function exportAuthorityAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findAuthorityById($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'collection', 'http://www.loc.gov/MARC21/slim');
        $this->writeAuthorityMarcRecord($xw, $row);
        $xw->endElement();
        $xw->endDocument();

        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $row['authorised_form']) ?: 'authority';
        return $this->xmlResponse($response, $xw->outputMemory(), 'authority_' . $slug . '.xml');
    }

    /**
     * GET /admin/archives/export.xml?ids=1,2,3 — bulk export. Without `ids`,
     * exports all non-deleted archival_units (capped at 1000).
     */
    public function exportCollectionAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $idsParam = (string) ($params['ids'] ?? '');
        $ids = [];
        if ($idsParam !== '') {
            foreach (explode(',', $idsParam) as $raw) {
                $n = (int) trim($raw);
                if ($n > 0) { $ids[] = $n; }
            }
        }

        $rows = [];
        if (empty($ids)) {
            $result = $this->db->query(
                "SELECT * FROM archival_units WHERE deleted_at IS NULL
                  ORDER BY FIELD(level,'fonds','series','file','item'), reference_code
                  LIMIT 1000"
            );
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                $result->free();
            }
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM archival_units
                  WHERE id IN ($placeholders) AND deleted_at IS NULL
                  ORDER BY FIELD(level,'fonds','series','file','item'), reference_code"
            );
            if ($stmt !== false) {
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                    $result->free();
                }
                $stmt->close();
            }
        }

        $unitIds = array_map(fn(array $r): int => (int) $r['id'], $rows);
        $authoritiesMap = $this->fetchAuthoritiesForUnits($unitIds);

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'collection', 'http://www.loc.gov/MARC21/slim');
        foreach ($rows as $row) {
            $auth = $authoritiesMap[(int) $row['id']] ?? [];
            $this->writeArchivalUnitMarcRecord($xw, $row, $auth);
        }
        $xw->endElement();
        $xw->endDocument();

        return $this->xmlResponse($response, $xw->outputMemory(), 'archives_export.xml');
    }

    /**
     * GET /admin/archives/import — render the upload form.
     */
    public function importFormAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderView($response, 'import', ['result' => null]);
    }

    /**
     * POST /admin/archives/import — parse uploaded MARCXML, UPSERT or preview.
     *
     * Now handles BOTH `<record type="Bibliographic">` and `<record type="Authority">`
     * (phase 4b). Bibliographic records UPSERT by (institution_code, reference_code)
     * so re-importing an exported file is idempotent — already-present rows
     * report as "updated" and never trigger a duplicate-key error. Authorities
     * INSERT only (no natural key to UPSERT against in ISAAR), so duplicate
     * authority names from re-import are reported as "skipped" via a
     * pre-check on (type, authorised_form).
     */
    public function importSubmitAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $uploaded = $request->getUploadedFiles();
        $file = $uploaded['marcxml'] ?? null;
        $body = (array) $request->getParsedBody();
        $dryRun = !empty($body['dry_run']);
        $strictXsd = !empty($body['strict_xsd']);

        $result = [
            'success'             => false,
            'dry_run'             => $dryRun,
            'strict_xsd'          => $strictXsd,
            'parsed'              => [],
            'parsed_authorities'  => [],
            'inserted'            => [],
            'updated'             => [],
            'skipped'             => [],
            'inserted_authorities'=> [],
            'skipped_authorities' => [],
            'xsd_errors'          => [],
            'errors'              => [],
        ];

        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface
            || $file->getError() !== UPLOAD_ERR_OK) {
            $result['errors'][] = 'Upload failed or no file provided.';
            return $this->renderView($response, 'import', ['result' => $result]);
        }

        $xmlContent = (string) $file->getStream();

        // Phase 4d: optional XSD validation against the MARC21 Slim schema
        // bundled in storage/plugins/archives/schemas/MARC21slim.xsd.
        // When strict mode is on and the document fails validation, abort
        // before touching the DB. Soft mode (default) runs the validator
        // informationally — errors are shown but the import proceeds.
        if ($strictXsd) {
            $xsdErrors = $this->validateMarcXmlSchema($xmlContent);
            if (!empty($xsdErrors)) {
                $result['xsd_errors'] = $xsdErrors;
                $result['errors'][] = sprintf(
                    '%d XSD validation error(s). Import aborted (strict mode).',
                    count($xsdErrors)
                );
                return $this->renderView($response, 'import', ['result' => $result]);
            }
        }

        $parsed = $this->parseMarcXml($xmlContent);
        if (isset($parsed['error'])) {
            $result['errors'][] = $parsed['error'];
            return $this->renderView($response, 'import', ['result' => $result]);
        }
        $result['parsed']             = $parsed['records']     ?? [];
        $result['parsed_authorities'] = $parsed['authorities'] ?? [];

        if (!$dryRun) {
            foreach ($result['parsed'] as $record) {
                $upsert = $this->upsertImportedArchivalUnit($record);
                if ($upsert['action'] === 'inserted') {
                    $result['inserted'][] = ['id' => $upsert['id'], 'reference_code' => $record['reference_code'] ?? ''];
                } elseif ($upsert['action'] === 'updated') {
                    $result['updated'][] = ['id' => $upsert['id'], 'reference_code' => $record['reference_code'] ?? ''];
                } else {
                    $result['errors'][] = 'Failed to upsert: ' . ($record['reference_code'] ?? '(no ref)');
                }
            }
            foreach ($result['parsed_authorities'] as $auth) {
                $existing = $this->findAuthorityByName(
                    (string) $auth['type'],
                    (string) $auth['authorised_form']
                );
                if ($existing !== null) {
                    $result['skipped_authorities'][] = [
                        'id'              => $existing,
                        'authorised_form' => $auth['authorised_form'],
                    ];
                    continue;
                }
                $newId = $this->insertImportedAuthority($auth);
                if ($newId > 0) {
                    $result['inserted_authorities'][] = [
                        'id'              => $newId,
                        'authorised_form' => $auth['authorised_form'],
                    ];
                } else {
                    $result['errors'][] = 'Failed to insert authority: ' . ($auth['authorised_form'] ?? '(no name)');
                }
            }
            $result['success'] = (count($result['inserted']) + count($result['updated'])
                                + count($result['inserted_authorities'])) > 0
                              || empty($result['errors']);
        } else {
            $result['success'] = true;
        }
        return $this->renderView($response, 'import', ['result' => $result]);
    }

    /**
     * Emit one <record type="Bibliographic"> for an archival_unit, following
     * the ABA crosswalk. Authorities render as 100/110/600/610/700/710
     * based on (type, role).
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $authorities
     */
    private function writeArchivalUnitMarcRecord(\XMLWriter $xw, array $row, array $authorities): void
    {
        $xw->startElement('record');
        $xw->writeAttribute('type', 'Bibliographic');

        // 009 — material designation. 'b' = bibliographic archival text,
        // 'g' = two-dimensional image (ABA billedmarc 009*a convention).
        // Subfield *g carries the granular type: hf/hp/hm/hd/lm/vm per ABA.
        $materialMap = [
            'text'       => ['a' => 'b', 'g' => 'bf'],
            'photograph' => ['a' => 'g', 'g' => 'hf'],
            'poster'     => ['a' => 'g', 'g' => 'hp'],
            'postcard'   => ['a' => 'g', 'g' => 'hm'],
            'drawing'    => ['a' => 'g', 'g' => 'hd'],
            'audio'      => ['a' => 'l', 'g' => 'lm'],
            'video'      => ['a' => 'v', 'g' => 'vm'],
            'other'      => ['a' => 'x', 'g' => 'xx'],
        ];
        $spec = (string) ($row['specific_material'] ?? 'text');
        $this->writeMarcDatafield($xw, '009', $materialMap[$spec] ?? $materialMap['text']);

        $this->writeMarcDatafield($xw, '001', [
            'a' => (string) ($row['reference_code'] ?? ''),
            'b' => (string) ($row['institution_code'] ?? 'PINAKES'),
            'd' => isset($row['registration_date']) ? (string) $row['registration_date'] : null,
        ]);

        $sub008 = [];
        if (!empty($row['date_start'])) { $sub008['a'] = (string) $row['date_start']; }
        if (!empty($row['date_end']))   { $sub008['z'] = (string) $row['date_end']; }
        $levelMap = ['fonds' => 'a', 'series' => 'b', 'file' => 'c', 'item' => 'd'];
        if (isset($levelMap[$row['level'] ?? ''])) { $sub008['c'] = $levelMap[$row['level']]; }
        if (!empty($row['language_codes'])) { $sub008['l'] = (string) $row['language_codes']; }
        $this->writeMarcDatafield($xw, '008', $sub008);

        // 088 — local DK5 classification (ABA billedmarc convention).
        if (!empty($row['local_classification'])) {
            $this->writeMarcDatafield($xw, '088', ['a' => (string) $row['local_classification']]);
        }
        // 096 — shelfmark composite (class mark + collection name).
        $sub096 = [];
        if (!empty($row['local_classification'])) { $sub096['a'] = (string) $row['local_classification']; }
        if (!empty($row['collection_name']))      { $sub096['c'] = (string) $row['collection_name']; }
        $this->writeMarcDatafield($xw, '096', $sub096);

        if (!empty($row['formal_title'])) {
            $this->writeMarcDatafield($xw, '241', ['a' => (string) $row['formal_title']]);
        }
        // 245 — title + attributions (photographer / publisher as 245*e, *f).
        $sub245 = [];
        if (!empty($row['constructed_title'])) { $sub245['a'] = (string) $row['constructed_title']; }
        if (!empty($row['photographer']))      { $sub245['e'] = (string) $row['photographer']; }
        if (!empty($row['publisher']))         { $sub245['f'] = (string) $row['publisher']; }
        if ($spec !== 'text')                  { $sub245['m'] = '[' . $spec . ']'; }
        $this->writeMarcDatafield($xw, '245', $sub245);
        if (!empty($row['date_start'])) {
            $dateText = (string) $row['date_start']
                . (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']
                    ? '-' . (string) $row['date_end']
                    : '');
            $this->writeMarcDatafield($xw, '260', ['c' => $dateText]);
        }
        // 300 — physical description. *a = extent (free text), *b = color mode,
        // *c = dimensions. ABA billedmarc also uses 300*n for photo quantity
        // but we fold that into 'extent' on the data side.
        $sub300 = [];
        if (!empty($row['extent']))     { $sub300['a'] = (string) $row['extent']; }
        $colorCode = [
            'bw'    => 'black-and-white',
            'color' => 'colour',
            'mixed' => 'mixed',
        ];
        if (!empty($row['color_mode']) && isset($colorCode[$row['color_mode']])) {
            $sub300['b'] = $colorCode[$row['color_mode']];
        }
        if (!empty($row['dimensions'])) { $sub300['c'] = (string) $row['dimensions']; }
        $this->writeMarcDatafield($xw, '300', $sub300);
        $sub501 = [];
        if (!empty($row['predominant_dates'])) { $sub501['a'] = (string) $row['predominant_dates']; }
        if (!empty($row['date_gaps']))         { $sub501['b'] = (string) $row['date_gaps']; }
        $this->writeMarcDatafield($xw, '501', $sub501);

        if (!empty($row['scope_content'])) {
            $this->writeMarcDatafield($xw, '504', ['a' => (string) $row['scope_content']]);
        }
        $sub512 = [];
        if (!empty($row['registration_date']))  { $sub512['a'] = (string) $row['registration_date']; }
        if (!empty($row['material_status']))    { $sub512['b'] = (string) $row['material_status']; }
        if (!empty($row['arrangement_system'])) { $sub512['c'] = (string) $row['arrangement_system']; }
        $this->writeMarcDatafield($xw, '512', $sub512);

        if (!empty($row['access_conditions'])) {
            $this->writeMarcDatafield($xw, '513', ['a' => (string) $row['access_conditions']]);
        }
        $sub518 = [];
        if (!empty($row['access_conditions']))  { $sub518['a'] = (string) $row['access_conditions']; }
        if (!empty($row['reproduction_rules'])) { $sub518['b'] = (string) $row['reproduction_rules']; }
        $this->writeMarcDatafield($xw, '518', $sub518);

        $sub520 = [];
        if (!empty($row['archival_history']))   { $sub520['a'] = (string) $row['archival_history']; }
        if (!empty($row['acquisition_source'])) { $sub520['b'] = (string) $row['acquisition_source']; }
        $this->writeMarcDatafield($xw, '520', $sub520);

        if (!empty($row['related_units'])) {
            $this->writeMarcDatafield($xw, '525', ['a' => (string) $row['related_units']]);
        }
        if (!empty($row['finding_aids'])) {
            $this->writeMarcDatafield($xw, '526', ['a' => (string) $row['finding_aids']]);
        }
        $sub529 = [];
        if (!empty($row['originals_location'])) { $sub529['a'] = (string) $row['originals_location']; }
        if (!empty($row['copies_location']))    { $sub529['b'] = (string) $row['copies_location']; }
        $this->writeMarcDatafield($xw, '529', $sub529);

        if (!empty($row['physical_location'])) {
            $this->writeMarcDatafield($xw, '852', ['c' => (string) $row['physical_location']]);
        }

        foreach ($authorities as $auth) {
            $tag = $this->authorityTagFor((string) ($auth['type'] ?? ''), (string) ($auth['role'] ?? 'associated'));
            $this->writeMarcDatafield($xw, $tag, [
                'a' => (string) ($auth['authorised_form'] ?? ''),
                'd' => (string) ($auth['dates_of_existence'] ?? ''),
                'e' => (string) ($auth['role'] ?? ''),
            ]);
        }

        $xw->endElement();
    }

    /**
     * Emit one <record type="Authority"> for an ISAAR authority record.
     *
     * @param array<string, mixed> $row
     */
    private function writeAuthorityMarcRecord(\XMLWriter $xw, array $row): void
    {
        $xw->startElement('record');
        $xw->writeAttribute('type', 'Authority');

        $this->writeMarcDatafield($xw, '001', [
            'a' => 'authority_' . (string) $row['id'],
            'b' => 'PINAKES',
        ]);

        $tag = ((string) ($row['type'] ?? '') === 'corporate') ? '110' : '100';
        $this->writeMarcDatafield($xw, $tag, [
            'a' => (string) ($row['authorised_form'] ?? ''),
            'd' => (string) ($row['dates_of_existence'] ?? ''),
        ]);

        foreach (['parallel_forms', 'other_forms'] as $field) {
            if (!empty($row[$field])) {
                foreach (preg_split('/\r?\n/', (string) $row[$field]) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $this->writeMarcDatafield($xw, '400', ['a' => $line]);
                    }
                }
            }
        }

        $extra = [
            '024' => 'identifiers',
            '370' => 'places',
            '368' => 'legal_status',
            '372' => 'functions',
            '377' => 'mandates',
            '373' => 'internal_structure',
        ];
        foreach ($extra as $marcTag => $col) {
            if (!empty($row[$col])) {
                // PHP coerces numeric-looking keys ('370') to int when
                // iterating the array; cast back to string for the writer.
                $this->writeMarcDatafield($xw, (string) $marcTag, ['a' => (string) $row[$col]]);
            }
        }

        $bio = trim(((string) ($row['history'] ?? '')) . "\n" . ((string) ($row['general_context'] ?? '')));
        if ($bio !== '') {
            $this->writeMarcDatafield($xw, '678', ['a' => $bio]);
        }

        $xw->endElement();
    }

    /**
     * Map (authority_type, role) → MARC tag for archival_unit export.
     */
    private function authorityTagFor(string $type, string $role): string
    {
        if ($role === 'creator') { return $type === 'corporate' ? '110' : '100'; }
        if ($role === 'subject') { return $type === 'corporate' ? '610' : '600'; }
        return $type === 'corporate' ? '710' : '700';
    }

    /**
     * Write a MARC datafield with non-empty subfields; skip if all empty.
     *
     * @param array<string, string|null> $subfields
     */
    private function writeMarcDatafield(\XMLWriter $xw, string $tag, array $subfields): void
    {
        $filtered = array_filter($subfields, static fn($v) => $v !== null && $v !== '');
        if (empty($filtered)) {
            return;
        }
        $xw->startElement('datafield');
        $xw->writeAttribute('tag', $tag);
        $xw->writeAttribute('ind1', ' ');
        $xw->writeAttribute('ind2', ' ');
        foreach ($filtered as $code => $value) {
            $xw->startElement('subfield');
            $xw->writeAttribute('code', (string) $code);
            $xw->text((string) $value);
            $xw->endElement();
        }
        $xw->endElement();
    }

    /**
     * Phase 4d — validate MARCXML against the bundled MARC21 Slim XSD.
     *
     * Returns a list of human-readable error strings (empty = valid).
     * The XSD ships in storage/plugins/archives/schemas/MARC21slim.xsd
     * (v1.1 from the Library of Congress, standalone — no xml.xsd import).
     *
     * Uses DOMDocument::schemaValidate() with libxml error capture so we
     * can surface each violation to the admin instead of an unhelpful
     * "validation failed" boolean.
     *
     * @return list<string>
     */
    private function validateMarcXmlSchema(string $xml): array
    {
        $xsdPath = __DIR__ . '/schemas/MARC21slim.xsd';
        if (!is_file($xsdPath)) {
            return ['MARC21 Slim XSD not shipped with the plugin at ' . $xsdPath];
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        if (!$loaded) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $out = [];
            foreach ($errs as $err) {
                $out[] = 'XML parse: line ' . $err->line . ': ' . trim($err->message);
            }
            return $out !== [] ? $out : ['XML parse failed (no details)'];
        }

        $valid = $doc->schemaValidate($xsdPath);
        $errors = [];
        if (!$valid) {
            foreach (libxml_get_errors() as $err) {
                $errors[] = 'XSD: line ' . $err->line . ': ' . trim($err->message);
            }
            if ($errors === []) {
                $errors[] = 'XSD validation failed (no details)';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $errors;
    }

    /**
     * Parse a MARCXML document. Returns either:
     *   - {error: string}                               on parse failure
     *   - {records: list<...>, authorities: list<...>}  on success
     *
     * `records` are bibliographic archival_unit payloads; `authorities`
     * are ISAAR authority-record payloads. Either list may be empty.
     *
     * @return array{records?: list<array<string, mixed>>, authorities?: list<array<string, mixed>>, error?: string}
     */
    private function parseMarcXml(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            return ['error' => 'XML parse error: ' . ($errs[0]->message ?? 'unknown')];
        }
        $doc->registerXPathNamespace('m', 'http://www.loc.gov/MARC21/slim');

        $records = [];
        $authorities = [];
        $nodes = $doc->xpath('//m:record');
        if ($nodes === false || count($nodes) === 0) {
            $nodes = $doc->xpath('//record') ?: [];
        }

        foreach ($nodes as $rec) {
            $type = (string) ($rec['type'] ?? 'Bibliographic');
            $fields = $this->collectMarcFields($rec);

            if ($type === 'Authority') {
                $authRow = $this->buildAuthorityRowFromMarc($fields);
                if ($authRow !== null) {
                    $authorities[] = $authRow;
                }
                continue;
            }

            // Default: bibliographic archival_unit.
            $level = $this->decodeLevel($this->marcSub($fields, '008', 'c'));
            $refCode = $this->marcSub($fields, '001', 'a');
            $constructed = $this->marcSub($fields, '245', 'a');
            if ($refCode === null || $constructed === null || $level === null) {
                continue;
            }
            $dateStartRaw = $this->marcSub($fields, '008', 'a');
            $dateEndRaw   = $this->marcSub($fields, '008', 'z');
            // Phase 5: material designation mapping (009*g → specific_material).
            $materialReverse = [
                'bf' => 'text',
                'hf' => 'photograph',
                'hp' => 'poster',
                'hm' => 'postcard',
                'hd' => 'drawing',
                'lm' => 'audio',
                'vm' => 'video',
            ];
            $rawMat = $this->marcSub($fields, '009', 'g') ?? '';
            $specific = $materialReverse[$rawMat] ?? 'text';

            // 300*b reverse lookup.
            $colorReverse = [
                'black-and-white' => 'bw',
                'bw'              => 'bw',
                'colour'          => 'color',
                'color'           => 'color',
                'mixed'           => 'mixed',
            ];
            $rawColor = $this->marcSub($fields, '300', 'b') ?? '';
            $color = $colorReverse[strtolower(trim($rawColor))] ?? null;

            $records[] = [
                'reference_code'       => $refCode,
                'institution_code'     => $this->marcSub($fields, '001', 'b') ?? 'PINAKES',
                'level'                => $level,
                'formal_title'         => $this->marcSub($fields, '241', 'a'),
                'constructed_title'    => $constructed,
                'date_start'           => $dateStartRaw !== null ? (int) $dateStartRaw : null,
                'date_end'             => $dateEndRaw   !== null ? (int) $dateEndRaw   : null,
                'extent'               => $this->marcSub($fields, '300', 'a'),
                'scope_content'        => $this->marcSub($fields, '504', 'a'),
                'language_codes'       => $this->marcSub($fields, '008', 'l'),
                'archival_history'     => $this->marcSub($fields, '520', 'a'),
                'acquisition_source'   => $this->marcSub($fields, '520', 'b'),
                'access_conditions'    => $this->marcSub($fields, '513', 'a')
                                         ?? $this->marcSub($fields, '518', 'a'),
                'reproduction_rules'   => $this->marcSub($fields, '518', 'b'),
                'related_units'        => $this->marcSub($fields, '525', 'a'),
                'finding_aids'         => $this->marcSub($fields, '526', 'a'),
                // Phase 5 image fields
                'specific_material'    => $specific,
                'dimensions'           => $this->marcSub($fields, '300', 'c'),
                'color_mode'           => $color,
                'photographer'         => $this->marcSub($fields, '245', 'e'),
                'publisher'            => $this->marcSub($fields, '245', 'f'),
                'collection_name'      => $this->marcSub($fields, '096', 'c'),
                'local_classification' => $this->marcSub($fields, '088', 'a')
                                         ?? $this->marcSub($fields, '096', 'a'),
            ];
        }
        return ['records' => $records, 'authorities' => $authorities];
    }

    /**
     * Build an authority-row payload from a parsed MARC field map.
     * Returns null when required fields (type, authorised_form) are
     * missing so the caller can report a skipped record.
     *
     * Tag conventions mirror writeAuthorityMarcRecord():
     *   100 / 110 → name (100 person/family, 110 corporate)
     *   400       → parallel_forms (multi-occurrence collected via marcSubAll,
     *               joined with newline — phase 4c)
     *   024 370 368 372 377 373 → ISAAR extended elements
     *   678       → history + general_context (joined; split heuristic below)
     *
     * @param array<string, list<array<string, string>>> $fields
     * @return array<string, mixed>|null
     */
    private function buildAuthorityRowFromMarc(array $fields): ?array
    {
        // Choose the MARC tag that carries the name.
        $personName    = $this->marcSub($fields, '100', 'a');
        $corporateName = $this->marcSub($fields, '110', 'a');
        $name = $personName ?? $corporateName;
        if ($name === null || $name === '') {
            return null;
        }
        // Type inference: 110 → corporate; else person. Family-vs-person
        // split needs an external signal (e.g. 378 cataloguer-added code);
        // defer until phase 5.
        $type = $corporateName !== null ? 'corporate' : 'person';

        $dates = $this->marcSub($fields, '100', 'd')
              ?? $this->marcSub($fields, '110', 'd');

        // Phase 4c: preserve every 400 occurrence on round-trip.
        $parallel400 = $this->marcSubAll($fields, '400', 'a');
        $parallel = !empty($parallel400) ? implode("\n", $parallel400) : null;

        return [
            'type'               => $type,
            'authorised_form'    => $name,
            'parallel_forms'     => $parallel,
            'other_forms'        => null,
            'identifiers'        => $this->marcSub($fields, '024', 'a'),
            'dates_of_existence' => $dates,
            'history'            => $this->marcSub($fields, '678', 'a'),
            'places'             => $this->marcSub($fields, '370', 'a'),
            'legal_status'       => $this->marcSub($fields, '368', 'a'),
            'functions'          => $this->marcSub($fields, '372', 'a'),
            'mandates'           => $this->marcSub($fields, '377', 'a'),
            'internal_structure' => $this->marcSub($fields, '373', 'a'),
            'general_context'    => null,
        ];
    }

    /**
     * Return the value of subfield $code in the FIRST occurrence of $tag,
     * or null if either is missing. Backward-compat helper for callers
     * that only care about the first instance.
     *
     * @param array<string, list<array<string, string>>> $fields
     */
    private function marcSub(array $fields, string $tag, string $code): ?string
    {
        if (!isset($fields[$tag][0])) {
            return null;
        }
        $first = $fields[$tag][0];
        return $first[$code] ?? null;
    }

    /**
     * Return every subfield $code across all occurrences of $tag, in
     * document order. Used for round-tripping repeated fields like 400
     * (name tracings), 410, 610, 700, 710.
     *
     * Empty or missing subfields are skipped (not included as empty
     * strings) so the caller can implode() without worrying about holes.
     *
     * @param array<string, list<array<string, string>>> $fields
     * @return list<string>
     */
    private function marcSubAll(array $fields, string $tag, string $code): array
    {
        $out = [];
        if (!isset($fields[$tag])) {
            return $out;
        }
        foreach ($fields[$tag] as $occurrence) {
            if (isset($occurrence[$code]) && $occurrence[$code] !== '') {
                $out[] = $occurrence[$code];
            }
        }
        return $out;
    }

    /**
     * Flatten a <record>'s datafields into a tag → list-of-occurrences map.
     *
     * Every occurrence of a given MARC tag lands as a separate entry in the
     * inner list, preserving round-trip fidelity for repeated fields (400,
     * 410, 610, 700, 710, 248…). Phase 4 used last-write-wins which lost
     * information on re-import; phase 4c fixes that.
     *
     * Shape: `{tag: [{subCode: value, ...}, {subCode: value, ...}, ...]}`
     *
     * @return array<string, list<array<string, string>>>
     */
    private function collectMarcFields(\SimpleXMLElement $record): array
    {
        $out = [];
        $children = $record->children('http://www.loc.gov/MARC21/slim');
        if (count($children) === 0) {
            $children = $record->children();
        }
        foreach ($children as $field) {
            if ($field->getName() !== 'datafield') { continue; }
            // SimpleXML quirk: when $children comes from
            // ->children('namespace'), `$field['tag']` returns empty; the
            // attribute is only reachable via ->attributes(). See phase-4d
            // regression — unit tests never caught this because they
            // exercise DDL string shape only.
            $fAttrs = $field->attributes();
            $tag = $fAttrs !== null ? (string) ($fAttrs['tag'] ?? '') : '';
            if ($tag === '') { continue; }
            $subs = [];
            $subChildren = $field->children('http://www.loc.gov/MARC21/slim');
            if (count($subChildren) === 0) {
                $subChildren = $field->children();
            }
            foreach ($subChildren as $sub) {
                if ($sub->getName() !== 'subfield') { continue; }
                $sAttrs = $sub->attributes();
                $code = $sAttrs !== null ? (string) ($sAttrs['code'] ?? '') : '';
                if ($code === '') { continue; }
                $subs[$code] = trim((string) $sub);
            }
            if (!empty($subs)) {
                $out[$tag][] = $subs;
            }
        }
        return $out;
    }

    private function decodeLevel(?string $code): ?string
    {
        return match ($code) {
            'a' => 'fonds',
            'b' => 'series',
            'c' => 'file',
            'd' => 'item',
            default => null,
        };
    }

    /**
     * UPSERT an imported archival_unit by (institution_code, reference_code)
     * — the unique key already declared in DDL. Returns:
     *   {action: 'inserted'|'updated'|'failed', id: int}
     *
     * On insert: id is the new auto_increment value.
     * On update: id is fetched via SELECT on the unique key (mysqli's
     * insert_id returns 0 for ON DUPLICATE KEY UPDATE that matched).
     *
     * @param array<string, mixed> $r
     * @return array{action: string, id: int}
     */
    private function upsertImportedArchivalUnit(array $r): array
    {
        $sql = 'INSERT INTO archival_units
                  (reference_code, institution_code, level, formal_title,
                   constructed_title, date_start, date_end, extent,
                   scope_content, language_codes, archival_history,
                   acquisition_source, access_conditions, reproduction_rules,
                   related_units, finding_aids,
                   specific_material, dimensions, color_mode,
                   photographer, publisher, collection_name, local_classification)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  level = VALUES(level),
                  formal_title = VALUES(formal_title),
                  constructed_title = VALUES(constructed_title),
                  date_start = VALUES(date_start),
                  date_end = VALUES(date_end),
                  extent = VALUES(extent),
                  scope_content = VALUES(scope_content),
                  language_codes = VALUES(language_codes),
                  archival_history = VALUES(archival_history),
                  acquisition_source = VALUES(acquisition_source),
                  access_conditions = VALUES(access_conditions),
                  reproduction_rules = VALUES(reproduction_rules),
                  related_units = VALUES(related_units),
                  finding_aids = VALUES(finding_aids),
                  specific_material = VALUES(specific_material),
                  dimensions = VALUES(dimensions),
                  color_mode = VALUES(color_mode),
                  photographer = VALUES(photographer),
                  publisher = VALUES(publisher),
                  collection_name = VALUES(collection_name),
                  local_classification = VALUES(local_classification),
                  deleted_at = NULL';
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            SecureLogger::error('[Archives] upsert prepare failed: ' . $this->db->error);
            return ['action' => 'failed', 'id' => 0];
        }
        $formalTitle         = $r['formal_title']         ?? null;
        $extent              = $r['extent']               ?? null;
        $scopeContent        = $r['scope_content']        ?? null;
        $languageCodes       = $r['language_codes']       ?? null;
        $archivalHistory     = $r['archival_history']     ?? null;
        $acquisitionSource   = $r['acquisition_source']   ?? null;
        $accessConditions    = $r['access_conditions']    ?? null;
        $reproductionRules   = $r['reproduction_rules']   ?? null;
        $relatedUnits        = $r['related_units']        ?? null;
        $findingAids         = $r['finding_aids']         ?? null;
        $dateStart           = $r['date_start']           ?? null;
        $dateEnd             = $r['date_end']             ?? null;
        $specificMaterial    = $r['specific_material']    ?? 'text';
        $dimensions          = $r['dimensions']           ?? null;
        $colorMode           = $r['color_mode']           ?? null;
        $photographer        = $r['photographer']         ?? null;
        $publisher           = $r['publisher']            ?? null;
        $collectionName      = $r['collection_name']      ?? null;
        $localClassification = $r['local_classification'] ?? null;

        // 23 params: 5s + 2i + 16s = 'sssssiissssssssssssssss' (23 chars)
        $stmt->bind_param(
            'sssssiissssssssssssssss',
            $r['reference_code'],
            $r['institution_code'],
            $r['level'],
            $formalTitle,
            $r['constructed_title'],
            $dateStart,
            $dateEnd,
            $extent,
            $scopeContent,
            $languageCodes,
            $archivalHistory,
            $acquisitionSource,
            $accessConditions,
            $reproductionRules,
            $relatedUnits,
            $findingAids,
            $specificMaterial,
            $dimensions,
            $colorMode,
            $photographer,
            $publisher,
            $collectionName,
            $localClassification
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] upsert exec failed: ' . $stmt->error);
            $stmt->close();
            return ['action' => 'failed', 'id' => 0];
        }
        // mysqli affected_rows: 1=inserted, 2=updated, 0=no-change.
        $affected = $stmt->affected_rows;
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        $action = $affected === 1 ? 'inserted' : 'updated';

        if ($insertId === 0) {
            // ON DUPLICATE KEY UPDATE matched — look up id by the natural key.
            $sel = $this->db->prepare(
                'SELECT id FROM archival_units
                  WHERE institution_code = ? AND reference_code = ?
                  LIMIT 1'
            );
            if ($sel !== false) {
                $sel->bind_param('ss', $r['institution_code'], $r['reference_code']);
                $sel->execute();
                $rs = $sel->get_result();
                if ($rs instanceof \mysqli_result) {
                    $row = $rs->fetch_assoc();
                    if (is_array($row)) {
                        $insertId = (int) $row['id'];
                    }
                }
                $sel->close();
            }
        }

        return ['action' => $action, 'id' => $insertId];
    }

    /**
     * Look up an existing authority_record by (type, authorised_form).
     * Returns its id or null. Used by the authority-import path to skip
     * exact duplicates so re-importing the same MARCXML is idempotent.
     */
    private function findAuthorityByName(string $type, string $authorisedForm): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM authority_records
              WHERE type = ? AND authorised_form = ? AND deleted_at IS NULL
              LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ss', $type, $authorisedForm);
        $stmt->execute();
        $rs = $stmt->get_result();
        $id = null;
        if ($rs instanceof \mysqli_result) {
            $row = $rs->fetch_assoc();
            if (is_array($row)) {
                $id = (int) $row['id'];
            }
        }
        $stmt->close();
        return $id;
    }

    /**
     * INSERT an imported authority_record and return its id, or 0 on failure.
     * Mirrors authorityStoreAction() but takes the parsed MARC payload
     * instead of a request body.
     *
     * @param array<string, mixed> $a
     */
    private function insertImportedAuthority(array $a): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO authority_records
                (type, authorised_form, parallel_forms, other_forms, identifiers,
                 dates_of_existence, history, places, legal_status, functions,
                 mandates, internal_structure, general_context)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] authority import prepare failed: ' . $this->db->error);
            return 0;
        }
        $parallel        = $a['parallel_forms']     ?? null;
        $other           = $a['other_forms']        ?? null;
        $identifiers     = $a['identifiers']        ?? null;
        $dates           = $a['dates_of_existence'] ?? null;
        $history         = $a['history']            ?? null;
        $places          = $a['places']             ?? null;
        $legalStatus     = $a['legal_status']       ?? null;
        $functions       = $a['functions']          ?? null;
        $mandates        = $a['mandates']           ?? null;
        $internalStruct  = $a['internal_structure'] ?? null;
        $generalContext  = $a['general_context']    ?? null;

        $stmt->bind_param(
            'sssssssssssss',
            $a['type'],
            $a['authorised_form'],
            $parallel,
            $other,
            $identifiers,
            $dates,
            $history,
            $places,
            $legalStatus,
            $functions,
            $mandates,
            $internalStruct,
            $generalContext
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] authority import INSERT failed: ' . $stmt->error);
            $stmt->close();
            return 0;
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Compose a ResponseInterface carrying MARCXML.
     */
    private function xmlResponse(ResponseInterface $response, string $xml, string $filename): ResponseInterface
    {
        $response = $response
            ->withHeader('Content-Type', 'application/marcxml+xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->getBody()->write($xml);
        return $response;
    }

    // ── Phase 3 — unified cross-entity search ──────────────────────────

    /**
     * GET /admin/archives/search?q=... — cross-entity search.
     *
     * Hits three sources in a single request:
     *   1. archival_units (FULLTEXT on title + scope_content + archival_history)
     *   2. authority_records (FULLTEXT on authorised_form + history + functions)
     *   3. autori (FULLTEXT on nome) — only when reconciled to an authority
     *      via autori_authority_link, so bibliographic hits without an
     *      ISAAR counterpart don't flood the view (use /admin/autori for those).
     *
     * FULLTEXT NATURAL LANGUAGE mode gives us relevance ranking for free;
     * we cap each source at 50 rows. Empty query renders the empty form.
     */
    public function unifiedSearchAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $results = [
            'archival_units'    => [],
            'authority_records' => [],
            'linked_autori'     => [],
        ];

        if ($q !== '' && strlen($q) >= 2) {
            $results['archival_units']    = $this->searchArchivalUnits($q, 50);
            $results['authority_records'] = $this->searchAuthorityRecords($q, 50);
            $results['linked_autori']     = $this->searchReconciledAutori($q, 25);
        }

        return $this->renderView($response, 'search', [
            'q'       => $q,
            'results' => $results,
        ]);
    }

    /**
     * GET /admin/archives/api/authorities/search?q=... — JSON type-ahead
     * for the authority attach widget on archival_units show.php.
     * Returns up to 25 rows matching `authorised_form` by LIKE-prefix or
     * FULLTEXT relevance (whichever yields hits). The FULLTEXT index has
     * an innodb_ft_min_token_size (default 3), so queries of 1-2 chars
     * OR short prefixes like "St" would otherwise return nothing — we
     * always run a LIKE pass and merge the results.
     */
    public function authorityTypeaheadAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $rows = [];
        if ($q !== '') {
            $rows = $this->searchAuthorityRecordsForTypeahead($q, 25);
        }
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode([
            'query'   => $q,
            'results' => $rows,
        ]) ?: '[]');
        return $response;
    }

    /**
     * Type-ahead friendly search: LIKE-prefix first (always produces hits
     * for short queries that FULLTEXT would ignore below min_token_size),
     * then top up with FULLTEXT relevance matches when there is budget
     * left. De-duplicates by id, preserves LIKE-prefix order first.
     *
     * @return list<array<string, mixed>>
     */
    private function searchAuthorityRecordsForTypeahead(string $q, int $limit): array
    {
        $rows = [];
        $seen = [];

        // Pass 1 — LIKE prefix on authorised_form. Escape `%` and `_` in
        // the user query so literal wildcards don't leak through.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $likePattern = $escaped . '%';
        $stmt = $this->db->prepare(
            "SELECT id, type, authorised_form, dates_of_existence
               FROM authority_records
              WHERE deleted_at IS NULL
                AND authorised_form LIKE ?
              ORDER BY authorised_form ASC
              LIMIT ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $likePattern, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) {
                    $rows[] = $r;
                    $seen[(int) $r['id']] = true;
                }
                $result->free();
            }
            $stmt->close();
        }

        // Pass 2 — FULLTEXT for any remaining budget. Only runs when the
        // query is long enough to have a chance of matching.
        $remaining = $limit - count($rows);
        if ($remaining > 0 && strlen($q) >= 3) {
            foreach ($this->searchAuthorityRecords($q, $remaining + count($seen)) as $r) {
                $rid = (int) $r['id'];
                if (isset($seen[$rid])) {
                    continue;
                }
                // Strip the `score` column — typeahead clients don't need it.
                unset($r['score']);
                $rows[] = $r;
                $seen[$rid] = true;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * FULLTEXT search against archival_units. Strips non-deleted rows,
     * orders by relevance, caps at $limit.
     *
     * @return list<array<string, mixed>>
     */
    private function searchArchivalUnits(string $q, int $limit): array
    {
        $rows = [];
        $seen = [];

        // Pass 1 — LIKE on reference_code. Short codes ("IT-MI-001", "1943")
        // are below MySQL's ft_min_word_len threshold and would never surface
        // in a FULLTEXT query, so we always probe reference_code with LIKE first.
        $pattern = '%' . $q . '%';
        $stmt = $this->db->prepare(
            'SELECT id, reference_code, level, constructed_title, formal_title,
                    date_start, date_end, extent
               FROM archival_units
              WHERE deleted_at IS NULL
                AND reference_code LIKE ?
              ORDER BY reference_code ASC
              LIMIT ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $pattern, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) {
                    $rows[] = $r;
                    $seen[(int) $r['id']] = true;
                }
                $result->free();
            }
            $stmt->close();
        }

        // Pass 2 — FULLTEXT for title / scope / history (skips words < 3 chars).
        $remaining = $limit - count($rows);
        if ($remaining > 0 && strlen($q) >= 3) {
            $stmt = $this->db->prepare(
                'SELECT id, reference_code, level, constructed_title, formal_title,
                        date_start, date_end, extent,
                        MATCH(formal_title, constructed_title, scope_content, archival_history)
                            AGAINST (? IN NATURAL LANGUAGE MODE) AS score
                   FROM archival_units
                  WHERE deleted_at IS NULL
                    AND MATCH(formal_title, constructed_title, scope_content, archival_history)
                            AGAINST (? IN NATURAL LANGUAGE MODE)
                  ORDER BY score DESC
                  LIMIT ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ssi', $q, $q, $remaining);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) {
                        $rid = (int) $r['id'];
                        if (!isset($seen[$rid])) {
                            $rows[] = $r;
                            $seen[$rid] = true;
                            if (count($rows) >= $limit) {
                                break;
                            }
                        }
                    }
                    $result->free();
                }
                $stmt->close();
            }
        }

        return $rows;
    }

    /**
     * FULLTEXT search against authority_records.
     *
     * @return list<array<string, mixed>>
     */
    private function searchAuthorityRecords(string $q, int $limit): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT id, type, authorised_form, dates_of_existence,
                    MATCH(authorised_form, parallel_forms, history, functions)
                        AGAINST (? IN NATURAL LANGUAGE MODE) AS score
               FROM authority_records
              WHERE deleted_at IS NULL
                AND MATCH(authorised_form, parallel_forms, history, functions)
                        AGAINST (? IN NATURAL LANGUAGE MODE)
              ORDER BY score DESC
              LIMIT ?'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('ssi', $q, $q, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) { $rows[] = $r; }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * FULLTEXT search against `autori.nome` — scoped to rows that are
     * reconciled to an authority_record so non-archival authors don't
     * flood the cross-entity view (they're reachable via /admin/autori).
     *
     * @return list<array<string, mixed>>
     */
    private function searchReconciledAutori(string $q, int $limit): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT a.id, a.nome, aal.authority_id,
                    ar.authorised_form, ar.type AS authority_type,
                    (SELECT COUNT(*) FROM libri_autori la WHERE la.autore_id = a.id) AS book_count,
                    MATCH(a.nome) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
               FROM autori a
               JOIN autori_authority_link aal ON aal.autori_id = a.id
               JOIN authority_records ar ON ar.id = aal.authority_id AND ar.deleted_at IS NULL
              WHERE MATCH(a.nome) AGAINST (? IN NATURAL LANGUAGE MODE)
              ORDER BY score DESC
              LIMIT ?'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('ssi', $q, $q, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) { $rows[] = $r; }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Extract + normalise the authority form payload. Covers both the
     * phase-2 minimum fields (type, authorised_form, dates_of_existence,
     * history, functions) and the phase-2b extensions (parallel_forms,
     * other_forms, identifiers, places, legal_status, mandates,
     * internal_structure, general_context).
     *
     * @return array<string, string>
     */
    private function extractAuthorityPayload(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $keys = [
            'type', 'authorised_form',
            'parallel_forms', 'other_forms', 'identifiers',
            'dates_of_existence', 'history', 'places', 'legal_status',
            'functions', 'mandates', 'internal_structure', 'general_context',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = trim((string) ($body[$k] ?? ''));
        }
        return $out;
    }

    /**
     * Convert empty strings to null for the nullable text columns so
     * MySQL doesn't persist empty strings (which would defeat COALESCE).
     * The required columns (type, authorised_form) are NOT in this set —
     * they pass through as-is.
     *
     * @param array<string, string> $values
     * @return array<string, string|null>
     */
    private function nullableAuthorityParams(array $values): array
    {
        $out = [];
        $fields = [
            'parallel_forms', 'other_forms', 'identifiers',
            'dates_of_existence', 'history', 'places', 'legal_status',
            'functions', 'mandates', 'internal_structure', 'general_context',
        ];
        foreach ($fields as $f) {
            $v = $values[$f] ?? '';
            $out[$f] = $v !== '' ? $v : null;
        }
        return $out;
    }

    /**
     * Validate a payload for authority insert/update.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validateAuthority(array $values): array
    {
        $errors = [];
        $type = (string) ($values['type'] ?? '');
        if (!array_key_exists($type, self::AUTHORITY_TYPES)) {
            $errors['type'] = 'Type must be one of person/corporate/family (ISAAR 5.1.1).';
        }
        $name = (string) ($values['authorised_form'] ?? '');
        if ($name === '') {
            $errors['authorised_form'] = 'Authorised form is required (ISAAR 5.1.2).';
        } elseif (strlen($name) > 500) {
            $errors['authorised_form'] = 'Authorised form must be 500 characters or fewer.';
        }
        if (isset($values['dates_of_existence']) && strlen((string) $values['dates_of_existence']) > 255) {
            $errors['dates_of_existence'] = 'Dates of existence must be 255 characters or fewer.';
        }
        return $errors;
    }

    /**
     * Fetch one non-deleted archival_unit by id, or null.
     *
     * @return array<string, mixed>|null
     */
    private function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] findById prepare failed: ' . $this->db->error);
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * Check if setting $proposedParentId as the parent of $childId would
     * introduce a cycle. Walks up from the proposed parent via parent_id
     * and returns true if $childId appears in the chain. Capped at 100
     * hops to harden against pathological (pre-existing) cycles in the
     * data — we must not loop forever on corrupt rows.
     *
     * Assumes $childId !== $proposedParentId (direct-self case is handled
     * by the caller, which is cheaper than a query).
     */
    private function parentWouldCreateCycle(int $childId, int $proposedParentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT parent_id FROM archival_units WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] parentWouldCreateCycle prepare failed: ' . $this->db->error);
            // Fail closed — treat as cycle so the edit is rejected rather
            // than letting a potentially-corrupt row through silently.
            return true;
        }
        $current = $proposedParentId;
        $visited = [];
        for ($i = 0; $i < 100; $i++) {
            if ($current === $childId) {
                $stmt->close();
                return true;
            }
            if (isset($visited[$current])) {
                // Pre-existing cycle in the data (not one we would create).
                // Break out and let the edit proceed; surfacing this here
                // would block unrelated edits on corrupt rows.
                break;
            }
            $visited[$current] = true;
            $stmt->bind_param('i', $current);
            if (!$stmt->execute()) {
                break;
            }
            $res = $stmt->get_result();
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            if (!is_array($row) || $row['parent_id'] === null) {
                break;
            }
            $current = (int) $row['parent_id'];
        }
        $stmt->close();
        return false;
    }

    /**
     * Render a 404 for a missing/deleted archival_unit id.
     */
    private function renderNotFound(ResponseInterface $response, int $id): ResponseInterface
    {
        $response->getBody()->write(
            '<h1>404 — Archival unit ' . htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') . ' not found</h1>'
        );
        return $response->withStatus(404);
    }

    /**
     * Validate a payload for insert or update. Returns map of field → error message,
     * empty map on success. Pass $excludeId when updating so we don't flag the
     * row's own reference_code as a duplicate against itself.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validateArchivalUnit(array &$values, ?int $excludeId = null): array
    {
        $errors = [];

        $ref = (string) ($values['reference_code'] ?? '');
        if ($ref === '') {
            $errors['reference_code'] = 'Reference code is required (ISAD(G) 3.1.1).';
        } elseif (strlen($ref) > 64) {
            $errors['reference_code'] = 'Reference code must be 64 characters or fewer.';
        }

        $level = (string) ($values['level'] ?? '');
        if (!array_key_exists($level, self::LEVELS)) {
            $errors['level'] = 'Level must be one of fonds/series/file/item (ISAD(G) 3.1.4).';
        }

        $title = (string) ($values['constructed_title'] ?? '');
        if ($title === '') {
            $errors['constructed_title'] = 'Title is required (ISAD(G) 3.1.2).';
        } elseif (strlen($title) > 500) {
            $errors['constructed_title'] = 'Title must be 500 characters or fewer.';
        }

        foreach (['date_start', 'date_end'] as $dateField) {
            $v = $values[$dateField] ?? null;
            if ($v !== null && ($v < -32768 || $v > 32767)) {
                $errors[$dateField] = 'Year out of range (SMALLINT -32768..32767).';
            }
        }

        if (isset($values['date_start'], $values['date_end'])
            && is_int($values['date_start']) && is_int($values['date_end'])
            && $values['date_end'] < $values['date_start']
        ) {
            $errors['date_end'] = 'End year cannot precede start year.';
        }

        if (!empty($values['parent_id']) && is_int($values['parent_id'])) {
            $check = $this->db->prepare(
                'SELECT id FROM archival_units WHERE id = ? AND deleted_at IS NULL'
            );
            if ($check !== false) {
                $parentId = $values['parent_id'];
                $check->bind_param('i', $parentId);
                $check->execute();
                $res = $check->get_result();
                if ($res === false || $res->num_rows === 0) {
                    $errors['parent_id'] = 'Parent archival unit not found or deleted.';
                }
                $check->close();
            }
        }

        foreach ([
            'iiif_manifest_url'    => 2000,
            'rights_statement_url' => 500,
        ] as $field => $maxLen) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[$field] = 'Inserire un URL assoluto valido.';
            } elseif (strlen($value) > $maxLen) {
                $errors[$field] = 'Valore troppo lungo (max ' . $maxLen . ' caratteri).';
            }
        }

        $ark = trim((string) ($values['ark_identifier'] ?? ''));
        if ($ark !== '' && preg_match('#^https?://[^/]+/(ark:/.+)$#i', $ark, $m) === 1) {
            $ark = $m[1];
            $values['ark_identifier'] = $ark;
        }
        if (strlen($ark) > 255) {
            $errors['ark_identifier'] = 'ARK identifier troppo lungo (max 255 caratteri).';
        } elseif ($ark !== '' && preg_match('#^ark:/#i', $ark) !== 1) {
            $errors['ark_identifier'] = "Inserire un identificatore ARK valido nel formato ark:/... (es. ark:/12345/abc123).";
        } elseif ($ark !== '') {
            $sql = 'SELECT id FROM archival_units WHERE ark_identifier = ?';
            if ($excludeId !== null) {
                $sql .= ' AND id != ?';
            }
            $arkCheck = $this->db->prepare($sql);
            if ($arkCheck !== false) {
                if ($excludeId !== null) {
                    $arkCheck->bind_param('si', $ark, $excludeId);
                } else {
                    $arkCheck->bind_param('s', $ark);
                }
                $arkCheck->execute();
                $res = $arkCheck->get_result();
                if ($res !== false && $res->num_rows > 0) {
                    $errors['ark_identifier'] = 'ARK identifier già utilizzato da un altro record.';
                }
                $arkCheck->close();
            }
        }

        if (strlen(trim((string) ($values['version_note'] ?? ''))) > 500) {
            $errors['version_note'] = 'Nota di versione troppo lunga (max 500 caratteri).';
        }

        return $errors;
    }

    /**
     * Render a plugin view with the core layout wrapper.
     *
     * @param array<string, mixed> $data
     */
    private function renderView(ResponseInterface $response, string $view, array $data): ResponseInterface
    {
        $viewFile = __DIR__ . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            SecureLogger::error('[Archives] view not found: ' . $viewFile);
            $response->getBody()->write('Archives view missing: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8'));
            return $response->withStatus(500);
        }

        // Extract view data into local variables expected by the view partials.
        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        // Reuse the core admin layout for consistent chrome (sidebar, header).
        ob_start();
        require __DIR__ . '/../../../app/Views/layout.php';
        $html = (string) ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Returns the list of hooks this plugin *will* register once the feature
     * set ships. Exposed as a pure data array so the skeleton can be audited
     * without side-effects, and so a future `registerHooks()` can simply
     * iterate this list and call Hooks::add(...) per entry.
     *
     * Shape: [hook_name, callback_method, priority]
     *
     * @return list<array{hook: string, method: string, priority: int, description: string}>
     */
    public function plannedHooks(): array
    {
        return [
            [
                'hook'        => 'search.unified.sources',
                'method'      => 'addArchivalSources',
                'priority'    => 10,
                'description' => 'Contribute archival_units + authority_records to unified search',
            ],
            [
                'hook'        => 'admin.menu.render',
                'method'      => 'addAdminMenu',
                'priority'    => 10,
                'description' => 'Add "Archivi" section to the admin sidebar',
            ],
            [
                'hook'        => 'libri.authority.resolve',
                'method'      => 'resolveAuthority',
                'priority'    => 10,
                'description' => 'Share authority_records with the legacy `libri.autori` table',
            ],
        ];
    }

    // ── Phase 5 — Interoperability: Dublin Core, EAD3, OAI-PMH ────────────

    // ── IIIF Presentation API 3.0 ────────────────────────────────────────────

    /**
     * GET /admin/archives/{id}/manifest.json and /archives/{id}/manifest.json
     *
     * Returns a IIIF Presentation API 3.0 manifest for one archival_unit.
     * If the unit has a stored `iiif_manifest_url` pointing to an external
     * IIIF server, the manifest includes a `seeAlso` link to it. Otherwise
     * a Pinakes-native manifest is generated from available metadata and any
     * locally stored cover image (`cover_image_path`).
     *
     * Consumed by Mirador, Universal Viewer, Cantaloupe, and aggregators that
     * understand IIIF Presentation 3.0 (e.g. Europeana, British Library).
     */
    public function iiifManifestAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            $response->getBody()->write(json_encode(['error' => 'db_error'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            $response->getBody()->write(json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $baseUrl    = absoluteUrl('');
        $base       = rtrim($baseUrl, '/');
        $manifestId = $base . '/archives/' . $id . '/manifest.json';
        $lang       = !empty($row['language_codes']) ? (preg_split('/[,;\s]+/', (string) $row['language_codes']) ?: ['it'])[0] : 'it';
        $title      = (string) ($row['constructed_title'] ?? $row['formal_title'] ?? 'Untitled');
        $institution = (string) ($row['institution_code'] ?? 'PINAKES');

        $metadata = [];
        $addMeta  = static function (string $label, mixed $value) use (&$metadata): void {
            if ($value === null || $value === '') {
                return;
            }
            $metadata[] = [
                'label' => ['en' => [$label]],
                'value' => ['none' => [(string) $value]],
            ];
        };
        $addMeta('Reference Code', $row['reference_code']);
        if (!empty($row['ark_identifier'])) {
            $addMeta('Identifier (ARK)', $row['ark_identifier']);
        }
        $addMeta('Level',          $row['level']);
        $dateStr = trim(($row['date_start'] ?? '') . ($row['date_end'] ? '–' . $row['date_end'] : ''), '–');
        $addMeta('Date',           $dateStr);
        $addMeta('Extent',         $row['extent']);
        $addMeta('Language',       $row['language_codes']);
        $addMeta('Institution',    $institution);
        if (!empty($row['version_note'])) {
            $addMeta('Version Note', $row['version_note']);
        }

        // Authority records — creator/subject names + external authority URIs
        $authorities = $this->iiifFetchAuthoritiesWithRefs($id);
        foreach ($authorities as $auth) {
            $roleLabel = match ((string) $auth['role']) {
                'creator'   => 'Creator',
                'recipient' => 'Recipient',
                'custodian' => 'Custodian',
                'subject'   => 'Subject',
                default     => 'Associated Name',
            };
            $addMeta($roleLabel, $auth['authorised_form']);

            if (!empty($auth['external_refs'])) {
                $refs = array_filter(array_map('trim', explode("\n", (string) $auth['external_refs'])));
                foreach ($refs as $ref) {
                    if (str_contains($ref, 'viaf.org')) {
                        $addMeta('VIAF', $ref);
                    } elseif (str_contains($ref, 'wikidata.org')) {
                        $addMeta('Wikidata', $ref);
                    }
                }
            }
        }

        $manifest = [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id'       => $manifestId,
            'type'     => 'Manifest',
            'label'    => [$lang => [$title]],
            'metadata' => $metadata,
        ];

        if (!empty($row['scope_content'])) {
            $manifest['summary'] = [$lang => [(string) $row['scope_content']]];
        }

        // requiredStatement: human-readable attribution displayed by every viewer
        $manifest['requiredStatement'] = [
            'label' => ['en' => ['Attribution']],
            'value' => ['none' => [$institution]],
        ];

        // provider: machine-readable Agent (institution homepage)
        $manifest['provider'] = [[
            'id'       => $base,
            'type'     => 'Agent',
            'label'    => ['none' => [$institution]],
            'homepage' => [[
                'id'     => $base,
                'type'   => 'Text',
                'label'  => ['none' => [$institution]],
                'format' => 'text/html',
            ]],
        ]];

        // rights: IIIF rights URI (e.g. https://rightsstatements.org/vocab/InC/1.0/)
        if (!empty($row['rights_statement_url'])) {
            $manifest['rights'] = (string) $row['rights_statement_url'];
        }

        // behavior: viewer UX hint derived from specific_material
        $behavior = match ((string) ($row['specific_material'] ?? 'text')) {
            'text', 'microform', 'electronic' => 'paged',
            'photograph', 'poster', 'postcard',
            'drawing', 'picture', 'object',
            'map'                              => 'individuals',
            'mixed'                            => 'unordered',
            default                            => null,
        };
        if ($behavior !== null) {
            $manifest['behavior'] = [$behavior];
        }

        // partOf: link to parent Collection (if unit has a parent)
        if (!empty($row['parent_id'])) {
            $manifest['partOf'] = [[
                'id'   => $base . '/archives/' . (int) $row['parent_id'] . '/collection.json',
                'type' => 'Collection',
            ]];
        } else {
            $manifest['partOf'] = [[
                'id'   => $base . '/archives/collection.json',
                'type' => 'Collection',
            ]];
        }

        // seeAlso: other serialisations (DC, EAD3, METS, OAI-PMH, RiC-O, external IIIF)
        $manifest['seeAlso'] = [
            ['id'     => $base . '/archives/' . $id . '/dc.xml',
             'type'   => 'Dataset', 'format' => 'application/xml',
             'label'  => ['en' => ['Dublin Core (OAI-DC)']]],
            ['id'     => $base . '/archives/' . $id . '/ead.xml',
             'type'   => 'Dataset', 'format' => 'application/xml',
             'profile' => 'http://ead3.archivists.org/schema/',
             'label'  => ['en' => ['EAD3 finding aid']]],
            ['id'     => $base . '/archives/' . $id . '/mets.xml',
             'type'   => 'Dataset', 'format' => 'application/xml',
             'profile' => 'http://www.loc.gov/METS/',
             'label'  => ['en' => ['METS package']]],
            ['id'     => $base . '/archives/' . $id . '/ric.json',
             'type'   => 'Dataset', 'format' => 'application/ld+json',
             'profile' => 'https://www.ica.org/standards/RiC/ontology',
             'label'  => ['en' => ['RiC-O (Records in Contexts)']]],
            ['id'     => $base . '/archives/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:pinakes:archival_unit:' . $id,
             'type'   => 'Dataset', 'format' => 'text/xml',
             'label'  => ['en' => ['OAI-PMH record']]],
        ];
        if (!empty($row['ark_identifier'])) {
            $manifest['seeAlso'][] = [
                'id'    => 'https://n2t.net/' . $row['ark_identifier'],
                'type'  => 'Text',
                'label' => ['en' => ['ARK persistent identifier']],
            ];
        }
        if (!empty($row['iiif_manifest_url'])) {
            $manifest['seeAlso'][] = [
                'id'     => (string) $row['iiif_manifest_url'],
                'type'   => 'Manifest',
                'format' => 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"',
                'label'  => ['en' => ['Full IIIF manifest (external server)']],
            ];
        }

        // Canvas items: one canvas per locally stored image, with real dimensions.
        // Skip canvas entirely if the file no longer exists — avoids 404 references.
        $items = [];
        if (!empty($row['cover_image_path'])) {
            $coverPath = (string) $row['cover_image_path'];
            $fsPath    = __DIR__ . '/../../../public' . $coverPath;
            if (is_file($fsPath)) {
                $imageUrl  = $base . '/' . ltrim($coverPath, '/');
                $imgSize   = @getimagesize($fsPath);
                $imgWidth  = ($imgSize !== false && $imgSize[0] > 0) ? $imgSize[0] : 1500;
                $imgHeight = ($imgSize !== false && $imgSize[1] > 0) ? $imgSize[1] : 2000;
                $mime      = ($imgSize !== false) ? $imgSize['mime'] : 'image/jpeg';

                $canvasId = $manifestId . '/canvas/1';
                $items[]  = [
                    'id'     => $canvasId,
                    'type'   => 'Canvas',
                    'label'  => ['none' => ['1']],
                    'width'  => $imgWidth,
                    'height' => $imgHeight,
                    'items'  => [[
                        'id'    => $canvasId . '/page',
                        'type'  => 'AnnotationPage',
                        'items' => [[
                            'id'         => $canvasId . '/annotation/1',
                            'type'       => 'Annotation',
                            'motivation' => 'painting',
                            'body'       => [
                                'id'     => $imageUrl,
                                'type'   => 'Image',
                                'format' => $mime,
                                'width'  => $imgWidth,
                                'height' => $imgHeight,
                            ],
                            'target' => $canvasId,
                        ]],
                    ]],
                ];
            }
        }
        // Additional image files from archival_unit_files → extra Canvases.
        // Non-image files (PDF, audio, video) → rendering[] array.
        $unitFiles   = $this->fetchUnitFiles($id);
        $canvasIndex = count($items) + 1;
        $rendering   = [];
        foreach ($unitFiles as $uf) {
            $fileMime = (string) $uf['file_mime'];
            $fsPath   = __DIR__ . '/../../../public' . (string) $uf['file_path'];
            if (!is_file($fsPath)) {
                continue;
            }
            $fileUrl = $base . '/' . ltrim((string) $uf['file_path'], '/');
            $label   = (string) ($uf['original_filename'] ?: basename((string) $uf['file_path']));
            if (str_starts_with($fileMime, 'image/')) {
                $imgSize      = @getimagesize($fsPath);
                $imgW         = ($imgSize !== false && $imgSize[0] > 0) ? $imgSize[0] : 1500;
                $imgH         = ($imgSize !== false && $imgSize[1] > 0) ? $imgSize[1] : 2000;
                $detectedMime = ($imgSize !== false) ? $imgSize['mime'] : $fileMime;
                $canvasId     = $manifestId . '/canvas/' . $canvasIndex;
                $items[]      = [
                    'id'     => $canvasId,
                    'type'   => 'Canvas',
                    'label'  => ['none' => [$label]],
                    'width'  => $imgW,
                    'height' => $imgH,
                    'items'  => [[
                        'id'    => $canvasId . '/page',
                        'type'  => 'AnnotationPage',
                        'items' => [[
                            'id'         => $canvasId . '/annotation/1',
                            'type'       => 'Annotation',
                            'motivation' => 'painting',
                            'body'       => [
                                'id'     => $fileUrl,
                                'type'   => 'Image',
                                'format' => $detectedMime,
                                'width'  => $imgW,
                                'height' => $imgH,
                            ],
                            'target' => $canvasId,
                        ]],
                    ]],
                ];
                $canvasIndex++;
            } else {
                $iiifType = match (true) {
                    str_starts_with($fileMime, 'audio/') => 'Sound',
                    str_starts_with($fileMime, 'video/') => 'Video',
                    default                              => 'Text',
                };
                $rendering[] = [
                    'id'     => $fileUrl,
                    'type'   => $iiifType,
                    'label'  => ['none' => [$label]],
                    'format' => $fileMime,
                ];
            }
        }
        if (!empty($rendering)) {
            $manifest['rendering'] = $rendering;
        }

        // IIIF 3.0 §3.3: items must have cardinality ≥ 1.
        // When no local image is available, serve a minimal placeholder Canvas
        // so clients receive a valid (if unpainted) manifest.
        if (empty($items)) {
            $canvasId = $manifestId . '/canvas/placeholder';
            $items[]  = [
                'id'     => $canvasId,
                'type'   => 'Canvas',
                'label'  => ['none' => ['[No digital image available]']],
                'width'  => 1,
                'height' => 1,
            ];
        }
        $manifest['items'] = $items;

        // structures: nested IIIF Range hierarchy (§1.1)
        // Each ancestor becomes an outer Range wrapping the next;
        // the innermost Range holds the canvas items directly.
        $ancestors = $this->iiifBuildAncestorChain((int) $row['id'], (int) ($row['parent_id'] ?? 0));
        $structures = $this->iiifBuildNestedStructures($ancestors, $items, $manifestId, $lang);
        if (!empty($structures)) {
            $manifest['structures'] = $structures;
        }

        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * Walk up the parent_id chain to build an ancestor list (root first).
     * Returns [{id, title}, ...] ending with the current unit.
     *
     * @return list<array{id:int,title:string}>
     */
    private function iiifBuildAncestorChain(int $leafId, int $parentId): array
    {
        $chain = [];
        $visited = [];
        $currentParent = $parentId;
        while ($currentParent > 0 && !isset($visited[$currentParent])) {
            $visited[$currentParent] = true;
            $s = $this->db->prepare(
                'SELECT id, parent_id, constructed_title, formal_title FROM archival_units WHERE id = ? AND deleted_at IS NULL'
            );
            if ($s === false) {
                break;
            }
            $s->bind_param('i', $currentParent);
            $s->execute();
            $res = $s->get_result();
            $r   = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            $s->close();
            if ($r === null) {
                break;
            }
            array_unshift($chain, [
                'id'    => (int) $r['id'],
                'title' => (string) ($r['constructed_title'] ?? $r['formal_title'] ?? 'Untitled'),
            ]);
            $currentParent = (int) ($r['parent_id'] ?? 0);
        }
        // Append the leaf itself
        $leafTitle = '';
        // FIX F033: add soft-delete guard (cf. CLAUDE.md soft-delete rule)
        $s2 = $this->db->prepare('SELECT constructed_title, formal_title FROM archival_units WHERE id = ? AND deleted_at IS NULL');
        if ($s2 !== false) {
            $s2->bind_param('i', $leafId);
            $s2->execute();
            $res2      = $s2->get_result();
            $r2        = $res2 instanceof \mysqli_result ? $res2->fetch_assoc() : null;
            $s2->close();
            $leafTitle = (string) ($r2['constructed_title'] ?? $r2['formal_title'] ?? 'Untitled');
        }
        $chain[] = ['id' => $leafId, 'title' => $leafTitle];
        return $chain;
    }

    /**
     * Build a nested IIIF Range hierarchy from a root-to-leaf ancestor chain (§1.1).
     *
     * Each ancestor becomes an outer Range wrapping the next; the innermost
     * Range holds references to the manifest's Canvas items directly.
     * Returns a list suitable for manifest['structures'].
     *
     * @param list<array{id:int,title:string}> $ancestors root-to-leaf
     * @param list<array<string,mixed>>        $canvases  canvas items already built
     * @param string                           $manifestId manifest base URL
     * @param string                           $lang       IIIF language code for labels
     * @return list<array<string,mixed>>
     */
    private function iiifBuildNestedStructures(
        array $ancestors,
        array $canvases,
        string $manifestId,
        string $lang
    ): array {
        $innerItems = array_map(
            static fn(array $c) => ['id' => (string) $c['id'], 'type' => 'Canvas'],
            $canvases
        );

        if (empty($innerItems)) {
            return [];
        }

        if (empty($ancestors)) {
            return [[
                'id'    => $manifestId . '#range/r0',
                'type'  => 'Range',
                'label' => ['none' => ['Content']],
                'items' => $innerItems,
            ]];
        }

        // Build from inside out: deepest ancestor wraps canvases,
        // each outer ancestor wraps the next inner Range as its sole item.
        $depth   = count($ancestors);
        $current = [
            'id'    => $manifestId . '#range/r' . ($depth - 1),
            'type'  => 'Range',
            'label' => [$lang => [$ancestors[$depth - 1]['title']]],
            'items' => $innerItems,
        ];
        for ($i = $depth - 2; $i >= 0; $i--) {
            $current = [
                'id'    => $manifestId . '#range/r' . $i,
                'type'  => 'Range',
                'label' => [$lang => [$ancestors[$i]['title']]],
                'items' => [$current],
            ];
        }

        return [$current];
    }

    /**
     * Fetch authority records linked to an archival unit, including external_refs
     * for VIAF/Wikidata URIs. Used only for IIIF manifest metadata enrichment.
     *
     * @return list<array<string,mixed>>
     */
    private function iiifFetchAuthoritiesWithRefs(int $archivalUnitId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT ar.type, ar.authorised_form, ar.external_refs, aua.role
               FROM archival_unit_authority aua
               JOIN authority_records ar ON ar.id = aua.authority_id AND ar.deleted_at IS NULL
              WHERE aua.archival_unit_id = ?
              ORDER BY ar.authorised_form'
        );
        if ($stmt === false) {
            return $rows;
        }
        $stmt->bind_param('i', $archivalUnitId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * GET /archives/collection.json         — root IIIF Collection (all top-level fondi)
     * GET /archives/{id}/collection.json    — sub-Collection (direct children of a unit)
     *
     * A IIIF Collection is a list of Manifest references. External viewers
     * (Mirador, Universal Viewer) can load a Collection and navigate the tree.
     */
    public function iiifCollectionAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?int $id = null
    ): ResponseInterface {
        $base = rtrim(absoluteUrl(''), '/');

        if ($id === null) {
            // Root collection: all non-deleted, top-level units (parent_id IS NULL)
            $collectionId = $base . '/archives/collection.json';
            $label        = ['en' => ['Archives collection'], 'it' => ['Collezione archivistica']];
            $result = $this->db->query(
                "SELECT a.id, a.constructed_title, a.formal_title, a.level,
                        EXISTS(SELECT 1 FROM archival_units c WHERE c.parent_id = a.id AND c.deleted_at IS NULL) AS has_children
                   FROM archival_units a
                  WHERE a.parent_id IS NULL AND a.level = 'fonds' AND a.deleted_at IS NULL
                  ORDER BY a.reference_code"
            );
        } else {
            // Sub-collection: direct children of the given unit
            $collectionId = $base . '/archives/' . $id . '/collection.json';
            $parent = $this->findById($id);
            if ($parent === null) {
                $response->getBody()->write(json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            $label = ['none' => [(string) ($parent['constructed_title'] ?? $parent['formal_title'] ?? 'Untitled')]];
            $stmt  = $this->db->prepare(
                'SELECT a.id, a.constructed_title, a.formal_title, a.level,
                        EXISTS(SELECT 1 FROM archival_units c WHERE c.parent_id = a.id AND c.deleted_at IS NULL) AS has_children
                   FROM archival_units a
                  WHERE a.parent_id = ? AND a.deleted_at IS NULL
                  ORDER BY a.reference_code'
            );
            if ($stmt === false) {
                $response->getBody()->write(json_encode(['error' => 'db_error'], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
        }

        $items = [];
        if ($result instanceof \mysqli_result) {
            while ($child = $result->fetch_assoc()) {
                $childId     = (int) $child['id'];
                $childTitle  = (string) ($child['constructed_title'] ?? $child['formal_title'] ?? 'Untitled');
                // Units with children become sub-Collections; leaf units are Manifests
                $hasChildren = (bool) ($child['has_children'] ?? false);
                $items[] = [
                    'id'    => $hasChildren
                        ? $base . '/archives/' . $childId . '/collection.json'
                        : $base . '/archives/' . $childId . '/manifest.json',
                    'type'  => $hasChildren ? 'Collection' : 'Manifest',
                    'label' => ['none' => [$childTitle]],
                ];
            }
            $result->free();
        }

        $collection = [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id'       => $collectionId,
            'type'     => 'Collection',
            'label'    => $label,
            'items'    => $items,
        ];

        $json = json_encode($collection, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/ld+json;profile="http://iiif.io/api/presentation/3/context.json"')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    // ── RiC-O (Records in Contexts Ontology) JSON-LD ────────────────────────
    //
    // Phase 1 of issue #122: read-only export of archival_units and
    // authority_records as RiC-CM entities. No DB schema changes. The
    // heavy lifting lives in RicJsonLdBuilder (pure, namespaced helper
    // class) so this method stays small and PHPStan-friendly.
    //
    // RiC-O: https://www.ica.org/standards/RiC/ontology

    /**
     * GET /archives/{id}/ric.json — RiC-O JSON-LD for one archival_unit.
     *
     * Public route (no auth). Mirrors the visibility of /archives/{id}/dc.xml.
     */
    public function ricUnitAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        // CodeRabbit #2: distinguish "missing" from "DB error" — both yield
        // null from findById() today, which would 404 a harvester that's
        // actually hitting a database problem. Use the discriminated lookup.
        $lookup = $this->findUnitForRic($id);
        if ($lookup['status'] === 'error') {
            SecureLogger::error('[Archives] ricUnitAction DB error: ' . $lookup['message']);
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }
        if ($lookup['status'] === 'missing') {
            return $this->ricJsonError($response, 404, 'not_found',
                'Archival unit ' . $id . ' does not exist or has been removed.');
        }
        $row = $lookup['row'];

        // CodeRabbit R4: the shared helper fetchAuthoritiesForArchivalUnit
        // silent-degrades on DB error (preserves non-RiC exporter
        // contracts). For RiC we need to surface the failure as a
        // proper 500 — check mysqli error state immediately after the
        // call to detect it. The RiC-only fetchDirectChildren still
        // throws because no non-RiC caller exists.
        try {
            $authorities = $this->fetchAuthoritiesForArchivalUnit($id);
            if ($this->db->errno !== 0) {
                throw new \RuntimeException(
                    '[Archives] fetchAuthoritiesForArchivalUnit failed: ' . $this->db->error
                );
            }
            $children   = $this->fetchDirectChildren($id);
            // RiC-CM Phase 3 (v0.7.9): activities linked to this unit.
            $activities = $this->fetchActivitiesForUnit($id);
        } catch (\RuntimeException $e) {
            SecureLogger::error('[Archives] ricUnitAction secondary fetch failed: ' . $e->getMessage());
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }

        $builder = $this->makeRicBuilder();
        $doc     = $builder->buildUnit($row, $authorities, $children, $activities);

        return $this->ricJsonResponse($response, $doc, $builder->unitIri($id));
    }

    /**
     * GET /archives/collection.ric.json — synthetic RecordSet that
     * aggregates all top-level fonds. Public route.
     */
    public function ricCollectionAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // CodeRabbit #3: an empty 200 on DB failure would look identical to
        // a real "no fonds yet" response — harvesters cannot tell the two
        // apart and would treat a transient outage as repository emptying.
        // Bail with 500 the moment the query returns false.
        $result = $this->db->query(
            "SELECT id, level, constructed_title, formal_title
               FROM archival_units
              WHERE parent_id IS NULL AND deleted_at IS NULL AND level = 'fonds'
              ORDER BY reference_code"
        );
        if (!($result instanceof \mysqli_result)) {
            SecureLogger::error('[Archives] ricCollectionAction DB error: ' . $this->db->error);
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }

        $rootUnits = [];
        while ($r = $result->fetch_assoc()) {
            $rootUnits[] = $r;
        }
        $result->free();

        $builder = $this->makeRicBuilder();
        $doc     = $builder->buildCollection($rootUnits);

        return $this->ricJsonResponse(
            $response,
            $doc,
            rtrim(absoluteUrl(''), '/') . '/archives/collection.ric.json'
        );
    }

    /**
     * GET /archives/agents/{id}/ric.json — RiC-O JSON-LD for one
     * authority_record (Agent). Public route.
     */
    public function ricAgentAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        // CodeRabbit #2: same as ricUnitAction — disambiguate missing vs DB error.
        $lookup = $this->findAuthorityForRic($id);
        if ($lookup['status'] === 'error') {
            SecureLogger::error('[Archives] ricAgentAction DB error: ' . $lookup['message']);
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }
        if ($lookup['status'] === 'missing') {
            return $this->ricJsonError($response, 404, 'not_found',
                'Authority record ' . $id . ' does not exist or has been removed.');
        }
        $auth = $lookup['row'];

        // CodeRabbit R4: fetchArchivalUnitsForAuthority silent-degrades
        // (used also by authorityShowAction admin view) — check mysqli
        // error state to translate failure into 500. collectSameAsForAuthority
        // and fetchAgentRelations are RiC-only and throw directly.
        try {
            $linkedUnits = $this->fetchArchivalUnitsForAuthority($id);
            if ($this->db->errno !== 0) {
                throw new \RuntimeException(
                    '[Archives] fetchArchivalUnitsForAuthority failed: ' . $this->db->error
                );
            }
            $sameAs         = $this->collectSameAsForAuthority($id);
            // RiC-CM Phase 2 (v0.7.8): Agent → Agent relations.
            $agentRelations = $this->fetchAgentRelations($id);
        } catch (\RuntimeException $e) {
            SecureLogger::error('[Archives] ricAgentAction secondary fetch failed: ' . $e->getMessage());
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }

        $builder = $this->makeRicBuilder();
        $doc     = $builder->buildAuthority($auth, $linkedUnits, $sameAs, $agentRelations);

        return $this->ricJsonResponse($response, $doc, $builder->agentIri($id));
    }

    /**
     * GET /archives/activities/{id}/ric.json — RiC-O JSON-LD for one
     * `archive_activities` row (RiC-CM Phase 3 — v0.7.9). Public route.
     */
    public function ricActivityAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $lookup = $this->findActivityForRic($id);
        if ($lookup['status'] === 'error') {
            SecureLogger::error('[Archives] ricActivityAction DB error: ' . $lookup['message']);
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }
        if ($lookup['status'] === 'missing') {
            return $this->ricJsonError($response, 404, 'not_found',
                'Archive activity ' . $id . ' does not exist or has been removed.');
        }
        $row = $lookup['row'];

        try {
            $unitLinks = $this->fetchUnitsForActivity($id);
        } catch (\RuntimeException $e) {
            SecureLogger::error('[Archives] ricActivityAction secondary fetch failed: ' . $e->getMessage());
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }

        $builder = $this->makeRicBuilder();
        $doc     = $builder->buildActivity($row, $unitLinks);
        return $this->ricJsonResponse($response, $doc, $builder->activityIri($id));
    }

    /**
     * GET /archives/activities/ric.json — synthetic RiC-O collection
     * listing all top-level activities (the ones with parent_id IS
     * NULL). Phase 3 (v0.7.9). Public route.
     */
    public function ricActivitiesListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $result = $this->db->query(
            'SELECT id, title, activity_type
               FROM archive_activities
              WHERE parent_id IS NULL AND deleted_at IS NULL
              ORDER BY activity_type, title'
        );
        if (!($result instanceof \mysqli_result)) {
            // archive_activities table may legitimately not exist on
            // installs that activated the plugin pre-0.7.9 and never
            // re-ran ensureSchema. Treat as empty list (200) rather
            // than 500 — the table will appear on the next plugin
            // re-activation or auto-update.
            if ($this->db->errno === 1146) {
                $rows = [];
            } else {
                SecureLogger::error('[Archives] ricActivitiesListAction DB error: ' . $this->db->error);
                return $this->ricJsonError($response, 500, 'persistence_error',
                    'A persistence error prevented the request from being served.');
            }
        } else {
            $rows = [];
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
            $result->free();
        }

        // Use the existing collection builder pattern via the helper —
        // emit a ric:RecordSet-style aggregator pointing at each
        // top-level activity. We avoid teaching the builder a separate
        // method by hand-rolling the document here.
        $builder = $this->makeRicBuilder();
        $parts   = [];
        foreach ($rows as $r) {
            $aid = (int) ($r['id'] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $node = [
                '@id'   => $builder->activityIri($aid),
                '@type' => 'ric:Activity',
            ];
            $title = (string) ($r['title'] ?? '');
            if ($title !== '') {
                $node['rdfs:label'] = $title;
            }
            $aType = (string) ($r['activity_type'] ?? '');
            if ($aType !== '') {
                $node['ric:type'] = $aType;
            }
            $parts[] = $node;
        }
        $base = rtrim(absoluteUrl(''), '/');
        $doc = [
            '@context'         => $builder->context(),
            '@id'              => $base . '/archives/activities/ric.json',
            '@type'            => 'ric:RecordSet',
            'rdfs:label'       => ['@value' => 'Archival activities', '@language' => 'en'],
            'ric:title'        => 'Archival activities',
            'ric:hasOrHadPart' => $parts,
        ];
        return $this->ricJsonResponse($response, $doc, $base . '/archives/activities/ric.json');
    }

    /**
     * Look up an archive_activities row, disambiguating missing rows
     * from DB persistence errors (CodeRabbit R2 pattern, applied to
     * Phase 3 activity lookups).
     *
     * @return array{status:'found',row:array<string,mixed>}|array{status:'missing'}|array{status:'error',message:string}
     */
    private function findActivityForRic(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archive_activities WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            // Table missing on pre-Phase-3 installs is a graceful
            // "not found" — caller emits 404 the same way it would
            // for a deleted row, since the activity catalogue is
            // legitimately empty until ensureSchema() runs again.
            if ($this->db->errno === 1146) {
                return ['status' => 'missing'];
            }
            return ['status' => 'error', 'message' => $this->db->error];
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err];
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err !== '' ? $err : 'get_result returned false'];
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row)
            ? ['status' => 'found', 'row' => $row]
            : ['status' => 'missing'];
    }

    /**
     * RiC-CM Phase 3 (v0.7.9): for one activity, fetch every linked
     * archival unit with the relation's RiC predicate. Skips
     * soft-deleted units at the JOIN. Throws \RuntimeException on
     * persistence error so the caller can 500 cleanly; returns []
     * when the link table is missing (pre-Phase-3 install).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUnitsForActivity(int $activityId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT au.id AS unit_id, au.level, au.constructed_title, au.formal_title,
                    aua.ric_predicate
               FROM archive_unit_activities aua
               JOIN archival_units au ON au.id = aua.unit_id AND au.deleted_at IS NULL
              WHERE aua.activity_id = ?
              ORDER BY aua.ric_predicate, au.reference_code'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return $rows;
            }
            throw new \RuntimeException(
                '[Archives] fetchUnitsForActivity prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('i', $activityId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Archives] fetchUnitsForActivity execute failed: ' . $err);
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * GET /archives/places/{id}/ric.json — RiC-O JSON-LD for one
     * archive_places row (RiC-CM Phase 4 — v0.7.10). Public route.
     */
    public function ricPlaceAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $lookup = $this->findPlaceForRic($id);
        if ($lookup['status'] === 'error') {
            SecureLogger::error('[Archives] ricPlaceAction DB error: ' . $lookup['message']);
            return $this->ricJsonError($response, 500, 'persistence_error',
                'A persistence error prevented the request from being served.');
        }
        if ($lookup['status'] === 'missing') {
            return $this->ricJsonError($response, 404, 'not_found',
                'Archive place ' . $id . ' does not exist or has been removed.');
        }
        $builder = $this->makeRicBuilder();
        $doc     = $builder->buildPlace($lookup['row']);
        return $this->ricJsonResponse($response, $doc, $builder->placeIri($id));
    }

    /**
     * GET /archives/places/ric.json — synthetic ric:RecordSet listing
     * every top-level place (parent_id IS NULL). Phase 4 (v0.7.10).
     */
    public function ricPlacesListAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $result = $this->db->query(
            'SELECT id, name, place_type FROM archive_places
              WHERE parent_id IS NULL AND deleted_at IS NULL
              ORDER BY place_type, name'
        );
        if (!($result instanceof \mysqli_result)) {
            if ($this->db->errno === 1146) {
                $rows = [];
            } else {
                SecureLogger::error('[Archives] ricPlacesListAction DB error: ' . $this->db->error);
                return $this->ricJsonError($response, 500, 'persistence_error',
                    'A persistence error prevented the request from being served.');
            }
        } else {
            $rows = [];
            while ($r = $result->fetch_assoc()) {
                $rows[] = $r;
            }
            $result->free();
        }

        $builder = $this->makeRicBuilder();
        $parts   = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $node = ['@id' => $builder->placeIri($pid), '@type' => 'ric:Place'];
            $name = (string) ($r['name'] ?? '');
            if ($name !== '') { $node['rdfs:label'] = $name; }
            $pt = (string) ($r['place_type'] ?? '');
            if ($pt !== '')   { $node['ric:type']   = $pt; }
            $parts[] = $node;
        }
        $base = rtrim(absoluteUrl(''), '/');
        $doc = [
            '@context'         => $builder->context(),
            '@id'              => $base . '/archives/places/ric.json',
            '@type'            => 'ric:RecordSet',
            'rdfs:label'       => ['@value' => 'Archival places', '@language' => 'en'],
            'ric:title'        => 'Archival places',
            'ric:hasOrHadPart' => $parts,
        ];
        return $this->ricJsonResponse($response, $doc, $base . '/archives/places/ric.json');
    }

    /**
     * Phase 4 (v0.7.10): place lookup with discriminated-union return
     * for consistent 404/500 separation (CodeRabbit R2 pattern).
     *
     * @return array{status:'found',row:array<string,mixed>}|array{status:'missing'}|array{status:'error',message:string}
     */
    private function findPlaceForRic(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archive_places WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return ['status' => 'missing'];  // table not yet created
            }
            return ['status' => 'error', 'message' => $this->db->error];
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err];
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err !== '' ? $err : 'get_result returned false'];
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row)
            ? ['status' => 'found', 'row' => $row]
            : ['status' => 'missing'];
    }

    /**
     * Phase 4 (v0.7.10): polymorphic-relations referential integrity.
     * Returns true when both endpoints (source and target) exist as
     * non-deleted rows in their respective entity tables. Used by
     * the admin form before INSERTing into archive_relations.
     */
    public function validateRelationEndpoints(
        string $sourceType, int $sourceId,
        string $targetType, int $targetId
    ): bool {
        $tableMap = [
            'archival_unit'    => 'archival_units',
            'authority_record' => 'authority_records',
            'archive_activity' => 'archive_activities',
            'archive_place'    => 'archive_places',
        ];
        foreach ([[$sourceType, $sourceId], [$targetType, $targetId]] as [$type, $id]) {
            $table = $tableMap[$type] ?? null;
            if ($table === null || $id <= 0) {
                return false;
            }
            $stmt = $this->db->prepare(
                "SELECT 1 FROM `{$table}` WHERE id = ? AND deleted_at IS NULL LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }
            $result = $stmt->get_result();
            $found  = ($result instanceof \mysqli_result) && $result->fetch_row() !== null;
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            $stmt->close();
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    /**
     * Phase 4 (v0.7.10): fetch every polymorphic relation where the
     * given entity appears as either source or target. Returns rows
     * directly usable by RicJsonLdBuilder::buildRelationNode. The
     * caller passes the canonical entity_type string used in the
     * archive_relations ENUM column.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRelationsForEntity(string $entityType, int $entityId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM archive_relations
              WHERE (source_type = ? AND source_id = ?)
                 OR (target_type = ? AND target_id = ?)
              ORDER BY id'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return $rows;  // pre-Phase-4 install — degrade silently
            }
            throw new \RuntimeException(
                '[Archives] fetchRelationsForEntity prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('sisi', $entityType, $entityId, $entityType, $entityId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException(
                '[Archives] fetchRelationsForEntity execute failed: ' . $err
            );
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * RiC-CM Phase 3 (v0.7.9): for one archival unit, fetch every
     * linked activity with its title + type so buildUnit can embed
     * the ric:relationHasTarget label inline. Same silent-degrade /
     * throw split as fetchUnitsForActivity.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchActivitiesForUnit(int $unitId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT aa.id AS activity_id, aa.title, aa.activity_type,
                    aua.ric_predicate
               FROM archive_unit_activities aua
               JOIN archive_activities aa ON aa.id = aua.activity_id AND aa.deleted_at IS NULL
              WHERE aua.unit_id = ?
              ORDER BY aua.ric_predicate, aa.title'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return $rows;
            }
            throw new \RuntimeException(
                '[Archives] fetchActivitiesForUnit prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('i', $unitId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Archives] fetchActivitiesForUnit execute failed: ' . $err);
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Build a per-request RicJsonLdBuilder configured with the current
     * canonical base URL and the INSTALLATION locale. Kept private so
     * callers cannot bypass the canonical-URL resolution path.
     *
     * CodeRabbit #4: the RiC endpoints are public + cacheable on a fixed
     * URL. Using the per-request locale (which can change with the user
     * session) would make two clients see different bodies for the same
     * URL, corrupting any shared HTTP cache. We pin to the installation
     * locale so the response is byte-deterministic per URL. If a future
     * change re-introduces per-request locale logic, ricJsonResponse()
     * must also re-introduce `Vary: Accept-Language` in the same commit.
     */
    private function makeRicBuilder(): RicJsonLdBuilder
    {
        $base   = rtrim(absoluteUrl(''), '/');
        $locale = (string) (\App\Support\I18n::getInstallationLocale() ?: 'en');
        return new RicJsonLdBuilder($base, $locale);
    }

    /**
     * Emit a JSON-LD response with the appropriate Content-Type,
     * caching, and CORS headers for cross-domain harvesters.
     *
     * @param array<string, mixed> $doc
     */
    private function ricJsonResponse(
        ResponseInterface $response,
        array $doc,
        string $canonicalUrl
    ): ResponseInterface {
        $json = json_encode(
            $doc,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->getBody()->write($json);
        // adamsreview F003: no Vary header. The body is pinned to the
        // installation locale via I18n::getInstallationLocale() (see
        // makeRicBuilder) and is therefore fully URL-deterministic — it
        // never varies with the request's Accept-Language. Emitting
        // `Vary: Accept-Language` would mis-signal cacheability and
        // fragment shared caches per client language while every fragment
        // stored byte-identical bodies, directly defeating
        // `Cache-Control: public, max-age=300`. If a future change
        // re-introduces per-request locale logic, also re-introduce Vary
        // here in the same commit.
        return $response
            ->withHeader('Content-Type', 'application/ld+json; charset=utf-8')
            ->withHeader('Link', '<' . $canonicalUrl . '>; rel="canonical"')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /**
     * RFC 7807 problem-details error envelope for the RiC endpoints.
     * Avoids mixing HTML 404 pages into a Content-Type the harvesters
     * expect to parse as JSON.
     *
     * CodeRabbit R4: the previous Content-Type `application/ld+json`
     * was misleading — the body `{"error":"...","message":"..."}` has
     * no `@context` and therefore is NOT valid JSON-LD. RFC 7807
     * defines `application/problem+json` for exactly this use case
     * (machine-readable HTTP error envelopes). A JSON-LD client
     * receiving a problem+json response can still parse it as plain
     * JSON without mis-applying JSON-LD processing.
     */
    private function ricJsonError(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message
    ): ResponseInterface {
        // RFC 7807 fields: type, title, status, detail. We keep `error`
        // and `message` for backward compatibility with any consumer
        // that started reading the previous payload shape.
        $payload = json_encode(
            [
                'type'    => 'about:blank',
                'title'   => $code,
                'status'  => $status,
                'detail'  => $message,
                'error'   => $code,
                'message' => $message,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->getBody()->write($payload);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/problem+json; charset=utf-8')
            ->withHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * Fetch direct children of an archival_unit (one level only — the
     * full subtree is referenced lazily by clients via the @id of each
     * child).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchDirectChildren(int $parentId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT id, level, constructed_title, formal_title
               FROM archival_units
              WHERE parent_id = ? AND deleted_at IS NULL
              ORDER BY reference_code'
        );
        // adamsreview F006 + CodeRabbit R2: throw on DB failure rather
        // than logging-and-degrading. RiC actions catch and return 500;
        // a silent empty children list would publish an incomplete graph
        // that harvesters mistake for a real structural change.
        if ($stmt === false) {
            throw new \RuntimeException('[Archives] fetchDirectChildren prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $parentId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Archives] fetchDirectChildren execute failed: ' . $err);
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException(
                '[Archives] fetchDirectChildren get_result failed: '
                . ($err !== '' ? $err : 'get_result returned false')
            );
        }
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
        return $rows;
    }

    /**
     * Look up an archival_unit row by id, distinguishing "not found"
     * from "DB error". Used by the RiC endpoints so harvesters can tell
     * a real 404 from a transient persistence failure (CodeRabbit #2).
     *
     * @return array{status:'found',row:array<string,mixed>}|array{status:'missing'}|array{status:'error',message:string}
     */
    private function findUnitForRic(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return ['status' => 'error', 'message' => $this->db->error];
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err];
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err !== '' ? $err : 'get_result returned false'];
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row)
            ? ['status' => 'found', 'row' => $row]
            : ['status' => 'missing'];
    }

    /**
     * Same as findUnitForRic but for authority_records. See CodeRabbit #2.
     *
     * @return array{status:'found',row:array<string,mixed>}|array{status:'missing'}|array{status:'error',message:string}
     */
    private function findAuthorityForRic(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM authority_records WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return ['status' => 'error', 'message' => $this->db->error];
        }
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err];
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            return ['status' => 'error', 'message' => $err !== '' ? $err : 'get_result returned false'];
        }
        $row = $result->fetch_assoc();
        $result->free();
        $stmt->close();
        return is_array($row)
            ? ['status' => 'found', 'row' => $row]
            : ['status' => 'missing'];
    }

    /**
     * Collect external authority URIs (VIAF, ISNI, Wikidata, ...) for
     * one authority_record by walking the autori_authority_link table
     * onto autori + author_authority_alternates. This is the Phase 1
     * source of `owl:sameAs`; Phase 2 introduces a dedicated
     * archive_agent_identifiers table.
     *
     * @return list<string>
     */
    private function collectSameAsForAuthority(int $authorityId): array
    {
        $uris = [];
        // CodeRabbit #5: MySQL does NOT interpret `\x1f` inside a single-quoted
        // string literal as the byte 0x1F — the backslash is silently dropped
        // and the separator becomes the three-character string "x1f". Use the
        // hex literal `0x1F` (the actual ASCII unit-separator byte) so the
        // separator is unambiguous and matches the PHP explode("\x1f", …)
        // below. CHAR(31) would also work and reads slightly clearer; we go
        // with 0x1F for parity with the PHP escape sequence.
        //
        // adamsreview F004: GROUP_CONCAT inherits the server-wide
        // group_concat_max_len (default 1024 bytes on MySQL/MariaDB). With
        // a handful of alternate URIs we already get close to that, and
        // the truncation is silent — the trailing token after the last
        // 0x1F becomes a malformed URL in owl:sameAs. Raise the session
        // limit before the query. 65535 is the maximum permitted as a
        // SESSION value across MySQL 8.x and MariaDB 10.x without
        // server-global tweaks; well above any plausible per-author URI
        // fan-out.
        if ($this->db->query('SET SESSION group_concat_max_len = 65535') === false) {
            throw new \RuntimeException(
                '[Archives] collectSameAsForAuthority group_concat_max_len failed: ' . $this->db->error
            );
        }
        $stmt = $this->db->prepare(
            "SELECT a.viaf_uri, a.isni_uri,
                    GROUP_CONCAT(aaa.uri SEPARATOR 0x1F) AS alt_uris
               FROM autori_authority_link aal
               JOIN autori a ON a.id = aal.autori_id
          LEFT JOIN author_authority_alternates aaa
                 ON aaa.autore_id = a.id AND aaa.uri IS NOT NULL AND aaa.uri <> ''
              WHERE aal.authority_id = ?
              GROUP BY a.id"
        );
        if ($stmt === false) {
            // CodeRabbit R2: distinguish "table missing" (legitimate
            // degradation when the viaf-authority plugin has never been
            // activated) from "real DB error" (must propagate).
            // mysqli reports error code 1146 = ER_NO_SUCH_TABLE: in that
            // case we keep the silent-degrade behaviour. Anything else
            // is a real failure and must reach the RiC action's error
            // envelope.
            if ($this->db->errno === 1146) {
                return $uris;
            }
            throw new \RuntimeException(
                '[Archives] collectSameAsForAuthority prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('i', $authorityId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Archives] collectSameAsForAuthority execute failed: ' . $err);
        }
        $result = $stmt->get_result();
        if (!($result instanceof \mysqli_result)) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException(
                '[Archives] collectSameAsForAuthority get_result failed: '
                . ($err !== '' ? $err : 'get_result returned false')
            );
        }
        while ($row = $result->fetch_assoc()) {
            foreach (['viaf_uri', 'isni_uri'] as $col) {
                $v = $row[$col] ?? null;
                if (is_string($v) && $v !== '') {
                    $uris[] = $v;
                }
            }
            $alt = $row['alt_uris'] ?? null;
            if (is_string($alt) && $alt !== '') {
                foreach (explode("\x1f", $alt) as $u) {
                    $u = trim($u);
                    if ($u !== '') {
                        $uris[] = $u;
                    }
                }
            }
        }
        $result->free();
        $stmt->close();

        // RiC-CM Phase 2 (v0.7.8): merge in the dedicated Agent
        // identifier table. This source lives on the archive side
        // (not the bibliographic autori side) so it works even on
        // installs without the viaf-authority plugin.
        foreach ($this->collectAgentIdentifierUris($authorityId) as $u) {
            $uris[] = $u;
        }
        return $uris;
    }

    /**
     * RiC-CM Phase 2 (v0.7.8): pull every URI tagged for this
     * authority from `archive_agent_identifiers`. Returns the bare
     * `uri` column when present; otherwise falls back to a synthetic
     * representation built from `scheme` + `value` so authority IDs
     * that don't ship with a precomputed URI still surface in the
     * RiC-O output.
     *
     * Silently returns empty when the table doesn't exist (install
     * predates Phase 2 and the migration hasn't run yet). Any other
     * DB error is propagated so RiC actions can 500 cleanly.
     *
     * @return list<string>
     */
    private function collectAgentIdentifierUris(int $authorityId): array
    {
        $uris = [];
        $stmt = $this->db->prepare(
            'SELECT scheme, value, uri FROM archive_agent_identifiers WHERE authority_id = ?'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return $uris;
            }
            throw new \RuntimeException(
                '[Archives] collectAgentIdentifierUris prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('i', $authorityId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException(
                '[Archives] collectAgentIdentifierUris execute failed: ' . $err
            );
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $uri = isset($row['uri']) ? trim((string) $row['uri']) : '';
                if ($uri !== '') {
                    $uris[] = $uri;
                    continue;
                }
                // No precomputed URI — synthesise from scheme + value
                // using the canonical Linked Data prefixes per
                // scheme. Unknown schemes are skipped (the value
                // alone isn't dereferenceable).
                $scheme = isset($row['scheme']) ? (string) $row['scheme'] : '';
                $value  = isset($row['value'])  ? trim((string) $row['value']) : '';
                if ($value === '') {
                    continue;
                }
                $synth = self::SCHEME_URI_PREFIX[$scheme] ?? null;
                if ($synth !== null) {
                    $uris[] = $synth . $value;
                }
            }
            $result->free();
        }
        $stmt->close();
        return $uris;
    }

    /**
     * RiC-CM Phase 2 (v0.7.8): canonical URI prefix per identifier
     * scheme. Used by collectAgentIdentifierUris() to synthesise a
     * dereferenceable IRI when the row carries only scheme + value.
     * `local` is excluded — local identifiers are not globally
     * resolvable, so we'd rather omit them than emit a guess.
     */
    private const SCHEME_URI_PREFIX = [
        'viaf'     => 'https://viaf.org/viaf/',
        'isni'     => 'https://isni.org/isni/',
        'wikidata' => 'https://www.wikidata.org/entity/',
        'gnd'      => 'https://d-nb.info/gnd/',
        'bnf'      => 'https://catalogue.bnf.fr/ark:/12148/cb',
        'lcnaf'    => 'https://id.loc.gov/authorities/names/',
        'ulan'     => 'https://vocab.getty.edu/page/ulan/',
        'ark'      => 'https://n2t.net/',
    ];

    /**
     * RiC-CM Phase 2 (v0.7.8): fetch Agent → Agent relations from
     * `archive_agent_relations` for the given authority. Returns rows
     * keyed by the columns RicJsonLdBuilder::buildAuthority expects
     * (`related_id`, `ric_predicate`, `qualifier`, `date_start`,
     * `date_end`). The target authority must be live (not soft-deleted)
     * — the JOIN filters that.
     *
     * Silently returns empty when the table is missing (pre-Phase-2
     * install). Any other DB error is propagated so RiC actions can
     * 500 cleanly.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAgentRelations(int $authorityId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT aar.related_id, aar.ric_predicate, aar.qualifier,
                    aar.date_start, aar.date_end
               FROM archive_agent_relations aar
               JOIN authority_records ar ON ar.id = aar.related_id
                                        AND ar.deleted_at IS NULL
              WHERE aar.agent_id = ?
              ORDER BY aar.ric_predicate, aar.related_id'
        );
        if ($stmt === false) {
            if ($this->db->errno === 1146) {
                return $rows;
            }
            throw new \RuntimeException(
                '[Archives] fetchAgentRelations prepare failed: ' . $this->db->error
            );
        }
        $stmt->bind_param('i', $authorityId);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[Archives] fetchAgentRelations execute failed: ' . $err);
        }
        $result = $stmt->get_result();
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        $stmt->close();
        return $rows;
    }

    // ── RiC-CM Phase 5 — Activities admin CRUD ───────────────────────────────
    //
    // Mirrors the authority_records CRUD shape (extractAuthorityPayload /
    // validateAuthority / authority{Index,New,Store,Show,Edit,Update,
    // Destroy}Action). Soft-delete via `deleted_at` so a curator can
    // restore by hand if needed.

    private const ACTIVITY_TYPES = [
        'function'    => 'Function (ISDF top-level)',
        'activity'    => 'Activity',
        'transaction' => 'Transaction',
        'task'        => 'Task',
        'mandate'     => 'Mandate',
    ];

    public function activityIndexAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $rows = [];
        $result = $this->db->query(
            'SELECT id, title, activity_type, agent_id, date_start, date_end, is_ongoing
               FROM archive_activities
              WHERE deleted_at IS NULL
              ORDER BY activity_type, title ASC
              LIMIT 500'
        );
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) { $rows[] = $r; }
            $result->free();
        } elseif ($this->db->errno !== 1146) {
            SecureLogger::warning('[Archives] activity index query failed: ' . $this->db->error);
        }
        return $this->renderView($response, 'activities/index', [
            'rows'  => $rows,
            'types' => array_keys(self::ACTIVITY_TYPES),
        ]);
    }

    public function activityNewAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->renderView($response, 'activities/form', [
            'mode'        => 'create',
            'types'       => array_keys(self::ACTIVITY_TYPES),
            'parentOpts'  => $this->listActivityOptions(null),
            'agentOpts'   => $this->listAuthorityOptions(),
            'values'      => [],
            'errors'      => [],
        ]);
    }

    public function activityStoreAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $values = $this->extractActivityPayload($request);
        $errors = $this->validateActivity($values, null);
        if (!empty($errors)) {
            return $this->renderView($response, 'activities/form', [
                'mode'       => 'create',
                'types'      => array_keys(self::ACTIVITY_TYPES),
                'parentOpts' => $this->listActivityOptions(null),
                'agentOpts'  => $this->listAuthorityOptions(),
                'values'     => $values,
                'errors'     => $errors,
            ]);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO archive_activities
                (title, description, activity_type, parent_id, date_start, date_end,
                 is_ongoing, agent_id, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] activity INSERT prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderView($response, 'activities/form', [
                'mode' => 'create', 'types' => array_keys(self::ACTIVITY_TYPES),
                'parentOpts' => $this->listActivityOptions(null),
                'agentOpts' => $this->listAuthorityOptions(),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $parentId = $values['parent_id'];
        $agentId  = $values['agent_id'];
        $isOngoing = (int) $values['is_ongoing'];
        $stmt->bind_param(
            'sssisssis',
            $values['title'],
            $values['description'],
            $values['activity_type'],
            $parentId,
            $values['date_start'],
            $values['date_end'],
            $isOngoing,
            $agentId,
            $values['source_ref']
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] activity INSERT failed: ' . $stmt->error);
            $errors['_global'] = 'Insert failed.';
            $stmt->close();
            return $this->renderView($response, 'activities/form', [
                'mode' => 'create', 'types' => array_keys(self::ACTIVITY_TYPES),
                'parentOpts' => $this->listActivityOptions(null),
                'agentOpts' => $this->listAuthorityOptions(),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        return $response
            ->withHeader('Location', url('/admin/archives/activities/' . $newId))
            ->withStatus(302);
    }

    public function activityShowAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findActivityRow($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        $linkedUnits = [];
        try {
            $linkedUnits = $this->fetchUnitsForActivity($id);
        } catch (\RuntimeException $e) {
            SecureLogger::warning('[Archives] activityShow units fetch failed: ' . $e->getMessage());
        }
        return $this->renderView($response, 'activities/show', [
            'row'         => $row,
            'linkedUnits' => $linkedUnits,
            'types'       => array_keys(self::ACTIVITY_TYPES),
            'headLinks'   => [
                ['rel' => 'alternate', 'type' => 'application/ld+json',
                 'title' => 'RiC-O (Records in Contexts)',
                 'href' => absoluteUrl('/archives/activities/' . $id . '/ric.json')],
            ],
        ]);
    }

    public function activityEditAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findActivityRow($id);
        if ($row === null) {
            return $this->renderNotFound($response, $id);
        }
        return $this->renderView($response, 'activities/form', [
            'mode'       => 'edit',
            'id'         => $id,
            'types'      => array_keys(self::ACTIVITY_TYPES),
            'parentOpts' => $this->listActivityOptions($id),
            'agentOpts'  => $this->listAuthorityOptions(),
            'values'     => $row,
            'errors'     => [],
        ]);
    }

    public function activityUpdateAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        if ($this->findActivityRow($id) === null) {
            return $this->renderNotFound($response, $id);
        }
        $values = $this->extractActivityPayload($request);
        $errors = $this->validateActivity($values, $id);
        if (!empty($errors)) {
            return $this->renderView($response, 'activities/form', [
                'mode'       => 'edit',
                'id'         => $id,
                'types'      => array_keys(self::ACTIVITY_TYPES),
                'parentOpts' => $this->listActivityOptions($id),
                'agentOpts'  => $this->listAuthorityOptions(),
                'values'     => $values,
                'errors'     => $errors,
            ]);
        }
        $stmt = $this->db->prepare(
            'UPDATE archive_activities
                SET title = ?, description = ?, activity_type = ?, parent_id = ?,
                    date_start = ?, date_end = ?, is_ongoing = ?, agent_id = ?, source_ref = ?
              WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] activity UPDATE prepare failed: ' . $this->db->error);
            return $this->renderNotFound($response, $id);
        }
        $parentId  = $values['parent_id'];
        $agentId   = $values['agent_id'];
        $isOngoing = (int) $values['is_ongoing'];
        $stmt->bind_param(
            'sssisssisi',
            $values['title'], $values['description'], $values['activity_type'],
            $parentId, $values['date_start'], $values['date_end'],
            $isOngoing, $agentId, $values['source_ref'], $id
        );
        $stmt->execute();
        $stmt->close();
        return $response
            ->withHeader('Location', url('/admin/archives/activities/' . $id))
            ->withStatus(302);
    }

    public function activityDestroyAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        // Soft-delete — mirrors the libri / archival_units convention.
        $stmt = $this->db->prepare(
            'UPDATE archive_activities SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', url('/admin/archives/activities'))
            ->withStatus(302);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActivityRow(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archive_activities WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r instanceof \mysqli_result ? $r->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractActivityPayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body)) { $body = []; }
        $get = static fn (string $k): string => is_string($body[$k] ?? null) ? trim((string) $body[$k]) : '';
        $parent = $get('parent_id');
        $agent  = $get('agent_id');
        return [
            'title'         => $get('title'),
            'description'   => $get('description'),
            'activity_type' => $get('activity_type') ?: 'activity',
            'parent_id'     => $parent === '' ? null : (int) $parent,
            'date_start'    => $get('date_start'),
            'date_end'      => $get('date_end'),
            'is_ongoing'    => !empty($body['is_ongoing']) ? 1 : 0,
            'agent_id'      => $agent === '' ? null : (int) $agent,
            'source_ref'    => $get('source_ref'),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validateActivity(array $values, ?int $editingId): array
    {
        $errors = [];
        if (($values['title'] ?? '') === '') {
            $errors['title'] = 'Required.';
        } elseif (mb_strlen((string) $values['title']) > 500) {
            $errors['title'] = 'Max 500 characters.';
        }
        $type = (string) ($values['activity_type'] ?? '');
        if (!isset(self::ACTIVITY_TYPES[$type])) {
            $errors['activity_type'] = 'Invalid type.';
        }
        if ($values['parent_id'] !== null) {
            if ($editingId !== null && $values['parent_id'] === $editingId) {
                $errors['parent_id'] = 'An activity cannot be its own parent.';
            } elseif ($editingId !== null && $this->activityWouldCreateCycle($editingId, (int) $values['parent_id'])) {
                $errors['parent_id'] = 'Cycle detected — pick a non-descendant parent.';
            } elseif ($this->findActivityRow((int) $values['parent_id']) === null) {
                $errors['parent_id'] = 'Parent activity does not exist.';
            }
        }
        if ($values['agent_id'] !== null && $this->findAuthorityById((int) $values['agent_id']) === null) {
            $errors['agent_id'] = 'Selected agent does not exist.';
        }
        return $errors;
    }

    /**
     * Phase 5: ancestor walk to reject cycles in the activity tree
     * (MySQL can't enforce this since the FK uses ON DELETE SET NULL).
     * Mirrors parentWouldCreateCycle for archival_units.
     */
    private function activityWouldCreateCycle(int $childId, int $proposedParentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT parent_id FROM archive_activities WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) { return true; }
        $current = $proposedParentId;
        $visited = [];
        for ($i = 0; $i < 100; $i++) {
            if ($current === $childId) { $stmt->close(); return true; }
            if (isset($visited[$current])) { break; }
            $visited[$current] = true;
            $stmt->bind_param('i', $current);
            $stmt->execute();
            $r = $stmt->get_result();
            $row = $r instanceof \mysqli_result ? $r->fetch_assoc() : null;
            if (!is_array($row) || $row['parent_id'] === null) {
                break;
            }
            $current = (int) $row['parent_id'];
        }
        $stmt->close();
        return false;
    }

    /**
     * @return list<array{id:int,title:string}>
     */
    private function listActivityOptions(?int $excludeId): array
    {
        $rows = [];
        $result = $this->db->query(
            'SELECT id, title FROM archive_activities WHERE deleted_at IS NULL ORDER BY title LIMIT 1000'
        );
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) {
                $id = (int) $r['id'];
                if ($excludeId !== null && $id === $excludeId) { continue; }
                $rows[] = ['id' => $id, 'title' => (string) $r['title']];
            }
            $result->free();
        }
        return $rows;
    }

    /**
     * @return list<array{id:int,label:string}>
     */
    private function listAuthorityOptions(): array
    {
        $rows = [];
        $result = $this->db->query(
            'SELECT id, authorised_form FROM authority_records WHERE deleted_at IS NULL ORDER BY authorised_form LIMIT 1000'
        );
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) {
                $rows[] = ['id' => (int) $r['id'], 'label' => (string) $r['authorised_form']];
            }
            $result->free();
        }
        return $rows;
    }

    // ── RiC-CM Phase 5 — Places admin CRUD ───────────────────────────────────

    private const PLACE_TYPES = [
        'country' => 'Country', 'region' => 'Region', 'province' => 'Province',
        'municipality' => 'Municipality', 'locality' => 'Locality',
        'building' => 'Building', 'room' => 'Room',
        'geographic_feature' => 'Geographic feature', 'other' => 'Other',
    ];

    public function placeIndexAction(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        $rows = [];
        $result = $this->db->query(
            'SELECT id, name, place_type, parent_id, latitude, longitude
               FROM archive_places WHERE deleted_at IS NULL
              ORDER BY place_type, name ASC LIMIT 500'
        );
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) { $rows[] = $r; }
            $result->free();
        } elseif ($this->db->errno !== 1146) {
            SecureLogger::warning('[Archives] place index query failed: ' . $this->db->error);
        }
        return $this->renderView($response, 'places/index', [
            'rows' => $rows, 'types' => array_keys(self::PLACE_TYPES),
        ]);
    }

    public function placeNewAction(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        return $this->renderView($response, 'places/form', [
            'mode' => 'create', 'types' => array_keys(self::PLACE_TYPES),
            'parentOpts' => $this->listPlaceOptions(null),
            'values' => [], 'errors' => [],
        ]);
    }

    public function placeStoreAction(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        $values = $this->extractPlacePayload($request);
        $errors = $this->validatePlace($values, null);
        if (!empty($errors)) {
            return $this->renderView($response, 'places/form', [
                'mode' => 'create', 'types' => array_keys(self::PLACE_TYPES),
                'parentOpts' => $this->listPlaceOptions(null),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO archive_places
                (name, place_type, parent_id, latitude, longitude,
                 geonames_id, wikidata_id, tgn_id, description, date_start, date_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] place INSERT prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error.';
            return $this->renderView($response, 'places/form', [
                'mode' => 'create', 'types' => array_keys(self::PLACE_TYPES),
                'parentOpts' => $this->listPlaceOptions(null),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $parent = $values['parent_id'];
        $lat    = $values['latitude'];
        $lng    = $values['longitude'];
        $stmt->bind_param(
            'ssiddsssssss',
            $values['name'], $values['place_type'], $parent, $lat, $lng,
            $values['geonames_id'], $values['wikidata_id'], $values['tgn_id'],
            $values['description'], $values['date_start'], $values['date_end']
        );
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] place INSERT failed: ' . $stmt->error);
            $errors['_global'] = 'Insert failed.';
            $stmt->close();
            return $this->renderView($response, 'places/form', [
                'mode' => 'create', 'types' => array_keys(self::PLACE_TYPES),
                'parentOpts' => $this->listPlaceOptions(null),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        return $response
            ->withHeader('Location', url('/admin/archives/places/' . $newId))
            ->withStatus(302);
    }

    public function placeShowAction(
        ServerRequestInterface $request, ResponseInterface $response, int $id
    ): ResponseInterface {
        $row = $this->findPlaceRow($id);
        if ($row === null) { return $this->renderNotFound($response, $id); }
        return $this->renderView($response, 'places/show', [
            'row' => $row,
            'headLinks' => [
                ['rel' => 'alternate', 'type' => 'application/ld+json',
                 'title' => 'RiC-O (Records in Contexts)',
                 'href' => absoluteUrl('/archives/places/' . $id . '/ric.json')],
            ],
        ]);
    }

    public function placeEditAction(
        ServerRequestInterface $request, ResponseInterface $response, int $id
    ): ResponseInterface {
        $row = $this->findPlaceRow($id);
        if ($row === null) { return $this->renderNotFound($response, $id); }
        return $this->renderView($response, 'places/form', [
            'mode' => 'edit', 'id' => $id,
            'types' => array_keys(self::PLACE_TYPES),
            'parentOpts' => $this->listPlaceOptions($id),
            'values' => $row, 'errors' => [],
        ]);
    }

    public function placeUpdateAction(
        ServerRequestInterface $request, ResponseInterface $response, int $id
    ): ResponseInterface {
        if ($this->findPlaceRow($id) === null) { return $this->renderNotFound($response, $id); }
        $values = $this->extractPlacePayload($request);
        $errors = $this->validatePlace($values, $id);
        if (!empty($errors)) {
            return $this->renderView($response, 'places/form', [
                'mode' => 'edit', 'id' => $id,
                'types' => array_keys(self::PLACE_TYPES),
                'parentOpts' => $this->listPlaceOptions($id),
                'values' => $values, 'errors' => $errors,
            ]);
        }
        $stmt = $this->db->prepare(
            'UPDATE archive_places
                SET name = ?, place_type = ?, parent_id = ?, latitude = ?, longitude = ?,
                    geonames_id = ?, wikidata_id = ?, tgn_id = ?, description = ?,
                    date_start = ?, date_end = ?
              WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt !== false) {
            $parent = $values['parent_id']; $lat = $values['latitude']; $lng = $values['longitude'];
            $stmt->bind_param(
                'ssiddssssssi',
                $values['name'], $values['place_type'], $parent, $lat, $lng,
                $values['geonames_id'], $values['wikidata_id'], $values['tgn_id'],
                $values['description'], $values['date_start'], $values['date_end'], $id
            );
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', url('/admin/archives/places/' . $id))
            ->withStatus(302);
    }

    public function placeDestroyAction(
        ServerRequestInterface $request, ResponseInterface $response, int $id
    ): ResponseInterface {
        $stmt = $this->db->prepare(
            'UPDATE archive_places SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', url('/admin/archives/places'))
            ->withStatus(302);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPlaceRow(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM archive_places WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r instanceof \mysqli_result ? $r->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPlacePayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body)) { $body = []; }
        $get = static fn (string $k): string => is_string($body[$k] ?? null) ? trim((string) $body[$k]) : '';
        $parent = $get('parent_id');
        $lat    = $get('latitude');
        $lng    = $get('longitude');
        return [
            'name'        => $get('name'),
            'place_type'  => $get('place_type') ?: 'locality',
            'parent_id'   => $parent === '' ? null : (int) $parent,
            'latitude'    => $lat === '' ? null : (float) $lat,
            'longitude'   => $lng === '' ? null : (float) $lng,
            'geonames_id' => $get('geonames_id'),
            'wikidata_id' => $get('wikidata_id'),
            'tgn_id'      => $get('tgn_id'),
            'description' => $get('description'),
            'date_start'  => $get('date_start'),
            'date_end'    => $get('date_end'),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validatePlace(array $values, ?int $editingId): array
    {
        $errors = [];
        if (($values['name'] ?? '') === '') {
            $errors['name'] = 'Required.';
        } elseif (mb_strlen((string) $values['name']) > 500) {
            $errors['name'] = 'Max 500 characters.';
        }
        if (!isset(self::PLACE_TYPES[(string) ($values['place_type'] ?? '')])) {
            $errors['place_type'] = 'Invalid type.';
        }
        if ($values['parent_id'] !== null) {
            if ($editingId !== null && $values['parent_id'] === $editingId) {
                $errors['parent_id'] = 'A place cannot be its own parent.';
            } elseif ($this->findPlaceRow((int) $values['parent_id']) === null) {
                $errors['parent_id'] = 'Parent place does not exist.';
            }
        }
        $lat = $values['latitude']; $lng = $values['longitude'];
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $errors['latitude'] = 'Latitude must be between -90 and 90.';
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $errors['longitude'] = 'Longitude must be between -180 and 180.';
        }
        return $errors;
    }

    /**
     * @return list<array{id:int,name:string,type:string}>
     */
    private function listPlaceOptions(?int $excludeId): array
    {
        $rows = [];
        $result = $this->db->query(
            'SELECT id, name, place_type FROM archive_places WHERE deleted_at IS NULL ORDER BY name LIMIT 1000'
        );
        if ($result instanceof \mysqli_result) {
            while ($r = $result->fetch_assoc()) {
                $id = (int) $r['id'];
                if ($excludeId !== null && $id === $excludeId) { continue; }
                $rows[] = ['id' => $id, 'name' => (string) $r['name'], 'type' => (string) $r['place_type']];
            }
            $result->free();
        }
        return $rows;
    }

    // ── RiC-CM Phase 5 — Polymorphic relations attach/detach ────────────────

    public function relationAttachAction(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body)) { $body = []; }
        $srcType   = is_string($body['source_type']   ?? null) ? (string) $body['source_type']   : '';
        $srcId     = (int) ($body['source_id']     ?? 0);
        $tgtType   = is_string($body['target_type']   ?? null) ? (string) $body['target_type']   : '';
        $tgtId     = (int) ($body['target_id']     ?? 0);
        $predicate = is_string($body['ric_predicate'] ?? null) ? trim((string) $body['ric_predicate']) : '';
        $qualifier = is_string($body['qualifier']     ?? null) ? trim((string) $body['qualifier']) : '';
        $certainty = is_string($body['certainty']     ?? null) ? (string) $body['certainty']     : 'certain';
        $dateStart = is_string($body['date_start']    ?? null) ? trim((string) $body['date_start']) : '';
        $dateEnd   = is_string($body['date_end']      ?? null) ? trim((string) $body['date_end']) : '';
        $sourceRef = is_string($body['source_ref']    ?? null) ? trim((string) $body['source_ref']) : '';

        $allowedCertainty = ['certain', 'probable', 'uncertain'];
        if (!in_array($certainty, $allowedCertainty, true)) {
            $certainty = 'certain';
        }
        $returnTo = is_string($body['_return_to'] ?? null) && $body['_return_to'] !== ''
            ? (string) $body['_return_to']
            : '/admin/archives';

        if ($predicate === '' || !$this->validateRelationEndpoints($srcType, $srcId, $tgtType, $tgtId)) {
            // Bail to the referrer with a query-flagged error — the
            // form view should render the message on next load.
            return $response
                ->withHeader('Location', url($returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'relation_error=1'))
                ->withStatus(302);
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO archive_relations
                (source_type, source_id, target_type, target_id, ric_predicate,
                 qualifier, certainty, date_start, date_end, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt !== false) {
            $qNull = $qualifier === '' ? null : $qualifier;
            $dsNull = $dateStart === '' ? null : $dateStart;
            $deNull = $dateEnd === '' ? null : $dateEnd;
            $srNull = $sourceRef === '' ? null : $sourceRef;
            $stmt->bind_param(
                'sisissssss',
                $srcType, $srcId, $tgtType, $tgtId, $predicate,
                $qNull, $certainty, $dsNull, $deNull, $srNull
            );
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', url($returnTo))
            ->withStatus(302);
    }

    public function relationDetachAction(
        ServerRequestInterface $request, ResponseInterface $response, int $id
    ): ResponseInterface {
        $body = $request->getParsedBody() ?? [];
        $returnTo = is_array($body) && is_string($body['_return_to'] ?? null) && $body['_return_to'] !== ''
            ? (string) $body['_return_to']
            : '/admin/archives';
        $stmt = $this->db->prepare('DELETE FROM archive_relations WHERE id = ?');
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        return $response
            ->withHeader('Location', url($returnTo))
            ->withStatus(302);
    }

    // ── RiC-CM Phase 5 — Cross-entity autocomplete ──────────────────────────

    /**
     * GET /api/archives/entities?type=archival_unit|authority_record|archive_activity|archive_place&q=…
     *
     * Returns up to 20 matching rows from the requested entity table,
     * as a JSON array of `{id, type, label, ric_type}`. Used by the
     * Phase-5 relation-add modal's typeahead.
     */
    public function entitiesAutocompleteAction(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $type   = is_string($params['type'] ?? null) ? (string) $params['type'] : '';
        $q      = is_string($params['q']    ?? null) ? trim((string) $params['q']) : '';

        $tableMap = [
            'archival_unit'    => ['table' => 'archival_units',     'label' => 'constructed_title', 'extra' => 'level'],
            'authority_record' => ['table' => 'authority_records',  'label' => 'authorised_form',   'extra' => 'ric_type'],
            'archive_activity' => ['table' => 'archive_activities', 'label' => 'title',             'extra' => 'activity_type'],
            'archive_place'    => ['table' => 'archive_places',     'label' => 'name',              'extra' => 'place_type'],
        ];
        $spec = $tableMap[$type] ?? null;
        $payload = ['results' => []];
        if ($spec === null) {
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
        $like = $q === '' ? '%' : '%' . $q . '%';
        // Column names are from a static map — safe to interpolate.
        $sql = "SELECT id, `{$spec['label']}` AS label, `{$spec['extra']}` AS extra
                  FROM `{$spec['table']}`
                 WHERE `{$spec['label']}` LIKE ? AND deleted_at IS NULL
                 ORDER BY `{$spec['label']}` ASC
                 LIMIT 20";
        $stmt = $this->db->prepare($sql);
        if ($stmt !== false) {
            $stmt->bind_param('s', $like);
            if ($stmt->execute()) {
                $r = $stmt->get_result();
                if ($r instanceof \mysqli_result) {
                    while ($row = $r->fetch_assoc()) {
                        $payload['results'][] = [
                            'id'    => (int) $row['id'],
                            'type'  => $type,
                            'label' => (string) ($row['label'] ?? ''),
                            'extra' => (string) ($row['extra'] ?? ''),
                        ];
                    }
                    $r->free();
                }
            }
            $stmt->close();
        }
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    // ── Dublin Core XML ──────────────────────────────────────────────────────

    /**
     * GET /admin/archives/{id}/dc.xml and /archives/{id}/dc.xml — Dublin Core
     * XML (oai_dc namespace) for one archival_unit. The public route supports
     * machine discovery; /archives/oai serves the same format to harvesters.
     */
    public function exportDublinCoreAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            $response->getBody()->write('Not found');
            return $response->withStatus(404);
        }
        $authorities = $this->fetchAuthoritiesForArchivalUnit($id);
        $xml = $this->buildDublinCoreXml($row, $authorities);
        $slug = preg_replace('/[^a-z0-9_-]/i', '_', (string) ($row['reference_code'] ?? 'dc'));
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . $slug . '.dc.xml"');
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $authorities
     */
    private function buildDublinCoreXml(array $row, array $authorities): string
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $this->writeDublinCoreRecord($xw, $row, $authorities);
        $xw->endDocument();
        return $xw->outputMemory();
    }

    /**
     * Emit one <oai_dc:dc> element for the given archival_unit row.
     * DC element crosswalk (ISAD(G) → Dublin Core):
     *   3.1.1 reference_code   → dc:identifier
     *   3.1.2 constructed/formal_title → dc:title
     *   3.1.3 date_start/end   → dc:date (ISO 8601 range with '/')
     *   3.1.5 extent           → dc:format
     *   3.3.1 scope_content    → dc:description
     *   3.4.1 access_conditions → dc:rights
     *   3.4.3 language_codes   → dc:language
     *   authority creators     → dc:creator
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $authorities
     */
    private function writeDublinCoreRecord(\XMLWriter $xw, array $row, array $authorities): void
    {
        $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->writeAttributeNs('xmlns', 'dc',  null, 'http://purl.org/dc/elements/1.1/');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

        $title = (string) ($row['constructed_title'] ?? '');
        if ($title === '' && !empty($row['formal_title'])) {
            $title = (string) $row['formal_title'];
        }
        $xw->writeElementNs('dc', 'title', null, $title);

        foreach ($authorities as $auth) {
            if ((string) ($auth['role'] ?? '') === 'creator') {
                $xw->writeElementNs('dc', 'creator', null, (string) ($auth['authorised_form'] ?? ''));
            }
        }

        if (!empty($row['reference_code'])) {
            $xw->writeElementNs('dc', 'identifier', null, (string) $row['reference_code']);
        }
        if (!empty($row['ark_identifier'])) {
            $xw->writeElementNs('dc', 'identifier', null, (string) $row['ark_identifier']);
        }
        $publisher = trim((string) ($row['repository_name'] ?? ''));
        if ($publisher === '') {
            $publisher = trim((string) \App\Support\ConfigStore::get('app.name', 'Pinakes'));
        }
        if ($publisher !== '') {
            $xw->writeElementNs('dc', 'publisher', null, $publisher);
        }

        if (!empty($row['date_start'])) {
            $dateStr = (string) $row['date_start'];
            if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
                $dateStr .= '/' . (string) $row['date_end'];
            }
            $xw->writeElementNs('dc', 'date', null, $dateStr);
        }

        if (!empty($row['scope_content'])) {
            $xw->writeElementNs('dc', 'description', null, (string) $row['scope_content']);
        }

        $xw->writeElementNs('dc', 'type', null, 'Collection');
        if (!empty($row['level'])) {
            $xw->writeElementNs('dc', 'type', null, ucfirst((string) $row['level']));
        }

        if (!empty($row['extent'])) {
            $xw->writeElementNs('dc', 'format', null, (string) $row['extent']);
        }
        if (!empty($row['language_codes'])) {
            foreach (preg_split('/[,;\s]+/', (string) $row['language_codes']) ?: [] as $langCode) {
                $langCode = trim($langCode);
                if ($langCode !== '') {
                    $xw->writeElementNs('dc', 'language', null, $langCode);
                }
            }
        }
        if (!empty($row['access_conditions'])) {
            $xw->writeElementNs('dc', 'rights', null, (string) $row['access_conditions']);
        }
        if (!empty($row['rights_statement_url'])) {
            $xw->writeElementNs('dc', 'rights', null, (string) $row['rights_statement_url']);
        }

        $xw->endElement(); // oai_dc:dc
    }

    // ── EAD3 / METS per-unit export ───────────────────────────────────────────

    /**
     * GET /archives/{id}/ead.xml and /admin/archives/{id}/ead.xml
     * Single-unit EAD3 finding aid (not wrapped in <eadlist>).
     */
    public function exportEad3Action(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            $response->getBody()->write('Not found');
            return $response->withStatus(404);
        }
        $authorities = $this->fetchAuthoritiesForArchivalUnit($id);
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $this->writeEad3Document($xw, $row, $authorities);
        $xw->endDocument();
        $slug = preg_replace('/[^a-z0-9_-]/i', '_', (string) ($row['reference_code'] ?? 'ead'));
        $response->getBody()->write($xw->outputMemory());
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . $slug . '.ead3.xml"');
    }

    /**
     * GET /archives/{id}/mets.xml and /admin/archives/{id}/mets.xml
     * METS package: DC inline + EAD3 by reference + IIIF manifest link.
     * Suitable for submission to Europeana and national aggregators.
     */
    public function exportMetsAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        $row = $this->findById($id);
        if ($row === null) {
            $response->getBody()->write('Not found');
            return $response->withStatus(404);
        }
        $authorities = $this->fetchAuthoritiesForArchivalUnit($id);
        $xml = $this->buildMetsXml($row, $authorities);
        $slug = preg_replace('/[^a-z0-9_-]/i', '_', (string) ($row['reference_code'] ?? 'mets'));
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . $slug . '.mets.xml"');
    }

    /**
     * Build a METS 1.12 document for one archival_unit.
     * Structure:
     *   metsHdr   — software agent (Pinakes)
     *   dmdSec/1  — Dublin Core (inline)
     *   dmdSec/2  — EAD3 finding aid (by URL reference)
     *   fileSec   — IIIF manifest + optional cover image
     *   structMap — logical div linking DMD sections to files
     *
     * @param array<string, mixed>      $row
     * @param list<array<string,mixed>> $authorities
     */
    private function buildMetsXml(array $row, array $authorities): string
    {
        $base      = rtrim((string) absoluteUrl(''), '/');
        $unitId    = (int) ($row['id'] ?? 0);
        $unitFiles = $unitId > 0 ? $this->fetchUnitFiles($unitId) : [];
        $title  = (string) ($row['constructed_title'] ?? $row['formal_title'] ?? 'Untitled');
        $objId = !empty($row['ark_identifier'])
            ? (string) $row['ark_identifier']
            : 'oai:pinakes:archival_unit:' . $unitId;

        $now       = gmdate('Y-m-d\TH:i:s\Z');
        $createdAt = $now;
        $updatedAt = $now;
        try {
            if (!empty($row['created_at'])) {
                $createdAt = (new \DateTimeImmutable((string) $row['created_at']))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            }
            if (!empty($row['updated_at'])) {
                $updatedAt = (new \DateTimeImmutable((string) $row['updated_at']))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            }
        } catch (\Throwable) {}

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');

        $xw->startElementNs('mets', 'mets', 'http://www.loc.gov/METS/');
        $xw->writeAttributeNs('xmlns', 'xlink', null, 'http://www.w3.org/1999/xlink');
        $xw->writeAttributeNs('xmlns', 'xsi',   null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.loc.gov/METS/ http://www.loc.gov/standards/mets/mets.xsd');
        $xw->writeAttribute('OBJID', $objId);
        $xw->writeAttribute('LABEL', $title);
        $xw->writeAttribute('TYPE',  'ArchivalUnit');

        // metsHdr
        $xw->startElement('mets:metsHdr');
        $xw->writeAttribute('CREATEDATE',   $createdAt);
        $xw->writeAttribute('LASTMODDATE',  $updatedAt);
        $xw->startElement('mets:agent');
        $xw->writeAttribute('ROLE', 'CREATOR');
        $xw->writeAttribute('TYPE', 'OTHER');
        $xw->writeAttribute('OTHERTYPE', 'SOFTWARE');
        $xw->writeElement('mets:name', 'Pinakes Archives Module');
        $xw->endElement(); // agent
        $xw->endElement(); // metsHdr

        // dmdSec 1 — Dublin Core inline
        $xw->startElement('mets:dmdSec');
        $xw->writeAttribute('ID', 'DMD01');
        $xw->startElement('mets:mdWrap');
        $xw->writeAttribute('MDTYPE',   'DC');
        $xw->writeAttribute('MIMETYPE', 'text/xml');
        $xw->startElement('mets:xmlData');
        $this->writeDublinCoreRecord($xw, $row, $authorities);
        $xw->endElement(); // xmlData
        $xw->endElement(); // mdWrap
        $xw->endElement(); // dmdSec

        // dmdSec 2 — EAD3 by reference
        $xw->startElement('mets:dmdSec');
        $xw->writeAttribute('ID', 'DMD02');
        $xw->startElement('mets:mdRef');
        $xw->writeAttribute('MDTYPE',   'EAD');
        $xw->writeAttribute('MIMETYPE', 'application/xml');
        $xw->writeAttribute('LOCTYPE',  'URL');
        $xw->writeAttributeNs('xlink', 'type', null, 'simple');
        $xw->writeAttributeNs('xlink', 'href', null, $base . '/archives/' . $unitId . '/ead.xml');
        $xw->endElement(); // mdRef
        $xw->endElement(); // dmdSec

        // fileSec
        $xw->startElement('mets:fileSec');

        $xw->startElement('mets:fileGrp');
        $xw->writeAttribute('USE', 'reference');
        $xw->startElement('mets:file');
        $xw->writeAttribute('ID',       'IIIF_MANIFEST');
        $xw->writeAttribute('MIMETYPE', 'application/ld+json');
        $xw->writeAttribute('USE',      'iiif-manifest');
        $xw->startElement('mets:FLocat');
        $xw->writeAttribute('LOCTYPE', 'URL');
        $xw->writeAttributeNs('xlink', 'type', null, 'simple');
        $xw->writeAttributeNs('xlink', 'href', null, $base . '/archives/' . $unitId . '/manifest.json');
        $xw->endElement(); // FLocat
        $xw->endElement(); // file
        $xw->endElement(); // fileGrp

        $hasCoverFile = !empty($row['cover_image_path'])
            && is_file(__DIR__ . '/../../../public' . (string) $row['cover_image_path']);
        if ($hasCoverFile) {
            $coverPath = (string) $row['cover_image_path'];
            $fsPath    = __DIR__ . '/../../../public' . $coverPath;
            $imgSize   = @getimagesize($fsPath);
            $mime      = ($imgSize !== false) ? $imgSize['mime'] : 'image/jpeg';
            $xw->startElement('mets:fileGrp');
            $xw->writeAttribute('USE', 'thumbnail');
            $xw->startElement('mets:file');
            $xw->writeAttribute('ID',       'COVER_IMAGE');
            $xw->writeAttribute('MIMETYPE', $mime);
            $xw->startElement('mets:FLocat');
            $xw->writeAttribute('LOCTYPE', 'URL');
            $xw->writeAttributeNs('xlink', 'type', null, 'simple');
            $xw->writeAttributeNs('xlink', 'href', null, $base . '/' . ltrim($coverPath, '/'));
            $xw->endElement(); // FLocat
            $xw->endElement(); // file
            $xw->endElement(); // fileGrp
        }

        // fileGrp for multi-document files stored in archival_unit_files.
        // Pre-collect valid (on-disk) files to avoid emitting an empty fileGrp.
        $validDocFiles = array_values(array_filter(
            $unitFiles,
            fn(array $uf): bool => is_file(__DIR__ . '/../../../public' . (string) $uf['file_path'])
        ));
        // $docFileEntries: array<int, array{id: string, label: string}> for structMap
        $docFileEntries = [];
        if (!empty($validDocFiles)) {
            $xw->startElement('mets:fileGrp');
            $xw->writeAttribute('USE', 'documents');
            foreach ($validDocFiles as $idx => $uf) {
                $docMime  = (string) ($uf['file_mime'] ?: 'application/octet-stream');
                $fileId   = 'DOC_' . $idx;
                $docLabel = (string) ($uf['original_filename'] ?: basename((string) $uf['file_path']));
                $docFileEntries[] = ['id' => $fileId, 'label' => $docLabel];
                $xw->startElement('mets:file');
                $xw->writeAttribute('ID',       $fileId);
                $xw->writeAttribute('MIMETYPE', $docMime);
                $xw->startElement('mets:FLocat');
                $xw->writeAttribute('LOCTYPE', 'URL');
                $xw->writeAttributeNs('xlink', 'type', null, 'simple');
                $xw->writeAttributeNs('xlink', 'href', null, $base . '/' . ltrim((string) $uf['file_path'], '/'));
                $xw->endElement(); // FLocat
                $xw->endElement(); // file
            }
            $xw->endElement(); // fileGrp
        }

        $xw->endElement(); // fileSec

        // structMap
        $xw->startElement('mets:structMap');
        $xw->writeAttribute('TYPE', 'logical');
        $xw->startElement('mets:div');
        $xw->writeAttribute('TYPE',  'ArchivalUnit');
        $xw->writeAttribute('LABEL', $title);
        $xw->writeAttribute('DMDID', 'DMD01 DMD02');
        $xw->startElement('mets:fptr');
        $xw->writeAttribute('FILEID', 'IIIF_MANIFEST');
        $xw->endElement(); // fptr
        if ($hasCoverFile) {
            $xw->startElement('mets:fptr');
            $xw->writeAttribute('FILEID', 'COVER_IMAGE');
            $xw->endElement(); // fptr
        }
        foreach ($docFileEntries as $entry) {
            $xw->startElement('mets:div');
            $xw->writeAttribute('TYPE',  'Document');
            $xw->writeAttribute('LABEL', $entry['label']);
            $xw->startElement('mets:fptr');
            $xw->writeAttribute('FILEID', $entry['id']);
            $xw->endElement(); // fptr
            $xw->endElement(); // div
        }
        $xw->endElement(); // div (ArchivalUnit)
        $xw->endElement(); // structMap

        $xw->endElement(); // mets:mets
        $xw->endDocument();
        return $xw->outputMemory();
    }

    // ── EAD3 bulk export ─────────────────────────────────────────────────────

    /**
     * GET /admin/archives/export.ead3?ids=1,2,3 — EAD3 XML bulk export.
     * Without ?ids= exports all non-deleted archival_units (capped at 1000).
     *
     * EAD3 is an alternative to MARCXML used by many national archives and
     * aggregators. This serialisation follows the Library of Congress EAD3
     * schema (http://ead3.archivists.org/schema/).
     */
    public function exportEad3CollectionAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $idsParam = (string) ($params['ids'] ?? '');
        $ids = [];
        if ($idsParam !== '') {
            foreach (explode(',', $idsParam) as $raw) {
                $n = (int) trim($raw);
                if ($n > 0) { $ids[] = $n; }
            }
        }

        $rows = [];
        if (empty($ids)) {
            $result = $this->db->query(
                "SELECT * FROM archival_units WHERE deleted_at IS NULL
                  ORDER BY FIELD(level,'fonds','series','file','item'), reference_code
                  LIMIT 1000"
            );
            if ($result instanceof \mysqli_result) {
                while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                $result->free();
            }
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM archival_units
                  WHERE id IN ($placeholders) AND deleted_at IS NULL
                  ORDER BY FIELD(level,'fonds','series','file','item'), reference_code"
            );
            if ($stmt !== false) {
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
                    $result->free();
                }
                $stmt->close();
            }
        }

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');

        // <eadlist> is a Pinakes-specific container element (not in the EAD3
        // schema) used to group multiple <ead> documents in a single XML file.
        // EAD3 has no standard bulk-export wrapper; tools that consume this file
        // can strip the outer element and process each <ead> child independently.
        // Pre-fetch all unit files and authorities in one query each to avoid N+1.
        $allUnitIds        = array_map(fn(array $r): int => (int) $r['id'], $rows);
        $allUnitFiles      = $this->fetchUnitFilesForUnits($allUnitIds);
        $allUnitFiles      = array_replace(array_fill_keys($allUnitIds, []), $allUnitFiles);
        $ead3AuthoritiesMap = $this->fetchAuthoritiesForUnits($allUnitIds);

        $xw->startElement('eadlist');
        $xw->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        foreach ($rows as $row) {
            $auth         = $ead3AuthoritiesMap[(int) $row['id']] ?? [];
            $rowUnitFiles = $allUnitFiles[(int) $row['id']] ?? null;
            $this->writeEad3Document($xw, $row, $auth, $rowUnitFiles);
        }
        $xw->endElement(); // eadlist
        $xw->endDocument();

        $xml = $xw->outputMemory();
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="archives.ead3.xml"');
    }

    /**
     * Emit one complete <ead> document for an archival_unit row.
     *
     * EAD3 crosswalk (ISAD(G) → EAD3):
     *   3.1.1 reference_code    → control/recordid + did/unitid
     *   3.1.2 constructed_title → control/filedesc/titlestmt/titleproper
     *                             + archdesc/did/unittitle
     *   3.1.2 formal_title      → archdesc/did/unittitle[@altrender="formal"]
     *   3.1.3 date_start/end    → archdesc/did/unitdatestructured
     *   3.1.4 level             → archdesc[@level]
     *   3.1.5 extent            → archdesc/did/physdescstructured
     *   3.2.3 archival_history  → archdesc/custodhist
     *   3.3.1 scope_content     → archdesc/scopecontent
     *   3.3.4 arrangement_system → archdesc/arrangement
     *   3.4.1 access_conditions → archdesc/accessrestrict
     *   3.4.2 reproduction_rules → archdesc/userestrict
     *   3.4.3 language_codes    → archdesc/did/langmaterial
     *   authority records       → archdesc/controlaccess
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $authorities
     */
    /**
     * @param list<array{id:int,file_path:string,file_mime:string,original_filename:string,sort_order:int}> $unitFiles
     *     Pre-fetched files for this unit; if empty the method fetches them itself.
     *     Pass a non-empty array to avoid N+1 queries in collection exports.
     */
    private function writeEad3Document(\XMLWriter $xw, array $row, array $authorities, ?array $unitFiles = null): void
    {
        $ns = 'http://ead3.archivists.org/schema/';
        $xw->startElementNs(null, 'ead', $ns);
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://ead3.archivists.org/schema/ https://www.loc.gov/ead/ead3.xsd');

        // <control>
        $xw->startElement('control');
        $xw->writeAttribute('countryencoding', 'iso3166-1');
        $xw->writeAttribute('dateencoding',    'iso8601');
        $xw->writeAttribute('langencoding',    'iso639-2b');

        // Prefer the ARK identifier as the canonical record ID; fall back to reference_code
        $recordId = !empty($row['ark_identifier'])
            ? (string) $row['ark_identifier']
            : (string) ($row['reference_code'] ?? 'pinakes_' . $row['id']);
        $xw->writeElement('recordid', $recordId);

        $xw->startElement('filedesc');
        $xw->startElement('titlestmt');
        $constructed = (string) ($row['constructed_title'] ?? '');
        $xw->startElement('titleproper');
        $xw->writeAttribute('encodinganalog', '245');
        $xw->text($constructed !== '' ? $constructed : (string) ($row['formal_title'] ?? ''));
        $xw->endElement(); // titleproper
        $xw->endElement(); // titlestmt
        $xw->endElement(); // filedesc

        $xw->startElement('maintenancestatus');
        $xw->writeAttribute('value', 'new');
        $xw->endElement();

        $xw->startElement('maintenanceagency');
        $xw->writeElement('agencycode', 'PINAKES');
        $xw->writeElement('agencyname', 'Pinakes Library Management System');
        $xw->endElement();

        $xw->startElement('maintenancehistory');
        $xw->startElement('maintenanceevent');
        $xw->startElement('eventtype');
        $xw->writeAttribute('value', 'created');
        $xw->endElement();
        $xw->startElement('eventdatetime');
        $createdAtRaw = (string) ($row['created_at'] ?? '');
        $createdAtIso = gmdate('Y-m-d\TH:i:s\Z');
        if ($createdAtRaw !== '') {
            try {
                $createdAtIso = (new \DateTimeImmutable($createdAtRaw))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                // fallback già inizializzato
            }
        }
        $xw->writeAttribute('standarddatetime', $createdAtIso);
        $xw->text($createdAtIso);
        $xw->endElement();
        $xw->startElement('agenttype');
        $xw->writeAttribute('value', 'machine');
        $xw->endElement();
        $xw->writeElement('agent', 'Pinakes');
        $xw->endElement(); // maintenanceevent
        $xw->endElement(); // maintenancehistory

        $xw->endElement(); // control

        // <archdesc>
        $levelAttr = (string) ($row['level'] ?? 'fonds');
        $xw->startElement('archdesc');
        $xw->writeAttribute('level', $levelAttr);

        $xw->startElement('did');
        // unittitle
        if (!empty($row['constructed_title'])) {
            $xw->startElement('unittitle');
            $xw->writeAttribute('encodinganalog', '245');
            $xw->text((string) $row['constructed_title']);
            $xw->endElement();
        }
        if (!empty($row['formal_title'])) {
            $xw->startElement('unittitle');
            $xw->writeAttribute('altrender', 'formal');
            $xw->writeAttribute('encodinganalog', '241');
            $xw->text((string) $row['formal_title']);
            $xw->endElement();
        }
        // unitid
        if (!empty($row['reference_code'])) {
            $xw->startElement('unitid');
            $xw->writeAttribute('encodinganalog', '001');
            $xw->text((string) $row['reference_code']);
            $xw->endElement();
        }
        // unitdatestructured
        if (!empty($row['date_start'])) {
            $xw->startElement('unitdatestructured');
            $xw->writeAttribute('calendar', 'gregorian');
            $xw->writeAttribute('era', 'ce');
            $dateEnd = !empty($row['date_end']) && $row['date_end'] !== $row['date_start']
                ? (string) $row['date_end'] : null;
            if ($dateEnd !== null) {
                $xw->startElement('daterange');
                $xw->startElement('fromdate');
                $xw->writeAttribute('standarddate', (string) $row['date_start']);
                $xw->text((string) $row['date_start']);
                $xw->endElement();
                $xw->startElement('todate');
                $xw->writeAttribute('standarddate', $dateEnd);
                $xw->text($dateEnd);
                $xw->endElement();
                $xw->endElement(); // daterange
            } else {
                $xw->startElement('datesingle');
                $xw->writeAttribute('standarddate', (string) $row['date_start']);
                $xw->text((string) $row['date_start']);
                $xw->endElement();
            }
            $xw->endElement(); // unitdatestructured
        }
        // physdescstructured
        if (!empty($row['extent'])) {
            $xw->startElement('physdescstructured');
            $xw->writeAttribute('coverage', 'whole');
            $xw->writeAttribute('physdescstructuredtype', 'spaceoccupied');
            $xw->writeElement('quantity', (string) $row['extent']);
            $xw->writeElement('unittype', 'units');
            $xw->endElement();
        }
        // langmaterial
        if (!empty($row['language_codes'])) {
            $xw->startElement('langmaterial');
            foreach (preg_split('/[,;\s]+/', (string) $row['language_codes']) ?: [] as $lang) {
                $lang = trim($lang);
                if ($lang !== '') {
                    $xw->startElement('language');
                    $xw->writeAttribute('langcode', $lang);
                    $xw->endElement();
                }
            }
            $xw->endElement(); // langmaterial
        }
        // <daoset> must be a child of <did> per EAD3 schema
        $unitId = (int) ($row['id'] ?? 0);
        if ($unitId > 0) {
            $xw->startElement('daoset');
            $xw->writeAttribute('coverage', 'whole');
            $xw->startElement('dao');
            $xw->writeAttribute('daotype', 'derived');
            $xw->writeAttribute('href', (string) absoluteUrl('/archives/' . $unitId . '/manifest.json'));
            $xw->writeAttribute('linktitle', 'IIIF Manifest');
            $xw->writeAttribute('actuate', 'onrequest');
            $xw->writeAttribute('show', 'new');
            $xw->endElement(); // dao
            if (!empty($row['cover_image_path'])
                && is_file(__DIR__ . '/../../../public' . (string) $row['cover_image_path'])
            ) {
                $xw->startElement('dao');
                $xw->writeAttribute('daotype', 'borndigital');
                $xw->writeAttribute('href', (string) absoluteUrl('/' . ltrim((string) $row['cover_image_path'], '/')));
                $xw->writeAttribute('linktitle', 'Cover image');
                $xw->writeAttribute('actuate', 'onrequest');
                $xw->writeAttribute('show', 'embed');
                $xw->endElement(); // dao
            }
            // One <dao> per multi-document file in archival_unit_files.
            $filesToEmit = $unitFiles ?? $this->fetchUnitFiles($unitId);
            foreach ($filesToEmit as $uf) {
                $fsPathDoc = __DIR__ . '/../../../public' . (string) $uf['file_path'];
                if (!is_file($fsPathDoc)) {
                    continue;
                }
                $docUrl = (string) absoluteUrl('/' . ltrim((string) $uf['file_path'], '/'));
                $xw->startElement('dao');
                $xw->writeAttribute('daotype',   'borndigital');
                $xw->writeAttribute('href',       $docUrl);
                $xw->writeAttribute('linktitle',  (string) ($uf['original_filename'] ?: basename((string) $uf['file_path'])));
                $xw->writeAttribute('actuate',    'onrequest');
                $xw->writeAttribute('show',       'new');
                $xw->endElement(); // dao
            }
            $xw->endElement(); // daoset
        }
        $xw->endElement(); // did

        // Narrative elements
        if (!empty($row['archival_history'])) {
            $xw->startElement('custodhist');
            $xw->startElement('head');
            $xw->text('Archival/administrative history');
            $xw->endElement();
            $xw->writeElement('p', (string) $row['archival_history']);
            $xw->endElement();
        }
        if (!empty($row['scope_content'])) {
            $xw->startElement('scopecontent');
            $xw->startElement('head');
            $xw->text('Scope and content');
            $xw->endElement();
            $xw->writeElement('p', (string) $row['scope_content']);
            $xw->endElement();
        }
        if (!empty($row['arrangement_system'])) {
            $xw->startElement('arrangement');
            $xw->startElement('head');
            $xw->text('System of arrangement');
            $xw->endElement();
            $xw->writeElement('p', (string) $row['arrangement_system']);
            $xw->endElement();
        }
        if (!empty($row['access_conditions'])) {
            $xw->startElement('accessrestrict');
            $xw->startElement('head');
            $xw->text('Conditions governing access');
            $xw->endElement();
            $xw->writeElement('p', (string) $row['access_conditions']);
            $xw->endElement();
        }
        if (!empty($row['reproduction_rules'])) {
            $xw->startElement('userestrict');
            $xw->startElement('head');
            $xw->text('Conditions governing reproduction');
            $xw->endElement();
            $xw->writeElement('p', (string) $row['reproduction_rules']);
            $xw->endElement();
        }

        // <controlaccess> — linked authority records
        if (!empty($authorities)) {
            $xw->startElement('controlaccess');
            foreach ($authorities as $auth) {
                $authType = (string) ($auth['type'] ?? 'person');
                $tag = match ($authType) {
                    'corporate' => 'corpname',
                    'family'    => 'famname',
                    default     => 'persname',
                };
                $xw->startElement($tag);
                $xw->writeAttribute('encodinganalog', $authType === 'corporate' ? '710' : '700');
                $role = (string) ($auth['role'] ?? 'associated');
                $xw->writeAttribute('relator', $role);
                $xw->text((string) ($auth['authorised_form'] ?? ''));
                $xw->endElement();
            }
            $xw->endElement(); // controlaccess
        }

        $xw->endElement(); // archdesc
        $xw->endElement(); // ead
    }

    // ── OAI-PMH 2.0 ──────────────────────────────────────────────────────────

    /**
     * OAI-PMH 2.0 endpoint — handles all standard verbs.
     *
     * Public endpoint at GET/POST /archives/oai (no admin auth). Aggregators
     * (Europeana, DPLA, national portals) poll this endpoint to harvest records
     * without authentication, as required by the OAI-PMH 2.0 specification
     * (https://www.openarchives.org/OAI/openarchivesprotocol.html).
     *
     * Supported verbs:
     *   Identify              — repository description
     *   ListMetadataFormats   — oai_dc (required) + marcxml + ead3
     *   ListRecords           — all records (metadataPrefix=oai_dc or marcxml)
     *   GetRecord             — one record by OAI identifier
     *   ListIdentifiers       — identifiers without metadata
     *   ListSets              — not supported (noSetHierarchy)
     *
     * OAI identifier scheme: oai:pinakes:archival_unit:{id}
     *
     * Resumption tokens: simple cursor-based paging (100 records per page).
     */
    public function oaiPmhAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // OAI-PMH allows parameters via GET or POST (application/x-www-form-urlencoded).
        $params = $request->getQueryParams();
        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            $params = array_merge($params, $body);
        }

        $verb   = (string) ($params['verb'] ?? '');
        $now    = gmdate('Y-m-d\TH:i:s\Z');
        $baseUrl = absoluteUrl('/archives/oai');

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'OAI-PMH', 'http://www.openarchives.org/OAI/2.0/');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');

        $xw->writeElement('responseDate', $now);

        // OAI-PMH §2.2: <request> must carry attributes only for valid verbs.
        // For badVerb/badArgument the element must contain only the base URL.
        static $validVerbs = ['Identify', 'ListMetadataFormats', 'ListRecords',
                               'GetRecord', 'ListIdentifiers', 'ListSets'];
        $verbIsValid = in_array($verb, $validVerbs, true);
        $xw->startElement('request');
        if ($verbIsValid) {
            if ($verb !== '') { $xw->writeAttribute('verb', $verb); }
            foreach (['metadataPrefix', 'identifier', 'from', 'until', 'set', 'resumptionToken'] as $k) {
                if (!empty($params[$k])) { $xw->writeAttribute($k, (string) $params[$k]); }
            }
        }
        $xw->text($baseUrl);
        $xw->endElement();

        match ($verb) {
            'Identify'            => $this->oaiIdentify($xw, $baseUrl, $now),
            'ListMetadataFormats' => $this->oaiListMetadataFormats($xw, $params),
            'ListRecords'         => $this->oaiListRecords($xw, $params, false),
            'GetRecord'           => $this->oaiGetRecord($xw, $params),
            'ListIdentifiers'     => $this->oaiListRecords($xw, $params, true),
            'ListSets'            => $this->oaiListSets($xw),
            default               => $this->oaiError($xw, 'badVerb',
                'Value of the verb argument is not a legal OAI-PMH verb, '
                . 'the verb argument is missing, or the verb argument is repeated.'),
        };

        $xw->endElement(); // OAI-PMH
        $xw->endDocument();

        $response->getBody()->write($xw->outputMemory());
        return $response->withHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    private function oaiIdentify(\XMLWriter $xw, string $baseUrl, string $now): void
    {
        // Earliest datestamp from the DB (or fallback to now if no records).
        $earliest = $now;
        $result   = $this->db->query(
            "SELECT MIN(created_at) AS earliest FROM archival_units WHERE deleted_at IS NULL"
        );
        if ($result instanceof \mysqli_result) {
            $r = $result->fetch_assoc();
            if (!empty($r['earliest'])) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $r['earliest']);
                if ($dt !== false) {
                    $earliest = $dt->format('Y-m-d\TH:i:s\Z');
                }
            }
            $result->free();
        }

        $cfg = \App\Support\ConfigStore::all();
        $repoName  = trim((string) ($cfg['app']['name'] ?? '')) ?: 'Pinakes';
        $adminMail = trim((string) ($cfg['mail']['from_email'] ?? '')) ?: 'admin@localhost';

        $xw->startElement('Identify');
        $xw->writeElement('repositoryName', $repoName . ' — Archival Repository');
        $xw->writeElement('baseURL', $baseUrl);
        $xw->writeElement('protocolVersion', '2.0');
        $xw->writeElement('adminEmail', $adminMail);
        $xw->writeElement('earliestDatestamp', $earliest);
        $xw->startElement('deletedRecord');
        $xw->text('no');
        $xw->endElement();
        $xw->startElement('granularity');
        $xw->text('YYYY-MM-DDThh:mm:ssZ');
        $xw->endElement();
        // oai-identifier description (Implementation Guidelines §2.1)
        // Namespace must match identifiers emitted by oaiListRecords/oaiGetRecord.
        $xw->startElement('description');
        $xw->startElementNs(null, 'oai-identifier',
            'http://www.openarchives.org/OAI/2.0/oai-identifier');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai-identifier ' .
            'http://www.openarchives.org/OAI/2.0/oai-identifier.xsd');
        $xw->writeElement('scheme', 'oai');
        $xw->writeElement('repositoryIdentifier', 'pinakes');
        $xw->writeElement('delimiter', ':');
        $xw->writeElement('sampleIdentifier', 'oai:pinakes:archival_unit:1');
        $xw->endElement(); // oai-identifier
        $xw->endElement(); // description
        $xw->endElement(); // Identify
    }

    private function oaiListMetadataFormats(\XMLWriter $xw, array $params = []): void
    {
        $identifier = (string) ($params['identifier'] ?? '');
        if ($identifier !== '') {
            // Canonical identifier: oai:pinakes:archival_unit:{id}.
            // Accept the old archives alias defensively for pre-release tokens.
            if (!preg_match('/^oai:pinakes:(?:archival_unit|archives):(\d+)$/i', $identifier, $m)) {
                $this->oaiError($xw, 'idDoesNotExist',
                    'The value of the identifier argument is unknown or illegal in this repository.');
                return;
            }
            if ($this->findById((int) $m[1]) === null) {
                $this->oaiError($xw, 'idDoesNotExist',
                    'The value of the identifier argument is unknown or illegal in this repository.');
                return;
            }
        }

        $xw->startElement('ListMetadataFormats');

        $xw->startElement('metadataFormat');
        $xw->writeElement('metadataPrefix', 'oai_dc');
        $xw->writeElement('schema', 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        $xw->writeElement('metadataNamespace', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->endElement();

        $xw->startElement('metadataFormat');
        $xw->writeElement('metadataPrefix', 'marcxml');
        $xw->writeElement('schema', 'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd');
        $xw->writeElement('metadataNamespace', 'http://www.loc.gov/MARC21/slim');
        $xw->endElement();

        $xw->startElement('metadataFormat');
        $xw->writeElement('metadataPrefix', 'ead3');
        $xw->writeElement('schema', 'https://www.loc.gov/ead/ead3.xsd');
        $xw->writeElement('metadataNamespace', 'http://ead3.archivists.org/schema/');
        $xw->endElement();

        $xw->endElement(); // ListMetadataFormats
    }

    /**
     * Shared implementation for ListRecords (identifiersOnly=false) and
     * ListIdentifiers (identifiersOnly=true).
     *
     * @param array<string, mixed> $params
     */
    private function oaiListRecords(\XMLWriter $xw, array $params, bool $identifiersOnly): void
    {
        $metadataPrefix = (string) ($params['metadataPrefix'] ?? '');
        $from  = (string) ($params['from']  ?? '');
        $until = (string) ($params['until'] ?? '');
        $set   = (string) ($params['set']   ?? '');
        $token = (string) ($params['resumptionToken'] ?? '');

        $cursor = 0;
        if ($token !== '') {
            $decoded = $this->decodeOaiResumptionToken($token);
            if ($decoded === null) {
                $this->oaiError($xw, 'badResumptionToken',
                    'The value of the resumptionToken argument is invalid or expired.');
                return;
            }
            $cursor         = $decoded['cursor'];
            $metadataPrefix = $decoded['metadataPrefix'];
            $from           = $decoded['from'];
            $until          = $decoded['until'];
            $set            = $decoded['set'];
        }

        if ($metadataPrefix === '' || !in_array($metadataPrefix, ['oai_dc', 'marcxml', 'ead3'], true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
            return;
        }
        if ($set !== '') {
            $this->oaiError($xw, 'noSetHierarchy',
                'This repository does not support sets.');
            return;
        }

        $validateOaiDate = static function (string $date): bool {
            $fmt = strlen($date) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
            $d   = \DateTime::createFromFormat($fmt, $date);
            if ($d === false) {
                return false;
            }
            $errs = \DateTime::getLastErrors();
            return $errs === false
                || ($errs['error_count'] === 0 && $errs['warning_count'] === 0);
        };
        if ($from !== '' && !$validateOaiDate($from)) {
            $this->oaiError($xw, 'badArgument', 'Invalid from date. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.');
            return;
        }
        if ($until !== '' && !$validateOaiDate($until)) {
            $this->oaiError($xw, 'badArgument', 'Invalid until date. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.');
            return;
        }
        if ($from !== '' && $until !== '' && strlen($from) !== strlen($until)) {
            $this->oaiError($xw, 'badArgument', 'from and until must use the same granularity (both YYYY-MM-DD or both YYYY-MM-DDThh:mm:ssZ).');
            return;
        }

        $pageSize = 100;
        $where    = ['deleted_at IS NULL'];
        $bindTypes = '';
        $bindVals  = [];

        if ($from !== '') {
            $fromSql    = strlen($from) === 10 ? $from . ' 00:00:00' : str_replace('T', ' ', str_replace('Z', '', $from));
            $where[]    = 'updated_at >= ?';
            $bindTypes .= 's';
            $bindVals[] = $fromSql;
        }
        if ($until !== '') {
            $untilSql   = strlen($until) === 10 ? $until . ' 23:59:59' : str_replace('T', ' ', str_replace('Z', '', $until));
            $where[]    = 'updated_at <= ?';
            $bindTypes .= 's';
            $bindVals[] = $untilSql;
        }
        $whereClause = implode(' AND ', $where);
        $limitSql    = 'LIMIT ' . $pageSize . ' OFFSET ' . $cursor;
        $countSql    = "SELECT COUNT(*) AS cnt FROM archival_units WHERE $whereClause";
        $dataSql     = "SELECT * FROM archival_units WHERE $whereClause ORDER BY id $limitSql";

        $totalCount = 0;
        if (!empty($bindVals)) {
            $countStmt = $this->db->prepare($countSql);
            if ($countStmt !== false) {
                $countStmt->bind_param($bindTypes, ...$bindVals);
                $countStmt->execute();
                $cr = $countStmt->get_result();
                if ($cr instanceof \mysqli_result) {
                    $totalCount = (int) ($cr->fetch_assoc()['cnt'] ?? 0);
                    $cr->free();
                }
                $countStmt->close();
            }
        } else {
            $cr = $this->db->query($countSql);
            if ($cr instanceof \mysqli_result) {
                $totalCount = (int) ($cr->fetch_assoc()['cnt'] ?? 0);
                $cr->free();
            }
        }

        if ($totalCount === 0) {
            $this->oaiError($xw, 'noRecordsMatch',
                'The combination of the values of the from, until, set, and metadataPrefix arguments '
                . 'results in an empty list.');
            return;
        }

        $rows = [];
        if (!empty($bindVals)) {
            $dataStmt = $this->db->prepare($dataSql);
            if ($dataStmt !== false) {
                $dataStmt->bind_param($bindTypes, ...$bindVals);
                $dataStmt->execute();
                $dr = $dataStmt->get_result();
                if ($dr instanceof \mysqli_result) {
                    while ($r = $dr->fetch_assoc()) { $rows[] = $r; }
                    $dr->free();
                }
                $dataStmt->close();
            }
        } else {
            $dr = $this->db->query($dataSql);
            if ($dr instanceof \mysqli_result) {
                while ($r = $dr->fetch_assoc()) { $rows[] = $r; }
                $dr->free();
            }
        }

        $verbElement = $identifiersOnly ? 'ListIdentifiers' : 'ListRecords';
        $xw->startElement($verbElement);

        $oaiUnitIds = array_map(fn(array $r): int => (int) $r['id'], $rows);
        $oaiAuthoritiesMap = $identifiersOnly ? [] : $this->fetchAuthoritiesForUnits($oaiUnitIds);

        foreach ($rows as $row) {
            $rowId   = (int) $row['id'];
            $oaiId   = 'oai:pinakes:archival_unit:' . $rowId;
            $dtStamp = gmdate('Y-m-d\TH:i:s\Z',
                strtotime((string) ($row['updated_at'] ?? $row['created_at'] ?? 'now')) ?: time());

            if (!$identifiersOnly) {
                $xw->startElement('record');
            }

            $xw->startElement('header');
            $xw->writeElement('identifier', $oaiId);
            $xw->writeElement('datestamp',  $dtStamp);
            // setSpec omitted: this repository returns noSetHierarchy from ListSets,
            // so advertising set membership in record headers would be contradictory.
            $xw->endElement(); // header

            if (!$identifiersOnly) {
                $xw->startElement('metadata');
                $authorities = $oaiAuthoritiesMap[$rowId] ?? [];
                if ($metadataPrefix === 'oai_dc') {
                    $this->writeDublinCoreRecord($xw, $row, $authorities);
                } elseif ($metadataPrefix === 'ead3') {
                    $this->writeEad3Document($xw, $row, $authorities);
                } else {
                    $this->writeArchivalUnitMarcRecord($xw, $row, $authorities);
                }
                $xw->endElement(); // metadata
            }

            if (!$identifiersOnly) {
                $xw->endElement(); // record
            }
        }

        // Resumption token if there are more results.
        $nextCursor = $cursor + $pageSize;
        $xw->startElement('resumptionToken');
        $xw->writeAttribute('completeListSize', (string) $totalCount);
        $xw->writeAttribute('cursor', (string) $cursor);
        if ($nextCursor < $totalCount) {
            $xw->text($this->encodeOaiResumptionToken($nextCursor, $metadataPrefix, $from, $until, $set));
        }
        $xw->endElement(); // resumptionToken

        $xw->endElement(); // ListRecords / ListIdentifiers
    }

    private function encodeOaiResumptionToken(
        int $cursor,
        string $metadataPrefix,
        string $from,
        string $until,
        string $set
    ): string {
        $json = json_encode([
            'cursor'         => $cursor,
            'metadataPrefix' => $metadataPrefix,
            'from'           => $from,
            'until'          => $until,
            'set'            => $set,
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /**
     * @return array{cursor:int, metadataPrefix:string, from:string, until:string, set:string}|null
     */
    private function decodeOaiResumptionToken(string $token): ?array
    {
        $padded = strtr($token, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($padded, true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        $metadataPrefix = (string) ($payload['metadataPrefix'] ?? '');
        if (!in_array($metadataPrefix, ['oai_dc', 'marcxml', 'ead3'], true)) {
            return null;
        }

        return [
            'cursor'         => max(0, (int) ($payload['cursor'] ?? 0)),
            'metadataPrefix' => $metadataPrefix,
            'from'           => (string) ($payload['from'] ?? ''),
            'until'          => (string) ($payload['until'] ?? ''),
            'set'            => (string) ($payload['set'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function oaiGetRecord(\XMLWriter $xw, array $params): void
    {
        $identifier     = (string) ($params['identifier']     ?? '');
        $metadataPrefix = (string) ($params['metadataPrefix'] ?? '');

        if ($identifier === '' || $metadataPrefix === '') {
            $this->oaiError($xw, 'badArgument',
                'The request includes illegal arguments, is missing required arguments, '
                . 'includes a repeated argument, or values for arguments have an illegal syntax.');
            return;
        }
        if (!in_array($metadataPrefix, ['oai_dc', 'marcxml', 'ead3'], true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
            return;
        }

        // Parse identifier: oai:pinakes:archival_unit:{id}
        if (!preg_match('/^oai:pinakes:archival_unit:(\d+)$/', $identifier, $m)) {
            $this->oaiError($xw, 'idDoesNotExist',
                'The value of the identifier argument is unknown or illegal in this repository.');
            return;
        }
        $row = $this->findById((int) $m[1]);
        if ($row === null) {
            $this->oaiError($xw, 'idDoesNotExist',
                'The value of the identifier argument is unknown or illegal in this repository.');
            return;
        }

        $rowId   = (int) $row['id'];
        $dtStamp = gmdate('Y-m-d\TH:i:s\Z',
            strtotime((string) ($row['updated_at'] ?? $row['created_at'] ?? 'now')) ?: time());
        $xw->startElement('GetRecord');
        $xw->startElement('record');
        $xw->startElement('header');
        $xw->writeElement('identifier', $identifier);
        $xw->writeElement('datestamp',  $dtStamp);
        $xw->endElement(); // header

        $xw->startElement('metadata');
        $authorities = $this->fetchAuthoritiesForArchivalUnit($rowId);
        if ($metadataPrefix === 'oai_dc') {
            $this->writeDublinCoreRecord($xw, $row, $authorities);
        } elseif ($metadataPrefix === 'ead3') {
            $this->writeEad3Document($xw, $row, $authorities);
        } else {
            $this->writeArchivalUnitMarcRecord($xw, $row, $authorities);
        }
        $xw->endElement(); // metadata
        $xw->endElement(); // record
        $xw->endElement(); // GetRecord
    }

    private function oaiListSets(\XMLWriter $xw): void
    {
        $this->oaiError($xw, 'noSetHierarchy',
            'This repository does not support sets.');
    }

    private function oaiError(\XMLWriter $xw, string $code, string $message): void
    {
        $xw->startElement('error');
        $xw->writeAttribute('code', $code);
        $xw->text($message);
        $xw->endElement();
    }

    /**
     * Returns a LIKE pattern for $q, stripping the last char when len >= 5
     * so that "disegno" matches "disegni" (shared root "disegn-").
     */
    private function archiveSearchPattern(string $q): string
    {
        // Escape LIKE wildcards (% and _) and the escape character itself
        // so literal user input doesn't leak through as wildcards. Backslash
        // must be escaped first to avoid double-escaping the new sequences.
        $q    = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        // FIX F034: use mb_strlen / mb_substr so the stem trim is multibyte-safe.
        // strlen()/substr() count bytes — for UTF-8 accented input ("città",
        // "éditeur") this both miscounts length and can truncate inside a
        // multibyte sequence, producing an invalid LIKE pattern.
        $stem = mb_strlen($q, 'UTF-8') >= 5 ? mb_substr($q, 0, -1, 'UTF-8') : $q;
        return '%' . $stem . '%';
    }

    /**
     * Filter hook: search.unified.sources
     *
     * Appends archival_units that match $q (via LIKE on reference_code, title
     * and scope_content) to the unified admin-search results array. Uses LIKE
     * rather than FULLTEXT
     * so that short reference codes ("IT-MI-1") and year fragments ("1943") are
     * found reliably regardless of MySQL's minimum full-text word length.
     *
     * Called by SearchController::unifiedSearch() via Hooks::apply().
     * Signature matches the HookManager::applyFilters() call convention:
     * the first argument is the accumulated $results array; subsequent
     * positional arguments come from the $args array passed to Hooks::apply().
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public function addArchivalSources(array $results, string $q): array
    {
        if ($q === '') {
            return $results;
        }
        $archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
        $searchPattern = $this->archiveSearchPattern($q);
        $stmt = $this->db->prepare(
            'SELECT id, reference_code, constructed_title
               FROM archival_units
              WHERE deleted_at IS NULL
                AND (
                    reference_code LIKE ?
                    OR constructed_title LIKE ?
                    OR formal_title LIKE ?
                    OR scope_content LIKE ?
                )
              ORDER BY constructed_title
              LIMIT 5'
        );
        if ($stmt === false) {
            return $results;
        }
        $stmt->bind_param('ssss', $searchPattern, $searchPattern, $searchPattern, $searchPattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $id    = (int) $row['id'];
                $title = (string) ($row['constructed_title'] ?? '');
                $slug  = slugify_text($title);
                $results[] = [
                    'id'         => $id,
                    'label'      => $title,
                    'identifier' => (string) ($row['reference_code'] ?? ''),
                    'type'       => 'archive',
                    'url'        => url($archiveBase . ($slug !== '' ? '/' . $slug . '-' . $id : '/' . $id)),
                ];
            }
            $res->free();
        }
        $stmt->close();
        return $results;
    }

    /**
     * Hook: frontend.catalog.archive_results
     * Called from FrontendController::catalog() when a search is active.
     * Returns archive units matching $q for display in the book catalog page.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    public function getPublicArchiveResults(array $results, string $q): array
    {
        if ($q === '') {
            return $results;
        }
        $archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
        $searchPattern = $this->archiveSearchPattern($q);
        $stmt = $this->db->prepare(
            'SELECT id, reference_code, level, constructed_title, scope_content
               FROM archival_units
              WHERE deleted_at IS NULL
                AND (
                    reference_code LIKE ?
                    OR constructed_title LIKE ?
                    OR formal_title LIKE ?
                    OR scope_content LIKE ?
                )
              ORDER BY FIELD(level,\'fonds\',\'series\',\'file\',\'item\'), constructed_title
              LIMIT 6'
        );
        if ($stmt === false) {
            return $results;
        }
        $stmt->bind_param('ssss', $searchPattern, $searchPattern, $searchPattern, $searchPattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $id    = (int) $row['id'];
                $title = (string) ($row['constructed_title'] ?? '');
                $slug  = slugify_text($title);
                $results[] = [
                    'id'            => $id,
                    'label'         => $title,
                    'reference_code' => (string) ($row['reference_code'] ?? ''),
                    'level'         => (string) ($row['level'] ?? ''),
                    'scope_content' => (string) ($row['scope_content'] ?? ''),
                    'url'           => url($archiveBase . ($slug !== '' ? '/' . $slug . '-' . $id : '/' . $id)),
                ];
            }
            $res->free();
        }
        $stmt->close();
        return $results;
    }

    /**
     * DDL for the `archival_units` hierarchical table.
     * Exposed as a string so tests and reviewers can inspect intent.
     *
     * ISAD(G) crosswalk (selected):
     *   reference_code         → 3.1.1 Reference code
     *   formal_title           → 3.1.2 Title (241*a in ABA format)
     *   constructed_title      → 3.1.2 Title (245*a — title given by archivist)
     *   date_start/date_end    → 3.1.3 Dates of creation
     *   predominant_dates      → 3.1.3 Dates — predominant
     *   level                  → 3.1.4 Level of description
     *   extent                 → 3.1.5 Extent and medium
     *   scope_content          → 3.3.1 Scope and content / Abstract
     *   appraisal              → 3.3.2 Appraisal / destruction
     *   accruals               → 3.3.3 Accruals
     *   arrangement_system     → 3.3.4 System of arrangement
     *   access_conditions      → 3.4.1 Conditions governing access
     *   reproduction_rules     → 3.4.2 Conditions governing reproduction
     *   language_codes         → 3.4.3 Language/scripts
     *   finding_aids           → 3.4.5 Finding aids
     *   originals_location     → 3.5.1 Existence and location of originals
     *   copies_location        → 3.5.2 Existence and location of copies
     *   related_units          → 3.5.3 Related units of description
     *   archival_history       → 3.2.3 Archival history
     *   acquisition_source     → 3.2.4 Immediate source of acquisition
     *   registration_date      → 3.7.3 Date(s) of descriptions
     */
    public static function ddlArchivalUnits(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archival_units (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id           BIGINT UNSIGNED NULL,
            reference_code      VARCHAR(64)  NOT NULL,
            institution_code    VARCHAR(16)  NOT NULL DEFAULT 'PINAKES',
            level               ENUM('fonds','series','file','item') NOT NULL,
            formal_title        VARCHAR(500) NULL,
            constructed_title   VARCHAR(500) NOT NULL,
            date_start          SMALLINT NULL,
            date_end            SMALLINT NULL,
            predominant_dates   VARCHAR(255) NULL,
            date_gaps           VARCHAR(255) NULL,
            extent              VARCHAR(500) NULL,
            scope_content       TEXT NULL,
            appraisal           TEXT NULL,
            accruals            ENUM('none','completed','ongoing','irregular') NULL,
            arrangement_system  VARCHAR(255) NULL,
            access_conditions   VARCHAR(255) NULL,
            reproduction_rules  VARCHAR(255) NULL,
            language_codes      VARCHAR(64)  NULL,
            finding_aids        TEXT NULL,
            originals_location  VARCHAR(500) NULL,
            copies_location     VARCHAR(500) NULL,
            related_units       TEXT NULL,
            archival_history    TEXT NULL,
            acquisition_source  VARCHAR(500) NULL,
            physical_location   VARCHAR(255) NULL,
            material_status     ENUM('unclassified','cataloguing','completed') NOT NULL DEFAULT 'unclassified',
            registration_date   DATE NULL,
            /* Phase 5 — photographic items (ABA billedmarc) */
            specific_material   ENUM('text','photograph','poster','postcard','drawing','audio','video','other','map','picture','object','film','microform','electronic','mixed') NOT NULL DEFAULT 'text',
            dimensions          VARCHAR(100) NULL,
            color_mode          ENUM('bw','color','mixed') NULL,
            photographer        VARCHAR(255) NULL,
            publisher           VARCHAR(255) NULL,
            collection_name     VARCHAR(255) NULL,
            local_classification VARCHAR(64) NULL,
            cover_image_path    VARCHAR(500) NULL,
            document_path       VARCHAR(500) NULL,
            document_mime       VARCHAR(100) NULL,
            document_filename   VARCHAR(255) NULL,
            iiif_manifest_url   VARCHAR(2000) NULL,
            rights_statement_url VARCHAR(500) NULL,
            ark_identifier      VARCHAR(255) NULL,
            version_note        VARCHAR(500) NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at          TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_reference (institution_code, reference_code),
            UNIQUE KEY uq_ark_identifier (ark_identifier),
            KEY idx_parent (parent_id),
            KEY idx_level (level),
            KEY idx_dates (date_start, date_end),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_search (formal_title, constructed_title, scope_content, archival_history),
            CONSTRAINT fk_archival_parent FOREIGN KEY (parent_id) REFERENCES archival_units(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `authority_records` (ISAAR(CPF)).
     *
     * Kept separate from the existing `autori` table because ISAAR covers
     * corporate bodies + families in addition to persons, and carries a
     * richer element set (dates of existence, history, functions, mandates).
     *
     * ISAAR(CPF) crosswalk (selected):
     *   type                 → 5.1.1 Type of entity (person/corporate/family)
     *   authorised_form      → 5.1.2 Authorized form of name
     *   parallel_forms       → 5.1.3 Parallel forms of name
     *   other_forms          → 5.1.5 Other forms of name
     *   identifiers          → 5.1.6 Identifiers
     *   dates_of_existence   → 5.2.1 Dates of existence (birth/death, founded/dissolved)
     *   history              → 5.2.2 History
     *   places               → 5.2.3 Places
     *   legal_status         → 5.2.4 Legal status
     *   functions            → 5.2.5 Functions, occupations, activities
     *   mandates             → 5.2.6 Mandates / sources of authority
     *   internal_structure   → 5.2.7 Internal structure / genealogy
     *   general_context      → 5.2.8 General context
     */
    public static function ddlAuthorityRecords(): string
    {
        // Phase 2 (v0.7.8 — issue #122) added ric_type / birth_date /
        // death_date / place_of_origin. Fresh installs get the full
        // shape from this DDL; in-place upgrades pick up the new
        // columns via migrateAuthorityRecordsRicColumns().
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS authority_records (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type               ENUM('person','corporate','family') NOT NULL,
            ric_type           ENUM('Person','CorporateBody','Family','Position','Group') NOT NULL DEFAULT 'Person',
            authorised_form    VARCHAR(500) NOT NULL,
            parallel_forms     TEXT NULL,
            other_forms        TEXT NULL,
            identifiers        VARCHAR(500) NULL,
            dates_of_existence VARCHAR(255) NULL,
            birth_date         VARCHAR(20)  NULL,
            death_date         VARCHAR(20)  NULL,
            place_of_origin    VARCHAR(255) NULL,
            history            TEXT NULL,
            places             TEXT NULL,
            legal_status       VARCHAR(255) NULL,
            functions          TEXT NULL,
            mandates           TEXT NULL,
            internal_structure TEXT NULL,
            general_context    TEXT NULL,
            gender             ENUM('female','male','other','unknown') NULL,
            external_refs      TEXT NULL,
            created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at         TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY idx_type (type),
            KEY idx_ric_type (ric_type),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_search (authorised_form, parallel_forms, history, functions)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for the many-to-many link between archival_units and authority_records.
     * Mirrors MARC fields 610/700/710 (subject + added entry) from ABA format.
     */
    public static function ddlArchivalAuthorityLinks(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archival_unit_authority (
            archival_unit_id BIGINT UNSIGNED NOT NULL,
            authority_id     BIGINT UNSIGNED NOT NULL,
            role             ENUM('creator','subject','recipient','custodian','associated') NOT NULL DEFAULT 'subject',
            PRIMARY KEY (archival_unit_id, authority_id, role),
            KEY idx_authority (authority_id, role),
            CONSTRAINT fk_aua_unit FOREIGN KEY (archival_unit_id) REFERENCES archival_units(id) ON DELETE CASCADE,
            CONSTRAINT fk_aua_auth FOREIGN KEY (authority_id)     REFERENCES authority_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for the M:N link between existing `autori` rows (the bibliographic
     * author table) and `authority_records`. Lets an archivist reconcile a
     * library-side author with an ISAAR authority so fonds/series/files
     * related to that person or corporate body surface in unified search
     * alongside their books.
     *
     * Composite PK (autori_id, authority_id) — one link per pair, which is
     * the realistic case (no role here: "who IS who" is not a role, it's an
     * identity assertion). FK to `autori.id` without a hard ON DELETE action
     * because removing an author row is already a significant operation in
     * the core schema; we'll need manual cleanup if the core ever soft-deletes
     * authors (it currently hard-deletes via `AutoriController`).
     */
    public static function ddlAutoriAuthorityLink(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS autori_authority_link (
            autori_id    INT             NOT NULL,
            authority_id BIGINT UNSIGNED NOT NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (autori_id, authority_id),
            KEY idx_authority_autore (authority_id),
            CONSTRAINT fk_aal_autore    FOREIGN KEY (autori_id)    REFERENCES autori(id) ON DELETE CASCADE,
            CONSTRAINT fk_aal_authority FOREIGN KEY (authority_id) REFERENCES authority_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    public static function ddlArchivalUnitFiles(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archival_unit_files (
            id                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            unit_id           BIGINT UNSIGNED   NOT NULL,
            file_path         VARCHAR(500)      NOT NULL,
            file_mime         VARCHAR(127)      NOT NULL DEFAULT 'application/octet-stream',
            original_filename VARCHAR(255)      NOT NULL DEFAULT '',
            sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at        TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_unit_id (unit_id),
            CONSTRAINT fk_archival_unit_files_unit
                FOREIGN KEY (unit_id) REFERENCES archival_units(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_agent_identifiers` (RiC-CM Phase 2 — v0.7.8).
     *
     * Multi-scheme identifier ledger for archive authorities. An
     * authority can carry a VIAF id, an ISNI id, AND a Wikidata id at
     * the same time; the legacy `authority_records.identifiers` blob
     * could only hold one. New rows are added by the admin form or
     * imported from VIAF / ISNI lookup tools; the RiC-O JSON-LD output
     * emits each entry under `owl:sameAs`.
     */
    public static function ddlAgentIdentifiers(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_agent_identifiers (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            authority_id BIGINT UNSIGNED NOT NULL,
            scheme       ENUM('viaf','isni','wikidata','gnd','bnf','lcnaf','ulan','ark','local') NOT NULL,
            value        VARCHAR(255) NOT NULL,
            uri          VARCHAR(500) NULL,
            is_preferred TINYINT(1) NOT NULL DEFAULT 0,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_authority_id (authority_id),
            KEY idx_scheme_value (scheme, value(64)),
            UNIQUE KEY uq_authority_scheme_value (authority_id, scheme, value(128)),
            CONSTRAINT fk_aai_authority FOREIGN KEY (authority_id)
                REFERENCES authority_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_agent_relations` (RiC-CM Phase 2 — v0.7.8).
     *
     * Agent ↔ Agent edges typed with a RiC-O predicate. Captures
     * organisational hierarchies (ric:isMemberOf), corporate
     * successions (ric:isSuccessorOf), family ties (ric:isParentOf,
     * ric:isSiblingOf, ric:isMarriedTo), and arbitrary associations
     * (ric:isAssociatedWith). The predicate column is VARCHAR rather
     * than ENUM because RiC-O's agent-to-agent vocabulary is open;
     * validation lives in the application layer.
     *
     * The CHECK constraint rejects self-loops at the schema layer on
     * MySQL 8.0.16+. Older versions parse but don't enforce CHECKs —
     * the application validator covers the fallback.
     */
    public static function ddlAgentRelations(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_agent_relations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_id      BIGINT UNSIGNED NOT NULL,
            related_id    BIGINT UNSIGNED NOT NULL,
            ric_predicate VARCHAR(128) NOT NULL,
            qualifier     VARCHAR(255) NULL,
            date_start    VARCHAR(20)  NULL,
            date_end      VARCHAR(20)  NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_agent (agent_id),
            KEY idx_related (related_id),
            KEY idx_predicate (ric_predicate),
            UNIQUE KEY uq_agent_related_predicate (agent_id, related_id, ric_predicate),
            CONSTRAINT fk_aar_agent
                FOREIGN KEY (agent_id)   REFERENCES authority_records(id) ON DELETE CASCADE,
            CONSTRAINT fk_aar_related
                FOREIGN KEY (related_id) REFERENCES authority_records(id) ON DELETE CASCADE,
            CONSTRAINT chk_aar_no_self_loop CHECK (agent_id <> related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_activities` (RiC-CM Phase 3 — v0.7.9).
     *
     * First-class Activity entity corresponding to ISDF. Captures any
     * human or organisational activity that produced, used, or
     * managed archival material. Self-referential parent_id supports
     * the ISDF function → activity → transaction hierarchy.
     */
    public static function ddlArchiveActivities(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_activities (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title         VARCHAR(500) NOT NULL,
            description   TEXT NULL,
            activity_type ENUM('function','activity','transaction','task','mandate') NOT NULL DEFAULT 'activity',
            parent_id     BIGINT UNSIGNED NULL,
            date_start    VARCHAR(20) NULL,
            date_end      VARCHAR(20) NULL,
            is_ongoing    TINYINT(1) NOT NULL DEFAULT 0,
            agent_id      BIGINT UNSIGNED NULL,
            place_id      BIGINT UNSIGNED NULL,
            source_ref    VARCHAR(500) NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at    TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_agent (agent_id),
            KEY idx_type (activity_type),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_activity_search (title, description),
            CONSTRAINT fk_activity_parent FOREIGN KEY (parent_id)
                REFERENCES archive_activities(id) ON DELETE SET NULL,
            CONSTRAINT fk_activity_agent  FOREIGN KEY (agent_id)
                REFERENCES authority_records(id) ON DELETE SET NULL
            -- Self-parent and deeper-cycle guards live in
            -- ArchivesPlugin::activityWouldCreateCycle() (application
            -- layer) because MySQL rejects a CHECK constraint on a
            -- column that's also part of a FK referential action
            -- (ON DELETE SET NULL).
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_unit_activities` (RiC-CM Phase 3 — v0.7.9).
     *
     * M:N link between archival units and activities. The
     * `ric_predicate` column captures the nature of the link in
     * RiC-O terms — `ric:resultsOrResultedFrom`, `ric:isOrWasUsedBy`,
     * `ric:isSubjectOf`. The column is VARCHAR (open vocabulary)
     * rather than ENUM because the RiC-O predicate set is open and
     * the admin form validates against a known allow-list.
     */
    public static function ddlArchiveUnitActivities(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_unit_activities (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id        BIGINT UNSIGNED NOT NULL,
            activity_id    BIGINT UNSIGNED NOT NULL,
            ric_predicate  VARCHAR(128) NOT NULL DEFAULT 'ric:resultsOrResultedFrom',
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_unit_activity_predicate (unit_id, activity_id, ric_predicate),
            KEY idx_unit (unit_id),
            KEY idx_activity (activity_id),
            KEY idx_predicate (ric_predicate),
            CONSTRAINT fk_ua_unit
                FOREIGN KEY (unit_id)     REFERENCES archival_units(id)     ON DELETE CASCADE,
            CONSTRAINT fk_ua_activity
                FOREIGN KEY (activity_id) REFERENCES archive_activities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_places` (RiC-CM Phase 4 — v0.7.10).
     *
     * First-class Place entity (RiC-CM §3.5). Lat/lng for map display,
     * GeoNames / Wikidata / Getty TGN identifiers for external linking,
     * self-referential parent_id for the place hierarchy
     * (Catania → Sicilia → Italia).
     */
    public static function ddlArchivePlaces(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_places (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(500) NOT NULL,
            place_type   ENUM('country','region','province','municipality','locality','building','room','geographic_feature','other') NOT NULL DEFAULT 'locality',
            parent_id    BIGINT UNSIGNED NULL,
            latitude     DECIMAL(10,7) NULL,
            longitude    DECIMAL(10,7) NULL,
            geonames_id  VARCHAR(20)   NULL,
            wikidata_id  VARCHAR(20)   NULL,
            tgn_id       VARCHAR(20)   NULL,
            description  TEXT          NULL,
            date_start   VARCHAR(20)   NULL,
            date_end     VARCHAR(20)   NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at   TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY idx_parent (parent_id),
            KEY idx_place_type (place_type),
            KEY idx_geonames (geonames_id),
            KEY idx_wikidata (wikidata_id),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_place_search (name, description),
            CONSTRAINT fk_place_parent FOREIGN KEY (parent_id)
                REFERENCES archive_places(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `archive_relations` (RiC-CM Phase 4 — v0.7.10).
     *
     * Polymorphic N:M relations between any two RiC-CM entities. No
     * FK on source_id/target_id because MySQL can't reference "one of
     * several tables" — referential integrity is enforced by the
     * application layer (validateRelationEndpoints) and by the
     * archive_relations sweep on hard-deletes.
     */
    public static function ddlArchiveRelations(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archive_relations (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type   ENUM('archival_unit','authority_record','archive_activity','archive_place') NOT NULL,
            source_id     BIGINT UNSIGNED NOT NULL,
            target_type   ENUM('archival_unit','authority_record','archive_activity','archive_place') NOT NULL,
            target_id     BIGINT UNSIGNED NOT NULL,
            ric_predicate VARCHAR(128) NOT NULL,
            qualifier     VARCHAR(500) NULL,
            certainty     ENUM('certain','probable','uncertain') NOT NULL DEFAULT 'certain',
            date_start    VARCHAR(20)  NULL,
            date_end      VARCHAR(20)  NULL,
            source_ref    VARCHAR(500) NULL,
            created_by    BIGINT UNSIGNED NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_relation (source_type, source_id, target_type, target_id, ric_predicate),
            KEY idx_source (source_type, source_id),
            KEY idx_target (target_type, target_id),
            KEY idx_predicate (ric_predicate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }
}
