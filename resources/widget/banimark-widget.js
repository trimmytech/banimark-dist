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
 *   poll_idle_seconds - how often to look while CLOSED, for the unread count
 *   launcher_reappear_minutes - a launcher the visitor put away comes back
 *                after this long (0 = not until their next visit)
 *   guest_mode   - 'off' | 'optional' | 'required': ask a guest who they are
 *   user      - {name, email} known to the page; skips the guest form
 *   status_line, logo_url, launcher_icon, launcher_label, corner, density,
 *   sound, auto_open, auto_open_after, auto_open_pages, show_on, hide_on -
 *                the owner's look and manners, resolved by Http\WidgetConfig
 *   data-preview="1" on the tag: the admin's test page - page rules and the
 *                once-per-visit auto-open memory are skipped
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
        user: null,
        status_line: '',
        logo_url: '',
        launcher_icon: 'chat',
        launcher_label: '',
        corner: 'rounded',
        density: 'comfortable',
        sound: true,
        auto_open: 'teaser',
        auto_open_after: 0,
        poll_idle_seconds: 30,
        launcher_reappear_minutes: 10,
        auto_open_pages: [],
        show_on: [],
        hide_on: []
    }, window.__BANIMARK_CFG || {}, script ? {
        endpoint: script.getAttribute('data-endpoint') || (window.__BANIMARK_CFG || {}).endpoint || '',
        token: script.getAttribute('data-token') || (window.__BANIMARK_CFG || {}).token || ''
    } : {});
    if (!cfg.endpoint) { return; }
    var MODE = (script && script.getAttribute('data-mode')) || cfg.mode || 'widget';
    var PREVIEW = !!(script && script.getAttribute('data-preview') === '1');

    /* A never-activated install (no trial started, no licence key) serves no
       chat - the widget, the shared link and the mobile SDK stay dark until the
       owner starts a trial or enters a key. The server decides (cfg.enabled,
       from Master::widgetActivated); the preview always renders. */
    if (cfg.enabled === false && !PREVIEW) { return; }

    /* Page rules: "/blog/*" style paths, * = anything. "Never" wins over
       "only". The shareable link (page mode) and the admin's test page ignore
       them - the link IS the chat, and the test page must always show it. */
    function slash(p) { return p.length > 1 ? p.replace(/\/+$/, '') : p; }
    function onPage(list) {
        var here = slash(location.pathname || '/');
        return (list || []).some(function (rule) {
            var rx = '^' + slash(String(rule)).replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$';
            try { return new RegExp(rx).test(here); } catch (e) { return false; }
        });
    }
    if (MODE !== 'page' && !PREVIEW) {
        if (cfg.hide_on && cfg.hide_on.length && onPage(cfg.hide_on)) { return; }
        if (cfg.show_on && cfg.show_on.length && !onPage(cfg.show_on)) { return; }
    }

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
    var MD = window.BanimarkMarkdown;
    try { delete window.BanimarkMarkdown; } catch (e) { window.BanimarkMarkdown = undefined; }
    var UPLOAD_URL = cfg.endpoint.replace(/\/chat$/, '/upload');
    var FILE_URL = cfg.endpoint.replace(/\/chat$/, '/file/');
    var FILES_ON = cfg.files !== false;
    // theme is set in the admin panel (auto follows the visitor's OS); page mode
    // turns the widget into a full-page chat, for links in emails and elsewhere
    var THEME = ['auto', 'light', 'dark'].indexOf(cfg.theme) >= 0 ? cfg.theme : 'auto';
    var SOUND = cfg.sound !== false && cfg.sound !== '0';
    var CORNER = ['rounded', 'soft', 'square'].indexOf(cfg.corner) >= 0 ? cfg.corner : 'rounded';
    var COMPACT = cfg.density === 'compact';
    var LABEL = String(cfg.launcher_label || '').slice(0, 30);
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
        '.w{--a:' + cfg.color + ';--on:' + onAccent + ';--bg:#fff;--fg:#0b1b1e;--mut:#7a8a8c;--bd:rgba(11,36,38,.10);--panel:#f4f7f6;--r1:24px;--r2:18px;--r3:18px}',
        // corners the owner picked: panel, bubbles, launcher
        '.w.soft{--r1:16px;--r2:12px;--r3:14px}.w.square{--r1:8px;--r2:6px;--r3:8px}',
        // theme comes from the admin panel: auto follows the visitor's OS, dark/light force it
        '.w.dark{--bg:#111a1c;--fg:#eaf2f1;--mut:#8ea3a1;--bd:rgba(255,255,255,.11);--panel:#0a1113;}',
        '@media (prefers-color-scheme:dark){.w.auto{--bg:#111a1c;--fg:#eaf2f1;--mut:#8ea3a1;--bd:rgba(255,255,255,.11);--panel:#0a1113;}}',
        // page mode: the chat IS the page (shared as a link) - no launcher, no close, fills the viewport
        '.w.page .btn,.w.page .teaser,.w.page .x{display:none}',
        '.w.page .p{position:fixed;inset:0;width:100%;height:100%;max-width:none;max-height:none;border-radius:0;bottom:auto;' + side + ':auto;box-shadow:none}',
        // the shareable link on a wide screen: a chat card on a calm backdrop, not a stretched panel
        '@media (min-width:760px){.w.page::before{content:"";position:fixed;inset:0;background:radial-gradient(700px 420px at 15% 0%,color-mix(in srgb,var(--a) 22%,transparent),transparent 70%),radial-gradient(600px 380px at 100% 100%,color-mix(in srgb,var(--a) 12%,transparent),transparent 70%),var(--panel)}',
        '.w.page .p{inset:auto;top:50%;left:50%;transform:translate(-50%,-50%);width:min(720px,calc(100vw - 48px));height:min(820px,calc(100vh - 64px));border-radius:var(--r1);box-shadow:0 30px 80px rgba(0,0,0,.18)}}',

        /* launcher */
        '.btn{width:56px;height:56px;border-radius:var(--r3);border:none;cursor:pointer;background:var(--a);color:var(--on);',
        'display:flex;align-items:center;justify-content:center;box-shadow:0 10px 28px color-mix(in srgb,var(--a) 45%,rgba(0,0,0,.2)),inset 0 1px 0 rgba(255,255,255,.22);',
        'transition:transform .22s cubic-bezier(.22,.61,.36,1),box-shadow .22s;position:relative}',
        '.btn:hover{transform:translateY(-2px) scale(1.03);box-shadow:0 12px 30px rgba(0,0,0,.28)}',
        '.btn:active{transform:scale(.96)}',
        '.ics{position:relative;width:25px;height:25px;flex:none}',
        '.btn svg{width:25px;height:25px;transition:transform .28s cubic-bezier(.22,.61,.36,1),opacity .18s;position:absolute;left:0;top:0}',
        // with a label the launcher is a pill: easier to notice than a round icon
        '.btn.pill{width:auto;padding:0 20px 0 16px;gap:9px;border-radius:999px;font:600 14.5px/1 inherit}',
        '.w.square .btn.pill{border-radius:var(--r3)}',
        '.lbl{white-space:nowrap}',
        '.btn .ic-x{opacity:0;transform:rotate(-90deg) scale(.6)}',
        '.open .btn .ic-chat{opacity:0;transform:rotate(90deg) scale(.6)}',
        '.open .btn .ic-x{opacity:1;transform:none}',
        '.pip{position:absolute;top:-3px;' + side + ':-3px;min-width:18px;height:18px;border-radius:9px;background:#e5484d;color:#fff;',
        'font-size:11px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px;border:2px solid var(--bg);animation:pop .3s cubic-bezier(.22,.61,.36,1)}',
        '.pip.on{display:flex}',
        /* the launcher can be put away (a small x on hover; always on touch) and dragged anywhere */
        '.hide{position:absolute;top:-7px;' + (side === 'right' ? 'left' : 'right') + ':-7px;width:22px;height:22px;border-radius:50%;border:1px solid var(--bd);background:var(--bg);color:var(--mut);',
        'font:600 15px/1 inherit;cursor:pointer;display:none;align-items:center;justify-content:center;z-index:2;padding:0}',
        '.hide:hover{color:var(--fg)}',
        '.w:hover .hide{display:flex}@media (hover:none){.hide{display:flex}}',
        '.w.open .hide,.w.page .hide,.w.away .hide,.w.away .btn,.w.away .teaser{display:none!important}',
        '.btn.dragging{cursor:grabbing;transform:scale(1.04)}',
        /* dragged into the top half / the far side: the panel and the teaser open where there is room */
        '.w.flip-y .p{bottom:auto;top:72px;transform-origin:top}.w.flip-y .teaser{bottom:auto;top:70px}',
        '.w.flip-x .p,.w.flip-x .teaser{' + side + ':auto;' + (side === 'right' ? 'left' : 'right') + ':0}',
        '@keyframes pop{from{transform:scale(0)}to{transform:none}}',

        /* teaser bubble before first open */
        /* width:max-content matters - the containing block is only as wide as the
           launcher, so shrink-to-fit would wrap the greeting one word per line */
        '.teaser{position:absolute;bottom:70px;' + side + ':0;width:max-content;max-width:250px;background:var(--bg);color:var(--fg);',
        'border:1px solid var(--bd);border-radius:var(--r2);border-bottom-' + side + '-radius:5px;padding:11px 14px;font-size:13.5px;line-height:1.45;',
        'box-shadow:0 10px 30px rgba(0,0,0,.14);cursor:pointer;animation:tIn .4s cubic-bezier(.22,.61,.36,1) both}',
        '@keyframes tIn{from{opacity:0;transform:translateY(8px) scale(.96)}to{opacity:1;transform:none}}',

        /* panel */
        '.p{display:flex;flex-direction:column;position:absolute;bottom:72px;' + side + ':0;width:378px;',
        'max-width:calc(100vw - 32px);height:552px;max-height:calc(100vh - 130px);background:var(--bg);',
        'border:1px solid var(--bd);border-radius:var(--r1);box-shadow:0 28px 70px rgba(0,0,0,.24),0 2px 8px rgba(0,0,0,.06);overflow:hidden;',
        'opacity:0;transform:translateY(14px) scale(.97);pointer-events:none;',
        'transition:opacity .24s cubic-bezier(.22,.61,.36,1),transform .24s cubic-bezier(.22,.61,.36,1)}',
        '.open .p{opacity:1;transform:none;pointer-events:auto}',

        '.hd{background:radial-gradient(260px 120px at 100% 0%,rgba(255,255,255,.22),transparent 70%),linear-gradient(135deg,var(--a),color-mix(in srgb,var(--a) 72%,#000));color:var(--on);padding:18px 18px 17px;display:flex;align-items:center;gap:12px}',
        '.av{width:40px;height:40px;border-radius:13px;background:rgba(255,255,255,.2);box-shadow:inset 0 0 0 1px rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden}',
        '.av img{width:100%;height:100%;object-fit:cover;display:block}',
        '.hd b{font-size:15.5px;font-weight:700;display:block;letter-spacing:-.015em}',
        '.hd .st{font-size:11.5px;opacity:.85;display:flex;align-items:center;gap:5px;margin-top:1px}',
        '.dot{width:6px;height:6px;border-radius:50%;background:#4ade80;box-shadow:0 0 0 0 rgba(74,222,128,.7);animation:pulse 2.2s infinite}',
        '@keyframes pulse{70%{box-shadow:0 0 0 6px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}',
        '.x{background:rgba(255,255,255,.15);border:none;color:var(--on);cursor:pointer;margin-left:auto;width:30px;height:30px;',
        'border-radius:9px;display:flex;align-items:center;justify-content:center;transition:background .16s}',
        '.x:hover{background:rgba(255,255,255,.28)}',
        /* the visitor can delete their conversation: a bin beside the close
           button (only once there is a conversation), confirmed inside the panel */
        '.del{background:rgba(255,255,255,.15);border:none;color:var(--on);cursor:pointer;margin-left:auto;width:30px;height:30px;',
        'border-radius:9px;display:none;align-items:center;justify-content:center;transition:background .16s}',
        '.del:hover{background:rgba(255,255,255,.28)}',
        '.w.has-chat .del{display:flex}.w.has-chat .x{margin-left:6px}',
        '.cf{position:absolute;inset:0;z-index:5;background:color-mix(in srgb,var(--bg) 82%,transparent);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:22px}',
        '.cf[hidden]{display:none}',
        '.cf-box{background:var(--bg);color:var(--fg);border:1px solid var(--bd);border-radius:var(--r2);padding:18px;box-shadow:0 18px 50px rgba(0,0,0,.2);max-width:290px;font-size:14px;line-height:1.5}',
        '.cf-box b{display:block;font-size:15px;margin-bottom:4px}.cf-box p{margin:0 0 14px;color:var(--mut)}',
        '.cf-row{display:flex;gap:8px;justify-content:flex-end}',
        '.cf-row button{border:1px solid var(--bd);background:var(--bg);color:var(--fg);border-radius:10px;padding:8px 14px;font:600 13.5px/1 inherit;cursor:pointer}',
        '.cf-row .cf-yes{background:#e5484d;border-color:#e5484d;color:#fff}',

        '.ms{flex:1;overflow-y:auto;padding:16px 14px;background:var(--panel);display:flex;flex-direction:column;gap:9px;scroll-behavior:smooth}',
        /* a short thread sits just above the composer, like every messaging app -
           so a new visitor's first messages are next to the keyboard, not at the
           top of the screen where an open keyboard pushes them out of sight */
        '.ms>:first-child{margin-top:auto}',
        '.ms::-webkit-scrollbar{width:6px}.ms::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}',
        '.m{max-width:84%;padding:10px 14px;border-radius:var(--r2);font-size:14px;line-height:1.5;white-space:pre-wrap;',
        'word-wrap:break-word;animation:mIn .26s cubic-bezier(.22,.61,.36,1) both}',
        '@keyframes mIn{from{opacity:0;transform:translateY(7px) scale(.98)}to{opacity:1;transform:none}}',
        '.m.user{align-self:flex-end;background:var(--a);color:var(--on);border-bottom-right-radius:6px;box-shadow:0 4px 14px color-mix(in srgb,var(--a) 28%,transparent)}',
        '.m.bot{align-self:flex-start;background:var(--bg);color:var(--fg);border:1px solid var(--bd);border-bottom-left-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.04)}',
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

        '.typ{align-self:flex-start;background:var(--bg);border:1px solid var(--bd);border-radius:var(--r2);border-bottom-left-radius:5px;',
        'padding:12px 15px;display:flex;gap:4px;animation:mIn .2s both}',
        '.typ i{width:6px;height:6px;border-radius:50%;background:var(--mut);animation:bob 1.3s infinite}',
        '.typ i:nth-child(2){animation-delay:.16s}.typ i:nth-child(3){animation-delay:.32s}',
        '@keyframes bob{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-5px);opacity:1}}',

        /* composer: one bordered box - the text gets the whole width, the tools sit on a row underneath */
        '.f{padding:10px 12px 12px;border-top:1px solid var(--bd);background:var(--bg)}',
        '.box{border:1px solid var(--bd);background:var(--panel);border-radius:calc(var(--r2) + 2px);transition:border-color .15s,box-shadow .15s}',
        '.box:focus-within{border-color:var(--a);box-shadow:0 0 0 4px color-mix(in srgb,var(--a) 14%,transparent)}',
        '.in{display:block;width:100%;box-sizing:border-box;border:none;background:transparent;color:var(--fg);outline:none;',
        'padding:11px 14px 4px;font:inherit;font-size:14px;line-height:1.45;resize:none;max-height:120px;min-height:40px}',
        '.in::placeholder{color:var(--mut)}',
        '.bar{display:flex;align-items:center;gap:2px;padding:2px 6px 6px}',
        '.bar .sp{flex:1}',
        '.sd{border:none;background:var(--a);color:var(--on);cursor:pointer;width:36px;height:36px;border-radius:12px;flex:none;',
        'display:flex;align-items:center;justify-content:center;transition:transform .15s,opacity .15s}',
        '.sd:hover:not(:disabled){transform:scale(1.06)}.sd:disabled{opacity:.35;cursor:default}',
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
        '.starters{display:flex;flex-wrap:wrap;gap:6px;padding:2px 0 4px}',
        '.chip{border:1px solid color-mix(in srgb,var(--a) 35%,var(--bd));background:color-mix(in srgb,var(--a) 7%,var(--bg));color:var(--fg);cursor:pointer;font:inherit;font-size:13px;',
        'padding:7px 12px;border-radius:999px;transition:border-color .15s,transform .1s;text-align:left}',
        '.chip:hover{border-color:var(--a);background:color-mix(in srgb,var(--a) 12%,var(--bg))}.chip:active{transform:scale(.98)}',
        '.more{align-self:center;border:1px solid var(--bd);background:var(--panel);color:var(--mut);cursor:pointer;',
        'font:inherit;font-size:12px;padding:6px 12px;border-radius:999px;margin:0 0 6px}',
        '.more:hover{color:var(--fg)}',
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
        '.pend:empty{display:none}',
        '.ic-btn{border:none;background:transparent;color:var(--mut);cursor:pointer;width:30px;height:30px;border-radius:9px;',
        'display:flex;align-items:center;justify-content:center;flex:none;transition:background .15s,color .15s}',
        '.ic-btn:hover{background:var(--panel);color:var(--fg)}',
        '.st.away .dot{background:#f5a524;box-shadow:none;animation:none}',
        // compact: more of the conversation fits on a small screen
        '.w.compact .hd{padding:13px 15px 12px}.w.compact .av{width:34px;height:34px;border-radius:11px}',
        '.w.compact .ms{padding:12px 10px;gap:6px}.w.compact .m{padding:7px 11px;font-size:13.5px;line-height:1.45}',
        '.w.compact .f{padding:8px 10px 10px}',
        '@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}'
    ].join('');
    root.appendChild(style);

    var ICON = {
        help: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.2 9.3a3 3 0 0 1 5.7 1c0 2-3 2.7-3 4.2M12 17.5h.01"/></svg>',
        headset: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path d="M19 20a3 3 0 0 1-3 2h-3"/></svg>',
        sparkle: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>',
        chat: '<svg class="ic-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 20.5l1.5-5.2a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 9 8.4z"/></svg>',
        x: '<svg class="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        trash: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>',
        send: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>',
        bot: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4M9 14h.01M15 14h.01"/></svg>',
        clip: '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.05 12.25 20.2a5.5 5.5 0 1 1-7.78-7.78l9.2-9.2a3.67 3.67 0 1 1 5.18 5.19l-9.2 9.19a1.83 1.83 0 1 1-2.6-2.59l8.5-8.49"/></svg>',
        doc: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>'
    };

    var wrap = document.createElement('div');
    wrap.className = 'w' + (THEME === 'light' ? '' : ' ' + THEME) + (MODE === 'page' ? ' page' : '')
        + (CORNER === 'rounded' ? '' : ' ' + CORNER) + (COMPACT ? ' compact' : '');
    var LAUNCH_ICON = ICON[['chat', 'help', 'headset', 'sparkle'].indexOf(cfg.launcher_icon) >= 0 ? cfg.launcher_icon : 'chat'];
    wrap.innerHTML =
        '<div class="p" role="dialog" aria-label="Support chat">' +
            '<div class="hd">' +
                '<span class="av">' + ICON.bot + '</span>' +
                '<span><b class="ttl"></b><span class="st"><i class="dot"></i><span class="stx">We typically reply in a moment</span></span></span>' +
                '<button class="del" type="button" aria-label="Delete this conversation" title="Delete this conversation">' + ICON.trash + '</button>' +
                '<button class="x" aria-label="Close chat">' + ICON.close + '</button>' +
            '</div>' +
            '<div class="cf" hidden role="alertdialog" aria-label="Delete this conversation"><div class="cf-box">' +
                '<b>Delete this conversation?</b><p>It will be cleared from this chat and you will not be able to see it again.</p>' +
                '<div class="cf-row"><button type="button" class="cf-no">Cancel</button><button type="button" class="cf-yes">Delete</button></div>' +
            '</div></div>' +
            '<div class="ms" role="log" aria-live="polite"></div>' +
            '<div class="guest" hidden>' +
                '<p class="g-intro"></p><div class="g-fields"></div>' +
                '<div class="row2"><button type="button" class="g-go">Start chat</button>' +
                '<button type="button" class="skip g-skip">Skip</button></div>' +
            '</div>' +
            '<div class="pend" hidden></div>' +
            '<form class="f"><div class="box">' +
            '<textarea class="in" rows="1" placeholder="Type a message…" aria-label="Message"></textarea>' +
            '<div class="bar">' +
            (FILES_ON ? '<button type="button" class="ic-btn clip" aria-label="Attach a file" title="Attach a file">' + ICON.clip + '</button>' +
                '<input type="file" class="fi" hidden>' : '') +
            '<span class="sp"></span>' +
            '<button type="submit" class="sd" aria-label="Send" title="Send" disabled>' + ICON.send + '</button>' +
            '</div></div></form>' +
            (cfg.offline_note ? '<div class="note"></div>' : '') +
            (cfg.hide_brand ? '' : '<div class="brand">Powered by Banimark</div>') +
        '</div>' +
        '<button class="btn' + (LABEL ? ' pill' : '') + '" aria-label="Open support chat"><span class="ics">' + LAUNCH_ICON + ICON.x + '</span>'
        + (LABEL ? '<span class="lbl"></span>' : '') + '<span class="pip">1</span></button>'
        + '<button type="button" class="hide" aria-label="Hide the chat bubble" title="Hide">&times;</button>';
    root.appendChild(wrap);

    var panel = wrap.querySelector('.p'), btn = wrap.querySelector('.btn'), pip = wrap.querySelector('.pip');
    var msgs = wrap.querySelector('.ms'), form = wrap.querySelector('.f');
    var input = wrap.querySelector('.in'), send = wrap.querySelector('.sd');
    var clipBtn = wrap.querySelector('.clip');
    var fileInput = wrap.querySelector('.fi'), pendBox = wrap.querySelector('.pend');
    var guestBox = wrap.querySelector('.guest');
    /* the owner chooses which details to ask a guest for, and which are needed;
       the server resolves that into cfg.guest_fields so the web widget and the
       Flutter SDK put up the same form */
    var GUEST_FIELDS = (cfg.guest_fields && cfg.guest_fields.length ? cfg.guest_fields
        : [{ key: 'name', label: 'Your name', type: 'text', required: false, autocomplete: 'name' },
           { key: 'email', label: 'you@example.com', type: 'email', required: false, autocomplete: 'email' }]);
    (function buildGuestForm() {
        var intro = guestBox.querySelector('.g-intro');
        intro.textContent = cfg.guest_intro || '';
        intro.hidden = !cfg.guest_intro;
        guestBox.querySelector('.g-fields').innerHTML = GUEST_FIELDS.map(function (f) {
            return '<input class="g-f" data-k="' + f.key + '" type="' + f.type + '" placeholder="' +
                String(f.label).replace(/"/g, '&quot;') + (f.required ? ' *' : '') + '" autocomplete="' + f.autocomplete + '">';
        }).join('');
    })();
    wrap.querySelector('.ttl').textContent = cfg.title;
    if (LABEL) { wrap.querySelector('.lbl').textContent = LABEL; }
    if (cfg.status_line) { wrap.querySelector('.stx').textContent = String(cfg.status_line).slice(0, 80); }
    // the owner's logo replaces the robot; a picture that fails to load puts the robot back
    if (cfg.logo_url && /^(https?:)?\/\/|^\//.test(cfg.logo_url)) {
        (function () {
            // a standalone desk sends a path: it belongs to the server that served
            // THIS script, which is not always the page's own origin
            var src = cfg.logo_url;
            try { if (script && script.src) { src = new URL(cfg.logo_url, script.src).href; } } catch (e) {}
            var av = wrap.querySelector('.av'), robot = av.innerHTML, img = document.createElement('img');
            img.alt = '';
            img.onerror = function () { av.innerHTML = robot; };
            img.src = src;
            av.textContent = '';
            av.appendChild(img);
        })();
    }
    if (cfg.offline_note) { wrap.querySelector('.note').textContent = cfg.offline_note; }
    // outside the team's hours, say so in the header rather than implying
    // someone is sitting there waiting
    if (cfg.away_note) {
        wrap.querySelector('.stx').textContent = cfg.away_note;
        wrap.querySelector('.st').classList.add('away');
    }

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

    /* Before anyone clicks: the greeting peeks out as a bubble (the default),
       the chat opens by itself, or nothing - after the owner's delay, on the
       owner's pages. Opening by itself happens once per visit, and never on a
       phone, where a chat that covers the screen chases people away. */
    var teaser = null;
    var AUTO = ['teaser', 'open', 'off'].indexOf(cfg.auto_open) >= 0 ? cfg.auto_open : 'teaser';
    var AUTO_S = Math.max(0, Math.min(120, parseInt(cfg.auto_open_after, 10) || 0));
    var autoHere = PREVIEW || !(cfg.auto_open_pages && cfg.auto_open_pages.length) || onPage(cfg.auto_open_pages);
    function showTeaser() {
        if (!cfg.greeting) { return; }
        teaser = document.createElement('div');
        teaser.className = 'teaser';
        teaser.textContent = cfg.greeting;
        teaser.addEventListener('click', openPanel);
        wrap.appendChild(teaser);
        pip.classList.add('on');
    }
    function openedThisVisit() {
        if (PREVIEW) { return false; }
        try { if (sessionStorage.getItem('banimark_auto')) { return true; } sessionStorage.setItem('banimark_auto', '1'); } catch (e) {}
        return false;
    }
    if (MODE !== 'page' && AUTO !== 'off' && autoHere) {
        setTimeout(function () {
            if (greeted || wrap.classList.contains('open') || wrap.classList.contains('away')) { return; }
            var phone = window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
            if (AUTO === 'open' && !phone && !openedThisVisit()) { openPanel(true); return; }
            showTeaser();
        }, AUTO_S ? AUTO_S * 1000 : 1400);
    }
    function dropTeaser() {
        if (teaser) { teaser.remove(); teaser = null; }
        clearUnread();
    }

    /* guest mode: ask who they are before the composer is usable */
    function guestNeeded() {
        if (GUEST === 'off' || cfg.token) { return false; }
        // answered already if every field the owner insists on has a value
        var required = GUEST_FIELDS.filter(function (f) { return f.required; });
        if (required.length) {
            return required.some(function (f) { return !visitor[f.key]; });
        }
        return !visitor.email && !visitor.name && !visitor.phone;
    }
    function showGuest(show) {
        guestBox.hidden = !show;
        form.style.display = show && GUEST === 'required' ? 'none' : '';
    }
    function saveGuest() {
        var values = {}, missing = null;
        Array.prototype.forEach.call(guestBox.querySelectorAll('.g-f'), function (el) {
            var key = el.getAttribute('data-k');
            values[key] = el.value.trim();
            var field = GUEST_FIELDS.filter(function (f) { return f.key === key; })[0] || {};
            if (field.required && values[key] === '' && !missing) { missing = el; }
        });
        if (missing) { missing.focus(); return; }
        var n = values.name || '';
        var m = values.email || '';
        var emailField = guestBox.querySelector('.g-f[data-k=email]');
        if (emailField && m !== '' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(m)) {
            emailField.focus();
            return false;
        }
        visitor = { name: n.slice(0, 190), email: m.slice(0, 190), phone: (values.phone || '').slice(0, 40) };
        try { localStorage.setItem('banimark_guest', JSON.stringify(visitor)); } catch (e) {}
        showGuest(false);
        showStarters();
        input.focus();
        return true;
    }
    guestBox.querySelector('.g-go').addEventListener('click', saveGuest);
    guestBox.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); saveGuest(); }
    });
    guestBox.querySelector('.g-skip').addEventListener('click', function () {
        showGuest(false);
        showStarters();
        input.focus();
    });

    function adoptSession(id) {
        if (!id || id === session) { return; }
        session = id;
        wrap.classList.add('has-chat');
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
            var seen = seenId(), missed = 0;
            res.messages.forEach(function (m) {
                var b = bubble(m.role === 'user' ? 'user' : 'bot', m.text, m.files);
                b.style.animation = 'none'; // a replay should not look like new arrivals
                lastAgentId = Math.max(lastAgentId, m.id || 0);
                if (seen !== null && m.role !== 'user' && (m.id || 0) > seen) { missed++; }
            });
            // replies that landed while the visitor was away: the badge, unless they are looking
            if (wrap.classList.contains('open') && !document.hidden) { markSeen(); }
            else if (seen === null) { markSeen(); }
            else if (missed) { addUnread(missed); }
            oldestId = res.oldest_id || 0;
            offerEarlier(!!res.has_more);
            bubble('sys', 'Picking up where you left off.');
            if (res.mode === 'agent') { enterAgentMode(true); }
            if (done) { done(); }
        });
    }

    /* Only the last page of the thread is drawn at first. A pill at the top
     * fetches the page before it, on click or when the visitor scrolls up to
     * it; the scroll position is kept so the thread does not jump. */
    var oldestId = 0, loadingEarlier = false, morePill = null;
    function offerEarlier(hasMore) {
        if (morePill) { morePill.remove(); morePill = null; }
        if (!hasMore) { return; }
        morePill = document.createElement('button');
        morePill.type = 'button';
        morePill.className = 'more';
        morePill.textContent = 'Load earlier messages';
        morePill.addEventListener('click', loadEarlier);
        msgs.insertBefore(morePill, msgs.firstChild);
    }
    function loadEarlier() {
        if (loadingEarlier || !morePill || !oldestId) { return; }
        loadingEarlier = true;
        morePill.textContent = 'Loading…';
        get('/chat/history', { before: oldestId }, function (res) {
            loadingEarlier = false;
            if (!res || !res.ok) { if (morePill) { morePill.textContent = 'Load earlier messages'; } return; }
            var before = msgs.scrollHeight;
            var anchor = morePill.nextSibling;
            (res.messages || []).forEach(function (m) {
                var b = bubble(m.role === 'user' ? 'user' : 'bot', m.text, m.files);
                b.style.animation = 'none';
                msgs.insertBefore(b, anchor);
            });
            oldestId = res.oldest_id || oldestId;
            offerEarlier(!!res.has_more);
            msgs.scrollTop = msgs.scrollHeight - before; // stay on the message the visitor was reading
        });
    }
    msgs.addEventListener('scroll', function () {
        if (morePill && msgs.scrollTop < 24) { loadEarlier(); }
    });

    /* Unread staff replies: a count on the launcher, and in the tab title so a
     * visitor who switched tabs sees "(2) Acme Help". Cleared the moment the
     * chat is looked at. */
    var unread = 0, baseTitle = document.title;
    /* The count survives a page load: the id of the last message the visitor
     * LOOKED AT is kept, and a restored thread counts what arrived after it.
     * Nothing stored (a visitor from before this) = everything so far is read. */
    var SEEN_KEY = 'banimark_seen';
    function seenId() {
        try { var v = localStorage.getItem(SEEN_KEY); return v === null ? null : (parseInt(v, 10) || 0); } catch (e) { return null; }
    }
    function markSeen() {
        try { localStorage.setItem(SEEN_KEY, String(lastAgentId)); } catch (e) {}
    }
    function addUnread(n) {
        unread += n;
        if (wrap.classList.contains('away')) { showLauncher(); } // a reply is the one thing that brings it back
        pip.textContent = unread > 9 ? '9+' : String(unread);
        pip.classList.add('on');
        document.title = '(' + unread + ') ' + (baseTitle || 'New message');
    }
    function clearUnread() {
        unread = 0;
        markSeen();
        pip.classList.remove('on'); // also the greeting teaser's badge
        if (document.title !== baseTitle) { document.title = baseTitle; }
    }
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && wrap.classList.contains('open')) { clearUnread(); }
    });
    window.addEventListener('focus', function () {
        if (wrap.classList.contains('open')) { clearUnread(); }
    });

    /* Tappable openers. A first-time visitor stares at an empty box and types
       nothing; a few phrases in their own words are the difference between a
       conversation and a bounce. Tapping one simply sends it. */
    function showStarters() {
        if (!cfg.starters || !cfg.starters.length || msgs.querySelector('.starters')) { return; }
        if (msgs.querySelector('.m.user')) { return; } // they have already spoken
        var row = document.createElement('div');
        row.className = 'starters';
        cfg.starters.forEach(function (text) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip';
            chip.textContent = text;
            chip.addEventListener('click', function () {
                dropStarters();
                input.value = text;
                refreshSend();
                wrap.querySelector('form.f').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            });
            row.appendChild(chip);
        });
        msgs.appendChild(row);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function dropStarters() {
        var row = msgs.querySelector('.starters');
        if (row) { row.remove(); }
    }

    /** quiet = opened by itself: do not pull the keyboard focus off the page */
    function openPanel(quiet) {
        wrap.classList.add('open');
        clearUnread();
        dropTeaser();
        restore();
        if (!greeted) {
            greeted = true;
            if (cfg.greeting) { bubble('bot', cfg.greeting); }
        }
        if (guestNeeded()) { showGuest(true); } else { showStarters(); }
        if (session) { startPolling(false); }
        if (quiet === true) { return; }
        setTimeout(function () {
            var first = guestBox.querySelector('.g-f');
            (guestBox.hidden || !first ? input : first).focus();
        }, 220);
    }
    function closePanel() {
        wrap.classList.remove('open');
        showAgentTyping(false);
        if (session) { startPolling(true); } else { stopPolling(); } // keep a slow ear open for a human's reply
    }

    btn.addEventListener('click', function () {
        if (Date.now() - draggedAt < 500) { return; } // the end of a drag is not a click
        wrap.classList.contains('open') ? closePanel() : openPanel();
    });

    /* ---- the launcher can be dragged anywhere, and put away ----
     * The spot is kept as FRACTIONS of the free area, so a different window
     * size keeps it in the same corner. Put away with the small x, it comes
     * back after the owner's minutes (0 = not until the next visit), and at
     * once when a reply arrives. The admin's test page never remembers either. */
    var POS_KEY = 'banimark_pos', HIDE_KEY = 'banimark_hidden_until', HIDE_SESSION = 'banimark_hidden';
    var REAPPEAR_MS = Math.max(0, Math.min(1440, parseInt(cfg.launcher_reappear_minutes, 10) || 0)) * 60000;
    var MARGIN = 20, draggedAt = 0, drag = null, frac = null, backTimer = null;
    var hideBtn = wrap.querySelector('.hide');
    function free() {
        return { w: Math.max(0, window.innerWidth - (host.offsetWidth || 56) - 2 * MARGIN),
                 h: Math.max(0, window.innerHeight - (host.offsetHeight || 56) - 2 * MARGIN) };
    }
    function place() {
        if (!frac) { return; }
        var f = free(), x = MARGIN + frac.x * f.w, y = MARGIN + frac.y * f.h;
        host.style.left = x + 'px'; host.style.top = y + 'px'; host.style.right = 'auto'; host.style.bottom = 'auto';
        var centre = x + (host.offsetWidth || 56) / 2;
        wrap.classList.toggle('flip-y', y + (host.offsetHeight || 56) / 2 < window.innerHeight / 2);
        wrap.classList.toggle('flip-x', side === 'right' ? centre < window.innerWidth / 2 : centre > window.innerWidth / 2);
    }
    function setPx(x, y) {
        var f = free();
        frac = { x: f.w ? Math.max(0, Math.min(1, (x - MARGIN) / f.w)) : 0, y: f.h ? Math.max(0, Math.min(1, (y - MARGIN) / f.h)) : 0 };
        place();
    }
    function dragStart(px, py) {
        if (wrap.classList.contains('open')) { return; }
        var r = host.getBoundingClientRect();
        drag = { sx: px, sy: py, x0: r.left, y0: r.top, moved: false };
    }
    function dragMove(px, py) {
        if (!drag) { return false; }
        var dx = px - drag.sx, dy = py - drag.sy;
        if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 6) { return false; } // a wobbly tap is still a tap
        drag.moved = true;
        btn.classList.add('dragging');
        setPx(drag.x0 + dx, drag.y0 + dy);
        return true;
    }
    function dragEnd() {
        if (!drag) { return; }
        var moved = drag.moved;
        drag = null;
        btn.classList.remove('dragging');
        if (!moved) { return; }
        draggedAt = Date.now();
        if (!PREVIEW && frac) { try { localStorage.setItem(POS_KEY, frac.x + ',' + frac.y); } catch (e) {} }
    }
    btn.addEventListener('mousedown', function (e) { if (e.button === 0) { dragStart(e.clientX, e.clientY); } });
    document.addEventListener('mousemove', function (e) { if (dragMove(e.clientX, e.clientY)) { e.preventDefault(); } });
    document.addEventListener('mouseup', dragEnd);
    btn.addEventListener('touchstart', function (e) { var t = e.touches[0]; if (t) { dragStart(t.clientX, t.clientY); } }, { passive: true });
    document.addEventListener('touchmove', function (e) { var t = e.touches[0]; if (t && dragMove(t.clientX, t.clientY)) { e.preventDefault(); } }, { passive: false });
    document.addEventListener('touchend', dragEnd);
    document.addEventListener('touchcancel', dragEnd);
    window.addEventListener('resize', place);

    function showLauncher() {
        clearTimeout(backTimer); backTimer = null;
        wrap.classList.remove('away');
        try { localStorage.removeItem(HIDE_KEY); sessionStorage.removeItem(HIDE_SESSION); } catch (e) {}
    }
    function hideLauncher(untilMs) {
        if (teaser) { teaser.remove(); teaser = null; } // not dropTeaser(): hiding is not reading

        wrap.classList.add('away');
        var wait = untilMs !== undefined ? untilMs - Date.now() : REAPPEAR_MS;
        if (REAPPEAR_MS <= 0) {
            if (!PREVIEW) { try { sessionStorage.setItem(HIDE_SESSION, '1'); } catch (e) {} } // this visit only
            return;
        }
        if (!PREVIEW) { try { localStorage.setItem(HIDE_KEY, String(Date.now() + wait)); } catch (e) {} }
        clearTimeout(backTimer);
        backTimer = setTimeout(showLauncher, Math.max(0, wait));
    }
    hideBtn.addEventListener('click', function (e) { e.stopPropagation(); hideLauncher(); });
    if (!PREVIEW && MODE !== 'page') {
        try {
            var saved = (localStorage.getItem(POS_KEY) || '').split(',');
            if (saved.length === 2 && !isNaN(parseFloat(saved[0])) && !isNaN(parseFloat(saved[1]))) {
                frac = { x: Math.max(0, Math.min(1, parseFloat(saved[0]))), y: Math.max(0, Math.min(1, parseFloat(saved[1]))) };
                place();
            }
            var until = parseInt(localStorage.getItem(HIDE_KEY) || '0', 10);
            if (until > Date.now() && REAPPEAR_MS > 0) { hideLauncher(until); }
            else if (until) { localStorage.removeItem(HIDE_KEY); }
            if (sessionStorage.getItem(HIDE_SESSION)) { wrap.classList.add('away'); }
        } catch (e) {}
    }
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
            visitor: { name: visitor.name, email: visitor.email, phone: visitor.phone || '' },
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
        dropStarters();
        var bub = bubble('user', text, ready);
        pending = [];
        drawPending();
        postMessage(text, ready, bub);
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
    var BG_MS = Math.max(10, Math.min(600, parseInt(cfg.poll_idle_seconds, 10) || 30)) * 1000;
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
    /* Browsers refuse to make a sound until the page has had a user gesture,
     * and an AudioContext created before one stays "suspended" forever. So the
     * context is created/resumed on the visitor's first click or keystroke in
     * the widget, and resumed again right before each chime. */
    function armAudio() {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') { audio.resume(); }
        } catch (e) {}
    }
    wrap.addEventListener('click', armAudio, true);
    wrap.addEventListener('keydown', armAudio, true);
    function chime() {
        if (!SOUND) { return; }
        try {
            armAudio();
            if (!audio || audio.state !== 'running') { return; }
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
            });
            if (fresh.length) {
                chime();
                // unread = the visitor cannot be looking: panel closed, or tab in the background
                if (!wrap.classList.contains('open') || document.hidden) { addUnread(fresh.length); } else { markSeen(); }
            }
            var wasAgent = agentMode;
            agentMode = res.mode === 'agent';
            if (agentMode && !wasAgent) { enterAgentMode(true); }
            showAgentTyping(!!res.agent_typing && wrap.classList.contains('open'));
        });
    }

    /* ---- the visitor deletes their conversation ----
     * Soft on the server (Http\DeleteEndpoint): gone for the visitor at once,
     * kept for the team until it is erased. Here it is simply a fresh start. */
    var confirmBox = wrap.querySelector('.cf');
    if (session) { wrap.classList.add('has-chat'); }
    wrap.querySelector('.del').addEventListener('click', function () {
        confirmBox.hidden = false;
        confirmBox.querySelector('.cf-no').focus();
    });
    confirmBox.querySelector('.cf-no').addEventListener('click', function () { confirmBox.hidden = true; });
    confirmBox.querySelector('.cf-yes').addEventListener('click', function () {
        var yes = this;
        yes.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.endpoint + '/delete', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = 15000;
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            yes.disabled = false;
            var res = null;
            try { res = JSON.parse(xhr.responseText); } catch (e) {}
            confirmBox.hidden = true;
            if (!res || !res.ok) {
                bubble('sys', (res && res.error) || 'The conversation could not be deleted just now. Please try again.');
                return;
            }
            startOver();
        };
        xhr.send(JSON.stringify({ session_id: session, token: cfg.token || '' }));
    });
    function startOver() {
        stopPolling();
        showAgentTyping(false);
        session = '';
        lastAgentId = 0; oldestId = 0; agentMode = false;
        try { localStorage.removeItem(SS_KEY); localStorage.removeItem(SEEN_KEY); } catch (e) {}
        wrap.classList.remove('has-chat');
        clearUnread();
        offerEarlier(false);
        msgs.innerHTML = '';
        bubble('sys', 'Your conversation was deleted.');
        greeted = true;
        if (cfg.greeting) { bubble('bot', cfg.greeting); }
        if (guestNeeded()) { showGuest(true); } else { showStarters(); }
    }

    // at load: bring back the thread, then listen in the background
    restore(function () { if (session) { startPolling(!wrap.classList.contains('open')); } });

    /* ---- the on-screen keyboard ----
     * A phone's keyboard shrinks only the VISIBLE part of the page (the visual
     * viewport); fixed elements keep the full height, and the browser scrolls
     * to keep the input in view - which pushed the header and the newest
     * messages off the top of the chat link (a new visitor typed and saw
     * nothing). So the chat is sized to what is actually visible, and the
     * thread is kept scrolled to the newest message. */
    var vv = window.visualViewport;
    function toBottom() { msgs.scrollTop = msgs.scrollHeight; }
    function fitToScreen() {
        if (!vv) { return; }
        var hidden = Math.max(0, window.innerHeight - vv.height - vv.offsetTop); // what the keyboard covers
        if (MODE === 'page') {
            if (window.matchMedia && window.matchMedia('(min-width: 760px)').matches) {
                panel.style.height = ''; panel.style.top = ''; // the desktop card layout
            } else {
                panel.style.top = vv.offsetTop + 'px';
                panel.style.height = vv.height + 'px';
                panel.style.bottom = 'auto';
            }
        } else {
            // the floating widget rides above the keyboard instead of under it
            host.style.bottom = (20 + hidden) + 'px';
            panel.style.maxHeight = hidden > 0 ? Math.max(220, vv.height - 110) + 'px' : '';
        }
        if (wrap.classList.contains('open')) { toBottom(); }
    }
    if (vv) {
        vv.addEventListener('resize', fitToScreen);
        vv.addEventListener('scroll', fitToScreen);
    }
    input.addEventListener('focus', function () {
        setTimeout(function () { fitToScreen(); toBottom(); }, 250); // after the keyboard has opened
    });

    // shared as a link: the chat is the whole page, open from the first paint
    if (MODE === 'page') { openPanel(); }
})();
