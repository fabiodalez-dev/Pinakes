<?php use App\Support\Csrf; $csrf = Csrf::ensureToken(); ?>
<?php
/**
 * @var array $data { autore: array }
 */
$autore = $data['autore'];
$title = __("Modifica Autore:") . " " . ($autore['nome'] ?? 'N/D');
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/authors'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-user-edit mr-1"></i>Autori
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium"><?= __("Modifica") ?></li>
      </ol>
    </nav>
    <!-- Header -->
    <div class="mb-8 fade-in">
      <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
        <i class="fas fa-user-edit text-blue-600"></i>
        <?= __("Modifica Autore") ?>
      </h1>
      <p class="text-gray-600">Aggiorna i dettagli dell'autore: <strong><?php echo App\Support\HtmlHelper::e($autore['nome'] ?? 'N/A'); ?></strong></p>
    </div>

    <!-- Main Form -->
    <form id="edit-author-form" method="post" enctype="multipart/form-data" action="<?= htmlspecialchars(url('/admin/authors/update/' . (int)$autore['id']), ENT_QUOTES, 'UTF-8') ?>" class="space-y-8 slide-in-up">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      
      <!-- Basic Information Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-user text-primary"></i>
            <?= __("Informazioni Base") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="nome" class="form-label">
                <?= __("Nome completo") ?> <span class="text-red-500">*</span>
              </label>
              <input id="nome" name="nome" value="<?php echo App\Support\HtmlHelper::e($autore['nome'] ?? ''); ?>" required class="form-input" placeholder="<?= __('Nome e cognome dell\'autore') ?>" />
            </div>
            <div>
              <label for="pseudonimo" class="form-label"><?= __("Pseudonimo") ?></label>
              <input id="pseudonimo" name="pseudonimo" value="<?php echo App\Support\HtmlHelper::e($autore['pseudonimo'] ?? ''); ?>" class="form-input" placeholder="<?= __('Nome d\'arte o pseudonimo') ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="data_nascita" class="form-label"><?= __("Data di nascita") ?></label>
              <input type="date" id="data_nascita" name="data_nascita" value="<?php echo App\Support\HtmlHelper::e($autore['data_nascita'] ?? ''); ?>" class="form-input" />
            </div>
            <div>
              <label for="data_morte" class="form-label"><?= __("Data di morte") ?></label>
              <input type="date" id="data_morte" name="data_morte" value="<?php echo App\Support\HtmlHelper::e($autore['data_morte'] ?? ''); ?>" class="form-input" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Lascia vuoto se l'autore è vivente") ?></p>
            </div>
          </div>

          <div>
            <label for="nazionalita" class="form-label"><?= __("Nazionalità") ?></label>
            <input id="nazionalita" name="nazionalita" value="<?php echo App\Support\HtmlHelper::e($autore['nazionalità'] ?? ''); ?>" class="form-input" placeholder="<?= __('Es. Italiana, Americana, Francese...') ?>" />
          </div>

          <div>
            <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>
            <input type="url" id="sito_web" name="sito_web" value="<?php echo App\Support\HtmlHelper::e($autore['sito_web'] ?? ''); ?>" class="form-input" placeholder="<?= __('https://www.esempio.com') ?>" />
            <p class="text-xs text-gray-500 mt-1"><?= __("Sito web ufficiale dell'autore (se disponibile)") ?></p>
          </div>
        </div>
      </div>

      <!-- Biography Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-book-open text-primary"></i>
            <?= __("Biografia") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div>
            <label for="biografia" class="form-label"><?= __("Biografia dell'autore") ?></label>
            <textarea id="biografia" name="biografia" rows="6" class="form-input" placeholder="<?= __("Inserisci una breve biografia dell'autore...") ?>"><?php echo App\Support\HtmlHelper::e($autore['biografia'] ?? ''); ?></textarea>
            <p class="text-xs text-gray-500 mt-1"><?= __("Una descrizione completa aiuta gli utenti a conoscere meglio l'autore") ?></p>
          </div>
        </div>
      </div>

      <?php
      // Issue #163 — author photo + relevant source/website links.
      $fotoVal = (string) ($autore['foto'] ?? '');
      $fotoIsUrl = $fotoVal !== '' && preg_match('#^https?://#i', $fotoVal) === 1;
      $collegamentiArr = [];
      if (!empty($autore['collegamenti'])) {
          $dec = json_decode((string) $autore['collegamenti'], true);
          if (is_array($dec)) { $collegamentiArr = $dec; }
      }
      ?>
      <!-- Photo & Links Section (issue #163) -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-image text-primary"></i>
            <?= __("Foto e Collegamenti") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div>
            <label class="form-label"><?= __("Foto dell'autore") ?></label>
            <?php if ($fotoVal !== ''): ?>
              <div class="flex items-center gap-3 mb-2" id="author-photo-current">
                <?php $fotoSrc = $fotoIsUrl ? $fotoVal : url($fotoVal); ?>
                <img src="<?= htmlspecialchars($fotoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(__('Foto autore'), ENT_QUOTES, 'UTF-8') ?>" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color,#e5e7eb);">
                <label class="inline-flex items-center gap-2 text-sm text-red-600">
                  <input type="checkbox" name="rimuovi_foto" value="1"> <?= __("Rimuovi la foto attuale") ?>
                </label>
              </div>
            <?php endif; ?>
            <!-- Uppy drag-drop UI (same pattern as the book cover uploader in book_form.php) -->
            <div id="author-uppy-upload" class="mb-2"></div>
            <div id="author-uppy-progress" class="mb-2"></div>
            <!-- Live preview of the newly selected image (mirrors book_form.php cover preview) -->
            <div id="author-photo-preview" class="mb-2"></div>
            <!-- Hidden file input fed by Uppy on file-added; the file rides the form's multipart submit -->
            <input type="file" id="author-fallback-file-input" name="foto_file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none">
            <p class="text-xs text-gray-500 mt-1"><?= __("Carica un'immagine (PNG/JPG/WEBP/GIF, max 5MB) oppure incolla un URL qui sotto.") ?></p>
            <input type="url" id="foto_url" name="foto_url" value="<?= $fotoIsUrl ? htmlspecialchars($fotoVal, ENT_QUOTES, 'UTF-8') : '' ?>" class="form-input mt-2" placeholder="<?= __('https://www.esempio.com/foto.jpg') ?>">
          </div>

          <div class="mt-6">
            <label class="form-label"><?= __("Collegamenti e fonti") ?></label>
            <p class="text-xs text-gray-500 mb-2"><?= __("Link a fonti, voci enciclopediche o siti rilevanti per l'autore.") ?></p>
            <div id="collegamenti-list" class="space-y-2">
              <?php foreach ($collegamentiArr as $c): if (!is_array($c)) { continue; } ?>
                <div class="collegamento-row flex flex-col sm:flex-row gap-2">
                  <input type="text" name="collegamenti_etichetta[]" value="<?= htmlspecialchars((string) ($c['etichetta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-input sm:w-1/3" placeholder="<?= __('Etichetta (es. Wikipedia)') ?>">
                  <input type="url" name="collegamenti_url[]" value="<?= htmlspecialchars((string) ($c['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-input sm:flex-1" placeholder="<?= __('https://...') ?>">
                  <button type="button" class="btn btn-light collegamento-remove" title="<?= __('Rimuovi') ?>"><i class="fas fa-times"></i></button>
                </div>
              <?php endforeach; ?>
            </div>
            <button type="button" id="collegamento-add" class="btn btn-light mt-2"><i class="fas fa-plus mr-1"></i><?= __("Aggiungi collegamento") ?></button>
          </div>
        </div>
      </div>

      <?php
      // Plugin hook: additional author fields (e.g. REICAT/SBN authority panel)
      \App\Support\Hooks::do('author.form.fields', [$autore ?? null]);
      ?>

      <!-- Submit Section -->
      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= htmlspecialchars(url('/admin/authors'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary order-2 sm:order-1 text-center">
          <i class="fas fa-times mr-2"></i>
          <?= __("Annulla") ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?= __("Salva Modifiche") ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- JavaScript for Enhanced UX -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    // Initialize form validation
    initializeFormValidation();

    // Initialize SweetAlert confirmations
    initializeSweetAlert();

    // Issue #163 — collegamenti (source/website links) repeater
    initializeCollegamenti();

    // Issue #163 — author photo via Uppy (same drag-drop pattern as book_form.php)
    initializeAuthorUppy();
});

// Uppy drag-drop UI feeding the hidden #author-fallback-file-input, mirroring
// book_form.php's cover uploader: Uppy is the UI, the file rides the form's
// multipart POST (AutoriController reads `foto_file`). No AJAX endpoint needed.
function initializeAuthorUppy() {
    // Whether via Uppy or the native fallback input, picking a file must cancel a
    // pending "remove current photo" — otherwise the controller (which now lets a
    // submitted upload win over rimuovi_foto) is fine, but we also keep the UI
    // consistent so the checkbox doesn't stay visually checked.
    var fallbackInput = document.getElementById('author-fallback-file-input');
    if (fallbackInput) {
        fallbackInput.addEventListener('change', function () {
            if (fallbackInput.files && fallbackInput.files.length > 0) {
                var f = fallbackInput.files[0];
                var rc = document.querySelector('input[name="rimuovi_foto"]');
                if (rc) rc.checked = false;
                // Show the preview of the picked image (mirrors book_form.php).
                showAuthorPhotoPreview(f, f.name, f.size);
            } else {
                clearAuthorPhotoPreview();
            }
        });
    }

    var mount = document.getElementById('author-uppy-upload');
    if (!mount || typeof Uppy === 'undefined' || typeof UppyDragDrop === 'undefined') {
        // Graceful fallback: reveal the hidden file input so upload still works.
        if (fallbackInput) fallbackInput.style.display = 'block';
        return;
    }
    try {
        var uppy = new Uppy({
            restrictions: { maxFileSize: 5000000, maxNumberOfFiles: 1, allowedFileTypes: ['image/*'] },
            autoProceed: false
        });
        uppy.use(UppyDragDrop, {
            target: '#author-uppy-upload',
            note: <?= json_encode(__("Trascina qui la foto dell'autore o clicca per selezionare"), JSON_HEX_TAG) ?>
        });
        if (typeof UppyProgressBar !== 'undefined') {
            uppy.use(UppyProgressBar, { target: '#author-uppy-progress', hideAfterFinish: false });
        }
        uppy.on('file-added', function (file) {
            var input = document.getElementById('author-fallback-file-input');
            if (!input) return;
            var dt = new DataTransfer();
            dt.items.add(new File([file.data], file.name, { type: file.type }));
            input.files = dt.files;
            // Picking a new file cancels a pending "remove current photo".
            var rc = document.querySelector('input[name="rimuovi_foto"]'); if (rc) rc.checked = false;
            // Show the preview of the picked image so the user can verify it.
            showAuthorPhotoPreview(file.data, file.name, file.size);
        });
        uppy.on('file-removed', function () {
            document.getElementById('author-fallback-file-input').value = '';
            clearAuthorPhotoPreview();
        });
    } catch (e) {
        console.error('Author Uppy init failed:', e);
        var fb2 = document.getElementById('author-fallback-file-input');
        if (fb2) fb2.style.display = 'block';
    }
}

// Live preview of a newly-selected author photo (Blob/File), mirroring the cover
// preview in book_form.php. The image is rendered from a data URL; the file name
// is set via textContent so a crafted file name cannot inject HTML.
function showAuthorPhotoPreview(blob, name, size) {
    var container = document.getElementById('author-photo-preview');
    if (!container || !blob) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        container.innerHTML =
            '<div class="inline-flex flex-col items-start gap-2">' +
                '<img alt="' + <?= json_encode(__('Anteprima foto autore'), JSON_HEX_TAG) ?> + '" ' +
                     'style="max-height:12rem;object-fit:contain;border:1px solid var(--border-color,#e5e7eb);border-radius:8px;" />' +
                '<div class="flex items-center gap-2 text-sm text-gray-600">' +
                    '<i class="fas fa-check-circle text-green-500"></i><span></span>' +
                '</div>' +
            '</div>';
        var img = container.querySelector('img'); if (img) img.src = e.target.result;
        var span = container.querySelector('span');
        if (span) span.textContent = (name || '') + (size ? ' (' + (size / 1024).toFixed(1) + ' KB)' : '');
    };
    reader.readAsDataURL(blob);
}
function clearAuthorPhotoPreview() {
    var c = document.getElementById('author-photo-preview');
    if (c) c.innerHTML = '';
}

