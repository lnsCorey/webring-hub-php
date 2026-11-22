#!/usr/bin/env node
// Signs a self-service removal request for a Webring Hub. Needs only Node.js 18+ (no npm
// install). The helpers below mirror src/Verify.php, which checks the signature on the hub.
import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import { parseArgs } from 'node:util';

/** Mirrors Verify::normalizeSiteUrl: https by default, lowercase host, default port and trailing slash dropped. */
function normalizeSiteUrl(rawUrl) {
  const trimmed = String(rawUrl ?? '').trim();
  if (!trimmed) return '';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed) && !/^https?:\/\//i.test(trimmed)) return '';
  const withProto = /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
  const m = /^(https?):\/\/(?:([^@/?#]*)@)?(\[[^\]]+\]|[^/:?#]+)(?::(\d+))?([^?#]*)(\?[^#]*)?(#.*)?$/i.exec(withProto);
  if (!m) return '';
  const [, schemeRaw, userInfo, hostRaw, portRaw, pathRaw, query, fragment] = m;
  if (userInfo || query !== undefined || fragment !== undefined) return '';
  const scheme = schemeRaw.toLowerCase();
  const host = hostRaw.toLowerCase();
  let port = portRaw ? Number(portRaw) : null;
  if ((scheme === 'https' && port === 443) || (scheme === 'http' && port === 80)) port = null;
  const authority = port !== null ? `${host}:${port}` : host;
  const path = pathRaw || '/';
  return `${scheme}://${authority}${path}`.replace(/\/+$/, '');
}

/** PHP's rawurlencode (RFC 3986): also encodes ! ' ( ) *, which encodeURIComponent leaves alone. */
function rawUrlEncode(segment) {
  return encodeURIComponent(segment).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

/** Mirrors Verify::siteUrlToDidWeb. */
function siteUrlToDidWeb(siteUrl) {
  const normalized = normalizeSiteUrl(siteUrl);
  if (!normalized) return '';
  const m = /^https?:\/\/(\[[^\]]+\]|[^/:]+)(?::(\d+))?(\/.*)?$/i.exec(normalized);
  if (!m) return '';
  const host = m[1].toLowerCase() + (m[2] ? `%3A${Number(m[2])}` : '');
  const segments = (m[3] || '').split('/').filter((s) => s !== '').map(rawUrlEncode);
  return segments.length ? `did:web:${host}:${segments.join(':')}` : `did:web:${host}`;
}

/**
 * Mirrors Verify::canonicalizeJson: object keys sorted recursively, any `proof` key dropped.
 * Arrays (and objects inside them) keep their order, as on the PHP side.
 */
function canonicalizeJson(obj) {
  const sortObject = (value) => {
    const out = {};
    for (const key of Object.keys(value).sort()) {
      if (key === 'proof') continue;
      const v = value[key];
      out[key] = v && typeof v === 'object' && !Array.isArray(v) ? sortObject(v) : v;
    }
    return out;
  };
  return JSON.stringify(sortObject(obj));
}

/** Ed25519 over the canonical JSON, base64url without padding (Verify::verifyPayloadSignature). */
function signPayload(payload, privateKeyPem) {
  let key;
  try {
    key = crypto.createPrivateKey(privateKeyPem);
  } catch {
    fail('could not read the private key.');
  }
  if (key.asymmetricKeyType !== 'ed25519') fail('the private key is not an Ed25519 key.');
  return crypto.sign(null, Buffer.from(canonicalizeJson(payload), 'utf8'), key).toString('base64url');
}

const { values } = parseArgs({
  options: {
    'site-url': { type: 'string' },
    did: { type: 'string' },
    nonce: { type: 'string' },
    key: { type: 'string' },
    created: { type: 'string' },
    dir: { type: 'string' },
    curl: { type: 'boolean' },
    link: { type: 'boolean' },
    auth: { type: 'boolean' },
    challenge: { type: 'string' },
    'opt-in': { type: 'string' },
    'opt-out': { type: 'string' }
  }
});

function fail(message) {
  console.error(`sign-request: ${message}`);
  process.exit(1);
}

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

const siteUrl = normalizeSiteUrl(values['site-url'] || '');
if (!siteUrl) fail('--site-url is required (for example https://you.example).');

const did = values.did || siteUrlToDidWeb(siteUrl);
if (!did) fail('could not derive a did:web; pass --did explicitly.');

if (!values.key) fail('--key is required (a PKCS#8 Ed25519 private key PEM, or "-" for stdin).');
const privateKeyPem = values.key === '-' ? await readStdin() : await fs.readFile(values.key, 'utf8');
if (!privateKeyPem.includes('BEGIN PRIVATE KEY')) fail('the key file is not a PKCS#8 private key PEM.');

let nonce = values.nonce;
let hubUrl = '';
if (values.dir) {
  hubUrl = values.dir.replace(/\/+$/, '');
  if (hubUrl && !/^https?:\/\//i.test(hubUrl)) hubUrl = `https://${hubUrl}`;
}
if (!nonce && hubUrl) {
  const response = await fetch(`${hubUrl}/api/nonce`, { headers: { accept: 'application/json' } });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.nonce) fail(`could not get a nonce from ${hubUrl} (HTTP ${response.status}).`);
  nonce = data.nonce;
}
if (!nonce) fail('--nonce is required unless --dir is given (the hub hands one out).');
if (!hubUrl) fail('--dir <hubUrl> is required so the signed removal request names the target hub.');
const normalizedHubUrl = normalizeSiteUrl(hubUrl);

const createdAt = values.created || new Date().toISOString();

if (values.auth) {
  const challengeId = String(values.challenge || '').trim();
  const optInExtensions = String(values['opt-in'] || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .sort();
  const payload = {
    action: 'hub_auth',
    hubUrl: normalizedHubUrl,
    did,
    siteUrl,
    nonce,
    challengeId,
    optInExtensions,
    createdAt
  };
  const signature = signPayload(payload, privateKeyPem);
  const body = { did, siteUrl, hubUrl: normalizedHubUrl, nonce, challengeId, optInExtensions, createdAt, signature };
  if (values.curl) {
    console.log([
      `curl -X POST '${hubUrl}/api/auth/verify'`,
      `  -H 'content-type: application/json'`,
      `  --data '${JSON.stringify(body)}'`
    ].join(' \\\n'));
  } else {
    console.log(JSON.stringify(body, null, 2));
  }
  process.exit(0);
}

if (values['opt-out']) {
  const extensionId = String(values['opt-out']).trim();
  const payload = {
    action: 'ext_opt_out',
    hubUrl: normalizedHubUrl,
    did,
    siteUrl,
    extensionId,
    nonce,
    createdAt
  };
  const signature = signPayload(payload, privateKeyPem);
  const body = { did, siteUrl, hubUrl: normalizedHubUrl, nonce, createdAt, signature };
  if (values.curl) {
    console.log([
      `curl -X POST '${hubUrl}/api/auth/extensions/${encodeURIComponent(extensionId)}/opt-out'`,
      `  -H 'content-type: application/json'`,
      `  --data '${JSON.stringify(body)}'`
    ].join(' \\\n'));
  } else {
    console.log(JSON.stringify(body, null, 2));
  }
  process.exit(0);
}

const payload = { action: 'remove', siteUrl, did, hubUrl: normalizedHubUrl, nonce, createdAt };
const signature = signPayload(payload, privateKeyPem);
const body = { siteUrl, did, hubUrl: normalizedHubUrl, nonce, createdAt, signature };

if (values.curl && values.link) fail('choose one of --curl or --link.');

if (values.link) {
  if (!hubUrl) fail('--link needs --dir so it knows which hub to link to.');
  const encoded = Buffer.from(JSON.stringify(body), 'utf8').toString('base64url');
  const url = new URL('/remove', hubUrl);
  url.hash = `request=${encoded}`;
  console.log(url.href);
} else if (values.curl) {
  const base = hubUrl || '<hub-url>';
  const command = [
    `curl -X POST '${base}/api/entries/${encodeURIComponent(did)}/remove'`,
    `  -H 'content-type: application/json'`,
    `  --data '${JSON.stringify(body)}'`
  ].join(' \\\n');
  console.log(command);
} else {
  console.log(JSON.stringify(body, null, 2));
}
