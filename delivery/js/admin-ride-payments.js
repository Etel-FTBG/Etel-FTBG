/* ETEL Admin - Ride Payments console (premium).
   One place for the money side of the Ride system: fare revenue over a chosen window (7 / 30 / 90 days or a year) with a daily
   chart and a payment-method split, the top-ups drivers are waiting on (receipt photo beside Approve / Reject, rejection needs
   a reason), every driver's earnings / paid out / commission owed / Etel Money balance, and the payout history. Everything can
   be exported as CSV. Data: admin_ride_payments, admin_approve_etel_topup, admin_reject_etel_topup (AdminController.php).
   Built on AdminKit (gold on carbon, light + dark). Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var V = { days: 30, tab: 'overview', q: '', sort: 'earnings', data: null, busy: false };
var METHOD = { cash: ['Cash', '💵'], etelpay: ['Etel Pay wallet', '👛'], wallet: ['Etel Pay wallet', '👛'], etel_money: ['Etel Money', '🪙'] };
var WINDOWS = [['7', '7 days'], ['30', '30 days'], ['90', '90 days'], ['365', '1 year']];
var TABS = [['overview', 'Overview'], ['topups', 'Top-ups'], ['drivers', 'Drivers'], ['payouts', 'Payouts']];

function root() { return document.getElementById('ridepay-body'); }
function f(n) { return parseFloat(n) || 0; }
function etb(n) { return K.money(f(n)); }
function methodOf(m) { return METHOD[m] || [String(m || 'Other'), '💳']; }

function css() {
  K.css();
  if (document.getElementById('rp2-css')) return;
  var s = document.createElement('style'); s.id = 'rp2-css';
  s.textContent = [
    '.rp2-tabs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 12px}',
    '.rp2-tabs .ak-seg button b{display:inline-block;min-width:18px;margin-left:6px;padding:0 6px;border-radius:9px;background:var(--red);color:var(--on-red);font-size:10.5px;line-height:18px}',
    '.rp2-cols{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:12px}',
    '@media(max-width:900px){.rp2-cols{grid-template-columns:1fr}}',
    '.rp2-box{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:16px;box-shadow:var(--shadow)}',
    '.rp2-box h3{margin:0 0 2px;font-size:14.5px;color:var(--ink)}.rp2-box .sub{font-size:12px;color:var(--mute);margin-bottom:12px}',
    '.rp2-bars{display:flex;align-items:flex-end;gap:3px;height:150px;padding-top:6px;border-bottom:1px solid var(--line)}',
    '.rp2-bar{flex:1 1 0;min-width:2px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,var(--gold),var(--gold2));opacity:.92;position:relative;cursor:default;min-height:2px}',
    '.rp2-bar:hover,.rp2-bar:focus-visible{opacity:1;outline:2px solid var(--focus);outline-offset:1px}',
    '.rp2-bar.zero{background:var(--line);opacity:1}',
    '.rp2-axis{display:flex;justify-content:space-between;font-size:11px;color:var(--mute);margin-top:6px}',
    '.rp2-tip{min-height:18px;font-size:12.5px;color:var(--ink);margin-top:8px;font-weight:600}',
    '.rp2-m{display:grid;grid-template-columns:auto 1fr auto;gap:4px 10px;align-items:center;margin-top:12px}',
    '.rp2-m i{font-style:normal;font-size:20px}.rp2-m .n{font-size:13px;font-weight:700;color:var(--ink)}.rp2-m .n small{display:block;font-weight:500;color:var(--mute);font-size:11.5px}',
    '.rp2-m .v{font-size:13px;font-weight:800;text-align:right}',
    '.rp2-track{grid-column:2 / 4;height:7px;border-radius:7px;background:var(--line);overflow:hidden}',
    '.rp2-track div{height:100%;border-radius:7px;background:linear-gradient(90deg,var(--gold),var(--gold2))}',
    '.rp2-tu{display:grid;grid-template-columns:150px 1fr;gap:14px;background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;box-shadow:var(--shadow)}',
    '@media(max-width:560px){.rp2-tu{grid-template-columns:1fr}}',
    '.rp2-rc{display:flex;align-items:center;justify-content:center;min-height:130px;border-radius:14px;background:var(--soft);border:1px dashed var(--line);overflow:hidden;color:var(--mute);font-size:12px;text-align:center;padding:8px}',
    '.rp2-rc img{max-width:100%;max-height:190px;border-radius:10px;display:block}',
    '.rp2-amt{font-size:22px;font-weight:800;color:var(--ink)}',
    '.rp2-list{display:grid;gap:12px}',
    '.rp2-note{font-size:12px;color:var(--mute);margin-top:6px;word-break:break-word}',
    '.rp2-num{text-align:right}',
    '.ak-tbl th.rp2-num{text-align:right}',
    '.rp2-warn{color:var(--red);font-weight:700}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- loading ---------- */
