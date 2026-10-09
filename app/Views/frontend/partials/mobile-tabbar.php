<?php
/**
 * Phone tab bar, as the Android app's bottom navigation: Home, Catalogue,
 * Loans and Favourites (signed-in readers, not in catalogue-only mode) and
 * the account. Hidden until the reader first scrolls, and out of the way once
 * the footer comes into view (public/assets/pinakes-2026.js). Phones only:
 * from 768px the header carries the same links.
 *
 * In scope (frontend/layout.php): $isHome, $navPathActive, $catalogRoute,
 * $reservationsRoute, $wishlistRoute, $profileRoute, $loginRoute,
 * $isCatalogueMode.
 *
 * @var bool $isHome
 * @var callable(string): bool $navPathActive
 * @var string $catalogRoute
 * @var string $reservationsRoute
 * @var string $wishlistRoute
 * @var string $profileRoute
 * @var string $loginRoute
 * @var bool $isCatalogueMode
 */

$pkTabLogged = !empty($_SESSION['user']['id']);
$pkTabRole = (string) ($_SESSION['user']['tipo_utente'] ?? '');
$pkTabs = [
    ['href' => '/', 'label' => __('Home'), 'icon' => 'fa-house', 'active' => $isHome],
    ['href' => $catalogRoute, 'label' => __('Catalogo'), 'icon' => 'fa-book-open', 'active' => $navPathActive((string) $catalogRoute)],
];
if ($pkTabLogged && !$isCatalogueMode) {
    $pkTabs[] = ['href' => $reservationsRoute, 'label' => __('Prestiti'), 'icon' => 'fa-bookmark', 'active' => $navPathActive((string) $reservationsRoute)];
    $pkTabs[] = ['href' => $wishlistRoute, 'label' => __('Preferiti'), 'icon' => 'fa-heart', 'active' => $navPathActive((string) $wishlistRoute), 'badge' => 'wish'];
}
if (!$pkTabLogged) {
    $pkTabs[] = ['href' => $loginRoute, 'label' => __('Accedi'), 'icon' => 'fa-right-to-bracket', 'active' => $navPathActive((string) $loginRoute)];
} elseif ($pkTabRole === 'admin' || $pkTabRole === 'staff') {
    // As the header menu: the back office is the account page of the staff.
    $pkTabs[] = ['href' => '/admin/dashboard', 'label' => $pkTabRole === 'admin' ? __('Admin') : __('Staff'), 'icon' => 'fa-user-shield', 'active' => false];
} else {
    $pkTabs[] = ['href' => $profileRoute, 'label' => __('Profilo'), 'icon' => 'fa-user', 'active' => $navPathActive((string) $profileRoute)];
}
?>
<nav class="pk-tabbar" data-pk-tabbar aria-label="<?= htmlspecialchars(__('Navigazione rapida'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true" inert>
    <ul class="pk-tabbar__list">
        <?php foreach ($pkTabs as $pkTab): ?>
            <li>
                <a class="pk-tabbar__item<?= $pkTab['active'] ? ' is-active' : '' ?>" href="<?= htmlspecialchars(absoluteUrl((string) $pkTab['href']), ENT_QUOTES, 'UTF-8') ?>"<?= $pkTab['active'] ? ' aria-current="page"' : '' ?>>
                    <span class="pk-tabbar__icon">
                        <i class="fas <?= htmlspecialchars($pkTab['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                        <?php if (($pkTab['badge'] ?? '') === 'wish'): ?>
                            <span class="pk-tabbar__badge" data-pk-wish-count hidden></span>
                        <?php endif; ?>
                    </span>
                    <span class="pk-tabbar__label"><?= htmlspecialchars($pkTab['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
