<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Hooks Helper
 *
 * Static accessor for HookManager to easily use hooks throughout the application
 */
class Hooks
{
    private static ?HookManager $instance = null;

    /**
     * Initialize the hook system with a HookManager instance
     *
     * @param HookManager $hookManager
     */
    public static function init(HookManager $hookManager): void
    {
        self::$instance = $hookManager;
    }

    /**
     * Get the HookManager instance
     *
     * @return HookManager|null
     */
    public static function getInstance(): ?HookManager
    {
        return self::$instance;
    }

    /**
     * Apply a filter hook
     *
     * @param string $hookName
     * @param mixed $value
     * @param array $args
     * @return mixed
     */
    public static function apply(string $hookName, $value, array $args = [])
    {
        if (self::$instance === null) {
            return $value;
        }

        return self::$instance->applyFilters($hookName, $value, $args);
    }

    /**
     * Execute an action hook
     *
     * @param string $hookName
     * @param array $args
     */
    public static function do(string $hookName, array $args = []): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->doAction($hookName, $args);
    }

    /**
     * Check if a hook exists
     *
     * @param string $hookName
     * @return bool
     */
    public static function has(string $hookName): bool
    {
        if (self::$instance === null) {
            return false;
        }

        return self::$instance->hasHook($hookName);
    }

    /**
     * Add a hook at runtime
     *
     * @param string $hookName
     * @param callable $callback
     * @param int $priority
     */
    public static function add(string $hookName, callable $callback, int $priority = 10): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->addHook($hookName, $callback, $priority);
    }
}
