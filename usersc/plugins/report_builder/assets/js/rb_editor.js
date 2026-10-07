/**
 * Report Builder — editor (list view + block editor with live preview).
 * Plain ES2017, no build step. SortableJS (bundled) adds drag-to-reorder;
 * ▲▼ buttons do the same if it's missing.
 *
 * All user/data text goes into the DOM via textContent / .value — never
 * innerHTML. The preview HTML comes from the server renderer (which escapes
 * everything) and is shown in a sandboxed iframe.
 */
(function () {
  'use strict';

  var root = document.getElementById('rbApp');
  if (!root) return;
  var API = root.dataset.api, PAGE = root.dataset.page, CSRF = root.dataset.csrf;
  var META = null;

  // ── tiny DOM helpers ──────────────────────────────────────────────────────

  function h(tag, props) {
    var e = document.createElement(tag);
    if (props) Object.keys(props).forEach(function (k) {
      var v = props[k];
      if (v == null || v === false) return;
      if (k === 'class') e.className = v;
      else if (k === 'style') e.style.cssText = v;
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
      else if (k === 'value') e.value = v;
      else if (k === 'checked' || k === 'disabled') e[k] = !!v;
      else e.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) append(e, arguments[i]);
    return e;
  }
  function append(e, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { append(e, x); }); return; }
    e.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  /** <select>. opts: [[value, label], ...]. multiple → value is an array. */
  function sel(opts, value, onChange, attrs) {
    attrs = attrs || {};
    var s = h('select', Object.assign({ class: 'rb-input' }, attrs));
    opts.forEach(function (o) { s.appendChild(h('option', { value: String(o[0]) }, o[1])); });
    if (attrs.multiple) {
      var vals = (value || []).map(String);
      Array.prototype.forEach.call(s.options, function (op) { op.selected = vals.indexOf(op.value) !== -1; });
    } else {
      s.value = value == null ? '' : String(value);
    }
    if (onChange) s.addEventListener('change', function () {
      onChange(attrs.multiple ? Array.prototype.map.call(s.selectedOptions, function (o) { return o.value; }) : s.value);
    });
    return s;
  }
  function inp(value, onInput, attrs) {
    var i = h('input', Object.assign({ class: 'rb-input', type: 'text' }, attrs || {}));
    i.value = value == null ? '' : value;
    i.addEventListener('input', function () { onInput(i.value); });
    return i;
  }
  function chk(label, value, onChange) {
    var c = h('input', { type: 'checkbox', checked: !!value });
    c.addEventListener('change', function () { onChange(c.checked); });
    return h('label', { class: 'rb-check' }, c, ' ', label);
  }
  function row(label) {
    var kids = Array.prototype.slice.call(arguments, 1);
    return h('div', { class: 'rb-row' }, label ? h('label', { class: 'rb-label' }, label) : null, h('div', { class: 'rb-row-body' }, kids));
  }
  function btn(text, onClick, cls, title) {
    return h('button', { type: 'button', class: 'rb-btn ' + (cls || ''), onclick: onClick, title: title || null }, text);
  }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function toast(msg, bad) {
    var t = h('div', { class: 'rb-toast' + (bad ? ' rb-toast-bad' : '') }, msg);
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('rb-toast-out'); }, bad ? 6000 : 3000);
    setTimeout(function () { t.remove(); }, bad ? 6600 : 3600);
  }

  function api(action, payload) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf', CSRF);
    fd.append('payload', JSON.stringify(payload || {}));
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var j;
        try { j = JSON.parse(t); } catch (e) { throw new Error('Unexpected server response: ' + t.replace(/<[^>]+>/g, ' ').trim().slice(0, 200)); }
        if (!j.ok) throw new Error(j.error || 'Request failed');
        return j;
      });
  }

  // ── labels ────────────────────────────────────────────────────────────────

  var BLOCK_TYPES = [
    ['header', 'Header', 'Title band at the top'],
    ['summary_tiles', 'Summary tiles', 'Big numbers from metrics'],
    ['table', 'Table', 'List rows, or totals by group'],
    ['grouped_table', 'Grouped table', 'One section per client, status…'],
    ['chart', 'Chart', 'Bar, column or line chart'],
    ['text', 'Text', 'Paragraph, callout or footer'],
    ['buttons', 'Buttons', 'Links back to the site'],
  ];
  var BLOCK_LABEL = {}; BLOCK_TYPES.forEach(function (b) { BLOCK_LABEL[b[0]] = b[1]; });
  var OP_LABEL = { eq: 'is', neq: 'is not', in: 'is any of', not_in: 'is none of', contains: 'contains', not_contains: "doesn't contain",
    starts_with: 'starts with', gt: 'is more than', gte: 'is at least', lt: 'is less than', lte: 'is at most', between: 'is between',
    is_null: 'is empty', not_null: 'is not empty' };
  var DATE_OP_LABEL = { eq: 'is on', gt: 'is after', gte: 'is on or after', lt: 'is before', lte: 'is on or before' };
  var AGG_LABEL = { count: 'Count of rows', count_distinct: 'Count distinct', sum: 'Total', avg: 'Average', min: 'Lowest / earliest', max: 'Highest / latest' };
  var RANGE_LABEL = { all_time: 'All time', today: 'Today', yesterday: 'Yesterday', last_7_days: 'Last 7 days', last_30_days: 'Last 30 days',
    this_week: 'This week', last_week: 'Last week', this_month: 'This month', last_month: 'Last month', this_year: 'This year',
    since_last_report: 'Since this report last sent', custom: 'Custom dates' };
  var COND_OPS = [['gt', '>'], ['gte', '≥'], ['eq', '='], ['neq', '≠'], ['lte', '≤'], ['lt', '<']];
  var DOW = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  function ds(key) { return META.datasets.filter(function (d) { return d.key === key; })[0] || null; }
  function field(dsKey, fkey) { var d = ds(dsKey); return d ? d.fields.filter(function (f) { return f.key === fkey; })[0] || null : null; }
  function fieldOpts(dsKey, pred) {
    var d = ds(dsKey); if (!d) return [];
    return d.fields.filter(pred || function () { return true; }).map(function (f) { return [f.key, f.label]; });
  }
  function isDate(f) { return f && (f.type === 'date' || f.type === 'datetime'); }

  // ═════════════════════════════════════════════════════════════════════════
  // List view
  // ═════════════════════════════════════════════════════════════════════════

  function showList() {
    root.textContent = '';
    var body = h('div', { class: 'rb-card-body' }, h('p', { class: 'rb-muted' }, 'Loading…'));
    var presetSel = sel(META.presets.map(function (p) { return [p.key, p.name]; }), META.presets[0] && META.presets[0].key);
    root.appendChild(h('div', { class: 'rb-page' },
      h('div', { class: 'rb-titlebar' },
        h('h1', null, 'Reports'),
        h('div', { class: 'rb-actions' },
          META.presets.length ? [presetSel, btn('Create from preset', function () {
            api('create_preset', { preset: presetSel.value }).then(function (r) { location.href = PAGE + '?id=' + r.id; }).catch(function (e) { toast(e.message, true); });
          })] : null,
          btn('+ New report', function () { location.href = PAGE + '?id=new'; }, 'rb-btn-primary'))),
      h('div', { class: 'rb-card' }, body)));

    api('list').then(function (r) {
      body.textContent = '';
      if (!r.reports.length) { body.appendChild(h('p', { class: 'rb-muted' }, 'No reports yet. Start from a preset or a new report.')); return; }
      var tb = h('tbody');
      r.reports.forEach(function (rep) {
        var act = function (action, confirmMsg, okMsg) {
          return function () {
            if (confirmMsg && !confirm(confirmMsg)) return;
            api(action, { id: rep.id }).then(function (res) {
              if (action === 'duplicate') { location.href = PAGE + '?id=' + res.id; return; }
              toast(res.message || okMsg); showList();
            }).catch(function (e) { toast(e.message, true); });
          };
        };
        tb.appendChild(h('tr', null,
          h('td', null, h('a', { href: PAGE + '?id=' + rep.id, class: 'rb-strong' }, rep.name), rep.description ? h('div', { class: 'rb-muted rb-small' }, rep.description) : null),
          h('td', { class: 'rb-small' }, rep.schedule),
          h('td', null, String(rep.recipients)),
          h('td', { class: 'rb-small' }, rep.last_sent_at || 'Never'),
          h('td', null, btn(rep.active ? 'Active' : 'Paused', act('toggle', null, rep.active ? 'Paused' : 'Activated'), rep.active ? 'rb-btn-on' : '')),
          h('td', { class: 'rb-nowrap' },
            h('a', { href: PAGE + '?id=' + rep.id, class: 'rb-btn' }, 'Edit'),
            btn('Copy', act('duplicate')),
            btn('Test to me', act('send_test', null, 'Test sent')),
            META.can_send ? btn('Send now', act('send_now', 'Send "' + rep.name + '" to all its recipients now?')) : null,
            btn('Delete', act('delete', 'Delete "' + rep.name + '" and its history?', 'Deleted'), 'rb-btn-danger'))));
      });
      body.appendChild(h('table', { class: 'rb-table' },
        h('thead', null, h('tr', null, ['Report', 'Schedule', 'Recipients', 'Last sent', 'Status', ''].map(function (t) { return h('th', null, t); }))), tb));
    }).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'rb-alert' }, e.message)); });
  }

  // ═════════════════════════════════════════════════════════════════════════
  // Editor
  // ═════════════════════════════════════════════════════════════════════════

  var S = null;          // editor state
  var uidSeq = 0;
  var tab = 'blocks';
  var openBlocks = {};   // _uid → expanded
  var leftEl, previewEl, statusEl;
  var previewTimer = null, previewSeq = 0, lastMetrics = {};

  function newBlock(type) {
    var d = META.datasets[0] || { key: '', default_fields: [], fields: [] };
    var b;
    switch (type) {
      case 'header': b = { type: 'header', eyebrow: '{brand}', title: '{report_name}', subtitle: '{date}' }; break;
      case 'summary_tiles': b = { type: 'summary_tiles', tiles: [] }; break;
      case 'table': b = { type: 'table', title: '', dataset: d.key, query: { fields: d.default_fields.slice() } }; break;
      case 'grouped_table':
        var g = d.fields.filter(function (f) { return f.groupable; })[0];
        b = { type: 'grouped_table', title: '', dataset: d.key, group_field: g ? g.key : '', query: { fields: d.default_fields.filter(function (k) { return !g || k !== g.key; }) } };
        break;
      case 'chart':
        b = { type: 'chart', chart_type: 'column', title: '', dataset: d.key, show_table: true,
              query: { group_by: d.default_date_field ? [{ field: d.default_date_field, bucket: 'day' }] : [], aggregates: [{ fn: 'count' }],
                       date_window: d.default_date_field ? { range: 'last_30_days' } : undefined } };
        if (!b.query.group_by.length) { var gf = d.fields.filter(function (f) { return f.groupable; })[0]; if (gf) b.query.group_by = [{ field: gf.key }]; }
        if (!b.query.date_window) delete b.query.date_window;
        break;
      case 'text': b = { type: 'text', style: 'normal', body: '' }; break;
      case 'buttons': b = { type: 'buttons', buttons: [{ label: 'Open dashboard', url: '', style: 'primary' }] }; break;
    }
    b._uid = ++uidSeq;
    return b;
  }

  function showEditor(id) {
    var start = id === 'new'
      ? Promise.resolve({
          report: { id: 0, name: 'New report', description: '', active: false, schedule_frequency: null, schedule_day_of_week: 1,
                    schedule_day_of_month: 1, schedule_hour: 6, scope_mode: 'creator', attach_csv: false },
          layout: { blocks: [newBlock('header')] }, recipients: [] })
      : api('load', { id: +id });
    start.then(function (d) {
      var layout = d.layout || {};
      layout.blocks = (layout.blocks || []).map(function (b) { b._uid = ++uidSeq; return b; });
      layout.metrics = layout.metrics && !Array.isArray(layout.metrics) ? layout.metrics : {};
      layout.report_filters = layout.report_filters || [];
      layout.subject = layout.subject || [];
      S = { id: d.report.id || 0, report: d.report, layout: layout, recipients: d.recipients || [], dirty: false };
      buildEditor();
    }).catch(function (e) { root.textContent = ''; root.appendChild(h('div', { class: 'rb-alert' }, e.message)); });
  }

  function payload() {
    return JSON.parse(JSON.stringify({ id: S.id, report: S.report, layout: S.layout, recipients: S.recipients }, function (k, v) {
      return k.charAt(0) === '_' ? undefined : v;   // drop editor-only keys like _uid
    }));
  }

  /** Something changed. structural=true re-renders the left panel. */
  function changed(structural) {
    S.dirty = true;
    updateStatus();
    if (structural) renderLeft();
    schedulePreview();
  }

  function updateStatus() {
    if (!statusEl) return;
    statusEl.textContent = S.dirty ? 'Unsaved changes' : (S.id ? 'Saved' : 'Not saved yet');
    statusEl.className = 'rb-status' + (S.dirty ? ' rb-status-dirty' : '');
  }

  function buildEditor() {
    root.textContent = '';
    statusEl = h('span', { class: 'rb-status' });
    var nameIn = inp(S.report.name, function (v) { S.report.name = v; changed(); }, { class: 'rb-input rb-name', placeholder: 'Report name', 'aria-label': 'Report name' });
    var descIn = inp(S.report.description, function (v) { S.report.description = v; changed(); }, { placeholder: 'Description (optional)', 'aria-label': 'Description' });

    var saveBtn = btn('Save', save, 'rb-btn-primary');
    var testBtn = btn('Test to me', function () {
      if (!S.id || S.dirty) { toast('Save first — the test sends the saved version.', true); return; }
      api('send_test', { id: S.id }).then(function (r) { toast(r.message); }).catch(function (e) { toast(e.message, true); });
    });

    leftEl = h('div', { class: 'rb-left' });
    previewEl = h('div', { class: 'rb-preview' });
    root.appendChild(h('div', { class: 'rb-page' },
      h('div', { class: 'rb-titlebar' },
        h('div', { class: 'rb-titlebar-main' }, h('a', { href: PAGE, class: 'rb-back' }, '← Reports'), nameIn, descIn),
        h('div', { class: 'rb-actions' }, statusEl, testBtn, saveBtn)),
      h('div', { class: 'rb-split' }, leftEl, previewEl)));
    updateStatus();
    renderLeft();
    refreshPreview();

    window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); } });
  }

  function save() {
    api('save', payload()).then(function (r) {
      var wasNew = !S.id;
      S.id = r.id; S.report.id = r.id; S.dirty = false; updateStatus();
      (r.warnings || []).forEach(function (w) { toast(w, true); });
      toast('Saved');
      if (wasNew && history.replaceState) history.replaceState(null, '', PAGE + '?id=' + r.id);
    }).catch(function (e) { toast(e.message, true); });
  }

  // ── preview ───────────────────────────────────────────────────────────────

  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(refreshPreview, 700);
  }

  function refreshPreview() {
    var seq = ++previewSeq;
    previewEl.classList.add('rb-preview-busy');
    api('preview', payload()).then(function (r) {
      if (seq !== previewSeq) return;  // a newer preview is on its way
      lastMetrics = r.metrics || {};
      previewEl.classList.remove('rb-preview-busy');
      previewEl.textContent = '';
      var frame = h('iframe', { sandbox: '', class: 'rb-frame', title: 'Report preview' });
      frame.srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:12px;background:#f1f5f9;">' + r.html;
      previewEl.appendChild(h('div', { class: 'rb-preview-meta' },
        h('div', null, h('strong', null, 'Subject: '), h('span', { 'data-rb': 'subject' }, r.subject)),
        h('div', { class: 'rb-muted rb-small' }, r.row_count + ' row(s)' + (r.attachments.length ? ' · CSV: ' + r.attachments.join(', ') + (S.report.attach_csv ? '' : ' (attachments off)') : ''),
          ' · ', btn('Refresh', refreshPreview, 'rb-btn-link'))));
      previewEl.appendChild(frame);
      if (tab === 'data') renderLeft();  // show fresh metric values
    }).catch(function (e) {
      if (seq !== previewSeq) return;
      previewEl.classList.remove('rb-preview-busy');
      var old = previewEl.querySelector('.rb-alert');
      if (old) old.remove();
      previewEl.insertBefore(h('div', { class: 'rb-alert', 'data-rb': 'preview-error' }, e.message), previewEl.firstChild);
    });
  }

  // ── left panel ────────────────────────────────────────────────────────────

  function renderLeft() {
    var scroll = leftEl.scrollTop;
    leftEl.textContent = '';
    var tabs = [['blocks', 'Blocks'], ['data', 'Metrics & filters'], ['delivery', 'Delivery'], ['advanced', 'Advanced']];
    leftEl.appendChild(h('div', { class: 'rb-tabs', role: 'tablist' }, tabs.map(function (t) {
      return h('button', { type: 'button', role: 'tab', class: 'rb-tab' + (tab === t[0] ? ' rb-tab-on' : ''), 'aria-selected': tab === t[0] ? 'true' : 'false',
        onclick: function () { tab = t[0]; renderLeft(); } }, t[1]);
    })));
    var panel = h('div', { class: 'rb-panel' });
    leftEl.appendChild(panel);
    ({ blocks: renderBlocks, data: renderData, delivery: renderDelivery, advanced: renderAdvanced })[tab](panel);
    leftEl.scrollTop = scroll;
  }

  // Blocks tab ──────────────────────────────────────────────────────────────

  function renderBlocks(panel) {
    panel.appendChild(h('div', { class: 'rb-palette' },
      h('div', { class: 'rb-muted rb-small' }, 'Add a block:'),
      BLOCK_TYPES.map(function (t) {
        return btn('+ ' + t[1], function () {
          var b = newBlock(t[0]);
          S.layout.blocks.push(b);
          openBlocks[b._uid] = true;
          changed(true);
        }, 'rb-btn-add', t[2]);
      })));

    var list = h('div', { class: 'rb-blocks' });
    panel.appendChild(list);
    if (!S.layout.blocks.length) list.appendChild(h('p', { class: 'rb-muted' }, 'No blocks yet — add one above.'));

    S.layout.blocks.forEach(function (b, i) {
      var open = !!openBlocks[b._uid];
      var move = function (d) { return function () {
        var j = i + d; if (j < 0 || j >= S.layout.blocks.length) return;
        S.layout.blocks.splice(j, 0, S.layout.blocks.splice(i, 1)[0]); changed(true);
      }; };
      var card = h('div', { class: 'rb-block' + (open ? ' rb-block-open' : ''), 'data-uid': b._uid, 'data-type': b.type },
        h('div', { class: 'rb-block-head' },
          h('span', { class: 'rb-handle', title: 'Drag to reorder', 'aria-hidden': 'true' }, '⠿'),
          h('button', { type: 'button', class: 'rb-block-title', 'aria-expanded': open ? 'true' : 'false',
            onclick: function () { openBlocks[b._uid] = !open; renderLeft(); } },
            h('strong', null, BLOCK_LABEL[b.type] || b.type), ' ', h('span', { class: 'rb-muted rb-small' }, blockSummary(b)),
            b.show_if ? h('span', { class: 'rb-badge', title: 'Only shown when a condition is met' }, 'if') : null),
          h('span', { class: 'rb-block-tools' },
            btn('▲', move(-1), 'rb-icon', 'Move up'), btn('▼', move(1), 'rb-icon', 'Move down'),
            btn('⧉', function () { var c = clone(b); c._uid = ++uidSeq; S.layout.blocks.splice(i + 1, 0, c); changed(true); }, 'rb-icon', 'Duplicate'),
            btn('✕', function () { if (confirm('Remove this ' + (BLOCK_LABEL[b.type] || 'block') + '?')) { S.layout.blocks.splice(i, 1); changed(true); } }, 'rb-icon rb-icon-danger', 'Remove'))),
        open ? h('div', { class: 'rb-block-body' }, blockEditor(b), showIfEditor(b)) : null);
      list.appendChild(card);
    });

    if (window.Sortable && S.layout.blocks.length > 1) {
      window.Sortable.create(list, { handle: '.rb-handle', animation: 150, onEnd: function (ev) {
        if (ev.oldIndex === ev.newIndex) return;
        S.layout.blocks.splice(ev.newIndex, 0, S.layout.blocks.splice(ev.oldIndex, 1)[0]);
        changed(true);
      } });
    }
  }

  function blockSummary(b) {
    switch (b.type) {
      case 'header': return b.title || '';
      case 'text': return (b.style && b.style !== 'normal' ? '[' + b.style + '] ' : '') + (b.body || '').slice(0, 40);
      case 'summary_tiles': return (b.tiles || []).map(function (t) { return t.label; }).join(' · ');
      case 'buttons': return (b.buttons || []).map(function (x) { return x.label; }).join(' · ');
      case 'chart':
        var cg = (b.query && b.query.group_by) || [];
        var cx = cg[0] && field(b.dataset, typeof cg[0] === 'string' ? cg[0] : cg[0].field);
        return (b.title ? b.title + ' — ' : '') + (b.chart_type || 'column') + (cx ? ' by ' + cx.label : '');
      case 'table': case 'grouped_table':
        var d = ds(b.dataset);
        return (b.title || '') + (d ? ' — ' + d.label : '') + (b.type === 'grouped_table' && b.group_field ? ' by ' + ((field(b.dataset, b.group_field) || {}).label || b.group_field) : '');
    }
    return '';
  }

  function blockEditor(b) {
    var c = function () { changed(); };
    switch (b.type) {
      case 'header':
        return [
          row('Small heading', inp(b.eyebrow, function (v) { b.eyebrow = v; c(); })),
          row('Title', inp(b.title, function (v) { b.title = v; c(); })),
          row('Subtitle', inp(b.subtitle, function (v) { b.subtitle = v; c(); })),
          tokenHint()];
      case 'text':
        return [
          row('Style', sel(META.text_styles.map(function (s) { return [s, s.charAt(0).toUpperCase() + s.slice(1)]; }), b.style || 'normal', function (v) { b.style = v; changed(true); })),
          row('Text', h('textarea', { class: 'rb-input', rows: 3, oninput: function (e) { b.body = e.target.value; c(); } }, b.body || '')),
          tokenHint()];
      case 'buttons': return buttonsEditor(b);
      case 'summary_tiles': return tilesEditor(b);
      case 'table': case 'grouped_table': return tableEditor(b);
      case 'chart': return chartEditor(b);
    }
    return null;
  }

  function tokenHint() {
    var names = Object.keys(S.layout.metrics).map(function (k) { return '{' + k + '}'; });
    return h('div', { class: 'rb-hint' }, 'You can use {date} {date_short} {time} {report_name} {brand}' + (names.length ? ' and metrics ' + names.join(' ') + ' (add {s:name} for a plural "s")' : '') + '.');
  }

  function showIfEditor(b) {
    var keys = Object.keys(S.layout.metrics);
    var on = !!b.show_if;
    var box = h('div', { class: 'rb-showif' },
      chk('Only show when…', on, function (v) {
        if (v) {
          if (!keys.length) { toast('Add a metric first (Metrics & filters tab).', true); renderLeft(); return; }
          b.show_if = { metric: keys[0], op: 'gt', value: 0 };
        } else delete b.show_if;
        changed(true);
      }));
    if (on) box.appendChild(condEditor(b.show_if));
    return box;
  }

  function condEditor(cond) {
    return h('div', { class: 'rb-inline' },
      sel(Object.keys(S.layout.metrics).map(function (k) { return [k, k]; }), cond.metric, function (v) { cond.metric = v; changed(); }),
      sel(COND_OPS, cond.op || 'gt', function (v) { cond.op = v; changed(); }, { class: 'rb-input rb-narrow' }),
      inp(cond.value, function (v) { cond.value = v === '' ? 0 : +v; changed(); }, { type: 'number', class: 'rb-input rb-narrow' }));
  }

  function buttonsEditor(b) {
    b.buttons = b.buttons || [];
    return [
      b.buttons.map(function (x, i) {
        return h('div', { class: 'rb-subrow' },
          inp(x.label, function (v) { x.label = v; changed(); }, { placeholder: 'Label' }),
          inp(x.url, function (v) { x.url = v; changed(); }, { placeholder: 'usersc/page.php or https://…' }),
          sel([['primary', 'Filled'], ['secondary', 'Outline']], x.style || 'primary', function (v) { x.style = v; changed(); }, { class: 'rb-input rb-narrow' }),
          btn('✕', function () { b.buttons.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove'));
      }),
      btn('+ Button', function () { b.buttons.push({ label: 'Open', url: '', style: 'primary' }); changed(true); }, 'rb-btn-small'),
      h('div', { class: 'rb-hint' }, 'Site pages: a path like usersc/container_dashboard.php (the site address is added). Other sites: a full https:// link.')];
  }

  function tilesEditor(b) {
    b.tiles = b.tiles || [];
    var keys = Object.keys(S.layout.metrics);
    return [
      b.tiles.map(function (t, i) {
        var color = h('input', { type: 'color', class: 'rb-color', value: /^#[0-9a-f]{6}$/i.test(t.color || '') ? t.color : '#374151', title: 'Colour' });
        color.addEventListener('input', function () { t.color = color.value; changed(); });
        return h('div', { class: 'rb-subrow' },
          inp(t.label, function (v) { t.label = v; changed(); }, { placeholder: 'Label' }),
          sel([['', '— metric —']].concat(keys.map(function (k) { return [k, k + (k in lastMetrics ? ' (' + lastMetrics[k] + ')' : '')]; })), t.metric, function (v) { t.metric = v; changed(); }),
          color,
          btn('✕', function () { b.tiles.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove'));
      }),
      h('div', { class: 'rb-inline' },
        btn('+ Tile', function () { b.tiles.push({ label: 'New tile', metric: keys[0] || '', color: '#1e3a5f' }); changed(true); }, 'rb-btn-small'),
        btn('+ Tile with a new metric', function () {
          var k = addMetric();
          b.tiles.push({ label: 'New tile', metric: k, color: '#1e3a5f' });
          toast('Metric "' + k + '" added — set what it counts on the Metrics & filters tab.');
          changed(true);
        }, 'rb-btn-small')),
      keys.length ? null : h('div', { class: 'rb-hint' }, 'Tiles show metrics (named numbers). Create them on the Metrics & filters tab, or with the button above.')];
  }

  // Table / grouped table ─────────────────────────────────────────────────────

  function tableEditor(b) {
    b.query = b.query || {};
    var q = b.query;
    var totals = b.type === 'table' && ((q.group_by && q.group_by.length) || (q.aggregates && q.aggregates.length));
    var out = [];

    out.push(row('Heading', inp(b.title, function (v) { b.title = v; changed(); }, { placeholder: 'Optional' })));
    out.push(row('Data', sel(META.datasets.map(function (d) { return [d.key, d.label]; }), b.dataset, function (v) {
      var d = ds(v);
      b.dataset = v; b.query = { fields: d ? d.default_fields.slice() : [] }; b.labels = {};
      if (b.type === 'grouped_table') { var g = d && d.fields.filter(function (f) { return f.groupable; })[0]; b.group_field = g ? g.key : ''; }
      changed(true);
    })));
    if (!ds(b.dataset)) return out.concat(h('div', { class: 'rb-alert' }, 'This dataset is no longer registered.'));

    if (b.type === 'grouped_table') {
      out.push(row('Section for each', sel(fieldOpts(b.dataset, function (f) { return f.groupable; }), b.group_field, function (v) {
        b.group_field = v;
        q.fields = (q.fields || []).filter(function (k) { return k !== v; });
        changed(true);
      })));
    }

    if (b.type === 'table') {
      out.push(row('Show', sel([['rows', 'A row per record'], ['totals', 'Totals (counts, sums…)']], totals ? 'totals' : 'rows', function (v) {
        if (v === 'totals') { q.group_by = []; q.aggregates = [{ fn: 'count' }]; delete q.fields; }
        else { delete q.group_by; delete q.aggregates; q.fields = ds(b.dataset).default_fields.slice(); }
        q.sort = []; b.labels = {};
        changed(true);
      })));
    }

    if (totals) {
      out.push(section('Group by', groupByEditor(b)));
      out.push(section('Values', aggregatesEditor(b)));
    } else {
      out.push(section('Columns', columnsEditor(b)));
    }
    out.push(section('Filters', filterList(b.dataset, q.filters || (q.filters = []))));
    out.push(section('Date range', dateWindowEditor(b.dataset, q)));
    out.push(section('Sort', sortEditor(b, totals)));
    out.push(section('Options', [
      row('Max rows', inp(q.limit || '', function (v) { if (v === '') delete q.limit; else q.limit = +v; changed(); }, { type: 'number', min: 1, max: 10000, placeholder: '5000', class: 'rb-input rb-narrow' })),
      chk('Hide this block when there are no rows', b.hide_if_empty, function (v) { b.hide_if_empty = v; changed(true); }),
      b.hide_if_empty ? null : row('Text when empty', inp(b.empty_text, function (v) { b.empty_text = v; changed(); }, { placeholder: 'Nothing to show.' })),
      chk('Include in the CSV attachment', b.csv !== false, function (v) { if (v) delete b.csv; else b.csv = false; changed(); })]));
    return out;
  }

  // Chart ─────────────────────────────────────────────────────────────────────

  function chartEditor(b) {
    b.query = b.query || {};
    var q = b.query;
    q.group_by = (q.group_by || []).map(function (g) { return typeof g === 'string' ? { field: g } : g; });
    q.aggregates = q.aggregates && q.aggregates.length ? q.aggregates.slice(0, 1) : [{ fn: 'count' }];
    var a = q.aggregates[0];
    var groupable = function (f) { return f.groupable; };
    var x = q.group_by[0] || (q.group_by[0] = { field: (fieldOpts(b.dataset, groupable)[0] || [''])[0] });
    var xf = field(b.dataset, x.field);
    var split = q.group_by[1] || null;
    var out = [];

    out.push(row('Heading', inp(b.title, function (v) { b.title = v; changed(); }, { placeholder: 'Optional' })));
    out.push(row('Chart', sel([['column', 'Column (vertical bars)'], ['line', 'Line (trend over time)'], ['bar', 'Bar (horizontal, ranked)']], b.chart_type || 'column', function (v) {
      b.chart_type = v;
      if (v === 'bar') q.group_by = q.group_by.slice(0, 1);
      changed(true);
    })));
    out.push(row('Data', sel(META.datasets.map(function (d) { return [d.key, d.label]; }), b.dataset, function (v) {
      var d = ds(v), g = d && d.fields.filter(groupable)[0];
      b.dataset = v; b.labels = {};
      b.query = { group_by: g ? [{ field: g.key }] : [], aggregates: [{ fn: 'count' }] };
      changed(true);
    })));
    if (!ds(b.dataset)) return out.concat(h('div', { class: 'rb-alert' }, 'This dataset is no longer registered.'));

    out.push(row('Across the bottom (X axis)', h('div', { class: 'rb-inline' },
      sel(fieldOpts(b.dataset, groupable), x.field, function (v) { x.field = v; delete x.bucket; if (isDate(field(b.dataset, v))) x.bucket = 'day'; q.sort = []; changed(true); }, { 'aria-label': 'X axis field' }),
      isDate(xf) ? sel([['day', 'By day'], ['week', 'By week'], ['month', 'By month'], ['year', 'By year'], ['', 'Exact value']], x.bucket || '', function (v) { if (v) x.bucket = v; else delete x.bucket; q.sort = []; changed(true); }, { 'aria-label': 'Group dates' }) : null)));

    out.push(row('Value', h('div', { class: 'rb-inline' },
      sel(META.aggregates.map(function (fn) { return [fn, AGG_LABEL[fn] || fn]; }), a.fn, function (v) {
        a.fn = v; if (v === 'count') delete a.field; else if (!a.field) { var af = aggFields(b.dataset, v)[0]; a.field = af && af[0]; }
        q.sort = []; changed(true);
      }, { 'aria-label': 'Value' }),
      a.fn === 'count' ? null : sel(aggFields(b.dataset, a.fn), a.field, function (v) { a.field = v; q.sort = []; changed(true); }, { 'aria-label': 'Value field' }))));

    if (b.chart_type !== 'bar') {
      out.push(row('Split into lines/colours by', sel([['', '— nothing (one series) —']].concat(fieldOpts(b.dataset, function (f) { return f.groupable && f.key !== x.field; })), split ? split.field : '', function (v) {
        if (v) q.group_by[1] = { field: v }; else q.group_by = q.group_by.slice(0, 1);
        changed(true);
      }, { 'aria-label': 'Split by' })));
      if (split) out.push(h('div', { class: 'rb-hint' }, 'Up to 6 series are drawn; smaller ones are grouped as "Other".'));
    }

    out.push(section('Filters', filterList(b.dataset, q.filters || (q.filters = []))));
    out.push(section('Date range', dateWindowEditor(b.dataset, q)));
    out.push(section('Sort', [h('div', { class: 'rb-hint' }, isDate(xf) ? 'Dates run left to right automatically.' : 'Default: biggest first.'), sortEditor(b, true)]));
    out.push(section('Options', [
      b.chart_type === 'bar' ? null : row('Height', sel([[200, 'Short'], [280, 'Medium'], [360, 'Tall']], b.height || 280, function (v) { b.height = +v; changed(); })),
      chk('Show the numbers in a table under the chart', b.show_table !== false, function (v) { b.show_table = v; changed(); }),
      chk('Hide this block when there is no data', b.hide_if_empty, function (v) { b.hide_if_empty = v; changed(true); }),
      chk('Include in the CSV attachment', b.csv !== false, function (v) { if (v) delete b.csv; else b.csv = false; changed(); })]));
    return out;
  }

  function section(title, body) {
    return h('div', { class: 'rb-section' }, h('div', { class: 'rb-section-title' }, title), body);
  }

  function columnsEditor(b) {
    var q = b.query;
    q.fields = q.fields || [];
    b.labels = b.labels || {};
    var shown = q.fields.filter(function (k) { return field(b.dataset, k) && k !== b.group_field; });
    var list = h('div', { class: 'rb-cols' });
    shown.forEach(function (k, i) {
      var f = field(b.dataset, k);
      list.appendChild(h('div', { class: 'rb-col', 'data-key': k },
        h('span', { class: 'rb-handle', 'aria-hidden': 'true' }, '⠿'),
        h('span', { class: 'rb-col-name' }, f.label),
        inp(b.labels[k], function (v) { if (v) b.labels[k] = v; else delete b.labels[k]; changed(); }, { placeholder: 'Heading: ' + f.label, class: 'rb-input rb-col-label', 'aria-label': 'Column heading for ' + f.label }),
        btn('▲', function () { if (i) { q.fields.splice(q.fields.indexOf(shown[i - 1]), 0, q.fields.splice(q.fields.indexOf(k), 1)[0]); changed(true); } }, 'rb-icon', 'Move left'),
        btn('✕', function () { q.fields.splice(q.fields.indexOf(k), 1); delete b.labels[k]; changed(true); }, 'rb-icon rb-icon-danger', 'Remove column')));
    });
    if (window.Sortable && shown.length > 1) {
      window.Sortable.create(list, { handle: '.rb-handle', animation: 120, onEnd: function () {
        q.fields = Array.prototype.map.call(list.children, function (c) { return c.dataset.key; });
        changed(true);
      } });
    }
    var remaining = fieldOpts(b.dataset, function (f) { return shown.indexOf(f.key) === -1 && f.key !== b.group_field; });
    return [shown.length ? list : h('p', { class: 'rb-muted rb-small' }, 'No columns — the dataset defaults will be used.'),
      remaining.length ? sel([['', '+ Add column…']].concat(remaining), '', function (v) { if (v) { q.fields.push(v); changed(true); } }, { 'aria-label': 'Add column' }) : null];
  }

  function groupByEditor(b) {
    var q = b.query;
    q.group_by = q.group_by || [];
    return [
      q.group_by.map(function (g, i) {
        if (typeof g === 'string') g = q.group_by[i] = { field: g };
        var f = field(b.dataset, g.field);
        return h('div', { class: 'rb-subrow' },
          sel(fieldOpts(b.dataset, function (x) { return x.groupable; }), g.field, function (v) { g.field = v; delete g.bucket; changed(true); }),
          isDate(f) ? sel([['', 'Exact value'], ['day', 'By day'], ['week', 'By week'], ['month', 'By month'], ['year', 'By year']], g.bucket || '', function (v) { if (v) g.bucket = v; else delete g.bucket; changed(true); }) : null,
          btn('✕', function () { q.group_by.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove'));
      }),
      btn('+ Group by', function () {
        var g = fieldOpts(b.dataset, function (x) { return x.groupable; })[0];
        if (g) { q.group_by.push({ field: g[0] }); changed(true); }
      }, 'rb-btn-small'),
      q.group_by.length ? null : h('div', { class: 'rb-hint' }, 'No grouping = one row of totals for everything.')];
  }

  function aggregatesEditor(b) {
    var q = b.query;
    q.aggregates = q.aggregates || [];
    return [
      q.aggregates.map(function (a, i) {
        return h('div', { class: 'rb-subrow' },
          sel(META.aggregates.map(function (fn) { return [fn, AGG_LABEL[fn] || fn]; }), a.fn, function (v) { a.fn = v; if (v === 'count') delete a.field; else if (!a.field) a.field = aggFields(b.dataset, v)[0] && aggFields(b.dataset, v)[0][0]; changed(true); }),
          a.fn === 'count' ? null : sel(aggFields(b.dataset, a.fn), a.field, function (v) { a.field = v; changed(true); }),
          btn('✕', function () { q.aggregates.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove'));
      }),
      btn('+ Value', function () { q.aggregates.push({ fn: 'count' }); changed(true); }, 'rb-btn-small')];
  }

  function aggFields(dsKey, fn) {
    return fieldOpts(dsKey, function (f) { return (fn === 'sum' || fn === 'avg') ? (f.aggregatable && f.type === 'number') : true; });
  }

  /** Output column keys, same naming as RbQuery (field, field__bucket, fn__field, count__all). */
  function outputColumns(b) {
    var q = b.query, cols = [];
    (q.group_by || []).forEach(function (g) {
      var f = field(b.dataset, g.field); if (!f) return;
      cols.push([g.bucket ? f.key + '__' + g.bucket : f.key, f.label + (g.bucket ? ' (' + g.bucket + ')' : '')]);
    });
    (q.aggregates || []).forEach(function (a) {
      if (a.fn === 'count' && !a.field) { cols.push(['count__all', 'Count']); return; }
      var f = field(b.dataset, a.field); if (f) cols.push([a.fn + '__' + f.key, (AGG_LABEL[a.fn] || a.fn) + ' ' + f.label]);
    });
    return cols;
  }

  function sortEditor(b, totals) {
    var q = b.query;
    q.sort = (q.sort || []).map(function (s) { return typeof s === 'string' ? { key: s, dir: 'asc' } : s; });
    var keys = totals ? outputColumns(b) : fieldOpts(b.dataset, function (f) { return f.sortable; });
    return [
      q.sort.map(function (s, i) {
        return h('div', { class: 'rb-subrow' },
          sel(keys, s.key || s.field, function (v) { s.key = v; delete s.field; changed(); }),
          sel([['asc', 'A→Z / low→high / oldest first'], ['desc', 'Z→A / high→low / newest first']], s.dir || 'asc', function (v) { s.dir = v; changed(); }),
          btn('✕', function () { q.sort.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove'));
      }),
      keys.length ? btn('+ Sort', function () { q.sort.push({ key: keys[0][0], dir: 'asc' }); changed(true); }, 'rb-btn-small') : null];
  }

  function dateWindowEditor(dsKey, q) {
    var dw = q.date_window || {};
    var dateFields = fieldOpts(dsKey, function (f) { return isDate(f) && f.filterable; });
    if (!dateFields.length) return h('p', { class: 'rb-muted rb-small' }, 'This data has no date fields.');
    var range = dw.range || 'all_time';
    var set = function (patch) {
      var n = Object.assign({}, q.date_window || {}, patch);
      if (!n.range || n.range === 'all_time') delete q.date_window; else q.date_window = n;
    };
    return [
      h('div', { class: 'rb-inline' },
        sel(META.date_ranges.map(function (r) { return [r, RANGE_LABEL[r] || r]; }), range, function (v) { set({ range: v }); changed(true); }),
        range === 'all_time' ? null : [h('span', { class: 'rb-muted' }, 'on'),
          sel(dateFields, dw.field || (ds(dsKey).default_date_field || dateFields[0][0]), function (v) { set({ field: v }); changed(); })]),
      range === 'custom' ? h('div', { class: 'rb-inline' },
        inp(dw.start, function (v) { set({ start: v }); changed(); }, { type: 'date', 'aria-label': 'Start date' }),
        h('span', { class: 'rb-muted' }, 'to'),
        inp(dw.end, function (v) { set({ end: v }); changed(); }, { type: 'date', 'aria-label': 'End date' })) : null];
  }

  // Filters (used by tables, metrics and report filters) ────────────────────

  function filterList(dsKey, filters) {
    var box = h('div', { class: 'rb-filters' });
    filters.forEach(function (flt, i) {
      box.appendChild(filterRow(dsKey, flt, function () { filters.splice(i, 1); changed(true); }));
    });
    var first = fieldOpts(dsKey, function (f) { return f.filterable; })[0];
    if (first) box.appendChild(btn('+ Filter', function () {
      var f = field(dsKey, first[0]);
      filters.push({ field: f.key, op: META.ops[f.type][0], value: '' });
      changed(true);
    }, 'rb-btn-small'));
    return box;
  }

  function filterRow(dsKey, flt, onRemove) {
    var f = field(dsKey, flt.field);
    var ops = f ? META.ops[f.type] : [];
    if (f && ops.indexOf(flt.op) === -1) flt.op = ops[0];
    var opLabel = function (op) { return (isDate(f) && DATE_OP_LABEL[op]) || OP_LABEL[op] || op; };
    return h('div', { class: 'rb-subrow rb-filter' },
      sel(fieldOpts(dsKey, function (x) { return x.filterable; }), flt.field, function (v) {
        var nf = field(dsKey, v);
        flt.field = v; flt.op = META.ops[nf.type][0]; flt.value = '';
        changed(true);
      }, { 'aria-label': 'Filter field' }),
      f ? sel(ops.map(function (o) { return [o, opLabel(o)]; }), flt.op, function (v) {
        var multi = function (o) { return o === 'in' || o === 'not_in'; };
        if (multi(v) !== multi(flt.op) || v === 'between' || flt.op === 'between') flt.value = (multi(v) || v === 'between') ? [] : '';
        flt.op = v;
        changed(true);
      }, { 'aria-label': 'Condition' }) : null,
      f ? valueEditor(f, flt) : h('span', { class: 'rb-alert' }, 'Unknown field'),
      btn('✕', onRemove, 'rb-icon rb-icon-danger', 'Remove filter'));
  }

  function valueEditor(f, flt) {
    var op = flt.op;
    if (op === 'is_null' || op === 'not_null') return null;
    var opts = f.options ? f.options.map(function (o) { return [o.value, o.label]; }) : null;
    var inputType = f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'datetime' ? 'datetime-local' : 'text';
    var toInput = function (v) { return f.type === 'datetime' && v ? String(v).replace(' ', 'T').slice(0, 16) : v; };
    var fromInput = function (v) { return f.type === 'datetime' && v ? v.replace('T', ' ') : v; };

    if (op === 'in' || op === 'not_in') {
      var vals = Array.isArray(flt.value) ? flt.value : (flt.value === '' || flt.value == null ? [] : [flt.value]);
      flt.value = vals;
      if (opts) return sel(opts, vals, function (v) { flt.value = v; changed(); }, { multiple: true, size: Math.min(6, opts.length), class: 'rb-input rb-multi', 'aria-label': 'Values' });
      return inp(vals.join(', '), function (v) { flt.value = v.split(',').map(function (s) { return s.trim(); }).filter(Boolean); changed(); }, { placeholder: 'a, b, c', 'aria-label': 'Values (comma separated)' });
    }
    if (op === 'between') {
      var pair = Array.isArray(flt.value) ? flt.value : ['', ''];
      flt.value = pair;
      return h('span', { class: 'rb-inline' },
        inp(toInput(pair[0]), function (v) { pair[0] = fromInput(v); changed(); }, { type: inputType, class: 'rb-input rb-narrow', 'aria-label': 'From' }),
        'and',
        inp(toInput(pair[1]), function (v) { pair[1] = fromInput(v); changed(); }, { type: inputType, class: 'rb-input rb-narrow', 'aria-label': 'To' }));
    }
    if (opts) return sel([['', '— choose —']].concat(opts), flt.value, function (v) { flt.value = v; changed(); }, { 'aria-label': 'Value' });
    if (f.type === 'bool') return sel([['1', 'Yes'], ['0', 'No']], flt.value === '' ? '1' : flt.value, function (v) { flt.value = v; changed(); });
    return inp(toInput(flt.value), function (v) { flt.value = fromInput(v); changed(); }, { type: inputType, 'aria-label': 'Value' });
  }

  // Metrics & report filters tab ─────────────────────────────────────────────

  function addMetric() {
    var n = Object.keys(S.layout.metrics).length + 1, k = 'metric_' + n;
    while (S.layout.metrics[k]) k = 'metric_' + (++n);
    S.layout.metrics[k] = { dataset: (META.datasets[0] || {}).key, fn: 'count', filters: [] };
    return k;
  }

  function renameMetric(oldKey, newKey) {
    var m = S.layout.metrics, out = {};
    Object.keys(m).forEach(function (k) { out[k === oldKey ? newKey : k] = m[k]; });
    S.layout.metrics = out;
    var fix = function (c) { if (c && c.metric === oldKey) c.metric = newKey; };
    var re = new RegExp('\\{(s:)?' + oldKey + '\\}', 'g');
    var txt = function (s) { return typeof s === 'string' ? s.replace(re, function (_, p) { return '{' + (p || '') + newKey + '}'; }) : s; };
    S.layout.blocks.forEach(function (b) {
      fix(b.show_if);
      (b.tiles || []).forEach(function (t) { if (t.metric === oldKey) t.metric = newKey; });
      ['title', 'subtitle', 'eyebrow', 'body', 'empty_text'].forEach(function (p) { if (p in b) b[p] = txt(b[p]); });
    });
    S.layout.subject.forEach(function (r) { fix(r.if); r.text = txt(r.text); });
  }

  function renderData(panel) {
    panel.appendChild(h('h3', { class: 'rb-h' }, 'Metrics'));
    panel.appendChild(h('p', { class: 'rb-hint' }, 'A metric is one number — e.g. "containers awaiting review". Use them in summary tiles, as {name} in any text, in the subject line, and to show or hide blocks.'));
    Object.keys(S.layout.metrics).forEach(function (k) {
      var m = S.layout.metrics[k];
      var nameIn = inp(k, function () {}, { class: 'rb-input rb-narrow', 'aria-label': 'Metric name' });
      nameIn.addEventListener('change', function () {
        var nk = nameIn.value.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^[^a-z]+/, '');
        if (!nk || nk === k) { nameIn.value = k; return; }
        if (S.layout.metrics[nk]) { toast('There is already a metric called ' + nk, true); nameIn.value = k; return; }
        renameMetric(k, nk); changed(true);
      });
      panel.appendChild(h('div', { class: 'rb-metric', 'data-metric': k },
        h('div', { class: 'rb-inline' },
          nameIn,
          h('span', { class: 'rb-metric-value', title: 'Value in the current preview' }, k in lastMetrics ? '= ' + lastMetrics[k] : ''),
          h('span', { class: 'rb-spacer' }),
          btn('✕', function () {
            if (!confirm('Delete metric "' + k + '"? Tiles or conditions using it will stop working.')) return;
            delete S.layout.metrics[k]; changed(true);
          }, 'rb-icon rb-icon-danger', 'Delete metric')),
        h('div', { class: 'rb-inline' },
          sel(META.aggregates.map(function (fn) { return [fn, AGG_LABEL[fn] || fn]; }), m.fn || 'count', function (v) {
            m.fn = v; if (v === 'count') delete m.field; else if (!m.field) { var a = aggFields(m.dataset, v)[0]; m.field = a && a[0]; }
            changed(true);
          }),
          (m.fn && m.fn !== 'count') ? sel(aggFields(m.dataset, m.fn), m.field, function (v) { m.field = v; changed(); }) : null,
          h('span', { class: 'rb-muted' }, 'from'),
          sel(META.datasets.map(function (d) { return [d.key, d.label]; }), m.dataset, function (v) { m.dataset = v; m.filters = []; delete m.field; m.fn = 'count'; changed(true); })),
        section('Where', filterList(m.dataset, m.filters || (m.filters = []))),
        section('Date range', dateWindowEditor(m.dataset, m))));
    });
    panel.appendChild(btn('+ Metric', function () { addMetric(); changed(true); }, 'rb-btn-small'));

    panel.appendChild(h('h3', { class: 'rb-h' }, 'Report filters'));
    panel.appendChild(h('p', { class: 'rb-hint' }, 'Applied to every table and metric that uses the same data — e.g. "Client is any of Acme, Beta" turns the whole report into a per-client report.'));
    S.layout.report_filters.forEach(function (rf, i) {
      panel.appendChild(h('div', { class: 'rb-metric' },
        h('div', { class: 'rb-inline' }, h('span', { class: 'rb-muted' }, 'Data:'),
          sel(META.datasets.map(function (d) { return [d.key, d.label]; }), rf.dataset, function (v) {
            var f = fieldOpts(v, function (x) { return x.filterable; })[0];
            S.layout.report_filters[i] = { dataset: v, field: f && f[0], op: 'eq', value: '' };
            changed(true);
          })),
        filterRow(rf.dataset, rf, function () { S.layout.report_filters.splice(i, 1); changed(true); })));
    });
    panel.appendChild(btn('+ Report filter', function () {
      var d = META.datasets[0];
      if (!d) return;
      // Default to a client pick-list if the dataset has one — the common case.
      var pick = d.fields.filter(function (f) { return f.filterable && f.type === 'enum' && /client|customer/i.test(f.key + f.label); })[0]
              || d.fields.filter(function (f) { return f.filterable; })[0];
      S.layout.report_filters.push({ dataset: d.key, field: pick.key, op: pick.options ? 'in' : META.ops[pick.type][0], value: pick.options ? [] : '' });
      changed(true);
    }, 'rb-btn-small'));
  }

  // Delivery tab ─────────────────────────────────────────────────────────────

  function renderDelivery(panel) {
    var r = S.report;
    panel.appendChild(h('h3', { class: 'rb-h' }, 'Schedule'));
    panel.appendChild(chk('Active — send automatically on this schedule', r.active, function (v) { r.active = v; changed(); }));
    panel.appendChild(row('How often', sel([['', 'Not scheduled (send by hand)'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly']], r.schedule_frequency || '', function (v) { r.schedule_frequency = v || null; changed(true); })));
    if (r.schedule_frequency === 'weekly') panel.appendChild(row('On', sel(DOW.map(function (d, i) { return [i, d]; }), r.schedule_day_of_week, function (v) { r.schedule_day_of_week = +v; changed(); })));
    if (r.schedule_frequency === 'monthly') {
      var days = []; for (var d = 1; d <= 31; d++) days.push([d, d + (d > 28 ? ' (or last day)' : '')]);
      panel.appendChild(row('On day', sel(days, r.schedule_day_of_month, function (v) { r.schedule_day_of_month = +v; changed(); })));
    }
    if (r.schedule_frequency) {
      var hours = []; for (var hr = 0; hr < 24; hr++) hours.push([hr, (hr % 12 || 12) + ':00 ' + (hr < 12 ? 'AM' : 'PM')]);
      panel.appendChild(row('At (server time)', sel(hours, r.schedule_hour, function (v) { r.schedule_hour = +v; changed(); })));
    }

    panel.appendChild(h('h3', { class: 'rb-h' }, 'Recipients'));
    panel.appendChild(h('p', { class: 'rb-hint' }, 'Anyone by email (no account needed), a specific user, or everyone with a permission level (looked up each time it sends). The note is just for you — why they get it.'));
    S.recipients.forEach(function (rc, i) {
      var who;
      if (rc.kind === 'user') who = sel([['', '— user —']].concat(META.users.map(function (u) { return [u.id, u.name + ' <' + u.email + '>']; })), rc.user_id, function (v) { rc.user_id = +v || null; changed(); });
      else if (rc.kind === 'permission') who = sel([['', '— permission level —']].concat(META.permissions.map(function (p) { return [p.id, p.name + ' (#' + p.id + ')']; })), rc.permission_id, function (v) { rc.permission_id = +v || null; changed(); });
      else who = inp(rc.email, function (v) { rc.email = v.trim(); changed(); }, { type: 'email', placeholder: 'name@company.com', 'aria-label': 'Email' });
      panel.appendChild(h('div', { class: 'rb-subrow rb-recipient' },
        sel([['email', 'Email'], ['user', 'User'], ['permission', 'Permission group']], rc.kind || 'email', function (v) { S.recipients[i] = { kind: v, note: rc.note }; changed(true); }, { class: 'rb-input rb-kind', 'aria-label': 'Recipient type' }),
        who,
        inp(rc.note, function (v) { rc.note = v; changed(); }, { placeholder: 'Note (why)', 'aria-label': 'Note' }),
        btn('✕', function () { S.recipients.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove recipient')));
    });
    panel.appendChild(btn('+ Recipient', function () { S.recipients.push({ kind: 'email', email: '', note: '' }); changed(true); }, 'rb-btn-small'));

    panel.appendChild(h('h3', { class: 'rb-h' }, 'Data access'));
    var scopes = [['creator', "Report creator's access"], ['recipient', 'Each recipient sees only what they can access (e.g. their warehouses)']];
    if (META.can_unscope || r.scope_mode === 'none') scopes.push(['none', 'No restriction — everything' + (META.can_unscope ? '' : ' (needs full access to save)')]);
    panel.appendChild(sel(scopes, r.scope_mode || 'creator', function (v) { r.scope_mode = v; changed(); }, { 'aria-label': 'Data access' }));
    panel.appendChild(chk('Attach a CSV of each table', r.attach_csv, function (v) { r.attach_csv = v; changed(); }));

    if (S.id) {
      var logBox = h('div', null, h('p', { class: 'rb-muted rb-small' }, 'Loading…'));
      panel.appendChild(h('h3', { class: 'rb-h' }, 'Recent runs'));
      panel.appendChild(logBox);
      api('run_log', { id: S.id }).then(function (res) {
        logBox.textContent = '';
        if (!res.log.length) { logBox.appendChild(h('p', { class: 'rb-muted rb-small' }, 'Not sent yet.')); return; }
        logBox.appendChild(h('table', { class: 'rb-table rb-small' }, h('tbody', null, res.log.map(function (l) {
          return h('tr', null, h('td', null, l.run_at), h('td', null, l.trigger_type), h('td', null, l.recipient_count + ' to'),
            h('td', { class: +l.success ? 'rb-ok' : 'rb-bad' }, +l.success ? 'OK' : (l.error_message || 'Failed')));
        }))));
      }).catch(function () { logBox.textContent = ''; });
      if (META.can_send) panel.appendChild(h('div', { class: 'rb-danger-zone' },
        btn('Send now to all recipients', function () {
          if (S.dirty) { toast('Save first — this sends the saved version.', true); return; }
          if (!confirm('Send "' + S.report.name + '" to all its recipients now?')) return;
          api('send_now', { id: S.id }).then(function (res) { toast(res.message); renderLeft(); }).catch(function (e) { toast(e.message, true); });
        })));
    }
  }

  // Advanced tab ─────────────────────────────────────────────────────────────

  function renderAdvanced(panel) {
    panel.appendChild(h('h3', { class: 'rb-h' }, 'Email subject'));
    panel.appendChild(h('p', { class: 'rb-hint' }, 'The first rule whose condition matches is used. Leave empty for "Report name — date".'));
    S.layout.subject.forEach(function (rule, i) {
      var has = !!rule.if;
      panel.appendChild(h('div', { class: 'rb-metric' },
        h('div', { class: 'rb-inline' },
          chk('Only when…', has, function (v) {
            var keys = Object.keys(S.layout.metrics);
            if (v && !keys.length) { toast('Add a metric first.', true); renderLeft(); return; }
            if (v) rule.if = { metric: keys[0], op: 'gt', value: 0 }; else delete rule.if;
            changed(true);
          }),
          has ? condEditor(rule.if) : h('span', { class: 'rb-muted rb-small' }, '(always)'),
          h('span', { class: 'rb-spacer' }),
          btn('✕', function () { S.layout.subject.splice(i, 1); changed(true); }, 'rb-icon rb-icon-danger', 'Remove rule')),
        inp(rule.text, function (v) { rule.text = v; changed(); }, { placeholder: 'Subject text — {date_short}, {metric}…', 'aria-label': 'Subject text' })));
    });
    panel.appendChild(btn('+ Subject rule', function () { S.layout.subject.push({ text: '{report_name} — {date_short}' }); changed(true); }, 'rb-btn-small'));

    panel.appendChild(h('h3', { class: 'rb-h' }, 'Layout JSON'));
    panel.appendChild(h('p', { class: 'rb-hint' }, 'The whole report layout. Edit and Apply to paste in or copy a report between sites.'));
    var ta = h('textarea', { class: 'rb-input rb-json', rows: 18, spellcheck: 'false', 'aria-label': 'Layout JSON' });
    ta.value = JSON.stringify(payload().layout, null, 2);
    panel.appendChild(ta);
    panel.appendChild(btn('Apply JSON', function () {
      var parsed;
      try { parsed = JSON.parse(ta.value); } catch (e) { toast('Not valid JSON: ' + e.message, true); return; }
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) { toast('The layout must be a JSON object.', true); return; }
      parsed.blocks = (parsed.blocks || []).map(function (b) { b._uid = ++uidSeq; return b; });
      parsed.metrics = parsed.metrics && !Array.isArray(parsed.metrics) ? parsed.metrics : {};
      parsed.report_filters = parsed.report_filters || [];
      parsed.subject = parsed.subject || [];
      S.layout = parsed;
      changed(true);
      toast('Applied — check the preview, then Save.');
    }, 'rb-btn-small'));
  }

  // ── boot ──────────────────────────────────────────────────────────────────

  api('meta').then(function (m) {
    META = m;
    var open = root.dataset.open;
    if (open) showEditor(open); else showList();
  }).catch(function (e) {
    root.textContent = '';
    root.appendChild(h('div', { class: 'rb-alert' }, e.message));
  });
})();
