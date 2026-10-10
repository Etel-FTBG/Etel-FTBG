/* Customers, items and stock, expenses. */
(function () {
  'use strict';
  var E = window.E, V = E.views, esc = E.esc;
  var field = function (id, label, val, attrs) { return '<label>' + label + '</label><input id="' + id + '" value="' + esc(val == null ? '' : val) + '" ' + (attrs || '') + '>'; };
  function searchList(root, title, addLabel, canAdd, fetch, row, onRow, onAdd) {
    root.innerHTML = '<div class="between"><h1>' + title + '</h1>' + (canAdd ? '<button class="small" id="add">' + addLabel + '</button>' : '') + '</div><input id="q" placeholder="Search..."><div class="card list" id="out"></div>';
    var t, text = '';
    function go() {
      var out = E.$('#out', root); out.innerHTML = '<div class="empty">Loading...</div>';
      fetch(text).then(function (rows) {
        out.innerHTML = rows.length ? rows.map(row).join('') : '<div class="empty">Nothing here yet.</div>';
        E.$$('.item', out).forEach(function (el, i) { el.onclick = function () { onRow(rows[i]); }; });
      }, function (e) { out.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });
    }
    E.$('#q', root).oninput = function (ev) { clearTimeout(t); t = setTimeout(function () { text = ev.target.value.trim(); go(); }, 250); };
    var a = E.$('#add', root); if (a) a.onclick = function () { onAdd(go); };
    go();
  }

  V.customers = function (root) {
    function form(c, done) {
      c = c || {};
      E.sheet('<h2>' + (c.id ? 'Customer' : 'New customer') + '</h2>' + field('n', 'Name', c.name, 'maxlength="160"') + field('p', 'Phone', c.phone, 'inputmode="tel"') + field('t', 'TIN (optional)', c.tin) + field('a', 'Address', c.address) + field('e', 'Email', c.email) +
        '<button class="block" id="ok">Save</button>' + (c.id ? '<p></p><button class="alt block" id="arch" style="color:var(--red)">Remove customer</button>' : ''), function (s, close) {
        E.$('#ok', s).onclick = function () { E.api('customers.save', { body: { id: c.id, name: E.$('#n', s).value, phone: E.$('#p', s).value, tin: E.$('#t', s).value, address: E.$('#a', s).value, email: E.$('#e', s).value } }).then(function () { close(); done(); }, function (e) { E.toast(e.message, true); }); };
        var ar = E.$('#arch', s); if (ar) ar.onclick = function () { if (confirm('Remove this customer? Their invoices stay.')) E.api('customers.archive', { body: { id: c.id } }).then(function () { close(); done(); }, function (e) { E.toast(e.message, true); }); };
      });
    }
    var refresh;
    searchList(root, 'Customers', '+ Add', true, function (q) { return E.api('customers.list', { query: { q: q } }); },
      function (c) { return '<div class="item"><div class="grow"><b>' + esc(c.name) + '</b><div class="muted">' + esc(c.phone) + '</div></div>' + (E.cents(c.owing) > 0 ? '<b class="num" style="color:var(--red)">owes ' + E.money(c.owing) + '</b>' : '') + '</div>'; },
      function (c) { form(c, E.render); }, function (done) { form(null, done); });
  };

  V.items = function (root) {
    function form(it, done) {
      it = it || { track_stock: 0, vat_exempt: 0 };
      var mgr = E.can('manager');
      if (!mgr && it.id) { E.toast('Only a manager can change items.', true); return; }
      E.sheet('<h2>' + (it.id ? 'Item' : 'New item') + '</h2>' + field('n', 'Name', it.name, 'maxlength="160"') + field('s', 'Code / SKU (optional)', it.sku) +
        '<div class="grid2"><div>' + field('p', 'Selling price (ETB, before VAT)', it.price ? E.money(it.price).replace(/,/g, '') : '', 'inputmode="decimal"') + '</div><div>' + field('c', 'Cost (ETB)', it.cost ? E.money(it.cost).replace(/,/g, '') : '', 'inputmode="decimal"') + '</div></div>' +
        field('u', 'Unit (pcs, kg, cup...)', it.unit || 'pcs') + '<label class="row"><input type="checkbox" id="ts" style="width:auto;margin:0 8px 0 0"' + (+it.track_stock ? ' checked' : '') + '> Count stock for this item (untick for services)</label><p></p>' +
        field('r', 'Warn me when stock is at or below', it.reorder_level ? E.qty(it.reorder_level) : '0', 'inputmode="decimal"') +
        '<label class="row"><input type="checkbox" id="ex" style="width:auto;margin:0 8px 0 0"' + (+it.vat_exempt ? ' checked' : '') + '> No VAT on this item</label><p></p>' +
        '<button class="block" id="ok">Save</button>' + (it.id && +it.track_stock ? '<p></p><button class="alt block" id="adj">Add or remove stock</button>' : '') + (it.id ? '<p></p><button class="alt block" id="arch" style="color:var(--red)">Remove item</button>' : ''), function (s, close) {
        E.$('#ok', s).onclick = function () { E.api('items.save', { body: { id: it.id, name: E.$('#n', s).value, sku: E.$('#s', s).value, price: E.$('#p', s).value || '0', cost: E.$('#c', s).value || '0', unit: E.$('#u', s).value, track_stock: E.$('#ts', s).checked, reorder_level: E.$('#r', s).value || '0', vat_exempt: E.$('#ex', s).checked } }).then(function () { close(); done(); }, function (e) { E.toast(e.message, true); }); };
        var ar = E.$('#arch', s); if (ar) ar.onclick = function () { if (confirm('Remove this item? Old invoices keep it.')) E.api('items.archive', { body: { id: it.id } }).then(function () { close(); done(); }, function (e) { E.toast(e.message, true); }); };
        var ad = E.$('#adj', s); if (ad) ad.onclick = function () { close(); adjust(it, done); };
      });
    }
    function adjust(it, done) {
      E.sheet('<h2>Stock: ' + esc(it.name) + '</h2><div class="muted">Now: <b>' + esc(E.qty(it.stock_qty)) + ' ' + esc(it.unit) + '</b></div>' + field('aq', 'Add (+) or remove (-) how many?', '', 'inputmode="decimal" placeholder="e.g. 20 or -3"') +
        '<div class="chips"><button class="chip on" data-r="purchase">Bought more</button><button class="chip" data-r="adjust">Correction / damaged</button></div>' + field('note', 'Note (optional)', '') + '<button class="block" id="ok">Save</button>', function (s, close) {
        var reason = 'purchase';
        E.$$('[data-r]', s).forEach(function (b) { b.onclick = function () { reason = b.dataset.r; E.$$('[data-r]', s).forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
        E.$('#ok', s).onclick = function () { E.api('items.adjust', { body: { item_id: it.id, qty_change: E.$('#aq', s).value.trim(), reason: reason, note: E.$('#note', s).value } }).then(function () { close(); E.toast('Stock updated'); done(); }, function (e) { E.toast(e.message, true); }); };
      });
    }
    searchList(root, 'Items and stock', '+ Add', E.can('manager'), function (q) { return E.api('items.list', { query: { q: q } }); },
      function (i) { var low = +i.track_stock && E.milli(i.stock_qty) <= E.milli(i.reorder_level); return '<div class="item"><div class="grow"><b>' + esc(i.name) + '</b><div class="muted">' + (+i.track_stock ? '<span' + (low ? ' style="color:var(--red)"' : '') + '>' + esc(E.qty(i.stock_qty)) + ' ' + esc(i.unit) + ' in stock</span>' : 'Service') + '</div></div><b class="num">' + E.money(i.price) + '</b></div>'; },
      function (i) { form(i, E.render); }, function (done) { form(null, done); });
  };

  V.expenses = function (root) {
    var month = E.today().slice(0, 8) + '01';
    root.innerHTML = '<div class="between"><h1>Expenses</h1><button class="small" id="add">+ Add</button></div><div class="card list" id="out"></div>';
    function load() {
      E.api('expenses.list', { query: { from: month, to: E.today() } }).then(function (rows) {
        var tot = rows.reduce(function (s, r) { return s + E.cents(r.amount); }, 0);
        E.$('#out', root).innerHTML = (rows.length ? '<div class="between"><span class="muted">This month</span><b class="num">' + E.money(tot) + '</b></div>' : '') + (rows.map(function (r) {
          return '<div class="item" data-id="' + r.id + '"><div class="grow"><b>' + esc(r.category) + '</b><div class="muted">' + esc(r.description || r.vendor) + ' &middot; ' + esc(r.spent_at) + ' &middot; ' + esc(r.method) + '</div></div><b class="num">' + E.money(r.amount) + '</b></div>';
        }).join('') || '<div class="empty">No expenses this month.</div>');
        E.$$('.item', root).forEach(function (el) { el.onclick = function () { if (confirm('Remove this expense?')) E.api('expenses.void', { body: { id: +el.dataset.id } }).then(function () { load(); }, function (e) { E.toast(e.message, true); }); }; });
      }, function (e) { E.$('#out', root).innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });
    }
    E.$('#add', root).onclick = function () {
      E.sheet('<h2>New expense</h2>' + field('a', 'Amount (ETB)', '', 'inputmode="decimal"') + field('c', 'Category (rent, transport, salary...)', '') + field('d', 'What was it for?', '') + field('v', 'Paid to (optional)', '') + field('dt', 'Date', E.today(), 'type="date"') + '<button class="block" id="ok">Save</button>', function (s, close) {
        E.$('#ok', s).onclick = function () { E.api('expenses.add', { body: { amount: E.$('#a', s).value, category: E.$('#c', s).value, description: E.$('#d', s).value, vendor: E.$('#v', s).value, spent_at: E.$('#dt', s).value } }).then(function () { close(); load(); }, function (e) { E.toast(e.message, true); }); };
      });
    };
    load();
  };
})();
