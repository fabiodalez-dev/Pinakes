<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Registers a plugin's public routes under every localized spelling of its
 * base path, plus the historical literal base the plugin always answered on.
 *
 * Wraps the Slim app handed to a plugin's registerRoutes(): a pattern that
 * starts with the legacy base (e.g. '/book-club/{slug}') is registered once
 * per distinct base ('/book-club/{slug}', '/club-di-lettura/{slug}',
 * '/lesekreis/{slug}', …) with the same handler; every other pattern
 * ('/admin/…', '/api/…') passes through untouched. Bases are de-duplicated
 * first, because FastRoute refuses a route registered twice and a localized
 * base can equal the legacy one (en_US '/book-club', it_IT '/emeroteca').
 *
 * Same idea as the archives plugin's per-locale loop over
 * RouteTranslator::getRouteForLocale('archives', …), packaged so that modules
 * receiving $app need no change. Links are built separately, from
 * RouteTranslator::route($key) (the visitor's locale).
 */
final class LocalizedRouteRegistrar
{
    /** Locales shipped with the app; their route files are always present. */
    private const BUNDLED_LOCALES = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];

    /** @var object Slim\App or a RouteCollectorProxy */
    private object $app;

    private string $legacyBase;

    /** @var list<string> */
    private array $bases;

    /**
     * @param object $app Slim app (or group proxy) exposing map()
     * @param list<string> $bases Every base to register, legacy included
     */
    public function __construct(object $app, string $legacyBase, array $bases)
    {
        $this->app = $app;
        $this->legacyBase = rtrim($legacyBase, '/');
        $this->bases = $bases;
    }

    /**
     * Wrap $app for the route key $routeKey, keeping $legacyBase answering.
     */
    public static function wrap(object $app, string $routeKey, string $legacyBase): self
    {
        return new self($app, $legacyBase, self::basesFor($routeKey, $legacyBase));
    }

    /**
     * The distinct bases for $routeKey across the bundled and the installed
     * locales, the legacy base first.
     *
     * @return list<string>
     */
    public static function basesFor(string $routeKey, string $legacyBase): array
    {
        $locales = self::BUNDLED_LOCALES;
        try {
            foreach (array_keys(I18n::getAvailableLocales()) as $locale) {
                $locales[] = (string) $locale;
            }
        } catch (\Throwable $e) {
            // The bundled locales alone are enough to register the routes.
        }

        $bases = [rtrim($legacyBase, '/') => true];
        foreach (array_unique($locales) as $locale) {
            $base = rtrim(RouteTranslator::getRouteForLocale($routeKey, $locale), '/');
            // A route key value is a plain path prefix: anything else (a
            // placeholder, a stray space) would register a broken pattern.
            if ($base !== '' && preg_match('#^(/[a-z0-9][a-z0-9._-]*)+$#i', $base) === 1) {
                $bases[$base] = true;
            }
        }

        return array_keys($bases);
    }

    /** @return list<string> */
    public function bases(): array
    {
        return $this->bases;
    }

    /** @param callable|string $callable */
    public function get(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['GET'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function post(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['POST'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function put(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['PUT'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function patch(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['PATCH'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function delete(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['DELETE'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function options(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['OPTIONS'], $pattern, $callable);
    }

    /** @param callable|string $callable */
    public function any(string $pattern, $callable): LocalizedRouteSet
    {
        return $this->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $pattern, $callable);
    }

    /**
     * @param list<string> $methods
     * @param callable|string $callable
     */
    public function map(array $methods, string $pattern, $callable): LocalizedRouteSet
    {
        $routes = [];
        foreach ($this->expand($pattern) as $expanded) {
            $routes[] = $this->app->map($methods, $expanded, $callable);
        }
        return new LocalizedRouteSet($routes);
    }

    /**
     * Anything else (group(), getContainer(), redirect(), …) goes to the
     * wrapped app unchanged.
     *
     * @param array<int|string, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->app->{$name}(...$arguments);
    }

    /**
     * The patterns $pattern stands for: one per base when it lives under the
     * legacy base, itself otherwise.
     *
     * @return list<string>
     */
    private function expand(string $pattern): array
    {
        if ($pattern !== $this->legacyBase && !str_starts_with($pattern, $this->legacyBase . '/')) {
            return [$pattern];
        }
        $suffix = substr($pattern, strlen($this->legacyBase));
        $patterns = [];
        foreach ($this->bases as $base) {
            $patterns[$base . $suffix] = true;
        }
        return array_keys($patterns);
    }
}
