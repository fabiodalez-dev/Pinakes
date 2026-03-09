<?php
/**
 * Digital Library Plugin - PDF Viewer
 *
 * Renders an inline PDF viewer using the browser's native PDF rendering engine.
 * Lazy-loaded: iframe src is injected only when the container becomes visible.
 *
 * @var array<string, mixed> $book
 */

$fileUrl = $book['file_url'] ?? '';
if (empty($fileUrl) || strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION)) !== 'pdf') {
    return;
}

$pdfUrl = htmlspecialchars(url($fileUrl), ENT_QUOTES, 'UTF-8');
$bookTitle = htmlspecialchars($book['titolo'] ?? 'PDF', ENT_QUOTES, 'UTF-8');
?>

<div id="pdf-viewer-container" class="container my-4" style="display: none;">
    <div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden;">
        <div class="card-body p-4">
            <!-- Header -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="fas fa-file-pdf" style="font-size: 1.5rem; color: #dc2626;"></i>
                    <span class="fw-bold"><?= __("Visualizzatore PDF") ?></span>
                </h5>
                <button type="button"
                        onclick="document.getElementById('btn-toggle-pdf-viewer')?.click()"
                        class="btn btn-sm btn-outline-secondary rounded-pill"
                        aria-label="<?= __("Chiudi Visualizzatore") ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Book Info -->
            <p class="text-muted small mb-3">
                <i class="fas fa-book me-1"></i>
                <?= $bookTitle ?>
            </p>

            <!-- PDF iframe (src injected lazily via JS) -->
            <iframe id="pdf-viewer-iframe"
                    class="pdf-viewer-frame"
                    src="about:blank"
                    data-pdf-src="<?= $pdfUrl ?>#toolbar=1&navpanes=1"
                    title="<?= __("Visualizzatore PDF") ?> — <?= $bookTitle ?>"
                    allowfullscreen>
            </iframe>

            <!-- Download Button -->
            <div class="mt-3 text-end">
                <a href="<?= $pdfUrl ?>"
                   download
                   class="btn btn-sm btn-danger rounded-pill">
                    <i class="fas fa-download me-1"></i>
                    <?= __("Scarica PDF") ?>
                </a>
            </div>

            <!-- Info Panel -->
            <div class="mt-3 p-3 bg-light rounded" style="font-size: 0.85rem;">
                <div class="row g-2">
                    <div class="col-md-6">
                        <i class="fas fa-search text-muted me-1"></i>
                        <span class="text-muted"><?= __("Usa la funzione di ricerca del browser per trovare testo nel documento") ?></span>
                    </div>
                    <div class="col-md-6">
                        <i class="fas fa-expand text-muted me-1"></i>
                        <span class="text-muted"><?= __("Usa il controllo schermo intero del viewer o del browser") ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Lazy-load PDF iframe: inject src only when container becomes visible.
 * Uses MutationObserver to detect display change (same pattern as audio toggle).
 */
(function() {
    var container = document.getElementById('pdf-viewer-container');
    var iframe = document.getElementById('pdf-viewer-iframe');
    if (!container || !iframe) return;

    var loaded = false;

    function loadPdf() {
        if (loaded) return;
        var src = iframe.getAttribute('data-pdf-src');
        if (src) {
            iframe.src = src;
            loaded = true;
        }
    }

    // Observe style changes on the container to detect when it becomes visible
    var observer = new MutationObserver(function(mutations) {
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].attributeName === 'style' && container.style.display !== 'none') {
                loadPdf();
                observer.disconnect();
                break;
            }
        }
    });

    observer.observe(container, { attributes: true, attributeFilter: ['style'] });
})();
</script>
