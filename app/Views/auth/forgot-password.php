<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogoPath = Branding::fullLogo();
$appLogo = $appLogoPath !== '' ? url($appLogoPath) : '';
$forgotPasswordRoute = route_path('forgot_password');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title><?= __('Recupera Password') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
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

    <!-- Forgot Password Form -->
    <main class="auth-card">
      <h1 class="auth-title"><?= __('Recupera Password') ?></h1>
      <p class="auth-subtitle"><?= __('Inserisci la tua email per ricevere un link di reset') ?></p>
      <?php if (isset($_GET['error'])): ?>
        <div class="auth-alert auth-alert--error" role="alert">
          <div class="auth-alert-body">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
            <div>
              <?php if ($_GET['error'] === 'email_not_found'): ?>
                <?= __('Email non trovata nel nostro sistema') ?>
              <?php elseif ($_GET['error'] === 'csrf'): ?>
                <?= __('Errore di sicurezza. Aggiorna la pagina e riprova') ?>
              <?php elseif ($_GET['error'] === 'invalid_email'): ?>
                <?= __('Email non valida. Verifica il formato') ?>
              <?php elseif ($_GET['error'] === 'email_error'): ?>
                <?= __('Errore durante l\'invio dell\'email. Riprova più tardi') ?>
              <?php elseif ($_GET['error'] === 'rate_limit'): ?>
                <?= __('Troppi tentativi. Attendi qualche minuto prima di riprovare') ?>
              <?php else: ?>
                <?= __('Si è verificato un errore. Riprova') ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['sent'])): ?>
        <div class="auth-alert auth-alert--success" role="alert">
          <div class="auth-alert-body">
            <i class="fas fa-check-circle" aria-hidden="true"></i>
            <div>
              <p><strong>
                <?= __('Email di recupero inviata con successo!') ?>
              </strong></p>
              <p>
                <?= __('Controlla la tua casella di posta e clicca sul link per resettare la password. Il link sarà valido per 2 ore.') ?>
              </p>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= htmlspecialchars($forgotPasswordRoute, ENT_QUOTES, 'UTF-8') ?>" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />

        <div>
          <label for="email" class="auth-label">
            <?= __('Email associata al tuo account') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="auth-input"
            placeholder="<?= htmlspecialchars(__('mario.rossi@email.it'), ENT_QUOTES, 'UTF-8') ?>"
            value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <p class="auth-help">
            <?= __('Riceverai un link di reset via email. Il link sarà valido per 2 ore.') ?>
          </p>
          <span id="email-error" class="auth-field-error hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <button
            type="submit"
            class="auth-btn"
          >
            <?= __('Invia link di reset') ?>
          </button>
        </div>
      </form>

      <div>
        <p class="auth-switch">
          <?= __('Ricordi la password?') ?>
          <a href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>" class="auth-link">
            <?= __('Accedi') ?>
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

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
