/**
 * Declarative admin panels for Webring Hub extensions.
 *
 * Pure tree-building helpers are exported so Node can test them with no DOM.
 * Every value coming from an extension route is rendered exclusively via
 * `textContent` — never `innerHTML` — and links are not rendered.
 */

const KIND_LABELS = {
  public: 'User-facing',
  admin: 'Admin',
  both: 'Both',
  headless: 'API only',
};

export function kindBadgeLabel(kind) {
  return KIND_LABELS[kind] || 'API only';
}

/**
 * Resolves an extension action/form path template such as `v1/admin/games/{slug}/enabled`
 * into `/api/ext/<extId>/v1/admin/games/<encoded>/enabled`.
 */
export function interpolateActionPath(extId, templatePath, values = {}) {
  const safeExt = encodeURIComponent(String(extId || ''));
  const clean = String(templatePath || '').replace(/^\/+/, '');
  const resolved = clean.replace(/\{([a-zA-Z][a-zA-Z0-9_-]*)\}/g, (_, key) => {
    const val = values && Object.prototype.hasOwnProperty.call(values, key) ? values[key] : '';
    return encodeURIComponent(String(val ?? ''));
  });
  return `/api/ext/${safeExt}/${resolved}`;
}

/**
 * Evaluates a row action's optional `when: { key, equals }` guard.
 */
export function matchesWhen(when, row) {
  if (!when) return true;
  if (!row || typeof row !== 'object') return false;
  return row[when.key] === when.equals;
}

/**
 * Formats one cell or stat value into plain text + display kind.
 */
export function formatCellValue(value, renderer = 'text') {
  if (value === null || value === undefined || value === '') {
    return { text: '—', renderer };
  }
  if (renderer === 'badge') {
    if (typeof value === 'boolean') {
      return { text: value ? 'Enabled' : 'Disabled', renderer: 'badge', badgeTone: value ? 'active' : 'stale' };
    }
    const str = String(value);
    const lower = str.toLowerCase();
    const tone = lower === 'active' || lower === 'enabled' || lower === 'rotated' || lower === 'first-seen'
      ? 'active'
      : lower === 'rejected' || lower === 'blocked' || lower === 'disabled'
        ? 'blocked'
        : 'stale';
    return { text: str, renderer: 'badge', badgeTone: tone };
  }
  if (renderer === 'time') {
    const d = new Date(String(value));
    if (!Number.isNaN(d.getTime())) {
      return {
        text: d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
        renderer: 'time',
      };
    }
    return { text: String(value), renderer: 'time' };
  }
  return { text: String(value), renderer };
}

/**
 * Pure descriptor-to-tree builder for a single panel (stats, table, or form).
 * Returns a plain JSON-serialisable node tree `{ tag, className, text, attrs, children, meta }`.
 * No HTML strings are ever produced.
 */
