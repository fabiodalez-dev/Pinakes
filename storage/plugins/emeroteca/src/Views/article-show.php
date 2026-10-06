<?php
declare(strict_types=1);
/*
 * Admin article page (#453, #454). The chrome follows the admin book page
 * verbatim (app/Views/libri/scheda_libro.php): breadcrumb, header with the
 * action buttons, the cover card on the left and the detail cards on the
 * right. The record is read here; it is changed in the form, one click away.
 */
require_once __DIR__ . '/../Services/ContributionService.php';
use App\Plugins\Emeroteca\Services\ContributionService;

$row = $row ?? [];
$genreTrail = $genreTrail ?? [];
$isAdmin = !empty($isAdmin);
$e = static fn($v): string => htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES, 'UTF-8');
$id = (int) ($row['id'] ?? 0);
$value = static fn(string $key): string => trim((string) ($row[$key] ?? ''));

$btnPrimary = 'inline-flex items-center gap-2 rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700';
$btnGhost   = 'inline-flex items-center gap-2 rounded-lg border-2 border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100';
$btnDanger  = 'inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700';

$isPublic = !empty($row['pubblico']);
$statusBadgeClass = $isPublic
    ? 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-green-500 text-white'
    : 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-gray-600 text-white';

/** Own image, else the issue's cover, else the masthead's logo. */
$cover = ContributionService::coverUrl($row);
if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
$cover = preg_match('#^https?://#', $cover) ? $cover : url($cover);

$authors = ContributionService::authorLinks($row);
$keywords = array_values(array_filter(array_map('trim', explode(',', $value('keywords'))), static fn(string $k): bool => $k !== ''));
$tipi = \EmerotecaPlugin::TIPI_ARTICOLO;
$contenitori = \EmerotecaPlugin::TIPI_CONTENITORE;
$testataId = (int) ($row['testata_id'] ?? 0);
$testataTitle = trim((string) ($row['testata_titolo'] ?? ''));
$fascicoloId = (int) ($row['fascicolo_id'] ?? 0);
$fascicoloNumero = trim((string) ($row['fascicolo_numero'] ?? ''));
$resource = ContributionService::resource($row, false);

/** A stored ISO code in the reader's language; the code itself without intl. */
$codeLabel = static function (string $code, bool $region): string {
    if ($code === '' || !class_exists(\Locale::class)) {
        return $code;
    }
    $locale = \App\Support\I18n::getLocale();
    $label = $region ? \Locale::getDisplayRegion('und_' . $code, $locale) : \Locale::getDisplayLanguage($code, $locale);
    return ($label === '' || $label === $code) ? $code : $label;
};

// Label => value, shown only when filled in, in the order of the form.
$details = [
    __('Tipo di contributo') => isset($tipi[$value('tipo_contributo')]) ? __($tipi[$value('tipo_contributo')]) : $value('tipo_contributo'),
    __('Titolo della pubblicazione') => $value('contenitore_titolo'),
    __('Tipo di pubblicazione') => isset($contenitori[$value('contenitore_tipo')]) ? __($contenitori[$value('contenitore_tipo')]) : $value('contenitore_tipo'),
    __('Data di pubblicazione') => $value('data_pubblicazione_testo'),
    __('Anno') => (int) ($row['anno_pubblicazione'] ?? 0) > 0 ? (string) (int) $row['anno_pubblicazione'] : '',
    __('Volume') => $value('volume'),
    __('Numero') => $value('numero'),
    __('Pagine') => $value('pagine'),
    __('Curatori') => $value('contenitore_curatori'),
    __('Editore') => $value('contenitore_editore'),
    __('Luogo di pubblicazione') => $value('contenitore_luogo'),
    'ISSN' => $value('issn'),
    'ISBN' => $value('isbn'),
    'DOI' => $value('doi'),
    __('Lingua') => $codeLabel($value('lingua'), false),
    __('Paese') => $codeLabel($value('paese'), true),
    __('Classificazione') => trim($value('classificazione_schema') . ' ' . $value('classificazione')),
    __('Collocazione') => $value('collocazione'),
    __('Nota di possesso') => $value('nota_possesso'),
];
$details = array_filter($details, static fn(string $v): bool => $v !== '');
?>
<section class="admin-book-detail min-h-screen bg-gray-50 py-6" data-article-id="<?= $id ?>">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

