<?php
/**
 * Emeroteca — public fascicolo (issue) detail.
 *
 * Big cover (or placeholder), data sheet (numero, data, pagine,
 * supplementi, collocazione), spoglio (article TOC) and prev/next
 * navigation within the annata.
 *
 * @var array<string, mixed>            $fascicolo
 * @var list<array<string, mixed>>      $articoli
 * @var array<string, mixed>|null       $collocazione
 * @var array<string, mixed>|null       $prev
 * @var array<string, mixed>|null       $next
 * @var array<string, string>           $statoFascicoloLabels
 * @var array<string, string>           $tipoArticoloLabels
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$asset = static function (string $path): string {
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return url($path[0] === '/' ? $path : '/' . $path);
};

$fascicoloId = (int) $fascicolo['id'];
$testataId   = (int) $fascicolo['testata_id'];
$anno        = (int) $fascicolo['anno'];
$stato       = (string) $fascicolo['stato'];
$cover       = $asset((string) ($fascicolo['copertina_url'] ?? ''));

$statoBadgeClass = [
    'posseduto'   => 'bg-emerald-100 text-emerald-800',
    'mancante'    => 'bg-slate-100 text-slate-700',
    'danneggiato' => 'bg-amber-50 text-amber-900',
    'in_restauro' => 'bg-sky-100 text-sky-800',
    'smarrito'    => 'bg-slate-100 text-slate-700',
    'atteso'      => 'bg-gray-100 text-gray-800',
];
$badge = $statoBadgeClass[$stato] ?? 'bg-gray-100 text-gray-800';

// Display date: prefer the free-text cover date, else the publication
// date formatted with the core helper when available.
$dataLabel = trim((string) ($fascicolo['data_copertina'] ?? ''));
if ($dataLabel === '' && !empty($fascicolo['data_pubblicazione'])) {
    $dataLabel = function_exists('format_date')
        ? (string) format_date((string) $fascicolo['data_pubblicazione'], false, '/')
        : (string) $fascicolo['data_pubblicazione'];
}

// Collocazione label: core model "scaffale.livello" (scaffali → mensole),
// same shape as LibriController::resolveCollocazione.
$collocazioneLabel = '';
if ($collocazione !== null) {
    $parts = [];
    if (!empty($collocazione['scaffale_codice'])) {
        $parts[] = (string) $collocazione['scaffale_codice'];
    } elseif (!empty($collocazione['scaffale_nome'])) {
        $parts[] = (string) $collocazione['scaffale_nome'];
    }
    if (isset($collocazione['numero_livello'])) {
        $parts[] = (string) (int) $collocazione['numero_livello'];
    }
    $collocazioneLabel = implode('.', $parts);
    if (!empty($collocazione['mensola_descrizione'])) {
        $collocazioneLabel .= ' — ' . (string) $collocazione['mensola_descrizione'];
    }
}

$issueLabel = sprintf(__('n. %s'), (string) $fascicolo['numero']);
$pagesLabel = static function (array $a): string {
    $start = $a['pagina_inizio'] !== null ? (int) $a['pagina_inizio'] : null;
    $end   = $a['pagina_fine'] !== null ? (int) $a['pagina_fine'] : null;
    if ($start === null) {
        return '';
    }
    if ($end !== null && $end !== $start) {
        return sprintf(__('pp. %d–%d'), $start, $end);
    }
    return sprintf(__('p. %d'), $start);
};

// ── Schema.org PublicationIssue with isPartOf Periodical ──────────────
$baseAbs = rtrim(\App\Support\HtmlHelper::getBaseUrl(), '/');
$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'PublicationIssue',
    'issueNumber' => (string) $fascicolo['numero'],
    'name'        => (string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel . ' (' . $anno . ')',
    'url'         => $baseAbs . '/emeroteca/fascicolo/' . $fascicoloId,
    'datePublished' => (string) ($fascicolo['data_pubblicazione'] ?? ''),
    'isPartOf'    => array_filter([
        '@type' => 'Periodical',
        'name'  => (string) $fascicolo['testata_titolo'],
        'issn'  => (string) ($fascicolo['testata_issn'] ?? ''),
        'url'   => $baseAbs . '/emeroteca/' . $testataId,
    ], static fn($v) => $v !== ''),
];
if ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0) {
    $schema['numberOfPages'] = (int) $fascicolo['pagine'];
}
if ($cover !== '') {
    $schema['image'] = absoluteUrl($cover);
}
$schema = array_filter($schema, static fn($v) => $v !== '');
$emerotecaSchema = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<script type="application/ld+json"><?= $emerotecaSchema ?: '{}' ?></script>

<style id="emeroteca-fascicolo-css">
/* Emeroteca public fascicolo page — plugin-scoped styles (Tailwind JIT:
   new utility classes would not exist in the compiled main.css). */
