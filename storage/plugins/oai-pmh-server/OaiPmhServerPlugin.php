<?php

declare(strict_types=1);

namespace App\Plugins\OaiPmhServer;

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
 * Metadata formats: oai_dc, marcxml, mods, mag
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

    /** Page size for ListRecords / ListIdentifiers */
    private const PAGE_SIZE = 100;

    /** Resumption token TTL in seconds (24 hours) */
    private const TOKEN_TTL = 86400;

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
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function onDeactivate(): void
    {
        $this->deleteHooksFromDb();
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
                entity_id    INT NOT NULL,
                oai_id       VARCHAR(255) NOT NULL,
                datestamp    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_entity (entity_type, entity_id),
                KEY idx_datestamp (datestamp),
                KEY idx_oai_id (oai_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'oai_resumption_tokens' => "CREATE TABLE IF NOT EXISTS oai_resumption_tokens (
                token      VARCHAR(64) NOT NULL,
                payload    JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                INDEX idx_libro_id (libro_id)
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

        // Install triggers for persistent deleted record tracking.
        $this->installTriggers();

        return ['created' => $created, 'failed' => $failed];
    }

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

            $this->db->query("DROP TRIGGER IF EXISTS `{$name}`");
            $this->db->query(
                "CREATE TRIGGER `{$name}` BEFORE UPDATE ON `{$table}`
                 FOR EACH ROW BEGIN {$def['body']}; END"
            );
        }
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

        // Purge expired tokens on every request (cheap maintenance).
        $this->purgeExpiredTokens();

        $verb    = (string) ($params['verb'] ?? '');
        $now     = gmdate('Y-m-d\TH:i:s\Z');
        $baseUrl = absoluteUrl('/oai');
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
        $verbIsValid = in_array($verb, $validVerbs, true);
        $xw->startElement('request');
        if ($verbIsValid) {
            if ($verb !== '') { $xw->writeAttribute('verb', $verb); }
            foreach (['metadataPrefix', 'identifier', 'from', 'until', 'set', 'resumptionToken'] as $k) {
                if (!empty($params[$k])) { $xw->writeAttribute($k, (string) $params[$k]); }
            }
        }
        $xw->text($baseUrl);
        $xw->endElement(); // request

        $argumentError = $verbIsValid ? $this->validateOaiArguments($verb, $params) : null;
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
            return ['badResumptionToken', 'The value of the resumptionToken argument is invalid or expired.'];
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
        $earliest = $now;
        $r = $this->db->query(
            "SELECT MIN(created_at) AS e FROM libri WHERE deleted_at IS NULL"
        );
        if ($r instanceof \mysqli_result) {
            $row = $r->fetch_assoc();
            $r->free();
            if (!empty($row['e'])) {
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $row['e']);
                if ($dt !== false) {
                    $earliest = $dt->format('Y-m-d\TH:i:s\Z');
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
                $dt2 = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $row2['e']);
                if ($dt2 !== false) {
                    $ts2 = $dt2->format('Y-m-d\TH:i:s\Z');
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
        $xw->writeElement('deletedRecord', 'persistent');
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
        if ($identifier !== '') {
            // Validate identifier refers to a known item (book or archival_unit).
            if ($this->resolveIdentifier($identifier, $host) === null) {
                $this->oaiError($xw, 'idDoesNotExist',
                    'The value of the identifier argument is unknown or illegal in this repository.');
                return;
            }
        }

        $xw->startElement('ListMetadataFormats');

        foreach ($this->metadataFormats() as $fmt) {
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
                'schema'    => 'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd',
                'namespace' => 'http://www.loc.gov/MARC21/slim',
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

        // Expose archives set only if archival_units table exists.
        $chk = $this->db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
        );
        if ($chk instanceof \mysqli_result) {
            $row = $chk->fetch_assoc();
            $chk->free();
            if (($row['c'] ?? 0) > 0) {
                $xw->startElement('set');
                $xw->writeElement('setSpec', 'archives');
                $xw->writeElement('setName', __('Archivio — Unità archivistiche'));
                $xw->endElement();
            }
        }

        $xw->endElement(); // ListSets
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

        $validPrefixes = ['oai_dc', 'marcxml', 'mods', 'mag', 'unimarc'];
        if ($metadataPrefix === '' || !in_array($metadataPrefix, $validPrefixes, true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
            return;
        }

        $validSets = ['', 'books', 'archives'];
        if (!in_array($set, $validSets, true)) {
            $this->oaiError($xw, 'noSetHierarchy',
                'This repository does not support the requested set.');
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

        // Normalise date strings to MySQL DATETIME format.
        $fromMysql  = $from  !== '' ? str_replace(['T', 'Z'], [' ', ''], $from)  : null;
        $untilMysql = $until !== '' ? str_replace(['T', 'Z'], [' ', ''], $until) : null;

        // Build the combined result set: active records + persistent deletions.
        $records = $this->fetchRecordsPage($set, $fromMysql, $untilMysql, $cursor, self::PAGE_SIZE + 1);

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
                $xw->startElement('metadata');
                $this->writeMetadata($xw, $rec, $metadataPrefix);
                $xw->endElement(); // metadata
            }

            if (!$identifiersOnly) {
                $xw->endElement(); // record
            }
        }

        // Resumption token
        $nextCursor = $cursor + self::PAGE_SIZE;
        $xw->startElement('resumptionToken');
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

        $validPrefixes = ['oai_dc', 'marcxml', 'mods', 'mag', 'unimarc'];
        if (!in_array($metadataPrefix, $validPrefixes, true)) {
            $this->oaiError($xw, 'cannotDisseminateFormat',
                'The metadata format identified by the value given for the metadataPrefix '
                . 'argument is not supported by the item or by the repository.');
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

        $datestamp = $this->recordDatestamp($rec);

        $xw->startElement('GetRecord');
        $xw->startElement('record');
        $xw->startElement('header');
        $xw->writeElement('identifier', $identifier);
        $xw->writeElement('datestamp', $datestamp);
        $setSpec = ($rec['_entity'] === 'archival_unit') ? 'archives' : 'books';
        $xw->writeElement('setSpec', $setSpec);
        $xw->endElement(); // header

        $xw->startElement('metadata');
        $this->writeMetadata($xw, $rec, $metadataPrefix);
        $xw->endElement(); // metadata

        $xw->endElement(); // record
        $xw->endElement(); // GetRecord
    }

    // ── Metadata format dispatcher ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $rec
     */
    private function writeMetadata(\XMLWriter $xw, array $rec, string $metadataPrefix): void
    {
        if ($rec['_entity'] === 'archival_unit') {
            $this->writeArchivalUnitMetadata($xw, $rec, $metadataPrefix);
            return;
        }

        // Books — fetch related data.
        $bookId    = (int) $rec['id'];
        $authors   = $this->fetchAuthorsForBook($bookId);
        $publisher = !empty($rec['editore_id']) ? $this->fetchPublisher((int) $rec['editore_id']) : null;
        $genre     = !empty($rec['genere_id'])  ? $this->fetchGenre((int) $rec['genere_id'])     : null;

        match ($metadataPrefix) {
            'oai_dc'  => $this->writeBookOaiDc($xw, $rec, $authors, $publisher, $genre),
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
        ?array $genre
    ): void {
        $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
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

        // dc:identifier (ISBN13 preferred, fallback ISBN10, then EAN)
        foreach (['isbn13', 'isbn10', 'ean'] as $col) {
            if (!empty($row[$col])) {
                $xw->writeElementNs('dc', 'identifier', null, (string) $row[$col]);
                break;
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
        $this->marcControlField($xw, '005', date('YmdHis', $ts) . '.0');

        // 008 — Fixed-length data (minimal: year + language)
        $year = str_pad((string) ($row['anno_pubblicazione'] ?? ''), 4, ' ');
        $lang = str_pad($this->iso639_3ToMarc((string) ($row['lingua'] ?? '')), 3, ' ');
        $this->marcControlField($xw, '008', date('ymd') . 's' . $year . '    it |||||||||' . $lang . '  d');

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
     * UNIMARC/XML serialisation using the MARC21slim XML container.
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
        $xw->startElementNs(null, 'record', 'http://www.loc.gov/MARC21/slim');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.loc.gov/MARC21/slim ' .
            'http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd');

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
        $xw->text(date('Y-m-d', $ts));
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
        $magSchema = 'http://www.iccu.sbn.it/mag/mag_V2.0.1.xsd';

        // Fetch MAG project config (fallback to defaults).
        $magCfg = $this->fetchMagProjectConfig();

        $xw->startElementNs(null, 'mag', $magNs);
        $xw->writeAttributeNs('xsi', 'schemaLocation', null, $magNs . ' ' . $magSchema);
        $xw->writeAttribute('version', '2.0.1');

        // ── <gen> — General metadata ──────────────────────────────────────────
        $xw->startElement('gen');
        $xw->startElement('stprog');
        $xw->writeElement('progetto', (string) ($magCfg['project_code'] ?? 'PINAKES'));
        $xw->writeElement('codice_progetto', (string) ($magCfg['institution_code'] ?? 'IT'));
        $xw->endElement(); // stprog
        $xw->writeElement('collection', (string) ($magCfg['collection_name'] ?? 'Biblioteca'));
        $xw->writeElement('item', (string) ($row['id'] ?? ''));
        $xw->writeElement('rights', (string) ($magCfg['rights_statement'] ?? 'In Copyright'));
        $xw->endElement(); // gen

        // ── <bib> — Bibliographic metadata ───────────────────────────────────
        $xw->startElement('bib');

        // <identifier type="ISBN">
        foreach (['isbn13', 'isbn10'] as $col) {
            if (!empty($row[$col])) {
                $xw->startElement('identifier');
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

        // <title>
        $xw->startElement('title');
        $xw->writeElement('title_proper', (string) ($row['titolo'] ?? ''));
        if (!empty($row['sottotitolo'])) {
            $xw->writeElement('parallelotitolo', (string) $row['sottotitolo']);
        }
        $xw->endElement(); // title

        // <creator> — one entry per author
        foreach ($authors as $a) {
            $xw->startElement('creator');
            $xw->writeElement('ente_nome', (string) $a['nome']);
            $xw->writeElement('affiliation', '');
            $xw->endElement();
        }

        // <publisher>
        if ($publisher !== null && !empty($publisher['nome'])) {
            $xw->writeElement('publisher', (string) $publisher['nome']);
        }

        // <format>
        $xw->writeElement('format', (string) ($row['formato'] ?? 'text'));

        // <description>
        $desc = !empty($row['descrizione_plain']) ? $row['descrizione_plain'] : ($row['descrizione'] ?? '');
        if ($desc !== '') {
            $xw->writeElement('description', strip_tags((string) $desc));
        }

        // <subject>
        if ($genre !== null && !empty($genre['nome'])) {
            $xw->writeElement('subject', (string) $genre['nome']);
        }

        $xw->endElement(); // bib

        // ── <doc> — Digital file (from digital_assets table, if present) ───────
        $asset = $this->fetchDigitalAsset((int) $row['id']);
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

        $xw->endElement(); // mag
    }

    // ── Archival unit metadata (delegates to archives plugin formats) ─────────

    /**
     * @param array<string, mixed> $rec
     */
    private function writeArchivalUnitMetadata(\XMLWriter $xw, array $rec, string $metadataPrefix): void
    {
        // For archival units, we produce minimal DC output.
        // The archives plugin's own /archives/oai endpoint handles richer formats.
        if ($metadataPrefix === 'oai_dc') {
            $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
            $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
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
        } else {
            // For MARCXML/MODS/MAG, emit minimal stub for archival units.
            $this->writeArchivalUnitDcFallback($xw, $rec, $metadataPrefix);
        }
    }

    /**
     * @param array<string, mixed> $rec
     */
    private function writeArchivalUnitDcFallback(\XMLWriter $xw, array $rec, string $format): void
    {
        // Fallback: always emit oai_dc-compatible output even for other format requests
        // (the richer formats for archival units are in the archives plugin itself).
        $xw->startElementNs('oai_dc', 'dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
        $xw->writeAttributeNs('xmlns', 'dc', null, 'http://purl.org/dc/elements/1.1/');
        $xw->writeAttributeNs('xsi', 'schemaLocation', null,
            'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        $xw->writeElementNs('dc', 'title', null, (string) ($rec['constructed_title'] ?? ''));
        $xw->writeElementNs('dc', 'type', null, 'Archival Unit');
        $xw->endElement();
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
            $id  = (int) $m[1];
            $res = $this->db->query(
                "SELECT * FROM archival_units WHERE id = {$id} AND deleted_at IS NULL"
            );
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
     * @return list<array<string, mixed>>
     */
    private function fetchRecordsPage(
        string $set,
        ?string $fromMysql,
        ?string $untilMysql,
        int $cursor,
        int $limit
    ): array {
        $rows = [];

        // Determine which entity types to query.
        $doBooks    = ($set === '' || $set === 'books');
        $doArchives = ($set === '' || $set === 'archives');

        // ── Active books ──────────────────────────────────────────────────────
        if ($doBooks) {
            $where = ['l.deleted_at IS NULL'];
            $types = '';
            $vals  = [];
            if ($fromMysql !== null) {
                $where[] = 'l.updated_at >= ?';
                $types  .= 's';
                $vals[]  = $fromMysql;
            }
            if ($untilMysql !== null) {
                $where[] = 'l.updated_at <= ?';
                $types  .= 's';
                $vals[]  = $untilMysql;
            }
            $whereStr = implode(' AND ', $where);
            $sql = "SELECT l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.lingua,
                           l.isbn13, l.isbn10, l.ean, l.issn, l.editore_id, l.genere_id,
                           l.sottogenere_id, l.numero_pagine, l.formato, l.tipo_media,
                           l.descrizione, l.descrizione_plain, l.parole_chiave,
                           l.traduttore, l.illustratore, l.curatore, l.collana,
                           l.numero_serie, l.classificazione_dewey, l.file_url,
                           l.edizione, l.created_at, l.updated_at,
                           'book' AS _entity, 'active' AS _status,
                           l.updated_at AS _datestamp
                      FROM libri l
                     WHERE $whereStr
                     ORDER BY l.updated_at, l.id";

            if (!empty($vals)) {
                $stmt = $this->db->prepare($sql);
                if ($stmt !== false) {
                    $stmt->bind_param($types, ...$vals);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res instanceof \mysqli_result) {
                        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                        $res->free();
                    }
                    $stmt->close();
                }
            } else {
                $res = $this->db->query($sql);
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                    $res->free();
                }
            }
        }

        // ── Active archival units ─────────────────────────────────────────────
        if ($doArchives) {
            $auTable = $this->db->query(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units'"
            );
            $auExists = false;
            if ($auTable instanceof \mysqli_result) {
                $auRow = $auTable->fetch_assoc();
                $auTable->free();
                $auExists = (($auRow['c'] ?? 0) > 0);
            }

            if ($auExists) {
                $where = ['deleted_at IS NULL'];
                $types = '';
                $vals  = [];
                if ($fromMysql !== null) {
                    $where[] = 'updated_at >= ?';
                    $types  .= 's';
                    $vals[]  = $fromMysql;
                }
                if ($untilMysql !== null) {
                    $where[] = 'updated_at <= ?';
                    $types  .= 's';
                    $vals[]  = $untilMysql;
                }
                $whereStr = implode(' AND ', $where);
                $sql = "SELECT id, reference_code, constructed_title, formal_title,
                               scope_content, language_codes, created_at, updated_at,
                               'archival_unit' AS _entity, 'active' AS _status,
                               updated_at AS _datestamp
                          FROM archival_units
                         WHERE $whereStr
                         ORDER BY updated_at, id";

                if (!empty($vals)) {
                    $stmt = $this->db->prepare($sql);
                    if ($stmt !== false) {
                        $stmt->bind_param($types, ...$vals);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res instanceof \mysqli_result) {
                            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                            $res->free();
                        }
                        $stmt->close();
                    }
                } else {
                    $res = $this->db->query($sql);
                    if ($res instanceof \mysqli_result) {
                        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                        $res->free();
                    }
                }
            }
        }

        // ── Deleted records (persistent) ──────────────────────────────────────
        $delWhere  = [];
        $delTypes  = '';
        $delVals   = [];

        if ($doBooks && !$doArchives) {
            $delWhere[] = "entity_type = 'book'";
        } elseif ($doArchives && !$doBooks) {
            $delWhere[] = "entity_type = 'archival_unit'";
        }
        if ($fromMysql !== null) {
            $delWhere[] = 'datestamp >= ?';
            $delTypes  .= 's';
            $delVals[]  = $fromMysql;
        }
        if ($untilMysql !== null) {
            $delWhere[] = 'datestamp <= ?';
            $delTypes  .= 's';
            $delVals[]  = $untilMysql;
        }
        $delWhereStr = !empty($delWhere) ? 'WHERE ' . implode(' AND ', $delWhere) : '';
        $delSql = "SELECT id, entity_type AS _entity, entity_id, oai_id, datestamp,
                          datestamp AS _datestamp, 'deleted' AS _status
                     FROM oai_deleted_records $delWhereStr
                     ORDER BY datestamp, id";

        if (!empty($delVals)) {
            $stmt = $this->db->prepare($delSql);
            if ($stmt !== false) {
                $stmt->bind_param($delTypes, ...$delVals);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res instanceof \mysqli_result) {
                    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                    $res->free();
                }
                $stmt->close();
            }
        } else {
            $res = $this->db->query($delSql);
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_assoc()) { $rows[] = $r; }
                $res->free();
            }
        }

        // Sort merged results by datestamp, then apply cursor + limit.
        usort($rows, function (array $a, array $b): int {
            $da = (string) ($a['_datestamp'] ?? '');
            $db = (string) ($b['_datestamp'] ?? '');
            return strcmp($da, $db) ?: (int) ($a['id'] ?? 0) - (int) ($b['id'] ?? 0);
        });

        return array_slice($rows, $cursor, $limit);
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
        $expires = date('Y-m-d H:i:s', time() + self::TOKEN_TTL);

        $stmt = $this->db->prepare(
            'INSERT INTO oai_resumption_tokens (token, payload, expires_at) VALUES (?, ?, ?)'
        );
        if ($stmt !== false) {
            $stmt->bind_param('sss', $token, $payload, $expires);
            $stmt->execute();
            $stmt->close();
        }

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
        if ($rec['_status'] === 'deleted') {
            return (string) ($rec['oai_id'] ?? '');
        }
        $entity = ($rec['_entity'] === 'archival_unit') ? 'archival_unit' : 'book';
        return 'oai:' . $host . ':' . $entity . ':' . (string) ($rec['id'] ?? '');
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
            return $out; // @phpstan-ignore-line
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
            return $out; // @phpstan-ignore-line
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
     * Sets $out to a 403 response and returns false when auth fails.
     *
     * @param ResponseInterface       $response template response
     * @param ResponseInterface|null  &$out     set to 403 on failure
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

        if ($request !== null) {
            $auth = $request->getHeaderLine('Authorization');
            if (str_starts_with($auth, 'Basic ')) {
                $decoded = base64_decode(substr($auth, 6), true);
                if ($decoded !== false) {
                    $parts = explode(':', $decoded, 2);
                    if (count($parts) === 2 && $this->authenticateBasicOai($parts[0], $parts[1])) {
                        return true;
                    }
                }
            }
        }

        $out = $response->withStatus(403);
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

        $leader = sprintf('%05d', $recordLength)
            . 'nam  22'
            . sprintf('%05d', $baseAddr)
            . '   4500';

        return $leader . $directory . $fieldData . $RT;
    }
}
