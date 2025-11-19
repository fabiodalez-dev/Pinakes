<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = __('Gestione Temi');
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?= __("Temi") ?></h1>
                <p class="mt-2 text-sm text-gray-600"><?= __("Personalizza l'aspetto dell'applicazione") ?></p>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Tema Attivo") ?></p>
                    <p class="text-lg font-bold text-gray-900 mt-2">
                        <?= HtmlHelper::e($activeTheme['name'] ?? 'N/A') ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Temi Disponibili") ?></p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?= count($themes) ?></p>
                </div>
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-swatchbook text-gray-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Versione") ?></p>
                    <p class="text-3xl font-bold text-blue-600 mt-2">
                        <?= HtmlHelper::e($activeTheme['version'] ?? '1.0') ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-code-branch text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Themes Grid -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Temi Disponibili") ?></h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 p-6">
            <?php foreach ($themes as $theme): ?>
                <?php
                $isActive = (bool)$theme['active'];
                $settings = json_decode($theme['settings'], true) ?? [];
                $colors = $settings['colors'] ?? [];
                $primaryColor = $colors['primary'] ?? '#d70161';
                $buttonColor = $colors['button'] ?? '#d70262';
                $secondaryColor = $colors['secondary'] ?? '#111827';
                ?>
                <div class="bg-white rounded-xl border <?= $isActive ? 'border-green-300 ring-2 ring-green-100' : 'border-gray-200' ?> overflow-hidden hover:shadow-lg transition-all duration-200">
                    <!-- Color Preview Bar -->
                    <div class="h-3 flex">
                        <div class="flex-1" style="background: <?= htmlspecialchars($primaryColor) ?>;"></div>
                        <div class="flex-1" style="background: <?= htmlspecialchars($buttonColor) ?>;"></div>
                        <div class="flex-1" style="background: <?= htmlspecialchars($secondaryColor) ?>;"></div>
                    </div>

                    <!-- Theme Content -->
                    <div class="p-5">
                        <!-- Header with name and status -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-base font-semibold text-gray-900 truncate">
                                    <?= HtmlHelper::e($theme['name']) ?>
                                </h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    v<?= HtmlHelper::e($theme['version']) ?> • <?= HtmlHelper::e($theme['author']) ?>
                                </p>
                            </div>
                            <?php if ($isActive): ?>
                                <span class="flex-shrink-0 ml-2 px-2 py-1 bg-green-100 text-green-700 text-xs font-medium rounded-md">
                                    <i class="fas fa-check-circle mr-1"></i><?= __("Attivo") ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <p class="text-sm text-gray-600 mb-4 line-clamp-2">
                            <?= HtmlHelper::e($theme['description']) ?>
                        </p>

                        <!-- Color Swatches -->
                        <div class="flex items-center gap-1.5 mb-4">
                            <?php
                            $colorLabels = [
                                'primary' => __('Primario'),
                                'secondary' => __('Secondario'),
                                'button' => __('Bottone'),
                                'button_text' => __('Testo Bottone'),
                                'accent' => __('Accento')
                            ];
                            foreach ($colors as $key => $color):
                                $label = $colorLabels[$key] ?? ucfirst($key);
                            ?>
                                <div class="w-6 h-6 rounded-md border border-gray-300 shadow-sm cursor-help"
                                     style="background: <?= htmlspecialchars($color) ?>;"
                                     title="<?= $label ?>: <?= htmlspecialchars($color) ?>"></div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Actions -->
                        <div class="flex gap-2">
                            <?php if (!$isActive): ?>
                                <button onclick="activateTheme(<?= $theme['id'] ?>)"
                                        class="flex-1 px-3 py-2 bg-black text-white rounded-lg hover:bg-gray-800 transition-colors text-sm font-medium">
                                    <i class="fas fa-check mr-1"></i>
                                    <?= __("Attiva") ?>
                                </button>
                            <?php endif; ?>

                            <a href="/admin/themes/<?= $theme['id'] ?>/customize"
                               class="<?= $isActive ? 'flex-1' : '' ?> px-3 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium text-center">
                                <i class="fas fa-sliders-h mr-1"></i>
                                <?= __("Personalizza") ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function activateTheme(themeId) {
    if (!confirm('<?= addslashes(__("Attivare questo tema?")) ?>')) {
        return;
    }

    fetch(`/admin/themes/${themeId}/activate`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= Csrf::ensureToken() ?>'
        }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || '<?= addslashes(__("Errore durante l'attivazione")) ?>');
        }
    })
    .catch(err => {
        console.error(err);
        alert('<?= addslashes(__("Errore di rete")) ?>');
    });
}
</script>
