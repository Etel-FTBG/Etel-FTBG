/* ETEL Admin - Service givers: the business directory.
   Counts that double as filters (verified / waiting for verification / suspended / online), search, type filter, sort,
   card or table view, quick verify on the card, and a drawer with the business's numbers (orders today / 7 / 30 days,
   open orders, sales, staff logins, rating) and recent orders, with the controls: verify, suspend, restore, and
   "Full profile…" which opens the existing profile / staff / payout editor.
   "Pre-register a business" makes a waiting (pending) account with a spare phone number and a one-time claim link; the owner opens
   claim.html, sets their own phone and password, and admin verifies. The drawer's "Hand-over" section edits owner name / phone and
   makes a new claim link (admin_sg_update_owner, admin_sg_claim_invite, admin_create_service_giver with draft).
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
    K.seg('mode', [['cards', '🎴 Cards'], ['table', '☰ Table']], V.mode) + '<button type="button" class="ak-btn" data-act="refresh">🔄</button><button type="button" class="ak-btn gold" data-act="draft">➕ Pre-register a business</button></div>' +
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
function openDraft() {
  var types = ['restaurant', 'cafe', 'fastfood', 'grocery', 'pharmacy', 'laundry', 'electronics', 'retail', 'custom', 'delivery'];
  var head = '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🏪</div><div class="ak-nm"><b>Pre-register a business</b><small>Waiting account, not live</small></div>';
  var body = '<div class="ak-sec"><p class="ak-fh" style="margin:0 0 10px">Makes the account now but keeps it switched off: it cannot sign in and customers do not see it. When the owner agrees, send them the claim link and verify the business.</p>' +
    K.field('Business name *', '<input id="sgd-biz" class="ak-in" type="text" autocomplete="off">') +
    K.field('Owner name', '<input id="sgd-owner" class="ak-in" type="text" autocomplete="off">', 'Leave empty to use the business name for now.') +
    K.field('Owner phone', '<input id="sgd-phone" class="ak-in" type="tel" autocomplete="off">', 'Leave empty to use a spare number. The owner types their real one when they claim it.') +
    K.field('Type', '<select id="sgd-type" class="ak-in">' + types.map(function (t) { return '<option value="' + t + '">' + t + '</option>'; }).join('') + '</select>') +
    K.field('Zone', '<input id="sgd-zone" class="ak-in" type="text" autocomplete="off">', 'Optional, for example Piazza.') +
    K.field('Address', '<input id="sgd-addr" class="ak-in" type="text" autocomplete="off">', 'Optional.') + '</div><div id="sgd-out"></div>';
  var foot = '<span class="ak-err" id="sgd-msg" role="status"></span><button type="button" class="ak-btn" data-close>Close</button><button type="button" class="ak-btn gold" data-draft-save>Pre-register</button>';
  var dr = K.openDrawer(head, body, foot);
  dr.querySelector('[data-draft-save]').addEventListener('click', function (e) {
    var b = e.currentTarget, v = function (id) { return (document.getElementById(id).value || '').trim(); }, msg = document.getElementById('sgd-msg');
    if (!v('sgd-biz')) { msg.textContent = 'Enter the business name.'; return; }
    var payload = { draft: 1, business_name: v('sgd-biz'), name: v('sgd-owner'), phone: v('sgd-phone'), service_type: document.getElementById('sgd-type').value, address: v('sgd-addr') };
    if (v('sgd-zone')) payload.zone = v('sgd-zone');
    b.disabled = true; msg.textContent = 'Saving…';
    K.api('admin_create_service_giver', payload).then(function (res) {
      b.disabled = false;
      if (!K.okRes(res)) { msg.textContent = K.failText(res); return; }
      var d = K.data(res); msg.textContent = ''; b.hidden = true;
      document.getElementById('sgd-out').innerHTML = '<div class="ak-sec"><h4>Done: ' + esc(d.code) + '</h4><p class="ak-fh" style="margin:0">The business is waiting in the list. Nothing is live.</p></div>' + (d.claim_code ? claimBox(d.claim_code, d.claim_expires_at) : '');
      V.busy = false; load(true);
    });
  });
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
  h += '<div class="ak-sec"><h4>Hand-over to the owner</h4>' + hand + '</div>';
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
function verify(id) {
  return K.confirm({ title: 'Verify this business?', text: 'It will show to customers and can receive orders.', label: 'Verify' }).then(function (r) { if (r) return act('admin_verify_service_giver', id, 'Business verified'); });
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
  else if (t.closest('[data-verify]')) verify(g.id);
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
    if (t.closest('[data-act="draft"]')) { openDraft(); return; }
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
document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('[data-sel-all]')) e.target.select(); });
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
