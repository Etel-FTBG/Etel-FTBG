/* Team, business settings, sales and VAT report. */
(function () {
  'use strict';
  var E = window.E, V = E.views, esc = E.esc;

  V.team = function (root) {
    E.load(root, E.api('team.list'), function (rows) {
      var owner = E.can('owner');
      root.innerHTML = '<div class="between"><h1>Team</h1>' + (owner ? '<button class="small" id="add">+ Add person</button>' : '') + '</div><div class="card list">' + rows.map(function (m) {
        return '<div class="item" data-id="' + m.id + '"><div class="grow"><b>' + esc(m.full_name) + '</b><div class="muted">' + esc(m.etel_id) + ' &middot; ' + esc(m.phone) + '</div></div><span class="tag ' + (+m.active ? 'paid' : 'void') + '">' + esc(+m.active ? m.role : 'removed') + '</span></div>';
      }).join('') + '</div><p class="muted">Cashiers can sell and take payments. Managers can also manage items, stock, expenses, reports and cancel invoices. Only the owner manages the team and settings.</p>';
      var add = E.$('#add', root);
      if (add) add.onclick = function () {
        var role = 'cashier';
        E.sheet('<h2>Add a person</h2><label>Their EtelID number or phone</label><input id="who" placeholder="EtelID or phone"><div class="chips"><button class="chip on" data-r="cashier">Cashier</button><button class="chip" data-r="manager">Manager</button></div><button class="block" id="ok">Add</button><p class="muted">They must already have an EtelID.</p>', function (s, close) {
          E.$$('[data-r]', s).forEach(function (b) { b.onclick = function () { role = b.dataset.r; E.$$('[data-r]', s).forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
          E.$('#ok', s).onclick = function () { E.api('team.add', { body: { identifier: E.$('#who', s).value.trim(), role: role } }).then(function () { close(); E.toast('Added'); E.render(); }, function (e) { E.toast(e.message, true); }); };
        });
      };
      if (owner) E.$$('.item', root).forEach(function (el) {
        var m = rows.filter(function (x) { return x.id === +el.dataset.id; })[0]; if (m.role === 'owner') return;
        el.onclick = function () {
          E.sheet('<h2>' + esc(m.full_name) + '</h2><div class="chips"><button class="chip' + (m.role === 'cashier' ? ' on' : '') + '" data-r="cashier">Cashier</button><button class="chip' + (m.role === 'manager' ? ' on' : '') + '" data-r="manager">Manager</button></div>' +
            '<button class="block" id="ok">Save role</button><p></p><button class="alt block" id="rm" style="color:var(--red)">' + (+m.active ? 'Remove from team' : 'Add back') + '</button>', function (s, close) {
            var role = m.role;
            E.$$('[data-r]', s).forEach(function (b) { b.onclick = function () { role = b.dataset.r; E.$$('[data-r]', s).forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
            E.$('#ok', s).onclick = function () { E.api('team.set', { body: { id: m.id, role: role, active: true } }).then(function () { close(); E.render(); }, function (e) { E.toast(e.message, true); }); };
            E.$('#rm', s).onclick = function () { E.api('team.set', { body: { id: m.id, role: m.role, active: !+m.active } }).then(function () { close(); E.render(); }, function (e) { E.toast(e.message, true); }); };
          });
        };
      });
    });
  };

  V.settings = function (root) {
    E.load(root, E.api('business.get'), function (b) {
      var f = function (id, label, v, a) { return '<label>' + label + '</label><input id="' + id + '" value="' + esc(v == null ? '' : v) + '" ' + (a || '') + '>'; };
      root.innerHTML = '<h1>Business settings</h1><div class="card"><div class="muted">Plan</div><b>' + esc(b.effective_plan) + '</b>' + (b.plan_expires_at ? ' <span class="muted">until ' + esc(b.plan_expires_at) + '</span>' : '') + '</div><form id="f">' +
        f('n', 'Business name', b.name, 'maxlength="160"') + f('t', 'TIN (tax number)', b.tin) + f('p', 'Phone', b.phone) + f('a', 'Address', b.address) + f('x', 'Invoice prefix', b.invoice_prefix, 'maxlength="12"') +
        '<label class="row"><input type="checkbox" id="v" style="width:auto;margin:0 8px 0 0"' + (+b.vat_registered ? ' checked' : '') + '> We charge VAT (15%)</label><p></p>' +
        '<label class="row"><input type="checkbox" id="ng" style="width:auto;margin:0 8px 0 0"' + (+b.allow_negative_stock ? ' checked' : '') + '> Allow selling more than the stock count</label><p></p><button class="block">Save</button></form>';
      E.$('#f', root).onsubmit = function (ev) { ev.preventDefault(); E.api('business.update', { body: { name: E.$('#n', root).value, tin: E.$('#t', root).value, phone: E.$('#p', root).value, address: E.$('#a', root).value, invoice_prefix: E.$('#x', root).value, vat_registered: E.$('#v', root).checked, allow_negative_stock: E.$('#ng', root).checked } }).then(function (nb) { E.bizName = nb.name; E.toast('Saved'); }, function (e) { E.toast(e.message, true); }); };
    });
  };

  V.report = function (root) {
    var from = E.today().slice(0, 8) + '01', to = E.today();
    root.innerHTML = '<h1>Sales and VAT</h1><div class="grid2"><div><label>From</label><input type="date" id="f" value="' + from + '"></div><div><label>To</label><input type="date" id="t" value="' + to + '"></div></div><button class="block" id="go">Show</button><div id="out"></div>';
    function run() {
      E.api('report.sales', { query: { from: E.$('#f', root).value, to: E.$('#t', root).value } }).then(function (r) {
        E.$('#out', root).innerHTML = '<div class="card"><div class="between"><span>Invoices</span><b>' + r.invoices + '</b></div><div class="between"><span>Sales (before VAT)</span><b class="num">' + E.money(r.sales_excl_vat) + '</b></div>' +
          '<div class="between"><span>VAT charged</span><b class="num">' + E.money(r.vat) + '</b></div><div class="between"><span>Sales (with VAT)</span><b class="num">' + E.money(r.sales) + '</b></div><div class="between"><span>Expenses</span><b class="num">' + E.money(r.expenses) + '</b></div></div>' +
          '<h2>Money received</h2><div class="card">' + (r.received_by_method.map(function (m) { return '<div class="between"><span>' + esc(m.method) + ' (' + m.n + ')</span><b class="num">' + E.money(m.amount) + '</b></div>'; }).join('') || '<div class="empty">No payments in this period.</div>') + '</div>';
      }, function (e) { E.toast(e.message, true); });
    }
    E.$('#go', root).onclick = run; run();
  };
})();