#emeroteca-fascicolo .emeroteca-cover-large {
    width: 100%; max-width: 20rem; aspect-ratio: 3 / 4; background: #f3f4f6;
    border-radius: .5rem; overflow: hidden; display: flex; align-items: center;
    justify-content: center; flex-direction: column; gap: .5rem;
}
#emeroteca-fascicolo .emeroteca-cover-large img { width: 100%; height: 100%; object-fit: cover; }
#emeroteca-fascicolo .emeroteca-cover-large i { font-size: 3rem; color: #9ca3af; }
#emeroteca-fascicolo .emeroteca-cover-large .emeroteca-cover-missing {
    color: #9ca3af; font-size: .85rem; text-transform: uppercase; letter-spacing: .05em;
}
#emeroteca-fascicolo .emeroteca-dl dt {
    font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;
    color: #6b7280; margin-top: .75rem;
}
#emeroteca-fascicolo .emeroteca-dl dt:first-child { margin-top: 0; }
#emeroteca-fascicolo .emeroteca-dl dd { margin: .1rem 0 0; color: #111827; }
#emeroteca-fascicolo .emeroteca-toc-main { flex: 1 1 auto; min-width: 0; }
#emeroteca-fascicolo .emeroteca-toc-pages {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: #6b7280;
    white-space: nowrap;
}
</style>

