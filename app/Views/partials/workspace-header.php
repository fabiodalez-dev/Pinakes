<?php
/**
 * Shared staff page anatomy. Slots contain trusted markup captured from each
 * view, whose data keeps its original escaping and whose controls keep their
 * existing IDs, routes, form names and event handlers.
 *
 * @var array{title:string,titleOpen?:string,context?:string,actions?:string,breadcrumb?:string,back?:string} $workspaceHeader
 */

$workspacePath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$workspacePath = substr($workspacePath, strlen(\App\Support\HtmlHelper::getBasePath()));
$workspaceSection = match (true) {
    preg_match('#^/admin/(settings|languages|cms|calendar)(?:/|$)#', $workspacePath) === 1 => __('Impostazioni'),
    preg_match('#^/admin/(books|authors|publishers|genres|series|placement|inventory|opere|espressioni)#', $workspacePath) === 1 => __('Catalogo'),
    preg_match('#^/admin/(loans|reservations|users)#', $workspacePath) === 1 => __('Circolazione'),
    str_starts_with($workspacePath, '/admin/periodicals') => __('Emeroteca'),
    str_starts_with($workspacePath, '/admin/archives') => __('Archivi'),
    str_starts_with($workspacePath, '/admin/book-club') => __('Club di lettura'),
    str_starts_with($workspacePath, '/admin/desiderata') => __('Acquisizioni'),
    str_starts_with($workspacePath, '/admin/plugins') => __('Estensioni'),
    default => __('Biblioteca'),
};
// A deterministic parent also works when a form was opened from a bookmark.
// Existing contextual back links take precedence and retain their destinations.
$workspaceBackPath = '/admin/dashboard';
$workspaceParentPaths = [
    '/admin/archives/authorities', '/admin/archives/activities', '/admin/archives/places',
    '/admin/periodicals/articles', '/admin/periodicals/subscriptions', '/admin/cms/events',
    '/admin/books', '/admin/authors', '/admin/publishers', '/admin/genres', '/admin/series',
    '/admin/users', '/admin/loans', '/admin/reservations', '/admin/archives',
    '/admin/periodicals', '/admin/book-club', '/admin/plugins', '/admin/languages',
    '/admin/opere', '/admin/espressioni', '/admin/themes', '/admin/inventory',
];
foreach ($workspaceParentPaths as $workspaceParentPath) {
    if (str_starts_with($workspacePath, $workspaceParentPath . '/')) {
        $workspaceBackPath = $workspaceParentPath;
        break;
    }
}
if (str_starts_with($workspacePath, '/admin/cms/') && $workspaceBackPath === '/admin/dashboard') {
    $workspaceBackPath = '/admin/settings';
}
?>
<header class="pk-workspace-header" data-workspace-header>
    <?php if ($workspacePath !== '/admin/dashboard' || trim($workspaceHeader['breadcrumb'] ?? '') !== ''): ?>
    <div class="pk-workspace-header__navigation">
    <?php if ($workspacePath !== '/admin/dashboard'): ?>
    <div class="pk-workspace-header__back" data-workspace-back>
        <?php if (trim($workspaceHeader['back'] ?? '') !== ''): ?>
            <?= $workspaceHeader['back'] ?>
        <?php else: ?>
            <a href="<?= htmlspecialchars(url($workspaceBackPath), ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-arrow-left" aria-hidden="true"></i><?= __('Indietro') ?>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="pk-workspace-header__breadcrumb">
        <?php if (trim($workspaceHeader['breadcrumb'] ?? '') !== ''): ?>
            <?= $workspaceHeader['breadcrumb'] ?>
        <?php else: ?>
            <nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
                <ol>
                    <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a></li>
                    <li aria-current="page"><?= htmlspecialchars(html_entity_decode(strip_tags($workspaceHeader['title']), ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                </ol>
            </nav>
        <?php endif; ?>
    </div>
    </div>
    <?php endif; ?>
    <div class="pk-workspace-header__row">
        <div class="pk-workspace-header__identity">
            <p class="pk-workspace-header__section"><?= htmlspecialchars($workspaceSection, ENT_QUOTES, 'UTF-8') ?></p>
            <?= $workspaceHeader['titleOpen'] ?? '<h1 class="pk-workspace-header__title">' ?><?= $workspaceHeader['title'] ?></h1>
            <?php if (trim($workspaceHeader['context'] ?? '') !== ''): ?>
            <div class="pk-workspace-header__context"><?= $workspaceHeader['context'] ?></div>
            <?php endif; ?>
        </div>
        <?php if (trim(strip_tags($workspaceHeader['actions'] ?? '')) !== '' || preg_match('/<(a|button|details|input|select)\b/i', $workspaceHeader['actions'] ?? '') === 1): ?>
        <div class="pk-workspace-header__actions" data-workspace-actions><?= $workspaceHeader['actions'] ?></div>
        <?php endif; ?>
    </div>
</header>
