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

  /* Toasts live in ONE fixed stack, not individually positioned: the old
     version pinned the second and third to bottom:96px/174px, which only held
     while every toast was exactly one line. A wrapped message overlapped it. */
  var stack;
  function toastStack() {
    if (!stack) stack = document.querySelector('.bm-toasts'); // the form answers share the stack
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'bm-toasts';
      stack.setAttribute('role', 'status');
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    return stack;
  }

  var ICON = {
    message: '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M20.5 12.2c0 3.9-3.8 7-8.5 7-1 0-2-.15-2.9-.42L4 20.5l1.5-3.6A6.6 6.6 0 0 1 3.5 12.2c0-3.9 3.8-7 8.5-7s8.5 3.1 8.5 7Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    escalation: '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><circle cx="12" cy="8" r="3.3" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M5.2 20c.5-3.5 3.3-5.7 6.8-5.7s6.3 2.2 6.8 5.7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>'
  };

  function toast(item) {
    var esc = item.kind === 'escalation';
    var el = document.createElement('a');
    el.className = 'bm-toast' + (esc ? ' esc' : '');
    el.href = (cfg.conversation || '').replace('__SID__', item.session_id);

    el.innerHTML = '<span class="ic"></span>'
      + '<span class="tx"><b></b><span class="msg"></span></span>'
      + '<button type="button" class="x" aria-label="Dismiss">&times;</button>';
    el.querySelector('.ic').innerHTML = esc ? ICON.escalation : ICON.message;
    el.querySelector('b').textContent = item.label;
    el.querySelector('.msg').textContent = item.text;

    var gone = false;
    function dismiss() {
      if (gone) { return; }
      gone = true;
      el.classList.remove('on');
      setTimeout(function () { el.remove(); }, 260);
    }
    el.querySelector('.x').addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();   // it sits inside the link
      dismiss();
    });
    // reading it should not yank it away mid-sentence
    var timer = setTimeout(dismiss, 7000);
    el.addEventListener('mouseenter', function () { clearTimeout(timer); });
    el.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 2500); });

    var box = toastStack();
    box.appendChild(el);
    while (box.children.length > 4) { box.removeChild(box.firstChild); }
    requestAnimationFrame(function () { el.classList.add('on'); });
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
  var savedDriver = driver.value;
  var savedModels = model && model.tagName === 'SELECT' ? model.innerHTML : '';

  /* The model is a menu of TESTED models (ProviderPresets::MODELS), rebuilt
   * when the driver changes; switching back restores the saved menu, so a
   * provider's current (maybe unlisted) model is never lost by looking. */
  function applyModels() {
    if (!model || model.tagName !== 'SELECT') return;
    if (driver.value === savedDriver) { model.innerHTML = savedModels; return; }
    var list = (cfg.models || {})[driver.value] || {};
    var pick = (cfg.defaultModel || {})[driver.value] || '';
    model.innerHTML = '';
    Object.keys(list).forEach(function (id) {
      var o = document.createElement('option');
      o.value = id; o.textContent = list[id];
      if (id === pick) o.selected = true;
      model.appendChild(o);
    });
  }

  function applyDriver() {
    var compat = driver.value === 'openai-compat';
    if (urlWrap) urlWrap.hidden = !compat;
    if (svcWrap) svcWrap.hidden = !compat;
    applyModels();
    if (!compat) {
      if (keyLink && cfg.driverKeys[driver.value]) keyLink.href = cfg.driverKeys[driver.value];
    } else {
      applyService();
    }
  }
  function applyService() {
    if (!service) return;
    var p = cfg.presets[service.value];
    if (!p) { if (note) note.textContent = 'Pick one and the address below is filled in for you.'; return; }
    if (url && p.base_url) url.value = p.base_url;
    if (model && model.tagName !== 'SELECT') { model.placeholder = p.model || ''; if (!model.value && p.model) model.value = p.model; }
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

/* ---- data-toggle-show / data-toggle-hide ----
 * A radio pair that reveals one block: the "different database" fields on the
 * Tools page. Declarative, because a customer CSP kills inline handlers. */
(function () {
  'use strict';
  function apply(el) {
    var show = el.getAttribute('data-toggle-show');
    var hide = el.getAttribute('data-toggle-hide');
    var target = document.querySelector(show || hide);
    if (target) target.hidden = !show;
  }
  document.addEventListener('change', function (ev) {
    var el = ev.target.closest('[data-toggle-show], [data-toggle-hide]');
    if (el && el.checked) apply(el);
  });
  Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-show], [data-toggle-hide]'), function (el) {
    if (el.checked) apply(el);
  });
})();


