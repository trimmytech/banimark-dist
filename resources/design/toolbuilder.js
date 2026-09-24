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

  // the AI assistant (third block below) fills the form through this - the
  // parameter rows have to be built by the same code that reads them back
  window.BanimarkToolForm = {
    setParameters: function (list) {
      paramsWrap.innerHTML = '';
      (list && list.length ? list : [{}]).forEach(function (p) { paramsWrap.appendChild(paramRow(p)); });
      reindexParams();
    }
  };

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

/* ---- "Describe it, and it builds it" ----
 * The owner types what they want; the server asks their own AI provider for a
 * draft and hands it back as JSON. Everything here is presentation: the draft
 * is validated server-side before it ever reaches this file, and it lands in
 * the form as ordinary values the owner can read, change, test and save. */
(function () {
  'use strict';
  var box = document.querySelector('[data-tool-assistant]');
  var form = document.querySelector('form[action$="/tools"], form[action*="/tools?"]');
  if (!box || !form) return;
  var log = box.querySelector('[data-assist-log]');
  var ask = box.querySelector('[data-assist-form]');
  var input = box.querySelector('[data-assist-input]');
  var send = box.querySelector('[data-assist-send]');
  var csrf = form.querySelector('[name=_token], [name=_csrf]');
  var history = [];
  var busy = false;

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function say(who, text, extraHtml) {
    var el = document.createElement('div');
    el.className = 'bm-assist-msg ' + who;
    el.innerHTML = esc(text).replace(/\n/g, '<br>') + (extraHtml || '');
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }
  function resize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }
  input.addEventListener('input', resize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
  });

  function setField(name, value) {
    var el = form.querySelector('[name="' + name + '"]');
    if (!el || value === undefined || value === null) return;
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fill(tool) {
    // an API tool and a database tool fill different halves of the form
    var kind = tool.kind === 'http' ? 'http' : 'sql';
    var radio = document.querySelector('[data-kind="' + kind + '"]');
    if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    if (kind === 'http' && tool.config) {
      setField('http_method', tool.config.method || 'GET');
      setField('http_url', tool.config.url || '');
      setField('http_body', tool.config.body || '');
      setField('http_path', tool.config.path || '');
      setField('http_fields', (tool.config.fields || []).join(', '));
      var headers = Object.keys(tool.config.headers || {}).map(function (k) { return k + ': ' + tool.config.headers[k]; });
      setField('http_headers', headers.join('\n'));
      setField('context_http', (tool.context || []).join(', '));
      // how it is secured. The SECRET is never filled in - the assistant is not
      // told it and the box is write-only; the owner types it before saving.
      var auth = tool.config.auth || { type: 'none' };
      setField('http_auth', auth.type || 'none');
      if (auth.name) setField('http_auth_header', auth.name);
      if (auth.user) setField('http_auth_user', auth.user);
      if (auth.issuer) setField('http_auth_issuer', auth.issuer);
      if (auth.audience) setField('http_auth_audience', auth.audience);
      if (auth.ttl) setField('http_auth_ttl', auth.ttl);
    }
    setField('name', tool.name);
    setField('description', tool.description);
    setField('max_rows', tool.max_rows);
    setField('context', (tool.context || []).join(', '));
    setField('columns', (tool.columns || []).join(', '));
    if (window.BanimarkToolForm) {
      window.BanimarkToolForm.setParameters((tool.parameters || []).map(function (p) {
        return { name: p.name, type: p.type, desc: p.description, required: p.required };
      }));
    }
    // the SQL goes last: the try-it panel rebuilds its boxes from this field
    setField('sql', tool.sql);
    var details = form.querySelector('details');
    if (details) details.open = true;
    var build = document.getElementById('build');
    if (build && build.scrollIntoView) build.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  ask.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || busy) return;
    busy = true;
    send.disabled = true;
    input.value = '';
    resize();
    say('you', text);
    history.push({ role: 'user', text: text });
    var thinking = say('bot', 'Thinking…');
    thinking.classList.add('thinking');

    var fd = new FormData();
    if (csrf) fd.append(csrf.name, csrf.value);
    history.forEach(function (t, i) {
      fd.append('turns[' + i + '][role]', t.role);
      fd.append('turns[' + i + '][text]', t.text);
    });
    var xhr = new XMLHttpRequest();
    xhr.open('POST', box.getAttribute('data-assist-url'), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.timeout = 60000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      busy = false;
      send.disabled = false;
      thinking.remove();
      var d = null;
      try { d = JSON.parse(xhr.responseText); } catch (err) {}
      if (!d || !d.ok) {
        say('err', (d && d.error) || 'The assistant could not be reached (HTTP ' + xhr.status + ').');
        return;
      }
      history.push({ role: 'assistant', text: d.reply || '' });
      if (d.tool) {
        say('bot', d.reply, '<div class="bm-assist-done">Filled in below — <b>try it</b>, then save.</div>');
        fill(d.tool);
      } else {
        say('bot', d.reply);
      }
      input.focus();
    };
    xhr.ontimeout = function () {
      busy = false; send.disabled = false; thinking.remove();
      say('err', 'That took too long. Try again, or describe it more simply.');
    };
    xhr.send(fd);
  });
})();

/* ---- which kind of tool is being built ----
 * The database block and the API block are mutually exclusive; the identity
 * keys field appears in both, so the visible one is mirrored into the single
 * input the server reads. */
(function () {
  'use strict';
  var radios = document.querySelectorAll('[data-kind]');
  if (!radios.length) return;
  var blocks = document.querySelectorAll('[data-kind-block]');
  var canonical = document.querySelector('[name=context]');
  var mirror = document.querySelector('[data-mirror=context]');

  function apply() {
    var kind = 'sql';
    Array.prototype.forEach.call(radios, function (r) { if (r.checked) kind = r.getAttribute('data-kind'); });
    Array.prototype.forEach.call(blocks, function (b) { b.hidden = b.getAttribute('data-kind-block') !== kind; });
    // only one of the two identity inputs is on screen; keep the server's copy right
    if (canonical && mirror) {
      if (kind === 'http') { canonical.value = mirror.value; } else { mirror.value = canonical.value; }
    }
    // SQL is only required when it is the kind being built
    var sql = document.querySelector('[name=sql]'), cols = document.querySelector('[name=columns]');
    if (sql) sql.required = kind === 'sql';
    if (cols) cols.required = kind === 'sql';
    var url = document.querySelector('[name=http_url]'), fields = document.querySelector('[name=http_fields]');
    if (url) url.required = kind === 'http';
    if (fields) fields.required = kind === 'http';
  }
  Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', apply); });
  if (mirror && canonical) mirror.addEventListener('input', function () { canonical.value = mirror.value; });
  apply();
})();

/* ---- how the endpoint is secured -------------------------------------
 * One select, one visible block. Wired here rather than with an inline
 * handler because customer apps ship a CSP with no unsafe-inline: an
 * onchange= attribute is silently dead there, and the form would show
 * every auth method at once. */
(function () {
  'use strict';
  var select = document.querySelector('[data-auth-select]');
  if (!select) return;
  var blocks = document.querySelectorAll('[data-auth-block]');

  function apply() {
    Array.prototype.forEach.call(blocks, function (b) {
      b.hidden = b.getAttribute('data-auth-block') !== select.value;
    });
  }
  select.addEventListener('change', apply);
  apply();
})();
