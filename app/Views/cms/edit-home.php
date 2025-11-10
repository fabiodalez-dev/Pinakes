<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$hero = $sections['hero'] ?? null;
$featuresTitle = $sections['features_title'] ?? null;
$feature1 = $sections['feature_1'] ?? null;
$feature2 = $sections['feature_2'] ?? null;
$feature3 = $sections['feature_3'] ?? null;
$feature4 = $sections['feature_4'] ?? null;
$latestBooksTitle = $sections['latest_books_title'] ?? null;
$cta = $sections['cta'] ?? null;
?>

<div class="max-w-7xl mx-auto py-6 px-4">
  <div class="mb-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-home text-blue-600"></i>
          <?= __("Modifica Homepage") ?>
        </h1>
        <p class="mt-1 text-sm text-gray-600">
          <?= __("Personalizza tutti i contenuti della homepage del sito") ?>
        </p>
      </div>
      <a href="/admin/settings?tab=cms" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors">
        <i class="fas fa-arrow-left"></i>
        <?= __("Torna alle Impostazioni") ?>
      </a>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="mt-4 p-4 bg-green-50 text-green-800 border border-green-200 rounded-xl" role="alert">
        <i class="fas fa-check-circle mr-2"></i><?php echo HtmlHelper::e($_SESSION['success_message']); ?>
      </div>
      <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
      <div class="mt-4 p-4 bg-red-50 text-red-800 border border-red-200 rounded-xl" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $_SESSION['error_message']; ?>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
  </div>

  <form action="/admin/cms/home" method="post" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e(Csrf::ensureToken()); ?>">

    <!-- Hero Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-star text-yellow-500"></i>
          <?= __("Sezione Hero (Testata principale)") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1"><?= __("La sezione principale che appare per prima sulla home") ?></p>
      </div>
      <div class="p-6 space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div>
            <label for="hero_title" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Titolo principale (H1)") ?></label>
            <input type="text" id="hero_title" name="hero[title]" value="<?php echo HtmlHelper::e($hero['title'] ?? 'La Tua Biblioteca Digitale'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="<?= __('Es. La Tua Biblioteca Digitale') ?>">
          </div>
          <div>
            <label for="hero_subtitle" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Sottotitolo") ?></label>
            <input type="text" id="hero_subtitle" name="hero[subtitle]" value="<?php echo HtmlHelper::e($hero['subtitle'] ?? 'Esplora, prenota e gestisci la tua collezione di libri'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="<?= __('Descrizione breve') ?>">
          </div>
        </div>

        <div class="space-y-3">
          <label class="block text-sm font-medium text-gray-700"><?= __("Immagine di sfondo Hero") ?></label>
          <?php if (!empty($hero['background_image'])): ?>
            <div class="relative rounded-2xl overflow-hidden h-48 bg-gray-100">
              <img src="<?php echo HtmlHelper::e($hero['background_image']); ?>" alt="Sfondo hero" class="w-full h-full object-cover">
              <div class="absolute inset-0 flex items-center justify-center" style="background: rgba(0, 0, 0, 0.4);">
                <span class="text-white text-sm font-medium"><?= __("Immagine attuale") ?></span>
              </div>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer">
              <input type="checkbox" name="hero[remove_background]" value="1" class="rounded border-gray-300">
              <?= __("Rimuovi immagine di sfondo attuale") ?>
            </label>
          <?php endif; ?>
          <!-- Uppy Upload Area -->
          <div id="uppy-hero-upload" class="mb-4"></div>
          <div id="uppy-hero-progress" class="mb-4"></div>
          <!-- Fallback file input (hidden, used by Uppy) -->
          <input type="file" name="hero_background" accept="image/jpeg,image/jpg,image/png,image/webp"
                 style="display: none;" id="hero-background-input">
          <p class="text-xs text-gray-500"><?= __("Consigliato JPG o PNG ad alta risoluzione (min 1920x1080px). Max 5MB.") ?></p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="hero_button_text" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Testo pulsante") ?></label>
            <input type="text" id="hero_button_text" name="hero[button_text]" value="<?php echo HtmlHelper::e($hero['button_text'] ?? 'Esplora il Catalogo'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="hero_button_link" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Link pulsante") ?></label>
            <input type="text" id="hero_button_link" name="hero[button_link]" value="<?php echo HtmlHelper::e($hero['button_link'] ?? '/catalogo'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="<?= __('/catalogo') ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Features Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-th text-purple-500"></i>
            <?= __("Sezione Caratteristiche") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("Titolo della sezione e le 4 card con le caratteristiche") ?></p>
        </div>
        <div class="flex items-center gap-2">
          <label for="features_visible" class="text-sm font-medium text-gray-700"><?= __("Visibile") ?></label>
          <input type="checkbox" id="features_visible" name="features_title[is_active]" value="1"
                 <?php echo (!isset($featuresTitle['is_active']) || $featuresTitle['is_active'] == 1) ? 'checked' : ''; ?>
                 class="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
        </div>
      </div>
      <div class="p-6 space-y-6">
        <!-- Features Title -->
        <div class="pb-4 border-b border-gray-200">
          <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4"><?= __("Intestazione sezione") ?></h3>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label for="features_title" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Titolo sezione") ?></label>
              <input type="text" id="features_title" name="features_title[title]" value="<?php echo HtmlHelper::e($featuresTitle['title'] ?? 'Perché scegliere la nostra biblioteca'); ?>"
                     class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
            </div>
            <div>
              <label for="features_subtitle" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Sottotitolo sezione") ?></label>
              <input type="text" id="features_subtitle" name="features_title[subtitle]" value="<?php echo HtmlHelper::e($featuresTitle['subtitle'] ?? 'Tutto ciò che ti serve per gestire la tua passione per la lettura'); ?>"
                     class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
            </div>
          </div>
        </div>

        <!-- Features Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <?php
          $defaultFeatures = [
            1 => ['icon' => 'fas fa-book', 'title' => 'Catalogo Completo', 'desc' => 'Migliaia di libri organizzati e facilmente ricercabili'],
            2 => ['icon' => 'fas fa-clock', 'title' => 'Disponibilità 24/7', 'desc' => 'Prenota e gestisci i tuoi libri in qualsiasi momento'],
            3 => ['icon' => 'fas fa-heart', 'title' => 'Wishlist Personale', 'desc' => 'Crea la tua lista dei desideri e ricevi notifiche'],
            4 => ['icon' => 'fas fa-users', 'title' => 'Comunità Attiva', 'desc' => 'Condividi recensioni e scopri nuove letture']
          ];
          foreach ([1, 2, 3, 4] as $num): ?>
            <?php $feature = ${"feature{$num}"}; ?>
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
              <h3 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="<?php echo HtmlHelper::e($feature['content'] ?? 'fas fa-star'); ?> text-gray-600"></i>
                <?= __("Caratteristica") ?> <?php echo $num; ?>
              </h3>
              <div class="space-y-3">
                <div>
                  <label for="feature<?php echo $num; ?>_icon" class="block text-xs font-medium text-gray-700 mb-1"><?= __("Icona FontAwesome") ?></label>
                  <div class="flex gap-2">
                    <input type="text" id="feature<?php echo $num; ?>_icon" name="feature_<?php echo $num; ?>[content]"
                           value="<?php echo HtmlHelper::e($feature['content'] ?? $defaultFeatures[$num]['icon']); ?>"
                           class="block flex-1 rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                           placeholder="<?= __('fas fa-users') ?>">
                    <button type="button" onclick="openIconPicker('feature<?php echo $num; ?>_icon')"
                            class="inline-flex items-center gap-1 px-3 py-2 bg-gray-900 text-white text-xs rounded-lg hover:bg-gray-700 transition-colors">
                      <i class="fas fa-icons"></i>
                      <?= __("Scegli") ?>
                    </button>
                  </div>
                  <div class="mt-1 flex items-center gap-2">
                    <span class="text-xs text-gray-500"><?= __("Anteprima:") ?></span>
                    <i class="<?php echo HtmlHelper::e($feature['content'] ?? $defaultFeatures[$num]['icon']); ?> text-lg" id="preview_feature<?php echo $num; ?>_icon"></i>
                  </div>
                </div>
                <div>
                  <label for="feature<?php echo $num; ?>_title" class="block text-xs font-medium text-gray-700 mb-1"><?= __("Titolo") ?></label>
                  <input type="text" id="feature<?php echo $num; ?>_title" name="feature_<?php echo $num; ?>[title]"
                         value="<?php echo HtmlHelper::e($feature['title'] ?? $defaultFeatures[$num]['title']); ?>"
                         class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3">
                </div>
                <div>
                  <label for="feature<?php echo $num; ?>_subtitle" class="block text-xs font-medium text-gray-700 mb-1"><?= __("Descrizione") ?></label>
                  <textarea id="feature<?php echo $num; ?>_subtitle" name="feature_<?php echo $num; ?>[subtitle]" rows="2"
                            class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"><?php echo HtmlHelper::e($feature['subtitle'] ?? $defaultFeatures[$num]['desc']); ?></textarea>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Text Content Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-align-left text-indigo-500"></i>
            <?= __("Sezione Testo Libero") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("Contenuto testuale HTML con editor avanzato") ?></p>
        </div>
        <div class="flex items-center gap-2">
          <label for="text_content_visible" class="text-sm font-medium text-gray-700"><?= __("Visibile") ?></label>
          <input type="checkbox" id="text_content_visible" name="text_content[is_active]" value="1"
                 <?php
                 $textContent = $sections['text_content'] ?? null;
                 echo (!isset($textContent['is_active']) || $textContent['is_active'] == 1) ? 'checked' : '';
                 ?>
                 class="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
        </div>
      </div>
      <div class="p-6 space-y-4">
        <div>
          <label for="text_content_title" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Titolo (opzionale)") ?></label>
          <input type="text" id="text_content_title" name="text_content[title]"
                 value="<?php echo HtmlHelper::e($textContent['title'] ?? ''); ?>"
                 class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                 placeholder="<?= __('Lascia vuoto per nascondere il titolo') ?>">
        </div>
        <div>
          <label for="text_content_body" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Contenuto") ?></label>
          <textarea id="text_content_body" name="text_content[content]"><?php echo htmlspecialchars($textContent['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
          <p class="mt-2 text-xs text-gray-500">
            <i class="fas fa-info-circle"></i> <?= __("Usa l'editor per formattare il testo, aggiungere link, immagini e altro.") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Latest Books Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-book text-green-500"></i>
            <?= __("Sezione Ultimi Libri") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("Mostra gli ultimi libri aggiunti al catalogo") ?></p>
        </div>
        <div class="flex items-center gap-2">
          <label for="latest_books_visible" class="text-sm font-medium text-gray-700"><?= __("Visibile") ?></label>
          <input type="checkbox" id="latest_books_visible" name="latest_books_title[is_active]" value="1"
                 <?php echo (!isset($latestBooksTitle['is_active']) || $latestBooksTitle['is_active'] == 1) ? 'checked' : ''; ?>
                 class="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
        </div>
      </div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="latest_title" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Titolo sezione") ?></label>
            <input type="text" id="latest_title" name="latest_books_title[title]"
                   value="<?php echo HtmlHelper::e($latestBooksTitle['title'] ?? 'Ultimi Arrivi'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="latest_subtitle" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Sottotitolo") ?></label>
            <input type="text" id="latest_subtitle" name="latest_books_title[subtitle]"
                   value="<?php echo HtmlHelper::e($latestBooksTitle['subtitle'] ?? 'Scopri le ultime novità aggiunte al catalogo'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
      </div>
    </div>

    <!-- CTA Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-bullhorn text-red-500"></i>
            <?= __("Call to Action (CTA)") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("L'ultima sezione che invita all'azione") ?></p>
        </div>
        <div class="flex items-center gap-2">
          <label for="cta_visible" class="text-sm font-medium text-gray-700"><?= __("Visibile") ?></label>
          <input type="checkbox" id="cta_visible" name="cta[is_active]" value="1"
                 <?php echo (!isset($cta['is_active']) || $cta['is_active'] == 1) ? 'checked' : ''; ?>
                 class="h-5 w-5 rounded border-gray-300 text-gray-900 focus:ring-gray-500">
        </div>
      </div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="cta_title" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Titolo CTA") ?></label>
            <input type="text" id="cta_title" name="cta[title]" value="<?php echo HtmlHelper::e($cta['title'] ?? 'Pronto a iniziare?'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="cta_subtitle" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Sottotitolo CTA") ?></label>
            <input type="text" id="cta_subtitle" name="cta[subtitle]" value="<?php echo HtmlHelper::e($cta['subtitle'] ?? 'Registrati ora e inizia a esplorare il nostro catalogo'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="cta_button_text" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Testo pulsante") ?></label>
            <input type="text" id="cta_button_text" name="cta[button_text]" value="<?php echo HtmlHelper::e($cta['button_text'] ?? 'Registrati Ora'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="cta_button_link" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Link pulsante") ?></label>
            <input type="text" id="cta_button_link" name="cta[button_link]" value="<?php echo HtmlHelper::e($cta['button_link'] ?? '/registrati'); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
      </div>
    </div>

    <!-- SEO & Social Media Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-search text-blue-600"></i>
          <?= __("Ottimizzazione SEO e Social Media") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1">
          <?= __("Personalizza i meta tag per i motori di ricerca e i social media. Se lasciati vuoti, verranno utilizzati i valori predefiniti della sezione Hero.") ?>
        </p>
      </div>
      <div class="p-6">
        <!-- Accordion Container -->
        <div class="space-y-3">

          <!-- Basic SEO Accordion Item -->
          <div class="border border-gray-200 rounded-lg overflow-hidden">
            <button type="button" class="w-full px-4 py-3 flex items-center justify-between bg-gray-50 hover:bg-gray-100 transition-colors"
                    onclick="toggleAccordion('seo-basic')">
              <span class="font-medium text-gray-900 flex items-center gap-2">
                <i class="fas fa-globe text-blue-600"></i>
                <?= __("SEO Base (Meta Tags)") ?>
              </span>
              <i class="fas fa-chevron-down transition-transform" id="seo-basic-icon"></i>
            </button>
            <div id="seo-basic-content" class="hidden p-4 space-y-4 bg-white">
              <div>
                <label for="hero_seo_title" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Titolo SEO") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - max 60 caratteri)") ?></span>
                </label>
                <input type="text" id="hero_seo_title" name="hero[seo_title]" maxlength="255"
                       value="<?php echo HtmlHelper::e($hero['seo_title'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. Biblioteca Digitale - Migliaia di libri da esplorare') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Apparirà nei risultati di ricerca Google. Se vuoto, usa il titolo hero o il nome dell'app.") ?>
                </p>
              </div>

              <div>
                <label for="hero_seo_description" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Descrizione SEO") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - max 160 caratteri)") ?></span>
                </label>
                <textarea id="hero_seo_description" name="hero[seo_description]" rows="3" maxlength="500"
                          class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                          placeholder="<?= __('Es. Scopri il nostro catalogo digitale con migliaia di libri disponibili per il prestito. Registrati gratuitamente e inizia a leggere oggi stesso.') ?>"><?php echo HtmlHelper::e($hero['seo_description'] ?? ''); ?></textarea>
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Apparirà sotto il titolo nei risultati di ricerca. Se vuoto, usa il sottotitolo hero o una descrizione generica.") ?>
                </p>
              </div>

              <div>
                <label for="hero_seo_keywords" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Parole Chiave SEO") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - separate da virgola)") ?></span>
                </label>
                <input type="text" id="hero_seo_keywords" name="hero[seo_keywords]" maxlength="500"
                       value="<?php echo HtmlHelper::e($hero['seo_keywords'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. biblioteca digitale, prestito libri, catalogo online, libri gratis') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Parole chiave per i motori di ricerca (impatto SEO limitato). Separate da virgola.") ?>
                </p>
              </div>
            </div>
          </div>

          <!-- Open Graph Accordion Item -->
          <div class="border border-gray-200 rounded-lg overflow-hidden">
            <button type="button" class="w-full px-4 py-3 flex items-center justify-between bg-gray-50 hover:bg-gray-100 transition-colors"
                    onclick="toggleAccordion('seo-og')">
              <span class="font-medium text-gray-900 flex items-center gap-2">
                <i class="fab fa-facebook text-blue-600"></i>
                <?= __("Open Graph (Facebook, LinkedIn)") ?>
              </span>
              <i class="fas fa-chevron-down transition-transform" id="seo-og-icon"></i>
            </button>
            <div id="seo-og-content" class="hidden p-4 space-y-4 bg-white">
              <div>
                <label for="hero_og_title" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Titolo Open Graph") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale)") ?></span>
                </label>
                <input type="text" id="hero_og_title" name="hero[og_title]" maxlength="255"
                       value="<?php echo HtmlHelper::e($hero['og_title'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. La Tua Biblioteca Digitale') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Titolo mostrato quando condividi su Facebook/LinkedIn. Se vuoto, usa il titolo SEO o hero.") ?>
                </p>
              </div>

              <div>
                <label for="hero_og_description" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Descrizione Open Graph") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale)") ?></span>
                </label>
                <textarea id="hero_og_description" name="hero[og_description]" rows="3" maxlength="500"
                          class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                          placeholder="<?= __('Es. Esplora migliaia di libri, prenota online e gestisci i tuoi prestiti.') ?>"><?php echo HtmlHelper::e($hero['og_description'] ?? ''); ?></textarea>
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Descrizione per anteprima social. Se vuoto, usa la descrizione SEO.") ?>
                </p>
              </div>

              <div>
                <label for="hero_og_image" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Immagine Open Graph") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - URL completo)") ?></span>
                </label>
                <input type="text" id="hero_og_image" name="hero[og_image]" maxlength="500"
                       value="<?php echo HtmlHelper::e($hero['og_image'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. https://tuosito.com/uploads/og-image.jpg') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Immagine mostrata quando condividi su social. Dimensioni consigliate: 1200x630px (rapporto 1.91:1). Se vuoto, usa l'immagine hero di sfondo.") ?>
                </p>
              </div>

              <div>
                <label for="hero_og_url" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("URL Canonico") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale)") ?></span>
                </label>
                <input type="url" id="hero_og_url" name="hero[og_url]" maxlength="500"
                       value="<?php echo HtmlHelper::e($hero['og_url'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. https://tuosito.com') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("URL principale del sito. Se vuoto, usa l'URL corrente.") ?>
                </p>
              </div>

              <div>
                <label for="hero_og_type" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Tipo Contenuto") ?>
                </label>
                <select id="hero_og_type" name="hero[og_type]"
                        class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                  <option value="website" <?php echo (($hero['og_type'] ?? 'website') === 'website') ? 'selected' : ''; ?>>
                    <?= __("Website (Sito Web)") ?>
                  </option>
                  <option value="article" <?php echo (($hero['og_type'] ?? '') === 'article') ? 'selected' : ''; ?>>
                    <?= __("Article (Articolo/Blog)") ?>
                  </option>
                  <option value="profile" <?php echo (($hero['og_type'] ?? '') === 'profile') ? 'selected' : ''; ?>>
                    <?= __("Profile (Profilo)") ?>
                  </option>
                </select>
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Tipo di contenuto per Open Graph. Scegli 'Website' per la homepage.") ?>
                </p>
              </div>
            </div>
          </div>

          <!-- Twitter Card Accordion Item -->
          <div class="border border-gray-200 rounded-lg overflow-hidden">
            <button type="button" class="w-full px-4 py-3 flex items-center justify-between bg-gray-50 hover:bg-gray-100 transition-colors"
                    onclick="toggleAccordion('seo-twitter')">
              <span class="font-medium text-gray-900 flex items-center gap-2">
                <i class="fab fa-twitter text-blue-400"></i>
                <?= __("Twitter Cards (X)") ?>
              </span>
              <i class="fas fa-chevron-down transition-transform" id="seo-twitter-icon"></i>
            </button>
            <div id="seo-twitter-content" class="hidden p-4 space-y-4 bg-white">
              <div>
                <label for="hero_twitter_card" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Tipo Twitter Card") ?>
                </label>
                <select id="hero_twitter_card" name="hero[twitter_card]"
                        class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                  <option value="summary_large_image" <?php echo (($hero['twitter_card'] ?? 'summary_large_image') === 'summary_large_image') ? 'selected' : ''; ?>>
                    <?= __("Summary Large Image (Immagine Grande)") ?>
                  </option>
                  <option value="summary" <?php echo (($hero['twitter_card'] ?? '') === 'summary') ? 'selected' : ''; ?>>
                    <?= __("Summary (Immagine Piccola)") ?>
                  </option>
                </select>
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Consigliato: Summary Large Image per homepage.") ?>
                </p>
              </div>

              <div>
                <label for="hero_twitter_title" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Titolo Twitter") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - max 70 caratteri)") ?></span>
                </label>
                <input type="text" id="hero_twitter_title" name="hero[twitter_title]" maxlength="255"
                       value="<?php echo HtmlHelper::e($hero['twitter_title'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. La Tua Biblioteca Digitale') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Titolo per Twitter/X. Se vuoto, usa il titolo Open Graph.") ?>
                </p>
              </div>

              <div>
                <label for="hero_twitter_description" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Descrizione Twitter") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - max 200 caratteri)") ?></span>
                </label>
                <textarea id="hero_twitter_description" name="hero[twitter_description]" rows="3" maxlength="500"
                          class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                          placeholder="<?= __('Es. Esplora migliaia di libri, prenota online e gestisci i tuoi prestiti.') ?>"><?php echo HtmlHelper::e($hero['twitter_description'] ?? ''); ?></textarea>
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Descrizione per Twitter/X. Se vuoto, usa la descrizione Open Graph.") ?>
                </p>
              </div>

              <div>
                <label for="hero_twitter_image" class="block text-sm font-medium text-gray-700 mb-2">
                  <?= __("Immagine Twitter") ?>
                  <span class="text-xs text-gray-500 font-normal"><?= __("(opzionale - URL completo)") ?></span>
                </label>
                <input type="text" id="hero_twitter_image" name="hero[twitter_image]" maxlength="500"
                       value="<?php echo HtmlHelper::e($hero['twitter_image'] ?? ''); ?>"
                       class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                       placeholder="<?= __('Es. https://tuosito.com/uploads/twitter-image.jpg') ?>">
                <p class="mt-1 text-xs text-gray-500">
                  <?= __("Immagine per Twitter/X. Dimensioni consigliate: 1200x675px o 1200x1200px. Se vuoto, usa l'immagine Open Graph.") ?>
                </p>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Submit Button -->
    <div class="flex justify-end gap-3">
      <a href="/admin/settings?tab=cms" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-semibold transition-colors">
        <i class="fas fa-times"></i>
        <?= __("Annulla") ?>
      </a>
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva modifiche Homepage") ?>
      </button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    if (typeof Uppy === 'undefined') {
        console.error('Uppy is not loaded! Check vendor.bundle.js');
        // Fallback to regular file input
        document.getElementById('hero-background-input').style.display = 'block';
        return;
    }

    try {
        const uppyHero = new Uppy({
            restrictions: {
                maxFileSize: 5 * 1024 * 1024, // 5MB
                maxNumberOfFiles: 1,
                allowedFileTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
            },
            autoProceed: false
        });

        uppyHero.use(UppyDragDrop, {
            target: '#uppy-hero-upload',
            note: '<?= addslashes(__("Immagini JPG, PNG o WebP (max 5MB)")) ?>',
            locale: {
                strings: {
                    dropPasteFiles: '<?= addslashes(__("Trascina qui l\'immagine di sfondo o %{browse}")) ?>',
                    browse: '<?= addslashes(__("seleziona file")) ?>'
                }
            }
        });

        uppyHero.use(UppyProgressBar, {
            target: '#uppy-hero-progress',
            hideAfterFinish: false
        });

        // Handle file added
        uppyHero.on('file-added', (file) => {

            // Set the file to the hidden input for form submission
            const fileInput = document.getElementById('hero-background-input');
            const dataTransfer = new DataTransfer();

            // Create a File object from Uppy file data
            fetch(file.data instanceof File ? URL.createObjectURL(file.data) : file.preview)
                .then(res => res.blob())
                .then(blob => {
                    const newFile = new File([blob], file.name, { type: file.type });
                    dataTransfer.items.add(newFile);
                    fileInput.files = dataTransfer.files;
                })
                .catch(err => {
                    console.error('Error converting file:', err);
                    // Fallback: if file.data is already a File object
                    if (file.data instanceof File) {
                        dataTransfer.items.add(file.data);
                        fileInput.files = dataTransfer.files;
                    }
                });
        });

        // Handle file removed
        uppyHero.on('file-removed', (file) => {
            document.getElementById('hero-background-input').value = '';
        });

        uppyHero.on('restriction-failed', (file, error) => {
            console.error('Upload restriction failed:', error);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__('Errore Upload')) ?>,
                    text: error.message
                });
            } else {
                alert(<?= json_encode(__('Errore')) ?> + ': ' + error.message);
            }
        });

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        // Fallback to regular file input
        document.getElementById('hero-background-input').style.display = 'block';
    }

    // Icon Picker - Update preview when typing
    document.querySelectorAll('[id$="_icon"]').forEach(input => {
        if (input.id.startsWith('feature')) {
            input.addEventListener('input', function() {
                const previewId = 'preview_' + this.id;
                const preview = document.getElementById(previewId);
                if (preview) {
                    preview.className = (this.value || 'fas fa-star') + ' text-lg';
                }
            });
        }
    });
});
</script>

