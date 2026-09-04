<?php

declare(strict_types=1);

namespace App\Plugins\NcipServer;

use App\Support\HookManager;
use App\Support\RateLimiter;
use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * NCIP 2.02 (NISO Circulation Interchange Protocol) server for Pinakes v0.7.3.
 *
 * Single endpoint: POST /ncip
 * Content-Type accepted: application/xml, text/xml, application/octet-stream
 *
 * Supported messages:
 *   LookupItem       — returns item details and availability
 *   LookupUser       — returns basic patron info (no PII beyond name)
 *   CheckOutItem     — creates a loan (admin/staff only)
 *   CheckInItem      — closes a loan (admin/staff only)
 *   RenewItem        — extends a loan due date (admin/staff only)
 *   RequestItem      — patron-side request for an item (admin/staff only)
 *   CancelRequestItem — cancels a pending request (admin/staff only)
 *
 * Unsupported messages return a ProblemType=Unsupported response.
 *
 * Authentication: Basic HTTP auth checked against Pinakes users table.
 *   Only users with tipo_utente IN ('admin','staff') may perform write operations.
 *   Unauthenticated requests can only call LookupItem.
 *
 * Spec: https://www.niso.org/standards-committees/ncip
 * Schema: http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd
 */
class NcipServerPlugin
{
    private const NCIP_NS      = 'http://www.niso.org/2008/ncip';
    private const NCIP_VERSION = 'http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd';

    /**
     * Maximum accepted request body size for the unauthenticated /ncip endpoint.
     *
     * FIX F048: tightened from 1 MiB to 256 KiB. NCIP request messages are
     * typically a few KB; SimpleXML retains the parsed DOM in memory so the
     * effective allocation is several multiples of the raw byte length.
     */
    private const MAX_REQUEST_BYTES = 262_144;

    private HookManager $hookManager;
    private \mysqli $db;
    private ?int $pluginId = null;

    /** Partner attivo risolto dal FromAgencyId del messaggio corrente (per il log transazioni). */
    private ?int $currentPartnerId = null;

