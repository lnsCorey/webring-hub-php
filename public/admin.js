// Webring Hub admin page. Everything here talks to the hub's own API; the admin token lives only
// in this tab's sessionStorage and is sent as a Bearer header, never in a URL. Listing data comes
// from third-party sites, so it is only ever rendered with textContent.

const TOKEN_KEY = 'webring-hub-admin-token';
const PAGE_SIZE = 50;
const STATUSES = ['active', 'blocked', 'stale'];

const $ = (id) => document.getElementById(id);

const els = {
  signinPanel: $('signin-panel'),
  signinForm: $('signin-form'),
  token: $('token'),
  signinMessage: $('signin-message'),
  adminArea: $('admin-area'),
  sessionPill: $('session-pill'),
  signOut: $('sign-out'),
  filterForm: $('filter-form'),
  filterStatus: $('filter-status'),
  filterQ: $('filter-q'),
  listStatus: $('list-status'),
  listCount: $('list-count'),
  body: $('entries-body'),
  empty: $('list-empty'),
  prev: $('page-prev'),
  next: $('page-next'),
  pageInfo: $('page-info'),
  addForm: $('add-form'),
  addUrl: $('add-url'),
  addMessage: $('add-message'),
  recheckForm: $('recheck-form'),
  recheckLimit: $('recheck-limit'),
  recheckMessage: $('recheck-message'),
  settingsForm: $('settings-form'),
  settingsMessage: $('settings-message'),
  extList: $('ext-list'),
  extMessage: $('ext-message'),
  notices: $('admin-notices'),
  keyDialog: $('key-dialog'),
  keyDialogSite: $('key-dialog-site'),
  keyDialogDid: $('key-dialog-did'),
  keyDialogPinned: $('key-dialog-pinned'),
  keyDialogCandidate: $('key-dialog-candidate'),
  keyDialogFirstSeen: $('key-dialog-first-seen'),
  keyDialogConflict: $('key-dialog-conflict'),
  keyDialogMessage: $('key-dialog-message'),
  keyDialogTrust: $('key-dialog-trust')
};

const state = {
  signedIn: false,
  status: '',
  q: '',
  offset: 0,
  total: 0,
  entries: [],
  requestId: 0,
  busy: new Set(),
  rowMessages: new Map(),
  initialSettings: null
};

// ---- token (sessionStorage only; an in-memory copy covers browsers that block storage) ----

let memoryToken = '';

function currentToken() {
  try {
    return sessionStorage.getItem(TOKEN_KEY) || memoryToken;
  } catch {
    return memoryToken;
  }
}

function storeToken(token) {
  memoryToken = token;
  try {
    sessionStorage.setItem(TOKEN_KEY, token);
  } catch {
    // Storage blocked: the in-memory copy lasts until the page is reloaded.
  }
}

function clearToken() {
  memoryToken = '';
  try {
    sessionStorage.removeItem(TOKEN_KEY);
  } catch {
    // Nothing stored.
  }
}

// ---- helpers ----

class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

async function api(path, { method = 'GET', body, admin = true } = {}) {
  const headers = { accept: 'application/json' };
  if (body !== undefined) headers['content-type'] = 'application/json';
  if (admin) headers.authorization = `Bearer ${currentToken()}`;
  let response;
  try {
    response = await fetch(path, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      cache: 'no-store',
      credentials: 'same-origin'
    });
  } catch {
    throw new ApiError(0, 'network_error', 'Could not reach the hub. Check your connection and try again.');
  }
  if (response.status === 204) return {};
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const err = new ApiError(response.status, data.error || 'http_error', data.message || `Request failed (${response.status}).`);
    if (admin && response.status === 401 && state.signedIn) {
      signOut('The hub no longer accepts this token. Sign in again.', 'error');
    }
    throw err;
  }
  return data;
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

function httpUrl(value) {
  if (typeof value !== 'string') return '';
  try {
    const url = new URL(value);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
  } catch {
    return '';
  }
}

function formatDateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function shortFingerprint(fp) {
  return typeof fp === 'string' && fp.length > 16 ? `${fp.slice(0, 10)}…${fp.slice(-6)}` : fp || '';
}

/** Full fingerprint in groups of four (uppercase), the form to read out to a site owner. */
function groupFingerprint(fp) {
  if (typeof fp !== 'string' || !fp) return '—';
  return fp.toUpperCase().match(/.{1,4}/g).join(' ');
}

function setMessage(node, text, kind) {
  node.textContent = text || '';
  node.className = `form-message${kind ? ` ${kind}` : ''}`;
}

/** did:web:host%3Aport:path:segments -> https://host:port/path/segments (as Verify.php builds it). */
function didWebToUrl(did) {
  if (typeof did !== 'string' || !did.startsWith('did:web:')) return '';
  try {
    const [host, ...segments] = did.slice('did:web:'.length).split(':').map((part) => decodeURIComponent(part));
    if (!host) return '';
    return `https://${host}${segments.length ? `/${segments.map(encodeURIComponent).join('/')}` : ''}`;
  } catch {
    return '';
  }
}

function entryLabel(entry) {
  return entry.displayName || entry.handle || entry.siteUrl || entry.did;
}

// ---- sign in / out ----

function showSignedIn() {
  state.signedIn = true;
  els.signinPanel.hidden = true;
  els.adminArea.hidden = false;
  els.sessionPill.hidden = false;
  els.signOut.hidden = false;
}

function signOut(message, kind) {
  state.signedIn = false;
  state.requestId++;
  clearToken();
  els.adminArea.hidden = true;
  els.sessionPill.hidden = true;
  els.signOut.hidden = true;
  els.signinPanel.hidden = false;
  els.body.replaceChildren();
  els.extList.replaceChildren();
  els.notices.replaceChildren();
  els.notices.hidden = true;
  if (els.keyDialog.open) els.keyDialog.close();
  state.entries = [];
  state.rowMessages.clear();
  els.token.value = '';
  setMessage(els.signinMessage, message || '', kind);
  els.token.focus();
}

function explainSignInError(err) {
  if (err.status === 401) {
    return 'That token was not accepted. It must match admin_token in config.php (or the ADMIN_TOKEN environment variable) exactly.';
  }
  if (err.status === 503 && err.code === 'admin_disabled') {
    return "Admin access is turned off on this hub because no admin token is configured. Set 'admin_token' in config.php, or the ADMIN_TOKEN environment variable, to a long random value (for example from: openssl rand -hex 32), then sign in with that value.";
  }
  return err.message || 'Could not sign in.';
}

async function signIn(token) {
  storeToken(token);
  setMessage(els.signinMessage, 'Checking the token…');
  const button = els.signinForm.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    // Any admin endpoint validates the token; this one also fills the Extensions panel.
    const data = await api('/api/admin/extensions');
    showSignedIn();
    setMessage(els.signinMessage, '');
    els.token.value = '';
    renderExtensions(Array.isArray(data.extensions) ? data.extensions : []);
    loadEntries();
    loadSettings();
    loadStatus();
  } catch (err) {
    clearToken();
    setMessage(els.signinMessage, explainSignInError(err), 'error');
  } finally {
    button.disabled = false;
  }
}

els.signinForm.addEventListener('submit', (event) => {
  event.preventDefault();
  const token = els.token.value.trim();
  if (token) signIn(token);
});

els.signOut.addEventListener('click', () => signOut('Signed out. The token was removed from this tab.', 'success'));

// ---- listings ----

