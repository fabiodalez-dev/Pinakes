<?php
// Expects $content

use App\Support\ConfigStore;
use App\Support\HtmlHelper;

$appName = (string)ConfigStore::get('app.name', 'Pinakes');
$appLogo = (string)ConfigStore::get('app.logo', '');
if ($appLogo !== '') {
    $parsedLogoPath = parse_url($appLogo, PHP_URL_PATH) ?? $appLogo;
    $publicDir = realpath(dirname(__DIR__, 1) . '/../public');
    if ($publicDir !== false) {
        $absoluteLogoPath = realpath($publicDir . $parsedLogoPath) ?: ($publicDir . $parsedLogoPath);
        if (!is_file($absoluteLogoPath)) {
            $appLogo = '';
        }
    }
}
$appInitial = mb_strtoupper(mb_substr($appName, 0, 1));
?><!doctype html>
<html lang="it">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo HtmlHelper::e($appName); ?> - Sistema di Gestione Bibliotecaria</title>
    <meta name="csrf-token" content="<?php echo App\Support\Csrf::ensureToken(); ?>" />
    <link rel="stylesheet" href="/assets/vendor.css" />
    <link rel="stylesheet" href="/assets/flatpickr-custom.css" />
    <link rel="stylesheet" href="/assets/main.css" />
    <link rel="stylesheet" href="/assets/css/swal-theme.css" />
    <script>
      (function() {
        if (typeof window.__ !== 'function') {
          window.__ = function(message, ...args) {
            if (typeof message !== 'string') {
              return '';
            }
            if (!args.length) {
              return message;
            }
            let argIndex = 0;
            return message.replace(/%(\d+\$)?[sd]/g, function() {
              const value = args[argIndex++];
              return value !== undefined ? String(value) : '';
            });
          };
        }
        if (typeof window.__n !== 'function') {
          window.__n = function(singular, plural, count, ...args) {
            const base = count === 1 ? singular : plural;
            return window.__(base, ...args);
          };
        }
      })();
    </script>

  </head>
  <body class="bg-gray-50 text-gray-900 antialiased">
    <!-- Mobile Menu Overlay -->
    <div id="mobile-menu-overlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 lg:hidden hidden transition-opacity duration-300"></div>

    <div class="min-h-screen flex">
      <!-- Minimal White Sidebar -->
      <?php
        // Only show sidebar for admin/staff users
        $isAdminOrStaff = isset($_SESSION['user']['tipo_utente']) &&
                         ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff');
        $sidebarStyle = !$isAdminOrStaff ? 'display: none;' : '';
      ?>
      <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-50 w-72 lg:w-64 xl:w-72 bg-white border-r border-gray-200 shadow-lg transform -translate-x-full lg:translate-x-0 transition-all duration-300 ease-in-out flex flex-col" style="<?= $sidebarStyle ?>">

        <!-- Sidebar Header -->
        <div class="flex items-center justify-between px-6 py-5 flex-shrink-0">
          <a href="/" class="flex items-center space-x-3 hover:opacity-80 transition-opacity cursor-pointer">
            <?php if ($appLogo !== ''): ?>
              <img src="<?php echo HtmlHelper::e($appLogo); ?>" alt="<?php echo HtmlHelper::e($appName); ?>" class="w-10 h-10 object-contain">
            <?php else: ?>
              <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center">
                <span class="text-gray-700 font-semibold text-lg"><?php echo HtmlHelper::e($appInitial); ?></span>
              </div>
            <?php endif; ?>
            <div>
              <span class="font-bold text-xl text-gray-900"><?php echo HtmlHelper::e($appName); ?></span>
              <div class="text-xs text-gray-500 font-medium">Sistema di Gestione</div>
              <?php
              $versionFile = __DIR__ . '/../../version.json';
              $versionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : null;
              $version = $versionData['version'] ?? '0.1.0';
              ?>
              <div class="text-[10px] text-gray-400 font-mono mt-0.5">v<?php echo HtmlHelper::e($version); ?></div>
            </div>
          </a>

          <!-- Mobile Close Button -->
          <button id="close-mobile-menu" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition-colors">
            <i class="fas fa-times text-gray-500"></i>
          </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 px-4 py-6 pb-24 space-y-2 overflow-y-auto scrollbar-thin scrollbar-thumb-gray-300">

          <!-- Main Navigation -->
          <div class="space-y-1">
            <div class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= __("Menu Principale") ?></div>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/dashboard">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-tachometer-alt text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Dashboard") ?></div>
                <div class="text-xs text-gray-500"><?= __("Panoramica generale") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/libri">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-book text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Libri") ?></div>
                <div class="text-xs text-gray-500"><?= __("Gestione collezione") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/autori">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-user-edit text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Autori") ?></div>
                <div class="text-xs text-gray-500"><?= __("Gestione autori") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/editori">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-building text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Editori") ?></div>
                <div class="text-xs text-gray-500"><?= __("Case editrici") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/generi">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-tags text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Generi") ?></div>
                <div class="text-xs text-gray-500"><?= __("Generi e sottogeneri") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/prestiti">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-handshake text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Prestiti") ?></div>
                <div class="text-xs text-gray-500"><?= __("Gestione prestiti") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/collocazione">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-warehouse text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Collocazione") ?></div>
                <div class="text-xs text-gray-500"><?= __("Scaffali e mensole") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/utenti">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-users text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Utenti") ?></div>
                <div class="text-xs text-gray-500"><?= __("Gestione utenti") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/statistiche">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-chart-bar text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Statistiche") ?></div>
                <div class="text-xs text-gray-500"><?= __("Report e analisi") ?></div>
              </div>
            </a>

            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/recensioni">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-star text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Recensioni") ?></div>
                <div class="text-xs text-gray-500"><?= __("Gestione recensioni") ?></div>
              </div>
            </a>

            <?php if (isset($_SESSION['user']['tipo_utente']) && $_SESSION['user']['tipo_utente'] === 'admin'): ?>
            <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/plugins">
              <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                <i class="fas fa-puzzle-piece text-gray-600"></i>
              </div>
              <div class="ml-3">
                <div class="font-medium"><?= __("Plugin") ?></div>
                <div class="text-xs text-gray-500"><?= __("Estensioni") ?></div>
              </div>
            </a>
            <?php endif; ?>
          </div>

          <!-- System Configuration Section -->
          <?php if (isset($_SESSION['user']['tipo_utente']) && $_SESSION['user']['tipo_utente'] === 'admin'): ?>
          <div class="pt-6 mt-6 border-t border-gray-200">
            <div class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= __("Configurazione") ?></div>
            <div class="space-y-1 mt-3">
              <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/settings">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                  <i class="fas fa-cog text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium"><?= __("Impostazioni") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Configurazione sistema") ?></div>
                </div>
              </a>

              <a class="nav-link group flex items-center px-4 py-3 rounded-lg transition-all duration-200 hover:bg-gray-100 text-gray-700 hover:text-gray-900" href="/admin/languages">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-100 group-hover:bg-gray-200 transition-all duration-200">
                  <i class="fas fa-language text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium"><?= __("Lingue") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Traduzioni e localizzazione") ?></div>
                </div>
              </a>
            </div>
          </div>
          <?php endif; ?>

          <!-- Quick Actions Section -->
          <div class="pt-6 mt-6 border-t border-gray-200">
            <div class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= __("Azioni Rapide") ?></div>
            <div class="space-y-2 mt-3">
              <a href="/admin/libri/crea" class="group flex items-center px-4 py-3 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-all duration-200">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-200">
                  <i class="fas fa-plus text-sm text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium text-sm"><?= __("Nuovo Libro") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Aggiungi alla collezione") ?></div>
                </div>
              </a>

              <a href="/prestiti/crea" class="group flex items-center px-4 py-3 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-all duration-200">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-200">
                  <i class="fas fa-handshake text-sm text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium text-sm"><?= __("Nuovo Prestito") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Registra prestito") ?></div>
                </div>
              </a>

              <a href="/admin/loans/pending" class="group flex items-center px-4 py-3 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-all duration-200">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-200">
                  <i class="fas fa-clock text-sm text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium text-sm"><?= __("Approva Prestiti") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Richieste pendenti") ?></div>
                </div>
              </a>

              <a href="/admin/maintenance/integrity-report" class="group flex items-center px-4 py-3 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 transition-all duration-200">
                <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-gray-200">
                  <i class="fas fa-shield-alt text-sm text-gray-600"></i>
                </div>
                <div class="ml-3">
                  <div class="font-medium text-sm"><?= __("Manutenzione") ?></div>
                  <div class="text-xs text-gray-500"><?= __("Integrità dati") ?></div>
                </div>
              </a>
            </div>
          </div>

          <!-- Statistics Section -->
          <div class="pt-6 mt-6 border-t border-gray-200">
            <div class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= __("Statistiche Rapide") ?></div>
            <div class="grid grid-cols-2 gap-3 mt-3">
              <div class="p-3 rounded-lg bg-gray-100 border border-gray-200">
                <div class="text-2xl font-bold text-gray-900" id="stats-books">-</div>
                <div class="text-xs text-gray-600 font-medium"><?= __("Libri") ?></div>
              </div>
              <div class="p-3 rounded-lg bg-gray-100 border border-gray-200">
                <div class="text-2xl font-bold text-gray-900" id="stats-loans">-</div>
                <div class="text-xs text-gray-600 font-medium"><?= __("Prestiti") ?></div>
              </div>
            </div>
          </div>

        </nav>

        <!-- Sidebar Footer -->
        <div class="flex-shrink-0 p-4 border-t border-gray-200 bg-white">
          <div class="flex items-center space-x-3">
            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
              <i class="fas fa-user text-gray-600 text-sm"></i>
            </div>
            <div class="flex-1">
              <div class="text-sm font-medium text-gray-900"><?= __("Admin") ?></div>
              <div class="text-xs text-gray-500"><?= __("Sistema attivo") ?></div>
            </div>
            <a href="/admin/settings" class="p-3 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20" title="<?= __('Impostazioni') ?>">
              <i class="fas fa-cog text-lg text-gray-600 transform hover:rotate-12 transition-transform"></i>
            </a>
          </div>
        </div>
      </aside>

      <!-- Main Content -->
      <div class="flex-1 min-w-0">
        <!-- Enhanced Responsive Header -->
        <header class="sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
          <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 lg:h-20">

              <!-- Mobile Menu Button & Branding -->
              <div class="flex items-center gap-4 lg:hidden">
                <button id="mobile-menu-button" class="p-2 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20">
                  <i class="fas fa-bars text-xl text-gray-600"></i>
                </button>
                <div class="flex items-center space-x-3">
                  <?php if ($appLogo !== ''): ?>
                    <img src="<?php echo HtmlHelper::e($appLogo); ?>" alt="<?php echo HtmlHelper::e($appName); ?>" class="w-8 h-8 object-contain">
                  <?php else: ?>
                    <div class="w-8 h-8 bg-gray-900 rounded-xl flex items-center justify-center shadow-lg">
                      <span class="text-white text-sm font-semibold"><?php echo HtmlHelper::e($appInitial); ?></span>
                    </div>
                  <?php endif; ?>
                  <div class="hidden sm:block">
                    <span class="font-bold text-lg text-gray-900"><?php echo HtmlHelper::e($appName); ?></span>
                    <div class="text-xs text-gray-500"><?= __("Sistema") ?></div>
                  </div>
                </div>
              </div>

              <!-- Enhanced Global Search (Desktop) -->
              <div class="hidden lg:flex flex-1 max-w-2xl mx-4 lg:mx-8">
                <div class="relative group w-full">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none transition-opacity duration-200">
                    <div class="flex items-center space-x-2">
                      <i class="fas fa-search text-gray-400 group-focus-within:text-gray-600 transition-colors"></i>
                      <span class="hidden sm:inline text-xs text-gray-400 group-focus-within:text-gray-600 transition-colors"><?= __("Cerca libri, autori, editori, utenti...") ?></span>
                    </div>
                  </div>
                  <input type="text" id="global-search"
                         class="w-full pl-12 pr-4 py-3 lg:py-3.5 text-sm text-gray-800 bg-gray-50 border border-gray-300 rounded-2xl shadow-sm hover:shadow-md focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500/20 focus:bg-white transition-all duration-200 placeholder:text-gray-400"
                         autocomplete="off">

                  <!-- Search Results Dropdown -->
                  <div id="global-search-results" class="absolute z-50 w-full mt-2 bg-white border border-gray-200 rounded-2xl shadow-2xl hidden max-h-96 overflow-y-auto">
                    <!-- Results populated via JavaScript -->
                  </div>

                  <!-- Search Shortcuts (visible on focus) -->
                  <div class="absolute right-3 top-1/2 -translate-y-1/2 hidden lg:flex items-center gap-1 opacity-0 group-focus-within:opacity-100 transition-opacity">
                    <kbd class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-500 border border-gray-300 rounded">⌘</kbd>
                    <kbd class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-500 border border-gray-300 rounded">K</kbd>
                  </div>
                </div>
              </div>

              <!-- Enhanced Header Actions -->
              <div class="flex items-center gap-1 sm:gap-2">

                <!-- Mobile Search Button -->
                <button id="mobile-search-button" class="lg:hidden p-3 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20" title="<?= __("Cerca") ?>">
                  <i class="fas fa-search text-lg text-gray-600"></i>
                </button>

                <!-- Quick Stats (hidden on mobile) -->
                <div class="hidden xl:flex items-center gap-4 mr-4">
                  <div class="px-3 py-2 rounded-xl bg-gray-50 border border-gray-200">
                    <div class="text-sm font-bold text-gray-900" id="header-books-count">-</div>
                    <div class="text-xs text-gray-600"><?= __("Libri") ?></div>
                  </div>
                  <div class="px-3 py-2 rounded-xl bg-gray-50 border border-gray-200">
                    <div class="text-sm font-bold text-gray-900" id="header-loans-count">-</div>
                    <div class="text-xs text-gray-600"><?= __("Prestiti") ?></div>
                  </div>
                </div>

                <!-- Notifications with Enhanced Badge -->
                <div class="relative">
                  <button id="notifications-button" class="relative p-3 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20" title="Notifiche">
                    <i class="fas fa-bell text-lg text-gray-600"></i>
                    <span id="notifications-badge" class="hidden absolute -top-1 -right-1 w-6 h-6 rounded-full bg-red-500 text-white text-sm font-bold flex items-center justify-center shadow-lg ring-2 ring-white"></span>
                  </button>

                  <!-- Notifications Dropdown -->
                  <div id="notifications-dropdown" class="absolute right-0 mt-2 w-80 bg-white border border-gray-200 rounded-2xl shadow-2xl hidden z-50">
                    <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                      <h3 class="text-lg font-semibold text-gray-900"><?= __("Notifiche") ?></h3>
                      <button onclick="markAllNotificationsAsRead()" class="text-xs text-gray-900 hover:text-gray-700 font-medium">
                        <?= __("Segna tutte come lette") ?>
                      </button>
                    </div>
                    <div class="max-h-96 overflow-y-auto" id="notifications-list">
                      <div id="notifications-empty" class="p-8 text-center text-sm text-gray-500">
                        <i class="fas fa-bell-slash text-3xl mb-2 text-gray-300"></i>
                        <p><?= __("Nessuna notifica") ?></p>
                      </div>
                    </div>
                    <div class="p-4 border-t border-gray-200 flex items-center justify-between">
                      <a href="/admin/notifications" class="text-sm text-gray-900 hover:text-gray-700 font-medium">
                        <?= __("Vedi tutte le notifiche") ?>
                      </a>
                    </div>
                  </div>
                </div>

                <!-- Settings Button -->
                <a href="/admin/settings" class="p-3 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20" title="<?= __('Impostazioni') ?>">
                  <i class="fas fa-cog text-lg text-gray-600 transform hover:rotate-12 transition-transform"></i>
                </a>

                <!-- Enhanced User Menu / Public Login -->
                <div class="relative ml-2">
                  <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>
                  <?php if ($isLogged): ?>
                    <button id="user-menu-button" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-100 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500/20">
                      <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-gray-900 text-white shadow">
                        <i class="fas fa-user"></i>
                      </div>
                      <div class="hidden sm:block text-left">
                        <div class="text-sm font-medium text-gray-900"><?php echo \App\Support\HtmlHelper::safe((string)($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? 'Utente')); ?></div>
                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string)($_SESSION['user']['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                      <i class="fas fa-chevron-down text-sm text-gray-400 hidden sm:block transition-transform duration-200" id="user-menu-arrow"></i>
                    </button>
                    <div id="user-menu-dropdown" class="absolute right-0 mt-2 w-56 sm:w-56 w-48 bg-white border border-gray-200 rounded-2xl shadow-2xl hidden z-50 max-w-[calc(100vw-2rem)]">
                      <div class="p-2">
                        <a href="/profilo" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-700">
                          <i class="fas fa-user-cog w-4 h-4"></i>
                          <span class="text-sm"><?= __("Profilo") ?></span>
                        </a>
                        <a href="/wishlist" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-700">
                          <i class="fas fa-heart w-4 h-4"></i>
                          <span class="text-sm"><?= __("Preferiti") ?></span>
                        </a>
                        <a href="/admin/settings" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-700">
                          <i class="fas fa-cog w-4 h-4"></i>
                          <span class="text-sm"><?= __("Impostazioni") ?></span>
                        </a>
                        <?php if (($_SESSION['user']['tipo_utente'] ?? '') === 'admin'): ?>
                        <a href="/admin/security-logs" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-700">
                          <i class="fas fa-shield-alt w-4 h-4 text-red-600"></i>
                          <span class="text-sm">Log Sicurezza</span>
                        </a>
                        <?php endif; ?>
                        <hr class="my-2 border-gray-200">
                        <a href="/logout" class="flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-red-50 transition-colors text-red-600">
                          <i class="fas fa-sign-out-alt w-4 h-4"></i>
                          <span class="text-sm">Esci</span>
                        </a>
                      </div>
                    </div>
                  <?php else: ?>
                    <a href="/login" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-100 hidden sm:inline-flex items-center">
                      <i class="fas fa-sign-in-alt mr-2"></i> <?= __("Accedi") ?>
                    </a>
                    <a href="/register" class="px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 ml-2 hidden sm:inline-flex items-center">
                      <i class="fas fa-user-plus mr-2"></i> <?= __("Registrati") ?>
                    </a>
                    <div class="sm:hidden">
                      <a href="/login" class="p-2 rounded-xl hover:bg-gray-100"><i class="fas fa-sign-in-alt"></i></a>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Mobile Search Bar (Expandable) -->
          <div id="mobile-search-bar" class="lg:hidden border-t border-gray-200 bg-white hidden">
            <div class="px-4 py-3">
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                  <i class="fas fa-search text-gray-400"></i>
                </div>
                <input type="text" id="mobile-global-search" class="w-full pl-14 pr-12 py-3 text-sm text-gray-800 bg-gray-50 border border-gray-300 rounded-2xl focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500/20 focus:bg-white transition-all" placeholder="<?= __('Cerca libri, autori, editori, utenti...') ?>" autocomplete="off">
                <button id="mobile-search-close" class="absolute inset-y-0 right-0 pr-4 flex items-center">
                  <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                </button>

                <!-- Mobile Search Results -->
                <div id="mobile-search-results" class="absolute z-50 w-full mt-2 bg-white border border-gray-200 rounded-2xl shadow-2xl hidden max-h-96 overflow-y-auto">
                  <!-- Results populated via JavaScript -->
                </div>
              </div>
            </div>
          </div>
        </header>
        
        <!-- Page Content -->
        <main class="p-6">
          <?php if (isset($_SESSION['error_message'])): ?>
            <div class="mb-6 p-4 rounded-xl border border-red-200 bg-red-50 text-red-700" role="alert">
              <i class="fas fa-exclamation-circle mr-2"></i>
              <?php echo App\Support\HtmlHelper::e($_SESSION['error_message']); ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
          <?php endif; ?>
          <?php if (isset($_SESSION['success_message'])): ?>
            <div class="mb-6 p-4 rounded-xl border border-green-200 bg-green-50 text-green-700" role="alert">
              <i class="fas fa-check-circle mr-2"></i>
              <?php echo App\Support\HtmlHelper::e($_SESSION['success_message']); ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
          <?php endif; ?>
          <?php echo $content; ?>
        </main>
      </div>
    </div>
    
    <!-- Scripts -->
    <script src="/assets/vendor.bundle.js"></script>
    <script src="/assets/flatpickr-init.js"></script>
    <script src="/assets/main.bundle.js"></script>
    <script src="/assets/js/csrf-helper.js"></script>
    <script src="/assets/js/swal-config.js"></script>
    <script>
      // Global translations for JavaScript
      window.i18nTranslations = <?= json_encode([
        'Apri' => __('Apri'),
        'Segna tutte come lette' => __('Segna tutte come lette'),
        'Vedi tutte le notifiche' => __('Vedi tutte le notifiche'),
        'Nessuna notifica' => __('Nessuna notifica'),
      ], JSON_UNESCAPED_UNICODE) ?>;

      // Global __ function for JavaScript translations
      window.__ = function(key) {
        return window.i18nTranslations[key] || key;
      };

      // Modern library management system initialization
      document.addEventListener('DOMContentLoaded', function() {
        initializeGlobalSearch();
        initializeDarkMode();
        initializeMobileMenu();
        initializeActiveNavigation();
        initializeDropdowns();
        initializeKeyboardShortcuts();
        loadQuickStats();
        
        // Auto-refresh stats every 5 minutes
        setInterval(loadQuickStats, 5 * 60 * 1000);
        
      });

      // Global search with enhanced UI
      function initializeGlobalSearch() {
        const searchInput = document.getElementById('global-search');
        const resultsDiv = document.getElementById('global-search-results');
        let searchTimeout;

        if (searchInput && resultsDiv) {
          // Hide placeholder on focus/blur
          searchInput.addEventListener('focus', function() {
            const visualPlaceholder = document.querySelector('.absolute.inset-y-0.left-0.pl-4');
            visualPlaceholder.style.opacity = '0';
          });

          searchInput.addEventListener('blur', function() {
            const visualPlaceholder = document.querySelector('.absolute.inset-y-0.left-0.pl-4');
            if (this.value.trim().length === 0) {
              visualPlaceholder.style.opacity = '1';
            }
          });

          searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            // Hide/show visual placeholder based on input (fallback)
            const visualPlaceholder = document.querySelector('.absolute.inset-y-0.left-0.pl-4');
            if (query.length > 0) {
              visualPlaceholder.style.opacity = '0';
            } else {
              visualPlaceholder.style.opacity = '1';
            }
            
            if (query.length < 2) {
              resultsDiv.classList.add('hidden');
              return;
            }

            // Show loading state
            resultsDiv.innerHTML = '<div class="p-4 text-center"><i class="fas fa-spinner fa-spin text-gray-400"></i> <span class="ml-2 text-sm text-gray-500">Ricerca in corso...</span></div>';
            resultsDiv.classList.remove('hidden');

            searchTimeout = setTimeout(async () => {
              try {
                // Use unified search endpoint
                const response = await fetch(`/api/search/unified?q=${encodeURIComponent(query)}`);
                const results = await response.json();

                let html = '';
                
                if (results.length > 0) {
                  results.forEach(item => {
                    // Determine icon and color based on type
                    let iconClass = 'fas fa-question';
                    let iconColor = 'text-gray-500';
                    let identifierHtml = '';
                    
                    switch (item.type) {
                      case 'book':
                        iconClass = 'fas fa-book-open';
                        iconColor = 'text-blue-500';
                        if (item.identifier) {
                          identifierHtml = `<div class="text-xs text-gray-500 dark:text-gray-400 mt-1">${item.identifier}</div>`;
                        }
                        break;
                      case 'author':
                        iconClass = 'fas fa-user-edit';
                        iconColor = 'text-purple-500';
                        break;
                      case 'publisher':
                        iconClass = 'fas fa-building';
                        iconColor = 'text-orange-500';
                        break;
                      case 'user':
                        iconClass = 'fas fa-user';
                        iconColor = 'text-pink-500';
                        break;
                    }
                    
                    html += `<a href="${item.url}" class="flex items-start p-3 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg text-sm transition-colors">
                      <i class="${iconClass} ${iconColor} w-4 h-4 mr-3 mt-1"></i>
                      <div class="flex-1">
                        <div class="text-gray-800 dark:text-gray-200 font-medium">${item.label}</div>
                        ${identifierHtml}
                      </div>
                    </a>`;
                  });
                } else {
                  html = '<div class="p-4 text-center"><i class="fas fa-search text-gray-300 text-2xl mb-2"></i><div class="text-sm text-gray-500 dark:text-gray-400">Nessun risultato trovato per "<span class="font-medium">' + query + '</span>"</div></div>';
                }

                resultsDiv.innerHTML = html;
                resultsDiv.classList.remove('hidden');
              } catch (error) {
                console.error('Search error:', error);
                resultsDiv.innerHTML = '<div class="p-4 text-center text-red-500"><i class="fas fa-exclamation-triangle mr-2"></i>Errore durante la ricerca</div>';
              }
            }, 300);
          });

          // Enhanced click outside behavior
          document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
              resultsDiv.classList.add('hidden');
            }
          });

          // Enhanced keyboard navigation
          searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
              resultsDiv.classList.add('hidden');
              searchInput.blur();
            }
          });
        }
      }

      // Dark mode with persistence
      function initializeDarkMode() {
        // Check for saved theme or default to light mode
        const savedTheme = localStorage.getItem('theme') || 'light';
        if (savedTheme === 'dark') {
          document.documentElement.classList.add('dark');
        }
      }

      function toggleDarkMode() {
        if (document.documentElement.classList.contains('dark')) {
          document.documentElement.classList.remove('dark');
          localStorage.setItem('theme', 'light');
        } else {
          document.documentElement.classList.add('dark');
          localStorage.setItem('theme', 'dark');
        }
      }

      // Enhanced Mobile menu functionality
      function initializeMobileMenu() {
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const sidebar = document.getElementById('sidebar');
        
        function openMobileMenu() {
          sidebar.classList.remove('-translate-x-full');
          sidebar.classList.add('translate-x-0');
          mobileMenuOverlay.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');
        }
        
        function closeMobileMenu() {
          sidebar.classList.add('-translate-x-full');
          sidebar.classList.remove('translate-x-0');
          mobileMenuOverlay.classList.add('hidden');
          document.body.classList.remove('overflow-hidden');
        }
        
        if (mobileMenuButton) {
          mobileMenuButton.addEventListener('click', openMobileMenu);
        }
        
        if (closeMobileMenuButton) {
          closeMobileMenuButton.addEventListener('click', closeMobileMenu);
        }
        
        if (mobileMenuOverlay) {
          mobileMenuOverlay.addEventListener('click', closeMobileMenu);
        }
        
        // Close mobile menu on escape key
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') {
            closeMobileMenu();
          }
        });
        
        // Close mobile menu when clicking on nav links (mobile)
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
          link.addEventListener('click', () => {
            if (window.innerWidth < 1024) { // lg breakpoint
              setTimeout(closeMobileMenu, 150);
            }
          });
        });
      }

      // Initialize dropdown menus
      function initializeDropdowns() {
        // Notifications dropdown
        const notificationsButton = document.getElementById('notifications-button');
        const notificationsDropdown = document.getElementById('notifications-dropdown');
        const notificationsBadge = document.getElementById('notifications-badge');

        if (notificationsButton && notificationsDropdown) {
          notificationsButton.addEventListener('click', async function(e) {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('hidden');
            if (!notificationsDropdown.classList.contains('hidden')) {
              await loadNotifications();
            }
            // Close other dropdowns
            const userDropdown = document.getElementById('user-menu-dropdown');
            if (userDropdown) userDropdown.classList.add('hidden');
            const languageDropdown = document.getElementById('language-menu-dropdown');
            if (languageDropdown) languageDropdown.classList.add('hidden');
          });
        }

        // Load notification count on page load
        loadNotificationCount();
        
        // User menu dropdown
        const userMenuButton = document.getElementById('user-menu-button');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');
        const userMenuArrow = document.getElementById('user-menu-arrow');

        if (userMenuButton && userMenuDropdown) {
          userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('hidden');
            if (userMenuArrow) {
              userMenuArrow.classList.toggle('rotate-180');
            }
            // Close other dropdowns
            if (notificationsDropdown) notificationsDropdown.classList.add('hidden');
          });
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
          if (notificationsDropdown) notificationsDropdown.classList.add('hidden');
          if (userMenuDropdown) userMenuDropdown.classList.add('hidden');
          if (userMenuArrow) userMenuArrow.classList.remove('rotate-180');
        });

        // Mobile search functionality
        const mobileSearchButton = document.getElementById('mobile-search-button');
        const mobileSearchBar = document.getElementById('mobile-search-bar');
        const mobileSearchClose = document.getElementById('mobile-search-close');
        const mobileGlobalSearch = document.getElementById('mobile-global-search');
        const mobileSearchResults = document.getElementById('mobile-search-results');

        if (mobileSearchButton && mobileSearchBar) {
          mobileSearchButton.addEventListener('click', function() {
            mobileSearchBar.classList.remove('hidden');
            setTimeout(() => mobileGlobalSearch.focus(), 100);
          });
        }

        if (mobileSearchClose) {
          mobileSearchClose.addEventListener('click', function() {
            mobileSearchBar.classList.add('hidden');
            mobileGlobalSearch.value = '';
            if (mobileSearchResults) mobileSearchResults.classList.add('hidden');
          });
        }

        // Mobile search with same functionality as desktop
        if (mobileGlobalSearch && mobileSearchResults) {
          let mobileSearchTimeout;

          mobileGlobalSearch.addEventListener('input', function() {
            clearTimeout(mobileSearchTimeout);
            const query = this.value.trim();

            if (query.length < 2) {
              mobileSearchResults.classList.add('hidden');
              return;
            }

            // Show loading state
            mobileSearchResults.innerHTML = '<div class="p-4 text-center"><i class="fas fa-spinner fa-spin text-gray-400"></i> <span class="ml-2 text-sm text-gray-500">Ricerca in corso...</span></div>';
            mobileSearchResults.classList.remove('hidden');

            mobileSearchTimeout = setTimeout(async () => {
              try {
                const response = await fetch(`/api/search/unified?q=${encodeURIComponent(query)}`);
                const results = await response.json();

                let html = '';

                if (results.length > 0) {
                  results.forEach(item => {
                    let iconClass = 'fas fa-question';
                    let iconColor = 'text-gray-500';
                    let identifierHtml = '';

                    switch (item.type) {
                      case 'book':
                        iconClass = 'fas fa-book-open';
                        iconColor = 'text-blue-500';
                        if (item.identifier) {
                          identifierHtml = `<div class="text-xs text-gray-500 mt-1">${item.identifier}</div>`;
                        }
                        break;
                      case 'author':
                        iconClass = 'fas fa-user-edit';
                        iconColor = 'text-purple-500';
                        break;
                      case 'publisher':
                        iconClass = 'fas fa-building';
                        iconColor = 'text-orange-500';
                        break;
                      case 'user':
                        iconClass = 'fas fa-user';
                        iconColor = 'text-pink-500';
                        break;
                    }

                    html += `<a href="${item.url}" class="flex items-start gap-3 p-3 hover:bg-gray-50 rounded-lg text-sm transition-colors">
                      <div class="flex-shrink-0 w-5 flex items-center justify-center mt-1">
                        <i class="${iconClass} ${iconColor}"></i>
                      </div>
                      <div class="flex-1 min-w-0">
                        <div class="font-medium text-gray-900">${item.label || item.title}</div>
                        ${item.description ? `<div class="text-xs text-gray-500 mt-0.5">${item.description}</div>` : ''}
                        ${identifierHtml}
                      </div>
                    </a>`;
                  });
                } else {
                  html = '<div class="p-4 text-center text-sm text-gray-500">Nessun risultato trovato</div>';
                }

                mobileSearchResults.innerHTML = html;
              } catch (error) {
                console.error('Search error:', error);
                mobileSearchResults.innerHTML = '<div class="p-4 text-center text-sm text-red-500">Errore durante la ricerca</div>';
              }
            }, 300);
          });
        }
      }

      // Load notifications
      async function loadNotifications() {
        const list = document.getElementById('notifications-list');
        const empty = document.getElementById('notifications-empty');

        try {
          const response = await fetch('/admin/notifications/recent?limit=5');
          if (!response.ok) {
            throw new Error('Failed to load notifications');
          }

          const data = await response.json();
          const notifications = data.notifications || [];

          if (notifications.length === 0) {
            if (empty) empty.classList.remove('hidden');
            list.innerHTML = '';
          } else {
            if (empty) empty.classList.add('hidden');
            list.innerHTML = '';

            notifications.forEach(notif => {
              const item = document.createElement('div');
              item.className = 'p-4 transition-colors border-b border-gray-100 last:border-0';

              let iconClass = 'fas fa-bell';
              let iconBg = 'bg-gray-100 text-gray-600';

              switch (notif.type) {
                case 'new_message':
                  iconClass = 'fas fa-envelope';
                  iconBg = 'bg-blue-100 text-blue-600';
                  break;
                case 'new_reservation':
                  iconClass = 'fas fa-book';
                  iconBg = 'bg-green-100 text-green-600';
                  break;
                case 'new_user':
                  iconClass = 'fas fa-user-plus';
                  iconBg = 'bg-purple-100 text-purple-600';
                  break;
                case 'overdue_loan':
                  iconClass = 'fas fa-exclamation-triangle';
                  iconBg = 'bg-red-100 text-red-600';
                  break;
                case 'new_loan_request':
                  iconClass = 'fas fa-calendar-check';
                  iconBg = 'bg-orange-100 text-orange-600';
                  break;
                case 'new_review':
                  iconClass = 'fas fa-star';
                  iconBg = 'bg-yellow-100 text-yellow-600';
                  break;
              }

              const isUnread = !notif.is_read;
              const hasLink = Boolean(notif.link);
              const rawLink = hasLink ? String(notif.link) : '';
              const escapedLink = hasLink ? escapeHtml(rawLink) : '';

              if (hasLink) {
                item.classList.add('cursor-pointer', 'hover:bg-gray-50', 'group');
                item.dataset.link = rawLink;
                item.tabIndex = 0;
                item.setAttribute('role', 'link');
              } else {
                item.classList.add('bg-white');
              }

              item.innerHTML = `
                <div class="flex items-start gap-3">
                  <div class="${iconBg} w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="${iconClass}"></i>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs text-gray-500 font-medium uppercase tracking-wide mb-2">${formatNotificationTime(notif.created_at)}</div>
                    <p class="text-sm font-semibold text-gray-900 group-hover:text-gray-800 transition-colors">
                      ${escapeHtml(notif.title || '')}
                      ${isUnread ? '<span class="ml-1 inline-block w-2 h-2 bg-blue-500 rounded-full"></span>' : ''}
                    </p>
                    <p class="text-xs text-gray-600 mt-1 group-hover:text-gray-700 transition-colors">${escapeHtml(notif.message || '')}</p>
                    ${hasLink ? `
                      <div class="mt-3">
                        <button type="button" class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-semibold text-white bg-gray-900 rounded-lg shadow-sm hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500/40" data-open-link="${escapedLink}">
                          <i class="fas fa-external-link-alt text-[11px]"></i>
                          ${__('Apri')}
                        </button>
                      </div>
                    ` : ''}
                  </div>
                </div>
              `;

              if (hasLink) {
                const navigate = () => {
                  window.location.href = rawLink;
                };

                item.addEventListener('click', navigate);
                item.addEventListener('keydown', event => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    navigate();
                  }
                });

                const button = item.querySelector('[data-open-link]');
                if (button) {
                  button.addEventListener('click', event => {
                    event.stopPropagation();
                    navigate();
                  });
                }
              }

              list.appendChild(item);
            });
          }
        } catch (error) {
          console.error('Error loading notifications:', error);
          if (empty) empty.classList.remove('hidden');
          list.innerHTML = '';
        }
      }

      // Load notification count
      async function loadNotificationCount() {
        try {
          const response = await fetch('/admin/notifications/unread-count');
          if (response.ok) {
            const data = await response.json();
            const badge = document.getElementById('notifications-badge');
            if (badge) {
              const count = parseInt(data.count || 0, 10);
              if (count > 0) {
                badge.textContent = String(count);
                badge.classList.remove('hidden');
              } else {
                badge.classList.add('hidden');
              }
            }
          }
        } catch (error) {
          console.error('Error loading notification count:', error);
        }
      }

      // Mark all notifications as read
      async function markAllNotificationsAsRead() {
        try {
          const response = await csrfFetch('/admin/notifications/mark-all-read', { method: 'POST' });
          if (response.ok) {
            loadNotificationCount();
            loadNotifications();
          }
        } catch (error) {
          console.error('Error marking notifications as read:', error);
        }
      }

      // Format notification time
      function formatNotificationTime(dateString) {
        if (!dateString) {
          return '-';
        }

        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) {
          return '-';
        }

        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Adesso';
        if (diffMins < 60) return `${diffMins} minut${diffMins === 1 ? 'o' : 'i'} fa`;
        if (diffHours < 24) return `${diffHours} or${diffHours === 1 ? 'a' : 'e'} fa`;
        if (diffDays === 1) return 'Ieri';
        return date.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
      }

      // HTML escape helper
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      // Load quick statistics
      async function loadQuickStats() {
        // Check if user is logged in
        const isLogged = <?php echo !empty($_SESSION['user'] ?? null) ? 'true' : 'false'; ?>;
        if (!isLogged) {
          return; // Don't load stats if not authenticated
        }

        try {
          // Load books count
          const booksResponse = await fetch('/api/stats/books-count');
          if (booksResponse.ok) {
            const booksData = await booksResponse.json();
            const booksCount = booksData.count || 0;
            const booksEl = document.getElementById('stats-books');
            const headerBooksEl = document.getElementById('header-books-count');
            if (booksEl) booksEl.textContent = booksCount.toLocaleString();
            if (headerBooksEl) headerBooksEl.textContent = booksCount.toLocaleString();
          }

          // Load loans count
          const loansResponse = await fetch('/api/stats/active-loans-count');
          if (loansResponse.ok) {
            const loansData = await loansResponse.json();
            const loansCount = loansData.count || 0;
            const loansEl = document.getElementById('stats-loans');
            const headerLoansEl = document.getElementById('header-loans-count');
            if (loansEl) loansEl.textContent = loansCount.toLocaleString();
            if (headerLoansEl) headerLoansEl.textContent = loansCount.toLocaleString();
          }
        } catch (error) {
          console.error('Error loading quick stats:', error);
        }
      }

      // Keyboard shortcuts
      function initializeKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
          // Cmd/Ctrl + K to focus search
          if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            e.stopPropagation();
            const searchInput = document.getElementById('global-search');
            if (searchInput) {
              searchInput.focus();
              searchInput.select();
            }
          }

          // ESC to close all popups
          if (e.key === 'Escape') {
            // Close SweetAlert2 if open
            if (window.Swal && typeof window.Swal.close === 'function') {
              window.Swal.close();
            }

            // Close search results
            const searchResults = document.getElementById('global-search-results');
            if (searchResults && !searchResults.classList.contains('hidden')) {
              searchResults.classList.add('hidden');
            }

            const mobileSearchResults = document.getElementById('mobile-search-results');
            if (mobileSearchResults && !mobileSearchResults.classList.contains('hidden')) {
              mobileSearchResults.classList.add('hidden');
            }

            // Close mobile search bar
            const mobileSearchBar = document.getElementById('mobile-search-bar');
            if (mobileSearchBar && !mobileSearchBar.classList.contains('hidden')) {
              mobileSearchBar.classList.add('hidden');
            }

            // Close notifications dropdown
            const notificationsDropdown = document.getElementById('notifications-dropdown');
            if (notificationsDropdown && !notificationsDropdown.classList.contains('hidden')) {
              notificationsDropdown.classList.add('hidden');
            }

            // Close user menu dropdown
            const userMenuDropdown = document.getElementById('user-menu-dropdown');
            if (userMenuDropdown && !userMenuDropdown.classList.contains('hidden')) {
              userMenuDropdown.classList.add('hidden');
            }

            // Blur focused input/button
            if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'BUTTON')) {
              document.activeElement.blur();
            }
          }
        });
      }

      // Active navigation highlighting
      function initializeActiveNavigation() {
        const currentPath = window.location.pathname;
        const navLinks = document.querySelectorAll('.nav-link');
        
        navLinks.forEach(link => {
          const href = link.getAttribute('href');
          if (currentPath.startsWith(href) && href !== '/') {
            link.classList.add('bg-blue-50', 'dark:bg-blue-900/30', 'text-blue-600', 'dark:text-blue-400', 'border-r-2', 'border-blue-600');
            link.classList.remove('text-gray-700', 'dark:text-gray-300');
          }
        });
      }
    </script>
  </body>
  </html>
