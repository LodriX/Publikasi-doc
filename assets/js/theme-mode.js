(function () {
  const validModes = new Set(['light', 'dark']);
  const cookieName = 'simpun_theme_mode';
  const basePath = document.documentElement.dataset.basePath || '/';
  const controls = [...document.querySelectorAll('[data-theme-mode]')];
  const rememberMode = mode => {
    document.documentElement.dataset.theme = mode;
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${cookieName}=${mode}; Path=${basePath}; Max-Age=31536000; SameSite=Lax${secure}`;
    try { localStorage.setItem(cookieName, mode); } catch { /* Cookie keeps the preference when storage is unavailable. */ }
    document.querySelectorAll('[data-theme-mode]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.themeMode === mode));
    });
  };

  if (controls.length) {
    let saved = '';
    try { saved = localStorage.getItem(cookieName) || ''; } catch { /* Use server/cookie default. */ }
    if (validModes.has(saved)) rememberMode(saved);
    else controls.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.themeMode === document.documentElement.dataset.theme)));
  }

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-theme-mode]');
    if (!button || !validModes.has(button.dataset.themeMode)) return;
    rememberMode(button.dataset.themeMode);
  });
})();
