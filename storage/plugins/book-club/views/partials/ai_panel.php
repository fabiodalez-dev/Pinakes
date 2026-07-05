<?php
/**
 * Book Club — club-page teaser of the AI module. Rendered ONLY for club
 * managers and ONLY when the Pinakes admin has configured an API key: a
 * small card linking to the generation page. The key itself never appears
 * anywhere in the markup.
 *
 * @var array<string, mixed> $club
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-wand-magic-sparkles mr-2 text-gray-400"></i><?= $e(__('Assistente IA')) ?></h2>
      <p class="text-xs text-gray-400 mt-1"><?= $e(__('Genera domande di discussione per un libro o il riassunto del verbale di un incontro.')) ?></p>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/ai')) ?>" class="text-sm text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Apri l\'assistente')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>
</section>
