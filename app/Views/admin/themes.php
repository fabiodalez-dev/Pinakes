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
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-palette text-gray-900"></i>
                    <?= __("Temi") ?>
                </h1>
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
                    <p class="text-sm font-medium text-gray-600"><?= __("Temi Installati") ?></p>
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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($themes as $theme): ?>
            <?php
            $isActive = (bool)$theme['active'];
            $settings = json_decode($theme['settings'], true) ?? [];
            $colors = $settings['colors'] ?? [];
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-shadow">
                <!-- Theme Preview -->
                <div class="relative aspect-video bg-gradient-to-br from-gray-50 to-gray-100 p-6">
                    <div class="absolute inset-0 p-6">
                        <div class="h-full rounded-lg border-2 border-gray-200 bg-white p-4 flex flex-col gap-2">
                            <div class="h-8 rounded flex items-center gap-2 px-3"
                                 style="background: <?= htmlspecialchars($colors['primary'] ?? '#d70161') ?>;">
                                <div class="w-4 h-4 bg-white bg-opacity-30 rounded"></div>
                                <div class="flex-1 h-2 bg-white bg-opacity-30 rounded"></div>
                            </div>
                            <div class="flex gap-2 mt-2">
                                <div class="flex-1 h-6 rounded"
                                     style="background: <?= htmlspecialchars($colors['button'] ?? '#d70262') ?>;"></div>
                                <div class="flex-1 h-6 rounded"
                                     style="background: <?= htmlspecialchars($colors['secondary'] ?? '#111827') ?>;"></div>
                            </div>
                            <div class="flex-1 rounded border border-gray-200 mt-2"></div>
                        </div>
                    </div>

                    <?php if ($isActive): ?>
                        <span class="absolute top-4 right-4 px-3 py-1 bg-green-600 text-white text-xs font-semibold rounded-full">
                            <?= __("Attivo") ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Theme Info -->
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-2">
                        <?= HtmlHelper::e($theme['name']) ?>
                    </h3>
                    <p class="text-sm text-gray-600 mb-1">
                        <?= HtmlHelper::e($theme['description']) ?>
                    </p>
                    <p class="text-xs text-gray-500 mb-4">
                        <?= __("Versione") ?>: <?= HtmlHelper::e($theme['version']) ?> •
                        <?= __("Autore") ?>: <?= HtmlHelper::e($theme['author']) ?>
                    </p>

                    <!-- Color Badges -->
                    <div class="flex gap-2 mb-4">
                        <?php foreach ($colors as $key => $color): ?>
                            <div class="group relative">
                                <div class="w-8 h-8 rounded-lg border-2 border-gray-200 shadow-sm"
                                     style="background: <?= htmlspecialchars($color) ?>;"
                                     title="<?= ucfirst($key) ?>"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-2">
                        <?php if (!$isActive): ?>
                            <button onclick="activateTheme(<?= $theme['id'] ?>)"
                                    class="flex-1 px-4 py-2 bg-black text-white rounded-lg hover:bg-gray-800 transition-colors text-sm font-medium">
                                <i class="fas fa-check mr-1"></i>
                                <?= __("Attiva") ?>
                            </button>
                        <?php endif; ?>

                        <a href="/admin/themes/<?= $theme['id'] ?>/customize"
                           class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium text-center">
                            <i class="fas fa-palette mr-1"></i>
                            <?= __("Personalizza") ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function activateTheme(themeId) {
    if (!confirm('<?= addslashes(__("Attivare questo tema?")) ?>')) {
        return;
    }

    fetch(`/admin/themes/${themeId}/activate`, {
        method: 'POST',
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
