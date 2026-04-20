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
     * Authority-record types, per ISAAR(CPF).
     */
    public const AUTHORITY_TYPES = [
        'person'    => 'Single person (biographical authority)',
        'corporate' => 'Corporate body (organisation, union, party)',
        'family'    => 'Family (genealogical authority)',
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
     * Creates the archival schema if missing + registers the `app.routes.register`
     * hook so registerRoutes() runs during every request bootstrap. Idempotent.
     */
    public function onActivate(): void
    {
        $this->ensureSchema();
        $this->registerHookInDb('app.routes.register', 'registerRoutes', 10);
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

        return ['created' => $created, 'failed' => $failed];
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
        return $this->renderView($response, 'form', [
            'levels' => array_keys(self::LEVELS),
            'values' => [],
            'errors' => [],
        ]);
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
        $body = (array) $request->getParsedBody();

        $values = [
            'reference_code'    => trim((string) ($body['reference_code'] ?? '')),
            'institution_code'  => trim((string) ($body['institution_code'] ?? 'PINAKES')),
            'level'             => (string) ($body['level'] ?? ''),
            'formal_title'      => trim((string) ($body['formal_title'] ?? '')),
            'constructed_title' => trim((string) ($body['constructed_title'] ?? '')),
            'date_start'        => $body['date_start'] !== '' && isset($body['date_start']) ? (int) $body['date_start'] : null,
            'date_end'          => $body['date_end']   !== '' && isset($body['date_end'])   ? (int) $body['date_end']   : null,
            'extent'            => trim((string) ($body['extent'] ?? '')),
            'scope_content'     => trim((string) ($body['scope_content'] ?? '')),
            'language_codes'    => trim((string) ($body['language_codes'] ?? 'ita')),
            'parent_id'         => isset($body['parent_id']) && $body['parent_id'] !== '' ? (int) $body['parent_id'] : null,
        ];

        $errors = $this->validateArchivalUnit($values);

        if (!empty($errors)) {
            return $this->renderView($response, 'form', [
                'levels' => array_keys(self::LEVELS),
                'values' => $values,
                'errors' => $errors,
            ]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO archival_units
                (parent_id, reference_code, institution_code, level, formal_title,
                 constructed_title, date_start, date_end, extent, scope_content, language_codes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            SecureLogger::error('[Archives] store prepare failed: ' . $this->db->error);
            $errors['_global'] = 'Database error. Check the log and retry.';
            return $this->renderView($response, 'form', [
                'levels' => array_keys(self::LEVELS),
                'values' => $values,
                'errors' => $errors,
            ]);
        }

        $formalTitleParam = $values['formal_title'] !== '' ? $values['formal_title'] : null;
        $extentParam      = $values['extent']       !== '' ? $values['extent']       : null;
        $scopeParam       = $values['scope_content']!== '' ? $values['scope_content']: null;

        $stmt->bind_param(
            'issssssissss',
            // MySQLi requires strict param types in order. i=int, s=string.
            // We pass all nullables as strings when null because mysqli coerces.
            $values['parent_id'],
            $values['reference_code'],
            $values['institution_code'],
            $values['level'],
            $formalTitleParam,
            $values['constructed_title'],
            $values['date_start'],
            $values['date_end'],
            $extentParam,
            $scopeParam,
            $values['language_codes']
        );

        if (!$stmt->execute()) {
            SecureLogger::error('[Archives] INSERT failed: ' . $stmt->error);
            $stmt->close();
            $errors['_global'] = 'Insert failed. ' . ($this->db->errno === 1062 ? 'Reference code already exists.' : 'Check the log.');
            return $this->renderView($response, 'form', [
                'levels' => array_keys(self::LEVELS),
                'values' => $values,
                'errors' => $errors,
            ]);
        }
        $stmt->close();

        return $response
            ->withHeader('Location', '/admin/archives')
            ->withStatus(303);
    }

    /**
     * Validate a payload for insert. Returns map of field → error message,
     * empty map on success.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validateArchivalUnit(array $values): array
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
}
