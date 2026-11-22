/* Service giver app - Extras of an item as rows: a name, a price in ETB and a remove button, instead of one text box that needs "Name:price" lines.
   (Typing "Mayonis, 50" in the old box made an extra called "Mayonis, 50" that cost nothing.) The old textarea #item-extras stays as the single source the
   page already saves from; this script hides it, draws the rows and writes "Name:price" lines back into it on every change. When the page fills the box
   (opening an item to edit, or clearing it), the rows are redrawn from it. Customers choose how many of each extra (0 to 9) on their side.
   Additive: no inline handlers; every value is escaped. */
(function () {
'use strict';
var ta = null, wrap = null, rowsBox = null, last = null, MAX = 12;
function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
var AM = { 'Name (for example Mayonnaise)': 'ስም (ለምሼ ማዮኔዜ)', 'Price (ETB)': 'ዋጋ (ብር)', '+ Add an extra': '+ ተጨማሪ አውጥ',
  'Price is for ONE. Leave it empty for free. Customers can choose how many of each (0 to 9).': 'ዋጋው አንድ እንበገይ ነው። ነጻ ከመሂን ባዶ ክመተው። ደንበኞች እያንዳንዱን ከ 0 እስከ 9 መምረጥ ይችላሉ።' };
function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function clean(n) { return String(n || '').replace(/[:|\n\r=;]+/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, ''); }

// the lines of the box -> [{name, price}] (the same reading the server does: Name:price, also Name, price)
function parse(text) {
  var out = [];
  String(text || '').split(/[|\n]+/).forEach(function (part) {
    part = part.replace(/^\s+|\s+$/g, ''); if (!part) return;
    var m = /^(.*?)\s*[:=,;]\s*([0-9]+(?:\.[0-9]{1,2})?)$/.exec(part);
    out.push(m ? { name: m[1].replace(/^\s+|\s+$/g, ''), price: m[2] } : { name: part, price: '' });
  });
  return out.slice(0, MAX);
}
function serialize() {
  var lines = [];
  Array.prototype.forEach.call(rowsBox.querySelectorAll('[data-sgx-row]'), function (r) {
    var name = clean(r.querySelector('[data-sgx-name]').value), price = String(r.querySelector('[data-sgx-price]').value || '').replace(',', '.').replace(/[^0-9.]/g, '');
    if (!name) return;
    if (!/^[0-9]+(\.[0-9]{1,2})?$/.test(price)) price = '0';
    lines.push(name + ':' + price);
  });
  return lines.join('\n');
}
function sync() { last = serialize(); ta.value = last; }
function rowHtml(e) {
  return '<div data-sgx-row style="display:flex;gap:8px;align-items:center;margin-bottom:8px">' +
    '<input data-sgx-name type="text" class="field-input" maxlength="80" placeholder="' + esc(T('Name (for example Mayonnaise)')) + '" value="' + esc(e.name) + '" style="flex:1 1 55%;min-width:0">' +
    '<input data-sgx-price type="text" inputmode="decimal" class="field-input" maxlength="9" placeholder="' + esc(T('Price (ETB)')) + '" value="' + esc(e.price && parseFloat(e.price) > 0 ? e.price : '') + '" style="flex:0 0 28%;min-width:0">' +
    '<button type="button" data-sgx-del aria-label="Remove" style="flex:none;width:36px;height:36px;border-radius:10px;border:1px solid rgba(128,128,128,.4);background:transparent;cursor:pointer;color:inherit">✕</button></div>';
}
function draw() {
  var list = parse(ta.value);
  rowsBox.innerHTML = list.map(rowHtml).join('');
  last = ta.value;
  addBtn.style.display = list.length >= MAX ? 'none' : '';
}
var addBtn = null;
function build() {
  ta = document.getElementById('item-extras'); if (!ta || document.getElementById('sgx-wrap')) return false;
  ta.style.display = 'none';
  wrap = document.createElement('div'); wrap.id = 'sgx-wrap';
  rowsBox = document.createElement('div');
  addBtn = document.createElement('button'); addBtn.type = 'button'; addBtn.className = 'btn btn-secondary btn-small'; addBtn.textContent = T('+ Add an extra');
  var hint = document.createElement('div'); hint.style.cssText = 'font-size:12px;opacity:.7;margin-top:6px'; hint.textContent = T('Price is for ONE. Leave it empty for free. Customers can choose how many of each (0 to 9).');
  wrap.appendChild(rowsBox); wrap.appendChild(addBtn); wrap.appendChild(hint);
  ta.parentNode.insertBefore(wrap, ta.nextSibling);
  addBtn.addEventListener('click', function () { rowsBox.insertAdjacentHTML('beforeend', rowHtml({ name: '', price: '' })); sync(); if (rowsBox.querySelectorAll('[data-sgx-row]').length >= MAX) addBtn.style.display = 'none'; var n = rowsBox.querySelectorAll('[data-sgx-name]'); n[n.length - 1].focus(); });
  rowsBox.addEventListener('input', sync);
  rowsBox.addEventListener('click', function (e) { var d = e.target.closest && e.target.closest('[data-sgx-del]'); if (!d) return; d.parentNode.parentNode.removeChild(d.parentNode); addBtn.style.display = ''; sync(); });
  draw();
  return true;
}
function tick() {
  if (!ta && !build()) return;
  if (ta && ta.value !== last && !(rowsBox && rowsBox.contains(document.activeElement))) draw();   // the page filled or cleared the box (an item was opened)
}
window.__etelSgExtras = { parse: parse };
function init() { build(); setInterval(tick, 400); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