async function loadEntries() {
  const requestId = ++state.requestId;
  // Keep an action's result visible while the list reloads after it.
  if (els.listStatus.dataset.keep !== '1') setMessage(els.listStatus, 'Loading…');
  const params = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(state.offset) });
  if (state.status) params.set('status', state.status);
  if (state.q) params.set('q', state.q);
  try {
    const data = await api(`/api/admin/entries?${params}`);
    if (requestId !== state.requestId) return;
    state.entries = Array.isArray(data.entries) ? data.entries : [];
    state.total = Number(data.total) || 0;
    if (state.entries.length === 0 && state.offset > 0 && state.total > 0) {
      state.offset = Math.max(0, Math.floor((state.total - 1) / PAGE_SIZE) * PAGE_SIZE);
      loadEntries();
      return;
    }
    if (els.listStatus.dataset.keep !== '1') setMessage(els.listStatus, '');
    els.listStatus.dataset.keep = '';
    renderEntries();
  } catch (err) {
    if (requestId !== state.requestId) return;
    els.listStatus.dataset.keep = '';
    setMessage(els.listStatus, err.message, 'error');
  }
}

function renderEntries() {
  const fragment = document.createDocumentFragment();
  for (const entry of state.entries) fragment.append(renderRow(entry));
  els.body.replaceChildren(fragment);

  els.listCount.textContent = state.total ? `${state.total.toLocaleString()} total` : '';
  els.empty.hidden = state.entries.length !== 0;
  els.empty.textContent = state.status || state.q ? 'No listings match these filters.' : 'Nothing is listed yet. Add a site to get started.';

  const from = state.total ? state.offset + 1 : 0;
  const to = state.offset + state.entries.length;
  els.pageInfo.textContent = state.total ? `${from}–${to} of ${state.total.toLocaleString()}` : '';
  els.prev.disabled = state.offset === 0;
  els.next.disabled = to >= state.total;
}

/** Appends a labelled cell to the row and returns the wrapper to fill (one grid item on phones). */
function cell(tr, label, className) {
  const td = element('td', className);
  td.dataset.label = label;
  const body = element('div', 'admin-cell-body');
  td.append(body);
  tr.append(td);
  return body;
}

