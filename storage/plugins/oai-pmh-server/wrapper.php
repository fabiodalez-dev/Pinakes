<?php

/**
 * OAI-PMH Server plugin wrapper.
 *
 * Mirrors the archives plugin pattern:
 *   1. Namespaced direct loading via App\Plugins\OaiPmhServer\OaiPmhServerPlugin
 *   2. PluginManager via the global unnamespaced OaiPmhServerPlugin proxy
 *
 * PluginManager::getPluginClassName('oai-pmh-server') → 'OaiPmhServerPlugin'
 * (no namespace), so we expose a thin forwarding class in the global namespace.
 */

require_once __DIR__ . '/OaiPmhServerPlugin.php';

if (!class_exists('OaiPmhServerPlugin', false)) {
    class OaiPmhServerPlugin
    {
        /** @var \App\Plugins\OaiPmhServer\OaiPmhServerPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[OaiPmhServer] Plugin activated');
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[OaiPmhServer] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[OaiPmhServer] Plugin installed');
        }

        public function onUninstall(): void
        {
            $this->instance->onUninstall();
            \App\Support\SecureLogger::debug('[OaiPmhServer] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on OaiPmhServerPlugin");
        }
    }
}
