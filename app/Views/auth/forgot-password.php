<?php
use App\Support\ConfigStore;
use App\Support\I18n;

$appName = (string)ConfigStore::get('app.name', 'Biblioteca');
$forgotPasswordRoute = route_path('forgot_password');
?>
<!DOCTYPE html>
<html lang="<?= substr(I18n::getLocale(), 0, 2) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('Recupera Password') ?> - <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath()) ?>;</script>
    <link rel="icon" type="image/x-icon" href="<?= url('/favicon.ico') ?>">

    <link href="<?= assetUrl('vendor.css') ?>" rel="stylesheet">
    <link href="<?= assetUrl('main.css') ?>" rel="stylesheet">
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
        <i class="fas fa-lock text-white text-3xl"></i>
      </div>
      <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2"><?= __('Recupera Password') ?></h1>
      <p class="text-gray-600 dark:text-gray-400"><?= __('Inserisci la tua email per ricevere un link di reset') ?></p>
    </div>

    <!-- Forgot Password Form -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-8 border border-gray-200 dark:border-gray-700">
      <?php if (isset($_GET['error'])): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 mb-6" role="alert">
          <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 dark:text-red-400 mr-3"></i>
            <div class="text-red-700 dark:text-red-300 text-sm">
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
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-4 mb-6" role="alert">
          <div class="flex">
            <div class="flex-shrink-0">
              <i class="fas fa-check-circle text-green-500 dark:text-green-400"></i>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-green-800 dark:text-green-200">
                <?= __('Email di recupero inviata con successo!') ?>
              </p>
              <p class="mt-2 text-sm text-green-700 dark:text-green-300">
                <?= __('Controlla la tua casella di posta e clicca sul link per resettare la password. Il link sarà valido per 2 ore.') ?>
              </p>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= htmlspecialchars($forgotPasswordRoute, ENT_QUOTES, 'UTF-8') ?>" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8'); ?>" />

        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            <?= __('Email associata al tuo account') ?>
          </label>
          <input
            type="email" autocomplete="email"
            id="email"
            name="email"
            required aria-required="true"
            aria-describedby="email-error"
            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-all duration-200"
            placeholder="<?= __('mario.rossi@email.it') ?>"
            value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          />
          <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            <?= __('Riceverai un link di reset via email. Il link sarà valido per 2 ore.') ?>
          </p>
          <span id="email-error" class="text-sm text-red-600 dark:text-red-400 mt-1 hidden" role="alert" aria-live="polite"></span>
        </div>

        <div>
          <button
            type="submit"
            class="w-full bg-gray-800 hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600 text-white font-medium py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5"
          >
            <?= __('Invia link di reset') ?>
          </button>
        </div>
      </form>

      <div class="mt-6 text-center">
        <p class="text-gray-600 dark:text-gray-400 text-sm">
          <?= __('Ricordi la password?') ?>
          <a href="<?= htmlspecialchars(route_path('login'), ENT_QUOTES, 'UTF-8') ?>" class="font-medium text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-300 transition-colors">
            <?= __('Accedi') ?>
          </a>
        </p>
      </div>
    </div>

    <!-- Footer Links -->
    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="<?= htmlspecialchars(route_path('privacy'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          <?= __('Privacy Policy') ?>
        </a>
        <a href="<?= htmlspecialchars(route_path('contact'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
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
