<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

require_once __DIR__ . '/SruClient.php';

/**
 * The SRU client pointed at this installation's own SRU server (/api/sru):
 * the request is answered in-process by the plugin's endpoint instead of
 * over HTTP. The SSRF guard of the HTTP client would refuse a site that runs
 * on a private address, and there is no reason to leave the process to read
 * our own catalogue. Every other URL goes through the network as usual.
 */
final class LocalSruClient extends SruClient
{
    /**
     * @param list<array<string, mixed>> $servers
     */
    public function __construct(array $servers, private \mysqli $db, private ?int $pluginId)
    {
        parent::__construct($servers);
    }

    public static function isLocalUrl(string $url): bool
    {
        $own = parse_url(function_exists('absoluteUrl') ? \absoluteUrl('/api/sru') : '');
        $target = parse_url($url);
        if (!is_array($own) || !is_array($target) || !isset($own['host'], $target['host'])) {
            return false;
        }
        // The scheme too: http://own-host:443 on an HTTPS site is not this
        // server, and answering it in-process would hide the TLS mismatch.
        return strcasecmp((string) $own['host'], (string) $target['host']) === 0
            && strcasecmp((string) ($own['scheme'] ?? ''), (string) ($target['scheme'] ?? '')) === 0
            && \App\Support\Marc\MarcCoverFetcher::effectivePort($own) === \App\Support\Marc\MarcCoverFetcher::effectivePort($target)
            && rtrim((string) ($own['path'] ?? ''), '/') === rtrim((string) ($target['path'] ?? ''), '/');
    }

    protected function fetchUrl(string $url): ?string
    {
        if (!self::isLocalUrl($url)) {
            return parent::fetchUrl($url);
        }
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        require_once dirname(__DIR__) . '/endpoint.php';
        // The endpoint checks the plugin's API key when the setting asks for
        // one: this request comes from the plugin itself, so it carries the
        // key as a client would, instead of being answered with a 401.
        $settings = \getPluginSettings($this->db, $this->pluginId);
        if (in_array($settings['require_api_key'] ?? '', ['1', 'true'], true) && trim((string) ($settings['api_key'] ?? '')) !== '') {
            $query['api_key'] = (string) $settings['api_key'];
        }
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/sru', $_SERVER)
            ->withQueryParams($query);
        $response = \handleSRURequest($request, new \Slim\Psr7\Response(), $this->db, $this->pluginId);
        $body = $response->getBody();
        $body->rewind();
        $xml = $body->getContents();
        // An error answer with a body (<error><message>, as the endpoint
        // writes on 401, 429 and 503) is returned so its message is read and
        // reported as the server's error; one without a body is the status.
        if ($response->getStatusCode() >= 400 && $xml === '') {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode() . ' error from server');
        }
        return $xml !== '' ? $xml : null;
    }
}
