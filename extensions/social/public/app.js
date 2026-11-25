/**
 * Conversations & Reactions page (/ext/social/).
 *
 * Talks only to this hub's own `/api/ext/social/v1/*` routes. Sign-in, opt-in and the CSRF token
 * come from the hub's shared `window.webringHubAuth` (public/hub-auth.js). Every piece of member
 * text is rendered with textContent; links are built with DOM APIs and only for http(s) URLs.
 */

const API = new URL('../../api/ext/social/v1/', import.meta.url).pathname;
const EXT_ID = 'social';
const REACTION_LABELS = { like: '👍', heart: '❤️', celebrate: '🎉', think: '🤔' };
const TYPE_LABELS = { post: 'Post', comment: 'Comment', like: 'Like', share: 'Share', recommendation: 'Recommendation' };

const $ = (id) => document.getElementById(id);
const auth = window.webringHubAuth;

const state = {
  config: { reactions: Object.keys(REACTION_LABELS), reportReasons: ['spam', 'abuse', 'off-topic', 'other'], membersOnly: false },
  me: { authenticated: false, user: null, consents: [] },
  feedType: '',
  feedCursor: null,
  activityBefore: null,
  lastFeedHash: '#feed',
  reportTarget: null,
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

function optedIn() {
  return Boolean(state.me.consents.find((c) => c.extensionId === EXT_ID && c.optedIn));
}

function myDid() {
  return state.me.user ? state.me.user.did : null;
}

async function api(path, { method = 'GET', body, query } = {}) {
  const url = new URL(API + path, location.origin);
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v));
    }
  }
  const headers = { accept: 'application/json' };
  if (method !== 'GET') {
    headers['content-type'] = 'application/json';
    headers['x-hub-csrf'] = auth ? auth.csrfToken() : '';
  }
  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers,
    body: method === 'GET' ? undefined : JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(data.message || `Request failed (${res.status}).`);
    err.status = res.status;
    err.code = data.error || '';
    throw err;
  }
  return data;
}

