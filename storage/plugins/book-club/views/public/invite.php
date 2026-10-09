<?php
/**
 * Book Club — confirm an invitation. Following the e-mail link only shows
 * this page; joining is the POST of its button, so a link fetched by a mail
 * scanner or opened by accident changes nothing.
 *
 * @var array<string, mixed> $club
 * @var string $token
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = \App\Support\Csrf::ensureToken();
?>
<style>
  .bc-card{background:var(--white);border:1px solid var(--border-color);border-radius:2px;box-shadow:none;padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  .bc-section-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  .bc-section-header i{color:var(--primary-text, var(--primary-color));font-size:1.15rem}
  .bc-section-header h2,.bc-section-header h1{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.55rem 1.4rem;border-radius:2px;border:1.5px solid var(--button-color);background:var(--button-color);color:var(--button-text-color);font-weight:600;font-size:.9rem;cursor:pointer;text-decoration:none;transition:background-color .2s ease,border-color .2s ease,color .2s ease;white-space:nowrap;min-height:44px}
  .bc-btn:hover{background:var(--button-hover);border-color:var(--button-hover);color:var(--button-text-color)}
  .bc-btn-outline{background:transparent;color:var(--text-color);border:1px solid var(--border-color)}
  .bc-btn-outline:hover{border-color:var(--primary-color);color:var(--primary-text, var(--primary-color));background:transparent}
  .bc-muted{color:var(--text-light);font-size:.85rem}
</style>
<div class="container py-4">
  <section class="bc-card" style="max-width:640px;margin-left:auto;margin-right:auto;">
    <div class="bc-section-header">
      <i class="fas fa-envelope-open-text"></i>
      <h1><?= $e(__('Invito al club')) ?></h1>
    </div>
    <p><?= $e(sprintf(__('Sei stato invitato a unirti al club "%s".'), (string) $club['name'])) ?></p>
    <?php if (!empty($club['description'])): ?>
      <p class="bc-muted"><?= nl2br($e(mb_substr((string) $club['description'], 0, 600))) ?></p>
    <?php endif; ?>
    <div class="flex flex-wrap gap-2 mt-4">
      <form method="post" action="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/invite/' . $token)) ?>">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="bc-btn"><i class="fas fa-check"></i><?= $e(__("Accetta l'invito")) ?></button>
      </form>
      <a class="bc-btn bc-btn-outline" href="<?= $e(url(\App\Support\RouteTranslator::route('book_club'))) ?>"><?= $e(__('Non ora')) ?></a>
    </div>
  </section>
</div>
