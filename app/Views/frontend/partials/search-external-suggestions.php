<?php
/**
 * Federated-search hint for the catalogue results page.
 *
 * Rendered when a plugin answered the `search.external_suggestions` filter
 * (see FrontendController::collectExternalSearchSuggestions() for the
 * contract): the catalogue only searches libri.search_index, so a term that
 * lives in a plugin corpus — periodicals, archives — needs a way out of the
 * "no results" dead end. Shown both when the catalogue found nothing and when
 * it found something the plugin can complement.
 *
 * A suggestion may carry the matches themselves in 'items'. When it does they
 * are listed here: a visitor who searched for an article title should read the
 * article's title back, not be told to search a second time somewhere else.
 * Without items the block degrades to the plain section link it always was.
 *
 * The controller already validated every entry (plain-text label and meta,
 * same-origin relative URL); this file only escapes and prints.
 *
 * @var array<int, array{label: string, url: string, items?: array<int, array{label: string, url: string, meta: string}>, total?: int}> $externalSearchSuggestions
 * @var string $searchTerm
 */

if (empty($externalSearchSuggestions)) {
    return;
}
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div id="external-search-suggestions" class="mt-4 p-3 rounded border" style="background:var(--light-bg,#f8f9fa);border-color:var(--border-color,#e5e7eb)!important;">
    <p class="text-sm font-semibold text-gray-500 mb-2">
        <i class="fas fa-search mr-1"></i>
        <?= $esc(__('Cerca "%s" anche in:', $searchTerm)) ?>
    </p>
    <ul class="mb-0 list-none">
        <?php foreach ($externalSearchSuggestions as $suggestion): ?>
        <?php
        $items = is_array($suggestion['items'] ?? null) ? $suggestion['items'] : [];
        $total = (int) ($suggestion['total'] ?? 0);
        ?>
        <li class="mb-1">
            <a href="<?= $esc($suggestion['url']) ?>" class="no-underline">
                <?= $esc($suggestion['label']) ?>
            </a>
            <?php if ($items !== []): ?>
            <ul class="mb-0 list-none" style="margin-left:1.25rem;margin-top:.35rem;">
                <?php foreach ($items as $item): ?>
                <li class="mb-1">
                    <a href="<?= $esc($item['url']) ?>" class="no-underline"><?= $esc($item['label']) ?></a>
                    <?php if ($item['meta'] !== ''): ?>
                    <span class="text-gray-500 text-sm ml-1"><?= $esc($item['meta']) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
                <?php if ($total > count($items)): ?>
                <li class="mb-1">
                    <a href="<?= $esc($suggestion['url']) ?>" class="no-underline text-sm">
                        <?= $esc(__('Vedi tutti i %d risultati', $total)) ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
