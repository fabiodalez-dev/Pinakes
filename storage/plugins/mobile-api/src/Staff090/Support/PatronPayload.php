<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Services\CirculationPolicy;
use App\Services\FineParameters;
use App\Services\PatronLedger;
use App\Support\Features;
use App\Support\PatronPhoto;
use mysqli;

/**
 * The Pinakes 0.9.0 fields of a StaffUser (GET /staff/users, /staff/users/{id}):
 * patron type, photo, ledger balance and whether the caller may edit the
 * record. Added beside the 1.7.0 fields, never replacing one.
 */
final class PatronPayload
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row utenti row with id, tipo_utente, ruolo_id,
     *                                  accesso_digitale, tipologia_id, foto_profilo_url
     * @return array<string, mixed>
     */
    public static function extras(mysqli $db, array $row, CirculationPolicy $policy): array
    {
        $id = (int) $row['id'];

        return [
            'patron_type' => self::patronType($row, $policy),
            'has_photo'   => self::photoUrl($id, $row['foto_profilo_url'] ?? null) !== null,
            'photo_url'   => self::photoUrl($id, $row['foto_profilo_url'] ?? null),
            'ledger'      => self::ledger($db, $id),
            'can_edit'    => self::canEdit($row),
        ];
    }

    /**
     * Effective patron type ({id, code, name, assigned}); `assigned` is false
     * when the account has none of its own and falls back to the default
     * type. Null while circulation rules are off (or not migrated).
     *
     * @param array<string, mixed> $row
     * @return array{id:int, code:string, name:string, assigned:bool}|null
     */
    public static function patronType(array $row, CirculationPolicy $policy): ?array
    {
        if (!$policy->rulesActive()) {
            return null;
        }
        $types = $policy->types();
        $own = isset($row['tipologia_id']) ? (int) $row['tipologia_id'] : null;
        $assigned = $own !== null && isset($types[$own]);
        $id = $assigned ? $own : $policy->defaultTypeId();
        if ($id === null || !isset($types[$id])) {
            return null;
        }

        return [
            'id'       => $id,
            'code'     => (string) ($types[$id]['codice'] ?? ''),
            'name'     => (string) ($types[$id]['nome'] ?? ''),
            'assigned' => $assigned,
        ];
    }

    /**
     * Authorised download URL of the photo (GET /staff/users/{id}/photo, bearer
     * token), versioned so a new photo never reuses a cached one. Null when
     * there is none or the photo module is off.
     */
    public static function photoUrl(int $userId, mixed $stored): ?string
    {
        if (!Features::enabled('patron_photos') || !is_string($stored) || PatronPhoto::absolutePath($stored) === null) {
            return null;
        }

        return absoluteUrl('/api/v1/staff/users/' . $userId . '/photo') . '?v=' . substr(sha1($stored), 0, 10);
    }

    /**
     * Balance summary when the fines module is on, else null.
     *
     * @return array{balance_cents:int, currency:string, debt_blocked:bool}|null
     */
    public static function ledger(mysqli $db, int $userId): ?array
    {
        if (!PatronLedger::active($db)) {
            return null;
        }

        return [
            'balance_cents' => PatronLedger::forDb($db)->balanceCents($userId),
            'currency'      => FineParameters::forUser($db, $userId)->currency,
            'debt_blocked'  => PatronLedger::isDebtBlocked($db, $userId),
        ];
    }

    /**
     * The web user editor's rule (UsersController::editForm/update):
     * users.manage and UserRoleAssignment::canEdit() — an administrator edits
     * every account, a Staff account only its own.
     *
     * @param array<string, mixed> $row
     */
    public static function canEdit(array $row): bool
    {
        return \App\Support\Permissions::can('users.manage') && \App\Support\UserRoleAssignment::canEdit($row);
    }
}
