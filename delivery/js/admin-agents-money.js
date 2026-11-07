/* ETEL Admin - Agents: Payouts, Reports, Settings, Audit log.  (needs js/admin-agents-pro.js)
   Payouts never move money: an admin creates a batch, downloads the Telebirr CSV, pays by hand, then marks the batch paid.
   Settings: every bonus, cap and risk number lives here with its default and allowed range; the server rejects anything out of range. */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__money) return; A.__money = true;
var K = A.K, esc = A.esc, T = A.T, run = A.run, ok = A.ok, dat = A.dat, etb = A.etb;
var panel = function () { return document.getElementById('panel-agents'); };

/* ======================= PAYOUTS ======================= */
var P = { cands: [], batches: [], pick: {}, loaded: false, err: '' };
var BATCH_TONE = { draft: '', exported: 'blue', paid: 'green', cancelled: 'red' };
function payoutsHtml() {
  if (!P.loaded) return K.skeleton(4);
  if (P.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(P.err) + '</div></div>';
  var pay = A.can('agents.pay'), picked = Object.keys(P.pick).filter(function (k) { return P.pick[k]; }).length;
  var tot = P.cands.reduce(function (a, c) { return a + A.f(c.net); }, 0);
  var h = '<section class="ag-box"><h3>' + esc(T('Candidates for payout')) + '</h3><div class="sub">Approved agents with a Telebirr number whose available bonuses (minus any reversals) reach the minimum payout of ' + esc(etb(A.S.cfg ? A.S.cfg.min_payout_etb : 0)) + '. Total ready: <b>' + esc(etb(tot)) + '</b></div>';
  if (!P.cands.length) h += K.empty('💸', 'Nobody is ready to be paid', 'Bonuses become payable after the hold period. Run the earnings calculation from the Dashboard if you expect some.');
  else {
    h += '<div class="ak-tblw"><table class="ak-tbl"><thead><tr>' + (pay ? '<th scope="col" style="width:36px"><input type="checkbox" data-pc-all aria-label="' + esc(T('Select all')) + '"></th>' : '') + '<th scope="col">' + esc(T('Agent')) + '</th><th scope="col">Telebirr</th><th scope="col" class="ag-num">Bonuses</th><th scope="col" class="ag-num">' + esc(T('Amount')) + '</th></tr></thead><tbody>' +
      P.cands.map(function (c) { return '<tr>' + (pay ? '<td><input type="checkbox" data-pc="' + esc(c.id) + '" aria-label="Select ' + esc(c.full_name) + '"' + (P.pick[c.id] ? ' checked' : '') + '></td>' : '') + '<td><b>' + esc(c.full_name) + '</b></td><td class="ag-mono">' + esc(c.payout_phone) + '</td><td class="ag-num">' + esc(c.n) + '</td><td class="ag-num"><b>' + esc(etb(c.net)) + '</b></td></tr>'; }).join('') + '</tbody></table></div>';
    if (pay) h += '<p style="margin:12px 0 0"><button type="button" class="ak-btn gold" data-pc-create>' + esc(T('Create payout batch')) + ' (' + (picked || P.cands.length) + ')</button> <span style="font-size:12px;color:var(--mute)">' + (picked ? 'Only the ticked agents.' : 'Nobody ticked: every agent above is included.') + '</span></p>';
  }
  h += '</section><section class="ag-box"><h3>' + esc(T('Payout batches')) + '</h3>';
  if (!P.batches.length) h += K.empty('🧾', 'No batches yet', 'Create one from the list above.');
  else h += '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">Batch</th><th scope="col">' + esc(T('Status')) + '</th><th scope="col" class="ag-num">Agents</th><th scope="col" class="ag-num">' + esc(T('Amount')) + '</th><th scope="col">Created</th><th scope="col">Paid</th></tr></thead><tbody>' +
    P.batches.map(function (b) { return '<tr data-open tabindex="0" data-batch="' + esc(b.id) + '"><td><b>#' + esc(b.id) + '</b>' + (b.note ? '<br><small>' + esc(b.note) + '</small>' : '') + '</td><td><span class="ag-pill ' + (BATCH_TONE[b.status] || '') + '">' + esc(b.status) + '</span></td><td class="ag-num">' + esc(b.item_count) + '</td><td class="ag-num"><b>' + esc(etb(b.total_amount)) + '</b></td><td>' + esc(A.when(b.created_at)) + '</td><td>' + esc(b.paid_at ? A.when(b.paid_at) : '-') + '</td></tr>'; }).join('') + '</tbody></table></div>';
  return h + '</section>';
}
function loadPayouts() {
  return run('admin_fa_payouts_list').then(function (r) {
    P.loaded = true; if (!ok(r)) { P.err = K.failText(r, 'Could not load payouts.'); } else { P.err = ''; P.cands = dat(r).candidates || []; P.batches = dat(r).batches || []; }
    var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'payouts') box.innerHTML = payoutsHtml();
  });
}
function createBatch(btn) {
  var ids = Object.keys(P.pick).filter(function (k) { return P.pick[k]; }).map(Number);
  var list = ids.length ? P.cands.filter(function (c) { return ids.indexOf(Number(c.id)) !== -1; }) : P.cands, total = list.reduce(function (a, c) { return a + A.f(c.net); }, 0);
  return K.confirm({ title: 'Create a payout batch?', text: list.length + ' agent(s), ' + etb(total) + ' in total. Their bonuses are locked into the batch. Nothing is paid yet: you download the Telebirr file next.', reason: 'Note (optional, for example: week 12)', label: T('Create payout batch') }).then(function (res) {
    if (!res) return;
    return A.busy(btn, function () { return run('admin_fa_payout_create', { agent_ids: ids, note: res.reason }); }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not create the batch.'), true); return; }
      K.toast('Batch #' + dat(r).batch_id + ' created'); P.pick = {}; return loadPayouts().then(function () { openBatch(dat(r).batch_id); });
    });
  });
}
function openBatch(id) {
  return run('admin_fa_payout_items', { batch_id: id }).then(function (r) {
    if (!ok(r)) { K.toast(K.failText(r, 'Could not open the batch.'), true); return; }
    var b = dat(r).batch, items = dat(r).items, pay = A.can('agents.pay'), open = b.status === 'draft' || b.status === 'exported';
    var head = '<div class="ak-top"><div class="ak-av lg">🧾</div><div class="ak-nm"><b>Batch #' + esc(b.id) + ' - ' + esc(etb(b.total_amount)) + '</b><small>' + esc(b.status) + ' - ' + esc(items.length) + ' agent(s)</small></div></div>';
    var body = '<div class="ak-sec"><h4>Agents in this batch</h4><div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">' + esc(T('Agent')) + '</th><th scope="col">Telebirr</th><th scope="col" class="ag-num">' + esc(T('Amount')) + '</th><th scope="col">Telebirr reference</th></tr></thead><tbody>' +
      items.map(function (i) { return '<tr><td><b>' + esc(i.full_name) + '</b></td><td class="ag-mono">' + esc(i.payout_phone) + '</td><td class="ag-num">' + esc(etb(i.amount)) + '</td><td>' + (open && pay ? '<input class="ak-in" data-ref="' + esc(i.id) + '" maxlength="60" placeholder="optional" aria-label="Telebirr reference for ' + esc(i.full_name) + '">' : esc(i.tx_ref || '-')) + '</td></tr>'; }).join('') + '</tbody></table></div>' +
      (open ? '<p style="font-size:12.5px;color:var(--mute);margin:10px 0 0">1) Download the file. 2) Pay each agent in Telebirr. 3) Come back and press "Mark paid". The reference column in the file is ETEL-AGENT-B' + esc(b.id) + '-(item number).</p>' : '') + '</div>';
    var foot = '';
    if (pay && open) foot = '<button type="button" class="ak-btn" data-bx="export">' + esc(T('Export for Telebirr')) + '</button><button type="button" class="ak-btn gold" data-bx="paid">' + esc(T('Mark paid')) + '</button><button type="button" class="ak-btn danger" data-bx="cancel">' + esc(T('Cancel')) + ' batch</button>';
    var dr = K.openDrawer(head, body, foot);
    dr.addEventListener('click', function (e) {
      var x = e.target.closest('[data-bx]'); if (!x) return; var what = x.getAttribute('data-bx');
      if (what === 'export') return A.busy(x, function () { return run('admin_fa_payout_export', { batch_id: b.id }); }).then(function (rr) { if (!ok(rr)) { K.toast(K.failText(rr, 'Could not export.'), true); return; } A.download(dat(rr).filename, dat(rr).csv); K.toast('File downloaded'); loadPayouts(); });
      if (what === 'paid') return K.confirm({ title: 'Mark batch #' + b.id + ' as paid?', text: 'Only press this after you have paid every agent in Telebirr. The agents are told their money was sent. This cannot be undone.', label: 'Yes, I paid them' }).then(function (res) {
        if (!res) return; var refs = {}; Array.prototype.forEach.call(dr.querySelectorAll('[data-ref]'), function (inp) { if (inp.value.trim()) refs[inp.getAttribute('data-ref')] = inp.value.trim(); });
        return A.busy(x, function () { return run('admin_fa_payout_mark_paid', { batch_id: b.id, refs: refs }); }).then(function (rr) { if (!ok(rr)) { K.toast(K.failText(rr, 'Could not mark paid.'), true); return; } K.toast('Batch marked paid'); K.closeDrawer(); loadPayouts(); });
      });
      if (what === 'cancel') return K.confirm({ title: 'Cancel batch #' + b.id + '?', text: 'The bonuses go back to the list and can be put in a new batch.', reason: 'Why? (recorded)', required: true, danger: true, label: 'Cancel the batch' }).then(function (res) {
        if (!res) return; return A.busy(x, function () { return run('admin_fa_payout_cancel', { batch_id: b.id, reason: res.reason }); }).then(function (rr) { if (!ok(rr)) { K.toast(K.failText(rr, 'Could not cancel.'), true); return; } K.toast('Batch cancelled'); K.closeDrawer(); loadPayouts(); });
      });
    });
  });
}

