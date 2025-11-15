<?php
/**
 * Scraping Pro Plugin Wrapper
 */

require_once __DIR__ . '/ScrapingProPlugin.php';

if (!class_exists('ScrapingProPlugin', false)) {
    class ScrapingProPlugin
    {
        private $instance;

        public function __construct($db = null, $hookManager = null)
        {
            $this->instance = new \App\Plugins\ScrapingPro\ScrapingProPlugin($db, $hookManager);
        }

        public function activate(): void
        {
            $this->instance->activate();
        }

        public function onInstall(): void
        {
            error_log('[ScrapingPro] Plugin installed');
        }

        public function onActivate(): void
        {
            $this->activate();
            error_log('[ScrapingPro] Plugin activated via PluginManager');
        }

        public function onDeactivate(): void
        {
            error_log('[ScrapingPro] Plugin deactivated');
        }

        public function onUninstall(): void
        {
            error_log('[ScrapingPro] Plugin uninstalled');
        }

        public function __call($method, $args)
        {
            if (method_exists($this->instance, $method)) {
                return $this->instance->$method(...$args);
            }

            throw new \BadMethodCallException("Method {$method} does not exist");
        }
    }
}
