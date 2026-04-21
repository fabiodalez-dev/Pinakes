<?php
/**
 * Public index — list of root-level archival_units.
 *
 * @var list<array<string, mixed>> $rows
 * @var int                        $total
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];
$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$archiveBase = \App\Support\RouteTranslator::route('archives');
?>
<section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
    <header class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __("Archivio") ?></h1>
        <p class="text-gray-600 text-sm leading-relaxed">
            <?= __("Consulta i fondi archivistici e le collezioni documentarie. Ogni unità è descritta secondo lo standard ISAD(G) — navigazione gerarchica per fondo, serie, fascicolo, unità.") ?>
        </p>
    </header>

    <?php if (empty($rows)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <p class="text-sm text-yellow-800">
                <strong><?= __("Nessun fondo pubblicato.") ?></strong>
                <?= __("L'archivio non contiene ancora unità di primo livello.") ?>
            </p>
        </div>
    <?php else: ?>
        <p class="text-sm text-gray-500 mb-4">
            <?= sprintf(__("%d unità archivistiche di primo livello."), $total) ?>
        </p>
        <ul class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($rows as $row):
                $level = (string) $row['level'];
                $badgeCls = $levelBadge[$level] ?? 'bg-gray-100 text-gray-800';
                $detailUrl = $e(url($archiveBase . '/' . (int) $row['id']));
                $dateRange = '';
                if (!empty($row['date_start'])) {
                    $dateRange = (string) $row['date_start'];
                    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
                        $dateRange .= '–' . (string) $row['date_end'];
                    }
                }
            ?>
                <li class="bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition p-5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $e($badgeCls) ?>">
                            <?= $e($levelLabel[$level] ?? $level) ?>
                        </span>
                        <span class="text-xs text-gray-400 font-mono"><?= $e((string) $row['reference_code']) ?></span>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">
                        <a href="<?= $detailUrl ?>" class="hover:text-blue-600 hover:underline">
                            <?= $e((string) $row['constructed_title']) ?>
                        </a>
                    </h2>
                    <?php if ($dateRange !== ''): ?>
                        <p class="text-xs text-gray-500 mb-2"><?= $e($dateRange) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($row['scope_content'])): ?>
                        <p class="text-sm text-gray-700 line-clamp-3">
                            <?= $e(mb_substr((string) $row['scope_content'], 0, 200)) ?><?= mb_strlen((string) $row['scope_content']) > 200 ? '…' : '' ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($row['extent'])): ?>
                        <p class="text-xs text-gray-500 mt-2 italic"><?= $e((string) $row['extent']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
