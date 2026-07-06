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
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <div class="bc-section-header mb-1">
        <i class="fas fa-wand-magic-sparkles"></i>
        <h2><?= $e(__('Assistente IA')) ?></h2>
      </div>
      <p class="bc-muted mb-0"><?= $e(__('Genera domande di discussione per un libro o il riassunto del verbale di un incontro.')) ?></p>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/ai')) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Apri l\'assistente')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>
</section>
