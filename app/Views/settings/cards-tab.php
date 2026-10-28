<?php
/** @var array{layout:string, code_type:string, format:string, title:string, back_text:string, show_photo:bool, show_logo:bool, show_expiry:bool} $cardSettings */
use App\Support\Csrf;

$cardLayouts = [
  ['key' => 'classic', 'name' => __('Classica'), 'desc' => __('Fascia con logo e nome della biblioteca, foto a sinistra, nome, codice e scadenza a destra.')],
  ['key' => 'minimal', 'name' => __('Essenziale'), 'desc' => __('Senza foto: nome grande e codice a barre su tutta la larghezza.')],
  ['key' => 'portrait', 'name' => __('Verticale'), 'desc' => __('Tessera in verticale con la foto al centro (9 per foglio A4).')],
];
$cardCodeTypes = [
  ['key' => 'code128', 'name' => __('Codice a barre (Code128)'), 'desc' => __('Leggibile da ogni lettore di codici a barre del banco prestiti.')],
  ['key' => 'qr', 'name' => __('QR code'), 'desc' => __('Leggibile anche con la fotocamera dello smartphone.')],
];
$cardFormats = [
  ['key' => 'single', 'name' => __('Tessera singola (CR80)'), 'desc' => __('Una tessera per pagina, 85,6×54mm: per stampanti di tessere.')],
  ['key' => 'sheet', 'name' => __('Foglio A4 da 10'), 'desc' => __('Dieci tessere per foglio A4 con i bordi di taglio.')],
];
?>
      <!-- Card Settings -->
      <section data-settings-panel="cards" class="settings-panel <?php echo ($activeTab ?? '') === 'cards' ? 'block' : 'hidden'; ?>">
        <form action="<?= htmlspecialchars(url('/admin/settings/cards'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-8">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-id-card text-gray-500"></i>
                <?= __("Configurazione Tessere") ?>
              </h2>
              <p class="text-sm text-gray-600">
                <?= __("Scegli il layout delle tessere della biblioteca e il codice che le identifica al banco prestiti.") ?>
                <?= __("La tessera si stampa dalla scheda dell'utente o, per più utenti insieme, dalla selezione nell'elenco utenti.") ?>
              </p>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
              <div>
                <p class="block text-sm font-medium text-gray-700 mb-3">
                  <?= __("Layout") ?>
                </p>
                <div class="space-y-3">
                  <?php foreach ($cardLayouts as $layout): ?>
                    <?php $isSelected = $layout['key'] === $cardSettings['layout']; ?>
                    <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all <?php echo $isSelected ? 'border-gray-900 bg-gray-100' : 'border-gray-200 hover:border-gray-300 bg-white'; ?>">
                      <input type="radio"
                             name="card_layout"
                             value="<?php echo htmlspecialchars((string) $layout['key'], ENT_QUOTES, 'UTF-8'); ?>"
                             <?php echo $isSelected ? 'checked' : ''; ?>
                             class="mt-1 w-4 h-4 aspect-square shrink-0 text-gray-900 focus:ring-gray-500">
                      <div class="flex-1">
                        <div class="flex flex-col md:flex-row items-start md:items-center gap-2">
                          <span class="font-semibold text-gray-900"><?php echo htmlspecialchars((string) $layout['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars((string) $layout['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <fieldset class="border-t border-gray-200 pt-5">
                <legend class="font-semibold text-gray-900 mb-3"><?= __('Codice della tessera') ?></legend>
                <div class="space-y-3">
                  <?php foreach ($cardCodeTypes as $codeType): ?>
                    <?php $isSelected = $codeType['key'] === $cardSettings['code_type']; ?>
                    <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all <?php echo $isSelected ? 'border-gray-900 bg-gray-100' : 'border-gray-200 hover:border-gray-300 bg-white'; ?>">
                      <input type="radio"
                             name="card_code_type"
                             value="<?php echo htmlspecialchars((string) $codeType['key'], ENT_QUOTES, 'UTF-8'); ?>"
                             <?php echo $isSelected ? 'checked' : ''; ?>
                             class="mt-1 w-4 h-4 aspect-square shrink-0 text-gray-900 focus:ring-gray-500">
                      <div class="flex-1">
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars((string) $codeType['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars((string) $codeType['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <fieldset class="border-t border-gray-200 pt-5">
                <legend class="font-semibold text-gray-900 mb-3"><?= __('Formato di stampa predefinito') ?></legend>
                <div class="space-y-3">
                  <?php foreach ($cardFormats as $cardFormat): ?>
                    <?php $isSelected = $cardFormat['key'] === $cardSettings['format']; ?>
                    <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all <?php echo $isSelected ? 'border-gray-900 bg-gray-100' : 'border-gray-200 hover:border-gray-300 bg-white'; ?>">
                      <input type="radio"
                             name="card_format"
                             value="<?php echo htmlspecialchars((string) $cardFormat['key'], ENT_QUOTES, 'UTF-8'); ?>"
                             <?php echo $isSelected ? 'checked' : ''; ?>
                             class="mt-1 w-4 h-4 aspect-square shrink-0 text-gray-900 focus:ring-gray-500">
                      <div class="flex-1">
                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars((string) $cardFormat['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars((string) $cardFormat['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <fieldset class="border-t border-gray-200 pt-5">
                <legend class="font-semibold text-gray-900 mb-3"><?= __('Contenuto tessera') ?></legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <?php foreach ([
                    'show_photo' => __('Foto dell\'utente'),
                    'show_logo' => __('Logo della biblioteca'),
                    'show_expiry' => __('Data di scadenza'),
                  ] as $settingKey => $settingLabel): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                      <input type="checkbox" name="card_<?= $settingKey ?>" value="1" <?= !empty($cardSettings[$settingKey]) ? 'checked' : '' ?> class="rounded border-gray-300">
                      <?= htmlspecialchars((string) $settingLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <label class="block text-sm text-gray-700 mt-4"><?= __('Intestazione') ?>
                  <input type="text" name="card_title" maxlength="<?= \App\Support\PatronCardSettings::TITLE_MAX ?>" value="<?= htmlspecialchars((string) $cardSettings['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars((string) __('Vuoto = nome della biblioteca'), ENT_QUOTES, 'UTF-8') ?>" class="form-input mt-1">
                </label>
              </fieldset>

              <fieldset class="border-t border-gray-200 pt-5">
                <legend class="font-semibold text-gray-900 mb-1"><?= __('Retro') ?></legend>
                <p class="text-sm text-gray-500 mb-3"><?= __('Testo stampato sul retro della tessera: regolamento, sito, orari, contatti. Se è vuoto la tessera non ha retro.') ?></p>
                <textarea name="card_back_text" rows="5" maxlength="<?= \App\Support\PatronCardSettings::BACK_TEXT_MAX ?>" class="form-input mt-1"><?= htmlspecialchars((string) $cardSettings['back_text'], ENT_QUOTES, 'UTF-8') ?></textarea>
              </fieldset>

              <div class="pk-inline-note">
                <div class="flex gap-2">
                  <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                  <div class="text-sm text-blue-800">
                    <p class="font-medium mb-1"><?= __("Nota:") ?></p>
                    <p><?= __("Nel foglio A4 il retro è stampato con le colonne specchiate: con la stampa fronte-retro sul lato lungo ogni retro finisce dietro la sua tessera.") ?></p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="pk-form-actions flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-save"></i>
              <?= __("Salva impostazioni tessere") ?>
            </button>
          </div>
        </form>
      </section>
