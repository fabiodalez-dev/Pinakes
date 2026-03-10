<?php
/**
 * Digital Library Plugin - Frontend Buttons
 *
 * Renders download buttons for eBooks and audiobooks in the book detail page.
 * PDF files get an inline viewer toggle; ePub/other files get a direct download/open link.
 *
 * @var array<string, mixed> $book
 */

$hasEbook = !empty($book['file_url'] ?? '');
$hasAudiobook = !empty($book['audio_url'] ?? '');

if (!$hasEbook && !$hasAudiobook) {
    return;
}

$ebookPath = parse_url($book['file_url'] ?? '', PHP_URL_PATH) ?: ($book['file_url'] ?? '');
$isPdf = $hasEbook && strtolower(pathinfo($ebookPath, PATHINFO_EXTENSION)) === 'pdf';
?>

<?php if ($hasEbook && $isPdf): ?>
<!-- PDF: toggle button to open inline viewer -->
<button type="button"
        id="btn-toggle-pdf-viewer"
        class="btn btn-outline-danger btn-lg"
        aria-controls="pdf-viewer-container"
        aria-expanded="false"
        title="<?= __("Leggi PDF") ?>">
    <i class="fas fa-book-reader me-2"></i>
    <?= __("Leggi PDF") ?>
</button>
<!-- PDF: direct download link -->
<a href="<?= htmlspecialchars(url($book['file_url']), ENT_QUOTES, 'UTF-8') ?>"
   download
   class="btn btn-outline-secondary btn-lg"
   title="<?= __("Scarica PDF") ?>">
    <i class="fas fa-download me-2"></i>
    <?= __("Scarica PDF") ?>
</a>
<?php elseif ($hasEbook): ?>
<!-- Non-PDF (ePub etc.): open in new tab, no download attribute -->
<a href="<?= htmlspecialchars(url($book['file_url']), ENT_QUOTES, 'UTF-8') ?>"
   target="_blank"
   rel="noopener noreferrer"
   class="btn btn-outline-danger btn-lg"
   title="<?= __("Scarica l'eBook in formato digitale") ?>">
    <i class="fas fa-file-pdf me-2"></i>
    <?= __("Scarica eBook") ?>
</a>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<button type="button"
        id="btn-toggle-audiobook"
        class="btn btn-outline-dark btn-lg"
        aria-controls="audiobook-player-container"
        aria-expanded="false"
        title="<?= __("Ascolta l'audiobook") ?>">
    <i class="fas fa-headphones me-2"></i>
    <?= __("Ascolta Audiobook") ?>
</button>
<?php endif; ?>

<style>
/* Additional button styles for Digital Library */
.action-buttons .btn-outline-danger {
    color: #dc2626;
    border-color: #dc2626;
    background: transparent;
}

.action-buttons .btn-outline-danger:hover {
    background: #dc2626;
    border-color: #dc2626;
    color: #ffffff;
}

.action-buttons .btn-outline-dark {
    color: #1e293b;
    border-color: #1e293b;
    background: transparent;
}

.action-buttons .btn-outline-dark:hover {
    background: #1e293b;
    border-color: #1e293b;
    color: #ffffff;
}

.action-buttons .btn-outline-secondary {
    color: #475569;
    border-color: #94a3b8;
    background: transparent;
}

.action-buttons .btn-outline-secondary:hover {
    background: #475569;
    border-color: #475569;
    color: #ffffff;
}
</style>

<?php if ($isPdf): ?>
<script>
// Toggle PDF viewer visibility
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('btn-toggle-pdf-viewer');
    var viewerContainer = document.getElementById('pdf-viewer-container');

    if (!toggleBtn || !viewerContainer) {
        return;
    }

    var openLabel = <?= json_encode(__("Leggi PDF"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var closeLabel = <?= json_encode(__("Chiudi Visualizzatore"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var openContent = '<i class="fas fa-book-reader me-2"></i>' + openLabel;
    var closeContent = '<i class="fas fa-times me-2"></i>' + closeLabel;

    var setButtonState = function(isOpen) {
        toggleBtn.innerHTML = isOpen ? closeContent : openContent;
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    toggleBtn.addEventListener('click', function() {
        var isHidden = viewerContainer.style.display === 'none' || viewerContainer.style.display === '';

        if (isHidden) {
            viewerContainer.style.display = 'block';
            setButtonState(true);
        } else {
            viewerContainer.style.display = 'none';
            setButtonState(false);
        }
    });

    // Ensure initial state reflects closed viewer
    viewerContainer.style.display = 'none';
    setButtonState(false);
});
</script>
<?php endif; ?>

<?php if ($hasAudiobook): ?>
<script>
// Toggle audiobook player visibility and stop playback if needed
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('btn-toggle-audiobook');
    var playerContainer = document.getElementById('audiobook-player-container');

    if (!toggleBtn || !playerContainer) {
        return;
    }

    var audioEl = playerContainer.querySelector('audio');

    var openLabel = <?= json_encode(__("Ascolta Audiobook"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var closeLabel = <?= json_encode(__("Chiudi Player"), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var openContent = '<i class="fas fa-headphones me-2"></i>' + openLabel;
    var closeContent = '<i class="fas fa-times me-2"></i>' + closeLabel;

    var setButtonState = function(isOpen) {
        toggleBtn.innerHTML = isOpen ? closeContent : openContent;
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    var pauseAudioIfPlaying = function() {
        if (audioEl && !audioEl.paused) {
            audioEl.pause();
        }
    };

    toggleBtn.addEventListener('click', function() {
        var isHidden = playerContainer.style.display === 'none' || playerContainer.style.display === '';

        if (isHidden) {
            playerContainer.style.display = 'block';
            setButtonState(true);
        } else {
            playerContainer.style.display = 'none';
            pauseAudioIfPlaying();
            setButtonState(false);
        }
    });

    // Ensure initial state reflects closed player
    playerContainer.style.display = 'none';
    setButtonState(false);
});
</script>
<?php endif; ?>
