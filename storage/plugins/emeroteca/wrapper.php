<?php

/**
 * Emeroteca plugin wrapper.
 *
 * Every plugin in BundledPlugins::LIST ships main_file = wrapper.php — the
 * loading convention pinned by tests/plugin-integrity.spec.js. The real
 * EmerotecaPlugin class already lives in the global namespace (the name
 * PluginManager::getPluginClassName('emeroteca') resolves to), so this
 * wrapper only needs to load its definition.
 */

require_once __DIR__ . '/EmerotecaPlugin.php';
