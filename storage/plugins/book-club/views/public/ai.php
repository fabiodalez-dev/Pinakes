<?php
/**
 * Book Club — AI module page (club managers only): generate 5 discussion
 * questions for a club book or a structured summary of a meeting's minutes,
 * with copy-ready output and the history of previous generations.
 * When no API key is configured the page only explains how to enable the
 * module (link to the admin settings for Pinakes admins).
 *
 * @var array<string, mixed> $club
 * @var bool $configured
 * @var bool $isPinakesAdmin
 * @var string $model
 * @var list<array<string, mixed>> $books
 * @var list<array<string, mixed>> $meetings
 * @var list<array<string, mixed>> $outputs
 * @var int $recentCount
 * @var int $dailyCap
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$capReached = $recentCount >= $dailyCap;
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Assistente IA')) ?> — <?= $e($club['name']) ?>
    </h1>
    <?php if ($configured): ?>
      <span class="text-xs text-gray-400">
        <i class="fas fa-microchip mr-1"></i><?= $e($model) ?>
        · <?= $e(sprintf(__('%1$d/%2$d generazioni nelle ultime 24 ore'), (int) $recentCount, (int) $dailyCap)) ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$configured): ?>
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-2"><i class="fas fa-wand-magic-sparkles mr-2 text-gray-400"></i><?= $e(__('Modulo IA non configurato')) ?></h2>
      <p class="text-sm text-gray-500">
        <?= $e(__('Per usare l\'assistente IA (domande di discussione e riassunti dei verbali) l\'amministratore di Pinakes deve configurare una chiave API nelle impostazioni del plugin.')) ?>
      </p>
      <?php if ($isPinakesAdmin): ?>
        <a href="<?= $e(url('/admin/book-club/ai')) ?>" class="inline-block mt-4 px-4 py-2 text-sm bg-gray-900 hover:bg-gray-700 text-white rounded-lg">
          <i class="fas fa-cog mr-1"></i><?= $e(__('Apri le impostazioni IA')) ?>
        </a>
      <?php endif; ?>
    </section>
  <?php else: ?>

    <?php if ($capReached): ?>
      <div class="mb-6 px-4 py-3 rounded-lg text-sm bg-yellow-50 text-yellow-800">
        <i class="fas fa-hand mr-1"></i><?= $e(sprintf(__('Limite di sicurezza raggiunto: massimo %d generazioni IA per club nelle ultime 24 ore. Riprova più tardi.'), (int) $dailyCap)) ?>
      </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
      <!-- Discussion questions -->
      <section class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-2"><i class="fas fa-comments mr-2 text-gray-400"></i><?= $e(__('Domande di discussione')) ?></h2>
        <p class="text-xs text-gray-400 mb-4"><?= $e(__('Genera 5 domande aperte per l\'incontro, a partire da titolo, autori e descrizione del libro nel catalogo.')) ?></p>
        <?php if ($books === []): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Nessun libro nel club: proponi prima un libro.')) ?></p>
        <?php else: ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/ai/questions')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <label class="block text-xs font-medium text-gray-500 mb-1" for="ai-book"><?= $e(__('Libro del club')) ?></label>
            <select id="ai-book" name="club_book_id" required class="w-full border rounded-lg px-3 py-2 text-sm mb-3">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>">
                  <?= $e($book['titolo']) ?><?= (string) ($book['autori'] ?? '') !== '' ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" <?= $capReached ? 'disabled' : '' ?>
                    class="w-full px-4 py-2 text-sm rounded-lg text-white <?= $capReached ? 'bg-gray-300 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700' ?>">
              <i class="fas fa-wand-magic-sparkles mr-1"></i><?= $e(__('Genera 5 domande')) ?>
            </button>
          </form>
        <?php endif; ?>
      </section>

      <!-- Meeting minutes summary -->
      <section class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-2"><i class="fas fa-file-lines mr-2 text-gray-400"></i><?= $e(__('Riassunto del verbale')) ?></h2>
        <p class="text-xs text-gray-400 mb-4"><?= $e(__('Genera un riassunto strutturato (sintesi, decisioni prese, prossimi passi) dal verbale di un incontro.')) ?></p>
        <?php if ($meetings === []): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Nessun incontro con verbale: compila prima il verbale di un incontro.')) ?></p>
        <?php else: ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/ai/minutes')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <label class="block text-xs font-medium text-gray-500 mb-1" for="ai-meeting"><?= $e(__('Incontro con verbale')) ?></label>
            <select id="ai-meeting" name="meeting_id" required class="w-full border rounded-lg px-3 py-2 text-sm mb-3">
              <?php foreach ($meetings as $meeting): ?>
                <option value="<?= (int) $meeting['id'] ?>">
                  <?= $e($meeting['title']) ?> — <?= $e(date('d/m/Y', strtotime((string) $meeting['starts_at']) ?: 0)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" <?= $capReached ? 'disabled' : '' ?>
                    class="w-full px-4 py-2 text-sm rounded-lg text-white <?= $capReached ? 'bg-gray-300 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700' ?>">
              <i class="fas fa-wand-magic-sparkles mr-1"></i><?= $e(__('Genera riassunto')) ?>
            </button>
          </form>
        <?php endif; ?>
      </section>
    </div>

    <!-- History -->
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-clock-rotate-left mr-2 text-gray-400"></i><?= $e(__('Generazioni precedenti')) ?></h2>
      <?php if ($outputs === []): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Ancora nessuna generazione per questo club.')) ?></p>
      <?php endif; ?>
      <?php foreach ($outputs as $output): ?>
        <?php
          $isQuestions = (string) $output['kind'] === 'questions';
          $sourceTitle = $isQuestions
              ? (string) ($output['book_title'] ?? '')
              : (string) ($output['meeting_title'] ?? '');
          $creator = trim((string) ($output['creator_nome'] ?? '') . ' ' . (string) ($output['creator_cognome'] ?? ''));
          $domId = 'ai-output-' . (int) $output['id'];
        ?>
        <div class="border-t first:border-t-0 py-4">
          <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <div class="text-sm">
              <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $isQuestions ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700' ?>">
                <?= $e($isQuestions ? __('Domande di discussione') : __('Riassunto verbale')) ?>
              </span>
              <?php if ($sourceTitle !== ''): ?>
                <span class="font-medium text-gray-700 ml-1"><?= $e($sourceTitle) ?></span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-3">
              <span class="text-xs text-gray-400">
                <?= $e(date('d/m/Y H:i', strtotime((string) $output['created_at']) ?: 0)) ?>
                <?php if ($creator !== ''): ?> · <i class="far fa-user mr-0.5"></i><?= $e($creator) ?><?php endif; ?>
                <?php if ((string) $output['model'] !== ''): ?> · <?= $e($output['model']) ?><?php endif; ?>
              </span>
              <button type="button" data-copy-target="<?= $e($domId) ?>"
                      class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg js-ai-copy">
                <i class="far fa-copy mr-1"></i><?= $e(__('Copia')) ?>
              </button>
            </div>
          </div>
          <pre id="<?= $e($domId) ?>" class="whitespace-pre-wrap text-sm text-gray-700 bg-gray-50 border rounded-lg p-4 font-sans"><?= $e($output['content']) ?></pre>
        </div>
      <?php endforeach; ?>
    </section>

    <script>
      document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-ai-copy');
        if (!button) { return; }
        var target = document.getElementById(button.getAttribute('data-copy-target'));
        if (!target || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(target.textContent).then(function () {
          var original = button.innerHTML;
          button.innerHTML = '<i class="fas fa-check mr-1"></i><?= $e(__('Copiato!')) ?>';
          setTimeout(function () { button.innerHTML = original; }, 1500);
        });
      });
    </script>
  <?php endif; ?>
</div>
