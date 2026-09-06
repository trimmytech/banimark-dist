/* Banimark visual Tool Builder.
   Lets a non-technical owner describe a lookup by picking a table, ticking
   the columns the AI may see, and adding plain-language conditions - and
   writes the SELECT for them. The generated SQL lands in the normal textarea,
   still visible and still editable, and still goes through the same save-time
   validator: the builder is a convenience over the trust model, never a way
   around it. Parameters are unlimited and can be added/removed freely. */
(function () {
  'use strict';
  var root = document.querySelector('[data-toolbuilder]');
  if (!root) return;

  var schemaUrl = root.getAttribute('data-schema-url');
  var form = root.closest('form');
  var sqlBox = form.querySelector('[name=sql]');
  var colsBox = form.querySelector('[name=columns]');
  var ctxBox = form.querySelector('[name=context]');
  var paramsWrap = form.querySelector('[data-params]');
  var addParamBtn = form.querySelector('[data-add-param]');
  var schema = {};

  /* ---------- parameters: unlimited rows ---------- */
  function paramRow(p) {
    p = p || {};
    var row = document.createElement('div');
    row.className = 'row bm-param';
    row.style.marginBottom = '7px';
    row.innerHTML =
      '<input type="text" name="param_name[]" placeholder="name e.g. reference" style="flex:2" value="' + esc(p.name || '') + '">' +
      '<select name="param_type[]" style="flex:1">' +
        ['string','integer','number','boolean'].map(function (t) { return '<option' + (p.type === t ? ' selected' : '') + '>' + t + '</option>'; }).join('') +
      '</select>' +
      '<input type="text" name="param_desc[]" placeholder="what the AI should ask the customer for" style="flex:3" value="' + esc(p.desc || '') + '">' +
      '<label style="margin:0;white-space:nowrap"><input type="checkbox" name="param_required[]" value="1"' + (p.required ? ' checked' : '') + '> required</label>' +
      '<button type="button" class="btn-ghost btn-icon" title="Remove" data-remove-param>&times;</button>';
    // a hidden index keeps checkbox arrays aligned when some are unchecked
    var idx = document.createElement('input');
    idx.type = 'hidden'; idx.name = 'param_idx[]'; idx.value = String(paramsWrap.children.length);
    row.appendChild(idx);
    return row;
  }
  function reindexParams() {
    Array.prototype.forEach.call(paramsWrap.querySelectorAll('.bm-param'), function (r, i) {
      r.querySelector('[name="param_idx[]"]').value = String(i);
      var cb = r.querySelector('[name="param_required[]"]');
      cb.value = String(i); // the server reads which indexes are required
    });
    refreshParamOptions();
  }
  addParamBtn.addEventListener('click', function () { paramsWrap.appendChild(paramRow()); reindexParams(); });
  paramsWrap.addEventListener('click', function (e) {
    if (e.target.closest('[data-remove-param]')) { e.target.closest('.bm-param').remove(); reindexParams(); }
  });
  paramsWrap.addEventListener('input', function (e) { if (e.target.name === 'param_name[]') refreshParamOptions(); });
  // NOTE: the first row is added in init() at the bottom - reindexParams() reaches
  // into the builder's nodes, which are only looked up below. Calling it here threw
  // and killed the whole script before the schema was ever requested ("Loading…").

  /* ---------- visual builder ---------- */
  var tableSel = root.querySelector('[data-table]');
  var colsWrap = root.querySelector('[data-columns]');
  var condWrap = root.querySelector('[data-conditions]');
  var addCondBtn = root.querySelector('[data-add-condition]');
  var preview = root.querySelector('[data-preview]');
  var applyBtn = root.querySelector('[data-apply]');
  var status = root.querySelector('[data-status]');

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

  function loadSchema() {
    status.textContent = 'Reading your database…';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', schemaUrl, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      try { schema = JSON.parse(xhr.responseText).tables || {}; } catch (e) { schema = {}; }
      var names = Object.keys(schema);
      tableSel.innerHTML = '<option value="">Choose a table…</option>' + names.map(function (t) { return '<option>' + esc(t) + '</option>'; }).join('');
      status.textContent = names.length ? names.length + ' tables found' : 'No tables found (or no permission to list them)';
    };
    xhr.send();
  }

  function currentColumns() { return schema[tableSel.value] || []; }

  function renderColumns() {
    var cols = currentColumns();
    colsWrap.innerHTML = cols.length ? '' : '<span class="muted">Pick a table first.</span>';
    cols.forEach(function (c) {
      var id = 'col_' + c.name;
      colsWrap.insertAdjacentHTML('beforeend',
        '<label style="display:inline-flex;align-items:center;gap:6px;margin:4px 12px 4px 0;font-weight:500">' +
        '<input type="checkbox" data-col value="' + esc(c.name) + '"> ' + esc(c.name) +
        ' <span class="muted">' + esc(c.type) + '</span></label>');
    });
    condWrap.innerHTML = '';
    build();
  }

  function paramNames() {
    return Array.prototype.map.call(paramsWrap.querySelectorAll('[name="param_name[]"]'), function (i) { return i.value.trim(); })
      .filter(function (v) { return /^[a-z][a-z0-9_]*$/i.test(v); });
  }
  function identityKeys() {
    return (ctxBox.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }
  function refreshParamOptions() {
    Array.prototype.forEach.call(condWrap.querySelectorAll('[data-source]'), fillSource);
    build();
  }
  function fillSource(sel) {
    var keep = sel.value;
    var opts = '<optgroup label="Asked from the customer (AI parameter)">' +
      paramNames().map(function (p) { return '<option value=":' + esc(p) + '">' + esc(p) + '</option>'; }).join('') + '</optgroup>' +
      '<optgroup label="Who is chatting (signed identity, never editable by the AI)">' +
      identityKeys().map(function (k) { return '<option value=":_' + esc(k) + '">' + esc(k) + ' (identity)</option>'; }).join('') + '</optgroup>';
    sel.innerHTML = opts;
    if (keep) sel.value = keep;
  }

  function condRow() {
    var cols = currentColumns();
    var row = document.createElement('div');
    row.className = 'row bm-cond'; row.style.marginBottom = '7px';
    row.innerHTML =
      '<select data-ccol style="flex:2">' + cols.map(function (c) { return '<option>' + esc(c.name) + '</option>'; }).join('') + '</select>' +
      '<select data-op style="flex:1">' +
        '<option value="=">equals</option><option value="LIKE">contains</option><option value="<">less than</option><option value=">">greater than</option><option value="<=">at most</option><option value=">=">at least</option>' +
      '</select>' +
      '<select data-source style="flex:2"></select>' +
      '<button type="button" class="btn-ghost btn-icon" title="Remove" data-remove-cond>&times;</button>';
    fillSource(row.querySelector('[data-source]'));
    return row;
  }
  addCondBtn.addEventListener('click', function () {
    if (!tableSel.value) { status.textContent = 'Pick a table first.'; return; }
    condWrap.appendChild(condRow()); build();
  });
  condWrap.addEventListener('click', function (e) { if (e.target.closest('[data-remove-cond]')) { e.target.closest('.bm-cond').remove(); build(); } });
  condWrap.addEventListener('change', build);
  colsWrap.addEventListener('change', build);
  tableSel.addEventListener('change', renderColumns);
  ctxBox.addEventListener('input', refreshParamOptions);

  function build() {
    var table = tableSel.value;
    var cols = Array.prototype.filter.call(colsWrap.querySelectorAll('[data-col]'), function (c) { return c.checked; }).map(function (c) { return c.value; });
    if (!table || !cols.length) { preview.textContent = '-- pick a table and at least one column'; applyBtn.disabled = true; return; }
    var where = Array.prototype.map.call(condWrap.querySelectorAll('.bm-cond'), function (r) {
      var col = r.querySelector('[data-ccol]').value, op = r.querySelector('[data-op]').value, src = r.querySelector('[data-source]').value;
      if (!src) return null;
      // "contains" binds the parameter and wraps it in the SQL, never the value
      return op === 'LIKE' ? col + " LIKE CONCAT('%', " + src + ", '%')" : col + ' ' + op + ' ' + src;
    }).filter(Boolean);
    var sql = 'SELECT ' + cols.join(', ') + '\nFROM ' + table + (where.length ? '\nWHERE ' + where.join('\n  AND ') : '');
    preview.textContent = sql;
    applyBtn.disabled = false;
    root.__built = { sql: sql, cols: cols };
  }

  applyBtn.addEventListener('click', function () {
    if (!root.__built) return;
    sqlBox.value = root.__built.sql;
    colsBox.value = root.__built.cols.join(', ');
    sqlBox.focus();
    status.textContent = 'Applied - review the SQL below, then Validate & save.';
  });

  function init() {
    // editing an existing tool: the server hands the saved parameters over as JSON
    var pre = [];
    try { pre = JSON.parse(paramsWrap.getAttribute('data-prefill') || '[]'); } catch (e) { pre = []; }
    if (Array.isArray(pre) && pre.length) { pre.forEach(function (p) { paramsWrap.appendChild(paramRow(p)); }); }
    if (!paramsWrap.children.length) { paramsWrap.appendChild(paramRow()); }
    reindexParams();
    loadSchema();
  }
  init();
})();

