<?php

/**
 * BIBFRAME 2.0 Linked Data plugin wrapper.
 *
 * Mirrors the oai-pmh-server plugin pattern:
 *   1. Namespaced direct loading via App\Plugins\BibframeLinkedData\BibframeLinkedDataPlugin
 *   2. PluginManager via the global unnamespaced BibframeLinkedDataPlugin proxy
 *
 * PluginManager::getPluginClassName('bibframe-linked-data') → 'BibframeLinkedDataPlugin'
 * (no namespace), so we expose a thin forwarding class in the global namespace.
 */

require_once __DIR__ . '/BibframeLinkedDataPlugin.php';

if (!class_exists('BibframeLinkedDataPlugin', false)) {
    class BibframeLinkedDataPlugin
    {
        /** @var \App\Plugins\BibframeLinkedData\BibframeLinkedDataPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\BibframeLinkedData\BibframeLinkedDataPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[BibframeLinkedData] Plugin activated');
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[BibframeLinkedData] Plugin deactivated');
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
            \App\Support\SecureLogger::debug('[BibframeLinkedData] Plugin installed');
        }

        public function onUninstall(): void
        {
            \App\Support\SecureLogger::debug('[BibframeLinkedData] Plugin uninstalled');
        }

        /** @param array<int, mixed> $args */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on BibframeLinkedDataPlugin");
        }
    }
}
