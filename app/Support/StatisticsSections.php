<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\AttendanceRepository;
use mysqli;

/**
 * Extra sections of /admin/statistics (0.9.0).
 *
 * Every section is one static function. A function returning NULL hides its
 * section, so a feature that is not installed (no table yet) simply does not
 * appear and the page keeps rendering.
 *
 * EXTENSION POINTS — loansByPatronType(), fines() and stocktakeOutcomes() are
 * placeholders that other 0.9.0 branches fill in, either by replacing the
 * body of the function or by registering a filter on its hook
 * (statistics.section.patron_types / .fines / .stocktake, initial value NULL,
 * argument the mysqli connection). They must keep the same signature and
 * return either NULL or the generic table shape:
 *
 *   array{rows: list<array{label: string, value: int|float|string}>, note?: string}
 *
 * which the view renders as a two-column table (label, value). Labels are
 * already translated by the provider.
 */
final class StatisticsSections
{
    /** Loan states that mean the item actually left the library. */
    public const CIRCULATED_STATES = ['in_corso', 'in_ritardo', 'restituito', 'perso', 'danneggiato'];

    /**
     * Visitors from the attendance log over the last $days days.
     *
     * @return array{days:int, soci:int, ospiti:int, totale:int, daily:list<array{giorno:string, soci:int, ospiti:int, totale:int}>}|null
     */
    public static function visitors(mysqli $db, int $days = 30): ?array
    {
        try {
            $repo = new AttendanceRepository($db);
            if (!$repo->isAvailable()) {
                return null;
            }
            $daily = $repo->dailyCounts($days);
        } catch (\Throwable $e) {
            SecureLogger::warning('Statistics visitors section unavailable', ['error' => $e->getMessage()]);
            return null;
        }
        $totals = ['soci' => 0, 'ospiti' => 0, 'totale' => 0];
        foreach ($daily as $row) {
            $totals['soci'] += $row['soci'];
            $totals['ospiti'] += $row['ospiti'];
            $totals['totale'] += $row['totale'];
        }
        return ['days' => AttendanceRepository::clampDays($days)] + $totals + ['daily' => $daily];
    }

    /**
     * Loans in the last 12 months grouped by material (libri.tipo_media),
     * every media type listed even when it has no loans.
     *
     * @return list<array{tipo_media:string, label:string, icon:string, totale:int}>
     */
    public static function loansByMediaType(mysqli $db): array
    {
        $states = "'" . implode("','", self::CIRCULATED_STATES) . "'";
        $sql = "SELECT COALESCE(NULLIF(l.tipo_media, ''), 'libro') AS tipo_media, COUNT(p.id) AS totale
                FROM prestiti p
                JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
                WHERE p.stato IN ({$states})
                  AND p.data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY COALESCE(NULLIF(l.tipo_media, ''), 'libro')";
        $counts = [];
        $res = $db->query($sql);
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $counts[(string) $row['tipo_media']] = (int) $row['totale'];
            }
        }
        $out = [];
        foreach (MediaLabels::allTypes() as $key => $meta) {
            $out[] = [
                'tipo_media' => $key,
                'label' => __($meta['label']),
                'icon' => $meta['icon'],
                'totale' => $counts[$key] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * EXTENSION POINT — loans by patron type (tipologie_lettore).
     * Filled by the patron-types branch; NULL hides the section.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function loansByPatronType(mysqli $db): ?array
    {
        return self::tableSection(Hooks::apply('statistics.section.patron_types', null, [$db]));
    }

    /**
     * EXTENSION POINT — fines (amount charged / paid / outstanding).
     * Filled by the fines branch; NULL hides the section.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function fines(mysqli $db): ?array
    {
        return self::tableSection(Hooks::apply('statistics.section.fines', null, [$db]));
    }

    /**
     * Stocktake outcomes (found / misplaced / on loan / missing) of the last
     * closed session. A filter on statistics.section.stocktake still wins;
     * NULL (no stocktake table, no closed session) hides the section.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function stocktakeOutcomes(mysqli $db): ?array
    {
        $hooked = self::tableSection(Hooks::apply('statistics.section.stocktake', null, [$db]));
        if ($hooked !== null) {
            return $hooked;
        }
        // Built-in provider: the outcomes of the most recently closed
        // stocktake session. No stocktake table or no closed session hides
        // the section.
        $exists = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventario_sessioni' LIMIT 1");
        if (!$exists instanceof \mysqli_result || $exists->num_rows === 0) {
            return null;
        }
        $res = $db->query("SELECT id, nome, chiusa_il, (SELECT COUNT(*) FROM inventario_sessioni WHERE stato = 'chiusa') AS chiuse
                           FROM inventario_sessioni WHERE stato = 'chiusa' ORDER BY chiusa_il DESC, id DESC LIMIT 1");
        $last = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        if (!is_array($last)) {
            return null;
        }
        $summary = (new \App\Services\StocktakeService($db))->summary((int) $last['id']);
        return [
            'rows' => [
                ['label' => __('Copie attese'), 'value' => $summary['attese']],
                ['label' => __('Trovata'), 'value' => $summary['trovate']],
                ['label' => __('Fuori posto'), 'value' => $summary['fuori_posto']],
                ['label' => __('In prestito'), 'value' => $summary['in_prestito']],
                ['label' => __('Mancanti'), 'value' => $summary['mancanti']],
                ['label' => __('Codici sconosciuti'), 'value' => $summary['sconosciute']],
            ],
            'note' => sprintf(
                __('Ultima revisione chiusa: «%s», %s. Revisioni chiuse in totale: %d.'),
                (string) $last['nome'],
                format_date((string) $last['chiusa_il'], true, '/'),
                (int) $last['chiuse']
            ),
        ];
    }

    /**
     * Validate a provider result against the generic table shape; anything
     * else (NULL included) hides the section.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function tableSection(mixed $data): ?array
    {
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            return null;
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || !isset($row['label'], $row['value']) || !is_scalar($row['label'])) {
                continue;
            }
            $value = $row['value'];
            if (!is_int($value) && !is_float($value) && !is_string($value)) {
                continue;
            }
            $rows[] = ['label' => (string) $row['label'], 'value' => $value];
        }
        $out = ['rows' => $rows];
        if (isset($data['note']) && is_string($data['note']) && $data['note'] !== '') {
            $out['note'] = $data['note'];
        }
        return $out;
    }

    /**
     * The extension sections in display order, keyed by a stable id; only the
     * ones whose provider returned data.
     *
     * @return array<string, array{title: string, icon: string, data: array{rows: list<array{label: string, value: int|float|string}>, note?: string}}>
     */
    public static function extensions(mysqli $db): array
    {
        $providers = [
            'patron-types' => [__('Prestiti per tipologia di lettore'), 'fa-id-badge', [self::class, 'loansByPatronType']],
            'fines' => [__('Multe'), 'fa-coins', [self::class, 'fines']],
            'stocktake' => [__('Esiti delle revisioni inventariali'), 'fa-clipboard-check', [self::class, 'stocktakeOutcomes']],
        ];
        $out = [];
        foreach ($providers as $id => [$title, $icon, $callable]) {
            try {
                $data = $callable($db);
            } catch (\Throwable $e) {
                SecureLogger::warning('Statistics extension section failed', ['section' => $id, 'error' => $e->getMessage()]);
                $data = null;
            }
            if (is_array($data)) {
                $out[$id] = ['title' => $title, 'icon' => $icon, 'data' => $data];
            }
        }
        return $out;
    }
}
