/* ETEL Admin - Couriers: the courier control room.
   Live counts that double as filters, search, vehicle filter, sort, card or table view, the live map, the "new couriers
   need approval" switch, quick approve / not approve for people waiting, and a courier drawer with their numbers
   (today / 7 / 30 days, average delivery time, fees), recent jobs and the controls: approve, edit vehicle / plate / zone,
   suspend or restore, print the ID slip.
   Data: admin_couriers_board, admin_courier_detail (api/controllers/PartnerAdminController.php); controls reuse
   admin_courier_set_approval, admin_courier_approval_mode (UserAdminController.php), admin_update_courier (boot.php).
   Standalone: replaces loadCouriers() so admin.js is not edited; the job-offer settings card above it is untouched.
   Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;

var VEH = { motorcycle: ['🏍️', 'Motorcycle'], bicycle: ['🚲', 'Bicycle'], car: ['🚗', 'Car'], on_foot: ['🚶', 'On foot'] };
var STATUS = { online: ['Online', 'green'], busy: ['On a job', 'amber'], offline: ['Offline', 'grey'] };
var GRAD = { online: ['#3ad08a', '#1fa463'], busy: ['#f0b429', '#d98e04'], offline: ['#9a95a8', '#6d6880'], pending: ['#a875d6', '#7b3fb0'], off: ['#d6364a', '#8f1f2e'] };
var V = { filter: '', q: '', veh: '', sort: 'recent', mode: 'cards', rows: [], sum: null, loaded: false, busy: false, openId: null, needApproval: null, canManage: true };
try { var m = localStorage.getItem('cou-mode'); if (m === 'table' || m === 'cards') V.mode = m; } catch (e) { /* private window */ }

function root() { return document.getElementById('cou-pro'); }
function state(r) { return r.suspended ? 'off' : (r.approval_status === 'pending' ? 'pending' : (r.status || 'offline')); }
function veh(r) { return VEH[r.vehicle_type] || ['🛵', r.vehicle_type ? String(r.vehicle_type).replace(/_/g, ' ') : 'No vehicle']; }
function pillFor(r) {
  if (r.suspended) return K.pill('Suspended', 'red');
  if (r.approval_status === 'pending') return K.pill('Waiting approval', 'amber');
  if (r.approval_status === 'rejected') return K.pill('Not approved', 'red');
  var s = STATUS[r.status] || STATUS.offline; return K.pill(s[0], s[1]);
}
function stars(n) { return n > 0 ? '⭐ ' + Number(n).toFixed(1) : '☆ New'; }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (typeof loadCourierOffers === 'function') { try { loadCourierOffers(); } catch (e) { /* settings card is optional */ } }
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  var mode = K.api('admin_courier_approval_mode', {});
  return K.api('admin_couriers_board', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load couriers', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = d.couriers || []; V.sum = d.summary || {}; V.loaded = true;
    try { couriersData = V.rows; } catch (e) { /* admin.js missing: Export CSV has no data */ }
    return mode.then(function (mr) { if (K.okRes(mr)) V.needApproval = !!K.data(mr).require; paint(); });
  });
}

