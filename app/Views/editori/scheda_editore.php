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
$sitoWeb = trim((string)($editore['sito_web'] ?? ''));
?>
<section class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <nav aria-label="breadcrumb" class="mb-6">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/editori'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-building mr-1"></i><?= __("Editori") ?>
          </a>
        </li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium truncate max-w-[12rem] sm:max-w-xs"><?php echo $nomeEditore; ?></li>
      </ol>
    </nav>

    <div class="relative overflow-hidden rounded-3xl bg-white border border-gray-200 shadow-xl mb-8">
      <div class="relative p-8 sm:p-10 lg:p-12">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
          <div>
            <div class="text-sm uppercase tracking-widest text-gray-900 mb-2 flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-gray-400"></span>
              <?= __("Profilo Editore") ?>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight flex flex-wrap items-center gap-3">
              <?php echo $nomeEditore; ?>
            </h1>
            <?php if ($sitoWeb): ?>
              <p class="mt-3 text-gray-900 text-sm sm:text-base flex items-center gap-2">
                <i class="fas fa-external-link-alt"></i>
                <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="underline decoration-gray-400 hover:decoration-gray-600">
                  <?php echo HtmlHelper::e($sitoWeb); ?>
                </a>
              </p>
            <?php endif; ?>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <a href="<?= htmlspecialchars(url('/admin/editori/modifica/' . (int)($editore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white hover:bg-gray-800 font-medium transition-colors">
              <i class="fas fa-pen"></i>
              <?= __("Modifica") ?>
            </a>
            <a href="<?= htmlspecialchars(url('/admin/libri/crea'), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium transition-colors">
              <i class="fas fa-plus"></i>
              <?= __("Nuovo Libro") ?>
            </a>
            <?php if ($hasBooks): ?>
              <button type="button" disabled
                      class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-300 text-gray-400 cursor-not-allowed"
                      title="<?= __("Rimuovere i libri dell'editore prima di eliminarlo") ?>">
                <i class="fas fa-lock"></i>
                <?= __("Non eliminabile") ?>
              </button>
            <?php else: ?>
              <form method="post" action="<?= htmlspecialchars(url('/admin/editori/delete/' . (int)($editore['id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-red-600 text-white hover:bg-red-700 transition-colors"
                        onclick="return confirm('<?= __("Confermi l\'eliminazione dell\'editore?") ?>');">
                  <i class="fas fa-trash"></i>
                  <?= __("Elimina") ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Totale Libri') ?></div>
            <div class="mt-1 text-2xl font-bold text-gray-900"><?php echo number_format($totalBooks, 0, ',', '.'); ?></div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Totale Autori') ?></div>
            <div class="mt-1 text-2xl font-bold text-gray-900"><?php echo number_format($totalAuthors, 0, ',', '.'); ?></div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('Ultimo Aggiornamento') ?></div>
            <div class="mt-1 text-base font-semibold text-gray-900">
              <?php echo HtmlHelper::e($editore['updated_at'] ?? 'N/D'); ?>
            </div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-2xl px-4 py-3">
            <div class="text-sm text-gray-600 font-medium"><?= __('ID Editore') ?></div>
            <div class="mt-1 text-base font-semibold text-gray-900">#<?php echo (int)($editore['id'] ?? 0); ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="space-y-6">
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-building text-gray-600"></i>
            <?= __("Informazioni generali") ?>
          </h2>
        </div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <div>
              <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Nome") ?></dt>
              <dd class="mt-1 text-gray-900 font-medium"><?php echo $nomeEditore; ?></dd>
            </div>
            <?php if ($sitoWeb): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Sito web") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium truncate">
                  <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="text-gray-600 hover:underline">
                    <?php echo HtmlHelper::e($sitoWeb); ?>
                  </a>
                </dd>
              </div>
            <?php endif; ?>
            <?php if (!empty($editore['codice_fiscale'])): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Codice Fiscale") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium"><?php echo HtmlHelper::e($editore['codice_fiscale']); ?></dd>
              </div>
            <?php endif; ?>
            <?php if (!empty($editore['created_at'])): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Aggiunto il") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium"><?php echo HtmlHelper::e($editore['created_at']); ?></dd>
              </div>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <?php if (!empty($editore['email']) || !empty($editore['telefono']) || !empty($editore['indirizzo'])): ?>
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-address-card text-gray-600"></i>
            <?= __("Contatti") ?>
          </h2>
        </div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <?php if (!empty($editore['email'])): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Email") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium">
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
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-user-tie text-gray-600"></i>
            <?= __("Referente") ?>
          </h2>
        </div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 text-sm">
            <?php if (!empty($editore['referente_nome'])): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Nome") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium"><?php echo HtmlHelper::e($editore['referente_nome']); ?></dd>
              </div>
            <?php endif; ?>
            <?php if (!empty($editore['referente_email'])): ?>
              <div>
                <dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Email") ?></dt>
                <dd class="mt-1 text-gray-900 font-medium">
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
        <div class="card">
          <div class="card-header">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
              <i class="fas fa-user-edit text-gray-600"></i>
              <?= __("Autori pubblicati") ?>
            </h2>
          </div>
          <div class="card-body">
            <div class="flex flex-wrap gap-2">
              <?php foreach ($autori as $autoreItem): ?>
                <a href="<?= htmlspecialchars(url('/admin/autori/' . (int)$autoreItem['id']), ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-gray-100 border border-gray-200 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                  <i class="fas fa-user"></i>
                  <?php echo HtmlHelper::e($autoreItem['nome'] ?? ''); ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
              <i class="fas fa-book text-gray-600"></i>
              <?= __("Catalogo libri") ?>
              <span class="bg-gray-200 text-gray-800 text-xs font-bold px-2.5 py-1 rounded-full">
                <?php echo $totalBooks; ?> <?= __("titoli") ?>
              </span>
            </h2>
            <a href="<?= htmlspecialchars(url('/admin/libri/crea'), ENT_QUOTES, 'UTF-8') ?>"
               class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-800">
              <i class="fas fa-plus"></i>
              <?= __("Aggiungi nuovo libro") ?>
            </a>
          </div>
        </div>
        <div class="card-body">
          <?php if ($totalBooks === 0): ?>
            <div class="text-center py-12 bg-gray-50 rounded-2xl">
              <div class="mx-auto mb-4 w-16 h-16 rounded-full bg-gray-200 flex items-center justify-center">
                <i class="fas fa-book text-gray-500 text-2xl"></i>
              </div>
              <h3 class="text-lg font-semibold text-gray-900 mb-1"><?= __("Nessun libro registrato") ?></h3>
              <p class="text-sm text-gray-500"><?= __("Aggiungi un nuovo titolo per arricchire il catalogo di questo editore.") ?></p>
            </div>
          <?php else: ?>
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              <?php foreach ($libri as $libro): ?>
                <?php
                  $cover = (string)($libro['copertina_url'] ?? '');
                  if ($cover === '' && !empty($libro['copertina'])) { $cover = (string)$libro['copertina']; }
                  if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
                  if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
                  $cover = url($cover);
                ?>
                <article class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:border-gray-300 hover:shadow-xl transition-all duration-300">
                  <div class="relative h-52 bg-gray-100 overflow-hidden">
                    <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                         alt="Copertina <?php echo HtmlHelper::e($libro['titolo'] ?? ''); ?>"
                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                         onerror="this.src=window.BASE_PATH+'/uploads/copertine/placeholder.jpg'">
                  </div>
                  <div class="p-5 space-y-3">
                    <div>
                      <h3 class="text-base font-semibold text-gray-900 line-clamp-2 group-hover:text-gray-600 transition-colors">
                        <a href="<?= htmlspecialchars(url('/admin/libri/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"><?php echo HtmlHelper::e($libro['titolo'] ?? __("Titolo non disponibile")); ?></a>
                      </h3>
                      <?php if (!empty($libro['editore_nome'])): ?>
                        <p class="text-sm text-gray-500 mt-1"><?= __("Editore:") ?> <?php echo HtmlHelper::e($libro['editore_nome']); ?></p>
                      <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between text-xs uppercase tracking-wide text-gray-500">
                      <span>ISBN13: <?php echo HtmlHelper::e($libro['isbn13'] ?? $libro['ean'] ?? 'N/D'); ?></span>
                      <span><?php echo HtmlHelper::e(__(ucfirst($libro['stato'] ?? ''))); ?></span>
                    </div>
                    <div class="flex gap-2 pt-3">
                      <a href="<?= htmlspecialchars(url('/admin/libri/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                         class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition-colors">
                        <i class="fas fa-eye"></i><?= __("Dettagli") ?>
                      </a>
                      <a href="<?= htmlspecialchars(url('/admin/libri/modifica/' . (int)$libro['id']), ENT_QUOTES, 'UTF-8') ?>"
                         class="inline-flex items-center justify-center p-2.5 rounded-xl bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition-colors"
                         title="<?= __("Modifica") ?>">
                        <i class="fas fa-edit"></i>
                      </a>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($sitoWeb): ?>
        <div class="card">
          <div class="card-header">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
              <i class="fas fa-globe text-gray-600"></i>
              <?= __("Risorse esterne") ?>
            </h2>
          </div>
          <div class="card-body">
            <a href="<?php echo htmlspecialchars($sitoWeb, ENT_QUOTES, 'UTF-8'); ?>" target="_blank"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white hover:bg-gray-800 font-medium transition-colors">
              <i class="fas fa-external-link-alt"></i>
              <?= __("Visita il sito ufficiale") ?>
            </a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
