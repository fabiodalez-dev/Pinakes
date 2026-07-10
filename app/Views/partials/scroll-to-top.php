<script>
(function() {
  var btn = document.createElement('button');
  btn.id = 'scroll-to-top';
  var scrollToTopLabel = <?= json_encode(__('Torna su'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  btn.setAttribute('aria-label', scrollToTopLabel);
  btn.setAttribute('title', scrollToTopLabel);
  var icon = document.createElement('i');
  icon.className = 'fas fa-chevron-up text-sm';
  btn.appendChild(icon);
  btn.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;width:44px;height:44px;border-radius:9999px;background:var(--white);background:color-mix(in srgb,var(--white) 85%,transparent);backdrop-filter:blur(8px);border:1px solid var(--border-color);box-shadow:var(--card-shadow);color:var(--text-light);display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;pointer-events:none;transition:all 0.3s ease;';
  document.body.appendChild(btn);

  btn.addEventListener('mouseenter', function() {
    btn.style.color = 'var(--primary-color)';
    btn.style.borderColor = 'var(--primary-color)';
    btn.style.boxShadow = '0 8px 24px rgba(0,0,0,0.15)';
  });
  btn.addEventListener('mouseleave', function() {
    btn.style.color = 'var(--text-light)';
    btn.style.borderColor = 'var(--border-color)';
    btn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
  });

  var visible = false;
  btn.setAttribute('aria-hidden', 'true');
  btn.tabIndex = -1;

  function updateVisibility() {
    var show = window.scrollY > 400;
    if (show !== visible) {
      visible = show;
      btn.style.opacity = show ? '1' : '0';
      btn.style.pointerEvents = show ? 'auto' : 'none';
      btn.setAttribute('aria-hidden', show ? 'false' : 'true');
      btn.tabIndex = show ? 0 : -1;
    }
  }

  updateVisibility();
  window.addEventListener('scroll', updateVisibility, { passive: true });

  btn.addEventListener('click', function() {
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
  });
})();
</script>
