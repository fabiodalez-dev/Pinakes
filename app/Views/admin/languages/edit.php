<?php
/**
 * Admin Languages Edit View
 *
 * Form to edit an existing language.
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
                    <span class="text-2xl"><?= HtmlHelper::e($language['flag_emoji']) ?></span>
                    <i class="fas fa-edit text-blue-600"></i>
                    <?= __("Modifica Lingua:") ?> <?= HtmlHelper::e($language['native_name']) ?>
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

        <!-- Edit Form -->
        <form method="POST" action="<?= url('/admin/languages/' . urlencode($language['code'])) ?>" enctype="multipart/form-data" class="space-y-6">
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
                    <!-- Language Code (Read-only) -->
                    <div>
                        <label for="code" class="form-label">
                            <?= __("Codice Lingua") ?>
                        </label>
                        <input type="text"
                               id="code"
                               class="form-input bg-gray-100 cursor-not-allowed"
                               value="<?= HtmlHelper::e($language['code']) ?>"
                               readonly>
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Il codice lingua non può essere modificato dopo la creazione.") ?>
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
                               value="<?= HtmlHelper::e($language['name']) ?>"
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
                               value="<?= HtmlHelper::e($language['native_name']) ?>"
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
                               value="<?= HtmlHelper::e($language['flag_emoji']) ?>"
                               placeholder="🇪🇸 🇫🇷 🇩🇪"
                               maxlength="10">
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Emoji della bandiera del paese (opzionale)") ?>
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
                    <!-- Current File Info -->
                    <?php if (!empty($language['translation_file'])): ?>
                        <div class="bg-green-50 border border-green-200 rounded p-3">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-green-900 mb-1">
                                        <?= __("File di Traduzione Attuale") ?>
                                    </p>
                                    <p class="text-xs text-green-800 mb-2">
                                        <code><?= HtmlHelper::e($language['translation_file']) ?></code>
                                    </p>
                                    <div class="text-sm text-green-800">
                                        <strong><?= __("Statistiche:") ?></strong>
                                        <?= $language['translated_keys'] ?>/<?= $language['total_keys'] ?> <?= __("chiavi tradotte") ?>
                                        (<?= number_format($language['completion_percentage'], 1) ?>% <?= __("completamento") ?>)
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?= __("Nessun file di traduzione caricato. Carica un file JSON per abilitare questa lingua.") ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- JSON Upload -->
                    <div>
                        <label for="translation_json" class="form-label">
                            <?= __("Carica Nuovo File JSON") ?>
                        </label>
                        <input type="file"
                               id="translation_json"
                               name="translation_json"
                               class="form-input"
                               accept=".json,application/json">
                        <p class="mt-1 text-sm text-gray-500">
                            <?= __("Carica un nuovo file per aggiornare le traduzioni (opzionale). Verrà creato un backup del file precedente.") ?>
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
                        <input type="checkbox"
                               name="is_active"
                               value="1"
                               <?= $language['is_active'] ? 'checked' : '' ?>
                               class="form-checkbox">
                        <span class="text-sm">
                            <strong><?= __("Lingua Attiva") ?></strong> - <?= __("Gli utenti possono selezionare questa lingua") ?>
                        </span>
                    </label>

                    <!-- Default Status -->
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox"
                               name="is_default"
                               value="1"
                               <?= $language['is_default'] ? 'checked' : '' ?>
                               class="form-checkbox">
                        <span class="text-sm">
                            <strong><?= __("Lingua Predefinita") ?></strong> - <?= __("Imposta come lingua predefinita per nuovi utenti") ?>
                        </span>
                    </label>

                    <?php if (!$language['is_default']): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-exclamation-triangle"></i>
                                <?= __("Nota: Impostare come predefinita disattiverà lo status di predefinita per tutte le altre lingue.") ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($language['is_default']): ?>
                        <div class="bg-blue-50 border border-blue-200 rounded p-3">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle"></i>
                                <?= __("Questa è la lingua predefinita. Per cambiarla, imposta prima un'altra lingua come predefinita.") ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-4">
                <a href="<?= url('/admin/languages') ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> <?= __("Annulla") ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= __("Salva Modifiche") ?>
                </button>
            </div>
        </form>

        <!-- Danger Zone -->
        <?php if (!$language['is_default']): ?>
            <div class="mt-8 card border-red-200">
                <div class="card-header bg-red-50">
                    <h2 class="text-lg font-semibold text-red-900 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= __("Zona Pericolosa") ?>
                    </h2>
                </div>
                <div class="card-body">
                    <p class="text-sm text-gray-700 mb-4">
                        <?= __("Elimina questa lingua. Questa azione non può essere annullata.") ?>
                    </p>
                    <form method="POST" action="<?= url('/admin/languages/' . urlencode($language['code']) . '/delete') ?>" onsubmit="return confirm('<?= __("Sei sicuro di voler eliminare questa lingua? Tutti i dati associati e il file di traduzione verranno rimossi.") ?>')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> <?= __("Elimina Lingua") ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