function renderRow(entry) {
  const tr = element('tr');
  const status = STATUSES.includes(entry.status) ? entry.status : 'unknown';
  if (state.busy.has(entry.did)) tr.setAttribute('aria-busy', 'true');

  // Site: name (link), handle, DID
  const site = cell(tr, 'Site', 'admin-cell-site');
  const siteUrl = httpUrl(entry.siteUrl);
  const nameEl = element('div', 'admin-name');
  if (siteUrl) {
    const link = element('a', null, entry.displayName || new URL(siteUrl).hostname);
    link.href = siteUrl;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    nameEl.append(link);
  } else {
    nameEl.textContent = entry.displayName || 'Unnamed';
  }
  site.append(nameEl);
  if (entry.handle) site.append(element('div', 'admin-sub admin-mono', entry.handle));
  const didEl = element('code', 'admin-did', entry.did);
  didEl.title = entry.did;
  site.append(didEl);

  // Status
  const statusCell = cell(tr, 'Status');
  statusCell.append(element('span', `admin-badge admin-badge-${status}`, status));

  // Last verified / checked
  const verified = cell(tr, 'Last verified');
  verified.append(element('div', null, formatDateTime(entry.verifiedAt) || '—'));
  if (entry.lastCheckedAt) verified.append(element('div', 'admin-sub', `Checked ${formatDateTime(entry.lastCheckedAt)}`));

  // Failures / last error
  const checks = cell(tr, 'Checks');
  const failCount = Number(entry.failCount) || 0;
  checks.append(element('div', failCount ? 'admin-warn' : null, failCount === 1 ? '1 failure' : `${failCount} failures`));
  if (entry.lastError) {
    const errEl = element('div', 'admin-error', entry.lastError);
    errEl.title = entry.lastError;
    checks.append(errEl);
  }

  // Key fingerprint, plus a warning while the site serves a new key nobody has reviewed yet
  const key = cell(tr, 'Key');
  if (entry.keyFingerprint) {
    const fp = element('code', 'admin-fp', shortFingerprint(entry.keyFingerprint));
    fp.title = `SHA-256 of the SPKI key: ${entry.keyFingerprint}`;
    key.append(fp);
  } else {
    key.append(element('span', 'admin-sub', '—'));
  }
  if (entry.keyChanged) {
    const badges = element('div', 'admin-key-badges');
    const changed = element('span', 'admin-badge admin-badge-key-changed', 'Key changed');
    changed.title = 'The site now serves a different signing key without a rotation certificate. The listing keeps the old key until you review the new one.';
    badges.append(changed);
    if (entry.candidateConflict) {
      const conflict = element('span', 'admin-badge admin-badge-key-conflict', 'Multiple new keys seen');
      conflict.title = 'The site has served more than one new key since the change was first detected.';
      badges.append(conflict);
    }
    key.append(badges);
  }

  // Actions
  const actions = cell(tr, 'Actions', 'admin-cell-actions');
  const group = element('div', 'admin-actions');
  const busy = state.busy.has(entry.did);
  const addButton = (label, action, opts = {}) => {
    const button = element('button', `admin-button admin-button-sm${opts.danger ? ' admin-button-danger' : ''}`, label);
    button.type = 'button';
    button.disabled = busy || Boolean(opts.disabled);
    if (opts.title) button.title = opts.title;
    button.setAttribute('aria-label', `${label}: ${entryLabel(entry)}`);
    button.addEventListener('click', () => runAction(entry, action));
    group.append(button);
  };
  if (status === 'blocked') {
    addButton('Unblock', 'activate');
  } else {
    if (status === 'stale') addButton('Mark active', 'activate');
    addButton('Block', 'block');
    if (status === 'active') addButton('Mark stale', 'stale');
  }
  addButton('Refresh now', 'refresh', {
    disabled: status === 'blocked',
    title: status === 'blocked' ? 'Unblock the site first; blocked sites are never re-checked.' : 'Fetch and verify the site again now'
  });
  if (entry.keyChanged) {
    addButton('Review new key', 'trust', { title: 'Compare fingerprints and decide whether to trust the new key' });
  }
  addButton(status === 'blocked' ? 'Delete listing (stays blocked)' : 'Delete', 'delete', { danger: true });
  actions.append(group);

  const note = state.rowMessages.get(entry.did);
  if (busy) {
    actions.append(element('p', 'form-message admin-row-message', 'Working…'));
  } else if (note) {
    const p = element('p', `form-message admin-row-message ${note.kind}`, note.text);
    p.setAttribute('role', 'status');
    actions.append(p);
  }
  return tr;
}

async function runAction(entry, action) {
  const did = entry.did;
  const enc = encodeURIComponent(did);
  const label = entryLabel(entry);

  if (action === 'trust') {
    openKeyDialog(entry);
    return;
  }
  if (action === 'delete') {
    const ok = window.confirm(
      entry.status === 'blocked'
        ? `Delete the listing for ${label} (${did})?\n\nThe site stays blocked: its owner can't submit it again. Unblock it later from the "blocked sites" notice if you change your mind.`
        : `Delete ${label} (${did}) from this hub?\n\nThe entry and any extension data for it are removed. The owner can list the site again by re-submitting it; block it instead to keep it out.`
    );
    if (!ok) return;
  }

  state.busy.add(did);
  state.rowMessages.delete(did);
  renderEntries();

  let note;
  try {
    if (action === 'block' || action === 'activate' || action === 'stale') {
      const status = action === 'block' ? 'blocked' : action === 'stale' ? 'stale' : 'active';
      await api(`/api/admin/entries/${enc}/status`, { method: 'POST', body: { status } });
      note = {
        kind: 'success',
        text:
          status === 'blocked'
            ? 'Blocked: hidden from the public listing and never re-checked.'
            : status === 'stale'
              ? 'Marked stale: hidden until a refresh succeeds.'
              : 'Active: shown in the public listing.'
      };
    } else if (action === 'refresh') {
      await api(`/api/entries/${enc}/refresh`, { method: 'POST', admin: false });
      note = { kind: 'success', text: 'Re-verified just now.' };
    } else if (action === 'delete') {
      await api(`/api/admin/entries/${enc}`, { method: 'DELETE' });
      note = { kind: 'success', text: 'Deleted.' };
    }
  } catch (err) {
    note = { kind: 'error', text: err.message || 'The action failed.' };
  } finally {
    state.busy.delete(did);
  }
  if (!state.signedIn) return;

  state.rowMessages.set(did, note);
  // Also show it above the table, in case the row leaves the current filter or page.
  setMessage(els.listStatus, `${label}: ${note.text}`, note.kind);
  els.listStatus.dataset.keep = '1';
  await loadEntries();
}

