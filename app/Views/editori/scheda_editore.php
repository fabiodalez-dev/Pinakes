<?php
/**
 * @var array $data { editore: array, libri: array, autori: array }
 */
use App\Support\HtmlHelper;

$editore = $data['editore'];
$libri = $data['libri'];
$autori = $data['autori'];

$hasBooks = !empty($libri);
$totalBooks = count($libri);
$totalAuthors = count($autori ?? []);
$nomeEditore = HtmlHelper::e($editore['nome'] ?? 'Editore sconosciuto');
$sitoWebRaw = trim((string)($editore['sito_web'] ?? ''));
$sitoWeb = '';
if ($sitoWebRaw !== '') {
    $scheme = strtolower((string) parse_url($sitoWebRaw, PHP_URL_SCHEME));
    if (filter_var($sitoWebRaw, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
        $sitoWeb = $sitoWebRaw;
    }
}
$createdAt = trim((string)($editore['created_at'] ?? ''));
$updatedAt = trim((string)($editore['updated_at'] ?? ''));

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
            <a href="<?= htmlspecialchars(url('/admin/publishers'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
              <i class="fas fa-building mr-1"></i><?= __("Editori") ?>
            </a>
          </li>
          <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
          <li class="text-gray-900 font-medium truncate max-w-[12rem] sm:max-w-xs"><?php echo $nomeEditore; ?></li>
        </ol>
      </nav>

      <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-2">
          <h1 class="text-3xl font-bold text-gray-900 break-words"><?php echo $nomeEditore; ?></h1>
        </div>

        <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-3">
          <a href="<?= htmlspecialchars(url('/admin/publishers/edit/' . (int)($editore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
             class="<?php echo $btnPrimary; ?> justify-center">
            <i class="fas fa-pen"></i>
            <?= __("Modifica") ?>
          </a>
          <a href="<?= htmlspecialchars(url('/admin/books/create'), ENT_QUOTES, 'UTF-8') ?>"
             class="<?php echo $btnGhost; ?> justify-center">
            <i class="fas fa-plus"></i>
            <?= __("Nuovo Libro") ?>
          </a>
          <?php if ($hasBooks): ?>
            <button type="button" disabled
                    class="btn-secondary inline-flex items-center justify-center gap-2 text-gray-400 cursor-not-allowed"
                    title="<?= htmlspecialchars(__("Rimuovere i libri dell'editore prima di eliminarlo"), ENT_QUOTES, 'UTF-8') ?>">
              <i class="fas fa-lock"></i>
              <?= __("Non eliminabile") ?>
            </button>
          <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(url('/admin/publishers/delete/' . (int)($editore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm="<?= htmlspecialchars(__("Confermi l'eliminazione dell'editore?"), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm-button="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="<?php echo $btnDanger; ?> w-full justify-center">
                <i class="fas fa-trash"></i>
                <?= __("Elimina") ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Identity card: icon + key facts, full width -->
    <div class="card mb-6">
      <div class="flex flex-col sm:flex-row gap-6">
        <div class="shrink-0 flex sm:block justify-center">
          <div class="w-28 h-28 rounded-lg bg-gray-100 flex items-center justify-center">
            <i class="fas fa-building text-gray-300 text-5xl"></i>
          </div>
        </div>
        <div class="flex-1 min-w-0">
          <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-4">
            <div>
              <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __('Totale Libri') ?></dt>
              <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo number_format($totalBooks, 0, ',', '.'); ?></dd>
            </div>
            <div>
              <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __('Totale Autori') ?></dt>
              <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo number_format($totalAuthors, 0, ',', '.'); ?></dd>
            </div>
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
            <?php if (!empty($editore['codice_fiscale'])): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Codice Fiscale") ?></dt>
                <dd class="mt-1 text-base font-semibold text-gray-900"><?php echo HtmlHelper::e($editore['codice_fiscale']); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($createdAt): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Aggiunto il") ?></dt>
                <dd class="mt-1 text-sm font-medium text-gray-600"><?php echo HtmlHelper::e($createdAt); ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($updatedAt): ?>
              <div>
                <dt class="text-xs uppercase tracking-wide text-gray-500"><?= __("Ultimo Aggiornamento") ?></dt>
                <dd class="mt-1 text-sm font-medium text-gray-600"><?php echo HtmlHelper::e($updatedAt); ?></dd>
              </div>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </div>

    <?php if (!empty($editore['email']) || !empty($editore['telefono']) || !empty($editore['indirizzo'])): ?>
    <!-- Contacts: full width -->
    <div class="card mb-6">
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <i class="fas fa-address-card text-gray-600"></i>
          <?= __("Contatti") ?>
        </h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
          <?php if (!empty($editore['email'])): ?>
            <div class="min-w-0">
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Email") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium truncate">
                <a href="mailto:<?php echo htmlspecialchars($editore['email'], ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-600 hover:underline">
                  <?php echo HtmlHelper::e($editore['email']); ?>
                </a>
              </dd>
            </div>
          <?php endif; ?>
          <?php if (!empty($editore['telefono'])): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Telefono") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium">
                <a href="tel:<?php echo htmlspecialchars($editore['telefono'], ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-600 hover:underline">
                  <?php echo HtmlHelper::e($editore['telefono']); ?>
                </a>
              </dd>
            </div>
          <?php endif; ?>
          <?php if (!empty($editore['indirizzo'])): ?>
            <div class="sm:col-span-2">
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Indirizzo") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium"><?php echo HtmlHelper::e($editore['indirizzo']); ?></dd>
            </div>
          <?php endif; ?>
        </dl>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($editore['referente_nome']) || !empty($editore['referente_email']) || !empty($editore['referente_telefono'])): ?>
    <!-- Reference: full width -->
    <div class="card mb-6">
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <i class="fas fa-user-tie text-gray-600"></i>
          <?= __("Referente") ?>
        </h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-4 text-sm">
          <?php if (!empty($editore['referente_nome'])): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Nome") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium"><?php echo HtmlHelper::e($editore['referente_nome']); ?></dd>
            </div>
          <?php endif; ?>
          <?php if (!empty($editore['referente_email'])): ?>
            <div class="min-w-0">
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Email") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium truncate">
                <a href="mailto:<?php echo htmlspecialchars($editore['referente_email'], ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-600 hover:underline">
                  <?php echo HtmlHelper::e($editore['referente_email']); ?>
                </a>
              </dd>
            </div>
          <?php endif; ?>
          <?php if (!empty($editore['referente_telefono'])): ?>
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Telefono") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium">
                <a href="tel:<?php echo htmlspecialchars($editore['referente_telefono'], ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-600 hover:underline">
                  <?php echo HtmlHelper::e($editore['referente_telefono']); ?>
                </a>
              </dd>
            </div>
          <?php endif; ?>
        </dl>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($autori)): ?>
    <!-- Published authors: full width -->
    <div class="card mb-6">
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center gap-2">
          <i class="fas fa-user-edit text-gray-600"></i>
          <?= __("Autori pubblicati") ?>
          <span class="status-badge bg-gray-100 text-gray-700"><?php echo number_format($totalAuthors, 0, ',', '.'); ?></span>
        </h2>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($autori as $autoreItem): ?>
            <a href="<?= htmlspecialchars(url('/admin/authors/' . (int)$autoreItem['id']), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-gray-100 text-gray-700 text-sm font-medium hover:bg-gray-200 transition-colors">
              <i class="fas fa-user text-gray-400"></i>
              <?php echo HtmlHelper::e($autoreItem['nome'] ?? ''); ?>
            </a>
          <?php endforeach; ?>
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
            <h3 class="text-lg font-semibold text-gray-900 mb-1"><?= __("Nessun libro registrato") ?></h3>
            <p class="text-sm text-gray-500"><?= __("Aggiungi un nuovo titolo per arricchire il catalogo di questo editore.") ?></p>
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
                      <a href="<?= htmlspecialchars(url('/admin/books/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"><?php echo HtmlHelper::e($libro['titolo'] ?? __("Titolo non disponibile")); ?></a>
                    </h3>
                    <?php if (!empty($libro['editore_nome'])): ?>
                      <p class="text-sm text-gray-500 mt-1"><?= __("Editore:") ?> <?php echo HtmlHelper::e($libro['editore_nome']); ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs uppercase tracking-wide text-gray-500">
                    <span>ISBN13: <?php echo HtmlHelper::e($libro['isbn13'] ?? $libro['ean'] ?? 'N/D'); ?></span>
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
