<?php
/**
 * Z39.50/SRU Server Endpoint
 *
 * Public endpoint for SRU protocol requests.
 * This file handles incoming SRU requests and returns XML responses.
 *
 * Usage:
 * GET /api/sru?operation=explain
 * GET /api/sru?operation=searchRetrieve&query=dc.title=shakespeare&maximumRecords=10
 * GET /api/sru?operation=scan&scanClause=dc.title
 */

declare(strict_types=1);

// This file is loaded by the router, so the autoloader is already available
// and the database connection is provided via dependency injection

require_once __DIR__ . '/classes/SRUServer.php';
require_once __DIR__ . '/classes/CQLParser.php';
require_once __DIR__ . '/classes/RecordFormatter.php';
require_once __DIR__ . '/classes/MARCXMLFormatter.php';
require_once __DIR__ . '/classes/DublinCoreFormatter.php';
require_once __DIR__ . '/classes/MODSFormatter.php';
require_once __DIR__ . '/classes/RateLimiter.php';

use Z39Server\SRUServer;
use Z39Server\RateLimiter;

/**
 * Handle SRU request
 *
 * @param \Psr\Http\Message\ServerRequestInterface $request
 * @param \Psr\Http\Message\ResponseInterface $response
 * @param mysqli $db Database connection
 * @param int|null $pluginId Plugin ID
 * @return \Psr\Http\Message\ResponseInterface
 */
function handleSRURequest(
    \Psr\Http\Message\ServerRequestInterface $request,
    \Psr\Http\Message\ResponseInterface $response,
    mysqli $db,
    ?int $pluginId = null
): \Psr\Http\Message\ResponseInterface {

    // Get plugin settings
    $settings = getPluginSettings($db, $pluginId);

    // Check if server is enabled
    if (($settings['server_enabled'] ?? 'false') !== 'true') {
        $response->getBody()->write(createErrorXML('Server is currently disabled'));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withStatus(503);
    }

    // Rate limiting (OWASP: Protection against DoS)
    if (($settings['rate_limit_enabled'] ?? 'false') === 'true') {
        $rateLimiter = new RateLimiter(
            $db,
            (int)($settings['rate_limit_requests'] ?? 100),
            (int)($settings['rate_limit_window'] ?? 3600)
        );

        $clientIp = getClientIp();

        if (!$rateLimiter->checkLimit($clientIp)) {
            $response->getBody()->write(createErrorXML('Rate limit exceeded. Please try again later.'));
            return $response
                ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
                ->withHeader('Retry-After', '3600')
                ->withStatus(429);
        }
    }

    // Get query parameters
    $params = $request->getQueryParams();

    // Initialize SRU server
    $sruServer = new SRUServer($db, $settings, $pluginId);

    // Handle request and get XML response
    $xmlResponse = $sruServer->handleRequest($params);

    // Write response
    $response->getBody()->write($xmlResponse);

    return $response
        ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->withHeader('Access-Control-Allow-Origin', '*') // Allow CORS for library systems
        ->withHeader('Access-Control-Allow-Methods', 'GET')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
}

/**
 * Get plugin settings
 *
 * @param mysqli $db Database connection
 * @param int|null $pluginId Plugin ID
 * @return array Settings array
 */
function getPluginSettings(mysqli $db, ?int $pluginId): array
{
    if ($pluginId === null) {
        // Try to get plugin ID from database
        $result = $db->query("SELECT id FROM plugins WHERE name = 'z39-server' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $pluginId = (int)$row['id'];
            $result->free();
        } else {
            return [];
        }
    }

    $stmt = $db->prepare("
        SELECT setting_key, setting_value
        FROM plugin_settings
        WHERE plugin_id = ?
    ");

    $stmt->bind_param('i', $pluginId);
    $stmt->execute();
    $result = $stmt->get_result();

    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $stmt->close();
    return $settings;
}

/**
 * Get client IP address
 *
 * @return string Client IP
 */
function getClientIp(): string
{
    // Check for proxies and load balancers (OWASP: Security consideration)
    $ipHeaders = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($ipHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // If X-Forwarded-For contains multiple IPs, take the first one
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return 'unknown';
}

/**
 * Create simple error XML response
 *
 * @param string $message Error message
 * @return string XML error response
 */
function createErrorXML(string $message): string
{
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;

    $root = $xml->createElement('error');
    $xml->appendChild($root);

    $messageEl = $xml->createElement('message', htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    $root->appendChild($messageEl);

    return $xml->saveXML();
}
