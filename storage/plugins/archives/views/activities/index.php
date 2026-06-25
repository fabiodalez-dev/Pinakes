<?php
/**
 * Archives — Attività (Phase 5 / v0.8.0) — index (list) view.
 *
 * Mirrors the chrome of storage/plugins/archives/views/index.php
 * (breadcrumb, h1 bold gray-900, p-6 max-w-4xl outer wrapper,
 * "+ Nuovo" button on the right, table with `hover:bg-gray-50 border-b`
 * rows). All strings are Italian source language with __() wrappers
 * so en_US / fr_FR / de_DE translations land in the locale JSON files.
 *
 * @var list<array<string, mixed>>|null $rows
 * @var list<string>|null               $types
 */
declare(strict_types=1);

$e     = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rows  = $rows  ?? [];
$types = $types ?? [];

$typeLabel = [
    'function'    => __('Funzione'),
    'activity'    => __('Attività'),
    'transaction' => __('Transazione'),
    'task'        => __('Compito'),
    'mandate'     => __('Mandato'),
];
$typeBadge = [
    'function'    => 'bg-blue-100 text-blue-800',
    'activity'    => 'bg-blue-100 text-blue-800',
    'transaction' => 'bg-green-100 text-green-800',
    'task'        => 'bg-gray-100 text-gray-800',
    'mandate'     => 'bg-amber-100 text-amber-800',
];
?>
<div class="p-6 max-w-6xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp; <?= __('Attività') ?>
        </nav>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= __('Attività (RiC-CM)') ?></h1>
                <p class="text-sm text-gray-600 mt-1">
                    <?= __("Funzioni, attività e transazioni allineate allo standard ISDF. Ogni attività può essere collegata a unità archivistiche e a un agente esecutore.") ?>
                </p>
            </div>
            <a href="<?= $e(url('/admin/archives/activities/new')) ?>"
               class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded shadow-sm">
                <?= __('+ Nuova attività') ?>
            </a>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="bg-white shadow rounded-lg p-6">
            <p class="text-sm text-gray-500"><?= __('Nessuna attività registrata.') ?></p>
        </div>
    <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Titolo') ?></th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Tipo') ?></th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Date') ?></th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <span class="sr-only"><?= __('Azioni') ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $rid     = (int) ($r['id'] ?? 0);
                    $title   = (string) ($r['title'] ?? '');
                    $type    = (string) ($r['activity_type'] ?? '');
                    $dstart  = (string) ($r['date_start'] ?? '');
                    $dend    = (string) ($r['date_end'] ?? '');
                    $ongoing = !empty($r['is_ongoing']);
                    $badge   = $typeBadge[$type] ?? 'bg-gray-100 text-gray-800';
                    $tLabel  = $typeLabel[$type] ?? $type;
                ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500">#<?= $rid ?></td>
                        <td class="px-4 py-2">
                            <a href="<?= $e(url('/admin/archives/activities/' . $rid)) ?>"
                               class="text-blue-600 hover:underline"><?= $e($title) ?></a>
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badge ?>"><?= $e($tLabel) ?></span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            <?= $e($dstart) ?><?= $dend !== '' ? '–' . $e($dend) : '' ?>
                            <?php if ($ongoing): ?>
                                <span class="ml-1 text-xs text-green-700">[<?= __('in corso') ?>]</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="<?= $e(url('/admin/archives/activities/' . $rid . '/edit')) ?>"
                               class="text-blue-600 hover:underline text-sm"><?= __('modifica') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
