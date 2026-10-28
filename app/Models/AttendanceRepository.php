<?php
declare(strict_types=1);

namespace App\Models;

use App\Support\DateHelper;
use mysqli;

/**
 * Visitor log (`presenze`): one row per visit, either a member identified by
 * the library card (`tipo = 'socio'`) or an anonymous/self-declared guest
 * (`tipo = 'ospite'`).
 *
 * Timestamps are written and compared in the application timezone (through
 * DateHelper), never with SQL NOW(), so the day and hour-slot statistics match
 * the library's wall clock whatever the database session timezone is.
 */
class AttendanceRepository
{
    public const SETTINGS_CATEGORY = 'attendance';
    public const SETTING_RETENTION = 'retention_days';
    /** GDPR-minded default: one year of visits, then the rows are purged. */
    public const DEFAULT_RETENTION_DAYS = 365;
    public const MAX_RETENTION_DAYS = 3650;

    public function __construct(private mysqli $db)
    {
    }

    public function isAvailable(): bool
    {
        try {
            $res = $this->db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'presenze'");
            return $res instanceof \mysqli_result && $res->num_rows > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Find the member owning a card code (exact match, surrounding blanks ignored).
     *
     * @return array{id:int, nome:string, cognome:string, stato:string}|null
     */
    public function findMemberByCard(string $card): ?array
    {
        $card = trim($card);
        if ($card === '' || mb_strlen($card) > 20) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, nome, cognome, stato FROM utenti WHERE codice_tessera = ? LIMIT 1');
        $stmt->bind_param('s', $card);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'cognome' => (string) $row['cognome'],
            'stato' => (string) ($row['stato'] ?? 'attivo'),
        ];
    }

    public function recordMember(int $userId, ?int $sedeId): int
    {
        return $this->insert('socio', $userId, $sedeId, null, null);
    }

    public function recordGuest(?string $name, ?string $reason, ?int $sedeId): int
    {
        return $this->insert('ospite', null, $sedeId, self::clip($name, 150), self::clip($reason, 255));
    }

    /**
     * Active branches (sedi) for the kiosk selector; empty on single-site installs.
     *
     * @return array<int, string>
     */
    public function branches(): array
    {
        $out = [];
        try {
            $res = $this->db->query('SELECT id, nome FROM sedi WHERE is_active = 1 ORDER BY nome ASC');
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $out[(int) $row['id']] = (string) $row['nome'];
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }

    /** A submitted branch id that exists and is active, or NULL. */
    public function validBranchId(mixed $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }
        return array_key_exists($id, $this->branches()) ? $id : null;
    }

    /**
     * Visits per day over the last $days days (today included), oldest first,
     * with zero rows for days without visits.
     *
     * @return list<array{giorno:string, soci:int, ospiti:int, totale:int}>
     */
    public function dailyCounts(int $days): array
    {
        [$from, $to] = self::window($days);
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS giorno,
                    SUM(tipo = 'socio') AS soci,
                    SUM(tipo = 'ospite') AS ospiti,
                    COUNT(*) AS totale
             FROM presenze
             WHERE created_at >= ? AND created_at < ?
             GROUP BY DATE(created_at)"
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $byDay = [];
        while ($row = $res->fetch_assoc()) {
            $byDay[(string) $row['giorno']] = $row;
        }
        $stmt->close();

        $out = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 10));
        for ($i = 0; $i < self::clampDays($days); $i++) {
            $day = $cursor->modify('+' . $i . ' day')->format('Y-m-d');
            $row = $byDay[$day] ?? null;
            $out[] = [
                'giorno' => $day,
                'soci' => (int) ($row['soci'] ?? 0),
                'ospiti' => (int) ($row['ospiti'] ?? 0),
                'totale' => (int) ($row['totale'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Visits per hour slot (0–23) over the last $days days.
     *
     * @return array<int, int>
     */
    public function hourlyCounts(int $days): array
    {
        [$from, $to] = self::window($days);
        $stmt = $this->db->prepare(
            'SELECT HOUR(created_at) AS ora, COUNT(*) AS totale
             FROM presenze
             WHERE created_at >= ? AND created_at < ?
             GROUP BY HOUR(created_at)'
        );
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = array_fill(0, 24, 0);
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['ora']] = (int) $row['totale'];
        }
        $stmt->close();
        return $out;
    }

    /**
     * @return array{soci:int, ospiti:int, totale:int}
     */
    public function totals(int $days): array
    {
        $totals = ['soci' => 0, 'ospiti' => 0, 'totale' => 0];
        foreach ($this->dailyCounts($days) as $row) {
            $totals['soci'] += $row['soci'];
            $totals['ospiti'] += $row['ospiti'];
            $totals['totale'] += $row['totale'];
        }
        return $totals;
    }

    /**
     * Latest visits for the kiosk feed. Member names are shortened to
     * "Name S." so the screen facing the desk shows as little as possible.
     *
     * @return list<array{created_at:string, tipo:string, etichetta:string}>
     */
    public function recent(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $res = $this->db->query(
            'SELECT p.created_at, p.tipo, p.nome_ospite, u.nome, u.cognome
             FROM presenze p
             LEFT JOIN utenti u ON u.id = p.utente_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . $limit
        );
        $out = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $label = $row['tipo'] === 'socio'
                    ? self::shortName((string) ($row['nome'] ?? ''), (string) ($row['cognome'] ?? ''))
                    : trim((string) ($row['nome_ospite'] ?? ''));
                $out[] = ['created_at' => (string) $row['created_at'], 'tipo' => (string) $row['tipo'], 'etichetta' => $label];
            }
        }
        return $out;
    }

