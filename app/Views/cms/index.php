<?php
/**
 * Index of the site content an administrator can edit.
 *
 * @var list<array{slug: string, title: string, is_active: int|string, updated_at: string|null}> $pages
 * @var string $currentLocale
 */


// The two fixed destinations. Everything else on this page comes from the
// database, so a page nobody thought to add to a menu is still reachable.
// Full class strings, never interpolated: Tailwind is purged against the
// sources, so a class assembled at runtime has no rule in the stylesheet.
$fixedCards = [
    [
        'icon' => 'fas fa-home text-blue-600',
        'iconWrap' => 'w-10 h-10 rounded-xl bg-blue-100 flex items-center justify-center',
        'heading' => __('Homepage'),
        'description' => __('Modifica i contenuti della homepage: hero, features, CTA e immagine di sfondo'),
        'admin' => url('/admin/cms/home'),
        'live' => url('/'),
        'action' => __('Modifica Homepage'),
    ],
    [
        'icon' => 'fas fa-calendar-alt text-purple-600',
        'iconWrap' => 'w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center',
        'heading' => __('Eventi'),
        'description' => __('Gestisci gli eventi della biblioteca: crea, modifica ed elimina eventi con immagini e descrizioni'),
        'admin' => url('/admin/cms/events'),
        'live' => route_path('events'),
        'action' => __('Gestisci Eventi'),
    ],
];
?>

<div class="max-w-7xl mx-auto py-6 px-4">
  <div class="mb-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-file-lines text-blue-600"></i>
          <?= __("Contenuti del sito") ?>
        </h1>
        <p class="mt-1 text-sm text-gray-600">
          <?= __("Homepage, pagine e eventi del sito pubblico") ?>
        </p>
      </div>
      <a href="<?= htmlspecialchars(url('/admin/settings?tab=cms'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors">
        <i class="fas fa-arrow-left"></i>
        <?= __("Torna alle Impostazioni") ?>
      </a>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <?php foreach ($fixedCards as $card): ?>
      <div class="bg-gray-50 border border-gray-200 rounded-2xl p-6 hover:border-gray-300 transition-colors">
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
              <div class="<?= htmlspecialchars($card['iconWrap'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="<?= htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
              </div>
              <h3 class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($card['heading'], ENT_QUOTES, 'UTF-8') ?></h3>
            </div>
            <p class="text-sm text-gray-600"><?= htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">
              <i class="fas fa-link"></i>
              <a href="<?= htmlspecialchars($card['live'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="hover:text-gray-900 underline"><?= __("Visualizza pagina live") ?></a>
            </div>
          </div>
        </div>
        <div class="mt-4">
          <a href="<?= htmlspecialchars($card['admin'], ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors w-full justify-center">
            <i class="fas fa-edit"></i>
            <?= htmlspecialchars($card['action'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-3xl shadow-xl border border-gray-200 mt-6">
    <div class="border-b border-gray-200 px-6 py-4">
      <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
        <i class="fas fa-file-alt text-green-600"></i>
        <?= __("Pagine") ?>
      </h2>
      <p class="text-sm text-gray-600 mt-1">
        <?= sprintf(__('Pagine di contenuto nella lingua attiva (%s)'), htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8')) ?>
      </p>
    </div>
    <div class="p-6">
      <?php if ($pages === []): ?>
        <p class="text-sm text-gray-600"><?= __("Nessuna pagina di contenuto in questa lingua.") ?></p>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($pages as $page): ?>
            <li class="bg-gray-50 rounded-xl p-4 border border-gray-200">
              <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                  <span class="font-medium text-gray-900"><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="text-xs text-gray-500 bg-gray-200 px-2 py-1 rounded">/<?= htmlspecialchars((string) $page['slug'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php if (empty($page['is_active'])): ?>
                    <span class="text-xs text-gray-600 bg-gray-200 px-2 py-1 rounded"><?= __("Non visibile") ?></span>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                  <a href="<?= htmlspecialchars(url('/' . $page['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="text-xs text-gray-500 hover:text-gray-900 underline">
                    <?= __("Visualizza pagina live") ?>
                  </a>
                  <a href="<?= htmlspecialchars(url('/admin/cms/' . $page['slug']), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 transition-colors">
                    <i class="fas fa-edit"></i>
                    <?= __("Modifica") ?>
                  </a>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