<main id="emeroteca-fascicolo" class="container py-4">
    <nav aria-label="breadcrumb" class="mb-3 text-sm text-gray-500">
        <a href="<?= $e(url('/')) ?>"><?= __('Home') ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= $e(url('/emeroteca')) ?>"><?= __('Emeroteca') ?></a>
        <span aria-hidden="true">/</span>
        <a href="<?= $e(url('/emeroteca/' . $testataId . '?anno=' . $anno)) ?>"><?= $e((string) $fascicolo['testata_titolo']) ?></a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= $e($issueLabel . ' (' . $anno . ')') ?></span>
    </nav>

    <div class="flex flex-wrap -mx-3 gap-y-4">
        <!-- Copertina -->
        <div class="w-full px-3 md:w-1/3 flex justify-center">
            <div class="emeroteca-cover-large">
                <?php if ($cover !== ''): ?>
                    <img src="<?= $e($cover) ?>"
                         alt="<?= $e((string) $fascicolo['testata_titolo'] . ' — ' . $issueLabel) ?>">
                <?php elseif ($stato === 'mancante'): ?>
                    <i class="far fa-circle-xmark" aria-hidden="true"></i>
                    <span class="emeroteca-cover-missing"><?= __('Mancante') ?></span>
                <?php else: ?>
                    <i class="far fa-newspaper" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scheda -->
        <div class="w-full px-3 md:w-2/3">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <span class="status-badge <?= $e($badge) ?>">
                    <?= $e($statoFascicoloLabels[$stato] ?? $stato) ?>
                </span>
                <?php if (!empty($fascicolo['rilegata'])): ?>
                    <span class="status-badge bg-gray-100 text-gray-800"><?= __('Annata rilegata') ?></span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl font-semibold mb-1">
                <a href="<?= $e(url('/emeroteca/' . $testataId)) ?>" class="underline underline-offset-2">
                    <?= $e((string) $fascicolo['testata_titolo']) ?>
                </a>
                — <?= $e($issueLabel . ' (' . $anno . ')') ?>
            </h1>
            <?php if (!empty($fascicolo['titolo_fascicolo'])): ?>
                <p class="text-gray-500 italic mb-3"><?= $e((string) $fascicolo['titolo_fascicolo']) ?></p>
            <?php endif; ?>

            <div class="card rounded-md mb-4">
                <div class="card-body p-4">
                    <dl class="emeroteca-dl mb-0">
                        <dt><?= __('Numero') ?></dt>
                        <dd><?= $e((string) $fascicolo['numero']) ?><?php if (!empty($fascicolo['numero_progressivo'])): ?>
                            <span class="text-gray-500">(<?= $e(sprintf(__('progressivo %s'), (string) $fascicolo['numero_progressivo'])) ?>)</span>
                        <?php endif; ?></dd>
                        <?php if ($dataLabel !== ''): ?>
                            <dt><?= __('Data') ?></dt>
                            <dd><?= $e($dataLabel) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($fascicolo['volume'])): ?>
                            <dt><?= __('Volume') ?></dt>
                            <dd><?= $e((string) $fascicolo['volume']) ?></dd>
                        <?php endif; ?>
                        <?php if ($fascicolo['pagine'] !== null && (int) $fascicolo['pagine'] > 0): ?>
                            <dt><?= __('Pagine') ?></dt>
                            <dd><?= $e((string) (int) $fascicolo['pagine']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($fascicolo['supplementi'])): ?>
                            <dt><?= __('Supplementi') ?></dt>
                            <dd><?= $e((string) $fascicolo['supplementi']) ?></dd>
                        <?php endif; ?>
                        <?php if ($collocazioneLabel !== ''): ?>
                            <dt><?= __('Collocazione') ?></dt>
                            <dd><?= $e($collocazioneLabel) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Navigazione fascicoli dentro l'annata -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($prev !== null): ?>
                    <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/fascicolo/' . (int) $prev['id'])) ?>">
                        <i class="fas fa-chevron-left mr-2" aria-hidden="true"></i><?= $e(sprintf(__('n. %s'), (string) $prev['numero'])) ?>
                    </a>
                <?php endif; ?>
                <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/' . $testataId . '?anno=' . $anno)) ?>">
                    <?= $e(sprintf(__('Annata %d'), $anno)) ?>
                </a>
                <?php if ($next !== null): ?>
                    <a class="ui-button btn-outline" href="<?= $e(url('/emeroteca/fascicolo/' . (int) $next['id'])) ?>">
                        <?= $e(sprintf(__('n. %s'), (string) $next['numero'])) ?><i class="fas fa-chevron-right ml-2" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sommario (spoglio) -->
    <?php if (!empty($articoli)): ?>
        <section class="mt-4">
            <div class="card rounded-md">
                <div class="card-header">
                    <h2 class="mb-0 text-base font-semibold">
                        <i class="fas fa-list mr-2" aria-hidden="true"></i>
                        <?= sprintf(__('Sommario (%d)'), count($articoli)) ?>
                    </h2>
                </div>
                <ul class="divide-y divide-gray-200">
                    <?php foreach ($articoli as $art):
                        $pp = $pagesLabel($art);
                        $tipoArt = (string) $art['tipo'];
                    ?>
                        <li class="py-3 px-4 flex items-center gap-2">
                            <?php if ($tipoArt !== 'articolo'): ?>
                                <span class="status-badge bg-gray-100 text-gray-800">
                                    <?= $e($tipoArticoloLabels[$tipoArt] ?? $tipoArt) ?>
                                </span>
                            <?php endif; ?>
                            <span class="emeroteca-toc-main">
                                <span class="font-medium"><?= $e((string) $art['titolo']) ?></span>
                                <?php if (!empty($art['autori'])): ?>
                                    <span class="text-gray-500 text-sm"> — <?= $e((string) $art['autori']) ?></span>
                                <?php endif; ?>
                            </span>
                            <?php if ($pp !== ''): ?>
                                <span class="emeroteca-toc-pages"><?= $e($pp) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($fascicolo['note'])): ?>
        <section class="mt-4">
            <div class="card rounded-md">
                <div class="card-body p-4">
                    <h2 class="text-base font-semibold mb-2"><?= __('Note') ?></h2>
                    <p class="mb-0 text-gray-500" style="white-space: pre-wrap;"><?= $e((string) $fascicolo['note']) ?></p>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
