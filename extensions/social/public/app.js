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
const GREETING_LABELS = { wave: '👋 Wave', welcome: '🎉 Welcome', coffee: '☕ Say hi' };
const BADGE_META = {
  host: { icon: '📅', label: 'Host (3+ events)' },
  helper: { icon: '🎁', label: 'Helper (3+ closed offers)' },
  voice: { icon: '💬', label: 'Voice (25+ replies)' },
  welcomer: { icon: '👋', label: 'Welcomer (10+ newcomers greeted)' },
  voucher: { icon: '🤝', label: 'Voucher (5+ vouches given)' },
  founding: { icon: '🌱', label: 'Founding member' },
  participant: { icon: '🏅', label: 'Challenge participant (3+ challenges)' },
  champion: { icon: '🏆', label: 'Challenge champion' },
};

const $ = (id) => document.getElementById(id);
const auth = window.webringHubAuth;

const state = {
  config: { reactions: Object.keys(REACTION_LABELS), reportReasons: ['spam', 'abuse', 'off-topic', 'other'], membersOnly: false },
  me: { authenticated: false, user: null, consents: [] },
  feedType: '',
  feedTag: '',
  feedDate: '',
  feedTimeFrom: '',
  feedTimeTo: '',
  feedQuery: '',
  feedCursor: null,
  activityBefore: null,
  lastFeedHash: '#feed',
  reportTarget: null,
  eventsWhen: 'upcoming',
  boardKind: '',
  boardStatus: 'open',
  pollStatus: 'open',
  guestbookOwnerDid: null,
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
  const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
  if (s < 60) return 'just now';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  if (d < 7) return `${d}d ago`;
  const w = Math.floor(d / 7);
  if (d < 30) return `${w}w ago`;
  const mo = Math.max(1, Math.floor(d / 30));
  if (d < 365) return `${mo}mo ago`;
  const y = Math.max(1, Math.floor(d / 365));
  return `${y}y ago`;
}

function safeHttpUrl(raw) {
  try {
    const u = new URL(raw);
    return u.protocol === 'https:' || u.protocol === 'http:' ? u.href : null;
  } catch {
    return null;
  }
}

/** Renders inline Markdown (`code`, **bold**, *italic*, [label](url), bare http(s) URLs) with DOM APIs only. */
function richInline(text) {
  const frag = document.createDocumentFragment();
  const re = /(`[^`\n]+`|\*\*[^*\n]+\*\*|\*[^*\s\n][^*\n]*\*|\[[^\]]+\]\(https?:\/\/[^\s)]+\)|https?:\/\/[^\s<>"']+)/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) frag.append(document.createTextNode(text.slice(last, m.index)));
    const tok = m[0];
    if (tok.startsWith('`') && tok.endsWith('`')) {
      frag.append(el('code', 'md-inline-code', tok.slice(1, -1)));
    } else if (tok.startsWith('**') && tok.endsWith('**')) {
      frag.append(el('strong', null, tok.slice(2, -2)));
    } else if (tok.startsWith('*') && tok.endsWith('*')) {
      frag.append(el('em', null, tok.slice(1, -1)));
    } else if (tok.startsWith('[')) {
      const lm = /^\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)$/.exec(tok);
      const href = lm ? safeHttpUrl(lm[2]) : null;
      if (lm && href) {
        const a = el('a', null, lm[1]);
        a.href = href;
        a.rel = 'nofollow ugc noopener noreferrer';
        a.target = '_blank';
        frag.append(a);
      } else {
        frag.append(document.createTextNode(tok));
      }
    } else {
      const cleaned = tok.replace(/[.,;:!?)]+$/, '');
      const href = safeHttpUrl(cleaned);
      if (href) {
        const a = el('a', null, cleaned);
        a.href = href;
        a.rel = 'nofollow ugc noopener noreferrer';
        a.target = '_blank';
        frag.append(a);
        if (cleaned.length < tok.length) frag.append(document.createTextNode(tok.slice(cleaned.length)));
      } else {
        frag.append(document.createTextNode(tok));
      }
    }
    last = m.index + tok.length;
  }
  if (last < text.length) frag.append(document.createTextNode(text.slice(last)));
  return frag;
}

/** Safe DOM Markdown renderer supporting fenced code blocks (```lang), paragraphs, and inline formatting. */
function richText(text) {
  const frag = document.createDocumentFragment();
  const src = String(text || '').replace(/\r\n?/g, '\n');
  const fenceRe = /^```([A-Za-z0-9_+.-]{0,24})\s*\n([\s\S]*?)^```\s*$/gm;
  let last = 0;
  let m;
  const appendProse = (chunk) => {
    const trimmed = chunk.replace(/^\n+|\n+$/g, '');
    if (!trimmed) return;
    for (const para of trimmed.split(/\n{2,}/)) {
      const p = el('p', 'md-para');
      const lines = para.split('\n');
      lines.forEach((ln, idx) => {
        if (idx > 0) p.append(document.createElement('br'));
        p.append(richInline(ln));
      });
      frag.append(p);
    }
  };
  while ((m = fenceRe.exec(src)) !== null) {
    if (m.index > last) appendProse(src.slice(last, m.index));
    const lang = m[1] || 'code';
    const codeText = m[2].replace(/\n$/, '');
    const wrap = el('div', 'md-code-block');
    const head = el('div', 'md-code-head');
    head.append(el('span', 'md-code-lang', lang));
    const copyBtn = el('button', 'hub-btn md-code-copy', 'Copy');
    copyBtn.type = 'button';
    copyBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(codeText);
        copyBtn.textContent = 'Copied';
        setTimeout(() => (copyBtn.textContent = 'Copy'), 1500);
      } catch {
        // Clipboard unavailable
      }
    });
    head.append(copyBtn);
    const pre = document.createElement('pre');
    pre.append(el('code', null, codeText));
    wrap.append(head, pre);
    frag.append(wrap);
    last = m.index + m[0].length;
  }
  if (last < src.length) appendProse(src.slice(last));
  return frag;
}

function authorName(author) {
  return author.displayName || author.handle || author.did;
}

