<?php
/**
 * Emeroteca — public index of testate.
 *
 * Three switchable views (?vista=az|editore|argomento) plus a simple
 * search (?q= over titolo / sottotitolo / ISSN). Chrome copied from the
 * Archives plugin public views (same card / form-input / ui-button
 * classes, all present in the compiled main.css).
 *
 * @var list<array<string, mixed>> $rows
 * @var string                     $q
 * @var string                     $vista
 * @var array<string, string>      $tipoLabels
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$emerotecaUrl = htmlspecialchars(url('/emeroteca'), ENT_QUOTES, 'UTF-8');

// Resolve a stored logo/cover reference: absolute URLs pass through,
// site-relative paths go through url() (base-path aware).
$asset = static function (string $path): string {
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return url($path[0] === '/' ? $path : '/' . $path);
};

// "Anni coperti": prefer the real annate range, fall back to the
// declared publication years of the testata.
$yearsLabel = static function (array $t): string {
    $from = $t['anno_min'] ?? $t['anno_inizio'] ?? null;
    $to   = $t['anno_max'] ?? $t['anno_fine'] ?? null;
    if ($from === null && $to === null) {
        return '';
    }
    if ($from !== null && $to !== null && (int) $from !== (int) $to) {
        return (string) (int) $from . '–' . (string) (int) $to;
    }
    return (string) (int) ($from ?? $to);
};

$vistaTabs = [
    'az'        => __('A–Z'),
    'editore'   => __('Per editore'),
    'argomento' => __('Per argomento'),
];

// Group rows for the editore/argomento views (rows arrive pre-sorted by
// the controller; ungrouped entries go last under a fallback header).
$groups = [];
if ($vista === 'editore' || $vista === 'argomento') {
    $field = $vista === 'editore' ? 'editore_nome' : 'genere_nome';
    $fallback = $vista === 'editore' ? __('Senza editore') : __('Senza argomento');
    foreach ($rows as $row) {
        $key = trim((string) ($row[$field] ?? ''));
        if ($key === '') {
            $key = $fallback;
        }
        $groups[$key][] = $row;
    }
} else {
    $groups[''] = $rows;
}
?>
<style id="emeroteca-index-css">
/* Emeroteca public index — plugin-scoped styles (Tailwind JIT: new
   utility classes would not exist in the compiled main.css). */
#emeroteca-index .emeroteca-hero h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: .5rem; }
#emeroteca-index .emeroteca-hero p { color: #6b7280; max-width: 46rem; }
#emeroteca-index .emeroteca-logo-box {
    width: 100%; height: 9rem; display: flex; align-items: center; justify-content: center;
    background: #f3f4f6; border-radius: .375rem; overflow: hidden; margin-bottom: .75rem;
}
#emeroteca-index .emeroteca-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
#emeroteca-index .emeroteca-logo-box i { font-size: 2.25rem; color: #9ca3af; }
#emeroteca-index .emeroteca-group-title {
    font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em;
    color: #6b7280; border-bottom: 1px solid #e5e7eb; padding-bottom: .35rem;
    margin: 1.5rem 0 1rem;
}
#emeroteca-index .emeroteca-vista-tab {
    display: inline-flex; align-items: center; padding: .4rem .9rem; border-radius: 9999px;
    border: 1px solid #d1d5db; color: #374151; font-size: .85rem; text-decoration: none;
}
#emeroteca-index .emeroteca-vista-tab.active {
    background: var(--primary-color, #d70161); border-color: var(--primary-color, #d70161); color: #fff;
}
</style>

