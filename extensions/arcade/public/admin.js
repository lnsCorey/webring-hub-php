(function () {
  'use strict';

  const API_BASE = '../../api/ext/arcade/v1/admin';
  // Same key as the hub's own admin page, so one sign-in covers both and signing out clears both.
  const TOKEN_KEY = 'webring-hub-admin-token';
  const el = (id) => document.getElementById(id);

  function make(tag, className, text) {
    const n = document.createElement(tag);
    if (className) n.className = className;
    if (text !== undefined) n.textContent = String(text);
    return n;
  }

  function getToken() {
    return el('admin-token').value.trim() || sessionStorage.getItem(TOKEN_KEY) || '';
  }

  async function api(path, method = 'GET', body = undefined) {
    const token = getToken();
    const res = await fetch(`${API_BASE}${path}`, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
    return data;
  }

  async function refresh() {
    try {
      const data = await api('/games');
      sessionStorage.setItem(TOKEN_KEY, getToken());
      el('admin-status').textContent = 'Connected';
      el('admin-main').classList.remove('hidden');

      const tbody = el('admin-games-body');
      tbody.replaceChildren();
      (data.games || []).forEach((g) => {
        const tr = make('tr');
        tr.appendChild(make('td', '', g.slug));
        tr.appendChild(make('td', '', `${g.title} (v${g.version})`));
        tr.appendChild(make('td', '', `${g.present ? 'Present' : 'Missing'} / ${g.enabled ? 'Enabled' : 'Disabled'}`));
        tr.appendChild(make('td', '', `${g.plays} plays / ${g.playersScored} players`));

        const actionsTd = make('td');
        const toggleBtn = make('button', 'btn', g.enabled ? 'Disable' : 'Enable');
        toggleBtn.type = 'button';
        toggleBtn.addEventListener('click', async () => {
          await api(`/games/${encodeURIComponent(g.slug)}/enabled`, 'POST', { enabled: !g.enabled });
          await refresh();
        });
        const purgeBtn = make('button', 'btn btn-danger', 'Purge Scores');
        purgeBtn.type = 'button';
        purgeBtn.style.marginLeft = '0.5rem';
        purgeBtn.addEventListener('click', async () => {
          await api(`/games/${encodeURIComponent(g.slug)}/scores`, 'DELETE');
          await refresh();
        });
        actionsTd.append(toggleBtn, purgeBtn);
        tr.appendChild(actionsTd);
        tbody.appendChild(tr);
      });

      const errBox = el('admin-errors');
      const errs = data.validationErrors || {};
      const keys = Object.keys(errs);
      if (keys.length === 0) {
        errBox.textContent = 'No manifest validation errors.';
      } else {
        errBox.textContent = keys.map((k) => `${k}: ${errs[k]}`).join(' | ');
      }
    } catch (err) {
      el('admin-status').textContent = err.message || 'Unauthorized';
    }
  }

  el('admin-load-btn').addEventListener('click', refresh);
  el('purge-did-btn').addEventListener('click', async () => {
    const did = el('purge-did-input').value.trim();
    if (!did) return;
    await api(`/players/${encodeURIComponent(did)}`, 'DELETE');
    el('purge-did-input').value = '';
    await refresh();
  });

  const saved = sessionStorage.getItem(TOKEN_KEY) || '';
  if (saved) {
    el('admin-token').value = saved;
    refresh();
  }
})();