/* ======================= REPORTS ======================= */
var RP = { days: 30, d: null, sort: 'acquired', dir: -1, err: '' };
var SORTS = [['full_name', 'Agent'], ['venue_name', 'Venue'], ['claims', 'Claims'], ['acquired', 'Acquired'], ['repeat_rate', 'Repeat rate'], ['rejected', 'Rejected'], ['cost', 'Cost'], ['cost_per_rider', 'Cost per rider'], ['risk_score', 'Risk']];
function reportsHtml() {
  var tools = '<div class="ag-tools">' + K.seg('ag-days', [['7', '7 days'], ['30', '30 days'], ['90', '90 days']], String(RP.days)) + '<span style="flex:1"></span><button type="button" class="ak-btn" data-ag-exp-rp>' + esc(T('Export CSV')) + '</button></div>';
  if (RP.err) return tools + '<div class="ak-empty"><b>⚠️</b><div>' + esc(RP.err) + '</div></div>';
  if (!RP.d) return tools + K.skeleton(4);
  var d = RP.d, t = d.totals;
  var h = tools + K.kpis([{ key: 'a', icon: '📲', label: T('Claims'), value: t.claims }, { key: 'b', icon: '🧍', label: T('Acquired'), value: t.acquired }, { key: 'c', icon: '🔁', label: T('Repeat rate'), value: t.repeat_rate + '%' }, { key: 'd', icon: '💰', label: T('Cost per rider'), value: etb(t.cost_per_rider) }, { key: 'e', icon: '🧾', label: T('Total cost'), value: etb(t.cost) }, { key: 'f', icon: '⛔', label: T('Rejected'), value: t.rejected }], '', true);
  h += '<div class="ag-grid2"><section class="ag-box"><h3>' + esc(T('Referrals and repeat riders')) + '</h3><div class="sub">Per day</div>' + A.lineChart(d.daily, [{ key: 'claims', name: T('Claims'), color: '#8a8aa0' }, { key: 'new_riders', name: T('New riders'), color: '#B8860B' }, { key: 'repeat', name: T('Repeat riders'), color: '#2e9e6a', dash: true }], 'Daily claims, new riders and repeat riders') + '</section>' +
    '<section class="ag-box"><h3>' + esc(T('By venue type')) + '</h3><div class="sub">' + esc(T('Cost per rider')) + ' and ' + esc(T('Repeat rate')) + '</div>' + (d.by_venue_type.length ? '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">' + esc(T('Venue')) + '</th><th scope="col" class="ag-num">' + esc(T('Acquired')) + '</th><th scope="col" class="ag-num">' + esc(T('Repeat rate')) + '</th><th scope="col" class="ag-num">' + esc(T('Cost per rider')) + '</th></tr></thead><tbody>' + d.by_venue_type.map(function (v) { return '<tr><td>' + esc(v.venue_type) + ' <small>(' + esc(v.agents) + ')</small></td><td class="ag-num">' + esc(v.acquired) + '</td><td class="ag-num">' + esc(v.repeat_rate) + '%</td><td class="ag-num">' + esc(etb(v.cost_per_rider)) + '</td></tr>'; }).join('') + '</tbody></table></div>' : K.empty('📊', 'No data yet', '')) + '</section></div>';
  h += '<section class="ag-box"><h3>' + esc(T('Per agent')) + '</h3><div class="sub">Click a column title to sort.</div>' + agentTable() + '</section>';
  return h;
}
function sortedAgents() {
  var k = RP.sort, dir = RP.dir;
  return RP.d.agents.slice().sort(function (a, b) { var x = a[k], y = b[k]; if (typeof x === 'string') return dir * x.localeCompare(y); return dir * ((A.f(x)) - (A.f(y))); });
}
function agentTable() {
  var rows = sortedAgents();
  if (!rows.length) return K.empty('🤝', 'No agents yet', '');
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr>' + SORTS.map(function (s) { var on = RP.sort === s[0]; return '<th scope="col" aria-sort="' + (on ? (RP.dir > 0 ? 'ascending' : 'descending') : 'none') + '"><button type="button" class="ak-btn" style="border:0;background:none;padding:0;font:inherit;font-weight:700" data-sort="' + s[0] + '">' + esc(T(s[1])) + (on ? (RP.dir > 0 ? ' ▲' : ' ▼') : '') + '</button></th>'; }).join('') + '</tr></thead><tbody>' +
    rows.map(function (a) { return '<tr><td><b>' + esc(a.full_name) + '</b> <small>' + esc(a.tier) + '</small></td><td>' + esc(a.venue_name) + '</td><td class="ag-num">' + esc(a.claims) + '</td><td class="ag-num">' + esc(a.acquired) + '</td><td class="ag-num">' + esc(a.repeat_rate) + '%</td><td class="ag-num">' + esc(a.rejected) + '</td><td class="ag-num">' + esc(etb(a.cost)) + '</td><td class="ag-num">' + esc(etb(a.cost_per_rider)) + '</td><td>' + A.riskBar(a.risk_score) + '</td></tr>'; }).join('') + '</tbody></table></div>';
}
function loadReports() {
  return run('admin_fa_reports', { days: RP.days }).then(function (r) {
    if (!ok(r)) { RP.err = K.failText(r, 'Could not load reports.'); RP.d = null; } else { RP.err = ''; RP.d = dat(r); }
    var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'reports') box.innerHTML = reportsHtml();
  });
}

