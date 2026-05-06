<?php

/**
 * OpenURL Z39.88 Resolver plugin wrapper.
 *
 * Mirrors the oai-pmh-server plugin pattern:
 *   1. Namespaced direct loading via App\Plugins\OpenUrlResolver\OpenUrlResolverPlugin
 *   2. PluginManager via the global unnamespaced OpenUrlResolverPlugin proxy
 *
 * PluginManager::getPluginClassName('openurl-resolver') → 'OpenUrlResolverPlugin'
 * (no namespace), so we expose a thin forwarding class in the global namespace.
 */

require_once __DIR__ . '/OpenUrlResolverPlugin.php';

if (!class_exists('OpenUrlResolverPlugin', false)) {
    class OpenUrlResolverPlugin
    {
        /** @var \App\Plugins\OpenUrlResolver\OpenUrlResolverPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\OpenUrlResolver\OpenUrlResolverPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[OpenUrlResolver] Plugin activated');
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[OpenUrlResolver] Plugin deactivated');
        }

        public function onInstall(): void
        {
            \App\Support\SecureLogger::debug('[OpenUrlResolver] Plugin installed');
        }

        public function onUninstall(): void
        {
            \App\Support\SecureLogger::debug('[OpenUrlResolver] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on OpenUrlResolverPlugin");
        }
    }
}
