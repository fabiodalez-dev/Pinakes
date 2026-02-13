<?php use App\Support\Csrf; $csrf = Csrf::ensureToken(); ?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= url('/admin/dashboard') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= url('/admin/autori') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-user-edit mr-1"></i>Autori
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium"><?= __("Nuovo") ?></li>
      </ol>
    </nav>
    <!-- Header -->
    <div class="mb-8 fade-in">
      <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
        <i class="fas fa-user-plus text-blue-600"></i>
        <?= __("Aggiungi Nuovo Autore") ?>
      </h1>
      <p class="text-gray-600"><?= __("Compila i dettagli dell'autore per aggiungerlo alla biblioteca") ?></p>
    </div>

    <!-- Main Form -->
    <form method="post" action="<?= url('/admin/autori/crea') ?>" class="space-y-8 slide-in-up">
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
              <input id="nome" name="nome" required aria-required="true" class="form-input" placeholder="<?= __('Nome e cognome dell\'autore') ?>" />
            </div>
            <div>
              <label for="pseudonimo" class="form-label"><?= __("Pseudonimo") ?></label>
              <input id="pseudonimo" name="pseudonimo" class="form-input" placeholder="<?= __('Nome d\'arte o pseudonimo') ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="data_nascita" class="form-label"><?= __("Data di nascita") ?></label>
              <input type="date" id="data_nascita" name="data_nascita" class="form-input" />
            </div>
            <div>
              <label for="data_morte" class="form-label"><?= __("Data di morte") ?></label>
              <input type="date" id="data_morte" name="data_morte" class="form-input" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Lascia vuoto se l'autore è vivente") ?></p>
            </div>
          </div>

          <div>
            <label for="nazionalita" class="form-label"><?= __("Nazionalità") ?></label>
            <input id="nazionalita" name="nazionalita" class="form-input" placeholder="<?= __("Es. Italiana, Americana, Francese...") ?>" />
          </div>

          <div>
            <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>
            <input type="url" id="sito_web" name="sito_web" class="form-input" placeholder="<?= __("https://www.esempio.com") ?>" />
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
            <textarea id="biografia" name="biografia" rows="6" class="form-input" placeholder="<?= __("Inserisci una breve biografia dell'autore...") ?>"></textarea>
            <p class="text-xs text-gray-500 mt-1"><?= __("Una descrizione completa aiuta gli utenti a conoscere meglio l'autore") ?></p>
          </div>
        </div>
      </div>

      <!-- Submit Section -->
      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= url('/admin/autori') ?>" class="btn-secondary order-2 sm:order-1 text-center">
          <i class="fas fa-times mr-2"></i>
          <?= __("Annulla") ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?= __("Salva Autore") ?>
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
    const form = document.querySelector('form[action$="/admin/autori/crea"]');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate required fields
        const nome = form.querySelector('input[name="nome"]').value.trim();
        if (!nome) {
            if (window.Swal) {
                Swal.fire({
                    icon: 'error',
                    title: '<?= addslashes(__("Campo Obbligatorio")) ?>',
                    text: '<?= addslashes(__("Il nome dell\'autore è obbligatorio.")) ?>'
                });
            } else {
                alert('<?= addslashes(__("Il nome dell\'autore è obbligatorio.")) ?>');
            }
            return;
        }
        
        // Validate dates if provided
        const dataNascita = form.querySelector('input[name="data_nascita"]').value;
        const dataMorte = form.querySelector('input[name="data_morte"]').value;
        
        if (dataNascita && dataMorte) {
            if (new Date(dataNascita) >= new Date(dataMorte)) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: '<?= addslashes(__("Date Non Valide")) ?>',
                        text: '<?= addslashes(__("La data di nascita deve essere precedente alla data di morte.")) ?>'
                    });
                } else {
                    alert('<?= addslashes(__("La data di nascita deve essere precedente alla data di morte.")) ?>');
                }
                return;
            }
        }
        
        // Show confirmation dialog
        if (window.Swal) {
            const result = await Swal.fire({
                title: '<?= addslashes(__("Conferma Salvataggio")) ?>',
                text: '<?= addslashes(__("Sei sicuro di voler salvare l\'autore \"%s\"?")) ?>'.replace('%s', nome),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<?= addslashes(__("Sì, Salva")) ?>',
                cancelButtonText: '<?= addslashes(__("Annulla")) ?>',
                reverseButtons: true
            });

            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: '<?= addslashes(__("Salvataggio in corso...")) ?>',
                    text: '<?= addslashes(__("Attendere prego")) ?>',
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
            if (confirm('<?= addslashes(__("Sei sicuro di voler salvare l\'autore \"%s\"?")) ?>'.replace('%s', nome))) {
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
