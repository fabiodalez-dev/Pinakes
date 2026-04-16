<?php
/**
 * Discogs Plugin Wrapper
 *
 * This wrapper allows the plugin to work with both:
 * 1. Direct loading via activate.php (with namespace)
 * 2. PluginManager installation (without namespace)
 */

// Load the main plugin file
require_once __DIR__ . '/DiscogsPlugin.php';

// Create global class alias for PluginManager compatibility
if (!class_exists('DiscogsPlugin', false)) {
    /**
     * Global DiscogsPlugin class (no namespace)
     * Acts as a proxy to the namespaced version
     */
    class DiscogsPlugin
    {
        private $instance;

        public function __construct($db = null, $hookManager = null)
        {
            // Create instance of the namespaced class with DB and HookManager
            $this->instance = new \App\Plugins\Discogs\DiscogsPlugin($db, $hookManager);
        }

        /**
         * Activate the plugin
         */
        public function activate(): void
        {
            if (is_callable([$this->instance, 'activate'])) {
                $this->instance->activate();
            }
        }

        /**
         * Deactivate the plugin (called by PluginManager)
         */
        public function onDeactivate(): void
        {
            if (is_callable([$this->instance, 'onDeactivate'])) {
                $this->instance->onDeactivate();
            }
            \App\Support\SecureLogger::debug('[Discogs] Plugin deactivated');
        }

        /**
         * Called when plugin is installed (by PluginManager)
         */
        public function onInstall(): void
        {
            if (is_callable([$this->instance, 'onInstall'])) {
                $this->instance->onInstall();
            }
            \App\Support\SecureLogger::debug('[Discogs] Plugin installed');
        }

        /**
         * Called when plugin is activated (by PluginManager)
         */
        public function onActivate(): void
        {
            if (is_callable([$this->instance, 'onActivate'])) {
                $this->instance->onActivate();
            } elseif (is_callable([$this->instance, 'activate'])) {
                $this->instance->activate();
            }
            \App\Support\SecureLogger::debug('[Discogs] Plugin activated');
        }

        /**
         * Called when plugin is uninstalled (by PluginManager)
         */
        public function onUninstall(): void
        {
            if (is_callable([$this->instance, 'onUninstall'])) {
                $this->instance->onUninstall();
            }
            \App\Support\SecureLogger::debug('[Discogs] Plugin uninstalled');
        }

        /**
         * Set the plugin ID (called by PluginManager after installation)
         */
        public function setPluginId(int $pluginId): void
        {
            if (is_callable([$this->instance, 'setPluginId'])) {
                $this->instance->setPluginId($pluginId);
            }
        }

        /**
         * Forward all method calls to the namespaced instance
         */
        public function __call($method, $args)
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }

            throw new \BadMethodCallException("Method {$method} does not exist");
        }
    }
}
