/* Banimark panel behaviour: theme toggle, mobile nav, chart tooltips.
   Vanilla, tiny, inlined by both runtimes - no framework, no build step. */
(function () {
  'use strict';

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
