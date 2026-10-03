<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where a journal or newspaper article belongs, told from the book form.
 *
 * `tipo_media` lists Book, Record, Audiobook, DVD and Other: a cataloguer with
 * a single article in hand looks there, finds nothing that fits and stops
 * (issue #412). Standalone articles live in the Emeroteca plugin, which is
 * optional and inactive on a fresh install — so the hint cannot come from a
 * hook: hooks only fire when the plugin is already active, which is exactly
 * the case that needs no hint. The core therefore asks the plugins table
 * directly, the way the admin layout and the CSV import page already do.
 *
 * Three states, because pointing at something that is not there is worse than
 * saying nothing: ACTIVE links to the article form, INACTIVE to the plugins
 * page, and ABSENT renders nothing at all — the plugin was uninstalled (its
 * row and directory are both removed) or was never shipped.
 */
final class PeriodicalArticlesHint
{
    /** The plugin is active: the article form is reachable. */
    public const ACTIVE = 'active';
    /** Installed but switched off: the operator can turn it on. */
    public const INACTIVE = 'inactive';
    /** Not installed: say nothing rather than offer a dead end. */
    public const ABSENT = 'absent';

    private const PLUGIN = 'emeroteca';

    /**
     * The state already resolved in this request for the real plugins
     * directory. The book pages resolve it with their own `$db`; the layout
     * around them then reuses it instead of opening a second connection to
     * run the same query again.
     */
    private static ?string $resolved = null;

    private function __construct()
    {
    }

    /**
     * The state for the admin layout, which renders on every admin page.
     *
     * In order: the state the page inside it already resolved; the connection
     * the rendering controller holds, which the layout passes when one is in
     * scope; and only then ConfigStore's standalone connection. That last one
     * is lazily opened and, with the settings served from the cache, is
     * usually not open yet: reaching for it first would cost most admin pages
     * a second MySQL connection just to draw the sidebar.
     */
    public static function stateForLayout(?\mysqli $db = null): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        if ($db instanceof \mysqli) {
            return self::state($db);
        }
        try {
            return self::state(ConfigStore::sharedConnection());
        } catch (\Throwable $e) {
            return self::ABSENT;
        }
    }

    /**
     * Resolve the state without ever breaking the page that asks.
     *
     * A missing plugins table, a failed statement or a deleted plugin
     * directory all resolve to ABSENT: the book form must render whatever
     * happens to the plugin registry.
     */
    public static function state(?\mysqli $db, ?string $pluginsDir = null): string
    {
        $state = self::resolve($db, $pluginsDir);
        if ($pluginsDir === null) {
            self::$resolved = $state;
        }
        return $state;
    }

    /**
     * Read the plugin's state from its row in `plugins`: ACTIVE or INACTIVE
     * when the row exists and its directory is on disk, ABSENT otherwise. A
     * missing connection or any database error also reads as ABSENT, so the
     * sidebar hides the link rather than breaking the page.
     */
    private static function resolve(?\mysqli $db, ?string $pluginsDir): string
    {
        if (!$db instanceof \mysqli) {
            return self::ABSENT;
        }
        try {
            $stmt = $db->prepare('SELECT is_active FROM plugins WHERE name = ? LIMIT 1');
            if ($stmt === false) {
                return self::ABSENT;
            }
            $name = self::PLUGIN;
            $stmt->bind_param('s', $name);
            if (!$stmt->execute()) {
                $stmt->close();
                return self::ABSENT;
            }
            $result = $stmt->get_result();
            $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($row === null) {
                return self::ABSENT;
            }
            // Uninstalling deletes the row AND the directory, so a row without
            // files means a half-removed install: its admin routes are not
            // registered, and a link to them would 404.
            $dir = rtrim($pluginsDir ?? dirname(__DIR__, 2) . '/storage/plugins', '/') . '/' . self::PLUGIN;
            if (!is_dir($dir)) {
                return self::ABSENT;
            }
            return (int)($row['is_active'] ?? 0) === 1 ? self::ACTIVE : self::INACTIVE;
        } catch (\Throwable $e) {
            return self::ABSENT;
        }
    }
}
