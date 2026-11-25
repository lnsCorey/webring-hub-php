/**
 * Hub public DID Sign-In & "My Data & Privacy" controller.
 * Communicates strictly with same-origin `/api/auth/*` endpoints using HttpOnly cookies
 * and the CSRF token returned by `GET /api/auth/me`. All text uses `textContent`.
 */

const $ = (id) => document.getElementById(id);

const els = {
  signInBtn: $('hub-signin-btn'),
  userBar: $('hub-user-bar'),
  userName: $('hub-user-name'),
  myDataBtn: $('hub-mydata-btn'),
  signOutBtn: $('hub-signout-btn'),
  authDialog: $('auth-dialog'),
  authStatus: $('auth-status'),
  authLaunchLink: $('auth-launch-link'),
  authCliCmd: $('auth-cli-cmd'),
  authCancelBtn: $('auth-cancel-btn'),
  myDataDialog: $('mydata-dialog'),
  myDataDid: $('mydata-did'),
  myDataList: $('mydata-list'),
  myDataStatus: $('mydata-status'),
  myDataCloseBtn: $('mydata-close-btn'),
};

let authState = {
  authenticated: false,
  user: null,
  csrfToken: null,
  consents: [],
};

let pollTimer = null;

function stopPolling() {
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function setMsg(node, text, kind = '') {
  if (!node) return;
  node.textContent = text || '';
  node.className = `form-message${kind ? ` ${kind}` : ''}`;
}

async function fetchMe() {
  try {
    const res = await fetch('/api/auth/me', { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) return;
    const data = await res.json();
    authState = {
      authenticated: Boolean(data.authenticated),
      user: data.user || null,
      csrfToken: data.csrfToken || null,
      consents: Array.isArray(data.consents) ? data.consents : [],
    };
    renderHeader();
  } catch {
    // Non-fatal: visitor stays signed out
  }
  document.dispatchEvent(new CustomEvent('webring-hub-auth', { detail: getState() }));
}

function getState() {
  return {
    authenticated: authState.authenticated,
    user: authState.user,
    consents: authState.consents.slice(),
  };
}

async function setConsent(extensionId, optedIn) {
  const res = await fetch(`/api/auth/extensions/${encodeURIComponent(extensionId)}/consent`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'content-type': 'application/json',
      'x-hub-csrf': authState.csrfToken || '',
    },
    body: JSON.stringify({ optedIn: Boolean(optedIn) }),
  });
  const data = await res.json().catch(() => ({}));
  await fetchMe();
  if (!res.ok) throw new Error(data.message || 'Could not update consent.');
  return getState();
}

function renderHeader() {
  if (!els.signInBtn || !els.userBar) return;
  if (authState.authenticated && authState.user) {
    els.signInBtn.hidden = true;
    els.userBar.hidden = false;
    els.userName.textContent = authState.user.displayName || authState.user.did;
    els.userName.title = authState.user.did;
  } else {
    els.signInBtn.hidden = false;
    els.userBar.hidden = true;
    els.userName.textContent = '';
  }
}

async function openSignInDialog(requestedExt = '') {
  if (!els.authDialog) return;
  stopPolling();
  setMsg(els.authStatus, 'Creating sign-in challenge…');
  if (els.authLaunchLink) {
    els.authLaunchLink.href = '#';
    els.authLaunchLink.hidden = true;
  }
  if (els.authCliCmd) els.authCliCmd.textContent = '';
  els.authDialog.showModal();

  try {
    const res = await fetch('/api/auth/challenge', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(requestedExt ? { extensionId: requestedExt } : {}),
    });
    const ch = await res.json().catch(() => ({}));
    if (!res.ok || !ch.challengeId) {
      setMsg(els.authStatus, ch.message || 'Could not start sign-in.', 'error');
      return;
    }

    const params = new URLSearchParams({
      hub: ch.hubUrl,
      challenge: ch.challengeId,
      nonce: ch.nonce,
    });
    if (ch.requestedExt) params.set('ext', ch.requestedExt);
    const deepLink = `webring://hub-auth?${params.toString()}`;

    if (els.authLaunchLink) {
      els.authLaunchLink.href = deepLink;
      els.authLaunchLink.hidden = false;
    }
    if (els.authCliCmd) {
      els.authCliCmd.textContent =
        `node scripts/sign-request.js --auth --site-url https://YOUR-SITE --key private.pem --dir ${ch.hubUrl} --nonce ${ch.nonce} --challenge ${ch.challengeId} --curl`;
    }
    setMsg(els.authStatus, 'Click "Open Webring Studio" to approve with your Ed25519 key. Waiting for approval…');

    pollTimer = setInterval(async () => {
      try {
        const claimRes = await fetch('/api/auth/claim', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({
            challengeId: ch.challengeId,
            challengeSecret: ch.challengeSecret,
          }),
        });
        const claimData = await claimRes.json().catch(() => ({}));
        if (claimRes.ok && claimData.status === 'authenticated') {
          stopPolling();
          els.authDialog.close();
          await fetchMe();
        } else if (!claimRes.ok) {
          stopPolling();
          setMsg(els.authStatus, claimData.message || 'Sign-in challenge expired.', 'error');
        }
      } catch {
        // Retry on transient network hiccup
      }
    }, 1500);
  } catch {
    setMsg(els.authStatus, 'Could not reach the hub to start sign-in.', 'error');
  }
}

