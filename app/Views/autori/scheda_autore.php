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

// Shared button styles — identical to the book detail page (scheda_libro.php)
// so the two admin entity pages share the same chrome.
$btnPrimary = 'inline-flex items-center gap-2 rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700';
$btnGhost   = 'inline-flex items-center gap-2 rounded-lg border-2 border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100';
$btnDanger  = 'inline-flex items-center gap-2 rounded-lg border-2 border-red-300 px-5 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-50';
?>
<section class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-6 p-4 rounded-xl border border-green-200 bg-green-50 text-green-700" role="alert">
      <i class="fas fa-check-circle mr-2"></i>
      <?php echo HtmlHelper::e($_SESSION['success_message']); ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

    <!-- Header: breadcrumb + title + actions -->
    <div class="mb-6">
      <!-- Breadcrumb -->
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
        <!-- Title -->
        <div class="flex flex-col gap-2">
          <h1 class="text-3xl font-bold text-gray-900 flex flex-wrap items-start gap-3">
            <i class="fas fa-user-edit text-gray-600 mt-1"></i>
            <?php echo $nomeAutore; ?>
            <?php if ($pseudonimo !== ''): ?>
              <span class="self-center inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                <i class="fas fa-theater-masks mr-1"></i><?php echo HtmlHelper::e($pseudonimo); ?>
              </span>
            <?php endif; ?>
          </h1>
        </div>

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:flex-wrap items-stretch lg:items-center gap-3">
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
                    class="inline-flex items-center justify-center gap-2 rounded-lg border-2 border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-400 cursor-not-allowed"
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

    <!-- Main content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Left: photo + quick info -->
      <div class="lg:col-span-1">
        <div class="card overflow-hidden">
          <div class="p-4 flex items-center justify-center bg-gray-50">
            <?php if ($fotoUrl !== ''): ?>
              <img src="<?php echo htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?= htmlspecialchars(__('Foto autore'), ENT_QUOTES, 'UTF-8') ?>" loading="lazy"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                   class="max-h-72 w-full object-contain rounded-lg shadow" />
              <div class="hidden w-32 h-32 rounded-full bg-gray-200 items-center justify-center">
                <i class="fas fa-user text-gray-400 text-4xl"></i>
              </div>
            <?php else: ?>
              <div class="w-32 h-32 rounded-full bg-gray-200 flex items-center justify-center my-6">
                <i class="fas fa-user text-gray-400 text-4xl"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="p-4 space-y-3">
            <div class="text-base text-gray-600">
              <i class="fas fa-book text-gray-400 mr-2"></i>
              <span class="font-medium"><?= __('Totale Libri') ?>:</span>
              <?php echo number_format($totalBooks, 0, ',', '.'); ?>
            </div>
            <?php if ($pseudonimo): ?>
              <div class="text-base text-gray-600">
                <i class="fas fa-theater-masks text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Pseudonimo") ?>:</span>
                <?php echo HtmlHelper::e($pseudonimo); ?>
              </div>
            <?php endif; ?>
            <?php if ($dataNascita): ?>
              <div class="text-base text-gray-600">
                <i class="fas fa-birthday-cake text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Data di nascita") ?>:</span>
                <?php echo HtmlHelper::e($dataNascita); ?>
              </div>
            <?php endif; ?>
            <?php if ($dataMorte): ?>
              <div class="text-base text-gray-600">
                <i class="fas fa-book-dead text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Data di morte") ?>:</span>
                <?php echo HtmlHelper::e($dataMorte); ?>
              </div>
            <?php endif; ?>
            <?php if ($nazionalita): ?>
              <div class="text-base text-gray-600">
                <i class="fas fa-flag text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Nazionalità") ?>:</span>
                <?php echo HtmlHelper::e($nazionalita); ?>
              </div>
            <?php endif; ?>
            <?php if ($sitoWeb): ?>
              <div class="text-base text-gray-600 break-words">
                <i class="fas fa-globe text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Sito web") ?>:</span>
                <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-gray-900 hover:text-gray-600 underline decoration-gray-400">
                  <?php echo HtmlHelper::e($sitoWeb); ?>
                </a>
              </div>
            <?php endif; ?>
            <?php if (!empty($collegamenti)): ?>
              <div class="text-base text-gray-600">
                <i class="fas fa-link text-gray-400 mr-2"></i>
                <span class="font-medium"><?= __("Collegamenti e fonti") ?>:</span>
                <div class="mt-2 flex flex-wrap gap-2">
                  <?php foreach ($collegamenti as $c): ?>
                    <a href="<?php echo htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                      <i class="fas fa-external-link-alt mr-1 text-xs"></i><?php echo htmlspecialchars($c['etichetta'] !== '' ? $c['etichetta'] : $c['url'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($createdAt || $updatedAt): ?>
              <div class="pt-3 border-t border-gray-100 space-y-2">
                <?php if ($createdAt): ?>
                  <div class="text-sm text-gray-500">
                    <i class="fas fa-calendar-plus text-gray-400 mr-2"></i>
                    <span class="font-medium"><?= __("Creato il") ?>:</span>
                    <?php echo format_date($createdAt, true, '/'); ?>
                  </div>
                <?php endif; ?>
                <?php if ($updatedAt): ?>
                  <div class="text-sm text-gray-500">
                    <i class="fas fa-calendar-check text-gray-400 mr-2"></i>
                    <span class="font-medium"><?= __("Ultimo aggiornamento") ?>:</span>
                    <?php echo format_date($updatedAt, true, '/'); ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Right: biography + book catalog -->
      <div class="lg:col-span-2 space-y-6">
        <?php if ($biografia): ?>
          <div class="card">
            <div class="p-6">
              <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i class="fas fa-feather text-gray-600"></i>
                <?= __("Biografia") ?>
              </h2>
              <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed">
                <?php echo nl2br(HtmlHelper::e($biografia)); ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="card">
          <div class="p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
              <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-book text-gray-600"></i>
                <?= __("Catalogo libri") ?>
                <span class="bg-gray-200 text-gray-800 text-xs font-bold px-2.5 py-1 rounded-full">
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
              <div class="text-center py-12 bg-gray-50 rounded-2xl">
                <div class="mx-auto mb-4 w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center">
                  <i class="fas fa-book text-gray-500 text-2xl"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-900 mb-1"><?= __("Nessun libro trovato") ?></h3>
                <p class="text-sm text-gray-500"><?= __("Questo autore non ha ancora libri registrati nella biblioteca.") ?></p>
              </div>
            <?php else: ?>
              <div class="grid gap-5 sm:grid-cols-2">
                <?php foreach ($libri as $libro): ?>
                  <?php
                    $cover = (string)($libro['copertina_url'] ?? '');
                    if ($cover === '' && !empty($libro['copertina'])) { $cover = (string)$libro['copertina']; }
                    if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
                    if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
                    $cover = preg_match('#^https?://#', $cover) ? $cover : url($cover);
                  ?>
                  <article class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:border-gray-300 hover:shadow-xl transition-all duration-300">
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
                      <div class="flex items-center justify-between text-xs uppercase tracking-wide text-gray-500">
                        <span><?= sprintf(__("ISBN13: %s"), HtmlHelper::e($libro['isbn13'] ?? $libro['ean'] ?? 'N/D')) ?></span>
                        <span><?php echo HtmlHelper::e(__(ucfirst($libro['stato'] ?? ''))); ?></span>
                      </div>
                      <div class="flex gap-2 pt-3 items-center">
                        <a href="<?= htmlspecialchars(url('/admin/books/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                           class="inline-flex items-center justify-center gap-2 rounded-lg bg-gray-900 text-white text-sm font-medium px-3 h-11 hover:bg-gray-700 transition whitespace-nowrap">
                          <i class="fas fa-eye"></i><?= __("Dettagli") ?>
                        </a>
                        <a href="<?= htmlspecialchars(url('/admin/books/edit/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                           class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg border-2 border-gray-300 text-gray-700 text-sm font-medium h-11 hover:bg-gray-100 transition"
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
    </div>
  </div>
</section>
