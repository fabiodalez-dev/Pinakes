<?php
/** @var array $theme */
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = __('Personalizza Tema') . ': ' . $theme['name'];
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <a href="<?= htmlspecialchars(url('/admin/themes'), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-600 hover:text-gray-900 mb-2 inline-block">
                        <i class="fas fa-arrow-left mr-1"></i>
                        <?= __("Torna ai temi") ?>
                    </a>
                    <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                        <i class="fas fa-palette text-gray-900"></i>
                        <?= __("Personalizza") ?>: <?= HtmlHelper::e($theme['name']) ?>
                    </h1>
                </div>

                <?php if ($theme['active']): ?>
                    <span class="px-4 py-2 bg-green-100 text-green-800 rounded-lg font-medium">
                        <i class="fas fa-check-circle mr-1"></i>
                        <?= __("Tema Attivo") ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <p class="text-green-800"><?= HtmlHelper::e($_SESSION['success']) ?></p>
                <?php unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <p class="text-red-800"><?= HtmlHelper::e($_SESSION['error']) ?></p>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars(url('/admin/themes/' . (int)$theme['id'] . '/save'), ENT_QUOTES, 'UTF-8') ?>" id="theme-form">
            <input type="hidden" name="csrf_token" value="<?= Csrf::ensureToken() ?>">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left: Settings -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Colors -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-swatchbook text-gray-600"></i>
                                <?= __("Colori Tema") ?>
                            </h2>
                            <p class="text-sm text-gray-600 mt-1">
                                <?= __("Personalizza la palette colori dell'applicazione") ?>
                            </p>
                        </div>

                        <div class="p-6 space-y-6">
                            <!-- Primary -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Primario") ?>
                                    <span class="text-gray-500 font-normal">(<?= __("link, accenti") ?>)</span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[primary]"
                                           id="color-primary"
                                           value="<?= htmlspecialchars($colors['primary'] ?? '#d70161', ENT_QUOTES, 'UTF-8') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300">
                                    <input type="text"
                                           id="color-primary-text"
                                           value="<?= htmlspecialchars($colors['primary'] ?? '#d70161', ENT_QUOTES, 'UTF-8') ?>"
                                           class="flex-1 px-3 py-2 border rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Secondary -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Secondario") ?>
                                    <span class="text-gray-500 font-normal">(<?= __("bottoni principali") ?>)</span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[secondary]"
                                           id="color-secondary"
                                           value="<?= htmlspecialchars($colors['secondary'] ?? '#111827', ENT_QUOTES, 'UTF-8') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300">
                                    <input type="text"
                                           id="color-secondary-text"
                                           value="<?= htmlspecialchars($colors['secondary'] ?? '#111827', ENT_QUOTES, 'UTF-8') ?>"
                                           class="flex-1 px-3 py-2 border rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Button -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Bottoni CTA") ?>
                                    <span class="text-gray-500 font-normal">(<?= __("bottoni nelle card") ?>)</span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[button]"
                                           id="color-button"
                                           value="<?= htmlspecialchars($colors['button'] ?? '#d70262', ENT_QUOTES, 'UTF-8') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300">
                                    <input type="text"
                                           id="color-button-hex"
                                           value="<?= htmlspecialchars($colors['button'] ?? '#d70262', ENT_QUOTES, 'UTF-8') ?>"
                                           class="flex-1 px-3 py-2 border rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Button Text -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Testo Bottoni") ?>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[button_text]"
                                           id="color-button-text"
                                           value="<?= htmlspecialchars($colors['button_text'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300">
                                    <input type="text"
                                           id="color-button-text-value"
                                           value="<?= htmlspecialchars($colors['button_text'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>"
                                           class="flex-1 px-3 py-2 border rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                    <button type="button"
                                            onclick="autoDetectButtonTextColor()"
                                            class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"
                                            title="<?= __("Auto") ?>">
                                        <i class="fas fa-magic"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Contrast -->
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4" id="contrast-warning">
                                <div class="flex items-start gap-3">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-yellow-900 text-sm mb-1">
                                            <?= __("Verifica Leggibilità") ?>
                                        </h4>
                                        <div id="contrast-info" class="text-sm text-yellow-800"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-code text-gray-600"></i>
                                <?= __("Avanzate") ?>
                            </h2>
                        </div>
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("CSS Personalizzato") ?>
                                </label>
                                <textarea name="advanced[custom_css]"
                                          rows="6"
                                          class="w-full px-3 py-2 border rounded-lg font-mono text-sm"
                                          placeholder="/* CSS qui */"><?= htmlspecialchars($advanced['custom_css'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Preview -->
                <div class="lg:col-span-1">
                    <div class="sticky top-6 space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                            <div class="p-6 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">
                                    <?= __("Anteprima") ?>
                                </h3>
                            </div>

                            <div class="p-6 space-y-4">
                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Link") ?>:</p>
                                    <a href="#" class="preview-link font-medium"
                                       style="color: <?= htmlspecialchars($colors['primary'] ?? '#d70161', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= __("Link di esempio") ?>
                                    </a>
                                </div>

                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Bottone CTA") ?>:</p>
                                    <button type="button" class="preview-btn-cta w-full px-4 py-2 rounded-lg font-medium"
                                            style="background: <?= htmlspecialchars($colors['button'] ?? '#d70262', ENT_QUOTES, 'UTF-8') ?>;
                                                   color: <?= htmlspecialchars($colors['button_text'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>;
                                                   border: 1.5px solid <?= htmlspecialchars($colors['button'] ?? '#d70262', ENT_QUOTES, 'UTF-8') ?>;">
                                        <?= __("Dettagli") ?>
                                    </button>
                                </div>

                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Bottone Primario") ?>:</p>
                                    <button type="button" class="preview-btn-primary w-full px-4 py-2 rounded-lg font-medium"
                                            style="background: <?= htmlspecialchars($colors['secondary'] ?? '#111827', ENT_QUOTES, 'UTF-8') ?>;
                                                   color: #fff;
                                                   border: 1.5px solid <?= htmlspecialchars($colors['secondary'] ?? '#111827', ENT_QUOTES, 'UTF-8') ?>;">
                                        <?= __("Richiedi Prestito") ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <button type="submit"
                                    class="w-full px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 font-medium">
                                <i class="fas fa-save mr-2"></i>
                                <?= __("Salva") ?>
                            </button>

                            <button type="button"
                                    onclick="resetToDefaults()"
                                    class="w-full px-6 py-3 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 font-medium">
                                <i class="fas fa-undo mr-2"></i>
                                <?= __("Ripristina") ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('input[type="color"]').forEach(input => {
    const id = input.id;
    const textInput = document.getElementById(id + '-text') ||
                     document.getElementById(id + '-hex') ||
                     document.getElementById(id + '-value');

    input.addEventListener('input', function() {
        if (textInput) textInput.value = this.value.toUpperCase();
        updatePreview();
        checkContrast();
    });
});

function updatePreview() {
    const primary = document.getElementById('color-primary').value;
    const secondary = document.getElementById('color-secondary').value;
    const button = document.getElementById('color-button').value;
    const buttonText = document.getElementById('color-button-text').value;

    document.querySelector('.preview-link').style.color = primary;

    const btnCta = document.querySelector('.preview-btn-cta');
    btnCta.style.background = button;
    btnCta.style.borderColor = button;
    btnCta.style.color = buttonText;

    const btnPrimary = document.querySelector('.preview-btn-primary');
    btnPrimary.style.background = secondary;
    btnPrimary.style.borderColor = secondary;
}

const csrfToken = <?= json_encode(\App\Support\Csrf::ensureToken()) ?>;

function checkContrast() {
    const button = document.getElementById('color-button').value;
    const buttonText = document.getElementById('color-button-text').value;

    fetch(window.BASE_PATH + '/admin/themes/check-contrast', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({bg: button, fg: buttonText})
    })
    .then(r => r.json())
    .then(data => {
        // Check for CSRF/session errors
        if (data.error || data.code) {
            alert(data.error || <?= json_encode(__("Errore di sicurezza"), JSON_HEX_TAG) ?>);
            if (data.code === 'SESSION_EXPIRED' || data.code === 'CSRF_INVALID') {
                setTimeout(() => window.location.reload(), 2000);
            }
            return;
        }

        const ratio = data.ratio.toFixed(2);
        const passAA = data.passAA;
        const warning = document.getElementById('contrast-warning');
        const info = document.getElementById('contrast-info');

        let html = `<p><strong><?= __("Contrasto") ?>:</strong> ${ratio}:1</p>`;

        if (passAA) {
            html += '<p class="text-green-700 font-medium"><i class="fas fa-check-circle mr-1"></i> <?= __("WCAG AA Conforme") ?></p>';
            warning.className = 'bg-green-50 border border-green-200 rounded-lg p-4';
        } else if (ratio >= 3.0) {
            html += '<p class="text-yellow-700 font-medium"><?= __("AA Testo Grande") ?></p>';
            warning.className = 'bg-yellow-50 border border-yellow-200 rounded-lg p-4';
        } else {
            html += '<p class="text-red-700 font-medium"><i class="fas fa-times-circle mr-1"></i> <?= __("Insufficiente") ?></p>';
            warning.className = 'bg-red-50 border border-red-200 rounded-lg p-4';
        }

        info.innerHTML = html;
    });
}

function autoDetectButtonTextColor() {
    const button = document.getElementById('color-button').value;
    const rgb = hexToRgb(button);
    const luminance = (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;
    const optimal = luminance > 0.5 ? '#000000' : '#FFFFFF';

    document.getElementById('color-button-text').value = optimal;
    document.getElementById('color-button-text-value').value = optimal;

    updatePreview();
    checkContrast();
}

function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
    } : {r: 0, g: 0, b: 0};
}

function resetToDefaults() {
    if (!confirm(<?= json_encode(__("Ripristinare i colori?"), JSON_HEX_TAG) ?>)) return;

    fetch(window.BASE_PATH + '/admin/themes/<?= (int)$theme['id'] ?>/reset', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        }
    })
    .then(r => r.json())
    .then(data => {
        // Check for CSRF/session errors
        if (data.error || data.code) {
            alert(data.error || <?= json_encode(__("Errore di sicurezza"), JSON_HEX_TAG) ?>);
            if (data.code === 'SESSION_EXPIRED' || data.code === 'CSRF_INVALID') {
                setTimeout(() => window.location.reload(), 2000);
            }
            return;
        }

        if (data.success) window.location.reload();
        else alert(data.message);
    });
}

checkContrast();
</script>
