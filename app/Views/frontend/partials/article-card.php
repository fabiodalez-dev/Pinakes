<?php
/**
 * The one article card, in the shared pk-card shape
 * (public/assets/pinakes-2026.css). Used by the mixed /catalogo grid
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
 *   cover: string,                                   // '' = blank book with the title
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
$acCover = (string) ($articleCard['cover'] ?? '');
$acAuthors = $articleCard['authors'] ?? [];
$acMeta = $articleCard['meta'] ?? [];
?>
<?php $acTitle = (string) ($articleCard['title'] ?? ''); ?>
<article class="book-card pk-card pk-card--article" data-record-kind="article" data-article-id="<?= (int) ($articleCard['id'] ?? 0) ?>">
    <div class="book-image-container pk-card__panel">
        <a href="<?= $acEscape($acUrl) ?>" class="pk-card__link" tabindex="-1" aria-hidden="true"></a>
        <div class="pk-book">
            <div class="pk-book__pages"></div>
            <div class="pk-book__cover">
                <div class="pk-book__blank" aria-hidden="true">
                    <div class="pk-book__blank-title"><?= $acEscape($acTitle) ?></div>
                    <div class="pk-book__blank-foot"><div class="pk-book__rule"></div><div class="pk-book__brand"><?= $acEscape($articleCard['badge'] ?? __('Articolo')) ?></div></div>
                </div>
                <?php if ($acCover !== '' && !str_contains($acCover, 'placeholder')): ?>
                <img class="book-image pk-book__img" data-pk-tone src="<?= $acEscape($acCover) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.classList.add('is-missing')">
                <?php endif; ?>
                <div class="pk-book__spine"></div>
                <div class="pk-book__gloss"></div>
                <div class="pk-book__edge"></div>
            </div>
        </div>
        <?php if (($articleCard['badge'] ?? '') !== ''): ?><span class="book-status-badge status-article pk-card__status"><span><?= $acEscape($articleCard['badge']) ?></span></span><?php endif; ?>
    </div>
    <div class="book-content pk-card__body">
        <h3 class="book-title pk-card__title"><a href="<?= $acEscape($acUrl) ?>"><?= $acEscape($acTitle) ?></a></h3>
        <?php if (($articleCard['subtitle'] ?? '') !== ''): ?><p class="book-subtitle pk-card__subtitle"><?= $acEscape($articleCard['subtitle']) ?></p><?php endif; ?>
        <?php if ($acAuthors !== []): ?>
            <p class="book-author pk-card__author"><?php foreach ($acAuthors as $acI => $acAuthor): ?><?= $acI > 0 ? '; ' : '' ?><?php if (($acAuthor['href'] ?? '') !== ''): ?><a href="<?= $acEscape($acAuthor['href']) ?>"><?= $acEscape($acAuthor['name']) ?></a><?php else: ?><?= $acEscape($acAuthor['name']) ?><?php endif; ?><?php endforeach; ?></p>
        <?php elseif (($articleCard['authorsText'] ?? '') !== ''): ?>
            <p class="book-author pk-card__author"><?= $acEscape($articleCard['authorsText']) ?></p>
        <?php endif; ?>
        <?php if ($acMeta !== []): ?>
            <p class="book-meta pk-card__meta"><?php foreach ($acMeta as $acI => $acPart): ?><?= $acI > 0 ? ' · ' : '' ?><?php if (($acPart['href'] ?? '') !== ''): ?><a href="<?= $acEscape($acPart['href']) ?>"><?= $acEscape($acPart['label']) ?></a><?php else: ?><?= $acEscape($acPart['label']) ?><?php endif; ?><?php endforeach; ?></p>
        <?php endif; ?>
        <div class="book-actions pk-card__actions"><a class="pk-card__details" href="<?= $acEscape($acUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i><?= __('Dettagli') ?></a></div>
    </div>
</article>
