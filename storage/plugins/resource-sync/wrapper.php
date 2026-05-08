<?php

/**
 * ResourceSync plugin wrapper.
 *
 * Mirrors the oai-pmh-server plugin pattern:
 *   1. Namespaced direct loading via App\Plugins\ResourceSync\ResourceSyncPlugin
 *   2. PluginManager via the global unnamespaced ResourceSyncPlugin proxy
 *
 * PluginManager::getPluginClassName('resource-sync') → 'ResourceSyncPlugin'
 * (no namespace), so we expose a thin forwarding class in the global namespace.
 */

require_once __DIR__ . '/ResourceSyncPlugin.php';

if (!class_exists('ResourceSyncPlugin', false)) {
    class ResourceSyncPlugin
    {
        /** @var \App\Plugins\ResourceSync\ResourceSyncPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\ResourceSync\ResourceSyncPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[ResourceSync] Plugin activated');
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[ResourceSync] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[ResourceSync] Plugin installed');
        }

        public function onUninstall(): void
        {
            $this->instance->onUninstall();
            \App\Support\SecureLogger::debug('[ResourceSync] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on ResourceSyncPlugin");
        }
    }
}
