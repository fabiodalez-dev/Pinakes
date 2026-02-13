<?php
use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$appLogo = Branding::fullLogo();
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Registrazione Completata') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <link href="/assets/vendor.css" rel="stylesheet">
    <link href="/assets/main.css" rel="stylesheet">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900">

<div class="min-h-screen bg-gray-50 dark:bg-gray-900 py-12 px-4 sm:px-6 lg:px-8">
  <div class="max-w-md w-full mx-auto">
    <div class="text-center mb-10">
      <?php if ($appLogo): ?>
        <div class="mx-auto mb-6 flex items-center justify-center">
          <img src="<?= htmlspecialchars($appLogo, ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>"
               class="h-20 w-auto object-contain">
        </div>
      <?php else: ?>
        <div class="w-20 h-20 bg-gray-800 dark:bg-gray-700 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-xl">
          <i class="fas fa-book-open text-white text-3xl"></i>
        </div>
      <?php endif; ?>
      <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-gray-600 dark:text-gray-400"><?= __('Registrazione completata') ?></p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 border border-gray-200 dark:border-gray-700">
      <div class="text-center">
        <div class="w-16 h-16 bg-green-100 dark:bg-green-900/20 rounded-full flex items-center justify-center mx-auto mb-6">
          <i class="fas fa-envelope-open-text text-green-600 dark:text-green-400 text-2xl"></i>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-4"><?= __('Conferma la tua email') ?></h2>
        <p class="text-gray-600 dark:text-gray-400 mb-6">
          <?= __('Ti abbiamo inviato un\'email con il link per confermare l\'indirizzo.') ?>
          <?= __('Dopo la conferma, un amministratore approverà la tua iscrizione.') ?>
        </p>
        <div class="space-y-4">
          <a
            href="<?= route_path('login') ?>"
            class="w-full bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white font-medium py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 inline-flex items-center justify-center"
          >
            <i class="fas fa-sign-in-alt mr-2"></i>
            <?= __('Vai al login') ?>
          </a>
        </div>
      </div>
    </div>

    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="<?= route_path('privacy') ?>" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= route_path('contact') ?>" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          <?= __('Contatti') ?>
        </a>
      </div>
      <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>. <?= __('Tutti i diritti riservati.') ?>
      </p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../partials/cookie-banner.php'; ?>

</body>
</html>
