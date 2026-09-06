/* Banimark panel behaviour: theme toggle, mobile nav, chart tooltips, and the
   small interactions that used to be onclick= attributes. Vanilla, no build
   step - and served as a FILE, because customer apps carry Content-Security-
   Policies that block inline scripts and inline handlers outright. */
(function () {
  'use strict';

  /* a marker a real browser can show us: set only if this file actually ran
     (a Content-Security-Policy that blocked it leaves the attribute absent) */
  document.documentElement.setAttribute('data-bm-ready', '1');

  /* runtime facts arrive in a JSON data block (CSP never executes those) */
  try {
    var cfgEl = document.getElementById('bm-config');
    if (cfgEl) window.BM = JSON.parse(cfgEl.textContent || '{}');
  } catch (e) { window.BM = window.BM || {}; }

  /* declarative replacements for inline handlers:
       data-confirm="…"      ask before the click goes through (works on submit buttons)
       data-select-all       click a readonly box → select its text
       data-reveal="#id"     un-hide a target, focus its first field
       data-dismiss=".sel"   hide the closest matching ancestor
       data-toggle="#id"     flip a target's hidden state */
  document.addEventListener('click', function (ev) {
    var c = ev.target.closest('[data-confirm]');
    if (c && !window.confirm(c.getAttribute('data-confirm'))) { ev.preventDefault(); ev.stopImmediatePropagation(); return; }
    var s = ev.target.closest('[data-select-all]');
    if (s && s.select) { s.select(); return; }
    var r = ev.target.closest('[data-reveal]');
    if (r) {
      var target = document.querySelector(r.getAttribute('data-reveal'));
      if (target) { target.hidden = false; var f = target.querySelector('input,textarea,select'); if (f) f.focus(); }
      return;
    }
    var d = ev.target.closest('[data-dismiss]');
    if (d) { var box = d.closest(d.getAttribute('data-dismiss')); if (box) box.hidden = true; return; }
    var t = ev.target.closest('[data-toggle]');
    if (t) { var el = document.querySelector(t.getAttribute('data-toggle')); if (el) el.hidden = !el.hidden; }
  }, true);

  /* theme: remembered per browser, falls back to the OS setting */
  var root = document.documentElement;
  try {
    var saved = localStorage.getItem('bm-theme');
    if (saved === 'dark' || saved === 'light') root.setAttribute('data-theme', saved);
  } catch (e) {}

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-theme-toggle]');
    if (t) {
      var isDark = root.getAttribute('data-theme') === 'dark' ||
        (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
      var next = isDark ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('bm-theme', next); } catch (e) {}
      return;
    }
    var b = ev.target.closest('[data-nav-toggle]');
    var side = document.querySelector('.bm-side');
    var scrim = document.querySelector('.bm-scrim');
    if (b && side) { side.classList.toggle('open'); if (scrim) scrim.classList.toggle('on'); return; }
    if (ev.target.classList && ev.target.classList.contains('bm-scrim') && side) {
      side.classList.remove('open'); ev.target.classList.remove('on');
    }
  });

  /* chart tooltips: one floating node, driven by [data-tip] hover targets */
  var tip;
  document.addEventListener('mouseover', function (ev) {
    var g = ev.target.closest ? ev.target.closest('[data-tip]') : null;
    if (!g) return;
    if (!tip) { tip = document.createElement('div'); tip.className = 'bm-tip'; document.body.appendChild(tip); }
    tip.innerHTML = g.getAttribute('data-tip');
    tip.classList.add('on');
  });
  document.addEventListener('mousemove', function (ev) {
    if (!tip || !tip.classList.contains('on')) return;
    tip.style.left = ev.clientX + 'px';
    tip.style.top = ev.clientY + 'px';
  });
  document.addEventListener('mouseout', function (ev) {
    var g = ev.target.closest ? ev.target.closest('[data-tip]') : null;
    if (g && tip) tip.classList.remove('on');
  });

  /* keep a conversation view pinned to the newest message */
  var msgs = document.querySelector('[data-autoscroll]');
  if (msgs) msgs.scrollTop = msgs.scrollHeight;
})();

/* Staff alerts: poll the event feed, chime + badge on new visitor messages
   and handovers. The chime is synthesised (no audio files to ship) and can
   be muted per browser. First poll starts from "now" so a reload is silent. */