<!-- Font Awesome Icon Picker Modal -->
<div id="iconPickerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900"><?= __("Scegli Icona Font Awesome") ?></h3>
            <button onclick="closeIconPicker()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Search -->
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="relative">
                <input type="text" id="iconSearch" placeholder="<?= __('Cerca icona... (es. user, home, book)') ?>"
                       class="w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 pl-10 pr-4 py-2 text-sm"
                       oninput="filterIcons(this.value)">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </div>
            <p class="mt-2 text-xs text-gray-500"><?= __("Clicca su un'icona per selezionarla") ?></p>
        </div>

        <!-- Icons Grid -->
        <div id="iconsGrid" class="flex-1 overflow-y-auto p-6">
            <div id="iconsGridContainer" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(48px, 1fr)); gap: 12px;">
                <!-- Icons will be inserted here by JavaScript -->
            </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-gray-200 flex justify-between items-center">
            <p class="text-xs text-gray-500">
                <span id="iconCount">0</span> <?= __("icone disponibili") ?>
            </p>
            <button onclick="closeIconPicker()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
                <?= __("Chiudi") ?>
            </button>
        </div>
    </div>
</div>

<script>
// Font Awesome Solid Icons (most common)
const fontAwesomeIcons = [
    'star', 'heart', 'user', 'users', 'home', 'search', 'cog', 'bell', 'envelope', 'calendar',
    'clock', 'map-marker-alt', 'phone', 'shopping-cart', 'credit-card', 'download', 'upload', 'trash',
    'edit', 'save', 'check', 'times', 'plus', 'minus', 'info-circle', 'question-circle', 'exclamation-triangle',
    'book', 'bookmark', 'graduation-cap', 'award', 'trophy', 'medal', 'certificate', 'brain',
    'lightbulb', 'comments', 'comment', 'share', 'thumbs-up', 'thumbs-down', 'flag', 'fire',
    'bolt', 'cloud', 'sun', 'moon', 'star-half-alt', 'eye', 'eye-slash', 'lock', 'unlock',
    'key', 'shield-alt', 'user-shield', 'database', 'server', 'laptop', 'mobile-alt', 'tablet-alt',
    'desktop', 'keyboard', 'mouse', 'wifi', 'signal', 'rss', 'film', 'video', 'music',
    'headphones', 'microphone', 'camera', 'image', 'images', 'file', 'file-alt', 'folder',
    'folder-open', 'archive', 'box', 'cubes', 'cube', 'briefcase', 'clipboard', 'tasks',
    'chart-bar', 'chart-line', 'chart-pie', 'chart-area', 'percentage', 'dollar-sign', 'euro-sign',
    'pound-sign', 'yen-sign', 'wallet', 'coins', 'money-bill-wave', 'handshake', 'hands-helping',
    'hand-holding-heart', 'gift', 'birthday-cake', 'glass-cheers', 'wine-glass', 'coffee', 'utensils',
    'pizza-slice', 'hamburger', 'apple-alt', 'carrot', 'drumstick-bite', 'cookie', 'candy-cane',
    'car', 'bus', 'train', 'plane', 'rocket', 'bicycle', 'motorcycle', 'truck', 'ship',
    'subway', 'taxi', 'helicopter', 'running', 'walking', 'biking', 'swimmer', 'skiing',
    'basketball-ball', 'football-ball', 'baseball-ball', 'volleyball-ball', 'bowling-ball', 'table-tennis',
    'hockey-puck', 'golf-ball', 'futbol', 'dumbbell', 'heartbeat', 'hospital', 'stethoscope',
    'ambulance', 'medkit', 'pills', 'syringe', 'thermometer', 'band-aid', 'wheelchair', 'bed',
    'baby', 'child', 'male', 'female', 'venus', 'mars', 'transgender', 'restroom', 'toilet',
    'shower', 'bath', 'spa', 'hot-tub', 'tree', 'leaf', 'seedling', 'flower', 'sun-plant-wilt',
    'mountain', 'water', 'snowflake', 'icicles', 'rainbow', 'umbrella', 'cloud-rain', 'cloud-sun',
    'smog', 'wind', 'temperature-high', 'temperature-low', 'industry', 'building', 'store',
    'shopping-bag', 'tag', 'tags', 'barcode', 'qrcode', 'fingerprint', 'robot', 'magnet',
    'paint-brush', 'palette', 'drafting-compass', 'ruler', 'pencil-alt', 'pen', 'highlighter',
    'marker', 'eraser', 'stamp', 'print', 'fax', 'phone-alt', 'voicemail', 'at', 'hashtag',
    'link', 'unlink', 'anchor', 'paperclip', 'thumbtack', 'map', 'map-marked', 'map-pin',
    'directions', 'location-arrow', 'route', 'compass', 'globe', 'language', 'flag-usa',
    'broadcast-tower', 'satellite', 'satellite-dish', 'plug', 'power-off', 'battery-full',
    'battery-half', 'battery-empty', 'solar-panel', 'fan', 'blender', 'door-open', 'door-closed',
    'window-maximize', 'window-minimize', 'window-restore', 'window-close', 'expand', 'compress',
    'arrows-alt', 'angle-up', 'angle-down', 'angle-left', 'angle-right', 'arrow-up', 'arrow-down',
    'arrow-left', 'arrow-right', 'arrow-circle-up', 'arrow-circle-down', 'arrow-circle-left',
    'arrow-circle-right', 'chevron-up', 'chevron-down', 'chevron-left', 'chevron-right',
    'caret-up', 'caret-down', 'caret-left', 'caret-right', 'sort', 'sort-up', 'sort-down',
    'filter', 'sliders-h', 'ellipsis-h', 'ellipsis-v', 'grip-horizontal', 'grip-vertical',
    'align-left', 'align-center', 'align-right', 'align-justify', 'list', 'list-ul', 'list-ol',
    'indent', 'outdent', 'paragraph', 'heading', 'bold', 'italic', 'underline', 'strikethrough',
    'subscript', 'superscript', 'text-height', 'text-width', 'font', 'quote-left', 'quote-right',
    'code', 'terminal', 'bug', 'flask', 'vial', 'microscope', 'dna', 'atom', 'magnet'
];

