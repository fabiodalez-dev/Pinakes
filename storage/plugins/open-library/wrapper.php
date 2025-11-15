<?php
/**
 * OpenLibrary Plugin Wrapper
 *
 * This wrapper allows the plugin to work with both:
 * 1. Direct loading via activate.php (with namespace)
 * 2. PluginManager installation (without namespace)
 */

// Load the main plugin file
require_once __DIR__ . '/OpenLibraryPlugin.php';

// Create global class alias for PluginManager compatibility
if (!class_exists('OpenLibraryPlugin', false)) {
    /**
     * Global OpenLibraryPlugin class (no namespace)
     * Acts as a proxy to the namespaced version
     */
    class OpenLibraryPlugin
    {
        private $instance;

        public function __construct($db = null, $hookManager = null)
        {
            // Create instance of the namespaced class with DB and HookManager
            $this->instance = new \App\Plugins\OpenLibrary\OpenLibraryPlugin($db, $hookManager);
        }

        /**
         * Activate the plugin
         */
        public function activate(): void
        {
            $this->instance->activate();
        }

        /**
         * Deactivate the plugin (called by PluginManager)
         */
        public function onDeactivate(): void
        {
            // Remove hooks when deactivated
            // Note: Hooks system doesn't have a remove method yet
            // For now, we just log the deactivation
            error_log('[OpenLibrary] Plugin deactivated');
        }

        /**
         * Called when plugin is installed (by PluginManager)
         */
        public function onInstall(): void
        {
            error_log('[OpenLibrary] Plugin installed');
        }

        /**
         * Called when plugin is activated (by PluginManager)
         */
        public function onActivate(): void
        {
            $this->activate();
            error_log('[OpenLibrary] Plugin activated via PluginManager');
        }

        /**
         * Called when plugin is uninstalled (by PluginManager)
         */
        public function onUninstall(): void
        {
            error_log('[OpenLibrary] Plugin uninstalled');
        }

        /**
         * Set the plugin ID (called by PluginManager after installation)
         */
        public function setPluginId(int $pluginId): void
        {
            $this->instance->setPluginId($pluginId);
        }

        /**
         * Forward all method calls to the namespaced instance
         */
        public function __call($method, $args)
        {
            if (method_exists($this->instance, $method)) {
                return call_user_func_array([$this->instance, $method], $args);
            }

            throw new \BadMethodCallException("Method {$method} does not exist");
        }
    }
}
