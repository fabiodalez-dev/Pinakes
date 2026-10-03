<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogoPath = Branding::fullLogo();
$appLogo = $appLogoPath !== '' ? url($appLogoPath) : '';
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,follow">
    <title><?= __('Registrazione Completata') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(url('/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>">
    
    <link href="<?= htmlspecialchars(assetUrl('vendor.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('main.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('fonts/fonts.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php require __DIR__ . '/partials/auth-theme.php'; ?>
    <?php require __DIR__ . '/partials/custom-css.php'; ?>
</head>
<body class="auth-body">

<div class="auth-page">
  <div class="auth-wrap">
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

    <main class="auth-card">
      <h1 class="auth-title"><?= __('Conferma la tua email') ?></h1>
      <div>
        <div class="auth-success-icon" aria-hidden="true"><i class="fas fa-envelope-open-text"></i></div>
        <p class="auth-subtitle">
          <?= __('Ti abbiamo inviato un\'email con il link per confermare l\'indirizzo.') ?>
          <?= __('Dopo la conferma, un amministratore approverà la tua iscrizione.') ?>
        </p>
        <div>
          <a
            href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>"
            class="auth-btn"
          >
            <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
            <?= __('Vai al login') ?>
          </a>
        </div>
      </div>
    </main>

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
