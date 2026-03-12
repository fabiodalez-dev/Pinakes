<?php
/**
 * Social sharing buttons partial
 *
 * Expected variables (set by FrontendController::bookDetail):
 * @var array  $sharingProviders  List of enabled provider slugs
 * @var string $shareUrl          Absolute URL of the book page
 * @var string $shareTitle        Book title for share text
 */
declare(strict_types=1);

if (empty($sharingProviders)) {
    return;
}

$providers = \App\Support\SharingProviders::all();

$encodedUrl   = rawurlencode($shareUrl);
$encodedTitle = rawurlencode($shareTitle);
?>

<div class="card" id="book-share-card">
  <div class="card-header">
    <h6 class="mb-0"><i class="fas fa-share-alt me-2"></i><?= htmlspecialchars(__('Condividi'), ENT_QUOTES, 'UTF-8') ?></h6>
  </div>
  <div class="card-body py-2 px-3">
    <div class="d-flex flex-wrap gap-2">
    <?php foreach ($sharingProviders as $slug): ?>
      <?php if (!isset($providers[$slug])) { continue; } ?>
      <?php $p = $providers[$slug]; ?>

      <?php if ($slug === 'copylink'): ?>
        <button type="button"
                class="social-share-btn"
                style="background-color: <?= htmlspecialchars($p['color'], ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?>"
                data-share-copy="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
          <i class="<?= htmlspecialchars($p['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
        </button>
      <?php else: ?>
        <?php
        $href = str_replace(['{url}', '{title}'], [$encodedUrl, $encodedTitle], $p['url']);
        ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
           class="social-share-btn"
           style="background-color: <?= htmlspecialchars($p['color'], ENT_QUOTES, 'UTF-8') ?>"
           title="<?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?>"
           aria-label="<?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?>"
           <?php if ($slug !== 'email' && $slug !== 'sms'): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>>
          <i class="<?= htmlspecialchars($p['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php /* Web Share API — intentional progressive enhancement: always rendered,
           shown via JS only when navigator.share exists. Not gated by admin config
           because it delegates to the OS share sheet (not a specific provider). */ ?>
    <button type="button"
            class="social-share-btn social-share-webapi"
            style="background-color: #333; display: none;"
            title="<?= htmlspecialchars(__('Condividi'), ENT_QUOTES, 'UTF-8') ?>"
            aria-label="<?= htmlspecialchars(__('Condividi'), ENT_QUOTES, 'UTF-8') ?>"
            data-share-title="<?= htmlspecialchars($shareTitle, ENT_QUOTES, 'UTF-8') ?>"
            data-share-url="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
      <i class="fas fa-share-nodes"></i>
    </button>
    </div>
  </div>
</div>

<style>
.social-share-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  color: #fff;
  font-size: 0.8rem;
  border: none;
  cursor: pointer;
  transition: opacity 0.2s, transform 0.2s;
  text-decoration: none;
}
.social-share-btn:hover {
  opacity: 0.85;
  transform: scale(1.1);
  color: #fff;
  text-decoration: none;
}
</style>

<script>
(function() {
  function fallbackCopy(url, btn) {
    var ta = document.createElement('textarea');
    ta.value = url;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    showCopyFeedback(btn);
  }

  // Copy link button
  document.querySelectorAll('[data-share-copy]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var url = this.getAttribute('data-share-copy');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url)
          .then(function() { showCopyFeedback(btn); })
          .catch(function() { fallbackCopy(url, btn); });
      } else {
        fallbackCopy(url, btn);
      }
    });
  });

  function showCopyFeedback(btn) {
    var origTitle = btn.getAttribute('title');
    btn.setAttribute('title', <?= json_encode(__('Link copiato!'), JSON_HEX_TAG) ?>);
    btn.style.backgroundColor = '#22c55e';
    var icon = btn.querySelector('i');
    if (icon) {
      var origClass = icon.className;
      icon.className = 'fas fa-check';
      setTimeout(function() {
        icon.className = origClass;
        btn.style.backgroundColor = '#666666';
        btn.setAttribute('title', origTitle);
      }, 1500);
    }
  }

  // Web Share API (mobile)
  var webShareBtn = document.querySelector('.social-share-webapi');
  if (webShareBtn && navigator.share) {
    webShareBtn.style.display = '';
    webShareBtn.addEventListener('click', function() {
      navigator.share({
        title: this.getAttribute('data-share-title'),
        url: this.getAttribute('data-share-url')
      }).catch(function() { /* user cancelled */ });
    });
  }
})();
</script>
