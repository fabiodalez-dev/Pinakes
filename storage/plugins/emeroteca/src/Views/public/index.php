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
 * @var string                     $tipo
 * @var array<string, string>      $availableTypes
 * @var array<string, int>         $typeCounts
 * @var array<string, string>      $tipoLabels
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$emerotecaUrl = htmlspecialchars(url('/emeroteca'), ENT_QUOTES, 'UTF-8');
$tipo = isset($tipo) && is_string($tipo) ? $tipo : '';
$availableTypes = isset($availableTypes) && is_array($availableTypes) ? $availableTypes : [];
$typeCounts = isset($typeCounts) && is_array($typeCounts) ? $typeCounts : [];

/** Build a base-path-aware Emeroteca URL while preserving active filters. */
$filteredUrl = static function (array $overrides = []) use ($q, $vista, $tipo): string {
    $params = array_merge(['vista' => $vista, 'q' => $q, 'tipo' => $tipo], $overrides);
    $params = array_filter($params, static fn(mixed $value): bool => $value !== '' && $value !== null);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return url('/emeroteca' . ($query !== '' ? '?' . $query : ''));
};

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
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.3')) ?>">

<main id="emeroteca-index" class="container emeroteca-public">
    <section class="emeroteca-hero mb-4">
        <h1><?= __('Emeroteca') ?></h1>
        <p><?= __('Consulta le testate di riviste, giornali e periodici conservate in emeroteca, con le annate e i fascicoli disponibili.') ?></p>
    </section>

    <!-- Barra di ricerca -->
    <form method="GET" action="<?= $emerotecaUrl ?>" class="emeroteca-search">
        <input type="hidden" name="vista" value="<?= $e($vista) ?>">
        <div class="emeroteca-search-grid">
            <div>
                <label for="eme-q" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __('Ricerca') ?>
                </label>
                <input id="eme-q" type="search" name="q" value="<?= $e($q) ?>"
                       class="form-input"
                       placeholder="<?= $e(__('Titolo, sottotitolo, ISSN o articolo…')) ?>">
            </div>
            <?php if ($availableTypes !== []): ?>
                <div>
                    <label for="eme-tipo" class="form-label text-sm font-semibold text-gray-500 mb-1">
                        <?= __('Tipologia') ?>
                    </label>
                    <select id="eme-tipo" name="tipo" class="form-input">
                        <option value=""><?= __('Tutte le tipologie') ?></option>
                        <?php foreach ($availableTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= $e($typeKey) ?>"<?= $tipo === $typeKey ? ' selected' : '' ?>>
                                <?= $e(__($typeLabel)) ?> (<?= (int) ($typeCounts[$typeKey] ?? 0) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="emeroteca-search-actions">
                <button type="submit" class="ui-button btn-primary w-full">
                    <?= __('Cerca') ?>
                </button>
                <?php if ($q !== '' || $tipo !== ''): ?>
                    <a href="<?= $e($filteredUrl(['q' => '', 'tipo' => ''])) ?>" class="ui-button btn-outline emeroteca-clear"
                       aria-label="<?= $e(__('Azzera filtri')) ?>" title="<?= $e(__('Azzera filtri')) ?>">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Viste commutabili -->
    <nav class="flex flex-wrap gap-2 mb-3" aria-label="<?= $e(__('Viste')) ?>">
        <?php foreach ($vistaTabs as $key => $label):
            $tabHref = $filteredUrl(['vista' => $key]);
        ?>
            <a class="emeroteca-vista-tab<?= $vista === $key ? ' active' : '' ?>"
               href="<?= $e($tabHref) ?>"<?= $vista === $key ? ' aria-current="page"' : '' ?>>
                <?= $e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($q !== '' || $tipo !== ''): ?>
        <p class="text-gray-500 text-sm mb-3">
            <?= sprintf(__('%d testate trovate'), count($rows)) ?>
            <?php if ($q !== ''): ?>
                <?= __('per') ?> <strong><?= $e($q) ?></strong>
            <?php endif; ?>
            <?php if ($tipo !== ''): ?>
                <span class="status-badge bg-gray-100 text-gray-700 ml-2"><?= $e(__($tipoLabels[$tipo] ?? $tipo)) ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <?php if ($q !== '' || $tipo !== ''): ?>
            <div class="emeroteca-notice" role="alert">
                <?= __('Nessun risultato') ?><?php if ($q !== ''): ?> <?= __('per') ?> <strong><?= $e($q) ?></strong><?php endif; ?>.
                <a href="<?= $e($filteredUrl(['q' => '', 'tipo' => ''])) ?>" class="font-semibold underline underline-offset-2"><?= __('Mostra tutto') ?></a>
            </div>
        <?php else: ?>
            <div class="emeroteca-notice" role="alert">
                <strong><?= __('Nessuna testata pubblicata.') ?></strong>
                <?= __("L'emeroteca non contiene ancora testate.") ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php foreach ($groups as $groupTitle => $groupRows): ?>
            <?php if ((string) $groupTitle !== ''): ?>
                <h2 class="emeroteca-group-title"><?= $e($groupTitle) ?></h2>
            <?php endif; ?>
            <div class="emeroteca-record-list">
                <?php foreach ($groupRows as $row):
                    $detailUrl = $e(url('/emeroteca/' . (int) $row['id']));
                    $logo = $asset((string) ($row['logo_url'] ?? ''));
                    $anni = $yearsLabel($row);
                ?>
                    <article class="emeroteca-record">
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
                            <div>
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
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if ($q === '' && $tipo === ''): ?>
            <p class="text-gray-500 text-sm mt-3">
                <?= sprintf(__('%d testate in emeroteca.'), count($rows)) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</main>
