<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090;

/**
 * Discovers the 0.9.0 staff areas (src/Staff090/Areas/*Area.php) in a stable
 * order. The files are already loaded by MobileApiPlugin; this only maps them
 * to their class names and keeps the ones implementing StaffArea.
 */
final class AreaRegistry
{
    /** @var list<class-string<StaffArea>>|null */
    private static ?array $areas = null;

    private function __construct()
    {
    }

    /** @return list<string> absolute paths of every Staff090 source file, load order */
    public static function files(): array
    {
        $base = __DIR__;
        $support = glob($base . '/Support/*.php') ?: [];
        $controllers = glob($base . '/Controllers/*.php') ?: [];
        $areas = glob($base . '/Areas/*Area.php') ?: [];
        sort($support);
        sort($controllers);
        sort($areas);

        return array_merge([$base . '/StaffArea.php'], $support, $controllers, $areas);
    }

    /** @return list<class-string<StaffArea>> */
    public static function areas(): array
    {
        if (self::$areas !== null) {
            return self::$areas;
        }
        $found = [];
        $paths = glob(__DIR__ . '/Areas/*Area.php') ?: [];
        sort($paths);
        foreach ($paths as $path) {
            $class = __NAMESPACE__ . '\\Areas\\' . basename($path, '.php');
            if (class_exists($class) && is_subclass_of($class, StaffArea::class)) {
                $found[] = $class;
            }
        }

        return self::$areas = $found;
    }

    /** @return list<string> the `area()` of every discovered area */
    public static function names(): array
    {
        return array_map(static fn (string $class): string => $class::area(), self::areas());
    }

    /**
     * `staff_<area>` health flags: true when the area's module is on (an area
     * without a feature is always true; the routes still check the role).
     *
     * @return array<string, bool>
     */
    public static function healthFlags(): array
    {
        $flags = [];
        foreach (self::areas() as $class) {
            $feature = $class::feature();
            $flags['staff_' . $class::area()] = $feature === null || \App\Support\Features::enabled($feature);
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function openApi(array $doc): array
    {
        foreach (self::areas() as $class) {
            $doc = $class::openApi($doc);
        }

        return $doc;
    }
}