/* ---- "Try it": run the definition in the form with sample values ----
 * The one place an owner learns, before a visitor does, that a tool needs a
 * signed-in visitor or has a typo in a column. Same validator, same binding,
 * same refusal as the live engine - we just show BOTH audiences' messages. */
(function () {
  'use strict';
  var box = document.querySelector('[data-tryit]');
  var form = document.querySelector('form[action$="/tools"], form[action*="/tools?"]') || (box && box.closest('form'));
  if (!box || !form) return;
  var argsWrap = box.querySelector('[data-try-args]'), ctxWrap = box.querySelector('[data-try-ctx]');
  var runBtn = box.querySelector('[data-try-run]'), status = box.querySelector('[data-try-status]'), out = box.querySelector('[data-try-out]');
  var sqlBox = form.querySelector('[name=sql]');
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function paramNames() {
    return Array.prototype.map.call(form.querySelectorAll('[name="param_name[]"]'), function (i) { return i.value.trim(); }).filter(Boolean);
  }
  function identityKeys() {
    var m = (sqlBox.value || '').match(/:_([a-zA-Z_][a-zA-Z0-9_]*)/g) || [];
    var seen = {}; return m.map(function (x) { return x.slice(2); }).filter(function (k) { if (seen[k]) return false; seen[k] = true; return true; });
  }
  function keep(wrap, sel) { var v = {}; Array.prototype.forEach.call(wrap.querySelectorAll(sel), function (i) { v[i.getAttribute('data-k')] = i.value; }); return v; }
  function refresh() {
    var oldA = keep(argsWrap, 'input'), oldC = keep(ctxWrap, 'input');
    var ps = paramNames(), ks = identityKeys();
    argsWrap.innerHTML = ps.length ? ps.map(function (p) {
      return '<div class="row" style="gap:8px;margin:4px 0"><code style="min-width:120px">' + esc(p) + '</code><input type="text" data-k="' + esc(p) + '" value="' + esc(oldA[p] || '') + '" placeholder="a sample value" style="margin:0"></div>';
    }).join('') : '<span class="muted">No parameters yet.</span>';
    ctxWrap.innerHTML = ks.length ? ks.map(function (k) {
      return '<div class="row" style="gap:8px;margin:4px 0"><code style="min-width:120px">' + esc(k) + '</code><input type="text" data-k="' + esc(k) + '" value="' + esc(oldC[k] || '') + '" placeholder="e.g. 1" style="margin:0"></div>';
    }).join('') + '<div class="hint">Leave a box empty to see what happens for a visitor who is NOT signed in.</div>'
      : '<span class="muted">This query needs no identity values - it works for anonymous visitors too.</span>';
  }
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();

  runBtn.addEventListener('click', function () {
    var fd = new FormData(form);
    Array.prototype.forEach.call(argsWrap.querySelectorAll('input'), function (i) { fd.append('args[' + i.getAttribute('data-k') + ']', i.value); });
    Array.prototype.forEach.call(ctxWrap.querySelectorAll('input'), function (i) { fd.append('context_values[' + i.getAttribute('data-k') + ']', i.value); });
    status.textContent = 'Running…'; runBtn.disabled = true; out.hidden = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', box.getAttribute('data-try-url'), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      runBtn.disabled = false; status.textContent = '';
      var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
      out.hidden = false;
      if (!d) { out.innerHTML = '<div class="flash-err">Could not run it (HTTP ' + xhr.status + ').</div>'; return; }
      if (!d.ok) {
        out.innerHTML = '<div class="flash-err" style="display:block"><b>The AI would be told:</b> ' + esc(d.error) +
          (d.diagnostic ? '<div style="margin-top:6px"><b>What staff would see in the thread:</b> ' + esc(d.diagnostic) + '</div>' : '') + '</div>';
        return;
      }
      if (!d.rows.length) { out.innerHTML = '<div class="flash-ok" style="display:block">It ran, and found nothing for those values (0 rows). The AI would tell the visitor there is no match.</div>'; return; }
      var cols = Object.keys(d.rows[0]);
      out.innerHTML = '<div class="flash-ok" style="display:block">It works - ' + d.count + ' row' + (d.count === 1 ? '' : 's') + '. This is what the AI would read:</div>' +
        '<div class="t-wrap" style="margin-top:8px"><table><tr>' + cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr>' +
        d.rows.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td>' + esc(r[c] === null ? '—' : r[c]) + '</td>'; }).join('') + '</tr>'; }).join('') + '</table></div>';
    };
    xhr.send(fd);
  });
})();
