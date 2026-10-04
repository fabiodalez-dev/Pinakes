<?php
/**
 * An article in a mixed catalogue page. The row comes from
 * UnifiedCatalogService, whose SQL already resolves the image (own cover, then
 * the issue's, then the masthead's — the same order as the emeroteca's
 * ContributionService::coverUrl()). Markup: partials/article-card.php.
 *
 * @var array<string,mixed> $book
 */
$articleCard = [
    'id' => (int) $book['id'],
    'url' => url('/emeroteca/articolo/' . (int) $book['id']),
    'title' => (string) ($book['titolo'] ?? ''),
    'cover' => ($book['copertina_url'] ?? '') !== '' ? absoluteUrl((string) $book['copertina_url']) : '',
    'subtitle' => (string) ($book['sottotitolo'] ?? ''),
    'authorsText' => (string) ($book['autori'] ?? ''),
    'meta' => array_map(
        static fn($part): array => ['label' => (string) $part],
        array_values(array_filter([
            $book['contenitore_titolo'] ?? '',
            trim((string) ($book['data_pubblicazione_testo'] ?? '')) !== '' ? $book['data_pubblicazione_testo'] : ($book['anno_pubblicazione'] ?? ''),
            $book['pagine'] ?? '',
        ], static fn($v): bool => trim((string) $v) !== ''))
    ),
    'badge' => __('Articolo'),
];
include __DIR__ . '/article-card.php';
