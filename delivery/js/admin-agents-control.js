/* ETEL Admin - Agents: Controls tab (agent control table, payout hold, own daily limit, internal note, bulk hold / release).
   Needs js/admin-agents-pro.js. Everything is a button an admin presses; nothing pays, approves or rejects here. Needs "agents.manage" to change anything
   ("agents.view" can look). Data: admin_fa_controls_list, admin_fa_controls_save. Every change goes into the audit log with the reason.
   Amharic labels are a first draft: please have a native speaker review them. */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__ctl) return; A.__ctl = true;
var K = A.K, esc = A.esc, run = A.run, ok = A.ok, dat = A.dat, etb = A.etb;
var AMC = {
  'Controls': 'መቆጣጠሪያዎች', 'Agent controls': 'የወኪል መቆጣጠሪያዎች', 'Hold payouts': 'ክፍያ ያዝ', 'Release hold': 'መያዣ ልቀቅ', 'On payout hold': 'በክፍያ መያዣ ላይ',
  'Own daily limit': 'የራሱ ዕለታዊ ገደብ', 'Agents': 'ወኪሎች', 'Search name, venue, phone': 'ስም፣ ቦታ፣ ስልክ ፈልግ', 'Riders (30 days)': 'ተሳፋሪዎች (30 ቀን)',
  'Earned (30 days)': 'የተገኘ (30 ቀን)', 'Ready to pay': 'ለክፍያ ዝግጁ', 'Today / limit': 'ዛሬ / ገደብ', 'Note': 'ማስታወሻ', 'Save': 'አስቀምጥ', 'Selected': 'የተመረጡ',
  'Clear': 'አጽዳ', 'All': 'ሁሉም', 'Dormant': 'የቆሙ', 'Suspended': 'ታግዷል', 'Export CSV': 'CSV አውጣ', 'Saved': 'ተቀምጧል'
};
function T(s) { return (A.S.lang === 'am' && AMC[s]) ? AMC[s] : A.T(s); }
var C = { list: null, cap: 100, q: '', filter: 'all', sort: 'earned', sel: {}, err: '' };
var FILTERS = [['all', 'All'], ['hold', 'On payout hold'], ['limit', 'Own daily limit'], ['suspended', 'Suspended'], ['dormant', 'Dormant']];
var SORTS = [['earned', 'Earned (30 days)'], ['riders', 'Riders (30 days)'], ['payable', 'Ready to pay'], ['name', 'Agent']];
function manage() { return A.can('agents.manage'); }
function num(n) { return Number(n) || 0; }
function money(n) { return etb(n).replace('ETB ', ''); }

function visible() {
  var q = C.q.trim().toLowerCase();
  var rows = (C.list || []).filter(function (a) {
    if (C.filter === 'hold' && !a.payout_hold) return false;
    if (C.filter === 'limit' && !(a.daily_cap_etb > 0)) return false;
    if (C.filter === 'suspended' && a.status !== 'suspended') return false;
    if (C.filter === 'dormant' && !(a.status === 'approved' && a.riders30 === 0)) return false;
    if (q && (a.name + ' ' + a.venue + ' ' + a.zone + ' ' + a.phone + ' ' + a.id).toLowerCase().indexOf(q) < 0) return false;
    return true;
  });
  rows.sort(function (x, y) {
    if (C.sort === 'name') return String(x.name).localeCompare(String(y.name));
    var k = C.sort === 'riders' ? 'riders30' : (C.sort === 'payable' ? 'payable' : 'earned30');
    return num(y[k]) - num(x[k]);
  });
  return rows;
}
function counts() {
  var l = C.list || [], o = { total: l.length, hold: 0, limit: 0, susp: 0, ready: 0, heldMoney: 0 };
  l.forEach(function (a) { if (a.payout_hold) { o.hold++; o.heldMoney += num(a.payable); } else if (a.status === 'approved') o.ready += num(a.payable); if (a.daily_cap_etb > 0) o.limit++; if (a.status === 'suspended') o.susp++; });
  return o;
}
function badges(a) {
  var h = '';
  if (a.payout_hold) h += '<span class="ag-pill amber" title="' + esc(a.hold_reason) + '">' + esc(T('On payout hold')) + '</span> ';
  if (a.daily_cap_etb > 0) h += '<span class="ag-pill blue">' + esc(T('Own daily limit')) + ' ' + esc(money(a.daily_cap_etb)) + '</span> ';
  if (a.note) h += '<span class="ag-pill" title="' + esc(a.note) + '">' + esc(T('Note')) + '</span>';
  return h;
}
function selCount() { var n = 0; for (var k in C.sel) if (C.sel[k]) n++; return n; }

