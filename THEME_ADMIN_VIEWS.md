# 🎨 VIEW ADMIN TEMI - DESIGN COMPLETO

**Riferimento**: Basato su stile di `plugins.php` e `settings.php`

---

## 📄 1. LISTA TEMI (`app/Views/admin/themes.php`)

### **Struttura Pagina**

```php
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
                <p class="mt-2 text-sm text-gray-600">
                    <?= __("Personalizza l'aspetto dell'applicazione") ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Tema Attivo -->
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

        <!-- Temi Installati -->
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

        <!-- Personalizzazioni -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Personalizzato") ?></p>
                    <p class="text-3xl font-bold text-blue-600 mt-2">
                        <?php
                        $customized = 0;
                        foreach ($themes as $theme) {
                            $settings = json_decode($theme['settings'], true);
                            $colors = $settings['colors'] ?? [];
                            // Check if colors are different from defaults
                            if (!empty($colors) && $colors !== [
                                'primary' => '#d70161',
                                'secondary' => '#111827',
                                'button' => '#d70262',
                                'button_text' => '#ffffff'
                            ]) {
                                $customized++;
                            }
                        }
                        echo $customized;
                        ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-paint-brush text-blue-600 text-xl"></i>
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
                    <!-- Preview colori del tema -->
                    <div class="absolute inset-0 p-6">
                        <div class="h-full rounded-lg border-2 border-gray-200 bg-white p-4 flex flex-col gap-2">
                            <!-- Simula header con primary color -->
                            <div class="h-8 rounded flex items-center gap-2 px-3"
                                 style="background: <?= htmlspecialchars($colors['primary'] ?? '#d70161') ?>;">
                                <div class="w-4 h-4 bg-white bg-opacity-30 rounded"></div>
                                <div class="flex-1 h-2 bg-white bg-opacity-30 rounded"></div>
                            </div>
                            <!-- Simula bottoni -->
                            <div class="flex gap-2 mt-2">
                                <div class="flex-1 h-6 rounded"
                                     style="background: <?= htmlspecialchars($colors['button'] ?? '#d70262') ?>;"></div>
                                <div class="flex-1 h-6 rounded"
                                     style="background: <?= htmlspecialchars($colors['secondary'] ?? '#111827') ?>;"></div>
                            </div>
                            <!-- Simula card -->
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
                                <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2 py-1 bg-gray-900 text-white text-xs rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                                    <?= ucfirst(str_replace('_', ' ', $key)) ?>
                                </div>
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
```

---

## 📄 2. CUSTOMIZER TEMA (`app/Views/admin/theme-customize.php`)

### **Struttura Pagina con Tabs**

