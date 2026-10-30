/* ETEL Admin - Service givers: the business directory.
   Counts that double as filters (verified / waiting for verification / suspended / online), search, type filter, sort,
   card or table view, quick verify on the card, and a drawer with the business's numbers (orders today / 7 / 30 days,
   open orders, sales, staff logins, rating) and recent orders, with the controls: verify, suspend, restore, and
   "Full profile…" which opens the existing profile / staff / payout editor.
   "Register a business" opens js/admin-sg-register.js (go live now, or pre-register and hand over with a claim link). The drawer's
   The drawer also shows a go-live checklist (logo, cover, hours, location, owner phone, payout, menu) with a quick action per row.
   "Hand-over" section edits owner name / phone and makes a new claim link (admin_sg_update_owner, admin_sg_claim_invite).
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
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh">🔄</button><button type="button" class="ak-btn gold" data-act="register">➕ Register a business</button></div>' +
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

/* ---------- claim link + pre-register ---------- */
function claimLink(code) { return location.origin + location.pathname.replace(/[^\/]*$/, '') + 'claim.html?code=' + encodeURIComponent(code); }
function copyText(text) {
  var done = function () { K.toast('Copied'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { K.toast('Select the text and copy it by hand', true); }); return; }
  var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { K.toast('Select the text and copy it by hand', true); } t.remove();
}
function claimBox(code, expires) {
  var link = claimLink(code);
  return '<div class="ak-sec"><h4>Claim link for the owner</h4>' +
    '<p class="ak-fh" style="margin:0 0 8px">Shown only now. Send it to the owner: they open it, add their own phone number and password, then you verify the business. It works once' + (expires ? ' and until ' + esc(K.fullDate(expires)) : '') + '.</p>' +
    '<input class="ak-in" readonly value="' + esc(link) + '" data-sel-all aria-label="Claim link">' +
    '<div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn gold" data-copy="' + esc(link) + '">Copy link</button>' +
    '<button type="button" class="ak-btn" data-copy="' + esc(code) + '">Copy code ' + esc(code) + '</button></div></div>';
}
/* ---------- go-live checklist ---------- */
function apiBase() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
function uploadPhoto(file, type) {
  var fd = new FormData(); fd.append('image', file); fd.append('type', type || 'shop_cover');
  var tok = typeof Auth !== 'undefined' ? Auth.getToken() : '';
  return fetch(apiBase().replace('boot.php', 'upload.php'), { method: 'POST', headers: { 'Authorization': 'Bearer ' + tok }, body: fd }).then(function (r) { return r.json(); });
}
function hhmm(v) { return v ? String(v).slice(0, 5) : ''; }
function readinessBox(rd, g) {
  if (!rd) return '';
  var pct = rd.total ? Math.round(rd.done / rd.total * 100) : 0;
  var rows = rd.items.map(function (it) {
    var act = '';
    if (!it.ok) {
      if (it.key === 'logo' || it.key === 'cover') act = '<label class="ak-btn gold" style="cursor:pointer">Add ' + (it.key === 'logo' ? 'logo' : 'cover') + '<input type="file" accept="image/*" hidden data-brand="' + it.key + '_url"></label>';
      else if (it.key === 'owner') act = '<button type="button" class="ak-btn" data-gl="owner">Enter phone</button>';
      else if (it.key === 'documents') act = '<button type="button" class="ak-btn" data-gl="docs">See papers</button>';
      else if (it.key === 'menu') act = '<button type="button" class="ak-btn gold" data-gl="menu">Import menu</button>';
      else if (it.key === 'location' || it.key === 'payout') act = '<button type="button" class="ak-btn" data-gl="full">Open profile</button>';
    }
    var hours = it.key === 'hours' ? '<div class="ak-acts" style="margin-top:6px"><input id="gl-open" class="ak-in" type="time" value="' + esc(hhmm(g.opens_at)) + '" aria-label="Opens" style="max-width:120px"><input id="gl-close" class="ak-in" type="time" value="' + esc(hhmm(g.closes_at)) + '" aria-label="Closes" style="max-width:120px"><button type="button" class="ak-btn" data-gl="hours">Save hours</button></div>' : '';
    return '<div class="gl-row" style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--line,rgba(128,128,128,.2))">' +
      '<span aria-hidden="true" style="font-size:16px;line-height:1.3">' + (it.ok ? '✅' : '⬜') + '</span>' +
      '<div style="flex:1;min-width:0"><b style="font-size:13.5px">' + esc(it.label) + '</b>' + (it.ok ? '' : '<div class="ak-fh" style="margin:2px 0 0">' + esc(it.hint) + '</div>') + hours + '</div>' + (act ? '<div>' + act + '</div>' : '') + '</div>';
  }).join('');
  return '<div class="ak-sec"><h4>Go-live checklist · ' + rd.done + ' of ' + rd.total + '</h4>' +
    '<div style="height:8px;border-radius:6px;background:rgba(128,128,128,.25);margin:2px 0 8px"><div style="height:8px;border-radius:6px;width:' + pct + '%;background:' + (pct >= 100 ? '#2ecc71' : pct >= 50 ? '#D4AF37' : '#e74c3c') + '"></div></div>' + rows + '</div>';
}
/* ---------- welcome message ---------- */
function welcomeText(g) {
  var link = location.origin + location.pathname.replace(/[^\/]*$/, '') + 'servicegiver.html';
  return 'Welcome to ETEL, ' + (g.name || 'partner') + '! 🎉\n\n' + title(g) + ' is now live on ETEL and customers can order from you.\n\n' +
    'Sign in: ' + link + '\nPhone: ' + (g.phone || '') + '\n\n' +
    'Tips:\n• Keep your shop switched to Open while you can take orders.\n• Accept new orders quickly.\n• Mark an item hidden when it runs out.\n\nQuestions? Reply to this message.';
}
function welcomeBox(g) {
  if (g.status !== 'verified') return '';
  var t = welcomeText(g);
  return '<div class="ak-sec"><h4>Welcome message</h4><p class="ak-fh" style="margin:0 0 8px">The business is live. Copy this and send it to the owner (SMS, Telegram or WhatsApp).</p>' +
    '<textarea class="ak-in" rows="9" readonly data-sel-all aria-label="Welcome message">' + esc(t) + '</textarea>' +
    '<div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn gold" data-copy="' + esc(t) + '">Copy message</button></div></div>';
}
/* ---------- papers (licence, ID) ---------- */
var DOC_PILL = { pending: ['Waiting for your check', 'amber'], approved: ['Approved', 'green'], rejected: ['Sent back', 'red'] };
function docsBox(d) {
  var types = d.doc_types || {}, have = {};
  (d.documents || []).forEach(function (x) { have[x.doc_type] = x; });
  var rows = Object.keys(types).map(function (k) {
    var t = types[k], x = have[k], pill = x ? K.pill(DOC_PILL[x.status][0], DOC_PILL[x.status][1]) : K.pill(t.required ? 'Not handed in' : 'Optional', 'grey');
    var up = '<label class="ak-btn" style="cursor:pointer">' + (x ? 'Replace photo' : 'Upload photo') + '<input type="file" accept="image/*" hidden data-doc-up="' + esc(k) + '"></label>';
    var view = x ? '<a class="ak-btn" href="' + esc(x.file_url) + '" target="_blank" rel="noopener">View</a>' : '';
    var rev = '';
    if (x && x.status === 'pending') {
      rev = '<div class="ak-acts" style="margin-top:6px"><button type="button" class="ak-btn good" data-doc-ok="' + x.id + '">✓ Approve</button>' +
        '<input class="ak-in" type="text" maxlength="200" placeholder="What is wrong? (to reject)" aria-label="Reason" data-doc-why="' + x.id + '" style="flex:1;min-width:140px">' +
        '<button type="button" class="ak-btn danger" data-doc-no="' + x.id + '">Reject</button></div>';
    }
    return '<div style="padding:8px 0;border-top:1px solid var(--line,rgba(128,128,128,.2))"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><b style="font-size:13.5px;flex:1">' + esc(t.label) + (t.required ? '' : ' <small>(optional)</small>') + '</b>' + pill + view + up + '</div>' +
      (x && x.status === 'rejected' && x.reject_reason ? '<div class="ak-fh" style="margin:4px 0 0">Reason: ' + esc(x.reject_reason) + '</div>' : '') + rev + '</div>';
  }).join('');
  return '<div class="ak-sec"><h4>Papers</h4><p class="ak-fh" style="margin:0 0 4px">The owner hands these in (or you upload them for them). Only you can see the photos.</p>' + rows + '</div>';
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
  var cl = d.claim || {}, hand = '';
  if (cl.placeholder_phone) hand += '<p class="ak-fh" style="margin:0 0 8px;color:var(--amber,#d98e04)">This account has a spare phone number. The owner sets their real one when they claim it, or you can type it here.</p>';
  hand += '<p class="ak-fh" style="margin:0 0 8px">' + (cl.claimed ? 'The owner has claimed this account and set their own phone and password.' : cl.invited ? 'A claim link is waiting (until ' + esc(K.fullDate(cl.expires_at)) + '). It is not shown again; make a new one to see it.' : 'No claim link is waiting.') + '</p>' +
    K.field('Owner name', '<input id="sgo-name" class="ak-in" type="text" value="' + esc(g.name || '') + '">') +
    K.field('Owner phone (sign-in)', '<input id="sgo-phone" class="ak-in" type="tel" value="' + esc(g.phone || '') + '">') +
    '<div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn" data-owner-save>Save owner details</button><button type="button" class="ak-btn gold" data-claim-new>📨 New claim link</button></div><div id="sgo-claim"></div>';
  h += welcomeBox(g) + readinessBox(d.readiness, g) + docsBox(d);
  h += '<div class="ak-sec" id="sgo-sec"><h4>Hand-over to the owner</h4>' + hand + '</div>';
  var acts = '';
  if (g.status === 'pending') acts += '<button type="button" class="ak-btn good" data-verify>✓ Verify business</button>';
  if (g.status === 'suspended') acts += '<button type="button" class="ak-btn good" data-verify>Restore (verify)</button>';
  if (g.status !== 'suspended') acts += '<button type="button" class="ak-btn danger" data-suspend>Suspend…</button>';
  var biz = Number(g.business_tools) === 1;
  acts += '<button type="button" class="ak-btn ' + (biz ? '' : 'good') + '" data-biz="' + (biz ? 0 : 1) + '">' + (biz ? 'Switch off Etel Business' : 'Switch on Etel Business') + '</button>';
  acts += '<button type="button" class="ak-btn gold" data-full>Full profile, staff &amp; payouts…</button>';
  h += '<div class="ak-sec"><h4>Controls</h4><div class="ak-acts">' + acts + '</div>' +
    '<p style="font-size:12.5px;color:var(--mute);margin:10px 2px 0">Etel Business gives this shop its own books that work with no internet: walk-in sales, stock, expenses and the daily cash count. Currently <b>' + (biz ? 'on' : 'off') + '</b>.</p></div>';
  if (d.recent && d.recent.length) {
    h += '<div class="ak-sec"><h4>Recent orders</h4><table class="ak-items">' + d.recent.map(function (o) {
      return '<tr><td><b>' + esc(o.order_code) + '</b> <small>' + esc(String(o.status).replace(/_/g, ' ')) + ' · ' + esc(K.ago(o.placed_at)) + '</small></td><td>' + esc(K.money(o.fare_total)) + '</td></tr>';
    }).join('') + '</table></div>';
  }
  dr.querySelector('.ak-dr-head').innerHTML = head + '<button type="button" class="ak-x" data-close aria-label="Close">✕</button>';
  dr.querySelector('.ak-dr-body').innerHTML = h;
  dr._d = d;
}