export function buildPanelTree(extId, panel, data = null, state = {}) {
  const children = [];

  if (state.loading) {
    children.push({ tag: 'p', className: 'admin-sub', text: 'Loading…' });
    return { tag: 'div', className: 'admin-ext-panel-body', children };
  }

  if (state.error) {
    children.push({ tag: 'p', className: 'admin-error', text: String(state.error) });
    return { tag: 'div', className: 'admin-ext-panel-body', children };
  }

  if (panel.type === 'stats') {
    const src = data && typeof data === 'object' ? data : {};
    const items = Array.isArray(panel.items) && panel.items.length
      ? panel.items
      : Object.keys(src).map((k) => ({ key: k, label: k, as: 'text' }));

    const dlChildren = [];
    for (const item of items) {
      const formatted = formatCellValue(src[item.key], item.as || 'text');
      const valNode = formatted.renderer === 'code'
        ? { tag: 'code', className: 'admin-mono', text: formatted.text }
        : formatted.renderer === 'badge'
          ? { tag: 'span', className: `admin-badge admin-badge-${formatted.badgeTone || 'stale'}`, text: formatted.text }
          : { tag: 'span', text: formatted.text };
      dlChildren.push({
        tag: 'div',
        className: 'admin-stat-card',
        children: [
          { tag: 'dt', className: 'admin-stat-label', text: String(item.label) },
          { tag: 'dd', className: 'admin-stat-value', children: [valNode] },
        ],
      });
    }
    children.push({ tag: 'dl', className: 'admin-stats-grid', children: dlChildren });
    return { tag: 'div', className: 'admin-ext-panel-body', children };
  }

  if (panel.type === 'table') {
    const itemsKey = panel.itemsKey || 'items';
    const rows = Array.isArray(data)
      ? data
      : (data && Array.isArray(data[itemsKey]) ? data[itemsKey] : []);

    if (rows.length === 0) {
      children.push({ tag: 'p', className: 'empty', text: String(panel.emptyMessage || 'No items.') });
      return { tag: 'div', className: 'admin-ext-panel-body', children };
    }

    const cols = Array.isArray(panel.columns) ? panel.columns : [];
    const actions = Array.isArray(panel.actions) ? panel.actions : [];

    const headCells = cols.map((c) => ({
      tag: 'th',
      attrs: { scope: 'col' },
      text: String(c.label),
    }));
    if (actions.length > 0) {
      headCells.push({ tag: 'th', attrs: { scope: 'col' }, text: 'Actions' });
    }

    const bodyRows = rows.map((row) => {
      const tds = cols.map((c) => {
        const formatted = formatCellValue(row ? row[c.key] : null, c.as || 'text');
        if (formatted.renderer === 'code') {
          return { tag: 'td', children: [{ tag: 'code', className: 'admin-mono', text: formatted.text }] };
        }
        if (formatted.renderer === 'badge') {
          return {
            tag: 'td',
            children: [{ tag: 'span', className: `admin-badge admin-badge-${formatted.badgeTone || 'stale'}`, text: formatted.text }],
          };
        }
        return { tag: 'td', text: formatted.text };
      });

      if (actions.length > 0) {
        const actionNodes = [];
        for (const act of actions) {
          if (!matchesWhen(act.when, row)) continue;
          const resolvedPath = interpolateActionPath(extId, act.path, row);
          actionNodes.push({
            tag: 'button',
            className: `admin-button admin-button-sm${act.style === 'danger' ? ' admin-button-danger' : ''}`,
            attrs: { type: 'button' },
            text: String(act.label),
            meta: {
              kind: 'rowAction',
              action: act,
              resolvedPath,
              row,
            },
          });
        }
        tds.push({ tag: 'td', className: 'admin-actions', children: actionNodes });
      }

      return { tag: 'tr', children: tds };
    });

    children.push({
      tag: 'div',
      className: 'admin-table-wrap',
      children: [
        {
          tag: 'table',
          className: 'admin-table',
          children: [
            { tag: 'thead', children: [{ tag: 'tr', children: headCells }] },
            { tag: 'tbody', children: bodyRows },
          ],
        },
      ],
    });
    return { tag: 'div', className: 'admin-ext-panel-body', children };
  }

  if (panel.type === 'form') {
    const fields = Array.isArray(panel.fields) ? panel.fields : [];
    const formChildren = [];
    for (const f of fields) {
      const inputId = `ext-panel-${extId}-${panel.id}-${f.name}`;
      formChildren.push({ tag: 'label', attrs: { for: inputId }, text: String(f.label) });
      const attrs = {
        id: inputId,
        name: String(f.name),
        type: 'text',
        autocomplete: 'off',
        spellcheck: 'false',
      };
      if (f.placeholder) attrs.placeholder = String(f.placeholder);
      if (f.required) attrs.required = 'required';
      formChildren.push({ tag: 'input', attrs });
    }
    formChildren.push({
      tag: 'button',
      className: panel.method === 'DELETE' ? 'admin-button admin-button-danger' : 'admin-button',
      attrs: { type: 'submit' },
      text: String(panel.submitLabel || 'Submit'),
    });

    children.push({
      tag: 'form',
      className: 'admin-ext-form',
      meta: { kind: 'panelForm', panel },
      children: formChildren,
    });
    return { tag: 'div', className: 'admin-ext-panel-body', children };
  }

  return { tag: 'div', className: 'admin-ext-panel-body', children };
}

/**
 * Materializes a pure tree into real DOM elements using `textContent` only.
 */
function materializeTree(node, handlers) {
  const el = document.createElement(node.tag);
  if (node.className) el.className = node.className;
  if (node.attrs) {
    for (const [k, v] of Object.entries(node.attrs)) {
      el.setAttribute(k, String(v));
    }
  }
  if (node.text !== undefined && node.text !== null) {
    el.textContent = String(node.text);
  }
  if (node.meta?.kind === 'rowAction' && handlers?.onRowAction) {
    el.addEventListener('click', () => handlers.onRowAction(node.meta, el));
  }
  if (node.meta?.kind === 'panelForm' && handlers?.onFormSubmit) {
    el.addEventListener('submit', (ev) => {
      ev.preventDefault();
      handlers.onFormSubmit(node.meta.panel, el);
    });
  }
  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      el.append(materializeTree(child, handlers));
    }
  }
  return el;
}

/**
 * Mounts declarative panels for a single extension into `containerEl`.
 * When `deferLoad` is true, panels render only when the returned `load()` function is called.
 */