function view() {
  if (C.err) return '<div class="ak-empty"><b>!</b><div>' + esc(C.err) + '</div></div>';
  if (!C.list) return '<div class="ak">' + K.skeleton(6) + '</div>';
  var c = counts(), rows = visible();
  var h = K.kpis([
    { key: 's1', icon: '🤝', label: T('Agents'), value: c.total },
    { key: 's2', icon: '⏸️', label: T('On payout hold'), value: c.hold, warn: true },
    { key: 's3', icon: '🎯', label: T('Own daily limit'), value: c.limit },
    { key: 's4', icon: '💰', label: T('Ready to pay'), value: etb(c.ready) },
    { key: 's5', icon: '🛑', label: T('Suspended'), value: c.susp, warn: true }
  ], '', true);
  h += '<section class="ag-box" style="margin-top:14px"><div class="ag-ctl-bar">' +
    '<input type="search" class="ak-in" id="ctl-q" value="' + esc(C.q) + '" placeholder="' + esc(T('Search name, venue, phone')) + '" aria-label="' + esc(T('Search name, venue, phone')) + '">' +
    '<select class="ak-in" id="ctl-sort" aria-label="Sort">' + SORTS.map(function (s) { return '<option value="' + s[0] + '"' + (C.sort === s[0] ? ' selected' : '') + '>' + esc(T(s[1])) + '</option>'; }).join('') + '</select>' +
    '<button type="button" class="ak-btn" data-ctl-export>' + esc(T('Export CSV')) + '</button></div>' +
    '<div class="ak-seg ag-ctl-seg" role="group" aria-label="Filter">' + FILTERS.map(function (f) { return '<button type="button" class="' + (C.filter === f[0] ? 'on' : '') + '" aria-pressed="' + (C.filter === f[0]) + '" data-ctl-filter="' + f[0] + '">' + esc(T(f[1])) + '</button>'; }).join('') + '</div>';
  var n = selCount();
  if (manage() && n) h += '<div class="ag-ctl-sel" role="status"><b>' + esc(T('Selected')) + ': ' + n + '</b><button type="button" class="ak-btn gold" data-ctl-bulk="hold">' + esc(T('Hold payouts')) + '</button>' +
    '<button type="button" class="ak-btn" data-ctl-bulk="release">' + esc(T('Release hold')) + '</button><button type="button" class="ak-btn" data-ctl-clear>' + esc(T('Clear')) + '</button></div>';
  if (!rows.length) return h + K.empty('🔎', 'No agents match', 'Change the search or the filter.') + '</section>';
  h += '<div class="ak-tblw"><table class="ak-tbl"><thead><tr>' + (manage() ? '<th scope="col" style="width:34px"><input type="checkbox" data-ctl-all aria-label="Select all shown"' + (rows.every(function (a) { return C.sel[a.id]; }) ? ' checked' : '') + '></th>' : '') +
    '<th scope="col">' + esc(T('Agent')) + '</th><th scope="col">' + esc(T('Status')) + '</th><th scope="col" class="ag-num">' + esc(T('Riders (30 days)')) + '</th><th scope="col" class="ag-num">' + esc(T('Earned (30 days)')) +
    '</th><th scope="col" class="ag-num">' + esc(T('Ready to pay')) + '</th><th scope="col" class="ag-num">' + esc(T('Today / limit')) + '</th><th scope="col">' + esc(T('Controls')) + '</th></tr></thead><tbody>';
  rows.forEach(function (a) {
    var lim = a.daily_cap_etb > 0 ? a.daily_cap_etb : C.cap;
    h += '<tr' + (a.payout_hold ? ' class="ag-ctl-held"' : '') + '>' + (manage() ? '<td><input type="checkbox" data-ctl-pick="' + a.id + '"' + (C.sel[a.id] ? ' checked' : '') + ' aria-label="Select ' + esc(a.name) + '"></td>' : '') +
      '<td><b>' + esc(a.name) + '</b><br><small>' + esc(a.venue) + (a.zone ? ' - ' + esc(a.zone) : '') + '</small></td><td>' + A.statusPill(a.status) + '</td>' +
      '<td class="ag-num">' + esc(a.riders30) + '</td><td class="ag-num">' + esc(money(a.earned30)) + '</td><td class="ag-num">' + esc(money(a.payable)) + '</td>' +
      '<td class="ag-num">' + esc(money(a.earned_today)) + ' / ' + esc(money(lim)) + '</td><td class="ag-ctl-c"><button type="button" class="ak-btn" data-ctl-open="' + a.id + '">' + esc(T(manage() ? 'Controls' : 'Details')) + '</button><div style="margin-top:6px">' + badges(a) + '</div></td></tr>';
  });
  return h + '</tbody></table></div></section>';
}
function paint() {
  var box = document.getElementById('ag-screen'); if (!box || A.S.tab !== 'controls') return;
  var keep = document.activeElement && document.activeElement.id === 'ctl-q' ? document.activeElement.selectionStart : -1;
  box.innerHTML = css() + view();
  if (keep >= 0) { var q = document.getElementById('ctl-q'); if (q) { q.focus(); try { q.setSelectionRange(keep, keep); } catch (e) { /* not supported */ } } }
}
function css() {
  return '<style>.ag-ctl-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.ag-ctl-bar .ak-in{width:auto;min-width:0}.ag-ctl-bar #ctl-q{flex:1 1 240px}.ag-ctl-c{min-width:200px;white-space:normal}.ag-ctl-c .ag-pill{margin:0 4px 4px 0}.ag-ctl-seg{margin-top:10px;flex-wrap:wrap}' +
    '.ag-ctl-sel{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px;padding:10px 12px;border-radius:12px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.45)}' +
    '.ag-ctl-held td{background:rgba(255,196,107,.08)}.ag-ctl-f{display:grid;gap:12px}.ag-ctl-f textarea{min-height:84px}</style>';
}
function load() {
  C.err = '';
  return run('admin_fa_controls_list').then(function (r) {
    if (!ok(r)) { C.err = K.failText(r, 'Could not load the agents.'); C.list = null; }
    else { C.list = dat(r).agents || []; C.cap = num(dat(r).programme_daily_cap); }
    paint();
  });
}
function byId(id) { return (C.list || []).filter(function (a) { return String(a.id) === String(id); })[0]; }

