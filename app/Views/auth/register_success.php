<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrazione Completata - Biblioteca</title>
    
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
        <i class="fas fa-book-open text-white text-3xl"></i>
      </div>
      <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2"><?= htmlspecialchars($appName ?? 'Biblioteca', ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-gray-600 dark:text-gray-400"><?= __('Registrazione completata') ?></p>
    </div>

    <!-- Success Message -->
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

    <!-- Footer Links -->
    <div class="mt-8 text-center">
      <div class="flex justify-center space-x-6 text-sm">
        <a href="#" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          Privacy Policy
        </a>
        <a href="#" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          Termini di Servizio
        </a>
        <a href="#" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
          Contatti
        </a>
      </div>
      <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
        &copy; 2024 Biblioteca. Tutti i diritti riservati.
      </p>
    </div>
  </div>
</div>

</body>
</html>
