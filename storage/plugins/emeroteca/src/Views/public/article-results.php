<?php
/**
 * Shared grid of public articles: the article search, the emeroteca home, a
 * masthead page, an issue's contents and an article's "related" sections all
 * render this block, so an article card is the same everywhere — and the same
 * as in the mixed /catalogo grid, because the markup itself is the core's
 * app/Views/frontend/partials/article-card.php.
 *
 * Each card carries its image — its own, else its issue's cover, else the
 * masthead's logo, else the catalogue's placeholder, resolved by
 * ContributionService::coverUrl() so this block and the article page cannot
 * disagree — and the links that make the list navigable: confirmed authors
 * open the shared author archive, unlinked names open a catalogue filter, the
 * masthead and the issue open their own pages. Citation names remain free
 * text and are never automatically merged into authority records.
 *
 * The block decides no width and no heading: callers put it inside their own
 * container and section.
 *
 * @var array{rows: array<int, array<string, mixed>>} $articleResults
 * @var array{title?: string, text?: string, ctaHref?: string, ctaLabel?: string}|null $articleEmpty
 *      what to say when there are no rows; null renders nothing at all
 * @var bool $articleGridWrap false when the caller has opened the grid itself,
 *      to put other cards in it (the books of the same author, #453)
 */
$articleResults = $articleResults ?? ['rows' => []];
$articleGridWrap = array_key_exists('articleGridWrap', get_defined_vars()) ? $articleGridWrap !== false : true;
$articleEmpty = array_key_exists('articleEmpty', get_defined_vars()) ? $articleEmpty : [];
// Plugin classes have no autoloader scope and a view must not depend on the
// controller having loaded them: require the service before reading it.
require_once __DIR__ . '/../../Services/ContributionService.php';
$articleCorePartials = dirname(__DIR__, 6) . '/app/Views/frontend/partials';
/**
 * Emptiness for a rendered metadata part: the stored value, not PHP's notion
 * of truth. A periodical's pilot issue really is numbered "0", and a bare
 * array_filter() would drop it from the line.
 */
$articleKeep = static fn(mixed $value): bool => trim((string) $value) !== '';
/** Name filter: linked authors open their archive; free-text names a catalogue search. */
$articleAuthorHref = static fn(array $an): string => $an['id'] !== null
    ? route_path('author') . '/' . $an['id']
    : route_path('catalog') . '?' . http_build_query(['autore' => $an['name']]);
?>
<?php if (!$articleResults['rows']): ?>
    <?php if ($articleEmpty !== null):
        $emptyTitle = $articleEmpty['title'] ?? __('Nessun articolo disponibile.');
        $emptyText = $articleEmpty['text'] ?? '';
        $emptyCtaHref = $articleEmpty['ctaHref'] ?? '';
        $emptyCtaLabel = $articleEmpty['ctaLabel'] ?? '';
        $emptyIcon = 'fa-newspaper';
        include $articleCorePartials . '/empty-state.php';
    endif; ?>
<?php else: ?>
<?php if ($articleGridWrap): ?><div class="books-grid emeroteca-articles-grid"><?php endif; ?>
<?php foreach ($articleResults['rows'] as $a):
    $articleLinks = \App\Plugins\Emeroteca\Services\ContributionService::authorLinks($a);
    $articleMeta = [];
    // Where it was published: the masthead page when the article belongs to
    // one, else the free-text container title as a search narrowing.
    if (!empty($a['testata_id']) && ($a['testata_titolo'] ?? '') !== '') {
        $articleMeta[] = ['label' => (string) $a['testata_titolo'], 'href' => url(\App\Support\RouteTranslator::route('periodicals') . '/' . (int) $a['testata_id'])];
    } elseif (($a['contenitore_titolo'] ?? '') !== '') {
        $articleMeta[] = ['label' => (string) $a['contenitore_titolo'], 'href' => url(\App\Support\RouteTranslator::route('periodicals') . '/articoli') . '?' . http_build_query(['pubblicazione' => (string) $a['contenitore_titolo']])];
    }
    // Which issue: the issue page when it is placed in one, else whatever
    // the citation itself says (date, volume, number).
    if (!empty($a['fascicolo_id']) && $articleKeep($a['fascicolo_numero'] ?? '')) {
        $articleMeta[] = [
            'label' => sprintf(__('n. %s'), (string) $a['fascicolo_numero']) . ($articleKeep($a['fascicolo_anno'] ?? '') ? ' (' . (int) $a['fascicolo_anno'] . ')' : ''),
            'href' => url(\App\Support\RouteTranslator::route('periodicals') . '/fascicolo/' . (int) $a['fascicolo_id']),
        ];
    } else {
        foreach (array_filter([$a['data_pubblicazione_testo'] ?? '', $a['volume'] ?? '', $a['numero'] ?? ''], $articleKeep) as $part) {
            $articleMeta[] = ['label' => (string) $part];
        }
    }
    if ($articleKeep($a['pagine'] ?? '')) {
        $articleMeta[] = ['label' => (string) $a['pagine']];
    }
    $resultCover = \App\Plugins\Emeroteca\Services\ContributionService::coverUrl($a);
    $articleCard = [
        'id' => (int) $a['id'],
        'url' => url(\App\Support\RouteTranslator::route('periodicals') . '/articolo/' . (int) $a['id']),
        'title' => (string) $a['titolo'],
        'cover' => $resultCover !== '' ? url($resultCover) : '',
        'subtitle' => (string) ($a['sottotitolo'] ?? ''),
        'authors' => array_map(static fn(array $an): array => ['name' => $an['name'], 'href' => $articleAuthorHref($an)], $articleLinks),
        'authorsText' => (string) ($a['autori'] ?? ''),
        'meta' => $articleMeta,
    ];
    include $articleCorePartials . '/article-card.php';
endforeach; ?>
<?php if ($articleGridWrap): ?></div><?php endif; ?>
<?php endif; ?>