/* ======================= SETTINGS ======================= */
var ST = { vals: {}, meta: {}, msg: '', err: '', loaded: false };
var GROUPS = [
  ['Programme', [['program_enabled', 'Programme switch (agents can only earn while this is on)', 'switch']]],
  ['Bonuses', [['bonus_first_etb', 'First-trip bonus (ETB)'], ['bonus_repeat_etb', 'Repeat bonus (ETB)'], ['repeat_trips_required', 'Trips needed for the repeat bonus'], ['repeat_window_days', 'Days to complete those trips'], ['qualify_window_days', 'Days a rider has to take a first qualifying trip']]],
  ['Qualifying trips', [['min_trip_km', 'Minimum trip distance (km, 0 = off)'], ['min_trip_minutes', 'Minimum trip time (minutes, 0 = off)'], ['require_digital_payment', 'Only count trips paid by wallet or Chapa (not cash)', 'switch']]],
  ['Hold and clawback', [['hold_days', 'Hold before a bonus is payable (days)'], ['hold_days_silver', 'Hold for Silver agents (days)'], ['hold_days_gold', 'Hold for Gold agents (days)'], ['clawback_window_days', 'Days a bonus can be taken back if the trip is refunded']]],
  ['Caps and budget', [['daily_cap_etb', 'Daily cap per agent (ETB, 0 = none)'], ['weekly_cap_etb', 'Weekly cap per agent (ETB, 0 = none)'], ['monthly_budget_etb', 'Monthly programme budget (ETB, 0 = none)'], ['budget_alert_pct', 'Alert when this % of the budget is used'], ['min_payout_etb', 'Minimum payout per agent (ETB)']]],
  ['Agent booking', [['agent_booking_enabled', 'Agents can book rides for customers (turn off to pause it)', 'switch'], ['agent_booking_daily_cap', 'Most bookings per agent in 24 hours'], ['agent_booking_cash_counts', 'A cash trip can earn the bonus for a customer an agent booked', 'switch'], ['agent_booking_sms', 'Text a NEW customer automatically when an agent books their first ride (needs the SMS service to be set up)', 'switch'], ['agent_booking_review_trips', 'Review an agent\'s first booked customers: how many must pass before they are trusted (0 = never review)']]],
  ['Risk scoring', [['risk_hold_threshold', 'Hold for review from this score'], ['risk_high_threshold', 'Call it "high risk" from this score'], ['risk_w_same_device', 'Points: claimed from the agent\'s own device'], ['risk_w_repeat_pair', 'Points: same agent and driver again and again'], ['risk_w_sequential_phones', 'Points: booked customers with phone numbers right next to each other'], ['risk_w_burst', 'Points: burst of referrals'], ['risk_w_code_reuse', 'Points: one device, several referrals'], ['risk_w_short_trip', 'Points: very short trip'], ['risk_w_odd_hours', 'Points: trip at odd hours'], ['risk_w_agent_history', 'Points: agent with many rejections'], ['risk_burst_count', 'A burst is this many referrals...'], ['risk_burst_minutes', '...within this many minutes'], ['risk_pair_threshold', 'Same driver counts from this many riders'], ['risk_short_trip_km', 'A very short trip is under (km)'], ['risk_odd_hour_start', 'Odd hours start (0-23)'], ['risk_odd_hour_end', 'Odd hours end (0-24)']]]
];
var GMETA = {
  'Programme': ['⚡', 'The master switch for the whole referral programme.'],
  'Bonuses': ['💰', 'What agents earn and the time limits that apply.'],
  'Qualifying trips': ['✅', 'Which trips count towards a bonus.'],
  'Hold and clawback': ['⏳', 'How long a bonus waits before it can be paid, and how long it can be taken back.'],
  'Caps and budget': ['🛡️', 'Limits that protect your budget.'],
  'Agent booking': ['🚕', 'Agents booking rides for their customers.'],
  'Risk scoring': ['🔍', 'The points that decide when a bonus waits for your review.']
};
/* "Daily cap per agent (ETB, 0 = none)" -> title "Daily cap per agent", unit "ETB", note "0 = none". */
function splitLabel(label) {
  var m = /^(.*?)\s*\(([^)]*)\)\s*$/.exec(label), title = label, unit = '', note = '';
  if (m) {
    var inner = m[2].split(',').map(function (x) { return x.trim(); }), u = inner[0];
    if (/^(ETB|days|km|minutes)$/i.test(u)) { title = m[1]; unit = u; note = inner.slice(1).join(', '); }
  }
  if (!unit && /%/.test(label)) unit = '%';
  return { title: title.replace(/\s*[:]\s*$/, ''), unit: unit, note: note };
}
function fieldHtml(g, def, edit) {
  var key = def[0], m = ST.meta[key] || {}, v = ST.vals[key];
  var range = (m.min != null ? 'Default ' + m.default + '  ·  Allowed ' + m.min + ' to ' + m.max : 'Default ' + m.default);
  if (def[2] === 'switch') return '<label class="ag-row ag-tgl" data-fl="' + key + '"><span class="ag-rt"><b>' + esc(def[1]) + '</b></span><span class="ag-sw2"><input type="checkbox" data-set="' + key + '"' + (A.f(v) ? ' checked' : '') + (edit ? '' : ' disabled') + '><i aria-hidden="true"></i></span></label>';
  var sp = splitLabel(def[1]);
  return '<label class="ag-row" data-fl="' + key + '"><span class="ag-rt"><b>' + esc(sp.title) + '</b><small>' + esc((sp.note ? sp.note + '  ·  ' : '') + range) + '</small></span>' +
    '<span class="ag-num-in"><input class="ak-in" type="number" inputmode="decimal" step="any" data-set="' + key + '" min="' + esc(m.min) + '" max="' + esc(m.max) + '" value="' + esc(v) + '"' + (edit ? '' : ' disabled') + '>' + '<em>' + esc(sp.unit) + '</em>' + '</span></label>';
}
function settingsHtml() {
  if (ST.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(ST.err) + '</div></div>';
  if (!ST.loaded) return K.skeleton(4);
  var edit = A.can('agents.manage');
  var h = edit ? '' : '<div class="ag-banner"><div><b>View only</b><span>You can see these numbers but not change them.</span></div></div>';
  h += '<div id="ag-set-msg" role="status" aria-live="polite" class="ag-sr"></div>';
  if (edit) h += '<section class="ag-box ag-sec"><header class="ag-sh"><span class="ag-si" aria-hidden="true">\u2709\ufe0f</span><span class="ag-st"><h3>' + esc(T('Text messages (SMS)')) + '</h3><p>' + esc(T('Send one test text to check that the SMS service works. To choose the language and edit the wording of the customer texts, open the Messages tab. On the free test package use a number you verified in the SMS dashboard.')) + '</p></span></header>' +
    '<div class="ag-row" style="cursor:default"><span class="ag-rt"><b>' + esc(T('Send a test text to')) + '</b><small>' + esc(T('For example 0911234567')) + '</small></span><span class="ag-num-in"><input class="ak-in" id="ag-sms-ph" type="tel" inputmode="tel" autocomplete="off" placeholder="09..." style="width:150px;text-align:left"><button type="button" class="ak-btn gold" data-sms-test>' + esc(T('Send test')) + '</button><button type="button" class="ak-btn" data-sms-test="am">' + esc(T('Test in Amharic')) + '</button></span></div></section>';
  GROUPS.forEach(function (g, gi) {
    var gm = GMETA[g[0]] || ['⚙️', ''];
    h += '<section class="ag-box ag-sec"><header class="ag-sh"><span class="ag-si" aria-hidden="true">' + gm[0] + '</span><span class="ag-st"><h3>' + esc(T(g[0])) + '</h3>' + (gm[1] ? '<p>' + esc(T(gm[1])) + '</p>' : '') + '</span>' +
      (edit ? '<button type="button" class="ag-ghost" data-reset="' + gi + '">' + esc(T('Reset section to defaults')) + '</button>' : '') + '</header>' +
      '<div class="ag-rows">' + g[1].map(function (d) { return fieldHtml(g, d, edit); }).join('') + '</div>' +
      (g[0] === 'Risk scoring' ? '<p class="ag-note">How a score works: each signal adds its points (capped at 100). From the "hold" score the bonus waits for you in the Fraud queue. Same account, phone, Etel ID or payout number as the rider is always blocked, whatever these numbers are.</p>' : '') + '</section>';
  });
  if (edit) h += '<div class="ag-savebar"><span id="ag-chg-n" class="ag-chg-n" aria-live="polite"></span><button type="button" class="ak-btn gold" data-save-set>' + esc(T('Save changes')) + '</button></div>';
  return h;
}
function loadSettings() {
  return run('admin_fa_settings_get').then(function (r) {
    if (!ok(r)) { ST.err = K.failText(r, 'Could not load settings.'); } else { ST.err = ''; ST.vals = Object.assign({}, dat(r).settings); ST.meta = dat(r).meta; A.S.cfg = dat(r).settings; ST.loaded = true; }
    var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'settings') box.innerHTML = settingsHtml();
  });
}
function changed() {
  var out = {}; Array.prototype.forEach.call(panel().querySelectorAll('[data-set]'), function (el) {
    var key = el.getAttribute('data-set'), val = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
    if (String(A.f(val)) !== String(A.f(ST.vals[key])) || (el.type !== 'checkbox' && val === '')) out[key] = val;
  });
  return out;
}
function markChanged() {
  var n = 0;
  Array.prototype.forEach.call(panel().querySelectorAll('[data-fl]'), function (l) { var el = l.querySelector('[data-set]'), v = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value, c = String(A.f(v)) !== String(A.f(ST.vals[el.getAttribute('data-set')])); if (c) n++; l.classList.toggle('chg', c); });
  var c = document.getElementById('ag-chg-n'); if (c) c.textContent = n ? n + (n === 1 ? ' unsaved change' : ' unsaved changes') : '';
}
function saveSettings(btn) {
  var ch = changed(), keys = Object.keys(ch);
  var live = function (m, bad) { var l = document.getElementById('ag-set-msg'); if (l) l.textContent = m; K.toast(m, bad); };
  if (!keys.length) { live(T('No changes to save')); return; }
  var switching = ch.program_enabled !== undefined;
  var go = function () { return A.busy(btn, function () { return run('admin_fa_settings_save', { settings: ch }); }).then(function (r) {
    if (!ok(r)) { live(K.failText(r, 'Could not save.'), true); return; }
    ST.vals = Object.assign({}, dat(r).settings); A.S.cfg = dat(r).settings; live(T('Saved') + ' (' + keys.length + ')');
    var box = document.getElementById('ag-screen'); if (box) box.innerHTML = settingsHtml();
  }); };
  if (switching && Number(ch.program_enabled) === 1) return K.confirm({ title: 'Switch the programme ON?', text: 'Agents can then earn bonuses from real trips. Check the bonus amounts and caps first.', label: 'Switch on' }).then(function (res) { if (res) return go(); });
  if (switching) return K.confirm({ title: 'Switch the programme OFF?', text: 'No new bonuses are earned. Money already earned stays and can still be paid.', label: 'Switch off', danger: true }).then(function (res) { if (res) return go(); });
  return go();
}

