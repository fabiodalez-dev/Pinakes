<?php
/**
 * Digital Library Plugin - Audio Player
 *
 * Renders Green Audio Player for audiobook playback.
 *
 * @var array<string, mixed> $book
 */

if (empty($book['audio_url'] ?? '')) {
    return;
}

$audioUrl = htmlspecialchars(url($book['audio_url']), ENT_QUOTES, 'UTF-8');
$bookTitle = htmlspecialchars($book['titolo'] ?? 'Audiobook', ENT_QUOTES, 'UTF-8');
?>

<div id="audiobook-player-container" class="container my-4" style="display: none;">
    <div class="card shadow-sm border-0" style="border-radius: 16px; overflow: hidden;">
        <div class="card-body p-4">
            <!-- Header -->
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="fas fa-play-circle" style="font-size: 1.5rem; color: #1e293b;"></i>
                    <span class="fw-bold"><?= __("Audiobook") ?></span>
                </h5>
                <button type="button"
                        onclick="document.getElementById('btn-toggle-audiobook')?.click()"
                        class="btn btn-sm btn-outline-secondary rounded-pill">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Book Info -->
            <p class="text-muted small mb-3">
                <i class="fas fa-book me-1"></i>
                <?= $bookTitle ?>
            </p>

            <!-- Green Audio Player -->
            <div class="player-digital-library player-accessible">
                <audio preload="metadata">
                    <source src="<?= $audioUrl ?>" type="audio/mpeg">
                    <?= __("Il tuo browser non supporta la riproduzione audio.") ?>
                </audio>
            </div>

            <!-- Download Button -->
            <div class="mt-3 text-end">
                <a href="<?= $audioUrl ?>"
                   download
                   class="btn btn-sm btn-success rounded-pill">
                    <i class="fas fa-download me-1"></i>
                    <?= __("Scarica Audiobook") ?>
                </a>
            </div>

            <!-- Info Panel -->
            <div class="mt-3 p-3 bg-light rounded" style="font-size: 0.85rem;">
                <div class="row g-2">
                    <div class="col-md-6">
                        <i class="fas fa-keyboard text-muted me-1"></i>
                        <span class="text-muted"><?= __("Usa le frecce ← → per saltare") ?></span>
                    </div>
                    <div class="col-md-6">
                        <i class="fas fa-volume-up text-muted me-1"></i>
                        <span class="text-muted"><?= __("Frecce ↑ ↓ per il volume") ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Initialize Green Audio Player with Media Session API
 * Enables OS-level controls (lock screen, media keys, notifications)
 */
