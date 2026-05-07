<?php

declare(strict_types=1);

namespace App\Plugins\NcipServer;

use App\Support\HookManager;
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

    /** @phpstan-ignore property.onlyWritten */
    private HookManager $hookManager;
    private \mysqli $db;
    private ?int $pluginId = null;

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
        $this->ensureSchema();
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
        $this->ensureSchema();
    }

    public function onUninstall(): void {}

    // ─── Schema ───────────────────────────────────────────────────────────────

    private function ensureSchema(): void
    {
        if (!$this->db->query(
            "CREATE TABLE IF NOT EXISTS ncip_partners (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        )) {
            throw new \RuntimeException('[NcipServer] Cannot create ncip_partners: ' . $this->db->error);
        }

        if (!$this->db->query(
            "CREATE TABLE IF NOT EXISTS ncip_transactions (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                partner_id   INT          NULL,
                message_type VARCHAR(64)  NOT NULL,
                prestito_id  INT          NULL,
                request_id   VARCHAR(255) NULL,
                status       ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
                error_msg    VARCHAR(1000) NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_partner  (partner_id),
                KEY idx_status   (status),
                KEY idx_prestito (prestito_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        )) {
            throw new \RuntimeException('[NcipServer] Cannot create ncip_transactions: ' . $this->db->error);
        }

        $cols = $this->getExistingColumns('prestiti');
        if (!isset($cols['ncip_request_id'])) {
            if (!$this->db->query(
                "ALTER TABLE prestiti ADD COLUMN ncip_request_id VARCHAR(255) NULL DEFAULT NULL AFTER origine"
            )) {
                throw new \RuntimeException('[NcipServer] Cannot alter prestiti (ncip_request_id): ' . $this->db->error);
            }
        }

        $res = $this->db->query("SHOW COLUMNS FROM prestiti LIKE 'origine'");
        if ($res instanceof \mysqli_result) {
            $col = $res->fetch_assoc();
            $res->free();
            if (is_array($col) && is_string($col['Type'] ?? null) && !str_contains((string) $col['Type'], "'ncip'")) {
                if (!$this->db->query(
                    "ALTER TABLE prestiti MODIFY COLUMN origine ENUM('richiesta','prenotazione','diretto','ncip') COLLATE utf8mb4_unicode_ci DEFAULT 'richiesta'"
                )) {
                    throw new \RuntimeException('[NcipServer] Cannot alter prestiti (origine): ' . $this->db->error);
                }
            }
        }
    }

    /** @return array<string, true> */
    private function getExistingColumns(string $table): array
    {
        $res = $this->db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->db->real_escape_string($table) . "'"
        );
        if (!($res instanceof \mysqli_result)) { return []; }
        $cols = [];
        while ($row = $res->fetch_assoc()) {
            $cols[(string) $row['COLUMN_NAME']] = true;
        }
        $res->free();
        return $cols;
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
        $callbackClass = 'NcipServerPlugin';
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
        if (!$this->requireAdmin()) {
            return $response->withStatus(403)->withHeader('Location', url('/admin/login'));
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
        if (!$this->requireAdmin()) {
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
        if (!$this->requireAdmin()) {
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
        if (!$this->requireAdmin()) {
            return $response->withStatus(403)->withHeader('Location', url('/admin/login'));
        }
        $params  = $request->getQueryParams();
        $perPage = 50;
        $page    = max(1, (int) ($params['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $countRes = $this->db->query('SELECT COUNT(*) AS c FROM ncip_transactions');
        $total    = ($countRes instanceof \mysqli_result) ? (int) ($countRes->fetch_assoc()['c'] ?? 0) : 0;

        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT id, message_type, partner_id, prestito_id, request_id, status, created_at
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

    private function requireAdmin(): bool
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
        $body = (string) $request->getBody();
        if (trim($body) === '') {
            return $this->xmlResponse(
                $response->withStatus(400),
                $this->buildProblem('Empty request body', 'empty-request')
            );
        }

        // Parse incoming NCIP XML
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOERROR);
        if ($xml === false) {
            return $this->xmlResponse(
                $response->withStatus(400),
                $this->buildProblem('Malformed XML', 'invalid-xml')
            );
        }

        // Authenticate caller from HTTP Basic auth
        $caller = $this->authenticate($request);

        // Determine the message type (first child element after NCIPMessage root)
        $messageType = $this->detectMessageType($xml);

        return match ($messageType) {
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
    }

    // ─── Message handlers ─────────────────────────────────────────────────────

    private function handleLookupItem(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \SimpleXMLElement $xml
    ): ResponseInterface {
        // Extract ItemIdentifierValue
        $ns     = self::NCIP_NS;
        $itemId = (string) ($xml->children($ns)->LookupItem->ItemId->ItemIdentifierValue ?? '');
        if ($itemId === '') {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemIdentifierValue', 'unknown-item')
            );
        }

        $bookId = (int) $itemId;
        $book   = $this->fetchBook($bookId);
        if ($book === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("Item '{$itemId}' not found", 'unknown-item')
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
                $response->withStatus(401),
                $this->buildProblem('Authentication required', 'unauthorized')
            );
        }

        $ns     = self::NCIP_NS;
        $userId = (string) ($xml->children($ns)->LookupUser->UserId->UserIdentifierValue ?? '');
        $user   = $this->fetchUser($userId !== '' ? (int) $userId : 0);
        if ($user === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem("User '{$userId}' not found", 'unknown-user')
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

        $ns     = self::NCIP_NS;
        $itemId = (int) ($xml->children($ns)->CheckOutItem->ItemId->ItemIdentifierValue ?? 0);
        $userId = (int) ($xml->children($ns)->CheckOutItem->UserId->UserIdentifierValue ?? 0);

        if ($itemId <= 0 || $userId <= 0) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemId or UserId', 'invalid-data')
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
        $dueDate = date('Y-m-d', strtotime('+30 days'));
        $loanId  = $this->createLoanAtomic($itemId, $userId, $dueDate);

        if ($loanId === null) {
            // Re-read availability to distinguish "no copies" from "DB error"
            $book = $this->fetchBook($itemId);
            if ($book === null) {
                return $this->xmlResponse(
                    $response,
                    $this->buildProblem('Item not found', 'unknown-item')
                );
            }
            if ((int) ($book['copie_disponibili'] ?? 0) <= 0) {
                return $this->xmlResponse(
                    $response,
                    $this->buildProblem('No copies available', 'item-not-checked-in')
                );
            }
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to create loan', 'temporary-processing-failure')
            );
        }

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

        $ns     = self::NCIP_NS;
        $itemId = (int) ($xml->children($ns)->CheckInItem->ItemId->ItemIdentifierValue ?? 0);
        if ($itemId <= 0) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemId', 'invalid-data')
            );
        }

        $loan = $this->findActiveLoan($itemId);
        if ($loan === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active loan for this item', 'item-not-checked-out')
            );
        }

        $this->closeLoan((int) $loan['id'], $itemId);
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

        $ns     = self::NCIP_NS;
        $itemId = (int) ($xml->children($ns)->RenewItem->ItemId->ItemIdentifierValue ?? 0);
        if ($itemId <= 0) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemId', 'invalid-data')
            );
        }

        $loan = $this->findActiveLoan($itemId);
        if ($loan === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active loan to renew', 'item-not-checked-out')
            );
        }

        // Extend by 30 days from current due_date (or today if past due)
        $currentDue  = (string) ($loan['data_scadenza'] ?? date('Y-m-d'));
        $baseDateTs  = max(strtotime($currentDue) ?: time(), time());
        $newDue      = date('Y-m-d', strtotime('+30 days', $baseDateTs));
        $this->extendLoan((int) $loan['id'], $newDue);

        return $this->xmlResponse($response, $this->buildRenewItemResponse($itemId, $newDue, (int) ($loan['utente_id'] ?? 0)));
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

        $ns     = self::NCIP_NS;
        $itemId = (int) ($xml->children($ns)->RequestItem->ItemId->ItemIdentifierValue ?? 0);
        $userId = (int) ($xml->children($ns)->RequestItem->UserId->UserIdentifierValue ?? 0);

        if ($itemId <= 0 || $userId <= 0) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemId or UserId', 'invalid-data')
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

        $requestId = (string) ($xml->children($ns)->RequestItem->RequestId->RequestIdentifierValue ?? '');
        $dueDate   = date('Y-m-d', strtotime('+30 days'));
        $loanId    = $this->createLoanNcip($itemId, $userId, $dueDate, $requestId !== '' ? $requestId : null);
        if ($loanId === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Failed to create ILL request', 'temporary-processing-failure')
            );
        }

        $this->logTransaction('RequestItem', $loanId, $requestId !== '' ? $requestId : null);

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

        $ns     = self::NCIP_NS;
        $itemId = (int) ($xml->children($ns)->CancelRequestItem->ItemId->ItemIdentifierValue ?? 0);
        $userId = (int) ($xml->children($ns)->CancelRequestItem->UserId->UserIdentifierValue ?? 0);

        if ($itemId <= 0) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('Missing ItemId', 'invalid-data')
            );
        }

        $loan = $this->findNcipLoan($itemId, $userId > 0 ? $userId : null);
        if ($loan === null) {
            return $this->xmlResponse(
                $response,
                $this->buildProblem('No active ILL request for this item', 'item-not-checked-out')
            );
        }

        $this->cancelLoan((int) $loan['id']);
        $this->logTransaction('CancelRequestItem', (int) $loan['id'], null);

        return $this->xmlResponse($response, $this->buildCancelRequestItemResponse($itemId, $userId > 0 ? $userId : null));
    }

    // ─── XML builders ─────────────────────────────────────────────────────────

    private function buildCapabilityXml(): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

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

        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('LookupItemResponse');

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
        $xw->text($avail > 0 ? 'Available On Shelf' : 'Checked Out');
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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('LookupUserResponse');

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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('CheckOutItemResponse');
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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('CheckInItemResponse');
        $xw->startElement('ItemId');
        $xw->startElement('ItemIdentifierType');
        $xw->writeAttributeNs('ncip', 'Scheme', self::NCIP_NS, 'http://www.niso.org/ncip/v2_02/schemes/itemidentifiertype/');
        $xw->text('Accession Number');
        $xw->endElement();
        $xw->writeElement('ItemIdentifierValue', (string) $itemId);
        $xw->endElement();
        $xw->startElement('Ext');
        $xw->writeElement('DateReturned', gmdate('Y-m-d\TH:i:s\Z'));
        $xw->endElement();
        $xw->endElement(); // CheckInItemResponse

        $xw->endElement(); // NCIPMessage
        $xw->endDocument();
        return (string) $xw->outputMemory();
    }

    private function buildRenewItemResponse(int $itemId, string $newDueDate, int $userId): string
    {
        $xw = $this->newXmlWriter();
        $xw->startElementNs(null, 'NCIPMessage', self::NCIP_NS);
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('RenewItemResponse');
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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('RequestItemResponse');
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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

        $xw->startElement('CancelRequestItemResponse');
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
        $xw->writeAttributeNs('ncip', 'version', self::NCIP_NS, self::NCIP_VERSION);

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
            'SELECT l.id, l.titolo, l.copie_totali, l.copie_disponibili,
                    l.anno_pubblicazione, a.nome AS author_name
               FROM libri l
               LEFT JOIN autori a ON a.id = (
                   SELECT la2.autore_id FROM libri_autori la2 WHERE la2.libro_id = l.id
                   ORDER BY COALESCE(la2.ordine_credito, 0), la2.autore_id LIMIT 1
               )
              WHERE l.id = ? AND l.deleted_at IS NULL'
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
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
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveLoan(int $bookId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, libro_id, utente_id, data_scadenza
               FROM prestiti
              WHERE libro_id = ? AND stato IN ('in_corso','in_ritardo')
              ORDER BY data_prestito DESC LIMIT 1"
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * Atomic checkout: locks the book row to prevent double-booking under concurrent requests.
     * Returns the new loan ID, or null if no copies are available or an error occurred.
     */
    private function createLoanAtomic(int $bookId, int $userId, string $dueDate): ?int
    {
        $this->db->begin_transaction();
        try {
            // Lock the row exclusively so concurrent CheckOutItem requests serialize.
            $lock = $this->db->prepare(
                'SELECT copie_disponibili FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
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

            if ($row === null || (int) ($row['copie_disponibili'] ?? 0) <= 0) {
                $this->db->rollback();
                return null;
            }

            $today = date('Y-m-d');
            $ins   = $this->db->prepare(
                "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'in_corso', 'diretto', NOW(), NOW())"
            );
            if ($ins === false) { $this->db->rollback(); return null; }
            $ins->bind_param('iiss', $bookId, $userId, $today, $dueDate);
            if (!$ins->execute()) { $ins->close(); $this->db->rollback(); return null; }
            $loanId = $ins->insert_id;
            $ins->close();

            $upd = $this->db->prepare(
                'UPDATE libri SET copie_disponibili = GREATEST(0, copie_disponibili - 1) WHERE id = ?'
            );
            if ($upd === false) { $this->db->rollback(); return null; }
            $upd->bind_param('i', $bookId);
            $upd->execute();
            $upd->close();

            $this->db->commit();
            return $loanId > 0 ? $loanId : null;
        } catch (\Throwable $e) {
            $this->db->rollback();
            SecureLogger::error('[NcipServer] createLoanAtomic failed: ' . $e->getMessage());
            return null;
        }
    }

    private function createLoanNcip(int $bookId, int $userId, string $dueDate, ?string $requestId): ?int
    {
        $today = date('Y-m-d');
        $stmt  = $this->db->prepare(
            "INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, ncip_request_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'pendente', 'ncip', ?, NOW(), NOW())"
        );
        if ($stmt === false) { return null; }
        $stmt->bind_param('iisss', $bookId, $userId, $today, $dueDate, $requestId);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $id = $stmt->insert_id;
        $stmt->close();
        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findNcipLoan(int $bookId, ?int $userId): ?array
    {
        $sql  = "SELECT id, libro_id, utente_id FROM prestiti
                  WHERE libro_id = ? AND origine = 'ncip' AND stato IN ('pendente','da_ritirare','in_corso')";
        $types = 'i';
        $params = [$bookId];
        if ($userId !== null) {
            $sql  .= ' AND utente_id = ?';
            $types .= 'i';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) { return null; }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    private function cancelLoan(int $loanId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE prestiti SET stato = 'annullato', updated_at = NOW() WHERE id = ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function logTransaction(string $messageType, int $prestitoId, ?string $requestId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO ncip_transactions (message_type, prestito_id, request_id, status, created_at)
             VALUES (?, ?, ?, 'success', NOW())"
        );
        if ($stmt === false) { return; }
        $stmt->bind_param('sis', $messageType, $prestitoId, $requestId);
        $stmt->execute();
        $stmt->close();
    }

    private function closeLoan(int $loanId, int $bookId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE prestiti SET stato = 'restituito', data_restituzione = CURDATE(), updated_at = NOW()
              WHERE id = ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $loanId);
            $stmt->execute();
            $stmt->close();
        }

        // Restore available copies
        $upd = $this->db->prepare(
            'UPDATE libri SET copie_disponibili = LEAST(copie_totali, copie_disponibili + 1) WHERE id = ?'
        );
        if ($upd !== false) {
            $upd->bind_param('i', $bookId);
            $upd->execute();
            $upd->close();
        }
    }

    private function extendLoan(int $loanId, string $newDueDate): void
    {
        $stmt = $this->db->prepare(
            'UPDATE prestiti SET data_scadenza = ?, updated_at = NOW() WHERE id = ?'
        );
        if ($stmt !== false) {
            $stmt->bind_param('si', $newDueDate, $loanId);
            $stmt->execute();
            $stmt->close();
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
        $email    = trim($email);
        $password = trim($password);

        if ($email === '' || $password === '') {
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
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($user)) { return null; }
        if (!password_verify($password, (string) ($user['password'] ?? ''))) {
            return null;
        }

        return $user;
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
