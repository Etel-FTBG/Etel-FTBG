/* Invoice list, invoice / receipt page (printable), take payment, cancel. */
(function () {
  'use strict';
  var E = window.E, V = E.views, esc = E.esc;
  var METHODS = [['cash', 'Cash'], ['telebirr', 'Telebirr'], ['cbe', 'CBE'], ['chapa', 'Chapa'], ['bank', 'Bank'], ['other', 'Other']];
  function tag(status, pay) {
    if (status === 'issued') return '<span class="tag ' + pay + '">' + ({ paid: 'Paid', partial: 'Part paid', unpaid: 'Unpaid' }[pay] || pay) + '</span>';
    return '<span class="tag ' + status + '">' + (status === 'void' ? 'Cancelled' : 'Draft') + '</span>';
  }

  V.invoices = function (root) {
    var q = E.hashQuery(), filter = q.unpaid ? 'unpaid' : (q.status || 'all'), text = '';
    root.innerHTML = '<h1>Invoices</h1><input id="q" placeholder="Search by number or customer..."><div class="chips" id="chips"></div><div class="card list" id="out"></div>';
    function chips() {
      var defs = [['all', 'All'], ['unpaid', 'Owing'], ['issued', 'Issued'], ['void', 'Cancelled'], ['draft', 'Drafts']];
      E.$('#chips', root).innerHTML = defs.map(function (d) { return '<button class="chip' + (filter === d[0] ? ' on' : '') + '" data-f="' + d[0] + '">' + d[1] + '</button>'; }).join('');
      E.$$('[data-f]', root).forEach(function (b) { b.onclick = function () { filter = b.dataset.f; chips(); fetchList(); }; });
    }
    function fetchList() {
      var query = { q: text }; if (filter === 'unpaid') query.unpaid = 1; else if (filter !== 'all') query.status = filter;
      var out = E.$('#out', root); out.innerHTML = '<div class="empty">Loading...</div>';
      E.api('invoices.list', { query: query }).then(function (rows) {
        out.innerHTML = rows.length ? rows.map(function (r) {
          return '<div class="item" data-id="' + r.id + '"><div class="grow"><b>' + esc(r.number || 'Draft #' + r.id) + '</b> ' + tag(r.status, r.payment_status) + '<div class="muted">' + esc(r.customer_name || 'No customer') + ' &middot; ' + esc(r.issue_date) + '</div></div><b class="num">' + E.money(r.total) + '</b></div>';
        }).join('') : '<div class="empty">No invoices here yet.</div>';
        E.$$('.item', out).forEach(function (el) { el.onclick = function () { E.go('#/invoice/' + el.dataset.id); }; });
      }, function (e) { out.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; });
    }
    var t; E.$('#q', root).oninput = function (ev) { clearTimeout(t); t = setTimeout(function () { text = ev.target.value.trim(); fetchList(); }, 250); };
    chips(); fetchList();
  };

  V.invoice = function (root, args) {
    var id = +args[0];
    E.load(root, Promise.all([E.api('invoices.get', { query: { id: id } }), E.api('business.get')]), function (r) {
      var inv = r[0], biz = r[1];
      var lines = inv.lines.map(function (l) { return '<tr><td>' + esc(l.description) + '</td><td class="r">' + esc(E.qty(l.qty)) + '</td><td class="r">' + E.money(l.unit_price) + '</td><td class="r">' + E.money(l.line_subtotal) + '</td></tr>'; }).join('');
      var pays = inv.payments.map(function (p) {
        return '<div class="between"><span' + (+p.voided ? ' style="text-decoration:line-through;opacity:.6"' : '') + '>' + esc(p.method) + (p.reference ? ' (' + esc(p.reference) + ')' : '') + ' &middot; ' + esc(p.paid_at.slice(0, 16)) + '</span><span><b class="num">' + E.money(p.amount) + '</b>' +
          (!+p.voided && E.can('manager') ? ' <button class="alt small noprint" data-vp="' + p.id + '">Cancel</button>' : '') + '</span></div>';
      }).join('');
      var head = '<h3>' + esc(biz.name) + '</h3><div>' + (biz.tin ? 'TIN: ' + esc(biz.tin) + '<br>' : '') + esc(biz.address) + (biz.phone ? ' &middot; ' + esc(biz.phone) : '') + '</div>';
      root.innerHTML = '<div class="between noprint"><h1>' + esc(inv.number || 'Draft #' + inv.id) + '</h1>' + tag(inv.status, inv.payment_status) + '</div>' +
        '<div class="inv">' + head + '<p><b>' + (+biz.vat_registered ? 'TAX INVOICE' : 'INVOICE') + ' ' + esc(inv.number || '(draft)') + '</b><br>Date: ' + esc(inv.issue_date) + (inv.due_date ? ' &middot; Due: ' + esc(inv.due_date) : '') +
        '<br>Customer: ' + esc(inv.customer_name || 'Walk-in') + (inv.customer_tin ? ' (TIN ' + esc(inv.customer_tin) + ')' : '') + '</p>' +
        '<table><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr>' + lines + '</table>' +
        '<p class="r">Subtotal: ' + E.money(inv.subtotal) + '<br>' + (+biz.vat_registered ? 'VAT: ' + E.money(inv.vat_amount) + '<br>' : '') + '<b>Total: ETB ' + E.money(inv.total) + '</b><br>Paid: ' + E.money(inv.paid_amount) + ' &middot; <b>Balance: ' + E.money(inv.balance) + '</b></p>' +
        (inv.status === 'void' ? '<p><b>CANCELLED</b> ' + esc(inv.void_reason) + '</p>' : '') + (inv.notes ? '<p>' + esc(inv.notes) + '</p>' : '') + '<p style="font-size:12px;color:#555">Thank you.</p></div>' +
        (pays ? '<h2 class="noprint">Payments</h2><div class="card noprint">' + pays + '</div>' : '') +
        '<div class="noprint" id="acts"></div>';
      var acts = E.$('#acts', root), html = '';
      if (inv.status === 'draft') html += '<button class="block" id="issue">Issue invoice</button><p></p>';
      if (inv.status === 'issued' && E.cents(inv.balance) > 0) html += '<button class="block" id="pay">Take a payment</button><p></p>';
      if (inv.status !== 'void') html += '<button class="alt block" id="print">Print / save as PDF</button><p></p>';
      if (E.can('manager') && inv.status !== 'void') html += '<button class="alt block danger" id="void" style="color:var(--red)">' + (inv.status === 'draft' ? 'Discard draft' : 'Cancel invoice') + '</button>';
      acts.innerHTML = html;
      var on = function (sel, fn) { var b = E.$(sel, acts); if (b) b.onclick = fn; };
      on('#print', function () { window.print(); });
      on('#issue', function () { E.api('invoices.issue', { body: { id: id } }).then(function () { E.toast('Invoice issued'); E.render(); }, function (e) { E.toast(e.message, true); }); });
      on('#pay', function () { payForm(inv); });
      on('#void', function () {
        E.sheet('<h2>' + (inv.status === 'draft' ? 'Discard this draft?' : 'Cancel this invoice?') + '</h2><label>Reason</label><input id="why" placeholder="e.g. customer returned the goods"><button class="danger block" id="yes">Yes, ' + (inv.status === 'draft' ? 'discard' : 'cancel it') + '</button><p></p><button class="alt block" id="no">Keep it</button>', function (s, close) {
          E.$('#no', s).onclick = close;
          E.$('#yes', s).onclick = function () { E.api('invoices.void', { body: { id: id, reason: E.$('#why', s).value || (inv.status === 'draft' ? 'discarded' : '') } }).then(function () { close(); E.toast(inv.status === 'draft' ? 'Draft discarded' : 'Invoice cancelled'); E.go(inv.status === 'draft' ? '#/invoices' : '#/invoice/' + id); E.render(); }, function (e) { E.toast(e.message, true); }); };
        });
      });
      E.$$('[data-vp]', root).forEach(function (b) { b.onclick = function () { if (!confirm('Cancel this payment?')) return; E.api('payments.void', { body: { id: +b.dataset.vp } }).then(function () { E.toast('Payment cancelled'); E.render(); }, function (e) { E.toast(e.message, true); }); }; });
    });
  };

  function payForm(inv) {
    var method = 'cash', ref = E.uid();
    var html = '<h2>Take a payment</h2><div class="muted">Balance: <b class="num">' + E.money(inv.balance) + '</b></div><label>Amount</label><input id="amt" inputmode="decimal" value="' + esc(inv.balance) + '">' +
      '<div class="chips">' + METHODS.map(function (m) { return '<button class="chip' + (m[0] === 'cash' ? ' on' : '') + '" data-m="' + m[0] + '">' + m[1] + '</button>'; }).join('') + '</div>' +
      '<label>Reference (Telebirr / bank receipt number, optional)</label><input id="ref" maxlength="80"><button class="block" id="ok">Save payment</button>';
    E.sheet(html, function (s, close) {
      E.$$('[data-m]', s).forEach(function (b) { b.onclick = function () { method = b.dataset.m; E.$$('[data-m]', s).forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
      E.$('#ok', s).onclick = function () {
        var amt = E.$('#amt', s).value.trim(), btn = E.$('#ok', s);
        btn.disabled = true;
        E.api('payments.add', { body: { invoice_id: inv.id, amount: amt, method: method, reference: E.$('#ref', s).value, client_ref: ref } }).then(function () { close(); E.toast('Payment saved'); E.render(); }, function (e) { E.toast(e.message, true); btn.disabled = false; });
      };
    });
  }
})();
