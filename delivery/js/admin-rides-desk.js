/* ETEL Admin - Rides: operations pulse and review desk.
   The lower half of the Rides tab, rebuilt:
   - Operations pulse: System health and Live trip watch as two cards side by side (all-clear or what needs attention),
     with the backup download, "require two-step sign-in" switch and Cancel ride controls kept.
   - Review desk: one place with five tabs for everything waiting on a decision - rider issue reports, vehicle changes,
     flagged identities, driver documents to approve, and every driver's insurance / inspection / road-fund dates
     (Valid, Expiring soon, Expired, Not set) with a guided form to set them. Counts on top double as tab buttons.
   Data (RideController.php / boot.php): admin_system_health, admin_ride_watch, admin_get_ride_issues, admin_resolve_ride_issue,
   admin_get_pending_vehicle_changes, admin_approve/reject_vehicle_change, admin_get_flagged_identities,
   admin_approve/reject_driver_identity, admin_get_pending_compliance_requests, admin_approve/reject_compliance_document,
   admin_list_driver_compliance, admin_update_driver_compliance, admin_cancel_ride, admin_set_2fa_required.
   The growth chart is untouched apart from its look. Every server value goes through AdminKit.esc; no inline handlers. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var DOCS = [['insurance', 'Insurance'], ['technical_inspection', 'Technical inspection'], ['road_fund', 'Road fund']];
var DOC_LABEL = { insurance: 'Insurance', technical_inspection: 'Technical inspection', road_fund: 'Road fund' };
var DOT = { ok: '#1fa463', info: '#2f6fd0', warn: '#d98e04', bad: '#d6364a' };
var D = { tab: 'issues', cdoc: '', rows: { issues: [], vehicle: [], identity: [], docs: [], comp: [] }, loaded: {}, canManage: true, healthTimer: null, watchTimer: null };

function css() {
  if (document.getElementById('rdk-css')) return;
  var s = document.createElement('style'); s.id = 'rdk-css';
  s.textContent = [
    '.rdk-pulse{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:14px}',
    '.rdk-card{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px 16px;box-shadow:var(--shadow)}',
    '.rdk-card h3{margin:0;font-size:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}',
    '.rdk-card h3 .sp{flex:1}',
    '.rdk-line{display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--line);font-size:13px}',
    '.rdk-line:first-of-type{border-top:0}',
    '.rdk-dot{flex:none;width:10px;height:10px;border-radius:50%;margin-top:5px}',
    '.rdk-nm{flex:none;width:118px;font-weight:700}',
    '.rdk-tx{flex:1;min-width:0;color:var(--mute);word-break:break-word}',
    '.rdk-sev{flex:none;font-size:10.5px;font-weight:800;text-transform:uppercase;padding:2px 8px;border-radius:7px;color:#1a1408;margin-top:2px}',
    '.rdk-h{margin:26px 2px 10px;font-size:15px;font-weight:800}',
    '@media(max-width:600px){.rdk-nm{width:92px}}',
    /* growth chart + its buttons: same look as the rest, still the original markup */
    '#panel-rides .chart-card{border-radius:20px;border:1.5px solid #ece5d2;background:#fff;box-shadow:0 6px 22px rgba(60,45,10,.09);padding:16px}',
    'body.dark-theme #panel-rides .chart-card{border-color:#34324a;background:#1b1b2a;box-shadow:0 8px 26px rgba(0,0,0,.45)}',
    '#panel-rides .rides-filter-btn{border-radius:12px;border:1.5px solid #ece5d2;background:#fff;color:#6b6b82;font-weight:700}',
    '#panel-rides .rides-filter-btn:hover{border-color:#D4AF37}',
    '#panel-rides .rides-filter-btn.active{background:linear-gradient(135deg,#D4AF37,#8B7230);color:#1c1500;border-color:transparent}',
    'body.dark-theme #panel-rides .rides-filter-btn{border-color:#34324a;background:#232337;color:#a9a38f}',
    'body.dark-theme #panel-rides .rides-filter-btn.active{background:linear-gradient(135deg,#D4AF37,#8B7230);color:#1c1500}'
  ].join('\n');
  document.head.appendChild(s);
}

