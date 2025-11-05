<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Hook Manager
 *
 * Manages hook registration and execution for the plugin system.
 * Hooks allow plugins to extend application functionality without modifying core code.
 */
class HookManager
{
    private mysqli $db;
    private array $hooks = [];
    private bool $loadedHooks = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Load all hooks from database for active plugins
     */
    private function loadHooks(): void
    {
        if ($this->loadedHooks) {
            return;
        }

        $query = "
            SELECT
                ph.hook_name,
                ph.callback_class,
                ph.callback_method,
                ph.priority,
                p.path as plugin_path,
                p.name as plugin_name
            FROM plugin_hooks ph
            INNER JOIN plugins p ON ph.plugin_id = p.id
            WHERE ph.is_active = 1 AND p.is_active = 1
            ORDER BY ph.priority ASC, ph.id ASC
        ";

        $result = $this->db->query($query);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $hookName = $row['hook_name'];

                if (!isset($this->hooks[$hookName])) {
                    $this->hooks[$hookName] = [];
                }

                $this->hooks[$hookName][] = [
                    'class' => $row['callback_class'],
                    'method' => $row['callback_method'],
                    'priority' => (int)$row['priority'],
                    'plugin_path' => $row['plugin_path'],
                    'plugin_name' => $row['plugin_name']
                ];
            }
            $result->free();
        }

        $this->loadedHooks = true;
    }

    /**
     * Register a hook programmatically (runtime)
     *
     * @param string $hookName Hook identifier
     * @param callable $callback Callback function/method
     * @param int $priority Execution priority (lower = earlier)
     */
    public function addHook(string $hookName, callable $callback, int $priority = 10): void
    {
        if (!isset($this->hooks[$hookName])) {
            $this->hooks[$hookName] = [];
        }

        $this->hooks[$hookName][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Re-sort by priority
        usort($this->hooks[$hookName], function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    /**
     * Execute a hook (filter type)
     * Filters modify and return a value
     *
     * @param string $hookName Hook identifier
     * @param mixed $value Initial value
     * @param array $args Additional arguments passed to hook
     * @return mixed Modified value
     */
    public function applyFilters(string $hookName, $value, array $args = [])
    {
        $this->loadHooks();

        if (!isset($this->hooks[$hookName]) || empty($this->hooks[$hookName])) {
            return $value;
        }

        foreach ($this->hooks[$hookName] as $hook) {
            try {
                if (isset($hook['callback'])) {
                    // Runtime callback
                    $value = call_user_func_array($hook['callback'], array_merge([$value], $args));
                } elseif (isset($hook['class']) && isset($hook['method'])) {
                    // Plugin class method
                    $pluginPath = $hook['plugin_path'] ?? '';
                    $className = $hook['class'];

                    // Load plugin class if not loaded
                    if (!class_exists($className)) {
                        $classFile = __DIR__ . '/../../storage/plugins/' . $pluginPath . '/' . str_replace('\\', '/', $className) . '.php';
                        if (file_exists($classFile)) {
                            require_once $classFile;
                        }
                    }

                    if (class_exists($className)) {
                        $instance = new $className();
                        $method = $hook['method'];

                        if (method_exists($instance, $method)) {
                            $value = call_user_func_array([$instance, $method], array_merge([$value], $args));
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("Hook execution error [{$hookName}]: " . $e->getMessage());
                // Continue execution even if one hook fails
            }
        }

        return $value;
    }

    /**
     * Execute a hook (action type)
     * Actions execute code without returning a value
     *
     * @param string $hookName Hook identifier
     * @param array $args Arguments passed to hook
     */
    public function doAction(string $hookName, array $args = []): void
    {
        $this->loadHooks();

        if (!isset($this->hooks[$hookName]) || empty($this->hooks[$hookName])) {
            return;
        }

        foreach ($this->hooks[$hookName] as $hook) {
            try {
                if (isset($hook['callback'])) {
                    // Runtime callback
                    call_user_func_array($hook['callback'], $args);
                } elseif (isset($hook['class']) && isset($hook['method'])) {
                    // Plugin class method
                    $pluginPath = $hook['plugin_path'] ?? '';
                    $className = $hook['class'];

                    // Load plugin class if not loaded
                    if (!class_exists($className)) {
                        $classFile = __DIR__ . '/../../storage/plugins/' . $pluginPath . '/' . str_replace('\\', '/', $className) . '.php';
                        if (file_exists($classFile)) {
                            require_once $classFile;
                        }
                    }

                    if (class_exists($className)) {
                        $instance = new $className();
                        $method = $hook['method'];

                        if (method_exists($instance, $method)) {
                            call_user_func_array([$instance, $method], $args);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("Hook execution error [{$hookName}]: " . $e->getMessage());
                // Continue execution even if one hook fails
            }
        }
    }

    /**
     * Check if a hook has any registered callbacks
     *
     * @param string $hookName Hook identifier
     * @return bool
     */
    public function hasHook(string $hookName): bool
    {
        $this->loadHooks();
        return isset($this->hooks[$hookName]) && !empty($this->hooks[$hookName]);
    }

    /**
     * Get all registered hooks for debugging
     *
     * @return array
     */
    public function getAllHooks(): array
    {
        $this->loadHooks();
        return $this->hooks;
    }

    /**
     * Mark hooks as loaded to prevent database loading
     * This should be called by PluginManager after loading plugins runtime
     */
    public function setPluginsLoadedRuntime(): void
    {
        $this->loadedHooks = true;
    }

    /**
     * Clear all loaded hooks (useful for testing)
     */
    public function clearHooks(): void
    {
        $this->hooks = [];
        $this->loadedHooks = false;
    }
}
