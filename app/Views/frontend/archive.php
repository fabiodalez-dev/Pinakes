<?php
/** @var string $archive_type */
/** @var array $archive_info */
/** @var int $totalBooks */
/** @var int $totalPages */
/** @var int $page */

$catalogRoute = route_path('catalog');

// #163 — author photo + relevant source/website links (only for the author archive).
$authorPhoto = '';
$authorLinks = [];
if ($archive_type === 'autore') {
    $pf = trim((string)($archive_info['foto'] ?? ''));
    if ($pf !== '') {
        if (strpos($pf, '/uploads/') === 0) {
            $authorPhoto = url($pf);
        } elseif (filter_var($pf, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $pf) === 1) {
            $authorPhoto = $pf;
        }
    }
    if (!empty($archive_info['collegamenti'])) {
        $decodedLinks = json_decode((string)$archive_info['collegamenti'], true);
        if (is_array($decodedLinks)) {
            foreach ($decodedLinks as $c) {
                if (!is_array($c)) { continue; }
                $u = trim((string)($c['url'] ?? ''));
                if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL) || preg_match('#^https?://#i', $u) !== 1) { continue; }
                $authorLinks[] = ['etichetta' => trim((string)($c['etichetta'] ?? '')), 'url' => $u];
            }
        }
    }
}
$additional_css = "
<style>
    .archive-hero {
        background: #1f2937;
        color: white;
        padding: 4rem 0;
        position: relative;
    }

    .archive-hero-content {
        position: relative;
        z-index: 2;
        max-width: 900px;
        margin: 0 auto;
        text-align: center;
    }

    .archive-icon {
        width: 80px;
        height: 80px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        backdrop-filter: blur(10px);
    }

    .archive-icon i {
        font-size: 2.5rem;
        color: white;
    }

    .archive-title {
        font-size: clamp(2rem, 5vw, 3rem);
        font-weight: 800;
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
    }

    .archive-subtitle {
        font-size: 1.125rem;
        opacity: 0.9;
        font-weight: 400;
    }

    .author-info {
        background: var(--white);
        border-radius: 16px;
        padding: 2rem;
        margin: 2rem auto 3rem;
        max-width: 900px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 1px solid var(--border-color);
    }

    .author-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .info-item {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .info-item i {
        color: var(--text-light);
        font-size: 1.125rem;
        margin-top: 0.125rem;
    }

    .info-content {
        flex: 1;
    }
    
    .stats-row {
    text-align: center;
    padding: 20px 0;
    }

    .info-label {
        font-size: 0.8125rem;
        color: var(--text-muted);
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-size: 1rem;
        color: var(--text-color);
        font-weight: 500;
    }

    .info-value a {
        color: #1f2937;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .info-value a:hover {
        color: var(--primary-color);
    }

    .author-bio {
        font-size: 1rem;
        line-height: 1.7;
        color: #4b5563;
        padding-top: 1.5rem;
        padding-bottom: 1.5rem;
        border-top: 1px solid var(--border-color);
    }

    .books-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 2rem;
    }

    .section-title {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-color);
        margin: 0;
    }

    .books-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 2rem;
        margin-bottom: 3rem;
    }

    @media (max-width: 768px) {
        .books-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .author-info {
            margin: 1.5rem 1rem 2rem;
            padding: 1.5rem;
        }

        .author-info-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
    }

    @media (max-width: 576px) {
        .books-grid {
            grid-template-columns: 1fr;
        }
    }

    .book-card {
        background: var(--white);
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .book-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        border-color: #d1d5db;
    }

    .book-image-container {
        position: relative;
        padding-top: 140%;
        background: var(--light-bg);
        overflow: hidden;
    }

    .book-image-container img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .book-status-badge {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        backdrop-filter: blur(8px);
    }

    .status-available {
        background: color-mix(in srgb, var(--success-color) 90%, transparent);
        color: white;
    }

    .status-borrowed {
        background: color-mix(in srgb, var(--danger-color) 90%, transparent);
        color: white;
    }

    .status-reserved {
        background: color-mix(in srgb, var(--warning-color) 90%, transparent);
        color: white;
    }

    .book-content {
        padding: 1.25rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .book-title {
        font-size: 1.0625rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 0.5rem;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .book-title a {
        color: inherit;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .book-title a:hover {
        color: var(--primary-color);
    }

    .book-author {
        font-size: 0.9375rem;
        color: var(--text-light);
        margin-bottom: 0.75rem;
    }

    .book-meta {
        flex: 1;
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }

    .book-meta a {
        color: inherit;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .book-meta a:hover {
        color: #1f2937;
    }

    .book-actions {
        margin-top: auto;
    }

    .btn-view {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.75rem 1.5rem;
        background: #1f2937;
        color: white;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .btn-view:hover {
        background: #111827;
        color: white;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    .pagination-wrapper {
        display: flex;
        justify-content: center;
        margin-top: 3rem;
    }

    .pagination {
        display: flex;
        gap: 0.5rem;
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .page-item {
        list-style: none;
    }

    .page-link {
        display: flex;
        align-items: center;
        padding: 0.625rem 1rem;
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        color: #374151;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .page-link:hover {
        background: var(--light-bg);
        border-color: #1f2937;
        color: var(--text-color);
    }

    .page-item.active .page-link {
        background: #1f2937;
        border-color: #1f2937;
        color: white;
    }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: var(--white);
        border-radius: 16px;
        border: 1px solid var(--border-color);
    }

    .empty-state i {
        font-size: 4rem;
        color: #d1d5db;
        margin-bottom: 1.5rem;
    }

    .empty-state h5 {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 0.5rem;
    }

    .empty-state p {
        color: var(--text-light);
        margin-bottom: 2rem;
    }

    .btn-catalog {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem 2rem;
        background: #1f2937;
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .btn-catalog:hover {
        background: #111827;
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
</style>
";

ob_start();
?>

<!-- Archive Hero -->
<section class="archive-hero">
    <div class="container">
        <div class="archive-hero-content">
            <div class="archive-icon">
                <?php if ($archive_type === 'autore' && $authorPhoto !== ''): ?>
                    <img src="<?= htmlspecialchars($authorPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)($archive_info['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php elseif ($archive_type === 'autore'): ?>
                    <i class="fas fa-user"></i>
                <?php elseif ($archive_type === 'editore'): ?>
                    <i class="fas fa-building"></i>
                <?php else: ?>
                    <i class="fas fa-tags"></i>
                <?php endif; ?>
            </div>
            <h1 class="archive-title"><?= htmlspecialchars($archive_info['nome']) ?></h1>
            <p class="archive-subtitle">
                <?php if ($archive_type === 'autore'): ?>
                    <?= __("Autore") ?>
                <?php elseif ($archive_type === 'editore'): ?>
                    <?= __("Casa Editrice") ?>
                <?php else: ?>
                    <?= __("Genere") ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</section>

<!-- Archive Info -->
<div class="container">
    <div class="stats-row">
            <span class="stat-badge">
                <i class="fas fa-book"></i>
                <span><?= $totalBooks ?> <?= __n('libro', 'libri', $totalBooks) ?></span>
            </span>
            <?php if ($totalPages > 1): ?>
                <span class="stat-badge">
                    <i class="fas fa-file-alt"></i>
                    <span><?= $totalPages ?> <?= __n('pagina', 'pagine', $totalPages) ?></span>
                </span>
            <?php endif; ?>
        </div>
    <div class="archive-info-card">
        <?php if ($archive_type === 'autore'): ?>
            <?php if (!empty($archive_info['biografia'])): ?>
                <div class="author-bio">
                    <?= nl2br(htmlspecialchars($archive_info['biografia'], ENT_QUOTES, 'UTF-8')) ?>
                </div>
            <?php endif; ?>
            <?php
            // #163 — relevant source/website links (official site + collegamenti)
            $sw = (string)($archive_info['sito_web'] ?? '');
            $hasSite = $sw !== '' && filter_var($sw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $sw) === 1;
            ?>
            <?php if ($hasSite || !empty($authorLinks)): ?>
                <div class="author-links" style="margin-top:<?= !empty($archive_info['biografia']) ? '1rem' : '0' ?>;display:flex;flex-wrap:wrap;gap:0.5rem 1.25rem;">
                    <?php if ($hasSite): ?>
                        <a href="<?= htmlspecialchars($sw, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-globe mr-1"></i><?= __("Sito web") ?></a>
                    <?php endif; ?>
                    <?php foreach ($authorLinks as $c): ?>
                        <a href="<?= htmlspecialchars($c['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt mr-1"></i><?= htmlspecialchars($c['etichetta'] !== '' ? $c['etichetta'] : $c['url'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($archive_type === 'editore'): ?>
            <div class="publisher-details">
                <?php if (!empty($archive_info['indirizzo'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($archive_info['indirizzo']) ?></p>
                <?php endif; ?>
                <?php if (!empty($archive_info['sito_web'])): ?>
                    <p><i class="fas fa-globe"></i><a href="<?= htmlspecialchars($archive_info['sito_web'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($archive_info['sito_web']) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        
    </div>

    <!-- Books Section -->
    <section class="books-section">
        <div class="books-section-header">
            <h2 class="section-title">
                <?php if ($archive_type === 'autore'): ?>
                    <?= __('Opere') ?>
                <?php elseif ($archive_type === 'editore'): ?>
                    <?= __('Pubblicazioni') ?>
                <?php else: ?>
                    <?= __('Libri') ?>
                <?php endif; ?>
            </h2>
        </div>

        <?php
$createBookUrl = static function ($book) {
    return book_url($book);
};
?>

        <?php if (!empty($books)): ?>
            <div class="books-grid">
                <?php foreach($books as $book): ?>
                    <div class="book-card">
                        <div class="book-image-container">
                            <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>">
                                <?php $coverUrl = ($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg'; ?>
                                <img src="<?= htmlspecialchars(url($coverUrl), ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </a>
                            <?php
                            $bookAvailable = ($book['copie_disponibili'] ?? 0) > 0;
                            $bookReserved = !$bookAvailable && ($book['stato'] ?? '') === 'prenotato';
                            ?>
                            <span class="book-status-badge <?= $bookAvailable ? 'status-available' : ($bookReserved ? 'status-reserved' : 'status-borrowed') ?>">
                                <i class="fas fa-<?= $bookAvailable ? 'check-circle' : ($bookReserved ? 'bookmark' : 'times-circle') ?>"></i>
                                <?= $bookAvailable ? __('Disponibile') : ($bookReserved ? __('Prenotato') : __('Prestato')) ?>
                                <?php
                                // Hook: Allow plugins to add icons to status badge (e.g., eBook/audio icons)
                                do_action('book.badge.digital_icons', $book);
                                ?>
                            </span>
                        </div>
                        <div class="book-content">
                            <h3 class="book-title">
                                <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                                </a>
                            </h3>
                            <?php if (!empty($book['autore']) && $archive_type !== 'autore'): ?>
                                <p class="book-author">di <?= htmlspecialchars(html_entity_decode($book['autore'], ENT_QUOTES, 'UTF-8')) ?></p>
                            <?php endif; ?>
                            <div class="book-meta">
                                <?php if (!empty($book['genere']) && $archive_type !== 'genere'): ?>
                                    <div>
                                        <i class="fas fa-tags me-1"></i>
                                        <a href="<?= htmlspecialchars(route_path('genre'), ENT_QUOTES, 'UTF-8') ?>/<?= urlencode(html_entity_decode($book['genere'], ENT_QUOTES, 'UTF-8')) ?>">
                                            <?= htmlspecialchars(html_entity_decode($book['genere'], ENT_QUOTES, 'UTF-8')) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($book['editore']) && $archive_type !== 'editore'): ?>
                                    <div>
                                        <i class="fas fa-building me-1"></i>
                                        <a href="<?= htmlspecialchars(route_path('publisher'), ENT_QUOTES, 'UTF-8') ?>/<?= urlencode(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>">
                                            <?= htmlspecialchars(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="book-actions">
                                <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>" class="btn-view">
                                    <i class="fas fa-eye"></i>
                                    <span><?= __('Vedi dettagli') ?></span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <nav aria-label="<?= htmlspecialchars(__('Navigazione pagine'), ENT_QUOTES, 'UTF-8') ?>">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3><?= __("Nessun libro trovato") ?></h3>
                <p>
                    <?php if ($archive_type === 'autore'): ?>
                        <?= __("Non sono stati trovati libri di questo autore.") ?>
                    <?php elseif ($archive_type === 'editore'): ?>
                        <?= __("Non sono stati trovati libri di questo editore.") ?>
                    <?php else: ?>
                        <?= __("Non sono stati trovati libri di questo genere.") ?>
                    <?php endif; ?>
                </p>
                <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="btn-catalog">
                    <i class="fas fa-search"></i>
                    <span><?= __('Esplora Catalogo') ?></span>
                </a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>
