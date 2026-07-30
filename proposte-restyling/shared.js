const root = document.documentElement;

document.querySelectorAll('[data-view]').forEach((button) => {
  button.addEventListener('click', () => {
    const target = button.dataset.view;
    document.querySelectorAll('[data-view]').forEach((item) => item.classList.toggle('is-active', item === button));
    document.querySelectorAll('.view').forEach((view) => view.classList.toggle('is-active', view.id === target));
    window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  });
});

document.querySelectorAll('[data-accent]').forEach((button) => {
  button.addEventListener('click', () => {
    root.style.setProperty('--accent', button.dataset.accent);
    root.style.setProperty('--accent-ink', button.dataset.ink || '#fdfcf9');
    document.querySelectorAll('[data-accent]').forEach((item) => item.classList.toggle('is-selected', item === button));
  });
});

document.querySelectorAll('[data-theme]').forEach((button) => {
  button.addEventListener('click', () => {
    root.dataset.theme = button.dataset.theme;
    document.querySelectorAll('[data-theme]').forEach((item) => item.classList.toggle('is-active', item === button));
  });
});

const settings = document.querySelector('.settings-panel');
const scrim = document.querySelector('.scrim');
function toggleSettings(open) {
  if (!settings) return;
  settings.classList.toggle('is-open', open);
  scrim?.classList.toggle('is-open', open);
  settings.setAttribute('aria-hidden', String(!open));
}
document.querySelectorAll('[data-open-settings]').forEach((button) => button.addEventListener('click', () => toggleSettings(true)));
document.querySelectorAll('[data-close-settings]').forEach((button) => button.addEventListener('click', () => toggleSettings(false)));
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') toggleSettings(false); });

document.querySelectorAll('.favorite').forEach((button) => button.addEventListener('click', () => {
  button.classList.toggle('is-active');
  button.setAttribute('aria-label', button.classList.contains('is-active') ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti');
}));

document.querySelectorAll('.filter-chip').forEach((button) => button.addEventListener('click', () => button.classList.toggle('is-active')));
