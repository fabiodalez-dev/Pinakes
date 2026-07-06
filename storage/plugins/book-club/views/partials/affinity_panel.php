<?php
/**
 * Book Club — affinity module sidebar teaser on the public club page:
 * members-only link to the affinity + suggestions page (the club page
 * itself stays clean, everything lives on the module page).
 *
 * @var array<string, mixed> $club
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-people-arrows"></i>
    <h2><?= $e(__('Affinità e suggerimenti')) ?></h2>
  </div>
  <p class="bc-muted mb-3">
    <?= $e(__('Scopri la tua affinità di lettura con gli altri membri e i suggerimenti dal catalogo.')) ?>
  </p>
  <a href="<?= $e(url('/book-club/' . $slug . '/affinity')) ?>" class="bc-btn w-100">
    <?= $e(__('Apri affinità e suggerimenti')) ?>
  </a>
</section>
