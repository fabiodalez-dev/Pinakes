<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogoPath = Branding::fullLogo();
$appLogo = $appLogoPath !== '' ? url($appLogoPath) : '';
$loginRoute = route_path('login');
$registerRoute = route_path('register');
$forgotPasswordRoute = route_path('forgot_password');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Accesso') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath()) ?>;</script>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(url('/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>">
    
    <link href="<?= htmlspecialchars(assetUrl('vendor.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('main.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
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
        <div class="w-20 h-20 bg-black rounded-2xl flex items-center justify-center mx-auto mb-6">
          <i class="fas fa-book-open text-white text-3xl"></i>
        </div>
      <?php endif; ?>
      <h1 class="text-3xl font-bold text-gray-900 mb-2"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-gray-600"><?= __('Accedi al tuo account') ?></p>
    </div>

    <!-- Login Form -->
    <div class="bg-white rounded-2xl p-8 border border-gray-200">
      <form method="post" action="<?= htmlspecialchars($loginRoute, ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
        <?php if (!empty($return_url ?? '')): ?>
          <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <?php if (isset($_GET['verified']) && $_GET['verified'] === '1'): ?>
          <div class="bg-green-50 border border-green-200 rounded-xl p-4" role="alert">
            <div class="flex items-center">
              <i class="fas fa-check-circle text-green-500 mr-3"></i>
              <div class="text-green-700 text-sm">
                <?= __('Email verificata con successo! Il tuo account è ora in attesa di approvazione da parte dell\'amministratore. Riceverai un\'email quando sarà attivato.') ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
          <div class="bg-red-50 border border-red-200 rounded-xl p-4" role="alert">
            <div class="flex items-center">
              <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
              <div class="text-red-700 text-sm">
                <?php if ($_GET['error'] === 'invalid_credentials'): ?>
                  <?= __('Email o password non corretti. Verifica le credenziali e riprova') ?>
                <?php elseif ($_GET['error'] === 'session_expired'): ?>
                  <?= __('La tua sessione è scaduta. Per motivi di sicurezza, effettua nuovamente l\'accesso') ?>
                <?php elseif ($_GET['error'] === 'csrf'): ?>
                  <?= __('Errore di sicurezza. Aggiorna la pagina e riprova') ?>
                <?php elseif ($_GET['error'] === 'account_suspended'): ?>
                  <?= __('Il tuo account è stato sospeso. Contatta l\'amministratore per maggiori informazioni') ?>
                <?php elseif ($_GET['error'] === 'account_pending'): ?>
                  <?= __('Il tuo account è in attesa di approvazione. Riceverai un\'email quando sarà attivato') ?>
                <?php elseif ($_GET['error'] === 'email_not_verified'): ?>
                  <?= __('Email non verificata. Controlla la tua casella di posta e clicca sul link di verifica') ?>
                <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                  <?= __('Compila tutti i campi richiesti') ?>
                <?php elseif ($_GET['error'] === 'token_expired'): ?>
                  <?= __('Il link di verifica email è scaduto o non valido. Registrati nuovamente per ricevere un nuovo link.') ?>
                <?php elseif ($_GET['error'] === 'invalid_token'): ?>
                  <?= __('Il link di verifica non è valido. Assicurati di aver copiato l\'intero link dall\'email.') ?>
                <?php elseif ($_GET['error'] === 'auth_required'): ?>
                  <?= __('Sessione scaduta, ti preghiamo di rifare il login') ?>
                <?php else: ?>
                  <?= __('Si è verificato un errore durante l\'accesso. Riprova') ?>
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
                <?php if ($_GET['success'] === 'logout'): ?>
                  <?= __('Logout effettuato con successo') ?>
                <?php elseif ($_GET['success'] === 'registered'): ?>
                  <?= __('Registrazione completata! Effettua l\'accesso') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Email') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-black focus:border-black transition-all duration-200"
            placeholder="<?= __('mario.rossi@email.it') ?>"
            value="<?php echo htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="email-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __('Password') ?>
          </label>
          <input
            type="password"
            id="password"
            name="password"
            required aria-required="true"
            autocomplete="current-password"
            aria-describedby="password-error"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 bg-white text-gray-900 focus:ring-2 focus:ring-black focus:border-black transition-all duration-200"
            placeholder="<?= __('••••••••') ?>"
          />
          <span id="password-error" class="text-sm text-red-600 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div class="flex items-center justify-between gap-4">
          <div class="flex items-center">
            <input
              id="remember_me"
              name="remember_me"
              type="checkbox"
              class="w-4 h-4 text-black bg-gray-100 border-gray-300 rounded focus:ring-black"
            />
            <label for="remember_me" class="ml-2 text-sm font-medium text-gray-700">
              <?= __('Ricordami') ?>
            </label>
          </div>
          <a href="<?= htmlspecialchars($forgotPasswordRoute, ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-600 hover:text-black transition-colors whitespace-nowrap">
            <?= __('Password dimenticata?') ?>
          </a>
        </div>

        <?php
        // Plugin hook: Additional fields before submit button (e.g., reCAPTCHA, 2FA)
        \App\Support\Hooks::do('login.form.fields', []);
        ?>

        <div>
          <button
            type="submit"
            class="w-full bg-black hover:bg-gray-900 text-white font-medium py-3 px-4 rounded-xl transition-all duration-300 transform hover:-translate-y-0.5"
          >
            <?= __('Accedi') ?>
          </button>
        </div>
      </form>

      <div class="mt-6 text-center">
        <p class="text-gray-600 text-sm">
          <?= __('Non hai un account?') ?>
          <a href="<?= htmlspecialchars($registerRoute, ENT_QUOTES, 'UTF-8') ?>" class="font-medium text-gray-600 hover:text-black transition-colors">
            <?= __('Registrati') ?>
          </a>
        </p>
      </div>
    </div>

    <!-- Footer Links -->
    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="<?= htmlspecialchars(route_path('privacy'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
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
// Auto-refresh CSRF token ogni 10 minuti per evitare scadenza
// Utile per utenti mobile che tengono aperta la pagina a lungo
(function() {
    let lastActivity = Date.now();

    // Aggiorna timestamp su qualsiasi interazione
    ['click', 'keydown', 'touchstart'].forEach(event => {
        document.addEventListener(event, () => {
            lastActivity = Date.now();
        });
    });

    // Ogni 10 minuti, se l'utente è stato attivo negli ultimi 5 minuti,
    // ricarica il token CSRF senza refresh della pagina
    setInterval(() => {
        const minutesInactive = (Date.now() - lastActivity) / 1000 / 60;

        // Se l'utente è stato attivo negli ultimi 5 minuti, refresh del token
        if (minutesInactive < 5) {
            fetch(<?= json_encode($loginRoute) ?>, {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => response.text())
            .then(html => {
                // Estrai il nuovo token dalla risposta
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newTokenInput = doc.querySelector('input[name="csrf_token"]');
                const currentTokenInput = document.querySelector('input[name="csrf_token"]');

                if (newTokenInput && currentTokenInput) {
                    currentTokenInput.value = newTokenInput.value;
                }
            })
            .catch(err => {
                console.error('Failed to refresh CSRF token:', err);
            });
        }
    }, 10 * 60 * 1000); // 10 minuti
})();

// Prevenzione double-submit del form
document.querySelector('form').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn.disabled) {
        e.preventDefault();
        return false;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= __('Accesso in corso...') ?>';
});
</script>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
