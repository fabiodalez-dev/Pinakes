<?php

/**
 * FRBR/LRM plugin wrapper (issue #134).
 *
 * PluginManager::getPluginClassName('frbr-lrm') → 'FrbrLrmPlugin' (no
 * namespace), so we expose a thin forwarding class in the global namespace
 * that constructs the real namespaced plugin and forwards lifecycle + hook
 * callbacks (registerRoutes, renderAdminMenuEntry, action methods).
 */

require_once __DIR__ . '/FrbrLrmPlugin.php';
require_once __DIR__ . '/OpereRepository.php';
require_once __DIR__ . '/EspressioniRepository.php';

if (!class_exists('FrbrLrmPlugin', false)) {
    class FrbrLrmPlugin
    {
        private \App\Plugins\FrbrLrm\FrbrLrmPlugin $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\FrbrLrm\FrbrLrmPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[FrbrLrm] Plugin activated');
        }

        public function expectedTables(): array
        {
            return $this->instance->expectedTables();
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[FrbrLrm] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[FrbrLrm] Plugin installed');
        }

        public function onUninstall(): void
        {
            \App\Support\SecureLogger::debug('[FrbrLrm] Plugin uninstalled');
        }

        /**
         * Forward hook callbacks (registerRoutes, renderAdminMenuEntry, the
         * action methods invoked from route closures) and introspection calls
         * (ensureSchema, setPluginId) to the namespaced instance.
         *
         * @param array<int, mixed> $args
         */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on FrbrLrmPlugin");
        }
    }
}
