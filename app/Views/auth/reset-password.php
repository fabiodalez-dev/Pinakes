<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogoPath = Branding::fullLogo();
$appLogo = $appLogoPath !== '' ? url($appLogoPath) : '';
$resetPasswordRoute = route_path('reset_password');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title><?= __('Resetta Password') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
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
      <?php if (!empty($appLogo)): ?>
        <img src="<?= htmlspecialchars($appLogo, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>"
             class="auth-brand-logo">
      <?php else: ?>
        <div class="auth-brand-tile" aria-hidden="true"><i class="fas fa-book-open"></i></div>
      <?php endif; ?>
      <p class="auth-brand-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <!-- Reset Password Form -->
    <main class="auth-card">
      <h1 class="auth-title"><?= __('Resetta Password') ?></h1>
      <p class="auth-subtitle"><?= __('Inserisci la tua nuova password') ?></p>
      <?php if (isset($_GET['error'])): ?>
        <div class="auth-alert auth-alert--error" role="alert">
          <div class="auth-alert-body">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
            <div>
              <?php if ($_GET['error'] === 'invalid_token'): ?>
                <?= __('Link di reset non valido o scaduto') ?>
              <?php elseif ($_GET['error'] === 'token_expired'): ?>
                <?= __('Questo link di reset è scaduto. Richiedi uno nuovo') ?>
              <?php elseif ($_GET['error'] === 'csrf'): ?>
                <?= __('Errore di sicurezza. Aggiorna la pagina e riprova') ?>
              <?php elseif ($_GET['error'] === 'password_mismatch'): ?>
                <?= __('Le password non coincidono') ?>
              <?php elseif ($_GET['error'] === 'weak_password'): ?>
                <?= __('La password deve contenere almeno 8 caratteri, lettere maiuscole, minuscole e numeri') ?>
              <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                <?= __('Compila tutti i campi richiesti') ?>
              <?php elseif ($_GET['error'] === 'password_too_short'): ?>
                <?= __('La password deve essere lunga almeno 8 caratteri.') ?>
              <?php elseif ($_GET['error'] === 'password_too_long'): ?>
                <?= __('La password non può superare i 72 caratteri.') ?>
              <?php elseif ($_GET['error'] === 'password_needs_upper_lower_number'): ?>
                <?= __('La password deve contenere maiuscole, minuscole e numeri.') ?>
              <?php else: ?>
                <?= __('Si è verificato un errore. Riprova') ?>
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
              <p><strong>
                <?= __('Password resettata con successo!') ?>
              </strong></p>
              <p>
                <?= __('Ora puoi accedere con la tua nuova password.') ?>
              </p>
              <div>
                <a href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
                  <?= __('Accedi') ?>
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!isset($_GET['success'])): ?>
        <form method="post" action="<?= htmlspecialchars($resetPasswordRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />

          <div>
            <label for="password" class="auth-label">
              <?= __('Nuova Password') ?>
            </label>
            <input
              type="password" autocomplete="new-password"
              id="password"
              name="password"
              required aria-required="true"
              aria-describedby="password-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('••••••••'), ENT_QUOTES, 'UTF-8') ?>"
              minlength="8"
            />
            <p class="auth-help">
              <?= __('Minimo 8 caratteri, con lettere maiuscole, minuscole e numeri') ?>
            </p>
            <span id="password-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>

          <div>
            <label for="password_confirm" class="auth-label">
              <?= __('Conferma Password') ?>
            </label>
            <input
              type="password" autocomplete="new-password"
              id="password_confirm"
              name="password_confirm"
              required aria-required="true"
              aria-describedby="password_confirm-error"
              class="auth-input"
              placeholder="<?= htmlspecialchars(__('••••••••'), ENT_QUOTES, 'UTF-8') ?>"
              minlength="8"
            />
            <span id="password_confirm-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
          </div>

          <!-- Password strength indicator -->
          <div id="password-strength" class="hidden">
            <div class="auth-strength">
              <div class="auth-strength-track">
                <div id="strength-bar" class="h-1 bg-red-500" style="width: 0%"></div>
              </div>
              <span id="strength-text" class="auth-help">-</span>
            </div>
          </div>

          <div>
            <button
              type="submit"
              class="auth-btn"
            >
              <?= __('Resetta Password') ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
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
  const passwordInput = document.getElementById('password');
  const passwordConfirmInput = document.getElementById('password_confirm');
  const strengthDiv = document.getElementById('password-strength');
  const strengthBar = document.getElementById('strength-bar');
  const strengthText = document.getElementById('strength-text');

  if (passwordInput) {
    const labels = {
      weak: <?= json_encode(__('Debole'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      medium: <?= json_encode(__('Media'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      strong: <?= json_encode(__('Forte'), JSON_HEX_TAG | JSON_HEX_AMP) ?>
    };
    passwordInput.addEventListener('input', function() {
      const password = this.value;
      let strength = 0;
      let strengthLabel = labels.weak;
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
        strengthLabel = labels.weak;
        color = 'bg-red-500';
      } else if (strength <= 4) {
        strengthLabel = labels.medium;
        color = 'bg-yellow-500';
      } else {
        strengthLabel = labels.strong;
        color = 'bg-green-500';
      }

      strengthDiv.classList.remove('hidden');
      strengthBar.style.width = (strength * 16.67) + '%';
      strengthBar.className = 'h-1 ' + color;
      strengthText.textContent = strengthLabel;
    });
  }
</script>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
