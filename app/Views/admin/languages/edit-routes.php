<?php
/**
 * Admin Edit Routes View
 *
 * Allows editing route translations for a specific language.
 */
/** @var array $language */
/** @var array $routes */

use App\Support\HtmlHelper;
?>

<div class="min-h-screen bg-gray-50 py-6 px-4">
    <div class="max-w-7xl mx-auto">
        <!-- Page Header -->
        <div class="mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                        <i class="fas fa-route text-blue-600"></i>
                        <?= __("Modifica Route Tradotte") ?>
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        <?= __("Lingua") ?>: <strong><?= HtmlHelper::e($language['native_name']) ?></strong> (<?= HtmlHelper::e($language['code']) ?>)
                    </p>
                </div>
                <a href="<?= htmlspecialchars(url('/admin/languages'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <?= __("Torna alle Lingue") ?>
                </a>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="mt-3 p-3 bg-green-50 text-green-800 border border-green-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_success']) ?>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="mt-3 p-3 bg-red-50 text-red-800 border border-red-200 rounded" role="alert">
                    <?= HtmlHelper::e($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <!-- Info Box -->
            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                    <div class="flex-1">
                        <h3 class="font-semibold text-blue-900 mb-2"><?= __("Personalizza Route URL") ?></h3>
                        <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                            <li><?= __("Ogni route deve iniziare con") ?> <code class="bg-blue-100 px-1 rounded">/</code></li>
                            <li><?= __("Non usare spazi nelle route") ?></li>
                            <li><?= __("Esempio route italiana:") ?> <code class="bg-blue-100 px-1 rounded">/catalogo</code>, <code class="bg-blue-100 px-1 rounded">/chi-siamo</code></li>
                            <li><?= __("Esempio route inglese:") ?> <code class="bg-blue-100 px-1 rounded">/catalog</code>, <code class="bg-blue-100 px-1 rounded">/about-us</code></li>
                            <li><?= __("Un backup viene creato automaticamente prima di salvare") ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Routes Form -->
        <form method="POST" action="<?= htmlspecialchars(url('/admin/languages/' . urlencode($language['code']) . '/update-routes'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="card">
                <div class="card-header flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <?= __("Route Tradotte") ?>
                        <span class="ml-2 text-sm font-normal text-gray-500">(<?= count($routes) ?>)</span>
                    </h2>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= __("Salva Route") ?>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/3">
                                        <?= __("Chiave Route") ?>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= __("Pattern URL") ?>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">
                                        <?= __("Azioni") ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($routes as $key => $pattern): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <code class="text-xs bg-gray-100 px-2 py-1 rounded font-mono">
                                                <?= HtmlHelper::e($key) ?>
                                            </code>
                                        </td>
                                        <td class="px-6 py-4">
                                            <input
                                                type="text"
                                                name="routes[<?= HtmlHelper::e($key) ?>]"
                                                value="<?= HtmlHelper::e($pattern) ?>"
                                                class="block w-full rounded border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm font-mono"
                                                placeholder="/<?= str_replace('_', '-', $key) ?>"
                                                required
                                                pattern="^/.*"
                                                title="<?= __("Deve iniziare con") ?> /">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button
                                                type="button"
                                                class="text-gray-600 hover:text-gray-900"
                                                onclick="resetRoute(this, <?= htmlspecialchars(json_encode($key), ENT_QUOTES, 'UTF-8') ?>)"
                                                title="<?= __("Ripristina Default") ?>">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer flex items-center justify-between">
                    <a href="<?= htmlspecialchars(url('/admin/languages'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                        <?= __("Annulla") ?>
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= __("Salva Route") ?>
                    </button>
                </div>
            </div>
        </form>

        <!-- Help Section -->
        <div class="mt-6 card">
            <div class="card-header">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-question-circle text-blue-600"></i>
                    <?= __("Guida alle Route") ?>
                </h3>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-700">
                    <div>
                        <h4 class="font-semibold mb-2"><?= __("Cosa sono le Route?") ?></h4>
                        <p class="mb-2">
                            <?= __("Le route sono gli URL usati nell'applicazione. Traducendole, puoi avere URL in italiano o inglese in base alla lingua dell'installazione.") ?>
                        </p>
                        <p class="text-xs text-gray-600">
                            <?= __("Esempio:") ?> <?= __("Installazione italiana usa") ?> <code class="bg-gray-100 px-1 rounded">/catalogo</code>,
                            <?= __("installazione inglese usa") ?> <code class="bg-gray-100 px-1 rounded">/catalog</code>
                        </p>
                    </div>
                    <div>
                        <h4 class="font-semibold mb-2"><?= __("Route Comuni") ?></h4>
                        <div class="space-y-1 text-xs">
                            <div class="grid grid-cols-2 gap-2">
                                <strong><?= __("Chiave") ?></strong>
                                <strong><?= __("Esempio") ?></strong>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <code class="bg-gray-100 px-1 rounded">catalog</code>
                                <code class="bg-gray-100 px-1 rounded">/catalogo</code>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <code class="bg-gray-100 px-1 rounded">book</code>
                                <code class="bg-gray-100 px-1 rounded">/libro <?= __('(percorso legacy)') ?></code>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <code class="bg-gray-100 px-1 rounded">login</code>
                                <code class="bg-gray-100 px-1 rounded">/accedi</code>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <code class="bg-gray-100 px-1 rounded">about</code>
                                <code class="bg-gray-100 px-1 rounded">/chi-siamo</code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Reset route to default fallback
function resetRoute(button, key) {
    const input = button.closest('tr').querySelector('input[type="text"]');
    if (!input) return;

    // Fallback patterns (English defaults)
    const fallbacks = {
        'login': '/login',
        'logout': '/logout',
        'register': '/register',
        'register_success': '/register/success',
        'verify_email': '/verify-email',
        'forgot_password': '/forgot-password',
        'reset_password': '/reset-password',
        'profile': '/profile',
        'profile_update': '/profile/update',
        'profile_password': '/profile/password',
        'user_dashboard': '/user/dashboard',
        'wishlist': '/wishlist',
        'reservations': '/reservations',
        'catalog': '/catalog',
        'catalog_legacy': '/catalog.php',
        'book': '/book',
        'book_legacy': '/book-detail.php',
        'author': '/author',
        'publisher': '/publisher',
        'genre': '/genre',
        'about': '/about-us',
        'contact': '/contact',
        'contact_submit': '/contact/submit',
        'privacy': '/privacy-policy',
        'cookies': '/cookie-policy',
        'api_catalog': '/api/catalog',
        'api_book': '/api/book',
        'api_home': '/api/home',
        'language_switch': '/language'
    };

    const fallback = fallbacks[key] || '/' + key.replace(/_/g, '-');
    input.value = fallback;

    // Highlight the change
    input.classList.add('bg-yellow-50', 'border-yellow-400');
    setTimeout(() => {
        input.classList.remove('bg-yellow-50', 'border-yellow-400');
    }, 1000);
}

// Client-side validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        const inputs = form.querySelectorAll('input[name^="routes["]');
        let hasErrors = false;

        for (const input of inputs) {
            const value = input.value.trim();

            // Check starts with /
            if (!value.startsWith('/')) {
                alert('<?= __("Tutte le route devono iniziare con") ?> "/" ');
                input.focus();
                e.preventDefault();
                hasErrors = true;
                break;
            }

            // Check no spaces
            if (/\s/.test(value)) {
                alert('<?= __("Le route non possono contenere spazi") ?>: ' + value);
                input.focus();
                e.preventDefault();
                hasErrors = true;
                break;
            }
        }

        if (!hasErrors) {
            // Show saving indicator
            const submitButtons = form.querySelectorAll('button[type="submit"]');
            submitButtons.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= __("Salvataggio...") ?>';
            });
        }
    });
});
</script>
