/* Sign in, choose a business, home dashboard, More menu. */
(function () {
  'use strict';
  var E = window.E, V = E.views, esc = E.esc;

  V.login = function (root) {
    root.innerHTML = '<div class="login"><img src="assets/icons/logo.png" alt="Etel"><h1>EtelERP</h1><p class="muted">Sign in with your EtelID</p>' +
      '<form id="f" autocomplete="on"><input id="id" placeholder="EtelID number or phone" autocomplete="username" required>' +
      '<input id="pw" type="password" placeholder="Password" autocomplete="current-password" required>' +
      '<input id="otp" inputmode="numeric" maxlength="12" placeholder="Authenticator code (if you use two-step)" autocomplete="one-time-code">' +
      '<button class="block" id="go">Sign in</button></form><p class="muted">Forgot your password? Reset it in the EtelID portal.</p></div>';
    E.$('#f', root).onsubmit = function (ev) {
      ev.preventDefault();
      var b = E.$('#go', root); b.disabled = true; b.textContent = 'Signing in...';
      E.api('auth.login', { body: { identifier: E.$('#id', root).value.trim(), password: E.$('#pw', root).value, totp_code: E.$('#otp', root).value.trim() } }).then(function (d) {
        E.me = d.member; E.businesses = d.businesses; E.biz = null;
        var saved = null; try { saved = parseInt(localStorage.getItem('etelerp_biz'), 10); } catch (e) {}
        var pick = d.businesses.filter(function (x) { return x.id === saved; })[0] || (d.businesses.length === 1 ? d.businesses[0] : null);
        if (pick) E.setBiz(pick);
        E.go(E.biz ? '#/' : '#/biz'); E.render();
      }, function (e) { E.toast(e.message, true); b.disabled = false; b.textContent = 'Sign in'; });
    };
  };

  V.biz = function (root) {
    var rows = E.businesses.map(function (b) { return '<div class="item" data-id="' + b.id + '"><div class="grow"><b>' + esc(b.name) + '</b><div class="muted">' + esc(b.role) + ' &middot; ' + esc(b.plan) + ' plan</div></div><span>&rsaquo;</span></div>'; }).join('');
    root.innerHTML = '<h1>Welcome' + (E.me ? ', ' + esc(E.me.full_name.split(' ')[0]) : '') + '</h1>' +
      (rows ? '<h2>Your businesses</h2><div class="card list">' + rows + '</div>' : '<div class="empty">You are not part of a business yet.<br>Create yours below, or ask an owner to add your EtelID to theirs.</div>') +
      '<button class="block" id="new">+ Create a business</button><p></p><button class="alt block" id="out">Sign out</button>';
    E.$$('.item', root).forEach(function (el) { el.onclick = function () { var b = E.businesses.filter(function (x) { return x.id === +el.dataset.id; })[0]; E.setBiz(b); E.go('#/'); E.render(); }; });
    E.$('#new', root).onclick = function () { E.go('#/new'); };
    E.$('#out', root).onclick = V.signOut;
  };

  V.signOut = function () { E.api('auth.logout', { body: {} }).then(function () {}, function () {}).then(function () { E.me = null; E.biz = null; try { localStorage.removeItem('etelerp_biz'); } catch (e) {} E.go('#/login'); }); };

  V.new = function (root) {
    root.innerHTML = '<h1>Create your business</h1><form id="f"><label>Business name</label><input id="name" required maxlength="160">' +
      '<label>TIN (tax number, optional)</label><input id="tin" maxlength="40"><label>Phone</label><input id="phone" maxlength="40">' +
      '<label>Address</label><input id="addr" maxlength="255"><label>Invoice prefix</label><input id="pre" value="INV" maxlength="12">' +
      '<label class="row"><input type="checkbox" id="vat" style="width:auto;margin:0 8px 0 0"> We charge VAT (15%) on our invoices</label><p></p>' +
      '<button class="block">Create</button></form><p></p><button class="alt block" id="back">Back</button>';
    E.$('#back', root).onclick = function () { E.go('#/biz'); };
    E.$('#f', root).onsubmit = function (ev) {
      ev.preventDefault();
      E.api('business.create', { body: { name: E.$('#name', root).value, tin: E.$('#tin', root).value, phone: E.$('#phone', root).value, address: E.$('#addr', root).value, invoice_prefix: E.$('#pre', root).value, vat_registered: E.$('#vat', root).checked } }).then(function (d) {
        return E.api('auth.me').then(function (m) { E.businesses = m.businesses; E.setBiz(m.businesses.filter(function (b) { return b.id === d.id; })[0]); E.go('#/'); E.render(); });
      }, function (e) { E.toast(e.message, true); });
    };
  };

  V.home = function (root) {
    E.load(root, E.api('dashboard'), function (d) {
      var max = 1; d.sales_by_day.forEach(function (x) { max = Math.max(max, E.cents(x.total)); });
      var bars = d.sales_by_day.map(function (x) { return '<i title="' + esc(x.d) + ': ' + E.money(x.total) + '" style="height:' + Math.max(3, Math.round(E.cents(x.total) / max * 100)) + '%"></i>'; }).join('');
      var low = d.low_stock.map(function (i) { return '<div class="between"><span>' + esc(i.name) + '</span><b class="num">' + esc(E.qty(i.stock_qty)) + ' ' + esc(i.unit) + '</b></div>'; }).join('');
      var top = d.top_items.map(function (i) { return '<div class="between"><span>' + esc(i.description) + '</span><b class="num">' + E.money(i.amount) + '</b></div>'; }).join('');
      root.innerHTML = '<h1>Today</h1><div class="grid2"><div class="card"><div class="muted">Sales today</div><div class="big num">' + E.money(d.today.sales) + '</div><div class="muted">' + d.today.invoices + ' invoices</div></div>' +
        '<div class="card"><div class="muted">Money received today</div><div class="big num">' + E.money(d.today.collected) + '</div></div></div>' +
        '<div class="card"><div class="between"><div><div class="muted">Customers still owe you</div><div class="big num">' + E.money(d.receivables.amount) + '</div></div><a href="#/invoices?unpaid=1">' + d.receivables.invoices + ' unpaid &rsaquo;</a></div></div>' +
        '<h2>This month</h2><div class="card"><div class="between"><span>Sales (with VAT)</span><b class="num">' + E.money(d.month.sales) + '</b></div>' +
        '<div class="between"><span>VAT collected</span><b class="num">' + E.money(d.month.vat) + '</b></div>' +
        '<div class="between"><span>Expenses</span><b class="num">' + E.money(d.month.expenses) + '</b></div>' +
        '<div class="between"><span>Estimated profit (sales without VAT minus expenses)</span><b class="num">' + E.money(d.month.profit_estimate) + '</b></div></div>' +
        (bars ? '<h2>Last 14 days</h2><div class="card"><div class="bars">' + bars + '</div></div>' : '') +
        (top ? '<h2>Best sellers this month</h2><div class="card">' + top + '</div>' : '') +
        (low ? '<h2>Running low</h2><div class="card">' + low + '</div>' : '') +
        '<p></p><a class="btn block" href="#/sell" style="display:block;text-align:center;text-decoration:none">New sale</a>';
    });
  };

  V.more = function (root) {
    var links = [['#/customers', 'Customers', 'cashier'], ['#/items', 'Items and stock', 'cashier'], ['#/expenses', 'Expenses', 'manager'], ['#/report', 'Sales and VAT report', 'manager'], ['#/team', 'Team', 'manager'], ['#/settings', 'Business settings', 'owner']]
      .filter(function (l) { return E.can(l[2]); }).map(function (l) { return '<a class="item" href="' + l[0] + '" style="text-decoration:none;color:inherit"><div class="grow"><b>' + l[1] + '</b></div><span>&rsaquo;</span></a>'; }).join('');
    root.innerHTML = '<h1>More</h1><div class="card list">' + links + '</div><div class="card"><div class="muted">Signed in as</div><b>' + esc(E.me.full_name) + '</b> <span class="muted">(' + esc(E.role) + ')</span></div>' +
      '<button class="alt block" id="sw">Switch business</button><p></p><button class="alt block" id="out">Sign out</button>';
    E.$('#sw', root).onclick = function () { E.go('#/biz'); };
    E.$('#out', root).onclick = V.signOut;
  };
})();
