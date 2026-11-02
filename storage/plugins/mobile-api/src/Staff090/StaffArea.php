<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090;

/**
 * One Pinakes 0.9.0 staff area of the Mobile API (1.10.0).
 *
 * An area is a class in src/Staff090/Areas named `<Name>Area.php`. AreaRegistry
 * finds it on disk, MobileApiPlugin mounts its routes behind the usual
 * auth → quota → staff-role chain, HealthController advertises
 * `staff_<area>` and OpenApiController merges its paths. Adding an area
 * therefore touches no shared file.
 *
 * A route is `[METHOD, '/staff/...', handler]` plus optional keys:
 *   'limit'       => [max, windowSeconds, actionKey]  throttle
 *   'feature'     => Features key                     overrides feature()
 *   'circulation' => bool                             overrides circulation()
 * The handler is `fn (\mysqli $db, $request, $response, array $args)`.
 */
interface StaffArea
{
    /** Health flag suffix: `staff_<area>`. */
    public static function area(): string;

    /**
     * App\Support\Features key that gates every route of the area: when it is
     * off the route answers 404 feature_disabled. Null = always available
     * (the controllers still apply their own admin checks).
     */
    public static function feature(): ?string;

    /** True when the area is part of circulation (404 in catalogue mode). */
    public static function circulation(): bool;

    /**
     * @return list<array{0: string, 1: string, 2: callable, limit?: array{0: int, 1: int, 2: string}, feature?: string|null, circulation?: bool}>
     */
    public static function routes(): array;

    /**
     * Adds the area's paths (and schemas) to the OpenAPI document.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function openApi(array $doc): array;
}
