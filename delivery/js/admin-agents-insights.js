/* ETEL Admin - Agents: Insights tab (tiers, best agents / venues / zones, dormant agents + nudge, near-cap and budget alerts, weekly digest).
   Needs js/admin-agents-pro.js. Read-only except two buttons an admin presses: "Send nudge" (in-portal message only) and "Recalculate tiers".
   Telegram / SMS / EtelAI are NOT connected: the screen says so. Data: admin_fa_insights, admin_fa_nudge, admin_fa_digest_*, admin_fa_tiers_run. */
(function () {
'use strict';
function init() {
var A = window.ETELAgents; if (!A || A.__ins) return; A.__ins = true;
var K = A.K, esc = A.esc, run = A.run, ok = A.ok, dat = A.dat, etb = A.etb;
var AMI = {
  "Insights": "ግንዛቤዎች",
  "Best agents": "ምርጥ ወኪሎች",
  "Best venues": "ምርጥ ቦታዎች",
  "Best zones": "ምርጥ አካባቢዎች",
  "Dormant agents": "የቆሙ ወኪሎች",
  "Send nudge": "አስታውሽ ላክ",
  "Near their limit": "ገደባቸው አጠገብ",
  "Tiers": "ደረጃዎች",
  "Weekly digest": "ሳምንታዊ ማጠቃለያ",
  "Build this week now": "የዚህን ሳምንት አሁን አዘጋጅ",
  "Recalculate tiers": "ደረጃዎችን እንደገና አስላ",
  "Needs attention": "ትኩረት የሚሹ",
  "Nothing needs attention.": "ትኩረት የሚሻ የለም።",
  "Repeat rate": "ተደጋጋሚ መጠን",
  "Riders": "ተሳፋሪዎች",
  "days": "ቀናት",
  "Daily": "ዕለታዊ",
  "Weekly": "ሳምንታዊ",
  "Light": "ብሩህ",
  "Dark": "ጨለማ",
  "Theme": "ገጽታ",
  "Nudge sent": "አስታዋሹ ተልኳል",
  "Messages go only inside the agent portal. Telegram and SMS are not connected.": "መልዕክቶች በወኪል ፖርታል ውስጥ ብቻ ይላካሉ። ቴሌግራም እና ኤስኤምኤስ አልተገናኙም።"
};
function T(s) { return (A.S.lang === 'am' && AMI[s]) ? AMI[s] : A.T(s); }
var I = { days: 30, d: null, ch: null, ai: null, digests: [], pick: {}, err: '' };
var TIER = { bronze: ['Bronze', '#8c5a2b'], silver: ['Silver', '#5c6370'], gold: ['Gold', '#8a6508'] };

function tierPill(t) { var x = TIER[t] || TIER.bronze; return '<span class="ag-pill" style="border:1px solid ' + x[1] + ';color:' + x[1] + '">' + esc(x[0]) + '</span>'; }
function table(head, rows) { return '<div class="ak-tblw"><table class="ak-tbl"><thead><tr>' + head.map(function (h, i) { return '<th scope="col"' + (i ? ' class="ag-num"' : '') + '>' + esc(T(h)) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>'; }
function box(title, sub, body) { return '<section class="ag-box"><h3>' + esc(T(title)) + '</h3>' + (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') + body + '</section>'; }

function alertsHtml(d) {
  if (!d.alerts.length) return box('Needs attention', '', '<p style="margin:6px 0 0">' + esc(T('Nothing needs attention.')) + '</p>');
  return box('Needs attention', '', '<ul class="ag-why" style="list-style:none;padding:0;margin:8px 0 0;display:grid;gap:8px">' + d.alerts.map(function (a) {
    var tone = a.level === 'critical' ? 'red' : (a.level === 'warn' ? 'amber' : '');
    return '<li><span class="ag-pill ' + tone + '">' + esc(a.level) + '</span> ' + esc(a.text) + '</li>'; }).join('') + '</ul>');
}
function bestAgents(d) {
  if (!d.best_agents.length) return K.empty('🏆', 'Not enough data yet', 'An agent needs at least 3 riders in the period to be ranked.');
  return table(['Agent', 'Riders', 'Repeat rate', 'Tier'], d.best_agents.map(function (x) {
    return '<tr><td><button type="button" class="ak-btn" style="border:0;background:none;padding:0;font:inherit;font-weight:700;text-decoration:underline" data-ag-open-agent="' + esc(x.id) + '">' + esc(x.name) + '</button><br><small>' + esc(x.venue) + '</small></td><td class="ag-num">' + esc(x.acquired) + '</td><td class="ag-num">' + esc(x.repeat_pct) + '%</td><td class="ag-num">' + tierPill(x.tier) + '</td></tr>'; }));
}
function groupTable(list, first) {
  if (!list.length) return K.empty('📍', 'Not enough data yet', 'A place needs at least 3 riders in the period to be ranked.');
  return table([first, 'Riders', 'Repeat rate'], list.map(function (x) { return '<tr><td><b>' + esc(x.name) + '</b><br><small>' + esc(x.agents) + ' agent(s)</small></td><td class="ag-num">' + esc(x.acquired) + '</td><td class="ag-num">' + esc(x.repeat_pct) + '%</td></tr>'; }));
}
function dormantHtml(d) {
  var edit = A.can('agents.manage');
  if (!d.dormant.length) return K.empty('✅', 'Every agent is active', '');
  var rows = d.dormant.map(function (x) {
    var pick = edit && x.can_nudge ? '<input type="checkbox" data-ins-pick="' + esc(x.id) + '" aria-label="' + esc(x.name) + '"' + (I.pick[x.id] ? ' checked' : '') + '>' : '';
    return '<tr><td style="width:36px">' + pick + '</td><td><b>' + esc(x.name) + '</b><br><small>' + esc(x.venue) + '</small></td><td class="ag-num">' + esc(x.days_since) + ' ' + esc(T('days')) + '</td><td class="ag-num">' + (x.can_nudge ? '' : '<small>' + esc(x.last_nudge_at ? A.when(x.last_nudge_at) : '') + '</small>') + '</td></tr>'; });
  return '<div class="ak-tblw"><table class="ak-tbl"><tbody>' + rows.join('') + '</tbody></table></div>' +
    (edit ? '<p style="margin:12px 0 0"><button type="button" class="ak-btn gold" data-ins-nudge>' + esc(T('Send nudge')) + '</button> <small>' + esc(T('Messages go only inside the agent portal. Telegram and SMS are not connected.')) + '</small></p>' : '');
}
function capHtml(d) {
  if (!d.near_cap.length) return '<p style="margin:6px 0 0">-</p>';
  return table(['Agent', 'Daily', 'Weekly'], d.near_cap.map(function (x) { return '<tr><td><b>' + esc(x.name) + '</b></td><td class="ag-num">' + esc(x.daily_pct) + '%</td><td class="ag-num">' + esc(x.weekly_pct) + '%</td></tr>'; }));
}
function tiersHtml(d) {
  var r = d.tier_rules, edit = A.can('agents.manage');
  var cnt = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0">' + ['bronze', 'silver', 'gold'].map(function (t) { return '<div style="min-width:96px;padding:10px 14px;border:1px solid var(--line);border-radius:12px">' + tierPill(t) + '<div style="font-size:24px;font-weight:800;margin-top:4px">' + esc(d.tiers[t]) + '</div></div>'; }).join('') + '</div>';
  var rules = '<p style="font-size:13px;color:var(--mute);margin:6px 0">Over the last ' + esc(r.window_days) + ' days. Silver: ' + esc(r.silver[0]) + '+ riders and ' + esc(r.silver[1]) + '% coming back. Gold: ' + esc(r.gold[0]) + '+ riders and ' + esc(r.gold[1]) + '% coming back. Both also need under ' + esc(r.max_reject_pct) + '% rejected and a risk score under ' + esc(r.max_risk) + '.</p>' +
    '<p style="font-size:13px;color:var(--mute);margin:6px 0">Perks: a shorter hold (Bronze ' + esc(r.hold_days.bronze) + ' / Silver ' + esc(r.hold_days.silver) + ' / Gold ' + esc(r.hold_days.gold) + ' days) and a badge. A tier never raises a bonus or a cap. Change the numbers in Settings.</p>';
  return cnt + rules + (edit ? '<button type="button" class="ak-btn" data-ins-tiers>' + esc(T('Recalculate tiers')) + '</button>' : '');
}
function digestHtml() {
  var edit = A.can('agents.manage'), h = '';
  if (edit) h += '<p style="margin:0 0 10px"><button type="button" class="ak-btn" data-ins-digest>' + esc(T('Build this week now')) + '</button></p>';
  if (!I.digests.length) return h + K.empty('🗞️', 'No digest yet', 'One is built automatically each week by the earnings job.');
  var one = I.digests[0], p = one.payload || {};
  h += '<details open><summary><b>Week of ' + esc(one.week_start) + '</b></summary><pre style="white-space:pre-wrap;font:inherit;margin:8px 0 0">' + esc(A.S.lang === 'am' ? p.text_am : p.text_en) + '</pre></details>';
  if (I.digests.length > 1) h += '<details style="margin-top:8px"><summary>Earlier weeks</summary>' + I.digests.slice(1).map(function (x) { return '<details style="margin:6px 0 0 12px"><summary>' + esc(x.week_start) + '</summary><pre style="white-space:pre-wrap;font:inherit;margin:6px 0 0">' + esc(A.S.lang === 'am' ? (x.payload || {}).text_am : (x.payload || {}).text_en) + '</pre></details>'; }).join('') + '</details>';
  return h;
}
function insightsHtml() {
  var tools = '<div class="ag-tools">' + K.seg('ins-days', [['7', '7 days'], ['30', '30 days'], ['90', '90 days']], String(I.days)) + '</div>';
  if (I.err) return tools + '<div class="ak-empty"><b>⚠️</b><div>' + esc(I.err) + '</div></div>';
  if (!I.d) return tools + K.skeleton(4);
  var d = I.d, b = d.budget;
  var budget = b.budget > 0 ? '<p style="margin:6px 0">' + etb(b.spent) + ' of ' + etb(b.budget) + ' used (' + esc(b.pct_used) + '%). At this pace: ' + etb(b.projected) + ' (' + esc(b.projected_pct) + '%).</p>' : '<p style="margin:6px 0">No monthly budget set. Spent this month: ' + etb(b.spent) + '. Set a budget in Settings to get alerts.</p>';
  var h = tools + alertsHtml(d);
  h += '<div class="ag-grid2">' + box('Best agents', 'Ranked by riders who come back, then by volume', bestAgents(d)) + box('Tiers', '', tiersHtml(d)) + '</div>';
  h += '<div class="ag-grid2">' + box('Best venues', 'Where riders keep riding', groupTable(d.best_venues, 'Venue')) + box('Best zones', 'Where riders keep riding', groupTable(d.best_zones, 'Zone')) + '</div>';
  h += '<div class="ag-grid2">' + box('Dormant agents', 'No new rider for a while', dormantHtml(d)) + box('Near their limit', 'Close to the daily or weekly cap', capHtml(d) + '<h4 style="margin:14px 0 4px">Budget</h4>' + budget) + '</div>';
  h += box('Weekly digest', '', digestHtml());
  h += '<p style="font-size:12.5px;color:var(--mute)">EtelAI summary: ' + esc(I.ai && I.ai.enabled ? 'on' : 'off. Nothing is sent to any outside service. Turning it on needs the owner\'s approval of exactly what is sent (totals only, no names or phone numbers).') + '</p>';
  return h;
}
function paintNow() { var box = document.getElementById('ag-screen'); if (box && A.S.tab === 'insights') box.innerHTML = insightsHtml(); }
function load() {
  var p1 = run('admin_fa_insights', { days: I.days }).then(function (r) {
    if (!ok(r)) { I.err = K.failText(r, 'Could not load insights.'); I.d = null; } else { I.err = ''; var x = dat(r); I.d = x.insights; I.ch = x.channels; I.ai = x.ai; }
  });
  var p2 = run('admin_fa_digest_list').then(function (r) { if (ok(r)) I.digests = dat(r).digests; });
  return Promise.all([p1, p2]).then(paintNow);
}
function onClick(e) {
  var t = e.target, b;
  if (A.S.tab !== 'insights') return;
  if ((b = t.closest('[data-seg="ins-days"]'))) { I.days = parseInt(b.getAttribute('data-val'), 10) || 30; I.d = null; paintNow(); load(); return; }
  if ((b = t.closest('[data-ins-pick]'))) { I.pick[b.getAttribute('data-ins-pick')] = b.checked; return; }
  if ((b = t.closest('[data-ins-nudge]'))) {
    var ids = Object.keys(I.pick).filter(function (k) { return I.pick[k]; });
    if (!ids.length) { K.toast('Tick at least one agent first', true); return; }
    A.busy(b, function () { return run('admin_fa_nudge', { agent_ids: ids.map(Number) }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not send.'), true); return; }
      var x = dat(r); K.toast(T('Nudge sent') + ': ' + x.sent.length + (x.skipped.length ? ', skipped ' + x.skipped.length : '')); I.pick = {}; return load(); }); });
    return;
  }
  if ((b = t.closest('[data-ins-tiers]'))) { A.busy(b, function () { return run('admin_fa_tiers_run').then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not recalculate.'), true); return; } K.toast(dat(r).changed.length + ' tier change(s)'); return load(); }); }); return; }
  if ((b = t.closest('[data-ins-digest]'))) { A.busy(b, function () { return run('admin_fa_digest_build').then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not build.'), true); return; } K.toast(dat(r).created ? 'Digest saved' : 'This week already has a digest'); return load(); }); }); return; }
}
document.addEventListener('click', function (e) { var host = document.getElementById('panel-agents'); if (host && host.contains(e.target)) onClick(e); });
A.register('insights', { render: insightsHtml, open: function () { I.d = null; A.paintScreen(); return load(); } });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
