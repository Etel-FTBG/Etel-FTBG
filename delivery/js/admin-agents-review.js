/* ETEL Admin - Agents: Agents list + approval, Referrals review, Fraud queue.  (needs js/admin-agents-pro.js)
   Every decision asks for a plain-language reason where it matters; the server writes it to the append-only audit log and tells the
   agent in simple words. Nothing here is automatic: a held bonus stays held until an admin releases or rejects it. */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__review) return; A.__review = true;
var K = A.K, esc = A.esc, T = A.T, run = A.run, ok = A.ok, dat = A.dat;
var panel = function () { return document.getElementById('panel-agents'); };

var AGE = { unchecked: ['Not verified', 'amber'], auto_verified: ['Verified by EtelID', 'green'], manual_verified: ['Checked by an admin', 'green'], failed: ['Under 18', 'red'] };
var TYPE = { hotel: 'Hotel', parking: 'Parking', shoe_shine: 'Shoe shine', restaurant: 'Restaurant', shop: 'Shop', other: 'Other' };
var KIND = { first_trip: 'First trip bonus', repeat3: 'Repeat bonus', clawback: 'Adjustment (reversed)' };
var RISKTXT = { SEQUENTIAL_PHONES: 'Booked for customers with phone numbers next to each other', SHARED_IDENTITY: 'Shares a payout number or device with another agent', SAME_DEVICE: 'Same device as the agent', CODE_REUSE: 'One device claimed several referrals', BURST: 'Many referrals in a short time', REPEAT_PAIR: 'Same driver again and again', SHORT_TRIP: 'Very short trips', ODD_HOURS: 'Trips at odd hours', AGENT_HISTORY: 'Many earlier referrals rejected' };
function agePill(s) { var a = AGE[s] || [s, '']; return '<span class="ag-pill ' + a[1] + '">' + esc(T(a[0])) + '</span>'; }
function kindLabel(k) { return T(KIND[k] || k); }

