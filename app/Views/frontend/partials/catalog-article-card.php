<?php
/**
 * An article in a mixed catalogue page. The row comes from
 * UnifiedCatalogService, whose SQL already resolves the image (own cover, then
 * the issue's, then the masthead's — the same order as the emeroteca's
 * ContributionService::coverUrl()). Markup: partials/article-card.php.
 *
 * @var array<string,mixed> $book
 */
// Each name opens that author's page when the credit is linked to the shared
// registry, else a catalogue search for the name — the same links the
// emeroteca lists give (#412: the author is the way into their other works).
$articleAuthors = [];
if (!empty($book['author_credits']) && is_array($book['author_credits'])) {
    foreach ($book['author_credits'] as $credit) {
        $name = (string) ($credit['display_name'] ?? $credit['nome_credito'] ?? '');
        $id = isset($credit['autore_id']) ? (int) $credit['autore_id'] : 0;
        if ($name !== '') {
            $articleAuthors[] = ['name' => $name, 'href' => $id > 0 ? route_path('author') . '/' . $id : route_path('catalog') . '?' . http_build_query(['autore' => $name])];
        }
    }
} else {
    foreach (preg_split('/\s*;\s*/', trim((string) ($book['autori'] ?? ''))) ?: [] as $name) {
        if ($name !== '') {
            $articleAuthors[] = ['name' => $name, 'href' => route_path('catalog') . '?' . http_build_query(['autore' => $name])];
        }
    }
}
// Where it was published: the masthead page when the article belongs to one,
// else the free-text title as a search of the articles from that publication.
$articleSource = [];
if (!empty($book['testata_id']) && (string) ($book['testata_titolo'] ?? '') !== '') {
    $articleSource = ['label' => (string) $book['testata_titolo'], 'href' => url(\App\Support\RouteTranslator::route('periodicals') . '/' . (int) $book['testata_id'])];
} elseif (trim((string) ($book['contenitore_titolo'] ?? '')) !== '') {
    $articleSource = ['label' => (string) $book['contenitore_titolo'], 'href' => url(\App\Support\RouteTranslator::route('periodicals') . '/articoli') . '?' . http_build_query(['pubblicazione' => (string) $book['contenitore_titolo']])];
}
$articleCard = [
    'id' => (int) $book['id'],
    'url' => url(\App\Support\RouteTranslator::route('periodicals') . '/articolo/' . (int) $book['id']),
    'title' => (string) ($book['titolo'] ?? ''),
    'cover' => ($book['copertina_url'] ?? '') !== '' ? absoluteUrl((string) $book['copertina_url']) : '',
    'subtitle' => (string) ($book['sottotitolo'] ?? ''),
    'authors' => $articleAuthors,
    'authorsText' => (string) ($book['autori'] ?? ''),
    'meta' => array_merge(
        $articleSource !== [] ? [$articleSource] : [],
        array_map(
            static fn($part): array => ['label' => (string) $part],
            array_values(array_filter([
                trim((string) ($book['data_pubblicazione_testo'] ?? '')) !== '' ? $book['data_pubblicazione_testo'] : ($book['anno_pubblicazione'] ?? ''),
                $book['pagine'] ?? '',
            ], static fn($v): bool => trim((string) $v) !== ''))
        )
    ),
    'badge' => __('Articolo'),
];
include __DIR__ . '/article-card.php';
