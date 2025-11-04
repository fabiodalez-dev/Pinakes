import '../css/input.css';

// Dark mode toggle persisted in localStorage
const STORAGE_KEY = 'darkMode';
function applyDarkModeClass(value) {
  const root = document.documentElement;
  if (value) root.classList.add('dark');
  else root.classList.remove('dark');
}
function initDarkMode() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    const isDark = saved ? JSON.parse(saved) : false;
    applyDarkModeClass(isDark);
    window.toggleDarkMode = () => {
      const next = !document.documentElement.classList.contains('dark');
      applyDarkModeClass(next);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    };
  } catch (e) {}
}
document.addEventListener('DOMContentLoaded', initDarkMode);
