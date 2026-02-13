<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\HtmlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class BasePathMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $basePath = HtmlHelper::getBasePath();
        if ($basePath === '') {
            return $response;
        }

        if ($response->hasHeader('Location')) {
            $location = $response->getHeaderLine('Location');
            // Only rewrite absolute paths (starting with /) that aren't already prefixed
            if (
                str_starts_with($location, '/')
                && !str_starts_with($location, '//')
                && !str_starts_with($location, $basePath . '/')
                && $location !== $basePath
            ) {
                $response = $response->withHeader('Location', $basePath . $location);
            }
        }

        return $response;
    }
}