// ---- key review dialog ----

let keyDialogEntry = null;

function openKeyDialog(entry) {
  keyDialogEntry = entry;
  els.keyDialogSite.textContent = entryLabel(entry);
  els.keyDialogDid.textContent = entry.did;
  els.keyDialogPinned.textContent = groupFingerprint(entry.keyFingerprint);
  els.keyDialogCandidate.textContent = groupFingerprint(entry.candidateFingerprint);
  const firstSeen = formatDateTime(entry.candidateFirstSeenAt);
  els.keyDialogFirstSeen.textContent = firstSeen ? `(first seen ${firstSeen})` : '';
  els.keyDialogConflict.hidden = !entry.candidateConflict;
  els.keyDialogTrust.disabled = false;
  setMessage(els.keyDialogMessage, '');
  els.keyDialog.showModal();
}

const KEY_ERROR_TEXT = {
  served_key_changed:
    'The site is now serving a different key from the one shown here, so nothing was changed. Close this, refresh the site and review again.',
  candidate_mismatch: 'The new key on record changed while this was open. Close this and review the key again.',
  no_candidate: 'There is no new key waiting for review any more (the site may be serving its pinned key again).',
  identity_changed: 'The site now publishes a different did:web identity, so its key cannot be trusted here.'
};

els.keyDialogTrust.addEventListener('click', async () => {
  const entry = keyDialogEntry;
  if (!entry) return;
  els.keyDialogTrust.disabled = true;
  setMessage(els.keyDialogMessage, 'Fetching the site and checking it still serves this key…');
  try {
    const data = await api(`/api/admin/entries/${encodeURIComponent(entry.did)}/trust-key`, {
      method: 'POST',
      body: { expectedFingerprint: entry.candidateFingerprint }
    });
    els.keyDialog.close();
    const text = data.keyChanged ? 'New signing key trusted and pinned.' : 'Re-verified. The key had not changed.';
    state.rowMessages.set(entry.did, { kind: 'success', text });
    setMessage(els.listStatus, `${entryLabel(entry)}: ${text}`, 'success');
    els.listStatus.dataset.keep = '1';
    await loadEntries();
  } catch (err) {
    setMessage(els.keyDialogMessage, KEY_ERROR_TEXT[err.code] || err.message || 'Could not trust the key.', 'error');
    els.keyDialogTrust.disabled = err.code === 'served_key_changed' || err.code === 'candidate_mismatch' || err.code === 'no_candidate';
    if (err.code in KEY_ERROR_TEXT) loadEntries();
  }
});

els.keyDialog.addEventListener('close', () => {
  keyDialogEntry = null;
});

// ---- operator notices (hub URL, IP salt, schema upgrades) ----

function notice(kind, title, ...parts) {
  const box = element('div', `admin-notice admin-notice-${kind}`);
  box.setAttribute('role', kind === 'info' ? 'note' : 'alert');
  box.append(element('strong', null, title));
  for (const part of parts) box.append(part);
  return box;
}

