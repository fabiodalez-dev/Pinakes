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
 * The controller already validated every entry (plain-text label, same-origin
 * relative URL); this file only escapes and prints.
 *
 * @var array<int, array{label: string, url: string}> $externalSearchSuggestions
 * @var string $searchTerm
 */

if (empty($externalSearchSuggestions)) {
    return;
}
?>
<div id="external-search-suggestions" class="mt-4 p-3 rounded border" style="background:var(--light-bg,#f8f9fa);border-color:var(--border-color,#e5e7eb)!important;">
    <p class="text-sm font-semibold text-gray-500 mb-2">
        <i class="fas fa-search mr-1"></i>
        <?= htmlspecialchars(__('Cerca "%s" anche in:', $searchTerm), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <ul class="mb-0 list-none">
        <?php foreach ($externalSearchSuggestions as $suggestion): ?>
        <li class="mb-1">
            <a href="<?= htmlspecialchars((string) $suggestion['url'], ENT_QUOTES, 'UTF-8') ?>" class="no-underline">
                <?= htmlspecialchars((string) $suggestion['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
