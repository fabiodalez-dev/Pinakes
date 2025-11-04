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
          Modifica Homepage
        </h1>
        <p class="mt-1 text-sm text-gray-600">
          Personalizza tutti i contenuti della homepage del sito
        </p>
      </div>
      <a href="/admin/settings?tab=cms" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors">
        <i class="fas fa-arrow-left"></i>
        Torna alle Impostazioni
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
          Sezione Hero (Testata principale)
        </h2>
        <p class="text-sm text-gray-600 mt-1">La sezione principale che appare per prima sulla home</p>
      </div>
      <div class="p-6 space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div>
            <label for="hero_title" class="block text-sm font-medium text-gray-700 mb-2">Titolo principale (H1)</label>
            <input type="text" id="hero_title" name="hero[title]" value="<?php echo HtmlHelper::e($hero['title'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="Es. La Tua Biblioteca Digitale" required>
          </div>
          <div>
            <label for="hero_subtitle" class="block text-sm font-medium text-gray-700 mb-2">Sottotitolo</label>
            <input type="text" id="hero_subtitle" name="hero[subtitle]" value="<?php echo HtmlHelper::e($hero['subtitle'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="Descrizione breve">
          </div>
        </div>

        <div class="space-y-3">
          <label class="block text-sm font-medium text-gray-700">Immagine di sfondo Hero</label>
          <?php if (!empty($hero['background_image'])): ?>
            <div class="relative rounded-2xl overflow-hidden h-48 bg-gray-100">
              <img src="<?php echo HtmlHelper::e($hero['background_image']); ?>" alt="Sfondo hero" class="w-full h-full object-cover">
              <div class="absolute inset-0 flex items-center justify-center" style="background: rgba(0, 0, 0, 0.4);">
                <span class="text-white text-sm font-medium">Immagine attuale</span>
              </div>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer">
              <input type="checkbox" name="hero[remove_background]" value="1" class="rounded border-gray-300">
              Rimuovi immagine di sfondo attuale
            </label>
          <?php endif; ?>
          <!-- Uppy Upload Area -->
          <div id="uppy-hero-upload" class="mb-4"></div>
          <div id="uppy-hero-progress" class="mb-4"></div>
          <!-- Fallback file input (hidden, used by Uppy) -->
          <input type="file" name="hero_background" accept="image/jpeg,image/jpg,image/png,image/webp"
                 style="display: none;" id="hero-background-input">
          <p class="text-xs text-gray-500">Consigliato JPG o PNG ad alta risoluzione (min 1920x1080px). Max 5MB.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="hero_button_text" class="block text-sm font-medium text-gray-700 mb-2">Testo pulsante</label>
            <input type="text" id="hero_button_text" name="hero[button_text]" value="<?php echo HtmlHelper::e($hero['button_text'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="hero_button_link" class="block text-sm font-medium text-gray-700 mb-2">Link pulsante</label>
            <input type="text" id="hero_button_link" name="hero[button_link]" value="<?php echo HtmlHelper::e($hero['button_link'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"
                   placeholder="/catalogo">
          </div>
        </div>
      </div>
    </div>

    <!-- Features Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-th text-purple-500"></i>
          Sezione Caratteristiche
        </h2>
        <p class="text-sm text-gray-600 mt-1">Titolo della sezione e le 4 card con le caratteristiche</p>
      </div>
      <div class="p-6 space-y-6">
        <!-- Features Title -->
        <div class="pb-4 border-b border-gray-200">
          <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Intestazione sezione</h3>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label for="features_title" class="block text-sm font-medium text-gray-700 mb-2">Titolo sezione</label>
              <input type="text" id="features_title" name="features_title[title]" value="<?php echo HtmlHelper::e($featuresTitle['title'] ?? ''); ?>"
                     class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
            </div>
            <div>
              <label for="features_subtitle" class="block text-sm font-medium text-gray-700 mb-2">Sottotitolo sezione</label>
              <input type="text" id="features_subtitle" name="features_title[subtitle]" value="<?php echo HtmlHelper::e($featuresTitle['subtitle'] ?? ''); ?>"
                     class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
            </div>
          </div>
        </div>

        <!-- Features Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <?php foreach ([1, 2, 3, 4] as $num): ?>
            <?php $feature = ${"feature{$num}"}; ?>
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
              <h3 class="text-sm font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="<?php echo HtmlHelper::e($feature['content'] ?? 'fas fa-star'); ?> text-gray-600"></i>
                Caratteristica <?php echo $num; ?>
              </h3>
              <div class="space-y-3">
                <div>
                  <label for="feature<?php echo $num; ?>_icon" class="block text-xs font-medium text-gray-700 mb-1">Icona FontAwesome</label>
                  <input type="text" id="feature<?php echo $num; ?>_icon" name="feature_<?php echo $num; ?>[content]"
                         value="<?php echo HtmlHelper::e($feature['content'] ?? ''); ?>"
                         class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"
                         placeholder="fas fa-users">
                </div>
                <div>
                  <label for="feature<?php echo $num; ?>_title" class="block text-xs font-medium text-gray-700 mb-1">Titolo</label>
                  <input type="text" id="feature<?php echo $num; ?>_title" name="feature_<?php echo $num; ?>[title]"
                         value="<?php echo HtmlHelper::e($feature['title'] ?? ''); ?>"
                         class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3">
                </div>
                <div>
                  <label for="feature<?php echo $num; ?>_subtitle" class="block text-xs font-medium text-gray-700 mb-1">Descrizione</label>
                  <textarea id="feature<?php echo $num; ?>_subtitle" name="feature_<?php echo $num; ?>[subtitle]" rows="2"
                            class="block w-full rounded-lg border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-2 px-3"><?php echo HtmlHelper::e($feature['subtitle'] ?? ''); ?></textarea>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Latest Books Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-book text-green-500"></i>
          Sezione Ultimi Libri
        </h2>
      </div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="latest_title" class="block text-sm font-medium text-gray-700 mb-2">Titolo sezione</label>
            <input type="text" id="latest_title" name="latest_books_title[title]"
                   value="<?php echo HtmlHelper::e($latestBooksTitle['title'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="latest_subtitle" class="block text-sm font-medium text-gray-700 mb-2">Sottotitolo</label>
            <input type="text" id="latest_subtitle" name="latest_books_title[subtitle]"
                   value="<?php echo HtmlHelper::e($latestBooksTitle['subtitle'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
      </div>
    </div>

    <!-- CTA Section -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-bullhorn text-red-500"></i>
          Call to Action (CTA)
        </h2>
        <p class="text-sm text-gray-600 mt-1">L'ultima sezione che invita all'azione</p>
      </div>
      <div class="p-6 space-y-4">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="cta_title" class="block text-sm font-medium text-gray-700 mb-2">Titolo CTA</label>
            <input type="text" id="cta_title" name="cta[title]" value="<?php echo HtmlHelper::e($cta['title'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="cta_subtitle" class="block text-sm font-medium text-gray-700 mb-2">Sottotitolo CTA</label>
            <input type="text" id="cta_subtitle" name="cta[subtitle]" value="<?php echo HtmlHelper::e($cta['subtitle'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div>
            <label for="cta_button_text" class="block text-sm font-medium text-gray-700 mb-2">Testo pulsante</label>
            <input type="text" id="cta_button_text" name="cta[button_text]" value="<?php echo HtmlHelper::e($cta['button_text'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
          <div>
            <label for="cta_button_link" class="block text-sm font-medium text-gray-700 mb-2">Link pulsante</label>
            <input type="text" id="cta_button_link" name="cta[button_link]" value="<?php echo HtmlHelper::e($cta['button_link'] ?? ''); ?>"
                   class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
          </div>
        </div>
      </div>
    </div>

    <!-- Submit Button -->
    <div class="flex justify-end gap-3">
      <a href="/admin/settings?tab=cms" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-semibold transition-colors">
        <i class="fas fa-times"></i>
        Annulla
      </a>
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
        <i class="fas fa-save"></i>
        Salva modifiche Homepage
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
            note: 'Immagini JPG, PNG o WebP (max 5MB)',
            locale: {
                strings: {
                    dropPasteFiles: 'Trascina qui l\'immagine di sfondo o %{browse}',
                    browse: 'seleziona file'
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
                    title: 'Errore Upload',
                    text: error.message
                });
            } else {
                alert('Errore: ' + error.message);
            }
        });

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        // Fallback to regular file input
        document.getElementById('hero-background-input').style.display = 'block';
    }
});
</script>
