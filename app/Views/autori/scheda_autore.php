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
$sitoWebRaw  = trim((string)($autore['sito_web'] ?? ''));
$sitoWeb     = '';
if ($sitoWebRaw !== '') {
    $scheme = strtolower((string) parse_url($sitoWebRaw, PHP_URL_SCHEME));
    if (filter_var($sitoWebRaw, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
        $sitoWeb = $sitoWebRaw;
    }
}
$biografia   = trim((string)($autore['biografia'] ?? ''));
$createdAt   = trim((string)($autore['created_at'] ?? ''));
$updatedAt   = trim((string)($autore['updated_at'] ?? ''));

// Issue #163 — author photo + relevant source/website links.
$fotoRaw = trim((string)($autore['foto'] ?? ''));
$fotoUrl = '';
if ($fotoRaw !== '') {
    if (strpos($fotoRaw, '/uploads/') === 0) {
        $fotoUrl = url($fotoRaw); // locally uploaded photo
    } elseif (filter_var($fotoRaw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $fotoRaw) === 1) {
        $fotoUrl = $fotoRaw; // external URL
    }
}
$collegamenti = [];
if (!empty($autore['collegamenti'])) {
    $decodedLinks = json_decode((string)$autore['collegamenti'], true);
    if (is_array($decodedLinks)) {
        foreach ($decodedLinks as $c) {
            if (!is_array($c)) { continue; }
            $u = trim((string)($c['url'] ?? ''));
            if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL) || preg_match('#^https?://#i', $u) !== 1) { continue; }
            $collegamenti[] = ['etichetta' => trim((string)($c['etichetta'] ?? '')), 'url' => $u];
        }
    }
}

