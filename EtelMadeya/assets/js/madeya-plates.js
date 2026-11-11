/* Etel Madeya - plate categories (1-5) as quick buttons + numbers-only plate entry.
   A plate is stored as "<category>-<digits>", e.g. 1-06369. */
(function () {
  'use strict';
  var CATS = [
    { c: 1, name: 'Taxi service', am: 'ታክሲ', color: '#e53935' },
    { c: 2, name: 'Private cars', am: 'የግል', color: '#1e6fe8' },
    { c: 3, name: 'Organization & transport', am: 'ድርጅት እና ትራንስፖርት', color: '#2e9e4f' },
    { c: 4, name: 'Government', am: 'መንግስት', color: '#1b1b1b' },
    { c: 5, name: 'NGO', am: 'መንግስታዊ ያልሆነ', color: '#f08a00' }
  ];
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function catOf(c) { for (var i = 0; i < CATS.length; i++) if (CATS[i].c == c) return CATS[i]; return null; }
  window.PLATE_CATS = CATS;
  window.plateCatName = function (c) { var x = catOf(c); return x ? x.name : ''; };
  window.plateCode = function (plate) { var m = /^([1-5])-/.exec(String(plate || '')); return m ? +m[1] : 0; };
  window.plateDigits = function (v) { return String(v || '').replace(/\D+/g, ''); };
  window.plateBuild = function (cat, digits) { return cat + '-' + digits; };
  /* HTML for a stored plate: coloured category chip + number. Legacy plates are shown as plain text. */
  window.plateHtml = function (plate) {
    var code = window.plateCode(plate), x = catOf(code);
    if (!x) return esc(plate);
    return '<span class="pl-chip" style="background:' + x.color + '" title="' + esc(x.name) + '">' + code + '</span><span class="pl-no">' + esc(String(plate).replace(/^[1-5]-/, '')) + '</span>';
  };
  /* Render the 5 category buttons into a container. onPick(code) runs on selection. */
  window.plateCatButtons = function (id, storeKey, onPick) {
    var box = document.getElementById(id); if (!box) return;
    var sel = 0; try { sel = +localStorage.getItem(storeKey) || 0; } catch (e) {}
    box.innerHTML = CATS.map(function (x) {
      return '<button type="button" class="pcat' + (x.c === sel ? ' sel' : '') + '" data-c="' + x.c + '" style="--pc:' + x.color + '"><b>' + x.c + '</b><span>' + esc(x.name) + '</span></button>';
    }).join('');
    box.setAttribute('data-sel', sel || '');
    box.onclick = function (e) {
      var b = e.target.closest ? e.target.closest('.pcat') : null; if (!b) return;
      box.querySelectorAll('.pcat').forEach(function (n) { n.classList.remove('sel'); });
      b.classList.add('sel'); box.setAttribute('data-sel', b.getAttribute('data-c'));
      try { localStorage.setItem(storeKey, b.getAttribute('data-c')); } catch (e2) {}
      if (onPick) onPick(+b.getAttribute('data-c'));
    };
  };
  window.plateCatSelected = function (id) { var b = document.getElementById(id); return b ? (+b.getAttribute('data-sel') || 0) : 0; };
  /* Numbers-only entry: numeric keypad on phones, anything else is stripped. */
  window.plateNumeric = function (el, onChange) {
    if (!el) return;
    el.setAttribute('inputmode', 'numeric'); el.setAttribute('pattern', '[0-9]*'); el.setAttribute('autocomplete', 'off');
    el.setAttribute('maxlength', '10');
    el.addEventListener('input', function () {
      var v = el.value.replace(/\D+/g, '').slice(0, 10); if (v !== el.value) el.value = v;
      if (onChange) onChange();
    });
  };
})();
