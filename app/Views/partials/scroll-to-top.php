<script>
(function() {
  var btn = document.createElement('button');
  btn.id = 'scroll-to-top';
  btn.setAttribute('aria-label', '<?= __('Torna su') ?>');
  btn.setAttribute('title', '<?= __('Torna su') ?>');
  var icon = document.createElement('i');
  icon.className = 'fas fa-chevron-up text-sm';
  btn.appendChild(icon);
  btn.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;width:2.5rem;height:2.5rem;border-radius:9999px;background:rgba(255,255,255,0.85);backdrop-filter:blur(8px);border:1px solid #e5e7eb;box-shadow:0 4px 12px rgba(0,0,0,0.1);color:#6b7280;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:0;pointer-events:none;transition:all 0.3s ease;';
  document.body.appendChild(btn);

  btn.addEventListener('mouseenter', function() {
    btn.style.color = '#e11d48';
    btn.style.borderColor = '#fda4af';
    btn.style.boxShadow = '0 8px 24px rgba(0,0,0,0.15)';
  });
  btn.addEventListener('mouseleave', function() {
    btn.style.color = '#6b7280';
    btn.style.borderColor = '#e5e7eb';
    btn.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
  });

  var visible = false;
  window.addEventListener('scroll', function() {
    var show = window.scrollY > 400;
    if (show !== visible) {
      visible = show;
      btn.style.opacity = show ? '1' : '0';
      btn.style.pointerEvents = show ? 'auto' : 'none';
    }
  }, { passive: true });

  btn.addEventListener('click', function() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();
</script>
