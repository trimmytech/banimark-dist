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
    // the emoji picker is served just above this file; take it and tidy up after
    var EMOJI = window.BanimarkEmoji;
    try { delete window.BanimarkEmoji; } catch (e) { window.BanimarkEmoji = undefined; }
    var MD = window.BanimarkMarkdown;
    try { delete window.BanimarkMarkdown; } catch (e) { window.BanimarkMarkdown = undefined; }
    var UPLOAD_URL = cfg.endpoint.replace(/\/chat$/, '/upload');
    var FILE_URL = cfg.endpoint.replace(/\/chat$/, '/file/');
    var FILES_ON = cfg.files !== false;
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
        // a message that did not arrive: dimmed, and carrying its own way back
        '.m.fail{opacity:.66}',
        '.ffoot{display:flex;align-items:center;gap:8px;margin-top:7px;font-size:11px;line-height:1.2}',
        '.ffoot>span{opacity:.9;white-space:normal}',
        '.again{border:1px solid currentColor;background:transparent;color:inherit;font:inherit;font-size:11px;',
        'font-weight:600;padding:2px 9px;border-radius:999px;cursor:pointer;flex:none;opacity:.95}',
        '.again:hover{opacity:1;background:rgba(255,255,255,.18)}',
        '.m.bot .again:hover,.m.sys .again:hover{background:rgba(0,0,0,.06)}',

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
        /* formatted text inside bubbles */
        '.m p{margin:0}.m p+p{margin-top:8px}.m ul,.m ol{margin:6px 0 0;padding-left:20px}.m li{margin:2px 0}',
        '.m a{color:inherit;text-decoration:underline;text-underline-offset:2px}',
        '.m code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;background:rgba(0,0,0,.08);padding:1px 5px;border-radius:5px}',
        '.m.user code{background:rgba(255,255,255,.2)}',
        '.m pre{margin:6px 0 0;padding:8px 10px;border-radius:9px;background:rgba(0,0,0,.08);overflow-x:auto;font-size:12px;line-height:1.45}',
        '.m pre code{background:none;padding:0}',
        /* attachments */
        '.m .att{display:block;margin-top:6px}',
        '.m .att img{max-width:210px;max-height:210px;border-radius:12px;display:block;cursor:zoom-in}',
        '.att-f{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:11px;background:var(--panel);',
        'border:1px solid var(--bd);color:var(--fg);text-decoration:none;max-width:230px}',
        '.m.user .att-f{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.25);color:var(--on)}',
        '.att-f b{font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}',
        '.att-f span{font-size:11px;opacity:.7}',
        '.pend{display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px 0;background:var(--bg)}',
        '.pend-i{display:flex;align-items:center;gap:6px;background:var(--panel);border:1px solid var(--bd);border-radius:10px;',
        'padding:5px 7px;font-size:11.5px;color:var(--fg);max-width:190px}',
        '.pend-i b{font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
        '.pend-i button{border:none;background:transparent;color:var(--mut);cursor:pointer;font-size:14px;line-height:1;padding:0 2px}',
        '.pend-i.up{opacity:.6}',
        '.ic-btn{border:none;background:transparent;color:var(--mut);cursor:pointer;width:32px;height:32px;border-radius:10px;',
        'display:flex;align-items:center;justify-content:center;flex:none;transition:background .15s,color .15s}',
        '.ic-btn:hover{background:var(--panel);color:var(--fg)}',
        /* emoji picker */
        '.bm-emoji{position:absolute;bottom:56px;left:10px;right:10px;background:var(--bg);border:1px solid var(--bd);',
        'border-radius:14px;box-shadow:0 12px 34px rgba(0,0,0,.18);z-index:5;overflow:hidden}',
        '.bm-emoji-top{padding:8px 8px 4px}',
        '.bm-emoji-q{width:100%;border:1px solid var(--bd);background:var(--panel);color:var(--fg);border-radius:9px;',
        'padding:6px 9px;font-size:12.5px;outline:none}',
        '.bm-emoji-tabs{display:flex;gap:2px;padding:2px 8px;border-bottom:1px solid var(--bd)}',
        '.bm-emoji-tab{border:none;background:transparent;cursor:pointer;font-size:15px;padding:4px 6px;border-radius:8px;opacity:.55}',
        '.bm-emoji-tab.on{opacity:1;background:var(--panel)}',
        '.bm-emoji-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:1px;padding:7px;max-height:172px;overflow-y:auto}',
        '.bm-emoji-b{border:none;background:transparent;cursor:pointer;font-size:19px;line-height:1;padding:5px;border-radius:8px}',
        '.bm-emoji-b:hover{background:var(--panel)}',
        '.bm-emoji-none{grid-column:1/-1;color:var(--mut);font-size:12px;padding:10px;text-align:center}',
        '@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}'
    ].join('');
    root.appendChild(style);

    var ICON = {
        chat: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 20.5l1.5-5.2a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 9 8.4z"/></svg>',
        x: '<svg class="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        send: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>',
        bot: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/></svg>',
        smile: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01"/></svg>',
        clip: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.05 12.25 20.2a5.5 5.5 0 1 1-7.78-7.78l9.2-9.2a3.67 3.67 0 1 1 5.18 5.19l-9.2 9.19a1.83 1.83 0 1 1-2.6-2.59l8.5-8.49"/></svg>',
        doc: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>'
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
            '<div class="pend" hidden></div>' +
            '<form class="f">' +
            '<button type="button" class="ic-btn emo" aria-label="Emoji">' + ICON.smile + '</button>' +
            (FILES_ON ? '<button type="button" class="ic-btn clip" aria-label="Attach a file">' + ICON.clip + '</button>' +
                '<input type="file" class="fi" hidden>' : '') +
            '<textarea class="in" rows="1" placeholder="Type a message…" aria-label="Message"></textarea>' +
            '<button type="submit" class="sd" aria-label="Send" disabled>' + ICON.send + '</button></form>' +
            (cfg.offline_note ? '<div class="note"></div>' : '') +
            '<div class="brand">Powered by Banimark</div>' +
        '</div>' +
        '<button class="btn" aria-label="Open support chat">' + ICON.chat + ICON.x + '<span class="pip">1</span></button>';
    root.appendChild(wrap);

    var panel = wrap.querySelector('.p'), btn = wrap.querySelector('.btn'), pip = wrap.querySelector('.pip');
    var msgs = wrap.querySelector('.ms'), form = wrap.querySelector('.f');
    var input = wrap.querySelector('.in'), send = wrap.querySelector('.sd');
    var emoBtn = wrap.querySelector('.emo'), clipBtn = wrap.querySelector('.clip');
    var fileInput = wrap.querySelector('.fi'), pendBox = wrap.querySelector('.pend');
    var guestBox = wrap.querySelector('.guest');
    wrap.querySelector('.ttl').textContent = cfg.title;
    if (cfg.offline_note) { wrap.querySelector('.note').textContent = cfg.offline_note; }

    function fileSize(n) {
        if (!n) { return ''; }
        return n < 1024 ? n + ' B' : (n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB');
    }
    function bubble(cls, text, files) {
        var b = document.createElement('div');
        b.className = 'm ' + cls;
        // messages carry light formatting (bold, lists, links...); the renderer
        // escapes first, so nothing a model or a visitor types becomes markup
        if (text) { if (MD) { b.innerHTML = MD.render(text); } else { b.textContent = text; } }
        (files || []).forEach(function (f) {
            var url = FILE_URL + f.token;
            var wrapEl = document.createElement('span');
            wrapEl.className = 'att';
            if (f.is_image) {
                var a = document.createElement('a');
                a.href = url; a.target = '_blank'; a.rel = 'noopener';
                var img = document.createElement('img');
                img.src = url; img.alt = f.name; img.loading = 'lazy';
                a.appendChild(img); wrapEl.appendChild(a);
            } else {
                var link = document.createElement('a');
                link.className = 'att-f'; link.href = url + '?download=1'; link.target = '_blank'; link.rel = 'noopener';
                link.innerHTML = ICON.doc + '<span style="min-width:0"><b></b><span></span></span>';
                link.querySelector('b').textContent = f.name;
                link.querySelector('span span').textContent = fileSize(f.size);
                wrapEl.appendChild(link);
            }
            b.appendChild(wrapEl);
        });
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

    function adoptSession(id) {
        if (!id || id === session) { return; }
        session = id;
        try { localStorage.setItem(SS_KEY, session); } catch (err) {}
    }

    var restored = false;
    /* continuation: replay what was said before - at LOAD, so a reload never
     * looks like a fresh chat. A signed-in visitor without a local session is
     * matched to their open thread by the server. */
    function restore(done) {
        if (restored || (!session && !cfg.token)) { if (done) { done(); } return; }
        restored = true;
        get('/chat/history', {}, function (res) {
            if (res && res.ok && res.session_id) { adoptSession(res.session_id); }
            if (!res || !res.ok || !res.messages || !res.messages.length) { if (done) { done(); } return; }
            msgs.innerHTML = '';
            greeted = true;
            res.messages.forEach(function (m) {
                var b = bubble(m.role === 'user' ? 'user' : 'bot', m.text, m.files);
                b.style.animation = 'none'; // a replay should not look like new arrivals
                lastAgentId = Math.max(lastAgentId, m.id || 0);
            });
            bubble('sys', 'Picking up where you left off.');
            if (res.mode === 'agent') { enterAgentMode(true); }
            if (done) { done(); }
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
        if (session) { startPolling(false); }
        setTimeout(function () { (guestBox.hidden ? input : guestBox.querySelector('.g-name')).focus(); }, 220);
    }
    function closePanel() {
        wrap.classList.remove('open');
        showAgentTyping(false);
        if (session) { startPolling(true); } else { stopPolling(); } // keep a slow ear open for a human's reply
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
        refreshSend();
    });
    function refreshSend() {
        send.disabled = busy || (input.value.trim() === '' && !pending.some(function (p) { return p.id; }));
    }
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
        }
    });

    /* ---- sending, and resending what did not make it ----
     * A message that fails stays in the thread, dimmed, with a Retry button on
     * it: the visitor never loses what they typed and never has to guess
     * whether it arrived. Attachments were stored before the send, so a retry
     * re-offers the same ids. The Flutter SDK's controller.retry() is the same
     * contract, and the shared chat link runs this very file. */
    function markFailed(bub, why, again) {
        bub.classList.add('fail');
        var foot = document.createElement('div');
        foot.className = 'ffoot';
        var note = document.createElement('span');
        note.textContent = why;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'again';
        btn.textContent = 'Retry';
        btn.addEventListener('click', function () {
            if (busy) { return; }
            foot.remove();
            bub.classList.remove('fail');
            msgs.appendChild(bub); // resent now, so it belongs at the end of the thread
            again();
        });
        foot.appendChild(note);
        foot.appendChild(btn);
        bub.appendChild(foot);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function postMessage(text, files, bub) {
        busy = true;
        send.disabled = true;
        var sendingIds = files.map(function (p) { return p.id; });

        /* The dots appear after a short, slightly random pause - as if the
         * message was read first - and stay up for a moment even when the
         * answer is instant. Instant dots and a reply that snaps in read as a
         * machine; this reads as someone typing. While a human owns the chat
         * the AI dots never show: the real agent's typing comes over the poll. */
        var typing = document.createElement('div');
        typing.className = 'typ';
        typing.innerHTML = '<i></i><i></i><i></i>';
        var dotsAt = 0, dotsTimer = null, waiting = null;
        if (!agentMode) {
            dotsTimer = setTimeout(function () {
                dotsTimer = null;
                dotsAt = Date.now();
                msgs.appendChild(typing);
                msgs.scrollTop = msgs.scrollHeight;
                if (waiting) { finish(waiting); } // the answer beat the dots: still show them
            }, 600 + Math.random() * 700);
        }
        function finish(fn) {
            var hold = dotsAt ? Math.max(0, 900 - (Date.now() - dotsAt)) : 0;
            setTimeout(function () { typing.remove(); fn(); }, hold);
        }
        function settle(fn) {
            if (dotsTimer) { waiting = fn; return; }
            finish(fn);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = 60000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            settle(function () { onAnswer(); });
        };
        function onAnswer() {
            busy = false;
            refreshSend();
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            // the thread id is kept even when the answer failed: the conversation
            // exists on the server and a human may pick it up - losing the id here
            // is what used to turn a reload into a brand-new chat
            if (res && res.session_id) { adoptSession(res.session_id); }
            if (res && res.ok) {
                if (res.reply) { bubble('bot', res.reply); }
                if (res.mode === 'agent' && !agentMode) { enterAgentMode(); }
                startPolling(false);
            } else {
                markFailed(bub, (res && res.error) || 'Not sent', function () {
                    postMessage(text, files, bub);
                });
            }
            input.focus();
        }
        xhr.send(JSON.stringify({
            message: text,
            session_id: session,
            token: cfg.token,
            visitor: { name: visitor.name, email: visitor.email },
            attachments: sendingIds
        }));
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        var ready = pending.filter(function (p) { return p.id; });
        if ((!text && !ready.length) || busy) { return; }
        input.value = '';
        resize();
        var bub = bubble('user', text, ready);
        pending = [];
        drawPending();
        postMessage(text, ready, bub);
    });

    /* ---- emoji ---- */
    var picker = EMOJI ? EMOJI.create(panel, function (e) {
        EMOJI.insertAt(input, e);
        picker.toggle(false);
    }) : null;
    if (emoBtn) {
        emoBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (picker) { picker.toggle(); }
        });
    }
    wrap.addEventListener('click', function (ev) {
        if (picker && picker.isOpen() && !ev.target.closest('.bm-emoji') && !ev.target.closest('.emo')) { picker.toggle(false); }
    });

    /* ---- attachments ----
     * Files upload the moment they are chosen, so the visitor sees progress and
     * the send button only ever sends ids the server has already accepted. */
    var pending = [];
    function drawPending() {
        if (!pendBox) { return; }
        pendBox.hidden = pending.length === 0;
        pendBox.innerHTML = '';
        pending.forEach(function (p, i) {
            var el = document.createElement('span');
            el.className = 'pend-i' + (p.id ? '' : ' up');
            el.innerHTML = '<b></b><span></span><button type="button" aria-label="Remove">&times;</button>';
            el.querySelector('b').textContent = p.name;
            el.querySelector('span').textContent = p.id ? fileSize(p.size) : 'sending…';
            el.querySelector('button').addEventListener('click', function () {
                pending.splice(i, 1);
                drawPending();
                refreshSend();
            });
            pendBox.appendChild(el);
        });
    }
    if (clipBtn && fileInput) {
        clipBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0];
            fileInput.value = '';
            if (!f) { return; }
            if (!session) { bubble('sys', 'Say hello first, then you can send a file.'); return; }
            var item = { name: f.name, size: f.size, id: 0 };
            pending.push(item);
            drawPending();
            var fd = new FormData();
            fd.append('file', f);
            fd.append('session_id', session);
            fd.append('token', cfg.token || '');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', UPLOAD_URL, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) { return; }
                var res = null;
                try { res = JSON.parse(xhr.responseText); } catch (err) {}
                if (res && res.ok && res.attachment) {
                    item.id = res.attachment.id;
                    item.token = res.attachment.token;
                    item.is_image = res.attachment.is_image;
                    item.size = res.attachment.size;
                } else {
                    var at = pending.indexOf(item);
                    if (at > -1) { pending.splice(at, 1); }
                    bubble('err', (res && res.error) || 'That file could not be sent.');
                }
                drawPending();
                refreshSend();
            };
            xhr.send(fd);
        });
    }

    /* ---- polling: agent replies AND the presence heartbeat ----
     * It runs whenever the panel is open, not only in agent mode, because the
     * desk uses "is this widget still polling?" to decide whether the visitor
     * is around - and emails them the reply when they are not. */
    /* open panel: every POLL_MS; closed panel: a slow background check so a
     * human's reply still arrives (pip + chime) - a real live chat never goes deaf */
    var BG_MS = Math.max(POLL_MS * 3, 30000);
    function startPolling(background) {
        stopPolling();
        pollAgent();
        pollTimer = setInterval(pollAgent, background ? BG_MS : POLL_MS);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    /* a soft two-note chime for a human's message (synthesised, nothing to load) */
    var audio;
    function chime() {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            var t = audio.currentTime;
            [[660, 0], [880, 0.11]].forEach(function (n) {
                var o = audio.createOscillator(), g = audio.createGain();
                o.type = 'sine'; o.frequency.value = n[0];
                g.gain.setValueAtTime(0.0001, t + n[1]);
                g.gain.exponentialRampToValueAtTime(0.15, t + n[1] + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.32);
                o.connect(g); g.connect(audio.destination); o.start(t + n[1]); o.stop(t + n[1] + 0.36);
            });
        } catch (e) {}
    }

    /* typing, both directions: our keystrokes are reported (throttled) on the
     * poll; the agent's typing comes back on it and shows the dots */
    var typingAt = 0, agentTypingEl = null;
    function showAgentTyping(on) {
        if (on && !agentTypingEl) {
            agentTypingEl = document.createElement('div');
            agentTypingEl.className = 'typ';
            agentTypingEl.innerHTML = '<i></i><i></i><i></i>';
            msgs.appendChild(agentTypingEl);
            msgs.scrollTop = msgs.scrollHeight;
        } else if (!on && agentTypingEl) {
            agentTypingEl.remove(); agentTypingEl = null;
        }
    }
    input.addEventListener('input', function () {
        if (!session || !agentMode) { return; }
        var now = Date.now();
        if (now - typingAt > 2500) { typingAt = now; pollAgent(true); }
    });
    function enterAgentMode(quiet) {
        agentMode = true;
        if (!quiet) { bubble('sys', "You're connected to our support team — replies appear here."); }
        if (!pollTimer) { startPolling(!wrap.classList.contains('open')); }
    }

    /* a hidden tab is not "in the chat" either */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stopPolling(); }
        else if (session) { startPolling(!wrap.classList.contains('open')); }
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

    function pollAgent(typing) {
        if (!session) { return; }
        var extra = { after: lastAgentId };
        if (typing) { extra.typing = 1; }
        get('/chat/poll', extra, function (res) {
            if (!res || !res.ok) { return; }
            var fresh = (res.messages || []);
            if (fresh.length) { showAgentTyping(false); }
            fresh.forEach(function (m) {
                lastAgentId = Math.max(lastAgentId, m.id || 0);
                bubble('bot', m.text, m.files);
                if (!wrap.classList.contains('open')) { pip.classList.add('on'); }
            });
            if (fresh.length) { chime(); }
            var wasAgent = agentMode;
            agentMode = res.mode === 'agent';
            if (agentMode && !wasAgent) { enterAgentMode(true); }
            showAgentTyping(!!res.agent_typing && wrap.classList.contains('open'));
        });
    }

    // at load: bring back the thread, then listen in the background
    restore(function () { if (session) { startPolling(!wrap.classList.contains('open')); } });

    // shared as a link: the chat is the whole page, open from the first paint
    if (MODE === 'page') { openPanel(); }
})();
