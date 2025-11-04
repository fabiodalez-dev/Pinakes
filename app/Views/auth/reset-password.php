<?php
use App\Support\ConfigStore;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resetta Password - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    
    <link href="/assets/vendor.css" rel="stylesheet">
    <link href="/assets/main.css" rel="stylesheet">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900">

<div class="min-h-screen bg-gray-50 dark:bg-gray-900 py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-md w-full mx-auto">
    <!-- Logo and Branding -->
    <div class="text-center mb-10">
      <div class="w-20 h-20 bg-gray-800 dark:bg-gray-700 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl">
        <i class="fas fa-key text-white text-3xl"></i>
      </div>
      <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Resetta Password</h1>
      <p class="text-gray-600 dark:text-gray-400">Inserisci la tua nuova password</p>
    </div>

    <!-- Reset Password Form -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 border border-gray-200 dark:border-gray-700">
      <?php if (isset($_GET['error'])): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6" role="alert">
          <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 dark:text-red-400 mr-3"></i>
            <div class="text-red-700 dark:text-red-300 text-sm">
              <?php if ($_GET['error'] === 'invalid_token'): ?>
                Link di reset non valido o scaduto
              <?php elseif ($_GET['error'] === 'token_expired'): ?>
                Questo link di reset è scaduto. Richiedi uno nuovo
              <?php elseif ($_GET['error'] === 'csrf'): ?>
                Errore di sicurezza. Aggiorna la pagina e riprova
              <?php elseif ($_GET['error'] === 'password_mismatch'): ?>
                Le password non coincidono
              <?php elseif ($_GET['error'] === 'weak_password'): ?>
                La password deve contenere almeno 8 caratteri, lettere maiuscole, minuscole e numeri
              <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                Compila tutti i campi richiesti
              <?php else: ?>
                Si è verificato un errore. Riprova
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-4 mb-6" role="alert">
          <div class="flex">
            <div class="flex-shrink-0">
              <i class="fas fa-check-circle text-green-500 dark:text-green-400"></i>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-green-800 dark:text-green-200">
                Password resettata con successo!
              </p>
              <p class="mt-2 text-sm text-green-700 dark:text-green-300">
                Ora puoi accedere con la tua nuova password.
              </p>
              <div class="mt-4">
                <a href="/login" class="inline-block bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition-colors">
                  Accedi
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!isset($_GET['success'])): ?>
        <form method="post" action="/reset-password" class="space-y-6">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Nuova Password
            </label>
            <input
              type="password" autocomplete="new-password"
              id="password"
              name="password"
              required aria-required="true"
              aria-describedby="password-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-all duration-200"
              placeholder="••••••••"
              minlength="8"
            />
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
              Minimo 8 caratteri, con lettere maiuscole, minuscole e numeri
            </p>
            <span id="password-error" class="text-sm text-red-600 dark:text-red-400 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              Conferma Password
            </label>
            <input
              type="password" autocomplete="new-password"
              id="password_confirm"
              name="password_confirm"
              required aria-required="true"
              aria-describedby="password_confirm-error"
              class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-all duration-200"
              placeholder="••••••••"
              minlength="8"
            />
            <span id="password_confirm-error" class="text-sm text-red-600 dark:text-red-400 mt-1 hidden" role="alert" aria-live="polite"></span>
          </div>

          <!-- Password strength indicator -->
          <div id="password-strength" class="hidden">
            <div class="flex items-center space-x-2 mb-2">
              <div class="h-1 flex-1 bg-gray-200 dark:bg-gray-600 rounded-full">
                <div id="strength-bar" class="h-1 bg-red-500 rounded-full" style="width: 0%"></div>
              </div>
              <span id="strength-text" class="text-xs font-medium text-gray-600 dark:text-gray-400">-</span>
            </div>
          </div>

          <div>
            <button
              type="submit"
              class="w-full bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white font-medium py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5"
            >
              Resetta Password
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- Footer Links -->
    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="/privacy-policy" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          Privacy Policy
        </a>
        <a href="/contatti" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          Contatti
        </a>
      </div>
      <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>. Tutti i diritti riservati.
      </p>
    </div>
  </div>
</div>

<script>
  const passwordInput = document.getElementById('password');
  const passwordConfirmInput = document.getElementById('password_confirm');
  const strengthDiv = document.getElementById('password-strength');
  const strengthBar = document.getElementById('strength-bar');
  const strengthText = document.getElementById('strength-text');

  if (passwordInput) {
    passwordInput.addEventListener('input', function() {
      const password = this.value;
      let strength = 0;
      let strengthLabel = 'Debole';
      let color = 'bg-red-500';

      // Check password criteria
      if (password.length >= 8) strength++;
      if (password.length >= 12) strength++;
      if (/[A-Z]/.test(password)) strength++;
      if (/[a-z]/.test(password)) strength++;
      if (/[0-9]/.test(password)) strength++;
      if (/[^A-Za-z0-9]/.test(password)) strength++;

      // Determine strength level
      if (strength <= 2) {
        strengthLabel = 'Debole';
        color = 'bg-red-500';
      } else if (strength <= 4) {
        strengthLabel = 'Media';
        color = 'bg-yellow-500';
      } else {
        strengthLabel = 'Forte';
        color = 'bg-green-500';
      }

      strengthDiv.classList.remove('hidden');
      strengthBar.style.width = (strength * 16.67) + '%';
      strengthBar.className = 'h-1 rounded-full ' + color;
      strengthText.textContent = strengthLabel;
    });
  }
</script>

</body>
</html>