    public function __construct(\mysqli $db, HookManager $hookManager)
    {
        $this->db          = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[NcipServer] Schema activation failed for: ' . implode(', ', $result['failed'])
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

    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[NcipServer] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }
    }

    public function onUninstall(): void {}

    // ─── Schema ───────────────────────────────────────────────────────────────

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
            'ncip_partners' => "CREATE TABLE IF NOT EXISTS ncip_partners (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                code         VARCHAR(64)   NULL DEFAULT NULL,
                name         VARCHAR(255)  NOT NULL,
                agency_id    VARCHAR(255)  NULL,
                endpoint_url VARCHAR(500)  NULL,
                isil         VARCHAR(64)   NULL,
                notes        TEXT          NULL,
                active       TINYINT(1)    NOT NULL DEFAULT 1,
                created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_code (code),
                KEY idx_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'ncip_transactions' => "CREATE TABLE IF NOT EXISTS ncip_transactions (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                partner_id   INT          NULL,
                message_type VARCHAR(64)  NOT NULL,
                prestito_id  INT          NULL,
                prenotazione_id INT       NULL,
                request_id   VARCHAR(255) NULL,
                status       ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
                error_msg    VARCHAR(1000) NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_partner  (partner_id),
                KEY idx_status   (status),
                KEY idx_prestito (prestito_id),
                KEY idx_prenotazione (prenotazione_id),
                CONSTRAINT ncip_transactions_ibfk_1 FOREIGN KEY (partner_id) REFERENCES ncip_partners (id) ON DELETE SET NULL,
                CONSTRAINT ncip_transactions_ibfk_2 FOREIGN KEY (prestito_id) REFERENCES prestiti (id) ON DELETE SET NULL,
                CONSTRAINT ncip_transactions_ibfk_3 FOREIGN KEY (prenotazione_id) REFERENCES prenotazioni (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** @return array<string,string> column => ADD COLUMN DDL */
    private static function columnDefs(): array
    {
        return [
            'prenotazione_id' => 'ALTER TABLE ncip_transactions ADD COLUMN prenotazione_id INT NULL AFTER prestito_id',
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
                SecureLogger::error("[NcipServer] CREATE TABLE {$name} failed: " . $this->db->error);
                $failed[] = $name;
            }
        }

        // CREATE TABLE IF NOT EXISTS is a no-op for a pre-existing table. Add
        // newer columns before their FKs so an active plugin upgrades safely.
        if (!in_array('ncip_transactions', $failed, true) && !$this->ensureColumns()) {
            $failed[] = 'ncip_transactions';
        }

        // The FK constraints are likewise not applied by CREATE TABLE on an
        // existing schema. Add them idempotently after every column is present;
        // fail activation rather than register hooks over a partial schema.
        if (!in_array('ncip_transactions', $failed, true) && !$this->ensureForeignKeys()) {
            $failed[] = 'ncip_transactions';
        }

        // Core schema changes for prestiti.ncip_request_id and origine ENUM are in migrate_0.7.4.sql

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Columns required on an existing ncip_transactions table. Exposed to the
     * PluginManager so a same-version stale-class upgrade self-heals on boot.
     *
     * @return list<array{table:string, column:string}>
     */
    public function expectedColumns(): array
    {
        $out = [];
        foreach (array_keys(self::columnDefs()) as $column) {
            $out[] = ['table' => 'ncip_transactions', 'column' => $column];
        }
        return $out;
    }

    private function ensureColumns(): bool
    {
        foreach (self::columnDefs() as $column => $alterSql) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = \'ncip_transactions\'
                   AND COLUMN_NAME = ?'
            );
            if ($stmt === false) {
                SecureLogger::error('[NcipServer] Column probe prepare failed: ' . $this->db->error);
                return false;
            }
            $stmt->bind_param('s', $column);
            if (!$stmt->execute()) {
                SecureLogger::error('[NcipServer] Column probe failed for ' . $column . ': ' . $stmt->error);
                $stmt->close();
                return false;
            }
            $result = $stmt->get_result();
            $exists = $result instanceof \mysqli_result
                && ((int) ($result->fetch_assoc()['c'] ?? 0)) > 0;
            $stmt->close();
            if (!$exists && $this->db->query($alterSql) === false) {
                SecureLogger::error('[NcipServer] Adding column ' . $column . ' failed: ' . $this->db->error);
                return false;
            }
        }
        return true;
    }

    /**
     * Add the ncip_transactions foreign keys when missing. Detects each FK
     * by column + referenced table (name-agnostic) via KEY_COLUMN_USAGE, nulls
     * out orphan rows that would violate the constraint, then ALTERs it in with
     * ON DELETE SET NULL. Idempotent and safe to re-run from onActivate/onInstall.
     * All table/column names below are static literals — no user input.
     *
     * @return bool True when every FK is present (or was added); false when any
     *              probe/cleanup/ALTER failed so the caller can report the schema
     *              as half-applied instead of registering hooks over it.
     */
    /**
     * Single source of truth for the ncip_transactions foreign keys, shared by
     * ensureForeignKeys() (which adds them) and expectedForeignKeys() (which
     * lets PluginManager's boot-time self-heal detect them missing after a
     * stale-class upgrade). Keep them in sync via this list.
     *
     * @return list<array{column:string, ref_table:string, ref_col:string, name:string}>
     */
    private static function foreignKeyDefs(): array
    {
        return [
            ['column' => 'partner_id',  'ref_table' => 'ncip_partners', 'ref_col' => 'id', 'name' => 'ncip_transactions_ibfk_1'],
            ['column' => 'prestito_id', 'ref_table' => 'prestiti',      'ref_col' => 'id', 'name' => 'ncip_transactions_ibfk_2'],
            ['column' => 'prenotazione_id', 'ref_table' => 'prenotazioni', 'ref_col' => 'id', 'name' => 'ncip_transactions_ibfk_3'],
        ];
    }

    /**
     * Declared to PluginManager::bundledSchemaIncomplete() so an already-active
     * ncip at the same version whose FKs are missing (stale-class admin-UI
     * upgrade) self-heals on the next boot by re-running onActivate.
     *
     * @return list<array{table:string, column:string, ref_table:string}>
     */
    public function expectedForeignKeys(): array
    {
        $out = [];
        foreach (self::foreignKeyDefs() as $fk) {
            $out[] = [
                'table'     => 'ncip_transactions',
                'column'    => $fk['column'],
                'ref_table' => $fk['ref_table'],
            ];
        }
        return $out;
    }

    private function ensureForeignKeys(): bool
    {
        $fks = self::foreignKeyDefs();

        $ok = true;
        foreach ($fks as $fk) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'ncip_transactions'
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME = ?"
            );
            if ($stmt === false) {
                SecureLogger::error('[NcipServer] FK probe prepare failed: ' . $this->db->error);
                $ok = false;
                continue;
            }
            $stmt->bind_param('ss', $fk['column'], $fk['ref_table']);
            if (!$stmt->execute()) {
                SecureLogger::error('[NcipServer] FK probe failed for ' . $fk['column'] . ': ' . $stmt->error);
                $stmt->close();
                $ok = false;
                continue;
            }
            $res = $stmt->get_result();
            $exists = $res instanceof \mysqli_result && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
            $stmt->close();
            if ($exists) {
                continue;
            }

            // Null out orphan references that would fail the FK on ADD.
            if ($this->db->query(
                "UPDATE ncip_transactions t
                 LEFT JOIN {$fk['ref_table']} r ON t.{$fk['column']} = r.{$fk['ref_col']}
                 SET t.{$fk['column']} = NULL
                 WHERE t.{$fk['column']} IS NOT NULL AND r.{$fk['ref_col']} IS NULL"
            ) === false) {
                SecureLogger::error('[NcipServer] Orphan cleanup for ' . $fk['column'] . ' failed: ' . $this->db->error);
                $ok = false;
                continue;
            }

            $alter = "ALTER TABLE ncip_transactions
                      ADD CONSTRAINT {$fk['name']}
                      FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['ref_table']} ({$fk['ref_col']}) ON DELETE SET NULL";
            if ($this->db->query($alter) === false) {
                SecureLogger::error('[NcipServer] Adding FK ' . $fk['name'] . ' failed: ' . $this->db->error);
                $ok = false;
            }
        }

        return $ok;
    }

    // ─── Hook registration ────────────────────────────────────────────────────

    private function registerHookInDb(string $hookName, string $method, int $priority): void
    {
        if ($this->pluginId === null) {
            SecureLogger::warning('[NcipServer] pluginId not set; cannot register hook ' . $hookName);
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
            throw new \RuntimeException('[NcipServer] prepare() failed for hook ' . $hookName . ': ' . $this->db->error);
        }
        $callbackClass = self::class;
        $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[NcipServer] hook insert failed for ' . $hookName . ': ' . $err);
        }
        $stmt->close();
    }

    private function deleteHooksFromDb(): void
    {
        if ($this->pluginId === null) { return; }
        $stmt = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
        if ($stmt === false) { return; }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /** Register routes. */
    public function registerRoutes(\Slim\App $app): void
    {
        $plugin = $this;

        $app->post('/ncip', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->ncipAction($request, $response);
        });

        // GET for capability discovery (returns NCIP InitiationHeader)
        $app->get('/ncip', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->ncipCapabilityAction($request, $response);
        });

        // Admin: partner management UI
        $app->get('/admin/plugins/ncip-server/partners', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->adminPartnersListAction($request, $response);
        });

        $app->post('/admin/plugins/ncip-server/partners', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->adminPartnersCreateAction($request, $response);
        });

        $app->post('/admin/plugins/ncip-server/partners/{id:[0-9]+}/delete', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->adminPartnersDeleteAction($request, $response, (int) $args['id']);
        });

        // Admin: transaction log
        $app->get('/admin/plugins/ncip-server/transactions', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->adminTransactionsAction($request, $response);
        });
    }

    // ─── Endpoint handlers ────────────────────────────────────────────────────

    public function ncipCapabilityAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $xml = $this->buildCapabilityXml();
        return $this->xmlResponse($response, $xml);
    }

    // ─── Admin UI handlers ────────────────────────────────────────────────────

    public function adminPartnersListAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $error = '',
        string $success = ''
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            // FIX F047: use locale-aware translated route instead of hardcoded /admin/login
            // (which does not exist; canonical login route is /accedi (IT) or /login (EN)).
            return $response->withStatus(302)->withHeader(
                'Location',
                url(\App\Support\RouteTranslator::route('login'))
            );
        }
        $partners   = $this->fetchAllPartners();
        $csrfToken  = \App\Support\Csrf::ensureToken();
        ob_start();
        include __DIR__ . '/views/partners.php';
        $content = (string) ob_get_clean();
        ob_start();
        $pageTitle = __('Gestione Partner NCIP');
        require __DIR__ . '/../../../app/Views/layout.php';
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    public function adminPartnersCreateAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            return $response->withStatus(403);
        }
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');
        if (!\App\Support\Csrf::validate($token)) {
            return $this->adminPartnersListAction($request, $response, __('Token CSRF non valido.'));
        }
        $name        = trim((string) ($body['name'] ?? ''));
        $endpointUrl = trim((string) ($body['endpoint_url'] ?? ''));
        $isil        = trim((string) ($body['isil'] ?? ''));
        $notes       = trim((string) ($body['notes'] ?? ''));

        if ($name === '' || $endpointUrl === '') {
            return $this->adminPartnersListAction($request, $response, __('Nome ed Endpoint URL sono obbligatori.'));
        }
        $stmt = $this->db->prepare(
            'INSERT INTO ncip_partners (name, endpoint_url, isil, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        if ($stmt === false) {
            return $this->adminPartnersListAction($request, $response, __('Errore nell\'aggiunta del partner.'));
        }
        if (!$stmt->bind_param('ssss', $name, $endpointUrl, $isil, $notes) || !$stmt->execute()) {
            $stmt->close();
            return $this->adminPartnersListAction($request, $response, __('Errore nell\'aggiunta del partner.'));
        }
        $stmt->close();
        return $this->adminPartnersListAction($request, $response, '', __('Partner aggiunto con successo.'));
    }

    public function adminPartnersDeleteAction(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            return $response->withStatus(403);
        }
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');
        if (!\App\Support\Csrf::validate($token)) {
            return $this->adminPartnersListAction($request, $response, __('Token CSRF non valido.'));
        }
        $stmt = $this->db->prepare('DELETE FROM ncip_partners WHERE id = ?');
        if ($stmt === false) {
            return $this->adminPartnersListAction($request, $response, __('Errore nell\'eliminazione del partner.'));
        }
        if (!$stmt->bind_param('i', $id) || !$stmt->execute()) {
            $stmt->close();
            return $this->adminPartnersListAction($request, $response, __('Errore nell\'eliminazione del partner.'));
        }
        $stmt->close();
        return $this->adminPartnersListAction($request, $response, '', __('Partner eliminato con successo.'));
    }

    public function adminTransactionsAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!$this->requireAdminOrStaff()) {
            // FIX F047: use locale-aware translated route instead of hardcoded /admin/login.
            return $response->withStatus(302)->withHeader(
                'Location',
                url(\App\Support\RouteTranslator::route('login'))
            );
        }
        $params  = $request->getQueryParams();
        $perPage = 50;
        $page    = max(1, (int) ($params['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $countRes = $this->db->query('SELECT COUNT(*) AS c FROM ncip_transactions');
        $total    = ($countRes instanceof \mysqli_result) ? (int) ($countRes->fetch_assoc()['c'] ?? 0) : 0;

        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT id, message_type, partner_id, prestito_id, prenotazione_id, request_id, status, created_at
               FROM ncip_transactions
              ORDER BY id DESC
              LIMIT ? OFFSET ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $perPage, $offset);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            }
            $stmt->close();
        }
        $transactions = $rows;
        $partners     = $this->fetchAllPartners();

        $csrfToken = \App\Support\Csrf::ensureToken();
        ob_start();
        include __DIR__ . '/views/transactions.php';
        $content = (string) ob_get_clean();
        ob_start();
        $pageTitle = __('Log Transazioni NCIP');
        require __DIR__ . '/../../../app/Views/layout.php';
        $html = (string) ob_get_clean();
        $response->getBody()->write($html);
        return $response;
    }

    private function requireAdminOrStaff(): bool
    {
        return isset($_SESSION['user']) &&
            in_array($_SESSION['user']['tipo_utente'] ?? '', ['admin', 'staff'], true);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchAllPartners(): array
    {
        $res = $this->db->query('SELECT id, name, endpoint_url, isil, notes, created_at FROM ncip_partners ORDER BY name ASC');
        if (!($res instanceof \mysqli_result)) { return []; }
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
        return $rows;
    }

    public function ncipAction(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // FIX F048: tighten body cap from 1 MiB to 256 KiB. NCIP messages are
        // typically < 10 KB; the endpoint is reachable unauthenticated, so a
        // smaller cap reduces the DoS surface (SimpleXML retains the parsed
        // DOM in memory, amplifying allocation vs. the raw byte size).
        $body = (string) $request->getBody();
        if (trim($body) === '') {
            return $this->xmlResponse(
                $response->withStatus(400),
                $this->buildProblem('Empty request body', 'empty-request')
            );
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            return $this->xmlResponse(
                $response->withStatus(413),
                $this->buildProblem('Request body too large', 'oversized-request')
            );
        }

        // Parse incoming NCIP XML. LIBXML_NONET disables network entity
        // resolution; LIBXML_NOERROR suppresses libxml warnings as we already
        // surface a clean 400 below on parse failure.
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOERROR | LIBXML_NONET);
        if ($xml === false) {
            return $this->xmlResponse(
                $response->withStatus(400),
                $this->buildProblem('Malformed XML', 'invalid-xml')
            );
        }
        // Free the original body string; the SimpleXML tree is the working copy.
        unset($body);

        // Authenticate caller from HTTP Basic auth
        $caller = $this->authenticate($request);

        // Determine the message type (first child element after NCIPMessage root)
        $messageType = $this->detectMessageType($xml);

        // La tabella partner esisteva storicamente come metadato amministrativo:
        // la sua mera presenza non può trasformarsi implicitamente in una nuova
        // policy di autorizzazione e bloccare i client NCIP già configurati.
        // Quando FromAgencyId identifica un partner attivo lo conserviamo per il
        // log transazioni; un header assente/sconosciuto lascia partner_id NULL.
        // L'autorità per le operazioni di scrittura resta la Basic auth staff.
        $partner = $this->resolvePartner($xml, $messageType);
        $this->currentPartnerId = $partner !== null ? (int) $partner['id'] : null;

        $result = match ($messageType) {
            'LookupItem'          => $this->handleLookupItem($request, $response, $xml),
            'LookupUser'          => $this->handleLookupUser($request, $response, $xml, $caller),
            'CheckOutItem'        => $this->handleCheckOutItem($request, $response, $xml, $caller),
            'CheckInItem'         => $this->handleCheckInItem($request, $response, $xml, $caller),
            'RenewItem'           => $this->handleRenewItem($request, $response, $xml, $caller),
            'RequestItem'         => $this->handleRequestItem($request, $response, $xml, $caller),
            'CancelRequestItem'   => $this->handleCancelRequestItem($request, $response, $xml, $caller),
            default               => $this->xmlResponse(
                $response,
                $this->buildProblem(
                    "Message type '{$messageType}' is not supported by this responder",
                    'unsupported-request'
                )
            ),
        };

        // FIX F048: explicitly release the SimpleXMLElement before returning.
        // SimpleXML holds the parsed DOM until refcount hits zero; nudging GC
        // here keeps peak memory bounded on bursty unauthenticated traffic.
        unset($xml);

        return $result;
    }

    // ─── Message handlers ─────────────────────────────────────────────────────

    private function handleLookupItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml
    ): ResponseInterface {
        // Extract ItemIdentifierValue
        $message = $this->messageNode($xml, 'LookupItem');
        $itemIdRaw = (string) ($message?->ItemId->ItemIdentifierValue ?? '');
        if ($itemIdRaw === '') {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemIdentifierValue', 'unknown-item')
            );
        }

        $bookId = $this->parseNcipNumericId($itemIdRaw);
        if ($bookId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("Invalid ItemIdentifierValue '{$itemIdRaw}'", 'unknown-item')
            );
        }
        $book   = $this->fetchBook($bookId);
        if ($book === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("Item '{$itemIdRaw}' not found", 'unknown-item')
            );
        }

        $xml = $this->buildLookupItemResponse($book);
        return $this->xmlResponse($response, $xml);
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleLookupUser(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if ($caller === null) {
            return $this->xmlResponse(
                $response->withStatus(401)->withHeader('WWW-Authenticate', 'Basic realm="NCIP"'),
                $this->buildProblem('Authentication required', 'unauthorized')
            );
        }

        $message = $this->messageNode($xml, 'LookupUser');
        $userIdRaw  = (string) ($message?->UserId->UserIdentifierValue ?? '');
        $targetId   = $userIdRaw !== '' ? $this->parseNcipNumericId($userIdRaw) : null;
        if ($targetId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("Invalid or missing UserIdentifierValue", 'unknown-user')
            );
        }

        // Authorization: patron can only look up themselves; staff/admin can look up anyone
        $callerRole = (string) ($caller['tipo_utente'] ?? '');
        $isPrivileged = in_array($callerRole, ['admin', 'staff'], true);
        if (!$isPrivileged && (int) ($caller['id'] ?? 0) !== $targetId) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Access denied', 'access-denied')
            );
        }

        $user = $this->fetchUser($targetId);
        if ($user === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("User '{$userIdRaw}' not found", 'unknown-user')
            );
        }

        return $this->xmlResponse($response, $this->buildLookupUserResponse($user));
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleCheckOutItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if (!$this->isStaff($caller)) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Insufficient privileges', 'unauthorized')
            );
        }

        $message = $this->messageNode($xml, 'CheckOutItem');
        $itemId = $this->parseNcipNumericId((string) ($message?->ItemId->ItemIdentifierValue ?? ''));
        $userId = $this->parseNcipNumericId((string) ($message?->UserId->UserIdentifierValue ?? ''));
        if ($itemId === null || $userId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Invalid ItemId or UserId', 'invalid-data')
            );
        }

        $user = $this->fetchUser($userId);
        if ($user === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('User not found', 'unknown-user')
            );
        }

        // Atomic checkout: lock the book row so concurrent requests serialize.
        $today = \App\Support\DateHelper::today();
        $loanDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'loan_duration_days', '30') ?? 30);
        $loanDays = $loanDays > 0 ? $loanDays : 30;
        $dueDate = (new \DateTimeImmutable($today))->modify("+{$loanDays} days")->format('Y-m-d');
        $failureReason = null;
        $loanId  = $this->createLoanAtomic($itemId, $userId, $dueDate, (int) ($caller['id'] ?? 0), $failureReason);

        if ($loanId === null) {
            // Map the atomic-checkout rejection to the correct NCIP ProblemType.
            // A PERMANENT rejection (duplicate/ineligible/limit/no-copies) must
            // not be reported as the retryable 'temporary-processing-failure',
            // or a partner keeps resubmitting a request that can never succeed.
            switch ($failureReason) {
                case 'not_found':
                    return $this->xmlResponse($response, $this->buildProblem('Item not found', 'unknown-item'));
                case 'duplicate':
                    return $this->xmlResponse($response, $this->buildProblem('User already has this item on loan or reserved', 'duplicate-request'));
                case 'ineligible':
                    return $this->xmlResponse($response, $this->buildProblem('User is not eligible to check out this item', 'user-ineligible-to-check-out'));
                case 'max_loans':
                    return $this->xmlResponse($response, $this->buildProblem('Maximum item checkouts reached for this user', 'user-loan-limit-reached'));
                case 'no_capacity':
                case 'no_copy':
                    return $this->xmlResponse($response, $this->buildProblem('No copies available', 'item-not-checked-in'));
                case 'invalid_due':
                case 'db_error':
                default:
                    return $this->xmlResponse($response, $this->buildProblem('Failed to create loan', 'temporary-processing-failure'));
            }
        }

        // Log della transazione come per RequestItem/CancelRequestItem: prima
        // solo le richieste venivano registrate e il log admin era parziale.
        $this->logTransaction('CheckOutItem', $loanId, null);

        return $this->xmlResponse($response, $this->buildCheckOutItemResponse($itemId, $userId, $dueDate));
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleCheckInItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if (!$this->isStaff($caller)) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Insufficient privileges', 'unauthorized')
            );
        }

        $checkInItem = $this->messageNode($xml, 'CheckInItem');
        $itemId = $this->parseNcipNumericId((string) ($checkInItem?->ItemId->ItemIdentifierValue ?? ''));
        if ($itemId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Invalid ItemId', 'invalid-data')
            );
        }
        // UserId è opzionale, ma se il client lo include deve essere un ID
        // positivo valido: trattare un valore malformato come "assente" farebbe
        // ricadere sulla ricerca per solo titolo e potrebbe chiudere il prestito
        // di un altro utente.
        $checkInUserId = null;
        if ($checkInItem !== null && isset($checkInItem->UserId)) {
            $checkInUserId = $this->parseNcipNumericId((string) ($checkInItem->UserId->UserIdentifierValue ?? ''));
            if ($checkInUserId === null) {
                return $this->xmlResponse(
                    $response,
                    $this->buildProblem('Invalid UserId', 'invalid-data')
                );
            }
        }

        $ambiguousLoan = false;
        $loanLookupFailed = false;
        $loan = $this->findActiveLoan($itemId, $checkInUserId, $ambiguousLoan, $loanLookupFailed);
        if ($loanLookupFailed) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to look up active loan', 'temporary-processing-failure')
            );
        }
        if ($ambiguousLoan) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Multiple active loans for this item; UserId is required', 'invalid-data')
            );
        }
        if ($loan === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active loan for this item', 'item-not-checked-out')
            );
        }

        $resolvedCheckInUserId = (int) $loan['utente_id'];
        if (!$this->closeLoan((int) $loan['id'], $itemId, $resolvedCheckInUserId)) {
            // A concurrent CheckInItem may have returned this exact loan between
            // findActiveLoan() and closeLoan(): LoanRepository::close()'s state
            // guard then returns false. That is not a failure — the item IS
            // checked in — so honour the F052 idempotency contract and report
            // success instead of a retryable temporary-processing-failure.
            if ($this->isLoanReturned((int) $loan['id'], $itemId, $resolvedCheckInUserId)) {
                // This request still completed a CheckInItem operation and
                // must be auditable even though another concurrent request won
                // the close race. The normal-success branch below logs once on
                // its own path, so this does not double-log a single request.
                $this->logTransaction('CheckInItem', (int) $loan['id'], null);
                return $this->xmlResponse($response, $this->buildCheckInItemResponse($itemId));
            }
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to check in item', 'temporary-processing-failure')
            );
        }
        $this->logTransaction('CheckInItem', (int) $loan['id'], null);
        return $this->xmlResponse($response, $this->buildCheckInItemResponse($itemId));
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleRenewItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if (!$this->isStaff($caller)) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Insufficient privileges', 'unauthorized')
            );
        }

        $renewItem = $this->messageNode($xml, 'RenewItem');
        $itemId = $this->parseNcipNumericId((string) ($renewItem?->ItemId->ItemIdentifierValue ?? ''));
        if ($itemId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Invalid ItemId', 'invalid-data')
            );
        }
        // Come CheckInItem: assenza ammessa, presenza malformata/non-positiva no.
        // In particolare non degradare "abc"/"0" a una ricerca per solo titolo.
        $renewUserId = null;
        if ($renewItem !== null && isset($renewItem->UserId)) {
            $renewUserId = $this->parseNcipNumericId((string) ($renewItem->UserId->UserIdentifierValue ?? ''));
            if ($renewUserId === null) {
                return $this->xmlResponse(
                    $response,
                    $this->buildProblem('Invalid UserId', 'invalid-data')
                );
            }
        }

        $ambiguousLoan = false;
        $loanLookupFailed = false;
        $loan = $this->findActiveLoan($itemId, $renewUserId, $ambiguousLoan, $loanLookupFailed);
        if ($loanLookupFailed) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to look up active loan', 'temporary-processing-failure')
            );
        }
        if ($ambiguousLoan) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Multiple active loans for this item; UserId is required', 'invalid-data')
            );
        }
        if ($loan === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active loan to renew', 'item-not-checked-out')
            );
        }

        $failureReason = 'db_error';
        $resolvedRenewUserId = (int) $loan['utente_id'];
        $newDue = $this->extendLoan(
            (int) $loan['id'],
            $itemId,
            $resolvedRenewUserId,
            $failureReason
        );
        if ($newDue === null) {
            // Map permanent rejections to stable NCIP ProblemTypes so the partner
            // stops retrying a renewal that can never succeed. Only a genuine DB
            // error stays retryable (temporary-processing-failure).
            $problemType = match ($failureReason) {
                'not_found'                => 'unknown-item',
                'identity_changed'         => 'item-not-checked-out',
                'ineligible_state'         => 'item-not-renewable',
                'user_ineligible'          => 'user-ineligible-to-renew',
                'max_renewals'             => 'maximum-renewals-exceeded',
                'no_capacity', 'overlap'   => 'item-not-renewable',
                default                    => 'temporary-processing-failure',
            };
            SecureLogger::error('[NcipServer] extendLoan failed: ' . $failureReason);
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to extend loan', $problemType)
            );
        }

        $this->logTransaction('RenewItem', (int) $loan['id'], null);

        return $this->xmlResponse($response, $this->buildRenewItemResponse($itemId, $newDue, $resolvedRenewUserId));
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleRequestItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if (!$this->isStaff($caller)) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Insufficient privileges', 'unauthorized')
            );
        }

        $message = $this->messageNode($xml, 'RequestItem');
        $itemId = $this->parseNcipNumericId((string) ($message?->ItemId->ItemIdentifierValue ?? ''));
        $userId = $this->parseNcipNumericId((string) ($message?->UserId->UserIdentifierValue ?? ''));
        if ($itemId === null || $userId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Invalid ItemId or UserId', 'invalid-data')
            );
        }

        $book = $this->fetchBook($itemId);
        $user = $this->fetchUser($userId);
        if ($book === null || $user === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Item or user not found', 'unknown-item')
            );
        }

        $requestId = (string) ($message?->RequestId->RequestIdentifierValue ?? '');
        $today = \App\Support\DateHelper::today();
        $loanDays = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'loan_duration_days', '30') ?? 30);
        $loanDays = $loanDays > 0 ? $loanDays : 30;
        $dueDate = (new \DateTimeImmutable($today))->modify("+{$loanDays} days")->format('Y-m-d');
        $failureReason = 'db_error';
        $requestOutcome = $this->createRequestItemNcip(
            $itemId,
            $userId,
            $dueDate,
            $requestId !== '' ? $requestId : null,
            $failureReason
        );
        if ($requestOutcome === null) {
            $problemType = match ($failureReason) {
                'not_found'   => 'unknown-item',
                'duplicate'   => 'duplicate-request',
                'ineligible'  => 'user-ineligible-to-check-out',
                'max_loans'   => 'user-loan-limit-reached',
                default       => 'temporary-processing-failure',
            };
            SecureLogger::error('[NcipServer] createRequestItemNcip failed: ' . $failureReason);
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to create ILL request', $problemType)
            );
        }

        return $this->xmlResponse($response, $this->buildRequestItemResponse($itemId, $userId, $dueDate));
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function handleCancelRequestItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml,
        ?array $caller
    ): ResponseInterface {
        if (!$this->isStaff($caller)) {
            return $this->xmlResponse(
                $response->withStatus(403),
                $this->buildProblem('Insufficient privileges', 'unauthorized')
            );
        }

        $message = $this->messageNode($xml, 'CancelRequestItem');
        $itemId = $this->parseNcipNumericId((string) ($message?->ItemId->ItemIdentifierValue ?? ''));

        if ($itemId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Invalid ItemId', 'invalid-data')
            );
        }

        // Come CheckInItem/RenewItem: UserId assente è ammesso, ma un valore
        // presente e malformato NON deve degradare a null — la ricerca per solo
        // titolo con LIMIT 1 potrebbe annullare la richiesta di un altro utente.
        $userId = null;
        if ($message !== null && isset($message->UserId)) {
            $userId = $this->parseNcipNumericId((string) ($message->UserId->UserIdentifierValue ?? ''));
            if ($userId === null) {
                return $this->xmlResponse(
                    $response,
                    $this->buildProblem('Invalid UserId', 'invalid-data')
                );
            }
        }

        try {
            $cancelResult = $this->cancelPendingNcipRequest($itemId, $userId);
        } catch (\RuntimeException $e) {
            SecureLogger::error('[NcipServer] cancelPendingNcipRequest failed: ' . $e->getMessage());
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to cancel request', 'temporary-processing-failure')
            );
        }
        if ($cancelResult['status'] === 'ambiguous') {
            return $this->xmlResponse(
                $response,
                $this->buildProblem(
                    $userId === null
                        ? 'Multiple pending ILL requests for this item; UserId is required'
                        : 'Multiple matching pending ILL requests for this item and user',
                    'invalid-data'
                )
            );
        }
        if ($cancelResult['status'] !== 'cancelled') {
            // Approval and cancellation serialize on the book row. If approval
            // won, the locked query no longer sees a pending request and this
            // branch cannot overwrite the approved loan or orphan its copy.
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active ILL request for this item', 'item-not-checked-out')
            );
        }

        // Like handleRenewItem, answer with the borrower the locked row
        // resolved: when the client omitted UserId the request-derived value
        // is null even though the cancellation just determined the user.
        return $this->xmlResponse(
            $response,
            $this->buildCancelRequestItemResponse($itemId, $cancelResult['user_id'])
        );
    }

    // ─── XML builders ─────────────────────────────────────────────────────────

    private function writeResponseHeader(\XMLWriter $xw, string $toAgencyId = 'LOCAL'): void
    {
        $ns = self::NCIP_NS;
        $xw->startElementNs('ncip', 'ResponseHeader', $ns);
        $xw->startElementNs('ncip', 'FromAgencyId', $ns);
        $xw->writeElementNs('ncip', 'AgencyId', $ns, $toAgencyId);
        $xw->endElement();
        $xw->startElementNs('ncip', 'ToAgencyId', $ns);
        $xw->writeElementNs('ncip', 'AgencyId', $ns, 'PINAKES');
        $xw->endElement();
        $xw->endElement();
    }

    private function buildCapabilityXml(): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('LookupAgencyResponse');
        $xw->startElement('AgencyId');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/agencyidtype/');
        $xw->text('Pinakes');
        $xw->endElement();

        $xw->startElement('OrganizationNameInformation');
        $xw->startElement('OrganizationNameType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/organizationnametype/');
        $xw->text('Library Name');
        $xw->endElement();
        $xw->writeElement('OrganizationName', 'Pinakes');
        $xw->endElement();

        $xw->startElement('ApplicationProfileSupportedType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/applicationprofiletype/');
        $xw->text('NCIP 2.02; supported messages: LookupItem, LookupUser, CheckOutItem, CheckInItem, RenewItem, RequestItem, CancelRequestItem');
        $xw->endElement();
        $xw->endElement(); // LookupAgencyResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    /**
     * @param array<string, mixed> $book
     */
    private function buildLookupItemResponse(array $book): string
    {
        $xw  = $this->newXmlWriter();
        $id  = (int) $book['id'];
        $avail = (int) ($book['copie_disponibili'] ?? 0);

        // Derive the NCIP circulation status from the book's derived availability
        // summary (libri.stato) rather than treating every not-available cause as
        // "Checked Out". Fall back to the lendable-copy counter when stato is unknown.
        $stato = (string) ($book['stato'] ?? '');
        switch ($stato) {
            case 'prestato':
                $circStatus = 'Checked Out';
                break;
            case 'prenotato':
                $circStatus = 'On Hold';
                break;
            case 'perso':
                $circStatus = 'Lost';
                break;
            case 'danneggiato':
            case 'non_disponibile':
                $circStatus = 'Not Available';
                break;
            case 'disponibile':
                $circStatus = $avail > 0 ? 'Available On Shelf' : 'Not Available';
                break;
            default:
                $circStatus = $avail > 0 ? 'Available On Shelf' : 'Not Available';
                break;
        }

        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('LookupItemResponse');
        $this->writeResponseHeader($xw);

        // ItemId
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $id);
        $xw->endElement();

        // ItemOptionalFields — title, availability
        $xw->startElement('ItemOptionalFields');
        $xw->startElement('BibliographicDescription');
        $xw->writeElement('Author', (string) ($book['author_name'] ?? ''));
        $xw->writeElement('PublicationDate', (string) ($book['anno_pubblicazione'] ?? ''));
        $xw->writeElement('Title', (string) ($book['titolo'] ?? ''));
        $xw->endElement(); // BibliographicDescription

        $xw->startElement('CirculationStatus');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/circulationstatus/');
        $xw->text($circStatus);
        $xw->endElement();

        $xw->startElement('ItemDescription');
        $xw->writeElement('NumberOfPieces', (string) ($book['copie_totali'] ?? 1));
        $xw->endElement();

        $xw->endElement(); // ItemOptionalFields

        $xw->endElement(); // LookupItemResponse
        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function buildLookupUserResponse(array $user): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('LookupUserResponse');
        $this->writeResponseHeader($xw);

        $xw->startElement('UserId');
        $xw->startElement('UserIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/useridentifiertype/');
        $xw->text('Institution Id Number');
        $xw->endElement();
        $xw->writeElement('UserIdentifierValue', (string) ($user['id'] ?? ''));
        $xw->endElement();

        $xw->startElement('UserOptionalFields');
        $xw->startElement('NameInformation');
        $xw->startElement('PersonalNameInformation');
        $xw->startElement('StructuredPersonalUserName');
        $xw->writeElement('GivenName', (string) ($user['nome'] ?? ''));
        $xw->writeElement('Surname', (string) ($user['cognome'] ?? ''));
        $xw->endElement(); // StructuredPersonalUserName
        $xw->endElement(); // PersonalNameInformation
        $xw->endElement(); // NameInformation

        $xw->startElement('UserPrivilege');
        $xw->startElement('AgencyId');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/agencyidtype/');
        $xw->text('Pinakes');
        $xw->endElement();
        $xw->startElement('AgencyUserPrivilegeType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/agencyuserprivilegetype/');
        $xw->text((string) ($user['tipo_utente'] ?? 'utente'));
        $xw->endElement();
        $xw->endElement(); // UserPrivilege

        $xw->endElement(); // UserOptionalFields

        $xw->endElement(); // LookupUserResponse
        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildCheckOutItemResponse(int $itemId, int $userId, string $dueDate): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('CheckOutItemResponse');
        $this->writeResponseHeader($xw);
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        $xw->startElement('UserId');
        $xw->startElement('UserIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/useridentifiertype/');
        $xw->text('Institution Id Number');
        $xw->endElement();
        $xw->writeElement('UserIdentifierValue', (string) $userId);
        $xw->endElement();
        $xw->writeElement('DateDue', gmdate('Y-m-d\T23:59:59\Z', strtotime($dueDate)));
        $xw->endElement(); // CheckOutItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildCheckInItemResponse(int $itemId): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('CheckInItemResponse');
        $this->writeResponseHeader($xw);
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        $xw->writeElement('DateReturned', gmdate('Y-m-d\TH:i:s\Z'));
        $xw->endElement(); // CheckInItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildRenewItemResponse(int $itemId, string $newDueDate, int $userId): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('RenewItemResponse');
        $this->writeResponseHeader($xw);
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        $xw->startElement('UserId');
        $xw->startElement('UserIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/useridentifiertype/');
        $xw->text('Institution Id Number');
        $xw->endElement();
        $xw->writeElement('UserIdentifierValue', (string) $userId);
        $xw->endElement();
        $xw->writeElement('DateDue', gmdate('Y-m-d\T23:59:59\Z', strtotime($newDueDate)));
        $xw->endElement(); // RenewItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildRequestItemResponse(int $itemId, int $userId, string $dueDate): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('RequestItemResponse');
        $this->writeResponseHeader($xw);
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        $xw->startElement('UserId');
        $xw->startElement('UserIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/useridentifiertype/');
        $xw->text('Institution Id Number');
        $xw->endElement();
        $xw->writeElement('UserIdentifierValue', (string) $userId);
        $xw->endElement();
        $xw->startElement('RequestType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/requesttype/');
        $xw->text('Hold');
        $xw->endElement();
        $xw->startElement('RequestScopeType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/requestscopetype/');
        $xw->text('Item');
        $xw->endElement();
        $xw->writeElement('DateAvailable', gmdate('Y-m-d\T23:59:59\Z', strtotime($dueDate)));
        $xw->endElement(); // RequestItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildCancelRequestItemResponse(int $itemId, ?int $userId): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('CancelRequestItemResponse');
        $this->writeResponseHeader($xw);
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        if ($userId !== null) {
            $xw->startElement('UserId');
            $xw->startElement('UserIdentifierType');
            $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/useridentifiertype/');
            $xw->text('Institution Id Number');
            $xw->endElement();
            $xw->writeElement('UserIdentifierValue', (string) $userId);
            $xw->endElement();
        }
        $xw->endElement(); // CancelRequestItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildProblem(string $message, string $type): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttribute('version', self::NCIP_VERSION);

        $xw->startElement('Problem');
        $xw->startElement('ProblemType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/processingerrortype/');
        $xw->text($type);
        $xw->endElement();
        $xw->writeElement('ProblemDetail', $message);
        $xw->endElement(); // Problem

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    // ─── DB helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function fetchBook(int $id): ?array
    {
        if ($id <= 0) { return null; }
        $stmt = $this->db->prepare(
            'SELECT l.id, l.titolo, l.stato, l.copie_totali, l.copie_disponibili,
                    l.anno_pubblicazione, a.nome AS author_name
               FROM libri l
               LEFT JOIN autori a ON a.id = (
                   SELECT la2.autore_id FROM libri_autori la2
                    WHERE la2.libro_id = l.id
                      AND la2.ruolo IN (\'principale\', \'co-autore\')
                   ORDER BY (la2.ruolo = \'principale\') DESC,
                            la2.ordine_credito IS NULL, la2.ordine_credito, la2.autore_id LIMIT 1
               )
              WHERE l.id = ? AND l.deleted_at IS NULL'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!($res instanceof \mysqli_result)) {
            $stmt->close();
            return null;
        }
        $row = $res->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchUser(int $id): ?array
    {
        if ($id <= 0) { return null; }
        $stmt = $this->db->prepare(
            "SELECT id, nome, cognome, email, tipo_utente FROM utenti WHERE id = ? AND stato = 'attivo' LIMIT 1"
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!($res instanceof \mysqli_result)) {
            $stmt->close();
            return null;
        }
        $row = $res->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @param-out bool $ambiguous True only when UserId is absent and the title
     *                            has more than one open NCIP loan.
     * @param-out bool $databaseError True when the lookup could not be completed.
     * @return array<string, mixed>|null
     */
    private function findActiveLoan(
        int $bookId,
        ?int $userId,
        bool &$ambiguous,
        bool &$databaseError
    ): ?array
    {
        $ambiguous = false;
        $databaseError = false;
        // Con più prestiti NCIP aperti dello stesso titolo (utenti diversi su
        // copie diverse) il solo libro_id è ambiguo. Leggine al massimo due:
        // senza UserId due righe devono produrre un errore, mai una mutazione
        // arbitraria; con UserId basta la singola riga filtrata.
        $userFilter = $userId !== null ? ' AND utente_id = ?' : '';
        $limit = $userId !== null ? 1 : 2;
        $stmt = null;
        try {
            $stmt = $this->db->prepare(
                "SELECT id, libro_id, utente_id, data_scadenza
                   FROM prestiti
                  WHERE libro_id = ? AND origine = 'ncip' AND attivo = 1
                    AND stato IN ('in_corso','in_ritardo'){$userFilter}
                  ORDER BY data_prestito DESC, id DESC LIMIT {$limit}"
            );
            if ($stmt === false) {
                throw new \RuntimeException('prepare failed: ' . $this->db->error);
            }
            if ($userId !== null) {
                $stmt->bind_param('ii', $bookId, $userId);
            } else {
                $stmt->bind_param('i', $bookId);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            if (!($res instanceof \mysqli_result)) {
                throw new \RuntimeException('result retrieval failed: ' . $stmt->error);
            }
            $row = $res->fetch_assoc();
            if ($userId === null && $row !== null && $res->fetch_assoc() !== null) {
                $ambiguous = true;
                $stmt->close();
                return null;
            }
            $stmt->close();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            if ($stmt instanceof \mysqli_stmt) {
                try {
                    $stmt->close();
                } catch (\Throwable) {
                    // Preserve the original lookup failure below.
                }
            }
            $databaseError = true;
            SecureLogger::error('[NcipServer] findActiveLoan failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Atomic checkout: locks the book row to prevent double-booking under concurrent requests.
     * Returns the new loan ID, or null on failure. On failure $failureReason is
     * set to a stable code so the caller can emit the correct NCIP ProblemType:
     * terminal — 'not_found' | 'duplicate' | 'ineligible' | 'max_loans' |
     * 'no_capacity' | 'no_copy'; transient/retryable — 'invalid_due' | 'db_error'.
     *
     * @param-out string $failureReason
     */
    private function createLoanAtomic(int $bookId, int $userId, string $dueDate, int $processedBy, ?string &$failureReason = null): ?int
    {
        $failureReason = 'db_error';
        $this->db->begin_transaction();
        try {
            $today = \App\Support\DateHelper::today();
            if (!\App\Support\DateHelper::isISODateFormat($dueDate) || $dueDate < $today) {
                $failureReason = 'invalid_due';
                $this->db->rollback();
                return null;
            }

            // Canonical lock order starts with the book. Unlike the old path,
            // availability is derived from commitments and a real copy is bound;
            // the cached libri.copie_disponibili counter is never decremented by hand.
            $lock = $this->db->prepare(
                'SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
            );
            if ($lock === false) {
                $this->db->rollback();
                return null;
            }
            $lock->bind_param('i', $bookId);
            $lock->execute();
            $res = $lock->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $lock->close();

            if ($row === null) {
                $failureReason = 'not_found';
                $this->db->rollback();
                return null;
            }

            // NCIP ItemId is currently a title-level libri.id, not a physical
            // copy identifier, and CheckOutItem carries no request idempotency
            // key. Keep this path strict even when the interactive staff UI
            // allows multiple same-title loans: otherwise an ordinary partner
            // retry is indistinguishable from an intentional second checkout
            // and can silently consume another copy.
            $multiplicityPolicy = new \App\Support\LoanMultiplicityPolicy($this->db);
            $hasDuplicate = $multiplicityPolicy->hasBlockingLoan($bookId, $userId, false);

            $reservation = $this->db->prepare(
                "SELECT id FROM prenotazioni
                 WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                 LIMIT 1 FOR UPDATE"
            );
            $reservation->bind_param('ii', $bookId, $userId);
            $reservation->execute();
            $hasReservation = (bool) $reservation->get_result()->fetch_row();
            $reservation->close();
            if ($hasDuplicate || $hasReservation) {
                $failureReason = 'duplicate';
                $this->db->rollback();
                return null;
            }

            $userLock = $this->db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
            $userLock->bind_param('i', $userId);
            $userLock->execute();
            $userLock->close();
            if (\App\Support\LoanEligibility::checkUser($this->db, $userId) !== null) {
                $failureReason = 'ineligible';
                $this->db->rollback();
                return null;
            }

            $maxLoans = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                $count = $this->db->prepare(
                    "SELECT COUNT(*) FROM prestiti
                     WHERE utente_id = ? AND attivo = 1
                       AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')"
                );
                $count->bind_param('i', $userId);
                $count->execute();
                $activeLoans = (int) ($count->get_result()->fetch_row()[0] ?? 0);
                $count->close();
                if ($activeLoans >= $maxLoans) {
                    $failureReason = 'max_loans';
                    $this->db->rollback();
                    return null;
                }
            }

            $capacity = new \App\Services\CapacityService($this->db);
            if (!$capacity->hasFreeCapacity($bookId, $today, $dueDate)) {
                $failureReason = 'no_capacity';
                $this->db->rollback();
                return null;
            }

            // #384 preference, identical to the canonical allocators (request
            // gate, FIFO promotion, approval Step 2d): prefer a copy with no
            // later commitment (or whose next one is furthest away) so a desk
            // checkout never takes the copy a scheduled loan already needs
            // while a free sibling sits idle. numero_inventario stays as the
            // librarian-friendly tie-break.
            $copySql =
                "SELECT c.id FROM copie c
                 WHERE c.libro_id = ?
                   AND c.stato IN ('disponibile','prenotato')
                   AND NOT EXISTS (
                       SELECT 1 FROM prestiti p
                       WHERE p.copia_id = c.id
                         AND p.data_prestito <= ?
                         AND (p.stato = 'in_ritardo'
                              OR (p.stato = 'in_corso' AND p.data_scadenza < ?)
                              OR p.data_scadenza >= ?)
                         AND ((p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                              OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL))
                   )";
            $copyParams = [$bookId, $dueDate, $today, $today];
            $copyTypes = 'isss';
            $copySql .=
                " ORDER BY COALESCE((
                       SELECT MIN(future.data_prestito)
                       FROM prestiti future
                       WHERE future.copia_id = c.id
                         AND future.data_prestito > ?
                         AND ((future.attivo = 1 AND future.stato IN ('in_corso','da_ritirare','prenotato','in_ritardo'))
                              OR (future.stato = 'pendente' AND future.copia_id IS NOT NULL))
                   ), '9999-12-31') DESC,
                   c.numero_inventario ASC
                 LIMIT 1 FOR UPDATE";
            $copyParams[] = $dueDate;
            $copyTypes .= 's';

            $copy = $this->db->prepare($copySql);
            $copy->bind_param($copyTypes, ...$copyParams);
            $copy->execute();
            $copyRow = $copy->get_result()->fetch_assoc();
            $copy->close();
            if (!$copyRow) {
                $failureReason = 'no_copy';
                $this->db->rollback();
                return null;
            }
            $copyId = (int) $copyRow['id'];

            $ins   = $this->db->prepare(
                "INSERT INTO prestiti
                    (libro_id, utente_id, copia_id, data_prestito, data_scadenza,
                     stato, origine, attivo, processed_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'in_corso', 'ncip', 1, ?, NOW(), NOW())"
            );
            if ($ins === false) { $this->db->rollback(); return null; }
            $processedByValue = $processedBy > 0 ? $processedBy : null;
            $ins->bind_param('iiissi', $bookId, $userId, $copyId, $today, $dueDate, $processedByValue);
            if (!$ins->execute()) { $ins->close(); $this->db->rollback(); return null; }
            $loanId = $ins->insert_id;
            $ins->close();

            $upd = $this->db->prepare("UPDATE copie SET stato = 'prestato' WHERE id = ?");
            if ($upd === false) { $this->db->rollback(); return null; }
            $upd->bind_param('i', $copyId);
            if (!$upd->execute() || $upd->affected_rows !== 1) {
                $upd->close();
                $this->db->rollback();
                return null;
            }
            $upd->close();

            $integrity = new \App\Support\DataIntegrity($this->db);
            if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
                throw new \RuntimeException('Failed to recalculate book availability');
            }

            $this->db->commit();
            try {
                (new \App\Support\NotificationService($this->db))->sendLoanApprovedNotification((int) $loanId);
            } catch (\Throwable $notificationError) {
                SecureLogger::warning('[NcipServer] checkout notification failed', [
                    'loan_id' => (int) $loanId,
                    'error' => $notificationError->getMessage(),
                ]);
            }
            return $loanId > 0 ? $loanId : null;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[NcipServer] createLoanAtomic failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param-out string $failureReason Stable rejection reason for RequestItem.
     * @return array{loan_id:?int,reservation_id:?int}|null
     */
    private function createRequestItemNcip(
        int $bookId,
        int $userId,
        string $dueDate,
        ?string $requestId,
        ?string &$failureReason = null
    ): ?array
    {
        $failureReason = 'db_error';
        $today = \App\Support\DateHelper::today();
        if (!\App\Support\DateHelper::isISODateFormat($dueDate) || $dueDate < $today) {
            $failureReason = 'invalid_due';
            return null;
        }

        $this->db->begin_transaction();
        try {
            $book = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $book->bind_param('i', $bookId);
            $book->execute();
            $bookExists = (bool) $book->get_result()->fetch_row();
            $book->close();
            if (!$bookExists) {
                $failureReason = 'not_found';
                $this->db->rollback();
                return null;
            }

            // A RequestItem is a BARE request (no copy is bound), so the
            // multiplicity policy stays strict regardless of the opt-in —
            // operationWillBindCopy=false reproduces exactly the historical
            // predicate, but from the single shared source instead of an
            // inline clone that drifts.
            $multiplicityPolicy = new \App\Support\LoanMultiplicityPolicy($this->db);
            $hasDuplicate = $multiplicityPolicy->hasBlockingLoan($bookId, $userId, false);

            $reservation = $this->db->prepare(
                "SELECT id FROM prenotazioni
                 WHERE libro_id = ? AND utente_id = ? AND stato = 'attiva'
                 LIMIT 1 FOR UPDATE"
            );
            $reservation->bind_param('ii', $bookId, $userId);
            $reservation->execute();
            $hasReservation = (bool) $reservation->get_result()->fetch_row();
            $reservation->close();
            if ($hasDuplicate || $hasReservation) {
                $failureReason = 'duplicate';
                $this->db->rollback();
                return null;
            }

            $userLock = $this->db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
            $userLock->bind_param('i', $userId);
            $userLock->execute();
            $userLock->close();
            if (\App\Support\LoanEligibility::checkUser($this->db, $userId) !== null) {
                $failureReason = 'ineligible';
                $this->db->rollback();
                return null;
            }

            // RequestItem is a Hold request: apply the same #384 gate as every
            // core patron entry point. If the only capacity depends on an
            // earlier return, persist a real FIFO reservation instead of a
            // bare pending loan that staff approval would inevitably reject.
            $routing = (new \App\Services\LoanRequestGate($this->db))->route(
                $bookId,
                $userId,
                $today,
                $dueDate,
                inCallerTransaction: true
            );
            if ($routing->isReservation()) {
                $reservationId = (int) $routing->reservationId;
                if (!$this->insertTransactionRecord(
                    'RequestItem',
                    null,
                    $requestId,
                    $reservationId
                )) {
                    throw new \RuntimeException('Failed to audit NCIP reservation request.');
                }
                $this->db->commit();
                return ['loan_id' => null, 'reservation_id' => $reservationId];
            }

            // A waitlist reservation does not consume an active-loan slot.
            // Enforce the cap only after the gate kept the request on the loan
            // path, matching ReservationsController/UserActionsController.
            $maxLoans = (int) ((new \App\Models\SettingsRepository($this->db))->get('loans', 'max_active_loans_per_user', '0') ?? 0);
            if ($maxLoans > 0) {
                $count = $this->db->prepare(
                    "SELECT COUNT(*) FROM prestiti
                     WHERE utente_id = ? AND attivo = 1
                       AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')"
                );
                $count->bind_param('i', $userId);
                $count->execute();
                $activeLoans = (int) ($count->get_result()->fetch_row()[0] ?? 0);
                $count->close();
                if ($activeLoans >= $maxLoans) {
                    $failureReason = 'max_loans';
                    $this->db->rollback();
                    return null;
                }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO prestiti
                    (libro_id, utente_id, data_prestito, data_scadenza, stato,
                     origine, ncip_request_id, attivo, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pendente', 'ncip', ?, 0, NOW(), NOW())"
            );
            if ($stmt === false) { $this->db->rollback(); return null; }
            $stmt->bind_param('iisss', $bookId, $userId, $today, $dueDate, $requestId);
            if (!$stmt->execute()) { $stmt->close(); $this->db->rollback(); return null; }
            $id = (int) $stmt->insert_id;
            $stmt->close();

            if (!$this->insertTransactionRecord('RequestItem', $id, $requestId, null)) {
                throw new \RuntimeException('Failed to audit NCIP loan request.');
            }
            $this->db->commit();

            if ($id > 0) {
                try {
                    (new \App\Support\NotificationService($this->db))->notifyLoanRequest($id);
                } catch (\Throwable $notificationError) {
                    SecureLogger::warning('[NcipServer] request notification failed', [
                        'loan_id' => $id,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }
            return $id > 0 ? ['loan_id' => $id, 'reservation_id' => null] : null;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[NcipServer] createRequestItemNcip failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve and cancel one still-pending NCIP loan request or active NCIP
     * waitlist reservation atomically.
     *
     * The book lock is acquired before the pending-request lookup, matching the
     * lock order used by RequestItem and approval. Consequently a concurrent
     * insert/approval either commits before this SELECT ... FOR UPDATE (and is
     * visible here) or waits until cancellation commits. Ambiguity detection is
     * therefore based on the same locked state that the UPDATE mutates.
     *
     * @return array{status:'cancelled', loan_id:?int, reservation_id:?int, user_id:int}|array{status:'not_found'|'ambiguous'}
     */
    private function cancelPendingNcipRequest(int $bookId, ?int $userId): array
    {
        $inTransaction = false;
        $reservationManager = null;
        try {
            $this->db->begin_transaction();
            $inTransaction = true;

            // CI-SOFT-DELETE-EXEMPT: cancelling a pending request must remain
            // possible after a title is archived, just as returning its copy is.
            $book = $this->db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
            if ($book === false) {
                throw new \RuntimeException('book lock prepare failed: ' . $this->db->error);
            }
            $book->bind_param('i', $bookId);
            $book->execute();
            $bookExists = (bool) $book->get_result()->fetch_row();
            $book->close();
            if (!$bookExists) {
                $this->db->rollback();
                $inTransaction = false;
                return ['status' => 'not_found'];
            }

            $userFilter = $userId !== null ? ' AND utente_id = ?' : '';
            $loanStmt = $this->db->prepare(
                "SELECT id, libro_id, utente_id, origine, attivo, stato
                   FROM prestiti
                  WHERE libro_id = ? AND origine = 'ncip'
                    AND attivo = 0 AND stato = 'pendente' AND copia_id IS NULL{$userFilter}
                  ORDER BY created_at DESC, id DESC
                  LIMIT 2 FOR UPDATE"
            );
            if ($loanStmt === false) {
                throw new \RuntimeException('loan lock prepare failed: ' . $this->db->error);
            }
            if ($userId !== null) {
                $loanStmt->bind_param('ii', $bookId, $userId);
            } else {
                $loanStmt->bind_param('i', $bookId);
            }
            $loanStmt->execute();
            $result = $loanStmt->get_result();
            $lockedLoans = $result instanceof \mysqli_result
                ? $result->fetch_all(MYSQLI_ASSOC)
                : [];
            $loanStmt->close();

            // A #384-routed RequestItem is represented by prenotazioni, not
            // prestiti. Resolve only rows carrying an explicit successful NCIP
            // transaction link: a web-created reservation for the same patron
            // must never be cancellable through this protocol endpoint.
            $reservationUserFilter = $userId !== null ? ' AND r.utente_id = ?' : '';
            $reservationStmt = $this->db->prepare(
                "SELECT r.id, r.libro_id, r.utente_id, r.queue_position
                   FROM prenotazioni r
                   JOIN ncip_transactions tx ON tx.prenotazione_id = r.id
                  WHERE r.libro_id = ? AND r.stato = 'attiva'
                    AND tx.message_type = 'RequestItem' AND tx.status = 'success'
                    AND tx.prestito_id IS NULL{$reservationUserFilter}
                  ORDER BY tx.created_at DESC, tx.id DESC
                  LIMIT 2 FOR UPDATE"
            );
            if ($reservationStmt === false) {
                throw new \RuntimeException('reservation lock prepare failed: ' . $this->db->error);
            }
            if ($userId !== null) {
                $reservationStmt->bind_param('ii', $bookId, $userId);
            } else {
                $reservationStmt->bind_param('i', $bookId);
            }
            $reservationStmt->execute();
            $reservationResult = $reservationStmt->get_result();
            $lockedReservations = $reservationResult instanceof \mysqli_result
                ? $reservationResult->fetch_all(MYSQLI_ASSOC)
                : [];
            $reservationStmt->close();

            $candidateCount = count($lockedLoans) + count($lockedReservations);
            if ($candidateCount === 0) {
                $this->db->rollback();
                $inTransaction = false;
                return ['status' => 'not_found'];
            }
            if ($candidateCount > 1) {
                $this->db->rollback();
                $inTransaction = false;
                return ['status' => 'ambiguous'];
            }

            $loanId = null;
            $reservationId = null;
            if ($lockedLoans !== []) {
                $lockedLoan = $lockedLoans[0];
                $loanId = (int) $lockedLoan['id'];
                $resolvedUserId = (int) $lockedLoan['utente_id'];

                // Repeat every lifecycle/identity predicate in the write itself.
                $update = $this->db->prepare(
                    "UPDATE prestiti
                        SET stato = 'annullato', attivo = 0, updated_at = NOW()
                      WHERE id = ? AND libro_id = ? AND utente_id = ?
                        AND origine = 'ncip' AND attivo = 0 AND stato = 'pendente'
                        AND copia_id IS NULL"
                );
                if ($update === false) {
                    throw new \RuntimeException('loan cancel prepare failed: ' . $this->db->error);
                }
                $update->bind_param('iii', $loanId, $bookId, $resolvedUserId);
                $update->execute();
                $affected = $update->affected_rows;
                $update->close();
            } else {
                $lockedReservation = $lockedReservations[0];
                $reservationId = (int) $lockedReservation['id'];
                $resolvedUserId = (int) $lockedReservation['utente_id'];

                $update = $this->db->prepare(
                    "UPDATE prenotazioni
                        SET stato = 'annullata', updated_at = NOW()
                      WHERE id = ? AND libro_id = ? AND utente_id = ?
                        AND stato = 'attiva'"
                );
                if ($update === false) {
                    throw new \RuntimeException('reservation cancel prepare failed: ' . $this->db->error);
                }
                $update->bind_param('iii', $reservationId, $bookId, $resolvedUserId);
                $update->execute();
                $affected = $update->affected_rows;
                $update->close();

                if ($affected === 1) {
                    // Preserve dense FIFO positions, then immediately use any
                    // capacity freed by the cancellation just like the web and
                    // admin cancellation paths.
                    $reorder = $this->db->prepare(
                        "SELECT id FROM prenotazioni
                          WHERE libro_id = ? AND stato = 'attiva'
                          ORDER BY queue_position ASC, id ASC
                          FOR UPDATE"
                    );
                    $reorder->bind_param('i', $bookId);
                    $reorder->execute();
                    $rows = $reorder->get_result();

                    // Two-phase move (stesso pattern di ReservationManager):
                    // prima le righe attive vanno su posizioni temporanee fuori
                    // intervallo, poi si assegnano le posizioni finali — il
                    // trigger BEFORE UPDATE rifiuta i duplicati riga per riga
                    // durante il riordino.
                    // Offset DINAMICO: un offset fisso può collidere con
                    // posizioni residue già oltre l'offset; deve superare sia
                    // il MAX attivo sia l'ultima posizione finale assegnata
                    // (righe NULL incluse in N).
                    $maxStmt = $this->db->prepare(
                        "SELECT COALESCE(MAX(queue_position), 0) FROM prenotazioni WHERE libro_id = ? AND stato = 'attiva'"
                    );
                    $maxStmt->bind_param('i', $bookId);
                    $maxStmt->execute();
                    $offset = max((int) $maxStmt->get_result()->fetch_row()[0], (int) $rows->num_rows);
                    $maxStmt->close();
                    if ($offset > 0) {
                        $shiftStmt = $this->db->prepare(
                            "UPDATE prenotazioni
                                SET queue_position = queue_position + ?
                              WHERE libro_id = ? AND stato = 'attiva' AND queue_position IS NOT NULL"
                        );
                        $shiftStmt->bind_param('ii', $offset, $bookId);
                        $shiftStmt->execute();
                        $shiftStmt->close();
                    }

                    $position = 1;
                    $setPosition = $this->db->prepare(
                        'UPDATE prenotazioni SET queue_position = ? WHERE id = ?'
                    );
                    while ($row = $rows->fetch_assoc()) {
                        $rowId = (int) $row['id'];
                        $setPosition->bind_param('ii', $position, $rowId);
                        $setPosition->execute();
                        $position++;
                    }
                    $setPosition->close();
                    $reorder->close();

                    $reservationManager = new \App\Controllers\ReservationManager($this->db);
                    $reservationManager->setExternalTransaction(true);
                    for ($guard = 0; $guard < 1000 && $reservationManager->processBookAvailability($bookId); $guard++) {
                        // Promote until the newly-freed capacity is exhausted.
                    }

                    $integrity = new \App\Support\DataIntegrity($this->db);
                    if (!$integrity->recalculateBookAvailability($bookId, insideTransaction: true)) {
                        throw new \RuntimeException('Failed to recalculate availability after NCIP reservation cancellation.');
                    }
                }
            }

            if ($affected !== 1) {
                $this->db->rollback();
                $inTransaction = false;
                return ['status' => 'not_found'];
            }

            if (!$this->insertTransactionRecord('CancelRequestItem', $loanId, null, $reservationId)) {
                throw new \RuntimeException('Failed to audit NCIP request cancellation.');
            }

            $this->db->commit();
            $inTransaction = false;

            if ($reservationManager !== null) {
                try {
                    $reservationManager->flushDeferredNotifications();
                } catch (\Throwable $notificationError) {
                    SecureLogger::warning('[NcipServer] deferred reservation notification failed', [
                        'book_id' => $bookId,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }

            return [
                'status' => 'cancelled',
                'loan_id' => $loanId,
                'reservation_id' => $reservationId,
                'user_id' => $resolvedUserId,
            ];
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $this->db->rollback();
                } catch (\Throwable) {
                    // Preserve the original database failure.
                }
            }
            throw new \RuntimeException(
                '[NcipServer] ' . __FUNCTION__ . ' failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function logTransaction(
        string $messageType,
        ?int $prestitoId,
        ?string $requestId,
        ?int $reservationId = null
    ): void
    {
        $this->insertTransactionRecord($messageType, $prestitoId, $requestId, $reservationId);
    }

    private function insertTransactionRecord(
        string $messageType,
        ?int $prestitoId,
        ?string $requestId,
        ?int $reservationId
    ): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ncip_transactions
                (partner_id, message_type, prestito_id, prenotazione_id, request_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'success', NOW())"
        );
        if ($stmt === false) {
            return false;
        }
        $partnerId = $this->currentPartnerId;
        $stmt->bind_param('isiis', $partnerId, $messageType, $prestitoId, $reservationId, $requestId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Risolve il partner ATTIVO dichiarato nel FromAgencyId dell'InitiationHeader
     * del messaggio corrente, per agency_id, code o ISIL. Null se il messaggio
     * non dichiara un'agenzia o nessun partner attivo corrisponde.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePartner(\SimpleXMLElement $xml, string $messageType): ?array
    {
        if ($messageType === '') {
            return null;
        }
        $message = $this->messageNode($xml, $messageType);
        if ($message === null) {
            return null;
        }
        $agency = trim((string) ($message->InitiationHeader->FromAgencyId->AgencyId ?? ''));
        if ($agency === '' || strlen($agency) > 255) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, name, agency_id, code, isil
               FROM ncip_partners
              WHERE active = 1 AND (agency_id = ? OR code = ? OR isil = ?)
              LIMIT 2'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('sss', $agency, $agency, $agency);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) > 1) {
            // Lo stesso identificativo corrisponde a più partner attivi: la
            // risoluzione e il partner_id loggato sarebbero arbitrari. I partner
            // sono metadata opzionali (l'autorità resta la Basic auth staff),
            // quindi registra NULL e lascia l'ambiguità da sanare in
            // configurazione senza attribuire l'operazione al partner sbagliato.
            SecureLogger::warning('[NcipServer] Ambiguous FromAgencyId: multiple active partners match the same identifier; treating as unresolved');
            return null;
        }
        return $rows[0] ?? null;
    }

    private function closeLoan(int $loanId, int $expectedBookId, int $expectedUserId): bool
    {
        try {
            $closed = (new \App\Models\LoanRepository($this->db))->close(
                $loanId,
                $expectedBookId,
                $expectedUserId
            );
        } catch (\Throwable $e) {
            SecureLogger::error('[NcipServer] closeLoan failed: ' . $e->getMessage());
            return false;
        }
        if (!$closed) {
            return false;
        }
        try {
            (new \App\Support\NotificationService($this->db))->sendLoanReturnedNotification($loanId);
        } catch (\Throwable $e) {
            // Il prestito È chiuso: un errore di notifica non deve trasformare
            // un check-in riuscito in un false (che farebbe imboccare al
            // chiamante il ramo idempotente saltando logTransaction()).
            SecureLogger::error('[NcipServer] return notification failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * True when the loan has already been returned (not active). Keeps NCIP
     * CheckInItem idempotent: a concurrent/replayed check-in whose loan is
     * already 'restituito' is a success, not a temporary-processing-failure.
     */
    private function isLoanReturned(int $loanId, int $expectedBookId, int $expectedUserId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT attivo, stato FROM prestiti
              WHERE id = ? AND libro_id = ? AND utente_id = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $loanId, $expectedBookId, $expectedUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return false;
        }
        // Only a genuine return counts. attivo=0 alone is too broad: a concurrent
        // CancelRequestItem sets stato='annullato', attivo=0, and treating that as
        // "returned" would answer a CheckInItem with success for an item that was
        // never checked in. LoanRepository::close() sets stato='restituito'.
        return $row['stato'] === 'restituito';
    }

    /**
     * Renew a loan, returning the new due date or null on failure. $failureReason
     * distinguishes permanent rejections (the caller maps them to a stable NCIP
     * ProblemType) from transient DB errors (retryable). Defaults to the
     * transient case; each guard sets its own reason before returning.
     *
     * @param-out string $failureReason
     */
    private function extendLoan(
        int $loanId,
        int $expectedBookId,
        int $expectedUserId,
        ?string &$failureReason = null
    ): ?string
    {
        $failureReason = 'db_error';
        if ($expectedBookId <= 0 || $expectedUserId <= 0) {
            $failureReason = 'identity_changed';
            return null;
        }

        $this->db->begin_transaction();
        try {
            $book = $this->db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $book->bind_param('i', $expectedBookId);
            $book->execute();
            $bookExists = (bool) $book->get_result()->fetch_row();
            $book->close();
            if (!$bookExists) {
                $this->db->rollback();
                $failureReason = 'not_found';
                return null;
            }

            $loanStmt = $this->db->prepare(
                "SELECT libro_id, utente_id, copia_id, data_scadenza, stato, attivo, renewals, origine
                 FROM prestiti WHERE id = ? FOR UPDATE"
            );
            $loanStmt->bind_param('i', $loanId);
            $loanStmt->execute();
            $loan = $loanStmt->get_result()->fetch_assoc();
            $loanStmt->close();
            if (!$loan
                || (int) $loan['libro_id'] !== $expectedBookId
                || (int) $loan['utente_id'] !== $expectedUserId
                || $loan['origine'] !== 'ncip') {
                $this->db->rollback();
                $failureReason = 'identity_changed';
                return null;
            }
            if ((int) $loan['attivo'] !== 1 || $loan['stato'] !== 'in_corso') {
                $this->db->rollback();
                $failureReason = 'ineligible_state';
                return null;
            }

            $userLock = $this->db->prepare('SELECT id FROM utenti WHERE id = ? FOR UPDATE');
            $userLock->bind_param('i', $expectedUserId);
            $userLock->execute();
            $userLock->close();
            if (\App\Support\LoanEligibility::checkUser($this->db, $expectedUserId) !== null) {
                $this->db->rollback();
                $failureReason = 'user_ineligible';
                return null;
            }

            $settings = new \App\Models\SettingsRepository($this->db);
            $maxRenewals = (int) ($settings->get('loans', 'max_renewals', '3') ?? 3);
            $maxRenewals = $maxRenewals >= 0 ? $maxRenewals : 3;
            if ((int) $loan['renewals'] >= $maxRenewals) {
                $this->db->rollback();
                $failureReason = 'max_renewals';
                return null;
            }
            $renewDays = (int) ($settings->get('loans', 'loan_duration_days', '30') ?? 30);
            $renewDays = $renewDays > 0 ? $renewDays : 30;
            $currentDue = (string) $loan['data_scadenza'];
            $newDueDate = (new \DateTimeImmutable($currentDue))->modify("+{$renewDays} days")->format('Y-m-d');

            // #336 parity con PrestitiController::renew/bulkExtend: il giorno di
            // scadenza corrente è già detenuto da QUESTO prestito, quindi la
            // finestra rivendicata parte dal giorno successivo. Con la finestra
            // [currentDue, newDue] un RenewItem falliva dove il rinnovo web
            // identico riusciva (giorno di confine contato due volte).
            $extensionStart = (new \DateTimeImmutable($currentDue))->modify('+1 day')->format('Y-m-d');

            $capacity = new \App\Services\CapacityService($this->db);
            if (!$capacity->hasFreeCapacity($expectedBookId, $extensionStart, $newDueDate, excludePrestitoId: $loanId)) {
                $this->db->rollback();
                $failureReason = 'no_capacity';
                return null;
            }

            $copyId = $loan['copia_id'] !== null ? (int) $loan['copia_id'] : null;
            if ($copyId !== null) {
                $applicationToday = \App\Support\DateHelper::today();
                $overlap = $this->db->prepare(
                    "SELECT 1 FROM prestiti
                     WHERE copia_id = ? AND id <> ?
                       AND data_prestito <= ?
                       AND (stato = 'in_ritardo'
                            OR (stato = 'in_corso' AND data_scadenza < ?)
                            OR data_scadenza >= ?)
                       AND ((attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                            OR (attivo = 0 AND stato = 'pendente' AND copia_id IS NOT NULL))
                     LIMIT 1"
                );
                $overlap->bind_param('iisss', $copyId, $loanId, $newDueDate, $applicationToday, $extensionStart);
                $overlap->execute();
                $hasOverlap = (bool) $overlap->get_result()->fetch_row();
                $overlap->close();
                if ($hasOverlap) {
                    $this->db->rollback();
                    $failureReason = 'overlap';
                    return null;
                }
            }

            $renewals = (int) $loan['renewals'] + 1;
            $update = $this->db->prepare(
                'UPDATE prestiti
                 SET data_scadenza = ?, renewals = ?, pickup_deadline = NULL,
                     warning_sent = 0, overdue_notification_sent = 0, updated_at = NOW()
                 WHERE id = ?'
            );
            $update->bind_param('sii', $newDueDate, $renewals, $loanId);
            if (!$update->execute()) {
                $error = $update->error;
                $update->close();
                throw new \RuntimeException($error);
            }
            $update->close();
            $this->db->commit();
            return $newDueDate;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[NcipServer] extendLoan failed: ' . $e->getMessage());
            return null;
        }
    }

    // ─── Auth helpers ─────────────────────────────────────────────────────────

    /**
     * Authenticate from HTTP Basic auth. Returns user array or null.
     *
     * @return array<string, mixed>|null
     */
    private function authenticate(ServerRequestInterface $request): ?array
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$email, $password] = explode(':', $decoded, 2);

        if ($email === '' || $password === '') {
            return null;
        }

        $server = $request->getServerParams();
        $remoteAddr = is_string($server['REMOTE_ADDR'] ?? null) ? (string) $server['REMOTE_ADDR'] : 'unknown';
        $rateKey = 'ncip_basic:' . $remoteAddr . ':' . strtolower($email);
        if (RateLimiter::isLimited($rateKey, 10, 900)) {
            SecureLogger::warning('[NCIP] Basic auth rate limit exceeded', [
                'remote_addr' => $remoteAddr,
                'email_hash'  => hash('sha256', strtolower($email)),
            ]);
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id, nome, email, password, tipo_utente
               FROM utenti
              WHERE email = ? AND stato = 'attivo' LIMIT 1"
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!($res instanceof \mysqli_result)) {
            $stmt->close();
            return null;
        }
        $user = $res->fetch_assoc();
        $stmt->close();

        if (!is_array($user)) { return null; }
        if (!password_verify($password, (string) ($user['password'] ?? ''))) {
            return null;
        }

        RateLimiter::reset($rateKey);

        return $user;
    }

    private function parseNcipNumericId(string $value): ?int
    {
        $trimmed = trim($value);
        $id = ctype_digit($trimmed) ? (int) $trimmed : 0;
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string, mixed>|null $caller
     */
    private function isStaff(?array $caller): bool
    {
        if ($caller === null) { return false; }
        $role = (string) ($caller['tipo_utente'] ?? '');
        return in_array($role, ['admin', 'staff'], true);
    }

    // ─── XML utilities ────────────────────────────────────────────────────────

    private function detectMessageType(\SimpleXMLElement $xml): string
    {
        $ns = self::NCIP_NS;
        // Try NCIP namespace children
        foreach ($xml->children($ns) as $name => $child) {
            return (string) $name;
        }
        // Try no-namespace children (some implementations omit ns prefix)
        foreach ($xml->children() as $name => $child) {
            return (string) $name;
        }
        return 'Unknown';
    }

    /**
     * Resolve the request element for both standards-compliant NCIP payloads
     * and legacy payloads that omit the default NCIP namespace. Keeping this
     * lookup centralized prevents partner attribution and field extraction
     * from disagreeing about which message was received.
     */
    private function messageNode(\SimpleXMLElement $xml, string $messageType): ?\SimpleXMLElement
    {
        if ($messageType === '' || $messageType === 'Unknown') {
            return null;
        }

        $message = $xml->children(self::NCIP_NS)->{$messageType} ?? null;
        if ($message instanceof \SimpleXMLElement && $message->count() > 0) {
            return $message;
        }

        $message = $xml->children()->{$messageType} ?? null;
        return $message instanceof \SimpleXMLElement ? $message : null;
    }

    private function newXmlWriter(): \XMLWriter
    {
        $xw = new \XMLWriter();
        $xw->openMemory();
        $xw->setIndent(true);
        $xw->setIndentString('  ');
        $xw->startDocument('1.0', 'UTF-8');
        return $xw;
    }

    private function xmlResponse(ResponseInterface $response, string $xml): ResponseInterface
    {
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
}
