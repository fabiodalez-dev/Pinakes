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
$urlPath = parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl;
if (empty($fileUrl) || strtolower(pathinfo($urlPath, PATHINFO_EXTENSION)) !== 'pdf') {
    return;
}

$pdfUrl = htmlspecialchars(url($fileUrl), ENT_QUOTES, 'UTF-8');
$bookTitle = htmlspecialchars($book['titolo'] ?? 'PDF', ENT_QUOTES, 'UTF-8');
?>

<div id="pdf-viewer-container" class="container my-4" style="display: none;">
    <div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden;">
        <div class="card-body p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-3">
                <h5 class="card-title mb-0 flex items-center gap-2">
                    <i class="fas fa-file-pdf" style="font-size: 1.5rem; color: #dc2626;"></i>
                    <span class="font-bold"><?= __("Visualizzatore PDF") ?></span>
                </h5>
                <button type="button"
                        onclick="document.getElementById('btn-toggle-pdf-viewer')?.click()"
                        class="ui-button btn-outline plugin-player-action plugin-player-action--close"
                        aria-label="<?= __("Chiudi Visualizzatore") ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Book Info -->
            <p class="text-gray-500 text-sm mb-3">
                <i class="fas fa-book mr-1"></i>
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
            <div class="mt-3 text-right">
                <a href="<?= $pdfUrl ?>"
                   download
                   class="ui-button btn-outline plugin-player-action plugin-player-action--download">
                    <i class="fas fa-download mr-1"></i>
                    <?= __("Scarica PDF") ?>
                </a>
            </div>

            <!-- Info Panel -->
            <div class="mt-3 p-3 bg-gray-100 rounded" style="font-size: 0.85rem;">
                <div class="flex flex-wrap -mx-3 gap-y-2">
                    <div class="w-full px-3">
                        <i class="fas fa-search text-gray-500 mr-1"></i>
                        <span class="text-gray-500"><?= __("Usa la funzione di ricerca del browser per trovare testo nel documento") ?></span>
                    </div>
                    <div class="w-full px-3">
                        <i class="fas fa-expand text-gray-500 mr-1"></i>
                        <span class="text-gray-500"><?= __("Usa il controllo schermo intero del viewer o del browser") ?></span>
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