/* ---- one-click update, with honest progress ----
 * Each step is a real server call that has actually finished before the tick
 * appears. No step is marked done optimistically, because the one thing worse
 * than a slow update is a panel that says it worked when it did not.
 * Without JavaScript the forms post normally and the page reloads. */
(function () {
  'use strict';

  function post(url, csrf, done) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 180000;                 // a big release on a slow line
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      var d = null;
      try { d = JSON.parse(xhr.responseText); } catch (e) {}
      if (d && typeof d.ok === 'boolean') { done(d.ok, d.message || d.error || ''); return; }
      done(false, xhr.status === 0
        ? 'The connection dropped. Reload this page to see where the update got to.'
        : 'The server answered ' + xhr.status + '. Reload this page to see where the update got to.');
    };
    xhr.send(csrf.name ? encodeURIComponent(csrf.name) + '=' + encodeURIComponent(csrf.value) : '');
  }

  function stepsBox(host, labels) {
    var box = document.createElement('div');
    box.className = 'bm-steps';
    labels.forEach(function (label) {
      var row = document.createElement('div');
      row.className = 'bm-step';
      row.innerHTML = '<span class="dot"></span><span><span class="what"></span></span>';
      row.querySelector('.what').textContent = label;
      box.appendChild(row);
    });
    host.appendChild(box);
    return box;
  }

  function mark(box, i, state, why) {
    var row = box.children[i];
    if (!row) { return; }
    row.className = 'bm-step' + (state ? ' ' + state : '');
    var note = row.querySelector('.why');
    if (why) {
      if (!note) { note = document.createElement('span'); note.className = 'why'; row.querySelector('span:last-child').appendChild(note); }
      note.textContent = why;
    } else if (note) { note.remove(); }
  }

  function csrfOf(form) {
    var f = form.querySelector('input[type=hidden][name]');
    return f ? { name: f.getAttribute('name'), value: f.value } : { name: '', value: '' };
  }

  function reloadSoon() {
    setTimeout(function () { window.location.reload(); }, 1400);
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-update-run]'), function (host) {
    var form = host.querySelector('form');
    if (!form) { return; }
    var fetchUrl = host.getAttribute('data-fetch');
    var applyUrl = host.getAttribute('data-apply');
    var schemaUrl = host.getAttribute('data-schema');
    var checkUrl = host.getAttribute('data-check');
    var version = host.getAttribute('data-version') || '';
    var schemaOnly = !fetchUrl && !checkUrl;

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var btn = form.querySelector('button');
      if (btn) { btn.disabled = true; }
      Array.prototype.forEach.call(host.querySelectorAll('form'), function (f) { f.hidden = true; });

      var csrf = csrfOf(form);
      var labels = checkUrl
        ? ['Asking Banimark whether anything newer exists']
        : schemaOnly
          ? ['Updating your database']
          : ['Downloading ' + (version ? 'Banimark ' + version : 'the update') + ' and checking it is ours',
             'Putting it in place, keeping a copy of the old one',
             'Updating your database'];
      var box = stepsBox(host, labels);

      function fail(i, why) {
        mark(box, i, 'bad', why);
        Array.prototype.forEach.call(host.querySelectorAll('form'), function (f) { f.hidden = false; });
        if (btn) { btn.disabled = false; }
      }

      if (checkUrl) {
        mark(box, 0, 'on');
        post(checkUrl, csrf, function (ok, msg) {
          mark(box, 0, ok ? 'done' : 'bad', msg);
          // a check that found something changes the page, so reload either
          // way rather than leave a stale card beside a fresh answer
          if (ok) { reloadSoon(); } else { fail(0, msg); }
        });
        return;
      }

      if (schemaOnly) {
        mark(box, 0, 'on');
        post(schemaUrl, csrf, function (ok, msg) {
          mark(box, 0, ok ? 'done' : 'bad', msg);
          if (ok) { reloadSoon(); } else { fail(0, msg); }
        });
        return;
      }

      mark(box, 0, 'on');
      post(fetchUrl, csrf, function (ok, msg) {
        if (!ok) { return fail(0, msg); }
        mark(box, 0, 'done');
        mark(box, 1, 'on');
        post(applyUrl, csrf, function (ok2, msg2) {
          if (!ok2) { return fail(1, msg2); }
          mark(box, 1, 'done', msg2);
          // the code on disk is now the NEW version; the database step runs
          // against it, which is exactly the order a manual update follows
          mark(box, 2, 'on');
          post(schemaUrl, csrf, function (ok3, msg3) {
            mark(box, 2, ok3 ? 'done' : 'bad', msg3);
            reloadSoon();
          });
        });
      });
    });
  });
})();


