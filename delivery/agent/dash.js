/* ETEL Agent portal - "Your numbers" dashboard on the Home screen. Loaded on demand by app.js (home only).
   Shows earnings, new riders, first rides and bookings for 7 / 30 / 90 days next to the period before, with two small charts
   (plain SVG, no library). Data: fa_agent_dashboard (read only, only this agent). Works in light and dark through the CSS variables.
   Every chart also has a "show as a list" table, so nothing depends on seeing the picture. */
(function () {
'use strict';
var P = window.ETELAP; if (!P || window.ETELDash) return;
var t = P.t, esc = P.esc, ic = P.ic, api = P.api, fmt = P.fmt;
var D = { days: 30, data: null, err: false, busy: false, pick: -1 };

function pct(cur, prev) {
  cur = Number(cur) || 0; prev = Number(prev) || 0;
  if (!prev) return cur ? { txt: '+100%', cls: 'ok' } : { txt: '0%', cls: '' };
  var p = Math.round(100 * (cur - prev) / prev);
  return { txt: (p > 0 ? '+' : '') + p + '%', cls: p > 0 ? 'ok' : (p < 0 ? 'bad' : '') };
}
function kpi(label, value, cur, prev, unit) {
  var c = pct(cur, prev);
  return '<div class="ds-k"><div class="lbl">' + esc(label) + '</div><div class="ds-v num">' + esc(value) + (unit ? '<small>' + esc(unit) + '</small>' : '') + '</div>' +
    '<div class="ds-c"><span class="chip ' + c.cls + '">' + esc(c.txt) + '</span> <small>' + esc(t('ds.vs')) + '</small></div></div>';
}
function shortDay(s) { var p = String(s).split('-'); return p.length === 3 ? p[2] + '/' + p[1] : s; }
function bars(series, key, label, money) {
  var W = 320, H = 120, pad = 6, n = series.length, max = 0, i, v;
  for (i = 0; i < n; i++) { v = Number(series[i][key]) || 0; if (v > max) max = v; }
  if (!max) return '<div class="ds-empty">' + esc(t('ds.none')) + '</div>';
  var bw = (W - pad * 2) / n, g = Math.min(3, bw * 0.25), out = '', total = 0;
  for (i = 0; i < n; i++) {
    v = Number(series[i][key]) || 0; total += v;
    var h = Math.max(v > 0 ? 3 : 1, Math.round((H - 22) * v / max));
    out += '<rect class="ds-bar' + (i === D.pick ? ' on' : '') + (v > 0 ? '' : ' z') + '" data-act="ds-pick" data-i="' + i + '" x="' + (pad + i * bw + g / 2).toFixed(1) + '" y="' + (H - 14 - h) + '" width="' + Math.max(1, bw - g).toFixed(1) + '" height="' + h + '" rx="2"><title>' + esc(shortDay(series[i].d) + ': ' + (money ? fmt(v) + ' ' + t('c.etb') : v)) + '</title></rect>';
  }
  out += '<text class="ds-ax" x="' + pad + '" y="' + (H - 2) + '">' + esc(shortDay(series[0].d)) + '</text><text class="ds-ax" x="' + (W - pad) + '" y="' + (H - 2) + '" text-anchor="end">' + esc(shortDay(series[n - 1].d)) + '</text>';
  var sel = D.pick >= 0 && series[D.pick] ? '<div class="ds-sel" role="status">' + esc(shortDay(series[D.pick].d)) + ': <b class="num">' + esc(money ? fmt(series[D.pick][key]) + ' ' + t('c.etb') : String(series[D.pick][key])) + '</b></div>' : '';
  return '<svg class="ds-svg" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(label + ': ' + (money ? fmt(total) + ' ' + t('c.etb') : total)) + '">' + out + '</svg>' + sel;
}
function listTable(series) {
  var rows = '', i;
  for (i = series.length - 1; i >= 0; i--) {
    var r = series[i]; if (!r.earned && !r.riders && !r.qualified && !r.bookings) continue;
    rows += '<tr><td>' + esc(shortDay(r.d)) + '</td><td class="num">' + esc(fmt(r.earned)) + '</td><td class="num">' + esc(r.riders) + '</td><td class="num">' + esc(r.qualified) + '</td><td class="num">' + esc(r.bookings) + '</td></tr>';
  }
  if (!rows) return '';
  return '<details class="ds-list"><summary>' + esc(t('ds.list')) + '</summary><div class="ds-tw"><table><thead><tr><th>' + esc(t('ds.day')) + '</th><th>' + esc(t('ds.earned')) + '</th><th>' + esc(t('ds.riders')) + '</th><th>' + esc(t('ds.qualified')) + '</th><th>' + esc(t('ds.bookings')) + '</th></tr></thead><tbody>' + rows + '</tbody></table></div></details>';
}
function view() {
  var h = '<div class="ds-head"><h2>' + esc(t('ds.title')) + '</h2><div class="ds-seg" role="group" aria-label="' + esc(t('ds.title')) + '">';
  [7, 30, 90].forEach(function (d) { h += '<button type="button" data-act="ds-days" data-d="' + d + '" aria-pressed="' + (D.days === d) + '">' + esc(t('ds.d' + d)) + '</button>'; });
  h += '</div></div>';
  if (D.err) return h + '<div class="err" role="alert">' + esc(t('ds.err')) + '</div><div style="margin-top:12px"><button class="btn" data-act="ds-retry">' + esc(t('c.retry')) + '</button></div>';
  var d = D.data;
  if (!d) return h + '<div class="ds-grid" aria-busy="true"><div class="skel" style="height:92px"></div><div class="skel" style="height:92px"></div><div class="skel" style="height:92px"></div><div class="skel" style="height:92px"></div></div>';
  var c = d.current, p = d.previous;
  h += '<div class="ds-grid">' + kpi(t('ds.earned'), fmt(c.earned), c.earned, p.earned, t('c.etb')) + kpi(t('ds.riders'), String(c.riders), c.riders, p.riders) +
    kpi(t('ds.qualified'), String(c.qualified), c.qualified, p.qualified) + kpi(t('ds.bookings'), String(c.bookings), c.bookings, p.bookings) + '</div>';
  h += '<div class="ds-facts"><span class="chip gold">' + esc(t('ds.today')) + ': <b class="num">' + esc(fmt(d.today.earned)) + '</b> ' + esc(t('c.etb')) + '</span>' +
    '<span class="chip">' + esc(t('ds.conv')) + ': <b class="num">' + esc(d.conversion_pct) + '%</b></span>' +
    (d.best_day ? '<span class="chip">' + esc(t('ds.best')) + ': <b class="num">' + esc(shortDay(d.best_day.d)) + '</b> (' + esc(fmt(d.best_day.earned)) + ')</span>' : '') +
    (d.streak_days > 1 ? '<span class="chip ok">' + esc(t('ds.streak')) + ': <b class="num">' + esc(d.streak_days) + '</b></span>' : '') + '</div>';
  h += '<div class="ds-charts"><div class="ds-ch"><div class="lbl">' + esc(t('ds.chart_earn')) + '</div>' + bars(d.series, 'earned', t('ds.chart_earn'), true) + '</div>' +
    '<div class="ds-ch"><div class="lbl">' + esc(t('ds.chart_riders')) + '</div>' + bars(d.series, 'riders', t('ds.chart_riders'), false) + '</div></div>' + listTable(d.series);
  return h;
}
function paint() { var el = document.getElementById('dash'); if (el) el.innerHTML = view(); }
function load() {
  if (D.busy) return; D.busy = true; D.err = false;
  var want = D.days;
  api('fa_agent_dashboard', { days: want }).then(function (r) {
    D.busy = false;
    if (want !== D.days) return load();                       // the agent changed the period while waiting
    if (r.ok) { D.data = r.data; D.err = false; } else if (!D.data) { D.err = true; }
    paint();
  });
}
window.ETELDash = {
  mount: function () { D.pick = -1; paint(); if (!D.data) load(); else { paint(); load(); } },
  act: function (act, b) {
    if (act === 'ds-days') { var d = parseInt(b.getAttribute('data-d'), 10); if (d !== D.days) { D.days = d; D.data = null; D.pick = -1; paint(); load(); } }
    else if (act === 'ds-retry') { D.err = false; D.data = null; paint(); load(); }
    else if (act === 'ds-pick') { var i = parseInt(b.getAttribute('data-i'), 10); D.pick = D.pick === i ? -1 : i; paint(); }
  }
};
})();
