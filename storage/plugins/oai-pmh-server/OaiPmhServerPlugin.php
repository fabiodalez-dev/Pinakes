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
 * Sets: books, archives (archives set only when archives plugin is active)
 * deletedRecord: persistent (tracked via oai_deleted_records + MySQL triggers)
 * Resumption tokens: DB-backed with 24h TTL
 *
 * OAI identifier scheme:
 *   books          → oai:{host}:book:{id}
 *   archival units → oai:{host}:archival_unit:{id}
 */
class OaiPmhServerPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

    /** Cached result of the archival_units table existence check. */
    private ?bool $archivalUnitsTableExists = null;

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
    private const NS_MARCXCHANGE = 'info:lc/xmlns/marcxchange-v2';
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
        foreach (['trg_libri_soft_delete', 'trg_archival_soft_delete'] as $trg) {
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
    public function ensureSchema(): array
    {
        $created = [];
        $failed  = [];

        $tables = [
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
                'body' => "IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    INSERT INTO oai_deleted_records (entity_type, entity_id, oai_id, datestamp)
                    VALUES ('book', OLD.id, CONCAT('oai:pinakes:book:', OLD.id), NOW())
                    ON DUPLICATE KEY UPDATE datestamp = NOW();
                END IF",
            ],
            'trg_archival_soft_delete' => [
                'table' => 'archival_units',
                'body' => "IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
                    INSERT INTO oai_deleted_records (entity_type, entity_id, oai_id, datestamp)
                    VALUES ('archival_unit', OLD.id, CONCAT('oai:pinakes:archival_unit:', OLD.id), NOW())
                    ON DUPLICATE KEY UPDATE datestamp = NOW();
                END IF",
            ],
        ];

        foreach ($triggers as $name => $def) {
            $table = $this->db->real_escape_string($def['table']);
            $tableExists = $this->db->query(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
            );
            if (!($tableExists instanceof \mysqli_result)) {
                continue;
            }
            $row = $tableExists->fetch_assoc();
            $tableExists->free();
            if (($row['c'] ?? 0) == 0) {
                continue; // table doesn't exist yet (e.g. archives not installed)
            }

            if ($this->db->query("DROP TRIGGER IF EXISTS `{$name}`") === false) {
                SecureLogger::warning('[OaiPmhServer] DROP TRIGGER failed for ' . $name . ': ' . $this->db->error);
            }
            $created = $this->db->query(
                "CREATE TRIGGER `{$name}` BEFORE UPDATE ON `{$table}`
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
                AND TRIGGER_NAME IN ('trg_libri_soft_delete', 'trg_archival_soft_delete')"
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
        $r = $this->db->query(
            "SELECT MIN(created_at) AS e FROM libri WHERE deleted_at IS NULL"
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
        // Also check archival_units if the table exists.
        $r2 = $this->db->query(
            "SELECT MIN(created_at) AS e FROM archival_units WHERE deleted_at IS NULL"
        );
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
            $resolved = $this->resolveIdentifier($identifier, $host);
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
                'schema'    => 'http://www.iccu.sbn.it/schede/mag/mag_V2.0.1.xsd',
                'namespace' => 'http://www.iccu.sbn.it/mag/',
            ],
            [
                'prefix'    => 'unimarc',
                'schema'    => self::SCHEMA_MARCXCHANGE,
                'namespace' => self::NS_MARCXCHANGE,
            ],
            // Phase 6 (v0.8.0): RiC-O is exposed as a metadataPrefix for
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

        $xw->endElement(); // ListSets
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
        if ($tokenStr !== '') {
            $payload = $this->loadResumptionToken($tokenStr);
            if ($payload === null) {
                $this->oaiError($xw, 'badResumptionToken',
                    'The value of the resumptionToken argument is invalid or expired.');
                return;
            }
            $metadataPrefix = $payload['metadataPrefix'];
            $from           = $payload['from'];
            $until          = $payload['until'];
            $set            = $payload['set'];
            $cursor         = $payload['cursor'];
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

        $validSets = ['', 'books', 'archives'];
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

        // Normalise date strings to MySQL DATETIME format.
        // For date-only values (YYYY-MM-DD) expand to inclusive day boundaries.
        $fromMysql = $from !== ''
            ? (strlen($from) === 10 ? $from . ' 00:00:00' : str_replace(['T', 'Z'], [' ', ''], $from))
            : null;
        $untilMysql = $until !== ''
            ? (strlen($until) === 10 ? $until . ' 23:59:59' : str_replace(['T', 'Z'], [' ', ''], $until))
            : null;

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
        $records = $this->fetchRecordsPage($fetchSet, $fromMysql, $untilMysql, $cursor, self::PAGE_SIZE + 1);

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

        $verbElement = $identifiersOnly ? 'ListIdentifiers' : 'ListRecords';
        $xw->startElement($verbElement);

        foreach ($records as $rec) {
            $oaiId    = $this->buildOaiId($rec, $host);
            $datestamp = $this->recordDatestamp($rec);
            $isDeleted = ($rec['_status'] === 'deleted');

            if (!$identifiersOnly) {
                $xw->startElement('record');
            }

            $xw->startElement('header');
            if ($isDeleted) {
                $xw->writeAttribute('status', 'deleted');
            }
            $xw->writeElement('identifier', $oaiId);
            $xw->writeElement('datestamp', $datestamp);
            if (!$isDeleted) {
                // Emit setSpec only for active records.
                $setSpec = ($rec['_entity'] === 'archival_unit') ? 'archives' : 'books';
                $xw->writeElement('setSpec', $setSpec);
            }
            $xw->endElement(); // header

            if (!$identifiersOnly && !$isDeleted) {
                try {
                    $xw->startElement('metadata');
                    $this->writeMetadata($xw, $rec, $metadataPrefix, $host);
                    $xw->endElement(); // metadata
                } catch (CannotDisseminateFormatException $e) {
                    // This record type doesn't support the requested format — skip it.
                    // The XMLWriter may have open 'metadata' and 'record' elements; close them safely.
                    try { $xw->endElement(); } catch (\Throwable $ignored) {} // close <metadata>
                    try { $xw->endElement(); } catch (\Throwable $ignored) {} // close <record>
                    continue;
                } catch (\Throwable $e) {
                    \App\Support\SecureLogger::warning('OAI-PMH skipped malformed record metadata', [
                        'metadataPrefix' => $metadataPrefix,
                        'entity' => $rec['_entity'] ?? null,
                        'id' => $rec['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    try { $xw->endElement(); } catch (\Throwable $ignored) {} // close <metadata>
                    try { $xw->endElement(); } catch (\Throwable $ignored) {} // close <record>
                    continue;
                }
            }

            if (!$identifiersOnly) {
                $xw->endElement(); // record
            }
        }

        // Resumption token — always emit the element with cursor + expirationDate,
        // even on the last page (empty text content when no more pages).
        $nextCursor = $cursor + self::PAGE_SIZE;
        $xw->startElement('resumptionToken');
        $xw->writeAttribute('expirationDate', gmdate('Y-m-d\TH:i:s\Z', time() + self::TOKEN_TTL));
        $xw->writeAttribute('cursor', (string) $cursor);
        if ($hasMore) {
            $newToken = $this->saveResumptionToken($metadataPrefix, $from, $until, $set, $nextCursor);
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
            if ($rec !== null) {
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

        $datestamp = $this->recordDatestamp($rec);

        $xw->startElement('GetRecord');
        $xw->startElement('record');
        $xw->startElement('header');
        $xw->writeElement('identifier', $identifier);
        $xw->writeElement('datestamp', $datestamp);
        $setSpec = ($rec['_entity'] === 'archival_unit') ? 'archives' : 'books';
        $xw->writeElement('setSpec', $setSpec);
        $xw->endElement(); // header

        try {
            $xw->startElement('metadata');
            $this->writeMetadata($xw, $rec, $metadataPrefix, $host);
            $xw->endElement(); // metadata
        } catch (CannotDisseminateFormatException $e) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The requested metadataPrefix is not supported for this record type.');
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

        // Books — use pre-fetched related data when available (batch path from fetchRecordsPage),
        // otherwise fall back to individual queries (GetRecord / direct download paths).
        $bookId    = (int) $rec['id'];
        $authors   = array_key_exists('_authors', $rec)
            ? (array) $rec['_authors']
            : $this->fetchAuthorsForBook($bookId);
        $publisher = array_key_exists('_publisher', $rec)
            ? (is_array($rec['_publisher']) ? $rec['_publisher'] : null)
            : (!empty($rec['editore_id']) ? $this->fetchPublisher((int) $rec['editore_id']) : null);
        $genre     = array_key_exists('_genre', $rec)
            ? (is_array($rec['_genre']) ? $rec['_genre'] : null)
            : (!empty($rec['genere_id']) ? $this->fetchGenre((int) $rec['genere_id']) : null);

        match ($metadataPrefix) {
            'oai_dc'  => $this->writeBookOaiDc($xw, $rec, $authors, $publisher, $genre, $host),
            'marcxml' => $this->writeBookMarcXml($xw, $rec, $authors, $publisher, $genre),
            'mods'    => $this->writeBookMods($xw, $rec, $authors, $publisher, $genre),
            'mag'     => $this->writeBookMag($xw, $rec, $authors, $publisher, $genre),
            'unimarc' => $this->writeBookUnimarc($xw, $rec, $authors, $publisher, $genre),
            default   => null,
        };
    }

    // ── oai_dc for books ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookOaiDc(
        \XMLWriter $xw,
        array $row,
        array $authors,
        ?array $publisher,
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

        // dc:creator
        foreach ($authors as $a) {
            $xw->writeElementNs('dc', 'creator', null, (string) $a['nome']);
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

        // dc:publisher
        if ($publisher !== null && !empty($publisher['nome'])) {
            $xw->writeElementNs('dc', 'publisher', null, (string) $publisher['nome']);
        }

        // dc:contributor (translators, illustrators, curators)
        foreach (['traduttore' => 'translator', 'illustratore' => 'illustrator', 'curatore' => 'editor'] as $col => $role) {
            if (!empty($row[$col])) {
                $xw->writeElementNs('dc', 'contributor', null, (string) $row[$col]);
            }
        }

        // dc:date
        if (!empty($row['anno_pubblicazione'])) {
            $xw->writeElementNs('dc', 'date', null, (string) $row['anno_pubblicazione']);
        }

        // dc:type
        $tipo = (string) ($row['tipo_media'] ?? 'libro');
        $xw->writeElementNs('dc', 'type', null, ucfirst($tipo));

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

        // dc:language
        if (!empty($row['lingua'])) {
            $xw->writeElementNs('dc', 'language', null, (string) $row['lingua']);
        }

        $xw->endElement(); // oai_dc:dc
    }

    // ── MARCXML for books ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMarcXml(
        \XMLWriter $xw,
        array $row,
        array $authors,
        ?array $publisher,
        ?array $genre
    ): void {
        $xw->startElementNs(null, 'record', 'http://www.loc.gov/MARC21/slim');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd');

        // Leader: type 'a' (language material), bibliographic level 'm' (monograph)
        $xw->writeElement('leader', '00000nam a2200000 i 4500');

        // 001 — Control number (book id)
        $this->marcControlField($xw, '001', (string) $row['id']);

        // 003 — MARC organization code
        $this->marcControlField($xw, '003', 'IT-Pinakes');

        // 005 — Date/time of latest transaction
        $ts = strtotime((string) ($row['updated_at'] ?? 'now')) ?: time();
        $this->marcControlField($xw, '005', gmdate('YmdHis', $ts) . '.0');

        // 008 — Fixed-length data (minimal: year + language)
        $year = str_pad((string) ($row['anno_pubblicazione'] ?? ''), 4, ' ');
        $lang = str_pad($this->iso639_3ToMarc((string) ($row['lingua'] ?? '')), 3, ' ');
        $this->marcControlField($xw, '008', gmdate('ymd') . 's' . $year . '    it |||||||||' . $lang . '  d');

        // 020 — ISBN
        if (!empty($row['isbn13'])) {
            $this->marcDataField($xw, '020', ' ', ' ', [['a', (string) $row['isbn13']]]);
        }
        if (!empty($row['isbn10'])) {
            $this->marcDataField($xw, '020', ' ', ' ', [['a', (string) $row['isbn10']]]);
        }

        // 022 — ISSN
        if (!empty($row['issn'])) {
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

        // 100/700 — Authors
        $mainAuthIdx = 0;
        foreach ($authors as $i => $a) {
            $name = (string) $a['nome'];
            if ($i === $mainAuthIdx) {
                $this->marcDataField($xw, '100', '1', ' ', [
                    ['a', $name],
                    ['e', 'author'],
                ]);
            } else {
                $this->marcDataField($xw, '700', '1', ' ', [
                    ['a', $name],
                    ['e', 'author'],
                ]);
            }
        }

        // 245 — Title statement
        $titleSubs = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $titleSubs[] = ['b', (string) $row['sottotitolo']];
        }
        if (!empty($row['traduttore'])) {
            $titleSubs[] = ['c', 'traduzione di ' . (string) $row['traduttore']];
        }
        $ind1 = empty($authors) ? '0' : '1';
        $this->marcDataField($xw, '245', $ind1, '0', $titleSubs);

        // 250 — Edition
        if (!empty($row['edizione'])) {
            $this->marcDataField($xw, '250', ' ', ' ', [['a', (string) $row['edizione']]]);
        }

        // 264 — Production/Publication (RDA)
        $pubSubs = [];
        if ($publisher !== null && !empty($publisher['nome'])) {
            $pubSubs[] = ['b', (string) $publisher['nome']];
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

        // 700 — Additional authors (translators etc. as added entries)
        foreach (['traduttore' => 'translator', 'illustratore' => 'illustrator', 'curatore' => 'editor'] as $col => $role) {
            if (!empty($row[$col])) {
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
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookUnimarc(
        \XMLWriter $xw,
        array $row,
        array $authors,
        ?array $publisher,
        ?array $genre
    ): void {
        $xw->startElementNs(null, 'record', self::NS_MARCXCHANGE);
        $xw->writeAttribute('type', 'Bibliographic');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            self::NS_MARCXCHANGE . ' ' . self::SCHEMA_MARCXCHANGE);

        // Leader — 'nam': text language material, monograph
        $xw->writeElement('leader', '00000nam a2200000 u 4500');

        // 001 — Record identifier
        $this->marcControlField($xw, '001', (string) $row['id']);

        // 003 — Identifier source (repository base URL)
        $this->marcControlField($xw, '003', absoluteUrl('/'));

        // 005 — Date/time of last modification
        $this->marcControlField($xw, '005', gmdate('YmdHis') . '.0');

        // 100 — General processing data (UNIMARC fixed-length 36 chars)
        // Pos 0-7: date entered (YYYYMMDD)
        // Pos 8:   type of date ('1' = single known date)
        // Pos 9-12: Date 1 (publication year, padded)
        // Pos 13-16: Date 2 (blank for single date)
        // Pos 17-23: various flags (audience, gov-pub, modified, alphabet)
        // Pos 24-26: language of cataloguing (ISO 639-2/B)
        // Pos 27-35: transliteration + character sets (spaces)
        $langCode  = $this->iso639_3ToMarc((string) ($row['lingua'] ?? 'italiano'));
        $year      = (string) ($row['anno_pubblicazione'] ?? '');
        $date1     = (strlen($year) === 4 && ctype_digit($year)) ? $year : '    ';
        $f100core  = gmdate('Ymd') . '1' . $date1 . '    ' . '0000ba' . $langCode;
        $this->marcControlField($xw, '100', str_pad($f100core, 36));

        // 010 — ISBN
        foreach (['isbn13', 'isbn10', 'ean'] as $col) {
            if (!empty($row[$col])) {
                $this->marcDataField($xw, '010', ' ', ' ', [['a', (string) $row[$col]]]);
                break;
            }
        }

        // 101 — Language of document
        $this->marcDataField($xw, '101', '0', ' ', [['a', $langCode]]);

        // 102 — Country of publication
        $this->marcDataField($xw, '102', ' ', ' ', [['a', 'IT']]);

        // 200 — Title proper
        $subs200 = [['a', (string) ($row['titolo'] ?? '')]];
        if (!empty($row['sottotitolo'])) {
            $subs200[] = ['e', (string) $row['sottotitolo']];
        }
        if (!empty($authors)) {
            $subs200[] = ['f', (string) $authors[0]['nome']];
        }
        $this->marcDataField($xw, '200', '1', ' ', $subs200);

        // 205 — Edition statement
        if (!empty($row['edizione'])) {
            $this->marcDataField($xw, '205', ' ', ' ', [['a', (string) $row['edizione']]]);
        }

        // 210 — Publication, distribution
        $subs210 = [];
        if ($publisher !== null && !empty($publisher['nome'])) {
            $subs210[] = ['c', (string) $publisher['nome']];
        }
        if (!empty($year)) {
            $subs210[] = ['d', $year];
        }
        if (!empty($subs210)) {
            $this->marcDataField($xw, '210', ' ', ' ', $subs210);
        }

        // 215 — Physical description
        if (!empty($row['numero_pagine'])) {
            $this->marcDataField($xw, '215', ' ', ' ', [['a', $row['numero_pagine'] . ' p.']]);
        }

        // 330 — Summary / abstract
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $this->marcDataField($xw, '330', ' ', ' ', [['a', strip_tags((string) $desc)]]);
        }

        // 606 — Subject — genre
        if ($genre !== null && !empty($genre['nome'])) {
            $this->marcDataField($xw, '606', ' ', ' ', [['a', (string) $genre['nome']]]);
        }

        // 606 — Subject — keywords
        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') {
                    $this->marcDataField($xw, '606', ' ', ' ', [['a', $kw]]);
                }
            }
        }

        // 700 — Personal name (primary intellectual responsibility)
        // 701 — Personal name (alternative intellectual responsibility)
        foreach ($authors as $i => $a) {
            $tag  = ($i === 0) ? '700' : '701';
            $role = $a['ruolo'] ?? 'autore';
            // UNIMARC relation codes: 070=author, 060=translator, 340=editor
            $relCode = match ($role) {
                'traduttore'   => '060',
                'curatore'     => '340',
                'illustratore' => '110',
                default        => '070',
            };
            $this->marcDataField($xw, $tag, '0', ' ', [
                ['a', (string) $a['nome']],
                ['4', $relCode],
            ]);
        }

        // 225 — Series (UNIMARC equivalent of MARC21 490)
        if (!empty($row['collana'])) {
            $subs225 = [['a', (string) $row['collana']]];
            if (!empty($row['numero_serie'])) {
                $subs225[] = ['v', (string) $row['numero_serie']];
            }
            $this->marcDataField($xw, '225', '0', ' ', $subs225);
        }

        // 801 — Originating source
        $this->marcDataField($xw, '801', ' ', '0', [
            ['a', 'IT'],
            ['b', 'Pinakes'],
            ['c', gmdate('Ymd')],
        ]);

        $xw->endElement(); // record
    }

    // ── MODS 3.7 for books ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>             $row
     * @param list<array<string, mixed>>        $authors
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMods(
        \XMLWriter $xw,
        array $row,
        array $authors,
        ?array $publisher,
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

        // name (authors)
        foreach ($authors as $a) {
            $xw->startElement('name');
            $xw->writeAttribute('type', 'personal');
            $xw->startElement('namePart');
            $xw->writeAttribute('type', 'text');
            $xw->text((string) $a['nome']);
            $xw->endElement();
            $xw->startElement('role');
            $xw->startElement('roleTerm');
            $xw->writeAttribute('type', 'text');
            $xw->writeAttribute('authority', 'marcrelator');
            $xw->text('author');
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
        foreach ($contribs as [$col, $role]) {
            if (!empty($row[$col])) {
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

        // originInfo
        $xw->startElement('originInfo');
        if ($publisher !== null && !empty($publisher['nome'])) {
            $xw->writeElement('publisher', (string) $publisher['nome']);
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
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function writeBookMag(
        \XMLWriter $xw,
        array $row,
        array $authors,
        ?array $publisher,
        ?array $genre
    ): void {
        $magNs     = 'http://www.iccu.sbn.it/mag/';
        $dcNs      = 'http://purl.org/dc/elements/1.1/';
        $magSchema = 'http://www.iccu.sbn.it/mag/mag_V2.0.1.xsd';

        // Use pre-fetched MAG project config when available (batch path), else fetch once.
        $magCfg = (array_key_exists('_mag_config', $row) && is_array($row['_mag_config']) && !empty($row['_mag_config']))
            ? $row['_mag_config']
            : $this->fetchMagProjectConfig();

        $xw->startElementNs(null, 'metadigit', $magNs);
        $xw->writeAttributeNs('xmlns', 'dc', null, $dcNs);
        $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null, $magNs . ' ' . $magSchema);
        $xw->writeAttribute('version', '2.0.1');

        // ── <gen> — General metadata ──────────────────────────────────────────
        $xw->startElement('gen');
        $xw->startElement('stprog');
        $xw->writeElement('progetto', (string) ($magCfg['project_code'] ?? 'PINAKES'));
        $xw->writeElement('codice_progetto', (string) ($magCfg['institution_code'] ?? 'IT'));
        $xw->endElement(); // stprog
        $xw->writeElement('agency', (string) ($magCfg['institution_code'] ?? 'IT-UNKNOWN'));
        $xw->writeElement('collection', (string) ($magCfg['collection_name'] ?? 'Biblioteca'));
        $xw->writeElement('item', (string) ($row['id'] ?? ''));
        $xw->writeElement('rights', (string) ($magCfg['rights_statement'] ?? 'In Copyright'));
        $xw->endElement(); // gen

        // ── <bib> — Bibliographic metadata ───────────────────────────────────
        $xw->startElement('bib');

        // <identifier type="ISBN">
        foreach (['isbn13', 'isbn10'] as $col) {
            if (!empty($row[$col])) {
                $xw->startElementNs('dc', 'identifier', null);
                $xw->writeAttribute('type', 'ISBN');
                $xw->text((string) $row[$col]);
                $xw->endElement();
                break;
            }
        }

        // <paese> — publication country (default IT)
        $xw->writeElement('paese', 'IT');

        // <data_pub>
        if (!empty($row['anno_pubblicazione'])) {
            $xw->writeElement('data_pub', (string) $row['anno_pubblicazione']);
        }

        // Dublin Core title.
        $title = (string) ($row['titolo'] ?? '');
        if (!empty($row['sottotitolo'])) {
            $title .= ': ' . (string) $row['sottotitolo'];
        }
        $xw->writeElementNs('dc', 'title', null, $title);

        // dc:creator — one entry per author
        foreach ($authors as $a) {
            $xw->writeElementNs('dc', 'creator', null, (string) $a['nome']);
        }

        if ($publisher !== null && !empty($publisher['nome'])) {
            $xw->writeElementNs('dc', 'publisher', null, (string) $publisher['nome']);
        }

        $xw->writeElementNs('dc', 'format', null, (string) ($row['formato'] ?? 'text'));

        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $xw->writeElementNs('dc', 'description', null, strip_tags((string) $desc));
        }

        if ($genre !== null && !empty($genre['nome'])) {
            $xw->writeElementNs('dc', 'subject', null, (string) $genre['nome']);
        }

        $xw->endElement(); // bib

        // ── <doc> — Digital file (from digital_assets table, if present) ───────
        // Prefer pre-fetched asset from fetchRecordsPage (avoids N+1 on list verbs).
        // Fall back to per-record query for GetRecord / direct MAG download endpoint.
        if (array_key_exists('_digital_asset', $row)) {
            $preAsset = $row['_digital_asset'];
            $asset = is_array($preAsset) ? $preAsset : null;
        } else {
            $asset = $this->fetchDigitalAsset((int) $row['id']);
        }
        if ($asset !== null) {
            $baseUrl = !empty($magCfg['base_url']) ? rtrim((string) $magCfg['base_url'], '/') : '';
            $fileUrl = (string) $asset['url'];
            if (!preg_match('/^https?:\/\//', $fileUrl) && $baseUrl !== '') {
                $fileUrl = $baseUrl . '/' . ltrim($fileUrl, '/');
            }
            $xw->startElement('doc');
            $xw->startElement('defile');
            $xw->writeElement('sequence_number', '1');
            $xw->writeElement('file', $fileUrl);
            $xw->writeElement('filesize', (string) ((int) ($asset['filesize'] ?? 0)));
            $xw->writeElement('md5', (string) ($asset['md5_hash'] ?? ''));
            $xw->writeElement('filetype', (string) ($asset['filetype'] ?? 'PDF'));
            if ((int) ($asset['image_width'] ?? 0) > 0) {
                $xw->writeElement('image_width', (string) (int) $asset['image_width']);
            }
            if ((int) ($asset['image_height'] ?? 0) > 0) {
                $xw->writeElement('image_height', (string) (int) $asset['image_height']);
            }
            if ((int) ($asset['ppi'] ?? 0) > 0) {
                $xw->writeElement('ppi', (string) (int) $asset['ppi']);
            }
            $xw->endElement(); // defile
            $xw->endElement(); // doc
        } elseif (!empty($row['file_url'])) {
            // Legacy fallback: file_url column (no digital_assets row)
            $baseUrl = !empty($magCfg['base_url']) ? rtrim((string) $magCfg['base_url'], '/') : '';
            $fileUrl = (string) $row['file_url'];
            if (!preg_match('/^https?:\/\//', $fileUrl) && $baseUrl !== '') {
                $fileUrl = $baseUrl . '/' . ltrim($fileUrl, '/');
            }
            $xw->startElement('doc');
            $xw->startElement('defile');
            $xw->writeElement('sequence_number', '1');
            $xw->writeElement('file', $fileUrl);
            $xw->writeElement('filesize', '0');
            $xw->writeElement('filetype', 'PDF');
            $xw->endElement(); // defile
            $xw->endElement(); // doc
        }

        $xw->endElement(); // metadigit
    }

    // ── Archival unit metadata (delegates to archives plugin formats) ─────────

    /**
     * @param array<string, mixed> $rec
     */
    private function writeArchivalUnitMetadata(\XMLWriter $xw, array $rec, string $metadataPrefix, string $host = 'localhost'): void
    {
        // For archival units, we produce minimal DC output.
        // The archives plugin's own /archives/oai endpoint handles richer formats.
        if ($metadataPrefix === 'oai_dc') {
            $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
            $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
            $xw->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
            $xw->writeAttributeNs('xsi', 'schemaLocation', null,
                'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

            $xw->writeElementNs('dc', 'title', null, (string) ($rec['constructed_title'] ?? $rec['formal_title'] ?? ''));
            $xw->writeElementNs('dc', 'type', null, 'Archival Unit');
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

        // Phase 6 (v0.8.0): RiC-O as RDF/XML for archival units.
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
              WHERE parent_id = ? AND deleted_at IS NULL
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

    // ── Identifier resolution ─────────────────────────────────────────────────

    /**
     * Resolve OAI identifier to a DB row. Returns null if not found.
     * Accepts:
     *   oai:{host}:book:{id}
     *   oai:{host}:archival_unit:{id}
     *   oai:pinakes:book:{id}         (canonical fallback)
     *   oai:pinakes:archival_unit:{id}
     *
     * @return array<string, mixed>|null
     */
    private function resolveIdentifier(string $identifier, string $host): ?array
    {
        // Try book pattern.
        if (preg_match('/^oai:(?:pinakes|' . preg_quote($host, '/') . '):book:(\d+)$/i', $identifier, $m)) {
            $id   = (int) $m[1];
            $stmt = $this->db->prepare(
                'SELECT l.*
                   FROM libri l
                  WHERE l.id = ? AND l.deleted_at IS NULL'
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
                $row['_entity'] = 'archival_unit';
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
        } else {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM oai_deleted_records WHERE entity_type = ? AND entity_id = ?'
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
        int $limit
    ): array {
        $doBooks    = ($set === '' || $set === 'books');
        $doArchives = ($set === '' || $set === 'archives');

        // Check archival_units existence (cached per request to avoid repeated I_S queries).
        $auExists = false;
        if ($doArchives) {
            if ($this->archivalUnitsTableExists === null) {
                $r = $this->db->query(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
                );
                $this->archivalUnitsTableExists = $r !== false
                    && $r instanceof \mysqli_result
                    && ((int) ($r->fetch_assoc()['c'] ?? 0)) > 0;
                if ($r instanceof \mysqli_result) { $r->free(); }
            }
            $auExists = $this->archivalUnitsTableExists;
        }

        // Build UNION ALL parts for page identifiers only.
        // Each part returns: _id INT, _entity VARCHAR, _status VARCHAR, _datestamp DATETIME.
        $parts = [];
        $types = '';
        $vals  = [];

        if ($doBooks) {
            $w = ['l.deleted_at IS NULL'];
            if ($fromMysql !== null)  { $w[] = 'l.updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'l.updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            $parts[] = 'SELECT l.id AS _id, \'book\' AS _entity, \'active\' AS _status, l.updated_at AS _datestamp'
                . ' FROM libri l WHERE ' . implode(' AND ', $w);
        }

        if ($doArchives && $auExists) {
            $w = ['deleted_at IS NULL'];
            if ($fromMysql !== null)  { $w[] = 'updated_at >= ?'; $types .= 's'; $vals[] = $fromMysql; }
            if ($untilMysql !== null) { $w[] = 'updated_at <= ?'; $types .= 's'; $vals[] = $untilMysql; }
            $parts[] = 'SELECT id AS _id, \'archival_unit\' AS _entity, \'active\' AS _status, updated_at AS _datestamp'
                . ' FROM archival_units WHERE ' . implode(' AND ', $w);
        }

        $delW = [];
        if ($doBooks && !$doArchives)      { $delW[] = "entity_type = 'book'"; }
        elseif ($doArchives && !$doBooks)  { $delW[] = "entity_type = 'archival_unit'"; }
        if ($fromMysql !== null)  { $delW[] = 'datestamp >= ?'; $types .= 's'; $vals[] = $fromMysql; }
        if ($untilMysql !== null) { $delW[] = 'datestamp <= ?'; $types .= 's'; $vals[] = $untilMysql; }
        $delCond = !empty($delW) ? 'WHERE ' . implode(' AND ', $delW) : '';
        $parts[] = "SELECT id AS _id, entity_type AS _entity, 'deleted' AS _status, datestamp AS _datestamp"
            . " FROM oai_deleted_records $delCond";

        // UNION ALL with DB-level ORDER + LIMIT + OFFSET.
        $union   = implode(' UNION ALL ', $parts);
        $pageSql = "SELECT _id, _entity, _status, _datestamp FROM ($union) AS _combined ORDER BY _datestamp, _id LIMIT ? OFFSET ?";
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
                           l.edizione, l.created_at, l.updated_at
                      FROM libri l WHERE l.deleted_at IS NULL AND l.id IN ($ph)";
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

        // Batch-fetch deleted record details.
        $delMap = [];
        $delIds = array_values(array_map(
            fn($r) => (int) $r['_id'],
            array_filter($pageRefs, fn($r) => $r['_status'] === 'deleted')
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

        // ── Batch-fetch related data for all book records on this page ─────────
        $authorsMap  = [];
        $publisherMap = [];
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
                  ORDER BY la.libro_id, la.ordine_credito, a.nome"
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
            if ($ref['_status'] === 'deleted') {
                if (isset($delMap[$id])) { $result[] = $delMap[$id]; }
            } elseif ($ref['_entity'] === 'book' && isset($bookMap[$id])) {
                $row = $bookMap[$id];
                $row['_entity']    = 'book';
                $row['_status']    = 'active';
                $row['_datestamp'] = $row['updated_at'];
                // Attach pre-fetched related data to avoid N+1 queries in writeMetadata().
                $row['_authors']   = $authorsMap[$id] ?? [];
                $row['_publisher'] = $publisherMap[(int) ($row['editore_id'] ?? 0)] ?? null;
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
        int $cursor
    ): string {
        $token   = bin2hex(random_bytes(24));
        $payload = json_encode([
            'metadataPrefix' => $metadataPrefix,
            'from'           => $from,
            'until'          => $until,
            'set'            => $set,
            'cursor'         => $cursor,
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
     * @return array{metadataPrefix:string, from:string, until:string, set:string, cursor:int}|null
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
              ORDER BY la.ordine_credito, a.nome'
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
        $entity = ($rec['_entity'] === 'archival_unit') ? 'archival_unit' : 'book';
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
     * Convert common language name/code to ISO 639-2/B three-letter MARC code.
     */
    private function iso639_3ToMarc(string $lang): string
    {
        $map = [
            'italiano' => 'ita', 'italian' => 'ita',
            'english'  => 'eng', 'inglese' => 'eng',
            'français' => 'fre', 'francese' => 'fre', 'french' => 'fre',
            'deutsch'  => 'ger', 'tedesco' => 'ger', 'german' => 'ger',
            'español'  => 'spa', 'spagnolo' => 'spa', 'spanish' => 'spa',
            'português'=> 'por', 'portoghese' => 'por', 'portuguese' => 'por',
            'ita' => 'ita', 'eng' => 'eng', 'fre' => 'fre', 'ger' => 'ger',
            'spa' => 'spa', 'por' => 'por', 'lat' => 'lat', 'grc' => 'grc',
        ];
        $key = strtolower(trim($lang));
        return $map[$key] ?? 'und';
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

        $authors   = $this->fetchAuthorsForBook($id);
        $publisher = !empty($row['editore_id']) ? $this->fetchPublisher((int) $row['editore_id']) : null;
        $genre     = !empty($row['genere_id'])  ? $this->fetchGenre((int) $row['genere_id'])     : null;

        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->startDocument('1.0', 'UTF-8');
        $this->writeBookUnimarc($xw, $row, $authors, $publisher, $genre);
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

        $authors   = $this->fetchAuthorsForBook($id);
        $publisher = !empty($row['editore_id']) ? $this->fetchPublisher((int) $row['editore_id']) : null;
        $genre     = !empty($row['genere_id'])  ? $this->fetchGenre((int) $row['genere_id'])     : null;

        $binary   = $this->bookToIso2709($row, $authors, $publisher, $genre);
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
     * RFC 7235 compliant:
     * - No credentials supplied: 401 + WWW-Authenticate (challenge the client)
     * - Credentials supplied but invalid: 403 (forbidden)
     *
     * @param ResponseInterface       $response template response
     * @param ResponseInterface|null  &$out     set to 401 or 403 on failure
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
        if ($auth !== '' && str_starts_with($auth, 'Basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);
            if ($decoded !== false) {
                $parts = explode(':', $decoded, 2);
                if (count($parts) === 2 && $this->authenticateBasicOai($parts[0], $parts[1])) {
                    return true;
                }
            }
            // Credentials present but invalid
            $out = $response->withStatus(403);
            return false;
        }

        // No credentials provided — challenge the client (RFC 7235 §3.1)
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
        return $row !== null && password_verify($pass, (string) $row['password']);
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
     * @param array<string, mixed>|null         $publisher
     * @param array<string, mixed>|null         $genre
     */
    private function bookToIso2709(
        array $row,
        array $authors,
        ?array $publisher,
        ?array $genre
    ): string {
        $FT = "\x1E"; // Field terminator
        $RT = "\x1D"; // Record terminator
        $SF = "\x1F"; // Subfield delimiter

        // Each entry: [tag, ind1|null, ind2|null, data_string]
        // Control fields (001-009): ind1/ind2 = null
        /** @var list<array{0:string,1:string|null,2:string|null,3:string}> $fields */
        $fields = [];

        $fields[] = ['001', null, null, (string) ($row['id'] ?? '')];
        $fields[] = ['003', null, null, absoluteUrl('/')];
        $fields[] = ['005', null, null, gmdate('YmdHis') . '.0'];

        $langCode = $this->iso639_3ToMarc((string) ($row['lingua'] ?? 'italiano'));
        $year     = (string) ($row['anno_pubblicazione'] ?? '');
        $date1    = (strlen($year) === 4 && ctype_digit($year)) ? $year : '    ';
        $f100core = gmdate('Ymd') . '1' . $date1 . '    ' . '0000ba' . $langCode;
        $fields[] = ['100', ' ', ' ', $SF . 'a' . str_pad($f100core, 36)];

        foreach (['isbn13', 'isbn10', 'ean'] as $col) {
            if (!empty($row[$col])) {
                $fields[] = ['010', ' ', ' ', $SF . 'a' . (string) $row[$col]];
                break;
            }
        }

        $fields[] = ['101', '0', ' ', $SF . 'a' . $langCode];
        $fields[] = ['102', ' ', ' ', $SF . 'a' . 'IT'];

        $d200 = $SF . 'a' . (string) ($row['titolo'] ?? '');
        if (!empty($row['sottotitolo'])) { $d200 .= $SF . 'e' . (string) $row['sottotitolo']; }
        if (!empty($authors)) { $d200 .= $SF . 'f' . (string) $authors[0]['nome']; }
        $fields[] = ['200', '1', ' ', $d200];

        if (!empty($row['edizione'])) {
            $fields[] = ['205', ' ', ' ', $SF . 'a' . (string) $row['edizione']];
        }

        $d210 = '';
        if ($publisher !== null && !empty($publisher['nome'])) {
            $d210 .= $SF . 'c' . (string) $publisher['nome'];
        }
        if ($year !== '') { $d210 .= $SF . 'd' . $year; }
        if ($d210 !== '') { $fields[] = ['210', ' ', ' ', $d210]; }

        if (!empty($row['numero_pagine'])) {
            $fields[] = ['215', ' ', ' ', $SF . 'a' . (string) $row['numero_pagine'] . ' p.'];
        }

        $desc = !empty($row['descrizione_plain']) ? (string) $row['descrizione_plain']
                                                  : (string) ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $fields[] = ['330', ' ', ' ', $SF . 'a' . strip_tags($desc)];
        }

        if ($genre !== null && !empty($genre['nome'])) {
            $fields[] = ['606', ' ', ' ', $SF . 'a' . (string) $genre['nome']];
        }

        if (!empty($row['parole_chiave'])) {
            foreach (explode(',', (string) $row['parole_chiave']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '') { $fields[] = ['606', ' ', ' ', $SF . 'a' . $kw]; }
            }
        }

        $relMap = ['traduttore' => '060', 'curatore' => '340', 'illustratore' => '110'];
        foreach ($authors as $i => $a) {
            $tag = ($i === 0) ? '700' : '701';
            $rel = $relMap[$a['ruolo'] ?? ''] ?? '070';
            $fields[] = [$tag, '0', ' ', $SF . 'a' . (string) $a['nome'] . $SF . '4' . $rel];
        }

        if (!empty($row['collana'])) {
            $d225 = $SF . 'a' . (string) $row['collana'];
            if (!empty($row['numero_serie'])) { $d225 .= $SF . 'v' . (string) $row['numero_serie']; }
            $fields[] = ['225', '0', ' ', $d225];
        }

        $fields[] = ['801', ' ', '0',
            $SF . 'a' . 'IT' . $SF . 'b' . 'Pinakes' . $SF . 'c' . gmdate('Ymd')];

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

        // FIX F065: UNIMARC leader was inconsistent between MARCXML and binary
        // (ISO 2709) serializations:
        //   - MARCXML (line ~1339): "00000nam a2200000 u 4500"  ← correct
        //   - binary (this fn):     "00000nam  2200000   4500"  ← missing
        //     character coding scheme at pos 9 ('a' = UCS/Unicode) and the
        //     descriptive cataloguing form at pos 18 ('u' = UNIMARC base level).
        //
        // UNIMARC leader layout (24 bytes total):
        //   00-04  Record length      (5 digits)
        //   05     Record status      'n'  (new)
        //   06     Type of record     'a'  (printed language material)
        //   07     Bibliographic level 'm'  (monograph)
        //   08-09  Implementation     '  ' (two blanks)
        //   10     Indicator count    '2'
        //   11     Subfield code len  '2'
        //   12-16  Base address       (5 digits)
        //   17     Encoding level     ' '  (full level)
        //   18     Descriptive form   'u'  (UNIMARC, IFLA 2008)
        //   19     Reserved           ' '
        //   20-23  Entry map          '4500'
        //
        // The XML and binary leaders now match exactly (modulo length & base
        // address fields, which obviously differ); preserves backward compat
        // with the existing `nam` prefix that test 14 verifies, and adds the
        // missing 'a' + 'u' that strict UNIMARC validators expect.
        $leader = sprintf('%05d', $recordLength)
            . 'nam a22'
            . sprintf('%05d', $baseAddr)
            . ' u 4500';

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
}