/* ---- every POST button answers in place (AJAX forms) ----
 * A button click posts through fetch; the answer is printed at the TOP of the
 * page and as a toast. An error keeps the owner on the page with what they
 * typed; a success goes where the server said (or reloads here) and the message
 * travels with it. Only POST forms - links stay links. Forms with their own
 * script (update card, tool builder, live reply) or data-native are left alone.
 * Declarative and served as a file: customer CSPs kill inline handlers.
 * The answer is JSON {ok, message, redirect} from Laravel\Http\FormAnswer
 * (HQ and the Laravel package) or Standalone\Panel::formAnswer(). Anything
 * else (a gate that redirected, an expired session) is handled by status. */
(function () {
  'use strict';
  if (!window.fetch || !window.FormData) return;
  var KEY = 'bm-pending-flash';
  var ICONS = {
    ok: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    err: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5v5.5M12 16.2v.3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
  };
  function wrap() { return document.querySelector('.bm-wrap'); }
  /* the RESULT of an action carries data-flash (the layouts mark it); a
     standing notice (update available, database behind) is also a .flash-*
     box and must be neither toasted on every page load nor replaced */
  function topFlash() {
    return document.querySelector('.bm-wrap [data-flash], .bm-card [data-flash], [data-flash]');
  }
  function showTop(ok, text, near) {
    var olds = document.querySelectorAll('[data-flash]');
    for (var i = 0; i < olds.length; i++) olds[i].remove();
    var el = document.createElement('div');
    el.className = ok ? 'flash-ok' : 'flash-err';
    el.setAttribute('data-flash', ok ? 'ok' : 'err');
    el.setAttribute('role', ok ? 'status' : 'alert');
    el.innerHTML = ICONS[ok ? 'ok' : 'err'] + '<span></span>';
    el.querySelector('span').textContent = text;
    var w = wrap();
    if (w) { w.insertBefore(el, w.firstChild); }
    else if (near && near.parentNode) { near.parentNode.insertBefore(el, near); }
    else { document.body.insertBefore(el, document.body.firstChild); }
    return el;
  }
  function stackEl() {
    var s = document.querySelector('.bm-toasts');
    if (!s) {
      s = document.createElement('div');
      s.className = 'bm-toasts';
      s.setAttribute('role', 'status');
      s.setAttribute('aria-live', 'polite');
      document.body.appendChild(s);
    }
    return s;
  }
  function toast(ok, text) {
    var el = document.createElement('div');
    el.className = 'bm-toast ' + (ok ? 'ok' : 'err');
    el.innerHTML = '<span class="ic"></span><span class="tx"><b></b><span class="msg"></span></span><button type="button" class="x" aria-label="Dismiss">&times;</button>';
    el.querySelector('.ic').innerHTML = ICONS[ok ? 'ok' : 'err'];
    el.querySelector('b').textContent = ok ? 'Done' : 'Not saved';
    el.querySelector('.msg').textContent = text;
    var gone = false;
    function dismiss() { if (gone) return; gone = true; el.classList.remove('on'); setTimeout(function () { el.remove(); }, 260); }
    el.querySelector('.x').addEventListener('click', dismiss);
    var timer = setTimeout(dismiss, ok ? 6000 : 9000);
    el.addEventListener('mouseenter', function () { clearTimeout(timer); });
    el.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 2500); });
    var box = stackEl();
    box.appendChild(el);
    while (box.children.length > 4) { box.removeChild(box.firstChild); }
    requestAnimationFrame(function () { el.classList.add('on'); });
    return el;
  }
  function remember(ok, text) {
    try { sessionStorage.setItem(KEY, JSON.stringify({ ok: ok, text: text, t: Date.now() })); } catch (e) {}
  }
  function pending() {
    try {
      var raw = sessionStorage.getItem(KEY);
      if (!raw) return null;
      sessionStorage.removeItem(KEY);
      var p = JSON.parse(raw);
      return (p && p.text && Date.now() - (p.t || 0) < 60000) ? p : null;
    } catch (e) { return null; }
  }
  function samePage(a, b) {
    function norm(u) {
      try { u = new URL(u, location.href); } catch (e) { return String(u || ''); }
      u.hash = '';
      return u.href.replace(/\/+$/, '').replace(/\/(\?)/, '$1');
    }
    return norm(a) === norm(b);
  }
  function here() { return location.href.split('#')[0]; }
  function navigate(url) {
    if (url && !samePage(url, here())) { location.assign(url); }
    else { location.reload(); }
  }

  /* on arrival: a message the server rendered at the top is also a toast; one
     an AJAX answer left behind (a reload after a success) is printed at the top */
  function onLoad() {
    var top = topFlash();
    var p = pending();
    if (top) {
      toast(top.classList.contains('flash-ok'), (top.textContent || '').trim());
    } else if (p) {
      showTop(!!p.ok, p.text);
      toast(!!p.ok, p.text);
    }
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', onLoad); } else { onLoad(); }

  function skip(form) {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return true;
    if (form.hasAttribute('data-native') || form.hasAttribute('data-reply')) return true;
    if (form.target && form.target !== '_self') return true;
    if (form.closest('[data-fetch],[data-check],[data-tryit],[data-recover]')) return true;
    return false;
  }
  function busy(btn, on) {
    if (!btn) return;
    if (on) {
      btn.setAttribute('data-was', btn.textContent);
      btn.disabled = true;
      if (btn.getAttribute('data-busy')) btn.textContent = btn.getAttribute('data-busy');
      btn.classList.add('is-busy');
    } else {
      btn.disabled = false;
      btn.classList.remove('is-busy');
      if (btn.hasAttribute('data-was')) { btn.textContent = btn.getAttribute('data-was'); btn.removeAttribute('data-was'); }
    }
  }
  function handle(json, btn, form) {
    var ok = !!json.ok;
    var text = String(json.message || json.error || '');
    if (typeof json.message === 'object' && json.message) text = '';
    var to = json.redirect ? String(json.redirect) : '';
    if (ok || (to && !samePage(to, here()))) {
      if (text) remember(ok, text);
      navigate(to);
      return;
    }
    showTop(false, text || 'Something went wrong. Please try again.', form);
    toast(false, text || 'Something went wrong. Please try again.');
    busy(btn, false);
  }
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.tagName !== 'FORM' || ev.defaultPrevented || skip(form)) return;
    ev.preventDefault();
    var btn = ev.submitter || form.querySelector('button:not([type=button]):not([type=reset]),input[type=submit]');
    var fd = new FormData(form);
    if (ev.submitter && ev.submitter.name) { fd.append(ev.submitter.name, ev.submitter.value); }
    busy(btn, true);
    fetch(form.getAttribute('action') || here(), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Banimark-Form': '1',
        'X-Banimark-Page': here(),
        'Accept': 'application/json, text/html'
      }
    }).then(function (res) {
      var ct = res.headers.get('content-type') || '';
      if (ct.indexOf('application/json') >= 0) {
        return res.json().then(function (j) { handle(j || {}, btn, form); });
      }
      if (res.redirected) { navigate(res.url); return; }   // a gate (login, licence) sent us somewhere
      if (res.status >= 400) {
        var why = res.status === 419 ? 'Your session expired. Reload the page and try again.'
          : 'Something went wrong (HTTP ' + res.status + '). Reload the page and try again.';
        showTop(false, why, form); toast(false, why); busy(btn, false);
        return;
      }
      return res.text().then(function (html) {   // a page rendered in place: show it
        document.open(); document.write(html); document.close();
      });
    }).catch(function () {
      var why = 'Could not reach the server. Check your connection and try again.';
      showTop(false, why, form); toast(false, why); busy(btn, false);
    });
  });
})();