function authorBadgesEl(author) {
  const roles = Array.isArray(author.roles) ? author.roles : [];
  const badges = Array.isArray(author.badges) ? author.badges : [];
  if (!roles.length && !badges.length) return null;
  const wrap = el('span', 'social-author-badges');
  for (const r of roles) {
    wrap.append(el('span', 'chip social-role-chip', r));
  }
  for (const b of badges) {
    const m = BADGE_META[b];
    if (!m) continue;
    const sp = el('span', 'social-badge-icon', m.icon);
    sp.title = m.label;
    sp.setAttribute('aria-label', m.label);
    wrap.append(sp);
  }
  return wrap;
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
  const nameRow = el('div', 'social-name-row');
  const nameLink = el('a', 'social-name', authorName(item.author));
  const site = safeHttpUrl(item.author.siteUrl);
  if (site) {
    nameLink.href = site;
    nameLink.rel = 'noopener noreferrer';
    nameLink.target = '_blank';
  }
  nameRow.append(nameLink);
  const ab = authorBadgesEl(item.author);
  if (ab) nameRow.append(ab);
  who.append(nameRow);
  const meta = el('span', 'social-meta');
  if (item.author.handle) meta.append(el('span', 'social-handle', `@${item.author.handle.replace(/^@/, '')}`));
  const time = el('time', 'social-time', relTime(item.publishedAt));
  time.dateTime = item.publishedAt;
  time.title = new Date(item.publishedAt).toLocaleString();
  meta.append(time);
  who.append(meta);
  head.append(who);
  if (item.listing) {
    head.append(el('span', `chip social-kind social-kind-${item.listing.kind}`,
      `${item.listing.kind === 'ask' ? 'Ask' : 'Offer'}${item.listing.status === 'closed' ? ' · closed' : ''}`));
  } else if (item.event) {
    head.append(el('span', 'chip social-type', 'Event'));
  } else if (item.vouch) {
    head.append(el('span', 'chip social-type', 'Vouch'));
  } else if (item.type !== 'post') {
    head.append(el('span', 'chip social-type', TYPE_LABELS[item.type] || item.type));
  }
  card.append(head);

  if (item.title) card.append(el('h3', 'social-title', item.title));
  if (item.vouch) {
    const vb = el('div', 'social-vouch-box');
    const detail = item.vouch.kind === 'skill'
      ? `skill: ${item.vouch.skill}`
      : item.vouch.kind === 'worked-with'
      ? 'worked with'
      : 'met';
    vb.append(document.createTextNode('🤝 Vouches for '));
    const sUrl = safeHttpUrl(item.vouch.subjectSiteUrl);
    if (sUrl) {
      const a = el('a', null, item.vouch.subjectSiteUrl.replace(/^https?:\/\//i, ''));
      a.href = sUrl;
      a.rel = 'noopener noreferrer';
      a.target = '_blank';
      vb.append(a);
    } else {
      vb.append(el('strong', null, item.vouch.subjectDid));
    }
    vb.append(document.createTextNode(`: ${detail}`));
    card.append(vb);
  }
  if (item.commentary) {
    const body = el('div', 'social-text');
    body.append(richText(item.commentary));
    card.append(body);
  }
  if (item.event) card.append(eventDetails(item.event));
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
  for (const v of ['feed', 'people', 'library', 'now', 'challenges', 'thread', 'events', 'board', 'polls', 'digest', 'activity']) {
    const node = $(`view-${v}`);
    if (node) node.hidden = v !== name;
  }
  for (const tab of document.querySelectorAll('.social-tab')) {
    const active = tab.dataset.tab === name || (name === 'thread' && tab.dataset.tab === 'feed');
    tab.classList.toggle('is-active', active);
    if (active) tab.setAttribute('aria-current', 'page');
    else tab.removeAttribute('aria-current');
  }
}

function localDateStr(d = new Date()) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function shiftLocalDate(dateStr, deltaDays) {
  const [y, m, day] = dateStr.split('-').map(Number);
  const d = new Date(y, (m || 1) - 1, (day || 1) + deltaDays);
  return localDateStr(d);
}

/** Converts the user's selected local day + optional HH:MM time bounds into UTC ISO timestamps. */
function dailyBoundsUtc() {
  const dateStr = state.feedDate || localDateStr();
  const [y, m, day] = dateStr.split('-').map(Number);
  const [fh, fm] = (state.feedTimeFrom || '00:00').split(':').map(Number);
  const [th, tm] = (state.feedTimeTo || '23:59').split(':').map(Number);
  const start = new Date(y, (m || 1) - 1, day || 1, fh || 0, fm || 0, 0, 0);
  const end = new Date(y, (m || 1) - 1, day || 1, Number.isFinite(th) ? th : 23, Number.isFinite(tm) ? tm : 59, 59, 999);
  return {
    from: Number.isNaN(start.getTime()) ? '' : start.toISOString(),
    to: Number.isNaN(end.getTime()) ? '' : end.toISOString(),
  };
}

function syncDailyBarUi() {
  const today = localDateStr();
  if (!state.feedDate) state.feedDate = today;
  if ($('feed-date')) $('feed-date').value = state.feedDate;
  if ($('feed-time-from')) $('feed-time-from').value = state.feedTimeFrom;
  if ($('feed-time-to')) $('feed-time-to').value = state.feedTimeTo;
  if ($('feed-search')) $('feed-search').value = state.feedQuery;
  if ($('feed-day-today')) $('feed-day-today').disabled = state.feedDate === today;
  if ($('feed-filter-reset')) {
    $('feed-filter-reset').hidden = state.feedDate === today && !state.feedTimeFrom && !state.feedTimeTo && !state.feedQuery;
  }
}

async function loadFeed(reset) {
  const list = $('feed-list');
  const status = $('feed-status');
  syncDailyBarUi();
  if (reset) {
    list.replaceChildren();
    state.feedCursor = null;
  }
  status.textContent = 'Loading…';
  $('feed-more').hidden = true;
  const { from, to } = dailyBoundsUtc();
  try {
    const data = await api('feed', {
      query: { type: state.feedType, tag: state.feedTag, from, to, q: state.feedQuery, before: state.feedCursor, limit: 20 },
    });
    for (const item of data.items) list.append(itemCard(item));
    state.feedCursor = data.nextCursor;
    $('feed-more').hidden = !data.nextCursor;
    const isToday = state.feedDate === localDateStr();
    status.textContent = list.childElementCount === 0
      ? `No posts for ${isToday ? 'today' : state.feedDate}${state.feedTimeFrom || state.feedTimeTo ? ' in this time window' : ''}${state.feedQuery ? ` matching "${state.feedQuery}"` : ''}. Pick another day or adjust the filter above.`
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
    const res = item.kind === 'guestbook'
      ? await api(`guestbook/${item.id}/report`, {
          method: 'POST',
          body: { reason, note: $('report-note').value },
        })
      : await api('reports', {
          method: 'POST',
          body: { did: item.did, itemId: item.itemId, reason, note: $('report-note').value },
        });
    $('report-dialog').close();
    showNotice(res.message || 'Report submitted.', 'success');
    if (res.autoHidden) {
      if (item.kind === 'guestbook' && state.guestbookOwnerDid) loadGuestbookEntries(state.guestbookOwnerDid);
      else route();
    }
  } catch (err) {
    const s = $('report-status');
    s.textContent = err.message;
    s.className = 'form-message error';
    $('report-submit').disabled = false;
  }
});

// ---------------------------------------------------------------------------
// Events, asks & offers, polls and the digest (Tier 2)
// ---------------------------------------------------------------------------

const RSVP_LABELS = { going: 'Going', maybe: 'Maybe', declined: "Can't go" };

function fmtEventWhen(ev) {
  const start = new Date(ev.startsAt);
  const end = ev.endsAt ? new Date(ev.endsAt) : null;
  if (ev.allDay) {
    const opts = { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' };
    const s = start.toLocaleDateString(undefined, opts);
    return end && end.toISOString().slice(0, 10) !== start.toISOString().slice(0, 10)
      ? `${s} – ${end.toLocaleDateString(undefined, opts)} (all day)`
      : `${s} (all day)`;
  }
  const s = start.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  if (!end) return s;
  const sameDay = end.toDateString() === start.toDateString();
  return `${s} – ${sameDay ? end.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : end.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`;
}

function eventDetails(ev) {
  const box = el('div', 'social-event');
  const when = el('p', 'social-event-line');
  when.append(el('span', 'social-event-icon', '📅'), document.createTextNode(fmtEventWhen(ev)));
  box.append(when);
  if (ev.location) {
    const where = el('p', 'social-event-line');
    where.append(el('span', 'social-event-icon', '📍'), document.createTextNode(ev.location));
    box.append(where);
  }
  const link = safeHttpUrl(ev.url);
  if (link) {
    const p = el('p', 'social-event-line');
    const a = el('a', null, 'Event link');
    a.href = link;
    a.rel = 'nofollow ugc noopener noreferrer';
    a.target = '_blank';
    p.append(el('span', 'social-event-icon', '🔗'), a);
    box.append(p);
  }
  return box;
}

function dateBadge(ev) {
  const d = new Date(ev.startsAt);
  const opts = ev.allDay ? { timeZone: 'UTC' } : {};
  const badge = el('div', 'social-datebadge');
  badge.append(
    el('span', 'social-datebadge-month', d.toLocaleDateString(undefined, { month: 'short', ...opts })),
    el('span', 'social-datebadge-day', d.toLocaleDateString(undefined, { day: 'numeric', ...opts }))
  );
  return badge;
}

function rsvpBar(item, past) {
  const wrap = el('div', 'social-rsvp');
  const counts = el('p', 'social-rsvp-counts');
  const going = (item.rsvps && item.rsvps.going) || 0;
  const maybe = (item.rsvps && item.rsvps.maybe) || 0;
  counts.textContent = `${going} going${maybe ? ` · ${maybe} maybe` : ''}`;
  if (item.attendees && item.attendees.length) {
    counts.append(document.createTextNode(': '), el('span', 'social-attendees', item.attendees.map((a) => a.displayName || a.did).join(', ')));
  }
  wrap.append(counts);
  if (past) return wrap;
  const buttons = el('div', 'social-reactions');
  for (const status of ['going', 'maybe', 'declined']) {
    const btn = el('button', 'hub-btn social-react', RSVP_LABELS[status]);
    btn.type = 'button';
    btn.setAttribute('aria-pressed', item.myRsvp === status ? 'true' : 'false');
    btn.addEventListener('click', async () => {
      if (!(await ensureCanAct())) return;
      btn.disabled = true;
      try {
        const res = await api('rsvps', {
          method: 'POST',
          body: { did: item.did, itemId: item.itemId, status: item.myRsvp === status ? null : status },
        });
        Object.assign(item, { rsvps: res.rsvps, myRsvp: res.myRsvp, attendees: res.attendees });
        wrap.replaceWith(rsvpBar(item, past));
      } catch (err) {
        showNotice(err.message, 'error');
        btn.disabled = false;
      }
    });
    buttons.append(btn);
  }
  wrap.append(buttons);
  return wrap;
}

function eventCard(item, past) {
  const row = el('div', 'social-event-row');
  row.append(dateBadge(item.event));
  const card = itemCard(item);
  card.querySelector('.social-foot')?.before(rsvpBar(item, past));
  row.append(card);
  return row;
}

async function loadEvents() {
  const list = $('events-list');
  const status = $('events-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  $('events-ics').hidden = state.config.membersOnly;
  try {
    const data = await api('events', { query: { when: state.eventsWhen, limit: 50 } });
    for (const item of data.events) list.append(eventCard(item, state.eventsWhen === 'past'));
    status.textContent = data.events.length ? '' : state.eventsWhen === 'past' ? 'No past events yet.' : 'No upcoming events. Add one from Webring Studio.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

function listingCard(item) {
  const card = itemCard(item);
  const m = item.matches || { count: 0 };
  if (m.count || m.matchesMe) {
    const p = el('p', `social-match${m.matchesMe ? ' is-me' : ''}`);
    if (m.matchesMe) p.append(el('strong', null, 'Matches your interests. '));
    const names = m.members && m.members.length ? `: ${m.members.map((x) => x.displayName || x.did).join(', ')}` : '';
    p.append(document.createTextNode(`${m.count} member${m.count === 1 ? '' : 's'} list${m.count === 1 ? 's' : ''} these interests${names}`));
    card.querySelector('.social-foot')?.before(p);
  }
  return card;
}

async function loadBoard() {
  const list = $('board-list');
  const status = $('board-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('listings', { query: { kind: state.boardKind, status: state.boardStatus, limit: 50 } });
    for (const item of data.listings) list.append(listingCard(item));
    status.textContent = data.listings.length ? '' : 'Nothing here yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

function pollCard(poll) {
  const card = el('article', 'entry social-card social-poll');
  const head = el('div', 'social-poll-head');
  head.append(el('h3', 'social-title', poll.question));
  const meta = el('p', 'hint social-poll-meta');
  const bits = [`by ${poll.createdBy.displayName}`];
  if (poll.closed) bits.push('closed');
  else if (poll.closesAt) bits.push(`closes ${new Date(poll.closesAt).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}`);
  if (poll.multi) bits.push('pick any');
  if (poll.voters !== null) bits.push(`${poll.voters} voter${poll.voters === 1 ? '' : 's'}`);
  meta.textContent = bits.join(' · ');
  head.append(meta);
  card.append(head);
  if (poll.description) card.append(el('p', 'social-text', poll.description));

  const form = el('form', 'social-poll-form');
  const total = Math.max(1, ...poll.options.map((o) => o.votes || 0));
  const voterCount = Math.max(1, poll.voters || 0);
  for (const opt of poll.options) {
    const row = el('label', 'social-poll-option');
    if (!poll.closed) {
      const input = el('input');
      input.type = poll.multi ? 'checkbox' : 'radio';
      input.name = `poll-${poll.id}`;
      input.value = String(opt.id);
      input.checked = poll.myVotes.includes(opt.id);
      row.append(input);
    }
    row.append(el('span', 'social-poll-label', opt.label));
    if (poll.resultsVisible) {
      const pct = Math.round(((opt.votes || 0) / voterCount) * 100);
      const bar = el('span', 'social-poll-bar');
      const fill = el('span', `social-poll-fill${poll.myVotes.includes(opt.id) ? ' is-mine' : ''}${(opt.votes || 0) === total && opt.votes ? ' is-top' : ''}`);
      fill.style.width = `${pct}%`;
      bar.append(fill);
      row.append(bar, el('span', 'social-poll-count', `${opt.votes} · ${pct}%`));
    }
    form.append(row);
  }
  const actions = el('div', 'social-actions');
  if (!poll.closed) {
    const vote = el('button', 'hub-btn', poll.myVotes.length ? 'Change vote' : 'Vote');
    vote.type = 'submit';
    actions.append(vote);
    if (!poll.resultsVisible) actions.append(el('span', 'hint', 'Results appear after you vote.'));
  }
  if (poll.canClose) {
    const close = el('button', 'hub-btn', 'Close poll');
    close.type = 'button';
    close.addEventListener('click', async () => {
      if (!window.confirm('Close this poll? Nobody can vote after that, and results become visible to everyone.')) return;
      try {
        const res = await api(`polls/${poll.id}/close`, { method: 'POST' });
        card.replaceWith(pollCard(res.poll));
      } catch (err) {
        showNotice(err.message, 'error');
      }
    });
    actions.append(close);
  }
  form.append(actions);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!(await ensureCanAct())) return;
    const optionIds = [...form.querySelectorAll('input:checked')].map((i) => Number(i.value));
    if (optionIds.length === 0) {
      showNotice('Pick an option first.', 'error');
      return;
    }
    try {
      const res = await api(`polls/${poll.id}/vote`, { method: 'POST', body: { optionIds } });
      card.replaceWith(pollCard(res.poll));
    } catch (err) {
      showNotice(err.message, 'error');
    }
  });
  card.append(form);
  return card;
}

async function loadPolls() {
  const list = $('polls-list');
  const status = $('polls-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('polls', { query: { status: state.pollStatus } });
    $('poll-new').hidden = !data.membersCreatePolls;
    for (const poll of data.polls) list.append(pollCard(poll));
    status.textContent = data.polls.length ? '' : state.pollStatus === 'open' ? 'No open polls.' : 'No closed polls yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

function addPollOption(value = '') {
  const box = $('poll-options');
  if (box.childElementCount >= 8) return;
  const input = el('input', 'social-input social-poll-input');
  input.maxLength = 120;
  input.placeholder = `Option ${box.childElementCount + 1}`;
  input.value = value;
  box.append(input);
  $('poll-add-option').hidden = box.childElementCount >= 8;
}

async function openPollDialog() {
  if (!(await ensureCanAct())) return;
  $('poll-form').reset();
  $('poll-options').replaceChildren();
  addPollOption();
  addPollOption();
  $('poll-status').textContent = '';
  $('poll-submit').disabled = false;
  $('poll-dialog').showModal();
}

function digestSection(title, nodes, empty) {
  const sec = el('section', 'panel social-digest-section');
  sec.append(el('h2', null, title));
  if (!nodes.length) {
    sec.append(el('p', 'hint', empty));
  } else {
    const ul = el('ul', 'social-digest-list');
    for (const n of nodes) {
      const li = el('li');
      li.append(n);
      ul.append(li);
    }
    sec.append(ul);
  }
  return sec;
}

function threadLink(item, text) {
  const a = el('a', null, text);
  a.href = threadHash(item.did, item.itemId);
  return a;
}

function itemLabel(item) {
  const t = item.title || item.commentary || '(no text)';
  return t.length > 90 ? `${t.slice(0, 89)}…` : t;
}

async function loadDigest() {
  const body = $('digest-body');
  const status = $('digest-status');
  body.replaceChildren();
  status.textContent = 'Loading…';
  $('digest-atom').hidden = state.config.membersOnly;
  try {
    const d = await api('digest', { query: { days: 7 } });
    $('digest-period').textContent = `${new Date(d.period.from).toLocaleDateString()} – ${new Date(d.period.to).toLocaleDateString()}: ${d.stats.posts} posts, ${d.stats.comments} comments, ${d.stats.reactions} reactions`;
    body.append(
      ...(d.spotlight ? [digestSection('Member spotlight', [(() => {
        const f = document.createDocumentFragment();
        const href = safeHttpUrl(d.spotlight.siteUrl);
        const a = el('a', null, d.spotlight.displayName || d.spotlight.did);
        if (href) {
          a.href = href;
          a.rel = 'noopener noreferrer';
          a.target = '_blank';
        }
        f.append(a);
        if (d.spotlight.vouchCount) f.append(el('span', 'hint', ` · Vouched by ${d.spotlight.vouchCount} ${d.spotlight.vouchCount === 1 ? 'member' : 'members'}`));
        return f;
      })()], '')] : []),
      digestSection('Busiest conversations', d.topConversations.map((it) => {
        const f = document.createDocumentFragment();
        f.append(el('strong', null, authorName(it.author)), document.createTextNode(': '), threadLink(it, itemLabel(it)),
          el('span', 'hint', ` · ${it.replyCount} ${it.replyCount === 1 ? 'reply' : 'replies'}`));
        return f;
      }), 'A quiet week.'),
      digestSection('Coming up', d.upcomingEvents.map((it) => {
        const f = document.createDocumentFragment();
        f.append(threadLink(it, itemLabel(it)), el('span', 'hint', ` · ${fmtEventWhen(it.event)}${it.rsvps.going ? ` · ${it.rsvps.going} going` : ''}`));
        return f;
      }), 'No events in the next two weeks.'),
      digestSection('New members', d.newMembers.map((m) => {
        const href = safeHttpUrl(m.siteUrl);
        const a = el('a', null, m.displayName || m.did);
        if (href) {
          a.href = href;
          a.rel = 'noopener noreferrer';
          a.target = '_blank';
        }
        return a;
      }), 'No new members this week.'),
      digestSection('Open polls', d.openPolls.map((p) => {
        const a = el('a', null, p.question);
        a.href = '#polls';
        return a;
      }), 'No open polls.'),
      digestSection('Asks & offers', d.newListings.map((it) => {
        const f = document.createDocumentFragment();
        f.append(el('span', `chip social-kind social-kind-${it.listing.kind}`, it.listing.kind === 'ask' ? 'Ask' : 'Offer'), document.createTextNode(' '), threadLink(it, itemLabel(it)));
        return f;
      }), 'No new asks or offers.')
    );
    status.textContent = '';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

$('poll-new').addEventListener('click', openPollDialog);
$('poll-add-option').addEventListener('click', () => addPollOption());
$('poll-form').addEventListener('submit', async (e) => {
  if (e.submitter && e.submitter.value === 'cancel') return;
  e.preventDefault();
  const options = [...$('poll-options').querySelectorAll('input')].map((i) => i.value.trim()).filter(Boolean);
  $('poll-submit').disabled = true;
  try {
    await api('polls', {
      method: 'POST',
      body: {
        question: $('poll-question').value,
        description: $('poll-description').value,
        options,
        multi: $('poll-multi').checked,
        closesInDays: $('poll-days').value || null,
      },
    });
    $('poll-dialog').close();
    state.pollStatus = 'open';
    setPressed('[data-poll-status]', (b) => b.dataset.pollStatus === 'open');
    loadPolls();
  } catch (err) {
    const s = $('poll-status');
    s.textContent = err.message;
    s.className = 'form-message error';
    $('poll-submit').disabled = false;
  }
});

function setPressed(selector, isOn) {
  for (const b of document.querySelectorAll(selector)) b.setAttribute('aria-pressed', isOn(b) ? 'true' : 'false');
}

for (const btn of document.querySelectorAll('[data-when]')) {
  btn.addEventListener('click', () => {
    state.eventsWhen = btn.dataset.when;
    setPressed('[data-when]', (b) => b === btn);
    loadEvents();
  });
}
for (const btn of document.querySelectorAll('[data-kind]')) {
  btn.addEventListener('click', () => {
    state.boardKind = btn.dataset.kind;
    setPressed('[data-kind]', (b) => b === btn);
    loadBoard();
  });
}
for (const btn of document.querySelectorAll('[data-status]')) {
  btn.addEventListener('click', () => {
    state.boardStatus = btn.dataset.status;
    setPressed('[data-status]', (b) => b === btn);
    loadBoard();
  });
}
for (const btn of document.querySelectorAll('[data-poll-status]')) {
  btn.addEventListener('click', () => {
    state.pollStatus = btn.dataset.pollStatus;
    setPressed('[data-poll-status]', (b) => b === btn);
    loadPolls();
  });
}

// ---------------------------------------------------------------------------
// Tier 3: Welcome wall, Spotlight, People, Vouches, Guestbooks
// ---------------------------------------------------------------------------

function greetingBar(member) {
  const bar = el('div', 'social-greetings');
  const mine = new Set(member.myGreetings || []);
  for (const kind of Object.keys(GREETING_LABELS)) {
    const count = (member.greetingCounts && member.greetingCounts[kind]) || 0;
    const btn = el('button', 'hub-btn social-react');
    btn.type = 'button';
    btn.setAttribute('aria-pressed', mine.has(kind) ? 'true' : 'false');
    btn.disabled = member.did === myDid();
    btn.append(el('span', null, GREETING_LABELS[kind]));
    if (count) btn.append(el('span', 'social-react-count', count));
    btn.addEventListener('click', async () => {
      if (!(await ensureCanAct())) return;
      btn.disabled = true;
      try {
        const res = await api('greetings', {
          method: 'POST',
          body: { subjectDid: member.did, kind, on: !mine.has(kind) },
        });
        member.greetingCounts = res.counts;
        member.myGreetings = res.myGreetings;
        bar.replaceWith(greetingBar(member));
      } catch (err) {
        showNotice(err.message, 'error');
        btn.disabled = false;
      }
    });
    bar.append(btn);
  }
  return bar;
}

async function loadFeedBelonging() {
  const box = $('feed-belonging');
  if (!box) return;
  box.replaceChildren();
  box.hidden = true;
  try {
    const [spot, wel] = await Promise.all([
      api('spotlight').catch(() => ({ enabled: false, member: null })),
      api('welcome').catch(() => ({ members: [] })),
    ]);
    let hasAny = false;

    if (spot && spot.enabled && spot.member) {
      hasAny = true;
      const m = spot.member;
      const card = el('section', 'panel social-spotlight');
      card.append(el('h2', 'social-section-label', '✨ Member spotlight'));
      const head = el('div', 'entry-head social-head');
      head.append(avatar(m));
      const who = el('div', 'social-who');
      const nr = el('div', 'social-name-row');
      const link = el('a', 'social-name', authorName(m));
      const href = safeHttpUrl(m.siteUrl);
      if (href) {
        link.href = href;
        link.rel = 'noopener noreferrer';
        link.target = '_blank';
      }
      nr.append(link);
      const ab = authorBadgesEl(m);
      if (ab) nr.append(ab);
      who.append(nr);
      if (m.vouchCount > 0) {
        who.append(el('span', 'social-meta', `🤝 Vouched by ${m.vouchCount} ${m.vouchCount === 1 ? 'member' : 'members'}`));
      }
      head.append(who);
      card.append(head);
      if (m.bio) card.append(el('p', 'social-text', m.bio));
      if (m.latestPost) {
        const p = el('p', 'hint social-spotlight-post');
        p.append(document.createTextNode('Latest post: '), threadLink(m.latestPost, itemLabel(m.latestPost)));
        card.append(p);
      }
      box.append(card);
    }

    if (wel && Array.isArray(wel.members) && wel.members.length > 0) {
      hasAny = true;
      const strip = el('section', 'panel social-welcome-strip');
      strip.append(el('h2', 'social-section-label', '👋 New here'));
      const grid = el('div', 'social-welcome-grid');
      for (const m of wel.members.slice(0, 6)) {
        const c = el('div', 'social-welcome-card');
        const head = el('div', 'entry-head social-head');
        head.append(avatar(m));
        const who = el('div', 'social-who');
        const link = el('a', 'social-name', authorName(m));
        const href = safeHttpUrl(m.siteUrl);
        if (href) {
          link.href = href;
          link.rel = 'noopener noreferrer';
          link.target = '_blank';
        }
        who.append(link, el('span', 'social-meta', `Joined ${relTime(m.createdAt)}`));
        head.append(who);
        c.append(head);
        if (m.introPost) {
          const intro = el('p', 'hint social-intro-link');
          intro.append(document.createTextNode('Intro: '), threadLink(m.introPost, m.introPost.title || m.introPost.excerpt));
          c.append(intro);
        }
        c.append(greetingBar(m));
        grid.append(c);
      }
      strip.append(grid);
      strip.append(el('p', 'hint social-welcome-hint', `Say hi with a preset above, or reply to a newcomer's #${wel.introTag || 'intro'} post from Webring Studio.`));
      box.append(strip);
    }

    box.hidden = !hasAny;
  } catch {
    box.hidden = true;
  }
}

async function openVouchesDialog(member) {
  const dlg = $('vouches-dialog');
  const body = $('vouches-body');
  $('vouches-title').textContent = `Vouches · ${authorName(member)}`;
  body.replaceChildren(el('p', 'hint', 'Loading…'));
  dlg.showModal();
  try {
    const data = await api('members/vouches', { query: { did: member.did } });
    body.replaceChildren();
    if (data.hidden && member.did !== myDid()) {
      body.append(el('p', 'hint', 'This member has chosen to hide vouches about them.'));
    } else if (data.received.length === 0) {
      body.append(el('p', 'hint', 'No vouches received yet.'));
    } else {
      if (data.hidden) body.append(el('p', 'hint', 'Your received vouches are currently hidden from other members.'));
      const addGroup = (title, list) => {
        if (!list || !list.length) return;
        const sec = el('div', 'social-vouch-group');
        sec.append(el('h3', null, title));
        const ul = el('ul', 'social-digest-list');
        for (const v of list) {
          const li = el('li');
          li.append(el('strong', null, authorName(v.voucher)));
          li.append(el('span', 'hint', ` · ${relTime(v.publishedAt)}`));
          if (v.keyChangedSince) {
            li.append(el('span', 'social-key-warn', ' · key changed since'));
          }
          ul.append(li);
        }
        sec.append(ul);
        body.append(sec);
      };
      addGroup('Met in person', data.grouped.met);
      addGroup('Worked together', data.grouped['worked-with']);
      for (const [skill, list] of Object.entries(data.grouped.skill || {})) {
        addGroup(`Skill: ${skill}`, list);
      }
    }
    if (data.given && data.given.length > 0) {
      const sec = el('div', 'social-vouch-group');
      sec.append(el('h3', null, `Vouches given (${data.given.length})`));
      const ul = el('ul', 'social-digest-list');
      for (const v of data.given) {
        const li = el('li');
        const detail = v.kind === 'skill' ? `skill: ${v.skill}` : v.kind === 'worked-with' ? 'worked with' : 'met';
        li.append(el('strong', null, authorName(v.subject)), document.createTextNode(` · ${detail}`));
        if (v.keyChangedSince) li.append(el('span', 'social-key-warn', ' · key changed since'));
        ul.append(li);
      }
      sec.append(ul);
      body.append(sec);
    }
  } catch (err) {
    body.replaceChildren(el('p', 'hint', err.message));
  }
}

async function loadGuestbookEntries(ownerDid) {
  const list = $('guestbook-list');
  const status = $('guestbook-status');
  const form = $('guestbook-form');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('guestbook', { query: { did: ownerDid } });
    $('guestbook-title').textContent = `${data.ownerName || ownerDid}'s guestbook`;
    form.hidden = !data.enabled || !state.me.authenticated;
    if (!data.enabled) {
      status.textContent = 'This member has not turned on their guestbook.';
      return;
    }
    for (const entry of data.entries) {
      const card = el('article', 'entry social-gb-entry');
      const head = el('div', 'entry-head social-head');
      head.append(avatar(entry.author));
      const who = el('div', 'social-who');
      const nr = el('div', 'social-name-row');
      nr.append(el('span', 'social-name', authorName(entry.author)));
      const ab = authorBadgesEl(entry.author);
      if (ab) nr.append(ab);
      who.append(nr, el('span', 'social-meta', relTime(entry.createdAt)));
      head.append(who);
      card.append(head);
      card.append(el('p', 'social-text', entry.body));
      const actions = el('div', 'social-actions');
      if (entry.canDelete) {
        const del = el('button', 'hub-btn', 'Delete');
        del.type = 'button';
        del.addEventListener('click', async () => {
          if (!window.confirm('Delete this guestbook entry?')) return;
          try {
            await api(`guestbook/${entry.id}`, { method: 'DELETE' });
            loadGuestbookEntries(ownerDid);
          } catch (err) {
            showNotice(err.message, 'error');
          }
        });
        actions.append(del);
      }
      if (state.me.authenticated && entry.author.did !== myDid()) {
        const rep = el('button', 'hub-btn social-report', 'Report');
        rep.type = 'button';
        rep.addEventListener('click', () => openReport({ kind: 'guestbook', id: entry.id }));
        actions.append(rep);
      }
      if (actions.childElementCount > 0) card.append(actions);
      list.append(card);
    }
    status.textContent = data.entries.length ? '' : 'No guestbook entries yet. Be the first to sign!';
  } catch (err) {
    status.textContent = err.message;
  }
}

function openGuestbookDialog(member) {
  state.guestbookOwnerDid = member.did;
  $('guestbook-form').reset();
  $('guestbook-form-status').textContent = '';
  $('guestbook-dialog').showModal();
  loadGuestbookEntries(member.did);
}

async function loadPeople() {
  const list = $('people-list');
  const status = $('people-status');
  const controls = $('people-my-controls');
  list.replaceChildren();
  status.textContent = 'Loading…';

  if (state.me.authenticated) {
    controls.hidden = false;
    $('pref-guestbook').checked = Boolean(state.config.myGuestbookEnabled);
    $('pref-guestbook-csv').hidden = !state.config.myGuestbookEnabled;
    $('pref-hide-vouches').checked = Boolean(state.config.myHideVouches);
  } else {
    controls.hidden = true;
  }

  try {
    const data = await api('people');
    for (const m of data.members) {
      const card = el('article', 'entry social-person-card');
      const head = el('div', 'entry-head social-head');
      head.append(avatar(m));
      const who = el('div', 'social-who');
      const nr = el('div', 'social-name-row');
      const link = el('a', 'social-name', authorName(m));
      const href = safeHttpUrl(m.siteUrl);
      if (href) {
        link.href = href;
        link.rel = 'noopener noreferrer';
        link.target = '_blank';
      }
      nr.append(link);
      const ab = authorBadgesEl(m);
      if (ab) nr.append(ab);
      who.append(nr);
      if (m.handle) who.append(el('span', 'social-meta', `@${m.handle.replace(/^@/, '')}`));
      head.append(who);
      card.append(head);
      if (m.bio) card.append(el('p', 'social-text', m.bio));
      const foot = el('div', 'social-actions');
      const vouchLabel = m.vouchesHidden
        ? '🤝 Vouches hidden'
        : `🤝 Vouched by ${m.vouchCount} ${m.vouchCount === 1 ? 'member' : 'members'}`;
      const vBtn = el('button', 'hub-btn', vouchLabel);
      vBtn.type = 'button';
      vBtn.addEventListener('click', () => openVouchesDialog(m));
      foot.append(vBtn);
      if (m.guestbookEnabled) {
        const gBtn = el('button', 'hub-btn', '📖 Guestbook');
        gBtn.type = 'button';
        gBtn.addEventListener('click', () => openGuestbookDialog(m));
        foot.append(gBtn);
      }
      card.append(foot);
      list.append(card);
    }
    status.textContent = data.members.length ? '' : 'No active members listed yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

$('vouches-close').addEventListener('click', () => $('vouches-dialog').close());
$('guestbook-close').addEventListener('click', () => $('guestbook-dialog').close());

$('guestbook-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!state.guestbookOwnerDid) return;
  if (!(await ensureCanAct())) return;
  $('guestbook-submit').disabled = true;
  $('guestbook-form-status').textContent = '';
  try {
    await api('guestbook', {
      method: 'POST',
      body: { ownerDid: state.guestbookOwnerDid, body: $('guestbook-input').value },
    });
    $('guestbook-input').value = '';
    loadGuestbookEntries(state.guestbookOwnerDid);
  } catch (err) {
    const s = $('guestbook-form-status');
    s.textContent = err.message;
    s.className = 'form-message error';
  } finally {
    $('guestbook-submit').disabled = false;
  }
});

$('pref-guestbook').addEventListener('change', async (e) => {
  const checked = e.target.checked;
  if (!(await ensureCanAct())) {
    e.target.checked = !checked;
    return;
  }
  try {
    const res = await api('me/guestbook', { method: 'POST', body: { enabled: checked } });
    state.config.myGuestbookEnabled = res.enabled;
    $('pref-guestbook-csv').hidden = !res.enabled;
    loadPeople();
  } catch (err) {
    e.target.checked = !checked;
    showNotice(err.message, 'error');
  }
});

$('pref-hide-vouches').addEventListener('change', async (e) => {
  const checked = e.target.checked;
  if (!(await ensureCanAct())) {
    e.target.checked = !checked;
    return;
  }
  try {
    const res = await api('me/vouch-prefs', { method: 'POST', body: { hideReceived: checked } });
    state.config.myHideVouches = res.hideReceived;
    loadPeople();
  } catch (err) {
    e.target.checked = !checked;
    showNotice(err.message, 'error');
  }
});

// ---------------------------------------------------------------------------
// Tier 4: Channels, Library, Now wall, Challenges
// ---------------------------------------------------------------------------

async function loadChannelPicker() {
  const box = $('channel-picker');
  if (!box) return;
  try {
    const data = await api('channels');
    const channels = data.channels || [];
    if (!channels.length) {
      box.hidden = true;
      return;
    }
    box.replaceChildren();
    const mkChip = (tag, label, active) => {
      const btn = el('button', 'hub-btn', label);
      btn.type = 'button';
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      btn.addEventListener('click', () => {
        state.feedTag = tag;
        loadChannelPicker();
        loadFeed(true);
      });
      return btn;
    };
    box.append(mkChip('', 'All channels', state.feedTag === ''));
    if (state.me.authenticated) {
      box.append(mkChip('__following', '★ Following', state.feedTag === '__following'));
    }
    for (const c of channels) {
      const wrap = el('span', 'social-channel-chip');
      wrap.append(mkChip(c.tag, `${c.title}${c.items7d ? ` (${c.items7d})` : ''}`, state.feedTag === c.tag));
      if (state.me.authenticated) {
        const fBtn = el('button', 'hub-btn social-follow-btn', c.following ? '✓' : '+');
        fBtn.type = 'button';
        fBtn.title = c.following ? `Unfollow #${c.tag}` : `Follow #${c.tag}`;
        fBtn.addEventListener('click', async () => {
          if (!(await ensureCanAct())) return;
          try {
            await api('follows', { method: 'POST', body: { tag: c.tag, on: !c.following } });
            loadChannelPicker();
          } catch (err) {
            showNotice(err.message, 'error');
          }
        });
        wrap.append(fBtn);
      }
      box.append(wrap);
    }
    box.hidden = false;
  } catch {
    box.hidden = true;
  }
}

async function loadActiveChallengeBanner() {
  const banner = $('feed-challenge-banner');
  if (!banner) return;
  try {
    const data = await api('challenges');
    const active = (data.active || [])[0];
    if (!active) {
      banner.hidden = true;
      return;
    }
    banner.replaceChildren();
    const title = el('strong', null, `🏆 Active challenge: ${active.title} `);
    const tag = el('code', null, `#${active.tag}`);
    const link = el('a', 'hub-btn', `View (${active.entryCount} ${active.entryCount === 1 ? 'entry' : 'entries'})`);
    link.href = '#challenges';
    banner.append(title, tag, document.createTextNode(' · '), link);
    banner.hidden = false;
  } catch {
    banner.hidden = true;
  }
}

async function loadLibrary() {
  const list = $('library-list');
  const status = $('library-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('library', { query: { limit: 50 } });
    for (const pick of data.picks || []) {
      const card = el('article', 'entry social-card');
      const link = el('a', 'social-title', pick.title);
      const safe = safeHttpUrl(pick.url);
      if (safe) {
        link.href = safe;
        link.rel = 'nofollow ugc noopener noreferrer';
        link.target = '_blank';
      }
      card.append(link);
      if (pick.excerpt) card.append(el('p', 'social-text', pick.excerpt));
      const names = (pick.sharers || []).map((s) => s.displayName || s.did).join(', ');
      card.append(el('p', 'hint', `Shared by ${names} · ${relTime(pick.latestAt)}`));
      const voteBtn = el('button', 'hub-btn', `👍 Pick (${pick.votes})`);
      voteBtn.type = 'button';
      voteBtn.setAttribute('aria-pressed', pick.myVote ? 'true' : 'false');
      voteBtn.addEventListener('click', async () => {
        if (!(await ensureCanAct())) return;
        voteBtn.disabled = true;
        try {
          const res = await api('library/vote', { method: 'POST', body: { url: pick.url, on: !pick.myVote } });
          pick.votes = res.votes;
          pick.myVote = res.myVote;
          voteBtn.textContent = `👍 Pick (${pick.votes})`;
          voteBtn.setAttribute('aria-pressed', pick.myVote ? 'true' : 'false');
        } catch (err) {
          showNotice(err.message, 'error');
        } finally {
          voteBtn.disabled = false;
        }
      });
      card.append(el('div', 'social-actions'));
      card.lastChild.append(voteBtn);
      list.append(card);
    }
    status.textContent = (data.picks || []).length ? '' : 'No shared links or recommendations yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

async function loadNowWall() {
  const list = $('now-list');
  const status = $('now-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('now');
    for (const m of data.entries || []) {
      const card = el('article', 'entry social-person-card');
      const head = el('div', 'entry-head social-head');
      head.append(avatar(m));
      const who = el('div', 'social-who');
      const nr = el('div', 'social-name-row');
      const link = el('a', 'social-name', authorName(m));
      const href = safeHttpUrl(m.siteUrl);
      if (href) {
        link.href = href;
        link.rel = 'noopener noreferrer';
        link.target = '_blank';
      }
      nr.append(link);
      const ab = authorBadgesEl(m);
      if (ab) nr.append(ab);
      who.append(nr, el('span', 'social-meta', relTime(m.now.updatedAt)));
      head.append(who);
      card.append(head, el('p', 'social-text', m.now.text));
      list.append(card);
    }
    status.textContent = (data.entries || []).length ? '' : 'No recent Now updates yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

async function loadChallenges() {
  const list = $('challenges-list');
  const status = $('challenges-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('challenges');
    const groups = [
      ['Active challenges', data.active || []],
      ['Upcoming challenges', data.upcoming || []],
      ['Past challenges', data.past || []],
    ];
    let total = 0;
    for (const [heading, items] of groups) {
      if (!items.length) continue;
      total += items.length;
      list.append(el('h2', 'social-section-label', heading));
      for (const ch of items) {
        const card = el('article', 'entry social-card');
        card.append(el('h3', 'social-title', ch.title));
        if (ch.prompt) card.append(el('p', 'social-text', ch.prompt));
        card.append(el('p', 'hint', `Tag: #${ch.tag} · ${ch.entryCount} ${ch.entryCount === 1 ? 'entry' : 'entries'} · Ends ${new Date(ch.endsAt).toLocaleDateString()}`));
        const entriesBox = el('div', 'social-list');
        entriesBox.hidden = true;
        const btn = el('button', 'hub-btn', 'Show entries');
        btn.type = 'button';
        btn.addEventListener('click', async () => {
          if (!entriesBox.hidden) {
            entriesBox.hidden = true;
            btn.textContent = 'Show entries';
            return;
          }
          btn.disabled = true;
          try {
            const det = await api(`challenges/${ch.id}`);
            entriesBox.replaceChildren();
            for (const en of det.entries || []) entriesBox.append(itemCard(en));
            if (!(det.entries || []).length) entriesBox.append(el('p', 'hint', 'No entries yet.'));
            entriesBox.hidden = false;
            btn.textContent = 'Hide entries';
          } catch (err) {
            showNotice(err.message, 'error');
          } finally {
            btn.disabled = false;
          }
        });
        const act = el('div', 'social-actions');
        act.append(btn);
        if (ch.pollId) {
          const pl = el('a', 'hub-btn', '🗳️ View voting poll');
          pl.href = '#polls';
          act.append(pl);
        }
        card.append(act, entriesBox);
        list.append(card);
      }
    }
    status.textContent = total ? '' : 'No challenges yet.';
  } catch (err) {
    status.textContent = err.code === 'auth_required' ? 'This community is visible to signed-in members only.' : err.message;
  }
}

// ---------------------------------------------------------------------------
// Routing & wiring
// ---------------------------------------------------------------------------

function route() {
  const hash = location.hash || '#feed';
  if (hash.startsWith('#thread?')) {
    const params = new URLSearchParams(hash.slice('#thread?'.length));
    setView('thread');
    loadThread(params.get('did') || '', params.get('id') || '');
  } else if (hash === '#people') {
    setView('people');
    loadPeople();
  } else if (hash === '#library') {
    setView('library');
    loadLibrary();
  } else if (hash === '#now') {
    setView('now');
    loadNowWall();
  } else if (hash === '#challenges') {
    setView('challenges');
    loadChallenges();
  } else if (hash === '#activity') {
    setView('activity');
    loadActivity(true);
  } else if (hash === '#events') {
    setView('events');
    loadEvents();
  } else if (hash === '#board') {
    setView('board');
    loadBoard();
  } else if (hash === '#polls') {
    setView('polls');
    loadPolls();
  } else if (hash === '#digest') {
    setView('digest');
    loadDigest();
  } else {
    state.lastFeedHash = '#feed';
    setView('feed');
    loadActiveChallengeBanner();
    loadFeedBelonging();
    loadChannelPicker();
    loadFeed(true);
  }
}

$('thread-back').addEventListener('click', () => {
  if (history.length > 1) history.back();
  else location.hash = state.lastFeedHash;
});
$('feed-more').addEventListener('click', () => loadFeed(false));
if ($('feed-daily-bar')) {
  $('feed-daily-bar').addEventListener('submit', (e) => {
    e.preventDefault();
    state.feedQuery = ($('feed-search')?.value || '').trim();
    loadFeed(true);
  });
  $('feed-date').addEventListener('change', () => {
    state.feedDate = $('feed-date').value || localDateStr();
    loadFeed(true);
  });
  $('feed-day-prev').addEventListener('click', () => {
    state.feedDate = shiftLocalDate(state.feedDate || localDateStr(), -1);
    loadFeed(true);
  });
  $('feed-day-next').addEventListener('click', () => {
    state.feedDate = shiftLocalDate(state.feedDate || localDateStr(), 1);
    loadFeed(true);
  });
  $('feed-day-today').addEventListener('click', () => {
    state.feedDate = localDateStr();
    loadFeed(true);
  });
  $('feed-time-from').addEventListener('change', () => {
    state.feedTimeFrom = $('feed-time-from').value || '';
    loadFeed(true);
  });
  $('feed-time-to').addEventListener('change', () => {
    state.feedTimeTo = $('feed-time-to').value || '';
    loadFeed(true);
  });
  let searchTimer = null;
  $('feed-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.feedQuery = ($('feed-search').value || '').trim();
      loadFeed(true);
    }, 220);
  });
  $('feed-filter-reset').addEventListener('click', () => {
    state.feedDate = localDateStr();
    state.feedTimeFrom = '';
    state.feedTimeTo = '';
    state.feedQuery = '';
    loadFeed(true);
  });
}
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
  const libPostBtn = $('library-post-btn');
  if (libPostBtn) {
    const hubName = state.config.hubTitle || window.location.host || 'Webring Hub';
    libPostBtn.href = `webring://interact?action=recommendation&location=${encodeURIComponent('Community Library')}&hub=${encodeURIComponent(hubName)}`;
  }
  buildReasons();
  route();
  refreshBadge();
}

init();
