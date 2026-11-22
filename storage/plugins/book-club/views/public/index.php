<?php
/**
 * Book Club — public directory of clubs.
 *
 * @var list<array<string, mixed>> $clubs
 * @var array<int, string> $mine  club_id → member_status for the logged-in user
 * @var bool $loggedIn
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$privacyLabels = [
    'public' => __('Pubblico'),
    'private' => __('Privato'),
    'invite' => __('Su invito'),
];
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$heroTitle = __('Club di lettura');
$heroSubtitle = __('Leggi insieme: proposte, votazioni e incontri.');
$breadcrumbItems = [['label' => __('Home'), 'href' => url('/')], ['label' => __('Club di lettura')]];
include $corePartials . '/catalog-hero.php';
?>
<?php $bookClubStyles = true; ?>
<style<?= csp_nonce_attr() ?>>
  /* Page-local helpers (directory page only). */
  .bc-club-card{display:block;position:relative;overflow:hidden;height:100%;color:inherit;text-decoration:none;transition:border-color .2s ease}
  .bc-club-card:hover,.bc-club-card:focus-visible{border-color:var(--primary-color);color:inherit}
  .bc-club-card h2{font-size:1.1rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-club-accent{position:absolute;top:0;left:0;right:0;height:3px}
  .bc-toolbar{display:flex;justify-content:flex-end;margin-bottom:1.5rem}
</style>
<div class="container py-4">
  <?php if ($loggedIn): ?>
    <div class="bc-toolbar">
      <a href="<?= $e(url('/my/book-clubs')) ?>" class="bc-btn">
        <i class="fas fa-book-reader" aria-hidden="true"></i><?= $e(__('I miei club')) ?>
      </a>
    </div>
  <?php endif; ?>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($clubs)): ?>
    <?php
    $emptyIcon = 'fa-book-open';
    $emptyTitle = __('Nessun club di lettura attivo al momento.');
    include $corePartials . '/empty-state.php';
    ?>
  <?php endif; ?>

  <div class="flex flex-wrap -mx-3 gap-y-4">
    <?php foreach ($clubs as $club): ?>
      <div class="w-full px-3 md:w-1/2 lg:w-1/3">
        <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $club['slug'])) ?>" class="bc-card bc-club-card mb-0">
          <span class="bc-club-accent" style="background: <?= $e($club['color']) ?>"></span>
          <div class="flex items-center justify-between gap-2 mb-2">
            <h2><?= $e($club['name']) ?></h2>
            <?php if (isset($mine[(int) $club['id']])): ?>
              <span class="bc-badge <?= $mine[(int) $club['id']] === 'active' ? 'bc-badge-open' : 'bc-badge-warn' ?>">
                <?= $mine[(int) $club['id']] === 'active' ? $e(__('Membro')) : $e(__('In attesa')) ?>
              </span>
            <?php endif; ?>
          </div>
          <p class="bc-muted"><?= $e(mb_substr((string) ($club['description'] ?? ''), 0, 180)) ?></p>
          <div class="flex items-center gap-3 bc-muted text-sm mt-3">
            <span><i class="fas fa-users mr-1"></i><?= (int) $club['member_count'] ?><?= $club['max_members'] !== null ? '/' . (int) $club['max_members'] : '' ?></span>
            <span><i class="fas fa-lock mr-1"></i><?= $e($privacyLabels[$club['privacy']] ?? $club['privacy']) ?></span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
