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
 * EXTENSION POINTS — fines() (patron ledger), loansByPatronType() (circulation
 * policy) and stocktakeOutcomes() have their built-in provider. Each can still be filled or overridden, either by replacing the
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
     * Loans in the last 12 months grouped by patron type (tipologie_lettore).
     * A reader without a type counts under the default type, as the
     * circulation policy treats them. Every active type is listed, also with
     * no loans; a deactivated type only when it has loans in the period. A
     * filter on statistics.section.patron_types still wins; no patron types
     * schema (upgrade not run yet) hides the section.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function loansByPatronType(mysqli $db): ?array
    {
        $hooked = self::tableSection(Hooks::apply('statistics.section.patron_types', null, [$db]));
        if ($hooked !== null) {
            return $hooked;
        }
        $policy = new \App\Services\CirculationPolicy($db);
        if (!$policy->schemaReady()) {
            return null;
        }
        $types = $policy->types();
        if ($types === []) {
            return null;
        }
        $defaultId = $policy->defaultTypeId() ?? 0;
        $states = "'" . implode("','", self::CIRCULATED_STATES) . "'";
        $sql = "SELECT COALESCE(u.tipologia_id, {$defaultId}) AS tipologia, COUNT(p.id) AS totale
                FROM prestiti p
                JOIN libri l ON l.id = p.libro_id AND l.deleted_at IS NULL
                LEFT JOIN utenti u ON u.id = p.utente_id
                WHERE p.stato IN ({$states})
                  AND p.data_prestito >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY COALESCE(u.tipologia_id, {$defaultId})";
        $counts = [];
        $res = $db->query($sql);
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $counts[(int) $row['tipologia']] = (int) $row['totale'];
            }
            $res->free();
        }
        $rows = [];
        foreach ($types as $id => $type) {
            $count = $counts[$id] ?? 0;
            if ((int) $type['attiva'] !== 1 && $count === 0) {
                continue;
            }
            $rows[] = ['label' => (string) $type['nome'], 'value' => $count];
        }
        $defaultName = $defaultId > 0 && isset($types[$defaultId]) ? (string) $types[$defaultId]['nome'] : '';
        return [
            'rows' => $rows,
            'note' => $defaultName !== ''
                ? sprintf(__('Ultimi 12 mesi. I lettori senza tipologia sono contati in «%s», la tipologia predefinita.'), $defaultName)
                : __('Ultimi 12 mesi.'),
        ];
    }

    /**
     * Fines over the last 12 months (charged, collected, waived, reversed)
     * and what readers owe today, from the patron ledger (movimenti_lettore).
     * A filter on statistics.section.fines still wins. Hidden (NULL) before
     * the ledger table exists and on an install that has never configured a
     * fine nor written a ledger row: zero configuration, same page as before.
     *
     * @return array{rows: list<array{label: string, value: int|float|string}>, note?: string}|null
     */
    public static function fines(mysqli $db): ?array
    {
        $hooked = self::tableSection(Hooks::apply('statistics.section.fines', null, [$db]));
        if ($hooked !== null) {
            return $hooked;
        }
        $exists = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore' LIMIT 1");
        if (!$exists instanceof \mysqli_result || $exists->num_rows === 0) {
            return null;
        }
        $any = $db->query('SELECT 1 FROM movimenti_lettore LIMIT 1');
        $hasRows = $any instanceof \mysqli_result && $any->num_rows > 0;
        if (!$hasRows && !self::anyFineConfigured($db)) {
            return null;
        }
        $to = DateHelper::today();
        $from = (new \DateTimeImmutable($to))->modify('-12 months')->modify('+1 day')->format('Y-m-d');
        $sum = ['addebitato' => 0, 'incassato' => 0, 'condonato' => 0, 'stornato' => 0];
        foreach (\App\Services\PatronLedger::forDb($db)->periodTotals($from, $to) as $month) {
            foreach ($sum as $k => $v) {
                $sum[$k] = $v + (int) $month[$k];
            }
        }
        $outstanding = 0;
        $debtors = 0;
        $res = $db->query('SELECT SUM(saldo), COUNT(*) FROM (SELECT SUM(importo) AS saldo FROM movimenti_lettore GROUP BY utente_id HAVING SUM(importo) > 0) d');
        if ($res instanceof \mysqli_result) {
            $row = $res->fetch_row();
            $outstanding = \App\Services\FineParameters::decimalToCents($row[0] ?? null);
            $debtors = (int) ($row[1] ?? 0);
        }
        $fmt = static fn (int $cents): string => \App\Services\FineParameters::format($cents);
        return [
            'rows' => [
                ['label' => __('Addebitato'), 'value' => $fmt($sum['addebitato'])],
                ['label' => __('Incassato'), 'value' => $fmt($sum['incassato'])],
                ['label' => __('Condonato'), 'value' => $fmt($sum['condonato'])],
                ['label' => __('Stornato'), 'value' => $fmt($sum['stornato'])],
                ['label' => __('Saldo da incassare'), 'value' => $fmt($outstanding)],
                ['label' => __('Lettori con saldo a debito'), 'value' => $debtors],
            ],
            'note' => __('Ultimi 12 mesi; il saldo da incassare è quello di oggi. Il dettaglio mese per mese è nel report delle multe più in basso.'),
        ];
    }

    /** Whether a daily fine is set anywhere: globally, on a patron type or in the rules matrix. */
    private static function anyFineConfigured(mysqli $db): bool
    {
        $policy = new \App\Services\CirculationPolicy($db);
        if (\App\Services\FineParameters::toCents((string) ($policy->globalLayer()['daily_fine'] ?? '')) > 0) {
            return true;
        }
        foreach ($policy->types() as $type) {
            if ((float) ($type['multa_giornaliera'] ?? 0) > 0) {
                return true;
            }
        }
        foreach ($policy->rules() as $rule) {
            if ((float) ($rule['multa_giornaliera'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
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