// Add/remove rows for the author "collegamenti" (links) list.
function initializeCollegamenti() {
    const list = document.getElementById('collegamenti-list');
    const addBtn = document.getElementById('collegamento-add');
    if (!list || !addBtn) return;
    const labelPh = <?= json_encode(__('Etichetta (es. Wikipedia)'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
    const urlPh = 'https://...';
    addBtn.addEventListener('click', function() {
        const row = document.createElement('div');
        row.className = 'collegamento-row flex flex-col sm:flex-row gap-2';
        row.innerHTML =
            '<input type="text" name="collegamenti_etichetta[]" class="form-input sm:w-1/3">' +
            '<input type="url" name="collegamenti_url[]" class="form-input sm:flex-1">' +
            '<button type="button" class="btn btn-light collegamento-remove"><i class="fas fa-times"></i></button>';
        row.querySelector('input[type="text"]').placeholder = labelPh;
        row.querySelector('input[type="url"]').placeholder = urlPh;
        list.appendChild(row);
    });
    list.addEventListener('click', function(e) {
        const btn = e.target.closest('.collegamento-remove');
        if (btn) { const row = btn.closest('.collegamento-row'); if (row) row.remove(); }
    });
}

// Initialize Form Validation
function initializeFormValidation() {
    const form = document.getElementById('edit-author-form');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate required fields
        const nome = form.querySelector('input[name="nome"]').value.trim();
        if (!nome) {
            window.SwalApp.error(
                <?= json_encode(__("Campo Obbligatorio"), JSON_HEX_TAG) ?>,
                <?= json_encode(__("Il nome dell'autore è obbligatorio."), JSON_HEX_TAG) ?>
            );
            return;
        }

        const dataNascita = form.querySelector('input[name="data_nascita"]').value;
        const dataMorte = form.querySelector('input[name="data_morte"]').value;

        if (dataNascita && dataMorte) {
            if (new Date(dataNascita) >= new Date(dataMorte)) {
                window.SwalApp.error(
                    <?= json_encode(__("Date Non Valide"), JSON_HEX_TAG) ?>,
                    <?= json_encode(__("La data di nascita deve essere precedente alla data di morte."), JSON_HEX_TAG) ?>
                );
                return;
            }
        }

        const result = await window.SwalApp.confirm({
            title: <?= json_encode(__("Conferma Aggiornamento"), JSON_HEX_TAG) ?>,
            text: <?= json_encode(__("Sei sicuro di voler aggiornare l'autore \"%s\"?"), JSON_HEX_TAG) ?>.replace('%s', nome),
            confirmText: <?= json_encode(__("Sì, Aggiorna"), JSON_HEX_TAG) ?>
        });

        if (result.isConfirmed) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: <?= json_encode(__("Aggiornamento in corso..."), JSON_HEX_TAG) ?>,
                    text: <?= json_encode(__("Attendere prego"), JSON_HEX_TAG) ?>,
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => { Swal.showLoading(); }
                });
            }
            form.submit();
        }
    });
}

// Initialize SweetAlert2 configurations
function initializeSweetAlert() {
    if (typeof Swal !== 'undefined') {
        
        // Set default configurations
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
        
        // Make Toast available globally
        window.Toast = Toast;
    }
}

</script>

<!-- Custom Styles -->
<style>
.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

.slide-in-up {
  animation: slideInUp 0.6s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