function renderNotices(status) {
  const items = [];
  if (!status.hubUrlConfigured) {
    const fix = element('button', 'admin-button admin-button-sm', 'Set the Hub URL');
    fix.type = 'button';
    fix.addEventListener('click', () => {
      const input = $('set-hub-url');
      input.scrollIntoView({ behavior: 'smooth', block: 'center' });
      input.focus();
    });
    items.push(
      notice(
        'warning',
        'Self-service removal is disabled until the Hub URL is set.',
        element(
          'p',
          null,
          "Signed removal requests name the hub they're meant for, and this hub needs its own public URL to check that. Until it is set, site owners can't remove their own listings."
        ),
        fix
      )
    );
  }

  const maintenance = status.maintenance || {};
  const conflicts = Array.isArray(maintenance.collationConflicts) ? maintenance.collationConflicts : [];
  const errors = Array.isArray(maintenance.migrationErrors) ? maintenance.migrationErrors : [];
  const pending = Array.isArray(maintenance.pendingMigrations) ? maintenance.pendingMigrations : [];
  if (errors.length || conflicts.length || pending.length) {
    const parts = [];
    if (pending.length) {
      parts.push(
        element(
          'p',
          null,
          `${pending.length} schema upgrade(s) have not run yet (auto_migrate is probably off), so adding or refreshing sites may fail until they do: ${pending.join(', ')}.`
        )
      );
    }
    if (conflicts.length) {
      parts.push(
        element(
          'p',
          null,
          'These listings differ only by letter case or accents, which lets one site be mistaken for another. Delete the duplicates you do not want, then retry:'
        )
      );
      const ul = element('ul');
      for (const group of conflicts) {
        ul.append(element('li', 'admin-mono', `${group.column}: ${(group.values || []).join('  ·  ')}`));
      }
      parts.push(ul);
    }
    const other = errors.filter((e) => !(conflicts.length && e.id === '2026_11_collation_bin'));
    if (other.length) {
      const ul = element('ul');
      for (const e of other) ul.append(element('li', null, `${e.id}: ${e.message}`));
      parts.push(element('p', null, 'These schema upgrades failed:'), ul);
    }
    const retry = element('button', 'admin-button admin-button-sm', errors.length || conflicts.length ? 'Retry upgrades' : 'Run upgrades now');
    retry.type = 'button';
    retry.addEventListener('click', async () => {
      retry.disabled = true;
      try {
        await api('/api/admin/migrations/retry', { method: 'POST', body: {} });
      } catch (err) {
        setMessage(els.listStatus, err.message || 'The retry failed.', 'error');
      }
      await loadStatus();
    });
    parts.push(retry);
    items.push(notice('error', 'Database maintenance needed', ...parts));
  }

  const blocked = Array.isArray(status.blockedWithoutListing) ? status.blockedWithoutListing : [];
  if (blocked.length) {
    const ul = element('ul');
    for (const site of blocked) {
      const li = element('li', null);
      li.append(element('span', 'admin-mono', `${site.siteUrl || site.did}`), ' ');
      const unblock = element('button', 'admin-button admin-button-sm', 'Unblock');
      unblock.type = 'button';
      unblock.setAttribute('aria-label', `Unblock ${site.siteUrl || site.did}`);
      unblock.addEventListener('click', async () => {
        if (!window.confirm(`Unblock ${site.siteUrl || site.did}?\n\nIts owner will be able to list it again.`)) return;
        unblock.disabled = true;
        try {
          await api(`/api/admin/blocked/${encodeURIComponent(site.did)}`, { method: 'DELETE' });
          setMessage(els.listStatus, `${site.siteUrl || site.did}: unblocked.`, 'success');
        } catch (err) {
          setMessage(els.listStatus, err.message || 'Unblocking failed.', 'error');
        }
        await loadStatus();
      });
      li.append(unblock);
      ul.append(li);
    }
    items.push(
      notice(
        'info',
        `${blocked.length} blocked site(s) with no listing`,
        element(
          'p',
          null,
          'These sites stay blocked after their listing was deleted (by you, or by the owner removing it), so they can\'t be submitted again. Unblock one to let its owner list it again.'
        ),
        ul
      )
    );
  }

  if (status.ipSaltInDatabase) {
    items.push(
      notice(
        'info',
        'Visitor IP hashes use a salt stored in the database.',
        element(
          'p',
          null,
          "Set IP_HASH_SALT (or 'ip_hash_salt' in config.php) to a random value of 32+ characters, for example from: openssl rand -hex 32. Then a leaked database can't be used to recover visitors' IP addresses."
        )
      )
    );
  }

  els.notices.replaceChildren(...items);
  els.notices.hidden = items.length === 0;
}

