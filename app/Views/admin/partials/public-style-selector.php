<?php
/**
 * The public site's style: the home hero and the book cards (2026 design).
 * Used by the themes overview and the theme customization form.
 *
 * @var array{hero_style:string,card_style:string} $publicStyle
 */
use App\Support\HtmlHelper;

$publicStyleGroups = [
    'hero_style' => [
        'title' => __('Stile hero'),
        'options' => [
            'covers' => [
                'name' => __('Copertine'),
                'description' => __('Testo a sinistra e un ventaglio di copertine: le ultime arrivate o quelle scelte nella homepage.'),
                'icon' => 'fa-layer-group',
                'default' => true,
            ],
            'centered' => [
                'name' => __('Centrato'),
                'description' => __('Titolo, ricerca e collegamenti al centro, senza copertine.'),
                'icon' => 'fa-align-center',
            ],
        ],
    ],
    'card_style' => [
        'title' => __('Stile card'),
        'options' => [
            'classic' => [
                'name' => __('Classica'),
                'description' => __('Il libro da solo, con dorso e ombra, sullo sfondo della pagina.'),
                'icon' => 'fa-book',
                'default' => true,
            ],
            'tinted' => [
                'name' => __('Tinta'),
                'description' => __('Il libro su un pannello colorato con la tinta della sua copertina.'),
                'icon' => 'fa-palette',
            ],
        ],
    ],
];
?>
<?php foreach ($publicStyleGroups as $field => $group): ?>
<fieldset>
    <legend class="sr-only"><?= HtmlHelper::e($group['title']) ?></legend>
    <p class="px-6 pt-5 pb-3 text-xs font-semibold uppercase tracking-wide text-gray-500" aria-hidden="true"><?= HtmlHelper::e($group['title']) ?></p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-px bg-gray-200">
        <?php foreach ($group['options'] as $value => $option): ?>
            <?php $isSelected = ($publicStyle[$field] === $value); ?>
            <label class="relative cursor-pointer bg-white p-5 transition-colors hover:bg-gray-50 focus-within:ring-2 focus-within:ring-inset focus-within:ring-gray-900">
                <span class="flex items-center justify-between gap-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-gray-700">
                        <i class="fas <?= htmlspecialchars($option['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    </span>
                    <input type="radio"
                           name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                           class="peer h-5 w-5 shrink-0 cursor-pointer accent-gray-900"
                           <?= $isSelected ? 'checked' : '' ?>>
                </span>
                <span class="absolute inset-x-0 top-0 h-1 <?= $isSelected ? 'bg-gray-900' : 'bg-transparent' ?>" aria-hidden="true"></span>
                <span class="mt-4 flex items-center gap-2">
                    <strong class="text-sm text-gray-900"><?= HtmlHelper::e($option['name']) ?></strong>
                    <?php if (!empty($option['default'])): ?>
                        <span class="rounded-full bg-pink-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-pink-700"><?= __('Default') ?></span>
                    <?php endif; ?>
                </span>
                <span class="mt-2 block text-xs leading-5 text-gray-600"><?= HtmlHelper::e($option['description']) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
<?php endforeach; ?>
