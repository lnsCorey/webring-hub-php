import assert from 'node:assert/strict';
import {
  kindBadgeLabel,
  interpolateActionPath,
  matchesWhen,
  buildPanelTree,
} from '../public/admin-panels.js';

function collectTexts(node, out = []) {
  if (!node) return out;
  if (typeof node.text === 'string') out.push(node.text);
  if (Array.isArray(node.children)) {
    for (const c of node.children) collectTexts(c, out);
  }
  return out;
}

function findNodes(node, pred, out = []) {
  if (!node) return out;
  if (pred(node)) out.push(node);
  if (Array.isArray(node.children)) {
    for (const c of node.children) findNodes(c, pred, out);
  }
  return out;
}

// 1. Kind labels
assert.equal(kindBadgeLabel('public'), 'User-facing');
assert.equal(kindBadgeLabel('admin'), 'Admin');
assert.equal(kindBadgeLabel('both'), 'Both');
assert.equal(kindBadgeLabel('headless'), 'API only');

// 2. Path interpolation encodes '/', '?', and ':' safely
const p = interpolateActionPath('arcade', 'v1/admin/players/{did}/scores', {
  did: 'did:web:evil.example/../../admin?x=1',
});
assert.equal(
  p,
  '/api/ext/arcade/v1/admin/players/did%3Aweb%3Aevil.example%2F..%2F..%2Fadmin%3Fx%3D1/scores'
);

// 3. Action `when` condition
assert.equal(matchesWhen({ key: 'enabled', equals: true }, { enabled: true }), true);
assert.equal(matchesWhen({ key: 'enabled', equals: true }, { enabled: false }), false);
assert.equal(matchesWhen(null, { enabled: false }), true);

// 4. Table panel: XSS payload ends up strictly as text, never markup; `when` shows/hides buttons
const tablePanel = {
  id: 'games',
  title: 'Games',
  type: 'table',
  source: 'v1/admin/games',
  itemsKey: 'games',
  columns: [
    { key: 'title', label: 'Game', as: 'text' },
    { key: 'slug', label: 'Slug', as: 'code' },
    { key: 'enabled', label: 'Status', as: 'badge' },
  ],
  actions: [
    {
      label: 'Disable',
      method: 'POST',
      path: 'v1/admin/games/{slug}/enabled',
      body: { enabled: false },
      when: { key: 'enabled', equals: true },
      confirm: 'Hide?',
    },
    {
      label: 'Enable',
      method: 'POST',
      path: 'v1/admin/games/{slug}/enabled',
      body: { enabled: true },
      when: { key: 'enabled', equals: false },
    },
  ],
};

const xss = '<img src=x onerror=alert(1)>';
const tree = buildPanelTree('arcade', tablePanel, {
  games: [
    { slug: 'vec/1', title: xss, enabled: true },
    { slug: 'star', title: 'Starfall', enabled: false },
  ],
});

const texts = collectTexts(tree);
assert.ok(texts.includes(xss), 'XSS value is preserved verbatim as text node');
assert.equal(findNodes(tree, (n) => n.tag === 'img').length, 0, 'No <img> tag is ever created');

const buttons = findNodes(tree, (n) => n.tag === 'button');
assert.equal(buttons.length, 2, 'Each row renders only the action matching its `when` condition');
assert.equal(buttons[0].text, 'Disable');
assert.equal(buttons[0].meta.resolvedPath, '/api/ext/arcade/v1/admin/games/vec%2F1/enabled');
assert.equal(buttons[1].text, 'Enable');

// 5. Empty and error states
const emptyTree = buildPanelTree('arcade', tablePanel, { games: [] });
assert.ok(collectTexts(emptyTree).includes('No items.'));

const errTree = buildPanelTree('arcade', tablePanel, null, { error: 'Forbidden' });
assert.ok(collectTexts(errTree).includes('Forbidden'));

// 6. Stats panel
const statsTree = buildPanelTree(
  'key-witness',
  {
    id: 'overview',
    title: 'Overview',
    type: 'stats',
    items: [
      { key: 'sites', label: 'Sites tracked' },
      { key: 'rejected', label: 'Rejected' },
    ],
  },
  { sites: 12, rejected: 2 }
);
const statTexts = collectTexts(statsTree);
assert.ok(statTexts.includes('Sites tracked') && statTexts.includes('12'));

// 7. Form panel
const formTree = buildPanelTree('arcade', {
  id: 'remove-player',
  title: 'Remove a player',
  type: 'form',
  method: 'DELETE',
  path: 'v1/admin/players/{did}',
  submitLabel: 'Remove player',
  fields: [{ name: 'did', label: 'Player DID', required: true }],
});
const inputs = findNodes(formTree, (n) => n.tag === 'input');
assert.equal(inputs.length, 1);
assert.equal(inputs[0].attrs.name, 'did');

console.log('admin-panels tests passed');