function openAgent(id) {
  var a = byId(id); if (!a) return;
  var edit = manage();
  var head = '<div class="ak-nm"><b>' + esc(a.name) + '</b><small>' + esc(a.venue) + ' - ' + esc(a.phone) + '</small></div>';
  var body = '<div class="ag-ctl-f"><div>' + A.statusPill(a.status) + ' <span class="ag-pill">' + esc(a.tier) + '</span></div>' +
    '<div class="ak-kv" style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><small>' + esc(T('Riders (30 days)')) + '</small><br><b>' + esc(a.riders30) + '</b></div><div><small>' + esc(T('Earned (30 days)')) + '</small><br><b>' + esc(etb(a.earned30)) + '</b></div>' +
    '<div><small>' + esc(T('Ready to pay')) + '</small><br><b>' + esc(etb(a.payable)) + '</b></div><div><small>' + esc(T('Today / limit')) + '</small><br><b>' + esc(money(a.earned_today)) + ' / ' + esc(money(a.daily_cap_etb > 0 ? a.daily_cap_etb : C.cap)) + '</b></div></div>' +
    K.sw('ctl-hold', T('Hold payouts'), !!a.payout_hold) + '<div class="ak-fh" style="margin-top:-6px">Earnings keep counting and stay payable. This agent is skipped when a payout batch is built.</div>' +
    K.field('Reason for the hold (needed when holding)', '<input class="ak-in" id="ctl-reason" maxlength="160" value="' + esc(a.hold_reason) + '"' + (edit ? '' : ' disabled') + '>') +
    K.field(T('Own daily limit') + ' (ETB)', '<input class="ak-in" id="ctl-cap" inputmode="decimal" value="' + (a.daily_cap_etb > 0 ? esc(a.daily_cap_etb) : '') + '" placeholder="' + esc(C.cap) + '"' + (edit ? '' : ' disabled') + '>',
      'Empty or 0 = use the programme limit (' + money(C.cap) + ' ETB a day). Money above the limit is held, as with the programme limit.') +
    K.field(T('Note') + ' (admins only, the agent never sees it)', '<textarea class="ak-in" id="ctl-note" maxlength="500"' + (edit ? '' : ' disabled') + '>' + esc(a.note) + '</textarea>') + '</div>';
  var dr = K.openDrawer(head, body, edit ? '<button type="button" class="ak-btn gold" data-ctl-save>' + esc(T('Save')) + '</button>' : '');
  var hold = dr.querySelector('#ctl-hold'); if (hold && !edit) hold.disabled = true;
  var sv = dr.querySelector('[data-ctl-save]');
  if (sv) sv.addEventListener('click', function () {
    var hv = dr.querySelector('#ctl-hold').checked, reason = dr.querySelector('#ctl-reason').value.trim();
    if (hv && !reason) { var ri = dr.querySelector('#ctl-reason'); ri.focus(); ri.setAttribute('aria-invalid', 'true'); K.toast('Write a short reason when you hold payouts.', true); return; }
    A.busy(sv, function () { return run('admin_fa_controls_save', { ids: [a.id], payout_hold: hv ? 1 : 0, hold_reason: hv ? reason : '', daily_cap_etb: dr.querySelector('#ctl-cap').value.trim(), note: dr.querySelector('#ctl-note').value }); }).then(function (r) {
      if (!r) return;
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      K.toast(T('Saved')); K.closeDrawer(); return load();
    });
  });
}
function bulk(kind) {
  var ids = Object.keys(C.sel).filter(function (k) { return C.sel[k]; }).map(Number); if (!ids.length) return;
  var hold = kind === 'hold';
  K.confirm({ title: (hold ? 'Hold payouts for ' : 'Release the hold for ') + ids.length + ' agent(s)?', text: hold ? 'They are skipped when a payout batch is built. Earnings keep counting.' : 'They can be picked for the next payout batch again.',
    label: T(hold ? 'Hold payouts' : 'Release hold'), reason: hold ? 'Reason (shown in the audit log)' : '', required: hold }).then(function (res) {
    if (!res) return;
    return run('admin_fa_controls_save', { ids: ids, payout_hold: hold ? 1 : 0, hold_reason: hold ? res.reason : '' }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; }
      C.sel = {}; K.toast(T('Saved') + ' (' + dat(r).changed + ')'); return load();
    });
  });
}
function exportCsv() {
  var rows = visible();
  A.exportRows('agent-controls.csv', ['ID', 'Name', 'Venue', 'Zone', 'Phone', 'Status', 'Tier', 'Riders 30d', 'Earned 30d', 'Ready to pay', 'Earned today', 'Own daily limit', 'Payout hold', 'Hold reason', 'Note'],
    rows.map(function (a) { return [a.id, a.name, a.venue, a.zone, a.phone, a.status, a.tier, a.riders30, a.earned30, a.payable, a.earned_today, a.daily_cap_etb, a.payout_hold ? 'yes' : 'no', a.hold_reason, a.note]; }));
}
function onClick(e) {
  var t = e.target, b;
  if ((b = t.closest('[data-ctl-open]'))) { openAgent(b.getAttribute('data-ctl-open')); return; }
  if ((b = t.closest('[data-ctl-filter]'))) { C.filter = b.getAttribute('data-ctl-filter'); paint(); return; }
  if ((b = t.closest('[data-ctl-bulk]'))) { bulk(b.getAttribute('data-ctl-bulk')); return; }
  if (t.closest('[data-ctl-clear]')) { C.sel = {}; paint(); return; }
  if (t.closest('[data-ctl-export]')) { exportCsv(); return; }
  if ((b = t.closest('[data-ctl-pick]'))) { C.sel[b.getAttribute('data-ctl-pick')] = b.checked; paint(); return; }
  if ((b = t.closest('[data-ctl-all]'))) { visible().forEach(function (a) { C.sel[a.id] = b.checked; }); paint(); return; }
}
function onInput(e) {
  if (e.target.id === 'ctl-q') { C.q = e.target.value; paint(); }
  else if (e.target.id === 'ctl-sort') { C.sort = e.target.value; paint(); }
}
document.addEventListener('click', function (e) { var host = document.getElementById('panel-agents'); if (host && host.contains(e.target)) onClick(e); });
document.addEventListener('input', function (e) { var host = document.getElementById('panel-agents'); if (host && host.contains(e.target)) onInput(e); });
document.addEventListener('change', function (e) { var host = document.getElementById('panel-agents'); if (host && host.contains(e.target)) onInput(e); });
A.register('controls', { render: function () { return css() + view(); }, open: function () { C.list = null; C.sel = {}; A.paintScreen(); return load(); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
