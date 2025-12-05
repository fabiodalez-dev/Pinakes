<?php
use App\Support\HtmlHelper;

/**
 * @var array $data { autore: array, libri: array }
 */
$autore = $data['autore'];
$libri = $data['libri'];
$title = __("Scheda Autore:") . " " . ($autore['nome'] ?? 'N/D');

$hasBooks = !empty($libri);
$totalBooks = count($libri);
$nomeAutore = HtmlHelper::e($autore['nome'] ?? 'Autore sconosciuto');
$pseudonimo = trim((string)($autore['pseudonimo'] ?? ''));
$dataNascita = trim((string)($autore['data_nascita'] ?? ''));
$dataMorte   = trim((string)($autore['data_morte'] ?? ''));
$nazionalita = trim((string)($autore['nazionalita'] ?? ''));
$sitoWeb     = trim((string)($autore['sito_web'] ?? ''));
$biografia   = trim((string)($autore['biografia'] ?? ''));
$createdAt   = trim((string)($autore['created_at'] ?? ''));
$updatedAt   = trim((string)($autore['updated_at'] ?? ''));
?>
<section class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-6">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="/admin/autori" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-user-edit mr-1"></i>Autori
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium truncate max-w-[12rem] sm:max-w-xs">
          <?php echo $nomeAutore; ?>
        </li>
      </ol>
    </nav>

    <!-- Hero -->
    <div class="relative overflow-hidden rounded-3xl bg-white border border-gray-200 shadow-xl mb-8">
      <div class="relative p-8 sm:p-10 lg:p-12">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 p-4">
          <div>
            <div class="text-sm uppercase tracking-widest text-gray-900 mb-2 flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-gray-400"></span>
              Profilo Autore
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight flex flex-wrap items-center gap-3">
              <?php echo $nomeAutore; ?>
              <?php if ($pseudonimo !== ''): ?>
                <span class="text-gray-900 text-lg italic">"<?php echo HtmlHelper::e($pseudonimo); ?>"</span>
              <?php endif; ?>
            </h1>
            <?php if ($dataNascita || $dataMorte || $nazionalita): ?>
              <p class="mt-3 text-gray-900 text-sm sm:text-base flex flex-wrap gap-4">
                <?php if ($dataNascita): ?>
                  <span><i class="fas fa-birthday-cake mr-2"></i><?= sprintf(__("Nato il %s"), HtmlHelper::e($dataNascita)) ?></span>
                <?php endif; ?>
                <?php if ($dataMorte): ?>
                  <span><i class="fas fa-book-dead mr-2"></i><?= sprintf(__("Deceduto il %s"), HtmlHelper::e($dataMorte)) ?></span>
                <?php endif; ?>
                <?php if ($nazionalita): ?>
                  <span><i class="fas fa-flag mr-2"></i><?php echo HtmlHelper::e($nazionalita); ?></span>
                <?php endif; ?>
              </p>
            <?php endif; ?>
          </div>

          <div class="flex flex-wrap items-center gap-3">
            <a href="/admin/autori/modifica/<?php echo (int)($autore['id'] ?? 0); ?>"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-medium hover:bg-gray-800 transition-colors">
              <i class="fas fa-pen"></i>
              Modifica
            </a>
            <a href="/admin/libri/crea"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium transition-colors">
              <i class="fas fa-plus"></i>
              Nuovo Libro
            </a>
            <?php if ($hasBooks): ?>
              <button type="button" disabled
                      class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-300 text-gray-400 cursor-not-allowed"
                      title="<?= __("Rimuovere i libri associati prima di eliminare l'autore") ?>">
                <i class="fas fa-lock"></i>
                Non eliminabile
              </button>
            <?php else: ?>
              <form method="post" action="/admin/autori/delete/<?php echo (int)($autore['id'] ?? 0); ?>" class="inline-flex">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition-colors"
                        onclick="return confirm('Confermi l\'eliminazione dell\'autore?');">
                  <i class="fas fa-trash"></i>
                  Elimina
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Totale Libri') ?></div>
            <div class="mt-1 text-2xl font-bold text-gray-900"><?php echo number_format($totalBooks, 0, ',', '.'); ?></div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Anni di Vita') ?></div>
            <div class="mt-1 text-base font-semibold text-gray-900">
              <?php
                if ($dataNascita && $dataMorte) {
                    echo HtmlHelper::e($dataNascita . ' ➜ ' . $dataMorte);
                } elseif ($dataNascita) {
                    echo 'Dal ' . HtmlHelper::e($dataNascita);
                } elseif ($dataMorte) {
                    echo 'Fino al ' . HtmlHelper::e($dataMorte);
                } else {
                    echo 'N/D';
                }
              ?>
            </div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Nazionalità') ?></div>
            <div class="mt-1 text-base font-semibold text-gray-900"><?php echo $nazionalita ? HtmlHelper::e($nazionalita) : 'N/D'; ?></div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Sito Web') ?></div>
            <div class="mt-1 text-base font-semibold text-gray-900 truncate">
              <?php if ($sitoWeb): ?>
                <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="underline decoration-gray-400 hover:decoration-gray-600">
                  <?php echo HtmlHelper::e($sitoWeb); ?>
                </a>
              <?php else: ?>
                N/D
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="space-y-6">
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <i class="fas fa-id-card text-gray-600"></i>
          <?= __("Profilo professionale") ?>
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
          <div>
            <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Nome completo") ?></dt>
            <dd class="text-gray-900 font-medium mt-1"><?php echo $nomeAutore; ?></dd>
          </div>
          <?php if ($pseudonimo): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Pseudonimo") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo HtmlHelper::e($pseudonimo); ?></dd>
            </div>
          <?php endif; ?>
          <?php if ($dataNascita): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Data di nascita") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo HtmlHelper::e($dataNascita); ?></dd>
            </div>
          <?php endif; ?>
          <?php if ($dataMorte): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Data di morte") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo HtmlHelper::e($dataMorte); ?></dd>
            </div>
          <?php endif; ?>
          <?php if ($nazionalita): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Nazionalità") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo HtmlHelper::e($nazionalita); ?></dd>
            </div>
          <?php endif; ?>
          <?php if ($sitoWeb): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Sito web") ?></dt>
              <dd class="text-gray-900 font-medium mt-1 truncate">
                <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="text-gray-600 hover:text-gray-800 underline decoration-gray-400">
                  <?php echo HtmlHelper::e($sitoWeb); ?>
                </a>
              </dd>
            </div>
          <?php endif; ?>
          <?php if ($createdAt): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Creato il") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo date('d/m/Y H:i', strtotime($createdAt)); ?></dd>
            </div>
          <?php endif; ?>
          <?php if ($updatedAt): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Ultimo aggiornamento") ?></dt>
              <dd class="text-gray-900 font-medium mt-1"><?php echo date('d/m/Y H:i', strtotime($updatedAt)); ?></dd>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($biografia): ?>
        <div>
          <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <i class="fas fa-feather text-gray-600"></i>
            <?= __("Biografia") ?>
          </h2>
          <div class="text-gray-700 leading-relaxed">
            <div class="prose prose-sm max-w-none">
              <?php echo nl2br(HtmlHelper::e($biografia)); ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div>
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-book text-gray-600"></i>
            Catalogo libri
            <span class="bg-gray-200 text-gray-800 text-xs font-bold px-2.5 py-1 rounded-full">
              <?= sprintf(__("%d titoli"), $totalBooks) ?>
            </span>
          </h2>
          <a href="/admin/libri/crea"
             class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 text-white text-sm font-medium hover:bg-gray-800 transition-colors">
            <i class="fas fa-plus"></i>
            Aggiungi nuovo libro
          </a>
        </div>
        <?php if ($totalBooks === 0): ?>
          <div class="text-center py-12 bg-gray-50 rounded-2xl">
            <div class="mx-auto mb-4 w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center">
              <i class="fas fa-book text-gray-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-1"><?= __("Nessun libro trovato") ?></h3>
            <p class="text-sm text-gray-500"><?= __("Questo autore non ha ancora libri registrati nella biblioteca.") ?></p>
          </div>
        <?php else: ?>
          <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3">
            <?php foreach ($libri as $libro): ?>
              <?php
                $cover = (string)($libro['copertina_url'] ?? '');
                if ($cover === '' && !empty($libro['copertina'])) { $cover = (string)$libro['copertina']; }
                if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
                if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
              ?>
              <article class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:border-gray-300 hover:shadow-xl transition-all duration-300">
                <div class="relative h-52 bg-gray-100 overflow-hidden">
                  <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                       alt="Copertina <?php echo HtmlHelper::e($libro['titolo'] ?? ''); ?>"
                       class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                       onerror="this.src='/uploads/copertine/placeholder.jpg'">
                </div>
                <div class="p-5 space-y-3">
                  <div>
                    <h3 class="text-base font-semibold text-gray-900 line-clamp-2 group-hover:text-gray-600 transition-colors">
                      <a href="/admin/libri/<?php echo (int)$libro['id']; ?>"><?php echo HtmlHelper::e($libro['titolo'] ?? 'Titolo non disponibile'); ?></a>
                    </h3>
                    <?php if (!empty($libro['editore_nome'])): ?>
                      <p class="text-sm text-gray-500 mt-1"><?= sprintf(__("Editore: %s"), HtmlHelper::e($libro['editore_nome'])) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="flex items-center justify-between text-xs uppercase tracking-wide text-gray-500">
                    <span><?= sprintf(__("ISBN13: %s"), HtmlHelper::e($libro['isbn13'] ?? $libro['ean'] ?? 'N/D')) ?></span>
                    <span><?php echo HtmlHelper::e($libro['stato'] ?? ''); ?></span>
                  </div>
                  <div class="flex gap-2 pt-3 items-center">
                    <a href="/admin/libri/<?php echo (int)$libro['id']; ?>"
                       class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 text-white text-sm font-medium px-3 h-11 hover:bg-gray-800 transition whitespace-nowrap">
                      <i class="fas fa-eye"></i><?= __("Dettagli") ?>
                    </a>
                    <a href="/admin/libri/modifica/<?php echo (int)$libro['id']; ?>"
                       class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-medium h-11 hover:bg-gray-50 transition"
                       title="<?= __("Modifica") ?>">
                      <i class="fas fa-edit"></i>
                      Modifica
                    </a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
  </div>
</section>