<main id="emeroteca-index" class="container py-4">
    <section class="emeroteca-hero mb-4">
        <h1><?= __('Emeroteca') ?></h1>
        <p><?= __('Consulta le testate di riviste, giornali e periodici conservate in emeroteca, con le annate e i fascicoli disponibili.') ?></p>
    </section>

    <!-- Barra di ricerca -->
    <form method="GET" action="<?= $emerotecaUrl ?>" class="mb-3">
        <input type="hidden" name="vista" value="<?= $e($vista) ?>">
        <div class="flex flex-wrap -mx-3 gap-y-2 items-end">
            <div class="w-full px-3 md:w-1/2">
                <label for="eme-q" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __('Ricerca') ?>
                </label>
                <input id="eme-q" type="search" name="q" value="<?= $e($q) ?>"
                       class="form-input"
                       placeholder="<?= $e(__('Titolo, sottotitolo, ISSN…')) ?>">
            </div>
            <div class="flex w-full gap-2 px-3 md:w-1/6">
                <button type="submit" class="ui-button btn-primary w-full">
                    <?= __('Cerca') ?>
                </button>
                <?php if ($q !== ''): ?>
                    <a href="<?= $e(url('/emeroteca?vista=' . $vista)) ?>" class="ui-button btn-outline"
                       aria-label="<?= $e(__('Azzera filtri')) ?>" title="<?= $e(__('Azzera filtri')) ?>"
                       style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Viste commutabili -->
    <nav class="flex flex-wrap gap-2 mb-3" aria-label="<?= $e(__('Viste')) ?>">
        <?php foreach ($vistaTabs as $key => $label):
            $tabHref = url('/emeroteca?vista=' . $key . ($q !== '' ? '&q=' . rawurlencode($q) : ''));
        ?>
            <a class="emeroteca-vista-tab<?= $vista === $key ? ' active' : '' ?>"
               href="<?= $e($tabHref) ?>"<?= $vista === $key ? ' aria-current="page"' : '' ?>>
                <?= $e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($q !== ''): ?>
        <p class="text-gray-500 text-sm mb-3">
            <?= sprintf(__('%d testate trovate'), count($rows)) ?>
            <?= __('per') ?> <strong><?= $e($q) ?></strong>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <?php if ($q !== ''): ?>
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="alert">
                <?= __('Nessun risultato') ?> <?= __('per') ?> <strong><?= $e($q) ?></strong>.
                <a href="<?= $e(url('/emeroteca?vista=' . $vista)) ?>" class="font-semibold underline underline-offset-2"><?= __('Mostra tutto') ?></a>
            </div>
        <?php else: ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                <strong><?= __('Nessuna testata pubblicata.') ?></strong>
                <?= __("L'emeroteca non contiene ancora testate.") ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($groups as $groupTitle => $groupRows): ?>
            <?php if ((string) $groupTitle !== ''): ?>
                <h2 class="emeroteca-group-title"><?= $e($groupTitle) ?></h2>
            <?php endif; ?>
            <div class="flex flex-wrap -mx-3 gap-y-3">
                <?php foreach ($groupRows as $row):
                    $detailUrl = $e(url('/emeroteca/' . (int) $row['id']));
                    $logo = $asset((string) ($row['logo_url'] ?? ''));
                    $anni = $yearsLabel($row);
                ?>
                    <div class="w-full px-3 sm:w-1/2 lg:w-1/3">
                        <article class="card rounded-md">
                            <div class="card-body">
                                <a href="<?= $detailUrl ?>" class="emeroteca-logo-box"
                                   aria-label="<?= $e((string) $row['titolo']) ?>">
                                    <?php if ($logo !== ''): ?>
                                        <img src="<?= $e($logo) ?>"
                                             alt="<?= $e((string) $row['titolo']) ?>"
                                             loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <i class="fas fa-newspaper" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </a>
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="status-badge bg-sky-100 text-sky-800">
                                        <?= $e($tipoLabels[(string) $row['tipo']] ?? (string) $row['tipo']) ?>
                                    </span>
                                    <?php if ($anni !== ''): ?>
                                        <span class="text-gray-500 text-sm"><?= $e($anni) ?></span>
                                    <?php endif; ?>
                                </div>
                                <h3 class="mb-1 text-base font-semibold">
                                    <a href="<?= $detailUrl ?>"><?= $e((string) $row['titolo']) ?></a>
                                </h3>
                                <?php if (!empty($row['sottotitolo'])): ?>
                                    <p class="text-gray-500 text-sm mb-2"><?= $e((string) $row['sottotitolo']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($row['editore_nome'])): ?>
                                    <p class="mb-0 text-sm text-gray-500">
                                        <?= __('Editore:') ?> <?= $e((string) $row['editore_nome']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($q === ''): ?>
            <p class="text-gray-500 text-sm mt-3">
                <?= sprintf(__('%d testate in emeroteca.'), count($rows)) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</main>
