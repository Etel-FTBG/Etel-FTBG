/* ETEL Admin - Orders: the live order board.
   Counts that double as filters, search, today / 7 days / 30 days, card or table view, a "late" and "no courier" warning,
   and an order drawer with the whole story (customer, shop, courier, items, confirmations, staff log) and the controls:
   assign / reassign a courier, take it back, move the status on, force a stuck confirmation, cancel with a reason, print.
   Data: admin_orders_board, admin_order_detail (api/controllers/OrderAdminController.php); controls reuse
   admin_assign_courier, admin_take_back_order, admin_cancel_order (ManualDispatchController.php), admin_update_order,
   admin_force_confirm (AdminController.php). Standalone: it replaces loadOrders() so admin.js is not edited.
   Every server value is escaped with AdminKit.esc before it goes into HTML; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;

var ST = {
  pending:    { label: 'Waiting for courier', short: 'Waiting', tone: 'amber',  icon: '🕒' },
  assigned:   { label: 'Courier assigned',    short: 'Assigned', tone: 'blue',   icon: '🧑‍✈️' },
  picked_up:  { label: 'Picked up',           tone: 'violet', icon: '📦' },
  in_transit: { label: 'On the way',          tone: 'violet', icon: '🛵' },
  delivered:  { label: 'Delivered',           tone: 'green',  icon: '✅' },
  cancelled:  { label: 'Cancelled',           tone: 'red',    icon: '✕' }
};
var NEXT = { assigned: ['picked_up', 'Mark picked up'], picked_up: ['in_transit', 'Mark on the way'], in_transit: ['delivered', 'Mark delivered'] };
var V = { status: '', q: '', period: 'today', mode: 'cards', rows: [], sum: null, loaded: false, busy: false, timer: null, openId: null };
try { var m = localStorage.getItem('ord-mode'); if (m === 'table' || m === 'cards') V.mode = m; } catch (e) { /* private window */ }

function st(s) { return ST[s] || { label: String(s || '—').replace(/_/g, ' '), tone: 'grey', icon: '•' }; }
function root() { return document.getElementById('ord-pro'); }
function who(r) { return r.customer_name || r.recipient_name || 'Customer'; }
function payLabel(r) { var p = String(r.payment_method || ''); return p ? p.replace(/_/g, ' ') : ''; }
function ageText(r) {
  if (r.status === 'delivered' && r.delivered_at) return 'Delivered ' + K.ago(r.delivered_at);
  if (r.status === 'cancelled') return 'Cancelled' + (r.cancelled_at ? ' ' + K.ago(r.cancelled_at) : '');
  return 'Placed ' + K.ago(r.placed_at);
}

/* ---------- list ---------- */
function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  return K.api('admin_orders_board', { status: V.status, q: V.q, period: V.period, limit: 300 }).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load orders', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = d.orders || []; V.sum = d.summary || {}; V.loaded = true;
    try { ordersData = V.rows; } catch (e) { /* admin.js not loaded: Export CSV simply has no data */ }
    paint();
    if (!quiet || !V.sideLoaded) { V.sideLoaded = true; if (typeof loadConfirmations === 'function') loadConfirmations(); if (typeof loadOrderIssues === 'function') loadOrderIssues(); }
  });
}