/* ======================= AUDIT LOG ======================= */
var AU = { rows: [], entity: '', q: '', loaded: false, more: false, chain: null, err: '' };
function auditHtml() {
  var tools = '<div class="ag-tools"><label class="ag-sr" for="ag-ent">Type</label><select class="ak-sel" id="ag-ent">' + [['', 'Everything'], ['agent', 'Agents'], ['referral', 'Referrals'], ['earning', 'Bonuses'], ['batch', 'Payout batches'], ['setting', 'Settings'], ['job', 'Earnings runs']].map(function (o) { return '<option value="' + o[0] + '"' + (AU.entity === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
    '<button type="button" class="ak-btn" data-ag-chain>' + esc(T('Verify chain')) + '</button>' + (AU.chain ? (AU.chain.ok ? '<span class="ag-pill green">Unchanged (' + esc(AU.chain.rows) + ' entries)</span>' : '<span class="ag-pill red">Entry #' + esc(AU.chain.first_bad_id) + ' was changed</span>') : '') +
    '<span style="flex:1"></span><button type="button" class="ak-btn" data-ag-exp-au>' + esc(T('Export CSV')) + '</button></div>';
  if (AU.err) return tools + '<div class="ak-empty"><b>⚠️</b><div>' + esc(AU.err) + '</div></div>';
  if (!AU.loaded) return tools + K.skeleton(4);
  if (!AU.rows.length) return tools + K.empty('🧾', 'No entries', 'Every decision is recorded here and cannot be edited.');
  return tools + '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">' + esc(T('Time')) + '</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">' + esc(T('Reason')) + '</th></tr></thead><tbody>' + AU.rows.map(function (e) {
    var x = A.feedText(e);
    return '<tr><td title="' + esc(K.fullDate(e.created_at)) + '">' + esc(A.when(e.created_at)) + '<br><small>' + esc(K.fullDate(e.created_at)) + '</small></td><td>' + esc(e.actor_type) + (e.actor_id ? ' #' + esc(e.actor_id) : '') + '</td><td>' + x.icon + ' ' + esc(x.text) + '<br><small>' + esc(e.entity) + (e.entity_id ? ' #' + esc(e.entity_id) : '') + '</small></td><td class="ag-why-td">' + esc(e.reason || '') + '</td></tr>';
  }).join('') + '</tbody></table></div>' + (AU.more ? '<p style="text-align:center;margin-top:12px"><button type="button" class="ak-btn" data-ag-au-more>' + esc(T('Load more')) + '</button></p>' : '');
}
function loadAudit(reset) {
  if (reset) { AU.rows = []; AU.loaded = false; }
  var body = { limit: 50, offset: AU.rows.length }; if (AU.entity) body.entity = AU.entity;
  return run('admin_fa_audit_list', body).then(function (r) {
    AU.loaded = true; if (!ok(r)) AU.err = K.failText(r, 'Could not load the audit log.'); else { AU.err = ''; var got = dat(r).entries || []; AU.rows = AU.rows.concat(got); AU.more = got.length === 50; }
    var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'audit') box.innerHTML = auditHtml();
  });
}

/* ======================= wiring ======================= */
panel().addEventListener('click', function (e) {
  var t = e.target, b;
  if (t.closest('[data-pc],[data-pc-all]')) return;
  if (t.closest('[data-pc-create]')) { createBatch(t.closest('[data-pc-create]')); return; }
  if ((b = t.closest('[data-batch]'))) { openBatch(b.getAttribute('data-batch')); return; }
  if ((b = t.closest('[data-seg="ag-days"]'))) { RP.days = parseInt(b.getAttribute('data-val'), 10); RP.d = null; A.paintScreen(); loadReports(); return; }
  if ((b = t.closest('[data-sort]'))) { var k = b.getAttribute('data-sort'); if (RP.sort === k) RP.dir = -RP.dir; else { RP.sort = k; RP.dir = (k === 'full_name' || k === 'venue_name') ? 1 : -1; } var box = document.getElementById('ag-screen'); if (box) box.innerHTML = reportsHtml(); return; }
  if (t.closest('[data-ag-exp-rp]') && RP.d) { A.exportRows('etel-agent-report-' + RP.days + 'd.csv', ['Agent', 'Venue', 'Type', 'Tier', 'Claims', 'Acquired', 'Repeaters', 'Repeat rate %', 'Rejected', 'Cost ETB', 'Cost per rider ETB', 'Held ETB', 'Risk'], RP.d.agents.map(function (a) { return [a.full_name, a.venue_name, a.venue_type, a.tier, a.claims, a.acquired, a.repeaters, a.repeat_rate, a.rejected, a.cost, a.cost_per_rider, a.held, a.risk_score]; })); return; }
  if ((b = t.closest('[data-sms-test]'))) { var ph = document.getElementById('ag-sms-ph'); A.busy(b, function () { return run('admin_fa_sms_test', { phone: ph ? ph.value : '', lang: b.getAttribute('data-sms-test') === 'am' ? 'am' : 'en' }); }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'The test text was not sent.'), true); return; } K.toast('Test text sent. Check the phone.'); }); return; }
  if ((b = t.closest('[data-reset]'))) { var gi = parseInt(b.getAttribute('data-reset'), 10); GROUPS[gi][1].forEach(function (d) { var el = panel().querySelector('[data-set="' + d[0] + '"]'); var dv = ST.meta[d[0]] ? ST.meta[d[0]].default : 0; if (el) { if (el.type === 'checkbox') el.checked = !!A.f(dv); else el.value = dv; } }); markChanged(); return; }
  if ((b = t.closest('[data-save-set]'))) { saveSettings(b); return; }
  if (t.closest('[data-ag-chain]')) { run('admin_fa_audit_list', { limit: 1, verify: 1 }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not check.'), true); return; } AU.chain = dat(r).chain; var box = document.getElementById('ag-screen'); if (box) box.innerHTML = auditHtml(); }); return; }
  if (t.closest('[data-ag-au-more]')) { loadAudit(false); return; }
  if (t.closest('[data-ag-exp-au]')) { A.exportRows('etel-agents-audit.csv', ['Time', 'Who', 'Action', 'Entity', 'Entity ID', 'Reason', 'Detail'], AU.rows.map(function (e) { return [e.created_at, e.actor_type + (e.actor_id ? ' #' + e.actor_id : ''), e.action, e.entity, e.entity_id, e.reason, e.detail]; })); return; }
});
panel().addEventListener('change', function (e) {
  var t = e.target;
  var relabel = function () { var n = Object.keys(P.pick).filter(function (k) { return P.pick[k]; }).length, c2 = panel().querySelector('[data-pc-create]'); if (c2) c2.textContent = T('Create payout batch') + ' (' + (n || P.cands.length) + ')'; };
  if (t.matches && t.matches('[data-pc-all]')) { P.cands.forEach(function (c) { P.pick[c.id] = t.checked; }); Array.prototype.forEach.call(panel().querySelectorAll('[data-pc]'), function (cb) { cb.checked = t.checked; }); relabel(); return; }
  if (t.matches && t.matches('[data-pc]')) { P.pick[t.getAttribute('data-pc')] = t.checked; relabel(); return; }
  if (t.id === 'ag-ent') { AU.entity = t.value; loadAudit(true); }
});
panel().addEventListener('input', function (e) { if (e.target.matches && e.target.matches('[data-set]')) markChanged(); });
panel().addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  var row = e.target.closest ? e.target.closest('tr[data-batch]') : null; if (!row || e.target !== row) return; e.preventDefault(); row.click();
});

A.register('payouts', { render: payoutsHtml, open: function () { P.loaded = false; A.paintScreen(); return loadPayouts(); } });
A.register('reports', { render: reportsHtml, open: function () { RP.d = null; A.paintScreen(); return loadReports(); } });
A.register('settings', { render: settingsHtml, open: function () { ST.loaded = false; A.paintScreen(); return loadSettings(); } });
A.register('audit', { render: auditHtml, open: function () { A.paintScreen(); return loadAudit(true); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
