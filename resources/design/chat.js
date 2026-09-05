/* Live staff conversation: new messages appear without a reload, replies go
   out over fetch and land in the thread instantly, and the visitor's presence
   is shown from their widget heartbeat. Falls back to the plain form if
   anything is missing - the page still works with JavaScript off. */
(function () {
  'use strict';
  var root = document.querySelector('[data-live-chat]');
  if (!root) return;
  var msgsUrl = root.getAttribute('data-messages-url');
  var replyUrl = root.getAttribute('data-reply-url');
  var csrfName = root.getAttribute('data-csrf-name');
  var csrf = root.getAttribute('data-csrf');
  var after = parseInt(root.getAttribute('data-after') || '0', 10) || 0;
  var thread = root.querySelector('[data-thread]');
  var form = root.querySelector('form[data-reply]');
  var box = form.querySelector('[name=message]');
  var send = form.querySelector('button[type=submit]');
  var pill = document.querySelector('[data-mode-pill]');
  var presence = root.querySelector('[data-presence]');
  var typing = root.querySelector('[data-typing]');
  var flash = root.querySelector('[data-flash]');

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function fmtTime(ts) { if (!ts) return ''; var d = new Date(ts * 1000); return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0'); }

  function bubble(m) {
    var el = document.createElement('div');
    el.className = 'msg ' + m.role + ' in';
    el.setAttribute('data-id', m.id);
    if (m.role === 'tool') { el.innerHTML = '⚡ ' + esc(m.text); return el; }
    if (m.role === 'system') { el.textContent = m.text; return el; }
    el.innerHTML = esc(m.text) + '<div class="msg-meta">' + (m.role === 'agent' ? 'human agent · ' : m.role === 'assistant' ? 'AI · ' : '') + fmtTime(m.at) + '</div>';
    return el;
  }
  function append(list) {
    if (!list.length) return;
    var stick = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;
    list.forEach(function (m) {
      if (thread.querySelector('[data-id="' + m.id + '"]')) return;
      thread.appendChild(bubble(m));
      if (m.id > after) after = m.id;
    });
    if (stick) thread.scrollTop = thread.scrollHeight;
  }
  function setMode(mode) {
    if (!pill || !mode) return;
    pill.className = 'pill ' + mode; pill.textContent = mode.toUpperCase();
    root.setAttribute('data-mode', mode);
  }
  function setPresence(p) {
    if (!presence || !p) return;
    var online = p.last_seen_at && (Date.now() / 1000 - p.last_seen_at) < 45;
    presence.className = 'bm-presence ' + (online ? 'on' : 'off');
    presence.textContent = (p.visitor_label || 'Visitor') + (online ? ' · online now' : (p.last_seen_at ? ' · left the chat' : ''));
    // the dots mean exactly one thing: the visitor is typing right now
    if (typing) typing.hidden = !p.visitor_typing;
  }

  // our own typing is reported on the poll (throttled) so the visitor's widget can show dots
  var typingAt = 0;
  box.addEventListener('input', function () {
    var now = Date.now();
    if (now - typingAt > 2500) { typingAt = now; poll(true); }
  });

  function poll(typing) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', msgsUrl + (msgsUrl.indexOf('?') > -1 ? '&' : '?') + 'after=' + after + (typing ? '&typing=1' : ''), true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || xhr.status !== 200) return;
      var d; try { d = JSON.parse(xhr.responseText); } catch (e) { return; }
      append(d.messages || []); setMode(d.mode); setPresence(d.presence);
    };
    xhr.send();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var text = box.value.trim();
    if (!text) return;
    send.disabled = true;
    var fd = new FormData(); fd.append('message', text); if (csrfName) fd.append(csrfName, csrf);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', replyUrl, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      send.disabled = false;
      var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
      if (xhr.status !== 200 || !d || !d.ok) { form.removeEventListener('submit', arguments.callee); form.submit(); return; }
      box.value = ''; box.style.height = '';
      if (d.message) append([d.message]);
      setMode('agent');
      if (flash) { flash.hidden = !d.emailed; if (d.emailed) flash.textContent = 'The visitor had left the chat, so we emailed them your reply.'; }
      box.focus();
    };
    xhr.send(fd);
  });
  // Enter sends, Shift+Enter makes a new line; the box grows with the text
  box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', {cancelable: true})); } });
  box.addEventListener('input', function () { box.style.height = 'auto'; box.style.height = Math.min(160, box.scrollHeight) + 'px'; });
  // quick replies drop straight into the box
  root.addEventListener('click', function (e) {
    var q = e.target.closest('[data-quick]'); if (!q) return;
    box.value = q.getAttribute('data-quick'); box.dispatchEvent(new Event('input')); box.focus();
  });

  thread.scrollTop = thread.scrollHeight;
  poll();
  setInterval(poll, 3000);
})();
