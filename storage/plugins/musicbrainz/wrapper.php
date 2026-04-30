<?php
declare(strict_types=1);

/**
 * MusicBrainz plugin wrapper.
 *
 * Like the Deezer plugin, this is metadata-only. The real MusicBrainz /
 * Cover Art Archive calls happen inside
 * storage/plugins/discogs/DiscogsPlugin.php (Discogs plugin falls back to
 * MusicBrainz when the Discogs release lookup fails). The standalone entry
 * exists for admin-panel visibility and documentation of API endpoints.
 *
 * No-op stub: if someone enables the plugin via the admin UI, the app
 * doesn't crash on "Main file not found". When we ship real standalone
 * MusicBrainz logic (hooks separated from Discogs), replace this stub.
 */

if (!class_exists(MusicBrainzPlugin::class, false)) {
    class MusicBrainzPlugin
    {
        public function __construct() {}

        public function onActivate(): void {}
        public function onDeactivate(): void {}
    }
}

return new MusicBrainzPlugin();