// Use the shared backend action vocabulary used by forms and plugin screens.
$btnPrimary = 'btn-primary inline-flex items-center gap-2';
$btnGhost   = 'btn-secondary inline-flex items-center gap-2';
$btnDanger  = 'btn-danger inline-flex items-center gap-2';
?>
<section class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 p-4 rounded-lg border border-green-200 bg-green-50 text-green-700" role="alert">
      <i class="fas fa-check-circle mr-2"></i>
      <?php echo HtmlHelper::e($_SESSION['success_message']); ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

    <!-- Header: breadcrumb + title + actions -->
    <div class="mb-6">
      <nav aria-label="breadcrumb" class="mb-4">
        <ol class="flex items-center space-x-2 text-sm">
          <li>
            <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
              <i class="fas fa-home mr-1"></i><?= __("Home") ?>
            </a>
          </li>
          <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
          <li>
            <a href="<?= htmlspecialchars(url('/admin/authors'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
              <i class="fas fa-user-edit mr-1"></i><?= __("Autori") ?>
            </a>
          </li>
          <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
          <li class="text-gray-900 font-medium truncate max-w-[12rem] sm:max-w-xs"><?php echo $nomeAutore; ?></li>
        </ol>
      </nav>

      <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
          <h1 class="text-3xl font-bold text-gray-900 break-words"><?php echo $nomeAutore; ?></h1>
          <?php if ($pseudonimo !== ''): ?>
            <p class="text-sm text-gray-600"><?= __("Pseudonimo:") ?> <?php echo htmlspecialchars($pseudonimo, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>
        </div>

        <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-3">
          <a href="<?= htmlspecialchars(url('/admin/authors/edit/' . (int)($autore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
             class="<?php echo $btnPrimary; ?> justify-center">
            <i class="fas fa-pen"></i>
            <?= __("Modifica") ?>
          </a>
          <a href="<?= htmlspecialchars(url('/admin/books/create'), ENT_QUOTES, 'UTF-8') ?>"
             class="<?php echo $btnGhost; ?> justify-center">
            <i class="fas fa-plus"></i>
            <?= __('Nuovo Libro') ?>
          </a>
          <?php if ($hasBooks): ?>
            <button type="button" disabled
                    class="btn-secondary inline-flex items-center justify-center gap-2 text-gray-400 cursor-not-allowed"
                    title="<?= htmlspecialchars(__("Rimuovere i libri associati prima di eliminare l'autore"), ENT_QUOTES, 'UTF-8') ?>">
              <i class="fas fa-lock"></i>
              <?= __('Non eliminabile') ?>
            </button>
          <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(url('/admin/authors/delete/' . (int)($autore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm="<?= htmlspecialchars(__("Confermi l'eliminazione dell'autore?"), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm-button="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="<?php echo $btnDanger; ?> w-full justify-center">
                <i class="fas fa-trash"></i>
                <?= __('Elimina') ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Identity card: photo + quick facts, full width, side-by-side -->
    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-6">
        <div class="shrink-0 flex sm:block justify-center">
          <?php if ($fotoUrl !== ''): ?>
            <img src="<?php echo htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?= htmlspecialchars(__('Foto autore'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                 class="w-28 h-28 object-cover rounded-lg border border-gray-200 shadow-sm" />
            <div class="hidden w-28 h-28 rounded-lg bg-gray-100 items-center justify-center">
              <i class="fas fa-user text-gray-300 text-5xl"></i>
            </div>
          <?php else: ?>
            <div class="w-28 h-28 rounded-lg bg-gray-100 flex items-center justify-center">
              <i class="fas fa-user text-gray-300 text-5xl"></i>
            </div>
          <?php endif; ?>
        </div>

        <div class="flex-1 min-w-0">
          <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-4">
            <div>
              <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __('Totale Libri') ?></dt>
              <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo number_format($totalBooks, 0, ',', '.'); ?></dd>
            </div>
            <?php if ($pseudonimo): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Pseudonimo") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo HtmlHelper::e($pseudonimo); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($dataNascita): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Data di nascita") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo HtmlHelper::e($dataNascita); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($dataMorte): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Data di morte") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo HtmlHelper::e($dataMorte); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($nazionalita): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Nazionalità") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo HtmlHelper::e($nazionalita); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($sitoWeb): ?>
              <div class="min-w-0">
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Sito web") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900 truncate">
                  <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-gray-900 hover:text-gray-600 underline decoration-gray-400">
                    <?php echo HtmlHelper::e($sitoWeb); ?>
                  </a>
                </dd>
              </div>
            <?php endif; ?>
            <?php if ($createdAt): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Creato il") ?></dt>
                <dd class="mt-1 text-sm font-medium text-gray-600"><?php echo format_date($createdAt, true, '/'); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($updatedAt): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Ultimo aggiornamento") ?></dt>
                <dd class="mt-1 text-sm font-medium text-gray-600"><?php echo format_date($updatedAt, true, '/'); ?></dd>
              </div>
            <?php endif; ?>
          </dl>

          <?php if (!empty($collegamenti)): ?>
            <div class="mt-5 pt-5 border-t border-gray-100">
              <dt class="text-xs uppercase tracking-wide text-gray-500 mb-2"><?= __("Collegamenti e fonti") ?></dt>
              <div class="flex flex-wrap gap-2">
                <?php foreach ($collegamenti as $c): ?>
                  <a href="<?php echo htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"
                     class="inline-flex items-center px-3 py-1 rounded-md text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                    <i class="fas fa-external-link-alt mr-1.5 text-xs text-gray-400"></i><?php echo htmlspecialchars($c['etichetta'] !== '' ? $c['etichetta'] : $c['url'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Biography: full width -->
    <?php if ($biografia): ?>
      <div class="card mb-6">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <i class="fas fa-feather text-gray-600"></i>
            <?= __("Biografia") ?>
          </h2>
          <div class="prose prose-sm sm:prose max-w-none text-gray-700 leading-relaxed">
            <?php echo nl2br(HtmlHelper::e($biografia)); ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Book catalog: full width, its own row -->
    <div class="card">
      <div>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
          <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-book text-gray-600"></i>
            <?= __("Catalogo libri") ?>
            <span class="status-badge bg-gray-100 text-gray-700">
              <?= sprintf(__("%d titoli"), $totalBooks) ?>
            </span>
          </h2>
          <a href="<?= htmlspecialchars(url('/admin/books/create'), ENT_QUOTES, 'UTF-8') ?>"
             class="<?php echo $btnPrimary; ?> justify-center">
            <i class="fas fa-plus"></i>
            <?= __("Aggiungi nuovo libro") ?>
          </a>
        </div>

        <?php if ($totalBooks === 0): ?>
          <div class="text-center py-12 bg-gray-50 rounded-lg">
            <div class="mx-auto mb-4 w-16 h-16 rounded-lg bg-gray-200 flex items-center justify-center">
              <i class="fas fa-book text-gray-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-1"><?= __("Nessun libro trovato") ?></h3>
            <p class="text-sm text-gray-500"><?= __("Questo autore non ha ancora libri registrati nella biblioteca.") ?></p>
          </div>
        <?php else: ?>
          <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($libri as $libro): ?>
              <?php
                $cover = (string)($libro['copertina_url'] ?? '');
                if ($cover === '' && !empty($libro['copertina'])) { $cover = (string)$libro['copertina']; }
                if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
                if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
                $cover = preg_match('#^https?://#', $cover) ? $cover : url($cover);
              ?>
              <article class="group bg-white border border-gray-200 rounded-lg overflow-hidden hover:border-gray-300 hover:shadow-md transition-all duration-200">
                <div class="relative h-52 bg-gray-100 overflow-hidden">
                  <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                       alt="Copertina <?php echo HtmlHelper::e($libro['titolo'] ?? ''); ?>"
                       class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                       onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
                </div>
                <div class="p-5 space-y-3">
                  <div>
                    <h3 class="text-base font-semibold text-gray-900 line-clamp-2 group-hover:text-gray-600 transition-colors">
                      <a href="<?= htmlspecialchars(url('/admin/books/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"><?php echo HtmlHelper::e($libro['titolo'] ?? 'Titolo non disponibile'); ?></a>
                    </h3>
                    <?php if (!empty($libro['editore_nome'])): ?>
                      <p class="text-sm text-gray-500 mt-1"><?= sprintf(__("Editore: %s"), HtmlHelper::e($libro['editore_nome'])) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs uppercase tracking-wide text-gray-500">
                    <span><?= sprintf(__("ISBN13: %s"), HtmlHelper::e($libro['isbn13'] ?? $libro['ean'] ?? 'N/D')) ?></span>
                    <span class="whitespace-nowrap"><?php echo HtmlHelper::e(translate_book_status((string)($libro['stato'] ?? ''))); ?></span>
                  </div>
                  <div class="flex gap-2 pt-3 items-center">
                    <a href="<?= htmlspecialchars(url('/admin/books/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn-primary inline-flex items-center justify-center gap-2 text-sm whitespace-nowrap">
                      <i class="fas fa-eye"></i><?= __("Dettagli") ?>
                    </a>
                    <a href="<?= htmlspecialchars(url('/admin/books/edit/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn-secondary flex-1 inline-flex items-center justify-center gap-2 text-sm"
                       title="<?= __("Modifica") ?>">
                      <i class="fas fa-edit"></i>
                      <?= __("Modifica") ?>
                    </a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