async function loadStatus() {
  try {
    const status = await api('/api/admin/status');
    if (state.signedIn) renderNotices(status);
  } catch {
    // Notices are advisory; the rest of the page still works without them.
  }
}

let searchTimer;
function applyFilters() {
  clearTimeout(searchTimer);
  state.status = STATUSES.includes(els.filterStatus.value) ? els.filterStatus.value : '';
  state.q = els.filterQ.value.trim();
  state.offset = 0;
  state.rowMessages.clear();
  loadEntries();
}

els.filterForm.addEventListener('submit', (event) => {
  event.preventDefault();
  applyFilters();
});
els.filterStatus.addEventListener('change', applyFilters);
els.filterQ.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(applyFilters, 300);
});
els.prev.addEventListener('click', () => {
  state.offset = Math.max(0, state.offset - PAGE_SIZE);
  loadEntries();
});
els.next.addEventListener('click', () => {
  state.offset += PAGE_SIZE;
  loadEntries();
});

// ---- add a site / re-check ----

els.addForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const siteUrl = els.addUrl.value.trim();
  if (!siteUrl) return;
  const button = els.addForm.querySelector('button[type="submit"]');
  button.disabled = true;
  setMessage(els.addMessage, 'Fetching and verifying the site…');
  try {
    const data = await api('/api/entries', { method: 'POST', body: { siteUrl }, admin: false });
    const name = (data.entry && (data.entry.displayName || data.entry.siteUrl)) || 'The site';
    setMessage(els.addMessage, `${name} is verified and listed.`, 'success');
    els.addUrl.value = '';
    await loadEntries();
  } catch (err) {
    setMessage(els.addMessage, err.message || 'Could not add that site.', 'error');
  } finally {
    button.disabled = false;
  }
});

els.recheckForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const limit = Math.min(Math.max(parseInt(els.recheckLimit.value, 10) || 10, 1), 25);
  const button = els.recheckForm.querySelector('button[type="submit"]');
  button.disabled = true;
  setMessage(els.recheckMessage, `Re-checking up to ${limit} site${limit === 1 ? '' : 's'}… this can take a while.`);
  try {
    const data = await api('/api/admin/refresh', { method: 'POST', body: { limit } });
    const checked = Number(data.checked) || 0;
    setMessage(
      els.recheckMessage,
      checked
        ? `Checked ${checked}: ${Number(data.updated) || 0} verified, ${Number(data.failed) || 0} failed.`
        : 'Nothing to check.',
      Number(data.failed) ? 'error' : 'success'
    );
    await loadEntries();
  } catch (err) {
    setMessage(els.recheckMessage, err.message || 'The re-check failed.', 'error');
  } finally {
    button.disabled = false;
  }
});

// ---- instance settings ----

const SETTING_INPUTS = {
  title: 'set-title',
  description: 'set-description',
  bannerUrl: 'set-banner',
  directoryUrl: 'set-hub-url', // the API keeps the original key name
  maintainerName: 'set-maintainer-name',
  maintainerUrl: 'set-maintainer-url',
  maintainerDid: 'set-maintainer-did'
};
const badgeInput = $('set-badge');

