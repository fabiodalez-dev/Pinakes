<?php
/**
 * Archives — show (detail) view.
 *
 * @var array<string, mixed> $row
 * @var string|null $parent_title
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$v = static fn(string $k): string => $e((string) ($row[$k] ?? ''));

$dateRange = '';
if ($row['date_start'] !== null) {
    $dateRange = (string) $row['date_start'];
    if ($row['date_end'] !== null && $row['date_end'] !== $row['date_start']) {
        $dateRange .= '–' . (string) $row['date_end'];
    }
}

$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$badgeClass = $levelBadge[(string) $row['level']] ?? 'bg-gray-100 text-gray-800';

$id = (int) $row['id'];
?>
<div class="p-6 max-w-4xl mx-auto">
    <nav class="text-sm text-gray-500 mb-2">
        <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline">Archivi</a>
        &nbsp;&raquo;&nbsp; <?= $v('reference_code') ?>
    </nav>

    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badgeClass ?>">
                    <?= $v('level') ?>
                </span>
                <span class="font-mono text-sm text-gray-500"><?= $v('reference_code') ?></span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $v('constructed_title') ?></h1>
            <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
                <p class="text-sm italic text-gray-600 mt-1">
                    Titolo formale: <?= $v('formal_title') ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= $e(url('/admin/archives/' . $id . '/edit')) ?>"
               class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                Modifica
            </a>
            <form method="POST" action="<?= $e(url('/admin/archives/' . $id . '/delete')) ?>"
                  onsubmit="return confirm('Eliminare questo record? L\'operazione è reversibile (soft-delete) ma rimuoverà l\'unità dalle viste.');"
                  class="inline">
                <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50">
                    Elimina
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <dl class="divide-y divide-gray-200">
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-sm font-medium text-gray-500">Istituzione</dt>
                <dd class="col-span-2 text-sm text-gray-900 font-mono"><?= $v('institution_code') ?></dd>
            </div>
            <?php if ($parent_title !== null): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Unità padre</dt>
                    <dd class="col-span-2 text-sm text-gray-900">
                        <a href="<?= $e(url('/admin/archives/' . (int) $row['parent_id'])) ?>"
                           class="text-blue-600 hover:underline">
                            <?= $e($parent_title) ?>
                        </a>
                    </dd>
                </div>
            <?php endif; ?>
            <?php if ($dateRange !== ''): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Date estreme</dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $e($dateRange) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['extent'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Estensione</dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('extent') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['scope_content'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Ambito e contenuto</dt>
                    <dd class="col-span-2 text-sm text-gray-900 whitespace-pre-wrap"><?= $v('scope_content') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['language_codes'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Lingua</dt>
                    <dd class="col-span-2 text-sm text-gray-900 font-mono"><?= $v('language_codes') ?></dd>
                </div>
            <?php endif; ?>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-sm font-medium text-gray-500">Creato</dt>
                <dd class="col-span-2 text-xs text-gray-600 font-mono"><?= $v('created_at') ?></dd>
            </div>
            <?php if (!empty($row['updated_at']) && $row['updated_at'] !== $row['created_at']): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500">Ultima modifica</dt>
                    <dd class="col-span-2 text-xs text-gray-600 font-mono"><?= $v('updated_at') ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>
</div>
