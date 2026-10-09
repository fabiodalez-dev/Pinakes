<?php

declare(strict_types=1);

namespace App\Plugins\OaiPmhServer;

/** Thrown when a record cannot be serialised in the requested metadata format. */
class CannotDisseminateFormatException extends \RuntimeException
{
    public function __construct(string $prefix)
    {
        parent::__construct("Format not supported for this record type: {$prefix}");
    }
}

use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OAI-PMH 2.0 server plugin for Pinakes — unified books + archives endpoint.
 *
 * Endpoint: GET/POST /oai
 * Supported verbs: Identify, ListMetadataFormats, ListRecords, ListIdentifiers,
 *                  GetRecord, ListSets
 * Metadata formats: oai_dc, marcxml, mods, mag, unimarc
 * Sets: books, archives (only when the archives plugin is active),
 *       periodicals (only when the emeroteca plugin is active)
 * deletedRecord: persistent (tracked via oai_deleted_records +
 *       oai_deleted_periodicals + MySQL triggers). Books and archival units
 *       are soft-deleted (BEFORE UPDATE triggers); periodical mastheads are
 *       hard-deleted by their plugin, so an AFTER DELETE trigger writes their
 *       tombstone. Tombstones are only recorded from the moment the trigger
 *       exists: mastheads deleted BEFORE this plugin version was installed
 *       cannot be reconstructed and stay invisible to harvesters.
 * Resumption tokens: DB-backed with 24h TTL
 *
 * OAI identifier scheme:
 *   books           → oai:{host}:book:{id}
 *   archival units  → oai:{host}:archival_unit:{id}
 *   periodicals     → oai:{host}:periodical:{id}
 */
class OaiPmhServerPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /** Cached result of the archival_units table existence check. */
    private ?bool $archivalUnitsTableExists = null;

    /** Cached result of the emeroteca_testate table existence check. */
    private ?bool $periodicalsTableCache = null;

    /**
     * Per-request memoization of isPeriodicalsSetExposed() — same rationale
     * as $ricExposedCache below (ListSets + ListRecords + GetRecord all hit
     * the gate within one OAI request).
     */
    private ?bool $periodicalsExposedCache = null;

    /**
     * FIX F009: per-request memoization of isArchivesSetExposed() result.
     * PluginManager construction + INFORMATION_SCHEMA query are non-trivial;
     * ListMetadataFormats + GetRecord + ListRecords each call this gate, so
     * a typical OAI request fires the same check ≥3 times. The cache is
     * implicitly reset between HTTP requests because a fresh plugin
     * instance is constructed per request.
     */
    private ?bool $ricExposedCache = null;

    /** Page size for ListRecords / ListIdentifiers */
    private const PAGE_SIZE = 100;

    /** Resumption token TTL in seconds (24 hours) */
    private const TOKEN_TTL = 86400;

    /** MARCXchange XML container for UNIMARC records (ISO 25577). */
    private const XLINK_NS    = 'http://www.w3.org/1999/xlink';
    /** NISO Z39.87 data dictionary namespace MAG 2.0.1 imports for image metrics. */
    private const NISO_MAG_NS = 'http://www.niso.org/pdfs/DataDict.pdf';

    /**
     * MAG 2.0.1 (ICCU): the schema's targetNamespace is the historical
     * http://www.iccu.sbn.it/metaAG1.pdf, not a /mag/ path; validators and
     * harvesters (Internet Culturale, MagTeca) match on it. The schema file is
     * metadigit.xsd, linked from ICCU's MAG 2.0.1 page.
     */
    private const MAG_NS = 'http://www.iccu.sbn.it/metaAG1.pdf';
    private const MAG_SCHEMA = 'https://www.iccu.sbn.it/pagine/metadigit.xsd';
    private const NS_MARCXCHANGE = 'info:lc/xmlns/marcxchange-v2';
    /**
     * UNIMARC leader template (record length and base address are filled by
     * the ISO 2709 serializer): n a m, pos 8-9 blank (pos 9 undefined in
     * UNIMARC), indicator/subfield counts 2 2, pos 17-19 blank (full level,
     * pos 18 blank because ISBD punctuation is not emitted), entry map 4500.
     */
    private const UNIMARC_LEADER_TEMPLATE = '00000nam  2200000   4500';
    private const SCHEMA_MARCXCHANGE = 'http://www.loc.gov/standards/iso25577/marcxchange-2-0.xsd';

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db          = $db;
        $this->hookManager = $hookManager;
    }

    public function getHookManager(): HookManager
    {
        return $this->hookManager;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[OaiPmhServer] Schema activation failed for: ' . implode(', ', $result['failed'])
            );
        }
        $this->db->begin_transaction();
        try {
            $this->registerHookInDb('app.routes.register', 'registerRoutes', 10);
            $this->registerHookInDb('book.form.fields', 'renderBookDigitalAssets', 20);
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
                '[OaiPmhServer] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }
    }

    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
    }

    // FIX F057: previously empty — left orphaned MySQL triggers
    // (trg_libri_soft_delete, trg_archival_soft_delete) on `libri` and
    // `archival_units` which would still fire on every soft-delete, inserting
    // ghost rows into oai_deleted_records even after the plugin was removed.
    //
    // We drop the triggers explicitly. The tracking tables (oai_deleted_records,
    // oai_resumption_tokens) are intentionally KEPT so historic deletion data
    // is preserved across uninstall/reinstall cycles — preserving OAI harvester
    // semantics (deletedRecord: persistent). Reinstalling the plugin will
    // re-create the triggers via ensureSchema()/installTriggers().
    public function onUninstall(): void
    {
        foreach (['trg_libri_soft_delete', 'trg_archival_soft_delete', 'trg_emeroteca_hard_delete'] as $trg) {
            if ($this->db->query("DROP TRIGGER IF EXISTS `{$trg}`") === false) {
                SecureLogger::warning(
                    '[OaiPmhServer] onUninstall: DROP TRIGGER failed for ' . $trg
                    . ': ' . $this->db->error
                );
            }
        }
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    /**
     * @return array{created:list<string>, failed:list<string>}
     */
    /**
     * Tables this plugin's ensureSchema() always creates. Declared so
     * PluginManager's boot-time self-heal re-runs ensureSchema when any is
     * missing on an already-active plugin (a partial/aborted upgrade). One
     * cheap read-only probe; DDL only runs when a table is actually absent.
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return array_keys(self::schemaSteps());
    }

    /** @return array<string,string> table => CREATE DDL, in dependency order. */
    private static function schemaSteps(): array
    {
        return [
            'oai_deleted_records' => "CREATE TABLE IF NOT EXISTS oai_deleted_records (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type  ENUM('book','archival_unit') NOT NULL,
                entity_id    BIGINT UNSIGNED NOT NULL,
                oai_id       VARCHAR(255) NOT NULL,
                datestamp    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_entity (entity_type, entity_id),
                KEY idx_datestamp (datestamp),
                KEY idx_oai_id (oai_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Issue #140 tombstones for periodical mastheads.
            //
            // Why a SECOND table instead of a new value in
            // oai_deleted_records.entity_type? Because that column is an ENUM,
            // and widening an ENUM is an ALTER that nothing would ever run on
            // an install where this plugin is already active: PluginManager's
            // self-heal fires on a MISSING TABLE / COLUMN / FK, never on a
            // changed column TYPE, and the version-bump path executes the
            // plugin's OLD class (stale-class trap documented in
            // PluginManager::bundledSchemaIncomplete). A brand new table IS
            // covered by expectedTables() → the self-heal creates it, and
            // installTriggers() then attaches the AFTER DELETE trigger.
            // Shipping the ENUM route would have produced a trigger that
            // fails with "Data truncated for column entity_type" on exactly
            // the installs that need it most.
            'oai_deleted_periodicals' => "CREATE TABLE IF NOT EXISTS oai_deleted_periodicals (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_id    BIGINT UNSIGNED NOT NULL,
                oai_id       VARCHAR(255) NOT NULL,
                datestamp    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_periodical (entity_id),
                KEY idx_datestamp (datestamp),
                KEY idx_oai_id (oai_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'oai_resumption_tokens' => "CREATE TABLE IF NOT EXISTS oai_resumption_tokens (
                token      VARCHAR(64) NOT NULL,
                payload    JSON NOT NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (token),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mag_project_config' => "CREATE TABLE IF NOT EXISTS mag_project_config (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_code     VARCHAR(64) NOT NULL,
                institution_code VARCHAR(16) NOT NULL DEFAULT 'IT',
                collection_name  VARCHAR(255) NOT NULL DEFAULT '',
                rights_statement VARCHAR(500) NOT NULL DEFAULT 'In Copyright',
                base_url         VARCHAR(500) NOT NULL DEFAULT '',
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_project_code (project_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'digital_assets' => "CREATE TABLE IF NOT EXISTS digital_assets (
                id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
                libro_id     INT               NOT NULL,
                url          VARCHAR(500)      NOT NULL,
                md5_hash     CHAR(32)          NOT NULL DEFAULT '',
                filesize     BIGINT UNSIGNED   NOT NULL DEFAULT 0,
                image_width  INT UNSIGNED      NOT NULL DEFAULT 0,
                image_height INT UNSIGNED      NOT NULL DEFAULT 0,
                ppi          SMALLINT UNSIGNED NOT NULL DEFAULT 300,
                filetype     VARCHAR(32)       NOT NULL DEFAULT 'PDF',
                created_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_libro_id (libro_id),
                CONSTRAINT fk_digital_assets_libro
                    FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    public function ensureSchema(): array
    {
        $created = [];
        $failed  = [];
        $tables = self::schemaSteps();

        foreach ($tables as $name => $ddl) {
            if ($this->db->query($ddl) === true) {
                $created[] = $name;
            } else {
                SecureLogger::error("[OaiPmhServer] CREATE TABLE {$name} failed: " . $this->db->error);
                $failed[] = $name;
            }
        }

        $this->ensureMagProjectConfigSchema();

        // Migrate oai_resumption_tokens if it still has the old column-per-field schema
        // (pre-payload refactor). Tokens are ephemeral — DROP + recreate is safe.
        $colRes = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME  = 'oai_resumption_tokens'
                AND COLUMN_NAME = 'payload'"
        );
        $hasPayload = false;
        if ($colRes instanceof \mysqli_result) {
            $colRow = $colRes->fetch_assoc();
            $colRes->free();
            $hasPayload = ((int) ($colRow['c'] ?? 0)) > 0;
        }
        if (!$hasPayload) {
            $this->db->query('DROP TABLE IF EXISTS oai_resumption_tokens');
            $this->db->query($tables['oai_resumption_tokens']);
        }

        // Install triggers for persistent deleted record tracking.
        $this->installTriggers();

        return ['created' => $created, 'failed' => $failed];
    }

    private function ensureMagProjectConfigSchema(): void
    {
        $columns = $this->getExistingColumns('mag_project_config');
        $addColumn = function (string $name, string $ddl) use ($columns): void {
            if (!isset($columns[$name])) {
                $this->db->query("ALTER TABLE mag_project_config ADD COLUMN {$ddl}");
            }
        };

        $addColumn('institution_code', "institution_code VARCHAR(16) NOT NULL DEFAULT 'IT' AFTER project_code");
        $addColumn('collection_name', "collection_name VARCHAR(255) NOT NULL DEFAULT 'Biblioteca Pinakes' AFTER institution_code");
        $addColumn('rights_statement', "rights_statement VARCHAR(500) NOT NULL DEFAULT 'In Copyright' AFTER collection_name");
        $addColumn('base_url', "base_url VARCHAR(500) NOT NULL DEFAULT '' AFTER rights_statement");

        if (isset($columns['collection'])) {
            $this->db->query(
                "UPDATE mag_project_config
                    SET collection_name = collection
                  WHERE collection_name IN ('', 'Biblioteca Pinakes')
                    AND collection <> ''"
            );
        }

        $idx = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'mag_project_config'
                AND INDEX_NAME = 'uq_project_code'"
        );
        $hasIndex = false;
        if ($idx instanceof \mysqli_result) {
            $row = $idx->fetch_assoc();
            $idx->free();
            $hasIndex = ((int) ($row['c'] ?? 0)) > 0;
        }
        if (!$hasIndex) {
            $this->db->query('ALTER TABLE mag_project_config ADD UNIQUE KEY uq_project_code (project_code)');
        }
    }

    /** @return array<string, true> */
    private function getExistingColumns(string $table): array
    {
        $res = $this->db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '" . $this->db->real_escape_string($table) . "'"
        );
        if (!($res instanceof \mysqli_result)) { return []; }
        $columns = [];
        while ($row = $res->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }
        $res->free();
        return $columns;
    }

    // FIX F058: previously logged-and-continued silently on CREATE TRIGGER
    // failure (e.g. missing TRIGGER privilege on shared hosting like cPanel).
    // The plugin would activate successfully and Identify would advertise
    // deletedRecord: persistent, but soft-deletes wouldn't be tracked → OAI
    // harvesters would never see deletion records and would believe the data
    // is still there, breaking incremental sync.
    //
    // Soft-fallback approach (chosen for compat-friendliness with OAI harvesters):
    // keep installTriggers() non-fatal but track whether the triggers actually
    // got installed. oaiIdentify() inspects this state (via hasActiveTriggers())
    // and downgrades deletedRecord to 'no' when triggers are missing — the
    // OAI-PMH 2.0 spec explicitly allows this value and harvesters handle it
    // gracefully. This matches the existing "table missing" branch which also
    // returns silently.
    private function installTriggers(): void
    {
        $triggers = [
            'trg_libri_soft_delete' => [
                'table' => 'libri',
                // Table the trigger BODY writes to. A trigger whose target
                // table is missing does not fail at CREATE time — it fails on
                // every DELETE/UPDATE of the watched table, i.e. it breaks
                // another plugin's writes. Never install one blind.
                'requires' => 'oai_deleted_records',
                'timing' => 'BEFORE UPDATE',
                'body' => "IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    INSERT INTO oai_deleted_records (entity_type, entity_id, oai_id, datestamp)
                    VALUES ('book', OLD.id, CONCAT('oai:pinakes:book:', OLD.id), NOW())
                    ON DUPLICATE KEY UPDATE datestamp = NOW();
                END IF",
            ],
            'trg_archival_soft_delete' => [
                'table' => 'archival_units',
                'requires' => 'oai_deleted_records',
                'timing' => 'BEFORE UPDATE',
                'body' => "IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    INSERT INTO oai_deleted_records (entity_type, entity_id, oai_id, datestamp)
                    VALUES ('archival_unit', OLD.id, CONCAT('oai:pinakes:archival_unit:', OLD.id), NOW())
                    ON DUPLICATE KEY UPDATE datestamp = NOW();
                END IF",
            ],
            // Issue #140: emeroteca_testate is HARD-deleted (no deleted_at
            // column, no soft-delete path — PeriodicalAdminController runs a
            // plain DELETE both on destroy and on merge). Identify advertises
            // deletedRecord=persistent for the whole repository, a promise
            // OAI-PMH cannot scope per set, so a masthead that vanished had to
            // become a tombstone or the promise was a lie for every record in
            // the `periodicals` set.
            //
            // AFTER DELETE (not BEFORE UPDATE) and, deliberately, a DB trigger
            // rather than a plugin hook: the emeroteca plugin is owned by
            // someone else and its two delete paths would both have to call in.
            // This mirrors what this plugin already does to `archival_units`,
            // another plugin's table.
            'trg_emeroteca_hard_delete' => [
                'table' => 'emeroteca_testate',
                'requires' => 'oai_deleted_periodicals',
                'timing' => 'AFTER DELETE',
                'body' => "INSERT INTO oai_deleted_periodicals (entity_id, oai_id, datestamp)
                    VALUES (OLD.id, CONCAT('oai:pinakes:periodical:', OLD.id), NOW())
                    ON DUPLICATE KEY UPDATE datestamp = NOW()",
            ],
        ];

        $tableExists = function (string $table): bool {
            $escaped = $this->db->real_escape_string($table);
            $res = $this->db->query(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escaped}'"
            );
            if (!($res instanceof \mysqli_result)) {
                return false;
            }
            $row = $res->fetch_assoc();
            $res->free();

            return ((int) ($row['c'] ?? 0)) > 0;
        };

        foreach ($triggers as $name => $def) {
            $table = $this->db->real_escape_string($def['table']);
            // Watched table missing (e.g. archives or emeroteca not installed).
            if (!$tableExists($def['table'])) {
                continue;
            }
            // Target table missing: installing the trigger anyway would make
            // every DELETE/UPDATE on the watched table fail — this plugin
            // would be breaking another plugin's writes.
            if (!$tableExists($def['requires'])) {
                SecureLogger::warning(
                    '[OaiPmhServer] skipping trigger ' . $name . ': target table '
                    . $def['requires'] . ' does not exist'
                );
                continue;
            }

            if ($this->db->query("DROP TRIGGER IF EXISTS `{$name}`") === false) {
                SecureLogger::warning('[OaiPmhServer] DROP TRIGGER failed for ' . $name . ': ' . $this->db->error);
            }
            $timing = $def['timing'] === 'AFTER DELETE' ? 'AFTER DELETE' : 'BEFORE UPDATE';
            $created = $this->db->query(
                "CREATE TRIGGER `{$name}` {$timing} ON `{$table}`
                 FOR EACH ROW BEGIN {$def['body']}; END"
            );
            if ($created === false) {
                SecureLogger::error(
                    '[OaiPmhServer] CREATE TRIGGER ' . $name . ' failed: ' . $this->db->error
                    . ' — deleted record tracking inactive; Identify will advertise deletedRecord=no'
                );
            }
        }
    }

    /**
     * FIX F058 helper: returns true when at least one soft-delete trigger
     * is actually installed in the current schema. Used by oaiIdentify()
     * to decide between deletedRecord=persistent and deletedRecord=no, so
     * we never falsely promise persistent tracking to OAI harvesters when
     * the underlying triggers failed to install (missing TRIGGER privilege,
     * shared hosting restrictions, etc.). Result is cached per request.
     */
    private ?bool $triggersActiveCache = null;

    private function hasActiveTriggers(): bool
    {
        if ($this->triggersActiveCache !== null) {
            return $this->triggersActiveCache;
        }
        $res = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND TRIGGER_NAME IN ('trg_libri_soft_delete', 'trg_archival_soft_delete',
                                     'trg_emeroteca_hard_delete')"
        );
        $active = false;
        if ($res instanceof \mysqli_result) {
            $row = $res->fetch_assoc();
            $res->free();
            $active = ((int) ($row['c'] ?? 0)) > 0;
        }
        $this->triggersActiveCache = $active;
        return $active;
    }

    // ── Hook registration ─────────────────────────────────────────────────────

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[OaiPmhServer] pluginId not set; cannot register hook ' . $hookName);
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
            throw new \RuntimeException('[OaiPmhServer] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $callbackClass = 'OaiPmhServerPlugin';
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[OaiPmhServer] hook insert failed for ' . $hookName . ': ' . $err);
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
            return;
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    // ── Route registration ────────────────────────────────────────────────────

    public function registerRoutes($app): void
    {
        $plugin = $this;

        // OAI-PMH 2.0 endpoint — public, no admin auth.
        $app->get('/oai', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->oaiPmhAction($request, $response);
        });

        $app->post('/oai', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->oaiPmhAction($request, $response);
        });

        // UNIMARC direct download — admin/staff only.
        $app->get('/admin/books/{id}/unimarc.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->downloadUnimarcXmlAction($request, $response, $args);
        });

        $app->get('/admin/books/{id}/unimarc.mrc', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->downloadUnimarcMrcAction($request, $response, $args);
        });

        // Digital-assets AJAX endpoints — admin/staff only.
        $app->post('/admin/api/books/{id}/digital-assets', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->digitalAssetAddAction($request, $response, $args);
        });

        $app->post('/admin/api/books/{id}/digital-assets/{aid}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->digitalAssetDeleteAction($request, $response, $args);
        });
    }

    // ── OAI-PMH dispatcher ────────────────────────────────────────────────────

    public function oaiPmhAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        if ($request->getMethod() === 'POST') {
            $body   = (array) ($request->getParsedBody() ?? []);
            $params = array_merge($params, $body);
        }

        // Purge expired tokens probabilistically (1% chance) to avoid per-request overhead.
        if (random_int(0, 99) === 0) {
            $this->purgeExpiredTokens();
        }

        $verb    = (string) ($params['verb'] ?? '');
        $now     = gmdate('Y-m-d\TH:i:s\Z');
        // FIX F059: OAI baseURL must be stable & not Host-header-spoofable —
        // an attacker who can set the HTTP Host header could otherwise inject
        // arbitrary domains into the OAI identifier scheme (oai:{host}:book:{id})
        // and the <baseURL>/<request> response elements, poisoning downstream
        // harvester caches. absoluteUrl() prefers APP_CANONICAL_URL when set;
        // when it isn't, we log a one-shot warning so the operator knows the
        // server is exposed to Host-header spoofing.
        $baseUrl = $this->oaiBaseUrl();
        $host    = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->startDocument('1.0', 'UTF-8');
        $xw->startElementNs(null, 'OAI-PMH', 'http://www.openarchives.org/OAI/2.0/');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');

        $xw->writeElement('responseDate', $now);

        static $validVerbs = ['Identify', 'ListMetadataFormats', 'ListRecords',
                               'GetRecord', 'ListIdentifiers', 'ListSets'];
        $verbIsValid   = in_array($verb, $validVerbs, true);
        $argumentError = $verbIsValid ? $this->validateOaiArguments($verb, $params) : null;
        $isErrorResponse = !$verbIsValid || $argumentError !== null;

        $xw->startElement('request');
        if (!$isErrorResponse) {
            if ($verb !== '') { $xw->writeAttribute('verb', $verb); }
            foreach (['metadataPrefix', 'identifier', 'from', 'until', 'set', 'resumptionToken'] as $k) {
                if (!empty($params[$k])) { $xw->writeAttribute($k, (string) $params[$k]); }
            }
        }
        $xw->text($baseUrl);
        $xw->endElement(); // request

        if ($argumentError !== null) {
            [$code, $message] = $argumentError;
            $this->oaiError($xw, $code, $message);
        } else {
            match ($verb) {
                'Identify'            => $this->oaiIdentify($xw, $baseUrl, $host, $now),
                'ListMetadataFormats' => $this->oaiListMetadataFormats($xw, $params, $host),
                'ListRecords'         => $this->oaiListRecords($xw, $params, $host, false),
                'GetRecord'           => $this->oaiGetRecord($xw, $params, $host),
                'ListIdentifiers'     => $this->oaiListRecords($xw, $params, $host, true),
                'ListSets'            => $this->oaiListSets($xw),
                default               => $this->oaiError($xw, 'badVerb',
                    'Value of the verb argument is not a legal OAI-PMH verb, '
                    . 'the verb argument is missing, or the verb argument is repeated.'),
            };
        }

        $xw->endElement(); // OAI-PMH
        $xw->endDocument();

        $response->getBody()->write($xw->outputMemory());
        return $response->withHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    /**
     * FIX F059: Build the OAI baseURL with explicit preference for
     * APP_CANONICAL_URL over Host-header-derived values. absoluteUrl() already
     * does this internally, but OAI-PMH responses are particularly sensitive
     * to host spoofing (the host ends up embedded in oai:{host}:... identifiers
     * that get cached by harvesters worldwide), so we additionally emit a
     * one-shot SecureLogger warning when APP_CANONICAL_URL is unset.
     */
    private function oaiBaseUrl(): string
    {
        $canonical = $_ENV['APP_CANONICAL_URL']
            ?? getenv('APP_CANONICAL_URL') ?: '';
        if (!is_string($canonical) || $canonical === '') {
            static $warned = false;
            if (!$warned) {
                SecureLogger::warning(
                    '[OaiPmhServer] APP_CANONICAL_URL not configured — OAI '
                    . 'baseURL will be derived from the HTTP Host header and '
                    . 'is therefore vulnerable to Host-header spoofing. Set '
                    . 'APP_CANONICAL_URL in .env to pin the OAI identifier scheme.'
                );
                $warned = true;
            }
        }
        return absoluteUrl('/oai');
    }

    /**
     * OAI-PMH requires verb-specific argument validation before dispatch.
     *
     * @param array<string, mixed> $params
     * @return array{0:string,1:string}|null
     */
    private function validateOaiArguments(string $verb, array $params): ?array
    {
        $allowedByVerb = [
            'Identify'            => ['verb'],
            'ListMetadataFormats' => ['verb', 'identifier'],
            'ListSets'            => ['verb', 'resumptionToken'],
            'ListRecords'         => ['verb', 'metadataPrefix', 'from', 'until', 'set', 'resumptionToken'],
            'ListIdentifiers'     => ['verb', 'metadataPrefix', 'from', 'until', 'set', 'resumptionToken'],
            'GetRecord'           => ['verb', 'identifier', 'metadataPrefix'],
        ];

        $allowed = $allowedByVerb[$verb] ?? ['verb'];
        foreach (array_keys($params) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                return ['badArgument', 'The request includes illegal arguments for this OAI-PMH verb.'];
            }
        }

        if ($verb === 'ListSets' && !empty($params['resumptionToken'])) {
            return ['badResumptionToken', 'This repository does not support resumption tokens for ListSets.'];
        }

        if ($verb === 'GetRecord') {
            if (empty($params['identifier']) || empty($params['metadataPrefix'])) {
                return ['badArgument', 'GetRecord requires identifier and metadataPrefix arguments.'];
            }
        }

        if ($verb === 'ListRecords' || $verb === 'ListIdentifiers') {
            $hasToken = !empty($params['resumptionToken']);
            if ($hasToken) {
                foreach (['metadataPrefix', 'from', 'until', 'set'] as $exclusiveArg) {
                    if (!empty($params[$exclusiveArg])) {
                        return ['badArgument', 'resumptionToken must not be combined with other selective arguments.'];
                    }
                }
            } elseif (empty($params['metadataPrefix'])) {
                return ['badArgument', $verb . ' requires metadataPrefix unless resumptionToken is supplied.'];
            }

            $from = (string) ($params['from'] ?? '');
            $until = (string) ($params['until'] ?? '');
            if ($from !== '' && !$this->isValidOaiDate($from)) {
                return ['badArgument', 'Invalid from date format. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.'];
            }
            if ($until !== '' && !$this->isValidOaiDate($until)) {
                return ['badArgument', 'Invalid until date format. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.'];
            }
            if ($from !== '' && $until !== '') {
                if ($this->dateGranularity($from) !== $this->dateGranularity($until)) {
                    return ['badArgument', 'from and until must use the same granularity.'];
                }
                if ($this->oaiDateTimestamp($from) > $this->oaiDateTimestamp($until)) {
                    return ['badArgument', 'from must not be later than until.'];
                }
            }
        }

        return null;
    }

    private function isValidOaiDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            [$y, $m, $d] = array_map('intval', explode('-', $value));
            return checkdate($m, $d, $y);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        return $dt instanceof \DateTimeImmutable
            && $dt->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function dateGranularity(string $value): string
    {
        return str_contains($value, 'T') ? 'seconds' : 'days';
    }

    /**
     * A validated OAI date (UTC, day or seconds granularity) as a local-time
     * MySQL DATETIME. Day granularity covers the whole UTC day: `from` starts
     * at 00:00:00Z, `until` ends at 23:59:59Z.
     */
    private function oaiDateToLocal(string $value, bool $isUntil): string
    {
        $utcText = strlen($value) === 10
            ? $value . ($isUntil ? ' 23:59:59' : ' 00:00:00')
            : str_replace(['T', 'Z'], [' ', ''], $value);
        $utc = new \DateTimeImmutable($utcText, new \DateTimeZone('UTC'));
        return $utc->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private function oaiDateTimestamp(string $value): int
    {
        $mysql = str_replace(['T', 'Z'], [' ', ''], $value);
        return strtotime($mysql) ?: 0;
    }

    // ── Identify ──────────────────────────────────────────────────────────────

    private function oaiIdentify(\XMLWriter $xw, string $baseUrl, string $host, string $now): void
    {
        // Earliest datestamp: MIN of books and archival units (if archives active).
        // Use a static epoch fallback when the repository is empty — never the current time.
        $earliest = '1970-01-01T00:00:00Z';
        // A requested book (desiderata) is not a holding: it is never harvested,
        // so it must not take part in the earliest-datestamp contract either.
        $r = $this->db->query(
            "SELECT MIN(created_at) AS e FROM libri WHERE deleted_at IS NULL AND " . \App\Support\BookVisibility::catalogue($this->db)
        );
        if ($r instanceof \mysqli_result) {
            $row = $r->fetch_assoc();
            $r->free();
            if (!empty($row['e'])) {
                $ts = strtotime((string) $row['e']);
                if ($ts !== false) {
                    $earliest = gmdate('Y-m-d\TH:i:s\Z', $ts);
                }
            }
        }
        // Also check archival_units if the table exists. Probed first: under
        // MYSQLI_REPORT_STRICT a query on a missing table throws, and Identify
        // must answer on installations that never had the Archives plugin.
        $r2 = $this->hasArchivalUnitsTable()
            ? $this->db->query("SELECT MIN(created_at) AS e FROM archival_units WHERE deleted_at IS NULL" . $this->archivalPublishedSql())
            : false;
        if ($r2 instanceof \mysqli_result) {
            $row2 = $r2->fetch_assoc();
            $r2->free();
            if (!empty($row2['e'])) {
                $ts2raw = strtotime((string) $row2['e']);
                if ($ts2raw !== false) {
                    $ts2 = gmdate('Y-m-d\TH:i:s\Z', $ts2raw);
                    if ($ts2 < $earliest) { $earliest = $ts2; }
                }
            }
        }
        // Issue #140: mastheads are harvestable records, so they take part in
        // the earliest-datestamp contract too (an incremental harvester uses it
        // as the lower bound of its very first `from`).
        if ($this->isPeriodicalsSetExposed()) {
            $r3 = $this->db->query('SELECT MIN(created_at) AS e FROM emeroteca_testate');
            if ($r3 instanceof \mysqli_result) {
                $row3 = $r3->fetch_assoc();
                $r3->free();
                if (!empty($row3['e'])) {
                    $ts3raw = strtotime((string) $row3['e']);
                    if ($ts3raw !== false) {
                        $ts3 = gmdate('Y-m-d\TH:i:s\Z', $ts3raw);
                        if ($ts3 < $earliest) { $earliest = $ts3; }
                    }
                }
            }
        }

        $cfg       = \App\Support\ConfigStore::all();
        $repoName  = trim((string) ($cfg['app']['name'] ?? '')) ?: 'Pinakes';
        $adminMail = trim((string) ($cfg['mail']['from_email'] ?? '')) ?: 'admin@localhost';

        $xw->startElement('Identify');
        $xw->writeElement('repositoryName', $repoName . ' — ' . __('Catalogo della biblioteca'));
        $xw->writeElement('baseURL', $baseUrl);
        $xw->writeElement('protocolVersion', '2.0');
        $xw->writeElement('adminEmail', $adminMail);
        $xw->writeElement('earliestDatestamp', $earliest);
        // FIX F058: only advertise persistent deletion tracking when the
        // underlying triggers are actually installed. On hosts where TRIGGER
        // privilege is missing (shared cPanel/etc.) we downgrade to 'no' so
        // harvesters don't expect tombstones we cannot supply.
        $xw->writeElement('deletedRecord', $this->hasActiveTriggers() ? 'persistent' : 'no');
        $xw->writeElement('granularity', 'YYYY-MM-DDThh:mm:ssZ');

        // oai-identifier description (OAI Implementation Guidelines §2.1)
        $xw->startElement('description');
        $xw->startElementNs(null, 'oai-identifier', 'http://www.openarchives.org/OAI/2.0/oai-identifier');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai-identifier ' .
            'http://www.openarchives.org/OAI/2.0/oai-identifier.xsd');
        $xw->writeElement('scheme', 'oai');
        $xw->writeElement('repositoryIdentifier', $host);
        $xw->writeElement('delimiter', ':');
        $xw->writeElement('sampleIdentifier', 'oai:' . $host . ':book:1');
        $xw->endElement(); // oai-identifier
        $xw->endElement(); // description

        $xw->endElement(); // Identify
    }

    // ── ListMetadataFormats ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function oaiListMetadataFormats(\XMLWriter $xw, array $params, string $host): void
    {
        $identifier = (string) ($params['identifier'] ?? '');
        $entityType = null; // null = no identifier filter; 'book' or 'archival_unit'
        if ($identifier !== '') {
            // Validate identifier refers to a known item (book or archival_unit).
            // A deleted record still exists for OAI-PMH (persistent deletion
            // tracking): its formats are listed, never idDoesNotExist.
            $resolved = $this->resolveIdentifier($identifier, $host)
                ?? $this->resolveDeletedIdentifier($identifier, $host);
            if ($resolved === null) {
                $this->oaiError($xw, 'idDoesNotExist',
                    'The value of the identifier argument is unknown or illegal in this repository.');
                return;
            }
            $entityType = (string) ($resolved['_entity'] ?? 'book');
        }

        // Phase 6: `ric-o` is only meaningful when the Archives plugin
        // is active AND the archival_units table exists — same gate as
        // the `archives` setSpec. If the gate is closed we strip the
        // format from the discovery response entirely so harvesters
        // don't probe an endpoint that will respond cannotDisseminate.
        $ricExposed = $this->isArchivesSetExposed();

        $xw->startElement('ListMetadataFormats');

        foreach ($this->metadataFormats() as $fmt) {
            // Global gate: ric-o is only advertised when the archives
            // set is exposed. Applies regardless of identifier — a
            // closed gate must never leak the format on discovery,
            // even for an archival_unit identifier (the GetRecord /
            // ListRecords paths would otherwise respond
            // cannotDisseminateFormat after announcing it here).
            if ($fmt['prefix'] === 'ric-o' && !$ricExposed) {
                continue;
            }
            // archival_unit records support oai_dc and (when archives is
            // active) ric-o. All other formats are book-only.
            if ($entityType === 'archival_unit'
                && $fmt['prefix'] !== 'oai_dc'
                && $fmt['prefix'] !== 'ric-o') {
                continue;
            }
            // Inverse: book identifiers MUST NOT advertise ric-o.
            if ($entityType === 'book' && $fmt['prefix'] === 'ric-o') {
                continue;
            }
            // Issue #140: periodical mastheads are oai_dc-only; advertising
            // anything else here would be answered with cannotDisseminateFormat.
            if ($entityType === 'periodical' && $fmt['prefix'] !== 'oai_dc') {
                continue;
            }
            $xw->startElement('metadataFormat');
            $xw->writeElement('metadataPrefix', $fmt['prefix']);
            $xw->writeElement('schema', $fmt['schema']);
            $xw->writeElement('metadataNamespace', $fmt['namespace']);
            $xw->endElement();
        }

        $xw->endElement(); // ListMetadataFormats
    }

    /**
     * @return list<array{prefix:string, schema:string, namespace:string}>
     */
    private function metadataFormats(): array
    {
        return [
            [
                'prefix'    => 'oai_dc',
                'schema'    => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
                'namespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
            ],
            [
                'prefix'    => 'marcxml',
                'schema'    => 'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd',
                'namespace' => 'http://www.loc.gov/MARC21/slim',
            ],
            [
                'prefix'    => 'mods',
                'schema'    => 'http://www.loc.gov/standards/mods/v3/mods-3-7.xsd',
                'namespace' => 'http://www.loc.gov/mods/v3',
            ],
            [
                'prefix'    => 'mag',
                'schema'    => self::MAG_SCHEMA,
                'namespace' => self::MAG_NS,
            ],
            [
                'prefix'    => 'unimarc',
                'schema'    => self::SCHEMA_MARCXCHANGE,
                'namespace' => self::NS_MARCXCHANGE,
            ],
            // Phase 6 (v0.7.12): RiC-O is exposed as a metadataPrefix for
            // archival_unit records. The schema URL is the ICA-hosted
            // OWL ontology document; clients perform shape validation
            // out-of-band since RDF/XML is not XSD-validatable.
            [
                'prefix'    => 'ric-o',
                'schema'    => 'https://www.ica.org/standards/RiC/RiC-O_v1-0.rdf',
                'namespace' => 'https://www.ica.org/standards/RiC/ontology#',
            ],
        ];
    }

    // ── ListSets ──────────────────────────────────────────────────────────────

    private function oaiListSets(\XMLWriter $xw): void
    {
        $xw->startElement('ListSets');

        $xw->startElement('set');
        $xw->writeElement('setSpec', 'books');
        $xw->writeElement('setName', __('Biblioteca — Catalogo libri'));
        $xw->endElement();

        // FIX F060: previously advertised the `archives` setSpec based solely
        // on archival_units TABLE existence, but the table persists across
        // archives-plugin deactivate cycles (drop-on-uninstall only). That
        // meant we'd advertise an empty set to harvesters whenever the plugin
        // was deactivated-but-not-uninstalled, breaking OAI consumers that
        // route harvest jobs by setSpec. Now we require BOTH the plugin to
        // be active AND the table to exist (the AND keeps us defensive
        // against fresh-activate races where the plugin row is flipped before
        // its schema is ready).
        if ($this->isArchivesSetExposed()) {
            $xw->startElement('set');
            $xw->writeElement('setSpec', 'archives');
            $xw->writeElement('setName', __('Archivio — Unità archivistiche'));
            $xw->endElement();
        }

        // Issue #140: periodical mastheads (emeroteca_testate) are exposed as
        // their own set so SBN/discovery harvesters can pick up ISSN-bearing
        // serials, which the books set structurally cannot carry. Same
        // plugin-active AND table-exists gate as `archives`: when the
        // Emeroteca plugin is absent or deactivated the set simply does not
        // appear (no empty set advertised to harvesters).
        if ($this->isPeriodicalsSetExposed()) {
            $xw->startElement('set');
            $xw->writeElement('setSpec', 'periodicals');
            $xw->writeElement('setName', __('Emeroteca — Testate periodiche'));
            $xw->endElement();
        }

        $xw->endElement(); // ListSets
    }

    /**
     * Issue #140 gate: `periodicals` setSpec exposure. Mirrors
     * isArchivesSetExposed() exactly — the Emeroteca plugin must be ACTIVE
     * and emeroteca_testate must exist. On PluginManager failure we degrade
     * to the table-existence check so an in-flight upgrade cannot break OAI.
     */
    private function isPeriodicalsSetExposed(): bool
    {
        if ($this->periodicalsExposedCache !== null) {
            return $this->periodicalsExposedCache;
        }

        $pluginActive = null;
        try {
            $pm = new \App\Support\PluginManager($this->db, $this->hookManager);
            $pluginActive = $pm->isActive('emeroteca');
        } catch (\Throwable $e) {
            SecureLogger::warning(
                '[OaiPmhServer] PluginManager::isActive(emeroteca) failed, '
                . 'falling back to table-existence check: ' . $e->getMessage()
            );
        }

        $tableExists = $this->periodicalsTableExists();

        if ($pluginActive === null) {
            $this->periodicalsExposedCache = $tableExists;
            return $tableExists;
        }
        $this->periodicalsExposedCache = $pluginActive && $tableExists;

        return $this->periodicalsExposedCache;
    }

    /** Cached INFORMATION_SCHEMA probe for emeroteca_testate. */
    private function periodicalsTableExists(): bool
    {
        if ($this->periodicalsTableCache !== null) {
            return $this->periodicalsTableCache;
        }
        $exists = false;
        $chk = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emeroteca_testate'"
        );
        if ($chk instanceof \mysqli_result) {
            $row = $chk->fetch_assoc();
            $chk->free();
            $exists = ((int) ($row['c'] ?? 0)) > 0;
        }

        return $this->periodicalsTableCache = $exists;
    }

    /** setSpec advertised in record headers for one internal entity name. */
    private function setSpecForEntity(string $entity): string
    {
        return match ($entity) {
            'archival_unit' => 'archives',
            'periodical'    => 'periodicals',
            default         => 'books',
        };
    }

    /**
     * FIX F060 helper: archives setSpec exposure gate.
     * Plugin-active check uses PluginManager::isActive() (per-process cached).
     * If the PluginManager construction fails for any reason (DI edge cases
     * during install/upgrade) we fall back to the table-existence check so
     * we don't break OAI mid-upgrade.
     */
    private function isArchivesSetExposed(): bool
    {
        // FIX F009: per-request memoization. ListMetadataFormats +
        // GetRecord + ListRecords all call this gate within a single
        // OAI request; without caching that's ≥3 PluginManager
        // constructions + ≥3 I_S queries per request. Cache is reset
        // by virtue of new plugin instance per HTTP request.
        if ($this->ricExposedCache !== null) {
            return $this->ricExposedCache;
        }

        $pluginActive = null;
        try {
            $pm = new \App\Support\PluginManager($this->db, $this->hookManager);
            $pluginActive = $pm->isActive('archives');
        } catch (\Throwable $e) {
            SecureLogger::warning(
                '[OaiPmhServer] PluginManager::isActive(archives) failed, '
                . 'falling back to table-existence check: ' . $e->getMessage()
            );
        }

        $tableExists = false;
        $chk = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
        );
        if ($chk instanceof \mysqli_result) {
            $row = $chk->fetch_assoc();
            $chk->free();
            $tableExists = ((int) ($row['c'] ?? 0)) > 0;
        }

        // If we couldn't read plugin state, defer to legacy behavior (table-only).
        if ($pluginActive === null) {
            $this->ricExposedCache = $tableExists;
            return $tableExists;
        }
        $this->ricExposedCache = $pluginActive && $tableExists;
        return $this->ricExposedCache;
    }

    // ── ListRecords / ListIdentifiers ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function oaiListRecords(
        \XMLWriter $xw,
        array $params,
        string $host,
        bool $identifiersOnly
    ): void {
        $metadataPrefix = (string) ($params['metadataPrefix'] ?? '');
        $from           = (string) ($params['from']           ?? '');
        $until          = (string) ($params['until']          ?? '');
        $set            = (string) ($params['set']            ?? '');
        $tokenStr       = (string) ($params['resumptionToken'] ?? '');

        // Resumption token overrides all other params.
        $tokenComposition = null;
        if ($tokenStr !== '') {
            $payload = $this->loadResumptionToken($tokenStr);
            if ($payload === null) {
                $this->oaiError($xw, 'badResumptionToken',
                    'The value of the resumptionToken argument is invalid or expired.');
                return;
            }
            $metadataPrefix   = $payload['metadataPrefix'];
            $from             = $payload['from'];
            $until            = $payload['until'];
            $set              = $payload['set'];
            $cursor           = $payload['cursor'];
            $tokenComposition = $payload['composition'];
        } else {
            $cursor = 0;
        }

        $validPrefixes = ['oai_dc', 'marcxml', 'mods', 'mag', 'unimarc', 'ric-o'];
        if ($metadataPrefix === '' || !in_array($metadataPrefix, $validPrefixes, true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
            return;
        }

        // Phase 6: `ric-o` is only meaningful when the Archives plugin
        // is installed AND has opted in to OAI exposure. Without this
        // gate, callers could request `metadataPrefix=ric-o` against a
        // repository that doesn't advertise the format in
        // ListMetadataFormats — a contract violation that could leak
        // (or crash on) archival entities the operator never wired up.
        if ($metadataPrefix === 'ric-o' && !$this->isArchivesSetExposed()) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'metadataPrefix=ric-o is not available — archives module is not exposed.');
            return;
        }

        // `periodicals` is only a legal set while the Emeroteca bridge is
        // exposed; otherwise it falls through to noRecordsMatch exactly like
        // any other unknown setSpec.
        $validSets = ['', 'books', 'archives'];
        if ($this->isPeriodicalsSetExposed()) {
            $validSets[] = 'periodicals';
        }
        if (!in_array($set, $validSets, true)) {
            $this->oaiError($xw, 'noRecordsMatch',
                'No records match the specified set.');
            return;
        }

        if ($from !== '' && !$this->isValidOaiDate($from)) {
            $this->oaiError($xw, 'badArgument', 'Invalid from date format. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.');
            return;
        }
        if ($until !== '' && !$this->isValidOaiDate($until)) {
            $this->oaiError($xw, 'badArgument', 'Invalid until date format. Use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.');
            return;
        }
        if ($from !== '' && $until !== '' && $this->oaiDateTimestamp($from) > $this->oaiDateTimestamp($until)) {
            $this->oaiError($xw, 'badArgument', 'from must not be later than until.');
            return;
        }

        if ($set === 'archives' && $metadataPrefix !== 'oai_dc' && $metadataPrefix !== 'ric-o') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for archival_unit records in this repository.');
            return;
        }
        // ric-o on the books set is meaningless — agents/units are archival entities.
        if ($set === 'books' && $metadataPrefix === 'ric-o') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'metadataPrefix=ric-o is only available for archival_unit records (set=archives).');
            return;
        }
        // Periodical mastheads are disseminated as Dublin Core only: the
        // MARC/UNIMARC/MODS/MAG writers here are monograph-shaped (book row
        // columns) and MAG describes digitised objects.
        if ($set === 'periodicals' && $metadataPrefix !== 'oai_dc') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for periodical records in this repository.');
            return;
        }

        // Normalise the UTC OAI dates to the local-time DATETIME the columns
        // hold (datestamps are emitted local → UTC by recordDatestamp(), so
        // the bound must make the opposite trip). Date-only values expand to
        // the inclusive UTC day boundaries first.
        $fromMysql  = $from !== '' ? $this->oaiDateToLocal($from, false) : null;
        $untilMysql = $until !== '' ? $this->oaiDateToLocal($until, true) : null;

        // Build the combined result set: active records + persistent deletions.
        // Non-DC formats are only available for book records on the unified endpoint.
        // ric-o is archival_unit-only; oai_dc is the only book/archives
        // shared format. For everything else default to the books set
        // when no explicit set was supplied.
        if ($set === '') {
            if ($metadataPrefix === 'ric-o') {
                $fetchSet = 'archives';
            } elseif ($metadataPrefix === 'oai_dc') {
                $fetchSet = '';
            } else {
                $fetchSet = 'books';
            }
        } else {
            $fetchSet = $set;
        }

        // FIX (issue #140 review): a resumption token is an OFFSET into a UNION
        // whose arms are decided by plugin-activation gates at request time.
        // Activating or deactivating Emeroteca/Archives between two pages moves
        // rows across the offset boundary and silently drops everything that
        // crossed it. Bind the token to the composition it was minted against
        // and refuse it when they disagree — the harvester restarts and gets a
        // complete harvest instead of a quietly incomplete one.
        $composition = $this->harvestComposition($fetchSet, $metadataPrefix);
        if ($tokenComposition !== null && $tokenComposition !== $composition) {
            $this->oaiError($xw, 'badResumptionToken',
                'The resumptionToken was issued against a different repository composition '
                . '(a content module was activated or deactivated during the harvest). '
                . 'Restart the harvest to obtain a complete result.');
            return;
        }

        $records = $this->fetchRecordsPage(
            $fetchSet,
            $fromMysql,
            $untilMysql,
            $cursor,
            self::PAGE_SIZE + 1,
            $metadataPrefix
        );

        // Determine whether there's a next page.
        $hasMore = count($records) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($records);
        }

        if (empty($records) && $cursor === 0) {
            $this->oaiError($xw, 'noRecordsMatch',
                'The combination of the values of the from, until, set, and metadataPrefix arguments '
                . 'results in an empty list.');
            return;
        }

        // FIX (issue #140 review): records are rendered into a per-record
        // buffer and only merged into the response once they are complete.
        // A record whose metadata writer throws is discarded WHOLE — the old
        // code tried to unwind a half-open <metadata>/<record> with two
        // best-effort endElement() calls, which is guesswork about XMLWriter's
        // internal depth. It also lets us count what actually got emitted:
        // <ListRecords> with a resumptionToken and zero <record> children is
        // rejected by the OAI-PMH XSD (record has minOccurs=1), and strict
        // harvesters abort on it.
        $renderPage = function (array $records) use ($metadataPrefix, $host, $identifiersOnly): array {
            $xml     = '';
            $emitted = 0;
            foreach ($records as $rec) {
                $rw = new \XMLWriter();
                $rw->openMemory();
                $rw->setIndent(true);

                $isDeleted = ($rec['_status'] === 'deleted');
                if (!$identifiersOnly) {
                    $rw->startElement('record');
                }
                $rw->startElement('header');
                if ($isDeleted) {
                    $rw->writeAttribute('status', 'deleted');
                }
                $rw->writeElement('identifier', $this->buildOaiId($rec, $host));
                $rw->writeElement('datestamp', $this->recordDatestamp($rec));
                if (!$isDeleted) {
                    // Emit setSpec only for active records.
                    $rw->writeElement('setSpec', $this->setSpecForEntity((string) $rec['_entity']));
                }
                $rw->endElement(); // header

                if (!$identifiersOnly && !$isDeleted) {
                    try {
                        $rw->startElement('metadata');
                        $this->writeMetadata($rw, $rec, $metadataPrefix, $host);
                        $rw->endElement(); // metadata
                    } catch (CannotDisseminateFormatException $e) {
                        // This record type doesn't support the requested format.
                        // harvestArms() is supposed to keep such a record out of
                        // the page entirely, so reaching here means the arm
                        // policy and the writers disagree: log it, drop the
                        // partial buffer, move on.
                        \App\Support\SecureLogger::warning('OAI-PMH skipped record: format not disseminable', [
                            'metadataPrefix' => $metadataPrefix,
                            'entity'         => $rec['_entity'] ?? null,
                            'id'             => $rec['id'] ?? null,
                        ]);
                        continue;
                    } catch (\Throwable $e) {
                        \App\Support\SecureLogger::warning('OAI-PMH skipped malformed record metadata', [
                            'metadataPrefix' => $metadataPrefix,
                            'entity' => $rec['_entity'] ?? null,
                            'id' => $rec['id'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }

                if (!$identifiersOnly) {
                    $rw->endElement(); // record
                }

                $xml .= $rw->outputMemory();
                $emitted++;
            }

            return [$xml, $emitted];
        };

        [$recordsXml, $emitted] = $renderPage($records);

        // Every record on this page was unrenderable. Rather than emit a page
        // that no XSD-validating harvester will accept, walk forward until a
        // disseminable record or the actual end. An arbitrary cutoff would
        // falsely signal exhaustion and hide all subsequent valid records.
        while ($emitted === 0 && $hasMore) {
            $cursor += self::PAGE_SIZE;
            $records = $this->fetchRecordsPage(
                $fetchSet,
                $fromMysql,
                $untilMysql,
                $cursor,
                self::PAGE_SIZE + 1,
                $metadataPrefix
            );
            $hasMore = count($records) > self::PAGE_SIZE;
            if ($hasMore) {
                array_pop($records);
            }
            [$recordsXml, $emitted] = $renderPage($records);
        }

        if ($emitted === 0) {
            \App\Support\SecureLogger::warning('OAI-PMH: no disseminable record on this page', [
                'metadataPrefix' => $metadataPrefix,
                'set'            => $fetchSet,
                'cursor'         => $cursor,
            ]);
            $this->oaiError($xw, 'noRecordsMatch',
                'The combination of the values of the from, until, set, and metadataPrefix arguments '
                . 'results in an empty list.');
            return;
        }

        $verbElement = $identifiersOnly ? 'ListIdentifiers' : 'ListRecords';
        $xw->startElement($verbElement);
        $xw->writeRaw($recordsXml);

        // Resumption token — always emit the element with cursor + expirationDate,
        // even on the last page (empty text content when no more pages).
        $nextCursor = $cursor + self::PAGE_SIZE;
        $xw->startElement('resumptionToken');
        $xw->writeAttribute('expirationDate', gmdate('Y-m-d\TH:i:s\Z', time() + self::TOKEN_TTL));
        $xw->writeAttribute('cursor', (string) $cursor);
        if ($hasMore) {
            $newToken = $this->saveResumptionToken(
                $metadataPrefix,
                $from,
                $until,
                $set,
                $nextCursor,
                $composition
            );
            $xw->text($newToken);
        }
        $xw->endElement(); // resumptionToken

        $xw->endElement(); // ListRecords / ListIdentifiers
    }

    // ── GetRecord ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function oaiGetRecord(\XMLWriter $xw, array $params, string $host): void
    {
        $identifier     = (string) ($params['identifier']     ?? '');
        $metadataPrefix = (string) ($params['metadataPrefix'] ?? '');

        if ($identifier === '' || $metadataPrefix === '') {
            $this->oaiError($xw, 'badArgument',
                'The request includes illegal arguments, is missing required arguments, '
                . 'includes a repeated argument, or values for arguments have an illegal syntax.');
            return;
        }

        $validPrefixes = ['oai_dc', 'marcxml', 'mods', 'mag', 'unimarc', 'ric-o'];
        if (!in_array($metadataPrefix, $validPrefixes, true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
            return;
        }

        // Phase 6: `ric-o` requires the Archives plugin to be installed
        // and exposed via OAI. Gate identical to oaiListRecords —
        // refuse the format upfront rather than risk leaking archival
        // entities that the operator hasn't chosen to publish.
        if ($metadataPrefix === 'ric-o' && !$this->isArchivesSetExposed()) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'metadataPrefix=ric-o is not available — archives module is not exposed.');
            return;
        }

        $rec = $this->resolveIdentifier($identifier, $host);
        if ($rec === null) {
            // Check if it's a deleted record.
            $rec = $this->resolveDeletedIdentifier($identifier, $host);
        }
        // One deleted-header writer for both sources: a tombstone table row and
        // a book withdrawn by the desiderata flag (which resolveIdentifier()
        // reports with _status = 'deleted') produce the same answer.
        if ($rec !== null && ($rec['_status'] ?? '') === 'deleted') {
            $xw->startElement('GetRecord');
            $xw->startElement('record');
            $xw->startElement('header');
            $xw->writeAttribute('status', 'deleted');
            $xw->writeElement('identifier', $identifier);
            $xw->writeElement('datestamp', $this->recordDatestamp($rec));
            $xw->endElement(); // header
            $xw->endElement(); // record
            $xw->endElement(); // GetRecord
            return;
        }
        if ($rec === null) {
            $this->oaiError($xw, 'idDoesNotExist',
                'The value of the identifier argument is unknown or illegal in this repository.');
            return;
        }

        if ($rec['_entity'] === 'archival_unit'
            && $metadataPrefix !== 'oai_dc'
            && $metadataPrefix !== 'ric-o') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for archival_unit records in this repository.');
            return;
        }
        if ($rec['_entity'] === 'book' && $metadataPrefix === 'ric-o') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'metadataPrefix=ric-o is only available for archival_unit records.');
            return;
        }
        if ($rec['_entity'] === 'periodical' && $metadataPrefix !== 'oai_dc') {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for periodical records in this repository.');
            return;
        }

        $datestamp = $this->recordDatestamp($rec);

        $xw->startElement('GetRecord');
        $xw->startElement('record');
        $xw->startElement('header');
        $xw->writeElement('identifier', $identifier);
        $xw->writeElement('datestamp', $datestamp);
        $xw->writeElement('setSpec', $this->setSpecForEntity((string) $rec['_entity']));
        $xw->endElement(); // header

        try {
            $xw->startElement('metadata');
            $this->writeMetadata($xw, $rec, $metadataPrefix, $host);
            $xw->endElement(); // metadata
        } catch (CannotDisseminateFormatException $e) {
            // FIX (CR full review): close the still-open <metadata>,
            // <record> and <GetRecord> elements before emitting the
            // top-level <error>. Without this the response is not
            // well-formed XML when CannotDisseminateFormatException
            // fires after startElement('metadata') has already opened
            // the inner subtree.
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // metadata
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // record
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // GetRecord
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for this record type.');
            return;
        } catch (\Throwable $e) {
            // FIX (L1-F5 / OAI sibling): symmetrical generic-Throwable
            // catch matching oaiListRecords (line ~1108). Any unexpected
            // error inside writeMetadata (UTF-8 XMLWriter error,
            // RuntimeException from RicJsonLdBuilder, etc.) would
            // otherwise propagate with <metadata>/<record>/<GetRecord>
            // half-open, breaking the response. Mirror the close sequence
            // and surface as cannotDisseminateFormat so the harvester
            // can recover.
            \App\Support\SecureLogger::warning('OAI-PMH GetRecord writeMetadata threw', [
                'metadataPrefix' => $metadataPrefix,
                'entity'         => $rec['_entity'] ?? null,
                'id'             => $rec['id'] ?? null,
                'error'          => $e->getMessage(),
            ]);
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // metadata
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // record
            try { $xw->endElement(); } catch (\Throwable $ignored) {} // GetRecord
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'Internal error rendering metadata for this record.');
            return;
        }

        $xw->endElement(); // record
        $xw->endElement(); // GetRecord
    }

    // ── Metadata format dispatcher ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $rec
     */
    private function writeMetadata(\XMLWriter $xw, array $rec, string $metadataPrefix, string $host = 'localhost'): void
    {
        if ($rec['_entity'] === 'archival_unit') {
            $this->writeArchivalUnitMetadata($xw, $rec, $metadataPrefix, $host);
            return;
        }

        if ($rec['_entity'] === 'periodical') {
            $this->writePeriodicalMetadata($xw, $rec, $metadataPrefix, $host);
            return;
        }

        // Books — use pre-fetched related data when available (batch path from fetchRecordsPage),
        // otherwise fall back to individual queries (GetRecord / direct download paths).
        $bookId    = (int) $rec['id'];
        $authors   = array_key_exists('_authors', $rec)
            ? (array) $rec['_authors']
            : $this->fetchAuthorsForBook($bookId);
        // Multi-publisher (issue #143): a list of publishers, ordered. Falls back
        // to the single primary publisher (editore_id) on pre-#143 data.
        $publishers = array_key_exists('_publishers', $rec) && is_array($rec['_publishers'])
            ? $rec['_publishers']
            : $this->fetchPublishersForBook($bookId, !empty($rec['editore_id']) ? (int) $rec['editore_id'] : null);
        $genre     = array_key_exists('_genre', $rec)
            ? (is_array($rec['_genre']) ? $rec['_genre'] : null)
            : (!empty($rec['genere_id']) ? $this->fetchGenre((int) $rec['genere_id']) : null);

        match ($metadataPrefix) {
            'oai_dc'  => $this->writeBookOaiDc($xw, $rec, $authors, $publishers, $genre, $host),
            'marcxml' => $this->writeBookMarcXml($xw, $rec, $authors, $publishers, $genre),
            'mods'    => $this->writeBookMods($xw, $rec, $authors, $publishers, $genre),
            'mag'     => $this->writeBookMag($xw, $rec, $authors, $publishers, $genre),
            'unimarc' => $this->writeBookUnimarc($xw, $rec, $authors, $publishers, $genre),
            default   => null,
        };
    }

    // ── oai_dc for books ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookOaiDc(
        \XMLWriter $xw,
        array $row,
        array $authors,
        array $publishers,
        ?array $genre,
        string $host = 'localhost'
    ): void {
        $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

        // dc:title
        $title = (string) ($row['titolo'] ?? '');
        if (!empty($row['sottotitolo'])) {
            $title .= ' : ' . (string) $row['sottotitolo'];
        }
        $xw->writeElementNs('dc', 'title', null, $title);

        // Creators and other contributors have distinct Dublin Core semantics.
        foreach ($authors as $a) {
            $role = (string) ($a['ruolo'] ?? '');
            $element = in_array($role, ['principale', 'co-autore'], true) ? 'creator' : 'contributor';
            $xw->writeElementNs('dc', $element, null, (string) $a['nome']);
        }

        // dc:subject (genre + keywords)
        if ($genre !== null && !empty($genre['nome'])) {
            $xw->writeElementNs('dc', 'subject', null, (string) $genre['nome']);
        }
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') { $xw->writeElementNs('dc', 'subject', null, $kw); }
            }
        }

        // dc:description
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $xw->writeElementNs('dc', 'description', null, strip_tags((string) $desc));
        }

        // dc:publisher — repeatable in Dublin Core (one element per publisher).
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $xw->writeElementNs('dc', 'publisher', null, (string) $pub['nome']);
            }
        }

        // Legacy safety-net columns are exported only when that role has not
        // already been represented by an entity above.
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        foreach (['traduttore', 'illustratore', 'curatore'] as $col) {
            if (!empty($row[$col]) && !in_array($col, $entityRoles, true)) {
                $xw->writeElementNs('dc', 'contributor', null, (string) $row[$col]);
            }
        }

        // dc:date
        if (!empty($row['anno_pubblicazione'])) {
            $xw->writeElementNs('dc', 'date', null, (string) $row['anno_pubblicazione']);
        }

        // dc:type — DCMI Type Vocabulary term for the media type
        $xw->writeElementNs('dc', 'type', null, $this->dcmiType((string) ($row['tipo_media'] ?? 'libro')));

        // dc:format
        if (!empty($row['formato'])) {
            $xw->writeElementNs('dc', 'format', null, (string) $row['formato']);
        }

        // dc:identifier — OAI identifier first, then all available ISBNs/EAN
        $xw->writeElementNs('dc', 'identifier', null, 'oai:' . $host . ':book:' . $row['id']);
        foreach (['isbn13', 'isbn10', 'ean'] as $col) {
            if (!empty($row[$col])) {
                $xw->writeElementNs('dc', 'identifier', null, (string) $row[$col]);
            }
        }

        // dc:language — ISO 639-2 code per language (free text kept when unknown)
        foreach ($this->languageList((string) ($row['lingua'] ?? '')) as $language) {
            $xw->writeElementNs('dc', 'language', null, $this->languageCode($language) ?? $language);
        }

        $xw->endElement(); // oai_dc:dc
    }

    // ── MARCXML for books ─────────────────────────────────────────────────────

    /**
     * Return the responsibility entry that must own the main-entry field.
     * A principal creator always wins even if a caller supplied contributors
     * or co-authors first; co-author is only the legacy fallback.
     *
     * @param list<array<string, mixed>> $authors
     */
    private function primaryCreatorIndex(array $authors): ?int
    {
        $coauthorIndex = null;
        foreach ($authors as $index => $author) {
            $role = (string) ($author['ruolo'] ?? '');
            if ($role === 'principale') {
                return $index;
            }
            if ($role === 'co-autore' && $coauthorIndex === null) {
                $coauthorIndex = $index;
            }
        }
        return $coauthorIndex;
    }

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMarcXml(
        \XMLWriter $xw,
        array $row,
        array $authors,
        array $publishers,
        ?array $genre
    ): void {
        $xw->startElementNs(null, 'record', 'http://www.loc.gov/MARC21/slim');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd');

        // Leader: type 'a' (language material), bibliographic level 'm'
        // (monograph), 's' for a serial. Leader/18 stays blank: the record
        // carries no ISBD punctuation, so 'i' (ISBD) would be a false claim.
        $xw->writeElement('leader', '00000na' . ($this->isSerialRecord($row) ? 's' : 'm') . ' a2200000   4500');

        // 001 — Control number (book id)
        $this->marcControlField($xw, '001', (string) $row['id']);

        // 003 — MARC organization code
        $this->marcControlField($xw, '003', 'IT-Pinakes');

        // 005 — Date/time of latest transaction
        $ts = strtotime((string) ($row['updated_at'] ?? 'now')) ?: time();
        $this->marcControlField($xw, '005', gmdate('YmdHis', $ts) . '.0');

        // 008 — Fixed-length data elements (exactly 40 characters)
        $this->marcControlField($xw, '008', $this->marc21Field008($row));

        // 020 — ISBN
        if (!empty($row['isbn13'])) {
            $this->marcDataField($xw, '020', ' ', ' ', [['a', (string) $row['isbn13']]]);
        }
        if (!empty($row['isbn10'])) {
            $this->marcDataField($xw, '020', ' ', ' ', [['a', (string) $row['isbn10']]]);
        }

        // 022 — ISSN of the resource itself: serials only. On a monograph the
        // ISSN belongs to its series and is carried in 490 $x below.
        if (!empty($row['issn']) && $this->isSerialRecord($row)) {
            $this->marcDataField($xw, '022', ' ', ' ', [['a', (string) $row['issn']]]);
        }

        // 040 — Cataloging source
        $this->marcDataField($xw, '040', ' ', ' ', [
            ['a', 'IT-Pinakes'],
            ['b', 'ita'],
            ['e', 'rda'],
            ['c', 'IT-Pinakes'],
        ]);

        // 041 — Language
        if (!empty($row['lingua'])) {
            $this->marcDataField($xw, '041', '0', ' ', [
                ['a', $this->iso639_3ToMarc((string) $row['lingua'])],
            ]);
        }

        // 082 — Dewey classification
        if (!empty($row['classificazione_dewey'])) {
            $this->marcDataField($xw, '082', '0', '4', [
                ['a', (string) $row['classificazione_dewey']],
                ['2', '23'],
            ]);
        }

        // 100/700 — creators and role-aware contributors.
        $primaryCreatorIndex = $this->primaryCreatorIndex($authors);
        foreach ($authors as $index => $a) {
            $name = (string) $a['nome'];
            $role = (string) ($a['ruolo'] ?? '');
            $relator = match ($role) {
                'traduttore' => 'translator',
                'illustratore' => 'illustrator',
                'curatore' => 'editor',
                'colorista' => 'colorist',
                default => 'author',
            };
            if ($index === $primaryCreatorIndex) {
                $this->marcDataField($xw, '100', '1', ' ', [
                    ['a', $name],
                    ['e', $relator],
                ]);
            } else {
                $this->marcDataField($xw, '700', '1', ' ', [
                    ['a', $name],
                    ['e', $relator],
                ]);
            }
        }

        // 245 — Title statement
        $titleSubs = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $titleSubs[] = ['b', (string) $row['sottotitolo']];
        }
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        if (!empty($row['traduttore']) && !in_array('traduttore', $entityRoles, true)) {
            $titleSubs[] = ['c', 'traduzione di ' . (string) $row['traduttore']];
        }
        $ind1 = $primaryCreatorIndex !== null ? '1' : '0';
        $this->marcDataField($xw, '245', $ind1, '0', $titleSubs);

        // 250 — Edition
        if (!empty($row['edizione'])) {
            $this->marcDataField($xw, '250', ' ', ' ', [['a', (string) $row['edizione']]]);
        }

        // 264 — Production/Publication (RDA). MARC21 $b (publisher name) is
        // repeatable, so multiple publishers become repeated $b in one 264.
        $pubSubs = [];
        // $a — place of publication, as printed (core 0.7.89).
        if (!empty($row['luogo_pubblicazione'])) {
            $pubSubs[] = ['a', (string) $row['luogo_pubblicazione']];
        }
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $pubSubs[] = ['b', (string) $pub['nome']];
            }
        }
        if (!empty($row['anno_pubblicazione'])) {
            $pubSubs[] = ['c', (string) $row['anno_pubblicazione']];
        }
        if (!empty($pubSubs)) {
            $this->marcDataField($xw, '264', ' ', '1', $pubSubs);
        }

        // 300 — Physical description
        $physSubs = [];
        if (!empty($row['numero_pagine'])) {
            $physSubs[] = ['a', (string) $row['numero_pagine'] . ' pages'];
        }
        if (!empty($row['dimensioni'])) {
            $physSubs[] = ['c', (string) $row['dimensioni']];
        }
        if (!empty($physSubs)) {
            $this->marcDataField($xw, '300', ' ', ' ', $physSubs);
        }

        // 490 — Series statement
        if (!empty($row['collana'])) {
            $serSubs = [['a', (string) $row['collana']]];
            if (!empty($row['issn']) && !$this->isSerialRecord($row)) {
                $serSubs[] = ['x', (string) $row['issn']];
            }
            if (!empty($row['numero_serie'])) {
                $serSubs[] = ['v', (string) $row['numero_serie']];
            }
            $this->marcDataField($xw, '490', '0', ' ', $serSubs);
        }

        // 520 — Summary
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $this->marcDataField($xw, '520', ' ', ' ', [['a', strip_tags((string) $desc)]]);
        }

        // 650 — Subject
        if ($genre !== null && !empty($genre['nome'])) {
            $this->marcDataField($xw, '650', ' ', '4', [['a', (string) $genre['nome']]]);
        }
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $this->marcDataField($xw, '650', ' ', '4', [['a', $kw]]);
                }
            }
        }

        // 700 — legacy contributors not already represented by entities.
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        foreach (['traduttore' => 'translator', 'illustratore' => 'illustrator', 'curatore' => 'editor'] as $col => $role) {
            if (!empty($row[$col]) && !in_array($col, $entityRoles, true)) {
                $this->marcDataField($xw, '700', '1', ' ', [
                    ['a', (string) $row[$col]],
                    ['e', $role],
                ]);
            }
        }

        $xw->endElement(); // record
    }

    private function marcControlField(\XMLWriter $xw, string $tag, string $text): void
    {
        $xw->startElement('controlfield');
        $xw->writeAttribute('tag', $tag);
        $xw->text($text);
        $xw->endElement();
    }

    /**
     * @param list<array{0: string, 1: string}> $subfields
     */
    private function marcDataField(\XMLWriter $xw, string $tag, string $ind1, string $ind2, array $subfields): void
    {
        $xw->startElement('datafield');
        $xw->writeAttribute('tag', $tag);
        $xw->writeAttribute('ind1', $ind1);
        $xw->writeAttribute('ind2', $ind2);
        foreach ($subfields as [$code, $value]) {
            $xw->startElement('subfield');
            $xw->writeAttribute('code', $code);
            $xw->text($value);
            $xw->endElement();
        }
        $xw->endElement();
    }

    // ── UNIMARC for books ─────────────────────────────────────────────────────

    /**
     * UNIMARC/XML serialisation using the MARCXchange XML container.
     * Field codes follow the UNIMARC Bibliographic format (IFLA 2008).
     *
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookUnimarc(
        \XMLWriter $xw,
        array $row,
        array $authors,
        array $publishers,
        ?array $genre
    ): void {
        $xw->startElementNs(null, 'record', self::NS_MARCXCHANGE);
        // FIX (CR confirm): declare the `xsi` namespace before using
        // `xsi:schemaLocation`. In the OAI dissemination path the
        // OAI envelope already declares xmlns:xsi on <OAI-PMH>, but
        // the standalone /admin/books/{id}/unimarc.xml download
        // emits <record> as the document root, so without this
        // attribute the xsi:schemaLocation prefix is unbound.
        $xw->writeAttributeNs('xmlns', 'xsi', null,
            'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttribute('type', 'Bibliographic');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            self::NS_MARCXCHANGE . ' ' . self::SCHEMA_MARCXCHANGE);

        $xw->writeElement('leader', self::UNIMARC_LEADER_TEMPLATE);

        foreach ($this->unimarcFields($row, $authors, $publishers, $genre) as [$tag, $ind1, $ind2, $data]) {
            if ($ind1 === null || $ind2 === null || is_string($data)) {
                $this->marcControlField($xw, $tag, is_string($data) ? $data : '');
            } else {
                $this->marcDataField($xw, $tag, $ind1, $ind2, $data);
            }
        }

        $xw->endElement(); // record
    }

    /**
     * The UNIMARC Bibliographic fields of a book, in tag order — one source
     * for the MARCXchange writer and the ISO 2709 serializer, so the two
     * downloads can never disagree. Control fields (001-005) carry their
     * value as a string and null indicators.
     *
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     * @return list<array{0: string, 1: ?string, 2: ?string, 3: string|list<array{0: string, 1: string}>}>
     */
    private function unimarcFields(array $row, array $authors, array $publishers, ?array $genre): array
    {
        $fields = [];

        // 001 — Record identifier
        $fields[] = ['001', null, null, (string) $row['id']];
        // 003 — Persistent record identifier source (repository base URL)
        $fields[] = ['003', null, null, absoluteUrl('/')];
        // 005 — Version identifier: date/time of the latest modification
        $updated = strtotime((string) ($row['updated_at'] ?? '')) ?: time();
        $fields[] = ['005', null, null, gmdate('YmdHis', $updated) . '.0'];

        // 010 — ISBN only. 073 — EAN (an EAN that is just the ISBN-13 again
        // is not repeated).
        foreach (['isbn13', 'isbn10'] as $col) {
            if (!empty($row[$col])) {
                $fields[] = ['010', ' ', ' ', [['a', (string) $row[$col]]]];
                break;
            }
        }
        $ean = trim((string) ($row['ean'] ?? ''));
        $isbn13Digits = preg_replace('/\D/', '', (string) ($row['isbn13'] ?? ''));
        if ($ean !== '' && preg_replace('/\D/', '', $ean) !== $isbn13Digits) {
            $fields[] = ['073', ' ', ' ', [['a', $ean]]];
        }

        // 100 — General processing data
        $langCode = $this->iso639_3ToMarc((string) ($row['lingua'] ?? 'italiano'));
        $fields[] = ['100', ' ', ' ', [['a', $this->unimarcField100a($row)]]];

        // 101 — Language of the item
        $fields[] = ['101', '0', ' ', [['a', $langCode]]];

        // 102 — Country of publication
        $fields[] = ['102', ' ', ' ', [['a', 'IT']]];

        // 200 — Title and statement of responsibility
        $subs200 = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $subs200[] = ['e', (string) $row['sottotitolo']];
        }
        $primaryCreatorIndex = $this->primaryCreatorIndex($authors);
        if ($primaryCreatorIndex !== null) {
            $subs200[] = ['f', (string) $authors[$primaryCreatorIndex]['nome']];
        }
        $fields[] = ['200', '1', ' ', $subs200];

        // 205 — Edition statement
        if (!empty($row['edizione'])) {
            $fields[] = ['205', ' ', ' ', [['a', (string) $row['edizione']]]];
        }

        // 210 — Publication, distribution. $c (publisher name) is repeatable,
        // so multiple publishers become repeated $c in one 210.
        $subs210 = [];
        if (!empty($row['luogo_pubblicazione'])) {
            $subs210[] = ['a', (string) $row['luogo_pubblicazione']];
        }
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $subs210[] = ['c', (string) $pub['nome']];
            }
        }
        $year = (string) ($row['anno_pubblicazione'] ?? '');
        if ($year !== '') {
            $subs210[] = ['d', $year];
        }
        if ($subs210 !== []) {
            $fields[] = ['210', ' ', ' ', $subs210];
        }

        // 215 — Physical description
        $subs215 = [];
        if (!empty($row['numero_pagine'])) {
            $subs215[] = ['a', $row['numero_pagine'] . ' p.'];
        }
        if (!empty($row['dimensioni'])) {
            $subs215[] = ['d', (string) $row['dimensioni']];
        }
        if ($subs215 !== []) {
            $fields[] = ['215', ' ', ' ', $subs215];
        }

        // 225 — Series (UNIMARC equivalent of MARC21 490)
        if (!empty($row['collana'])) {
            $subs225 = [['a', (string) $row['collana']]];
            if (!empty($row['numero_serie'])) {
                $subs225[] = ['v', (string) $row['numero_serie']];
            }
            $fields[] = ['225', '0', ' ', $subs225];
        }

        // 330 — Summary / abstract
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $fields[] = ['330', ' ', ' ', [['a', strip_tags((string) $desc)]]];
        }

        // 606 — Subject: genre, then keywords
        if ($genre !== null && !empty($genre['nome'])) {
            $fields[] = ['606', ' ', ' ', [['a', (string) $genre['nome']]]];
        }
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $fields[] = ['606', ' ', ' ', [['a', $kw]]];
                }
            }
        }

        // 700/701 — primary and alternative intellectual responsibility.
        // 702 — secondary responsibility (translator, illustrator, etc.).
        $responsibility = [];
        foreach ($authors as $index => $a) {
            $role = (string) ($a['ruolo'] ?? '');
            $isCreator = in_array($role, ['principale', 'co-autore'], true);
            $tag = $isCreator ? ($index === $primaryCreatorIndex ? '700' : '701') : '702';
            $relCode = match ($role) {
                'traduttore'   => '730',
                'curatore'     => '340',
                'illustratore' => '440',
                'colorista'    => '410',
                default        => '070',
            };
            [$ind2, $nameSubs] = $this->unimarcPersonalName((string) $a['nome']);
            $nameSubs[] = ['4', $relCode];
            $responsibility[$tag][] = [$tag, ' ', $ind2, $nameSubs];
        }
        foreach (['700', '701', '702'] as $tag) {
            foreach ($responsibility[$tag] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        // 801 — Originating source
        $fields[] = ['801', ' ', '0', [
            ['a', 'IT'],
            ['b', 'Pinakes'],
            ['c', gmdate('Ymd')],
        ]];

        return $fields;
    }

    /**
     * UNIMARC 100 $a — general processing data, exactly 36 positions:
     *   0-7 date entered (YYYYMMDD) · 8 type of publication date (d single
     *   known date, u unknown) · 9-12 date 1 · 13-16 date 2 · 17-19 target
     *   audience · 20 government publication (y not a government
     *   publication) · 21 modified record (0) · 22-24 language of
     *   cataloguing · 25 transliteration (y none) · 26-29 character set
     *   (50 = ISO 10646) · 30-33 additional character sets · 34-35 script
     *   of title (ba = Latin).
     *
     * @param array<string, mixed> $row
     */
    private function unimarcField100a(array $row): string
    {
        $entered = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $year    = trim((string) ($row['anno_pubblicazione'] ?? ''));
        $known   = strlen($year) === 4 && ctype_digit($year);

        $value = date('Ymd', $entered)
            . ($known ? 'd' : 'u')
            . ($known ? $year : '    ')
            . '    '
            . '   '
            . 'y'
            . '0'
            . $this->cataloguingLanguage()
            . 'y'
            . '50  '
            . '    '
            . 'ba';

        return $value;
    }

    /** ISO 639-2/B language of cataloguing, from the installation locale. */
    private function cataloguingLanguage(): string
    {
        try {
            $locale = \App\Support\I18n::getInstallationLocale();
        } catch (\Throwable) {
            $locale = 'it_IT';
        }
        return match (strtolower(substr($locale, 0, 2))) {
            'en'    => 'eng',
            'de'    => 'ger',
            'fr'    => 'fre',
            'da'    => 'dan',
            default => 'ita',
        };
    }

    /**
     * UNIMARC personal name (7XX): [ind2, subfields]. A name that can be
     * inverted ("Surname, Forename", or a multi-word name whose last word is
     * taken as the surname) is entered under the surname — ind2 1, $a surname
     * $b forename; a single-word name stays in direct order — ind2 0, $a.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>}
     */
    private function unimarcPersonalName(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (str_contains($name, ',')) {
            [$surname, $forename] = array_map('trim', explode(',', $name, 2));
            if ($surname !== '' && $forename !== '') {
                return ['1', [['a', $surname], ['b', $forename]]];
            }
        }
        $words = $name === '' ? [] : explode(' ', $name);
        if (count($words) >= 2) {
            $surname = (string) array_pop($words);
            return ['1', [['a', $surname], ['b', implode(' ', $words)]]];
        }
        return ['0', [['a', $name]]];
    }

    // ── MODS 3.7 for books ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMods(
        \XMLWriter $xw,
        array $row,
        array $authors,
        array $publishers,
        ?array $genre
    ): void {
        $xw->startElementNs(null, 'mods', 'http://www.loc.gov/mods/v3');
        $xw->writeAttribute('version', '3.7');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-7.xsd');

        // titleInfo
        $xw->startElement('titleInfo');
        $xw->writeElement('title', (string) ($row['titolo'] ?? ''));
        if (!empty($row['sottotitolo'])) {
            $xw->writeElement('subTitle', (string) $row['sottotitolo']);
        }
        $xw->endElement();

        // Names retain the entity role instead of promoting every contributor
        // to an author.
        foreach ($authors as $a) {
            $role = match ((string) ($a['ruolo'] ?? '')) {
                'traduttore' => 'translator',
                'illustratore' => 'illustrator',
                'curatore' => 'editor',
                'colorista' => 'colorist',
                default => 'author',
            };
            $xw->startElement('name');
            $xw->writeAttribute('type', 'personal');
            // namePart@type only allows date|family|given|termsOfAddress:
            // a stored "Surname, Forename" splits into family/given, any
            // other form stays a single untyped namePart.
            $nameParts = explode(',', (string) $a['nome'], 2);
            if (count($nameParts) === 2 && trim($nameParts[0]) !== '' && trim($nameParts[1]) !== '') {
                $xw->startElement('namePart');
                $xw->writeAttribute('type', 'family');
                $xw->text(trim($nameParts[0]));
                $xw->endElement();
                $xw->startElement('namePart');
                $xw->writeAttribute('type', 'given');
                $xw->text(trim($nameParts[1]));
                $xw->endElement();
            } else {
                $xw->writeElement('namePart', (string) $a['nome']);
            }
            $xw->startElement('role');
            $xw->startElement('roleTerm');
            $xw->writeAttribute('type', 'text');
            $xw->writeAttribute('authority', 'marcrelator');
            $xw->text($role);
            $xw->endElement();
            $xw->endElement(); // role
            $xw->endElement(); // name
        }

        // Additional contributors
        $contribs = [
            ['traduttore', 'translator'],
            ['illustratore', 'illustrator'],
            ['curatore', 'editor'],
        ];
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        foreach ($contribs as [$col, $role]) {
            if (!empty($row[$col]) && !in_array($col, $entityRoles, true)) {
                $xw->startElement('name');
                $xw->writeAttribute('type', 'personal');
                $xw->writeElement('displayForm', (string) $row[$col]);
                $xw->startElement('role');
                $xw->startElement('roleTerm');
                $xw->writeAttribute('type', 'text');
                $xw->writeAttribute('authority', 'marcrelator');
                $xw->text($role);
                $xw->endElement();
                $xw->endElement(); // role
                $xw->endElement(); // name
            }
        }

        // typeOfResource
        $xw->writeElement('typeOfResource', 'text');

        // originInfo — MODS allows multiple <publisher> elements.
        $xw->startElement('originInfo');
        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $xw->writeElement('publisher', (string) $pub['nome']);
            }
        }
        if (!empty($row['anno_pubblicazione'])) {
            $xw->startElement('dateIssued');
            $xw->writeAttribute('encoding', 'w3cdtf');
            $xw->text((string) $row['anno_pubblicazione']);
            $xw->endElement();
        }
        if (!empty($row['edizione'])) {
            $xw->writeElement('edition', (string) $row['edizione']);
        }
        $xw->endElement(); // originInfo

        // language
        if (!empty($row['lingua'])) {
            $xw->startElement('language');
            $xw->startElement('languageTerm');
            $xw->writeAttribute('type', 'text');
            $xw->text((string) $row['lingua']);
            $xw->endElement();
            $xw->endElement();
        }

        // physicalDescription
        $xw->startElement('physicalDescription');
        if (!empty($row['formato'])) {
            $xw->writeElement('form', (string) $row['formato']);
        }
        if (!empty($row['numero_pagine'])) {
            $xw->writeElement('extent', (string) $row['numero_pagine'] . ' pages');
        }
        $xw->endElement();

        // abstract
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $xw->writeElement('abstract', strip_tags((string) $desc));
        }

        // subject (genre)
        if ($genre !== null && !empty($genre['nome'])) {
            $xw->startElement('subject');
            $xw->writeElement('topic', (string) $genre['nome']);
            $xw->endElement();
        }

        // subject (keywords)
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $xw->startElement('subject');
                    $xw->writeElement('topic', $kw);
                    $xw->endElement();
                }
            }
        }

        // classification — Dewey
        if (!empty($row['classificazione_dewey'])) {
            $xw->startElement('classification');
            $xw->writeAttribute('authority', 'ddc');
            $xw->text((string) $row['classificazione_dewey']);
            $xw->endElement();
        }

        // relatedItem — series
        if (!empty($row['collana'])) {
            $xw->startElement('relatedItem');
            $xw->writeAttribute('type', 'series');
            $xw->startElement('titleInfo');
            $xw->writeElement('title', (string) $row['collana']);
            $xw->endElement();
            $xw->endElement();
        }

        // identifier — ISBN, ISSN, EAN
        foreach (['isbn13' => 'isbn', 'isbn10' => 'isbn', 'issn' => 'issn', 'ean' => 'ean'] as $col => $type) {
            if (!empty($row[$col])) {
                $xw->startElement('identifier');
                $xw->writeAttribute('type', $type);
                $xw->text((string) $row[$col]);
                $xw->endElement();
            }
        }

        // recordInfo
        $xw->startElement('recordInfo');
        $xw->writeElement('recordContentSource', 'IT-Pinakes');
        $ts = strtotime((string) ($row['created_at'] ?? 'now')) ?: time();
        $xw->startElement('recordCreationDate');
        $xw->writeAttribute('encoding', 'w3cdtf');
        $xw->text(gmdate('Y-m-d', $ts));
        $xw->endElement();
        $xw->endElement(); // recordInfo

        $xw->endElement(); // mods
    }

    // ── MAG 2.0.1 for books ───────────────────────────────────────────────────

    /**
     * MAG 2.0.1 (Metadati Amministrativi e Gestionali) — ICCU standard.
     * Primarily designed for digitized materials; for physical books, emits the
     * <bib> section only. When digital assets exist (file_url), the <img>/<doc>
     * section is included.
     *
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMag(
        \XMLWriter $xw,
        array $row,
        array $authors,
        array $publishers,
        ?array $genre
    ): void {
        $magNs     = self::MAG_NS;
        $dcNs      = 'http://purl.org/dc/elements/1.1/';
        $magSchema = self::MAG_SCHEMA;

        // Use pre-fetched MAG project config when available (batch path), else fetch once.
        $magCfg = (array_key_exists('_mag_config', $row) && is_array($row['_mag_config']) && !empty($row['_mag_config']))
            ? $row['_mag_config']
            : $this->fetchMagProjectConfig();

        $xw->startElementNs(null, 'metadigit', $magNs);
        $xw->writeAttributeNs('xmlns', 'dc', null, $dcNs);
        $xw->writeAttributeNs('xmlns', 'xlink', null, self::XLINK_NS);
        $xw->writeAttributeNs('xmlns', 'niso', null, self::NISO_MAG_NS);
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null, $magNs . ' ' . $magSchema);
        $xw->writeAttribute('version', '2.0.1');

        // Digital object: prefer the pre-fetched asset (avoids N+1 on list
        // verbs), else one query (GetRecord / direct MAG download); a bare
        // libri.file_url is the legacy fallback.
        if (array_key_exists('_digital_asset', $row)) {
            $preAsset = $row['_digital_asset'];
            $asset = is_array($preAsset) ? $preAsset : null;
        } else {
            $asset = $this->fetchDigitalAsset((int) $row['id']);
        }
        if ($asset === null && !empty($row['file_url'])) {
            $asset = ['url' => (string) $row['file_url'], 'filetype' => 'PDF'];
        }

        // ── <gen> — General metadata (MAG 2.0.1: stprog, collection?, agency,
        // access_rights, completeness). stprog/collection are URIs; a plain
        // project code or collection name is kept as a comment.
        $projectCode    = trim((string) ($magCfg['project_code'] ?? ''));
        $collectionName = trim((string) ($magCfg['collection_name'] ?? ''));
        $baseCfgUrl     = trim((string) ($magCfg['base_url'] ?? ''));
        $rights         = trim((string) ($magCfg['rights_statement'] ?? ''));
        $xw->startElement('gen');
        if ($projectCode !== '' && !$this->isAbsoluteUri($projectCode)) {
            $xw->writeComment(' project: ' . str_replace('--', '- -', $projectCode) . ' ');
        }
        $xw->writeElement('stprog', $this->isAbsoluteUri($projectCode)
            ? $projectCode
            : ($this->isAbsoluteUri($baseCfgUrl) ? $baseCfgUrl : absoluteUrl('/')));
        if ($this->isAbsoluteUri($collectionName)) {
            $xw->writeElement('collection', $collectionName);
        } elseif ($collectionName !== '') {
            $xw->writeComment(' collection: ' . str_replace('--', '- -', $collectionName) . ' ');
        }
        $xw->writeElement('agency', (string) ($magCfg['institution_code'] ?? 'IT-UNKNOWN'));
        // 1 = public use, 0 = use restricted to the institution.
        $xw->writeElement('access_rights', $this->isOpenRights($rights) ? '1' : '0');
        // 0 = complete digitisation, 1 = incomplete (no digital object yet).
        $xw->writeElement('completeness', $asset !== null ? '0' : '1');
        $xw->endElement(); // gen

        // ── <bib> — Bibliographic metadata: @level and Dublin Core only, in
        // the order of the MAG schema's bib sequence.
        $xw->startElement('bib');
        $xw->writeAttribute('level', $this->isSerialRecord($row) ? 's' : 'm');

        $identifiers = [];
        foreach (['isbn13', 'isbn10'] as $col) {
            if (!empty($row[$col])) {
                $identifiers[] = (string) $row[$col];
            }
        }
        $identifiers[] = (string) ($row['id'] ?? '');
        foreach ($identifiers as $identifier) {
            $xw->writeElementNs('dc', 'identifier', null, $identifier);
        }

        // Dublin Core title.
        $title = (string) ($row['titolo'] ?? '');
        if (!empty($row['sottotitolo'])) {
            $title .= ': ' . (string) $row['sottotitolo'];
        }
        $xw->writeElementNs('dc', 'title', null, $title);

        // Preserve creator/contributor semantics in the embedded Dublin Core.
        $contributors = [];
        foreach ($authors as $a) {
            $role = (string) ($a['ruolo'] ?? '');
            if (in_array($role, ['principale', 'co-autore'], true)) {
                $xw->writeElementNs('dc', 'creator', null, (string) $a['nome']);
            } else {
                $contributors[] = (string) $a['nome'];
            }
        }

        foreach ($publishers as $pub) {
            if (!empty($pub['nome'])) {
                $xw->writeElementNs('dc', 'publisher', null, (string) $pub['nome']);
            }
        }

        if ($genre !== null && !empty($genre['nome'])) {
            $xw->writeElementNs('dc', 'subject', null, (string) $genre['nome']);
        }

        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $xw->writeElementNs('dc', 'description', null, strip_tags((string) $desc));
        }

        // Fallback for contributors still held on the legacy free-text columns
        // (not yet promoted to entities by the backfill) — mirrors oai_dc/marcxml
        // so the MAG Dublin Core sub-record doesn't drop them mid-migration.
        $entityRoles = array_map(static fn (array $a): string => (string) ($a['ruolo'] ?? ''), $authors);
        foreach (['traduttore', 'illustratore', 'curatore'] as $col) {
            if (!empty($row[$col]) && !in_array($col, $entityRoles, true)) {
                $contributors[] = (string) $row[$col];
            }
        }
        foreach ($contributors as $contributor) {
            $xw->writeElementNs('dc', 'contributor', null, $contributor);
        }

        if (!empty($row['anno_pubblicazione'])) {
            $xw->writeElementNs('dc', 'date', null, (string) $row['anno_pubblicazione']);
        }

        $xw->writeElementNs('dc', 'type', null, $this->dcmiType((string) ($row['tipo_media'] ?? 'libro')));

        $xw->writeElementNs('dc', 'format', null, (string) ($row['formato'] ?? 'text'));

        foreach ($this->languageList((string) ($row['lingua'] ?? '')) as $language) {
            $xw->writeElementNs('dc', 'language', null, $this->languageCode($language) ?? $language);
        }

        // Place of publication as printed: the spatial coverage of the edition.
        if (!empty($row['luogo_pubblicazione'])) {
            $xw->writeElementNs('dc', 'coverage', null, (string) $row['luogo_pubblicazione']);
        }

        if ($rights !== '') {
            $xw->writeElementNs('dc', 'rights', null, $rights);
        }

        $xw->endElement(); // bib

        // ── <img>/<doc> — the digital file (sequence_number, nomenclature,
        // file@xlink:href, md5, filesize, then the type-specific metrics and
        // format). Images go to <img>, every other file type to <doc>.
        if ($asset !== null) {
            $baseUrl = $baseCfgUrl !== '' ? rtrim($baseCfgUrl, '/') : '';
            $fileUrl = (string) ($asset['url'] ?? '');
            if (!preg_match('/^https?:\/\//', $fileUrl) && $baseUrl !== '') {
                $fileUrl = $baseUrl . '/' . ltrim($fileUrl, '/');
            }
            [$formatName, $mime] = $this->magFileFormat((string) ($asset['filetype'] ?? 'PDF'));
            $isImage = str_starts_with($mime, 'image/');
            $md5     = strtolower(trim((string) ($asset['md5_hash'] ?? '')));
            $size    = (int) ($asset['filesize'] ?? 0);

            $xw->startElement($isImage ? 'img' : 'doc');
            $xw->writeElement('sequence_number', '1');
            $nomenclature = basename((string) (parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl));
            $xw->writeElement('nomenclature', $nomenclature !== '' ? $nomenclature : $title);
            $xw->startElement('file');
            $xw->writeAttributeNs('xlink', 'href', null, $fileUrl);
            $xw->endElement(); // file
            if (preg_match('/^[0-9a-f]{32}$/', $md5) === 1) {
                $xw->writeElement('md5', $md5);
            }
            if ($size > 0) {
                $xw->writeElement('filesize', (string) $size);
            }
            if ($isImage) {
                $width  = (int) ($asset['image_width'] ?? 0);
                $height = (int) ($asset['image_height'] ?? 0);
                if ($width > 0 && $height > 0) {
                    $xw->startElement('image_dimensions');
                    $xw->writeElementNs('niso', 'imagelength', null, (string) $height);
                    $xw->writeElementNs('niso', 'imagewidth', null, (string) $width);
                    $xw->endElement(); // image_dimensions
                }
                if ((int) ($asset['ppi'] ?? 0) > 0) {
                    $xw->writeElement('ppi', (string) (int) $asset['ppi']);
                }
            }
            $xw->startElement('format');
            $xw->writeElement('name', $formatName);
            $xw->writeElement('mime', $mime);
            $xw->endElement(); // format
            $xw->endElement(); // img / doc
        }

        $xw->endElement(); // metadigit
    }

    /** True for an absolute URI (scheme:...), the shape MAG's anyURI fields expect here. */
    private function isAbsoluteUri(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*:\S+$/i', $value) === 1;
    }

    /** Whether a rights statement grants public use (MAG gen/access_rights = 1). */
    private function isOpenRights(string $rights): bool
    {
        $r = strtolower($rights);
        foreach (['public domain', 'pubblico dominio', 'no copyright', 'creativecommons.org', 'cc0', 'cc by', 'cc-by'] as $open) {
            if (str_contains($r, $open)) {
                return true;
            }
        }
        return false;
    }

    /**
     * MAG format/name and format/mime for a digital_assets.filetype value.
     *
     * @return array{0: string, 1: string}
     */
    private function magFileFormat(string $filetype): array
    {
        return match (strtoupper(trim($filetype))) {
            'JPG', 'JPEG' => ['JPG', 'image/jpeg'],
            'TIF', 'TIFF' => ['TIF', 'image/tiff'],
            'PNG'         => ['PNG', 'image/png'],
            'GIF'         => ['GIF', 'image/gif'],
            'JP2'         => ['JP2', 'image/jp2'],
            'EPUB'        => ['EPUB', 'application/epub+zip'],
            'DJVU'        => ['DJVU', 'image/vnd.djvu'],
            'TXT'         => ['TXT', 'text/plain'],
            'XML'         => ['XML', 'text/xml'],
            'HTML', 'HTM' => ['HTML', 'text/html'],
            default       => ['PDF', 'application/pdf'],
        };
    }

    // ── Archival unit metadata (delegates to archives plugin formats) ─────────

    /**
     * @param array<string, mixed> $rec
     */
    private function writeArchivalUnitMetadata(\XMLWriter $xw, array $rec, string $metadataPrefix, string $host = 'localhost'): void
    {
        // For archival units, we produce minimal DC output here AND, since
        // v0.7.12, full RiC-O RDF/XML via the writeArchivalUnitRicO()
        // branch below when metadataPrefix=ric-o. (FIX F024: prior comment
        // suggested only the archives plugin's own /archives/oai endpoint
        // exposed richer formats; that is now also true of this one for
        // ric-o.)
        if ($metadataPrefix === 'oai_dc') {
            $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
            $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
            $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
            $xw->writeAttributeNs('xsi', 'schemaLocation', null,
                'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

            $xw->writeElementNs('dc', 'title', null, (string) ($rec['constructed_title'] ?? $rec['formal_title'] ?? ''));
            // DCMI Type: an aggregation level (fonds, series, ...) is a
            // Collection; a single file or item is described as Text.
            $level = strtolower((string) ($rec['level'] ?? 'fonds'));
            $xw->writeElementNs('dc', 'type', null, in_array($level, ['file', 'item'], true) ? 'Text' : 'Collection');
            if (!empty($rec['reference_code'])) {
                $xw->writeElementNs('dc', 'identifier', null, (string) $rec['reference_code']);
            }
            if (!empty($rec['scope_content'])) {
                $xw->writeElementNs('dc', 'description', null, strip_tags((string) $rec['scope_content']));
            }
            if (!empty($rec['language_codes'])) {
                $xw->writeElementNs('dc', 'language', null, (string) $rec['language_codes']);
            }

            $xw->endElement(); // oai_dc:dc
            return;
        }

        // Phase 6 (v0.7.12): RiC-O as RDF/XML for archival units.
        if ($metadataPrefix === 'ric-o') {
            $this->writeArchivalUnitRicO($xw, $rec, $host);
            return;
        }

        // Non-supported formats fall through to cannotDisseminateFormat.
        throw new CannotDisseminateFormatException($metadataPrefix);
    }

    /**
     * Phase 6: emit a RiC-O RDF/XML payload for one archival_unit row.
     *
     * Delegates the JSON-LD shape to the Archives plugin's
     * RicJsonLdBuilder (single source of truth for ICA semantics) and
     * canonicalises it to RDF/XML through the builder's serialiser.
     *
     * Per-record cost: 3 short prepared queries (authorities + direct
     * children + linked activities) plus the in-memory tree walk. We
     * re-issue the queries per-record rather than batching because
     * OAI-PMH `ric-o` harvests are expected to be small (one fonds
     * tree at a time, typically).
     *
     * @param array<string, mixed> $rec
     */
    private function writeArchivalUnitRicO(\XMLWriter $xw, array $rec, string $host = 'localhost'): void
    {
        $unitId = (int) ($rec['id'] ?? 0);
        if ($unitId <= 0) {
            throw new CannotDisseminateFormatException('ric-o');
        }

        $builderClass = '\\App\\Plugins\\Archives\\RicJsonLdBuilder';
        if (!class_exists($builderClass, false)) {
            $builderFile = dirname(__DIR__) . '/archives/RicJsonLdBuilder.php';
            if (!is_file($builderFile)) {
                throw new CannotDisseminateFormatException('ric-o');
            }
            require_once $builderFile;
        }

        $authorities = $this->fetchAuthoritiesForArchivalUnitRic($unitId);
        $children    = $this->fetchDirectChildrenForRic($unitId);
        $activities  = $this->fetchActivitiesForUnitRic($unitId);

        // Use absoluteUrl() when available; otherwise build from the OAI request
        // host so rdf:about attributes are always absolute IRIs (RDF clients
        // resolve relative URIs against the harvester's POV otherwise).
        $baseUrl = function_exists('absoluteUrl')
            ? rtrim((string) \absoluteUrl(''), '/')
            : '';
        if ($baseUrl === '' && $host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $host;
        }
        // F042: prefer the installation-wide locale (same source the main
        // Archives plugin uses) over the unreliable APP_LOCALE env var,
        // which is rarely set under FPM. Fall through to getenv when the
        // I18n class is not loaded in this scope (plugin loaded in
        // isolation, e.g. from a bare CLI harvester).
        $locale = class_exists('\\App\\Support\\I18n')
            ? (string) (\App\Support\I18n::getInstallationLocale() ?: 'it')
            : (getenv('APP_LOCALE') ?: 'it');

        /** @var \App\Plugins\Archives\RicJsonLdBuilder $builder */
        $builder = new $builderClass($baseUrl, $locale);
        $doc     = $builder->buildUnit($rec, $authorities, $children, $activities);

        $xw->startElementNs('rdf', 'RDF', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xw->writeAttributeNs('xmlns', 'rdfs', null, $builderClass::NS_RDFS);
        $xw->writeAttributeNs('xmlns', 'xsd',  null, $builderClass::NS_XSD);
        $xw->writeAttributeNs('xmlns', 'owl',  null, $builderClass::NS_OWL);
        $xw->writeAttributeNs('xmlns', 'ric',  null, $builderClass::NS_RIC);

        $builder->serializeToRdfXml($doc, $xw);

        $xw->endElement(); // rdf:RDF
    }

    /**
     * Load Phase 2 authority rows linked to an archival unit, in the
     * shape RicJsonLdBuilder::buildUnit expects (id, type, authorised_form,
     * dates_of_existence, role). Returns [] on any error — the surrounding
     * caller will simply emit a unit with no agent relations rather than
     * failing the whole OAI response.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAuthoritiesForArchivalUnitRic(int $unitId): array
    {
        // NOTE: link table is `archival_unit_authority` (singular) — same
        // shape ArchivesPlugin::fetchAuthoritiesForArchivalUnit uses.
        $sql = "SELECT a.id, a.type, a.authorised_form, a.dates_of_existence, l.role
                  FROM archival_unit_authority l
                  JOIN authority_records a ON a.id = l.authority_id
                 WHERE l.archival_unit_id = ?
                   AND a.deleted_at IS NULL
                 ORDER BY l.role, a.authorised_form";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $unitId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $rows = $res instanceof \mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /**
     * Load direct children of an archival_unit for inclusion as
     * ric:hasOrHadPart references. We only need id+level+titles —
     * the children themselves serve their own OAI record on harvest.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchDirectChildrenForRic(int $unitId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, level, constructed_title, formal_title
               FROM archival_units
              WHERE parent_id = ? AND deleted_at IS NULL' . $this->archivalPublishedSql() . '
              ORDER BY reference_code'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $unitId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $rows = $res instanceof \mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /**
     * F022 (Phase 8): load RiC-CM Phase 3 activity links for one
     * archival unit, in the shape RicJsonLdBuilder::buildUnit expects
     * (activity_id, ric_predicate, title, activity_type, date_start,
     * date_end). Same silent-degrade contract as the other fetch
     * helpers in this plugin: errors / missing link table return [].
     *
     * Mirrors ArchivesPlugin::fetchActivitiesForUnit() (which throws
     * on persistence error). The OAI surface stays soft-failing so a
     * Phase-3 schema gap on a partially-upgraded install never
     * shipwrecks a whole harvest response.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchActivitiesForUnitRic(int $unitId): array
    {
        $sql = "SELECT a.id AS activity_id, l.ric_predicate, a.title, a.activity_type, a.date_start, a.date_end
                  FROM archive_unit_activities l
                  JOIN archive_activities a ON a.id = l.activity_id
                 WHERE l.unit_id = ?
                   AND a.deleted_at IS NULL
                 ORDER BY a.title";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $unitId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $rows = $res instanceof \mysqli_result ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    // ── Periodical (emeroteca_testate) metadata ───────────────────────────────

    /**
     * Issue #140: dispatcher for periodical mastheads. Only oai_dc is
     * disseminable — every other writer in this class is monograph-shaped —
     * so anything else raises cannotDisseminateFormat, consistently with the
     * upfront gates in oaiListRecords()/oaiGetRecord().
     *
     * @param array<string, mixed> $rec
     */
    private function writePeriodicalMetadata(
        \XMLWriter $xw,
        array $rec,
        string $metadataPrefix,
        string $host = 'localhost'
    ): void {
        if ($metadataPrefix !== 'oai_dc') {
            throw new CannotDisseminateFormatException($metadataPrefix);
        }

        // Batch path pre-attaches the related rows; GetRecord resolves a bare
        // testata row and falls back to the single-row fetchers (same
        // pre-fetch/fallback contract books use in writeMetadata()).
        $publisher = array_key_exists('_publisher', $rec)
            ? (is_array($rec['_publisher']) ? $rec['_publisher'] : null)
            : (!empty($rec['editore_id']) ? $this->fetchPublisher((int) $rec['editore_id']) : null);
        $genre = array_key_exists('_genre', $rec)
            ? (is_array($rec['_genre']) ? $rec['_genre'] : null)
            : (!empty($rec['genere_id']) ? $this->fetchGenre((int) $rec['genere_id']) : null);

        $this->writePeriodicalOaiDc($xw, $rec, $publisher, $genre, $host);
    }

    /**
     * Dublin Core for one periodical masthead.
     *
     * Field map (emeroteca_testate → oai_dc):
     *   titolo [+ ' : ' sottotitolo]        → dc:title
     *   descrizione                          → dc:description (tags stripped)
     *   issn / e_issn / issn_l               → dc:identifier (urn:ISSN:…)
     *   public masthead URL                  → dc:identifier
     *   OAI identifier                       → dc:identifier
     *   editori.nome (editore_id)            → dc:publisher
     *   lingua                               → dc:language
     *   tipo (+ constant 'Periodical')       → dc:type
     *   anno_inizio[-anno_fine]              → dc:date
     *   luogo_pubblicazione                  → dc:coverage
     *   generi.nome (genere_id)              → dc:subject
     *   periodicita                          → dc:description (frequency note)
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $publisher
     * @param array<string, mixed>|null $genre
     */
    private function writePeriodicalOaiDc(
        \XMLWriter $xw,
        array $row,
        ?array $publisher,
        ?array $genre,
        string $host = 'localhost'
    ): void {
        $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

        // dc:title — subtitle joined ISBD-style, exactly like books.
        $title = (string) ($row['titolo'] ?? '');
        if (!empty($row['sottotitolo'])) {
            $title .= ' : ' . (string) $row['sottotitolo'];
        }
        $xw->writeElementNs('dc', 'title', null, $title);

        // dc:subject — the classification genre, when linked.
        if ($genre !== null && !empty($genre['nome'])) {
            $xw->writeElementNs('dc', 'subject', null, (string) $genre['nome']);
        }

        // dc:description — free text first, then the frequency note.
        if (!empty($row['descrizione'])) {
            $xw->writeElementNs('dc', 'description', null, strip_tags((string) $row['descrizione']));
        }
        if (!empty($row['periodicita'])) {
            $xw->writeElementNs('dc', 'description', null, 'Periodicity: ' . (string) $row['periodicita']);
        }

        // dc:publisher
        if ($publisher !== null && !empty($publisher['nome'])) {
            $xw->writeElementNs('dc', 'publisher', null, (string) $publisher['nome']);
        }

        // dc:date — the run of the title. Open-ended runs keep the trailing
        // separator so a harvester can tell "1950-" from a single-year run.
        $start = trim((string) ($row['anno_inizio'] ?? ''));
        $end   = trim((string) ($row['anno_fine'] ?? ''));
        if ($start !== '') {
            $xw->writeElementNs('dc', 'date', null, $end !== '' ? $start . '-' . $end : $start . '-');
        } elseif ($end !== '') {
            $xw->writeElementNs('dc', 'date', null, '-' . $end);
        }

        // dc:type — generic serial type first (harvester-facing), then the
        // local flavour (rivista / giornale / magazine / bollettino / fanzine).
        $xw->writeElementNs('dc', 'type', null, 'Text'); // DCMI Type Vocabulary
        $xw->writeElementNs('dc', 'type', null, 'Periodical');
        $tipo = trim((string) ($row['tipo'] ?? ''));
        if ($tipo !== '') {
            $xw->writeElementNs('dc', 'type', null, ucfirst($tipo));
        }

        // dc:identifier — OAI id, then every ISSN flavour as a URN, then the
        // absolute public URL of the masthead page.
        $xw->writeElementNs('dc', 'identifier', null,
            'oai:' . $host . ':periodical:' . (string) ($row['id'] ?? ''));
        $seenIssn = [];
        foreach (['issn', 'e_issn', 'issn_l'] as $col) {
            $issn = strtoupper(trim((string) ($row[$col] ?? '')));
            if ($issn === '' || isset($seenIssn[$issn])) {
                continue;
            }
            $seenIssn[$issn] = true;
            $xw->writeElementNs('dc', 'identifier', null, 'urn:ISSN:' . $issn);
        }
        $publicUrl = $this->periodicalPublicUrl((int) ($row['id'] ?? 0), $host);
        if ($publicUrl !== '') {
            $xw->writeElementNs('dc', 'identifier', null, $publicUrl);
        }

        // dc:language
        if (!empty($row['lingua'])) {
            $xw->writeElementNs('dc', 'language', null, (string) $row['lingua']);
        }

        // dc:coverage — place of publication.
        if (!empty($row['luogo_pubblicazione'])) {
            $xw->writeElementNs('dc', 'coverage', null, (string) $row['luogo_pubblicazione']);
        }

        $xw->endElement(); // oai_dc:dc
    }

    /**
     * Absolute URL of the public masthead page (/emeroteca/{id}, localized). Prefers
     * absoluteUrl() (canonical base, same source the RiC-O writer uses) and
     * degrades to the OAI request host when the helper is unavailable
     * (plugin loaded standalone, e.g. from a bare CLI harvester).
     */
    private function periodicalPublicUrl(int $id, string $host): string
    {
        if ($id <= 0) {
            return '';
        }
        // The section's localized base ('periodicals' route key); the
        // historical /emeroteca still answers when the core is not loaded.
        $section = class_exists(\App\Support\RouteTranslator::class)
            ? \App\Support\RouteTranslator::route('periodicals')
            : '/emeroteca';
        if (function_exists('absoluteUrl')) {
            $url = (string) \absoluteUrl($section . '/' . $id);
            if ($url !== '') {
                return $url;
            }
        }
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $host . $section . '/' . $id;
    }

    // ── Identifier resolution ─────────────────────────────────────────────────

    /**
     * Resolve OAI identifier to a DB row. Returns null if not found.
     * Accepts:
     *   oai:{host}:book:{id}
     *   oai:{host}:archival_unit:{id}
     *   oai:{host}:periodical:{id}
     *   oai:pinakes:book:{id}         (canonical fallback)
     *   oai:pinakes:archival_unit:{id}
     *   oai:pinakes:periodical:{id}
     *
     * @return array<string, mixed>|null
     */
    private function resolveIdentifier(string $identifier, string $host): ?array
    {
        // Try book pattern.
        if (preg_match('/^oai:(?:pinakes|' . preg_quote($host, '/') . '):book:(\d+)$/i', $identifier, $m)) {
            $id   = (int) $m[1];
            // A requested book (desiderata) is not a holding and must not
            // resolve as one, so it is excluded here. It is not simply unknown
            // either: see the de-listing branch below, which answers a deleted
            // header instead of idDoesNotExist when the repository is allowed
            // to report deletions at all.
            $stmt = $this->db->prepare(
                'SELECT l.*
                   FROM libri l
                  WHERE l.id = ? AND l.deleted_at IS NULL AND ' . \App\Support\BookVisibility::catalogue($this->db, 'l')
            );
            if ($stmt === false) { return null; }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row !== null) {
                $row['_entity'] = 'book';
                $row['_status'] = 'active';
                return $row;
            }

            // Still here, still flagged: the record was withdrawn from the
            // holdings rather than never existing. Answering idDoesNotExist
            // would tell a harvester that already holds it to keep its stale
            // copy; a deleted header tells it to drop the record, which is the
            // truth. ONE row, deliberately: a book that was flagged AND then
            // soft-deleted does not match here (deleted_at IS NOT NULL) and
            // falls through to resolveDeletedIdentifier()'s real tombstone, so
            // GetRecord can never produce two conflicting resolutions.
            //
            // Gated on hasActiveTriggers() for the same conformance reason as
            // the de-listing arm in fetchRecordsPage(): without them Identify
            // advertises deletedRecord='no', and a repository at that level may
            // not reveal a deleted status in any response. There it falls back
            // to idDoesNotExist, which is what this method answered before.
            if (!$this->hasActiveTriggers()) { return null; }
            $stmt = $this->db->prepare(
                'SELECT l.id, l.updated_at
                   FROM libri l
                  WHERE l.id = ? AND l.deleted_at IS NULL AND '
                . \App\Support\BookVisibility::delisted($this->db, 'l')
                // Same pairing as the ListIdentifiers/ListRecords arm: a
                // record born as a request was never handed to anyone, so
                // answering "deleted" for it both misstates the protocol and
                // confirms a wish-list id to whoever guessed it. Without this
                // the two responses would disagree about the same identifier.
                . ' AND ' . \App\Support\BookVisibility::everCatalogued($this->db, 'l')
            );
            if ($stmt === false) { return null; }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row !== null) {
                return [
                    '_entity'    => 'book',
                    '_status'    => 'deleted',
                    'entity_id'  => (int) $row['id'],
                    'datestamp'  => $row['updated_at'],
                    '_datestamp' => $row['updated_at'],
                ];
            }
        }

        // Try archival_unit pattern.
        if (preg_match('/^oai:(?:pinakes|' . preg_quote($host, '/') . '):archival_unit:(\d+)$/i', $identifier, $m)) {
            $id   = (int) $m[1];
            $stmt = $this->db->prepare('SELECT * FROM archival_units WHERE id = ? AND deleted_at IS NULL');
            if (!$stmt) { return null; }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if (!($res instanceof \mysqli_result)) { return null; }
            $row = $res->fetch_assoc();
            $res->free();
            if ($row !== null) {
                // Off the site (Archives' "published" flag): it does not exist
                // for a harvester. Not a deletion: nothing records whether it
                // was ever public, and deletedRecord may be "no" here.
                if ($this->archivalPublishedSql() !== '' && (int) ($row['published'] ?? 1) !== 1) {
                    return null;
                }
                $row['_entity'] = 'archival_unit';
                $row['_status'] = 'active';
                return $row;
            }
        }

        // Try periodical masthead pattern (issue #140). Resolvable only while
        // the Emeroteca bridge is exposed, so a deactivated plugin cannot be
        // harvested record-by-record through GetRecord.
        if (preg_match('/^oai:(?:pinakes|' . preg_quote($host, '/') . '):periodical:(\d+)$/i', $identifier, $m)
            && $this->isPeriodicalsSetExposed()
        ) {
            $id   = (int) $m[1];
            $stmt = $this->db->prepare(
                'SELECT id, titolo, sottotitolo, issn, e_issn, issn_l, editore_id,
                        luogo_pubblicazione, lingua, periodicita, tipo,
                        anno_inizio, anno_fine, genere_id, descrizione,
                        stato_raccolta, created_at, updated_at
                   FROM emeroteca_testate WHERE id = ?'
            );
            if ($stmt === false) { return null; }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row !== null) {
                $row['_entity'] = 'periodical';
                $row['_status'] = 'active';
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDeletedIdentifier(string $identifier, string $host): ?array
    {
        // Parse entity_type + entity_id from the identifier so the lookup is
        // host-independent: the trigger stores oai:pinakes:book:{id} but requests
        // arrive with oai:{realhost}:book:{id} — matching by entity fields avoids
        // the mismatch.
        $hostPat = preg_quote($host, '/');
        if (preg_match('/^oai:(?:pinakes|' . $hostPat . '):book:(\d+)$/i', $identifier, $m)) {
            $entityType = 'book';
            $entityId   = (int) $m[1];
        } elseif (preg_match('/^oai:(?:pinakes|' . $hostPat . '):archival_unit:(\d+)$/i', $identifier, $m)) {
            $entityType = 'archival_unit';
            $entityId   = (int) $m[1];
        } elseif (preg_match('/^oai:(?:pinakes|' . $hostPat . '):periodical:(\d+)$/i', $identifier, $m)) {
            // Masthead tombstones live in their own table (hard delete).
            // Gated on exposure exactly like the active lookup: a deactivated
            // Emeroteca must not leak deletion history either.
            if (!$this->isPeriodicalsSetExposed() || !$this->hasPeriodicalTombstoneTable()) {
                return null;
            }
            $periodicalId = (int) $m[1];
            $stmt = $this->db->prepare(
                'SELECT id, entity_id, oai_id, datestamp FROM oai_deleted_periodicals WHERE entity_id = ?'
            );
            if ($stmt === false) { return null; }
            $stmt->bind_param('i', $periodicalId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row === null) { return null; }
            $row['_entity'] = 'periodical';
            $row['_status'] = 'deleted';

            return $row;
        } else {
            return null;
        }

        // The soft-delete trigger records EVERY book, including a request that
        // was never in the catalogue, so a deletion is answered here only for a
        // record a harvester could actually have received — the same rule the
        // ListIdentifiers/ListRecords arm applies (see neverPublishedGuard()).
        // CI-SOFT-DELETE-EXEMPT: a tombstone is by definition about a soft-deleted libri row.
        $stmt = $this->db->prepare(
            'SELECT * FROM oai_deleted_records d WHERE d.entity_type = ? AND d.entity_id = ? AND ' . $this->neverPublishedGuard('d')
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('si', $entityType, $entityId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row !== null) {
            $row['_entity'] = (string) $row['entity_type'];
            $row['_status'] = 'deleted';
            return $row;
        }

        return null;
    }

    // ── Record page fetcher ───────────────────────────────────────────────────

    /** Cached INFORMATION_SCHEMA probe for the periodical tombstone table. */
    private ?bool $periodicalTombstoneTableCache = null;

    private function hasPeriodicalTombstoneTable(): bool
    {
        if ($this->periodicalTombstoneTableCache !== null) {
            return $this->periodicalTombstoneTableCache;
        }
        $exists = false;
        $r = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oai_deleted_periodicals'"
        );
        if ($r instanceof \mysqli_result) {
            $exists = ((int) ($r->fetch_assoc()['c'] ?? 0)) > 0;
            $r->free();
        }

        return $this->periodicalTombstoneTableCache = $exists;
    }

    /** Cached probe for archival_units.published (Archives 1.5.2+). */
    private ?bool $archivalPublishedColumnExists = null;

    /**
     * SQL that keeps only the archival units published on the site, or ''
     * when the installed Archives version has no publication flag yet.
     */
    private function archivalPublishedSql(string $alias = ''): string
    {
        if ($this->archivalPublishedColumnExists === null) {
            $exists = false;
            if ($this->hasArchivalUnitsTable()) {
                $r = $this->db->query(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units' AND COLUMN_NAME = 'published'"
                );
                $exists = $r instanceof \mysqli_result && ((int) ($r->fetch_assoc()['c'] ?? 0)) > 0;
                if ($r instanceof \mysqli_result) { $r->free(); }
            }
            $this->archivalPublishedColumnExists = $exists;
        }
        return $this->archivalPublishedColumnExists ? ' AND ' . $alias . 'published = 1' : '';
    }

    /** Cached INFORMATION_SCHEMA probe for archival_units. */
    private function hasArchivalUnitsTable(): bool
    {
        if ($this->archivalUnitsTableExists === null) {
            $r = $this->db->query(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
            );
            $this->archivalUnitsTableExists = $r instanceof \mysqli_result
                && ((int) ($r->fetch_assoc()['c'] ?? 0)) > 0;
            if ($r instanceof \mysqli_result) { $r->free(); }
        }

        return $this->archivalUnitsTableExists;
    }

    /**
     * Which entity arms take part in a harvest, given the (already resolved)
     * fetch set and the requested metadataPrefix.
     *
     * The metadataPrefix is part of the answer, not decoration: an arm whose
     * records cannot be disseminated in the requested format would enter the
     * UNION, reach writeMetadata(), throw CannotDisseminateFormatException and
     * be skipped one by one — and because the page is ordered by datestamp,
     * records inserted in the same session cluster together, so a whole page
     * could end up containing nothing but skipped records. That yields a
     * <ListRecords> with a resumptionToken and zero <record> children, which
     * the OAI-PMH XSD rejects (record has minOccurs=1).
     *
     * oaiListRecords() already resolves set='' to 'books' for the
     * monograph-only formats before calling in, so today no such arm can
     * enter; deciding it HERE means a future change to that mapping cannot
     * silently reintroduce the empty page.
     *
     * This method is also the single source of truth for the resumption-token
     * composition marker (see harvestComposition()).
     *
     * @return array{book:bool, archival_unit:bool, periodical:bool}
     */
    private function harvestArms(string $set, string $metadataPrefix): array
    {
        $unqualified = ($set === '');

        return [
            // Books carry every format except ric-o.
            'book' => ($unqualified || $set === 'books') && $metadataPrefix !== 'ric-o',
            // Archival units are oai_dc + ric-o only.
            'archival_unit' => ($unqualified || $set === 'archives')
                && ($metadataPrefix === 'oai_dc' || $metadataPrefix === 'ric-o'),
            // Mastheads are oai_dc only, and only while Emeroteca is exposed.
            'periodical' => ($set === 'periodicals' || ($unqualified && $metadataPrefix === 'oai_dc'))
                && $this->isPeriodicalsSetExposed(),
        ];
    }

    /**
     * Fingerprint of the UNION composition a resumption token was minted
     * against (issue #140 review).
     *
     * Paging is LIMIT/OFFSET over a UNION whose arms are decided at request
     * time by plugin activation gates. Activating (or deactivating) Emeroteca
     * or Archives mid-harvest therefore inserts or removes rows *before* the
     * harvester's current offset, and every record shifted across that
     * boundary is skipped — permanently, because an incremental harvester
     * never asks for those datestamps again. Nothing in the payload used to
     * tie a token to the shape of the result set it was computed on.
     *
     * The marker is stored in the token and re-derived on resume; a mismatch
     * is answered with badResumptionToken, which makes the harvester restart
     * the harvest (correct, complete data) instead of silently losing records.
     */
    private function harvestComposition(string $set, string $metadataPrefix): string
    {
        $arms  = $this->harvestArms($set, $metadataPrefix);
        $parts = [];
        foreach ($arms as $arm => $enabled) {
            if (!$enabled) {
                continue;
            }
            // The archives arm only really contributes rows when its table is
            // present, and that too can appear mid-harvest (archives installed).
            if ($arm === 'archival_unit' && !$this->hasArchivalUnitsTable()) {
                continue;
            }
            $parts[] = $arm;
        }

        // The books arm carries a second, de-listing tombstone arm, and that one
        // only exists while libri.is_desiderata does. Installing the desiderata
        // plugin mid-harvest therefore inserts rows before the harvester's
        // offset exactly the way activating Emeroteca does, so it belongs in the
        // marker: a token minted before it is refused, and the harvester
        // restarts instead of losing whatever crossed the boundary.
        if ($arms['book'] && $this->hasActiveTriggers() && \App\Support\BookVisibility::hasDesiderata($this->db)) {
            $parts[] = 'book_delisted';
        }

        return implode('|', $parts);
    }

    /**
     * Fetch up to $limit records (active + deleted) for the given set/date range.
     * Returns rows with _entity (book|archival_unit) and _status (active|deleted).
     *
     * Uses a UNION ALL subquery with DB-level LIMIT/OFFSET so only the requested
     * page is loaded — avoids fetching the entire repository into PHP memory.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRecordsPage(
        string $set,
        ?string $fromMysql,
        ?string $untilMysql,
        int $cursor,
        int $limit,
        string $metadataPrefix
    ): array {
        $arms       = $this->harvestArms($set, $metadataPrefix);
        $doBooks    = $arms['book'];
        $doArchives = $arms['archival_unit'];
        $doPeriodicals = $arms['periodical'];
        $auExists   = $doArchives && $this->hasArchivalUnitsTable();

        // Build UNION ALL parts for page identifiers only.
        // Each part returns: _id INT, _entity VARCHAR, _status VARCHAR,
        // _datestamp DATETIME, _source VARCHAR.
        //
        // _source names the TABLE _id belongs to, and it is not decoration: the
        // batch-detail step below looks deleted rows up by primary key, and the
        // two tombstone tables plus `libri` have overlapping auto-increment ids.
        // A de-listed book enters this union as a deleted row whose _id is a
        // `libri` id; resolving it against oai_deleted_records would emit a
        // header for an unrelated record — corruption, not an omission.
        $parts = [];
        $types = '';
        $vals  = [];

        if ($doBooks) {
            $w = ['l.deleted_at IS NULL'];
            // A requested book (desiderata) is not a holding: it must never be
            // listed to a harvester, whatever the set or date window.
            $w[] = \App\Support\BookVisibility::catalogue($this->db, 'l');
            if ($fromMysql !== null)  { $w[] = 'l.updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'l.updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            // CI-SOFT-DELETE-EXEMPT: $w is initialized for this UNION arm with l.deleted_at IS NULL.
            $parts[] = 'SELECT l.id AS _id, \'book\' AS _entity, \'active\' AS _status, l.updated_at AS _datestamp,'
                . ' \'libri\' AS _source'
                . ' FROM libri l WHERE ' . implode(' AND ', $w);

            // DE-LISTING TOMBSTONES. Flagging an already-harvested book as
            // wanted withdraws it from the holdings: the row stays, but it stops
            // being published. Without this arm the record simply never comes up
            // again and every remote catalogue keeps a stale copy forever — the
            // same failure the plugin already fixed for hard-deleted mastheads.
            //
            // Dated by updated_at, which the flag write bumps, so un-flagging
            // self-heals: the row reappears in the active arm at a newer
            // datestamp and this arm stops matching it. Currently-unflagged rows
            // are excluded, so one id is never both active and deleted in a
            // single response.
            //
            // Bounded like the ResourceSync tombstone windows (90 days with a
            // from=, 30 without), so a from=1970 harvest cannot walk out with
            // the library's entire wish list as identifiers.
            //
            // Gated on hasActiveTriggers() for conformance, not for capability:
            // this arm derives its tombstones from libri directly and would work
            // without them, but oaiIdentify() advertises deletedRecord='no' when
            // the triggers are absent (shared hosting with no TRIGGER privilege),
            // and OAI-PMH 2.0 §2.5.1 forbids revealing a deleted status at that
            // level. The pre-existing oai_deleted_records arm never had to say so
            // because its table simply stays empty without the triggers.
            if ($this->hasActiveTriggers()) {
            $delisted = \App\Support\BookVisibility::delisted($this->db, 'l');
            // Both halves, and the second is not redundant. "Wanted" alone
            // tombstones a record that was BORN a request and therefore never
            // reached a harvester at all — a deletion for something nobody was
            // ever given, which also hands anonymous harvesters the ids and
            // timestamps of the library's wish list. Only a row that was once
            // in the catalogue can have been withdrawn from it.
            $w = ['l.deleted_at IS NULL', $delisted, \App\Support\BookVisibility::everCatalogued($this->db, 'l')];
            $w[] = 'l.updated_at >= DATE_SUB(NOW(), INTERVAL ' . ($fromMysql !== null ? 90 : 30) . ' DAY)';
            if ($fromMysql !== null)  { $w[] = 'l.updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'l.updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            // CI-SOFT-DELETE-EXEMPT: $w is initialized for this UNION arm with l.deleted_at IS NULL.
            $parts[] = 'SELECT l.id AS _id, \'book\' AS _entity, \'deleted\' AS _status, l.updated_at AS _datestamp,'
                . ' \'libri\' AS _source'
                . ' FROM libri l WHERE ' . implode(' AND ', $w);
            }
        }

        if ($doArchives && $auExists) {
            $w = ['deleted_at IS NULL'];
            if ($fromMysql !== null)  { $w[] = 'updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            // Only units published on the site are harvestable. An
            // unpublished one is left out, never reported as deleted: nothing
            // records whether it was ever public (a draft never was), and
            // without the tombstone triggers Identify says deletedRecord=no.
            $publishedOnly = $this->archivalPublishedSql();
            if ($publishedOnly !== '') {
                $w[] = ltrim(substr($publishedOnly, 5));
            }
            $parts[] = 'SELECT id AS _id, \'archival_unit\' AS _entity, \'active\' AS _status, updated_at AS _datestamp,'
                . ' \'archival_units\' AS _source'
                . ' FROM archival_units WHERE ' . implode(' AND ', $w);
        }

        if ($doPeriodicals) {
            $w = [];
            if ($fromMysql !== null)  { $w[] = 'updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            $parts[] = 'SELECT id AS _id, \'periodical\' AS _entity, \'active\' AS _status, updated_at AS _datestamp,'
                . ' \'emeroteca_testate\' AS _source'
                . ' FROM emeroteca_testate'
                . ($w !== [] ? ' WHERE ' . implode(' AND ', $w) : '');

            // Mastheads are hard-deleted, so their tombstones live in their own
            // table (see schemaSteps()) and are unioned in separately. Probed,
            // never assumed: the table arrives with this plugin version and
            // PluginManager's self-heal may not have created it yet — a missing
            // table must degrade to "no periodical tombstones", never break the
            // whole ListRecords query.
            if ($this->hasPeriodicalTombstoneTable()) {
                $delW = [];
                if ($fromMysql !== null)  { $delW[] = 'datestamp >= ?'; $types .= 's'; $vals[] = $fromMysql; }
                if ($untilMysql !== null) { $delW[] = 'datestamp <= ?'; $types .= 's'; $vals[] = $untilMysql; }
                $parts[] = "SELECT id AS _id, 'periodical' AS _entity, 'deleted' AS _status, datestamp AS _datestamp,"
                    . " 'oai_deleted_periodicals' AS _source"
                    . ' FROM oai_deleted_periodicals'
                    . ($delW !== [] ? ' WHERE ' . implode(' AND ', $delW) : '');
            }
        }

        // Deletion tombstones only cover the entity types the requested set
        // actually contains. A set that tracks no deletions at all must NOT
        // pull in the whole tombstone table — hence the explicit allow-list.
        $delTypes = [];
        if ($doBooks)    { $delTypes[] = 'book'; }
        if ($doArchives) { $delTypes[] = 'archival_unit'; }
        if ($delTypes !== []) {
            $delW = [];
            // FIX (issue #140 review): the filter used to be applied only when
            // exactly ONE type was allowed, on the assumption that "two types"
            // meant "all types". That held while the ENUM had two values; it is
            // an accident waiting to happen, so the IN() list is now always
            // emitted. Values come from the fixed allow-list above, never from
            // input.
            $delW[] = "entity_type IN ('" . implode("','", $delTypes) . "')";
            $delW[] = $this->neverPublishedGuard('oai_deleted_records');
            if ($fromMysql !== null)  { $delW[] = 'datestamp >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $delW[] = 'datestamp <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            // CI-SOFT-DELETE-EXEMPT: the libri subquery in neverPublishedGuard() targets soft-deleted rows by design.
            $parts[] = "SELECT id AS _id, entity_type AS _entity, 'deleted' AS _status, datestamp AS _datestamp,"
                . " 'oai_deleted_records' AS _source"
                . " FROM oai_deleted_records WHERE " . implode(' AND ', $delW);
        }

        if ($parts === []) {
            return [];
        }

        // UNION ALL with DB-level ORDER + LIMIT + OFFSET. Paging needs nothing
        // else from the new arm: the cursor is a plain OFFSET into this ordered
        // union and PAGE_SIZE + 1 still decides hasMore, so the extra rows are
        // counted by the same arithmetic as every other arm and no separate
        // total is kept anywhere.
        $union   = implode(' UNION ALL ', $parts);
        $pageSql = "SELECT _id, _entity, _status, _datestamp, _source FROM ($union) AS _combined"
            . " ORDER BY _datestamp, _id, _entity, _status, _source LIMIT ? OFFSET ?";
        $types  .= 'ii';
        $vals[]  = $limit;
        $vals[]  = $cursor;

        $pageRefs = [];
        $stmt = $this->db->prepare($pageSql);
        if ($stmt !== false) {
            $stmt->bind_param($types, ...$vals);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_assoc()) { $pageRefs[] = $r; }
                $res->free();
            }
            $stmt->close();
        }

        if (empty($pageRefs)) {
            return [];
        }

        // Batch-fetch full book rows for the page.
        $bookMap = [];
        $bookIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter($pageRefs, fn($r) => $r['_entity'] === 'book' && $r['_status'] === 'active')
        ));
        if (!empty($bookIds)) {
            $ph  = implode(',', array_fill(0, count($bookIds), '?'));
            $sql = "SELECT l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.lingua,
                           l.isbn13, l.isbn10, l.ean, l.issn, l.editore_id, l.genere_id,
                           l.sottogenere_id, l.numero_pagine, l.formato, l.tipo_media,
                           l.descrizione, l.descrizione_plain, l.parole_chiave,
                           l.traduttore, l.illustratore, l.curatore, l.collana,
                           l.numero_serie, l.classificazione_dewey, l.file_url,
                           l.edizione, l.luogo_pubblicazione, l.dimensioni,
                           l.created_at, l.updated_at
                      FROM libri l WHERE l.deleted_at IS NULL AND " . \App\Support\BookVisibility::catalogue($this->db, 'l') . " AND l.id IN ($ph)";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($bookIds)), ...$bookIds);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $bookMap[(int) $r['id']] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        }

        // Batch-fetch full archival_unit rows for the page.
        $auMap = [];
        $auIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter($pageRefs, fn($r) => $r['_entity'] === 'archival_unit' && $r['_status'] === 'active')
        ));
        if (!empty($auIds) && $auExists) {
            $ph  = implode(',', array_fill(0, count($auIds), '?'));
            // Explicit column list (more reviewable than SELECT *) covering
            // every field RicJsonLdBuilder::buildUnit() reads. Missing any of
            // these columns would silently degrade the RiC-O JSON-LD output
            // for OAI-PMH ListRecords pages (e.g. empty extent, history,
            // dates, locations, rights, ARK).
            $sql = "SELECT id, level, parent_id,
                           reference_code, constructed_title, formal_title,
                           scope_content, archival_history, extent,
                           date_start, date_end,
                           physical_location, language_codes,
                           rights_statement_url, ark_identifier,
                           created_at, updated_at
                      FROM archival_units WHERE deleted_at IS NULL AND id IN ($ph)";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($auIds)), ...$auIds);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $auMap[(int) $r['id']] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        }

        // Batch-fetch full periodical masthead rows for the page (issue #140).
        $perMap = [];
        $perIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter($pageRefs, fn($r) => $r['_entity'] === 'periodical' && $r['_status'] === 'active')
        ));
        if (!empty($perIds) && $doPeriodicals) {
            $ph  = implode(',', array_fill(0, count($perIds), '?'));
            $sql = "SELECT id, titolo, sottotitolo, issn, e_issn, issn_l, editore_id,
                           luogo_pubblicazione, lingua, periodicita, tipo,
                           anno_inizio, anno_fine, genere_id, descrizione,
                           stato_raccolta, created_at, updated_at
                      FROM emeroteca_testate WHERE id IN ($ph)";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($perIds)), ...$perIds);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $perMap[(int) $r['id']] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        }

        // Batch-fetch deleted record details. Two tombstone tables now feed the
        // union, and their auto-increment ids overlap, so the maps are kept
        // apart and the assembly loop picks by _entity.
        $delMap    = [];
        $perDelMap = [];
        // Selected by _source, not by _entity: a de-listed book is also a
        // deleted, non-periodical row, but its _id is a `libri` id and looking
        // it up here would resolve an unrelated tombstone.
        $delIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter(
                $pageRefs,
                fn($r) => $r['_status'] === 'deleted' && ($r['_source'] ?? '') === 'oai_deleted_records'
            )
        ));
        if (!empty($delIds)) {
            $ph  = implode(',', array_fill(0, count($delIds), '?'));
            $sql = "SELECT id, entity_type AS _entity, entity_id, oai_id,
                           datestamp, datestamp AS _datestamp, 'deleted' AS _status
                      FROM oai_deleted_records WHERE id IN ($ph)";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($delIds)), ...$delIds);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $delMap[(int) $r['id']] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        }

        $perDelIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter(
                $pageRefs,
                fn($r) => $r['_status'] === 'deleted' && ($r['_source'] ?? '') === 'oai_deleted_periodicals'
            )
        ));
        if (!empty($perDelIds)) {
            $ph  = implode(',', array_fill(0, count($perDelIds), '?'));
            $sql = "SELECT id, 'periodical' AS _entity, entity_id, oai_id,
                           datestamp, datestamp AS _datestamp, 'deleted' AS _status
                      FROM oai_deleted_periodicals WHERE id IN ($ph)";
            $stmt = $this->db->prepare($sql);
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('i', count($perDelIds)), ...$perDelIds);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $perDelMap[(int) $r['id']] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        }

        // ── Batch-fetch related data for all book records on this page ─────────
        $authorsMap  = [];
        $publisherMap = [];
        $publishersByBook = []; // issue #143: all publishers per book (ordered)
        $genreMap    = [];

        if (!empty($bookIds)) {
            $ph    = implode(',', array_fill(0, count($bookIds), '?'));
            $types = str_repeat('i', count($bookIds));

            // Batch authors
            $stmtA = $this->db->prepare(
                "SELECT la.libro_id, a.nome, la.ruolo, la.ordine_credito
                   FROM libri_autori la
                   JOIN autori a ON a.id = la.autore_id
                  WHERE la.libro_id IN ($ph)
                  ORDER BY la.libro_id,
                           CASE la.ruolo WHEN 'principale' THEN 0 WHEN 'co-autore' THEN 1 ELSE 2 END,
                           la.ordine_credito IS NULL, la.ordine_credito, a.nome"
            );
            if ($stmtA !== false) {
                $stmtA->bind_param($types, ...$bookIds);
                $stmtA->execute();
                $resA = $stmtA->get_result();
                if ($resA instanceof \mysqli_result) {
                    while ($rowA = $resA->fetch_assoc()) {
                        $authorsMap[(int) $rowA['libro_id']][] = $rowA;
                    }
                    $resA->free();
                }
                $stmtA->close();
            }

            // Batch publishers
            $publisherIds = array_values(array_filter(array_unique(
                array_map(fn($bm) => (int) ($bm['editore_id'] ?? 0), $bookMap)
            )));
            if (!empty($publisherIds)) {
                $ph2   = implode(',', array_fill(0, count($publisherIds), '?'));
                $types2 = str_repeat('i', count($publisherIds));
                $stmtP = $this->db->prepare("SELECT id, nome FROM editori WHERE id IN ($ph2)");
                if ($stmtP !== false) {
                    $stmtP->bind_param($types2, ...$publisherIds);
                    $stmtP->execute();
                    $resP = $stmtP->get_result();
                    if ($resP instanceof \mysqli_result) {
                        while ($rowP = $resP->fetch_assoc()) {
                            $publisherMap[(int) $rowP['id']] = $rowP;
                        }
                        $resP->free();
                    }
                    $stmtP->close();
                }
            }

            // Batch ALL publishers per book (issue #143) from the junction.
            // Guarded: prepare() returns false on installs predating libri_editori.
            $stmtPB = $this->db->prepare(
                "SELECT le.libro_id, e.id, e.nome
                   FROM libri_editori le JOIN editori e ON e.id = le.editore_id
                  WHERE le.libro_id IN ($ph)
                  ORDER BY le.libro_id, le.ordine, e.nome"
            );
            if ($stmtPB !== false) {
                $stmtPB->bind_param($types, ...$bookIds);
                $stmtPB->execute();
                $resPB = $stmtPB->get_result();
                if ($resPB instanceof \mysqli_result) {
                    while ($rowPB = $resPB->fetch_assoc()) {
                        $publishersByBook[(int) $rowPB['libro_id']][] = ['id' => $rowPB['id'], 'nome' => $rowPB['nome']];
                    }
                    $resPB->free();
                }
                $stmtPB->close();
            }

            // Batch genres
            $genreIds = array_values(array_filter(array_unique(
                array_map(fn($bm) => (int) ($bm['genere_id'] ?? 0), $bookMap)
            )));
            if (!empty($genreIds)) {
                $ph3   = implode(',', array_fill(0, count($genreIds), '?'));
                $types3 = str_repeat('i', count($genreIds));
                $stmtG = $this->db->prepare("SELECT id, nome FROM generi WHERE id IN ($ph3)");
                if ($stmtG !== false) {
                    $stmtG->bind_param($types3, ...$genreIds);
                    $stmtG->execute();
                    $resG = $stmtG->get_result();
                    if ($resG instanceof \mysqli_result) {
                        while ($rowG = $resG->fetch_assoc()) {
                            $genreMap[(int) $rowG['id']] = $rowG;
                        }
                        $resG->free();
                    }
                    $stmtG->close();
                }
            }
        }

        // ── Batch-fetch related data for the periodical rows on this page ──────
        // (issue #140) Same N+1 avoidance the book arm does: one query for the
        // publishers and one for the genres referenced by the page's mastheads.
        $perPublisherMap = [];
        $perGenreMap     = [];
        if ($perMap !== []) {
            $perPublisherIds = array_values(array_filter(array_unique(
                array_map(fn($pm) => (int) ($pm['editore_id'] ?? 0), $perMap)
            )));
            if (!empty($perPublisherIds)) {
                $phP = implode(',', array_fill(0, count($perPublisherIds), '?'));
                $stmtPP = $this->db->prepare("SELECT id, nome FROM editori WHERE id IN ($phP)");
                if ($stmtPP !== false) {
                    $stmtPP->bind_param(str_repeat('i', count($perPublisherIds)), ...$perPublisherIds);
                    $stmtPP->execute();
                    $resPP = $stmtPP->get_result();
                    if ($resPP instanceof \mysqli_result) {
                        while ($rowPP = $resPP->fetch_assoc()) {
                            $perPublisherMap[(int) $rowPP['id']] = $rowPP;
                        }
                        $resPP->free();
                    }
                    $stmtPP->close();
                }
            }

            $perGenreIds = array_values(array_filter(array_unique(
                array_map(fn($pm) => (int) ($pm['genere_id'] ?? 0), $perMap)
            )));
            if (!empty($perGenreIds)) {
                $phG = implode(',', array_fill(0, count($perGenreIds), '?'));
                $stmtPG = $this->db->prepare("SELECT id, nome FROM generi WHERE id IN ($phG)");
                if ($stmtPG !== false) {
                    $stmtPG->bind_param(str_repeat('i', count($perGenreIds)), ...$perGenreIds);
                    $stmtPG->execute();
                    $resPG = $stmtPG->get_result();
                    if ($resPG instanceof \mysqli_result) {
                        while ($rowPG = $resPG->fetch_assoc()) {
                            $perGenreMap[(int) $rowPG['id']] = $rowPG;
                        }
                        $resPG->free();
                    }
                    $stmtPG->close();
                }
            }
        }

        // Batch-fetch digital_assets for all books on this page to avoid N+1 in writeBookMag().
        // ORDER BY libro_id, id + first-wins replicates fetchDigitalAsset's ORDER BY id LIMIT 1.
        $assetMap = [];
        if (!empty($bookIds)) {
            $ph4    = implode(',', array_fill(0, count($bookIds), '?'));
            $types4 = str_repeat('i', count($bookIds));
            $stmtA  = $this->db->prepare(
                "SELECT libro_id, url, md5_hash, filesize, image_width, image_height, ppi, filetype
                   FROM digital_assets WHERE libro_id IN ($ph4) ORDER BY libro_id, id"
            );
            if ($stmtA !== false) {
                $stmtA->bind_param($types4, ...$bookIds);
                $stmtA->execute();
                $resA = $stmtA->get_result();
                if ($resA instanceof \mysqli_result) {
                    while ($rowA = $resA->fetch_assoc()) {
                        $lid = (int) $rowA['libro_id'];
                        // First-wins: keep only the row with the smallest id per libro_id.
                        if (!isset($assetMap[$lid])) {
                            $assetMap[$lid] = $rowA;
                        }
                    }
                    $resA->free();
                }
                $stmtA->close();
            }
        }

        // Fetch MAG config once for the whole page (used by writeBookMag).
        $magConfig = !empty($bookIds) ? $this->fetchMagProjectConfig() : [];

        // Reassemble page in UNION order.
        $result = [];
        foreach ($pageRefs as $ref) {
            $id = (int) $ref['_id'];
            $source = (string) ($ref['_source'] ?? '');
            if ($ref['_status'] === 'deleted' && $source === 'libri') {
                // De-listed book: the tombstone has no row of its own. Built
                // here from the union reference, with entity_id carrying the
                // `libri` id buildOaiId() reads for a deleted record — the same
                // shape a tombstone table row arrives in.
                $result[] = [
                    '_entity'    => 'book',
                    '_status'    => 'deleted',
                    'entity_id'  => $id,
                    'datestamp'  => $ref['_datestamp'],
                    '_datestamp' => $ref['_datestamp'],
                ];
            } elseif ($ref['_status'] === 'deleted') {
                $tomb = $source === 'oai_deleted_periodicals'
                    ? ($perDelMap[$id] ?? null)
                    : ($delMap[$id] ?? null);
                if ($tomb !== null) { $result[] = $tomb; }
            } elseif ($ref['_entity'] === 'book' && isset($bookMap[$id])) {
                $row = $bookMap[$id];
                $row['_entity']    = 'book';
                $row['_status']    = 'active';
                $row['_datestamp'] = $row['updated_at'];
                // Attach pre-fetched related data to avoid N+1 queries in writeMetadata().
                $row['_authors']   = $authorsMap[$id] ?? [];
                $pbPrimary = $publisherMap[(int) ($row['editore_id'] ?? 0)] ?? null;
                $row['_publisher'] = $pbPrimary; // kept for back-compat
                $row['_publishers'] = $publishersByBook[$id]
                    ?? ($pbPrimary !== null ? [$pbPrimary] : []);
                $row['_genre']     = $genreMap[(int) ($row['genere_id'] ?? 0)] ?? null;
                $row['_mag_config'] = $magConfig;
                $row['_digital_asset'] = $assetMap[$id] ?? null;
                $result[] = $row;
            } elseif ($ref['_entity'] === 'archival_unit' && isset($auMap[$id])) {
                $row = $auMap[$id];
                $row['_entity']    = 'archival_unit';
                $row['_status']    = 'active';
                $row['_datestamp'] = $row['updated_at'];
                $result[] = $row;
            } elseif ($ref['_entity'] === 'periodical' && isset($perMap[$id])) {
                $row = $perMap[$id];
                $row['_entity']    = 'periodical';
                $row['_status']    = 'active';
                $row['_datestamp'] = $row['updated_at'];
                $row['_publisher'] = $perPublisherMap[(int) ($row['editore_id'] ?? 0)] ?? null;
                $row['_genre']     = $perGenreMap[(int) ($row['genere_id'] ?? 0)] ?? null;
                $result[] = $row;
            }
        }

        return $result;
    }

    // ── Resumption token management ───────────────────────────────────────────

    private function saveResumptionToken(
        string $metadataPrefix,
        string $from,
        string $until,
        string $set,
        int $cursor,
        string $composition
    ): string {
        $token   = bin2hex(random_bytes(24));
        $payload = json_encode([
            'metadataPrefix' => $metadataPrefix,
            'from'           => $from,
            'until'          => $until,
            'set'            => $set,
            'cursor'         => $cursor,
            // Which UNION arms the cursor was computed against — see
            // harvestComposition(). Without it, OFFSET paging silently skips
            // records when a content module is toggled mid-harvest.
            'composition'    => $composition,
        ], JSON_UNESCAPED_SLASHES);
        // FIX F063: previously computed `expires_at` with PHP `date(time()+TTL)`
        // (server local TZ from date.timezone), but loadResumptionToken()
        // compares against MySQL `NOW()` (MySQL session TZ). When PHP and MySQL
        // ran in different time zones (common on shared hosting: PHP=Europe/Rome
        // server-side default, MySQL=UTC), a freshly-minted token could already
        // appear expired or live for the wrong duration → spurious
        // badResumptionToken errors mid-harvest.
        //
        // Solution: compute the expiry in MySQL itself using
        // DATE_ADD(NOW(), INTERVAL ? SECOND) so the TZ is whatever NOW() is —
        // identical to the comparison side in loadResumptionToken().
        $ttl = self::TOKEN_TTL;

        $stmt = $this->db->prepare(
            'INSERT INTO oai_resumption_tokens (token, payload, expires_at) '
            . 'VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        if ($stmt === false) {
            SecureLogger::error('[OaiPmhServer] saveResumptionToken prepare() failed: ' . $this->db->error);
            return $token;
        }
        $stmt->bind_param('ssi', $token, $payload, $ttl);
        if (!$stmt->execute()) {
            SecureLogger::error('[OaiPmhServer] saveResumptionToken INSERT failed: ' . $stmt->error);
        }
        $stmt->close();

        return $token;
    }

    /**
     * @return array{metadataPrefix:string, from:string, until:string, set:string, cursor:int, composition:string}|null
     */
    private function loadResumptionToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT payload FROM oai_resumption_tokens WHERE token = ? AND expires_at > NOW()'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null || empty($row['payload'])) { return null; }

        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) { return null; }

        return [
            'metadataPrefix' => (string) ($payload['metadataPrefix'] ?? 'oai_dc'),
            'from'           => (string) ($payload['from'] ?? ''),
            'until'          => (string) ($payload['until'] ?? ''),
            'set'            => (string) ($payload['set'] ?? ''),
            'cursor'         => max(0, (int) ($payload['cursor'] ?? 0)),
            // A token minted before the composition marker existed carries no
            // guarantee at all, so it is deliberately mapped to a value that
            // can never match a freshly derived composition (which is a
            // '|'-joined list of arm names, never '?'). Such a token is
            // answered with badResumptionToken and the harvest restarts —
            // the safe outcome for the at most 24h of in-flight tokens that
            // straddle an upgrade.
            'composition'    => isset($payload['composition'])
                ? (string) $payload['composition']
                : '?legacy',
        ];
    }

    private function purgeExpiredTokens(): void
    {
        $this->db->query("DELETE FROM oai_resumption_tokens WHERE expires_at < NOW()");
    }

    // ── Related data fetchers ─────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuthorsForBook(int $bookId): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.nome, la.ruolo, la.ordine_credito
               FROM libri_autori la
               JOIN autori a ON a.id = la.autore_id
              WHERE la.libro_id = ?
              ORDER BY CASE la.ruolo WHEN \'principale\' THEN 0 WHEN \'co-autore\' THEN 1 ELSE 2 END,
                       la.ordine_credito IS NULL, la.ordine_credito, a.nome'
        );
        if ($stmt === false) { return []; }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        if ($res instanceof \mysqli_result) {
            while ($r = $res->fetch_assoc()) { $out[] = $r; }
            $res->free();
        }
        $stmt->close();
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPublisher(int $editorId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nome FROM editori WHERE id = ?');
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $editorId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    /**
     * All publishers for a book (issue #143), ordered. Falls back to the single
     * primary publisher (editore_id) when the libri_editori junction is empty or
     * absent (installs predating the multi-publisher migration).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPublishersForBook(int $bookId, ?int $primaryEditoreId): array
    {
        $out = [];
        $stmt = $this->db->prepare(
            'SELECT e.id, e.nome
               FROM libri_editori le
               JOIN editori e ON e.id = le.editore_id
              WHERE le.libro_id = ?
              ORDER BY le.ordine, e.nome'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_assoc()) { $out[] = $r; }
                $res->free();
            }
            $stmt->close();
        }
        if ($out === [] && $primaryEditoreId !== null && $primaryEditoreId > 0) {
            $single = $this->fetchPublisher($primaryEditoreId);
            if ($single !== null) { $out[] = $single; }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGenre(int $genreId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nome FROM generi WHERE id = ?');
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $genreId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMagProjectConfig(): array
    {
        $res = $this->db->query('SELECT * FROM mag_project_config ORDER BY id LIMIT 1');
        if ($res instanceof \mysqli_result) {
            $row = $res->fetch_assoc();
            $res->free();
            if ($row !== null) { return $row; }
        }
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchDigitalAsset(int $bookId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT url, md5_hash, filesize, image_width, image_height, ppi, filetype
               FROM digital_assets WHERE libro_id = ? ORDER BY id LIMIT 1'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $rec
     */
    private function buildOaiId(array $rec, string $host): string
    {
        $entity = match ($rec['_entity']) {
            'archival_unit' => 'archival_unit',
            'periodical'    => 'periodical',
            default         => 'book',
        };
        // For deleted records use entity_id (stored by trigger); for active records use id.
        // Both use the same host-based namespace for OAI identifier consistency.
        $recId = $rec['_status'] === 'deleted'
            ? (string) ($rec['entity_id'] ?? '')
            : (string) ($rec['id'] ?? '');
        return 'oai:' . $host . ':' . $entity . ':' . $recId;
    }

    /**
     * @param array<string, mixed> $rec
     */
    private function recordDatestamp(array $rec): string
    {
        $raw = (string) ($rec['_datestamp'] ?? $rec['updated_at'] ?? $rec['datestamp'] ?? '');
        if ($raw === '') { return gmdate('Y-m-d\TH:i:s\Z'); }
        $ts = strtotime($raw);
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Convert common language name/code to ISO 639-2/B three-letter MARC code
     * ('und' when unknown). A multi-language value uses its first language.
     */
    private function iso639_3ToMarc(string $lang): string
    {
        $first = $this->languageList($lang)[0] ?? '';
        return $this->languageCode($first) ?? 'und';
    }

    /**
     * ISO 639-2/B code for one stored language (Italian/English/native name,
     * ISO 639-1 or 639-2 code), or null when it is not recognised.
     */
    private function languageCode(string $lang): ?string
    {
        static $map = [
            'italiano' => 'ita', 'italian' => 'ita', 'it' => 'ita', 'ita' => 'ita',
            'inglese' => 'eng', 'english' => 'eng', 'en' => 'eng', 'eng' => 'eng',
            'francese' => 'fre', 'français' => 'fre', 'french' => 'fre', 'fr' => 'fre', 'fre' => 'fre', 'fra' => 'fre',
            'tedesco' => 'ger', 'deutsch' => 'ger', 'german' => 'ger', 'de' => 'ger', 'ger' => 'ger', 'deu' => 'ger',
            'spagnolo' => 'spa', 'español' => 'spa', 'spanish' => 'spa', 'es' => 'spa', 'spa' => 'spa',
            'portoghese' => 'por', 'português' => 'por', 'portuguese' => 'por', 'pt' => 'por', 'por' => 'por',
            'danese' => 'dan', 'dansk' => 'dan', 'danish' => 'dan', 'da' => 'dan', 'dan' => 'dan',
            'olandese' => 'dut', 'nederlands' => 'dut', 'dutch' => 'dut', 'nl' => 'dut', 'dut' => 'dut', 'nld' => 'dut',
            'svedese' => 'swe', 'svenska' => 'swe', 'swedish' => 'swe', 'sv' => 'swe', 'swe' => 'swe',
            'norvegese' => 'nor', 'norsk' => 'nor', 'norwegian' => 'nor', 'no' => 'nor', 'nor' => 'nor',
            'russo' => 'rus', 'russian' => 'rus', 'ru' => 'rus', 'rus' => 'rus',
            'polacco' => 'pol', 'polski' => 'pol', 'polish' => 'pol', 'pl' => 'pol', 'pol' => 'pol',
            'greco' => 'gre', 'greek' => 'gre', 'el' => 'gre', 'gre' => 'gre', 'ell' => 'gre',
            'greco antico' => 'grc', 'grc' => 'grc',
            'latino' => 'lat', 'latin' => 'lat', 'la' => 'lat', 'lat' => 'lat',
            'cinese' => 'chi', 'chinese' => 'chi', 'zh' => 'chi', 'chi' => 'chi', 'zho' => 'chi',
            'giapponese' => 'jpn', 'japanese' => 'jpn', 'ja' => 'jpn', 'jpn' => 'jpn',
            'arabo' => 'ara', 'arabic' => 'ara', 'ar' => 'ara', 'ara' => 'ara',
            'catalano' => 'cat', 'català' => 'cat', 'catalan' => 'cat', 'ca' => 'cat', 'cat' => 'cat',
        ];
        $key = mb_strtolower(trim($lang));
        return $map[$key] ?? null;
    }

    /**
     * The individual languages of a stored lingua value ("italiano, inglese").
     *
     * @return list<string>
     */
    private function languageList(string $lang): array
    {
        $parts = preg_split('/\s*[,;\/]\s*/', trim($lang)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /** DCMI Type Vocabulary term for a libri.tipo_media value. */
    private function dcmiType(string $tipoMedia): string
    {
        return match (strtolower(trim($tipoMedia))) {
            'audiolibro', 'disco', 'cd', 'audio', 'vinile' => 'Sound',
            'dvd', 'video', 'bluray', 'blu-ray'            => 'MovingImage',
            'immagine', 'image', 'foto', 'stampa'          => 'Image',
            'fondo', 'collection'                          => 'Collection',
            default                                        => 'Text',
        };
    }

    /** Whether a libri row describes a serial (MARC bibliographic level 's'). */
    private function isSerialRecord(array $row): bool
    {
        return in_array(strtolower((string) ($row['tipo_media'] ?? '')), ['periodical', 'serial', 'periodico', 'rivista'], true);
    }

    /**
     * MARC 21 008 for books/serials: exactly 40 positions (same layout as the
     * Z39.50 server's MARCXMLFormatter::generateField008()).
     *   00-05 date entered (YYMMDD), 06 date type, 07-10 date 1, 11-14 date 2,
     *   15-17 place of publication, 35-37 language, 39 cataloguing source.
     *
     * @param array<string, mixed> $row
     */
    private function marc21Field008(array $row): string
    {
        $field = str_repeat(' ', 40);
        $created = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $field = substr_replace($field, date('ymd', $created), 0, 6);

        $isSerial = $this->isSerialRecord($row);
        $field = substr_replace($field, $isSerial ? 'c' : 's', 6, 1);

        $year = trim((string) ($row['anno_pubblicazione'] ?? ''));
        if ($year !== '' && ctype_digit($year) && strlen($year) <= 4) {
            $field = substr_replace($field, str_pad($year, 4, '0', STR_PAD_LEFT), 7, 4);
        }
        if ($isSerial) {
            // Continuing resource still published: date 2 = 9999.
            $field = substr_replace($field, '9999', 11, 4);
        }

        // Place of publication unknown/unspecified.
        $field = substr_replace($field, 'xx ', 15, 3);

        if (trim((string) ($row['lingua'] ?? '')) !== '') {
            $field = substr_replace($field, $this->iso639_3ToMarc((string) $row['lingua']), 35, 3);
        }

        // 39 — cataloguing source: 'd' (other than a national agency).
        return substr_replace($field, 'd', 39, 1);
    }

    private function oaiError(\XMLWriter $xw, string $code, string $message): void
    {
        $xw->startElement('error');
        $xw->writeAttribute('code', $code);
        $xw->text($message);
        $xw->endElement();
    }

    // ── UNIMARC direct download endpoints ────────────────────────────────────

    /**
     * GET /admin/books/{id}/unimarc.xml
     *
     * Returns the UNIMARC/XML record as a standalone downloadable file.
     *
     * @param array<string, string> $args
     */
    public function downloadUnimarcXmlAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $out = null;
        if (!$this->requireAdminForDownload($response, $out, $request)) {
            return $out;
        }

        $id  = (int) ($args['id'] ?? 0);
        $row = $this->fetchBookById($id);
        if ($row === null) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Book not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $authors    = $this->fetchAuthorsForBook($id);
        $publishers = $this->fetchPublishersForBook($id, !empty($row['editore_id']) ? (int) $row['editore_id'] : null);
        $genre      = !empty($row['genere_id'])  ? $this->fetchGenre((int) $row['genere_id'])     : null;

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');
        $this->writeBookUnimarc($xw, $row, $authors, $publishers, $genre);
        $xw->endDocument();
        $xml = $xw->outputMemory();

        $filename = 'unimarc-' . $id . '.xml';
        $response->getBody()->write($xml);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * GET /admin/books/{id}/unimarc.mrc
     *
     * Returns the UNIMARC record in ISO 2709 binary format.
     *
     * @param array<string, string> $args
     */
    public function downloadUnimarcMrcAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $out = null;
        if (!$this->requireAdminForDownload($response, $out, $request)) {
            return $out;
        }

        $id  = (int) ($args['id'] ?? 0);
        $row = $this->fetchBookById($id);
        if ($row === null) {
            $response->getBody()->write(json_encode(['error' => true, 'message' => 'Book not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $authors    = $this->fetchAuthorsForBook($id);
        $publishers = $this->fetchPublishersForBook($id, !empty($row['editore_id']) ? (int) $row['editore_id'] : null);
        $genre      = !empty($row['genere_id'])  ? $this->fetchGenre((int) $row['genere_id'])     : null;

        $binary   = $this->bookToIso2709($row, $authors, $publishers, $genre);
        $filename = 'unimarc-' . $id . '.mrc';

        $response->getBody()->write($binary);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/marc')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Require admin or staff via session or HTTP Basic Auth.
     *
     * RFC 7235 compliant: missing or invalid credentials answer 401 with a
     * WWW-Authenticate challenge; more than 10 Basic attempts per IP in
     * 300 s answer 429 (Retry-After) before the password is even checked.
     *
     * @param ResponseInterface       $response template response
     * @param ResponseInterface|null  &$out     set to 401 or 429 on failure
     * @param ServerRequestInterface|null $request used for Basic Auth header
     */
    private function requireAdminForDownload(
        ResponseInterface $response,
        ?ResponseInterface &$out,
        ?ServerRequestInterface $request = null
    ): bool {
        if (
            isset($_SESSION['user']) &&
            in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true)
        ) {
            return true;
        }

        $auth = $request !== null ? $request->getHeaderLine('Authorization') : '';
        if ($request !== null && $auth !== '' && str_starts_with($auth, 'Basic ')) {
            // Throttle credential guessing per client IP, checked BEFORE the
            // password (same 10 attempts / 300 s as the ResourceSync gate and
            // the mobile login): every attempt counts toward the limit.
            $ip   = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $rlId = 'oai_unimarc_basic:' . $ip;
            if (\App\Support\RateLimiter::isLimited($rlId, 10, 300)) {
                $out = $response->withStatus(429)->withHeader('Retry-After', '300');
                return false;
            }
            $decoded = base64_decode(substr($auth, 6), true);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2 && $this->authenticateBasicOai($parts[0], $parts[1])) {
                    // Successful auth clears the throttle for this IP.
                    \App\Support\RateLimiter::reset($rlId);
                    return true;
                }
            }
        }

        // Missing or invalid credentials — challenge the client (RFC 7235 §3.1)
        $out = $response->withStatus(401)->withHeader('WWW-Authenticate', 'Basic realm="OAI-PMH"');
        return false;
    }

    private function authenticateBasicOai(string $email, string $pass): bool
    {
        $stmt = $this->db->prepare(
            "SELECT password FROM utenti WHERE email = ? AND stato = 'attivo'
             AND tipo_utente IN ('admin','staff') LIMIT 1"
        );
        if ($stmt === false) { return false; }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res  = $stmt->get_result();
        $row  = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) {
            // Constant-time dummy verify: an unknown admin/staff email costs
            // the same bcrypt work as a wrong password (no enumeration oracle).
            password_verify($pass, '$2y$12$FYWkjQ0krgMEuFnovQ3C6.vL6MZP/pdGGrLm.Q1PBhX29YNNu.Bfe');
            return false;
        }
        return password_verify($pass, (string) $row['password']);
    }

    /**
     * Fetch a single active book row from `libri`.
     *
     * @return array<string, mixed>|null
     */
    private function fetchBookById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    /**
     * Serialize a book record to an ISO 2709 binary UNIMARC record.
     *
     * Structure: Leader(24) + Directory(12 per field + FT) + Fields(each ends FT) + RT
     *
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param list<array<string, mixed>>        $publishers
     * @param array<string, mixed>|null         $genre
     */
    private function bookToIso2709(
        array $row,
        array $authors,
        array $publishers,
        ?array $genre
    ): string {
        $FT = "\x1E"; // Field terminator
        $RT = "\x1D"; // Record terminator
        $SF = "\x1F"; // Subfield delimiter

        // Each entry: [tag, ind1|null, ind2|null, data_string]
        // Control fields (001-009): ind1/ind2 = null
        /** @var list<array{0:string,1:string|null,2:string|null,3:string}> $fields */
        $fields = [];
        foreach ($this->unimarcFields($row, $authors, $publishers, $genre) as [$tag, $ind1, $ind2, $data]) {
            if (is_string($data)) {
                $fields[] = [$tag, null, null, $data];
                continue;
            }
            $encoded = '';
            foreach ($data as [$code, $value]) {
                $encoded .= $SF . $code . $value;
            }
            $fields[] = [$tag, $ind1, $ind2, $encoded];
        }

        // ── Build directory and field data section ─────────────────────────────
        $directory = '';
        $fieldData = '';
        $pos       = 0;

        foreach ($fields as [$tag, $ind1, $ind2, $data]) {
            $isControl   = ($ind1 === null);
            $fieldContent = $isControl ? ($data . $FT) : ($ind1 . $ind2 . $data . $FT);
            $len          = strlen($fieldContent);
            $directory   .= $tag . sprintf('%04d', $len) . sprintf('%05d', $pos);
            $fieldData   .= $fieldContent;
            $pos         += $len;
        }
        $directory .= $FT; // directory block ends with field terminator

        $baseAddr     = 24 + strlen($directory);
        $recordLength = $baseAddr + strlen($fieldData) + 1; // +1 for record terminator

        // Same leader as the MARCXchange record (UNIMARC_LEADER_TEMPLATE),
        // with the record length (00-04) and base address (12-16) filled in.
        $leader = sprintf('%05d', $recordLength)
            . substr(self::UNIMARC_LEADER_TEMPLATE, 5, 7)
            . sprintf('%05d', $baseAddr)
            . substr(self::UNIMARC_LEADER_TEMPLATE, 17);

        return $leader . $directory . $fieldData . $RT;
    }

    // ── Digital-assets admin UI ───────────────────────────────────────────────

    /**
     * Hook: book.form.fields — injects MAG digital-assets section into book edit form.
     *
     * @param array<string,mixed>|null $book
     * @param int|null                 $bookId
     */
    public function renderBookDigitalAssets(?array $book, ?int $bookId): void
    {
        if ($bookId === null) {
            return;
        }

        $assets = [];
        $stmt = $this->db->prepare(
            'SELECT id, url, filetype, md5_hash, filesize, image_width, image_height, ppi
               FROM digital_assets WHERE libro_id = ? ORDER BY id'
        );
        if ($stmt) {
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $assets[] = $row;
            }
            $stmt->close();
        }

        $csrfToken = \App\Support\Csrf::ensureToken();
        include __DIR__ . '/views/book-digital-assets.php';
    }

    /**
     * AJAX: POST /admin/api/books/{id}/digital-assets — add a digital asset.
     *
     * @param array<string,string> $args
     */
    public function digitalAssetAddAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            return $this->jsonError($response, 'Unauthorized', 403);
        }

        $body  = (string) $request->getBody();
        $data  = (array) (json_decode($body, true) ?? []);
        $token = (string) ($data['csrf_token'] ?? '');
        if (!\App\Support\Csrf::validate($token)) {
            return $this->jsonError($response, 'Token CSRF non valido.');
        }

        $bookId = (int) ($args['id'] ?? 0);
        if ($bookId <= 0) {
            return $this->jsonError($response, 'ID libro non valido.');
        }

        $url      = trim((string) ($data['url'] ?? ''));
        $filetype = trim((string) ($data['filetype'] ?? 'PDF'));
        $md5      = trim((string) ($data['md5_hash'] ?? ''));
        $filesize = max(0, (int) ($data['filesize'] ?? 0));
        $width    = max(0, (int) ($data['image_width'] ?? 0));
        $height   = max(0, (int) ($data['image_height'] ?? 0));
        $ppi      = max(0, (int) ($data['ppi'] ?? 0));

        if ($url === '') {
            return $this->jsonError($response, 'URL obbligatorio.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->jsonError($response, 'URL non valido.');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->jsonError($response, 'Solo URL http/https consentiti.');
        }
        $allowedTypes = ['PDF', 'TIFF', 'JPEG', 'PNG', 'EPUB'];
        if (!in_array(strtoupper($filetype), $allowedTypes, true)) {
            $filetype = 'PDF';
        } else {
            $filetype = strtoupper($filetype);
        }
        if ($md5 !== '' && !preg_match('/^[0-9a-f]{32}$/i', $md5)) {
            return $this->jsonError($response, 'MD5 hash non valido (32 caratteri esadecimali).');
        }

        // Verify the book exists and is not soft-deleted.
        $chk = $this->db->prepare('SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if (!$chk) {
            return $this->jsonError($response, 'Errore database.');
        }
        $chk->bind_param('i', $bookId);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows === 0) {
            $chk->close();
            return $this->jsonError($response, 'Libro non trovato.', 404);
        }
        $chk->close();

        $stmt = $this->db->prepare(
            'INSERT INTO digital_assets
             (libro_id, url, filetype, md5_hash, filesize, image_width, image_height, ppi, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        if ($stmt === false) {
            return $this->jsonError($response, 'Errore database.');
        }
        // FIX F067: bind_param type string corrected from 'issssiii' to 'isssiiii' to match arg order (i,s,s,s,i,i,i,i)
        $stmt->bind_param('isssiiii', $bookId, $url, $filetype, $md5, $filesize, $width, $height, $ppi);
        if (!$stmt->execute()) {
            $stmt->close();
            return $this->jsonError($response, 'Errore nel salvataggio.');
        }
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        return $this->jsonSuccess($response, [
            'asset' => [
                'id'           => $newId,
                'url'          => $url,
                'filetype'     => $filetype,
                'md5_hash'     => $md5,
                'filesize'     => $filesize,
                'image_width'  => $width,
                'image_height' => $height,
                'ppi'          => $ppi,
            ],
        ]);
    }

    /**
     * AJAX: POST /admin/api/books/{id}/digital-assets/{aid}/delete — delete a digital asset.
     *
     * @param array<string,string> $args
     */
    public function digitalAssetDeleteAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            return $this->jsonError($response, 'Unauthorized', 403);
        }

        $body  = (string) $request->getBody();
        $data  = (array) (json_decode($body, true) ?? []);
        $token = (string) ($data['csrf_token'] ?? '');
        if (!\App\Support\Csrf::validate($token)) {
            return $this->jsonError($response, 'Token CSRF non valido.');
        }

        $bookId  = (int) ($args['id']  ?? 0);
        $assetId = (int) ($args['aid'] ?? 0);
        if ($bookId <= 0 || $assetId <= 0) {
            return $this->jsonError($response, 'Parametri non validi.');
        }

        $stmt = $this->db->prepare(
            'DELETE FROM digital_assets WHERE id = ? AND libro_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            return $this->jsonError($response, 'Errore database.');
        }
        $stmt->bind_param('ii', $assetId, $bookId);
        if (!$stmt->execute()) {
            $stmt->close();
            return $this->jsonError($response, 'Errore nell\'eliminazione.');
        }
        $stmt->close();

        return $this->jsonSuccess($response, []);
    }

    private function requireAdminOrStaff(): bool
    {
        return isset($_SESSION['user']) &&
            in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function jsonSuccess(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(array_merge(['success' => true], $data)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(ResponseInterface $response, string $error, int $status = 400): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(['success' => false, 'error' => $error]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * SQL guard for oai_deleted_records: drop a BOOK tombstone whose row is a
     * library request that never reached the catalogue.
     *
     * The soft-delete trigger fires for every book, and it cannot tell a
     * withdrawn holding from a wish list entry — the desiderata columns may not
     * even exist when the trigger is created. Filtering at read time also
     * covers the tombstones already recorded, which changing the trigger would
     * not. A tombstone for such a row announces the deletion of a record no
     * harvester was given, and publishes the request's id and timestamps.
     *
     * Inert without the plugin: delisted() is then 0=1 and nothing is dropped.
     * Archival units and any other entity type are never affected.
     */
    private function neverPublishedGuard(string $alias): string
    {
        if (!preg_match('/^[a-z_]+$/', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }
        $delisted = \App\Support\BookVisibility::delisted($this->db, 'nl');
        $everCatalogued = \App\Support\BookVisibility::everCatalogued($this->db, 'nl');

        // CI-SOFT-DELETE-EXEMPT: the guard must see the soft-deleted row the tombstone is about.
        return "NOT ({$alias}.entity_type = 'book' AND EXISTS (SELECT 1 FROM libri nl WHERE nl.id = {$alias}.entity_id"
            . " AND {$delisted} AND NOT ({$everCatalogued})))";
    }
}
