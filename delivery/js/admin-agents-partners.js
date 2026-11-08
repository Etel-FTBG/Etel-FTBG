/* ETEL Admin - Agents: Partners tab. The review board for couriers, drivers and shops that agents register.
   Needs js/admin-agents-pro.js. The owner sees a queue with warnings, opens a partner (details, ID and selfie photos, shop products, the agent's record),
   ticks a short checklist, and approves, asks the agent for changes, or rejects. Approving creates the real account and pays the agent a fixed fee once.
   Also: per-agent quality table and the fee and rule settings (switch, fees, minimum products, daily limit, pay delay).
   Data: admin_fa_partners_list / _stats / admin_fa_partner_get / admin_fa_partner_decide (api/controllers/FieldAgentPartnerController.php),
   settings through admin_fa_settings_get / admin_fa_settings_save. Capabilities: agents.view (read), agents.manage (decide, settings). */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__par) return; A.__par = true;
var K = A.K, esc = A.esc, run = A.run, ok = A.ok, dat = A.dat;
var KIND = { courier: ['🛵', 'Courier'], driver: ['🚖', 'Driver'], shop: ['🏪', 'Shop'] };
var STATUS = [['submitted', 'Waiting'], ['changes', 'Changes asked'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['draft', 'Drafts'], ['', 'All']];
var CHECKS = {
  person: ['The ID photo is clear and matches the selfie', 'The phone number works (call or text checked)', 'The details look right and real'],
  shop: ['The ID photo is clear and matches the selfie', 'The shop location is right on the map', 'The products and prices look real']
};
var SETKEYS = ['partners_enabled', 'partner_fee_courier', 'partner_fee_driver', 'partner_fee_shop', 'partner_shop_min_items', 'partner_daily_cap', 'partner_pay_days'];

var P = { sub: 'queue', status: 'submitted', kind: '', q: '', list: null, counts: {}, stats: null, err: '', sel: null, checks: {}, note: '', cfg: null, busy: false };
function manage() { return A.can('agents.manage'); }
function etb(n) { return (A.etb ? A.etb(n) : (Number(n) || 0).toLocaleString('en-US')) ; }

function css() {
  A.css();
  if (document.getElementById('pa-css')) return;
  var s = document.createElement('style'); s.id = 'pa-css';
  s.textContent = [
    '.pa-box{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;width:100%;box-sizing:border-box}',
    '.pa-hero{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:18px;border-radius:18px;margin:0 0 14px;color:#f6e7b0;background:linear-gradient(135deg,#0b1a33,#13294d 60%,#1b3563);border:1px solid rgba(201,162,39,.5)}',
    '.pa-hero h2{margin:0 0 4px;font-size:19px;color:#f6e7b0!important;-webkit-text-fill-color:#f6e7b0!important;background:none!important}.pa-hero p{margin:0;font-size:13px;color:#c9d3e8!important;-webkit-text-fill-color:#c9d3e8!important;max-width:60ch}',
    '.pa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 14px}',
    '.pa-st{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px}.pa-st b{display:block;font-size:24px;line-height:1.1}.pa-st small{color:var(--mute);font-size:12px}',
    '.pa-st.hot{border-color:var(--gold);box-shadow:0 0 0 1px var(--gold) inset}',
    '.pa-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}.pa-bar select,.pa-bar input[type=search]{min-height:44px;padding:0 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.pa-chip{min-height:40px;padding:0 14px;border-radius:99px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:600 13px inherit;cursor:pointer}.pa-chip.on{border-color:var(--gold);background:rgba(201,162,39,.16)}.pa-chip i{font-style:normal;opacity:.7;margin-left:4px}',
    '.pa-row{display:flex;gap:12px;align-items:center;width:100%;text-align:left;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:0 0 8px;color:inherit;font:inherit;cursor:pointer}.pa-row:hover{border-color:var(--gold)}',
    '.pa-ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--soft);font-size:22px;flex:none}.pa-m{flex:1;min-width:0}.pa-m b{display:block;overflow-wrap:anywhere}.pa-m small{color:var(--mute);font-size:12.5px;display:block}',
    '.pa-pill{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;border:1px solid var(--line);background:var(--soft);white-space:nowrap}.pa-pill.g{color:var(--green);border-color:var(--green)}.pa-pill.a{color:var(--amber);border-color:var(--amber)}.pa-pill.r{color:var(--red);border-color:var(--red)}',
    '.pa-ov{position:fixed;inset:0;z-index:9000;background:rgba(5,10,22,.65);display:flex;align-items:flex-end;justify-content:center}',
    '.pa-sheet{width:100%;max-width:760px;max-height:94vh;overflow:auto;background:var(--card);color:var(--ink);border-radius:20px 20px 0 0;padding:18px;border-top:3px solid var(--gold)}',
    '@media(min-width:800px){.pa-ov{align-items:center}.pa-sheet{border-radius:20px}}',
    '.pa-sec{margin:14px 0 6px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--mute);font-weight:700}',
    '.pa-kv{display:grid;grid-template-columns:150px 1fr;gap:6px 12px;font-size:14px}.pa-kv dt{color:var(--mute)}.pa-kv dd{margin:0;overflow-wrap:anywhere}',
    '.pa-ph{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}.pa-ph a{display:block;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--soft);text-decoration:none;color:var(--ink)}',
    '.pa-ph img{display:block;width:100%;height:110px;object-fit:cover}.pa-ph span{display:block;padding:6px 8px;font-size:12px}',
    '.pa-it{display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--line)}.pa-it img,.pa-it .pa-th{width:44px;height:44px;border-radius:10px;object-fit:cover;background:var(--soft);flex:none}',
    '.pa-flag{padding:8px 12px;border-radius:10px;background:rgba(224,121,90,.12);border:1px solid rgba(224,121,90,.4);margin:0 0 6px;font-size:13px}',
    '.pa-chk{display:flex;gap:10px;align-items:center;min-height:44px;font-size:14px;cursor:pointer}.pa-chk input{width:22px;height:22px;accent-color:#c9a227}',
    '.pa-note{width:100%;min-height:76px;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.pa-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.pa-acts .ak-btn{flex:1;min-height:48px}',
    '.pa-cfg{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}.pa-cfg label{display:block;font-size:13px;font-weight:600}.pa-cfg input[type=number]{width:100%;min-height:44px;margin-top:6px;padding:0 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.pa-tbl{width:100%;border-collapse:collapse;font-size:13.5px}.pa-tbl th,.pa-tbl td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left}.pa-tbl th{color:var(--mute);font-size:12px}'
  ].join('\n');
  document.head.appendChild(s);
}

function riskPill(n) { n = Number(n) || 0; var c = n >= 60 ? 'r' : (n >= 30 ? 'a' : 'g'); return '<span class="pa-pill ' + c + '">' + (n >= 60 ? 'High risk' : (n >= 30 ? 'Check' : 'Low risk')) + (n ? ' · ' + n : '') + '</span>'; }
function stPill(s) { var c = s === 'approved' ? 'g' : (s === 'submitted' ? 'a' : (s === 'rejected' ? 'r' : '')); var l = { submitted: 'Waiting', changes: 'Changes asked', approved: 'Approved', rejected: 'Rejected', draft: 'Draft' }[s] || s; return '<span class="pa-pill ' + c + '">' + esc(l) + '</span>'; }
function ago(s) { return A.when ? A.when(s) : String(s || ''); }
function hours(h) { return h == null ? '-' : (h < 1 ? 'under 1 h' : (h < 48 ? h + ' h' : Math.round(h / 24) + ' days')); }

/* ---------- screens ---------- */
function statsHtml() {
  var s = P.stats, c = s ? s.counts : P.counts || {};
  var tile = function (n, l, hot) { return '<div class="pa-st' + (hot ? ' hot' : '') + '"><b>' + esc(n) + '</b><small>' + esc(l) + '</small></div>'; };
  return '<div class="pa-stats">' + tile(c.submitted || 0, 'Waiting for you', (c.submitted || 0) > 0) + tile(c.changes || 0, 'Waiting for the agent') + tile(s ? s.month.approved : '-', 'Approved this month') +
    tile(s ? etb(s.month.fees) : '-', 'Fees earned this month') + tile(s ? hours(s.oldest_waiting_hours) : '-', 'Oldest waiting') + '</div>';
}
function subTabs() {
  return '<div class="ak-seg" role="tablist" style="margin:0 0 12px">' + [['queue', 'Review queue'], ['agents', 'Agent quality'], ['rules', 'Fees and rules']].map(function (t) {
    return '<button type="button" role="tab" aria-selected="' + (P.sub === t[0]) + '" class="' + (P.sub === t[0] ? 'on' : '') + '" data-pa-sub="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>';
}
function queueHtml() {
  var c = (P.stats ? P.stats.counts : P.counts) || {};
  var h = '<div class="pa-bar">' + STATUS.map(function (x) { var n = x[0] ? c[x[0]] : null; return '<button type="button" class="pa-chip' + (P.status === x[0] ? ' on' : '') + '" data-pa-status="' + x[0] + '">' + esc(x[1]) + (n != null ? '<i>' + n + '</i>' : '') + '</button>'; }).join('') + '</div>' +
    '<div class="pa-bar"><select id="pa-kind" aria-label="Type"><option value="">All types</option>' + Object.keys(KIND).map(function (k) { return '<option value="' + k + '"' + (P.kind === k ? ' selected' : '') + '>' + KIND[k][1] + '</option>'; }).join('') + '</select>' +
    '<input type="search" id="pa-q" placeholder="Search name, phone or agent" value="' + esc(P.q) + '" style="flex:1;min-width:180px"></div>';
  if (P.err) return h + K.empty('⚠️', 'Could not load', P.err);
  if (P.list === null) return h + K.skeleton(3);
  if (!P.list.length) return h + K.empty('🤝', P.status === 'submitted' ? 'Nothing waiting for you' : 'Nothing here', P.status === 'submitted' ? 'When an agent sends a courier, driver or shop, it shows up here.' : 'Try another filter.');
  return h + P.list.map(function (r) {
    return '<button type="button" class="pa-row" data-pa-open="' + r.id + '"><span class="pa-ic">' + KIND[r.kind][0] + '</span><span class="pa-m"><b>' + esc(r.name || r.phone || '-') + '</b>' +
      '<small>' + esc(KIND[r.kind][1]) + (r.item_count ? ' · ' + r.item_count + ' products' : '') + ' · by ' + esc(r.agent_name) + ' (' + esc(r.agent_code) + ') · ' + esc(ago(r.submitted_at || r.decided_at)) + '</small></span>' +
      (r.status === 'submitted' ? riskPill(r.risk_score) : stPill(r.status)) + '</button>';
  }).join('');
}
function agentsHtml() {
  var s = P.stats; if (!s) return K.skeleton(2);
  if (!s.agents.length) return K.empty('📊', 'No submissions yet', 'Agent quality appears here after the first partners are sent.');
  return '<div class="pa-box" style="overflow:auto"><table class="pa-tbl"><thead><tr><th>Agent</th><th>Sent</th><th>Approved</th><th>Rejected</th><th>Approval</th><th>Avg risk</th><th>Fees</th></tr></thead><tbody>' + s.agents.map(function (a) {
    var pct = a.sent ? Math.round(a.approved * 100 / Math.max(1, a.approved + a.rejected)) : 0, bad = (a.approved + a.rejected) >= 3 && (a.rejected * 100 / (a.approved + a.rejected)) >= 40;
    return '<tr><td><b>' + esc(a.full_name) + '</b><br><small style="color:var(--mute)">' + esc(a.code) + '</small></td><td>' + a.sent + '</td><td>' + a.approved + '</td><td>' + a.rejected + '</td><td><span class="pa-pill ' + (bad ? 'r' : (pct >= 70 ? 'g' : 'a')) + '">' + ((a.approved + a.rejected) ? pct + '%' : '-') + '</span></td><td>' + a.avg_risk + '</td><td>' + etb(a.fees) + '</td></tr>';
  }).join('') + '</tbody></table></div><p class="ak-fh" style="margin-top:8px">Last 90 days. Approval = approved out of decided. A high rejection rate is a reason to talk to the agent.</p>';
}
function rulesHtml() {
  if (!P.cfg) return K.skeleton(2);
  var c = P.cfg, can = manage(), dis = can ? '' : ' disabled';
  var num = function (k, label, hint) { return '<label>' + esc(label) + '<input type="number" min="0" step="1" data-pa-set="' + k + '" value="' + esc(c[k]) + '"' + dis + '><span class="ak-fh">' + esc(hint) + '</span></label>'; };
  return '<div class="pa-box"><div style="margin-bottom:12px">' + K.sw('pa-on', 'Agents can register partners', !!Number(c.partners_enabled)) + '</div><div class="pa-cfg">' +
    num('partner_fee_courier', 'Fee for a courier (ETB)', 'Paid once, after you approve.') + num('partner_fee_driver', 'Fee for a driver (ETB)', 'Paid once, after you approve.') + num('partner_fee_shop', 'Fee for a shop (ETB)', 'Shops take more work: details and products.') +
    num('partner_shop_min_items', 'Minimum products for a shop', 'A shop cannot be sent with fewer.') + num('partner_daily_cap', 'Daily limit per agent', 'How many partners one agent can send in 24 hours.') +
    num('partner_pay_days', 'Days to wait before the fee is payable', '0 = payable straight after approval.') + '</div>' +
    (can ? '<div class="pa-acts"><button class="ak-btn gold" data-pa-savecfg style="flex:none;padding:0 24px">Save</button></div>' : '<p class="ak-fh">View only.</p>') + '</div>';
}
function render() {
  css();
  var h = '<div class="pa-hero"><div><h2>Partners</h2><p>Couriers, drivers and shops registered by agents. Nothing becomes an account until you approve it; the agent is paid a fixed fee once.</p></div></div>' + statsHtml() + subTabs();
  return h + (P.sub === 'queue' ? queueHtml() : (P.sub === 'agents' ? agentsHtml() : rulesHtml()));
}
function paint() { var el = document.getElementById('ag-screen'); if (el && A.S.tab === 'partners') el.innerHTML = render(); }

/* ---------- detail sheet ---------- */
function field(k, v) { return (v === '' || v == null) ? '' : '<dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd>'; }
function photoTile(url, label) { return url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener"><img src="' + esc(url) + '" alt="" loading="lazy"><span>' + esc(label) + '</span></a>' : ''; }
function sheetHtml(p, fee) {
  var d = p.data || {}, shop = p.kind === 'shop', st = p.status, deciding = st === 'submitted' && manage();
  var h = '<div class="pa-ov" data-pa-close role="dialog" aria-modal="true"><div class="pa-sheet" role="document"><div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start"><div><h2 style="margin:0">' + KIND[p.kind][0] + ' ' + esc(p.name || p.phone) + '</h2><div style="margin-top:6px">' + stPill(st) + ' ' + (st === 'submitted' ? riskPill(p.risk_score) : '') + '</div></div><button class="ak-btn" data-pa-close aria-label="Close">✕</button></div>';
  if (p.risk_flags && p.risk_flags.length) h += '<div class="pa-sec">Warnings</div>' + p.risk_flags.map(function (f) { return '<div class="pa-flag">⚠️ ' + esc(f.text) + '</div>'; }).join('');
  h += '<div class="pa-sec">Registered by</div><dl class="pa-kv">' + field('Agent', p.agent_name + ' (' + p.agent_code + ')') + field('Agent phone', p.agent_phone) + field('Agent record', p.agent_stats ? (p.agent_stats.ok || 0) + ' approved, ' + (p.agent_stats.bad || 0) + ' rejected' : '') + field('Sent', p.submitted_at) + '</dl>';
  h += '<div class="pa-sec">' + (shop ? 'Owner' : 'Person') + '</div><dl class="pa-kv">' + field('Name', p.name) + field('Phone', p.phone) + field('ID type', d.id_type) + field('ID number', p.id_number) + field('Contact name', d.emergency_name) + field('Contact phone', d.emergency_phone) + field('Area', d.zone) + field('Address', d.address) + '</dl>';
  if (p.kind === 'courier') h += '<div class="pa-sec">Vehicle</div><dl class="pa-kv">' + field('Type', d.vehicle_type) + field('Plate', d.plate_number) + field('Licence number', d.licence_number) + '</dl>';
  if (p.kind === 'driver') h += '<div class="pa-sec">Vehicle and licence</div><dl class="pa-kv">' + field('Type', d.vehicle_type) + field('Plate', d.vehicle_plate) + field('Make', d.vehicle_make) + field('Colour', d.vehicle_color) + field('Year', d.vehicle_year || '') + field('Name on licence', d.license_name) + field('Licence number', d.license_number) + '</dl>';
  if (shop) {
    var map = (d.lat != null && d.lng != null) ? '<a href="https://www.google.com/maps?q=' + encodeURIComponent(d.lat + ',' + d.lng) + '" target="_blank" rel="noopener">Open on the map (' + esc(Number(d.lat).toFixed(5)) + ', ' + esc(Number(d.lng).toFixed(5)) + ')</a>' : '';
    h += '<div class="pa-sec">Shop</div><dl class="pa-kv">' + field('Name', d.business_name) + field('Sells', d.service_type) + field('City', d.city) + field('Hours', d.opens_at && d.closes_at ? d.opens_at + ' to ' + d.closes_at : '') + field('Description', d.description) + (map ? '<dt>Location</dt><dd>' + map + '</dd>' : '') + '</dl>';
  }
  h += '<div class="pa-sec">Photos</div><div class="pa-ph">' + photoTile(d.id_photo_url, 'ID card') + photoTile(d.selfie_url, 'Selfie') + photoTile(d.licence_photo_url, 'Licence') + photoTile(d.license_photo_url, 'Licence') + photoTile(d.logo_url, 'Logo') + photoTile(d.cover_url, 'Cover') + photoTile(d.place_photo_url, 'Shop place') + photoTile(d.center_photo_url, 'Shopping center') + photoTile(d.vehicle_photo_front, 'Vehicle front') + photoTile(d.vehicle_photo_side, 'Vehicle side') + photoTile(d.vehicle_photo_back, 'Vehicle back') + '</div>';
  if (shop) h += '<div class="pa-sec">Products (' + p.items.length + ')</div>' + p.items.map(function (it) { return '<div class="pa-it">' + (it.image_url ? '<img src="' + esc(it.image_url) + '" alt="" loading="lazy">' : '<span class="pa-th"></span>') + '<div class="pa-m"><b>' + esc(it.name) + '</b><small>' + esc(it.category) + (it.description ? ' · ' + esc(it.description) : '') + '</small></div><b>' + esc(etb(it.price)) + ' ETB</b></div>'; }).join('');
  if (p.decision_note) h += '<div class="pa-sec">Last note</div><p>' + esc(p.decision_note) + '</p>';
  if (st === 'approved' && manage()) h += '<div class="pa-acts"><button class="ak-btn" data-pa-sms="' + p.id + '">✉️ Send the sign-in text again</button></div>';
  if (st === 'approved') h += '<div class="pa-sec">Result</div><dl class="pa-kv">' + field('Fee paid to agent', etb(p.fee_amount)) + field('Account', p.user_id ? 'User #' + p.user_id : '') + '</dl>';
  if (deciding) {
    var list = CHECKS[shop ? 'shop' : 'person'];
    h += '<div class="pa-sec">Your checklist</div>' + list.map(function (t, i) { return '<label class="pa-chk"><input type="checkbox" data-pa-check="' + i + '"' + (P.checks[i] ? ' checked' : '') + '><span>' + esc(t) + '</span></label>'; }).join('') +
      '<div class="pa-sec">Note to the agent (needed to reject or ask for changes)</div><textarea class="pa-note" id="pa-note" maxlength="255" placeholder="For example: the ID photo is blurry, please take it again.">' + esc(P.note) + '</textarea>' +
      '<div class="pa-acts"><button class="ak-btn gold" id="pa-ok" data-pa-decide="approve"' + (list.every(function (t, i) { return P.checks[i]; }) ? '' : ' disabled') + '>✓ Approve · pay ' + esc(etb(fee)) + '</button><button class="ak-btn" data-pa-decide="changes">↩ Ask for changes</button><button class="ak-btn" data-pa-decide="reject" style="color:var(--red)">✕ Reject</button></div>' +
      '<p class="ak-fh">Approving creates the account and texts the person how to set a password.</p>';
  }
  return h + '</div></div>';
}
function openSheet(id) {
  run('admin_fa_partner_get', { id: id }).then(function (r) {
    if (!ok(r)) { K.toast(K.failText(r, 'Could not open this one.'), true); return; }
    var d = dat(r); closeSheet(); P.sel = d.partner; P.checks = {}; P.note = '';
    var wrap = document.createElement('div'); wrap.id = 'pa-sheet'; wrap.className = 'ak'; wrap.innerHTML = sheetHtml(d.partner, d.fee); document.body.appendChild(wrap);
  });
}
function closeSheet() { var s = document.getElementById('pa-sheet'); if (s) s.remove(); P.sel = null; }

/* ---------- data ---------- */
function loadList() {
  P.err = '';
  return run('admin_fa_partners_list', { status: P.status, kind: P.kind, q: P.q }).then(function (r) {
    if (!ok(r)) { P.list = []; P.err = K.failText(r, 'Please try again.'); } else { var d = dat(r); P.list = d.partners; P.counts = d.counts; }
    paint();
  });
}
function loadStats() { return run('admin_fa_partners_stats').then(function (r) { if (ok(r)) { P.stats = dat(r); } paint(); }); }
function loadCfg() { return run('admin_fa_settings_get').then(function (r) { if (ok(r)) { var s = dat(r).settings, o = {}; SETKEYS.forEach(function (k) { o[k] = s[k]; }); P.cfg = o; } paint(); }); }
function open() { P.list = null; paint(); return Promise.all([loadList(), loadStats(), loadCfg()]); }

function decide(kind) {
  var p = P.sel; if (!p || P.busy) return;
  var note = (document.getElementById('pa-note') || {}).value || ''; P.note = note;
  if (kind !== 'approve' && !note.trim()) { K.toast('Write a short reason for the agent first.', true); return; }
  var go = function () {
    P.busy = true;
    run('admin_fa_partner_decide', { id: p.id, decision: kind, note: note }).then(function (r) {
      P.busy = false;
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save the decision.'), true); return; }
      var sms = dat(r).sms_sent;
      K.toast(kind === 'approve' ? (sms ? 'Approved. The account is ready, the agent is paid, and the person was texted.' : 'Approved and the agent is paid, but the text could not be sent. Open it and press "Send the sign-in text again", or tell the person to use Forgot password.') : (kind === 'reject' ? 'Rejected. The agent was told why.' : 'Sent back to the agent.'));
      closeSheet(); open(); if (A.paintTabsOnly) A.paintTabsOnly();
    });
  };
  if (kind === 'approve' && Number(p.risk_score) >= 60) { K.confirm({ title: 'High risk. Approve anyway?', text: 'This one has warnings. Make sure you checked the photos and called the person.', label: 'Approve' }).then(function (y) { if (y) go(); }); return; }
  if (kind === 'reject') { K.confirm({ title: 'Reject this partner?', text: 'The agent is not paid and sees your note.', label: 'Reject', danger: true }).then(function (y) { if (y) go(); }); return; }
  go();
}
function saveCfg() {
  var s = {}; document.querySelectorAll('[data-pa-set]').forEach(function (e) { s[e.getAttribute('data-pa-set')] = Number(e.value) || 0; });
  var on = document.getElementById('pa-on'); if (on) s.partners_enabled = on.checked ? 1 : 0;
  run('admin_fa_settings_save', { settings: s }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; } K.toast('Saved'); loadCfg(); });
}

/* ---------- events ---------- */
document.addEventListener('click', function (e) {
  var t = e.target, b;
  if (!t.closest) return;
  var sheet = document.getElementById('pa-sheet');
  if (sheet && sheet.contains(t)) {
    if (t.hasAttribute('data-pa-close') && (t.classList.contains('pa-ov') || t.tagName === 'BUTTON')) { closeSheet(); return; }
    if ((b = t.closest('[data-pa-decide]'))) { decide(b.getAttribute('data-pa-decide')); return; }
    if ((b = t.closest('[data-pa-sms]'))) { run('admin_fa_partner_resend_sms', { id: b.getAttribute('data-pa-sms') }).then(function (r) { K.toast(ok(r) ? (dat(r).sent ? 'Text sent.' : 'The text could not be sent (is the SMS service set up?).') : K.failText(r, 'Could not send.'), !ok(r) || !dat(r).sent); }); return; }
    return;
  }
  var root = document.getElementById('ag-screen'); if (!root || !root.contains(t) || A.S.tab !== 'partners') return;
  if ((b = t.closest('[data-pa-sub]'))) { P.sub = b.getAttribute('data-pa-sub'); paint(); return; }
  if ((b = t.closest('[data-pa-status]'))) { P.status = b.getAttribute('data-pa-status'); P.list = null; paint(); loadList(); return; }
  if ((b = t.closest('[data-pa-open]'))) { openSheet(b.getAttribute('data-pa-open')); return; }
  if (t.closest('[data-pa-savecfg]')) { saveCfg(); return; }
});
document.addEventListener('change', function (e) {
  var t = e.target;
  var sheet = document.getElementById('pa-sheet');
  if (sheet && sheet.contains(t) && t.hasAttribute && t.hasAttribute('data-pa-check')) {
    P.checks[t.getAttribute('data-pa-check')] = t.checked; var n = sheet.querySelectorAll('[data-pa-check]').length, all = true;
    sheet.querySelectorAll('[data-pa-check]').forEach(function (c) { if (!c.checked) all = false; }); var ok2 = document.getElementById('pa-ok'); if (ok2) ok2.disabled = !(all && n); return;
  }
  if (t.id === 'pa-kind') { P.kind = t.value; P.list = null; paint(); loadList(); }
});
var qTimer = null;
document.addEventListener('input', function (e) { if (e.target.id !== 'pa-q') return; P.q = e.target.value; clearTimeout(qTimer); qTimer = setTimeout(function () { loadList().then(function () { var q = document.getElementById('pa-q'); if (q) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); } }); }, 350); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

A.register('partners', { render: render, open: open });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