function settingsFromInfo(info) {
  const m = info && info.maintainer ? info.maintainer : null;
  return {
    title: String((info && info.title) || ''),
    description: String((info && info.description) || ''),
    bannerUrl: String((info && info.bannerUrl) || ''),
    directoryUrl: didWebToUrl(info && info.did),
    maintainerBadge: Boolean(info && info.maintainerBadge),
    maintainerName: m ? String(m.name || '') : '',
    maintainerUrl: m ? String(m.url || '') : '',
    maintainerDid: m ? String(m.did || '') : ''
  };
}

function fillSettings(values) {
  state.initialSettings = values;
  for (const [key, id] of Object.entries(SETTING_INPUTS)) $(id).value = values[key];
  badgeInput.checked = values.maintainerBadge;
}

async function loadSettings() {
  try {
    const info = await api('/api/info', { admin: false });
    fillSettings(settingsFromInfo(info));
  } catch (err) {
    setMessage(els.settingsMessage, `Could not load the current settings: ${err.message}`, 'error');
  }
}

els.settingsForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const initial = state.initialSettings || settingsFromInfo(null);
  const patch = {};
  for (const [key, id] of Object.entries(SETTING_INPUTS)) {
    const value = $(id).value.trim();
    if (value !== initial[key]) patch[key] = value;
  }
  if (badgeInput.checked !== initial.maintainerBadge) patch.maintainerBadge = badgeInput.checked;
  if (Object.keys(patch).length === 0) {
    setMessage(els.settingsMessage, 'Nothing changed.');
    return;
  }
  const button = els.settingsForm.querySelector('button[type="submit"]');
  button.disabled = true;
  setMessage(els.settingsMessage, 'Saving…');
  try {
    const data = await api('/api/admin/settings', { method: 'POST', body: patch });
    fillSettings(settingsFromInfo(data.settings));
    setMessage(els.settingsMessage, 'Saved. The public page uses the new settings now.', 'success');
    loadStatus();
  } catch (err) {
    setMessage(els.settingsMessage, err.message || 'Could not save the settings.', 'error');
  } finally {
    button.disabled = false;
  }
});

// ---- extensions ----

function renderExtensions(list) {
  els.extList.replaceChildren();
  if (!list.length) {
    els.extList.append(element('p', null, 'No extensions are enabled.'));
    return;
  }
  for (const ext of list) {
    const box = element('div', 'admin-ext');
    const head = element('div', 'admin-ext-head');
    head.append(element('strong', null, ext.name || ext.id));
    head.append(element('code', null, ext.id));
    head.append(element('span', `admin-badge ${ext.loaded ? 'admin-badge-active' : 'admin-badge-blocked'}`, ext.loaded ? 'loaded' : 'not loaded'));
    box.append(head);

    if (ext.loaded) {
      box.append(
        element('p', 'admin-sub', `Version ${ext.version || '?'} · API v${ext.apiVersion} · schema ${ext.schemaVersion}`)
      );
      const routes = Array.isArray(ext.routes) ? ext.routes : [];
      const events = Array.isArray(ext.events) ? ext.events : [];
      if (routes.length) {
        box.append(element('p', 'admin-ext-label', 'Routes'));
        const ul = element('ul', 'admin-ext-items');
        for (const route of routes) {
          const li = element('li');
          li.append(element('code', null, `${route}`.replace(/^(\w+) /, `$1 /api/ext/${ext.id}/`)));
          ul.append(li);
        }
        box.append(ul);
      }
      if (events.length) {
        box.append(element('p', 'admin-ext-label', 'Events'));
        const ul = element('ul', 'admin-ext-items');
        for (const ev of events) {
          const li = element('li');
          li.append(element('code', null, ev));
          ul.append(li);
        }
        box.append(ul);
      }
    }
    if (ext.error) box.append(element('p', 'admin-error', ext.error));
    els.extList.append(box);
  }
}

// ---- start ----

const saved = currentToken();
if (saved) {
  signIn(saved);
} else {
  els.token.focus();
}
