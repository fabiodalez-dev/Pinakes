<?php

declare(strict_types=1);

namespace App\Plugins\BookClub\Modules;

/**
 * A Book Club module: a self-contained feature (reading tracker,
 * discussions, gamification, …) discovered automatically by the Registry
 * from src/Modules/*Module.php.
 *
 * Modules are toggled PER CLUB via bookclub_clubs.settings['modules']
 * (a list of enabled slugs; when absent, defaultEnabled() applies).
 * Route handlers must therefore re-check enablement for the club they
 * operate on — Registry::clubEnabled() — because routes are registered
 * globally once.
 */
interface ModuleInterface
{
    /** Stable machine name, e.g. 'reading'. */
    public function slug(): string;

    /** Translated human name for the admin checkbox list. */
    public function label(): string;

    /** One-line translated description for the admin checkbox list. */
    public function description(): string;

    public function defaultEnabled(): bool;

    /**
     * Idempotent DDL (CREATE TABLE IF NOT EXISTS + guarded ALTERs).
     * Called from BOTH onInstall() and onActivate() of the plugin.
     *
     * @return array{created: list<string>, failed: list<string>}
     */
    public function ensureSchema(): array;

    /**
     * Attach the module's Slim routes. Called once per request at
     * app.routes.register time, regardless of per-club enablement.
     *
     * @param \Slim\App<\Psr\Container\ContainerInterface|null> $app
     */
    public function registerRoutes($app): void;

    /**
     * HTML panel for the main column of the public club page ('' = none).
     *
     * @param array<string, mixed> $ctx  club, states, membership, isMember,
     *                                   canManage, loggedIn, csrf
     */
    public function renderClubPanel(array $ctx): string;

    /**
     * HTML block for the sidebar of the public club page ('' = none).
     *
     * @param array<string, mixed> $ctx
     */
    public function renderClubSidebar(array $ctx): string;

    /** Scheduled work, invoked by the plugin's maintenance tick. */
    public function onMaintenanceTick(): void;
}
