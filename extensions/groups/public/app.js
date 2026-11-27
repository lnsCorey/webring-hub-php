const API = new URL('../../api/ext/groups/v1/', import.meta.url).pathname;
const EXT_ID = 'groups';
const $ = (id) => document.getElementById(id);
const auth = window.webringHubAuth;
const state = { me: { authenticated: false, user: null, consents: [] }, loadSeq: 0 };

function el(tag, className, text) {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text !== undefined && text !== null) n.textContent = String(text);
  return n;
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

function memberLink(member) {
  const label = member.displayName || member.handle || member.did;
  const href = safeHttpUrl(member.siteUrl);
  if (!href) return el('span', null, label);
  const a = el('a', 'group-member-link', label);
  a.href = href;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  return a;
}

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

function optedIn() {
  return Boolean(state.me.consents.find((c) => c.extensionId === EXT_ID && c.optedIn));
}

async function ensureCanAct() {
  if (!state.me.authenticated) {
    if (auth) auth.openSignIn(EXT_ID);
    return false;
  }
  if (optedIn()) return true;
  if (!window.confirm('Opt in to Working Groups? Your memberships, requests and group votes can be deleted any time from My Data.')) return false;
  state.me = await auth.setConsent(EXT_ID, true);
  return optedIn();
}

async function api(path, { method = 'GET', body } = {}) {
  const headers = { accept: 'application/json' };
  if (method !== 'GET') {
    headers['content-type'] = 'application/json';
    headers['x-hub-csrf'] = auth ? auth.csrfToken() : '';
  }
  const res = await fetch(API + path, {
    method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers,
    body: method === 'GET' ? undefined : JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || `Request failed (${res.status}).`);
  return data;
}

function notice(msg, kind = '') {
  const n = $('groups-notice');
  n.hidden = !msg;
  n.textContent = msg || '';
  n.className = `groups-notice${kind ? ` ${kind}` : ''}`;
}

async function loadList() {
  const seq = ++state.loadSeq;
  $('view-list').hidden = false;
  $('view-detail').hidden = true;
  const list = $('groups-list');
  const status = $('groups-status');
  list.replaceChildren();
  status.textContent = 'Loading…';
  try {
    const data = await api('groups');
    if (seq !== state.loadSeq) return;
    list.replaceChildren();
    for (const g of data.groups || []) {
      const card = el('article', 'panel group-card');
      card.append(el('h2', null, g.title));
      if (g.description) card.append(el('p', 'hint', g.description));
      card.append(el('p', 'hint', `Tag #${g.tag} · ${g.memberCount} member(s) · ${g.visibility === 'request' ? 'Approval required' : 'Open'}`));
      const btn = el('button', 'hub-btn', 'Open group');
      btn.type = 'button';
      btn.addEventListener('click', () => { location.hash = `#${g.slug}`; });
      card.append(btn);
      list.append(card);
    }
    status.textContent = (data.groups || []).length ? '' : 'No working groups yet.';
  } catch (err) {
    if (seq !== state.loadSeq) return;
    status.textContent = err.message;
  }
}

async function loadDetail(slug) {
  $('view-list').hidden = true;
  $('view-detail').hidden = false;
  const box = $('group-detail');
  box.replaceChildren(el('p', 'hint', 'Loading…'));
  try {
    const data = await api(`groups/${encodeURIComponent(slug)}`);
    const g = data.group;
    box.replaceChildren();
    const head = el('section', 'panel');
    head.append(el('h2', null, g.title));
    if (g.description) head.append(el('p', null, g.description));
    head.append(el('p', 'hint', `Post with #${g.tag} in Webring Studio to share to this group's feed.`));
    const act = el('div', 'group-actions');
    if (g.myRole) {
      const postLink = el('a', 'hub-btn group-post-btn', '＋ Post in Studio');
      const loc = `${g.title || g.slug} (Group Feed)`;
      const hubName = data.hubTitle || window.location.host || 'Webring Hub';
      postLink.href = `webring://interact?action=post&tags=${encodeURIComponent(g.tag)}&location=${encodeURIComponent(loc)}&hub=${encodeURIComponent(hubName)}`;
      postLink.title = `Open Webring Studio to compose a post tagged #${g.tag}`;
      const leave = el('button', 'hub-btn', 'Leave group');
      leave.type = 'button';
      leave.addEventListener('click', async () => {
        if (!window.confirm('Leave this working group?')) return;
        await api(`groups/${encodeURIComponent(slug)}/leave`, { method: 'POST' });
        loadDetail(slug);
      });
      act.append(postLink, el('span', 'chip', `Role: ${g.myRole}`), leave);
    } else if (g.myRequestPending) {
      act.append(el('span', 'hint', 'Join request pending organiser approval.'));
    } else {
      const join = el('button', 'hub-btn', g.visibility === 'request' ? 'Request to join' : 'Join group');
      join.type = 'button';
      join.addEventListener('click', async () => {
        if (!(await ensureCanAct())) return;
        try {
          await api(`groups/${encodeURIComponent(slug)}/join`, { method: 'POST' });
          loadDetail(slug);
        } catch (err) {
          notice(err.message, 'error');
        }
      });
      act.append(join);
    }
    head.append(act);
    box.append(head);

    if (data.requests && data.requests.length) {
      const rqSec = el('section', 'panel');
      rqSec.append(el('h3', null, 'Pending join requests'));
      for (const r of data.requests) {
        const row = el('div', 'group-req-row');
        row.append(memberLink(r));
        const ok = el('button', 'hub-btn', 'Approve');
        ok.type = 'button';
        ok.addEventListener('click', async () => {
          await api(`groups/${encodeURIComponent(slug)}/requests/${encodeURIComponent(r.did)}/approve`, { method: 'POST' });
          loadDetail(slug);
        });
        row.append(ok);
        rqSec.append(row);
      }
      box.append(rqSec);
    }

    const feedSec = el('section', 'panel');
    feedSec.append(el('h3', null, `Group feed (${(data.feed || []).length})`));
    if (!(data.feed || []).length) {
      feedSec.append(el('p', 'hint', `No posts tagged #${g.tag} from group members yet.`));
    } else {
      for (const it of data.feed) {
        const c = el('article', 'entry');
        const head = el('div', 'group-post-head');
        head.append(memberLink(it.author));
        if (it.publishedAt) {
          const time = el('time', 'hint group-post-time', ` · ${relTime(it.publishedAt)}`);
          time.dateTime = it.publishedAt;
          time.title = new Date(it.publishedAt).toLocaleString();
          head.append(time);
        }
        if (it.title) head.append(el('strong', null, `: ${it.title}`));
        c.append(head);
        if (it.commentary) {
          const body = el('div', 'group-post-body');
          body.append(richText(it.commentary));
          c.append(body);
        }
        feedSec.append(c);
      }
    }
    box.append(feedSec);

    if (data.members && data.members.length) {
      const memSec = el('section', 'panel');
      memSec.append(el('h3', null, `Members (${data.members.length})`));
      const ul = el('ul', 'group-members-list');
      for (const m of data.members) {
        const li = el('li');
        li.append(memberLink(m));
        if (m.handle) li.append(el('span', 'hint', ` @${m.handle.replace(/^@/, '')}`));
        li.append(document.createTextNode(' '), el('span', 'chip', m.role));
        ul.append(li);
      }
      memSec.append(ul);
      box.append(memSec);
    }
  } catch (err) {
    box.replaceChildren(el('p', 'hint', err.message));
  }
}

$('group-back').addEventListener('click', () => { location.hash = ''; });
function route() {
  const slug = (location.hash || '').replace(/^#/, '').trim();
  if (slug) loadDetail(slug);
  else loadList();
}
let lastDid;
window.addEventListener('hashchange', route);
document.addEventListener('webring-hub-auth', (e) => {
  state.me = e.detail;
  const nextDid = state.me.user ? state.me.user.did : null;
  if (lastDid !== undefined && nextDid !== lastDid) route();
  lastDid = nextDid;
});

(async function init() {
  if (auth) {
    state.me = await auth.ready;
    lastDid = state.me.user ? state.me.user.did : null;
  }
  route();
})();