(function () {
  'use strict';
  var cfg = window.BM || {};
  if (!cfg.events) return;
  var KEY_SINCE = 'bm-events-since', KEY_MUTE = 'bm-sound-muted';
  var since = 0, muted = false;
  try { since = parseInt(localStorage.getItem(KEY_SINCE) || '0', 10) || 0; muted = localStorage.getItem(KEY_MUTE) === '1'; } catch (e) {}
  var btn = document.querySelector('[data-sound-toggle]');
  function paintBtn() { if (btn) { btn.classList.toggle('muted', muted); btn.title = muted ? 'Sound is off - click to turn on' : 'Sound is on - click to mute'; } }
  paintBtn();
  if (btn) btn.addEventListener('click', function () {
    muted = !muted; try { localStorage.setItem(KEY_MUTE, muted ? '1' : '0'); } catch (e) {}
    paintBtn(); if (!muted) chime();
  });

  var ctx;
  function chime() {
    if (muted) return;
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var t = ctx.currentTime;
      [[880, 0], [1174.66, 0.12]].forEach(function (n) {
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine'; o.frequency.value = n[0];
        g.gain.setValueAtTime(0.0001, t + n[1]);
        g.gain.exponentialRampToValueAtTime(0.18, t + n[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.35);
        o.connect(g); g.connect(ctx.destination); o.start(t + n[1]); o.stop(t + n[1] + 0.4);
      });
    } catch (e) {}
  }

  var inboxLink = document.querySelector('.bm-nav a[href$="/inbox"]');
  var badge;
  function setBadge(n) {
    if (!inboxLink) return;
    if (!badge) { badge = document.createElement('span'); badge.className = 'bm-badge'; inboxLink.appendChild(badge); }
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = n <= 0;
  }
  var baseTitle = document.title;
  function toast(item) {
    var el = document.createElement('a');
    el.className = 'bm-toast';
    el.href = (cfg.conversation || '').replace('__SID__', item.session_id);
    el.innerHTML = '<b></b><span></span>';
    el.querySelector('b').textContent = (item.kind === 'escalation' ? '🙋 ' : '💬 ') + item.label;
    el.querySelector('span').textContent = item.text;
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('on'); }, 10);
    setTimeout(function () { el.classList.remove('on'); setTimeout(function () { el.remove(); }, 400); }, 6000);
  }

  function poll() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', cfg.events + (cfg.events.indexOf('?') > -1 ? '&' : '?') + 'since=' + since, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status !== 200) return;
      var d; try { d = JSON.parse(xhr.responseText); } catch (e) { return; }
      var fresh = since > 0 ? (d.messages + d.escalations) : 0;
      since = d.now; try { localStorage.setItem(KEY_SINCE, String(since)); } catch (e) {}
      setBadge(d.waiting);
      document.title = (d.waiting > 0 ? '(' + d.waiting + ') ' : '') + baseTitle;
      if (fresh > 0) {
        chime();
        // the live conversation page shows its own messages - only toast others
        var here = (document.querySelector('[data-live-chat]') || {}).getAttribute ? document.querySelector('[data-live-chat]').getAttribute('data-session') : '';
        (d.items || []).slice(0, 3).forEach(function (it) { if (it.session_id !== here) toast(it); });
        document.dispatchEvent(new CustomEvent('bm:events', { detail: d }));
      }
    };
    xhr.send();
  }
  poll();
  setInterval(poll, cfg.eventsEvery || 10000);
})();


/* Collapsible sections (rule folders): [data-collapse=key] toggles the
   [data-collapse-body] in its [data-collapsible] card. Open state is remembered
   per browser; controls inside the header (move/edit/delete) never toggle. */
