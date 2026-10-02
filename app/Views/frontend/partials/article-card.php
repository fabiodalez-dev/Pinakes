<?php
/**
 * The one article card, in the catalogue's .book-card shape
 * (public/assets/catalog-pages.css). Used by the mixed /catalogo grid
 * (catalog-article-card.php) and by every emeroteca listing
 * (storage/plugins/emeroteca/src/Views/public/article-results.php), so an
 * article looks the same wherever it is listed.
 *
 * Callers pass data, not a database row: each knows how to resolve its own
 * row's image and links, and this file only decides how a card looks.
 *
 * Input $articleCard: array{
 *   id: int,
 *   url: string,
 *   title: string,
 *   cover: string,                                   // '' = placeholder
 *   subtitle?: string,
 *   authors?: list<array{name: string, href?: ?string}>,
 *   authorsText?: string,                            // when there are no links
 *   meta?: list<array{label: string, href?: ?string}>,
 *   badge?: string
 * }
 */
$articleCard = $articleCard ?? [];
$acUrl = (string) ($articleCard['url'] ?? '');
$acEscape = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$acPlaceholder = url('/uploads/copertine/placeholder.jpg');
$acCover = (string) ($articleCard['cover'] ?? '');
$acAuthors = $articleCard['authors'] ?? [];
$acMeta = $articleCard['meta'] ?? [];
?>
<article class="book-card" data-record-kind="article" data-article-id="<?= (int) ($articleCard['id'] ?? 0) ?>">
    <div class="book-image-container">
        <a href="<?= $acEscape($acUrl) ?>" tabindex="-1" aria-hidden="true"><img class="book-image" src="<?= $acEscape($acCover !== '' ? $acCover : $acPlaceholder) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=<?= $acEscape(json_encode($acPlaceholder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></a>
        <?php if (($articleCard['badge'] ?? '') !== ''): ?><span class="book-status-badge status-article"><?= $acEscape($articleCard['badge']) ?></span><?php endif; ?>
    </div>
    <div class="book-content">
        <h3 class="book-title"><a href="<?= $acEscape($acUrl) ?>"><?= $acEscape($articleCard['title'] ?? '') ?></a></h3>
        <?php if (($articleCard['subtitle'] ?? '') !== ''): ?><p class="book-subtitle"><?= $acEscape($articleCard['subtitle']) ?></p><?php endif; ?>
        <?php if ($acAuthors !== []): ?>
            <p class="book-author"><?php foreach ($acAuthors as $acI => $acAuthor): ?><?= $acI > 0 ? '; ' : '' ?><?php if (($acAuthor['href'] ?? '') !== ''): ?><a href="<?= $acEscape($acAuthor['href']) ?>"><?= $acEscape($acAuthor['name']) ?></a><?php else: ?><?= $acEscape($acAuthor['name']) ?><?php endif; ?><?php endforeach; ?></p>
        <?php elseif (($articleCard['authorsText'] ?? '') !== ''): ?>
            <p class="book-author"><?= $acEscape($articleCard['authorsText']) ?></p>
        <?php endif; ?>
        <?php if ($acMeta !== []): ?>
            <p class="book-meta"><?php foreach ($acMeta as $acI => $acPart): ?><?= $acI > 0 ? ' · ' : '' ?><?php if (($acPart['href'] ?? '') !== ''): ?><a href="<?= $acEscape($acPart['href']) ?>"><?= $acEscape($acPart['label']) ?></a><?php else: ?><?= $acEscape($acPart['label']) ?><?php endif; ?><?php endforeach; ?></p>
        <?php endif; ?>
        <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $acEscape($acUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a></div>
    </div>
</article>
