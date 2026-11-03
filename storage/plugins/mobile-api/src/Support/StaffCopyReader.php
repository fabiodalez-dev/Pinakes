<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Support\HtmlHelper;
use mysqli;

/**
 * Read model of one physical copy (`copie`) for the staff area (StaffCopy,
 * Mobile API 1.7.0). `held` is the same predicate as CopyController::isCopyHeld();
 * `deletable` mirrors the three refusals of CopyController::deleteCopy() (held,
 * still in circulation, any loan history).
 */
final class StaffCopyReader
{
    /** States a copy may be deleted from (CopyController::deleteCopy). */
    public const OUT_OF_CIRCULATION = ['perso', 'danneggiato', 'manutenzione', 'in_restauro', 'in_trasferimento'];

    private const SELECT = "SELECT c.id, c.libro_id, c.numero_inventario, c.stato, c.note, c.created_at, c.updated_at, {consultation},
               l.titolo, l.copertina_url,
               EXISTS(SELECT 1 FROM prestiti p WHERE p.copia_id = c.id
                       AND ((p.attivo = 1 AND p.stato IN ('prenotato','da_ritirare','in_corso','in_ritardo'))
                            OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL))) AS held,
               EXISTS(SELECT 1 FROM prestiti p WHERE p.copia_id = c.id) AS has_history,
               (SELECT CONCAT(p.id, '|', COALESCE(p.data_scadenza, ''), '|', u.nome, ' ', u.cognome)
                  FROM prestiti p JOIN utenti u ON u.id = p.utente_id
                 WHERE p.copia_id = c.id AND p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo')
                 ORDER BY p.id DESC LIMIT 1) AS on_loan
          FROM copie c
          JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL";

    /** Whether consultation-only copies apply (module on, 0.9.0 column present), memoised. */
    private ?bool $consultation = null;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Consultation-only copies are in force: the module is on (Settings ->
     * Loans, also off in catalogue mode) and the 0.9.0 column exists. Off,
     * every copy reads consultation_only false and none can be changed.
     */
    public function consultationActive(): bool
    {
        return $this->consultation ??= \App\Support\Features::enabled('consultation_only')
            && (new \App\Services\CirculationPolicy($this->db))->schemaReady();
    }

    /** SELECT with the consultation flag, or a constant before the migration / with the module off. */
    private function select(): string
    {
        return str_replace('{consultation}', $this->consultationActive() ? 'c.solo_consultazione' : '0 AS solo_consultazione', self::SELECT);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'disponibile'      => __('Disponibile'),
            'prestato'         => __('Prestato'),
            'prenotato'        => __('Prenotato'),
            'manutenzione'     => __('Manutenzione'),
            'in_restauro'      => __('In restauro'),
            'perso'            => __('Perso'),
            'danneggiato'      => __('Danneggiato'),
            'in_trasferimento' => __('In trasferimento'),
        ];
    }

    /** @return array<string, mixed>|null StaffCopy */
    public function copy(int $id): ?array
    {
        $rows = $this->query($this->select() . ' WHERE c.id = ? LIMIT 1', 'i', [$id]);

        return isset($rows[0]) ? $this->map($rows[0]) : null;
    }

    /** @return array<string, mixed>|null raw copie row (no soft-delete join: the copy itself) */
    public function row(int $id): ?array
    {
        $rows = $this->query('SELECT c.* FROM copie c JOIN libri l ON l.id = c.libro_id AND l.deleted_at IS NULL WHERE c.id = ? LIMIT 1', 'i', [$id]);

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function forBook(int $bookId): array
    {
        $rows = $this->query($this->select() . ' WHERE c.libro_id = ? ORDER BY c.numero_inventario ASC, c.id ASC', 'i', [$bookId]);

        return array_map(fn (array $r): array => $this->map($r), $rows);
    }

    /** @return array<string, mixed>|null copie row by inventory code */
    public function byCode(string $code): ?array
    {
        $rows = $this->query($this->select() . ' WHERE c.numero_inventario = ? LIMIT 1', 's', [$code]);

        return isset($rows[0]) ? $this->map($rows[0]) : null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function map(array $r): array
    {
        $status = (string) ($r['stato'] ?? '');
        $labels = self::labels();
        $held = (int) ($r['held'] ?? 0) === 1;
        $onLoan = null;
        if (is_string($r['on_loan'] ?? null) && $r['on_loan'] !== '') {
            $parts = explode('|', $r['on_loan'], 3);
            $onLoan = [
                'loan_id'   => (int) $parts[0],
                'user_name' => HtmlHelper::decode(trim($parts[2] ?? '')),
                'due_at'    => ($parts[1] ?? '') !== '' ? $parts[1] : null,
            ];
        }

        return [
            'id'               => (int) $r['id'],
            'inventory_number' => (string) ($r['numero_inventario'] ?? ''),
            'status'           => $status,
            'status_label'     => $labels[$status] ?? $status,
            'notes'            => StaffLoanReader::str($r['note'] ?? null),
            'book'             => [
                'id'        => (int) $r['libro_id'],
                'title'     => HtmlHelper::decode((string) ($r['titolo'] ?? '')),
                'cover_url' => absoluteUrl(StaffLoanReader::coverPath($r['copertina_url'] ?? null)),
            ],
            'held'             => $held,
            // Kept for in-house consultation, never lent (Pinakes 0.9.0).
            'consultation_only' => (int) ($r['solo_consultazione'] ?? 0) === 1,
            'deletable'        => !$held
                && in_array($status, self::OUT_OF_CIRCULATION, true)
                && (int) ($r['has_history'] ?? 0) === 0,
            'on_loan'          => $onLoan,
            'created_at'       => StaffLoanReader::str($r['created_at'] ?? null),
            'updated_at'       => StaffLoanReader::str($r['updated_at'] ?? null),
        ];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
