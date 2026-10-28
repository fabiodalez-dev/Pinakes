<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\Features;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Refuses a route whose optional module is switched off (Settings → Modules
 * or Loans). Added FIRST to a route, so it runs after the authentication
 * middlewares: a visitor who is not signed in is sent to the login, not told
 * how the library is configured.
 *
 * Browser requests get the 302 to the dashboard used by catalogue mode, with
 * the message; JSON callers (path under /api/, XHR, Accept: application/json)
 * a 404 {"error":true,"code":"feature_disabled"}.
 */
final class FeatureGateMiddleware implements MiddlewareInterface
{
    public function __construct(private string $feature)
    {
        // Fail at boot on a typo, not at the first request.
        if (!in_array($feature, Features::keys(), true)) {
            throw new \InvalidArgumentException('Unknown feature: ' . $feature);
        }
    }

    public function feature(): string
    {
        return $this->feature;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if (Features::enabled($this->feature)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri()->getPath();
        $wantsJson = str_starts_with($uri, '/api/')
            || str_contains($uri, '/api/')
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($wantsJson) {
            $res = new SlimResponse(404);
            $res->getBody()->write((string) json_encode([
                'error' => true,
                'code' => 'feature_disabled',
                'message' => __('Funzione non disponibile su questa istanza.'),
            ], JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['error_message'] = __('Funzione disattivata nelle impostazioni.');
        return (new SlimResponse(302))->withHeader('Location', url('/admin/dashboard'));
    }
}
