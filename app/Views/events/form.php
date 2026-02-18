<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$isEdit = isset($event) && $event;
$pageTitle = $isEdit ? __("Modifica Evento") : __("Crea Nuovo Evento");
?>
<style>
/* Fix Flatpickr input heights for events form */
#event_date + input.form-control,
#event_time + input.form-control,
.flatpickr-date + input,
.flatpickr-time + input {
  height: 46px !important;
  border-radius: 0.75rem !important;
  border: 1px solid #d1d5db !important;
  padding: 0.75rem 1rem !important;
  font-size: 0.875rem !important;
  width: 100% !important;
  background-color: white !important;
}
.flatpickr-date + input:focus,
.flatpickr-time + input:focus {
  border-color: #a855f7 !important;
  outline: none !important;
  box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.1) !important;
}
</style>

<div class="max-w-5xl mx-auto py-6 px-4">
  <div class="mb-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-calendar-alt text-purple-600"></i>
          <?= $pageTitle ?>
        </h1>
        <p class="mt-1 text-sm text-gray-600">
          <?= $isEdit ? __("Modifica le informazioni dell'evento") : __("Inserisci le informazioni del nuovo evento") ?>
        </p>
      </div>
      <a href="<?= htmlspecialchars(url('/admin/cms/events'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors">
        <i class="fas fa-arrow-left"></i>
        <?= __("Torna agli Eventi") ?>
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
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo \App\Support\HtmlHelper::e($_SESSION['error_message']); ?>
      </div>
      <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
  </div>

  <form action="<?= htmlspecialchars($isEdit ? url('/admin/cms/events/update/' . (int)$event['id']) : url('/admin/cms/events'), ENT_QUOTES, 'UTF-8') ?>" method="post" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e(Csrf::ensureToken()); ?>">

    <!-- Main Event Information -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-info-circle text-purple-500"></i>
          <?= __("Informazioni Evento") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1"><?= __("Dettagli principali dell'evento") ?></p>
      </div>
      <div class="p-6 space-y-6">

        <!-- Title -->
        <div>
          <label for="event_title" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Titolo Evento") ?> <span class="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="event_title"
            name="title"
            value="<?= HtmlHelper::e($event['title'] ?? '') ?>"
            class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
            placeholder="<?= __('Es. Presentazione libro "Il Nome della Rosa"') ?>"
            required
          >
          <p class="mt-1 text-xs text-gray-500"><?= __("Il titolo verrà utilizzato anche per generare l'URL della pagina") ?></p>
        </div>

        <!-- Featured Image -->
        <div class="space-y-3">
          <label class="block text-sm font-medium text-gray-700"><?= __("Immagine in Evidenza") ?></label>
          <?php if ($isEdit && !empty($event['featured_image'])): ?>
            <div class="relative rounded-2xl overflow-hidden h-64 bg-gray-100">
              <?php $featuredSrc = url($event['featured_image']); ?>
              <img src="<?= htmlspecialchars($featuredSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= HtmlHelper::e($event['title']) ?>" class="w-full h-full object-cover">
              <div class="absolute inset-0 flex items-center justify-center" style="background: rgba(0, 0, 0, 0.4);">
                <span class="text-white text-sm font-medium"><?= __("Immagine attuale") ?></span>
              </div>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-red-600 cursor-pointer">
              <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300">
              <?= __("Rimuovi immagine attuale") ?>
            </label>
          <?php endif; ?>
          <!-- Uppy Upload Area -->
          <div id="uppy-event-upload" class="mb-4"></div>
          <div id="uppy-event-progress" class="mb-4"></div>
          <div id="event-image-preview" class="hidden rounded-2xl overflow-hidden border border-gray-200 bg-gray-50">
            <img src="" alt="<?= __("Anteprima immagine caricata") ?>" class="w-full h-60 object-cover" id="event-image-preview-img">
            <div class="px-4 py-2 text-xs text-gray-500" id="event-image-preview-text">
              <?= __("Anteprima immagine caricata") ?>
            </div>
          </div>
          <!-- Fallback file input (hidden, used by Uppy) -->
          <input type="file" name="featured_image" accept="image/jpeg,image/jpg,image/png,image/webp"
                 style="display: none;" id="event-image-input">
          <p class="text-xs text-gray-500"><?= __("Consigliato JPG, PNG o WebP (min 800x600px). Max 5MB.") ?></p>
        </div>

        <!-- Date and Time -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label for="event_date" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Data Evento") ?> <span class="text-red-500">*</span>
            </label>
            <input
              type="text"
              id="event_date"
              name="event_date"
              value="<?= HtmlHelper::e($event['event_date'] ?? '') ?>"
              class="flatpickr-date block w-full rounded-xl border border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4 h-[46px]"
              placeholder="<?= __('Seleziona data') ?>"
              required
            >
          </div>
          <div>
            <label for="event_time" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Ora Evento") ?>
            </label>
            <input
              type="text"
              id="event_time"
              name="event_time"
              value="<?= HtmlHelper::e($event['event_time'] ?? '') ?>"
              class="flatpickr-time block w-full rounded-xl border border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4 h-[46px]"
              placeholder="<?= __('Seleziona ora') ?>"
            >
          </div>
        </div>

        <!-- Content (TinyMCE) -->
        <div>
          <label for="event_content" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Descrizione Evento") ?>
          </label>
          <textarea
            id="event_content"
            name="content"
            rows="10"
            class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
          ><?= HtmlHelper::e($event['content'] ?? '') ?></textarea>
          <p class="mt-1 text-xs text-gray-500"><?= __("Descrizione completa dell'evento con possibilità di formattazione HTML") ?></p>
        </div>

        <!-- Active Status -->
        <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-xl">
          <input
            type="checkbox"
            id="is_active"
            name="is_active"
            value="1"
            <?= (!isset($event['is_active']) || $event['is_active'] == 1) ? 'checked' : '' ?>
            class="h-5 w-5 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
          >
          <label for="is_active" class="text-sm font-medium text-gray-700">
            <?= __("Evento visibile sul sito") ?>
          </label>
        </div>

      </div>
    </div>

    <!-- SEO Settings -->
    <div class="bg-white rounded-3xl shadow-xl border border-gray-200">
      <div class="border-b border-gray-200 px-6 py-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-search text-blue-500"></i>
          <?= __("Impostazioni SEO") ?>
        </h2>
        <p class="text-sm text-gray-600 mt-1"><?= __("Ottimizza l'evento per i motori di ricerca e i social media") ?></p>
      </div>
      <div class="p-6 space-y-6">

        <!-- Basic SEO -->
        <div class="space-y-4">
          <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?= __("SEO Base") ?></h3>

          <div>
            <label for="seo_title" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Titolo SEO") ?>
            </label>
            <input
              type="text"
              id="seo_title"
              name="seo_title"
              value="<?= HtmlHelper::e($event['seo_title'] ?? '') ?>"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
              placeholder="<?= __('Se vuoto, verrà usato il titolo dell\'evento') ?>"
              maxlength="60"
            >
            <p class="mt-1 text-xs text-gray-500"><?= __("Consigliato: 50-60 caratteri") ?></p>
          </div>

          <div>
            <label for="seo_description" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Descrizione SEO") ?>
            </label>
            <textarea
              id="seo_description"
              name="seo_description"
              rows="3"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
              placeholder="<?= __('Breve descrizione per i motori di ricerca') ?>"
              maxlength="160"
            ><?= HtmlHelper::e($event['seo_description'] ?? '') ?></textarea>
            <p class="mt-1 text-xs text-gray-500"><?= __("Consigliato: 150-160 caratteri") ?></p>
          </div>

          <div>
            <label for="seo_keywords" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Parole chiave SEO") ?>
            </label>
            <input
              type="text"
              id="seo_keywords"
              name="seo_keywords"
              value="<?= HtmlHelper::e($event['seo_keywords'] ?? '') ?>"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
              placeholder="<?= __('eventi, biblioteca, cultura') ?>"
            >
            <p class="mt-1 text-xs text-gray-500"><?= __("Separale con virgole") ?></p>
          </div>
        </div>

        <!-- Open Graph -->
        <div class="space-y-4 pt-4 border-t border-gray-200">
          <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?= __("Open Graph (Facebook)") ?></h3>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label for="og_title" class="block text-sm font-medium text-gray-700 mb-2">
                <?= __("Titolo OG") ?>
              </label>
              <input
                type="text"
                id="og_title"
                name="og_title"
                value="<?= HtmlHelper::e($event['og_title'] ?? '') ?>"
                class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
              >
            </div>
            <div>
              <label for="og_type" class="block text-sm font-medium text-gray-700 mb-2">
                <?= __("Tipo OG") ?>
              </label>
              <select
                id="og_type"
                name="og_type"
                class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
              >
                <option value="article" <?= ($event['og_type'] ?? 'article') === 'article' ? 'selected' : '' ?>>Article</option>
                <option value="website" <?= ($event['og_type'] ?? '') === 'website' ? 'selected' : '' ?>>Website</option>
                <option value="event" <?= ($event['og_type'] ?? '') === 'event' ? 'selected' : '' ?>>Event</option>
              </select>
            </div>
          </div>

          <div>
            <label for="og_description" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Descrizione OG") ?>
            </label>
            <textarea
              id="og_description"
              name="og_description"
              rows="2"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
            ><?= HtmlHelper::e($event['og_description'] ?? '') ?></textarea>
          </div>

          <div>
            <label for="og_url" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("URL OG") ?>
            </label>
            <input
              type="text"
              id="og_url"
              name="og_url"
              value="<?= HtmlHelper::e($event['og_url'] ?? '') ?>"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
              placeholder="<?= __('URL completo della pagina (auto-generato se vuoto)') ?>"
            >
          </div>
        </div>

        <!-- Twitter Card -->
        <div class="space-y-4 pt-4 border-t border-gray-200">
          <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide"><?= __("Twitter Card") ?></h3>

          <div>
            <label for="twitter_card" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Tipo Card") ?>
            </label>
            <select
              id="twitter_card"
              name="twitter_card"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
            >
              <option value="summary_large_image" <?= ($event['twitter_card'] ?? 'summary_large_image') === 'summary_large_image' ? 'selected' : '' ?>>Summary Large Image</option>
              <option value="summary" <?= ($event['twitter_card'] ?? '') === 'summary' ? 'selected' : '' ?>>Summary</option>
            </select>
          </div>

          <div>
            <label for="twitter_title" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Titolo Twitter") ?>
            </label>
            <input
              type="text"
              id="twitter_title"
              name="twitter_title"
              value="<?= HtmlHelper::e($event['twitter_title'] ?? '') ?>"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4"
            >
          </div>

          <div>
            <label for="twitter_description" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __("Descrizione Twitter") ?>
            </label>
            <textarea
              id="twitter_description"
              name="twitter_description"
              rows="2"
              class="block w-full rounded-xl border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
            ><?= HtmlHelper::e($event['twitter_description'] ?? '') ?></textarea>
          </div>
        </div>

      </div>
    </div>

    <!-- Submit Button -->
    <div class="flex items-center justify-between gap-4">
      <a href="<?= htmlspecialchars(url('/admin/cms/events'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-6 py-3 bg-white border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition-colors font-semibold">
        <i class="fas fa-times"></i>
        <?= __("Annulla") ?>
      </a>
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-gray-900 text-white rounded-xl hover:bg-gray-700 transition-colors font-semibold shadow-sm">
        <i class="fas fa-save"></i>
        <?= $isEdit ? __("Aggiorna Evento") : __("Crea Evento") ?>
      </button>
    </div>

  </form>