/* ---- data-busy="…" on a submit button ----
 * A slow form post (the customer-insights analysis waits on the AI provider):
 * the button says what is happening and cannot be pressed twice. Declarative -
 * customer CSPs kill inline handlers. */
(function () {
  'use strict';
  document.addEventListener('submit', function (ev) {
    var btn = ev.target.querySelector('button[data-busy]');
    if (!btn || ev.defaultPrevented) return;
    setTimeout(function () {           // after the browser has read the form
      btn.disabled = true;
      btn.textContent = btn.getAttribute('data-busy');
    }, 0);
  });
})();


/* ---- widget page: live preview ([data-widget-preview]) ----
 * Mirrors the form as the owner types: colour, title, welcome message,
 * starters, position, theme. Text only ever goes in via textContent. */
(function () {
  'use strict';
  var pv = document.querySelector('[data-widget-preview]');
  if (!pv) return;
  var form = document.querySelector('form.set-form');
  if (!form) return;
  var q = function (sel) { return form.querySelector(sel); };
  var pick = q('[data-color-pick]'), text = q('[data-color-text]');
  function color(v) { if (/^#[0-9a-fA-F]{6}$/.test(v)) { pv.style.setProperty('--wc', v); } }
  if (pick && text) {
    pick.addEventListener('input', function () { text.value = pick.value; color(pick.value); });
    text.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(text.value)) { pick.value = text.value; color(text.value); } });
  }
  function bind(sel, fn) { var el = q(sel); if (el) { el.addEventListener('input', function () { fn(el.value); }); el.addEventListener('change', function () { fn(el.value); }); } }
  bind('[name=title]', function (v) { pv.querySelector('[data-wp-title]').textContent = v || 'Support'; });
  bind('[data-wp-greeting-in]', function (v) { pv.querySelector('[data-wp-greeting]').textContent = v || 'Hi! How can we help you today?'; });
  bind('[data-wp-starters-in]', function (v) {
    var box = pv.querySelector('[data-wp-starters]');
    box.textContent = '';
    v.split('\n').map(function (s) { return s.trim(); }).filter(Boolean).slice(0, 6).forEach(function (s) {
      var chip = document.createElement('span'); chip.textContent = s; box.appendChild(chip);
    });
  });
  bind('[name=position]', function (v) { pv.setAttribute('data-pos', v); });
  var shade = '';
  function look() {
    var theme = pv.getAttribute('data-theme');
    pv.setAttribute('data-look', shade || (theme === 'dark' ? 'dark' : 'light'));
  }
  bind('[name=theme]', function (v) { pv.setAttribute('data-theme', v); look(); });
  bind('[name=corner]', function (v) { pv.setAttribute('data-corner', v); });
  bind('[name=density]', function (v) { pv.setAttribute('data-density', v); });
  bind('[name=launcher_icon]', function (v) { pv.querySelector('[data-wp-launch]').setAttribute('data-icon', v); });
  bind('[name=launcher_label]', function (v) { pv.querySelector('[data-wp-label]').textContent = v.trim(); });

  /* the header line and the greeting both change out of hours */
  var bub = pv.querySelector('[data-wp-greeting]'), status = pv.querySelector('[data-wp-status]');
  var statusIn = q('[name=status_line]'), awayIn = q('[data-wp-away-in]'), greetIn = q('[data-wp-greeting-in]');
  function words() {
    var away = pv.getAttribute('data-hours') === 'away';
    var hello = (greetIn && greetIn.value.trim()) || 'Hi! How can we help you today?';
    var awayHello = awayIn && awayIn.value.trim();
    bub.textContent = away && awayHello ? awayHello : hello;
    status.textContent = away ? 'Back tomorrow at 9:00' : ((statusIn && statusIn.value.trim()) || 'We typically reply in a moment');
  }
  [statusIn, awayIn, greetIn].forEach(function (el) { if (el) { el.addEventListener('input', words); } });

  /* preview-only switches: they change the picture, never the form */
  Array.prototype.forEach.call(pv.querySelectorAll('[data-wp-toggle]'), function (grp) {
    grp.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-v]');
      if (!b) { return; }
      Array.prototype.forEach.call(grp.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
      var what = grp.getAttribute('data-wp-toggle'), v = b.getAttribute('data-v');
      if (what === 'shade') { shade = v; look(); }
      if (what === 'device') { pv.setAttribute('data-device', v); }
      if (what === 'hours') { pv.setAttribute('data-hours', v); words(); }
    });
  });

  /* the logo: shrink to 128 px HERE, so a 3 MB photo uploads as a few KB and
     the server only ever stores a small picture. The file input is cleared so
     the big original is not posted as well; without JavaScript it posts as is. */
  var logoFile = q('[data-logo-file]'), logoData = q('[data-logo-data]'), logoRemove = q('[data-logo-remove]');
  var avatar = pv.querySelector('[data-wp-logo]'), logoNow = form.querySelector('[data-logo-now]');
  function showLogo(src) {
    avatar.textContent = '';
    if (src) { var img = document.createElement('img'); img.src = src; img.alt = ''; avatar.appendChild(img); }
    if (logoNow && src) { logoNow.textContent = ''; var i2 = document.createElement('img'); i2.src = src; i2.alt = 'New logo'; logoNow.appendChild(i2); }
  }
  var savedLogo = avatar.querySelector('img') ? avatar.querySelector('img').src : '';
  if (logoFile && logoData) {
    logoFile.addEventListener('change', function () {
      var f = logoFile.files && logoFile.files[0];
      if (!f || !/^image\/(png|jpeg|webp|gif)$/.test(f.type)) { return; }
      var url = URL.createObjectURL(f), img = new Image();
      img.onload = function () {
        var n = 128, c = document.createElement('canvas'), side = Math.min(img.width, img.height);
        c.width = n; c.height = n;
        // centre-crop to a square: the header shows a square tile
        c.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, n, n);
        var data = c.toDataURL('image/png');
        if (data.length > 52000) { data = c.toDataURL('image/jpeg', 0.85); }
        URL.revokeObjectURL(url);
        logoData.value = data;
        logoFile.value = '';
        if (logoRemove) { logoRemove.checked = false; }
        showLogo(data);
      };
      img.src = url;
    });
  }
  if (logoRemove) {
    logoRemove.addEventListener('change', function () {
      if (logoRemove.checked) { logoData.value = ''; avatar.textContent = ''; } else if (savedLogo) { showLogo(savedLogo); }
    });
  }

  /* "Reset the look": fill the form with the widget's defaults; saving is still the owner's call */
  var reset = pv.querySelector('[data-widget-reset]');
  if (reset) {
    reset.addEventListener('click', function () {
      var d = {};
      try { d = JSON.parse(reset.getAttribute('data-widget-reset')) || {}; } catch (e) {}
      Object.keys(d).forEach(function (k) {
        var el = q('[name=' + k + ']');
        if (!el) { return; }
        el.value = String(d[k]);
        el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
      });
      if (pick && d.color) { pick.value = d.color; color(d.color); }
    });
  }
})();