let currentTargetInput = null;

function openIconPicker(inputId) {
    currentTargetInput = inputId;
    const modal = document.getElementById('iconPickerModal');
    modal.classList.remove('hidden');
    renderIcons(fontAwesomeIcons);
    document.getElementById('iconSearch').value = '';
    document.getElementById('iconSearch').focus();
}

function closeIconPicker() {
    document.getElementById('iconPickerModal').classList.add('hidden');
    currentTargetInput = null;
}

function renderIcons(icons) {
    const grid = document.getElementById('iconsGridContainer');
    grid.innerHTML = '';

    icons.forEach(icon => {
        const iconClass = 'fas fa-' + icon;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'flex items-center justify-center h-12 rounded-lg border-2 border-gray-200 hover:border-gray-900 hover:bg-gray-50 transition-all group';
        btn.style.aspectRatio = '1';
        btn.title = iconClass;
        btn.onclick = () => selectIcon(iconClass);

        const iconEl = document.createElement('i');
        iconEl.className = iconClass + ' text-xl text-gray-600 group-hover:text-gray-900';

        btn.appendChild(iconEl);
        grid.appendChild(btn);
    });

    document.getElementById('iconCount').textContent = icons.length;
}

function filterIcons(searchTerm) {
    const filtered = fontAwesomeIcons.filter(icon =>
        icon.toLowerCase().includes(searchTerm.toLowerCase())
    );
    renderIcons(filtered);
}

