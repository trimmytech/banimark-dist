/* The emoji picker shared by the panel's reply box and (in its own copy) the
   widget. No library: a curated set covering what support chats actually use,
   grouped, with a search box. Inserting at the caret keeps the draft intact. */
window.BanimarkEmoji = (function () {
  'use strict';
  var GROUPS = [
    ['Smileys', '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 🥲 😋 😛 😜 🤪 😝 🤗 🤭 🤔 🤐 😐 😑 😶 😏 😒 🙄 😬 😮‍💨 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠'],
    ['Gestures', '👍 👎 👌 🤌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🤝 🙏 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 💪 🫶 ✍️ 💅 🦾'],
    ['People', '😀 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙋 🙋‍♂️ 🙋‍♀️ 🤷 🤷‍♂️ 🤷‍♀️ 🤦 🙇 💁 🧑‍💻 👨‍💻 👩‍💻 🧑‍🔧 🕵️ 💂 👮 👷 🧑‍⚕️ 🎅 🦸 🦹'],
    ['Objects', '📎 📁 📂 📄 📃 📋 📌 📍 ✂️ 🔒 🔓 🔑 🗝️ 🔧 🔨 ⚙️ 🧰 🔗 ⛓️ 💡 🔦 🕯️ 🧯 🛒 💳 💰 💵 🧾 📦 📮 ✉️ 📧 📨 📩 📤 📥 📱 💻 🖥️ ⌨️ 🖨️ 🖱️ 💾 💿 📷 📹 🎧 🔊 🔔 🔕 ⏰ ⌛ ⏳ 📅 📆 🗓️ 📊 📈 📉'],
    ['Symbols', '✅ ☑️ ✔️ ❌ ❎ ⭕ 🚫 ⚠️ ❗ ❓ 💬 💭 🗯️ ♻️ 🔄 🔃 ➡️ ⬅️ ⬆️ ⬇️ ▶️ ⏸️ ⏹️ ⭐ 🌟 ✨ ⚡ 🔥 💥 💫 💯 🎉 🎊 🎁 🏆 🥇 ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 ❣️ 💕 👀 🧠 🫡'],
    ['Nature', '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🐦 🦆 🦉 🌵 🌲 🌳 🌴 🌱 🌿 🍀 🍁 🍂 🌸 🌺 🌻 🌷 🌹 🌍 🌙 ☀️ ⛅ ☁️ 🌧️ ⛈️ ❄️ 🌈 💧 🌊 🍎 🍊 🍋 🍉 🍇 🍓 🍕 🍔 🍟 🍿 ☕ 🍵 🍺 🥂 🎂'],
  ];
  var NAMES = { '👍': 'thumbs up yes ok', '👎': 'thumbs down no', '🙏': 'please thanks pray', '✅': 'check done tick yes',
    '❌': 'cross no wrong', '⚠️': 'warning careful', '🔥': 'fire hot', '🎉': 'party celebrate', '❤️': 'heart love',
    '😀': 'smile happy', '😂': 'laugh cry funny', '😊': 'smile blush', '😢': 'sad cry', '😡': 'angry mad',
    '📎': 'clip attach file', '📧': 'email mail', '💳': 'card payment', '🧾': 'receipt invoice', '⏰': 'time clock',
    '🤔': 'thinking hmm', '🙋': 'raise hand help' };

  /** Build a picker into `host`; calls onPick(emoji) and closes itself. */
  function create(host, onPick, styleFn) {
    var box = document.createElement('div');
    box.className = 'bm-emoji';
    box.hidden = true;
    var tabs = GROUPS.map(function (g, i) {
      return '<button type="button" class="bm-emoji-tab' + (i === 0 ? ' on' : '') + '" data-g="' + i + '">' + g[1].split(' ')[0] + '</button>';
    }).join('');
    box.innerHTML = '<div class="bm-emoji-top"><input type="text" class="bm-emoji-q" placeholder="Search…" aria-label="Search emoji">' +
      '</div><div class="bm-emoji-tabs">' + tabs + '</div><div class="bm-emoji-grid" role="listbox"></div>';
    host.appendChild(box);
    if (styleFn) { styleFn(box); }
    var grid = box.querySelector('.bm-emoji-grid'), q = box.querySelector('.bm-emoji-q');
    var group = 0;

    function render() {
      var term = q.value.trim().toLowerCase();
      var list;
      if (term) {
        list = [];
        GROUPS.forEach(function (g) {
          g[1].split(' ').forEach(function (e) {
            if (list.indexOf(e) < 0 && ((NAMES[e] || '').indexOf(term) > -1 || e.indexOf(term) > -1)) { list.push(e); }
          });
        });
      } else {
        list = GROUPS[group][1].split(' ');
      }
      grid.innerHTML = list.map(function (e) {
        return '<button type="button" class="bm-emoji-b" data-e="' + e + '" title="' + (NAMES[e] || '') + '">' + e + '</button>';
      }).join('') || '<span class="bm-emoji-none">Nothing matches that.</span>';
    }
    box.addEventListener('click', function (ev) {
      var tab = ev.target.closest('.bm-emoji-tab');
      if (tab) {
        group = parseInt(tab.getAttribute('data-g'), 10);
        q.value = '';
        Array.prototype.forEach.call(box.querySelectorAll('.bm-emoji-tab'), function (t) { t.classList.toggle('on', t === tab); });
        render();
        return;
      }
      var b = ev.target.closest('.bm-emoji-b');
      if (b) { onPick(b.getAttribute('data-e')); }
    });
    q.addEventListener('input', render);
    render();
    return {
      el: box,
      toggle: function (show) {
        box.hidden = show === undefined ? !box.hidden : !show;
        if (!box.hidden) { q.value = ''; render(); q.focus(); }
      },
      isOpen: function () { return !box.hidden; },
    };
  }

  /** Insert text at the caret of an input/textarea, keeping the draft and cursor sane. */
  function insertAt(field, text) {
    var start = field.selectionStart === null ? field.value.length : field.selectionStart;
    var end = field.selectionEnd === null ? field.value.length : field.selectionEnd;
    field.value = field.value.slice(0, start) + text + field.value.slice(end);
    field.selectionStart = field.selectionEnd = start + text.length;
    field.focus();
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }

  return { create: create, insertAt: insertAt, groups: GROUPS };
})();