</div>

<!-- Initialize Uppy, TinyMCE, and Flatpickr -->
<script>
document.addEventListener('DOMContentLoaded', function() {

  // Initialize Flatpickr for date
  if (typeof flatpickr !== 'undefined') {
    flatpickr('.flatpickr-date', {
      dateFormat: 'Y-m-d',
      locale: '<?= \App\Support\I18n::getLocale() === 'it_IT' ? 'it' : 'default' ?>',
      altInput: true,
      altFormat: 'd/m/Y',
      altInputClass: 'block w-full rounded-xl border border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm py-3 px-4 h-[46px] bg-white'
    });

    // Initialize Flatpickr for time
    flatpickr('.flatpickr-time', {
      enableTime: true,
      noCalendar: true,
      dateFormat: 'H:i',
      time_24hr: true,
      locale: '<?= \App\Support\I18n::getLocale() === 'it_IT' ? 'it' : 'default' ?>'
    });
  }

  // Initialize TinyMCE
  if (window.tinymce) {
    tinymce.init({
      selector: '#event_content',
      base_url: <?= json_encode(assetUrl("tinymce")) ?>,
      suffix: '.min',
      model: 'dom',
      license_key: 'gpl',
      height: 520,
      menubar: true,
      toolbar_mode: 'wrap',
      toolbar_sticky: true,
      plugins: [
        'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
        'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
        'insertdatetime', 'media', 'table', 'help', 'wordcount'
      ],
      toolbar: 'undo redo | blocks | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | removeformat | code fullscreen help',
      style_formats: [
        { title: <?= json_encode(__('Paragrafo'), JSON_HEX_TAG) ?>, format: 'p' },
        { title: <?= json_encode(__('Titolo 1'), JSON_HEX_TAG) ?>, format: 'h1' },
        { title: <?= json_encode(__('Titolo 2'), JSON_HEX_TAG) ?>, format: 'h2' },
        { title: <?= json_encode(__('Titolo 3'), JSON_HEX_TAG) ?>, format: 'h3' }
      ],
      content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; line-height: 1.6; }',
      branding: false,
      promotion: false
    });
  }

  // Initialize Uppy for event image
  try {
    if (typeof Uppy !== 'undefined' && typeof UppyDragDrop !== 'undefined' && typeof UppyProgressBar !== 'undefined') {
      const fileInput = document.getElementById('event-image-input');
      const previewWrapper = document.getElementById('event-image-preview');
      const previewImage = document.getElementById('event-image-preview-img');
      const previewText = document.getElementById('event-image-preview-text');

      const uppyEvent = new Uppy({
        restrictions: {
          maxFileSize: 5 * 1024 * 1024, // 5MB
          maxNumberOfFiles: 1,
          allowedFileTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
        },
        autoProceed: false
      });

      uppyEvent.use(UppyDragDrop, {
        target: '#uppy-event-upload',
        note: <?= json_encode(__("Immagini JPG, PNG o WebP (max 5MB)"), JSON_HEX_TAG) ?>,
        locale: {
          strings: {
            dropPasteFiles: <?= json_encode(__("Trascina qui l'immagine o %{browse}"), JSON_HEX_TAG) ?>,
            browse: <?= json_encode(__("seleziona file"), JSON_HEX_TAG) ?>
          }
        }
      });

      uppyEvent.use(UppyProgressBar, {
        target: '#uppy-event-progress',
        hideAfterFinish: false
      });

      const updatePreview = (fileObj) => {
        if (!previewWrapper || !previewImage) {
          return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
          previewImage.src = e.target?.result || '';
          previewWrapper.classList.remove('hidden');
          if (previewText) {
            previewText.textContent = <?= json_encode(__("Anteprima immagine caricata"), JSON_HEX_TAG) ?>;
          }
        };
        reader.readAsDataURL(fileObj);
      };

      // Handle file added
      uppyEvent.on('file-added', (file) => {
        const dataTransfer = new DataTransfer();
        let normalizedFile = null;

        if (file.data instanceof File) {
          normalizedFile = file.data;
        } else if (file.data instanceof Blob) {
          normalizedFile = new File([file.data], file.name, { type: file.type });
        } else if (file.preview) {
          fetch(file.preview)
            .then(res => res.blob())
            .then(blob => {
              const fetchedFile = new File([blob], file.name, { type: file.type });
              dataTransfer.items.add(fetchedFile);
              fileInput.files = dataTransfer.files;
              updatePreview(fetchedFile);
            })
            .catch(err => console.error('Error loading preview blob:', err));
          return;
        }

        if (normalizedFile) {
          dataTransfer.items.add(normalizedFile);
          fileInput.files = dataTransfer.files;
          updatePreview(normalizedFile);
        }
      });

      // Handle file removed
      uppyEvent.on('file-removed', (file) => {
        document.getElementById('event-image-input').value = '';
        if (previewWrapper) {
          previewWrapper.classList.add('hidden');
        }
      });

      uppyEvent.on('restriction-failed', (file, error) => {
        console.error('Upload restriction failed:', error);
        alert(<?= json_encode(__("Errore"), JSON_HEX_TAG) ?> + ': ' + error.message);
      });
    }
  } catch (error) {
    console.error('Error initializing Uppy:', error);
  }
});
</script>
