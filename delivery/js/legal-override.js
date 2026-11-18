/* Privacy and Terms pages: when the owner wrote their own text in Admin (ETEL app > Privacy and accounts), show it instead of the built-in text.
   The text is put on the page as plain text only (never as markup). With no custom text, or no connection, the built-in page stays as it is.
   Blank line = new paragraph. A line that starts with "# " = a heading. Page: data-legal-doc="privacy" or "terms" on <body>. */
(function () {
    'use strict';
    var doc = document.body && document.body.getAttribute('data-legal-doc');
    var box = document.querySelector('.content');
    if (!doc || !box || !window.fetch) return;
    fetch('api/boot.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'get_legal_doc', doc: doc }) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var d = j && j.data;
            if (!j || !j.success || !d || !d.custom || !d.body) return;
            var title = document.querySelector('.hero-title'); if (title && d.title) title.textContent = d.title;
            var when = document.querySelector('.hero-date'); if (when && d.updated_at) when.textContent = 'Last updated: ' + String(d.updated_at).substring(0, 10);
            var contact = box.querySelector('.contact-box');   // the contact card stays under the owner's text
            while (box.firstChild) box.removeChild(box.firstChild);
            String(d.body).split(/\n{2,}/).forEach(function (block) {
                block = block.trim(); if (!block) return;
                var sec = document.createElement('div'); sec.className = 'section';
                var lines = block.split('\n');
                if (/^#\s+/.test(lines[0])) {
                    var h = document.createElement('div'); h.className = 'section-title'; h.textContent = lines.shift().replace(/^#\s+/, ''); sec.appendChild(h);
                }
                if (lines.length) {
                    var b = document.createElement('div'); b.className = 'section-body';
                    var p = document.createElement('p'); p.style.whiteSpace = 'pre-line'; p.textContent = lines.join('\n'); b.appendChild(p); sec.appendChild(b);
                }
                box.appendChild(sec);
            });
            if (contact) box.appendChild(contact);
        })
        .catch(function () { /* keep the built-in page */ });
})();