<div class="mb-6">
  <nav aria-label="breadcrumb" class="mb-4">
    <ol class="flex items-center space-x-2 text-sm">
      <li><a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i><?= __('Home') ?></a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li><a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?></a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li><a href="<?= $e(url('/admin/periodicals/articles')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><?= __('Articoli') ?></a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li class="text-gray-900 font-medium"><?= $e(mb_strimwidth($value('titolo'), 0, 60, '…')) ?></li>
    </ol>
  </nav>

  <div class="flex flex-col gap-4">
    <div class="flex flex-col gap-2">
      <h1 class="text-3xl font-bold text-gray-900 flex flex-wrap items-start gap-3">
        <i class="fas fa-file-alt text-gray-600 mt-1"></i>
        <span><?= $e($value('titolo')) ?></span>
        <span class="self-center inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
          <i class="far fa-file-lines mr-1"></i><?= $e(ContributionService::materialType($row)) ?>
        </span>
      </h1>
      <?php if ($value('sottotitolo') !== ''): ?>
      <div class="text-gray-600 mt-1"><?= $e($value('sottotitolo')) ?></div>
      <?php endif; ?>
    </div>

    <div class="flex flex-col lg:flex-row lg:flex-wrap items-stretch lg:items-center gap-3">
      <div class="flex gap-3 w-full lg:w-auto">
        <?php if ($isPublic): ?>
        <a href="<?= $e(url('/emeroteca/articolo/' . $id)) ?>" target="_blank" rel="noopener noreferrer" class="<?= $btnGhost ?> flex-1 lg:flex-none justify-center">
          <i class="fas fa-eye"></i>
          <?= __('Vedi pagina pubblica') ?>
        </a>
        <?php endif; ?>
        <a href="<?= $e(url('/admin/periodicals/articles/' . $id . '/edit')) ?>" class="<?= $btnPrimary ?> flex-1 lg:flex-none justify-center" data-testid="article-edit">
          <i class="fas fa-edit"></i>
          <?= __('Modifica') ?>
        </a>
      </div>
      <?php if ($isAdmin): ?>
      <div class="flex gap-3 w-full lg:w-auto">
        <form method="post" action="<?= $e(url('/admin/periodicals/articles/' . $id . '/delete')) ?>" onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo articolo e il suo PDF?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);" class="flex-1 lg:flex-none">
          <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
          <input type="hidden" name="revision" value="<?= (int) ($row['revision'] ?? 0) ?>">
          <button type="submit" class="<?= $btnDanger ?> w-full">
            <i class="fas fa-trash"></i>
            <?= __('Elimina') ?>
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left: cover + quick info -->
    <div class="lg:col-span-1">
      <div class="card overflow-hidden">
        <div class="p-4 flex items-center justify-center bg-gray-50">
          <img src="<?= $e($cover) ?>"
               onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'"
               alt="<?= $e(sprintf(__('Immagine di «%s»'), $value('titolo'))) ?>"
               class="max-h-80 object-contain rounded-lg shadow" />
        </div>
        <div class="p-4 space-y-3">
          <div>
            <span class="<?= $statusBadgeClass ?>">
              <i class="fas fa-circle text-[8px]"></i>
              <?= $isPublic ? __('Pubblico') : __('Privato') ?>
            </span>
          </div>
          <div class="text-base text-gray-600">
            <i class="fas fa-users text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __('Autori:') ?></span>
            <div class="mt-2 flex flex-wrap gap-2">
              <?php foreach ($authors as $author): ?>
                <?php if ($author['id'] !== null): ?>
                <a href="<?= $e(url('/admin/authors/' . (int) $author['id'])) ?>"
                   class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                  <i class="fas fa-user mr-1"></i><?= $e($author['name']) ?>
                </a>
                <?php else: ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><i class="fas fa-user mr-1"></i><?= $e($author['name']) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ($authors === []): ?>
                <span class="text-gray-400"><?= __('Non specificato') ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="text-base text-gray-600" data-testid="genre-display">
            <i class="fas fa-layer-group text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __('Genere:') ?></span>
            <?php if ($genreTrail !== []): ?>
              <?php foreach ($genreTrail as $i => $genre): ?>
                <?php if ($i > 0): ?> <span class="text-gray-400">→</span> <?php endif; ?>
                <span class="text-gray-900 font-semibold"><?= $e($genre['nome']) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="text-gray-500"><?= __('Non specificato') ?></span>
            <?php endif; ?>
          </div>
          <?php if ($testataId > 0 && $testataTitle !== ''): ?>
          <div class="text-base text-gray-600">
            <i class="fas fa-newspaper text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __('Testata associata') ?>:</span>
            <a href="<?= $e(url('/admin/periodicals/' . $testataId . '/issues')) ?>" class="text-gray-900 hover:text-gray-600 hover:underline font-semibold"><?= $e($testataTitle) ?></a>
            <?php if ($fascicoloId > 0 && $fascicoloNumero !== ''): ?>
              <span class="text-gray-400">→</span>
              <a href="<?= $e(url('/admin/periodicals/issue/' . $fascicoloId)) ?>" class="text-gray-900 hover:text-gray-600 hover:underline font-semibold"><?= $e(sprintf(__('n. %s'), $fascicoloNumero)) ?></a>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right: the record -->
    <div class="lg:col-span-2 space-y-6">
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-info-circle text-gray-900"></i>
            <?= __('Dettagli') ?>
          </h2>
        </div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($details as $label => $text): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= $e($label) ?></dt>
              <dd class="text-gray-900 font-medium"><?= $e($text) ?></dd>
            </div>
            <?php endforeach; ?>
            <?php if ($keywords !== []): ?>
            <div class="sm:col-span-2">
              <dt class="text-xs uppercase text-gray-500"><?= __('Parole chiave') ?></dt>
              <dd class="mt-1 flex flex-wrap gap-2">
                <?php foreach ($keywords as $keyword): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700"><?= $e($keyword) ?></span>
                <?php endforeach; ?>
              </dd>
            </div>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <?php if ($value('abstract') !== ''): ?>
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-align-left text-gray-900"></i>
            <?= __('Abstract') ?>
          </h2>
        </div>
        <div class="card-body text-gray-700 whitespace-pre-line"><?= $e($value('abstract')) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($value('note_private') !== ''): ?>
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-sticky-note text-gray-900"></i>
            <?= __('Note') ?>
          </h2>
        </div>
        <div class="card-body text-gray-700 whitespace-pre-line"><?= $e($value('note_private')) ?></div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-paperclip text-gray-900"></i>
            <?= __('File e citazione') ?>
          </h2>
        </div>
        <div class="card-body flex flex-col sm:flex-row sm:flex-wrap gap-3">
          <?php if (!empty($row['pdf_path'])): ?>
          <a href="<?= $e(url('/admin/periodicals/articles/' . $id . '/pdf')) ?>" class="<?= $btnGhost ?> justify-center">
            <i class="fas fa-file-pdf"></i>
            <?= $e($value('pdf_nome_originale') !== '' ? $value('pdf_nome_originale') : __('PDF')) ?>
          </a>
          <?php endif; ?>
          <?php if ($resource !== null && $resource['linkable']): ?>
          <a href="<?= $e($resource['url']) ?>" target="_blank" rel="noopener noreferrer" class="<?= $btnGhost ?> justify-center">
            <i class="fas fa-external-link-alt"></i>
            <?= $e($resource['text'] !== '' ? $resource['text'] : $resource['url']) ?>
          </a>
          <?php endif; ?>
          <a href="<?= $e(url('/admin/periodicals/articles/' . $id . '/citation.ris')) ?>" class="<?= $btnGhost ?> justify-center">
            <i class="fas fa-download"></i>
            RIS
          </a>
          <a href="<?= $e(url('/admin/periodicals/articles/' . $id . '/marc.xml')) ?>" class="<?= $btnGhost ?> justify-center">
            <i class="fas fa-code"></i>
            MARCXML
          </a>
        </div>
      </div>
    </div>
  </div>
  </div>
</section>
