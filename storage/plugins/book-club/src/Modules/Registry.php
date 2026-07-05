<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

use App\Plugins\BookClub\Repo;
use App\Support\SecureLogger;
use mysqli;

/**
 * Auto-discovery registry: every src/Modules/*Module.php that implements
 * ModuleInterface is loaded and instantiated once per request. Adding a
 * module therefore requires ZERO edits to the plugin core.
 */
final class Registry
{
    /** @var list<ModuleInterface>|null */
    private static ?array $modules = null;

    private function __construct()
    {
    }

    /** @return list<ModuleInterface> */
    public static function all(mysqli $db): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }
        $modules = [];
        $repo = new Repo($db);
        foreach (glob(__DIR__ . '/*Module.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if ($base === 'AbstractModule') {
                continue;
            }
            require_once $file;
            $class = __NAMESPACE__ . '\\' . $base;
            if (!class_exists($class) || !is_subclass_of($class, ModuleInterface::class)) {
                continue;
            }
            try {
                $modules[] = new $class($db, $repo);
            } catch (\Throwable $e) {
                SecureLogger::error('[BookClub] module ' . $base . ' failed to load: ' . $e->getMessage());
            }
        }
        usort($modules, static fn(ModuleInterface $a, ModuleInterface $b): int => strcmp($a->slug(), $b->slug()));
        return self::$modules = $modules;
    }

    /**
     * Per-club enablement: settings['modules'] is the explicit list of
     * enabled slugs; when the key is absent the module default applies.
     *
     * @param array<string, mixed> $club hydrated club row (settings decoded)
     */
    public static function clubEnabled(array $club, ModuleInterface $module): bool
    {
        $settings = is_array($club['settings'] ?? null) ? $club['settings'] : [];
        if (!array_key_exists('modules', $settings) || !is_array($settings['modules'])) {
            return $module->defaultEnabled();
        }
        return in_array($module->slug(), $settings['modules'], true);
    }

    /**
     * @param array<string, mixed> $club
     * @return list<ModuleInterface>
     */
    public static function enabledForClub(mysqli $db, array $club): array
    {
        return array_values(array_filter(
            self::all($db),
            static fn(ModuleInterface $m): bool => self::clubEnabled($club, $m)
        ));
    }
}
