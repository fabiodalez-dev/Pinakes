<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\HtmlHelper;
use App\Support\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/** Covers backend routes even when a plugin omitted its route-level guard. */
final class BackendPermissionsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // Decide on the path the router dispatches: Slim rawurldecode()s it once
        // before matching, so '/%61dmin/books' reaches /admin/books and must be
        // gated as such. Repeated leading slashes are collapsed so that a form the
        // router would not route anyway is gated rather than waved through.
        // Permissions::authorizePath() decodes the same way and refuses (for
        // custom roles) anything that is not a plain decoded path: an encoded
        // '/', '\' or NUL, or a '%' left after the one decode.
        $decoded = rawurldecode($path);
        $base = HtmlHelper::getBasePath();
        $local = $base !== '' && str_starts_with($decoded, $base . '/') ? substr($decoded, strlen($base)) : $decoded;
        $local = '/' . ltrim($local, '/');
        if ($local === '/admin' || (!str_starts_with($local, '/admin/') && !str_starts_with($local, '/api/admin/'))
            || empty($_SESSION['user'])) {
            return $handler->handle($request);
        }
        if (!Permissions::authorizePath($path, null, $request->getMethod())) {
            $response = new Response(403);
            $response->getBody()->write(__('Non hai il permesso di accedere a questa sezione.'));
            return $response->withHeader('Cache-Control', 'no-store');
        }
        return $handler->handle($request);
    }
}
