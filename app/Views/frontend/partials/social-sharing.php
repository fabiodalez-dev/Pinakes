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

$providers = [
    'facebook'  => [
        'name'  => __('Facebook'),
        'icon'  => 'fab fa-facebook-f',
        'color' => '#1877F2',
        'url'   => 'https://www.facebook.com/sharer/sharer.php?u={url}',
        'label' => __('Condividi su Facebook'),
    ],
    'x'         => [
        'name'  => 'X',
        'icon'  => 'fab fa-x-twitter',
        'color' => '#000000',
        'url'   => 'https://twitter.com/intent/tweet?text={title}&url={url}',
        'label' => __('Condividi su X'),
    ],
    'whatsapp'  => [
        'name'  => 'WhatsApp',
        'icon'  => 'fab fa-whatsapp',
        'color' => '#25D366',
        'url'   => 'https://wa.me/?text={title}%20{url}',
        'label' => __('Condividi su WhatsApp'),
    ],
    'telegram'  => [
        'name'  => 'Telegram',
        'icon'  => 'fab fa-telegram',
        'color' => '#0088CC',
        'url'   => 'https://t.me/share/url?url={url}&text={title}',
        'label' => __('Condividi su Telegram'),
    ],
    'linkedin'  => [
        'name'  => 'LinkedIn',
        'icon'  => 'fab fa-linkedin-in',
        'color' => '#0A66C2',
        'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
        'label' => __('Condividi su LinkedIn'),
    ],
    'reddit'    => [
        'name'  => 'Reddit',
        'icon'  => 'fab fa-reddit-alien',
        'color' => '#FF4500',
        'url'   => 'https://www.reddit.com/submit?url={url}&title={title}',
        'label' => __('Condividi su Reddit'),
    ],
    'pinterest' => [
        'name'  => 'Pinterest',
        'icon'  => 'fab fa-pinterest-p',
        'color' => '#E60023',
        'url'   => 'https://pinterest.com/pin/create/button/?url={url}&description={title}',
        'label' => __('Condividi su Pinterest'),
    ],
    'threads'   => [
        'name'  => 'Threads',
        'icon'  => 'fab fa-threads',
        'color' => '#000000',
        'url'   => 'https://www.threads.com/intent/post?text={title}%20{url}',
        'label' => __('Condividi su Threads'),
    ],
    'bluesky'   => [
        'name'  => 'Bluesky',
        'icon'  => 'fab fa-bluesky',
        'color' => '#0085FF',
        'url'   => 'https://bsky.app/intent/compose?text={title}%20{url}',
        'label' => __('Condividi su Bluesky'),
    ],
    'tumblr'    => [
        'name'  => 'Tumblr',
        'icon'  => 'fab fa-tumblr',
        'color' => '#36465D',
        'url'   => 'https://www.tumblr.com/widgets/share/tool?canonicalUrl={url}&title={title}',
        'label' => __('Condividi su Tumblr'),
    ],
    'pocket'    => [
        'name'  => 'Pocket',
        'icon'  => 'fab fa-get-pocket',
        'color' => '#EF4056',
        'url'   => 'https://getpocket.com/save?url={url}&title={title}',
        'label' => __('Salva su Pocket'),
    ],
    'vk'        => [
        'name'  => 'VKontakte',
        'icon'  => 'fab fa-vk',
        'color' => '#4680C2',
        'url'   => 'https://vk.com/share.php?url={url}&title={title}',
        'label' => __('Condividi su VK'),
    ],
    'line'      => [
        'name'  => 'LINE',
        'icon'  => 'fab fa-line',
        'color' => '#00C300',
        'url'   => 'https://social-plugins.line.me/lineit/share?url={url}',
        'label' => __('Condividi su LINE'),
    ],
    'sms'       => [
        'name'  => 'SMS',
        'icon'  => 'fas fa-sms',
        'color' => '#666666',
        'url'   => 'sms:?body={title}%20{url}',
        'label' => __('Invia via SMS'),
    ],
    'email'     => [
        'name'  => 'Email',
        'icon'  => 'fas fa-envelope',
        'color' => '#666666',
        'url'   => 'mailto:?subject={title}&body={url}',
        'label' => __('Invia per email'),
    ],
    'copylink'  => [
        'name'  => __('Copia link'),
        'icon'  => 'fas fa-link',
        'color' => '#666666',
        'url'   => '',
        'label' => __('Copia link'),
    ],
];

$encodedUrl   = rawurlencode($shareUrl);
$encodedTitle = rawurlencode($shareTitle);
?>

<div class="card" id="book-share-card">
  <div class="card-header">
    <h6 class="mb-0"><i class="fas fa-share-alt me-2"></i><?= htmlspecialchars(__('Condividi'), ENT_QUOTES, 'UTF-8') ?></h6>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-around gap-2">
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

    <?php /* Web Share API — shown only when browser supports it */ ?>
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
  width: 38px;
  height: 38px;
  border-radius: 50%;
  color: #fff;
  font-size: 1rem;
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
