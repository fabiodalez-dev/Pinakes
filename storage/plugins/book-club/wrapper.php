<?php

/**
 * Book Club plugin wrapper.
 *
 * PluginManager::getPluginClassName('book-club') → 'BookClubPlugin' (no
 * namespace), so we expose a thin forwarding class in the global namespace
 * that constructs the real namespaced plugin, mirroring the Archives and
 * Discogs wrappers.
 */

require_once __DIR__ . '/BookClubPlugin.php';

if (!class_exists('BookClubPlugin', false)) {
    class BookClubPlugin
    {
        /** @var \App\Plugins\BookClub\BookClubPlugin */
        private $instance;

        public function __construct(mysqli $db, \App\Support\HookManager $hookManager)
        {
            $this->instance = new \App\Plugins\BookClub\BookClubPlugin($db, $hookManager);
        }

        public function onInstall(): void
        {
            $this->instance->onInstall();
        }

        public function onActivate(): void
        {
            $this->instance->onActivate();
        }

        public function onDeactivate(): void
        {
            $this->instance->onDeactivate();
        }

        public function onUninstall(): void
        {
            $this->instance->onUninstall();
        }

        /**
         * Forward any other call (setPluginId, registerRoutes,
         * renderAdminMenuEntry, onMaintenanceTick, ensureSchema, …) to the
         * namespaced instance so PluginManager and HookManager can invoke
         * the callbacks registered in plugin_hooks.
         *
         * @param array<int, mixed> $args
         */
        public function __call(string $method, array $args): mixed
        {
            if (is_callable([$this->instance, $method])) {
                return call_user_func_array([$this->instance, $method], $args);
            }
            throw new \BadMethodCallException("Method {$method} does not exist on BookClubPlugin");
        }
    }
}
