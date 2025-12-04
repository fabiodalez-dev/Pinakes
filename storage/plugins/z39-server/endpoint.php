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

// Load exception classes first (required by CQLParser and SRUServer)
require_once __DIR__ . '/classes/Exceptions/CQLException.php';
require_once __DIR__ . '/classes/Exceptions/InvalidCQLSyntaxException.php';
require_once __DIR__ . '/classes/Exceptions/UnsupportedRelationException.php';
require_once __DIR__ . '/classes/Exceptions/UnsupportedIndexException.php';
require_once __DIR__ . '/classes/Exceptions/DatabaseException.php';
require_once __DIR__ . '/classes/Exceptions/SRUQueryException.php';

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

    // Helper to check boolean settings (accepts '1', 'true')
    $isEnabled = fn($key) => in_array($settings[$key] ?? '', ['1', 'true'], true);

    // Check if server is enabled (supports both 'enable_server' and legacy 'server_enabled')
    if (!$isEnabled('enable_server') && !$isEnabled('server_enabled')) {
        $response->getBody()->write(createErrorXML('Server is currently disabled'));
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withStatus(503);
    }

    // Optional API key authentication
    if ($isEnabled('require_api_key')) {
        $apiKey = $settings['api_key'] ?? '';
        $providedKey = $request->getHeaderLine('X-API-Key')
            ?: ($request->getQueryParams()['api_key'] ?? '');

        // Deny access if API key is required but not configured, or if provided key doesn't match
        if ($apiKey === '' || !hash_equals($apiKey, $providedKey)) {
            $response->getBody()->write(createErrorXML('Invalid or missing API key'));
            return $response
                ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
                ->withHeader('WWW-Authenticate', 'API-Key')
                ->withStatus(401);
        }
    }

    // Rate limiting (OWASP: Protection against DoS)
    if ($isEnabled('rate_limit_enabled')) {
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

    // SECURITY FIX: Add comprehensive security headers
    return $response
        ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->withHeader('Access-Control-Allow-Origin', '*') // Allow CORS for library systems (SRU standard)
        ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-Key')
        ->withHeader('Access-Control-Max-Age', '86400') // Cache preflight for 24h
        ->withHeader('X-Content-Type-Options', 'nosniff') // Prevent MIME sniffing
        ->withHeader('X-Frame-Options', 'DENY') // Prevent clickjacking
        ->withHeader('X-XSS-Protection', '1; mode=block') // Enable XSS filter
        ->withHeader('Referrer-Policy', 'no-referrer') // Don't leak referrer
        ->withHeader('Content-Security-Policy', "default-src 'none'"); // CSP for XML responses
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
        // Try to get plugin ID from database (use prepared statement for consistency)
        $pluginName = 'z39-server';
        $stmt = $db->prepare("SELECT id FROM plugins WHERE name = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $pluginName);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $pluginId = (int)$row['id'];
            }
            $stmt->close();
        }
        if ($pluginId === null) {
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
        $value = $row['setting_value'];
        // Decrypt encrypted values (ENC: prefix)
        if (is_string($value) && str_starts_with($value, 'ENC:')) {
            $value = decryptSettingValue($value);
        }
        $settings[$row['setting_key']] = $value;
    }

    $stmt->close();
    return $settings;
}

/**
 * Decrypt a setting value encrypted by PluginManager
 *
 * @param string $encrypted Encrypted value with ENC: prefix
 * @return string|null Decrypted value or null on failure
 */
function decryptSettingValue(string $encrypted): ?string
{
    // Validate ENC: prefix
    if (!str_starts_with($encrypted, 'ENC:')) {
        return null;
    }

    // Remove ENC: prefix
    $payload = substr($encrypted, 4);
    $decoded = base64_decode($payload);

    if ($decoded === false || strlen($decoded) < 28) {
        error_log('[Z39 SRU Endpoint] Invalid encrypted payload');
        return null;
    }

    // Get encryption key from environment
    $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
        ?? getenv('PLUGIN_ENCRYPTION_KEY')
        ?? $_ENV['APP_KEY']
        ?? getenv('APP_KEY')
        ?? null;

    if ($rawKey === null || $rawKey === '') {
        error_log('[Z39 SRU Endpoint] Cannot decrypt: PLUGIN_ENCRYPTION_KEY not available');
        return null;
    }

    // Hash key exactly like PluginManager does
    $key = hash('sha256', (string)$rawKey, true);

    try {
        // Extract IV (12 bytes), tag (16 bytes), and ciphertext
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $decrypted !== false ? $decrypted : null;
    } catch (\Throwable $e) {
        error_log('[Z39 SRU Endpoint] Decryption error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get client IP address with trusted proxy validation
 *
 * @return string Client IP
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Get trusted proxies from environment (comma-separated)
    // Default: trust localhost and common private ranges when behind proxy
    $trustedProxies = array_filter(array_map(
        'trim',
        explode(',', $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '')
    ));

    // If no trusted proxies configured or request not from trusted proxy, use REMOTE_ADDR
    if (empty($trustedProxies) || !isTrustedProxy($remoteAddr, $trustedProxies)) {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : 'unknown';
    }

    // Only trust forwarded headers if request is from a trusted proxy
    $forwardedHeaders = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
    ];

    foreach ($forwardedHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            // If X-Forwarded-For contains multiple IPs, take the first (original client)
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : 'unknown';
}

/**
 * Check if IP is in trusted proxy list
 *
 * @param string $ip IP to check
 * @param array $trustedProxies List of trusted proxy IPs/CIDRs
 * @return bool
 */
function isTrustedProxy(string $ip, array $trustedProxies): bool
{
    foreach ($trustedProxies as $trusted) {
        // Exact match
        if ($ip === $trusted) {
            return true;
        }
        // CIDR notation support (e.g., 10.0.0.0/8)
        if (str_contains($trusted, '/')) {
            if (ipInCidr($ip, $trusted)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if IP is within CIDR range (IPv4 only)
 *
 * @param string $ip IP address
 * @param string $cidr CIDR notation (e.g., 192.168.1.0/24)
 * @return bool
 */
function ipInCidr(string $ip, string $cidr): bool
{
    // Only supports IPv4
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    // Validate CIDR format
    if (strpos($cidr, '/') === false) {
        return false;
    }

    [$subnet, $bits] = explode('/', $cidr);

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    // Check if ip2long succeeded
    if ($ipLong === false || $subnetLong === false) {
        return false;
    }

    $bits = (int) $bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }

    $mask = -1 << (32 - $bits);
    return ($ipLong & $mask) === ($subnetLong & $mask);
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
