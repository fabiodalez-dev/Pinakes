<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Support;

use App\Models\AttendanceRepository;
use App\Support\Permissions;
use mysqli;

/**
 * API shapes of the visitor log (Mobile API 1.10.0): one recorded visit and
 * the day / totals counters, in English keys.
 */
final class AttendancePayload
{
    private function __construct()
    {
    }

    /** `socio` → member, `ospite` → guest. */
    public static function type(string $tipo): string
    {
        return $tipo === 'socio' ? 'member' : 'guest';
    }

    /**
     * @param array{soci:int, ospiti:int, totale:int} $totals
     * @return array{members:int, guests:int, total:int}
     */
    public static function totals(array $totals): array
    {
        return ['members' => (int) $totals['soci'], 'guests' => (int) $totals['ospiti'], 'total' => (int) $totals['totale']];
    }

    /**
     * @param array{giorno:string, soci:int, ospiti:int, totale:int} $row
     * @return array{date:string, members:int, guests:int, total:int}
     */
    public static function day(array $row): array
    {
        return ['date' => $row['giorno']] + self::totals($row);
    }

    /**
     * Whether the card of an account counts as active at the desk: the kiosk
     * looks at the account status only (attivo); the expiry date is reported
     * beside it.
     */
    public static function cardActive(string $status): bool
    {
        return $status === 'attivo';
    }

    /**
     * One visit (presenze row) as the check-in / guest endpoints answer it.
     * The member is read with the operator's account scope, as the kiosk's
     * recent-visits feed does: outside it, `member` is null.
     *
     * @return array<string, mixed>|null
     */
    public static function visit(mysqli $db, int $id): ?array
    {
        $scope = Permissions::accountScopeSql('u', Permissions::SCOPE_BORROWER);
        $stmt = $db->prepare(
            'SELECT p.id, p.tipo, p.nome_ospite, p.motivo, p.sede_id, p.created_at, s.nome AS sede_nome,
                    u.id AS uid, u.nome, u.cognome, u.codice_tessera, u.stato, u.data_scadenza_tessera, u.foto_profilo_url
               FROM presenze p
               LEFT JOIN sedi s ON s.id = p.sede_id
               LEFT JOIN utenti u ON u.id = p.utente_id' . $scope . '
              WHERE p.id = ? LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('visit lookup prepare failed');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $type = self::type((string) $row['tipo']);
        $member = null;
        $message = __('Presenza dell\'ospite registrata.');
        $warning = null;
        if ($type === 'member') {
            $message = __('Presenza registrata.');
            if ($row['uid'] !== null) {
                $userId = (int) $row['uid'];
                $status = (string) ($row['stato'] ?? 'attivo');
                $name = AttendanceRepository::shortName((string) $row['nome'], (string) $row['cognome']);
                $expiry = is_string($row['data_scadenza_tessera'] ?? null) && $row['data_scadenza_tessera'] !== '' ? substr((string) $row['data_scadenza_tessera'], 0, 10) : null;
                $photo = PatronPayload::photoUrl($userId, $row['foto_profilo_url'] ?? null);
                $active = self::cardActive($status);
                $member = [
                    'id'              => $userId,
                    'short_name'      => $name,
                    'card_code'       => (string) ($row['codice_tessera'] ?? ''),
                    'account_status'  => $status,
                    'card_active'     => $active,
                    'card_expires_at' => $expiry,
                    'card_expired'    => $expiry !== null && $expiry < \App\Support\DateHelper::today(),
                    'has_photo'       => $photo !== null,
                    'photo_url'       => $photo,
                ];
                // The kiosk's two messages (AttendanceController::scan).
                if ($active) {
                    $message = sprintf(__('Benvenuto/a, %s: presenza registrata.'), $name);
                } else {
                    $message = sprintf(__('Presenza registrata per %s, ma la tessera non è attiva.'), $name);
                    $warning = 'card_not_active';
                }
            }
        }

        return [
            'id'         => (int) $row['id'],
            'type'       => $type,
            'created_at' => (string) $row['created_at'],
            'branch'     => $row['sede_id'] !== null ? ['id' => (int) $row['sede_id'], 'name' => (string) ($row['sede_nome'] ?? '')] : null,
            'member'     => $member,
            'guest'      => $type === 'guest' ? [
                'name'   => $row['nome_ospite'] !== null ? (string) $row['nome_ospite'] : null,
                'reason' => $row['motivo'] !== null ? (string) $row['motivo'] : null,
            ] : null,
            'warning'    => $warning,
            'message'    => $message,
        ];
    }
}