document.addEventListener('DOMContentLoaded', function() {
    var preInitAudio = document.querySelector('.player-digital-library audio');
    if (!preInitAudio) return;

    // Wait for Green Audio Player to be available
    if (typeof GreenAudioPlayer === 'undefined') {
        console.warn('Green Audio Player not loaded - using native controls');
        preInitAudio.controls = true;
        return;
    }

    try {
        // Initialize player with full features
        GreenAudioPlayer.init({
            selector: '.player-digital-library',
            stopOthersOnPlay: true,
            enableKeystrokes: true,
            showTooltips: true,
            showDownloadButton: false // We have our own download button
        });

        // Re-query audio element — GAP.init() replaces innerHTML, destroying the original node
        var audioEl = document.querySelector('.player-digital-library audio');

        console.log('✓ Green Audio Player initialized successfully');

        // === Global keyboard shortcuts (active when player is visible) ===
        document.addEventListener('keydown', function(e) {
            var container = document.getElementById('audiobook-player-container');
            if (!container || container.style.display === 'none') return;
            // Skip if user is typing in an input/textarea
            var tag = (document.activeElement || {}).tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            switch (e.key) {
                case 'ArrowLeft':
                    audioEl.currentTime = Math.max(audioEl.currentTime - 5, 0);
                    e.preventDefault();
                    break;
                case 'ArrowRight':
                    if (audioEl.duration) audioEl.currentTime = Math.min(audioEl.currentTime + 5, audioEl.duration);
                    e.preventDefault();
                    break;
                case 'ArrowUp':
                    audioEl.volume = Math.min(audioEl.volume + 0.05, 1);
                    e.preventDefault();
                    break;
                case 'ArrowDown':
                    audioEl.volume = Math.max(audioEl.volume - 0.05, 0);
                    e.preventDefault();
                    break;
                case ' ':
                    if (audioEl.paused) { audioEl.play(); } else { audioEl.pause(); }
                    e.preventDefault();
                    break;
            }
        });

        // === Media Session API Integration ===
        if ('mediaSession' in navigator) {
            // Set metadata for OS-level display (lock screen, notifications, media keys)
            navigator.mediaSession.metadata = new MediaMetadata({
                title: <?= json_encode($bookTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
                artist: <?= json_encode($book['autori_nomi'] ?? 'Audiobook', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
                album: 'Biblioteca',
                artwork: [
                    <?php if (!empty($book['copertina_url'])): ?>
                    { src: <?= json_encode(url($book['copertina_url']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, sizes: '512x512', type: 'image/jpeg' }
                    <?php else: ?>
                    { src: <?= json_encode(url('/uploads/copertine/placeholder.jpg'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, sizes: '512x512', type: 'image/jpeg' }
                    <?php endif; ?>
                ]
            });

            // Handle play action from OS controls
            navigator.mediaSession.setActionHandler('play', function() {
                audioEl.play();
                navigator.mediaSession.playbackState = 'playing';
            });

            // Handle pause action from OS controls
            navigator.mediaSession.setActionHandler('pause', function() {
                audioEl.pause();
                navigator.mediaSession.playbackState = 'paused';
            });

            // Handle seek backward (usually 10 seconds)
            navigator.mediaSession.setActionHandler('seekbackward', function(details) {
                const skipTime = details.seekOffset || 10;
                audioEl.currentTime = Math.max(audioEl.currentTime - skipTime, 0);
            });

            // Handle seek forward (usually 10 seconds)
            navigator.mediaSession.setActionHandler('seekforward', function(details) {
                const skipTime = details.seekOffset || 10;
                audioEl.currentTime = Math.min(audioEl.currentTime + skipTime, audioEl.duration);
            });

            // Handle seek to specific position
            navigator.mediaSession.setActionHandler('seekto', function(details) {
                if (details.fastSeek && ('fastSeek' in audioEl)) {
                    audioEl.fastSeek(details.seekTime);
                } else {
                    audioEl.currentTime = details.seekTime;
                }
            });

            // Update position state periodically for progress bar in OS controls
            audioEl.addEventListener('timeupdate', function() {
                if ('setPositionState' in navigator.mediaSession) {
                    if (audioEl.duration && !isNaN(audioEl.duration)) {
                        navigator.mediaSession.setPositionState({
                            duration: audioEl.duration,
                            playbackRate: audioEl.playbackRate,
                            position: audioEl.currentTime
                        });
                    }
                }
            });

            // Update playback state on play/pause
            audioEl.addEventListener('play', function() {
                navigator.mediaSession.playbackState = 'playing';
            });

            audioEl.addEventListener('pause', function() {
                navigator.mediaSession.playbackState = 'paused';
            });

            console.log('✓ Media Session API enabled (OS controls active)');
        } else {
            console.info('Media Session API not supported in this browser');
        }
    } catch (error) {
        console.error('Failed to initialize Green Audio Player:', error);
        // Fallback to native controls
        audioEl.controls = true;
    }
});
</script>

<style>
/* Audio Player — Black & White Theme */
.player-digital-library {
    margin: 0;
}

.player-digital-library.green-audio-player {
    box-shadow: none;
    border-radius: 12px;
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
}

/* Circular play button with triangle inside */
.player-digital-library .holder .play-pause-btn {
    width: 40px;
    height: 40px;
    background: #1e293b;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s ease;
}

.player-digital-library .holder .play-pause-btn:hover {
    background: #334155;
}

.player-digital-library .holder .play-pause-btn svg {
    width: 14px;
    height: 14px;
}

.player-digital-library .holder .play-pause-btn svg path {
    fill: #fff;
}

/* Progress bar */
.player-digital-library .slider .gap-progress {
    background-color: #1e293b !important;
}

.player-digital-library .controls__slider {
    background-color: #e5e7eb;
}

.player-digital-library .pin {
    background-color: #1e293b !important;
}

/* Time labels */
.player-digital-library .controls__current-time,
.player-digital-library .controls__total-time {
    color: #475569;
}

/* Volume icon */
.player-digital-library .volume__speaker {
    fill: #475569;
}

.player-digital-library .volume__progress {
    background-color: #1e293b !important;
}

/* Download button */
#audiobook-player-container .btn-success {
    background-color: #1e293b !important;
    border-color: #1e293b !important;
    color: #fff;
}

#audiobook-player-container .btn-success:hover,
#audiobook-player-container .btn-success:focus {
    background-color: #334155 !important;
    border-color: #334155 !important;
}

/* Card Styling */
#audiobook-player-container .card {
    border: 2px solid #e5e7eb;
    transition: box-shadow 0.3s ease;
}

#audiobook-player-container .card:hover {
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
}

/* Responsive */
@media (max-width: 768px) {
    #audiobook-player-container .card-body {
        padding: 1rem;
    }

    #audiobook-player-container .row {
        font-size: 0.75rem;
    }
}
</style>
