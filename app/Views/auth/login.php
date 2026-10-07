<?php
/** @var ?string $return_url */
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
    <meta name="robots" content="noindex,follow">
    <title><?= __('Accesso') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(url('/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>">
    
    <link href="<?= htmlspecialchars(assetUrl('vendor.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('main.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('fonts/fonts.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php require __DIR__ . '/partials/auth-theme.php'; ?>
    <?php require __DIR__ . '/partials/theme-custom-css.php'; ?>
    <?php require __DIR__ . '/partials/custom-css.php'; ?>
    <?php require __DIR__ . '/../partials/custom-js.php'; ?>
</head>
<body class="auth-body">

<div class="auth-page">
  <div class="auth-wrap">
    <!-- Logo and Branding -->
    <header class="auth-brand">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>"
             class="auth-brand-logo">
      <?php else: ?>
        <div class="auth-brand-tile" aria-hidden="true"><i class="fas fa-book-open"></i></div>
      <?php endif; ?>
      <p class="auth-brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <!-- Login Form -->
    <main class="auth-card">
      <h1 class="auth-title"><?= __('Accedi') ?></h1>
      <p class="auth-subtitle"><?= __('Accedi al tuo account') ?></p>
      <form method="post" action="<?= htmlspecialchars($loginRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
        <?php if (!empty($return_url ?? '')): ?>
          <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string)$return_url, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>

        <?php if (isset($_GET['verified']) && $_GET['verified'] === '1'): ?>
          <div class="auth-alert auth-alert--success" role="alert">
            <div class="auth-alert-body">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <div>
                <?php if (isset($_GET['activated']) && $_GET['activated'] === '1'): ?>
                  <?= __('Email verificata con successo! Il tuo account è attivo: puoi accedere subito.') ?>
                <?php else: ?>
                  <?= __('Email verificata con successo! Il tuo account è ora in attesa di approvazione da parte dell\'amministratore. Riceverai un\'email quando sarà attivato.') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
          <div class="auth-alert auth-alert--error" role="alert">
            <div class="auth-alert-body">
              <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
              <div>
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
                <?php elseif ($_GET['error'] === 'private_mode'): ?>
                  <?= __('Questo sito è riservato agli utenti registrati. Accedi per continuare.') ?>
                <?php else: ?>
                  <?= __('Si è verificato un errore durante l\'accesso. Riprova') ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
          <div class="auth-alert auth-alert--success" role="alert">
            <div class="auth-alert-body">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
              <div>
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
          <label for="email" class="auth-label">
            <?= __('Email') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('mario.rossi@email.it'), ENT_QUOTES, 'UTF-8') ?>"
            value="<?php echo htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <span id="email-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <label for="password" class="auth-label">
            <?= __('Password') ?>
          </label>
          <input
            type="password"
            id="password"
            name="password"
            required aria-required="true"
            autocomplete="current-password"
            aria-describedby="password-error"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('••••••••'), ENT_QUOTES, 'UTF-8') ?>"
          />
          <span id="password-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div class="auth-row">
          <label for="remember_me" class="auth-check">
            <input id="remember_me" name="remember_me" type="checkbox" />
            <span><?= __('Ricordami') ?></span>
          </label>
          <a href="<?= htmlspecialchars($forgotPasswordRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
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
            class="auth-btn"
          >
            <?= __('Accedi') ?>
          </button>
        </div>
      </form>

      <div>
        <p class="auth-switch">
          <?= __('Non hai un account?') ?>
          <a href="<?= htmlspecialchars($registerRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
            <?= __('Registrati') ?>
          </a>
        </p>
      </div>
    </main>

    <!-- Footer Links -->
    <div class="auth-footer">
      <div class="auth-footer-links">
        <a href="<?= htmlspecialchars(route_path('privacy'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
          <?= __('Contatti') ?>
        </a>
      </div>
      <p class="auth-copy">
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
            fetch(<?= json_encode($loginRoute, JSON_HEX_TAG | JSON_HEX_AMP) ?>, {
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
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + <?= json_encode(__('Accesso in corso...'), JSON_HEX_TAG) ?>;
});
</script>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
