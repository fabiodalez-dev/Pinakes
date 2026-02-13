<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogo = Branding::fullLogo();
$registerRoute = route_path('register');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Registrazione') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <link href="/assets/vendor.css" rel="stylesheet">
    <link href="/assets/main.css" rel="stylesheet">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-gray-50">

<div class="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-md lg:max-w-2xl w-full mx-auto">
    <!-- Logo and Branding -->
    <div class="text-center mb-10">
      <?php if ($appLogo): ?>
        <div class="mx-auto mb-6 flex items-center justify-center">
          <img src="<?= htmlspecialchars($appLogo, ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>"
               class="h-20 w-auto object-contain">
        </div>
      <?php else: ?>
        <div class="w-20 h-20 bg-gray-800 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl">
          <i class="fas fa-book-open text-white text-3xl"></i>
        </div>
      <?php endif; ?>
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-gray-600"><?= __('Crea un nuovo account') ?></p>
    </div>

    <!-- Registration Form -->
    <div class="bg-white rounded-2xl shadow-xl p-8 border border-gray-200">
      <form method="post" action="<?= $registerRoute ?>" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>" />
        
        <?php if (isset($_GET['error'])): ?>
          <div class="bg-red-50 border border-red-200 rounded-xl p-4" role="alert">
            <div class="flex items-center">
              <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
              <div class="text-red-700 text-sm">
                <?php if ($_GET['error'] === 'session_expired'): ?>
                  <?= __('La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina e riprova') ?>
                <?php elseif ($_GET['error'] === '1'): ?>
                  <?= __('Errore durante la registrazione') ?>
                <?php elseif ($_GET['error'] === 'csrf'): ?>
                  <?= __('Errore di sicurezza, riprova') ?>
                <?php elseif ($_GET['error'] === 'email_exists'): ?>
                  <?= __('Email già registrata') ?>
                <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                  <?= __('Compila tutti i campi richiesti') ?>
                <?php elseif ($_GET['error'] === 'privacy_required'): ?>
                  <?= __('Devi accettare la Privacy Policy per procedere') ?>
                <?php elseif ($_GET['error'] === 'name_too_long'): ?>
                  <?= __('Nome o cognome troppo lungo (massimo 100 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'email_too_long'): ?>
                  <?= __('Email troppo lunga (massimo 255 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'password_too_long'): ?>
                  <?= __('Password troppo lunga (massimo 128 caratteri)') ?>
                <?php elseif ($_GET['error'] === 'password_too_short'): ?>
                  <?= __('La password deve essere lunga almeno 8 caratteri') ?>
                <?php elseif ($_GET['error'] === 'password_needs_upper_lower_number'): ?>
                  <?= __('La password deve contenere almeno una lettera maiuscola, una minuscola e un numero') ?>
                <?php elseif ($_GET['error'] === 'db'): ?>
                  <?= __('Errore del database durante la registrazione. Riprova più tardi') ?>
                <?php else: ?>
                  <?= __('Errore durante la registrazione') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
          <div class="bg-green-50 border border-green-200 rounded-xl p-4" role="alert">
            <div class="flex items-center">
              <i class="fas fa-check-circle text-green-500 mr-3"></i>
              <div class="text-green-700 text-sm">
                <?php if ($_GET['success'] === 'registered'): ?>
                  <?= __('Account creato con successo! Verifica la tua email.') ?>
                <?php elseif ($_GET['success'] === 'pending_approval'): ?>
                  <?= __('Account creato! In attesa di approvazione da parte dell\'amministratore.') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __('Nome') ?>
            </label>
            <input
              type="text"
              id="nome"
              name="nome"
              required aria-required="true"
              aria-describedby="nome-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
              placeholder="<?= __('Mario') ?>"
              value="<?php echo htmlspecialchars($_GET['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
            <span id="nome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="cognome" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __('Cognome') ?>
            </label>
            <input
              type="text"
              id="cognome"
              name="cognome"
              required aria-required="true"
              aria-describedby="cognome-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
              placeholder="<?= __('Rossi') ?>"
              value="<?php echo htmlspecialchars($_GET['cognome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
            <span id="cognome-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Email *') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
            placeholder="<?= __('mario.rossi@email.it') ?>"
            value="<?php echo htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="email-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="telefono" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Telefono *') ?>
          </label>
          <input
            type="tel"
            id="telefono"
            name="telefono"
            required aria-required="true"
            aria-describedby="telefono-error"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
            placeholder="<?= __('+39 123 456 7890') ?>"
            value="<?php echo htmlspecialchars($_GET['telefono'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="telefono-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="indirizzo" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Indirizzo completo *') ?>
          </label>
          <textarea
            id="indirizzo"
            name="indirizzo"
            required aria-required="true"
            aria-describedby="indirizzo-error"
            rows="3"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
            placeholder="<?= __('Via, numero civico, città, CAP') ?>"
          ><?php echo htmlspecialchars($_GET['indirizzo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
          <span id="indirizzo-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="data_nascita" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __('Data di nascita') ?>
            </label>
            <input
              type="date"
              id="data_nascita"
              name="data_nascita"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
              value="<?php echo htmlspecialchars($_GET['data_nascita'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            />
          </div>

          <div>
            <label for="sesso" class="block text-sm font-medium text-gray-700 mb-2">
              <?= __('Sesso') ?>
            </label>
            <select
              id="sesso"
              name="sesso"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
            >
              <option value=""><?= __("-- Seleziona --") ?></option>
              <option value="M"><?= __('Maschio') ?></option>
              <option value="F"><?= __('Femmina') ?></option>
              <option value="Altro"><?= __('Altro') ?></option>
            </select>
          </div>
        </div>

        <div>
          <label for="cod_fiscale" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Codice Fiscale') ?>
          </label>
          <input
            type="text"
            id="cod_fiscale"
            name="cod_fiscale"
            maxlength="16"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
            placeholder="<?= __('es. RSSMRA80A01H501U') ?>"
            style="text-transform: uppercase;"
            value="<?php echo htmlspecialchars($_GET['cod_fiscale'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <p class="mt-1 text-xs text-gray-500"><?= __("Opzionale") ?></p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
              Password
            </label>
            <input
              type="password"
              id="password"
              name="password"
              required aria-required="true"
              autocomplete="new-password"
              aria-describedby="password-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
              placeholder="<?= __('••••••••') ?>"
            />
            <span id="password-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-2">
              Conferma Password
            </label>
            <input
              type="password"
              id="password_confirm"
              name="password_confirm"
              required aria-required="true"
              autocomplete="new-password"
              aria-describedby="password_confirm-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
              placeholder="<?= __('••••••••') ?>"
            />
            <span id="password_confirm-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>
        </div>

        <div class="flex items-start">
          <div class="flex items-center h-5">
            <input
              id="privacy_acceptance"
              name="privacy_acceptance"
              type="checkbox"
              class="w-4 h-4 text-gray-600 bg-gray-100 border-gray-300 rounded focus:ring-gray-500"
              required aria-required="true"
              aria-describedby="privacy_acceptance-error"
            />
          </div>
          <div class="ml-2">
            <label for="privacy_acceptance" class="text-sm font-medium text-gray-700">
              <?= __('Accetto la') ?> <a href="<?= route_path('privacy') ?>" class="text-gray-600 hover:underline"><?= __('Privacy Policy') ?></a>.
            </label>
            <span id="privacy_acceptance-error" class="text-sm text-red-600 mt-1 hidden block" role="alert" aria-live="polite"></span>
          </div>
        </div>

        <div>
          <button
            type="submit"
            class="w-full bg-gray-800 hover:bg-gray-900 text-white font-medium py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5"
          >
            <?= __('Registrati') ?>
          </button>
        </div>
      </form>

      <div class="mt-6 text-center">
        <p class="text-gray-600 text-sm">
          <?= __('Hai già un account?') ?> 
          <a href="<?= route_path('login') ?>" class="font-medium text-gray-600 hover:text-gray-800 transition-colors">
            <?= __('Accedi') ?>
          </a>
        </p>
      </div>
    </div>

    <!-- Footer Links -->
    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="<?= route_path('privacy') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= route_path('contact') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <?= __('Contatti') ?>
        </a>
      </div>
      <p class="mt-4 text-xs text-gray-500">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>. <?= __('Tutti i diritti riservati.') ?>
      </p>
    </div>
  </div>
</div>

<script>
// Password strength validation
document.addEventListener('DOMContentLoaded', function() {
  const password = document.getElementById('password');
  const confirmPassword = document.getElementById('password_confirm');
  const form = document.querySelector('form');

  if (password && confirmPassword && form) {
    form.addEventListener('submit', function(e) {
      if (password.value !== confirmPassword.value) {
        e.preventDefault();
        alert('<?= __("Le password non coincidono!") ?>');
        confirmPassword.focus();
        return false;
      }

      if (password.value.length < 8) {
        e.preventDefault();
        alert('<?= __("La password deve essere lunga almeno 8 caratteri!") ?>');
        password.focus();
        return false;
      }
    });
  }
});
</script>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
