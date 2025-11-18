<?php
/**
 * Simple Router
 * Handles HTTP routing with pattern matching
 */
class Router
{
    private array $routes = [];

    /**
     * Register a GET route
     */
    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $pattern, callable|array $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $pattern, callable|array $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Add route to registry
     */
    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'regex' => $this->compilePattern($pattern)
        ];
    }

    /**
     * Compile pattern to regex
     */
    private function compilePattern(string $pattern): string
    {
        // Convert {param} to named capture groups
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * Match request to route
     */
    public function match(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return [
                    'handler' => $route['handler'],
                    'params' => $params
                ];
            }
        }

        return null;
    }

    /**
     * Dispatch request to handler
     */
    public function dispatch(string $method, string $uri): void
    {
        $match = $this->match($method, $uri);

        if (!$match) {
            Response::notFound('Endpoint not found');
        }

        $handler = $match['handler'];
        $params = $match['params'];

        // Call handler
        if (is_array($handler)) {
            // Controller method: [ClassName::class, 'methodName']
            [$class, $method] = $handler;

            if (!class_exists($class)) {
                Response::serverError("Controller class not found: {$class}");
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                Response::serverError("Controller method not found: {$class}::{$method}");
            }

            $controller->$method($params);
        } else {
            // Callable function
            $handler($params);
        }
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
