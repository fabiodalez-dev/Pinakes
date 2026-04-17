<?php
declare(strict_types=1);

/**
 * Deezer plugin wrapper.
 *
 * This plugin is metadata-only: the actual Deezer API calls (cover art,
 * tracklist, album search) are performed from inside
 * storage/plugins/discogs/DiscogsPlugin.php as part of the Discogs scraping
 * chain. The Deezer entry exists so the admin panel can show "Deezer is
 * available as a source" and so operators can see which APIs the app reaches.
 *
 * If the plugin is activated via the admin UI, the class below acts as a
 * no-op registration handler — it won't break the app. When we ship real
 * standalone Deezer logic, replace this stub.
 */

if (!class_exists(DeezerPlugin::class, false)) {
    class DeezerPlugin
    {
        public function __construct() {}

        /** No-op: Deezer enrichment is currently handled by DiscogsPlugin. */
        public function onActivate(): void {}
        public function onDeactivate(): void {}
    }
}

return new DeezerPlugin();
