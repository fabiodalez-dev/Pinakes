<?php
/**
 * Admin Languages Create View
 *
 * Form to add a new language to the system.
 */

use App\Support\HtmlHelper;
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-6">
            <div class="flex items-center gap-3 mb-2">
                <a href="<?= url('/admin/languages') ?>" class="text-gray-600 hover:text-gray-900">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-plus-circle text-blue-600"></i>
                    <?= __("Aggiungi Nuova Lingua") ?>
                </h1>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
        </div>

        <!-- Create Form -->
        <form method="POST" action="<?= url('/admin/languages') ?>" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <!-- Basic Info -->
            <div class="card">
                <div class="card-header">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-info-circle text-blue-600"></i>
                        <?= __("Informazioni Base") ?>
                    </h2>
                </div>
                <div class="card-body space-y-4">
                    <!-- Language Code -->
                    <div>
                        <label for="code" class="form-label">
                            <?= __("Codice Lingua") ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               id="code"
                               name="code"
                               class="form-input"
                               placeholder="es_ES, fr_FR, de_DE"
                               pattern="[a-z]{2}_[A-Z]{2}"
                               required>
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Formato: xx_XX (es. it_IT, en_US, es_ES)") ?>
                        </p>
                    </div>

                    <!-- English Name -->
                    <div>
                        <label for="name" class="form-label">
                            <?= __("Nome Inglese") ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               id="name"
                               name="name"
                               class="form-input"
                               placeholder="Spanish, French, German"
                               required>
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Nome della lingua in inglese (es. Italian, English, Spanish)") ?>
                        </p>
                    </div>

                    <!-- Native Name -->
                    <div>
                        <label for="native_name" class="form-label">
                            <?= __("Nome Nativo") ?> <span class="text-red-500">*</span>
                        </label>
                        <input type="text"
                               id="native_name"
                               name="native_name"
                               class="form-input"
                               placeholder="Español, Français, Deutsch"
                               required>
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Nome della lingua nella lingua stessa (es. Italiano, English, Español)") ?>
                        </p>
                    </div>

                    <!-- Flag Emoji -->
                    <div>
                        <label for="flag_emoji" class="form-label">
                            <?= __("Emoji Bandiera") ?>
                        </label>
                        <input type="text"
                               id="flag_emoji"
                               name="flag_emoji"
                               class="form-input"
                               placeholder="🇪🇸 🇫🇷 🇩🇪"
                               maxlength="10"
                               value="🌐">
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Emoji della bandiera del paese (opzionale, default: 🌐)") ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Translation File -->
            <div class="card">
                <div class="card-header">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-file-code text-blue-600"></i>
                        <?= __("File di Traduzione") ?>
                    </h2>
                </div>
                <div class="card-body space-y-4">
                    <!-- JSON Upload -->
                    <div>
                        <label for="translation_json" class="form-label">
                            <?= __("Carica File JSON") ?>
                        </label>
                        <input type="file"
                               id="translation_json"
                               name="translation_json"
                               class="form-input"
                               accept=".json,application/json">
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("File JSON con le traduzioni (opzionale). Puoi caricarlo anche in seguito.") ?>
                        </p>
                    </div>

                    <!-- JSON Format Example -->
                    <div class="bg-gray-50 border border-gray-200 rounded p-4">
                        <h4 class="font-semibold text-sm text-gray-700 mb-2">
                            <i class="fas fa-lightbulb text-yellow-500"></i> <?= __("Formato File JSON") ?>
                        </h4>
                        <pre class="text-xs bg-gray-100 p-3 rounded overflow-x-auto">
{
  "Benvenuto": "Welcome",
  "Ciao": "Hello",
  "Grazie": "Thank you",
  "Libri": "Books",
  "Autori": "Authors"
}</pre>
                        <p class="mt-2 text-xs text-gray-600">
                            <?= __("Il file deve contenere coppie chiave (italiano) - valore (traduzione).") ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Settings -->
            <div class="card">
                <div class="card-header">
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <i class="fas fa-cog text-blue-600"></i>
                        <?= __("Impostazioni") ?>
                    </h2>
                </div>
                <div class="card-body space-y-4">
                    <!-- Active Status -->
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" checked class="form-checkbox">
                        <span class="text-sm">
                            <strong><?= __("Lingua Attiva") ?></strong> - <?= __("Gli utenti possono selezionare questa lingua") ?>
                        </span>
                    </label>

                    <!-- Default Status -->
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="is_default" value="1" class="form-checkbox">
                        <span class="text-sm">
                            <strong><?= __("Lingua Predefinita") ?></strong> - <?= __("Imposta come lingua predefinita per nuovi utenti") ?>
                        </span>
                    </label>

                    <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                        <p class="text-sm text-yellow-800">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?= __("Nota: Impostare come predefinita disattiverà lo status di predefinita per tutte le altre lingue.") ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-4">
                <a href="<?= url('/admin/languages') ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> <?= __("Annulla") ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= __("Salva Lingua") ?>
                </button>
            </div>
        </form>
    </div>
</div>
