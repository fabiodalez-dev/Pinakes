<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
$activeTab = $activeTab ?? 'general';
?>
<div class="max-w-6xl mx-auto py-10">
  <div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
      <span class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-gray-100 text-gray-700">
        <i class="fas fa-sliders-h"></i>
      </span>
      Centro Impostazioni
    </h1>
    <p class="mt-2 text-sm text-gray-600">
      Configura l'identità dell'applicazione, i metodi di invio email e personalizza i template delle notifiche automatiche.
    </p>
  </div>

  <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
    <div class="border-b border-gray-200 px-6 py-4 flex flex-wrap gap-3">
      <button type="button" data-settings-tab="general" class="settings-tab <?php echo $activeTab === 'general' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-building text-sm mr-2"></i>
        Identità
      </button>
      <button type="button" data-settings-tab="email" class="settings-tab <?php echo $activeTab === 'email' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-envelope text-sm mr-2"></i>
        Email
      </button>
      <button type="button" data-settings-tab="templates" class="settings-tab <?php echo $activeTab === 'templates' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-file-alt text-sm mr-2"></i>
        Template
      </button>
      <button type="button" data-settings-tab="cms" class="settings-tab <?php echo $activeTab === 'cms' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-edit text-sm mr-2"></i>
        CMS
      </button>
      <button type="button" data-settings-tab="contacts" class="settings-tab <?php echo $activeTab === 'contacts' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-envelope text-sm mr-2"></i>
        Contatti
      </button>
      <button type="button" data-settings-tab="privacy" class="settings-tab <?php echo $activeTab === 'privacy' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-shield-alt text-sm mr-2"></i>
        Privacy
      </button>
      <button type="button" data-settings-tab="messages" class="settings-tab <?php echo $activeTab === 'messages' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-inbox text-sm mr-2"></i>
        Messaggi
      </button>
      <button type="button" data-settings-tab="labels" class="settings-tab <?php echo $activeTab === 'labels' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-barcode text-sm mr-2"></i>
        Etichette
      </button>
      <button type="button" data-settings-tab="advanced" class="settings-tab <?php echo $activeTab === 'advanced' ? 'settings-tab-active' : ''; ?>">
        <i class="fas fa-cogs text-sm mr-2"></i>
        Avanzate
      </button>
    </div>

    <div class="p-6">
      <!-- General Settings -->
      <section data-settings-panel="general" class="settings-panel <?php echo $activeTab === 'general' ? 'block' : 'hidden'; ?>">
        <form action="/admin/settings/general" method="post" enctype="multipart/form-data" class="space-y-8">
          <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-id-card-alt text-gray-500"></i>
                Identità Applicazione
              </h2>
              <p class="text-sm text-gray-600">Imposta il nome mostrato nel backend e il logo utilizzato nel layout.</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
              <div>
                <label for="app_name" class="block text-sm font-medium text-gray-700">Nome applicazione</label>
                <input type="text"
                       id="app_name"
                       name="app_name"
                       value="<?php echo HtmlHelper::e((string)($appSettings['name'] ?? '')); ?>"
                       class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" />
              </div>

              <div class="space-y-3">
                <div class="flex items-center justify-between">
                  <label class="block text-sm font-medium text-gray-700">Logo</label>
                  <?php if (!empty($appSettings['logo'])): ?>
                    <label class="inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer">
                      <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300">
                      Rimuovi logo attuale
                    </label>
                  <?php endif; ?>
                </div>

                <?php $currentLogo = (string)($appSettings['logo'] ?? ''); ?>
                <div id="logo-preview-wrapper"
                     class="flex items-center gap-4 bg-white border border-gray-200 rounded-xl p-3 <?php echo $currentLogo === '' ? 'hidden' : ''; ?>"
                     data-original-src="<?php echo HtmlHelper::e($currentLogo); ?>">
                  <img id="logo-preview-image"
                       src="<?php echo $currentLogo !== '' ? HtmlHelper::e($currentLogo) : ''; ?>"
                       alt="Anteprima logo"
                       class="h-16 object-contain <?php echo $currentLogo === '' ? 'hidden' : ''; ?>">
                  <div id="logo-preview-label" class="text-xs text-gray-500">
                    <?php echo $currentLogo !== '' ? 'Anteprima logo' : 'Nessun logo caricato'; ?>
                  </div>
                </div>

                <!-- Uppy Upload Area -->
                <div id="uppy-logo-upload" class="mb-4"></div>
                <div id="uppy-logo-progress" class="mb-4"></div>
                <!-- Fallback file input (hidden, used by Uppy) -->
                <input type="file"
                       name="app_logo"
                       accept="image/png,image/jpeg,image/webp,image/svg+xml"
                       style="display: none;"
                       id="logo-file-input">
                <p class="text-xs text-gray-500">Consigliato PNG o SVG con sfondo trasparente. Dimensione massima 2MB.</p>
              </div>
            </div>
          </div>

          <!-- Footer Section -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-alt text-gray-500"></i>
                Footer
              </h2>
              <p class="text-sm text-gray-600">Personalizza il testo descrittivo e i link ai social media nel footer del sito</p>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
              <div>
                <label for="footer_description" class="block text-sm font-medium text-gray-700">Descrizione footer</label>
                <textarea
                  id="footer_description"
                  name="footer_description"
                  rows="3"
                  class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                  placeholder="<?= __('La tua biblioteca digitale...') ?>"><?php echo HtmlHelper::e((string)($appSettings['footer_description'] ?? '')); ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Testo che apparirà nel footer del sito</p>
              </div>

              <div class="border-t border-gray-200 pt-4">
                <label class="block text-sm font-medium text-gray-700 mb-3">
                  <i class="fab fa-facebook-square mr-1"></i> Link Social Media
                </label>
                <div class="space-y-3">
                  <div>
                    <label for="social_facebook" class="block text-xs text-gray-600 mb-1">
                      <i class="fab fa-facebook mr-1"></i> Facebook
                    </label>
                    <input type="url"
                           id="social_facebook"
                           name="social_facebook"
                           value="<?php echo HtmlHelper::e((string)($appSettings['social_facebook'] ?? '')); ?>"
                           class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('https://facebook.com/tuapagina') ?>">
                  </div>
                  <div>
                    <label for="social_twitter" class="block text-xs text-gray-600 mb-1">
                      <i class="fab fa-twitter mr-1"></i> Twitter
                    </label>
                    <input type="url"
                           id="social_twitter"
                           name="social_twitter"
                           value="<?php echo HtmlHelper::e((string)($appSettings['social_twitter'] ?? '')); ?>"
                           class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('https://twitter.com/tuoprofilo') ?>">
                  </div>
                  <div>
                    <label for="social_instagram" class="block text-xs text-gray-600 mb-1">
                      <i class="fab fa-instagram mr-1"></i> Instagram
                    </label>
                    <input type="url"
                           id="social_instagram"
                           name="social_instagram"
                           value="<?php echo HtmlHelper::e((string)($appSettings['social_instagram'] ?? '')); ?>"
                           class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('https://instagram.com/tuoprofilo') ?>">
                  </div>
                  <div>
                    <label for="social_linkedin" class="block text-xs text-gray-600 mb-1">
                      <i class="fab fa-linkedin mr-1"></i> LinkedIn
                    </label>
                    <input type="url"
                           id="social_linkedin"
                           name="social_linkedin"
                           value="<?php echo HtmlHelper::e((string)($appSettings['social_linkedin'] ?? '')); ?>"
                           class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('https://linkedin.com/company/tuaazienda') ?>">
                  </div>
                  <div>
                    <label for="social_bluesky" class="block text-xs text-gray-600 mb-1">
                      <i class="fa-brands fa-bluesky mr-1"></i> Bluesky
                    </label>
                    <input type="url"
                           id="social_bluesky"
                           name="social_bluesky"
                           value="<?php echo HtmlHelper::e((string)($appSettings['social_bluesky'] ?? '')); ?>"
                           class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('https://bsky.app/profile/tuoprofilo') ?>">
                  </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                  <i class="fas fa-info-circle"></i> Lascia vuoto per nascondere il social dal footer
                </p>
              </div>
            </div>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-save"></i>
              Salva identità
            </button>
          </div>
        </form>
      </section>

      <!-- Email Settings -->
      <section data-settings-panel="email" class="settings-panel <?php echo $activeTab === 'email' ? 'block' : 'hidden'; ?>">
        <form action="/admin/settings/email" method="post" class="space-y-8">
          <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e(Csrf::ensureToken()); ?>">

          <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="space-y-4">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-paper-plane text-gray-500"></i>
                Configurazione invio
              </h2>
              <p class="text-sm text-gray-600">Scegli come inviare le email dal sistema. Puoi usare la funzione PHP <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">mail()</code>, PHPMailer o un server SMTP esterno.</p>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
              <div>
                <label for="mail_driver" class="block text-sm font-medium text-gray-700">Metodo di invio</label>
                <select id="mail_driver" name="mail_driver" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                  <?php $selectedDriver = (string)($emailSettings['type'] ?? 'mail'); ?>
                  <option value="mail" <?php echo $selectedDriver === 'mail' ? 'selected' : ''; ?>>PHP mail()</option>
                  <option value="phpmailer" <?php echo $selectedDriver === 'phpmailer' ? 'selected' : ''; ?>>PHPMailer</option>
                  <option value="smtp" <?php echo $selectedDriver === 'smtp' ? 'selected' : ''; ?>>SMTP personalizzato</option>
                </select>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label for="from_email" class="block text-sm font-medium text-gray-700">Mittente (email)</label>
                  <input type="email" id="from_email" name="from_email" value="<?php echo HtmlHelper::e((string)($emailSettings['from_email'] ?? '')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" placeholder="<?= __('es. noreply@biblioteca.local') ?>">
                </div>
                <div>
                  <label for="from_name" class="block text-sm font-medium text-gray-700">Mittente (nome)</label>
                  <input type="text" id="from_name" name="from_name" value="<?php echo HtmlHelper::e((string)($emailSettings['from_name'] ?? '')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" placeholder="<?= __('es. Biblioteca Civica') ?>">
                </div>
              </div>
            </div>
          </div>

          <div id="smtp-settings-card" class="border border-gray-200 rounded-2xl p-5 bg-white">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide">Server SMTP</h3>
              <span class="text-xs text-gray-500">Disponibile solo con driver SMTP</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="smtp_host" class="block text-sm font-medium text-gray-700">Host</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?php echo HtmlHelper::e((string)($emailSettings['smtp_host'] ?? '')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4" placeholder="<?= __('smtp.example.com') ?>">
              </div>
              <div>
                <label for="smtp_port" class="block text-sm font-medium text-gray-700">Porta</label>
                <input type="number" min="1" id="smtp_port" name="smtp_port" value="<?php echo HtmlHelper::e((string)($emailSettings['smtp_port'] ?? '587')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
              </div>
              <div>
                <label for="smtp_username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo HtmlHelper::e((string)($emailSettings['smtp_username'] ?? '')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
              </div>
              <div>
                <label for="smtp_password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" id="smtp_password" autocomplete="off" name="smtp_password" value="<?php echo HtmlHelper::e((string)($emailSettings['smtp_password'] ?? '')); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
              </div>
              <div>
                <label for="smtp_encryption" class="block text-sm font-medium text-gray-700">Crittografia</label>
                <?php $encryption = (string)($emailSettings['smtp_security'] ?? 'tls'); ?>
                <select id="smtp_encryption" name="smtp_encryption" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                  <option value="tls" <?php echo $encryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                  <option value="ssl" <?php echo $encryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                  <option value="none" <?php echo $encryption === 'none' ? 'selected' : ''; ?>>Nessuna</option>
                </select>
              </div>
            </div>
          </div>

          <div id="phpmailer-note" class="hidden rounded-2xl border border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">
            <div class="flex items-start gap-3">
              <i class="fas fa-info-circle text-base text-gray-500 mt-0.5"></i>
              <div>
                <p class="font-semibold text-gray-800">PHPMailer</p>
                <p>Quando utilizzi PHPMailer il sistema invia le email con le configurazioni definite nel codice o tramite provider esterni. Passa al driver "SMTP personalizzato" per modificare questi parametri direttamente dall'interfaccia.</p>
              </div>
            </div>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-save"></i>
              Salva impostazioni email
            </button>
          </div>
        </form>
      </section>

      <!-- Email Templates -->
      <section data-settings-panel="templates" class="settings-panel <?php echo $activeTab === 'templates' ? 'block' : 'hidden'; ?>">
        <div class="space-y-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-envelope-open-text text-gray-500"></i>
                Template email
              </h2>
              <p class="text-sm text-gray-600">Personalizza il contenuto delle mail automatiche con l'editor TinyMCE. Usa i segnaposto <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{variabile}}</code> per inserire dati dinamici.</p>
            </div>
            <div class="text-xs text-gray-500 bg-gray-100 border border-gray-200 rounded-lg px-3 py-2">
              Segnaposto disponibili mostrati in ciascun template.
            </div>
          </div>

          <div class="space-y-6">
            <?php foreach ($templates as $template): ?>
              <div class="border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="bg-gray-50 px-5 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                  <div class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900"><?php echo HtmlHelper::e($template['label']); ?></h3>
                    <p class="text-sm text-gray-600 mt-1"><?php echo HtmlHelper::e($template['description']); ?></p>
                    <?php if (!empty($template['placeholders'])): ?>
                      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span class="font-medium text-gray-700">Segnaposto:</span>
                        <?php foreach ($template['placeholders'] as $placeholder): ?>
                          <span class="inline-flex items-center rounded-lg bg-gray-200/70 px-2 py-1 text-[11px] font-semibold text-gray-700">{{<?php echo HtmlHelper::e($placeholder); ?>}}</span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="flex items-center gap-2 text-xs text-gray-500 flex-shrink-0">
                    <i class="fas fa-code text-gray-400"></i>
                    <span class="font-mono"><?php echo HtmlHelper::e($template['name']); ?></span>
                  </div>
                </div>

                <form action="/admin/settings/templates/<?php echo HtmlHelper::e($template['name']); ?>" method="post" class="p-5 space-y-4">
                  <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e(Csrf::ensureToken()); ?>">
                  <div>
                    <label class="block text-sm font-medium text-gray-700">Oggetto</label>
                    <input type="text" name="subject" value="<?php echo HtmlHelper::e($template['subject']); ?>" class="mt-1 block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Corpo email</label>
                    <textarea name="body" class="tinymce-editor" data-template="<?php echo HtmlHelper::e($template['name']); ?>"><?php echo HtmlHelper::escape($template['body']); ?></textarea>
                  </div>
                  <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
                      <i class="fas fa-save"></i>
                      Salva template
                    </button>
                  </div>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- CMS Settings -->
      <section data-settings-panel="cms" class="settings-panel <?php echo $activeTab === 'cms' ? 'block' : 'hidden'; ?>">
        <div class="space-y-6">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-file-alt text-gray-500"></i>
                Gestione Contenuti (CMS)
              </h2>
              <p class="text-sm text-gray-600 mt-1">Modifica le pagine statiche del sito</p>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 hover:border-gray-300 transition-colors">
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center">
                      <i class="fas fa-home text-blue-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900">Homepage</h3>
                  </div>
                  <p class="text-sm text-gray-600">Modifica i contenuti della homepage: hero, features, CTA e immagine di sfondo</p>
                  <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                    <i class="fas fa-link"></i>
                    <a href="/" target="_blank" class="hover:text-gray-900 underline">Visualizza pagina live</a>
                  </div>
                </div>
              </div>
              <div class="mt-4">
                <a href="/admin/cms/home" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors w-full justify-center">
                  <i class="fas fa-edit"></i>
                  Modifica Homepage
                </a>
              </div>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 hover:border-gray-300 transition-colors">
              <div class="flex items-start justify-between gap-4">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center">
                      <i class="fas fa-info-circle text-green-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900">Chi Siamo</h3>
                  </div>
                  <p class="text-sm text-gray-600">Gestisci il contenuto della pagina Chi Siamo con testo e immagine personalizzati</p>
                  <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
                    <i class="fas fa-link"></i>
                    <a href="/chi-siamo" target="_blank" class="hover:text-gray-900 underline">Visualizza pagina live</a>
                  </div>
                </div>
              </div>
              <div class="mt-4">
                <a href="/admin/cms/chi-siamo" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors w-full justify-center">
                  <i class="fas fa-edit"></i>
                  Modifica Chi Siamo
                </a>
              </div>
            </div>
          </div>

          <div class="bg-blue-50 border border-blue-200 rounded-2xl p-5">
            <div class="flex items-start gap-3">
              <i class="fas fa-lightbulb text-blue-600 text-lg mt-0.5"></i>
              <div class="text-sm text-blue-800">
                <p class="font-semibold mb-1">Suggerimento</p>
                <p>Utilizza l'editor TinyMCE per formattare il testo e Uppy per caricare immagini di alta qualità. Le modifiche saranno immediatamente visibili nella pagina pubblica.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Contacts Settings -->
      <?php include __DIR__ . '/contacts-tab.php'; ?>

      <!-- Privacy Settings -->
      <?php include __DIR__ . '/privacy-tab.php'; ?>

      <!-- Messages Tab -->
      <?php include __DIR__ . '/messages-tab.php'; ?>

      <!-- Label Settings -->
      <section data-settings-panel="labels" class="settings-panel <?php echo $activeTab === 'labels' ? 'block' : 'hidden'; ?>">
        <form action="/admin/settings/labels" method="post" class="space-y-8">
          <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e(Csrf::ensureToken()); ?>">

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="space-y-4">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-barcode text-gray-500"></i>
                Configurazione Etichette Libri
              </h2>
              <p class="text-sm text-gray-600">
                Seleziona il formato delle etichette da stampare per i libri.
                Il formato scelto verrà utilizzato per generare i PDF delle etichette con codice a barre.
              </p>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5 space-y-5">
              <div>
                <label for="label_format" class="block text-sm font-medium text-gray-700 mb-3">
                  Formato Etichetta
                </label>

                <?php
                $currentWidth = (int)($labelSettings['width'] ?? 25);
                $currentHeight = (int)($labelSettings['height'] ?? 38);
                $currentFormat = "{$currentWidth}x{$currentHeight}";

                $labelFormats = [
                  ['width' => 25, 'height' => 38, 'name' => '25×38mm', 'desc' => 'Standard dorso libri (più comune)'],
                  ['width' => 50, 'height' => 25, 'name' => '50×25mm', 'desc' => 'Formato orizzontale per dorso'],
                  ['width' => 70, 'height' => 36, 'name' => '70×36mm', 'desc' => 'Etichette interne grandi (Herma 4630, Avery 3490)'],
                  ['width' => 25, 'height' => 40, 'name' => '25×40mm', 'desc' => 'Standard Tirrenia catalogazione'],
                  ['width' => 34, 'height' => 48, 'name' => '34×48mm', 'desc' => 'Formato quadrato Tirrenia'],
                  ['width' => 52, 'height' => 30, 'name' => '52×30mm', 'desc' => 'Formato biblioteche scolastiche (compatibile A4)'],
                ];
                ?>

                <div class="space-y-3">
                  <?php foreach ($labelFormats as $format): ?>
                    <?php
                      $formatKey = "{$format['width']}x{$format['height']}";
                      $isSelected = $formatKey === $currentFormat;
                    ?>
                    <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all <?php echo $isSelected ? 'border-gray-900 bg-gray-100' : 'border-gray-200 hover:border-gray-300 bg-white'; ?>">
                      <input type="radio"
                             name="label_format"
                             value="<?php echo HtmlHelper::e($formatKey); ?>"
                             <?php echo $isSelected ? 'checked' : ''; ?>
                             class="mt-1 w-4 h-4 aspect-square flex-shrink-0 text-gray-900 focus:ring-gray-500">
                      <div class="flex-1">
                        <div class="flex items-center gap-2">
                          <span class="font-semibold text-gray-900"><?php echo HtmlHelper::e($format['name']); ?></span>
                          <span class="text-xs text-gray-500">(<?php echo $format['width']; ?>×<?php echo $format['height']; ?>mm)</span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1"><?php echo HtmlHelper::e($format['desc']); ?></p>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                <div class="flex gap-2">
                  <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                  <div class="text-sm text-blue-800">
                    <p class="font-medium mb-1">Nota:</p>
                    <p>Il formato selezionato verrà applicato a tutte le etichette generate dal sistema.
                    Assicurati che corrisponda al tipo di carta per etichette che utilizzi.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="flex justify-end">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
              <i class="fas fa-save"></i>
              Salva impostazioni etichette
            </button>
          </div>
        </form>
      </section>

      <!-- Advanced Settings -->
      <?php include __DIR__ . '/advanced-tab.php'; ?>
    </div>
  </div>
</div>

<style>
  .settings-tab {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: #4b5563;
    background-color: #f3f4f6;
    transition: background-color 0.2s ease, color 0.2s ease;
  }

  .settings-tab:hover {
    background-color: #e5e7eb;
  }

  .settings-tab-active {
    background-color: #111827;
    color: #ffffff;
  }

  .settings-tab-active:hover {
    background-color: #1f2937;
  }

  /* Fix radio button size - override contrast-fixes.css */
  input[type="radio"][name="label_format"] {
    width: 1rem !important;
    height: 1rem !important;
    min-height: 1rem !important;
    min-width: 1rem !important;
  }
</style>

<script src="/assets/tinymce/tinymce.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('[data-settings-tab]');
    const panels = document.querySelectorAll('[data-settings-panel]');

    function activateTab(tabName) {
      tabs.forEach(t => t.classList.remove('settings-tab-active'));
      const targetTab = document.querySelector(`[data-settings-tab="${tabName}"]`);
      if (targetTab) {
        targetTab.classList.add('settings-tab-active');
      }
      panels.forEach(panel => {
        const isActive = panel.getAttribute('data-settings-panel') === tabName;
        panel.classList.toggle('hidden', !isActive);
        panel.classList.toggle('block', isActive);
      });
    }

    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const target = tab.getAttribute('data-settings-tab');
        activateTab(target);
        // Update URL with both query parameter and hash
        const url = new URL(window.location.href);
        url.searchParams.set('tab', target);
        url.hash = target;
        window.history.pushState({}, '', url.toString());
      });
    });

    // Check URL hash on page load
    const hash = window.location.hash.substring(1);
    if (hash && document.querySelector(`[data-settings-tab="${hash}"]`)) {
      activateTab(hash);
    }

    // Handle browser back/forward
    window.addEventListener('hashchange', () => {
      const currentHash = window.location.hash.substring(1);
      if (currentHash && document.querySelector(`[data-settings-tab="${currentHash}"]`)) {
        activateTab(currentHash);
      }
    });

    if (window.tinymce) {
      tinymce.init({
        selector: 'textarea.tinymce-editor',
        license_key: 'gpl',
        menubar: false,
        height: 320,
        plugins: 'link lists table code autoresize',
        toolbar: 'undo redo | styles | bold italic underline | alignleft aligncenter alignright | bullist numlist | link table | code',
        style_formats: [
          { title: __('Paragraph'), format: 'p' },
          { title: __('Heading 1'), format: 'h1' },
          { title: __('Heading 2'), format: 'h2' },
          { title: __('Heading 3'), format: 'h3' },
          { title: __('Heading 4'), format: 'h4' },
          { title: __('Heading 5'), format: 'h5' },
          { title: __('Heading 6'), format: 'h6' }
        ],
        branding: false,
        relative_urls: false,
        remove_script_host: false,
        setup: function(editor) {
          // Save TinyMCE content to textarea before form submit
          editor.on('init', function() {
            const form = editor.getElement().closest('form');
            if (form) {
              form.addEventListener('submit', function(e) {
                tinymce.triggerSave();
              });
            }
          });
        },
      });
    }

    const driverSelect = document.getElementById('mail_driver');
    const smtpCard = document.getElementById('smtp-settings-card');
    const phpmailerNote = document.getElementById('phpmailer-note');

    function updateEmailDriverUI() {
      if (!driverSelect) return;
      const value = driverSelect.value;
      if (smtpCard) {
        smtpCard.classList.toggle('hidden', value !== 'smtp');
      }
      if (phpmailerNote) {
        phpmailerNote.classList.toggle('hidden', value !== 'phpmailer');
      }
    }

    if (driverSelect) {
      driverSelect.addEventListener('change', updateEmailDriverUI);
      updateEmailDriverUI();
    }

    const previewWrapper = document.getElementById('logo-preview-wrapper');
    const previewImage = document.getElementById('logo-preview-image');
    const previewLabel = document.getElementById('logo-preview-label');
    const removeLogoCheckbox = document.querySelector('input[name="remove_logo"]');
    const logoFileInput = document.getElementById('logo-file-input');
    const originalLogoSrc = previewWrapper ? previewWrapper.dataset.originalSrc || '' : '';
    let currentLogoPreviewSrc = originalLogoSrc;
    let tempLogoObjectUrl = null;
    let uppyLogoInstance = null;

    const setLogoPreview = (src) => {
      if (!previewWrapper || !previewImage || !previewLabel) return;
      if (src) {
        previewImage.src = src;
        previewWrapper.classList.remove('hidden');
        previewImage.classList.remove('hidden');
        previewLabel.textContent = 'Anteprima logo';
        currentLogoPreviewSrc = src;
      } else {
        previewImage.src = '';
        previewImage.classList.add('hidden');
        previewLabel.textContent = 'Nessun logo caricato';
        previewWrapper.classList.add('hidden');
        currentLogoPreviewSrc = '';
      }
    };

    if (!originalLogoSrc) {
      setLogoPreview('');
    }

    if (removeLogoCheckbox) {
      removeLogoCheckbox.addEventListener('change', (event) => {
        if (event.target.checked) {
          if (logoFileInput) {
            logoFileInput.value = '';
          }
          if (tempLogoObjectUrl) {
            URL.revokeObjectURL(tempLogoObjectUrl);
            tempLogoObjectUrl = null;
          }
          setLogoPreview('');
          if (uppyLogoInstance) {
            uppyLogoInstance.reset();
          }
        } else {
          if (currentLogoPreviewSrc) {
            setLogoPreview(currentLogoPreviewSrc);
          } else if (originalLogoSrc) {
            setLogoPreview(originalLogoSrc);
          }
        }
      });
    }

    // Initialize Uppy for logo upload
    if (typeof Uppy !== 'undefined') {
      try {
        const uppyLogo = new Uppy({
          restrictions: {
            maxFileSize: 2 * 1024 * 1024, // 2MB
            maxNumberOfFiles: 1,
            allowedFileTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml']
          },
          autoProceed: false
        });

        uppyLogoInstance = uppyLogo;

        uppyLogo.use(UppyDragDrop, {
          target: '#uppy-logo-upload',
          note: 'PNG, SVG, JPG o WebP (max 2MB)',
          locale: {
            strings: {
              dropPasteFiles: 'Trascina qui il logo o %{browse}',
              browse: 'seleziona file'
            }
          }
        });

        uppyLogo.use(UppyProgressBar, {
          target: '#uppy-logo-progress',
          hideAfterFinish: false
        });

        uppyLogo.on('file-added', (file) => {
          if (removeLogoCheckbox) {
            removeLogoCheckbox.checked = false;
          }

          let previewSrc = '';

          if (file.preview) {
            if (tempLogoObjectUrl) {
              URL.revokeObjectURL(tempLogoObjectUrl);
              tempLogoObjectUrl = null;
            }
            previewSrc = file.preview;
          } else if (file.data instanceof Blob) {
            if (tempLogoObjectUrl) {
              URL.revokeObjectURL(tempLogoObjectUrl);
            }
            tempLogoObjectUrl = URL.createObjectURL(file.data);
            previewSrc = tempLogoObjectUrl;
          }

          if (previewSrc) {
            setLogoPreview(previewSrc);
          }

          const dataTransfer = new DataTransfer();

          if (file.data instanceof File) {
            dataTransfer.items.add(file.data);
            if (logoFileInput) {
              logoFileInput.files = dataTransfer.files;
            }
          } else if (file.data instanceof Blob) {
            const newFile = new File([file.data], file.name, { type: file.type });
            dataTransfer.items.add(newFile);
            if (logoFileInput) {
              logoFileInput.files = dataTransfer.files;
            }
          } else if (file.preview) {
            fetch(file.preview)
              .then(res => res.blob())
              .then(blob => {
                const newFile = new File([blob], file.name, { type: file.type });
                dataTransfer.items.add(newFile);
                if (logoFileInput) {
                  logoFileInput.files = dataTransfer.files;
                }
              })
              .catch(() => {
                if (logoFileInput) {
                  logoFileInput.value = '';
                }
              });
          }

          if (previewSrc) {
            currentLogoPreviewSrc = previewSrc;
          }
        });

        uppyLogo.on('file-removed', () => {
          if (logoFileInput) {
            logoFileInput.value = '';
          }
          if (tempLogoObjectUrl) {
            URL.revokeObjectURL(tempLogoObjectUrl);
            tempLogoObjectUrl = null;
          }
          if (removeLogoCheckbox && removeLogoCheckbox.checked) {
            setLogoPreview('');
            return;
          }
          if (removeLogoCheckbox) {
            removeLogoCheckbox.checked = false;
          }
          if (originalLogoSrc) {
            setLogoPreview(originalLogoSrc);
            currentLogoPreviewSrc = originalLogoSrc;
          } else {
            setLogoPreview('');
          }
        });

        uppyLogo.on('restriction-failed', (file, error) => {
          if (typeof Swal !== 'undefined') {
            Swal.fire({
              icon: 'error',
              title: __('Errore Upload'),
              text: error.message
            });
          } else {
            alert('Errore: ' + error.message);
          }
        });
      } catch (error) {
        console.error('Error initializing Uppy for logo:', error);
        if (logoFileInput) {
          logoFileInput.style.display = 'block';
        }
      }
    } else {
      // Fallback to regular file input
      if (logoFileInput) {
        logoFileInput.style.display = 'block';
      }
    }
  });
</script>