function paint() {
  var el = root(); if (!el) return;
  var s = V.sum || {}, focus = document.activeElement && document.activeElement.id === 'ord-q';
  var kp = [
    { key: '', icon: '🧾', label: 'All orders', value: K.num(s.total) },
    { key: 'pending', icon: '🕒', label: 'Waiting', value: K.num(s.pending) },
    { key: 'assigned', icon: '🧑‍✈️', label: 'Assigned', value: K.num(s.assigned) },
    { key: 'on_way', icon: '🛵', label: 'On the way', value: K.num(s.on_way) },
    { key: 'delivered', icon: '✅', label: 'Delivered', value: K.num(s.delivered) },
    { key: 'cancelled', icon: '✕', label: 'Cancelled', value: K.num(s.cancelled) },
    { key: 'late', icon: '⏰', label: 'Late', value: K.num(s.late), warn: true }
  ];
  var cur = V.status === '' ? '' : V.status;
  var h = '<div class="ak">' +
    K.hero('Orders', 'Every order on ETEL as it happens. Tap an order to see everything and take control.', [
      ['Orders today', K.num(s.today)], ['Delivered revenue today', K.money(s.revenue_today)],
      ['Avg delivery (7 d)', s.avg_delivery_min == null ? '—' : K.minutes(s.avg_delivery_min)]
    ]) +
    K.kpis(kp, cur) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="ord-q" type="search" placeholder="Order code, customer, phone, place…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    K.seg('period', [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['all', 'All']], V.period) +
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) +
    '<button type="button" class="ak-btn" data-act="refresh">🔄</button></div>';
  var rows = V.rows;
  if (V.status === 'on_way') rows = rows.filter(function (r) { return r.status === 'picked_up' || r.status === 'in_transit'; });
  if (V.status === 'late') rows = rows.filter(function (r) { return r.late; });
  h += '<div class="ak-count"><span class="ak-live"></span>' + K.num(rows.length) + ' order' + (rows.length === 1 ? '' : 's') + ' shown' + (V.rows.length >= 300 ? ' (latest 300 — narrow the search)' : '') + '</div>';
  if (!rows.length) h += K.empty('🧾', 'No orders here', V.q ? 'Nothing matches your search.' : 'Orders will appear as customers place them.');
  else h += V.mode === 'table' ? table(rows) : '<div class="ak-grid">' + rows.map(card).join('') + '</div>';
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('ord-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function flags(r) {
  var f = '';
  if (r.late) f += '<span class="ak-chip warn">⏰ Late — ' + esc(K.minutes(r.age_min)) + '</span>';
  if (r.unassigned) f += '<span class="ak-chip warn">No courier yet</span>';
  if (r.pickup_conf === 'pending') f += '<span class="ak-chip">Pickup confirm waiting</span>';
  if (r.delivery_conf === 'pending') f += '<span class="ak-chip">Delivery confirm waiting</span>';
  return f;
}
function card(r) {
  var s = st(r.status);
  return '<div class="ak-card' + (r.late && r.status !== 'delivered' && r.status !== 'cancelled' ? ' alert' : '') + '" tabindex="0" role="button" data-open="' + esc(r.id) + '">' +
    '<div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">' + esc(s.icon) + '</div>' +
    '<div class="ak-nm"><b>' + esc(r.order_code) + '</b><small>' + esc(who(r)) + (r.customer_phone ? ' · ' + esc(r.customer_phone) : '') + '</small></div>' +
    K.pill(s.short || s.label, s.tone) + '</div>' +
    '<div class="ak-route"><i>📍</i><span>' + esc(r.from_zone || '—') + ' → ' + esc(r.to_zone || '—') + '</span></div>' +
    (r.shop_name ? '<div class="ak-route"><i>🏪</i><span>' + esc(r.shop_name) + '</span></div>' : '') +
    '<div class="ak-route"><i>🛵</i><span>' + (r.courier_name ? esc(r.courier_name) + (r.courier_code ? ' · ' + esc(r.courier_code) : '') : '<span style="color:var(--mute)">Not assigned</span>') + '</span></div>' +
    '<div class="ak-chips">' + (r.kind === 'package' ? '<span class="ak-chip">📦 Package' + (r.package_type ? ' · ' + esc(r.package_type) : '') + '</span>' : '') +
    (payLabel(r) ? '<span class="ak-chip">' + esc(payLabel(r)) + '</span>' : '') + flags(r) + '</div>' +
    '<div class="ak-row"><span>' + esc(ageText(r)) + '</span><b>' + esc(K.money(r.fare_total)) + '</b></div></div>';
}
function table(rows) {
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Order</th><th>Status</th><th>Customer</th><th>Route</th><th>Courier</th><th>Fare</th><th>Placed</th></tr></thead><tbody>' +
    rows.map(function (r) {
      var s = st(r.status);
      return '<tr data-open="' + esc(r.id) + '"><td><b>' + esc(r.order_code) + '</b>' + (r.late ? ' ⏰' : '') + '</td><td>' + K.pill(s.label, s.tone) + '</td><td>' + esc(who(r)) + '</td><td>' + esc(r.from_zone || '—') + ' → ' + esc(r.to_zone || '—') +
        '</td><td>' + (r.courier_name ? esc(r.courier_name) : '—') + '</td><td>' + esc(K.money(r.fare_total)) + '</td><td>' + esc(K.ago(r.placed_at)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
}

/* ---------- drawer ---------- */
function openOrder(id) {
  V.openId = id;
  var row = null; V.rows.forEach(function (r) { if (r.id === id) row = r; });
  var dr = K.openDrawer('<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🧾</div><div class="ak-nm"><b>' + esc(row ? row.order_code : 'Order') + '</b><small>Loading…</small></div>', K.skeleton(2));
  return K.api('admin_order_detail', { id: id }).then(function (res) {
    if (V.openId !== id || !K.drawerEl()) return;
    if (!K.okRes(res)) { K.drawerBody().innerHTML = K.empty('⚠️', 'Could not open this order', K.failText(res)); return; }
    drawer(K.data(res), dr);
  });
}

function drawer(d, dr) {
  var o = d.order, s = st(o.status), c = d.controls || {}, mo = o.money || {};
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">' + esc(s.icon) + '</div><div class="ak-nm"><b>' + esc(o.order_code) + '</b><small>' + (o.kind === 'package' ? 'Package' : 'Order') + ' · placed ' + esc(K.fullDate(o.placed_at)) + '</small></div>' + K.pill(s.label, s.tone);
  var h = '';
  h += '<div class="ak-sec"><h4>Route</h4>' + K.kv([['From', o.from_zone || '—'], ['To', o.to_zone || '—'], ['Package', o.package_type || ''], ['Recipient', [o.recipient_name, o.recipient_phone].filter(Boolean).join(' · ')], ['Address', o.recipient_address || ''], ['Notes', o.notes || '']]) + '</div>';
  var money = [];
  ['subtotal', 'delivery_fee', 'service_charge', 'cancel_fee'].forEach(function (k) { if (mo[k] != null && Number(mo[k]) !== 0) money.push([k.replace(/_/g, ' ').replace(/^./, function (x) { return x.toUpperCase(); }), K.money(mo[k])]); });
  money.push(['Total', K.money(mo.fare_total)]);
  if (o.payment_method) money.push(['Payment', String(o.payment_method).replace(/_/g, ' ') + (o.payment_status ? ' · ' + String(o.payment_status).replace(/_/g, ' ') : '')]);
  h += '<div class="ak-sec"><h4>Money</h4>' + K.kv(money) + '</div>';
  if (d.items && d.items.length) {
    h += '<div class="ak-sec"><h4>Items</h4><table class="ak-items">' + d.items.map(function (i) { return '<tr><td>' + esc(i.quantity) + ' × ' + esc(i.item_name) + '</td><td>' + esc(K.money(i.subtotal)) + '</td></tr>'; }).join('') + '</table></div>';
  }
  var people = [];
  if (d.customer) people.push(['Customer', '<b>' + esc(d.customer.name) + '</b> · <a href="tel:' + esc(d.customer.phone) + '">' + esc(d.customer.phone) + '</a>', true]);
  if (d.shop) people.push(['Shop', '<b>' + esc(d.shop.business_name) + '</b>' + (d.shop.phone ? ' · <a href="tel:' + esc(d.shop.phone) + '">' + esc(d.shop.phone) + '</a>' : '') + ' · ' + esc(String(d.shop.assign_status || '').replace(/_/g, ' ')), true]);
  if (d.courier) people.push(['Courier', '<b>' + esc(d.courier.name) + '</b> · ' + esc(d.courier.courier_code) + (d.courier.phone ? ' · <a href="tel:' + esc(d.courier.phone) + '">' + esc(d.courier.phone) + '</a>' : ''), true]);
  else people.push(['Courier', 'Not assigned']);
  h += '<div class="ak-sec"><h4>People</h4>' + K.kv(people) + '</div>';

  if (o.status === 'cancelled') {
    h += '<div class="ak-sec"><h4>Cancellation</h4>' + K.kv([['Reason', String(o.cancel_reason || '—').replace(/_/g, ' ')], ['By', o.cancelled_by || ''], ['Note', o.cancel_note || ''], ['When', o.cancelled_at ? K.fullDate(o.cancelled_at) : '']]) + '</div>';
  }
  if (d.confirmations && d.confirmations.length) {
    h += '<div class="ak-sec"><h4>Confirmations</h4>' + d.confirmations.map(function (cf) {
      var tone = cf.status === 'confirmed' ? 'green' : cf.status === 'pending' ? 'amber' : 'grey';
      return '<div class="ak-row" style="margin-top:6px"><span><b>' + esc(cf.type) + '</b> · ' + esc(K.ago(cf.requested_at)) + '</span><span>' + K.pill(cf.status, tone) +
        (cf.status === 'pending' ? ' <button type="button" class="ak-btn" data-force="' + esc(cf.id) + '" style="padding:4px 10px">Force confirm</button>' : '') + '</span></div>';
    }).join('') + '</div>';
  }

  /* controls */
  var acts = '';
  if (c.can_assign && d.couriers && d.couriers.length) {
    acts += '<div style="flex:1 1 100%"><select class="ak-in" data-courier>' + d.couriers.map(function (cr) {
      return '<option value="' + esc(cr.id) + '"' + (d.courier && String(d.courier.id) === String(cr.id) ? ' selected' : '') + '>' + esc((cr.name || 'Courier') + ' · ' + cr.courier_code + ' · ' + cr.status + (cr.zone ? ' · ' + cr.zone : '')) + '</option>';
    }).join('') + '</select></div><button type="button" class="ak-btn gold" data-assign>' + (d.courier ? 'Reassign courier' : 'Assign courier') + '</button>';
  } else if (c.can_assign) acts += '<span class="ak-chip warn">No approved courier is available to assign.</span>';
  if (c.can_take_back) acts += '<button type="button" class="ak-btn" data-takeback>Take back from courier</button>';
  if (NEXT[o.status]) acts += '<button type="button" class="ak-btn good" data-next="' + esc(NEXT[o.status][0]) + '">' + esc(NEXT[o.status][1]) + '</button>';
  acts += '<button type="button" class="ak-btn" data-print>🖨 Print receipt</button>';
  if (c.can_cancel) acts += '<button type="button" class="ak-btn danger" data-cancel>Cancel order…</button>';
  h += '<div class="ak-sec"><h4>Controls</h4><div class="ak-acts">' + acts + '</div></div>';

  if (d.log && d.log.length) {
    h += '<div class="ak-sec"><h4>Staff log</h4><ul class="ak-tl">' + d.log.map(function (l) {
      return '<li><b>' + esc(String(l.action || '').replace(/_/g, ' ')) + '</b>' + (l.detail ? ' — ' + esc(l.detail) : '') + '<small>' + esc(l.admin_name || 'Staff') + ' · ' + esc(K.fullDate(l.created_at)) + '</small></li>';
    }).join('') + '</ul></div>';
  }
  dr.querySelector('.ak-dr-head').innerHTML = head + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button>';
  dr.querySelector('.ak-dr-body').innerHTML = h;
  dr._d = d;
}

function act(action, body, okMsg) {
  return K.api(action, body).then(function (res) {
    if (K.okRes(res)) { K.toast(okMsg); var id = V.openId; load(true); if (id) openOrder(id); return true; }
    K.toast(K.failText(res), true); return false;
  });
}

function onDrawerClick(e) {
  var dr = K.drawerEl(); if (!dr || !dr.contains(e.target)) return false;
  var t = e.target.closest ? e.target : null; if (!t) return true;
  var d = dr._d; if (!d) return true;
  var oid = d.order.id, b;
  if ((b = t.closest('[data-assign]'))) {
    var sel = dr.querySelector('[data-courier]'); if (!sel) return true;
    b.disabled = true; act('admin_assign_courier', { order_id: oid, courier_id: parseInt(sel.value, 10) }, 'Courier assigned').then(function () { b.disabled = false; });
  } else if (t.closest('[data-takeback]')) {
    K.confirm({ title: 'Take this order back?', text: 'The courier is released and the order goes back to the waiting list.', label: 'Take back' }).then(function (r) { if (r) act('admin_take_back_order', { order_id: oid }, 'Order taken back'); });
  } else if ((b = t.closest('[data-next]'))) {
    var nx = b.getAttribute('data-next');
    K.confirm({ title: NEXT[d.order.status][1] + '?', text: 'This changes the order for the customer and the courier too.', label: 'Yes, update' }).then(function (r) { if (r) act('admin_update_order', { order_id: oid, status: nx }, 'Status updated'); });
  } else if ((b = t.closest('[data-force]'))) {
    K.confirm({ title: 'Force this confirmation?', text: 'Use this only when the shop or customer cannot confirm. It is written to the staff log.', label: 'Force confirm', danger: true }).then(function (r) { if (r) act('admin_force_confirm', { conf_id: parseInt(b.getAttribute('data-force'), 10) }, 'Confirmation forced'); });
  } else if (t.closest('[data-print]')) {
    if (typeof printOrderReceiptUI === 'function') printOrderReceiptUI(oid); else K.toast('Printing is not available here.', true);
  } else if (t.closest('[data-cancel]')) {
    var reasons = d.cancel_reasons || {}, ch = Object.keys(reasons).map(function (k) { return [k, reasons[k]]; });
    K.confirm({ title: 'Cancel ' + d.order.order_code + '?', text: 'The courier is released and a refund is flagged if the customer paid online.', label: 'Cancel order', danger: true, choices: ch, reason: 'Note for the record (optional)' }).then(function (r) {
      if (r) act('admin_cancel_order', { order_id: oid, reason: r.choice || 'other', note: r.reason }, 'Order cancelled');
    });
  }
  return true;
}

/* ---------- wiring ---------- */
var search = K.debounce(function () { load(true); }, 350);
function wire(el) {
  if (el._ordWired) return; el._ordWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.status = (V.status === k ? '' : k); if (V.status === 'late' || V.status === 'on_way') paint(); else load(true); return; }
    if ((b = t.closest('[data-seg]'))) {
      var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val');
      if (n === 'period') { V.period = v; load(true); } else { V.mode = v; try { localStorage.setItem('ord-mode', v); } catch (x) { /* ignore */ } paint(); }
      return;
    }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if ((b = t.closest('[data-open]'))) openOrder(parseInt(b.getAttribute('data-open'), 10));
  });
  el.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.ak-card[data-open]')) { e.preventDefault(); openOrder(parseInt(e.target.getAttribute('data-open'), 10)); }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'ord-q') { V.q = e.target.value.trim(); search(); } });
}
document.addEventListener('click', function (e) { onDrawerClick(e); });

function visible() { var el = document.getElementById('panel-orders'); return el && el.style.display !== 'none' && !document.hidden; }
function tick() { if (visible() && !K.drawerEl()) load(true); }

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadOrders = function () { return load(V.loaded); };
  window.renderOrderTable = function () { if (V.loaded) paint(); };
  window.filterOrderTable = window.renderOrderTable;
  if (!V.timer) V.timer = setInterval(tick, 30000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