function load(quiet) {
  var el = root(); if (!el || V.busy) return Promise.resolve();
  css(); V.busy = true;
  if (!quiet && !V.data) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  return K.api('admin_ride_payments', { days: V.days }).then(function (res) {
    V.busy = false;
    if (!K.okRes(res)) {
      if (!V.data) el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load Ride Payments', K.failText(res)) + '<div style="text-align:center;margin-top:12px"><button type="button" class="ak-btn gold" data-act="refresh">Try again</button></div></div>';
      else K.toast(K.failText(res), true);
      return;
    }
    V.data = K.data(res) || {};
    paint();
  });
}

/* ---------- pieces ---------- */
function trendChart(d) {
  var byDay = {}; (d.trend || []).forEach(function (r) { byDay[String(r.d).slice(0, 10)] = r; });
  var days = [], i, t = new Date(); t.setHours(12, 0, 0, 0);
  var n = Math.min(V.days, 90);               /* one bar per day up to 90; a year shows the last 90 days of it */
  for (i = n - 1; i >= 0; i--) { var x = new Date(t.getTime() - i * 86400000); days.push(x.toISOString().slice(0, 10)); }
  if (V.days === 365) {                        /* bucket the year by week so it stays readable */
    var wk = [], cur = null;
    (d.trend || []).forEach(function (r) {
      var dt = new Date(String(r.d).slice(0, 10) + 'T12:00:00'), key = Math.floor(dt.getTime() / (7 * 86400000));
      if (!cur || cur.key !== key) { cur = { key: key, d: String(r.d).slice(0, 10), amount: 0, trips: 0 }; wk.push(cur); }
      cur.amount += f(r.amount); cur.trips += parseInt(r.trips, 10) || 0;
    });
    return bars(wk.map(function (w) { return { label: 'Week of ' + w.d, amount: w.amount, trips: w.trips }; }));
  }
  return bars(days.map(function (k) { var r = byDay[k]; return { label: k, amount: r ? f(r.amount) : 0, trips: r ? parseInt(r.trips, 10) || 0 : 0 }; }));
}
function bars(list) {
  if (!list.length) return K.empty('📈', 'No trips in this window', 'Completed trips will draw the chart.');
  var max = 0; list.forEach(function (x) { if (x.amount > max) max = x.amount; });
  var h = '<div class="rp2-bars" role="list" aria-label="Fare revenue per ' + (V.days === 365 ? 'week' : 'day') + '">' + list.map(function (x) {
    var pct = max ? Math.max(2, Math.round(x.amount / max * 100)) : 2;
    var txt = x.label + ': ' + etb(x.amount) + ' from ' + x.trips + (x.trips === 1 ? ' trip' : ' trips');
    return '<div class="rp2-bar' + (x.amount ? '' : ' zero') + '" role="listitem" tabindex="0" style="height:' + pct + '%" data-tip="' + esc(txt) + '" aria-label="' + esc(txt) + '"></div>';
  }).join('') + '</div><div class="rp2-axis"><span>' + esc(list[0].label.replace('Week of ', '')) + '</span><span>' + esc(list[list.length - 1].label.replace('Week of ', '')) + '</span></div>' +
    '<div class="rp2-tip" id="rp2-tip" aria-live="polite">Hover or focus a bar to see its day.</div>';
  return h;
}
function methodSplit(d) {
  var rows = d.by_method || [], total = 0; rows.forEach(function (r) { total += f(r.amount); });
  if (!rows.length) return K.empty('💳', 'No payments yet', 'The split by payment method appears with the first completed trip.');
  return rows.map(function (r) {
    var m = methodOf(r.method), pct = total ? Math.round(f(r.amount) / total * 100) : 0;
    return '<div class="rp2-m"><i aria-hidden="true">' + m[1] + '</i><div class="n">' + esc(m[0]) + '<small>' + K.num(r.trips) + ' trips · ' + pct + '%</small></div><div class="v">' + esc(etb(r.amount)) + '</div>' +
      '<div class="rp2-track" role="img" aria-label="' + esc(m[0] + ' ' + pct + ' percent') + '"><div style="width:' + pct + '%"></div></div></div>';
  }).join('');
}
function safeUrl(u) { u = String(u || ''); return /^(https?:)?\/\//i.test(u) || /^\/?[\w.\-\/]+\.(jpe?g|png|webp|gif)$/i.test(u) ? u : ''; }

function overview(d) {
  var hist = d.topup_history || [];
  return '<div class="rp2-cols"><div class="rp2-box"><h3>Fare revenue</h3><div class="sub">Completed trips, ' + (V.days === 365 ? 'by week over the last year' : 'by day over the last ' + V.days + ' days') + '</div>' + trendChart(d) + '</div>' +
    '<div class="rp2-box"><h3>How riders paid</h3><div class="sub">Share of fare revenue in this window</div>' + methodSplit(d) + '</div></div>' +
    (hist.length ? '<div class="rp2-box" style="margin-top:12px"><h3>Recent top-up decisions</h3><div class="sub">Last ' + hist.length + ' approved or rejected</div><div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Driver</th><th class="rp2-num">Amount</th><th>Source</th><th>Decision</th><th>When</th></tr></thead><tbody>' +
      hist.map(function (r) { return '<tr><td>' + esc(r.driver_name || '') + '</td><td class="rp2-num">' + esc(etb(r.amount)) + '</td><td>' + esc(r.source === 'manual_receipt' ? 'Receipt photo' : 'Etel Pay') + '</td><td>' + K.pill(r.status === 'approved' ? 'Approved' : 'Rejected', r.status === 'approved' ? 'green' : 'red') + (r.note ? ' <small>' + esc(r.note) + '</small>' : '') + '</td><td>' + esc(K.ago(r.approved_at || r.created_at)) + '</td></tr>'; }).join('') +
      '</tbody></table></div></div>' : '');
}

function topups(d) {
  var rows = d.topups || [];
  if (!rows.length) return K.empty('✅', 'Nothing waiting', 'Driver Etel Money top-ups appear here for your approval. Nothing is credited until you approve.');
  return '<div class="rp2-list">' + rows.map(function (r) {
    var url = safeUrl(r.receipt_image_url), manual = r.source === 'manual_receipt';
    return '<div class="rp2-tu"><div class="rp2-rc">' + (url ? '<a href="' + esc(url) + '" target="_blank" rel="noopener" aria-label="Open the receipt photo in a new tab"><img src="' + esc(url) + '" alt="Receipt uploaded by ' + esc(r.driver_name || 'the driver') + '" loading="lazy"></a>' : (manual ? 'No photo found' : 'Verified automatically with Etel Pay')) + '</div>' +
      '<div><div style="display:flex;gap:8px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap"><div><b style="font-size:15px">' + esc(r.driver_name || 'Driver') + '</b><div style="font-size:12px;color:var(--mute)">' + esc(r.driver_phone || '') + '</div></div>' + K.pill(manual ? 'Receipt photo' : 'Etel Pay', manual ? 'amber' : 'blue') + '</div>' +
      '<div class="rp2-amt" style="margin-top:8px">' + esc(etb(r.amount)) + '</div>' +
      '<div class="rp2-note">Reference <b>' + esc(r.tx_ref) + '</b> · requested ' + esc(K.ago(r.created_at)) + '</div>' + (r.note ? '<div class="rp2-note">' + esc(r.note) + '</div>' : '') +
      '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn good" data-approve="' + esc(r.id) + '" data-name="' + esc(r.driver_name || '') + '" data-amt="' + esc(f(r.amount)) + '">✔ Approve</button><button type="button" class="ak-btn danger" data-reject="' + esc(r.id) + '" data-name="' + esc(r.driver_name || '') + '">✖ Reject</button></div></div></div>';
  }).join('') + '</div>';
}

function driversView(d) {
  var q = V.q.toLowerCase(), rows = (d.drivers || []).filter(function (r) { return !q || (String(r.name || '') + ' ' + String(r.phone || '')).toLowerCase().indexOf(q) >= 0; });
  var key = { earnings: 'total_earnings', owed: 'commission_owed', balance: 'etel_money_balance', paid: 'total_paid_out' }[V.sort] || 'total_earnings';
  rows.sort(function (a, b) { return f(b[key]) - f(a[key]); });
  var h = '<div class="ak-tools"><label class="ak-search"><u>🔎</u><input id="rp2-q" type="search" aria-label="Search drivers" placeholder="Driver name or phone…" value="' + esc(V.q) + '" autocomplete="off"></label>' +
    '<select class="ak-sel" id="rp2-sort" aria-label="Sort drivers by"><option value="earnings">Most earned</option><option value="owed">Most commission owed</option><option value="balance">Highest Etel Money</option><option value="paid">Most paid out</option></select></div>' +
    '<div class="ak-count">' + K.num(rows.length) + ' of ' + K.num((d.drivers || []).length) + ' drivers</div>';
  if (!rows.length) return h + K.empty('🚖', 'No drivers to show', q ? 'Try a different name.' : 'Drivers appear here once they sign up.');
  h += '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Driver</th><th class="rp2-num">Earned</th><th class="rp2-num">Paid out</th><th class="rp2-num">Commission owed</th><th class="rp2-num">Etel Money</th></tr></thead><tbody>' +
    rows.map(function (r) {
      return '<tr><td><b>' + esc(r.name || 'Driver #' + r.id) + '</b><div style="font-size:11.5px;color:var(--mute)">' + esc(r.phone || '') + '</div></td><td class="rp2-num">' + esc(etb(r.total_earnings)) + '</td><td class="rp2-num">' + esc(etb(r.total_paid_out)) + '</td>' +
        '<td class="rp2-num' + (f(r.commission_owed) > 0 ? ' rp2-warn' : '') + '">' + esc(etb(r.commission_owed)) + '</td><td class="rp2-num">' + esc(etb(r.etel_money_balance)) + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  return h;
}
function payoutsView(d) {
  var rows = d.payouts || [];
  if (!rows.length) return K.empty('💸', 'No payouts yet', 'Driver cash-outs are listed here with their status.');
  var tone = { paid: 'green', completed: 'green', success: 'green', pending: 'amber', processing: 'amber', failed: 'red', rejected: 'red' };
  return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Driver</th><th class="rp2-num">Amount</th><th>Status</th><th>Reference</th><th>Date</th></tr></thead><tbody>' + rows.map(function (r) {
    return '<tr><td><b>' + esc(r.driver_name || '') + '</b><div style="font-size:11.5px;color:var(--mute)">' + esc(r.driver_phone || '') + '</div></td><td class="rp2-num">' + esc(etb(r.amount)) + '</td><td>' + K.pill(String(r.status || '—'), tone[String(r.status).toLowerCase()] || 'grey') + '</td><td>' + esc(r.tx_ref || '') + '</td><td>' + esc(K.fullDate(r.created_at)) + '</td></tr>';
  }).join('') + '</tbody></table></div>';
}

function paint() {
  var el = root(), d = V.data; if (!el || !d) return;
  var t = d.totals || {}, p = d.period || {}, pend = (d.topups || []).length;
  var focus = document.activeElement && document.activeElement.id === 'rp2-q';
  var tabs = TABS.map(function (x) { return [x[0], x[1]]; });
  var segHtml = '<div class="ak-seg" role="group" aria-label="Section">' + tabs.map(function (x) {
    return '<button type="button" data-seg="tab" data-val="' + x[0] + '" aria-pressed="' + (V.tab === x[0]) + '" class="' + (V.tab === x[0] ? 'on' : '') + '">' + esc(x[1]) + (x[0] === 'topups' && pend ? '<b aria-label="' + pend + ' waiting">' + pend + '</b>' : '') + '</button>';
  }).join('') + '</div>';
  var body = V.tab === 'topups' ? topups(d) : V.tab === 'drivers' ? driversView(d) : V.tab === 'payouts' ? payoutsView(d) : overview(d);
  el.innerHTML = '<div class="ak">' +
    K.hero('Ride Payments', 'Fare revenue, driver earnings and payouts, and the Etel Money top-ups waiting for your approval.', [['Today', etb(t.today)], ['Last 7 days', etb(t.week)], ['Last 30 days', etb(t.month)], ['Waiting for approval', K.num(pend)]]) +
    K.kpis([
      { key: 'rev', icon: '💰', label: 'Revenue (' + WINDOWS.filter(function (w) { return +w[0] === V.days; })[0][1] + ')', value: etb(p.amount) },
      { key: 'trips', icon: '🚖', label: 'Trips', value: K.num(p.trips || 0) },
      { key: 'avg', icon: '📊', label: 'Average fare', value: etb(p.avg_fare) },
      { key: 'earn', icon: '🏁', label: 'Driver earnings (all time)', value: etb(t.earnings) },
      { key: 'paid', icon: '💸', label: 'Paid out (all time)', value: etb(t.paid_out) },
      { key: 'owed', icon: '🧾', label: 'Commission owed', value: etb(t.commission_owed), warn: f(t.commission_owed) > 0 },
      { key: 'em', icon: '🪙', label: 'Driver Etel Money', value: etb(t.etel_money) }
    ], '', true) +
    '<div class="rp2-tabs">' + segHtml + '<span style="flex:1"></span>' +
    K.seg('days', WINDOWS, V.days) +
    '<button type="button" class="ak-btn" data-act="export" aria-label="Download as CSV">⬇ CSV</button><button type="button" class="ak-btn" data-act="refresh" aria-label="Refresh">🔄</button></div>' +
    body + '</div>';
  var sel = document.getElementById('rp2-sort'); if (sel) sel.value = V.sort;
  if (focus) { var i = document.getElementById('rp2-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }
}

/* ---------- CSV ---------- */
function csvCell(x) { x = String(x == null ? '' : x); if (/^[=+\-@\t\r]/.test(x)) x = "'" + x; return '"' + x.replace(/"/g, '""') + '"'; }
function exportCsv() {
  var d = V.data; if (!d) return;
  var lines = [['Section', 'Name / Date', 'Phone / Method', 'Trips', 'Amount (ETB)', 'Paid out (ETB)', 'Commission owed (ETB)', 'Etel Money (ETB)', 'Status / Reference']];
  (d.trend || []).forEach(function (r) { lines.push(['Daily revenue', r.d, '', r.trips, r.amount, '', '', '', '']); });
  (d.by_method || []).forEach(function (r) { lines.push(['By method', methodOf(r.method)[0], r.method, r.trips, r.amount, '', '', '', '']); });
  (d.drivers || []).forEach(function (r) { lines.push(['Driver', r.name, r.phone, '', r.total_earnings, r.total_paid_out, r.commission_owed, r.etel_money_balance, '']); });
  (d.payouts || []).forEach(function (r) { lines.push(['Payout', r.driver_name, r.driver_phone, '', r.amount, '', '', '', (r.status || '') + ' ' + (r.tx_ref || '')]); });
  var blob = new Blob(['﻿' + lines.map(function (l) { return l.map(csvCell).join(','); }).join('\r\n')], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'ride-payments-' + V.days + 'd-' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); if (a.parentNode) a.parentNode.removeChild(a); }, 500);
  K.toast('CSV downloaded');
}

/* ---------- top-up decisions ---------- */
function decide(id, approve, reason) {
  return K.api(approve ? 'admin_approve_etel_topup' : 'admin_reject_etel_topup', { id: id, reason: reason || '' }).then(function (res) {
    if (K.okRes(res)) { K.toast(approve ? 'Top-up approved — balance credited' : 'Top-up rejected'); V.busy = false; return load(true); }
    K.toast(K.failText(res, 'Could not save the decision.'), true);
    V.busy = false; return load(true);          /* someone else may already have decided it */
  });
}

function wire(el) {
  if (el._rp2) return; el._rp2 = true;
  el.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-seg]'))) {
      var n = b.getAttribute('data-seg'), v = b.getAttribute('data-val');
      if (n === 'tab') { V.tab = v; paint(); }
      else if (n === 'days') { V.days = parseInt(v, 10) || 30; V.busy = false; load(true); paint(); }
      return;
    }
    if (t.closest('[data-act="refresh"]')) { V.busy = false; load(true); return; }
    if (t.closest('[data-act="export"]')) { exportCsv(); return; }
    if ((b = t.closest('[data-approve]'))) {
      var id = parseInt(b.getAttribute('data-approve'), 10);
      K.confirm({ title: 'Approve this top-up?', text: etb(b.getAttribute('data-amt')) + ' will be added to ' + (b.getAttribute('data-name') || 'the driver') + '\'s Etel Money balance. This cannot be undone.', label: 'Approve and credit' }).then(function (r) { if (r) decide(id, true); });
      return;
    }
    if ((b = t.closest('[data-reject]'))) {
      var rid = parseInt(b.getAttribute('data-reject'), 10);
      K.confirm({ title: 'Reject this top-up?', text: 'Nothing is credited to ' + (b.getAttribute('data-name') || 'the driver') + '. Say why so the decision is on record.', label: 'Reject', danger: true, reason: 'Reason for rejecting', required: true }).then(function (r) { if (r) decide(rid, false, r.reason); });
    }
  });
  el.addEventListener('input', function (e) { if (e.target.id === 'rp2-q') { V.q = e.target.value.trim(); sq(); } });
  el.addEventListener('change', function (e) { if (e.target.id === 'rp2-sort') { V.sort = e.target.value; paint(); } });
  el.addEventListener('mouseover', tip); el.addEventListener('focusin', tip);
}
function tip(e) {
  var b = e.target.closest && e.target.closest('[data-tip]'), t = document.getElementById('rp2-tip');
  if (b && t) t.textContent = b.getAttribute('data-tip');
}
var sq = K.debounce(function () { paint(); }, 200);

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.ridePaymentsLoad = function () { return load(!!V.data); };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