function pic(u) { u = String(u || ''); return (/^(https?:)?\/\//i.test(u) || u.charAt(0) === '/') ? u : ''; }
function link(u, text) { var h = pic(u); return h ? '<a href="' + esc(h) + '" target="_blank" rel="noopener">' + esc(text) + '</a>' : ''; }
function vis(id) { var p = document.getElementById('panel-rides'); return p && p.style.display !== 'none' && !document.hidden; }

/* ======================= operations pulse ======================= */
function pulseRoot() { return document.getElementById('rides-ops-pro'); }
function shell() {
  var el = pulseRoot(); if (!el || el.firstChild) return;
  el.innerHTML = '<div class="ak"><div class="rdk-pulse"><div class="rdk-card" id="rdk-health" aria-live="polite"><h3>🩺 System health</h3><div class="rdk-tx">Checking…</div></div><div class="rdk-card" id="rdk-watch" aria-live="polite"><h3>🛰️ Live trip watch</h3><div class="rdk-tx">Checking…</div></div></div></div>';
}
function loadHealth() {
  shell();
  return K.api('admin_system_health', {}).then(function (res) {
    var b = document.getElementById('rdk-health'); if (!b) return;
    if (!K.okRes(res)) { b.innerHTML = '<h3>🩺 System health</h3><div class="rdk-tx">' + esc(K.failText(res, 'Could not load')) + '</div>'; return; }
    var d = K.data(res), tone = d.overall === 'bad' ? 'red' : d.overall === 'warn' ? 'amber' : 'green';
    var sum = d.overall === 'ok' ? 'All good' : (d.problems ? d.problems + ' problem' + (d.problems === 1 ? '' : 's') : '') + (d.problems && d.warnings ? ' · ' : '') + (d.warnings ? d.warnings + ' to check' : '');
    b.innerHTML = '<h3>🩺 System health<span class="sp"></span>' + K.pill(sum, tone) + '<button type="button" class="ak-btn" data-pulse="health" aria-label="Refresh health" style="padding:5px 9px">🔄</button></h3>' +
      (d.checks || []).map(function (c) {
        return '<div class="rdk-line"><span class="rdk-dot" style="background:' + (DOT[c.status] || DOT.ok) + '"></span><div class="rdk-nm">' + esc(c.name) + '</div><div class="rdk-tx">' + esc(c.detail) +
          (c.download ? ' <button type="button" class="ak-btn" data-backup style="padding:4px 9px">Download</button>' : '') +
          (c.require_toggle ? ' <button type="button" class="ak-btn" data-req2fa="' + (c.require_toggle === 'on' ? '0' : '1') + '" style="padding:4px 9px">' + (c.require_toggle === 'on' ? 'Turn off' : 'Require for admins') + '</button>' : '') + '</div></div>';
      }).join('');
  });
}
function loadWatch() {
  shell();
  return K.api('admin_ride_watch', {}).then(function (res) {
    var b = document.getElementById('rdk-watch'); if (!b) return;
    if (!K.okRes(res)) { b.innerHTML = '<h3>🛰️ Live trip watch</h3><div class="rdk-tx">' + esc(K.failText(res, 'Could not load')) + '</div>'; return; }
    var d = K.data(res), f = d.findings || [], crit = f.filter(function (x) { return x.severity === 'critical'; }).length;
    var note = (d.active || 0) + ' active trip' + ((d.active || 0) === 1 ? '' : 's') + (d.api ? ' · API ' + d.api.avg_ms + ' ms, ' + d.api.error_pct + '% errors' : '');
    var head = '<h3>🛰️ Live trip watch<span class="sp"></span>' + K.pill(f.length ? f.length + ' need attention' : 'All clear', crit ? 'red' : f.length ? 'amber' : 'green') + '<button type="button" class="ak-btn" data-pulse="watch" aria-label="Refresh trip watch" style="padding:5px 9px">🔄</button></h3><div class="ak-fh" style="margin:2px 0 6px">' + esc(note) + '</div>';
    b.innerHTML = head + (f.length ? f.map(function (x) {
      var who = [x.passenger ? 'Rider ' + x.passenger + (x.passenger_phone ? ' ' + x.passenger_phone : '') : '', x.driver ? 'Driver ' + x.driver + (x.driver_phone ? ' ' + x.driver_phone : '') : ''].filter(Boolean).join(' · ');
      var canCancel = x.ride_id > 0 && x.rule !== 'issue' && x.rule !== 'safety' && x.status !== 'arrived';
      return '<div class="rdk-line"><span class="rdk-sev" style="background:' + (x.severity === 'critical' ? DOT.bad : '#F0B84A') + '">' + esc(x.severity) + '</span><div class="rdk-tx" style="color:var(--ink)"><b>' + (x.ride_id > 0 ? '#' + esc(x.ride_id) + ' · ' : '') + esc(x.title) + '</b> <span style="color:var(--mute)">· ' + esc(K.minutes(x.minutes)) + '</span><div style="color:var(--mute);font-size:12px">' + esc(x.detail) + '</div>' + (who ? '<div style="color:var(--mute);font-size:12px">' + esc(who) + '</div>' : '') + '</div>' +
        (canCancel ? '<button type="button" class="ak-btn danger" data-cancel-ride="' + esc(x.ride_id) + '" style="padding:5px 9px">Cancel ride</button>' : '') + '</div>';
    }).join('') : '<div class="rdk-line" style="color:var(--green);font-weight:700">✓ No stuck trips right now.</div>');
  });
}
function pulseClick(e) {
  var t = e.target, b;
  if (t.closest('[data-pulse="health"]')) { loadHealth(); return; }
  if (t.closest('[data-pulse="watch"]')) { loadWatch(); return; }
  if (t.closest('[data-backup]')) { if (typeof downloadLatestBackup === 'function') downloadLatestBackup(t.closest('[data-backup]')); return; }
  if ((b = t.closest('[data-req2fa]'))) {
    var on = b.getAttribute('data-req2fa') === '1';
    var go = function () { b.disabled = true; K.api('admin_set_2fa_required', { required: on }).then(function (res) { if (K.okRes(res)) { K.toast(on ? 'Two-step sign-in is now required' : 'Two-step sign-in is no longer required'); loadHealth(); } else { b.disabled = false; K.toast(K.failText(res), true); } }); };
    if (on) K.confirm({ title: 'Require two-step sign-in for every admin?', text: 'Admins without it will be asked to set it up before they can open admin pages. Make sure everyone has saved their backup codes.', label: 'Require it', danger: true }).then(function (r) { if (r) go(); }); else go();
    return;
  }
  if ((b = t.closest('[data-cancel-ride]'))) {
    var id = parseInt(b.getAttribute('data-cancel-ride'), 10);
    K.confirm({ title: 'Cancel ride #' + id + '?', text: 'The rider and the driver are told it is cancelled.', label: 'Cancel ride', danger: true }).then(function (r) {
      if (r) K.api('admin_cancel_ride', { ride_id: id }).then(function (res) { if (K.okRes(res)) { K.toast('Ride cancelled'); loadWatch(); } else K.toast(K.failText(res, 'Cannot cancel this ride'), true); });
    });
  }
}

/* ======================= review desk ======================= */
function deskRoot() { return document.getElementById('rides-desk-pro'); }
var TABS = [['issues', '🚩', 'Rider reports'], ['vehicle', '🚗', 'Vehicle changes'], ['identity', '🪪', 'Flagged identities'], ['docs', '📄', 'Documents to review'], ['comp', '🗂️', 'Driver documents']];
function today() { return new Date().toISOString().slice(0, 10); }
function docState(exp) {
  if (!exp) return ['Not set', 'grey'];
  var t = today(), soon = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10);
  return exp < t ? ['Expired ' + exp, 'red'] : exp <= soon ? ['Expires ' + exp, 'amber'] : ['Valid to ' + exp, 'green'];
}
function compFlag(r) {
  var worst = 'ok';
  DOCS.forEach(function (d) { var s = docState(r[d[0] + '_expiry_date'])[1]; if (s === 'red') worst = 'expired'; else if ((s === 'grey' || s === 'amber') && worst !== 'expired') worst = s === 'amber' ? 'soon' : (worst === 'soon' ? 'soon' : 'unset'); });
  return worst;
}
function counts() {
  var r = D.rows, open = r.issues.filter(function (x) { return (x.status || 'open') === 'open'; }).length, exp = r.comp.filter(function (x) { return compFlag(x) === 'expired'; }).length;
  return { issues: open, vehicle: r.vehicle.length, identity: r.identity.length, docs: r.docs.length, comp: exp };
}
function loadDesk() {
  var el = deskRoot(); if (!el) return Promise.resolve();
  css();
  if (!el.firstChild) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  var ask = function (action, key, field) {
    return K.api(action, {}).then(function (res) { D.loaded[key] = K.okRes(res); D.rows[key] = K.okRes(res) ? (K.data(res)[field] || []) : []; if (!K.okRes(res)) D['err_' + key] = K.failText(res); });
  };
  var caps = window.ETEL_CAPS_PROMISE || Promise.resolve(window.ETEL_CAPS || []);
  return caps.then(function (c) {
    c = c || []; D.canManage = c.indexOf('*') >= 0 || c.indexOf('users.manage') >= 0;
    var jobs = [ask('admin_get_ride_issues', 'issues', 'issues'), ask('admin_get_pending_vehicle_changes', 'vehicle', 'requests')];
    if (D.canManage) jobs.push(ask('admin_get_flagged_identities', 'identity', 'drivers'), ask('admin_get_pending_compliance_requests', 'docs', 'requests'), ask('admin_list_driver_compliance', 'comp', 'drivers'));
    return Promise.all(jobs);
  }).then(paintDesk);
}
function tabsFor() { return TABS.filter(function (t) { return D.canManage || t[0] === 'issues' || t[0] === 'vehicle'; }); }

function paintDesk() {
  var el = deskRoot(); if (!el) return;
  var c = counts(), tabs = tabsFor(), total = c.issues + c.vehicle + c.identity + c.docs;
  if (!tabs.some(function (t) { return t[0] === D.tab; })) D.tab = 'issues';
  var kp = tabs.map(function (t) { return { key: t[0], icon: t[1], label: t[2], value: K.num(c[t[0]]), warn: true }; });
  var h = '<div class="ak">' + K.hero('Review desk', 'Everything on ETEL Ride waiting for a decision: rider reports, vehicle changes, driver identity and documents.', [['Waiting for you', K.num(total)], ['Expired documents', K.num(c.comp)]]) + K.kpis(kp, D.tab) + '<div id="rdd-body"></div></div>';
  el.innerHTML = h; paintTab();
}
function empty(icon, msg) { return K.empty(icon, msg, ''); }
function who(r, nm, ph) { return '<div class="ak-nm"><b>' + esc(r[nm] || '—') + '</b><small>' + esc(r[ph] || '') + '</small></div>'; }
function paintTab() {
  var b = document.getElementById('rdd-body'); if (!b) return;
  var t = D.tab, rows = D.rows[t], err = D['err_' + t];
  if (err) { b.innerHTML = K.empty('⚠️', 'Could not load', err); return; }
  var out = '';
  if (t === 'issues') {
    out = K.seg('ifilter', [['open', 'Open'], ['resolved', 'Resolved'], ['all', 'All']], D.ifilter || 'open');
    var f = D.ifilter || 'open', list = rows.filter(function (r) { return f === 'all' || (r.status || 'open') === f; });
    out += list.length ? '<div class="ak-grid" style="margin-top:10px">' + list.map(function (r) {
      var open = (r.status || 'open') === 'open';
      return '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">🚩</div>' + who(r, 'reporter_name', 'reporter_phone') + K.pill(open ? 'Open' : 'Resolved', open ? 'amber' : 'green') + '</div>' +
        '<div class="ak-chips"><span class="ak-chip">' + (r.ride_id ? 'Ride #' + esc(r.ride_id) : 'No ride') + '</span><span class="ak-chip">' + esc(r.category || '—') + '</span></div><div style="margin-top:10px;font-size:13px">' + esc(r.details || '—') + '</div>' +
        '<div class="ak-row"><span>' + esc(K.ago(r.created_at)) + '</span>' + (open ? '<button type="button" class="ak-btn good" data-resolve="' + esc(r.id) + '">Mark resolved</button>' : '<span>' + esc(r.resolved_at ? K.ago(r.resolved_at) : '') + '</span>') + '</div></div>';
    }).join('') + '</div>' : empty('✅', 'No ' + (f === 'all' ? '' : f + ' ') + 'rider reports.');
  } else if (t === 'vehicle') {
    out = rows.length ? '<div class="ak-grid">' + rows.map(function (r) {
      var cur = [r.current_vehicle_type, r.current_vehicle_make, r.current_vehicle_plate].filter(Boolean).join(' · ') || '—', req = [r.requested_vehicle_type, r.requested_vehicle_make, r.requested_vehicle_color, r.requested_vehicle_year, r.requested_vehicle_plate].filter(Boolean).join(' · ') || '—';
      return '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#5aa9ff;--b:#2f6fd0">🚗</div>' + who(r, 'driver_name', 'driver_phone') + '</div><div style="margin-top:10px;font-size:12px;color:var(--mute)">Now</div><div style="font-size:13px">' + esc(cur) + '</div><div style="margin-top:8px;font-size:12px;color:var(--mute)">Wants to change to</div><div style="font-size:13px;font-weight:700">' + esc(req) + '</div>' +
        '<div class="ak-row"><span>' + esc(K.ago(r.created_at)) + '</span></div><div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn good" data-vok="' + esc(r.id) + '">✓ Approve</button><button type="button" class="ak-btn danger" data-vno="' + esc(r.id) + '">Reject…</button></div></div>';
    }).join('') + '</div>' : empty('✅', 'No pending vehicle changes.');
    out = '<div class="ak-fh" style="margin:0 0 10px">A driver\'s vehicle only changes on the map and in ride previews once approved here.</div>' + out;
  } else if (t === 'identity') {
    out = rows.length ? '<div class="ak-grid">' + rows.map(function (r) {
      return '<div class="ak-card alert" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#d6364a;--b:#8f1f2e">🪪</div>' + who(r, 'name', 'phone') + '</div><div style="margin-top:10px;font-size:12px;color:var(--mute)">License on file</div><div style="font-size:13px"><b>' + esc(r.license_name || '—') + '</b><br>' + esc([r.license_number, r.license_dob].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="ak-chips"><span class="ak-chip">' + esc([r.vehicle_type, r.vehicle_plate].filter(Boolean).join(' · ') || 'No vehicle') + '</span>' + (link(r.selfie_url, '📷 Selfie') ? '<span class="ak-chip">' + link(r.selfie_url, '📷 Selfie') + '</span>' : '') + (link(r.license_photo_url, '🪪 License') ? '<span class="ak-chip">' + link(r.license_photo_url, '🪪 License') + '</span>' : '') + '</div>' +
        '<div class="ak-row"><span>Registered ' + esc(K.ago(r.created_at)) + '</span></div><div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn good" data-iok="' + esc(r.id) + '">✓ Approve</button><button type="button" class="ak-btn danger" data-ino="' + esc(r.id) + '">Reject &amp; suspend…</button></div></div>';
    }).join('') + '</div>' : empty('✅', 'No accounts are flagged.');
    out = '<div class="ak-fh" style="margin:0 0 10px">Registration compares the license name and date of birth with the EtelID record. A mismatch blocks the driver from going online until you decide.</div>' + out;
  } else if (t === 'docs') {
    out = rows.length ? '<div class="ak-grid">' + rows.map(function (r) {
      return '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#E8C14A;--b:#9a7a1c">📄</div>' + who(r, 'name', 'phone') + '</div><div class="ak-chips"><span class="ak-chip"><b>' + esc(DOC_LABEL[r.doc_type] || r.doc_type) + '</b></span><span class="ak-chip">Expires ' + esc(r.requested_expiry_date || '—') + '</span>' + (link(r.requested_receipt_url, '📷 Photo') ? '<span class="ak-chip">' + link(r.requested_receipt_url, '📷 Photo') + '</span>' : '') + '</div>' +
        '<div class="ak-row"><span>Sent ' + esc(K.ago(r.created_at)) + '</span></div><div class="ak-acts" style="margin-top:8px"><button type="button" class="ak-btn good" data-dok="' + esc(r.id) + '">✓ Approve</button><button type="button" class="ak-btn danger" data-dno="' + esc(r.id) + '">Reject…</button></div></div>';
    }).join('') + '</div>' : empty('✅', 'No documents are waiting.');
    out = '<div class="ak-fh" style="margin:0 0 10px">Drivers send their own document dates and photos. Nothing shows to a Traffic Officer until you approve it.</div>' + out;
  } else {
    var fl = D.cflag || '', list2 = rows.filter(function (r) { return !fl || compFlag(r) === fl; }), q = (D.cq || '').toLowerCase();
    list2 = list2.filter(function (r) { return !q || (String(r.name || '') + ' ' + String(r.phone || '') + ' ' + String(r.vehicle_plate || '')).toLowerCase().indexOf(q) >= 0; });
    out = K.seg('cflag', [['', 'All'], ['expired', 'Has expired'], ['soon', 'Expiring soon'], ['unset', 'Not set']], fl) + '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="rdd-cq" type="search" placeholder="Driver, phone or plate…" value="' + esc(D.cq || '') + '" autocomplete="off"></label></div>' +
      '<div class="ak-fh" style="margin:0 0 10px">Feeds the Traffic Officer NFC check. Association dues are not set here — they come live from EtelTaxi when a card is checked.</div>';
    out += list2.length ? '<div class="ak-grid">' + list2.map(function (r) {
      return '<div class="ak-card" style="cursor:default"><div class="ak-top"><div class="ak-av" style="--a:#3ad08a;--b:#1fa463">🗂️</div>' + who(r, 'name', 'phone') + '</div><div class="ak-chips"><span class="ak-chip">' + esc([r.vehicle_type, r.vehicle_plate].filter(Boolean).join(' · ') || 'No vehicle') + '</span></div>' +
        DOCS.map(function (d) { var s = docState(r[d[0] + '_expiry_date']), ph = link(r[d[0] + '_receipt_url'], 'photo'); return '<div class="ak-row"><span><b style="color:var(--ink)">' + esc(d[1]) + '</b> ' + (ph || '') + '</span><span>' + K.pill(s[0], s[1]) + ' <button type="button" class="ak-btn" data-cset="' + esc(r.user_id) + '|' + d[0] + '" style="padding:3px 9px">Set</button></span></div>'; }).join('') + '</div>';
    }).join('') + '</div>' : empty('🗂️', rows.length ? 'No drivers match.' : 'No onboarded drivers yet.');
  }
  b.innerHTML = out;
  var q2 = document.getElementById('rdd-cq'); if (q2 && D.focusQ) { q2.focus(); q2.setSelectionRange(q2.value.length, q2.value.length); }
}

function rowId(arr, id) { for (var i = 0; i < arr.length; i++) if (String(arr[i].id) === String(id)) return i; return -1; }
function decide(action, body, key, id, msg) {
  return K.api(action, body).then(function (res) {
    if (K.okRes(res)) { var i = rowId(D.rows[key], id); if (i >= 0) { if (key === 'issues') { D.rows[key][i].status = 'resolved'; D.rows[key][i].resolved_at = new Date().toISOString(); } else D.rows[key].splice(i, 1); } K.toast(msg); paintDesk(); if (key === 'docs') loadDeskComp(); }
    else K.toast(K.failText(res), true);
  });
}
function loadDeskComp() { K.api('admin_list_driver_compliance', {}).then(function (res) { if (K.okRes(res)) { D.rows.comp = K.data(res).drivers || []; paintDesk(); } }); }
function reject(title, text, act, bodyFn, key, id, label) {
  K.confirm({ title: title, text: text, label: label || 'Reject', danger: true, reason: 'Reason (shown to the driver, optional)' }).then(function (r) { if (r) decide(act, bodyFn(r.reason), key, id, 'Rejected'); });
}

/* guided form: set a driver's document date (+ photo) */
function openSetDoc(userId, key) {
  var r = null; D.rows.comp.forEach(function (x) { if (String(x.user_id) === String(userId)) r = x; }); if (!r) return;
  var head = '<div class="ak-av lg" style="--a:#3ad08a;--b:#1fa463">🗂️</div><div class="ak-nm"><b>' + esc(DOC_LABEL[key]) + '</b><small>' + esc(r.name || '') + ' · ' + esc(r.vehicle_plate || '') + '</small></div>';
  var body = '<div class="ak-sec">' + K.field('Expiry date', '<input id="sd-date" type="date" class="ak-in" value="' + esc(r[key + '_expiry_date'] || '') + '">', 'As printed on the certificate or sticker. Leave empty to clear it.') + '</div>' +
    '<div class="ak-sec">' + K.field('Photo of the document (optional)', '<input id="sd-file" type="file" accept="image/*" class="ak-in">', pic(r[key + '_receipt_url']) ? 'A photo is already on file; choosing a new one replaces it.' : 'Take or choose a clear photo.') + '</div>';
  var dr = K.openDrawer(head, body, '<span class="ak-err" id="sd-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-sdsave>Save</button>');
  dr.addEventListener('click', function (e) {
    if (!(e.target.closest && e.target.closest('[data-sdsave]'))) return;
    var err = document.getElementById('sd-err'), date = document.getElementById('sd-date').value.trim(), file = document.getElementById('sd-file').files[0];
    if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) { err.textContent = 'Pick a valid date.'; return; }
    var btn = dr.querySelector('[data-sdsave]'); btn.disabled = true; err.textContent = file ? 'Uploading photo…' : '';
    var save = function (url) {
      var b = { user_id: parseInt(userId, 10) }; b[key + '_expiry_date'] = date; if (url) b[key + '_receipt_url'] = url;
      K.api('admin_update_driver_compliance', b).then(function (res) { btn.disabled = false; if (K.okRes(res)) { K.closeDrawer(); K.toast('Saved'); loadDeskComp(); } else err.textContent = K.failText(res, 'Could not save.'); });
    };
    if (!file) return save('');
    var fd = new FormData(); fd.append('image', file); fd.append('type', 'driver_' + key + '_photo');
    var up = (window.ETEL && window.ETEL.api ? window.ETEL.api : '/delivery/api/boot.php').replace('boot.php', 'upload.php');
    fetch(up, { method: 'POST', headers: { 'Authorization': 'Bearer ' + (typeof Auth !== 'undefined' ? Auth.getToken() : '') }, body: fd })
      .then(function (r2) { return r2.json(); }).then(function (j) { if (!j || !j.success) throw new Error(j && j.message ? j.message : 'Upload failed'); save(j.data.url); })
      .catch(function (x) { btn.disabled = false; err.textContent = 'Photo upload failed: ' + (x.message || 'unknown error'); });
  });
}

function deskClick(e) {
  var t = e.target, b;
  if ((b = t.closest('[data-kpi]'))) { D.tab = b.getAttribute('data-kpi'); paintDesk(); return; }
  if ((b = t.closest('[data-seg]'))) { var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val'); if (n === 'ifilter') D.ifilter = v; else D.cflag = v; paintTab(); return; }
  if ((b = t.closest('[data-resolve]'))) { var id = b.getAttribute('data-resolve'); K.confirm({ title: 'Mark this report as resolved?', text: 'It moves to the Resolved list.', label: 'Mark resolved' }).then(function (r) { if (r) decide('admin_resolve_ride_issue', { id: parseInt(id, 10) }, 'issues', id, 'Report resolved'); }); return; }
  if ((b = t.closest('[data-vok]'))) { var v1 = b.getAttribute('data-vok'); K.confirm({ title: 'Approve this vehicle change?', text: 'It updates the map, ride previews and the driver\'s profile everywhere, straight away.', label: 'Approve' }).then(function (r) { if (r) decide('admin_approve_vehicle_change', { request_id: parseInt(v1, 10) }, 'vehicle', v1, 'Vehicle change approved'); }); return; }
  if ((b = t.closest('[data-vno]'))) { var v2 = b.getAttribute('data-vno'); reject('Reject this vehicle change?', 'The driver keeps their current vehicle.', 'admin_reject_vehicle_change', function (why) { return { request_id: parseInt(v2, 10), reason: why }; }, 'vehicle', v2); return; }
  if ((b = t.closest('[data-iok]'))) { var i1 = b.getAttribute('data-iok'); K.confirm({ title: 'Approve this account?', text: 'The license name and date of birth are treated as matching and the driver can go online straight away.', label: 'Approve' }).then(function (r) { if (r) decide('admin_approve_driver_identity', { user_id: parseInt(i1, 10) }, 'identity', i1, 'Account approved'); }); return; }
  if ((b = t.closest('[data-ino]'))) { var i2 = b.getAttribute('data-ino'); reject('Reject and suspend this account?', 'The account is suspended immediately.', 'admin_reject_driver_identity', function (why) { return { user_id: parseInt(i2, 10), reason: why || 'License identity did not match EtelID record.' }; }, 'identity', i2, 'Reject & suspend'); return; }
  if ((b = t.closest('[data-dok]'))) { var d1 = b.getAttribute('data-dok'); K.confirm({ title: 'Approve this document?', text: 'It shows on the Traffic Officer NFC check straight away.', label: 'Approve' }).then(function (r) { if (r) decide('admin_approve_compliance_document', { request_id: parseInt(d1, 10) }, 'docs', d1, 'Document approved'); }); return; }
  if ((b = t.closest('[data-dno]'))) { var d2 = b.getAttribute('data-dno'); reject('Reject this document?', 'The driver is asked to send it again.', 'admin_reject_compliance_document', function (why) { return { request_id: parseInt(d2, 10), reason: why || 'Photo unclear - please resubmit.' }; }, 'docs', d2); return; }
  if ((b = t.closest('[data-cset]'))) { var p = b.getAttribute('data-cset').split('|'); openSetDoc(p[0], p[1]); }
}
var csearch = K.debounce(function () { D.focusQ = true; paintTab(); D.focusQ = false; }, 200);

function loadPulse() { loadHealth(); loadWatch(); }
function install() {
  var po = pulseRoot(), dk = deskRoot(); if (!po && !dk) return;
  css(); K.css();
  if (po && !po._rdkWired) { po._rdkWired = true; po.addEventListener('click', pulseClick); }
  if (dk && !dk._rdkWired) { dk._rdkWired = true; dk.addEventListener('click', deskClick); dk.addEventListener('input', function (e) { if (e.target.id === 'rdd-cq') { D.cq = e.target.value.trim(); csearch(); } }); }
  var orig = window.ridesLoad;
  window.ridesLoad = function () { var r = typeof orig === 'function' ? orig.apply(this, arguments) : undefined; loadPulse(); loadDesk(); return r; };
  if (!D.healthTimer) D.healthTimer = setInterval(function () { if (vis()) loadHealth(); }, 60000);
  if (!D.watchTimer) D.watchTimer = setInterval(function () { if (vis()) loadWatch(); }, 30000);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
