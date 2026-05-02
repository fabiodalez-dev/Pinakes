<?php

declare(strict_types=1);

namespace App\Plugins\Archives;

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
        $this->registerHookInDb('app.routes.register', 'registerRoutes',       10);
        $this->registerHookInDb('admin.menu.render',   'renderAdminMenuEntry', 10);
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
            SecureLogger::error('[Archives] prepare() failed: ' . $this->db->error);
            return;
        }
        // PluginManager instantiates the global proxy class (no namespace).
        $callbackClass = 'ArchivesPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] hook insert failed: ' . $stmt->error);
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
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Execute the DDL for archival_units, authority_records, and the
     * M:N link table. Errors are logged but don't abort activation —
     * the admin can inspect the log and retry. Partial success is
     * acceptable because each CREATE is IF NOT EXISTS + independent.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array
    {
        $steps = [
            'archival_units'          => self::ddlArchivalUnits(),
            'authority_records'       => self::ddlAuthorityRecords(),
            'archival_unit_authority' => self::ddlArchivalAuthorityLinks(),
            'autori_authority_link'   => self::ddlAutoriAuthorityLink(),
        ];
        // Phase 5 migration: add image columns to archival_units if they're
        // not already there. Harmless no-op on fresh installs because the
        // DDL above already declares them; essential on installs created
        // before phase 5 where the CREATE TABLE IF NOT EXISTS won't touch
        // an existing table.
        $this->migrateImageColumns();

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

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Additive migration for phase 5 image-item columns. Checks the
     * information_schema for each column and runs an ALTER TABLE ADD
     * COLUMN when missing. Idempotent; safe to call on every activation.
     *
     * MySQL 5.7 / MariaDB 10.x don't support ADD COLUMN IF NOT EXISTS
     * consistently, which is why we pre-check instead.
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

        foreach ($columns as $col => $definition) {
            if (isset($existing[$col])) {
                continue;
            }
            // Column names are hard-coded above; definition comes from our
            // own source. Not user-supplied → safe to interpolate.
            $sql = 'ALTER TABLE archival_units ADD COLUMN ' . $col . ' ' . $definition;
            try {
                if ($this->db->query($sql) === false) {
                    SecureLogger::warning(
                        '[Archives] migrate image columns: ALTER failed for ' . $col . ': ' . $this->db->error
                    );
                }
            } catch (\Throwable $e) {
                SecureLogger::error(
                    '[Archives] migrate image columns: exception on ' . $col . ': ' . $e->getMessage()
                );
            }
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
        foreach (['it_IT', 'en_US', 'de_DE'] as $locale) {
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
        $rows = [];
        // Order by level first so fonds appear before their series/files/items,
        // then by reference_code for stable ordering inside a level. A proper
        // recursive CTE tree view is roadmapped for Phase 2.
        $result = $this->db->query(
            "SELECT id, parent_id, reference_code, level, constructed_title, formal_title,
                    date_start, date_end, extent, language_codes, created_at
               FROM archival_units
              WHERE deleted_at IS NULL
              ORDER BY FIELD(level, 'fonds','series','file','item'), reference_code ASC
              LIMIT 500"
        );
        if ($result instanceof \mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        } else {
            SecureLogger::warning('[Archives] index query failed: ' . $this->db->error);
        }

        return $this->renderView($response, 'index', ['rows' => $rows]);
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
                 photographer, publisher, collection_name, local_classification)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] store prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderArchivalForm($response, 'create', null, $values, $errors);
        }

        $p = $this->nullableArchivalParams($values);
        // 18 params: 1 int, 3 strings, 2 strings, 2 ints, 3 strings, 1 string (specific_material), 6 strings
        // Layout: parent_id(i) ref(s) inst(s) level(s) formal(s) constructed(s) date_start(i) date_end(i)
        //         extent(s) scope(s) lang(s) spec_material(s) dimensions(s) color_mode(s) photographer(s)
        //         publisher(s) collection(s) local_class(s)  → 'isssssiisssssssssss'
        //                                                      minus one 's' (counted 19) = 'isssssiissssssssss' (18)
        $stmt->bind_param(
            'isssssiissssssssss',
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
            $p['local_classification']
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
            'linked_authorities'   => $this->fetchAuthoritiesForArchivalUnit($id),
            'available_authorities'=> $this->listAllAuthorities(),
            'authority_roles'      => array_keys(self::AUTHORITY_ROLES),
            'headLinks'            => [
                ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'Dublin Core',
                 'href' => absoluteUrl('/archives/' . $id . '/dc.xml')],
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
                photographer = ?, publisher = ?, collection_name = ?, local_classification = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] update prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderArchivalForm($response, 'edit', $id, $values, $errors);
        }

        $p = $this->nullableArchivalParams($values);
        // 19 params: 18 INSERT-columns + id (int). Type string:
        //   parent_id(i) ref(s) inst(s) level(s) formal(s) constructed(s)
        //   date_start(i) date_end(i) extent(s) scope(s) lang(s)
        //   spec_material(s) dimensions(s) color(s) photographer(s)
        //   publisher(s) collection(s) local_class(s) id(i)
        //   = 'isssssiisssssssssssi'   (no — count again)
        //   Breakdown: i + s*3 + s*2 + i*2 + s*3 + s*7 + i = 'i' + 'sss' + 'ss' + 'ii' + 'sss' + 'sssssss' + 'i'
        //   = 'isssss' + 'ii' + 'sssssssssss' + 'i' = 'isssssiisssssssssssi' = 20 chars for 19 params
        //   Let's enumerate: i s s s s s i i s s s s s s s s s s i = 19 types, 'isssssiisssssssssssi' = 20, off by one.
        //   Correct is 'isssssiissssssssssi' (19 chars).
        $stmt->bind_param(
            'isssssiissssssssssi',
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
            SecureLogger::error('[Archives] delete prepare failed: ' . $this->db->error);
            return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return $response->withHeader('Location', '/admin/archives')->withStatus(303);
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
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
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

        // Remove any previous file to avoid orphans.
        $existingPathField = $kind === 'cover' ? 'cover_image_path' : 'document_path';
        $existingPath = (string) ($row[$existingPathField] ?? '');
        if ($existingPath !== '') {
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
            // Strip any path component from the client-provided name.
            $clientName = basename($clientName);
            $stmt = $this->db->prepare(
                'UPDATE archival_units SET document_path = ?, document_mime = ?, document_filename = ? WHERE id = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('sssi', $relPath, $mime, $clientName, $id);
                $stmt->execute();
                $stmt->close();
            }
        }
        return $response->withHeader('Location', '/admin/archives/' . $id)->withStatus(303);
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
            SecureLogger::error('[Archives] authority delete prepare failed: ' . $this->db->error);
            return $response->withHeader('Location', '/admin/archives/authorities/' . $id)->withStatus(303);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return $response->withHeader('Location', '/admin/archives/authorities')->withStatus(303);
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
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        return $response
            ->withHeader('Location', '/admin/archives/' . $archivalUnitId)
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
                $stmt->execute();
                $stmt->close();
            }
        }
        return $response
            ->withHeader('Location', '/admin/archives/authorities/' . $authorityId)
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
        $stmt = $this->db->prepare(
            'SELECT au.id, au.reference_code, au.level, au.constructed_title, aua.role
               FROM archival_unit_authority aua
               JOIN archival_units au ON au.id = aua.archival_unit_id AND au.deleted_at IS NULL
              WHERE aua.authority_id = ?
              ORDER BY FIELD(au.level,\'fonds\',\'series\',\'file\',\'item\'), au.reference_code'
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
     * Return all authorities linked to the given archival_unit_id.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAuthoritiesForArchivalUnit(int $archivalUnitId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT ar.id, ar.type, ar.authorised_form, ar.dates_of_existence, aua.role
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
     * Lists all root-level (parent_id IS NULL) archival_units. Anonymous
     * browsing; no auth required.
     */
    public function publicIndexAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $rows = [];
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
                while ($r = $result->fetch_assoc()) {
                    $rows[] = $r;
                }
                $result->free();
            }
            $stmt->close();
        }
        $viewPath = __DIR__ . '/views/public/index.php';
        return $this->renderPublic($response, $viewPath, [
            'rows' => $rows,
            'total' => count($rows),
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
            $headLinks[] = ['rel' => 'alternate', 'type' => 'application/xml', 'title' => 'Dublin Core',
                            'href' => absoluteUrl('/archives/' . (int) $data['row']['id'] . '/dc.xml')];
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
            $xw->startElementNs('sru', 'records', null);
            $position = $startRecord;
            foreach ($rows as $row) {
                $xw->startElementNs('sru', 'record', null);
                $xw->writeElementNs('sru', 'recordSchema', null, 'info:srw/schema/1/marcxml-v1.1');
                $xw->writeElementNs('sru', 'recordPacking', null, 'xml');
                $xw->startElementNs('sru', 'recordData', null);
                $authorities = $this->fetchAuthoritiesForArchivalUnit((int) $row['id']);
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

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'collection', 'http://www.loc.gov/MARC21/slim');
        foreach ($rows as $row) {
            $auth = $this->fetchAuthoritiesForArchivalUnit((int) $row['id']);
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
    private function validateArchivalUnit(array $values, ?int $excludeId = null): array
    {
        $errors = [];
        // $excludeId is reserved for reference_code uniqueness pre-check once
        // we add it in phase 2 — currently enforced by the DB UNIQUE KEY and
        // surfaced via errno 1062 in storeAction/updateAction.
        unset($excludeId);

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
            $xw->writeElementNs('dc', 'language', null, (string) $row['language_codes']);
        }
        if (!empty($row['access_conditions'])) {
            $xw->writeElementNs('dc', 'rights', null, (string) $row['access_conditions']);
        }

        $xw->endElement(); // oai_dc:dc
    }

    // ── EAD3 export ──────────────────────────────────────────────────────────

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
        $xw->startElement('eadlist');
        $xw->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        foreach ($rows as $row) {
            $auth = $this->fetchAuthoritiesForArchivalUnit((int) $row['id']);
            $this->writeEad3Document($xw, $row, $auth);
        }
        $xw->endElement(); // eadlist
        $xw->endDocument();

        $xml = $xw->outputMemory();
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="archives_export.ead3.xml"');
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
    private function writeEad3Document(\XMLWriter $xw, array $row, array $authorities): void
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

        $xw->writeElement('recordid', (string) ($row['reference_code'] ?? 'pinakes_' . $row['id']));

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
        $createdAt = (string) ($row['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $xw->writeAttribute('standarddatetime', $createdAt);
        $xw->text($createdAt);
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
                $tag = $authType === 'corporate' ? 'corpname' : 'persname';
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
     *   ListSets              — archival levels as OAI sets
     *
     * OAI identifier scheme: oai:{hostname}:pinakes:{id}
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

        // <request> element with verb and any legal params
        $xw->startElement('request');
        if ($verb !== '') { $xw->writeAttribute('verb', $verb); }
        foreach (['metadataPrefix', 'identifier', 'from', 'until', 'set', 'resumptionToken'] as $k) {
            if (!empty($params[$k])) { $xw->writeAttribute($k, (string) $params[$k]); }
        }
        $xw->text($baseUrl);
        $xw->endElement();

        match ($verb) {
            'Identify'            => $this->oaiIdentify($xw, $baseUrl, $now),
            'ListMetadataFormats' => $this->oaiListMetadataFormats($xw),
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
        $xw->endElement(); // Identify
    }

    private function oaiListMetadataFormats(\XMLWriter $xw): void
    {
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

        $pageSize = 100;
        $where    = ['deleted_at IS NULL'];
        $bindTypes = '';
        $bindVals  = [];

        if ($from !== '') {
            $where[]     = 'updated_at >= ?';
            $bindTypes  .= 's';
            $bindVals[]  = str_replace('T', ' ', str_replace('Z', '', $from));
        }
        if ($until !== '') {
            $where[]    = 'updated_at <= ?';
            $bindTypes .= 's';
            $bindVals[] = str_replace('T', ' ', str_replace('Z', '', $until));
        }
        if ($set !== '' && in_array($set, ['fonds', 'series', 'file', 'item'], true)) {
            $where[]    = 'level = ?';
            $bindTypes .= 's';
            $bindVals[] = $set;
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
            if (!empty($row['level'])) {
                $xw->writeElement('setSpec', (string) $row['level']);
            }
            $xw->endElement(); // header

            if (!$identifiersOnly) {
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
        if (!empty($row['level'])) {
            $xw->writeElement('setSpec', (string) $row['level']);
        }
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
        $xw->startElement('ListSets');
        $levels = [
            'fonds'  => 'Fonds — complete archive of a creator (ISAD(G) level 1)',
            'series' => 'Series — grouping by provenance or function (ISAD(G) level 2)',
            'file'   => 'File — organic unit (case file, volume) (ISAD(G) level 3)',
            'item'   => 'Item — smallest indivisible unit (ISAD(G) level 4)',
        ];
        foreach ($levels as $spec => $desc) {
            $xw->startElement('set');
            $xw->writeElement('setSpec', $spec);
            $xw->writeElement('setName', $desc);
            $xw->endElement();
        }
        $xw->endElement(); // ListSets
    }

    private function oaiError(\XMLWriter $xw, string $code, string $message): void
    {
        $xw->startElement('error');
        $xw->writeAttribute('code', $code);
        $xw->text($message);
        $xw->endElement();
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
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at          TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_reference (institution_code, reference_code),
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
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS authority_records (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type               ENUM('person','corporate','family') NOT NULL,
            authorised_form    VARCHAR(500) NOT NULL,
            parallel_forms     TEXT NULL,
            other_forms        TEXT NULL,
            identifiers        VARCHAR(500) NULL,
            dates_of_existence VARCHAR(255) NULL,
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
            CONSTRAINT fk_aal_authority FOREIGN KEY (authority_id) REFERENCES authority_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }
}
