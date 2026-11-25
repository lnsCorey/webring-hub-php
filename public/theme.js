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
    return def === 'light' || def === 'dark' || def === 'system' ? def : 'system';
  }

  // The toggle cycles System -> Light -> Dark -> System.
  var NEXT = { system: 'light', light: 'dark', dark: 'system' };
  var LABELS = { system: '◐ System', light: '☀ Light', dark: '🌙 Dark' };

  function updateButtons(pref) {
    var btns = document.querySelectorAll('[data-theme-toggle]');
    var hint = 'Theme: ' + pref + ' (click for ' + NEXT[pref] + ')';
    for (var i = 0; i < btns.length; i++) {
      var btn = btns[i];
      btn.textContent = LABELS[pref];
      btn.setAttribute('aria-label', hint);
      btn.setAttribute('title', hint);
    }
  }

  function applyTheme(pref) {
    var resolved = resolveTheme(pref);
    root.setAttribute('data-theme', resolved);
    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');
    root.setAttribute('data-theme-pref', pref);
    updateButtons(pref);
    return resolved;
  }

  applyTheme(getPreference());

  document.addEventListener('DOMContentLoaded', function () {
    updateButtons(getPreference());
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function () {
        var next = NEXT[getPreference()] || 'system';
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
