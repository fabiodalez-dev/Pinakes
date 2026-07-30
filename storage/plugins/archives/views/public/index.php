<?php
/**
 * Public index — list of root-level archival_units (or search results).
 *
 * @var list<array<string, mixed>> $rows
 * @var int                        $total
 * @var string|null                $q
 * @var string|null                $level
 * @var string|null                $date_from
 * @var string|null                $date_to
 * @var bool|null                  $isSearch
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];
$levelBadgeClass = [
    'fonds'  => 'bg-[var(--primary-color)] text-white',
    'series' => 'bg-sky-100 text-sky-800',
    'file'   => 'bg-emerald-100 text-emerald-800',
    'item'   => 'bg-slate-100 text-slate-700',
];
$archiveBase = \App\Support\RouteTranslator::route('archives') ?: '/archive';
$q        = $q        ?? '';
$level    = $level    ?? '';
$dateFrom = $date_from ?? '';
$dateTo   = $date_to   ?? '';
$isSearch = $isSearch  ?? false;
$archiveUrl = htmlspecialchars(url($archiveBase), ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/archives/assets/css/archives-public.css')) ?>">

<main class="container py-4">
    <section class="archive-hero-index">
        <h1><?= __("Archivio") ?></h1>
        <p>
            <?= __("Consulta i fondi archivistici e le collezioni documentarie. Ogni unità è descritta secondo lo standard ISAD(G) — navigazione gerarchica per fondo, serie, fascicolo, unità.") ?>
        </p>
    </section>

    <!-- Barra di ricerca -->
    <form method="GET" action="<?= $archiveUrl ?>" class="archive-search-form mb-4">
        <div class="flex flex-wrap -mx-3 gap-y-2 items-end">
            <div class="w-full px-3 md:w-5/12">
                <label for="arc-q" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __("Ricerca") ?>
                </label>
                <div class="flex items-stretch">
                    <span class="inline-flex items-center px-3 border border-gray-300 bg-gray-100 text-gray-600 archive-search-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/>
                        </svg>
                    </span>
                    <input id="arc-q" type="search" name="q" value="<?= $e($q) ?>"
                           class="form-input"
                           placeholder="<?= $e(__("Titolo, reference code, descrizione…")) ?>">
                </div>
            </div>
            <div class="w-1/2 px-3 md:w-1/6">
                <label for="arc-level" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __("Livello") ?>
                </label>
                <select id="arc-level" name="level" class="form-input">
                    <option value=""><?= __("Tutti") ?></option>
                    <option value="fonds"  <?= $level === 'fonds'  ? 'selected' : '' ?>><?= __("Fondo")     ?></option>
                    <option value="series" <?= $level === 'series' ? 'selected' : '' ?>><?= __("Serie")     ?></option>
                    <option value="file"   <?= $level === 'file'   ? 'selected' : '' ?>><?= __("Fascicolo") ?></option>
                    <option value="item"   <?= $level === 'item'   ? 'selected' : '' ?>><?= __("Unità")     ?></option>
                </select>
            </div>
            <div class="w-1/2 px-3 md:w-1/6">
                <label for="arc-from" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __("Anno dal") ?>
                </label>
                <input id="arc-from" type="number" name="date_from" value="<?= $e($dateFrom) ?>"
                       min="-9999" max="9999"
                       class="form-input"
                       placeholder="<?= $e(__("es. 1900")) ?>">
            </div>
            <div class="w-1/2 px-3 md:w-1/6">
                <label for="arc-to" class="form-label text-sm font-semibold text-gray-500 mb-1">
                    <?= __("Anno al") ?>
                </label>
                <input id="arc-to" type="number" name="date_to" value="<?= $e($dateTo) ?>"
                       min="-9999" max="9999"
                       class="form-input"
                       placeholder="<?= $e(__("es. 1950")) ?>">
            </div>
            <div class="flex w-1/2 gap-2 px-3 md:w-1/12">
                <button type="submit" class="ui-button btn-primary w-full">
                    <?= __("Cerca") ?>
                </button>
                <?php if ($isSearch): ?>
                    <a href="<?= $archiveUrl ?>" class="ui-button btn-outline" aria-label="<?= $e(__("Azzera filtri")) ?>" title="<?= $e(__("Azzera filtri")) ?>" style="min-width:44px;min-height:44px;display:inline-flex;align-items:center;justify-content:center;">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if ($isSearch && !empty($rows)): ?>
        <p class="text-gray-500 text-sm mb-3">
            <?= __n("%d risultato", "%d risultati", $total) ?>
            <?php if ($q !== ''): ?>
                <?= __("per") ?> <strong><?= $e($q) ?></strong>
            <?php endif; ?>
            <?php if ($level !== ''): ?>
                · <?= $e($levelLabel[$level] ?? $level) ?>
            <?php endif; ?>
            <?php if ($dateFrom !== '' && $dateTo !== ''): ?>
                · <?= $e($dateFrom) ?>–<?= $e($dateTo) ?>
            <?php elseif ($dateFrom !== ''): ?>
                · <?= __("dal") ?> <?= $e($dateFrom) ?>
            <?php elseif ($dateTo !== ''): ?>
                · <?= __("fino al") ?> <?= $e($dateTo) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <?php if ($isSearch): ?>
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="alert">
                <?= __("Nessun risultato") ?>
                <?php if ($q !== ''): ?> <?= __("per") ?> <strong><?= $e($q) ?></strong><?php endif; ?>.
                <a href="<?= $archiveUrl ?>" class="font-semibold underline underline-offset-2"><?= __("Mostra tutto") ?></a>
            </div>
        <?php else: ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                <strong><?= __("Nessun fondo pubblicato.") ?></strong>
                <?= __("L'archivio non contiene ancora unità di primo livello.") ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="flex flex-wrap -mx-3 gap-y-3">
            <?php foreach ($rows as $row):
                $lvl  = (string) $row['level'];
                $badge = $levelBadgeClass[$lvl] ?? 'bg-slate-100 text-slate-700';
                $detailUrl = $e(url($archiveBase . '/' . slugify_text((string) $row['constructed_title']) . '-' . (int) $row['id']));
                $dateRange = '';
                if (!empty($row['date_start'])) {
                    $dateRange = (string) $row['date_start'];
                    if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']) {
                        $dateRange .= '–' . (string) $row['date_end'];
                    }
                }
            ?>
                <div class="w-full px-3 md:w-1/2 lg:w-1/3">
                    <article class="card archive-card rounded-md">
                        <div class="card-body">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="status-badge <?= $e($badge) ?>"><?= $e($levelLabel[$lvl] ?? $lvl) ?></span>
                                <span class="archive-ref"><?= $e((string) $row['reference_code']) ?></span>
                            </div>
                            <h2 class="card-title mb-1 text-base font-semibold">
                                <a href="<?= $detailUrl ?>"><?= $e((string) $row['constructed_title']) ?></a>
                            </h2>
                            <?php if ($dateRange !== ''): ?>
                                <p class="text-gray-500 text-sm mb-2"><?= $e($dateRange) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row['scope_content'])): ?>
                                <p class="text-gray-600 text-sm text-gray-500 mb-2">
                                    <?= $e(mb_substr((string) $row['scope_content'], 0, 180)) ?><?= mb_strlen((string) $row['scope_content']) > 180 ? '…' : '' ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($row['extent'])): ?>
                                <p class="mb-0 text-sm italic text-gray-500"><?= $e((string) $row['extent']) ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$isSearch): ?>
            <p class="text-gray-500 text-sm mt-3">
                <?= sprintf(__("%d unità archivistiche di primo livello."), $total) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</main>
