<?php

/**
 * Mobile API plugin wrapper.
 *
 * Mirrors the ncip-server / oai-pmh-server plugin pattern:
 *   1. Real logic lives in the namespaced App\Plugins\MobileApi\MobileApiPlugin.
 *   2. PluginManager instantiates plugins in the GLOBAL namespace via the class
 *      name resolved from the directory name:
 *      PluginManager::getPluginClassName('mobile-api') → 'MobileApiPlugin'.
 *
 * So we expose a thin forwarding class in the global namespace whose __call()
 * proxies every method (setPluginId, registerRoutes, getSettingsViewPath, …) to
 * the real instance.
 */

require_once __DIR__ . '/MobileApiPlugin.php';

if (!class_exists('MobileApiPlugin', false)) {
    class MobileApiPlugin
    {
        /** @var \App\Plugins\MobileApi\MobileApiPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\MobileApi\MobileApiPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[MobileApi] Plugin activated');
        }

        public function expectedTables(): array
        {
            return $this->instance->expectedTables();
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[MobileApi] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[MobileApi] Plugin installed');
        }

        public function onUninstall(): void
        {
            $this->instance->onUninstall();
            \App\Support\SecureLogger::debug('[MobileApi] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on MobileApiPlugin");
        }
    }
}
