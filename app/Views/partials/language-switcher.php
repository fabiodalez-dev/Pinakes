<?php
/**
 * Language Switcher Component
 *
 * Displays a dropdown menu to switch between available languages.
 * Can be included in any layout (admin, user, frontend).
 *
 * Usage:
 *   <?php require_once __DIR__ . '/../partials/language-switcher.php'; ?>
 */

use App\Support\I18n;
use App\Support\HtmlHelper;

// Get available locales from I18n (loads from database)
$availableLocales = I18n::getAvailableLocales();
$currentLocale = I18n::getLocale();

// Get current language details from database
$currentLangName = $availableLocales[$currentLocale] ?? 'Italiano';

// Find current language flag (if using Language model)
$currentLangFlag = '🌐'; // Default
if (isset($db)) {
    try {
        $stmt = $db->prepare("SELECT flag_emoji FROM languages WHERE code = ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param('s', $currentLocale);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $currentLangFlag = $row['flag_emoji'];
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        // Fallback to default flag if query fails
    }
}

// Get all language details for dropdown
$languagesData = [];
if (isset($db)) {
    try {
        $result = $db->query("SELECT code, native_name, flag_emoji FROM languages WHERE is_active = 1 ORDER BY is_default DESC, code ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $languagesData[$row['code']] = [
                    'native_name' => $row['native_name'],
                    'flag_emoji' => $row['flag_emoji']
                ];
            }
        }
    } catch (Exception $e) {
        // Fallback to I18n locales only
        foreach ($availableLocales as $code => $name) {
            $languagesData[$code] = [
                'native_name' => $name,
                'flag_emoji' => '🌐'
            ];
        }
    }
} else {
    // No DB connection available, use I18n locales only
    foreach ($availableLocales as $code => $name) {
        $languagesData[$code] = [
            'native_name' => $name,
            'flag_emoji' => '🌐'
        ];
    }
}

// Don't show switcher if only one language available
if (count($languagesData) <= 1) {
    return;
}
?>

<!-- Language Switcher Dropdown -->
<div class="language-switcher relative inline-block">
    <button type="button"
            class="language-switcher-button flex items-center gap-2 px-3 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors"
            onclick="toggleLanguageDropdown()"
            aria-haspopup="true"
            aria-expanded="false">
        <span class="text-xl leading-none"><?= $currentLangFlag ?></span>
        <span class="font-medium"><?= HtmlHelper::e($currentLangName) ?></span>
        <i class="fas fa-chevron-down text-xs"></i>
    </button>

    <div class="language-switcher-dropdown hidden absolute right-0 mt-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50"
         role="menu"
         aria-orientation="vertical">
        <div class="py-1">
            <?php foreach ($languagesData as $code => $lang): ?>
                <?php $isActive = $code === $currentLocale; ?>
                <a href="/language/<?= urlencode($code) ?>"
                   class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors <?= $isActive ? 'bg-blue-50 border-l-4 border-blue-600' : '' ?>"
                   role="menuitem">
                    <span class="text-xl leading-none"><?= HtmlHelper::e($lang['flag_emoji']) ?></span>
                    <span class="flex-1 font-medium <?= $isActive ? 'text-blue-600' : '' ?>">
                        <?= HtmlHelper::e($lang['native_name']) ?>
                    </span>
                    <?php if ($isActive): ?>
                        <i class="fas fa-check text-blue-600"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Language Switcher JavaScript -->
<script>
function toggleLanguageDropdown() {
    const dropdown = document.querySelector('.language-switcher-dropdown');
    const button = document.querySelector('.language-switcher-button');

    const isHidden = dropdown.classList.contains('hidden');

    if (isHidden) {
        dropdown.classList.remove('hidden');
        button.setAttribute('aria-expanded', 'true');
    } else {
        dropdown.classList.add('hidden');
        button.setAttribute('aria-expanded', 'false');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const languageSwitcher = document.querySelector('.language-switcher');
    const dropdown = document.querySelector('.language-switcher-dropdown');
    const button = document.querySelector('.language-switcher-button');

    if (languageSwitcher && !languageSwitcher.contains(event.target)) {
        dropdown.classList.add('hidden');
        button.setAttribute('aria-expanded', 'false');
    }
});

// Close dropdown when pressing Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const dropdown = document.querySelector('.language-switcher-dropdown');
        const button = document.querySelector('.language-switcher-button');

        if (dropdown && !dropdown.classList.contains('hidden')) {
            dropdown.classList.add('hidden');
            button.setAttribute('aria-expanded', 'false');
        }
    }
});
</script>

<style>
/* Language Switcher Styles */
.language-switcher {
    position: relative;
    display: inline-block;
}

.language-switcher-dropdown {
    position: absolute;
    right: 0;
    margin-top: 0.5rem;
    min-width: 14rem;
    background-color: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    z-index: 50;
}

.language-switcher-dropdown.hidden {
    display: none;
}

.language-switcher-dropdown a {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    color: #374151;
    transition: background-color 0.15s ease-in-out;
}

.language-switcher-dropdown a:hover {
    background-color: #f3f4f6;
}

.language-switcher-dropdown a.active {
    background-color: #eff6ff;
    border-left: 4px solid #2563eb;
}

/* Responsive adjustments */
@media (max-width: 640px) {
    .language-switcher-dropdown {
        right: auto;
        left: 0;
    }
}
</style>
