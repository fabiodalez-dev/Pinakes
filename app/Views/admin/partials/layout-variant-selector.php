<?php
/** @var string $layoutVariant */
use App\Support\HtmlHelper;

$layoutOptions = [
    'editorial' => [
        'name' => __('Editoriale'),
        'description' => __('Tipografia autorevole, copertine protagoniste e ritmo arioso.'),
        'icon' => 'fa-book-open',
        'recommended' => true,
    ],
    'workspace' => [
        'name' => __('Workspace'),
        'description' => __('Più compatto e operativo, ideale per cataloghi ricchi di metadati.'),
        'icon' => 'fa-table-columns',
    ],
    'command' => [
        'name' => __('Command'),
        'description' => __('Ricerca centrale, gerarchia netta e superfici ad alto contrasto.'),
        'icon' => 'fa-terminal',
    ],
    'soft' => [
        'name' => __('Soft'),
        'description' => __('Accogliente e morbido, con angoli ampi e profondità discreta.'),
        'icon' => 'fa-feather-pointed',
    ],
];
?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-px bg-gray-200">
    <?php foreach ($layoutOptions as $value => $option): ?>
        <?php $isSelected = ($layoutVariant === $value); ?>
        <label class="relative cursor-pointer bg-white p-5 transition-colors hover:bg-gray-50 focus-within:ring-2 focus-within:ring-inset focus-within:ring-gray-900">
            <span class="flex items-center justify-between gap-4">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-gray-700">
                    <i class="fas <?= htmlspecialchars($option['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                </span>
                <input type="radio"
                       name="layout_variant"
                       value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                       class="peer h-5 w-5 shrink-0 cursor-pointer accent-gray-900"
                       <?= $isSelected ? 'checked' : '' ?>>
            </span>
            <span class="absolute inset-x-0 top-0 h-1 <?= $isSelected ? 'bg-gray-900' : 'bg-transparent' ?>" aria-hidden="true"></span>
            <span class="mt-4 flex items-center gap-2">
                <strong class="text-sm text-gray-900"><?= HtmlHelper::e($option['name']) ?></strong>
                <?php if (!empty($option['recommended'])): ?>
                    <span class="rounded-full bg-pink-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-pink-700"><?= __('Default') ?></span>
                <?php endif; ?>
            </span>
            <span class="mt-2 block text-xs leading-5 text-gray-600"><?= HtmlHelper::e($option['description']) ?></span>
        </label>
    <?php endforeach; ?>
</div>