```php
<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = __('Personalizza Tema') . ': ' . $theme['name'];
$settings = json_decode($theme['settings'], true) ?? [];
$colors = $settings['colors'] ?? [];
$advanced = $settings['advanced'] ?? [];
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <a href="/admin/themes" class="text-sm text-gray-600 hover:text-gray-900 mb-2 inline-block">
                        <i class="fas fa-arrow-left mr-1"></i>
                        <?= __("Torna ai temi") ?>
                    </a>
                    <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                        <i class="fas fa-palette text-gray-900"></i>
                        <?= __("Personalizza") ?>: <?= HtmlHelper::e($theme['name']) ?>
                    </h1>
                </div>

                <!-- Active Badge -->
                <?php if ($theme['active']): ?>
                    <span class="px-4 py-2 bg-green-100 text-green-800 rounded-lg font-medium">
                        <i class="fas fa-check-circle mr-1"></i>
                        <?= __("Tema Attivo") ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="/admin/themes/<?= $theme['id'] ?>/save" id="theme-form">
            <input type="hidden" name="csrf_token" value="<?= Csrf::ensureToken() ?>">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Settings -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Colors Section -->
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
                            <!-- Primary Color -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Primario") ?>
                                    <span class="text-gray-500 font-normal">
                                        (<?= __("link, accenti, badge principali") ?>)
                                    </span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[primary]"
                                           id="color-primary"
                                           value="<?= htmlspecialchars($colors['primary'] ?? '#d70161') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300 hover:border-gray-400 transition">
                                    <input type="text"
                                           id="color-primary-text"
                                           value="<?= htmlspecialchars($colors['primary'] ?? '#d70161') ?>"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Secondary Color -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Secondario") ?>
                                    <span class="text-gray-500 font-normal">
                                        (<?= __("bottoni azioni principali, testi scuri") ?>)
                                    </span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[secondary]"
                                           id="color-secondary"
                                           value="<?= htmlspecialchars($colors['secondary'] ?? '#111827') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300 hover:border-gray-400 transition">
                                    <input type="text"
                                           id="color-secondary-text"
                                           value="<?= htmlspecialchars($colors['secondary'] ?? '#111827') ?>"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Button Color -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Bottoni CTA") ?>
                                    <span class="text-gray-500 font-normal">
                                        (<?= __("bottoni nelle card dei libri") ?>)
                                    </span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[button]"
                                           id="color-button"
                                           value="<?= htmlspecialchars($colors['button'] ?? '#d70262') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300 hover:border-gray-400 transition">
                                    <input type="text"
                                           id="color-button-text-hex"
                                           value="<?= htmlspecialchars($colors['button'] ?? '#d70262') ?>"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                </div>
                            </div>

                            <!-- Button Text Color -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("Colore Testo Bottoni") ?>
                                    <span class="text-gray-500 font-normal">
                                        (<?= __("testo nei bottoni CTA") ?>)
                                    </span>
                                </label>
                                <div class="flex gap-3 items-center">
                                    <input type="color"
                                           name="colors[button_text]"
                                           id="color-button-text"
                                           value="<?= htmlspecialchars($colors['button_text'] ?? '#ffffff') ?>"
                                           class="h-12 w-20 rounded-lg cursor-pointer border-2 border-gray-300 hover:border-gray-400 transition">
                                    <input type="text"
                                           id="color-button-text-value"
                                           value="<?= htmlspecialchars($colors['button_text'] ?? '#ffffff') ?>"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 font-mono text-sm"
                                           readonly>
                                    <button type="button"
                                            onclick="autoDetectButtonTextColor()"
                                            class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg transition"
                                            title="<?= __("Rileva automaticamente il colore ottimale") ?>">
                                        <i class="fas fa-magic"></i>
                                        <?= __("Auto") ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Contrast Checker -->
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4" id="contrast-warning">
                                <div class="flex items-start gap-3">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-yellow-900 text-sm mb-1">
                                            <?= __("Verifica Leggibilità (WCAG)") ?>
                                        </h4>
                                        <div id="contrast-info" class="text-sm text-yellow-800">
                                            <!-- Popolato da JavaScript -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Advanced Settings (Opzionale) -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <i class="fas fa-code text-gray-600"></i>
                                <?= __("Impostazioni Avanzate") ?>
                            </h2>
                            <p class="text-sm text-gray-600 mt-1">
                                <?= __("Aggiungi CSS o JavaScript personalizzato") ?>
                            </p>
                        </div>

                        <div class="p-6 space-y-4">
                            <!-- Custom CSS -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("CSS Personalizzato") ?>
                                </label>
                                <textarea name="advanced[custom_css]"
                                          rows="6"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm"
                                          placeholder="/* Il tuo CSS qui */"><?= htmlspecialchars($advanced['custom_css'] ?? '') ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= __("Questo CSS verrà iniettato nel <head> di tutte le pagine frontend") ?>
                                </p>
                            </div>

                            <!-- Custom JS -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?= __("JavaScript Personalizzato") ?>
                                </label>
                                <textarea name="advanced[custom_js]"
                                          rows="6"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg font-mono text-sm"
                                          placeholder="// Il tuo JavaScript qui"><?= htmlspecialchars($advanced['custom_js'] ?? '') ?></textarea>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= __("Questo JavaScript verrà eseguito prima della chiusura del </body>") ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Live Preview -->
                <div class="lg:col-span-1">
                    <div class="sticky top-6 space-y-6">
                        <!-- Preview Card -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                            <div class="p-6 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">
                                    <?= __("Anteprima Live") ?>
                                </h3>
                            </div>

                            <div class="p-6 space-y-4">
                                <!-- Preview Link -->
                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Link esempio") ?>:</p>
                                    <a href="#" class="preview-link font-medium hover:underline"
                                       style="color: <?= $colors['primary'] ?? '#d70161' ?>">
                                        <?= __("Questo è un link primario") ?>
                                    </a>
                                </div>

                                <!-- Preview CTA Button -->
                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Bottone CTA") ?>:</p>
                                    <button type="button" class="preview-btn-cta w-full px-4 py-2 rounded-lg font-medium transition"
                                            style="background: <?= $colors['button'] ?? '#d70262' ?>;
                                                   color: <?= $colors['button_text'] ?? '#ffffff' ?>;
                                                   border: 1.5px solid <?= $colors['button'] ?? '#d70262' ?>;">
                                        <?= __("Dettagli Libro") ?>
                                    </button>
                                </div>

                                <!-- Preview Primary Button -->
                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Bottone Azione") ?>:</p>
                                    <button type="button" class="preview-btn-primary w-full px-4 py-2 rounded-lg font-medium transition"
                                            style="background: <?= $colors['secondary'] ?? '#111827' ?>;
                                                   color: #ffffff;
                                                   border: 1.5px solid <?= $colors['secondary'] ?? '#111827' ?>;">
                                        <?= __("Richiedi Prestito") ?>
                                    </button>
                                </div>

                                <!-- Preview Badge -->
                                <div>
                                    <p class="text-sm text-gray-600 mb-2"><?= __("Badge") ?>:</p>
                                    <span class="inline-block px-3 py-1 rounded-full text-sm font-semibold text-white"
                                          style="background: <?= $colors['primary'] ?? '#d70161' ?>;">
                                        <?= __("Autore Principale") ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="space-y-3">
                            <button type="submit"
                                    class="w-full px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 shadow-md hover:shadow-lg font-medium">
                                <i class="fas fa-save mr-2"></i>
                                <?= __("Salva Modifiche") ?>
                            </button>

                            <button type="button"
                                    onclick="resetToDefaults()"
                                    class="w-full px-6 py-3 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition font-medium">
                                <i class="fas fa-undo mr-2"></i>
                                <?= __("Ripristina Default") ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Sync color picker con text input
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

// Live preview
function updatePreview() {
    const primary = document.getElementById('color-primary').value;
    const secondary = document.getElementById('color-secondary').value;
    const button = document.getElementById('color-button').value;
    const buttonText = document.getElementById('color-button-text').value;

    // Update preview elements
    document.querySelector('.preview-link').style.color = primary;

    const btnCta = document.querySelector('.preview-btn-cta');
    btnCta.style.background = button;
    btnCta.style.borderColor = button;
    btnCta.style.color = buttonText;

    const btnPrimary = document.querySelector('.preview-btn-primary');
    btnPrimary.style.background = secondary;
    btnPrimary.style.borderColor = secondary;

    const badge = document.querySelector('.preview-btn-cta').nextElementSibling;
    if (badge) badge.style.background = primary;
}

// Contrast checker
function checkContrast() {
    const button = document.getElementById('color-button').value;
    const buttonText = document.getElementById('color-button-text').value;

    fetch('/admin/themes/check-contrast', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= Csrf::ensureToken() ?>'
        },
        body: JSON.stringify({ bg: button, fg: buttonText })
    })
    .then(r => r.json())
    .then(data => {
        const ratio = data.ratio.toFixed(2);
        const passAA = data.passAA;
        const passAAA = data.passAAA;

        const warning = document.getElementById('contrast-warning');
        const info = document.getElementById('contrast-info');

        let html = `<p><strong><?= __("Rapporto contrasto") ?>:</strong> ${ratio}:1</p>`;

        if (passAAA) {
            html += '<p class="text-green-700 font-medium"><i class="fas fa-check-circle mr-1"></i> <?= __("Eccellente") ?> (WCAG AAA)</p>';
            warning.className = 'bg-green-50 border border-green-200 rounded-lg p-4';
            warning.querySelector('i').className = 'fas fa-check-circle text-green-600 mt-0.5';
        } else if (passAA) {
            html += '<p class="text-yellow-700 font-medium"><i class="fas fa-check-circle mr-1"></i> <?= __("Buono") ?> (WCAG AA)</p>';
            warning.className = 'bg-yellow-50 border border-yellow-200 rounded-lg p-4';
            warning.querySelector('i').className = 'fas fa-exclamation-triangle text-yellow-600 mt-0.5';
        } else {
            html += '<p class="text-red-700 font-medium"><i class="fas fa-times-circle mr-1"></i> <?= __("Contrasto insufficiente") ?></p>';
            html += '<p class="text-sm mt-1"><?= __("Consigliato almeno 4.5:1 per leggibilità") ?></p>';
            warning.className = 'bg-red-50 border border-red-200 rounded-lg p-4';
            warning.querySelector('i').className = 'fas fa-times-circle text-red-600 mt-0.5';
        }

        info.innerHTML = html;
    });
}

// Auto-detect optimal text color
function autoDetectButtonTextColor() {
    const buttonColor = document.getElementById('color-button').value;

    // Calculate luminance
    const rgb = hexToRgb(buttonColor);
    const luminance = (0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b) / 255;

    // If background is light, use dark text; if dark, use light text
    const optimalColor = luminance > 0.5 ? '#000000' : '#ffffff';

    document.getElementById('color-button-text').value = optimalColor;
    document.getElementById('color-button-text-value').value = optimalColor;

    updatePreview();
    checkContrast();
}

function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
    } : { r: 0, g: 0, b: 0 };
}

function resetToDefaults() {
    if (!confirm('<?= addslashes(__("Ripristinare i colori predefiniti?")) ?>')) {
        return;
    }

    fetch('/admin/themes/<?= $theme['id'] ?>/reset', {
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
            alert(data.message || '<?= addslashes(__("Errore")) ?>');
        }
    });
}

// Init
checkContrast();
</script>
```

---

## 🎯 FEATURES CHIAVE

### **1. Live Preview Interattiva**
- Preview in tempo reale dei colori
- Simulazione di link, bottoni CTA, bottoni primary, badge
- Aggiornamento istantaneo on color change

### **2. Contrast Checker WCAG**
- Verifica automatica contrasto
- Badge AAA (7:1), AA (4.5:1), o warning se insufficiente
- Colori semaforo (verde/giallo/rosso)

### **3. Auto-Detect Text Color**
- Bottone "Auto" per calcolare automaticamente colore testo ottimale
- Basato su luminanza del background
- Garantisce sempre contrasto accettabile

### **4. Design Coerente Admin**
- Stesso stile di plugins.php
- Card rounded-xl con border gray
- Bottoni neri per azioni primarie
- Grid responsive Tailwind

### **5. Advanced Settings**
- Custom CSS/JS opzionali
- Textarea con font monospace
- Warning su dove viene iniettato il codice

---

**Prossimo**: Implementare ThemeController con tutte le route e logica di salvataggio
