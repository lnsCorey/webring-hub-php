(function () {
  var STORAGE_KEY = 'webring-hub-theme';
  var root = document.documentElement;

  function resolveTheme(pref) {
    if (pref === 'light' || pref === 'dark') return pref;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      return 'light';
    }
    return 'dark';
  }

  function getPreference() {
    try {
      var saved = window.localStorage.getItem(STORAGE_KEY);
      if (saved === 'light' || saved === 'dark' || saved === 'system') return saved;
    } catch (_) {}
    var def = root.getAttribute('data-default-theme');
    return def === 'light' || def === 'dark' || def === 'system' ? def : 'dark';
  }

  function updateButtons(resolved) {
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      var btn = btns[i];
      var isLight = resolved === 'light';
      btn.textContent = isLight ? '🌙 Dark' : '☀ Light';
      btn.setAttribute('aria-label', isLight ? 'Switch to dark theme' : 'Switch to light theme');
      btn.setAttribute('aria-pressed', isLight ? 'true' : 'false');
      btn.setAttribute('title', isLight ? 'Switch to dark theme' : 'Switch to light theme');
    }
  }

  function applyTheme(pref) {
    var resolved = resolveTheme(pref);
    root.setAttribute('data-theme', resolved);
    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');
    updateButtons(resolved);
    return resolved;
  }

  applyTheme(getPreference());

  document.addEventListener('DOMContentLoaded', function () {
    updateButtons(root.getAttribute('data-theme') || 'dark');
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function () {
        var current = root.getAttribute('data-theme') || 'dark';
        var next = current === 'light' ? 'dark' : 'light';
        try {
          window.localStorage.setItem(STORAGE_KEY, next);
        } catch (_) {}
        applyTheme(next);
      });
    }
  });

  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: light)');
    if (mq && typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', function () {
        if (getPreference() === 'system') applyTheme('system');
      });
    }
  }
})();
