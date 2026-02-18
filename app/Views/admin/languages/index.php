<?php
/**
 * Admin Languages List View
 *
 * Displays all languages with translation statistics and management actions.
 */
/** @var array $languages */

use App\Support\HtmlHelper;
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-globe text-blue-600"></i>
                    <?= __("Gestione Lingue") ?>
                </h1>
                <a href="<?= htmlspecialchars(url('/admin/languages/create'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> <?= __("Aggiungi Lingua") ?>
                </a>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="mt-3 p-3 bg-green-50 text-green-800 border border-green-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_success']) ?>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_warning'])): ?>
                <div class="mt-3 p-3 bg-yellow-50 text-yellow-800 border border-yellow-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_warning']) ?>
                </div>
                <?php unset($_SESSION['flash_warning']); ?>
            <?php endif; ?>

            <!-- Info Box -->
            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                    <div class="flex-1">
                        <h3 class="font-semibold text-blue-900 mb-2"><?= __("Gestione Multilingua") ?></h3>
                        <p class="text-sm text-blue-800 mb-3">
                            <?= __("Da qui puoi gestire tutte le lingue disponibili nell'applicazione. Carica file JSON di traduzione e abilita/disabilita lingue.") ?>
                        </p>
                        <div class="bg-blue-100 border border-blue-300 rounded p-3">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-star text-yellow-600 mt-0.5"></i>
                                <div class="text-sm">
                                    <strong class="text-blue-900"><?= __("Lingua Predefinita:") ?></strong>
                                    <span class="text-blue-800">
                                        <?= __("La lingua contrassegnata come 'Predefinita' verrà usata in tutta l'applicazione per tutti gli utenti. Per cambiare la lingua dell'intera app, clicca sull'icona stella") ?> <i class="fas fa-star text-yellow-600"></i> <?= __("della lingua desiderata.") ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Languages Table -->
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">
                    <?= __("Lingue Configurate") ?>
                    <span class="ml-2 text-sm font-normal text-gray-500">(<?= count($languages) ?>)</span>
                </h2>
                <form method="POST" action="<?= htmlspecialchars(url('/admin/languages/refresh-stats'), ENT_QUOTES, 'UTF-8') ?>" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="fas fa-sync-alt"></i> <?= __("Aggiorna Statistiche") ?>
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($languages)): ?>
                    <div class="p-8 text-center text-gray-500">
                        <i class="fas fa-globe text-6xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2"><?= __("Nessuna lingua configurata") ?></p>
                        <p class="text-sm"><?= __("Inizia aggiungendo la prima lingua.") ?></p>
                        <a href="<?= htmlspecialchars(url('/admin/languages/create'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary mt-4">
                            <i class="fas fa-plus"></i> <?= __("Aggiungi Prima Lingua") ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Lingua") ?>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Codice") ?>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Completamento") ?>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Stato") ?>
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Azioni") ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($languages as $lang): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-3">
                                                <span class="text-2xl"><?= HtmlHelper::e($lang['flag_emoji']) ?></span>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= HtmlHelper::e($lang['native_name']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= HtmlHelper::e($lang['name']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <code class="text-xs bg-gray-100 px-2 py-1 rounded">
                                                <?= HtmlHelper::e($lang['code']) ?>
                                            </code>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1 bg-gray-200 rounded-full h-2 max-w-[120px]">
                                                    <div class="bg-blue-600 h-2 rounded-full" style="width: <?= (float)$lang['completion_percentage'] ?>%"></div>
                                                </div>
                                                <span class="text-sm text-gray-700 font-medium">
                                                    <?= number_format($lang['completion_percentage'], 1) ?>%
                                                </span>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?= $lang['translated_keys'] ?>/<?= $lang['total_keys'] ?> <?= __("chiavi") ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col gap-1">
                                                <?php if ($lang['is_default']): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold bg-yellow-100 text-yellow-800 border border-yellow-300"
                                                          title="<?= __("Questa è la lingua usata in tutta l'app") ?>">
                                                        <i class="fas fa-star mr-1"></i> <?= __("Lingua App") ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($lang['is_active']): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                                        <i class="fas fa-check-circle mr-1"></i> <?= __("Attiva") ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                                        <i class="fas fa-times-circle mr-1"></i> <?= __("Disattivata") ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex items-center justify-end gap-2">
                                                <!-- Download JSON -->
                                                <a href="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/download'), ENT_QUOTES, 'UTF-8') ?>"
                                                   class="text-green-600 hover:text-green-900"
                                                   title="<?= __("Scarica JSON") ?>"
                                                   download>
                                                    <i class="fas fa-download"></i>
                                                </a>

                                                <!-- Edit -->
                                                <a href="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/edit'), ENT_QUOTES, 'UTF-8') ?>"
                                                   class="text-blue-600 hover:text-blue-900"
                                                   title="<?= __("Modifica") ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <!-- Edit Routes -->
                                                <a href="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/edit-routes'), ENT_QUOTES, 'UTF-8') ?>"
                                                   class="text-purple-600 hover:text-purple-900"
                                                   title="<?= __("Modifica Route") ?>">
                                                    <i class="fas fa-route"></i>
                                                </a>

                                                <!-- Set as Default -->
                                                <?php if (!$lang['is_default']): ?>
                                                    <form method="POST" action="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/set-default'), ENT_QUOTES, 'UTF-8') ?>" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit"
                                                                class="text-yellow-600 hover:text-yellow-900"
                                                                title="<?= __("Imposta come Predefinita") ?>"
                                                                onclick="return confirm(<?= htmlspecialchars(json_encode(__("Impostare questa lingua come predefinita?\n\nQuesta diventerà la lingua dell'intera applicazione per tutti gli utenti."), JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="fas fa-star"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Toggle Active -->
                                                <form method="POST" action="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/toggle-active'), ENT_QUOTES, 'UTF-8') ?>" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit"
                                                            class="<?= $lang['is_active'] ? 'text-gray-600 hover:text-gray-900' : 'text-green-600 hover:text-green-900' ?>"
                                                            title="<?= $lang['is_active'] ? __("Disattiva") : __("Attiva") ?>">
                                                        <i class="fas fa-<?= $lang['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                                    </button>
                                                </form>

                                                <!-- Delete -->
                                                <?php if (!$lang['is_default']): ?>
                                                    <form method="POST" action="<?= htmlspecialchars(url('/admin/languages/' . rawurlencode($lang['code']) . '/delete'), ENT_QUOTES, 'UTF-8') ?>" class="inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit"
                                                                class="text-red-600 hover:text-red-900"
                                                                title="<?= __("Elimina") ?>"
                                                                onclick="return confirm(<?= htmlspecialchars(json_encode(__("Sei sicuro di voler eliminare questa lingua? Questa azione non può essere annullata."), JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Help Section -->
        <div class="mt-6 card">
            <div class="card-header">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-question-circle text-blue-600"></i>
                    <?= __("Guida alla Gestione Lingue") ?>
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-700">
                    <div>
                        <h4 class="font-semibold mb-2"><?= __("Aggiungere una Nuova Lingua") ?></h4>
                        <ol class="list-decimal list-inside space-y-1">
                            <li><?= __("Clicca su 'Aggiungi Lingua'") ?></li>
                            <li><?= __("Inserisci codice locale (es. es_ES per Spagnolo)") ?></li>
                            <li><?= __("Carica il file JSON di traduzione (opzionale)") ?></li>
                            <li><?= __("Imposta come attiva o predefinita") ?></li>
                        </ol>
                    </div>
                    <div>
                        <h4 class="font-semibold mb-2"><?= __("File di Traduzione JSON") ?></h4>
                        <p class="mb-2"><?= __("Il file JSON deve contenere coppie chiave-valore:") ?></p>
                        <pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto">{
  "Benvenuto": "Bienvenido",
  "Ciao": "Hola",
  "Grazie": "Gracias"
}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
