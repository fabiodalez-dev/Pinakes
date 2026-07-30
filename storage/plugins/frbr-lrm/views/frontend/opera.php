<?php
/**
 * Public Opera page — lists all available editions (Manifestations) of a Work.
 *
 * @var array<string, mixed> $opera
 * @var array<int, array<string, mixed>> $edizioni
 */
$placeholder = url('/uploads/copertine/placeholder.jpg');
?>
<div class="frbr-opera-page max-w-5xl mx-auto px-4 py-8">
  <header class="mb-8">
    <p class="text-sm uppercase tracking-wide text-gray-400 mb-1"><?= __("Opera") ?></p>
    <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars((string) $opera['titolo_uniforme'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($opera['titolo_originale'])): ?>
      <p class="text-gray-500 italic mt-1"><?= htmlspecialchars((string) $opera['titolo_originale'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p class="text-gray-600 mt-3">
      <?php if (!empty($opera['autore_nome'])): ?>
        <i class="fas fa-user mr-1"></i><?= htmlspecialchars((string) $opera['autore_nome'], ENT_QUOTES, 'UTF-8') ?>
      <?php endif; ?>
      <?php if (!empty($opera['lingua_originale'])): ?>
        &nbsp;·&nbsp;<?= htmlspecialchars((string) $opera['lingua_originale'], ENT_QUOTES, 'UTF-8') ?>
      <?php endif; ?>
    </p>
  </header>

  <h2 class="text-lg font-semibold text-gray-800 mb-4">
    <?= sprintf(__("%d edizioni disponibili"), count($edizioni)) ?>
  </h2>

  <?php if (empty($edizioni)): ?>
    <p class="text-gray-500"><?= __("Nessuna edizione disponibile per questa opera.") ?></p>
  <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-6">
      <?php foreach ($edizioni as $ed): ?>
        <?php
          $cover = trim((string) ($ed['copertina_url'] ?? ''));
          $coverUrl = $cover !== '' ? absoluteUrl($cover) : $placeholder;
          $bookUrl = book_url([
              'id' => (int) $ed['id'],
              'titolo' => (string) $ed['titolo'],
              'autore_principale' => (string) ($opera['autore_nome'] ?? ''),
          ]);
        ?>
        <a href="<?= htmlspecialchars($bookUrl, ENT_QUOTES, 'UTF-8') ?>" class="group block">
          <div class="aspect-[2/3] overflow-hidden rounded-lg bg-gray-100 shadow-sm group-hover:shadow-md transition-shadow">
            <img src="<?= htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $ed['titolo'], ENT_QUOTES, 'UTF-8') ?>" class="w-full h-full object-contain" loading="lazy">
          </div>
          <h3 class="mt-2 text-sm font-medium text-gray-900 leading-tight group-hover:text-primary line-clamp-2">
            <?= htmlspecialchars((string) $ed['titolo'], ENT_QUOTES, 'UTF-8') ?>
          </h3>
          <p class="text-xs text-gray-500 mt-0.5">
            <?php if (!empty($ed['anno_pubblicazione'])): ?><?= (int) $ed['anno_pubblicazione'] ?><?php endif; ?>
            <?php if (!empty($ed['editore'])): ?> · <?= htmlspecialchars((string) $ed['editore'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </p>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
