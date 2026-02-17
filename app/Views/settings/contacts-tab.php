<?php
/** @var string $activeTab */
/** @var string $csrfToken */
use App\Support\HtmlHelper;
?>
<section data-settings-panel="contacts" class="settings-panel <?php echo $activeTab === 'contacts' ? 'block' : 'hidden'; ?>">
  <form action="<?= htmlspecialchars(url('/admin/settings/contacts'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">

    <!-- Titolo e contenuto pagina -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-file-alt text-gray-500"></i>
          <?= __("Contenuto Pagina") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Personalizza il titolo e il testo introduttivo della pagina contatti") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
        <div>
          <label for="page_title" class="block text-sm font-medium text-gray-700"><?= __("Titolo pagina") ?></label>
          <input type="text"
                 id="page_title"
                 name="page_title"
                 value="<?php echo HtmlHelper::e($contactSettings['page_title'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('Contattaci') ?>" />
        </div>

        <div>
          <label for="page_content" class="block text-sm font-medium text-gray-700"><?= __("Testo introduttivo") ?></label>
          <textarea id="page_content"
                    name="page_content"
                    rows="4"
                    class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 tinymce-editor"><?php echo HtmlHelper::e($contactSettings['page_content'] ?? ''); ?></textarea>
        </div>
      </div>
    </div>

    <!-- Informazioni di contatto -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-address-card text-gray-500"></i>
          <?= __("Informazioni di Contatto") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Email e telefono visibili sulla pagina contatti") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
        <div>
          <label for="contact_email" class="block text-sm font-medium text-gray-700"><?= __("Email di contatto") ?></label>
          <input type="email"
                 id="contact_email"
                 name="contact_email"
                 value="<?php echo HtmlHelper::e($contactSettings['contact_email'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('info@biblioteca.it') ?>" />
          <p class="mt-1 text-xs text-gray-500"><?= __("Visibile pubblicamente sulla pagina contatti") ?></p>
        </div>

        <div>
          <label for="contact_phone" class="block text-sm font-medium text-gray-700"><?= __("Telefono") ?></label>
          <input type="tel"
                 id="contact_phone"
                 name="contact_phone"
                 value="<?php echo HtmlHelper::e($contactSettings['contact_phone'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('+39 049 123 4567') ?>" />
        </div>

        <div>
          <label for="notification_email" class="block text-sm font-medium text-gray-700"><?= __("Email per notifiche") ?></label>
          <input type="email"
                 id="notification_email"
                 name="notification_email"
                 value="<?php echo HtmlHelper::e($contactSettings['notification_email'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('admin@biblioteca.it') ?>" />
          <p class="mt-1 text-xs text-gray-500"><?= __("Email dove ricevere i messaggi dal form contatti") ?></p>
        </div>
      </div>
    </div>

    <!-- Maps (Google Maps or OpenStreetMap) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-map-marked-alt text-gray-500"></i>
          <?= __("Mappa Interattiva") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Embed della mappa (Google Maps o OpenStreetMap). Puoi inserire l'URL o il codice iframe completo.") ?></p>
        <p class="text-xs text-gray-500 mt-2">
          <strong><?= __("⚠️ Privacy: Le mappe esterne vengono caricate solo se l'utente accetta i cookie Analytics.") ?></strong>
        </p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
        <label for="google_maps_embed" class="block text-sm font-medium text-gray-700"><?= __("Codice embed completo") ?></label>
        <textarea id="google_maps_embed"
                  name="google_maps_embed"
                  rows="5"
                  class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono text-xs"
                  placeholder='<iframe src="https://www.google.com/maps/embed?pb=..." ...></iframe>'><?php echo HtmlHelper::e($contactSettings['google_maps_embed'] ?? ''); ?></textarea>
        <div class="mt-2 text-xs text-gray-500 space-y-1">
          <p><i class="fas fa-info-circle mr-1"></i><strong><?= __("Come ottenere il codice") ?></strong></p>
          <ul class="ml-4 space-y-1">
            <li>• <a href="https://www.google.com/maps" target="_blank" class="text-blue-600 hover:underline">Google Maps</a>: https://www.google.com/maps/embed?pb=...</li>
            <li>• <a href="https://www.openstreetmap.org/" target="_blank" class="text-blue-600 hover:underline">OpenStreetMap</a>: https://www.openstreetmap.org/export/embed.html?bbox=...</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- ReCAPTCHA v3 -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-shield-alt text-gray-500"></i>
          <?= __("Google reCAPTCHA v3") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Protezione anti-spam per il form contatti") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
        <div>
          <label for="recaptcha_site_key" class="block text-sm font-medium text-gray-700"><?= __("Site Key") ?></label>
          <input type="text"
                 id="recaptcha_site_key"
                 name="recaptcha_site_key"
                 value="<?php echo HtmlHelper::e($contactSettings['recaptcha_site_key'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono" />
        </div>

        <div>
          <label for="recaptcha_secret_key" class="block text-sm font-medium text-gray-700"><?= __("Secret Key") ?></label>
          <input type="password" autocomplete="off"
                 id="recaptcha_secret_key"
                 name="recaptcha_secret_key"
                 value="<?php echo HtmlHelper::e($contactSettings['recaptcha_secret_key'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 font-mono" />
        </div>

        <p class="text-xs text-gray-500">
          <i class="fas fa-info-circle mr-1"></i>
          <a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-blue-600 hover:underline"><?= __("Ottieni le chiavi da Google reCAPTCHA") ?></a>
        </p>
      </div>
    </div>

    <!-- Privacy -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-user-shield text-gray-500"></i>
          <?= __("Testo Privacy") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Testo della checkbox privacy nel form") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
        <label for="privacy_text" class="block text-sm font-medium text-gray-700"><?= __("Testo checkbox") ?></label>
        <textarea id="privacy_text"
                  name="privacy_text"
                  rows="3"
                  class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"><?php echo HtmlHelper::e($contactSettings['privacy_text'] ?? ''); ?></textarea>
      </div>
    </div>

    <div class="flex justify-end gap-3">
      <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors">
        <i class="fas fa-eye"></i>
        <?= __("Anteprima") ?>
      </a>
      <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva Contatti") ?>
      </button>
    </div>
  </form>
</section>
