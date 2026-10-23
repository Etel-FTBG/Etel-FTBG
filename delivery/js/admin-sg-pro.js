/* ETEL Admin - Service givers: the business directory.
   Counts that double as filters (verified / waiting for verification / suspended / online), search, type filter, sort,
   card or table view, quick verify on the card, and a drawer with the business's numbers (orders today / 7 / 30 days,
   open orders, sales, staff logins, rating) and recent orders, with the controls: verify, suspend, restore, and
   "Full profile…" which opens the existing profile / staff / payout editor.
   Data: admin_service_givers_board, admin_service_giver_detail (api/controllers/PartnerAdminController.php); controls reuse
   admin_verify_service_giver / admin_suspend_service_giver (boot.php). Standalone: replaces loadServiceGivers() so
   admin.js is not edited. Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;

var V = { filter: '', q: '', type: '', sort: 'recent', mode: 'cards', rows: [], sum: null, loaded: false, busy: false, openId: null };
try { var m = localStorage.getItem('sg-mode'); if (m === 'table' || m === 'cards') V.mode = m; } catch (e) { /* private window */ }
var GRAD = { verified: ['#3ad08a', '#1fa463'], pending: ['#f0b429', '#d98e04'], suspended: ['#d6364a', '#8f1f2e'] };

function root() { return document.getElementById('sg-pro'); }
function title(r) { return r.business_name || r.name || 'Business'; }
function pillFor(r) {
  if (r.status === 'suspended') return K.pill('Suspended', 'red');
  if (r.status === 'pending') return K.pill('Waiting verification', 'amber');
  return K.pill(r.is_online ? 'Open now' : 'Closed', r.is_online ? 'green' : 'grey');
}
function logo(r, cls) {
  var g = GRAD[r.status] || GRAD.verified, u = String(r.logo_url || '');
  if (/^(https?:)?\/\//i.test(u) || u.charAt(0) === '/') {
    return '<div class="ak-av' + (cls ? ' ' + cls : '') + '" style="--a:' + g[0] + ';--b:' + g[1] + ';overflow:hidden"><img src="' + esc(u) + '" alt="" style="width:100%;height:100%;object-fit:cover" loading="lazy"></div>';
  }
  return '<div class="ak-av' + (cls ? ' ' + cls : '') + '" style="--a:' + g[0] + ';--b:' + g[1] + '">' + esc(K.initials(title(r))) + '</div>';
}
function stars(n) { return n > 0 ? '⭐ ' + Number(n).toFixed(1) : '☆ New'; }

function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  K.css(); V.busy = true;
  if (!quiet && !V.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(6) + '</div>';
  return K.api('admin_service_givers_board', {}).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) { if (!V.loaded) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load service givers', K.failText(res)) + '</div>'; else K.toast(K.failText(res), true); return; }
    var d = K.data(res); V.rows = d.service_givers || []; V.sum = d.summary || {}; V.loaded = true;
    try { sgData = V.rows; } catch (e) { /* admin.js missing: Export CSV and the profile editor have no data */ }
    paint();
  });
}

