<?php
/**
 * @var array $data { editore: array }
 */
use App\Support\Csrf;
$csrf = Csrf::ensureToken();
$editore = $data['editore'];
$title = "Modifica <?= __("Editore:") ?> " . ($editore['nome'] ?? 'N/D');
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="/admin/editori" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-building mr-1"></i><?= __("Editori") ?>
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
        <i class="fas fa-building text-blue-600"></i>
        <?= __("Modifica Editore") ?>
      </h1>
      <p class="text-gray-600"><?= __("Aggiorna i dettagli dell'editore:") ?> <strong><?php echo App\Support\HtmlHelper::e($editore['nome'] ?? 'N/A'); ?></strong></p>
    </div>

    <!-- Main Form -->
    <form method="post" action="/admin/editori/update/<?php echo (int)$editore['id']; ?>" class="space-y-8 slide-in-up">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      
      <!-- Basic Information Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-building text-primary"></i>
            <?= __("Informazioni Base") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="nome" class="form-label">
                <?= __("Nome Editore") ?> <span class="text-red-500">*</span>
              </label>
              <input id="nome" name="nome" value="<?php echo App\Support\HtmlHelper::e($editore['nome'] ?? ''); ?>" required class="form-input" placeholder="<?= __('Nome della casa editrice') ?>" />
            </div>
            <div>
              <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>
              <input id="sito_web" name="sito_web" value="<?php echo App\Support\HtmlHelper::e($editore['sito_web'] ?? ''); ?>" type="url" class="form-input" placeholder="<?= __('https://www.editore.com') ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Sito web ufficiale dell'editore") ?></p>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="email" class="form-label"><?= __("Email Contatto") ?></label>
              <input id="email" name="email" value="<?php echo App\Support\HtmlHelper::e($editore['email'] ?? ''); ?>" type="email" class="form-input" placeholder="<?= __('info@editore.com') ?>" />
            </div>
            <div>
              <label for="telefono" class="form-label"><?= __("Telefono") ?></label>
              <input id="telefono" name="telefono" value="<?php echo App\Support\HtmlHelper::e($editore['telefono'] ?? ''); ?>" type="tel" class="form-input" placeholder="<?= __('+39 02 1234567') ?>" />
            </div>
          </div>

          <div>
            <label for="indirizzo" class="form-label"><?= __("Indirizzo") ?></label>
            <textarea id="indirizzo" name="indirizzo" rows="3" class="form-input" placeholder="<?= __('Via Roma 123, 00100 Roma RM, Italia') ?>"><?php echo App\Support\HtmlHelper::e($editore['indirizzo'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>

      <!-- Referente Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-user-tie text-primary"></i>
            <?= __("Referente") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="referente_nome" class="form-label"><?= __("Nome Referente") ?></label>
              <input id="referente_nome" name="referente_nome" value="<?php echo App\Support\HtmlHelper::e($editore['referente_nome'] ?? ''); ?>" class="form-input" placeholder="<?= __('Nome e cognome del referente') ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Persona di riferimento presso l'editore") ?></p>
            </div>
            <div>
              <label for="referente_telefono" class="form-label"><?= __("Telefono Referente") ?></label>
              <input id="referente_telefono" name="referente_telefono" value="<?php echo App\Support\HtmlHelper::e($editore['referente_telefono'] ?? ''); ?>" type="tel" class="form-input" placeholder="<?= __('+39 02 1234567') ?>" />
            </div>
            <div>
              <label for="referente_email" class="form-label"><?= __("Email Referente") ?></label>
              <input id="referente_email" name="referente_email" value="<?php echo App\Support\HtmlHelper::e($editore['referente_email'] ?? ''); ?>" type="email" class="form-input" placeholder="<?= __('referente@editore.com') ?>" />
            </div>
          </div>

          <div>
            <label for="codice_fiscale" class="form-label"><?= __("Codice Fiscale") ?></label>
            <input id="codice_fiscale" name="codice_fiscale" value="<?php echo App\Support\HtmlHelper::e($editore['codice_fiscale'] ?? ''); ?>" type="text" maxlength="16" class="form-input" placeholder="<?= __('es. RSSMRA80A01H501U') ?>" />
            <p class="text-xs text-gray-500 mt-1"><?= __("Codice fiscale dell'editore (opzionale)") ?></p>
          </div>
        </div>
      </div>

      <!-- Submit Section -->
      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="/admin/editori" class="btn-secondary order-2 sm:order-1 text-center">
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
});

// Initialize Form Validation
function initializeFormValidation() {
    const form = document.querySelector('form[action*="/admin/editori/update/"]');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate required fields
        const nome = form.querySelector('input[name="nome"]').value.trim();
        if (!nome) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: __('Campo Obbligatorio'),
                    text: __('Il nome dell\')editore è obbligatorio.'
                });
            } else {
                alert('Il nome dell\'editore è obbligatorio.');
            }
            return;
        }
        
        // Validate URL if provided
        const sitoWeb = form.querySelector('input[name="sito_web"]').value.trim();
        if (sitoWeb && !isValidURL(sitoWeb)) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: __('URL Non Valido'),
                    text: __('Il sito web deve essere un URL valido (es. https://www.esempio.com).')
                });
            } else {
                alert(__('Il sito web deve essere un URL valido.'));
            }
            return;
        }
        
        // Validate email if provided
        const email = form.querySelector('input[name="email"]').value.trim();
        if (email && !isValidEmail(email)) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: __('Email Non Valida'),
                    text: __('L\')indirizzo email deve essere valido.'
                });
            } else {
                alert('L\'indirizzo email deve essere valido.');
            }
            return;
        }
        
        // Show confirmation dialog
        if (window.Swal) {
            const result = await Swal.fire({
                title: __('Conferma Aggiornamento'),
                text: `Sei sicuro di voler aggiornare l'editore "${nome}"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: __('Sì, Aggiorna'),
                cancelButtonText: __('Annulla'),
                reverseButtons: true
            });
            
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: __('Aggiornamento in corso...'),
                    text: __('Attendere prego'),
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    willOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Submit the form
                form.submit();
            }
        } else {
            if (confirm('<?= addslashes(__("Sei sicuro di voler aggiornare l\'editore")) ?> "' + nome + '"?')) {
                form.submit();
            }
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

// Utility functions
function isValidURL(string) {
    try {
        new URL(string);
        return true;
    } catch (_) {
        return false;
    }
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
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
