/* The Sell screen: pick items, choose how they pay, charge. Safe to tap twice (client_ref). */
(function () {
  'use strict';
  var E = window.E, V = E.views, esc = E.esc;
  var cart = null;
  function fresh() { cart = { ref: E.uid(), lines: [], customer: null, method: 'cash', credit: false, notes: '' }; }
  var METHODS = [['cash', 'Cash'], ['telebirr', 'Telebirr'], ['cbe', 'CBE'], ['chapa', 'Chapa'], ['bank', 'Bank'], ['other', 'Other']];

  function totals(vat) {
    var sub = 0, v = 0;
    cart.lines.forEach(function (l) {
      var q = E.milli(l.qty), p = E.cents(l.price); if (isNaN(q) || isNaN(p) || q <= 0) return;
      var ls = Math.floor((q * p * 2 + 1000) / 2000); sub += ls;
      if (vat && !l.exempt) v += Math.floor((ls * 1500 * 2 + 10000) / 20000);
    });
    return { sub: sub, vat: v, total: sub + v };
  }

  V.sell = function (root) {
    if (!cart) fresh();
    var items = [], biz = null, timer = null;
    Promise.all([E.api('items.list'), E.api('business.get')]).then(function (r) { items = r[0]; biz = r[1]; draw(); }, function (e) { root.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });

    function draw() {
      var vat = biz && +biz.vat_registered === 1, t = totals(vat);
      var lines = cart.lines.map(function (l, i) {
        return '<div class="item" style="cursor:default"><div class="grow"><b>' + esc(l.name) + '</b><div class="muted num">' + E.money(l.price) + ' each</div></div>' +
          '<button class="alt small" data-m="' + i + '">-</button><b class="num" style="min-width:34px;text-align:center">' + esc(E.qty(l.qty)) + '</b><button class="alt small" data-p="' + i + '">+</button></div>';
      }).join('');
      var chips = METHODS.map(function (m) { return '<button class="chip' + (!cart.credit && cart.method === m[0] ? ' on' : '') + '" data-method="' + m[0] + '">' + m[1] + '</button>'; }).join('') +
        '<button class="chip' + (cart.credit ? ' on' : '') + '" data-method="credit">Pay later</button>';
      root.innerHTML = '<h1>New sale</h1><input id="q" placeholder="Search items..." autocomplete="off"><div class="card list" id="results"></div>' +
        '<h2>Basket</h2><div class="card list">' + (lines || '<div class="empty">Tap an item above to add it.</div>') + '</div>' +
        '<div class="card"><div class="between"><span>Customer</span><button class="alt small" id="cust">' + (cart.customer ? esc(cart.customer.name) : 'Choose (optional)') + '</button></div></div>' +
        '<div class="card"><div class="between"><span>Subtotal</span><b class="num">' + E.money(t.sub) + '</b></div>' +
        (vat ? '<div class="between"><span>VAT 15%</span><b class="num">' + E.money(t.vat) + '</b></div>' : '') +
        '<div class="between"><span>Total</span><span class="big num">' + E.money(t.total) + '</span></div></div>' +
        '<div class="muted">How are they paying?</div><div class="chips">' + chips + '</div>' +
        '<button class="block" id="charge"' + (cart.lines.length ? '' : ' disabled') + '>' + (cart.credit ? 'Issue invoice (pay later)' : 'Charge ' + E.money(t.total)) + '</button><p></p><button class="alt block" id="clear">Clear</button>';
      var q = E.$('#q', root);
      q.oninput = function () { showResults(q.value); };
      showResults('');
      E.$$('[data-p]', root).forEach(function (b) { b.onclick = function () { var l = cart.lines[+b.dataset.p]; l.qty = String((E.milli(l.qty) + 1000) / 1000); draw(); }; });
      E.$$('[data-m]', root).forEach(function (b) { b.onclick = function () { var i = +b.dataset.m, l = cart.lines[i], n = E.milli(l.qty) - 1000; if (n <= 0) cart.lines.splice(i, 1); else l.qty = String(n / 1000); draw(); }; });
      E.$$('[data-method]', root).forEach(function (b) { b.onclick = function () { if (b.dataset.method === 'credit') cart.credit = true; else { cart.credit = false; cart.method = b.dataset.method; } draw(); }; });
      E.$('#clear', root).onclick = function () { fresh(); draw(); };
      E.$('#cust', root).onclick = pickCustomer;
      E.$('#charge', root).onclick = charge;
    }

    function showResults(text) {
      var box = E.$('#results', root), s = text.trim().toLowerCase();
      var list = items.filter(function (i) { return !s || i.name.toLowerCase().indexOf(s) >= 0 || (i.sku || '').toLowerCase().indexOf(s) >= 0; }).slice(0, 30);
      box.innerHTML = list.length ? list.map(function (i) {
        var stock = +i.track_stock ? '<div class="muted">' + esc(E.qty(i.stock_qty)) + ' ' + esc(i.unit) + ' in stock</div>' : '';
        return '<div class="item" data-i="' + i.id + '"><div class="grow"><b>' + esc(i.name) + '</b>' + stock + '</div><b class="num">' + E.money(i.price) + '</b></div>';
      }).join('') : '<div class="empty">No items found.' + (E.can('manager') ? ' Add items under More.' : '') + '</div>';
      E.$$('[data-i]', box).forEach(function (el) {
        el.onclick = function () {
          var it = items.filter(function (x) { return x.id === +el.dataset.i; })[0];
          var ex = cart.lines.filter(function (l) { return l.item_id === it.id; })[0];
          if (ex) ex.qty = String((E.milli(ex.qty) + 1000) / 1000);
          else cart.lines.push({ item_id: it.id, name: it.name, price: it.price, qty: '1', exempt: +it.vat_exempt === 1 });
          draw();
        };
      });
    }

    function pickCustomer() {
      E.api('customers.list').then(function (list) {
        var html = '<h2>Choose customer</h2><input id="cq" placeholder="Search..."><div class="list" id="cl"></div><button class="alt block" id="none">No customer</button>';
        E.sheet(html, function (s, close) {
          var fill = function () { var t = E.$('#cq', s).value.toLowerCase(); E.$('#cl', s).innerHTML = list.filter(function (c) { return !t || c.name.toLowerCase().indexOf(t) >= 0 || (c.phone || '').indexOf(t) >= 0; }).map(function (c) { return '<div class="item" data-c="' + c.id + '"><div class="grow"><b>' + esc(c.name) + '</b><div class="muted">' + esc(c.phone) + '</div></div></div>'; }).join('') || '<div class="empty">No customers yet. Add them under More.</div>'; E.$$('[data-c]', s).forEach(function (el) { el.onclick = function () { cart.customer = list.filter(function (c) { return c.id === +el.dataset.c; })[0]; close(); draw(); }; }); };
          E.$('#cq', s).oninput = fill; fill();
          E.$('#none', s).onclick = function () { cart.customer = null; close(); draw(); };
        });
      }, function (e) { E.toast(e.message, true); });
    }

    function charge() {
      var b = E.$('#charge', root); b.disabled = true; var label = b.textContent; b.textContent = 'Working...';
      var body = { client_ref: cart.ref, customer_id: cart.customer ? cart.customer.id : null, lines: cart.lines.map(function (l) { return { item_id: l.item_id, qty: l.qty }; }) };
      if (!cart.credit) body.pay = { method: cart.method };
      E.api('invoices.sell', { body: body }).then(function (inv) { fresh(); E.toast('Sale saved: ' + inv.number); E.go('#/invoice/' + inv.id); }, function (e) { E.toast(e.message, true); b.disabled = false; b.textContent = label; });
    }
  };
})();