(function () {
  'use strict';
  var KEY = 'bm-open';
  var open = {};
  try { open = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
  function save() { try { localStorage.setItem(KEY, JSON.stringify(open)); } catch (e) {} }
  function apply(head) {
    var key = head.getAttribute('data-collapse');
    var card = head.closest('[data-collapsible]');
    var body = card ? card.querySelector('[data-collapse-body]') : null;
    if (!body) return;
    var isOpen = !!open[key];
    body.hidden = !isOpen;
    head.classList.toggle('open', isOpen);
  }
  var heads = document.querySelectorAll('[data-collapse]');
  if (!heads.length) return;
  Array.prototype.forEach.call(heads, apply);
  document.addEventListener('click', function (ev) {
    var all = ev.target.closest('[data-collapse-all]');
    if (all) {
      var to = all.getAttribute('data-collapse-all') === 'open';
      Array.prototype.forEach.call(heads, function (h) { open[h.getAttribute('data-collapse')] = to; apply(h); });
      save();
      return;
    }
    var head = ev.target.closest('[data-collapse]');
    if (!head || ev.target.closest('a,button,form,input,select,textarea,label')) return;
    var key = head.getAttribute('data-collapse');
    open[key] = !open[key];
    apply(head);
    save();
  });
})();

/* Access presets: picking a preset ticks the matching permissions; ticking by
   hand flips the preset to "custom". The preset list is shared with the server. */
(function () {
  'use strict';
  var PRESETS = { viewer: ['dashboard.view', 'inbox.view'], agent: ['dashboard.view', 'inbox.view', 'inbox.reply', 'inbox.close'], editor: 'all' };
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('[data-preset-for]');
    if (sel) {
      var box = document.querySelector(sel.getAttribute('data-preset-for'));
      if (!box || sel.value === 'custom') return;
      var set = PRESETS[sel.value];
      Array.prototype.forEach.call(box.querySelectorAll('input[name="perms[]"]'), function (cb) { cb.checked = set === 'all' || set.indexOf(cb.value) > -1; });
      return;
    }
    var cb = ev.target.closest('input[name="perms[]"]');
    if (cb) {
      var form = cb.closest('form'); var preset = form && form.querySelector('[data-preset-for]');
      if (preset) preset.value = 'custom';
    }
  });
})();

/* ---- AI provider form: plain-language service picker ----
 * Gemini/Claude need no address, so the field is hidden for them. For the
 * OpenAI-compatible driver the owner picks the service they have a key for and
 * the address (and a first model) are filled in; the key link follows suit. */
(function () {
  'use strict';
  var form = document.querySelector('[data-provider-form]');
  if (!form) return;
  var cfgEl = form.querySelector('[data-provider-presets]');
  var cfg = { presets: {}, driverKeys: {} };
  try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}') || cfg; } catch (e) {}
  var driver = form.querySelector('[name=driver]'), service = form.querySelector('[data-service]');
  var urlWrap = form.querySelector('[data-provider-url]'), svcWrap = form.querySelector('[data-provider-service]');
  var url = form.querySelector('[name=base_url]'), model = form.querySelector('[name=model]');
  var note = form.querySelector('[data-service-note]'), keyLink = form.querySelector('[data-key-link]');
  var modelHints = { gemini: 'gemini-2.5-flash', anthropic: 'claude-3-5-haiku-latest' };

  function applyDriver() {
    var compat = driver.value === 'openai-compat';
    if (urlWrap) urlWrap.hidden = !compat;
    if (svcWrap) svcWrap.hidden = !compat;
    if (!compat) {
      if (keyLink && cfg.driverKeys[driver.value]) keyLink.href = cfg.driverKeys[driver.value];
      if (model && !model.value) model.placeholder = modelHints[driver.value] || '';
    } else {
      applyService();
    }
  }
  function applyService() {
    if (!service) return;
    var p = cfg.presets[service.value];
    if (!p) { if (note) note.textContent = 'Pick one and the address below is filled in for you.'; return; }
    if (url && p.base_url) url.value = p.base_url;
    if (model) { model.placeholder = p.model || ''; if (!model.value && p.model) model.value = p.model; }
    if (keyLink && p.keys) keyLink.href = p.keys;
    if (note) note.textContent = p.note || ('Address filled in. Get your key at ' + p.keys.replace(/^https?:\/\//, '').split('/')[0] + ', paste it below, and you are done.');
  }
  driver.addEventListener('change', applyDriver);
  if (service) service.addEventListener('change', applyService);
  applyDriver();
  // the key link matches whatever is selected when the page opens, without clobbering saved values
  if (driver.value === 'openai-compat' && service && cfg.presets[service.value]) {
    var p = cfg.presets[service.value];
    if (keyLink && p.keys) keyLink.href = p.keys;
    if (note) note.textContent = p.note || 'Address filled in from the service you chose.';
  }
})();