function visibleRows() {
  var q = V.q.toLowerCase(), rows = V.rows.filter(function (r) {
    if (V.filter === 'pending' && r.approval_status !== 'pending') return false;
    if (V.filter === 'suspended' && !r.suspended) return false;
    if ((V.filter === 'online' || V.filter === 'busy' || V.filter === 'offline') && (r.suspended || r.approval_status === 'pending' || r.status !== V.filter)) return false;
    if (V.veh && r.vehicle_type !== V.veh) return false;
    if (q && (String(r.name || '') + ' ' + String(r.phone || '') + ' ' + String(r.courier_code || '') + ' ' + String(r.plate_number || '') + ' ' + String(r.zone || r.home_zone || '')).toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
  var by = { recent: function (a, b) { return b.id - a.id; }, rating: function (a, b) { return b.rating - a.rating; }, deliveries: function (a, b) { return b.total_deliveries - a.total_deliveries; },
             today: function (a, b) { return b.today_deliveries - a.today_deliveries; }, name: function (a, b) { return String(a.name || '').localeCompare(String(b.name || '')); } };
  return rows.sort(by[V.sort] || by.recent);
}

function paint() {
  var el = root(); if (!el) return;
  var s = V.sum || {}, focus = document.activeElement && document.activeElement.id === 'cou-q';
  var kp = [
    { key: '', icon: '👥', label: 'All couriers', value: K.num(s.total) },
    { key: 'online', icon: '🟢', label: 'Online', value: K.num(s.online) },
    { key: 'busy', icon: '🟡', label: 'On a job', value: K.num(s.busy) },
    { key: 'offline', icon: '⚪', label: 'Offline', value: K.num(s.offline) },
    { key: 'pending', icon: '🕒', label: 'Waiting approval', value: K.num(s.pending), warn: true },
    { key: 'suspended', icon: '⛔', label: 'Suspended', value: K.num(s.suspended) }
  ];
  var vopts = '<option value="">All vehicles</option>' + Object.keys(VEH).map(function (k) { return '<option value="' + k + '"' + (V.veh === k ? ' selected' : '') + '>' + VEH[k][1] + '</option>'; }).join('');
  var sopts = [['recent', 'Newest'], ['today', 'Most deliveries today'], ['deliveries', 'Most deliveries ever'], ['rating', 'Best rated'], ['name', 'Name A–Z']].map(function (o) { return '<option value="' + o[0] + '"' + (V.sort === o[1] || V.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
  var rows = visibleRows();
  var h = '<div class="ak">' +
    K.hero('Couriers', 'Who is working, who is waiting to be approved, and how everyone is doing. Tap a courier for the full picture and controls.', [
      ['Online now', K.num((s.online || 0) + (s.busy || 0))], ['Deliveries today', K.num(s.deliveries_today)], ['Average rating', s.avg_rating == null ? '—' : '⭐ ' + s.avg_rating]
    ]) +
    '<div class="ak-card" style="cursor:default;margin-bottom:12px;padding:12px 16px"><label style="display:flex;gap:10px;align-items:center;font-size:13px;cursor:pointer"><input type="checkbox" id="cou-req" ' + (V.needApproval ? 'checked ' : '') + (V.needApproval === null ? 'disabled ' : '') + 'style="width:auto;margin:0"> New couriers must be approved by an admin before they can go online or take jobs</label></div>' +
    K.kpis(kp, V.filter) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="cou-q" type="search" placeholder="Name, phone, code, plate, zone…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<select class="ak-sel" data-sel="veh" aria-label="Vehicle">' + vopts + '</select><select class="ak-sel" data-sel="sort" aria-label="Sort">' + sopts + '</select>' +
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh">🔄</button><button type="button" class="ak-btn" data-act="map">🗺️ Live map</button></div>' +
    '<div id="courier-live-map-wrap" style="display:none;margin-bottom:12px"><div id="courier-live-map" style="width:100%;height:360px;border-radius:16px;overflow:hidden;background:#191926"></div><div class="ak-count" id="courier-map-updated">—</div></div>' +
    '<span id="courier-map-toggle-tag" hidden></span>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num(V.rows.length) + ' couriers</div>';
  if (!rows.length) h += K.empty('🛵', V.rows.length ? 'No couriers match' : 'No couriers yet', V.rows.length ? 'Try clearing the filters.' : 'Couriers appear here when they register.');
  else h += V.mode === 'table' ? table(rows) : '<div class="ak-grid">' + rows.map(card).join('') + '</div>';
  var oldMap = document.getElementById('courier-live-map-wrap');   // keep the same map node: the map library owns what is inside it
  if (oldMap && oldMap.parentNode) oldMap.parentNode.removeChild(oldMap);
  el.innerHTML = h + '</div>';
  var fresh = document.getElementById('courier-live-map-wrap');
  if (oldMap && fresh && fresh.parentNode) fresh.parentNode.replaceChild(oldMap, fresh);
  if (focus) { var i = document.getElementById('cou-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function avatar(r, cls) {
  var g = GRAD[state(r)] || GRAD.offline;
  return '<div class="ak-av' + (cls ? ' ' + cls : '') + '" style="--a:' + g[0] + ';--b:' + g[1] + '">' + esc(K.initials(r.name)) + '</div>';
}
function card(r) {
  var v = veh(r), pend = r.approval_status === 'pending';
  return '<div class="ak-card' + (r.suspended ? ' off' : '') + (pend ? ' alert' : '') + '" tabindex="0" role="button" data-open="' + esc(r.id) + '">' +
    '<div class="ak-top">' + avatar(r) + '<div class="ak-nm"><b>' + esc(r.name || 'Courier') + '</b><small>' + esc(r.courier_code || '') + ' · ' + esc(r.phone || '') + '</small></div>' + pillFor(r) + '</div>' +
    '<div class="ak-chips"><span class="ak-chip">' + esc(v[0]) + ' ' + esc(v[1]) + '</span>' + (r.plate_number ? '<span class="ak-chip">' + esc(r.plate_number) + '</span>' : '') +
    ((r.zone || r.home_zone) ? '<span class="ak-chip">📍 ' + esc(r.zone || r.home_zone) + '</span>' : '') + (r.live_jobs ? '<span class="ak-chip">🛵 ' + esc(r.live_jobs) + ' live job' + (r.live_jobs === 1 ? '' : 's') + '</span>' : '') + '</div>' +
    '<div class="ak-row"><span>' + esc(stars(r.rating)) + ' · ' + esc(K.num(r.total_deliveries)) + ' total</span><span><b>' + esc(r.today_deliveries) + '</b> today</span></div>' +
    (pend ? '<div class="ak-acts" style="margin-top:10px"><button type="button" class="ak-btn good" data-quick="approved" data-uid="' + esc(r.user_id) + '">✓ Approve</button><button type="button" class="ak-btn danger" data-quick="rejected" data-uid="' + esc(r.user_id) + '">Not approve</button></div>' : '') +
    '</div>';
}
function table(rows) {
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Courier</th><th>Status</th><th>Vehicle</th><th>Zone</th><th>Rating</th><th>Today</th><th>Total</th></tr></thead><tbody>' +
    rows.map(function (r) {
      var v = veh(r);
      return '<tr data-open="' + esc(r.id) + '"><td><b>' + esc(r.name || 'Courier') + '</b><br><small>' + esc(r.courier_code || '') + ' · ' + esc(r.phone || '') + '</small></td><td>' + pillFor(r) + '</td><td>' + esc(v[0] + ' ' + v[1]) + '</td><td>' + esc(r.zone || r.home_zone || '—') +
        '</td><td>' + esc(stars(r.rating)) + '</td><td>' + esc(r.today_deliveries) + '</td><td>' + esc(K.num(r.total_deliveries)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
}

/* ---------- drawer ---------- */
function openCourier(id) {
  V.openId = id;
  var row = null; V.rows.forEach(function (r) { if (r.id === id) row = r; });
  var dr = K.openDrawer((row ? avatar(row, 'lg') : '<div class="ak-av lg">?</div>') + '<div class="ak-nm"><b>' + esc(row ? row.name : 'Courier') + '</b><small>Loading…</small></div>', K.skeleton(2));
  return K.api('admin_courier_detail', { id: id }).then(function (res) {
    if (V.openId !== id || !K.drawerEl()) return;
    if (!K.okRes(res)) { K.drawerBody().innerHTML = K.empty('⚠️', 'Could not open this courier', K.failText(res)); return; }
    drawer(K.data(res), row, dr);
  });
}
function drawer(d, row, dr) {
  var c = d.courier, s = d.stats || {}, r = Object.assign({}, row || {}, c, { suspended: !(Number(c.active) !== 0 && d.account_active) });
  var v = veh(r);
  var head = avatar(r, 'lg') + '<div class="ak-nm"><b>' + esc(c.name) + '</b><small>' + esc(c.courier_code || '') + ' · joined ' + esc(K.fullDate(c.created_at)) + '</small></div>' + pillFor(r);
  var h = '<div class="ak-sec"><div class="ak-stat3"><div><b>' + esc(s.today) + '</b><span>Delivered today</span></div><div><b>' + esc(s.d7) + '</b><span>Last 7 days</span></div><div><b>' + esc(s.d30) + '</b><span>Last 30 days</span></div>' +
    '<div><b>' + esc(s.live) + '</b><span>Live jobs now</span></div><div><b>' + esc(s.avg_minutes == null ? '—' : K.minutes(s.avg_minutes)) + '</b><span>Avg time (30 d)</span></div><div><b>' + esc(s.cancelled_30d) + '</b><span>Cancelled (30 d)</span></div></div></div>';
  h += '<div class="ak-sec"><h4>Profile</h4>' + K.kv([['Phone', '<a href="tel:' + esc(c.phone) + '">' + esc(c.phone) + '</a>', true], ['Vehicle', v[0] + ' ' + v[1]], ['Plate', c.plate_number || ''], ['Zone', c.zone || c.home_zone || ''], ['Rating', stars(c.rating) + ' · ' + K.num(c.total_deliveries) + ' deliveries'],
    ['Last seen', c.last_seen ? K.ago(c.last_seen) : ''], ['Fees earned (30 d)', s.fees_30d == null ? '' : K.money(s.fees_30d)], ['Approval', c.approval_status ? String(c.approval_status) + (c.approval_note ? ' — ' + c.approval_note : '') : '']]) + '</div>';
  var acts = '';
  if (c.approval_status === 'pending' || c.approval_status === 'rejected') acts += '<button type="button" class="ak-btn good" data-approve="approved">✓ Approve</button>';
  if (c.approval_status === 'pending' || c.approval_status === 'approved') acts += '<button type="button" class="ak-btn danger" data-approve="rejected">Not approve…</button>';
  acts += '<button type="button" class="ak-btn" data-slip>🖨 ID slip</button>';
  acts += r.suspended ? '<button type="button" class="ak-btn good" data-active="1">Restore courier</button>' : '<button type="button" class="ak-btn danger" data-active="0">Suspend courier…</button>';
  h += '<div class="ak-sec"><h4>Controls</h4><div class="ak-acts">' + acts + '</div></div>';
  h += '<div class="ak-sec"><h4>Edit details</h4><div style="display:grid;gap:8px"><select class="ak-in" data-f="vehicle_type">' + Object.keys(VEH).map(function (k) { return '<option value="' + k + '"' + (c.vehicle_type === k ? ' selected' : '') + '>' + VEH[k][1] + '</option>'; }).join('') + '</select>' +
    '<input class="ak-in" data-f="plate_number" maxlength="30" placeholder="Plate number" value="' + esc(c.plate_number || '') + '"><input class="ak-in" data-f="zone" maxlength="60" placeholder="Zone" value="' + esc(c.zone || c.home_zone || '') + '">' +
    '<div><button type="button" class="ak-btn gold" data-save>Save changes</button></div></div></div>';
  if (d.recent && d.recent.length) {
    h += '<div class="ak-sec"><h4>Recent jobs</h4><table class="ak-items">' + d.recent.map(function (o) {
      return '<tr><td><b>' + esc(o.order_code) + '</b> <small>' + esc(String(o.status).replace(/_/g, ' ')) + ' · ' + esc(o.from_zone || '') + ' → ' + esc(o.to_zone || '') + '</small></td><td>' + esc(K.money(o.fare_total)) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  dr.querySelector('.ak-dr-head').innerHTML = head + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button>';
  dr.querySelector('.ak-dr-body').innerHTML = h;
  dr._d = d;
}

function done(res, msg) {
  if (K.okRes(res)) { K.toast(msg); var id = V.openId; V.busy = false; load(true); if (id && K.drawerEl()) openCourier(id); return true; }
  K.toast(K.failText(res), true); return false;
}
function setApproval(userId, status) {
  var ask = status === 'rejected'
    ? K.confirm({ title: 'Not approve this courier?', text: 'They will not be able to go online. The reason is shown to them.', label: 'Not approve', danger: true, reason: 'Reason (optional)' })
    : K.confirm({ title: 'Approve this courier?', text: 'They can go online and take jobs straight away.', label: 'Approve' });
  return ask.then(function (r) { if (!r) return; return K.api('admin_courier_set_approval', { id: userId, status: status, note: r.reason || '' }).then(function (res) { done(res, status === 'approved' ? 'Courier approved' : 'Courier not approved'); }); });
}

function onDrawerClick(e) {
  var dr = K.drawerEl(); if (!dr || !dr.contains(e.target) || !dr._d || !dr._d.courier) return;
  var d = dr._d, c = d.courier, t = e.target, b;
  if ((b = t.closest('[data-approve]'))) setApproval(c.user_id, b.getAttribute('data-approve'));
  else if (t.closest('[data-slip]')) { if (typeof printCourierSlip === 'function') printCourierSlip(c.courier_code); else K.toast('Printing is not available here.', true); }
  else if ((b = t.closest('[data-active]'))) {
    var on = b.getAttribute('data-active') === '1';
    K.confirm({ title: on ? 'Restore this courier?' : 'Suspend this courier?', text: on ? 'They can work again.' : 'They cannot take new jobs while suspended.', label: on ? 'Restore' : 'Suspend', danger: !on }).then(function (r) {
      if (r) K.api('admin_update_courier', { courier_id: c.id, active: on ? 1 : 0 }).then(function (res) { done(res, on ? 'Courier restored' : 'Courier suspended'); });
    });
  } else if (t.closest('[data-save]')) {
    var body = { courier_id: c.id };
    Array.prototype.forEach.call(dr.querySelectorAll('[data-f]'), function (f) { body[f.getAttribute('data-f')] = f.value; });
    K.api('admin_update_courier', body).then(function (res) { done(res, 'Courier updated'); });
  }
}

/* ---------- wiring ---------- */
var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._couWired) return; el._couWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-quick]'))) { e.stopPropagation(); setApproval(parseInt(b.getAttribute('data-uid'), 10), b.getAttribute('data-quick')); return; }
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if ((b = t.closest('[data-seg]'))) { V.mode = b.getAttribute('data-val'); try { localStorage.setItem('cou-mode', V.mode); } catch (x) { /* ignore */ } paint(); return; }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if (t.closest('[data-act="map"]')) { if (typeof toggleCourierLiveMap === 'function') toggleCourierLiveMap(); else K.toast('The map is not available here.', true); return; }
    if ((b = t.closest('[data-open]'))) openCourier(parseInt(b.getAttribute('data-open'), 10));
  });
  el.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.ak-card[data-open]')) { e.preventDefault(); openCourier(parseInt(e.target.getAttribute('data-open'), 10)); }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'cou-q') { V.q = e.target.value.trim(); search(); } });
  el.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'cou-req') {
      K.api('admin_courier_approval_mode', { require: t.checked ? 1 : 0 }).then(function (res) {
        if (K.okRes(res)) { V.needApproval = !!K.data(res).require; K.toast(V.needApproval ? 'New couriers now need approval' : 'New couriers are approved automatically'); }
        else { K.toast(K.failText(res), true); t.checked = !t.checked; }
      });
    } else if (t.getAttribute && t.getAttribute('data-sel') === 'veh') { V.veh = t.value; paint(); }
    else if (t.getAttribute && t.getAttribute('data-sel') === 'sort') { V.sort = t.value; paint(); }
  });
}
document.addEventListener('click', onDrawerClick);
document.addEventListener('admin:filter', function (e) {   // opened from the dashboard's "Needs your attention"
  var d = e.detail || {}; if (d.page !== 'couriers') return;
  V.filter = d.filter || ''; V.q = '';
});

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadCouriers = function () { return load(V.loaded); };
  window.renderCourierTable = function () { if (V.loaded) paint(); };
  window.filterCourierTable = window.renderCourierTable;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
