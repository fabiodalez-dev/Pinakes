<?php

/**
 * VIAF Authority Control plugin wrapper.
 * Same pattern as the OAI-PMH server plugin wrapper.
 */

require_once __DIR__ . '/ViafAuthorityPlugin.php';

if (!class_exists('ViafAuthorityPlugin', false)) {
    class ViafAuthorityPlugin
    {
        /** @var \App\Plugins\ViafAuthority\ViafAuthorityPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\ViafAuthority\ViafAuthorityPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[ViafAuthority] Plugin activated');
        }

        public function expectedTables(): array
        {
            return $this->instance->expectedTables();
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[ViafAuthority] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[ViafAuthority] Plugin installed');
        }

        public function onUninstall(): void
        {
            $this->instance->onUninstall();
            \App\Support\SecureLogger::debug('[ViafAuthority] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on ViafAuthorityPlugin");
        }
    }
}
