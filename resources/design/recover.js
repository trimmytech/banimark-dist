/* Banimark - "Is there an update that fixes this?" on the admin error screen.
 *
 * The same honest progress as the changelog page's updater (panel.js): every
 * step is a real server call that has finished before its tick appears. It
 * runs against <admin>/recover/*, which needs no login and no panel code, so
 * it works when every admin page throws - the changelog page included.
 *
 *   check  -> nothing newer: send the owner to support (nothing here can fix it)
 *          -> newer: ask for the licence key, then fetch -> apply -> database
 *
 * Served as a same-origin file: customer CSPs block inline script. Server text
 * only ever goes in via textContent. Without JavaScript the forms post
 * normally and the recover page answers them.
 */
(function () {
  'use strict';

  var box = document.querySelector('[data-recover]');
  if (!box) { return; }
  var checkForm = box.querySelector('[data-recover-check]');
  if (!checkForm) { return; }
  var urls = {
    check: box.getAttribute('data-check'),
    fetch: box.getAttribute('data-fetch'),
    apply: box.getAttribute('data-apply'),
    database: box.getAttribute('data-database')
  };
  var support = box.getAttribute('data-support') || '';
  var tokenInput = checkForm.querySelector('input[name="_token"]');
  var token = tokenInput ? tokenInput.value : '';

  function post(url, fields, done) {
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
      if (d && typeof d.ok === 'boolean') { done(d.ok, d.message || d.error || '', d); return; }
      done(false, xhr.status === 0
        ? 'The connection dropped. Reload this page and try again.'
        : xhr.status === 419
          ? 'This page has been open too long. Reload it and try again.'
          : 'The server answered ' + xhr.status + '. Reload this page and try again.', {});
    };
    var body = ['_token=' + encodeURIComponent(token)];
    Object.keys(fields || {}).forEach(function (k) {
      body.push(encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]));
    });
    xhr.send(body.join('&'));
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text) { n.textContent = text; }
    return n;
  }

  function stepsBox(labels) {
    var steps = el('div', 'bm-steps');
    labels.forEach(function (label) {
      var row = el('div', 'bm-step');
      row.appendChild(el('span', 'dot'));
      var right = el('span');
      right.appendChild(el('span', 'what', label));
      row.appendChild(right);
      steps.appendChild(row);
    });
    box.appendChild(steps);
    return steps;
  }

  function mark(steps, i, state, why) {
    var row = steps.children[i];
    if (!row) { return; }
    row.className = 'bm-step' + (state ? ' ' + state : '');
    var right = row.lastChild;
    var note = right.querySelector('.why');
    if (why) {
      if (!note) { note = el('span', 'why'); right.appendChild(note); }
      note.textContent = why;
    } else if (note) { right.removeChild(note); }
  }

  /* nothing newer (or it would not install): a person has to look at it */
  function supportBlock(lead) {
    var wrap = el('div', 'bm-support');
    wrap.appendChild(el('p', '', lead + (support
      ? ' Please email support - the details of this error go with it.'
      : ' Please send the details on this page to whoever supplies your Banimark.')));
    if (support) {
      var a = el('a', 'btn', 'Email support');
      a.setAttribute('href', support);
      var p = el('p');
      p.appendChild(a);
      wrap.appendChild(p);
    }
    box.appendChild(wrap);
    return wrap;
  }

  function clearResults() {
    Array.prototype.forEach.call(box.querySelectorAll('.bm-steps, .bm-support, .bm-install, .bm-done'), function (n) {
      n.parentNode.removeChild(n);
    });
  }

  function installPanel(found) {
    var panel = el('div', 'bm-install');
    var h = el('h2', '', 'Banimark ' + found.version + ' is available');
    if (found.test) { h.appendChild(el('span', 'pill', 'TEST BUILD')); }
    panel.appendChild(h);
    if (found.notes) {
      var notes = el('p', 'muted', found.notes);
      notes.style.whiteSpace = 'pre-wrap';
      panel.appendChild(notes);
    }
    var form = el('form');
    var label = el('label');
    label.setAttribute('for', 'bmk-live');
    label.appendChild(el('b', '', 'Your licence key'));
    label.appendChild(document.createTextNode(' '));
    label.appendChild(el('span', 'muted', '(from the License page or your purchase email - it proves you own this site)'));
    var key = el('input');
    key.id = 'bmk-live';
    key.type = 'password';
    key.name = 'banimark_recover_key';
    key.autocomplete = 'off';
    key.required = true;
    key.placeholder = 'BM-XXXX-XXXX-XXXX-XXXX';
    var btn = el('button', '', 'Install ' + found.version + ' now');
    btn.type = 'submit';
    form.appendChild(label);
    form.appendChild(key);
    form.appendChild(btn);
    panel.appendChild(form);
    panel.appendChild(el('p', 'muted', "Downloads the release from Banimark, checks its signature, and swaps it in with a backup kept. Your data is not touched."));
    box.appendChild(panel);
    key.focus();

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!key.value.trim()) { key.focus(); return; }
      form.hidden = true;
      Array.prototype.forEach.call(box.querySelectorAll('.bm-support'), function (n) { n.parentNode.removeChild(n); });
      var old = panel.querySelector('.bm-steps');
      if (old) { old.parentNode.removeChild(old); }
      var steps = stepsBox([
        'Downloading Banimark ' + found.version + ' and checking it is ours',
        'Putting it in place, keeping a copy of the old one',
        'Updating your database'
      ]);
      panel.appendChild(steps);

      function fail(i, why, badKey) {
        mark(steps, i, 'bad', why);
        form.hidden = false;
        if (badKey) { key.value = ''; key.focus(); return; }
        supportBlock('If it will not install, a person has to look at it.');
      }

      mark(steps, 0, 'on');
      post(urls.fetch, { banimark_recover_key: key.value.trim(), banimark_recover_version: found.version }, function (ok, msg, d) {
        if (!ok) { return fail(0, msg, !!d.bad_key); }
        mark(steps, 0, 'done');
        mark(steps, 1, 'on');
        post(urls.apply, {}, function (ok2, msg2) {
          if (!ok2) { return fail(1, msg2, false); }
          mark(steps, 1, 'done', msg2);
          // a NEW request, so the database step runs the version just installed
          mark(steps, 2, 'on');
          post(urls.database, {}, function (ok3, msg3) {
            mark(steps, 2, ok3 ? 'done' : 'bad', msg3);
            var done = el('div', 'bm-done');
            done.appendChild(el('p', ok3 ? 'good' : '', ok3
              ? 'Installed. Reloading this page...'
              : 'The new version is installed. Reload this page to see whether the error is gone.'));
            var again = el('a', 'btn', 'Reload this page');
            again.setAttribute('href', window.location.href);
            done.appendChild(again);
            box.appendChild(done);
            if (ok3) { setTimeout(function () { window.location.reload(); }, 1400); }
          });
        });
      });
    });
  }

  checkForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    checkForm.hidden = true;
    clearResults();
    var steps = stepsBox(['Asking Banimark whether a newer version exists']);
    mark(steps, 0, 'on');
    post(urls.check, {}, function (ok, msg, d) {
      if (!ok) {
        mark(steps, 0, 'bad', msg);
        var btn = checkForm.querySelector('button');
        if (btn) { btn.textContent = 'Try again'; }
        checkForm.hidden = false;
        return;
      }
      mark(steps, 0, 'done', msg);
      if (d.available) {
        installPanel(d);
      } else if (d.manual) {
        supportBlock('It cannot be installed from this page.');
      } else {
        supportBlock('No update is available yet, so this has to be looked at by a person.');
      }
    });
  });
})();
