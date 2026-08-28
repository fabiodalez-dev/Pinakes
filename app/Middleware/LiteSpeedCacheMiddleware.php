<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\ConfigStore;
use App\Support\ContentCache;
use App\Support\ContentSecurityPolicy;
use App\Support\LiteSpeedCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;

/** Applies LiteSpeed full-page policy only to explicitly marked public HTML. */
final class LiteSpeedCacheMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $enabledBefore = LiteSpeedCache::enabled();
        $response = $handler->handle($request);

        // Defense in depth for site-wide admin surfaces (themes, languages,
        // plugins and future settings): a successful privileged mutation can
        // change shared HTML even if its controller has no domain cache hook.
        $role = (string) ($_SESSION['user']['tipo_utente'] ?? '');
        if (
            ($enabledBefore || LiteSpeedCache::enabled())
            && in_array($role, ['admin', 'staff'], true)
            && !in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)
            && $response->getStatusCode() < 400
        ) {
            LiteSpeedCache::queuePurge([LiteSpeedCache::TAG_ALL], true);
        }

        // Flush transaction-deferred invalidations before response headers are
        // finalized; shutdown remains the CLI/error fallback.
        ContentCache::flushDeferred();
        $purgeTags = LiteSpeedCache::consumeQueuedPurge();
        if ($purgeTags !== []) {
            $response = $response->withHeader('X-LiteSpeed-Purge', LiteSpeedCache::purgeHeader($purgeTags));
        }

        $marker = trim($response->getHeaderLine(LiteSpeedCache::MARKER_HEADER));
        $response = $response->withoutHeader(LiteSpeedCache::MARKER_HEADER);
        if (!LiteSpeedCache::enabled()) {
            // An upstream LiteSpeed configuration may still have generic cache
            // rules. Disabling the feature in Pinakes must therefore be an
            // explicit bypass, not merely the absence of a public-cache header.
            return $response->withHeader('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        if (!$this->isCacheable($request, $response, $marker)) {
            return $response->withHeader('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        [$page, $id] = array_pad(explode(':', $marker, 2), 2, '');
        $tags = [LiteSpeedCache::TAG_ALL];
        if ($page === 'home') {
            $tags[] = LiteSpeedCache::TAG_HOME;
        } elseif ($page === 'catalog') {
            $tags[] = LiteSpeedCache::TAG_CATALOG;
            $tags[] = LiteSpeedCache::TAG_BOOKS;
        } elseif ($page === 'book') {
            $tags[] = LiteSpeedCache::TAG_BOOKS;
            if (ctype_digit($id) && (int) $id > 0) {
                $tags[] = 'pinakes_book_' . $id;
            }
        }

        $ttl = LiteSpeedCache::ttlFor($page);
        $html = (string) $response->getBody();
        $stableHtml = ContentSecurityPolicy::removeNonceAttributes($html);
        // Preserve the proxy-aware HTTPS decision already made by the security
        // middleware. PSR-7's URI scheme can still be "http" behind TLS
        // termination even though upgrade-insecure-requests is required.
        $upgradeInsecureRequests = str_contains(
            strtolower($response->getHeaderLine('Content-Security-Policy')),
            'upgrade-insecure-requests'
        );
        $csp = ContentSecurityPolicy::headerForCachedHtml(
            $stableHtml,
            $upgradeInsecureRequests
        );

        return $response
            ->withBody((new StreamFactory())->createStream($stableHtml))
            ->withoutHeader('Content-Length')
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-LiteSpeed-Cache-Control', 'public,max-age=' . $ttl)
            ->withHeader('X-LiteSpeed-Tag', implode(',', array_unique($tags)))
            ->withHeader('X-LiteSpeed-Vary', 'cookie=pinakes_locale')
            ->withHeader('Cache-Control', 'public,max-age=0,s-maxage=' . $ttl . ',must-revalidate')
            ->withHeader('Vary', $this->mergeVary($response->getHeaderLine('Vary'), 'Cookie'));
    }

    private function isCacheable(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $marker
    ): bool {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return false;
        }
        if ($response->getStatusCode() !== 200 || !preg_match('/^(home|catalog|book:\d+)$/D', $marker)) {
            return false;
        }
        if ($request->getHeaderLine('Authorization') !== '' || !empty($_SESSION['user'])) {
            return false;
        }
        foreach ($request->getCookieParams() as $cookie => $value) {
            if ($cookie !== 'pinakes_locale') {
                return false;
            }
            if (!is_string($value) || preg_match('/^[A-Za-z]{2}_[A-Za-z]{2}$/D', $value) !== 1) {
                return false;
            }
        }
        if ($response->hasHeader('Set-Cookie')) {
            return false;
        }
        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'private')) {
            return false;
        }
        if ((string) ConfigStore::get('advanced.private_mode', '0') === '1') {
            return false;
        }
        return str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html');
    }

    private function mergeVary(string $current, string $value): string
    {
        $parts = array_filter(array_map('trim', explode(',', $current)));
        $parts[] = $value;
        return implode(', ', array_values(array_unique($parts)));
    }
}