export function mountSingleExtensionPanels(containerEl, ext, api, { deferLoad = false } = {}) {
  if (!containerEl) return { load: async () => {} };
  containerEl.replaceChildren();

  const panels = ext && ext.loaded && Array.isArray(ext.panels) ? ext.panels : [];
  if (panels.length === 0) {
    return { load: async () => {} };
  }

  const panelGrid = document.createElement('div');
  panelGrid.className = 'admin-ext-panel-stack';
  containerEl.append(panelGrid);

  const refreshers = [];
  const loaders = [];

  for (const panel of panels) {
    const card = document.createElement('section');
    card.className = 'panel admin-ext-panel';

    const head = document.createElement('div');
    head.className = 'admin-ext-panel-head';
    const h3 = document.createElement('h3');
    h3.textContent = panel.title;
    head.append(h3);

    const statusMsg = document.createElement('p');
    statusMsg.className = 'form-message';
    statusMsg.setAttribute('role', 'status');
    statusMsg.setAttribute('aria-live', 'polite');

    const bodySlot = document.createElement('div');

    const setPanelMsg = (text, kind = '') => {
      statusMsg.textContent = text || '';
      statusMsg.className = `form-message${kind ? ` ${kind}` : ''}`;
    };

    const renderBody = (data, st = {}) => {
      const tree = buildPanelTree(ext.id, panel, data, st);
      bodySlot.replaceChildren(
        materializeTree(tree, {
          onRowAction: async (meta, btn) => {
            const { action, resolvedPath } = meta;
            if (action.confirm && !window.confirm(action.confirm)) {
              return;
            }
            btn.disabled = true;
            setPanelMsg('Working…');
            try {
              const res = await api(resolvedPath, {
                method: action.method,
                body: action.body !== null && action.body !== undefined ? action.body : undefined,
              });
              const msg = (res && typeof res.message === 'string' && res.message) || `${action.label}: done.`;
              setPanelMsg(msg, 'success');
              await Promise.all(refreshers.map((fn) => fn()));
            } catch (err) {
              setPanelMsg(err.message || 'The action failed.', 'error');
            } finally {
              btn.disabled = false;
            }
          },
          onFormSubmit: async (formPanel, formEl) => {
            const formData = new FormData(formEl);
            const values = {};
            for (const f of formPanel.fields || []) {
              values[f.name] = String(formData.get(f.name) || '').trim();
            }
            if (formPanel.confirm && !window.confirm(formPanel.confirm)) {
              return;
            }
            const submitBtn = formEl.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            setPanelMsg('Submitting…');
            try {
              const resolvedPath = interpolateActionPath(ext.id, formPanel.path, values);
              // Any fields not consumed by `{param}` in the path are sent as the JSON body
              const body = {};
              for (const [k, v] of Object.entries(values)) {
                if (!formPanel.path.includes(`{${k}}`)) {
                  body[k] = v;
                }
              }
              const sendBody = Object.keys(body).length > 0 || formPanel.method === 'POST' ? body : undefined;
              const res = await api(resolvedPath, { method: formPanel.method, body: sendBody });
              const msg = (res && typeof res.message === 'string' && res.message) || 'Completed.';
              setPanelMsg(msg, 'success');
              formEl.reset();
              await Promise.all(refreshers.map((fn) => fn()));
            } catch (err) {
              setPanelMsg(err.message || 'Request failed.', 'error');
            } finally {
              if (submitBtn) submitBtn.disabled = false;
            }
          },
        })
      );
    };

    const loadPanelData = async () => {
      if (panel.type === 'form') {
        renderBody(null, {});
        return;
      }
      renderBody(null, { loading: true });
      try {
        const url = `/api/ext/${encodeURIComponent(ext.id)}/${panel.source}`;
        const data = await api(url);
        renderBody(data, {});
      } catch (err) {
        renderBody(null, { error: err.message || 'Could not load panel data.' });
      }
    };

    if (panel.type === 'stats' || panel.type === 'table') {
      const refreshBtn = document.createElement('button');
      refreshBtn.type = 'button';
      refreshBtn.className = 'admin-button admin-button-sm';
      refreshBtn.textContent = 'Refresh';
      refreshBtn.addEventListener('click', () => {
        setPanelMsg('');
        loadPanelData();
      });
      head.append(refreshBtn);
      refreshers.push(loadPanelData);
    }

    card.append(head);
    if (panel.description) {
      const desc = document.createElement('p');
      desc.className = 'admin-sub';
      desc.textContent = panel.description;
      card.append(desc);
    }
    card.append(bodySlot, statusMsg);
    panelGrid.append(card);
    loaders.push(loadPanelData);
  }

  let loaded = false;
  const load = async () => {
    if (loaded) return;
    loaded = true;
    await Promise.all(loaders.map((fn) => fn()));
  };

  if (!deferLoad) {
    load();
  }
  return { load };
}

/**
 * Mounts collapsible panel groups for all enabled extensions that declare `panels`.
 */
export function mountExtensionPanels(sectionEl, containerEl, extensions, api) {
  if (!sectionEl || !containerEl) return;
  containerEl.replaceChildren();

  const withPanels = (Array.isArray(extensions) ? extensions : []).filter(
    (ext) => ext.loaded && Array.isArray(ext.panels) && ext.panels.length > 0
  );

  if (withPanels.length === 0) {
    sectionEl.hidden = true;
    return;
  }

  sectionEl.hidden = false;

  for (const ext of withPanels) {
    const details = document.createElement('details');
    details.className = 'panel admin-ext-panel-group';
    details.open = true;

    const summary = document.createElement('summary');
    summary.className = 'admin-ext-panel-summary';
    const titleSpan = document.createElement('span');
    titleSpan.textContent = ext.name || ext.id;
    const idCode = document.createElement('code');
    idCode.textContent = ext.id;
    summary.append(titleSpan, ' ', idCode);
    details.append(summary);

    const slot = document.createElement('div');
    details.append(slot);
    mountSingleExtensionPanels(slot, ext, api);

    containerEl.append(details);
  }
}