function relTime(iso) {
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const s = Math.round((Date.now() - t) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  if (s < 86400 * 30) return `${Math.floor(s / 86400)}d ago`;
  return new Date(t).toLocaleDateString();
}

function safeHttpUrl(raw) {
  try {
    const u = new URL(raw);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
  } catch {
    return null;
  }
}

/** Plain text with http(s) URLs turned into links, all built with DOM APIs. */
function richText(text) {
  const frag = document.createDocumentFragment();
  const parts = String(text || '').split(/(https?:\/\/[^\s<>"']+)/g);
  for (const part of parts) {
    const href = /^https?:\/\//.test(part) ? safeHttpUrl(part.replace(/[.,;:!?)]+$/, '')) : null;
    if (href) {
      const a = el('a', null, part);
      a.href = href;
      a.rel = 'nofollow ugc noopener noreferrer';
      a.target = '_blank';
      frag.append(a);
    } else if (part) {
      frag.append(document.createTextNode(part));
    }
  }
  return frag;
}

function authorName(author) {
  return author.displayName || author.handle || author.did;
}

function avatar(author) {
  if (author.avatarUrl) {
    const img = el('img', 'avatar social-avatar');
    img.src = author.avatarUrl;
    img.alt = '';
    img.loading = 'lazy';
    img.referrerPolicy = 'no-referrer';
    return img;
  }
  const initials = authorName(author).replace(/^did:web:/, '').slice(0, 2).toUpperCase();
  return el('span', 'avatar avatar-fallback social-avatar', initials);
}

function threadHash(did, itemId) {
  return `#thread?${new URLSearchParams({ did, id: itemId }).toString()}`;
}

function showNotice(text, kind = '') {
  const n = $('social-notice');
  n.hidden = !text;
  n.textContent = text || '';
  n.className = `social-notice${kind ? ` ${kind}` : ''}`;
}

// ---------------------------------------------------------------------------
// Sign-in & opt-in
// ---------------------------------------------------------------------------

async function ensureCanAct() {
  if (!state.me.authenticated) {
    if (auth) auth.openSignIn(EXT_ID);
    return false;
  }
  if (optedIn()) return true;
  const ok = window.confirm(
    'Reactions and reports are stored on this hub under your DID. You can opt out at any time from My Data, which deletes them. Opt in to Conversations?'
  );
  if (!ok) return false;
  try {
    const s = await auth.setConsent(EXT_ID, true);
    state.me = s;
    return optedIn();
  } catch (err) {
    showNotice(err.message, 'error');
    return false;
  }
}

// ---------------------------------------------------------------------------
// Item cards
// ---------------------------------------------------------------------------

function reactionBar(item) {
  const bar = el('div', 'social-reactions');
  const mine = new Set(item.myReactions || []);
  for (const key of state.config.reactions) {
    const count = (item.reactions && item.reactions[key]) || 0;
    const btn = el('button', 'hub-btn social-react');
    btn.type = 'button';
    btn.setAttribute('aria-pressed', mine.has(key) ? 'true' : 'false');
    btn.setAttribute('aria-label', `${key}${count ? ` (${count})` : ''}`);
    btn.title = key;
    btn.append(el('span', 'social-react-emoji', REACTION_LABELS[key] || key));
    if (count) btn.append(el('span', 'social-react-count', count));
    btn.addEventListener('click', async () => {
      if (!(await ensureCanAct())) return;
      btn.disabled = true;
      try {
        const res = await api('reactions', {
          method: 'POST',
          body: { did: item.did, itemId: item.itemId, reaction: key, on: !mine.has(key) },
        });
        item.reactions = res.reactions;
        item.myReactions = res.myReactions;
        bar.replaceWith(reactionBar(item));
      } catch (err) {
        showNotice(err.message, 'error');
        btn.disabled = false;
      }
    });
    bar.append(btn);
  }
  return bar;
}

function itemCard(item, { inThread = false, focus = false } = {}) {
  if (item.hidden) {
    const card = el('article', 'entry social-card social-hidden');
    card.append(el('p', 'hint', "This item was hidden by the hub's moderators."));
    return card;
  }
  const card = el('article', `entry social-card${focus ? ' social-focus' : ''}`);

  const head = el('div', 'entry-head social-head');
  head.append(avatar(item.author));
  const who = el('div', 'social-who');
  const nameLink = el('a', 'social-name', authorName(item.author));
  const site = safeHttpUrl(item.author.siteUrl);
  if (site) {
    nameLink.href = site;
    nameLink.rel = 'noopener noreferrer';
    nameLink.target = '_blank';
  }
  who.append(nameLink);
  const meta = el('span', 'social-meta');
  if (item.author.handle) meta.append(el('span', 'social-handle', `@${item.author.handle.replace(/^@/, '')}`));
  const time = el('time', 'social-time', relTime(item.publishedAt));
  time.dateTime = item.publishedAt;
  time.title = new Date(item.publishedAt).toLocaleString();
  meta.append(time);
  who.append(meta);
  head.append(who);
  if (item.type !== 'post') head.append(el('span', 'chip social-type', TYPE_LABELS[item.type] || item.type));
  card.append(head);

  if (item.title) card.append(el('h3', 'social-title', item.title));
  if (item.commentary) {
    const p = el('p', 'social-text');
    p.append(richText(item.commentary));
    card.append(p);
  }
  if (item.mediaUrl) {
    const img = el('img', 'social-media');
    img.src = item.mediaUrl;
    img.alt = '';
    img.loading = 'lazy';
    img.referrerPolicy = 'no-referrer';
    card.append(img);
  }
  const target = safeHttpUrl(item.targetUrl);
  if (target && !inThread && item.type !== 'comment') {
    const t = el('p', 'hint social-target');
    t.append(document.createTextNode('↪ '));
    const a = el('a', null, item.targetAuthorName || target);
    a.href = target;
    a.rel = 'nofollow ugc noopener noreferrer';
    a.target = '_blank';
    t.append(a);
    if (item.rating) t.append(document.createTextNode(` · ${'★'.repeat(item.rating)}`));
    card.append(t);
  }
  if (item.tags && item.tags.length) {
    const chips = el('ul', 'chips');
    for (const tag of item.tags) chips.append(el('li', 'chip', `#${tag}`));
    card.append(chips);
  }

  const foot = el('div', 'social-foot');
  foot.append(reactionBar(item));
  const actions = el('div', 'social-actions');
  if (!inThread) {
    const replies = el('a', 'hub-btn social-replies', `💬 ${item.replyCount || 0} ${item.replyCount === 1 ? 'reply' : 'replies'}`);
    replies.href = threadHash(item.did, item.itemId);
    actions.append(replies);
  }
  const copy = el('button', 'hub-btn', 'Copy link');
  copy.type = 'button';
  copy.title = 'Copy the link to reply to this from Webring Studio';
  copy.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(item.canonicalUrl);
      copy.textContent = 'Copied';
      setTimeout(() => (copy.textContent = 'Copy link'), 1500);
    } catch {
      window.prompt('Copy this link:', item.canonicalUrl);
    }
  });
  actions.append(copy);
  if (state.me.authenticated && item.did !== myDid()) {
    const report = el('button', 'hub-btn social-report', 'Report');
    report.type = 'button';
    report.addEventListener('click', () => openReport(item));
    actions.append(report);
  }
  foot.append(actions);
  card.append(foot);
  return card;
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

function setView(name) {
  for (const v of ['feed', 'thread', 'activity']) {
    $(`view-${v}`).hidden = v !== name;
  }
  for (const tab of document.querySelectorAll('.social-tab')) {
    const active = tab.dataset.tab === name || (name === 'thread' && tab.dataset.tab === 'feed');
    tab.classList.toggle('is-active', active);
    if (active) tab.setAttribute('aria-current', 'page');
    else tab.removeAttribute('aria-current');
  }
}

async function loadFeed(reset) {
  const list = $('feed-list');
  const status = $('feed-status');
  if (reset) {
    list.replaceChildren();
    state.feedCursor = null;
  }
  status.textContent = 'Loading…';
  $('feed-more').hidden = true;
  try {
    const data = await api('feed', { query: { type: state.feedType, before: state.feedCursor, limit: 20 } });
    for (const item of data.items) list.append(itemCard(item));
    state.feedCursor = data.nextCursor;
    $('feed-more').hidden = !data.nextCursor;
    status.textContent = list.childElementCount === 0
      ? 'Nothing here yet. Members publish posts from Webring Studio; they appear after their site syncs with this hub.'
      : '';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

function renderTree(node, focus, depth = 0) {
  const wrap = el('div', 'social-node');
  const isFocus = focus && node.did === focus.did && node.itemId === focus.itemId;
  wrap.append(itemCard(node, { inThread: true, focus: isFocus }));
  if (node.children && node.children.length) {
    const kids = el('div', depth < 5 ? 'social-children' : 'social-children social-children-flat');
    for (const child of node.children) kids.append(renderTree(child, focus, depth + 1));
    wrap.append(kids);
  }
  return wrap;
}

async function loadThread(did, id) {
  const root = $('thread-root');
  const status = $('thread-status');
  root.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('thread', { query: { did, id } });
    root.append(renderTree(data.root, data.focus));
    status.textContent = data.truncated ? 'This conversation is long; only the first part is shown.' : '';
    const focusEl = root.querySelector('.social-focus');
    if (focusEl && (data.focus.did !== data.root.did || data.focus.itemId !== data.root.itemId)) {
      focusEl.scrollIntoView({ block: 'center' });
    }
  } catch (err) {
    status.textContent = err.status === 404 ? 'That conversation is no longer on this hub.' : err.message;
  }
}

function activityLine(ev) {
  const card = el('article', 'entry social-card social-activity');
  const line = el('p', 'social-activity-line');
  if (ev.kind === 'reactions') {
    line.append(document.createTextNode('Your post '));
    line.append(el('q', null, ev.target.excerpt));
    line.append(document.createTextNode(' got '));
    for (const [k, n] of Object.entries(ev.reactions)) line.append(el('span', 'social-react-chip', `${REACTION_LABELS[k] || k} ${n}`));
  } else {
    const who = el('strong', null, authorName(ev.item.author));
    line.append(who);
    if (ev.kind === 'reply') {
      const verb = ev.item.type === 'comment' ? ' replied to ' : ` (${TYPE_LABELS[ev.item.type] || ev.item.type}) responded to `;
      line.append(document.createTextNode(verb));
      line.append(el('q', null, ev.target.excerpt));
    } else {
      line.append(document.createTextNode(' mentioned you'));
    }
  }
  const time = el('time', 'social-time', relTime(ev.at));
  time.dateTime = ev.at;
  line.append(time);
  card.append(line);
  if (ev.item && ev.item.commentary) {
    const p = el('p', 'social-text social-excerpt');
    p.append(richText(ev.item.commentary.length > 280 ? `${ev.item.commentary.slice(0, 279)}…` : ev.item.commentary));
    card.append(p);
  }
  const open = el('a', 'hub-btn', 'Open conversation');
  const ref = ev.item || ev.target;
  open.href = threadHash(ref.did, ref.itemId);
  card.append(open);
  return card;
}

async function loadActivity(reset) {
  const gate = $('activity-gate');
  const list = $('activity-list');
  const status = $('activity-status');
  if (!state.me.authenticated) {
    gate.hidden = false;
    gate.replaceChildren(
      el('p', null, 'Sign in with Webring Studio to see replies to your posts, mentions of you and reactions.')
    );
    const btn = el('button', 'hub-btn', 'Sign in with Studio');
    btn.type = 'button';
    btn.addEventListener('click', () => auth && auth.openSignIn(EXT_ID));
    gate.append(btn);
    list.replaceChildren();
    status.textContent = '';
    $('activity-more').hidden = true;
    return;
  }
  gate.hidden = true;
  if (reset) {
    list.replaceChildren();
    state.activityBefore = null;
  }
  status.textContent = 'Loading…';
  try {
    const data = await api('me/activity', { query: { before: state.activityBefore, limit: 30 } });
    for (const ev of data.events) list.append(activityLine(ev));
    state.activityBefore = data.nextBefore;
    $('activity-more').hidden = !data.nextBefore;
    status.textContent = list.childElementCount === 0 ? 'No activity yet. Replies, mentions and reactions show up here.' : '';
    if (reset && optedIn() && data.unread > 0) {
      await api('me/activity/seen', { method: 'POST' }).catch(() => {});
    }
    setBadge(0);
  } catch (err) {
    status.textContent = err.message;
  }
}

function setBadge(n) {
  const b = $('activity-badge');
  b.hidden = !n;
  b.textContent = n > 99 ? '99+' : String(n || '');
}

async function refreshBadge() {
  if (!state.me.authenticated || !optedIn()) {
    setBadge(0);
    return;
  }
  try {
    const data = await api('me/activity', { query: { limit: 50 } });
    setBadge(data.unread);
  } catch {
    setBadge(0);
  }
}

// ---------------------------------------------------------------------------
// Report dialog
// ---------------------------------------------------------------------------

function buildReasons() {
  const fs = $('report-reasons');
  for (const node of [...fs.querySelectorAll('label')]) node.remove();
  state.config.reportReasons.forEach((reason, i) => {
    const label = el('label', 'social-reason');
    const input = el('input');
    input.type = 'radio';
    input.name = 'reason';
    input.value = reason;
    input.required = true;
    if (i === 0) input.checked = true;
    label.append(input, document.createTextNode(` ${reason.replace('-', ' ')}`));
    fs.append(label);
  });
}

async function openReport(item) {
  if (!(await ensureCanAct())) return;
  state.reportTarget = item;
  $('report-note').value = '';
  $('report-status').textContent = '';
  $('report-submit').disabled = false;
  $('report-dialog').showModal();
}

$('report-form').addEventListener('submit', async (e) => {
  if (e.submitter && e.submitter.value === 'cancel') return;
  e.preventDefault();
  const item = state.reportTarget;
  if (!item) return;
  const reason = new FormData($('report-form')).get('reason');
  $('report-submit').disabled = true;
  try {
    const res = await api('reports', {
      method: 'POST',
      body: { did: item.did, itemId: item.itemId, reason, note: $('report-note').value },
    });
    $('report-dialog').close();
    showNotice(res.message, 'success');
    if (res.autoHidden) route();
  } catch (err) {
    const s = $('report-status');
    s.textContent = err.message;
    s.className = 'form-message error';
    $('report-submit').disabled = false;
  }
});

// ---------------------------------------------------------------------------
// Routing & wiring
// ---------------------------------------------------------------------------

function route() {
  const hash = location.hash || '#feed';
  if (hash.startsWith('#thread?')) {
    const params = new URLSearchParams(hash.slice('#thread?'.length));
    setView('thread');
    loadThread(params.get('did') || '', params.get('id') || '');
  } else if (hash === '#activity') {
    setView('activity');
    loadActivity(true);
  } else {
    state.lastFeedHash = '#feed';
    setView('feed');
    loadFeed(true);
  }
}

$('thread-back').addEventListener('click', () => {
  if (history.length > 1) history.back();
  else location.hash = state.lastFeedHash;
});
$('feed-more').addEventListener('click', () => loadFeed(false));
$('activity-more').addEventListener('click', () => loadActivity(false));
for (const btn of document.querySelectorAll('.social-filter [data-type]')) {
  btn.addEventListener('click', () => {
    state.feedType = btn.dataset.type;
    for (const b of document.querySelectorAll('.social-filter [data-type]')) {
      b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
    }
    if (location.hash && location.hash !== '#feed') location.hash = '#feed';
    else loadFeed(true);
  });
}
window.addEventListener('hashchange', route);

// Re-render when someone signs in or out. An opt-in change only refreshes the badge: re-rendering
// then would race the reaction or report that asked for the opt-in.
let lastDid;
document.addEventListener('webring-hub-auth', (e) => {
  state.me = e.detail;
  if (lastDid !== undefined && myDid() !== lastDid) route();
  if (lastDid !== undefined) refreshBadge();
  lastDid = myDid();
});

async function init() {
  if (auth) {
    state.me = await auth.ready;
    lastDid = myDid();
  } else {
    showNotice('Sign-in is unavailable on this page.', 'error');
  }
  try {
    const cfg = await api('config');
    state.config = { ...state.config, ...cfg };
  } catch {
    // Defaults are fine.
  }
  buildReasons();
  route();
  refreshBadge();
}

init();
