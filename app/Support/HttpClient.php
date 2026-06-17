<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Centralized HTTP client for outbound metadata requests (book scrapers,
 * authority lookups, JSON APIs), built on Guzzle.
 *
 * Replaces the assorted curl_init blocks scattered across ScrapeController and
 * the scraper plugins with a single configuration point: consistent timeouts,
 * a shared User-Agent, SSL verification, bounded redirects, and — importantly —
 * a protocol allow-list (http/https only) so a redirect can never be coerced
 * into file://, gopher://, etc.
 *
 * SCOPE: this is for metadata/API calls only. The cover-image downloaders in
 * CoverController / LibriController keep their bespoke curl handling: they do
 * per-redirect-hop private/reserved-IP validation (SSRF) and streamed size
 * caps (DoS) that Guzzle does not replicate out of the box. Do not route those
 * through this client without porting those protections first.
 *
 * Every call returns a plain result array and never throws on HTTP status or
 * transport errors, mirroring the previous `curl_exec() === false` handling:
 *   ['ok' => bool, 'status' => int, 'body' => string]
 * `ok` is false on a transport-level failure (DNS, connection, TLS); `status`
 * is then 0. HTTP 4xx/5xx are returned as-is (ok=true, status set) so callers
 * keep deciding what a "successful" response means, exactly as before.
 */
final class HttpClient
{
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; BibliotecaBot/1.0)';
    private const DEFAULT_TIMEOUT = 20;
    private const DEFAULT_CONNECT_TIMEOUT = 10;
    private const DEFAULT_MAX_REDIRECTS = 3;

    private static ?Client $client = null;

    /**
     * Perform a GET request.
     *
     * @param array<string,string> $headers Extra request headers
     * @param array<string,mixed>  $options Overrides: timeout, connect_timeout,
     *                                       user_agent, max_redirects, verify,
     *                                       query (array), https_only (bool)
     * @return array{ok:bool,status:int,body:string}
     */
    public static function get(string $url, array $headers = [], array $options = []): array
    {
        return self::request('GET', $url, $headers, $options);
    }

    /**
     * Perform a POST request.
     *
     * @param string|array<string,mixed>|null $body    Raw string body, or an
     *                                                  array sent as form params
     * @param array<string,string>            $headers Extra request headers
     * @param array<string,mixed>             $options See {@see self::get()}
     * @return array{ok:bool,status:int,body:string}
     */
    public static function post(string $url, string|array|null $body = null, array $headers = [], array $options = []): array
    {
        if (is_array($body)) {
            $options[RequestOptions::FORM_PARAMS] = $body;
        } elseif ($body !== null) {
            $options[RequestOptions::BODY] = $body;
        }

        return self::request('POST', $url, $headers, $options);
    }

    /**
     * Convenience helper: GET + JSON decode. Returns null when the request
     * fails, the status is not 2xx, or the body is not valid JSON.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     * @return array<mixed>|null
     */
    public static function getJson(string $url, array $headers = [], array $options = []): ?array
    {
        $res = self::get($url, $headers, $options);
        if (!$res['ok'] || $res['status'] < 200 || $res['status'] >= 300) {
            return null;
        }

        $decoded = json_decode($res['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     * @return array{ok:bool,status:int,body:string}
     */
    private static function request(string $method, string $url, array $headers, array $options): array
    {
        // Pin the initial request scheme (the ALLOW_REDIRECTS protocol
        // allow-list below only constrains *redirect* hops). This preserves
        // the CURLOPT_PROTOCOLS hardening of the original curl calls. When a
        // caller passes https_only=true, the allow-list narrows to https on
        // BOTH the initial request and any redirect, so a 30x can never
        // downgrade the scheme — important for requests that carry an
        // Authorization token (e.g. the Discogs API), which must never travel
        // over cleartext after a redirect.
        $httpsOnly = (bool) ($options['https_only'] ?? false);
        $allowedSchemes = $httpsOnly ? ['https'] : ['http', 'https'];

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, $allowedSchemes, true)) {
            return ['ok' => false, 'status' => 0, 'body' => ''];
        }

        $userAgent = (string) ($options['user_agent'] ?? self::DEFAULT_USER_AGENT);
        $maxRedirects = (int) ($options['max_redirects'] ?? self::DEFAULT_MAX_REDIRECTS);

        $guzzleOptions = [
            RequestOptions::TIMEOUT => (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            RequestOptions::CONNECT_TIMEOUT => (float) ($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT),
            RequestOptions::VERIFY => $options['verify'] ?? true,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::HEADERS => ['User-Agent' => $userAgent] + $headers,
            RequestOptions::ALLOW_REDIRECTS => $maxRedirects > 0
                ? ['max' => $maxRedirects, 'protocols' => $allowedSchemes, 'strict' => false]
                : false,
        ];

        // Optional IP pinning (SSRF / DNS-rebind defense). When the caller has
        // already resolved the host to a vetted public IP, pin the connection to
        // it via CURLOPT_RESOLVE so Guzzle/curl never re-resolves — a TOCTOU
        // rebind between the caller's check and connect cannot reach a different
        // address. Only the host the URL targets is pinned, on its effective port.
        if (isset($options['pin_ip']) && is_string($options['pin_ip']) && $options['pin_ip'] !== '' && \extension_loaded('curl')) {
            $pinHost = strtolower((string) parse_url($url, PHP_URL_HOST));
            $pinPort = (int) (parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80));
            if ($pinHost !== '') {
                $guzzleOptions['curl'] = [
                    CURLOPT_RESOLVE => ["$pinHost:$pinPort:" . $options['pin_ip']],
                ];
            }
        }

        if (isset($options['query']) && is_array($options['query'])) {
            $guzzleOptions[RequestOptions::QUERY] = $options['query'];
        }
        if (isset($options[RequestOptions::FORM_PARAMS])) {
            $guzzleOptions[RequestOptions::FORM_PARAMS] = $options[RequestOptions::FORM_PARAMS];
        }
        if (isset($options[RequestOptions::BODY])) {
            $guzzleOptions[RequestOptions::BODY] = $options[RequestOptions::BODY];
        }

        try {
            $response = self::client()->request($method, $url, $guzzleOptions);

            return [
                'ok' => true,
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ];
        } catch (GuzzleException $e) {
            // Transport-level failure (DNS / connection / TLS). Mirror the old
            // `curl_exec() === false` path: surface an empty, non-ok result.
            SecureLogger::warning('HttpClient request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'body' => ''];
        }
    }

    private static function client(): Client
    {
        if (self::$client === null) {
            self::$client = new Client();
        }

        return self::$client;
    }
}
