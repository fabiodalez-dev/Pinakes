<?php
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
        background: white;
        border-radius: 16px;
        padding: 2rem;
        margin: 2rem auto 3rem;
        max-width: 900px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        border: 1px solid #e5e7eb;
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
        color: #6b7280;
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
        color: #9ca3af;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-size: 1rem;
        color: #111827;
        font-weight: 500;
    }

    .info-value a {
        color: #1f2937;
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .info-value a:hover {
        color: #3b82f6;
    }

    .author-bio {
        font-size: 1rem;
        line-height: 1.7;
        color: #4b5563;
        padding-top: 1.5rem;
        padding-bottom: 1.5rem;
        border-top: 1px solid #e5e7eb;
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
        color: #111827;
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
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
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
        background: #f3f4f6;
        overflow: hidden;
    }

    .book-image-container img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
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
        background: rgba(16, 185, 129, 0.9);
        color: white;
    }

    .status-borrowed {
        background: rgba(239, 68, 68, 0.9);
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
        color: #111827;
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
        color: #3b82f6;
    }

    .book-author {
        font-size: 0.9375rem;
        color: #6b7280;
        margin-bottom: 0.75rem;
    }

    .book-meta {
        flex: 1;
        font-size: 0.875rem;
        color: #9ca3af;
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
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        color: #374151;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .page-link:hover {
        background: #f9fafb;
        border-color: #1f2937;
        color: #111827;
    }

    .page-item.active .page-link {
        background: #1f2937;
        border-color: #1f2937;
        color: white;
    }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: white;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
    }

    .empty-state i {
        font-size: 4rem;
        color: #d1d5db;
        margin-bottom: 1.5rem;
    }

    .empty-state h5 {
        font-size: 1.5rem;
        font-weight: 600;
        color: #111827;
        margin-bottom: 0.5rem;
    }

    .empty-state p {
        color: #6b7280;
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
                <?php if ($archive_type === 'autore'): ?>
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
                    Autore
                <?php elseif ($archive_type === 'editore'): ?>
                    Casa Editrice
                <?php else: ?>
                    Genere
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
                <span><?= $totalBooks ?> <?= $totalBooks === 1 ? 'libro' : 'libri' ?></span>
            </span>
            <?php if ($totalPages > 1): ?>
                <span class="stat-badge">
                    <i class="fas fa-file-alt"></i>
                    <span><?= $totalPages ?> <?= $totalPages === 1 ? 'pagina' : 'pagine' ?></span>
                </span>
            <?php endif; ?>
        </div>
    <div class="archive-info-card">
        <?php if ($archive_type === 'autore' && !empty($archive_info['biografia'])): ?>
            <div class="author-bio">
                <?= nl2br(htmlspecialchars($archive_info['biografia'])) ?>
            </div>
        <?php elseif ($archive_type === 'editore'): ?>
            <div class="publisher-details">
                <?php if (!empty($archive_info['indirizzo'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($archive_info['indirizzo']) ?></p>
                <?php endif; ?>
                <?php if (!empty($archive_info['sito_web'])): ?>
                    <p><i class="fas fa-globe"></i><a href="<?= htmlspecialchars($archive_info['sito_web']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($archive_info['sito_web']) ?></a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        
    </div>

    <!-- Books Section -->
    <section class="books-section">
        <div class="books-section-header">
            <h2 class="section-title">
                <?php if ($archive_type === 'autore'): ?>
                    Opere
                <?php elseif ($archive_type === 'editore'): ?>
                    Pubblicazioni
                <?php else: ?>
                    Libri
                <?php endif; ?>
            </h2>
        </div>

        <?php
function createBookUrl($book) {
    $text = html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8');
    $text = strtolower($text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return "/libro/{$book['id']}" . ($slug ? "/$slug" : '');
}
?>

        <?php if (!empty($books)): ?>
            <div class="books-grid">
                <?php foreach($books as $book): ?>
                    <div class="book-card">
                        <div class="book-image-container">
                            <a href="<?= createBookUrl($book) ?>">
                                <img src="<?= htmlspecialchars($book['copertina_url'] ?? '/uploads/copertine/default-cover.jpg') ?>"
                                     alt="<?= htmlspecialchars($book['titolo'] ?? '') ?>">
                            </a>
                            <span class="book-status-badge <?= ($book['copie_disponibili'] > 0) ? 'status-available' : 'status-borrowed' ?>">
                                <i class="fas fa-<?= ($book['copie_disponibili'] > 0) ? 'check-circle' : 'times-circle' ?>"></i>
                                <?= ($book['copie_disponibili'] > 0) ? 'Disponibile' : 'Prestato' ?>
                            </span>
                        </div>
                        <div class="book-content">
                            <h3 class="book-title">
                                <a href="<?= createBookUrl($book) ?>">
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
                                        <a href="/genere/<?= urlencode(html_entity_decode($book['genere'], ENT_QUOTES, 'UTF-8')) ?>">
                                            <?= htmlspecialchars(html_entity_decode($book['genere'], ENT_QUOTES, 'UTF-8')) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($book['editore']) && $archive_type !== 'editore'): ?>
                                    <div>
                                        <i class="fas fa-building me-1"></i>
                                        <a href="/editore/<?= urlencode(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>">
                                            <?= htmlspecialchars(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="book-actions">
                                <a href="<?= createBookUrl($book) ?>" class="btn-view">
                                    <i class="fas fa-eye"></i>
                                    <span>Vedi dettagli</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <nav aria-label="Navigazione pagine">
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
                <h5>Nessun libro trovato</h5>
                <p>
                    <?php if ($archive_type === 'autore'): ?>
                        Non sono stati trovati libri di questo autore.
                    <?php elseif ($archive_type === 'editore'): ?>
                        Non sono stati trovati libri di questo editore.
                    <?php else: ?>
                        Non sono stati trovati libri di questo genere.
                    <?php endif; ?>
                </p>
                <a href="/catalogo" class="btn-catalog">
                    <i class="fas fa-search"></i>
                    <span>Esplora Catalogo</span>
                </a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
$content = ob_get_clean();
include 'layout.php';
?>