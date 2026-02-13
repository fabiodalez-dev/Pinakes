<?php use App\Support\HtmlHelper; ?>
<?php $cookieBannerTexts = $cookieBannerTexts ?? []; ?>
<section id="privacy" data-settings-panel="privacy" class="settings-panel <?php echo $activeTab === 'privacy' ? 'block' : 'hidden'; ?>">
  <form action="/admin/settings/privacy" method="post" class="space-y-8">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">

    <!-- Contenuto Privacy Policy -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-file-contract text-gray-500"></i>
          <?= __("Contenuto Privacy Policy") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Personalizza il titolo e il contenuto della pagina privacy policy") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3 md:p-5 space-y-4 md:space-y-5">
        <div>
          <label for="privacy_page_title" class="block text-sm font-medium text-gray-700"><?= __("Titolo pagina") ?></label>
          <input type="text"
                 id="privacy_page_title"
                 name="page_title"
                 value="<?php echo HtmlHelper::e($privacySettings['page_title'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('Privacy Policy') ?>" />
        </div>

        <div>
          <label for="privacy_page_content" class="block text-sm font-medium text-gray-700"><?= __("Contenuto pagina") ?></label>
          <textarea id="privacy_page_content"
                    name="page_content"
                    rows="10"
                    class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 tinymce-editor"><?php echo HtmlHelper::e($privacySettings['page_content'] ?? ''); ?></textarea>
        </div>
      </div>
    </div>

    <!-- Cookie Policy Content -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-cookie-bite text-gray-500"></i>
          <?= __("Pagina Cookie Policy") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Contenuto della pagina /cookies accessibile dal banner") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3 md:p-5 space-y-4 md:space-y-5">
        <div>
          <label for="cookie_policy_content" class="block text-sm font-medium text-gray-700"><?= __("Contenuto Cookie Policy") ?></label>
          <textarea id="cookie_policy_content"
                    name="cookie_policy_content"
                    rows="10"
                    class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 tinymce-editor"><?php echo HtmlHelper::e($privacySettings['cookie_policy_content'] ?? ''); ?></textarea>
          <p class="mt-2 text-xs text-gray-500"><?= __("Questo contenuto verrà mostrato nella pagina /cookies linkata dal cookie banner") ?></p>
        </div>
      </div>
    </div>

    <!-- Cookie Banner -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-cookie-bite text-gray-500"></i>
          <?= __("Cookie Banner") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Configurazione del banner cookie") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3 md:p-5 space-y-4 md:space-y-5">
        <div class="flex items-center justify-between">
          <label for="cookie_banner_enabled" class="text-sm font-medium text-gray-700"><?= __("Abilita Cookie Banner") ?></label>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox"
                   id="cookie_banner_enabled"
                   name="cookie_banner_enabled"
                   value="1"
                   <?php echo !empty($privacySettings['cookie_banner_enabled']) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label for="cookie_banner_language" class="block text-sm font-medium text-gray-700"><?= __("Lingua") ?></label>
            <select id="cookie_banner_language"
                    name="cookie_banner_language"
                    class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
              <option value="it" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'it' ? 'selected' : ''; ?>><?= __("Italiano (IT)") ?></option>
              <option value="en" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'en' ? 'selected' : ''; ?>><?= __("English (EN)") ?></option>
              <option value="de" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'de' ? 'selected' : ''; ?>><?= __("Deutsch (DE)") ?></option>
              <option value="es" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'es' ? 'selected' : ''; ?>><?= __("Español (ES)") ?></option>
              <option value="fr" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'fr' ? 'selected' : ''; ?>><?= __("Français (FR)") ?></option>
              <option value="nl" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'nl' ? 'selected' : ''; ?>><?= __("Nederlands (NL)") ?></option>
              <option value="pl" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'pl' ? 'selected' : ''; ?>><?= __("Polski (PL)") ?></option>
              <option value="da" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'da' ? 'selected' : ''; ?>><?= __("Dansk (DA)") ?></option>
              <option value="bg" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'bg' ? 'selected' : ''; ?>><?= __("Български (BG)") ?></option>
              <option value="ca" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'ca' ? 'selected' : ''; ?>><?= __("Català (CA)") ?></option>
              <option value="sk" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'sk' ? 'selected' : ''; ?>><?= __("Slovenčina (SK)") ?></option>
              <option value="he" <?php echo ($privacySettings['cookie_banner_language'] ?? 'it') === 'he' ? 'selected' : ''; ?>><?= __("עברית (HE)") ?></option>
            </select>
          </div>

          <div>
            <label for="cookie_banner_country" class="block text-sm font-medium text-gray-700"><?= __("Paese") ?></label>
            <input type="text"
                   id="cookie_banner_country"
                   name="cookie_banner_country"
                   value="<?php echo HtmlHelper::e($privacySettings['cookie_banner_country'] ?? 'it'); ?>"
                   maxlength="2"
                   class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 uppercase"
                   placeholder="<?= __('IT') ?>" />
            <p class="mt-1 text-xs text-gray-500"><?= __("Codice ISO 2 lettere (es: IT, FR, GB)") ?></p>
          </div>
        </div>

        <div>
          <label for="cookie_statement_link" class="block text-sm font-medium text-gray-700"><?= __("Link Cookie Statement") ?></label>
          <input type="url"
                 id="cookie_statement_link"
                 name="cookie_statement_link"
                 value="<?php echo HtmlHelper::e($privacySettings['cookie_statement_link'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('https://esempio.com/cookie-policy') ?>" />
          <p class="mt-1 text-xs text-gray-500"><?= __("URL della pagina con la cookie policy") ?></p>
        </div>

        <div>
          <label for="cookie_technologies_link" class="block text-sm font-medium text-gray-700"><?= __("Link Cookie Technologies") ?></label>
          <input type="url"
                 id="cookie_technologies_link"
                 name="cookie_technologies_link"
                 value="<?php echo HtmlHelper::e($privacySettings['cookie_technologies_link'] ?? ''); ?>"
                 class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('https://esempio.com/tecnologie-cookie') ?>" />
          <p class="mt-1 text-xs text-gray-500"><?= __("URL della pagina con le tecnologie dei cookie") ?></p>
        </div>
      </div>
    </div>

    <!-- Cookie Categories Visibility -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-sliders-h text-gray-500"></i>
          <?= __("Categorie Cookie") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Gestisci la visibilità delle categorie di cookie nel banner. I cookie essenziali sono sempre visibili e obbligatori.") ?></p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-3 md:p-5 space-y-4 md:space-y-5">
        <div class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-xl">
          <div class="flex-1">
            <label for="show_analytics" class="text-sm font-medium text-gray-900 cursor-pointer flex items-center gap-2">
              <i class="fas fa-chart-line text-blue-600"></i>
              <?= __("Mostra Cookie Analitici") ?>
            </label>
            <p class="text-xs text-gray-500 mt-1"><?= __("Nascondi se il sito non utilizza strumenti di analytics (es. Google Analytics)") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox"
                   id="show_analytics"
                   name="show_analytics"
                   value="1"
                   <?php echo ($privacySettings['show_analytics'] ?? true) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>

        <div class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-xl">
          <div class="flex-1">
            <label for="show_marketing" class="text-sm font-medium text-gray-900 cursor-pointer flex items-center gap-2">
              <i class="fas fa-bullhorn text-orange-600"></i>
              <?= __("Mostra Cookie di Marketing") ?>
            </label>
            <p class="text-xs text-gray-500 mt-1"><?= __("Nascondi se il sito non utilizza cookie di marketing o advertising") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox"
                   id="show_marketing"
                   name="show_marketing"
                   value="1"
                   <?php echo ($privacySettings['show_marketing'] ?? true) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>

        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
          <div class="flex gap-2">
            <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
            <div class="text-sm text-blue-800">
              <p class="font-medium mb-1"><?= __("Nota:") ?></p>
              <p><?= __("I Cookie Essenziali sono sempre visibili e non possono essere disabilitati poiché necessari per il funzionamento del sito.") ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>

  <div class="flex justify-end gap-2 md:gap-3">
      <a href="<?= route_path('privacy') ?>" target="_blank" class="inline-flex items-center gap-2 px-3 py-2 md:px-5 md:py-3 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors">
        <i class="fas fa-eye"></i>
        <?= __("Anteprima") ?>
      </a>
      <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 md:px-5 md:py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva Privacy Policy") ?>
      </button>
    </div>
  </form>

  <div class="mt-12">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-language text-gray-500"></i>
          <?= __("Testi Banner Cookie") ?>
        </h2>
        <p class="text-sm text-gray-600">
          <?= __("Personalizza i testi mostrati sia nel banner iniziale che nel pannello delle preferenze.") ?>
        </p>
      </div>
      <div class="bg-gray-50 border border-gray-200 rounded-3xl p-3 md:p-5">
        <form action="/admin/settings/cookie-banner" method="post" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">

          <details class="bg-white rounded-2xl border border-gray-200 p-4 md:p-6 group" open>
            <summary class="flex items-center justify-between cursor-pointer text-left">
              <div>
                <p class="text-base font-semibold text-gray-900">
                  <?= __("Personalizzazione banner e preferenze") ?>
                </p>
                <p class="text-sm text-gray-600 mt-1">
                  <?= __("Configura i testi visualizzati agli utenti in ogni parte del cookie banner.") ?>
                </p>
              </div>
              <span class="accordion-icon w-8 h-8 rounded-full bg-gray-100 text-gray-600 flex items-center justify-center transition-transform duration-200 group-open:rotate-180">
                <i class="fas fa-chevron-down text-sm"></i>
              </span>
            </summary>

            <div class="mt-6 space-y-8">
              <div>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <h3 class="text-lg font-semibold text-gray-900"><?= __("Testi Banner Iniziale") ?></h3>
                    <p class="text-sm text-gray-600"><?= __("Configura i testi visualizzati agli utenti nel banner iniziale.") ?></p>
                  </div>
                </div>
                <div class="mt-4 space-y-4">
                  <div>
                    <label for="cookie_banner_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione banner") ?></label>
                    <textarea id="cookie_banner_description"
                              name="cookie_banner_description"
                              rows="4"
                              class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 tinymce-editor"><?php echo HtmlHelper::e($cookieBannerTexts['banner_description'] ?? ''); ?></textarea>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="cookie_accept_all_text" class="block text-sm font-medium text-gray-700"><?= __("Testo pulsante \"Accetta tutti\"") ?></label>
                      <input type="text"
                             id="cookie_accept_all_text"
                             name="cookie_accept_all_text"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['accept_all_text'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                    <div>
                      <label for="cookie_reject_non_essential_text" class="block text-sm font-medium text-gray-700"><?= __("Testo pulsante \"Rifiuta non essenziali\"") ?></label>
                      <input type="text"
                             id="cookie_reject_non_essential_text"
                             name="cookie_reject_non_essential_text"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['reject_non_essential_text'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="cookie_preferences_button_text" class="block text-sm font-medium text-gray-700"><?= __("Testo pulsante \"Preferenze\"") ?></label>
                      <input type="text"
                             id="cookie_preferences_button_text"
                             name="cookie_preferences_button_text"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['preferences_button_text'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                    <div>
                      <label for="cookie_save_selected_text" class="block text-sm font-medium text-gray-700"><?= __("Testo pulsante \"Salva selezionati\"") ?></label>
                      <input type="text"
                             id="cookie_save_selected_text"
                             name="cookie_save_selected_text"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['save_selected_text'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <h3 class="text-lg font-semibold text-gray-900"><?= __("Testi Modale Preferenze") ?></h3>
                    <p class="text-sm text-gray-600"><?= __("Configura i testi mostrati all'interno del pannello delle preferenze dei cookie.") ?></p>
                  </div>
                </div>
                <div class="mt-4 space-y-4">
                  <div>
                    <label for="cookie_preferences_title" class="block text-sm font-medium text-gray-700"><?= __("Titolo modale") ?></label>
                    <input type="text"
                           id="cookie_preferences_title"
                           name="cookie_preferences_title"
                           value="<?php echo HtmlHelper::e($cookieBannerTexts['preferences_title'] ?? ''); ?>"
                           class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                  </div>
                  <div>
                    <label for="cookie_preferences_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione modale") ?></label>
                    <textarea id="cookie_preferences_description"
                              name="cookie_preferences_description"
                              rows="4"
                              class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 tinymce-editor"><?php echo HtmlHelper::e($cookieBannerTexts['preferences_description'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>

              <div>
                <div class="flex items-center justify-between gap-3 flex-wrap">
                  <div>
                    <h3 class="text-lg font-semibold text-gray-900"><?= __("Testi categorie cookie") ?></h3>
                    <p class="text-sm text-gray-600"><?= __("Personalizza nome e descrizione delle categorie di cookie disponibili.") ?></p>
                  </div>
                </div>
                <div class="mt-4 space-y-5">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label for="cookie_essential_name" class="block text-sm font-medium text-gray-700"><?= __("Nome cookie essenziali") ?></label>
                      <input type="text"
                             id="cookie_essential_name"
                             name="cookie_essential_name"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['cookie_essential_name'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                    <div>
                      <label for="cookie_analytics_name" class="block text-sm font-medium text-gray-700"><?= __("Nome cookie analitici") ?></label>
                      <input type="text"
                             id="cookie_analytics_name"
                             name="cookie_analytics_name"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['cookie_analytics_name'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                    <div>
                      <label for="cookie_marketing_name" class="block text-sm font-medium text-gray-700"><?= __("Nome cookie marketing") ?></label>
                      <input type="text"
                             id="cookie_marketing_name"
                             name="cookie_marketing_name"
                             value="<?php echo HtmlHelper::e($cookieBannerTexts['cookie_marketing_name'] ?? ''); ?>"
                             class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
                    </div>
                  </div>
                  <div>
                    <label for="cookie_essential_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione cookie essenziali") ?></label>
                    <textarea id="cookie_essential_description"
                              name="cookie_essential_description"
                              rows="3"
                              class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"><?php echo HtmlHelper::e($cookieBannerTexts['cookie_essential_description'] ?? ''); ?></textarea>
                  </div>
                  <div>
                    <label for="cookie_analytics_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione cookie analitici") ?></label>
                    <textarea id="cookie_analytics_description"
                              name="cookie_analytics_description"
                              rows="3"
                              class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"><?php echo HtmlHelper::e($cookieBannerTexts['cookie_analytics_description'] ?? ''); ?></textarea>
                  </div>
                  <div>
                    <label for="cookie_marketing_description" class="block text-sm font-medium text-gray-700"><?= __("Descrizione cookie marketing") ?></label>
                    <textarea id="cookie_marketing_description"
                              name="cookie_marketing_description"
                              rows="3"
                              class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"><?php echo HtmlHelper::e($cookieBannerTexts['cookie_marketing_description'] ?? ''); ?></textarea>
                  </div>
                </div>
              </div>
            </div>
          </details>

          <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="/" target="_blank" class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900">
              <i class="fas fa-external-link-alt"></i>
              <?= __("Anteprima Banner") ?>
            </a>
            <button type="submit" class="inline-flex items-center gap-2 px-4 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-save"></i>
              <?= __("Salva testi banner") ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<style>
.toggle-checkbox:checked + .toggle-bg {
  background-color: #111827;
}

.toggle-checkbox:checked ~ .toggle-dot {
  transform: translateX(1.25rem);
}

.toggle-checkbox:focus + .toggle-bg {
  outline: none;
  box-shadow: 0 0 0 4px rgba(156, 163, 175, 0.3);
}

.accordion-icon {
  transition: transform 0.2s ease;
}

.group[open] .accordion-icon {
  transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Handle all toggle switches generically
  const toggles = document.querySelectorAll('.toggle-checkbox');

  toggles.forEach(toggle => {
    const bg = toggle.nextElementSibling;
    const dot = bg.nextElementSibling;

    // Update toggle appearance
    function updateToggle() {
      if (toggle.checked) {
        bg.style.backgroundColor = '#111827';
        dot.style.transform = 'translateX(1.25rem)';
      } else {
        bg.style.backgroundColor = '#e5e7eb';
        dot.style.transform = 'translateX(0)';
      }
    }

    // Initialize on load
    updateToggle();

    // Handle toggle change
    toggle.addEventListener('change', updateToggle);
  });
});
</script>
