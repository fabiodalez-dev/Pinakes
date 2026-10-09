<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The routes one LocalizedRouteRegistrar call registered: middleware and
 * arguments apply to every one of them, a name only to the first (route
 * names are unique in Slim).
 */
final class LocalizedRouteSet
{
    /** @var list<object> */
    private array $routes;

    /** @param list<object> $routes */
    public function __construct(array $routes)
    {
        $this->routes = $routes;
    }

    /** @param mixed $middleware */
    public function add($middleware): self
    {
        foreach ($this->routes as $route) {
            $route->add($middleware);
        }
        return $this;
    }

    /** @param mixed $middleware */
    public function addMiddleware($middleware): self
    {
        foreach ($this->routes as $route) {
            $route->addMiddleware($middleware);
        }
        return $this;
    }

    public function setName(string $name): self
    {
        if ($this->routes !== []) {
            $this->routes[0]->setName($name);
        }
        return $this;
    }

    /** @return list<object> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @param array<int|string, mixed> $arguments */
    public function __call(string $name, array $arguments): self
    {
        foreach ($this->routes as $route) {
            $route->{$name}(...$arguments);
        }
        return $this;
    }
}
