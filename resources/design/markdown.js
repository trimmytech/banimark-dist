/* The formatting a chat message may carry - **bold**, *italic*, `code`, fenced
   code, links, bullet/numbered lists, line breaks - and nothing else. Text is
   HTML-escaped FIRST; only these constructs become tags, and links are limited
   to http(s)/mailto. Twin of src/Ui/Markdown.php: both are checked against
   tests/fixtures/markdown.json, so a message renders the same for the visitor,
   the staff and the PHP-rendered transcript. */
window.BanimarkMarkdown = (function () {
  'use strict';
  // placeholders for lifted-out code use a NUL byte, which escaped text can never contain
  var NUL = String.fromCharCode(0);
  var CODE_PH = new RegExp(NUL + 'C(\\d+)' + NUL, 'g');
  var BLOCK_PH = new RegExp(NUL + 'B(\\d+)' + NUL, 'g');
  var BLOCK_ONLY = new RegExp('^' + NUL + 'B\\d+' + NUL + '$');
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function link(url, label) { return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>'; }
  function inline(line) {
    var s = esc(line), codes = [];
    s = s.replace(/`([^`\n]+)`/g, function (_, c) { codes.push('<code>' + c + '</code>'); return NUL + 'C' + (codes.length - 1) + NUL; });
    s = s.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/gi, function (_, t, u) { return link(u, t); });
    s = s.replace(/(^|[^="'>\/])(\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)"'])/gi, function (_, pre, u) { return pre + link(u, u); });
    s = s.replace(/\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\w)/g, '<em>$1</em>');
    s = s.replace(/(?<![\w_])_(?=\S)([^_\n]+?)(?<=\S)_(?!\w)/g, '<em>$1</em>');
    return s.replace(CODE_PH, function (_, i) { return codes[+i]; });
  }
  function render(text) {
    text = String(text || '').replace(/\r\n?/g, '\n').trim();
    if (!text) return '';
    var blocks = [];
    text = text.replace(/```[a-zA-Z0-9_-]*\n?([\s\S]*?)```/g, function (_, c) { blocks.push('<pre><code>' + esc(c.replace(/\n+$/, '')) + '</code></pre>'); return NUL + 'B' + (blocks.length - 1) + NUL; });
    var out = [], list = null;
    text.split('\n').forEach(function (line) {
      var ul = line.match(/^\s*[-*•]\s+(.*)$/), ol = !ul && line.match(/^\s*\d+[.)]\s+(.*)$/);
      if (ul || ol) {
        var kind = ul ? 'ul' : 'ol';
        if (list !== kind) { if (list) out.push('</' + list + '>'); out.push('<' + kind + '>'); list = kind; }
        out.push('<li>' + inline((ul || ol)[1]) + '</li>');
        return;
      }
      if (list) { out.push('</' + list + '>'); list = null; }
      out.push(line === '' ? '' : inline(line));
    });
    if (list) out.push('</' + list + '>');
    var html = '', para = [];
    function flush() { if (para.length) { html += '<p>' + para.join('<br>') + '</p>'; para = []; } }
    out.forEach(function (piece) {
      if (piece === '') { flush(); return; }
      if (/^<\/?(ul|ol)>$|^<li>/.test(piece) || BLOCK_ONLY.test(piece)) { flush(); html += piece; return; }
      para.push(piece);
    });
    flush();
    return html.replace(BLOCK_PH, function (_, i) { return blocks[+i]; });
  }
  return { render: render, esc: esc };
})();