function selectIcon(iconClass) {
    if (!currentTargetInput) return;

    const input = document.getElementById(currentTargetInput);
    if (input) {
        input.value = iconClass;

        // Update preview
        const previewId = 'preview_' + currentTargetInput;
        const preview = document.getElementById(previewId);
        if (preview) {
            preview.className = iconClass + ' text-lg';
        }

        // Trigger input event for any other listeners
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    closeIconPicker();
}

// Close modal when clicking outside
document.getElementById('iconPickerModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeIconPicker();
    }
});

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('iconPickerModal');
    if (!modal.classList.contains('hidden') && e.key === 'Escape') {
        closeIconPicker();
    }
});

</script>

<!-- TinyMCE -->
<script src="/assets/tinymce/tinymce.min.js"></script>
<script>
// Accordion toggle function
function toggleAccordion(id) {
  const content = document.getElementById(id + '-content');
  const icon = document.getElementById(id + '-icon');

  if (content.classList.contains('hidden')) {
    content.classList.remove('hidden');
    icon.classList.add('rotate-180');
  } else {
    content.classList.add('hidden');
    icon.classList.remove('rotate-180');
  }
}

document.addEventListener('DOMContentLoaded', function() {
  if (window.tinymce) {
   tinymce.init({
     selector: '#text_content_body',
     license_key: 'gpl',
     height: 500,
     menubar: true,
     plugins: [
       'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
       'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
       'insertdatetime', 'media', 'table', 'help', 'wordcount'
     ],
     toolbar: 'undo redo | styles | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image | removeformat | help',
     style_formats: [
       { title: __('Paragraph'), format: 'p' },
       { title: __('Heading 1'), format: 'h1' },
       { title: __('Heading 2'), format: 'h2' },
       { title: __('Heading 3'), format: 'h3' },
       { title: __('Heading 4'), format: 'h4' },
       { title: __('Heading 5'), format: 'h5' },
       { title: __('Heading 6'), format: 'h6' }
     ],
     content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; line-height: 1.6; }',
     branding: false,
     promotion: false
   });
 }
});
</script>