function act(action, id, msg, extra) {
  var body = { id: id }; if (extra) Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
  return K.api(action, body).then(function (res) {
    if (K.okRes(res)) { K.toast(msg); V.busy = false; load(true); if (V.openId === id && K.drawerEl()) openSG(id); return true; }
    K.toast(K.failText(res), true); return false;
  });
}
function verify(id, rd) {
  var miss = rd ? rd.items.filter(function (i) { return !i.ok; }).map(function (i) { return i.label; }) : [];
  return K.confirm({ title: 'Verify this business?', text: 'It will show to customers and can receive orders.' + (miss.length ? ' Still missing: ' + miss.join(', ') + '.' : ''), label: miss.length ? 'Verify anyway' : 'Verify' }).then(function (r) { if (r) return act('admin_verify_service_giver', id, 'Business is live. Copy the welcome message below.'); });
}
function docReview(sgId, id, decision, reason) {
  K.api('admin_sg_doc_review', { id: id, decision: decision, reason: reason }).then(function (res) {
    if (K.okRes(res)) { K.toast(K.data(res).message || 'Saved'); V.busy = false; load(true); openSG(sgId); } else K.toast(K.failText(res), true);
  });
}
function onDrawerClick(e) {
  var dr = K.drawerEl(); if (!dr || !dr.contains(e.target)) return;
  var cp = e.target.closest('[data-copy]'); if (cp) { copyText(cp.getAttribute('data-copy')); return; }
  if (!dr._d || !dr._d.service_giver) return;
  var g = dr._d.service_giver, t = e.target;
  if (t.closest('[data-owner-save]')) {
    K.api('admin_sg_update_owner', { id: g.id, name: document.getElementById('sgo-name').value.trim(), phone: document.getElementById('sgo-phone').value.trim() }).then(function (res) {
      if (K.okRes(res)) { K.toast('Owner details saved'); V.busy = false; load(true); openSG(g.id); } else K.toast(K.failText(res), true);
    });
  }
  else if (t.closest('[data-claim-new]')) {
    K.confirm({ title: 'Make a new claim link?', text: 'Any claim link made before stops working. The owner will be able to set their own phone and password with the new one.', label: 'Make link' }).then(function (r) {
      if (!r) return;
      K.api('admin_sg_claim_invite', { id: g.id }).then(function (res) {
        if (!K.okRes(res)) { K.toast(K.failText(res), true); return; }
        var d = K.data(res), box = document.getElementById('sgo-claim');
        if (box) box.innerHTML = claimBox(d.claim_code, d.claim_expires_at);
      });
    });
  }
  else if (t.closest('[data-doc-ok]')) {
    docReview(g.id, parseInt(t.closest('[data-doc-ok]').getAttribute('data-doc-ok'), 10), 'approve', '');
  }
  else if (t.closest('[data-doc-no]')) {
    var did = parseInt(t.closest('[data-doc-no]').getAttribute('data-doc-no'), 10), why = dr.querySelector('[data-doc-why="' + did + '"]');
    if (!why || !why.value.trim()) { K.toast('Write what is wrong first, so the owner can fix it', true); if (why) why.focus(); return; }
    docReview(g.id, did, 'reject', why.value.trim());
  }
  else if (t.closest('[data-gl]')) {
    var k = t.closest('[data-gl]').getAttribute('data-gl');
    if (k === 'hours') {
      K.api('admin_sg_set_hours', { id: g.id, opens_at: document.getElementById('gl-open').value, closes_at: document.getElementById('gl-close').value }).then(function (res) {
        if (K.okRes(res)) { K.toast('Opening hours saved'); V.busy = false; load(true); openSG(g.id); } else K.toast(K.failText(res), true);
      });
    }
    else if (k === 'owner') { var ph = document.getElementById('sgo-phone'); if (ph) { ph.scrollIntoView({ block: 'center' }); ph.focus(); ph.select(); } }
    else if (k === 'docs') { var sec = dr.querySelector('[data-doc-up]'); if (sec) sec.closest('.ak-sec').scrollIntoView({ block: 'start' }); }
    else if (k === 'menu') { if (window.EtelCsvImport) window.EtelCsvImport.openItems(function () { openSG(g.id); }); else K.toast('The import is not available here.', true); }
    else if (k === 'full') { K.closeDrawer(); if (typeof openSGManageModal === 'function') openSGManageModal(g.id); else K.toast('The full profile editor is not available here.', true); }
  }
  else if (t.closest('[data-verify]')) verify(g.id, dr._d.readiness);
  else if (t.closest('[data-suspend]')) K.confirm({ title: 'Suspend ' + title(g) + '?', text: 'It is taken offline and stops receiving orders until you verify it again.', label: 'Suspend', danger: true }).then(function (r) { if (r) act('admin_suspend_service_giver', g.id, 'Business suspended'); });
  else if (t.closest('[data-biz]')) {
    var on = t.closest('[data-biz]').getAttribute('data-biz') === '1';
    K.confirm({ title: (on ? 'Switch on' : 'Switch off') + ' Etel Business for ' + title(g) + '?', text: on ? 'The shop gets the counter, stock, expenses and cash-up screens. Their data stays if you switch it off later.' : 'The shop loses the business screens. Nothing is deleted; it comes back if you switch it on again.', label: on ? 'Switch on' : 'Switch off', danger: !on }).then(function (r) {
      if (r) act('admin_sg_set_business_tools', g.id, on ? 'Etel Business switched on' : 'Etel Business switched off', { enabled: on ? 1 : 0 });
    });
  }
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
    if (t.closest('[data-act="register"]')) { if (window.EtelSgRegister) window.EtelSgRegister.open(); else K.toast('The registration panel is not available.', true); return; }
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
document.addEventListener('change', function (e) {
  var inp = e.target, kind = inp && inp.getAttribute && inp.getAttribute('data-brand'), dr = K.drawerEl();
  var dtype = inp && inp.getAttribute && inp.getAttribute('data-doc-up');
  if (dtype && dr && dr._d && inp.files && inp.files[0]) {
    var sid = dr._d.service_giver.id; K.toast('Uploading…');
    uploadPhoto(inp.files[0], 'sg_document').then(function (u) {
      if (!u || !u.success) throw new Error((u && u.message) || 'Upload failed');
      return K.api('sg_doc_submit', { sg_id: sid, doc_type: dtype, file_url: u.data.url });
    }).then(function (res) {
      if (!K.okRes(res)) throw new Error(K.failText(res));
      K.toast('Saved'); V.busy = false; load(true); openSG(sid);
    }).catch(function (er) { K.toast(er.message, true); });
    return;
  }
  if (!kind || !dr || !dr._d) return;
  var f = inp.files && inp.files[0]; if (!f) return;
  var id = dr._d.service_giver.id; K.toast('Uploading…');
  uploadPhoto(f).then(function (u) {
    if (!u || !u.success) throw new Error((u && u.message) || 'Upload failed');
    var b = { service_giver_id: id }; b[kind] = u.data.url;
    return K.api('admin_set_shop_branding', b);
  }).then(function (res) {
    if (!K.okRes(res)) throw new Error(K.failText(res));
    K.toast('Saved'); V.busy = false; load(true); openSG(id);
  }).catch(function (er) { K.toast(er.message, true); });
});
document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('[data-sel-all]')) e.target.select(); });
document.addEventListener('admin:filter', function (e) {   // opened from the dashboard's "Needs your attention"
  var d = e.detail || {}; if (d.page !== 'servicegivers') return;
  V.filter = d.filter || ''; V.q = '';
});

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadServiceGivers = function () { return load(V.loaded); };
  window.etelOpenServiceGiver = function (id) { V.busy = false; return load(true).then(function () { return openSG(id); }); };
  window.renderSGTable = window.renderSGCards = window.renderSGStatStrip = function () { if (V.loaded) paint(); };
  window.filterSGTable = window.renderSGTable;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
