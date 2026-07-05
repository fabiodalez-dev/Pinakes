<?php
/**
 * Book Club — affinity module page (members/managers): privacy opt-in
 * toggle, the member's ranked affinity list (opted-in members only) and
 * the club suggestions (unread catalog books by top finished genres +
 * authors to rediscover).
 *
 * @var array<string, mixed> $club
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $optedIn
 * @var int $optedInCount
 * @var list<array{name: string, score: int|null, computed_at: string}> $myAffinities
 * @var list<array{id: int, nome: string, n: int}> $topGenres
 * @var list<array<string, mixed>> $suggestedBooks
 * @var list<array{id: int, nome: string, unread_count: int}> $similarAuthors
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <h1 class="text-2xl font-bold text-gray-900 flex items-center mt-4 mb-6">
    <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
    <?= $e(__('Affinità e suggerimenti')) ?> — <?= $e($club['name']) ?>
  </h1>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Section 1: reader affinity (opt-in) -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-people-arrows mr-2 text-gray-400"></i><?= $e(__('Affinità tra lettori')) ?></h2>
      <span class="text-xs text-gray-400"><?= $e(sprintf(__('Membri con condivisione attiva: %d'), (int) $optedInCount)) ?></span>
    </div>

    <p class="text-sm text-gray-500 mb-4">
      <i class="fas fa-lock mr-1 text-gray-300"></i>
      <?= $e(__('Le affinità sono calcolate solo tra i membri che hanno attivato la condivisione: chi non aderisce non compare mai negli elenchi degli altri.')) ?>
    </p>

    <?php if ($isMember): ?>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/affinity/optin')) ?>" class="flex flex-wrap items-center gap-3 mb-5 border rounded-lg px-4 py-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <span class="text-sm <?= $optedIn ? 'text-green-700' : 'text-gray-500' ?>">
          <i class="fas <?= $optedIn ? 'fa-toggle-on' : 'fa-toggle-off' ?> mr-1"></i>
          <?= $e($optedIn ? __('Condivisione attiva') : __('Condivisione disattivata')) ?>
        </span>
        <button type="submit"
                class="px-4 py-2 text-sm font-medium rounded-lg <?= $optedIn ? 'bg-gray-100 hover:bg-gray-200 text-gray-700' : 'bg-gray-900 hover:bg-gray-700 text-white' ?>">
          <?= $e($optedIn ? __('Disattiva condivisione') : __('Attiva condivisione')) ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if ($optedIn): ?>
      <?php if ($myAffinities === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Nessun altro membro ha attivato la condivisione, per ora.')) ?></p>
      <?php else: ?>
        <?php foreach ($myAffinities as $row): ?>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-56 text-sm text-gray-700 truncate shrink-0">
              <?= $e(sprintf(__('Affinità con %s'), (string) $row['name'])) ?>
            </div>
            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
              <?php if ($row['score'] !== null): ?>
                <div class="h-full rounded-full" style="width: <?= (int) $row['score'] ?>%; background: <?= $e($club['color']) ?>"></div>
              <?php endif; ?>
            </div>
            <div class="w-28 text-right text-sm font-medium <?= $row['score'] !== null ? 'text-gray-700' : 'text-gray-400' ?>">
              <?= $row['score'] !== null ? (int) $row['score'] . '%' : $e(__('dati insufficienti')) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php elseif ($isMember): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Attiva la condivisione per scoprire la tua affinità di lettura con gli altri membri.')) ?></p>
    <?php endif; ?>
  </section>

  <!-- Section 2: club suggestions -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-3"><i class="fas fa-lightbulb mr-2 text-gray-400"></i><?= $e(__('Suggerimenti per il club')) ?></h2>

    <?php if ($topGenres === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun suggerimento disponibile: il club non ha ancora concluso letture con un genere assegnato.')) ?></p>
    <?php else: ?>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <span class="text-xs text-gray-400 uppercase tracking-wide"><?= $e(__('Generi più letti dal club')) ?>:</span>
        <?php foreach ($topGenres as $genre): ?>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs text-white" style="background: <?= $e($club['color']) ?>">
            <?= $e($genre['nome']) ?> · <?= (int) $genre['n'] ?>
          </span>
        <?php endforeach; ?>
      </div>

      <?php if ($suggestedBooks === []): ?>
        <p class="text-sm text-gray-400 mb-4"><?= $e(__('Il club ha già letto tutti i libri in catalogo per i suoi generi preferiti.')) ?></p>
      <?php else: ?>
        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= $e(__('Libri dal catalogo che il club non ha ancora letto')) ?></h3>
        <ul class="divide-y divide-gray-100 mb-4">
          <?php foreach ($suggestedBooks as $book): ?>
            <li class="py-3 flex items-start gap-3">
              <?php if (!empty($book['copertina_url'])): ?>
                <img src="<?= $e($book['copertina_url']) ?>" alt="" class="w-10 h-14 object-cover rounded shadow-sm shrink-0" loading="lazy">
              <?php else: ?>
                <div class="w-10 h-14 bg-gray-100 rounded flex items-center justify-center shrink-0"><i class="fas fa-book text-gray-300"></i></div>
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 truncate"><?= $e($book['titolo']) ?></p>
                <p class="text-xs text-gray-500 truncate">
                  <?= $e((string) ($book['autori'] ?? '')) ?>
                  <?php if (!empty($book['anno_pubblicazione'])): ?> · <?= (int) $book['anno_pubblicazione'] ?><?php endif; ?>
                  · <?= $e($book['genere']) ?>
                </p>
              </div>
              <div class="text-xs text-gray-400 whitespace-nowrap shrink-0 pt-1">
                <?php if ($book['rating'] !== null): ?>
                  <i class="fas fa-star text-yellow-400"></i> <?= (int) $book['rating'] ?>/5
                <?php else: ?>
                  —
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="text-xs text-gray-400">
          <i class="fas fa-hand-point-right mr-1"></i><?= $e(__('Ti piace un titolo? Proponilo al club dalla pagina principale.')) ?>
          <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-blue-600 hover:underline"><?= $e(__('Vai alla pagina del club')) ?></a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Authors to rediscover -->
  <?php if ($similarAuthors !== []): ?>
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-1"><i class="fas fa-feather-alt mr-2 text-gray-400"></i><?= $e(__('Autori da riscoprire')) ?></h2>
      <p class="text-xs text-gray-400 mb-4"><?= $e(__('Autori dei libri conclusi con altri titoli in catalogo non ancora letti dal club.')) ?></p>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($similarAuthors as $author): ?>
          <li class="py-2 flex items-center justify-between gap-3">
            <span class="text-sm text-gray-700 truncate"><?= $e($author['nome']) ?></span>
            <span class="text-xs text-gray-400 whitespace-nowrap shrink-0">
              <?= $e(sprintf(__('%d libri non ancora letti dal club'), (int) $author['unread_count'])) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</div>
