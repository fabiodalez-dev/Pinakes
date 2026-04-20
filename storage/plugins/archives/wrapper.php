<?php

/**
 * Archives plugin wrapper.
 *
 * Supports two instantiation paths, mirroring the Discogs plugin:
 *   1. Namespaced direct loading via App\Plugins\Archives\ArchivesPlugin
 *   2. PluginManager via the global unnamespaced ArchivesPlugin proxy
 *
 * PluginManager::getPluginClassName('archives') → 'ArchivesPlugin' (no
 * namespace), so we expose a thin forwarding class in the global namespace
 * that constructs the real namespaced plugin.
 *
 * Phase 1a (issue #103) — schema creation is real; hook wiring, CRUD UI,
 * MARCXML I/O, and unified search are roadmapped (see README.md).
 */

require_once __DIR__ . '/ArchivesPlugin.php';

if (!class_exists('ArchivesPlugin', false)) {
    class ArchivesPlugin
    {
        /** @var \App\Plugins\Archives\ArchivesPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\Archives\ArchivesPlugin($db, $hookManager);
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
            \App\Support\SecureLogger::debug('[Archives] Plugin activated');
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
            \App\Support\SecureLogger::debug('[Archives] Plugin deactivated');
        }

        public function onInstall(): void
        {
            \App\Support\SecureLogger::debug('[Archives] Plugin installed');
        }

        public function onUninstall(): void
        {
            \App\Support\SecureLogger::debug('[Archives] Plugin uninstalled');
        }

        /**
         * Forward any other method calls (e.g. ensureSchema, plannedHooks,
         * getHookManager) to the namespaced instance so PluginManager can
         * introspect the plugin.
         *
         * @param array<int, mixed> $args
         */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on ArchivesPlugin");
        }
    }
}