/* ======================= AGENTS ======================= */
var G = { rows: [], status: '', q: '', loaded: false, err: '' };
function agentRows() {
  if (!G.loaded) return K.skeleton(4);
  if (G.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(G.err) + '</div></div>';
  if (!G.rows.length) return K.empty('🤝', 'No agents here', G.q || G.status ? 'Try another filter or search.' : 'Agents appear here when they apply in the Agent portal.');
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th scope="col">' + esc(T('Agent')) + '</th><th scope="col">' + esc(T('Venue')) + '</th><th scope="col">' + esc(T('Status')) + '</th><th scope="col">' + esc(T('Age')) + '</th><th scope="col">' + esc(T('Tier')) + '</th><th scope="col">' + esc(T('Code')) + '</th><th scope="col">' + esc(T('Risk')) + '</th><th scope="col" class="ag-num">' + esc(T('Referrals')) + '</th><th scope="col" class="ag-num">' + esc(T('Payable')) + ' / ' + esc(T('Held')) + '</th></tr></thead><tbody>' +
    G.rows.map(function (a) {
      return '<tr data-open tabindex="0" data-agent-row="' + esc(a.id) + '"><td><b>' + esc(a.full_name) + '</b><br><small>' + esc(a.phone) + '</small></td><td>' + esc(a.venue_name) + '<br><small>' + esc(TYPE[a.venue_type] || a.venue_type) + (a.venue_zone ? ' - ' + esc(a.venue_zone) : '') + '</small></td><td>' + A.statusPill(a.status) + '</td><td>' + agePill(a.age_status) + '</td><td>' + esc(a.tier) + '</td><td class="ag-mono">' + esc(a.code || '-') + '</td><td>' + A.riskBar(a.risk_score) + '</td><td class="ag-num">' + esc(a.referrals) + '</td><td class="ag-num">' + esc(A.etb(a.payable)) + ' / ' + esc(A.etb(a.held)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
}
function agentsHtml() {
  return '<div class="ag-tools"><div class="ak-search"><u aria-hidden="true">🔎</u><input id="ag-aq" type="search" placeholder="Name, phone, venue or code" aria-label="' + esc(T('Search')) + '" value="' + esc(G.q) + '"></div>' +
    K.seg('ag-astatus', [['', T('All')], ['pending', T('Pending')], ['approved', T('Approved')], ['suspended', T('Suspended')], ['rejected', T('Rejected')]], G.status) +
    '<span class="ak-count" id="ag-acount">' + (G.loaded ? G.rows.length + ' ' + esc(T('Agents')) : '') + '</span><span style="flex:1"></span><button type="button" class="ak-btn" data-ag-exp-agents>' + esc(T('Export CSV')) + '</button></div><div id="ag-alist">' + agentRows() + '</div>';
}
function loadAgents() {
  var body = { limit: 300 }; if (G.status) body.status = G.status; if (G.q) body.q = G.q;
  return run('admin_fa_agents_list', body).then(function (r) {
    G.loaded = true; if (!ok(r)) { G.err = K.failText(r, 'Could not load agents.'); G.rows = []; } else { G.err = ''; G.rows = dat(r).agents || []; }
    var l = document.getElementById('ag-alist'); if (l) l.innerHTML = agentRows();
    var c = document.getElementById('ag-acount'); if (c) c.textContent = G.rows.length + ' ' + T('Agents');
  });
}
function agentDrawer(id) {
  return run('admin_fa_agent_get', { id: id }).then(function (r) {
    if (!ok(r)) { K.toast(K.failText(r, 'Could not open this agent.'), true); return; }
    var d = dat(r), a = d.agent, manage = A.can('agents.manage');
    var head = '<div class="ak-top"><div class="ak-av lg">' + esc(K.initials(a.full_name)) + '</div><div class="ak-nm"><b>' + esc(a.full_name) + '</b><small>' + esc(a.venue_name) + '</small></div></div>';
    var top = (a.risk_reasons || []).map(function (x) { return '<li><b>x' + esc(x.count) + '</b><span>' + esc(RISKTXT[x.code] || x.code) + '</span></li>'; }).join('');
    var body = '<div class="ak-sec"><h4>Profile</h4>' + K.kv([['Status', A.statusPill(a.status), true], ['Phone', a.phone], ['Etel ID', a.etel_id || '-'], ['Venue', a.venue_name + ' (' + (TYPE[a.venue_type] || a.venue_type) + ')'], ['Zone', a.venue_zone || '-'], ['Payout phone (Telebirr)', a.payout_phone], ['Code', a.code || 'Not issued yet'], ['Tier', a.tier], ['Applied', K.fullDate(a.created_at)]]) + '</div>' +
      '<div class="ak-sec"><h4>Age check (must be 18 or older)</h4><p style="margin:0 0 6px">' + agePill(a.age_status) + '</p><p style="font-size:13px;color:var(--mute);margin:0">' + esc(a.age_note || '') + '</p></div>' +
      (d.shared_with && d.shared_with.length ? '<div class="ak-sec"><h4>Shared details</h4><ul class="ag-why">' + d.shared_with.map(function (x) { return '<li><b>!</b><span><b>' + esc(x.name) + '</b> uses the same ' + esc(x.why) + '</span></li>'; }).join('') + '</ul></div>' : '') +
      '<div class="ak-sec"><h4>Risk</h4><p style="margin:0 0 8px">' + A.riskBar(a.risk_score) + '</p>' + (top ? '<ul class="ag-why">' + top + '</ul>' : '<p style="font-size:13px;color:var(--mute);margin:0">No repeated risk signals.</p>') + '</div>' +
      (d.bookings && d.bookings.length ? '<div class="ak-sec"><h4>Rides booked for customers (' + d.bookings.length + ')</h4><ul class="ag-feed">' + d.bookings.map(function (x) { return '<li><span class="ag-mono" style="min-width:110px">' + esc(x.cust_phone) + '</span><span style="flex:1">' + esc(x.vehicle_type) + ' · ' + esc(x.ride_status || '') + (+x.bonus_eligible ? '' : ' · no bonus') + '</span><small>' + esc(K.ago(x.created_at)) + '</small></li>'; }).join('') + '</ul></div>' : '') +
      '<div class="ak-sec"><h4>Referrals (' + d.referrals.length + ')</h4>' + (d.referrals.length ? '<ul class="ag-feed">' + d.referrals.slice(0, 15).map(function (x) { return '<li><span class="ag-mono" style="min-width:110px">' + esc(x.rider_phone) + '</span><span style="flex:1">' + A.statusPill(x.status === 'claimed' ? 'pending' : x.status) + '</span>' + (x.risk_score ? A.riskBar(x.risk_score) : '') + '<time>' + esc(A.when(x.claimed_at)) + '</time></li>'; }).join('') + '</ul>' : '<p style="font-size:13px;color:var(--mute);margin:0">None yet.</p>') + '</div>' +
      '<div class="ak-sec"><h4>History</h4>' + (d.audit.length ? '<ul class="ag-feed">' + d.audit.slice(0, 10).map(function (e) { var x = A.feedText(e); return '<li><i aria-hidden="true">' + x.icon + '</i><span style="flex:1;overflow-wrap:anywhere">' + esc(x.text) + (e.reason ? ' - ' + esc(e.reason) : '') + '</span><time>' + esc(A.when(e.created_at)) + '</time></li>'; }).join('') + '</ul>' : '<p style="font-size:13px;color:var(--mute);margin:0">No history.</p>') + '</div>';
    var foot = '';
    if (manage) {
      if (a.status === 'pending' || a.status === 'rejected') foot += '<button type="button" class="ak-btn gold" data-do="approve">' + esc(T('Approve')) + '</button>';
      if (a.status === 'pending') foot += '<button type="button" class="ak-btn danger" data-do="reject">' + esc(T('Reject')) + '</button>';
      if (a.status === 'approved') foot += '<button type="button" class="ak-btn danger" data-do="suspend">' + esc(T('Suspend')) + '</button>';
      if (a.status === 'suspended') foot += '<button type="button" class="ak-btn gold" data-do="reinstate">' + esc(T('Reinstate')) + '</button>';
    }
    var dr = K.openDrawer(head, body, foot);
    dr.addEventListener('click', function (e) {
      var b = e.target.closest('[data-do]'); if (!b) return;
      decideAgent(a, b.getAttribute('data-do'), b);
    });
  });
}
function decideAgent(a, what, btn) {
  var verified = a.age_status === 'auto_verified' || a.age_status === 'manual_verified', p;
  if (what === 'approve') {
    p = verified ? K.confirm({ title: 'Approve ' + a.full_name + '?', text: 'They get a referral code and QR card and can start earning. Age is already verified.', label: T('Approve') })
      : K.confirm({ title: 'Check the ID before approving', text: 'EtelID could not confirm this person is 18 or older. Look at their Fayda ID card, then write what you saw. This is recorded in the audit log.', reason: 'What did you check? (for example: Fayda card seen, born 1990)', required: true, label: 'I checked: 18 or older - approve' });
  } else if (what === 'reinstate') {
    p = K.confirm({ title: 'Reinstate ' + a.full_name + '?', text: 'Their code works again.', reason: 'Why? (recorded)', required: true, label: T('Reinstate') });
  } else {
    p = K.confirm({ title: (what === 'reject' ? 'Reject ' : 'Suspend ') + a.full_name + '?', text: what === 'reject' ? 'They cannot earn. The agent is told this in simple words.' : 'Their code stops working and no new bonuses start. Existing money is not touched.', reason: 'Why? (recorded, and shown to the agent in simple words)', required: true, danger: true, label: what === 'reject' ? T('Reject') : T('Suspend') });
  }
  return p.then(function (res) {
    if (!res) return;
    var body = { id: a.id, decision: what };
    if (what === 'approve' && !verified) { body.manual_age_ok = true; body.age_note = res.reason; }
    else if (res.reason) body.reason = res.reason;
    return A.busy(btn, function () { return run('admin_fa_agent_decide', body); }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      K.toast(what === 'approve' ? 'Approved. Code: ' + dat(r).code : 'Saved');
      K.closeDrawer(); loadAgents(); refreshBadges();
    });
  });
}

/* ======================= EARNINGS TABLES (Referrals + Fraud queue) ======================= */
function earnTable(rows, sel) {
  if (!rows.length) return K.empty('📲', 'Nothing here', 'No bonuses match this view.');
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr>' + (sel ? '<th scope="col" style="width:36px"></th>' : '') +
    '<th scope="col">' + esc(T('Agent')) + '</th><th scope="col">' + esc(T('Rider')) + '</th><th scope="col">' + esc(T('Type')) + '</th><th scope="col" class="ag-num">' + esc(T('Amount')) + '</th><th scope="col">' + esc(T('Status')) + '</th><th scope="col">' + esc(T('Risk')) + '</th><th scope="col">' + esc(T('Reason')) + '</th><th scope="col">' + esc(T('Time')) + '</th></tr></thead><tbody>' +
    rows.map(function (e) {
      var why = (e.risk_reasons && e.risk_reasons.length) ? e.risk_reasons.map(function (x) { return x.text; }).slice(0, 2).join('; ') : (e.reason_text && e.reason_code ? e.reason_text : '');
      return '<tr data-open tabindex="0" data-earn-row="' + esc(e.id) + '">' + (sel ? '<td><input type="checkbox" data-pick="' + esc(e.id) + '" aria-label="Select bonus ' + esc(e.id) + '"></td>' : '') +
        '<td><b>' + esc(e.agent_name) + '</b><br><small>' + esc(e.venue_name) + '</small></td><td class="ag-mono">' + esc(e.rider_phone) + '</td><td>' + esc(kindLabel(e.kind)) + '</td><td class="ag-num">' + esc(A.etb(e.amount)) + '</td><td>' + A.statusPill(e.status) + '</td><td>' + A.riskBar(e.risk_score) + '</td><td class="ag-why-td">' + esc(why) + '</td><td class="ag-nowrap">' + esc(A.when(e.earned_at)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
}
function earnDrawer(e) {
  var manage = A.can('agents.manage');
  var head = '<div class="ak-top"><div class="ak-av lg">💰</div><div class="ak-nm"><b>' + esc(kindLabel(e.kind)) + ' - ' + esc(A.etb(e.amount)) + '</b><small>' + esc(e.agent_name) + ' - ' + esc(e.venue_name) + '</small></div></div>';
  var body = '<div class="ak-sec"><h4>Status</h4><p style="margin:0 0 8px">' + A.statusPill(e.status) + ' ' + A.riskBar(e.risk_score) + '</p>' + (e.reason_code ? '<p style="font-size:13px;margin:0;color:var(--ink)">' + esc(e.reason_text) + (e.reason_note ? ' Note: ' + esc(e.reason_note) : '') + '</p>' : '') + '</div>' +
    '<div class="ak-sec"><h4>Why this risk score</h4>' + A.reasonList(e.risk_reasons) + '<p style="font-size:12px;color:var(--mute);margin:10px 0 0">Scores come from fixed rules (see Settings). The score never rejects or pays by itself: a person decides.</p></div>' +
    '<div class="ak-sec"><h4>Details</h4>' + K.kv([['Rider (masked)', e.rider_phone], ['Trip', e.ride_id ? '#' + e.ride_id : '-'], ['Driver', e.driver_id ? '#' + e.driver_id : '-'], ['Agent tier', e.tier], ['Trip finished', K.fullDate(e.earned_at)], ['Can be paid after', K.fullDate(e.payable_at)]]) + '</div>';
  var foot = '';
  if (manage && e.status === 'held') foot += '<button type="button" class="ak-btn gold" data-do="release">' + esc(T('Release')) + '</button>';
  if (manage && (e.status === 'held' || e.status === 'pending')) foot += '<button type="button" class="ak-btn danger" data-do="reject">' + esc(T('Reject')) + '</button>';
  var dr = K.openDrawer(head, body, foot);
  dr.addEventListener('click', function (ev) { var b = ev.target.closest('[data-do]'); if (b) decideEarnings([e.id], b.getAttribute('data-do'), b, true); });
}
function decideEarnings(ids, what, btn, fromDrawer) {
  var n = ids.length, rel = what === 'release';
  return K.confirm({
    title: (rel ? 'Release ' : 'Reject ') + n + (n === 1 ? ' bonus?' : ' bonuses?'),
    text: rel ? 'The bonus goes back to the normal hold timer and can then be paid.' : 'The agent is told this in simple words. This cannot be undone.',
    reason: rel ? 'Why is it safe to release? (recorded)' : 'Why? (recorded, shown to the agent in simple words)', required: true, danger: !rel, label: rel ? T('Release') : T('Reject')
  }).then(function (res) {
    if (!res) return;
    return A.busy(btn, function () { return run('admin_fa_earning_decide', { ids: ids, decision: what, reason: res.reason }); }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      var d = dat(r); K.toast(d.done + ' done' + (d.skipped ? ', ' + d.skipped + ' skipped (already decided)' : ''), !d.done);
      if (fromDrawer) K.closeDrawer();
      F.pick = {}; reloadEarnings(); refreshBadges();
    });
  });
}
function reloadEarnings() { var s = A.S.tab; if (s === 'referrals') return loadRef(true); if (s === 'fraud') return loadFraud(true); }
function refreshBadges() { run('admin_fa_stats').then(function (r) { if (ok(r)) { A.S.stats = dat(r); A.S.badges = { agents: A.S.stats.agents_pending, fraud: A.S.stats.flagged }; A.paintTabsOnly(); } }); }

/* ======================= REFERRALS ======================= */
var R = { rows: [], status: '', kind: '', loaded: false, more: false, err: '' };
function refHtml() {
  return '<div class="ag-tools">' + K.seg('ag-rstatus', [['', T('All')], ['pending', T('Pending')], ['held', T('Held')], ['payable', T('Payable')], ['paid', T('Paid')], ['rejected', T('Rejected')], ['clawed_back', T('Clawed back')]], R.status) +
    K.seg('ag-rkind', [['', 'All types'], ['first_trip', 'First trip'], ['repeat3', 'Repeat']], R.kind) +
    '<span class="ak-count" id="ag-rcount"></span><span style="flex:1"></span><button type="button" class="ak-btn" data-ag-exp-ref>' + esc(T('Export CSV')) + '</button></div><div id="ag-rlist">' + refList() + '</div>';
}
function refList() {
  if (!R.loaded) return K.skeleton(4);
  if (R.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(R.err) + '</div></div>';
  return earnTable(R.rows, false) + (R.more ? '<p style="text-align:center;margin-top:12px"><button type="button" class="ak-btn" data-ag-ref-more>' + esc(T('Load more')) + '</button></p>' : '');
}
function loadRef(reset) {
  if (reset) { R.rows = []; R.loaded = false; }
  var body = { limit: 50, offset: R.rows.length }; if (R.status) body.status = R.status; if (R.kind) body.kind = R.kind;
  return run('admin_fa_earnings_list', body).then(function (r) {
    R.loaded = true;
    if (!ok(r)) { R.err = K.failText(r, 'Could not load referrals.'); } else { R.err = ''; var got = dat(r).earnings || []; R.rows = R.rows.concat(got); R.more = got.length === 50; }
    var l = document.getElementById('ag-rlist'); if (l) l.innerHTML = refList();
    var c = document.getElementById('ag-rcount'); if (c) c.textContent = R.rows.length + (R.more ? '+' : '') + ' ' + T('Referrals');
  });
}

/* ======================= FRAUD QUEUE ======================= */
var F = { mode: 'held', rows: [], pick: {}, loaded: false, err: '' };
function fraudHtml() {
  return '<div class="ag-tools">' + K.seg('ag-fmode', [['held', T('Needs a decision')], ['watch', 'Watchlist (pending, risk 30+)']], F.mode) + '<span class="ak-count" id="ag-fcount"></span><span style="flex:1"></span></div><div id="ag-fbulk"></div><div id="ag-flist">' + fraudList() + '</div>';
}
function bulkBar() {
  var ids = Object.keys(F.pick).filter(function (k) { return F.pick[k]; }), manage = A.can('agents.manage');
  if (!manage || !F.rows.length) return '';
  return '<div class="ag-bulk"><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-pick-all ' + (ids.length === F.rows.length && ids.length ? 'checked' : '') + '> ' + esc(T('Select all')) + '</label><span aria-live="polite"><b>' + ids.length + '</b> ' + esc(T('selected')) + '</span><span style="flex:1"></span>' +
    (F.mode === 'held' ? '<button type="button" class="ak-btn gold" data-bulk="release"' + (ids.length ? '' : ' disabled') + '>' + esc(T('Release')) + '</button>' : '') + '<button type="button" class="ak-btn danger" data-bulk="reject"' + (ids.length ? '' : ' disabled') + '>' + esc(T('Reject')) + '</button></div>';
}
function fraudList() {
  if (!F.loaded) return K.skeleton(3);
  if (F.err) return '<div class="ak-empty"><b>⚠️</b><div>' + esc(F.err) + '</div></div>';
  return earnTable(F.rows, A.can('agents.manage'));
}
function paintFraud() {
  var l = document.getElementById('ag-flist'); if (l) l.innerHTML = fraudList();
  var b = document.getElementById('ag-fbulk'); if (b) b.innerHTML = bulkBar();
  var c = document.getElementById('ag-fcount'); if (c) c.textContent = F.rows.length + ' ' + T('Referrals');
  Object.keys(F.pick).forEach(function (k) { var cb = document.querySelector('[data-pick="' + k + '"]'); if (cb) cb.checked = !!F.pick[k]; });
}
function loadFraud(reset) {
  if (reset) { F.pick = {}; F.loaded = false; }
  var body = F.mode === 'held' ? { status: 'held', limit: 200 } : { status: 'pending', min_risk: 30, limit: 200 };
  return run('admin_fa_earnings_list', body).then(function (r) {
    F.loaded = true; if (!ok(r)) { F.err = K.failText(r, 'Could not load the queue.'); F.rows = []; } else { F.err = ''; F.rows = dat(r).earnings || []; }
    paintFraud();
  });
}

/* ======================= wiring ======================= */
var findEarn = function (id) { return R.rows.concat(F.rows).filter(function (x) { return String(x.id) === String(id); })[0]; };
panel().addEventListener('click', function (e) {
  var t = e.target, b;
  if ((b = t.closest('[data-seg="ag-astatus"]'))) { G.status = b.getAttribute('data-val'); A.paintScreen(); loadAgents(); return; }
  if ((b = t.closest('[data-seg="ag-rstatus"]'))) { R.status = b.getAttribute('data-val'); A.paintScreen(); loadRef(true); return; }
  if ((b = t.closest('[data-seg="ag-rkind"]'))) { R.kind = b.getAttribute('data-val'); A.paintScreen(); loadRef(true); return; }
  if ((b = t.closest('[data-seg="ag-fmode"]'))) { F.mode = b.getAttribute('data-val'); A.paintScreen(); loadFraud(true); return; }
  if (t.closest('[data-pick],[data-pick-all]')) return;     // checkbox clicks are handled in the change listener
  if ((b = t.closest('[data-agent-row]'))) { agentDrawer(b.getAttribute('data-agent-row')); return; }
  if ((b = t.closest('[data-earn-row]'))) { var en = findEarn(b.getAttribute('data-earn-row')); if (en) earnDrawer(en); return; }
  if ((b = t.closest('[data-bulk]'))) { var ids = Object.keys(F.pick).filter(function (k) { return F.pick[k]; }).map(Number); if (ids.length) decideEarnings(ids, b.getAttribute('data-bulk'), b, false); return; }
  if (t.closest('[data-ag-ref-more]')) { loadRef(false); return; }
  if (t.closest('[data-ag-exp-agents]')) { A.exportRows('etel-agents.csv', ['Name', 'Phone', 'Venue', 'Type', 'Zone', 'Status', 'Age check', 'Tier', 'Code', 'Risk', 'Referrals', 'Payable ETB', 'Held ETB', 'Applied'], G.rows.map(function (a) { return [a.full_name, a.phone, a.venue_name, a.venue_type, a.venue_zone, a.status, a.age_status, a.tier, a.code || '', a.risk_score, a.referrals, a.payable, a.held, a.created_at]; })); return; }
  if (t.closest('[data-ag-exp-ref]')) { A.exportRows('etel-referral-bonuses.csv', ['ID', 'Agent', 'Venue', 'Rider (masked)', 'Type', 'Amount ETB', 'Status', 'Risk', 'Reason', 'Trip finished', 'Payable after'], R.rows.map(function (x) { return [x.id, x.agent_name, x.venue_name, x.rider_phone, x.kind, x.amount, x.status, x.risk_score, x.reason_text || '', x.earned_at, x.payable_at]; })); return; }
});
panel().addEventListener('change', function (e) {
  var t = e.target;
  if (t.matches && t.matches('[data-pick]')) { F.pick[t.getAttribute('data-pick')] = t.checked; var bb = document.getElementById('ag-fbulk'); if (bb) bb.innerHTML = bulkBar(); return; }
  if (t.matches && t.matches('[data-pick-all]')) { F.rows.forEach(function (x) { F.pick[x.id] = t.checked; }); paintFraud(); }
});
panel().addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  var row = e.target.closest ? e.target.closest('tr[data-open]') : null; if (!row || e.target !== row) return;
  e.preventDefault(); row.click();
});
panel().addEventListener('input', K.debounce(function (e) { if (e.target.id === 'ag-aq') { G.q = e.target.value.trim(); loadAgents(); } }, 300));

A.register('agents', { render: agentsHtml, open: function (arg) {
  if (arg && arg.indexOf('status:') === 0) G.status = arg.slice(7); else if (arg === 'pending') G.status = 'pending';
  A.paintScreen();
  return loadAgents().then(function () { if (arg && arg.indexOf('open:') === 0) agentDrawer(arg.slice(5)); });
} });
A.register('referrals', { render: refHtml, open: function () { A.paintScreen(); return loadRef(true); } });
A.register('fraud', { render: fraudHtml, open: function () { A.paintScreen(); return loadFraud(true); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