function types() { var seen = {}; V.rows.forEach(function (r) { if (r.service_type) seen[r.service_type] = 1; }); return Object.keys(seen).sort(); }
function visibleRows() {
  var q = V.q.toLowerCase(), rows = V.rows.filter(function (r) {
    if (V.filter === 'online') { if (!r.is_online || r.status === 'suspended') return false; }
    else if (V.filter && r.status !== V.filter) return false;
    if (V.type && r.service_type !== V.type) return false;
    if (q && (String(r.business_name || '') + ' ' + String(r.name || '') + ' ' + String(r.phone || '') + ' ' + String(r.service_giver_code || '') + ' ' + String(r.zone || '')).toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
  var by = { recent: function (a, b) { return b.id - a.id; }, orders: function (a, b) { return b.orders_30d - a.orders_30d; }, rating: function (a, b) { return b.average_rating - a.average_rating; },
             earnings: function (a, b) { return b.total_earnings - a.total_earnings; }, name: function (a, b) { return title(a).localeCompare(title(b)); } };
  return rows.sort(by[V.sort] || by.recent);
}

function paint() {
  var el = root(); if (!el) return;
  var s = V.sum || {}, focus = document.activeElement && document.activeElement.id === 'sg-q';
  var kp = [
    { key: '', icon: '🏪', label: 'All businesses', value: K.num(s.total) },
    { key: 'verified', icon: '✅', label: 'Verified', value: K.num(s.verified) },
    { key: 'pending', icon: '🕒', label: 'Waiting verification', value: K.num(s.pending), warn: true },
    { key: 'online', icon: '🟢', label: 'Open now', value: K.num(s.online) },
    { key: 'suspended', icon: '⛔', label: 'Suspended', value: K.num(s.suspended) }
  ];
  var topts = '<option value="">All types</option>' + types().map(function (t) { return '<option value="' + esc(t) + '"' + (V.type === t ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('');
  var sopts = [['recent', 'Newest'], ['orders', 'Most orders (30 d)'], ['rating', 'Best rated'], ['earnings', 'Highest earnings'], ['name', 'Name A–Z']].map(function (o) { return '<option value="' + o[0] + '"' + (V.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
  var rows = visibleRows();
  var h = '<div class="ak">' +
    K.hero('Service givers', 'Every shop, restaurant and business on ETEL — who is open, who is waiting to be verified, and how they are selling.', [
      ['Open now', K.num(s.online)], ['Orders today', K.num(s.orders_today)], ['Average rating', s.avg_rating == null ? '—' : '⭐ ' + s.avg_rating]
    ]) + K.kpis(kp, V.filter) +
    '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="sg-q" type="search" placeholder="Business, owner, phone, code, zone…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<select class="ak-sel" data-sel="type" aria-label="Type">' + topts + '</select><select class="ak-sel" data-sel="sort" aria-label="Sort">' + sopts + '</select>' +
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh">🔄</button></div>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num(V.rows.length) + ' businesses</div>';
  if (!rows.length) h += K.empty('🏪', V.rows.length ? 'No businesses match' : 'No service givers yet', V.rows.length ? 'Try clearing the filters.' : 'Businesses appear here when they sign up.');
  else h += V.mode === 'table' ? table(rows) : '<div class="ak-grid">' + rows.map(card).join('') + '</div>';
  el.innerHTML = h + '</div>';
  if (focus) { var i = document.getElementById('sg-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

function card(r) {
  var pend = r.status === 'pending';
  return '<div class="ak-card' + (r.status === 'suspended' ? ' off' : '') + (pend ? ' alert' : '') + '" tabindex="0" role="button" data-open="' + esc(r.id) + '">' +
    '<div class="ak-top">' + logo(r) + '<div class="ak-nm"><b>' + esc(title(r)) + '</b><small>' + esc(r.service_giver_code || '') + (r.service_type ? ' · ' + esc(r.service_type) : '') + '</small></div>' + pillFor(r) + '</div>' +
    '<div class="ak-chips"><span class="ak-chip">👤 ' + esc(r.name || '—') + '</span>' + (r.zone ? '<span class="ak-chip">📍 ' + esc(r.zone) + '</span>' : '') + (r.open_orders ? '<span class="ak-chip">🧾 ' + esc(r.open_orders) + ' open</span>' : '') + '</div>' +
    '<div class="ak-row"><span>' + esc(stars(r.average_rating)) + (r.rating_count ? ' (' + esc(r.rating_count) + ')' : '') + '</span><span><b>' + esc(r.orders_today) + '</b> today · <b>' + esc(r.orders_30d) + '</b> in 30 d</span></div>' +
    (pend ? '<div class="ak-acts" style="margin-top:10px"><button type="button" class="ak-btn good" data-quick="verify" data-id="' + esc(r.id) + '">✓ Verify</button></div>' : '') + '</div>';
}
function table(rows) {
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Business</th><th>Status</th><th>Owner</th><th>Type</th><th>Zone</th><th>Rating</th><th>Orders 30 d</th><th>Earnings</th></tr></thead><tbody>' +
    rows.map(function (r) {
      return '<tr data-open="' + esc(r.id) + '"><td><b>' + esc(title(r)) + '</b><br><small>' + esc(r.service_giver_code || '') + '</small></td><td>' + pillFor(r) + '</td><td>' + esc(r.name || '—') + '<br><small>' + esc(r.phone || '') + '</small></td><td>' + esc(r.service_type || '—') +
        '</td><td>' + esc(r.zone || '—') + '</td><td>' + esc(stars(r.average_rating)) + '</td><td>' + esc(r.orders_30d) + '</td><td>' + esc(K.money(r.total_earnings)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
}

/* ---------- drawer ---------- */
function openSG(id) {
  V.openId = id;
  var row = null; V.rows.forEach(function (r) { if (r.id === id) row = r; });
  var dr = K.openDrawer((row ? logo(row, 'lg') : '<div class="ak-av lg">?</div>') + '<div class="ak-nm"><b>' + esc(row ? title(row) : 'Business') + '</b><small>Loading…</small></div>', K.skeleton(2));
  return K.api('admin_service_giver_detail', { id: id }).then(function (res) {
    if (V.openId !== id || !K.drawerEl()) return;
    if (!K.okRes(res)) { K.drawerBody().innerHTML = K.empty('⚠️', 'Could not open this business', K.failText(res)); return; }
    drawer(K.data(res), dr);
  });
}
function drawer(d, dr) {
  var g = d.service_giver, s = d.stats || {};
  var done = Number(g.completed_deliveries) || 0, total = Number(g.total_deliveries) || 0;
  var head = logo(g, 'lg') + '<div class="ak-nm"><b>' + esc(title(g)) + '</b><small>' + esc(g.service_giver_code || '') + ' · joined ' + esc(K.fullDate(g.created_at)) + '</small></div>' + pillFor(g);
  var h = '<div class="ak-sec"><div class="ak-stat3"><div><b>' + esc(s.today) + '</b><span>Orders today</span></div><div><b>' + esc(s.d7) + '</b><span>Last 7 days</span></div><div><b>' + esc(s.d30) + '</b><span>Last 30 days</span></div>' +
    '<div><b>' + esc(s.open) + '</b><span>Open orders now</span></div><div><b>' + esc(K.money(s.sales_30d)) + '</b><span>Sales (30 d)</span></div><div><b>' + esc(s.cancelled_30d) + '</b><span>Cancelled (30 d)</span></div></div></div>';
  h += '<div class="ak-sec"><h4>Business</h4>' + K.kv([['Owner', g.name], ['Phone', '<a href="tel:' + esc(g.phone) + '">' + esc(g.phone) + '</a>', true], ['Type', g.service_type || ''], ['Zone', g.zone || ''], ['Address', g.address || ''],
    ['Rating', stars(Number(g.average_rating)) + (g.rating_count ? ' · ' + g.rating_count + ' reviews' : '')], ['Deliveries', total ? done + ' of ' + total + ' completed (' + Math.round(done / total * 100) + '%)' : ''],
    ['Total earnings', g.total_earnings == null ? '' : K.money(g.total_earnings)], ['Staff logins', s.staff == null ? '' : String(s.staff)]]) + '</div>';
  var acts = '';
  if (g.status === 'pending') acts += '<button type="button" class="ak-btn good" data-verify>✓ Verify business</button>';
  if (g.status === 'suspended') acts += '<button type="button" class="ak-btn good" data-verify>Restore (verify)</button>';
  if (g.status !== 'suspended') acts += '<button type="button" class="ak-btn danger" data-suspend>Suspend…</button>';
  acts += '<button type="button" class="ak-btn gold" data-full>Full profile, staff &amp; payouts…</button>';
  h += '<div class="ak-sec"><h4>Controls</h4><div class="ak-acts">' + acts + '</div></div>';
  if (d.recent && d.recent.length) {
    h += '<div class="ak-sec"><h4>Recent orders</h4><table class="ak-items">' + d.recent.map(function (o) {
      return '<tr><td><b>' + esc(o.order_code) + '</b> <small>' + esc(String(o.status).replace(/_/g, ' ')) + ' · ' + esc(K.ago(o.placed_at)) + '</small></td><td>' + esc(K.money(o.fare_total)) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  dr.querySelector('.ak-dr-head').innerHTML = head + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button>';
  dr.querySelector('.ak-dr-body').innerHTML = h;
  dr._d = d;
}

function act(action, id, msg) {
  return K.api(action, { id: id }).then(function (res) {
    if (K.okRes(res)) { K.toast(msg); V.busy = false; load(true); if (V.openId === id && K.drawerEl()) openSG(id); return true; }
    K.toast(K.failText(res), true); return false;
  });
}
function verify(id) {
  return K.confirm({ title: 'Verify this business?', text: 'It will show to customers and can receive orders.', label: 'Verify' }).then(function (r) { if (r) return act('admin_verify_service_giver', id, 'Business verified'); });
}
function onDrawerClick(e) {
  var dr = K.drawerEl(); if (!dr || !dr.contains(e.target) || !dr._d || !dr._d.service_giver) return;
  var g = dr._d.service_giver, t = e.target;
  if (t.closest('[data-verify]')) verify(g.id);
  else if (t.closest('[data-suspend]')) K.confirm({ title: 'Suspend ' + title(g) + '?', text: 'It is taken offline and stops receiving orders until you verify it again.', label: 'Suspend', danger: true }).then(function (r) { if (r) act('admin_suspend_service_giver', g.id, 'Business suspended'); });
  else if (t.closest('[data-full]')) {
    K.closeDrawer();
    if (typeof openSGManageModal === 'function') openSGManageModal(g.id); else K.toast('The full profile editor is not available here.', true);
  }
}

/* ---------- wiring ---------- */
var search = K.debounce(function () { paint(); }, 200);
function wire(el) {
  if (el._sgWired) return; el._sgWired = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-quick]'))) { e.stopPropagation(); verify(parseInt(b.getAttribute('data-id'), 10)); return; }
    if ((b = t.closest('[data-kpi]'))) { var k = b.getAttribute('data-kpi'); V.filter = (V.filter === k ? '' : k); paint(); return; }
    if ((b = t.closest('[data-seg]'))) { V.mode = b.getAttribute('data-val'); try { localStorage.setItem('sg-mode', V.mode); } catch (x) { /* ignore */ } paint(); return; }
    if (t.closest('[data-act="refresh"]')) { load(true); return; }
    if ((b = t.closest('[data-open]'))) openSG(parseInt(b.getAttribute('data-open'), 10));
  });
  el.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.ak-card[data-open]')) { e.preventDefault(); openSG(parseInt(e.target.getAttribute('data-open'), 10)); }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'sg-q') { V.q = e.target.value.trim(); search(); } });
  el.addEventListener('change', function (e) {
    var a = e.target.getAttribute && e.target.getAttribute('data-sel');
    if (a === 'type') { V.type = e.target.value; paint(); } else if (a === 'sort') { V.sort = e.target.value; paint(); }
  });
}
document.addEventListener('click', onDrawerClick);
document.addEventListener('admin:filter', function (e) {   // opened from the dashboard's "Needs your attention"
  var d = e.detail || {}; if (d.page !== 'servicegivers') return;
  V.filter = d.filter || ''; V.q = '';
});

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadServiceGivers = function () { return load(V.loaded); };
  window.renderSGTable = window.renderSGCards = window.renderSGStatStrip = function () { if (V.loaded) paint(); };
  window.filterSGTable = window.renderSGTable;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
