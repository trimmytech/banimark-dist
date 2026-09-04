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
