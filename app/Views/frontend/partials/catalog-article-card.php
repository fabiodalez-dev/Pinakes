<?php
/** @var array<string,mixed> $book An article in a mixed catalogue page. */
$articleUrl = url('/emeroteca/articolo/' . (int)$book['id']);
$articleEscape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$articleCover = absoluteUrl(($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg');
?>
<article class="book-card" data-record-kind="article" data-article-id="<?= (int)$book['id'] ?>">
    <div class="book-image-container">
        <a href="<?= $articleEscape($articleUrl) ?>"><img class="book-image" src="<?= $articleEscape($articleCover) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src=<?= $articleEscape(json_encode(absoluteUrl('/uploads/copertine/placeholder.jpg'))) ?>"></a>
        <span class="book-status-badge"><?= __('Articolo') ?></span>
    </div>
    <div class="book-content">
        <h3 class="book-title"><a href="<?= $articleEscape($articleUrl) ?>"><?= $articleEscape($book['titolo']) ?></a></h3>
        <?php if (!empty($book['sottotitolo'])): ?><p class="book-subtitle"><?= $articleEscape($book['sottotitolo']) ?></p><?php endif; ?>
        <p class="book-author"><?= $articleEscape($book['autori'] ?? '') ?></p>
        <p class="book-meta"><?= $articleEscape(implode(' · ', array_filter([$book['contenitore_titolo'] ?? '', $book['data_pubblicazione_testo'] ?? ($book['anno_pubblicazione'] ?? ''), $book['pagine'] ?? '']))) ?></p>
        <div class="book-actions"><a class="btn-cta btn-cta-sm" href="<?= $articleEscape($articleUrl) ?>"><i class="fas fa-eye" aria-hidden="true"></i> <?= __('Dettagli') ?></a></div>
    </div>
</article>
