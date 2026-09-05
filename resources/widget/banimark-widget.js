/* Banimark chat widget - self-contained, zero dependencies, served from the
 * host's own domain (no CDN). Configuration is injected by the serving route
 * as window.__BANIMARK_CFG before this file, or via data-* attributes on the
 * <script> tag:
 *   endpoint  - POST chat URL (required)
 *   token     - signed VisitorToken for logged-in users (optional)
 *   color     - accent (default #6F04D9)
 *   position  - 'right' | 'left'
 *   title     - header title
 *   greeting  - first bubble shown before any message
 *   poll_seconds - how often to check for replies while open (owner-set)
 *   guest_mode   - 'off' | 'optional' | 'required': ask a guest who they are
 *   user      - {name, email} known to the page; skips the guest form
 * All UI lives inside a Shadow DOM so host CSS cannot bleed in or out, and
 * every colour derives from the configured accent so one setting re-themes it.
 *
 * The chat CONTINUES across page loads and visits: the session id is kept in
 * localStorage and the transcript is replayed from the server on open. While
 * the panel is open the widget polls, which doubles as the presence heartbeat
 * the desk uses to decide whether a visitor is still watching. */
(function () {
    'use strict';

    if (window.__banimarkWidgetLoaded) { return; }
    window.__banimarkWidgetLoaded = true;

    var script = document.currentScript || (function () {
        var s = document.getElementsByTagName('script');
        return s[s.length - 1];
    })();
    var cfg = Object.assign({
        endpoint: '',
        token: '',
        color: '#6F04D9',
        position: 'right',
        title: 'Support',
        greeting: 'Hi! How can we help you today?',
        poll_seconds: 10,
        guest_mode: 'off',
        offline_note: '',
        user: null
    }, window.__BANIMARK_CFG || {}, script ? {
        endpoint: script.getAttribute('data-endpoint') || (window.__BANIMARK_CFG || {}).endpoint || '',
        token: script.getAttribute('data-token') || (window.__BANIMARK_CFG || {}).token || ''
    } : {});
    if (!cfg.endpoint) { return; }

    /* a page can also name the visitor via data-name / data-email */
    var initUser = cfg.user || {};
    if (script) {
        if (script.getAttribute('data-name')) { initUser.name = script.getAttribute('data-name'); }
        if (script.getAttribute('data-email')) { initUser.email = script.getAttribute('data-email'); }
    }
    var visitor = {
        name: (initUser.name || '').toString().slice(0, 190),
        email: (initUser.email || '').toString().slice(0, 190)
    };
    var POLL_MS = Math.max(3, Math.min(600, parseInt(cfg.poll_seconds, 10) || 10)) * 1000;
    var GUEST = ['off', 'optional', 'required'].indexOf(cfg.guest_mode) >= 0 ? cfg.guest_mode : 'off';
    // theme is set in the admin panel (auto follows the visitor's OS); page mode
    // turns the widget into a full-page chat, for links in emails and elsewhere
    var THEME = ['auto', 'light', 'dark'].indexOf(cfg.theme) >= 0 ? cfg.theme : 'auto';
    var MODE = (script && script.getAttribute('data-mode')) || cfg.mode || 'widget';
    try {
        var savedGuest = JSON.parse(localStorage.getItem('banimark_guest') || 'null');
        if (savedGuest && !visitor.email) { visitor = savedGuest; }
    } catch (e) {}

    var SS_KEY = 'banimark_session';
    var side = cfg.position === 'left' ? 'left' : 'right';
    var session = '';
    try { session = localStorage.getItem(SS_KEY) || ''; } catch (e) {}
    var agentMode = false, lastAgentId = 0, pollTimer = null, busy = false, greeted = false;

    /* a readable ink colour for the accent, so a light brand still reads */
    function ink(hex) {
        var c = hex.replace('#', '');
        if (c.length === 3) { c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2]; }
        var r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) > 165 ? '#12121a' : '#ffffff';
    }
    var onAccent = ink(cfg.color);

    var host = document.createElement('div');
    host.style.cssText = 'position:fixed;bottom:20px;' + side + ':20px;z-index:2147483000;';
    document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'closed' }) : host;

    var style = document.createElement('style');
    style.textContent = [
        ':host{all:initial}',
        '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Helvetica,Arial,sans-serif}',
        '.w{--a:' + cfg.color + ';--on:' + onAccent + ';--bg:#fff;--fg:#12131a;--mut:#8a8fa3;--bd:rgba(16,18,32,.10);--panel:#f7f7fb;}',
        // theme comes from the admin panel: auto follows the visitor's OS, dark/light force it
        '.w.dark{--bg:#16161c;--fg:#f2f3f7;--mut:#9297aa;--bd:rgba(255,255,255,.12);--panel:#101015;}',
        '@media (prefers-color-scheme:dark){.w.auto{--bg:#16161c;--fg:#f2f3f7;--mut:#9297aa;--bd:rgba(255,255,255,.12);--panel:#101015;}}',
        // page mode: the chat IS the page (shared as a link) - no launcher, no close, fills the viewport
        '.w.page .btn,.w.page .teaser,.w.page .x{display:none}',
        '.w.page .p{position:fixed;inset:0;width:100%;height:100%;max-width:none;max-height:none;border-radius:0;bottom:auto;' + side + ':auto;box-shadow:none}',

        /* launcher */
        '.btn{width:56px;height:56px;border-radius:18px;border:none;cursor:pointer;background:var(--a);color:var(--on);',
        'display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,0,0,.22);',
        'transition:transform .22s cubic-bezier(.22,.61,.36,1),box-shadow .22s;position:relative}',
        '.btn:hover{transform:translateY(-2px) scale(1.03);box-shadow:0 12px 30px rgba(0,0,0,.28)}',
        '.btn:active{transform:scale(.96)}',
        '.btn svg{width:25px;height:25px;transition:transform .28s cubic-bezier(.22,.61,.36,1),opacity .18s;position:absolute}',
        '.btn .ic-x{opacity:0;transform:rotate(-90deg) scale(.6)}',
        '.open .btn .ic-chat{opacity:0;transform:rotate(90deg) scale(.6)}',
        '.open .btn .ic-x{opacity:1;transform:none}',
        '.pip{position:absolute;top:-3px;' + side + ':-3px;min-width:18px;height:18px;border-radius:9px;background:#e5484d;color:#fff;',
        'font-size:11px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px;border:2px solid var(--bg);animation:pop .3s cubic-bezier(.22,.61,.36,1)}',
        '.pip.on{display:flex}',
        '@keyframes pop{from{transform:scale(0)}to{transform:none}}',

        /* teaser bubble before first open */
        /* width:max-content matters - the containing block is only as wide as the
           launcher, so shrink-to-fit would wrap the greeting one word per line */
        '.teaser{position:absolute;bottom:70px;' + side + ':0;width:max-content;max-width:250px;background:var(--bg);color:var(--fg);',
        'border:1px solid var(--bd);border-radius:16px;border-bottom-' + side + '-radius:5px;padding:11px 14px;font-size:13.5px;line-height:1.45;',
        'box-shadow:0 10px 30px rgba(0,0,0,.14);cursor:pointer;animation:tIn .4s cubic-bezier(.22,.61,.36,1) both}',
        '@keyframes tIn{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:none}}',

        /* panel */
        '.p{display:flex;flex-direction:column;position:absolute;bottom:72px;' + side + ':0;width:378px;',
        'max-width:calc(100vw - 32px);height:552px;max-height:calc(100vh - 130px);background:var(--bg);',
        'border:1px solid var(--bd);border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.26);overflow:hidden;',
        'opacity:0;transform:translateY(14px) scale(.97);pointer-events:none;',
        'transition:opacity .24s cubic-bezier(.22,.61,.36,1),transform .24s cubic-bezier(.22,.61,.36,1)}',
        '.open .p{opacity:1;transform:none;pointer-events:auto}',

        '.hd{background:var(--a);color:var(--on);padding:15px 16px;display:flex;align-items:center;gap:11px}',
        '.av{width:36px;height:36px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex:none}',
        '.hd b{font-size:14.5px;font-weight:650;display:block;letter-spacing:-.01em}',
        '.hd .st{font-size:11.5px;opacity:.85;display:flex;align-items:center;gap:5px;margin-top:1px}',
        '.dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 0 rgba(74,222,128,.7);animation:pulse 2.2s infinite}',
        '@keyframes pulse{70%{box-shadow:0 0 0 6px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}',
        '.x{background:rgba(255,255,255,.15);border:none;color:var(--on);cursor:pointer;margin-left:auto;width:30px;height:30px;',
        'border-radius:9px;display:flex;align-items:center;justify-content:center;transition:background .16s}',
        '.x:hover{background:rgba(255,255,255,.28)}',

        '.ms{flex:1;overflow-y:auto;padding:16px 14px;background:var(--panel);display:flex;flex-direction:column;gap:9px;scroll-behavior:smooth}',
        '.ms::-webkit-scrollbar{width:6px}.ms::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}',
        '.m{max-width:84%;padding:10px 14px;border-radius:16px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;',
        'word-wrap:break-word;animation:mIn .26s cubic-bezier(.22,.61,.36,1) both}',
        '@keyframes mIn{from{opacity:0;transform:translateY(7px) scale(.98)}to{opacity:1;transform:none}}',
        '.m.user{align-self:flex-end;background:var(--a);color:var(--on);border-bottom-right-radius:5px}',
        '.m.bot{align-self:flex-start;background:var(--bg);color:var(--fg);border:1px solid var(--bd);border-bottom-left-radius:5px}',
        '.m.sys{align-self:center;background:transparent;color:var(--mut);font-size:12px;text-align:center;padding:4px 8px}',
        '.m.err{align-self:center;background:rgba(229,72,77,.12);color:#e5484d;font-size:12.5px}',

        '.typ{align-self:flex-start;background:var(--bg);border:1px solid var(--bd);border-radius:16px;border-bottom-left-radius:5px;',
        'padding:12px 15px;display:flex;gap:4px;animation:mIn .2s both}',
        '.typ i{width:6px;height:6px;border-radius:50%;background:var(--mut);animation:bob 1.3s infinite}',
        '.typ i:nth-child(2){animation-delay:.16s}.typ i:nth-child(3){animation-delay:.32s}',
        '@keyframes bob{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-5px);opacity:1}}',

        '.f{display:flex;align-items:flex-end;gap:8px;padding:10px 12px;border-top:1px solid var(--bd);background:var(--bg)}',
        '.in{flex:1;border:1px solid var(--bd);background:var(--panel);color:var(--fg);outline:none;padding:10px 13px;',
        'font-size:13.5px;resize:none;max-height:110px;border-radius:14px;line-height:1.45;transition:border-color .16s,box-shadow .16s}',
        '.in:focus{border-color:var(--a);box-shadow:0 0 0 3px rgba(0,0,0,.04)}',
        '.in::placeholder{color:var(--mut)}',
        '.sd{border:none;background:var(--a);color:var(--on);cursor:pointer;width:38px;height:38px;border-radius:13px;flex:none;',
        'display:flex;align-items:center;justify-content:center;transition:transform .16s,opacity .16s}',
        '.sd:hover:not(:disabled){transform:scale(1.06)}.sd:disabled{opacity:.4;cursor:default}',
        '.brand{text-align:center;font-size:10.5px;color:var(--mut);padding:0 0 9px;background:var(--bg)}',
        '.guest{padding:14px;border-top:1px solid var(--bd);background:var(--bg);animation:mIn .24s both}',
        '.guest p{margin:0 0 10px;font-size:12.5px;color:var(--mut);line-height:1.45}',
        '.guest input{width:100%;border:1px solid var(--bd);background:var(--panel);color:var(--fg);border-radius:11px;',
        'padding:9px 12px;font-size:13.5px;margin-bottom:7px;outline:none}',
        '.guest input:focus{border-color:var(--a)}',
        '.guest .row2{display:flex;gap:8px}.guest .row2 button{flex:none}',
        '.guest button{border:none;background:var(--a);color:var(--on);border-radius:11px;padding:9px 15px;font-size:13px;font-weight:600;cursor:pointer;flex:1}',
        '.guest .skip{background:transparent;color:var(--mut);border:1px solid var(--bd)}',
        '.note{text-align:center;font-size:11.5px;color:var(--mut);padding:6px 14px 0}',
        '@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}'
    ].join('');
    root.appendChild(style);

    var ICON = {
        chat: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 20.5l1.5-5.2a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 9 8.4z"/></svg>',
        x: '<svg class="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        send: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>',
        bot: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/></svg>'
    };

    var wrap = document.createElement('div');
    wrap.className = 'w' + (THEME === 'light' ? '' : ' ' + THEME) + (MODE === 'page' ? ' page' : '');
    wrap.innerHTML =
        '<div class="p" role="dialog" aria-label="Support chat">' +
            '<div class="hd">' +
                '<span class="av">' + ICON.bot + '</span>' +
                '<span><b class="ttl"></b><span class="st"><i class="dot"></i>We typically reply in a moment</span></span>' +
                '<button class="x" aria-label="Close chat">' + ICON.close + '</button>' +
            '</div>' +
            '<div class="ms" role="log" aria-live="polite"></div>' +
            '<div class="guest" hidden>' +
                '<p>Tell us where to reach you and we can follow up even if you close this tab.</p>' +
                '<input class="g-name" type="text" placeholder="Your name" autocomplete="name">' +
                '<input class="g-mail" type="email" placeholder="you@example.com" autocomplete="email">' +
                '<div class="row2"><button type="button" class="g-go">Start chat</button>' +
                '<button type="button" class="skip g-skip">Skip</button></div>' +
            '</div>' +
            '<form class="f"><textarea class="in" rows="1" placeholder="Type a message…" aria-label="Message"></textarea>' +
            '<button type="submit" class="sd" aria-label="Send" disabled>' + ICON.send + '</button></form>' +
            (cfg.offline_note ? '<div class="note"></div>' : '') +
            '<div class="brand">Powered by Banimark</div>' +
        '</div>' +
        '<button class="btn" aria-label="Open support chat">' + ICON.chat + ICON.x + '<span class="pip">1</span></button>';
    root.appendChild(wrap);

    var panel = wrap.querySelector('.p'), btn = wrap.querySelector('.btn'), pip = wrap.querySelector('.pip');
    var msgs = wrap.querySelector('.ms'), form = wrap.querySelector('.f');
    var input = wrap.querySelector('.in'), send = wrap.querySelector('.sd');
    var guestBox = wrap.querySelector('.guest');
    wrap.querySelector('.ttl').textContent = cfg.title;
    if (cfg.offline_note) { wrap.querySelector('.note').textContent = cfg.offline_note; }

    function bubble(cls, text) {
        var b = document.createElement('div');
        b.className = 'm ' + cls;
        b.textContent = text;
        msgs.appendChild(b);
        msgs.scrollTop = msgs.scrollHeight;
        return b;
    }

    /* teaser: the greeting peeks out once, before the visitor ever opens the panel */
    var teaser = null;
    if (cfg.greeting) {
        setTimeout(function () {
            if (greeted || wrap.classList.contains('open')) { return; }
            teaser = document.createElement('div');
            teaser.className = 'teaser';
            teaser.textContent = cfg.greeting;
            teaser.addEventListener('click', openPanel);
            wrap.appendChild(teaser);
            pip.classList.add('on');
        }, 1400);
    }
    function dropTeaser() {
        if (teaser) { teaser.remove(); teaser = null; }
        pip.classList.remove('on');
    }

    /* guest mode: ask who they are before the composer is usable */
    function guestNeeded() {
        return GUEST !== 'off' && !visitor.email && !cfg.token;
    }
    function showGuest(show) {
        guestBox.hidden = !show;
        form.style.display = show && GUEST === 'required' ? 'none' : '';
    }
    function saveGuest() {
        var n = guestBox.querySelector('.g-name').value.trim();
        var m = guestBox.querySelector('.g-mail').value.trim();
        if (GUEST === 'required' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(m)) {
            guestBox.querySelector('.g-mail').focus();
            return false;
        }
        visitor = { name: n.slice(0, 190), email: m.slice(0, 190) };
        try { localStorage.setItem('banimark_guest', JSON.stringify(visitor)); } catch (e) {}
        showGuest(false);
        input.focus();
        return true;
    }
    guestBox.querySelector('.g-go').addEventListener('click', saveGuest);
    guestBox.querySelector('.g-skip').addEventListener('click', function () {
        showGuest(false);
        input.focus();
    });

    var restored = false;
    /* continuation: replay what was said before, then keep the heartbeat going */
    function restore() {
        if (restored || !session) { return; }
        restored = true;
        get('/chat/history', {}, function (res) {
            if (!res || !res.ok || !res.messages || !res.messages.length) { return; }
            msgs.innerHTML = '';
            greeted = true;
            res.messages.forEach(function (m) {
                var b = bubble(m.role === 'user' ? 'user' : 'bot', m.text);
                b.style.animation = 'none'; // a replay should not look like new arrivals
                lastAgentId = Math.max(lastAgentId, m.id || 0);
            });
            bubble('sys', 'Picking up where you left off.');
            if (res.mode === 'agent') { enterAgentMode(true); }
        });
    }

    function openPanel() {
        wrap.classList.add('open');
        dropTeaser();
        restore();
        if (!greeted) {
            greeted = true;
            if (cfg.greeting) { bubble('bot', cfg.greeting); }
        }
        if (guestNeeded()) { showGuest(true); }
        startPolling();
        setTimeout(function () { (guestBox.hidden ? input : guestBox.querySelector('.g-name')).focus(); }, 220);
    }
    function closePanel() {
        wrap.classList.remove('open');
        stopPolling(); // presence follows the panel: closed means not watching
    }

    btn.addEventListener('click', function () {
        wrap.classList.contains('open') ? closePanel() : openPanel();
    });
    wrap.querySelector('.x').addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && wrap.classList.contains('open')) { closePanel(); }
    });

    /* composer: grow with the text, enable send only when there is something */
    function resize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    }
    input.addEventListener('input', function () {
        resize();
        send.disabled = busy || input.value.trim() === '';
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text || busy) { return; }
        busy = true;
        send.disabled = true;
        input.value = '';
        resize();
        bubble('user', text);

        var typing = document.createElement('div');
        typing.className = 'typ';
        typing.innerHTML = '<i></i><i></i><i></i>';
        msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = 60000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            busy = false;
            send.disabled = input.value.trim() === '';
            typing.remove();
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            if (res && res.ok) {
                if (res.session_id) {
                    session = res.session_id;
                    try { localStorage.setItem(SS_KEY, session); } catch (err) {}
                }
                if (res.reply) { bubble('bot', res.reply); }
                if (res.mode === 'agent' && !agentMode) { enterAgentMode(); }
                startPolling();
            } else {
                bubble('err', (res && res.error) || 'Could not send — please try again.');
            }
            input.focus();
        };
        xhr.send(JSON.stringify({
            message: text,
            session_id: session,
            token: cfg.token,
            visitor: { name: visitor.name, email: visitor.email }
        }));
    });

    /* ---- polling: agent replies AND the presence heartbeat ----
     * It runs whenever the panel is open, not only in agent mode, because the
     * desk uses "is this widget still polling?" to decide whether the visitor
     * is around - and emails them the reply when they are not. */
    function startPolling() {
        if (!pollTimer) { pollAgent(); pollTimer = setInterval(pollAgent, POLL_MS); }
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }
    function enterAgentMode(quiet) {
        agentMode = true;
        if (!quiet) { bubble('sys', "You're connected to our support team — replies appear here."); }
        startPolling();
    }

    /* a hidden tab is not "in the chat" either */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); }
        else if (wrap.classList.contains('open')) { startPolling(); }
    });
    /** GET against a sibling of the chat endpoint, with identity attached. */
    function get(path, extra, done) {
        var u = cfg.endpoint.replace(/\/chat$/, path)
            + '?session_id=' + encodeURIComponent(session)
            + (cfg.token ? '&token=' + encodeURIComponent(cfg.token) : '');
        for (var k in extra) { u += '&' + k + '=' + encodeURIComponent(extra[k]); }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', u, true);
        xhr.timeout = 15000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            done(res);
        };
        xhr.send();
    }

    function pollAgent() {
        if (!session) { return; }
        get('/chat/poll', { after: lastAgentId }, function (res) {
            if (!res || !res.ok) { return; }
            (res.messages || []).forEach(function (m) {
                lastAgentId = Math.max(lastAgentId, m.id || 0);
                bubble('bot', m.text);
                if (!wrap.classList.contains('open')) { pip.classList.add('on'); }
            });
            agentMode = res.mode === 'agent';
        });
    }

    // shared as a link: the chat is the whole page, open from the first paint
    if (MODE === 'page') { openPanel(); }
})();
