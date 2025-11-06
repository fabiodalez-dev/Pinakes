<div class="max-w-5xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-file-alt text-blue-600"></i>
          <?= htmlspecialchars($title) ?>
        </h1>
        <a href="/admin/settings" class="btn-secondary">
          <i class="fas fa-arrow-left mr-2"></i><?= __("Torna alle Impostazioni") ?>
        </a>
      </div>

      <?php if(isset($_GET['saved'])): ?>
        <div class="mt-3 p-3 bg-green-50 text-green-800 border border-green-200 rounded" role="alert">
          <i class="fas fa-check-circle mr-2"></i><?= __("Pagina aggiornata con successo.") ?>
        </div>
      <?php endif; ?>
      <?php if(isset($_GET['error']) && $_GET['error']==='csrf'): ?>
        <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
          <i class="fas fa-exclamation-triangle mr-2"></i><?= __("CSRF non valido.") ?>
        </div>
      <?php elseif(isset($_GET['error'])): ?>
        <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
          <i class="fas fa-exclamation-triangle mr-2"></i><?= __("Errore durante il salvataggio.") ?>
        </div>
      <?php endif; ?>
    </div>

    <form method="post" action="/admin/cms/<?= htmlspecialchars($pageData['slug']) ?>/update" class="space-y-6">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <!-- Titolo -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-heading text-blue-600"></i>
            <?= __("Titolo Pagina") ?>
          </h2>
        </div>
        <div class="card-body">
          <input
            type="text"
            name="title"
            class="form-input w-full text-lg"
            value="<?= htmlspecialchars($pageData['title']) ?>"
            required
            placeholder="<?= __("Inserisci il titolo") ?>">
        </div>
      </div>

      <!-- Immagine -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-image text-blue-600"></i>
            <?= __("Immagine") ?>
          </h2>
        </div>
        <div class="card-body space-y-4">
          <div id="image-preview" class="<?= !empty($pageData['image']) ? '' : 'hidden' ?>">
            <img
              id="preview-img"
              src="<?= htmlspecialchars($pageData['image'] ?? '') ?>"
              alt="Preview"
              class="max-w-full h-auto rounded border"
              style="max-height: 300px;">
            <button
              type="button"
              id="remove-image-btn"
              class="mt-2 text-red-600 hover:text-red-800 text-sm">
              <i class="fas fa-trash mr-1"></i><?= __("Rimuovi immagine") ?>
            </button>
          </div>

          <div id="uppy-container"></div>

          <input
            type="hidden"
            name="image"
            id="image-url"
            value="<?= htmlspecialchars($pageData['image'] ?? '') ?>">

          <p class="text-sm text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Formati supportati: JPG, PNG, GIF, WebP. Dimensione massima: 5MB") ?>
          </p>
        </div>
      </div>

      <!-- Contenuto -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-align-left text-blue-600"></i>
            <?= __("Contenuto") ?>
          </h2>
        </div>
        <div class="card-body">
          <textarea
            name="content"
            id="tinymce-editor"
            class="w-full"><?= htmlspecialchars($pageData['content']) ?></textarea>
        </div>
      </div>

      <!-- Meta Description -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-search text-blue-600"></i>
            <?= __("SEO - Meta Description") ?>
          </h2>
        </div>
        <div class="card-body">
          <textarea
            name="meta_description"
            class="form-input w-full"
            rows="3"
            placeholder="<?= __("Breve descrizione per i motori di ricerca (max 160 caratteri)") ?>"><?= htmlspecialchars($pageData['meta_description'] ?? '') ?></textarea>
          <p class="text-sm text-gray-500 mt-1">
            <?= __("Questa descrizione apparirà nei risultati di ricerca di Google") ?>
          </p>
        </div>
      </div>

      <!-- Stato -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-toggle-on text-blue-600"></i>
            <?= __("Stato") ?>
          </h2>
        </div>
        <div class="card-body">
          <label class="inline-flex items-center gap-2">
            <input
              type="checkbox"
              name="is_active"
              value="1"
              <?= $pageData['is_active'] ? 'checked' : '' ?>>
            <span><?= __("Pagina attiva (visibile sul sito)") ?></span>
          </label>
        </div>
      </div>

      <!-- Pulsanti -->
      <div class="flex items-center justify-between">
        <a href="/chi-siamo" target="_blank" class="btn-secondary">
          <i class="fas fa-eye mr-2"></i><?= __("Anteprima") ?>
        </a>
        <button type="submit" class="btn-primary">
          <i class="fas fa-save mr-2"></i><?= __("Salva Modifiche") ?>
        </button>
      </div>
    </form>
</div>

<!-- TinyMCE -->
<script src="/assets/tinymce/tinymce.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  if (window.tinymce) {
   tinymce.init({
     selector: '#tinymce-editor',
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
       { title: '<?= addslashes(__("Paragraph")) ?>', format: 'p' },
       { title: '<?= addslashes(__("Heading 1")) ?>', format: 'h1' },
       { title: '<?= addslashes(__("Heading 2")) ?>', format: 'h2' },
       { title: '<?= addslashes(__("Heading 3")) ?>', format: 'h3' },
       { title: '<?= addslashes(__("Heading 4")) ?>', format: 'h4' },
       { title: '<?= addslashes(__("Heading 5")) ?>', format: 'h5' },
       { title: '<?= addslashes(__("Heading 6")) ?>', format: 'h6' }
     ],
     content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 16px; line-height: 1.6; }',
     branding: false,
     promotion: false
   });
 }
});
</script>

<!-- Uppy - CSS bundled in vendor.css, JS bundled in vendor.bundle.js -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Use globally available Uppy from vendor.bundle.js
  const { Uppy } = window;
  const { Dashboard } = window.UppyDashboard;
  const { XHRUpload } = window.UppyXHRUpload;

  const uppy = new Uppy({
    restrictions: {
      maxFileSize: 5 * 1024 * 1024, // 5MB
      allowedFileTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp']
    },
    autoProceed: false
  })
  .use(Dashboard, {
    inline: true,
    target: '#uppy-container',
    height: 300,
    proudlyDisplayPoweredByUppy: false,
    locale: {
      strings: {
        dropPasteImport: '<?= addslashes(__("Trascina qui l'immagine, %{browse} o importa da")) ?>',
        browse: '<?= addslashes(__("sfoglia")) ?>',
        uploadXFiles: {
          0: '<?= addslashes(__("Carica %{smart_count} file")) ?>',
          1: '<?= addslashes(__("Carica %{smart_count} file")) ?>'
        }
      }
    }
  })
  .use(XHRUpload, {
    endpoint: '/admin/cms/upload',
    fieldName: 'file',
    headers: {
      'X-CSRF-Token': document.querySelector('input[name="csrf_token"]').value
    }
  });

  uppy.on('upload-success', (file, response) => {
    const imageUrl = response.body.url;
    document.getElementById('image-url').value = imageUrl;
    document.getElementById('preview-img').src = imageUrl;
    document.getElementById('image-preview').classList.remove('hidden');
    uppy.clear();
  });

  // Remove image button
  const removeBtn = document.getElementById('remove-image-btn');
  if (removeBtn) {
    removeBtn.addEventListener('click', function() {
      document.getElementById('image-url').value = '';
      document.getElementById('preview-img').src = '';
      document.getElementById('image-preview').classList.add('hidden');
    });
  }
});
</script>

<style>
.uppy-Dashboard-inner {
  border: 2px dashed #d1d5db;
  border-radius: 8px;
}

.uppy-Dashboard-AddFiles {
  border: none;
}
</style>
