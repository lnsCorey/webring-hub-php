(function () {
  'use strict';

  const API_BASE = '../../api/ext/arcade/v1';
  const HUB_API = '../../api';

  let allGames = [];
  let activeDid = '';
  let activePlayerName = '';
  let arcadeOptedIn = false;
  let csrfToken = '';
  let authPollTimer = null;
  let currentGame = null;
  let currentSessionId = '';

  const el = (id) => document.getElementById(id);
  function make(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  }

  async function loadHubInfo() {
    try {
      const res = await fetch(`${HUB_API}/info`);
      if (!res.ok) return;
      const data = await res.json();
      if (data?.instance?.title) {
        el('arcade-title').textContent = `${data.instance.title} — Arcade`;
      }
    } catch {
      // Keep default header
    }
  }

  async function loadAuthSession() {
    const statusEl = el('did-status');
    try {
      const res = await fetch(`${HUB_API}/auth/me`, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('Auth check failed');
      const data = await res.json();
      if (data.authenticated && data.user && data.user.did) {
        activeDid = data.user.did;
        activePlayerName = data.user.displayName || activeDid;
        csrfToken = data.csrfToken || '';
        const consent = (data.consents || []).find((c) => c.extensionId === 'arcade');
        arcadeOptedIn = Boolean(consent && consent.optedIn);

        el('auth-signin-btn').classList.add('hidden');
        el('auth-signout-btn').classList.remove('hidden');
        if (arcadeOptedIn) {
          statusEl.textContent = `Playing as ${activePlayerName} (Verified DID)`;
          statusEl.className = 'status-pill status-ok';
          el('optin-btn').classList.add('hidden');
          el('did-history-btn').classList.remove('hidden');
          el('optout-btn').classList.remove('hidden');
        } else {
          statusEl.textContent = `Signed in as ${activePlayerName} (not opted in — scores not saved)`;
          statusEl.className = 'status-pill status-warn';
          el('optin-btn').classList.remove('hidden');
          el('did-history-btn').classList.add('hidden');
          el('optout-btn').classList.add('hidden');
        }
        return;
      }
    } catch {
      // Fall through to guest mode
    }
    activeDid = '';
    activePlayerName = '';
    arcadeOptedIn = false;
    csrfToken = '';
    statusEl.textContent = 'Playing as Guest (scores not saved)';
    statusEl.className = 'status-pill status-warn';
    el('auth-signin-btn').classList.remove('hidden');
    el('auth-signout-btn').classList.add('hidden');
    el('optin-btn').classList.add('hidden');
    el('did-history-btn').classList.add('hidden');
    el('optout-btn').classList.add('hidden');
  }

  function stopAuthPoll() {
    if (authPollTimer !== null) {
      clearInterval(authPollTimer);
      authPollTimer = null;
    }
  }

  async function openSignIn() {
    const dialog = el('arcade-auth-dialog');
    const status = el('arcade-auth-status');
    const link = el('arcade-auth-link');
    stopAuthPoll();
    status.textContent = 'Creating sign-in challenge…';
    link.classList.add('hidden');
    dialog.showModal();

    try {
      const res = await fetch(`${HUB_API}/auth/challenge`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ extensionId: 'arcade' }),
      });
      const ch = await res.json().catch(() => ({}));
      if (!res.ok || !ch.challengeId) {
        status.textContent = ch.message || 'Could not start sign-in.';
        return;
      }
      const params = new URLSearchParams({
        hub: ch.hubUrl,
        challenge: ch.challengeId,
        nonce: ch.nonce,
        ext: 'arcade',
      });
      link.href = `webring://hub-auth?${params.toString()}`;
      link.classList.remove('hidden');
      status.textContent = 'Click "Open Webring Studio" to approve with your Ed25519 key. Waiting…';

      authPollTimer = setInterval(async () => {
        try {
          const cRes = await fetch(`${HUB_API}/auth/claim`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ challengeId: ch.challengeId, challengeSecret: ch.challengeSecret }),
          });
          const cData = await cRes.json().catch(() => ({}));
          if (cRes.ok && cData.status === 'authenticated') {
            stopAuthPoll();
            dialog.close();
            await loadAuthSession();
          } else if (!cRes.ok) {
            stopAuthPoll();
            status.textContent = cData.message || 'Sign-in challenge expired.';
          }
        } catch {
          // Retry next tick
        }
      }, 1500);
    } catch {
      status.textContent = 'Network error starting sign-in.';
    }
  }

  async function setArcadeConsent(optedIn) {
    if (!optedIn && !window.confirm('Opt out of Arcade and permanently delete all your high scores and play history?')) {
      return;
    }
    const res = await fetch(`${HUB_API}/auth/extensions/arcade/consent`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Hub-Csrf': csrfToken,
      },
      body: JSON.stringify({ optedIn }),
    });
    if (res.ok) {
      await loadAuthSession();
      if (!optedIn) {
        el('history-view').classList.add('hidden');
        loadCatalog();
      }
    }
  }

  async function loadCatalog() {
    const q = el('search-input').value.trim();
    const tag = el('tag-select').value;
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (tag) params.set('tag', tag);
    const res = await fetch(`${API_BASE}/games?${params.toString()}`);
    const data = await res.json();
    allGames = Array.isArray(data.games) ? data.games : [];
    populateTagFilter(allGames);
    renderCatalog(allGames);
  }

  function populateTagFilter(games) {
    const select = el('tag-select');
    if (select.options.length > 1) return;
    const tags = new Set();
    games.forEach((g) => (g.tags || []).forEach((t) => tags.add(t)));
    Array.from(tags).sort().forEach((t) => {
      const opt = make('option', '', t);
      opt.value = t;
      select.appendChild(opt);
    });
  }

  function renderCatalog(games) {
    const grid = el('games-grid');
    grid.replaceChildren();
    if (games.length === 0) {
      grid.appendChild(make('p', 'notice', 'No games match your search.'));
      return;
    }
    games.forEach((game) => {
      const card = make('article', 'card');

      const marquee = make('div', 'card-marquee');
      marquee.appendChild(make('h3', 'card-title', game.title));
      marquee.appendChild(make('span', 'card-attract', 'INSERT COIN'));
      card.appendChild(marquee);

      if (game.thumbnailUrl) {
        const screen = make('div', 'card-screen');
        const img = make('img', 'card-thumb');
        img.src = `../..${game.thumbnailUrl}`;
        img.alt = game.title;
        screen.appendChild(img);
        card.appendChild(screen);
      }

      const body = make('div', 'card-body');
      body.appendChild(make('p', 'notice', game.description));

      const tagRow = make('div', 'tags');
      (game.tags || []).forEach((t) => tagRow.appendChild(make('span', 'tag', t)));
      body.appendChild(tagRow);

      const topText = game.stats?.topScore
        ? `HI-SCORE: ${game.stats.topScore.score} (${game.stats.topScore.displayName})`
        : 'NO SCORES YET';
      body.appendChild(make('div', 'notice', `${topText} • ${game.stats?.plays || 0} PLAYS`));

      const btn = make('button', 'btn btn-primary', '▶ 1P START');
      btn.type = 'button';
      btn.addEventListener('click', () => openGameDetail(game.slug));
      body.appendChild(btn);

      card.appendChild(body);
      grid.appendChild(card);
    });
  }

  async function openGameDetail(slug) {
    const res = await fetch(`${API_BASE}/games/${encodeURIComponent(slug)}`);
    if (!res.ok) return;
    const data = await res.json();
    currentGame = data.game;
    el('catalog-view').classList.add('hidden');
    el('catalog-controls').classList.add('hidden');
    el('history-view').classList.add('hidden');
    el('detail-view').classList.remove('hidden');
    stopGame();

    el('detail-title').textContent = currentGame.title;
    el('detail-desc').textContent = currentGame.description;

    const ctrlWrap = el('detail-controls');
    ctrlWrap.replaceChildren();
    (currentGame.controls || []).forEach((c) => {
      ctrlWrap.appendChild(make('span', 'tag', `${c.action}: ${(c.keys || []).join(', ')}`));
    });

    renderLeaderboard(data.leaderboard || []);
  }

  const UNVERIFIED_TITLE = "Scores are self-reported by the player's browser and are not proof of identity.";
  let lastScores = [];

  function verificationPill(verified) {
    const pill = make('span', verified ? 'pill-verified' : 'pill-unverified', verified ? 'Verified' : 'Unverified');
    pill.title = verified ? 'This score was checked by the hub.' : UNVERIFIED_TITLE;
    return pill;
  }

  function renderLeaderboard(scores) {
    lastScores = Array.isArray(scores) ? scores : [];
    const verifiedOnly = el('verified-only').checked;
    const rows = verifiedOnly ? lastScores.filter((row) => row.verified === true) : lastScores;
    const tbody = el('leaderboard-body');
    tbody.replaceChildren();
    if (rows.length === 0) {
      const tr = make('tr');
      const text = verifiedOnly
        ? 'No verified scores yet. Every score on this arcade is currently self-reported.'
        : 'No scores recorded yet. Be the first!';
      const td = make('td', 'notice', text);
      td.colSpan = 4;
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }
    rows.forEach((row) => {
      const tr = make('tr');
      tr.appendChild(make('td', '', `#${row.rank}`));
      tr.appendChild(make('td', '', `${row.displayName} (${row.did})`));
      const scoreCell = make('td', '', String(row.score));
      scoreCell.appendChild(verificationPill(row.verified === true));
      tr.appendChild(scoreCell);
      tr.appendChild(make('td', '', row.achievedAt ? row.achievedAt.slice(0, 10) : ''));
      tbody.appendChild(tr);
    });
  }

  el('verified-only').addEventListener('change', () => renderLeaderboard(lastScores));

  /** Readable text for an API error, with a clear message when the player is rate-limited. */
  function apiErrorText(res, data, fallback) {
    if (res && res.status === 429) {
      return (data && data.message) || "You're going too quickly. Try again in a few minutes.";
    }
    return (data && data.message) || fallback;
  }

  async function startGame() {
    if (!currentGame) return;
    currentSessionId = '';
    const statusEl = el('session-status');
    if (activeDid && arcadeOptedIn) {
      try {
        const res = await fetch(`${API_BASE}/games/${encodeURIComponent(currentGame.slug)}/sessions`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Hub-Csrf': csrfToken,
          },
          body: JSON.stringify({}),
        });
        if (res.ok) {
          const sess = await res.json();
          currentSessionId = sess.sessionId || '';
          statusEl.textContent = `Playing as ${activePlayerName} (Verified DID — scores saved)`;
          statusEl.className = 'status-pill status-ok';
        } else {
          const data = await res.json().catch(() => ({}));
          statusEl.textContent = res.status === 429
            ? `Guest play — ${apiErrorText(res, data, '')}`
            : `Guest play — ${apiErrorText(res, data, 'could not start scored session')}`;
          statusEl.className = 'status-pill status-warn';
        }
      } catch {
        statusEl.textContent = 'Guest play (offline session)';
        statusEl.className = 'status-pill status-warn';
      }
    } else if (activeDid && !arcadeOptedIn) {
      statusEl.textContent = 'Guest play — opt in above to record high scores under your DID';
      statusEl.className = 'status-pill status-warn';
    } else {
      statusEl.textContent = 'Guest play — sign in with Webring Studio above to record high scores';
      statusEl.className = 'status-pill status-warn';
    }

    const frame = el('game-frame');
    const url = `../..${currentGame.entryUrl}${currentSessionId ? `?session=${encodeURIComponent(currentSessionId)}` : ''}`;
    frame.src = url;
    el('play-container').classList.remove('hidden');
  }

  function stopGame() {
    const frame = el('game-frame');
    frame.src = 'about:blank';
    currentSessionId = '';
    el('play-container').classList.add('hidden');
  }

  window.addEventListener('message', async (event) => {
    const frame = el('game-frame');
    if (!frame || event.source !== frame.contentWindow) return;
    const msg = event.data;
    if (!msg || typeof msg !== 'object' || msg.protocol !== 1) return;

    if (msg.type === 'arcade:score' && currentGame) {
      const score = Number(msg.score);
      const durationMs = Number(msg.durationMs || 0);
      const statusEl = el('session-status');
      if (!activeDid || !arcadeOptedIn || !currentSessionId) {
        statusEl.textContent = `Game Over! Score: ${score} (Sign in & opt in to save scores)`;
        return;
      }
      try {
        const res = await fetch(`${API_BASE}/games/${encodeURIComponent(currentGame.slug)}/scores`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Hub-Csrf': csrfToken,
          },
          body: JSON.stringify({
            sessionId: currentSessionId,
            score: Math.floor(score),
            durationMs: Math.max(0, Math.floor(durationMs)),
            meta: msg.meta && typeof msg.meta === 'object' ? msg.meta : undefined,
          }),
        });
        const out = await res.json();
        if (res.ok && out.accepted) {
          statusEl.textContent = `Score ${score} saved! Rank #${out.rank}${out.isPersonalBest ? ' (New Personal Best!)' : ''}`;
          statusEl.className = 'status-pill status-ok';
          const lbRes = await fetch(`${API_BASE}/games/${encodeURIComponent(currentGame.slug)}/scores`);
          if (lbRes.ok) {
            const lb = await lbRes.json();
            renderLeaderboard(lb.scores || []);
          }
        } else {
          statusEl.textContent = apiErrorText(res, out, 'Score could not be saved.');
          statusEl.className = 'status-pill status-warn';
        }
      } catch {
        statusEl.textContent = 'Network error submitting score.';
        statusEl.className = 'status-pill status-warn';
      }
    }
  });

  async function openHistory() {
    if (!activeDid) return;
    const res = await fetch(`${API_BASE}/players/${encodeURIComponent(activeDid)}/history`);
    if (!res.ok) return;
    const data = await res.json();
    el('history-view').classList.remove('hidden');

    const bestBody = el('history-bests-body');
    bestBody.replaceChildren();
    (data.bestScores || []).forEach((b) => {
      const tr = make('tr');
      tr.appendChild(make('td', '', b.gameTitle));
      tr.appendChild(make('td', '', String(b.score)));
      tr.appendChild(make('td', '', b.achievedAt ? b.achievedAt.slice(0, 10) : ''));
      bestBody.appendChild(tr);
    });

    const playsBody = el('history-plays-body');
    playsBody.replaceChildren();
    (data.recentPlays || []).forEach((p) => {
      const tr = make('tr');
      tr.appendChild(make('td', '', p.gameTitle));
      tr.appendChild(make('td', '', String(p.score)));
      tr.appendChild(make('td', '', `${Math.round((p.durationMs || 0) / 1000)}s`));
      tr.appendChild(make('td', '', p.finishedAt ? p.finishedAt.slice(0, 16).replace('T', ' ') : ''));
      playsBody.appendChild(tr);
    });
  }

  el('auth-signin-btn').addEventListener('click', openSignIn);
  el('arcade-auth-cancel').addEventListener('click', () => {
    stopAuthPoll();
    el('arcade-auth-dialog').close();
  });
  el('arcade-auth-dialog').addEventListener('close', stopAuthPoll);
  el('optin-btn').addEventListener('click', () => setArcadeConsent(true));
  el('optout-btn').addEventListener('click', () => setArcadeConsent(false));
  el('auth-signout-btn').addEventListener('click', async () => {
    await fetch(`${HUB_API}/auth/logout`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Hub-Csrf': csrfToken },
      body: '{}',
    }).catch(() => {});
    await loadAuthSession();
  });
  el('did-history-btn').addEventListener('click', openHistory);
  el('history-close-btn').addEventListener('click', () => el('history-view').classList.add('hidden'));
  el('search-input').addEventListener('input', loadCatalog);
  el('tag-select').addEventListener('change', loadCatalog);
  el('play-start-btn').addEventListener('click', startGame);
  el('play-stop-btn').addEventListener('click', stopGame);
  el('detail-close-btn').addEventListener('click', () => {
    stopGame();
    el('detail-view').classList.add('hidden');
    el('catalog-view').classList.remove('hidden');
    el('catalog-controls').classList.remove('hidden');
    loadCatalog();
  });

  loadHubInfo();
  loadAuthSession();
  loadCatalog();
})();
