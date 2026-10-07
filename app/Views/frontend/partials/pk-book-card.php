<?php
/**
 * One book as a card (2026 design): the cover as a 3D book on a panel tinted
 * from the cover's own colour, the availability badge and the wishlist heart
 * on the panel, then title, subtitle, author, publisher and "Details".
 *
 * Shared by the home, the catalogue grid and the book page's related books,
 * so every list of books looks the same. Keeps every element the previous
 * card had: the live-availability badge (data-live-*), the digital-content
 * icons plugins add through `book.badge.digital_icons`, the "wanted by the
 * library" badge, the media-type badge, subtitle, publisher and Details.
 *
 * @var array<string,mixed> $book
 * @var bool|null $pkCardMeta    show the publisher line (default true)
 * @var string|null $pkCardTitleTag heading element (default h3)
 * @var string|null $pkCardClass   extra classes on the card root
 */
$pkBook = $book;
$pkUrl = book_url($pkBook);
$pkTitle = html_entity_decode((string) ($pkBook['titolo'] ?? ''), ENT_QUOTES, 'UTF-8');
$pkSubtitle = html_entity_decode((string) ($pkBook['sottotitolo'] ?? ''), ENT_QUOTES, 'UTF-8');
$pkAuthor = html_entity_decode((string) ($pkBook['autore'] ?? ''), ENT_QUOTES, 'UTF-8');
$pkPublisher = html_entity_decode((string) ($pkBook['editore'] ?? ''), ENT_QUOTES, 'UTF-8');
$pkCover = trim((string) ($pkBook['copertina_url'] ?? ''));
// The placeholder image is the old way of saying "no cover"; the design draws
// a blank book with the title instead.
if ($pkCover !== '' && str_contains($pkCover, 'placeholder')) {
    $pkCover = '';
}
$pkShowMeta = $pkCardMeta ?? true;
$pkTitleTag = in_array($pkCardTitleTag ?? 'h3', ['h2', 'h3', 'h4'], true) ? ($pkCardTitleTag ?? 'h3') : 'h3';
$pkEdgeCache = \App\Support\LiteSpeedCache::enabled() && \App\Support\LiteSpeedCache::serverDetected();
$pkE = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$pkId = (int) ($pkBook['id'] ?? 0);
// Same resolution as the book page: a legacy record with no tipo_media is
// typed from its formato, so a disc reads as a disc on the card too.
$pkTipoMedia = \App\Support\MediaLabels::resolveTipoMedia(
    isset($pkBook['formato']) ? (string) $pkBook['formato'] : null,
    isset($pkBook['tipo_media']) ? (string) $pkBook['tipo_media'] : null
);
// The blank cover carries the library's name, as the header does.
$pkBrand = (string) \App\Support\ConfigStore::get('app.name', 'Pinakes');
?>
<div class="book-card pk-card<?= ($pkCardClass ?? '') !== '' ? ' ' . $pkE((string) $pkCardClass) : '' ?>" data-book-id="<?= $pkId ?>">
    <div class="book-image-container pk-card__panel">
        <a href="<?= $pkE($pkUrl) ?>" class="pk-card__link" tabindex="-1" aria-hidden="true"></a>
        <div class="pk-book">
            <div class="pk-book__pages"></div>
            <div class="pk-book__cover">
                <div class="pk-book__blank" aria-hidden="true">
                    <div class="pk-book__blank-title"><?= $pkE($pkTitle) ?></div>
                    <div class="pk-book__blank-foot"><div class="pk-book__rule"></div><div class="pk-book__brand"><?= $pkE($pkBrand) ?></div></div>
                </div>
                <?php if ($pkCover !== ''): ?>
                <img class="book-image pk-book__img" data-pk-tone
                     src="<?= $pkE(absoluteUrl($pkCover)) ?>"
                     alt="<?= $pkE($pkTitle) ?>"
                     loading="lazy" decoding="async"
                     onerror="this.onerror=null;this.classList.add('is-missing')">
                <?php endif; ?>
                <div class="pk-book__spine"></div>
                <div class="pk-book__gloss"></div>
                <div class="pk-book__edge"></div>
            </div>
        </div>
        <?php if (!empty($pkBook['is_desiderata'])): ?>
            <?php // Wanted, not held: no availability to hydrate, so no data-live-*. ?>
            <span class="book-status-badge status-unavailable dw-wanted-badge pk-card__status"><span><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> <?= $pkE(__('Cercato dalla biblioteca')) ?></span></span>
        <?php elseif ($pkEdgeCache || !empty($pkBook['_availability_unknown'])): ?>
            <span class="book-status-badge availability-pending pk-card__status" data-live-book-id="<?= $pkId ?>" data-live-role="badge" data-live-pending="1"><span data-live-label><?= $pkE(__('Verifica disponibilità')) ?></span><?php
                $pkStatic = $pkBook;
                unset($pkStatic['copie_disponibili'], $pkStatic['copie_totali'], $pkStatic['stato']);
                do_action('book.badge.digital_icons', $pkStatic);
            ?></span>
        <?php else: ?>
            <?php
            $pkStato = (string) ($pkBook['stato'] ?? '');
            if ((int) ($pkBook['copie_disponibili'] ?? 0) > 0) {
                [$pkClass, $pkLabel] = ['status-available', __('Disponibile')];
            } elseif ($pkStato === 'prenotato') {
                [$pkClass, $pkLabel] = ['status-reserved', __('Prenotato')];
            } elseif ($pkStato === 'prestato') {
                // "In prestito" only for stato='prestato'; anything else is "Non disponibile" (#303).
                [$pkClass, $pkLabel] = ['status-borrowed', __('In prestito')];
            } else {
                [$pkClass, $pkLabel] = ['status-unavailable', __('Non disponibile')];
            }
            ?>
            <span class="book-status-badge <?= $pkClass ?> pk-card__status"><span data-live-label><?= $pkE($pkLabel) ?></span><?php do_action('book.badge.digital_icons', $pkBook); ?></span>
        <?php endif; ?>
        <?php if ($pkTipoMedia !== 'libro'): ?>
            <?php $pkMedia = \App\Support\MediaLabels::tipoMediaDisplayName($pkTipoMedia); ?>
            <span class="book-media-badge pk-card__media" title="<?= $pkE($pkMedia) ?>" aria-label="<?= $pkE($pkMedia) ?>"><i class="fas <?= $pkE(\App\Support\MediaLabels::icon($pkTipoMedia)) ?>" aria-hidden="true"></i></span>
        <?php endif; ?>
        <?php if (!\App\Support\ConfigStore::isCatalogueMode() && empty($pkBook['is_desiderata'])): ?>
        <button type="button" class="pk-heart" data-pk-wish="<?= $pkId ?>" aria-pressed="false" aria-label="<?= htmlspecialchars(__('Aggiungi ai preferiti'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Aggiungi ai preferiti'), ENT_QUOTES, 'UTF-8') ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"></path></svg>
        </button>
        <?php endif; ?>
    </div>
    <div class="book-content pk-card__body">
        <<?= $pkTitleTag ?> class="book-title pk-card__title"><a href="<?= $pkE($pkUrl) ?>"><?= $pkE($pkTitle) ?></a></<?= $pkTitleTag ?>>
        <?php if ($pkSubtitle !== ''): ?>
            <p class="book-subtitle pk-card__subtitle"><?= $pkE($pkSubtitle) ?></p>
        <?php endif; ?>
        <?php if ($pkAuthor !== ''): ?>
            <p class="book-author pk-card__author"><?= $pkE($pkAuthor) ?></p>
        <?php else: ?>
            <?php // Not .book-author: that class means a real credited author (the catalogue's author sort reads it). ?>
            <p class="pk-card__author pk-card__author--unknown"><?= $pkE($pkTipoMedia === 'disco' ? __('Artista sconosciuto') : __('Autore sconosciuto')) ?></p>
        <?php endif; ?>
        <?php if ($pkShowMeta): ?>
            <?php if ($pkPublisher !== ''): ?>
                <p class="book-meta pk-card__meta"><span><?= __("Editore:") ?></span> <?= $pkE($pkPublisher) ?></p>
            <?php else: ?>
                <p class="book-meta book-meta-empty pk-card__meta" aria-hidden="true">&nbsp;</p>
            <?php endif; ?>
        <?php endif; ?>
        <div class="book-actions pk-card__actions">
            <a href="<?= $pkE($pkUrl) ?>" class="pk-card__details"><i class="fas fa-eye" aria-hidden="true"></i><?= __("Dettagli") ?></a>
        </div>
    </div>
</div>
