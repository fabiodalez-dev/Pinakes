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
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-sm font-semibold text-gray-400 uppercase mb-3"><?= $e(__('Affinità e suggerimenti')) ?></h2>
  <p class="text-sm text-gray-500 mb-3">
    <?= $e(__('Scopri la tua affinità di lettura con gli altri membri e i suggerimenti dal catalogo.')) ?>
  </p>
  <a href="<?= $e(url('/book-club/' . $slug . '/affinity')) ?>"
     class="block text-center w-full px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm font-medium rounded-lg">
    <?= $e(__('Apri affinità e suggerimenti')) ?>
  </a>
</section>