function renderMyDataList() {
  if (!els.myDataList) return;
  els.myDataList.replaceChildren();
  if (els.myDataDid && authState.user) {
    els.myDataDid.textContent = authState.user.did;
  }
  const list = authState.consents || [];
  if (list.length === 0) {
    const p = document.createElement('p');
    p.className = 'hint';
    p.textContent = 'No enabled extensions on this hub store user-specific data.';
    els.myDataList.append(p);
    return;
  }

  for (const item of list) {
    const row = document.createElement('div');
    row.className = 'mydata-row';

    const info = document.createElement('div');
    info.className = 'mydata-info';
    const title = document.createElement('strong');
    title.textContent = item.name || item.extensionId;
    const badge = document.createElement('span');
    badge.className = `status-pill ${item.optedIn ? 'status-pill-emerald' : 'status-pill-indigo'}`;
    badge.textContent = item.optedIn ? 'Opted in' : 'Not opted in';
    const head = document.createElement('div');
    head.className = 'mydata-head';
    head.append(title, badge);

    const desc = document.createElement('p');
    desc.className = 'hint';
    desc.textContent = item.summary || 'Stores data under your DID.';
    info.append(head, desc);

    const actions = document.createElement('div');
    actions.className = 'mydata-actions';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = item.optedIn ? 'hub-btn hub-btn-danger' : 'hub-btn';
    btn.textContent = item.optedIn ? 'Opt out & delete data' : 'Opt in';
    btn.addEventListener('click', async () => {
      if (item.optedIn && !window.confirm(`Opt out of ${item.name || item.extensionId} and permanently delete all your data stored by it?`)) {
        return;
      }
      btn.disabled = true;
      setMsg(els.myDataStatus, item.optedIn ? 'Opting out and deleting data…' : 'Saving opt-in…');
      try {
        await setConsent(item.extensionId, !item.optedIn);
        setMsg(
          els.myDataStatus,
          item.optedIn
            ? `Opted out of ${item.name || item.extensionId}. Your data has been deleted.`
            : `Opted in to ${item.name || item.extensionId}.`,
          'success'
        );
        renderMyDataList();
      } catch (err) {
        setMsg(els.myDataStatus, err instanceof Error ? err.message : 'Network error updating consent.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
    actions.append(btn);

    row.append(info, actions);
    els.myDataList.append(row);
  }
}

if (els.signInBtn) {
  els.signInBtn.addEventListener('click', () => openSignInDialog(''));
}

if (els.authCancelBtn && els.authDialog) {
  els.authCancelBtn.addEventListener('click', () => {
    stopPolling();
    els.authDialog.close();
  });
  els.authDialog.addEventListener('close', stopPolling);
}

if (els.myDataBtn && els.myDataDialog) {
  els.myDataBtn.addEventListener('click', async () => {
    setMsg(els.myDataStatus, '');
    await fetchMe();
    renderMyDataList();
    els.myDataDialog.showModal();
  });
}

if (els.myDataCloseBtn && els.myDataDialog) {
  els.myDataCloseBtn.addEventListener('click', () => els.myDataDialog.close());
}

if (els.signOutBtn) {
  els.signOutBtn.addEventListener('click', async () => {
    try {
      await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'content-type': 'application/json',
          'x-hub-csrf': authState.csrfToken || '',
        },
        body: '{}',
      });
    } finally {
      await fetchMe();
    }
  });
}

const ready = fetchMe();

/**
 * Small API for hub pages served by extensions (e.g. /ext/social/), so they reuse this sign-in
 * and consent flow instead of copying it. Listen for the `webring-hub-auth` event on `document`
 * to hear about sign-in, sign-out and consent changes.
 */
window.webringHubAuth = Object.freeze({
  ready: ready.then(getState),
  getState,
  refresh: () => fetchMe().then(getState),
  csrfToken: () => authState.csrfToken || '',
  openSignIn: (extensionId = '') => openSignInDialog(extensionId),
  setConsent,
});