    /**
     * Delete visits older than the retention window. 0 days = keep forever.
     * The cut-off is midnight (application timezone) $retentionDays days ago,
     * so a visit is kept for at least $retentionDays whole days.
     */
    public function purge(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        $cutoff = (new \DateTimeImmutable(DateHelper::today()))
            ->modify('-' . min($retentionDays, self::MAX_RETENTION_DAYS) . ' day')
            ->format('Y-m-d 00:00:00');
        $stmt = $this->db->prepare('DELETE FROM presenze WHERE created_at < ?');
        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $deleted = (int) $stmt->affected_rows;
        $stmt->close();
        return max(0, $deleted);
    }

    /** Configured retention in days (0 = keep forever). */
    public static function retentionDays(SettingsRepository $settings): int
    {
        $raw = $settings->get(self::SETTINGS_CATEGORY, self::SETTING_RETENTION, null);
        if ($raw === null || trim($raw) === '' || !is_numeric($raw)) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        return self::normalizeRetention($raw);
    }

    /** Clamp a submitted retention to 0..MAX_RETENTION_DAYS (non-numeric → default). */
    public static function normalizeRetention(mixed $raw): int
    {
        $days = filter_var($raw, FILTER_VALIDATE_INT);
        // Not a number, or negative: the prudent default, never 0 (= keep
        // forever), so a typo cannot switch the GDPR purge off.
        if ($days === false || $days < 0) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        return max(0, min(self::MAX_RETENTION_DAYS, $days));
    }

    /** "Mario Rossi" → "Mario R." */
    public static function shortName(string $first, string $last): string
    {
        $first = trim($first);
        $last = trim($last);
        if ($last === '') {
            return $first;
        }
        return trim($first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.');
    }

    public static function clampDays(int $days): int
    {
        return max(1, min(366, $days));
    }

    /**
     * [from, to) bounds of the last $days days in the application timezone.
     *
     * @return array{0:string, 1:string}
     */
    public static function window(int $days): array
    {
        $today = new \DateTimeImmutable(DateHelper::today());
        $from = $today->modify('-' . (self::clampDays($days) - 1) . ' day')->format('Y-m-d 00:00:00');
        $to = $today->modify('+1 day')->format('Y-m-d 00:00:00');
        return [$from, $to];
    }

    private function insert(string $type, ?int $userId, ?int $sedeId, ?string $guestName, ?string $reason): int
    {
        $now = DateHelper::now();
        $stmt = $this->db->prepare('INSERT INTO presenze (utente_id, sede_id, tipo, nome_ospite, motivo, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iissss', $userId, $sedeId, $type, $guestName, $reason, $now);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    private static function clip(?string $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
