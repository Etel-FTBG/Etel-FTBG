/* ETEL Admin - Pricing studio (premium).
   Everything an admin sets prices with, in one controller screen: ride fares per vehicle (with a live fare calculator that reads the numbers
   you are typing, before you save them), the surge multiplier and peak-hour schedule (with a week-at-a-glance map of when surge applies),
   driver daily-goal tiers, delivery zones and tiers (with a zone x tier price table), and the bank-transfer accounts shown at checkout.
   The calculator mirrors rides_calc_fare() in api/lib/ride_fare.php, the schedule map mirrors rides_active_peak_multiplier().
   Data actions are the same as before: admin_get/update_ride_fare_rule(s), admin_add_vehicle_type, admin_delete_vehicle_type,
   admin_update_ride_surge_multiplier, admin_get/update_ride_peak_windows, admin_get/save/delete_goal_tier(s), admin_get/add/update/delete_zone(s),
   admin_get_delivery_tiers, admin_update_delivery_tier, admin_get_bank_accounts, admin_update_bank_account.
   Also holds the courier pay and tips settings (admin_get/save_courier_pay, admin_preview_courier_pay, admin_get/set_order_pay). Replaces js/admin-pricing.js and js/admin-courier-pay.js; admin-ui.js still opens the page through ETELPricing.loadPricing(). Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var S = { tab: '', caps: [], rules: [], surge: 1.3, windows: [], isDefault: false, goals: [], zones: [], tiers: [], banks: [], err: {}, cp: null, order: null, cpOut: '', loaded: false, calc: { vt: '', km: 5, wait: 0, open: false, peak: false } };
var TABS = [['fares', 'Ride fares', 'pricing.manage'], ['peak', 'Peak hours', 'pricing.manage'], ['goals', 'Driver goals', 'pricing.manage'], ['delivery', 'Delivery', 'settings.view'], ['courier', 'Courier pay', ['pricing.manage', 'settings.view']], ['banks', 'Bank accounts', 'finance.view']];
var BUILTIN = ['bajaj', 'moto', 'car', 'minibus'];
var ART = ['bajaj', 'qute', 'moto', 'car', 'ev_car', 'minibus'];
var ICON = { bajaj: '🛺', moto: '🏍️', car: '🚗', minibus: '🚐', qute: '🛺', ev_car: '⚡' };
var DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
var FIELDS = [['base_fare', 'Base fare'], ['per_km_rate', 'Per km (metered)'], ['per_km_rate_open', 'Per km (open trip)'], ['wait_rate_per_min', 'Waiting / min'], ['min_fare', 'Minimum fare'], ['min_fare_short_trip', 'Minimum (3 km or less)']];

function root() { return document.getElementById('pricing-studio'); }
function f(n) { return parseFloat(n) || 0; }
function etb(n) { return 'ETB ' + (Math.round(f(n) * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function can(cap) { if (cap instanceof Array) return cap.some(can); return S.caps.indexOf('*') !== -1 || S.caps.indexOf(cap) !== -1; }
function vlabel(vt) { return vt === 'ev_car' ? 'EV Car' : String(vt).charAt(0).toUpperCase() + String(vt).slice(1).replace(/_/g, ' '); }
function slug(raw) { return String(raw || '').toLowerCase().replace(/[^a-z0-9\s_-]/g, '').replace(/[\s-]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '').replace(/^[^a-z]+/, '').slice(0, 20); }
function vpic(vt) {
  return ART.indexOf(vt) >= 0 ? '<img src="img/vehicles/' + esc(vt) + '.png" alt="" loading="lazy" data-emoji="' + esc(ICON[vt] || '🚘') + '">' : (ICON[vt] || '🚘');
}
function inp(cls, field, val, extra) { return '<input class="ak-in ' + cls + '" data-f="' + field + '" value="' + esc(val == null ? '' : val) + '" ' + (extra || '') + '>'; }
function numIn(cls, field, val, label, step, min) { return '<label class="ak-fl">' + esc(label) + inp(cls, field, val, 'type="number" inputmode="decimal" step="' + (step || '0.01') + '" min="' + (min == null ? '0' : min) + '"') + '</label>'; }

function css() {
  K.css();
  if (document.getElementById('ps-css')) return;
  var s = document.createElement('style'); s.id = 'ps-css';
  s.textContent = [
    '.ps-tabs{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 12px}',
    '.ps-box{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:16px;box-shadow:var(--shadow);margin-bottom:12px}',
    '.ps-box h3{margin:0 0 2px;font-size:14.5px;color:var(--ink)}.ps-box .sub{font-size:12px;color:var(--mute);margin-bottom:12px}',
    '.ps-calc{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px}@media(max-width:820px){.ps-calc{grid-template-columns:1fr}}',
    '.ps-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;align-items:end}',
    '.ps-out{background:linear-gradient(160deg,rgba(212,175,55,.14),var(--soft));border:1.5px solid var(--gold);border-radius:16px;padding:14px}',
    '.ps-out .big{font-size:30px;font-weight:800;color:var(--ink);line-height:1.1}.ps-out table{width:100%;font-size:12.5px;margin-top:8px;border-collapse:collapse}.ps-out td{padding:3px 0;color:var(--mute)}.ps-out td:last-child{text-align:right;color:var(--ink);font-weight:700}',
    '.ps-vs{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px}',
    '.ps-v{background:var(--card);border:1.5px solid var(--line);border-radius:18px;padding:14px;box-shadow:var(--shadow)}.ps-v.off{border-style:dashed;opacity:.8}.ps-v.dirty{border-color:var(--gold)}',
    '.ps-vh{display:flex;gap:12px;align-items:center;margin-bottom:12px}.ps-pic{flex:none;width:76px;height:52px;display:flex;align-items:center;justify-content:center;font-size:30px;border-radius:12px;background:var(--soft)}.ps-pic img{max-width:100%;max-height:100%;object-fit:contain}',
    '.ps-vh b{display:block;font-size:15px}.ps-vh small{color:var(--mute);font-size:11.5px}',
    '.ps-fg{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 10px}',
    '.ps-win{display:grid;grid-template-columns:minmax(140px,1.2fr) auto auto minmax(90px,.5fr) auto;gap:10px;align-items:end;background:var(--soft);border:1.5px solid var(--line);border-radius:14px;padding:12px;margin-bottom:10px}',
    '@media(max-width:900px){.ps-win{grid-template-columns:1fr 1fr}.ps-win>.wide{grid-column:1 / 3}}',
    '.ps-days{display:flex;gap:4px;flex-wrap:wrap}.ps-day{width:34px;height:34px;border-radius:10px;border:1.5px solid var(--line);background:var(--card);color:var(--mute);font:inherit;font-size:12px;font-weight:800;cursor:pointer}.ps-day[aria-pressed="true"]{background:linear-gradient(135deg,var(--gold),var(--gold2));color:#1c1500;border-color:transparent}',
    '.ps-time{display:flex;gap:6px;align-items:center}.ps-time .ak-in{width:112px}',
    '.ps-map{display:grid;grid-template-columns:44px repeat(24,minmax(0,1fr));gap:2px;font-size:10.5px;color:var(--mute);align-items:center}',
    '.ps-map i{font-style:normal}.ps-map .c{height:20px;border-radius:4px;background:var(--line)}.ps-map .h{text-align:center}',
    '.ps-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--mute);margin-top:10px;align-items:center}.ps-legend span.c{display:inline-block;width:16px;height:12px;border-radius:3px;vertical-align:-1px;margin-right:4px}',
    '.ps-tbl input.ak-in{padding:7px 9px;min-width:70px}.ps-tbl td{vertical-align:middle}.ps-r{text-align:right}',
    '.ps-banks{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}',
    '.ps-note{font-size:12px;color:var(--mute);margin-top:8px}.ps-warn{color:var(--red);font-weight:700}',
    '.ps-vh2{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
  ].join('\n');
  document.head.appendChild(s);
}

/* ---------- loading ---------- */
function run(action, body) { return K.api(action, body || {}); }
function loadAll() {
  var el = root(); if (!el) return Promise.resolve();
  css();
  if (!S.loaded) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  var caps = window.ETEL_CAPS ? Promise.resolve(window.ETEL_CAPS) : (window.ETEL_CAPS_PROMISE || Promise.resolve([]));
  return caps.then(function (c) {
    S.caps = c || [];
    var jobs = [];
    S.err = {};
    function job(name, action, fn) { jobs.push(run(action).then(function (res) { if (K.okRes(res)) fn(K.data(res)); else S.err[name] = K.failText(res); })); }
    if (can('pricing.manage')) {
      job('fares', 'admin_get_ride_fare_rules', function (d) { S.rules = d.rules || []; S.surge = f(d.ride_surge_multiplier) || 1.3; });
      job('peak', 'admin_get_ride_peak_windows', function (d) {
        S.isDefault = !!d.is_default;
        S.windows = (d.windows || []).map(function (w) { return { label: w.label || '', days: (w.days || []).map(Number), start: w.start || '00:00', end: w.end || '00:00', multiplier: w.multiplier != null ? Number(w.multiplier) : null }; });
      });
      job('goals', 'admin_get_goal_tiers', function (d) { S.goals = d.tiers || []; });
    }
    if (can('settings.view')) { job('zones', 'admin_get_zones', function (d) { S.zones = d.zones || []; }); job('tiers', 'admin_get_delivery_tiers', function (d) { S.tiers = d.tiers || []; }); }
    if (can('finance.view')) job('banks', 'admin_get_bank_accounts', function (d) { S.banks = d.accounts || []; });
    if (can(['pricing.manage', 'settings.view'])) job('courier', 'admin_get_courier_pay', function (d) { S.cp = d; });
    return Promise.all(jobs);
  }).then(function () {
    var vis = TABS.filter(function (t) { return can(t[2]); });
    if (!S.tab || !vis.some(function (t) { return t[0] === S.tab; })) S.tab = vis.length ? vis[0][0] : '';
    var act = S.rules.filter(function (r) { return String(r.active) === '1'; });
    if (!S.calc.vt || !act.some(function (r) { return r.vehicle_type === S.calc.vt; })) S.calc.vt = act.length ? act[0].vehicle_type : '';
    S.loaded = true; paint();
  });
}

/* ---------- surge logic (mirror of rides_active_peak_multiplier) ---------- */
function mins(t) { var p = String(t || '').split(':'); return Math.max(0, Math.min(24, parseInt(p[0], 10) || 0)) * 60 + Math.max(0, Math.min(59, parseInt(p[1], 10) || 0)); }
function surgeAt(dow, minute) {
  var prev = dow === 1 ? 7 : dow - 1;
  for (var i = 0; i < S.windows.length; i++) {
    var w = S.windows[i], st = mins(w.start), en = mins(w.end), hit = false;
    if (en > st) hit = w.days.indexOf(dow) >= 0 && minute >= st && minute < en;
    else hit = (w.days.indexOf(dow) >= 0 && minute >= st) || (w.days.indexOf(prev) >= 0 && minute < en);
    if (hit) return w.multiplier > 0 ? w.multiplier : S.surge;
  }
  return null;
}

/* ---------- views ---------- */
function calcOut() {
  var c = S.calc, row = rowFor(c.vt);
  if (!row) return '<div class="ps-note">Switch on a vehicle type to try a fare.</div>';
  var v = {}; FIELDS.forEach(function (x) { v[x[0]] = f(row[x[0]]); });
  var km = Math.max(0, f(c.km)), wait = Math.min(Math.max(0, f(c.wait)), 30), surge = c.peak ? S.surge : 1;
  var perKm = c.open ? v.per_km_rate_open : v.per_km_rate;
  var raw = (v.base_fare + perKm * km + v.wait_rate_per_min * wait) * surge;
  var floor = c.open ? v.min_fare : (km <= 3 ? v.min_fare_short_trip : v.min_fare);
  var fare = Math.max(floor, Math.round(raw * 100) / 100);
  return '<div class="ps-out"><div style="font-size:12px;color:var(--mute)">Rider pays</div><div class="big" id="ps-fare">' + esc(etb(fare)) + '</div><table>' +
    '<tr><td>Base fare</td><td>' + esc(etb(v.base_fare)) + '</td></tr><tr><td>Distance (' + km + ' km × ' + esc(perKm) + ')</td><td>' + esc(etb(perKm * km)) + '</td></tr>' +
    '<tr><td>Waiting (' + wait + ' min)</td><td>' + esc(etb(v.wait_rate_per_min * wait)) + '</td></tr>' + (c.peak ? '<tr><td>Surge</td><td>× ' + esc(surge) + '</td></tr>' : '') +
    '<tr><td>' + (fare > Math.round(raw * 100) / 100 ? 'Minimum fare applied' : 'Minimum fare') + '</td><td>' + esc(etb(floor)) + '</td></tr></table>' +
    '<div class="ps-note">Uses the numbers in the cards below as you type them, before you save. Billed waiting stops at 30 minutes.</div></div>';
}
function rowFor(vt) {
  var el = document.querySelector('.ps-v[data-vt="' + vt + '"]'), r = null;
  S.rules.forEach(function (x) { if (x.vehicle_type === vt) r = x; });
  if (!r) return null;
  if (!el) return r;
  var o = {}; Object.keys(r).forEach(function (k) { o[k] = r[k]; });
  el.querySelectorAll('input[data-f]').forEach(function (i) { if (i.type !== 'checkbox') o[i.getAttribute('data-f')] = i.value; });
  return o;
}
function faresView() {
  if (S.err.fares) return K.empty('⚠️', 'Could not load ride fares', S.err.fares + ' — has migration 007_ride_fare_rules.sql been applied?');
  var act = S.rules.filter(function (r) { return String(r.active) === '1'; }), c = S.calc;
  var calc = '<div class="ps-box"><h3>Fare calculator</h3><div class="sub">Try a trip with the prices on this screen.</div><div class="ps-calc"><div><div class="ps-ins">' +
    '<label class="ak-fl">Vehicle<select class="ak-in" id="ps-c-vt">' + act.map(function (r) { return '<option value="' + esc(r.vehicle_type) + '"' + (r.vehicle_type === c.vt ? ' selected' : '') + '>' + esc(vlabel(r.vehicle_type)) + '</option>'; }).join('') + '</select></label>' +
    '<label class="ak-fl">Distance (km)<input class="ak-in" id="ps-c-km" type="number" min="0" step="0.1" value="' + esc(c.km) + '"></label>' +
    '<label class="ak-fl">Waiting (min)<input class="ak-in" id="ps-c-wait" type="number" min="0" step="1" value="' + esc(c.wait) + '"></label></div>' +
    '<div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px">' + K.sw('ps-c-open', 'Open trip (no fixed destination)', c.open) + K.sw('ps-c-peak', 'During peak hours (× ' + S.surge + ')', c.peak) + '</div></div><div id="ps-calc-out">' + calcOut() + '</div></div></div>';
  var order = S.rules.slice().sort(function (a, b) { var ia = BUILTIN.indexOf(a.vehicle_type), ib = BUILTIN.indexOf(b.vehicle_type); return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib) || a.vehicle_type.localeCompare(b.vehicle_type); });
  var cards = order.map(function (r) {
    var on = String(r.active) === '1';
    return '<div class="ps-v' + (on ? '' : ' off') + '" data-vt="' + esc(r.vehicle_type) + '"><div class="ps-vh"><div class="ps-pic">' + vpic(r.vehicle_type) + '</div><div style="flex:1;min-width:0"><b>' + esc(vlabel(r.vehicle_type)) + '</b><small>' + esc(r.vehicle_type) + '</small></div>' +
      K.pill(on ? 'On' : 'Off', on ? 'green' : 'grey') + '</div><div class="ps-fg">' + FIELDS.map(function (x) { return numIn('ps-f', x[0], r[x[0]], x[1]); }).join('') + '</div>' +
      '<div style="margin-top:10px">' + K.sw('ps-on-' + r.vehicle_type, 'Offered to riders', on) + '</div>' +
      '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn gold" data-save-vt="' + esc(r.vehicle_type) + '">💾 Save</button><button type="button" class="ak-btn danger" data-del-vt="' + esc(r.vehicle_type) + '">Switch off…</button></div></div>';
  }).join('');
  var surge = '<div class="ps-box"><h3>Peak-hour surge</h3><div class="sub">The default multiplier for new peak windows. Each window on the Peak hours tab can use its own.</div><div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">' +
    '<label class="ak-fl">Multiplier<input class="ak-in" id="ps-surge" type="number" step="0.01" min="0.1" max="5" value="' + esc(S.surge) + '" style="width:120px"></label><button type="button" class="ak-btn gold" data-save-surge>💾 Save</button></div></div>';
  return calc + surge + '<div class="ak-tools"><span style="flex:1"></span><button type="button" class="ak-btn gold" data-add-vt>➕ Add vehicle type</button></div>' + (cards ? '<div class="ps-vs">' + cards + '</div>' : K.empty('🚖', 'No vehicle types yet', 'Use "Add vehicle type" to create the first one.'));
}

function peakMap() {
  var h = '<div class="ps-map" role="img" aria-label="Map of when surge applies, by day of the week and hour. The list of windows above has the same information as text.">' + '<i></i>';
  for (var x = 0; x < 24; x++) h += '<i class="h">' + (x % 3 === 0 ? x : '') + '</i>';
  var max = 1; S.windows.forEach(function (w) { if (w.multiplier > max) max = w.multiplier; });
  for (var d = 1; d <= 7; d++) {
    h += '<i>' + DAYS[d - 1].slice(0, 3) + '</i>';
    for (var hr = 0; hr < 24; hr++) {
      var m = surgeAt(d, hr * 60 + 30);
      h += '<i class="c"' + (m ? ' style="background:rgba(212,175,55,' + Math.min(1, 0.35 + (m - 1) / Math.max(0.5, max - 1 + 0.5) * 0.65).toFixed(2) + ')"' : '') + '></i>';
    }
  }
  return h + '</div><div class="ps-legend"><span><span class="c" style="background:var(--line)"></span>Normal price</span><span><span class="c" style="background:rgba(212,175,55,.55)"></span>Surge (darker = higher)</span></div>';
}
function peakView() {
  if (S.err.peak) return K.empty('⚠️', 'Could not load the peak schedule', S.err.peak);
  var rows = S.windows.map(function (w, i) {
    var mid = w.end === '24:00';
    return '<div class="ps-win" data-i="' + i + '"><label class="ak-fl wide">Label<input class="ak-in" data-f="label" maxlength="40" placeholder="Morning rush" value="' + esc(w.label) + '"></label>' +
      '<div class="ak-fl wide">Days<div class="ps-days">' + DAYS.map(function (n, d) { return '<button type="button" class="ps-day" data-day="' + (d + 1) + '" aria-pressed="' + (w.days.indexOf(d + 1) >= 0) + '" aria-label="' + n + '" title="' + n + '">' + n.charAt(0) + '</button>'; }).join('') + '</div></div>' +
      '<div class="ak-fl wide">Hours<div class="ps-time"><input class="ak-in" type="time" data-f="start" aria-label="Starts" value="' + esc(w.start) + '"><span>to</span><input class="ak-in" type="time" data-f="end" aria-label="Ends" value="' + (mid ? '23:59' : esc(w.end)) + '"' + (mid ? ' disabled' : '') + '></div>' +
      '<label class="ak-sw" style="margin-top:6px"><input type="checkbox" data-f="midnight"' + (mid ? ' checked' : '') + '><span>Until midnight</span></label></div>' +
      '<label class="ak-fl">Multiplier<input class="ak-in" type="number" data-f="multiplier" step="0.01" min="0.1" max="5" placeholder="' + esc(S.surge) + '" value="' + esc(w.multiplier != null ? w.multiplier : '') + '"></label>' +
      '<button type="button" class="ak-btn danger" data-rm-win="' + i + '" aria-label="Remove ' + esc(w.label || 'window') + '">🗑️</button></div>';
  }).join('');
  return '<div class="ps-box"><h3>When surge applies</h3><div class="sub">' + (S.isDefault ? 'This is the built-in default — nothing has been saved yet, and it is what is running now. ' : '') + 'Times are Hawassa clock times (24-hour). A window that ends before it starts runs past midnight.</div>' + peakMap() + '</div>' +
    '<div class="ps-box"><h3>Windows</h3><div class="sub">If two windows overlap, the first one in the list wins.</div>' + (rows || K.empty('🕒', 'No windows', 'Without a window, surge never applies.')) +
    '<div class="ak-acts" style="margin-top:6px"><button type="button" class="ak-btn" data-add-win>➕ Add window</button><button type="button" class="ak-btn gold" data-save-win>💾 Save schedule</button></div></div>';
}
function readWindows() {
  document.querySelectorAll('.ps-win').forEach(function (el) {
    var w = S.windows[parseInt(el.getAttribute('data-i'), 10)]; if (!w) return;
    w.label = el.querySelector('[data-f=label]').value.trim(); w.start = el.querySelector('[data-f=start]').value || '00:00';
    w.end = el.querySelector('[data-f=midnight]').checked ? '24:00' : (el.querySelector('[data-f=end]').value || '00:00');
    var m = el.querySelector('[data-f=multiplier]').value.trim(); w.multiplier = m ? parseFloat(m) : null;
  });
}

function goalsView() {
  if (S.err.goals) return K.empty('⚠️', 'Could not load goal tiers', S.err.goals);
  var rows = S.goals.slice().sort(function (a, b) { return (a.sort_order - b.sort_order) || (a.trips_required - b.trips_required); }).map(function (t) {
    return '<tr data-id="' + esc(t.id) + '"><td>' + inp('ps-g', 'trips_required', t.trips_required, 'type="number" min="1" max="200" aria-label="Trips required"') + '</td><td>' + K.sw('ps-g-on-' + t.id, 'Shown', String(t.active) === '1') + '</td>' +
      '<td class="ps-r"><button type="button" class="ak-btn gold" data-save-goal="' + esc(t.id) + '">💾 Save</button> <button type="button" class="ak-btn danger" data-del-goal="' + esc(t.id) + '">🗑️</button></td></tr>';
  }).join('');
  return '<div class="ps-box"><h3>Driver daily goals</h3><div class="sub">The "do N trips today" chips on the driver home screen. Each driver sees their own projected earnings per tier (trips × their own average fare) — you only set the trip counts.</div>' +
    (rows ? '<div class="ak-tblw"><table class="ak-tbl ps-tbl"><thead><tr><th>Trips required today</th><th>Visible</th><th><span class="ps-vh2">Actions</span></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : K.empty('🎯', 'No goal tiers', 'Add the first one below.')) +
    '<div style="display:flex;gap:10px;align-items:flex-end;margin-top:12px;flex-wrap:wrap"><label class="ak-fl">New tier (trips)<input class="ak-in" id="ps-g-new" type="number" min="1" max="200" placeholder="15" style="width:140px"></label><button type="button" class="ak-btn gold" data-add-goal>➕ Add tier</button></div></div>';
}

function deliveryView() {
  var h = '';
  if (S.err.zones) h += K.empty('⚠️', 'Could not load zones', S.err.zones + ' — has migration 007_ride_fare_rules.sql been applied?');
  else {
    var zr = S.zones.map(function (z) {
      return '<tr data-id="' + esc(z.id) + '"><td>' + inp('ps-z', 'name', z.name, 'maxlength="60" aria-label="Zone name"') + '</td><td>' + inp('ps-z', 'base_fare', z.base_fare, 'type="number" min="0" step="1" aria-label="Fee"') + '</td><td>' + inp('ps-z', 'est_minutes', z.est_minutes, 'type="number" min="0" step="1" aria-label="Estimated minutes"') + '</td><td>' + inp('ps-z', 'sort_order', z.sort_order, 'type="number" step="1" aria-label="Sort order"') + '</td>' +
        '<td>' + K.sw('ps-z-on-' + z.id, 'On', String(z.active) === '1') + '</td><td class="ps-r"><button type="button" class="ak-btn gold" data-save-zone="' + esc(z.id) + '">💾</button> <button type="button" class="ak-btn danger" data-del-zone="' + esc(z.id) + '" data-name="' + esc(z.name) + '" aria-label="Switch off ' + esc(z.name) + '">🗑️</button></td></tr>';
    }).join('');
    h += '<div class="ps-box"><h3>Delivery zones</h3><div class="sub">The base fee a customer pays to get delivery to each zone.</div><div class="ak-tools"><span style="flex:1"></span><button type="button" class="ak-btn gold" data-add-zone>➕ Add zone</button></div>' +
      (zr ? '<div class="ak-tblw"><table class="ak-tbl ps-tbl"><thead><tr><th>Zone</th><th>Fee (ETB)</th><th>Est. min</th><th>Sort</th><th>Active</th><th><span class="ps-vh2">Actions</span></th></tr></thead><tbody>' + zr + '</tbody></table></div>' : K.empty('📍', 'No zones yet', 'Add the first delivery zone.')) + '</div>';
  }
  if (S.err.tiers) h += K.empty('⚠️', 'Could not load delivery tiers', S.err.tiers);
  else {
    var tr = S.tiers.map(function (t) {
      return '<tr data-id="' + esc(t.id) + '"><td><b>' + esc(t.tier_key) + '</b></td><td>' + inp('ps-t', 'label', t.label, 'maxlength="40" aria-label="Label"') + '</td><td>' + inp('ps-t', 'multiplier', t.multiplier, 'type="number" min="0.01" step="0.01" aria-label="Multiplier"') + '</td><td>' + inp('ps-t', 'eta_delta_min', t.eta_delta_min, 'type="number" step="1" aria-label="Minutes added"') + '</td><td>' + inp('ps-t', 'sort_order', t.sort_order, 'type="number" step="1" aria-label="Sort order"') + '</td>' +
        '<td>' + K.sw('ps-t-on-' + t.id, 'On', String(t.active) === '1') + '</td><td class="ps-r"><button type="button" class="ak-btn gold" data-save-tier="' + esc(t.id) + '">💾</button></td></tr>';
    }).join('');
    var az = S.zones.filter(function (z) { return String(z.active) === '1'; }), at = S.tiers.filter(function (t) { return String(t.active) === '1'; });
    h += '<div class="ps-box"><h3>Delivery tiers</h3><div class="sub">Moto, car or express cargo. The multiplier is applied on top of the zone fee; the minutes are added to the zone\'s estimate.</div>' +
      (tr ? '<div class="ak-tblw"><table class="ak-tbl ps-tbl"><thead><tr><th>Key</th><th>Label</th><th>Multiplier</th><th>+ minutes</th><th>Sort</th><th>Active</th><th><span class="ps-vh2">Actions</span></th></tr></thead><tbody>' + tr + '</tbody></table></div>' : K.empty('🛵', 'No delivery tiers', 'They are created by migration 012_delivery_tiers.sql.')) + '</div>';
    if (az.length && at.length) {
      h += '<div class="ps-box"><h3>What a customer pays</h3><div class="sub">Zone fee × tier multiplier, for the zones and tiers that are switched on (saved values).</div><div class="ak-tblw"><table class="ak-tbl"><thead><tr><th>Zone</th>' + at.map(function (t) { return '<th class="ps-r">' + esc(t.label) + '</th>'; }).join('') + '</tr></thead><tbody>' +
        az.map(function (z) { return '<tr><td><b>' + esc(z.name) + '</b></td>' + at.map(function (t) { return '<td class="ps-r">' + esc(etb(f(z.base_fare) * f(t.multiplier))) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div></div>';
    }
  }
  return h;
}

var CP_FIELDS = [['base', 'Base pay per order (ETB)', 'number'], ['per_km', 'Pay per km (ETB)', 'number'], ['pickup_cap_km', 'Most km counted to the shop', 'number'], ['min', 'Minimum pay per order (ETB)', 'number'],
  ['road_factor', 'Road distance vs straight line (1 to 2)', 'number'], ['night_mult', 'Night multiplier (1 to 3)', 'number'], ['night_start', 'Night starts at hour (0-23)', 'number'], ['night_end', 'Night ends at hour (0-23)', 'number'],
  ['tip_max', 'Largest tip a customer can add (ETB)', 'number'], ['tip_presets', 'Tip buttons at checkout (ETB, comma separated)', 'text']];
function courierView() {
  if (S.err.courier || !S.cp) return K.empty('⚠️', 'Could not load courier pay', S.err.courier || 'No data.');
  var cs = S.cp.settings || {}, st = S.cp.stats || {};
  var h = '<div class="ps-box"><h3>Courier pay and tips</h3><div class="sub"><b>Off</b>: the courier is paid the delivery fee the customer was charged. <b>On</b>: pay follows the distance and the numbers below.</div>' +
    '<div id="ps-cp-form">' + K.sw('ps-cp-enabled', 'Pay couriers by distance', !!cs.enabled) + '<div class="ak-fg" style="margin-top:12px">' + CP_FIELDS.map(function (x) {
      return '<label class="ak-fl">' + esc(x[1]) + '<input class="ak-in" data-f="' + x[0] + '" ' + (x[2] === 'number' ? 'type="number" step="any" min="0" inputmode="decimal"' : 'type="text"') + ' value="' + esc(cs[x[0]] == null ? '' : cs[x[0]]) + '"></label>';
    }).join('') + '</div></div><div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn gold" data-save-cp>💾 Save</button></div>' +
    '<div class="ak-chips"><span class="ak-chip">Last 7 days · avg delivery fee <b>' + esc(etb(st.avg_fee_7d)) + '</b></span><span class="ak-chip">avg courier pay <b>' + esc(etb(st.avg_pay_7d)) + '</b></span><span class="ak-chip">paid by distance <b>' + esc(K.num(st.paid_by_distance_7d || 0)) + '</b></span><span class="ak-chip">tips <b>' + esc(etb(st.tips_7d)) + '</b></span></div></div>';
  h += '<div class="ps-box"><h3>Try a number</h3><div class="sub">What a courier would be paid for one trip, with the saved settings.</div><div class="ps-ins">' +
    '<label class="ak-fl">Courier to shop (km)<input class="ak-in" id="ps-t-pick" type="number" min="0" step="any" value="1"></label><label class="ak-fl">Shop to customer (km)<input class="ak-in" id="ps-t-drop" type="number" min="0" step="any" value="2"></label>' +
    '<label class="ak-fl">Vehicle<select class="ak-in" id="ps-t-tier"><option value="1">Moto × 1.00</option><option value="1.12">Car × 1.12</option><option value="1.6">Cargo × 1.60</option></select></label>' +
    '<div>' + K.sw('ps-t-night', 'Night', false) + '</div><button type="button" class="ak-btn gold" data-cp-calc>Calculate</button></div><div class="ps-note" id="ps-t-out" aria-live="polite">' + S.cpOut + '</div></div>';
  var o = S.order;
  h += '<div class="ps-box"><h3>Change the pay of one order</h3><div class="sub">Look an order up, then set what its courier gets. Every change is logged with your reason.</div>' +
    '<div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><label class="ak-fl">Order code<input class="ak-in" id="ps-o-code" placeholder="ETD-XXXXXX" value="' + esc(o ? o.order.order_code : '') + '" style="width:200px"></label><button type="button" class="ak-btn" data-cp-find>Find</button></div>';
  if (o && o.order) {
    var x = o.order;
    h += '<div style="margin-top:12px"><div><b>' + esc(x.order_code) + '</b> · ' + esc(x.status) + ' · courier ' + esc(x.courier_name || 'none') + '</div><div class="ps-note">Customer paid ' + esc(etb(x.fare_total)) + ' (delivery fee ' + esc(etb(x.delivery_fee)) + ', tip ' + esc(etb(x.courier_tip)) + ')</div>' +
      '<div class="ps-note">Courier gets <b>' + esc(etb(o.courier_gets)) + '</b> (pay from ' + esc(o.pay_source) + (x.courier_pay_note ? ': ' + esc(x.courier_pay_note) : '') + ' + tip)</div>' +
      '<div class="ak-fg" style="margin-top:10px"><label class="ak-fl">New pay before tip (ETB; empty = back to automatic)<input class="ak-in" id="ps-o-amt" type="number" min="0" max="2000" step="any"></label><label class="ak-fl">Reason (saved in the log)<input class="ak-in" id="ps-o-why" maxlength="200"></label></div>' +
      '<div class="ak-acts" style="margin-top:10px"><button type="button" class="ak-btn gold" data-cp-set="' + esc(x.id) + '">Save for this order</button></div>' +
      (o.log || []).map(function (l) { return '<div class="ps-note">' + esc(l.created_at) + ' · ' + esc(l.admin_name || 'admin') + ': ' + (l.old_pay == null ? '—' : esc(l.old_pay)) + ' → ' + (l.new_pay == null ? 'cleared' : esc(l.new_pay)) + ' · ' + esc(l.reason) + '</div>'; }).join('') + '</div>';
  } else if (S.cpOrderMsg) h += '<div class="ps-note">' + esc(S.cpOrderMsg) + '</div>';
  return h + '</div>';
}

function banksView() {
  if (S.err.banks) return K.empty('⚠️', 'Could not load bank accounts', S.err.banks + ' — has migration 014_bank_accounts.sql been applied?');
  if (!S.banks.length) return K.empty('🏦', 'No bank accounts', 'They are created by migration 014_bank_accounts.sql.');
  return '<div class="ps-note" style="margin:0 0 10px">Customers see these accounts when they pay by bank transfer. Switching one off removes that option from checkout.</div><div class="ps-banks">' + S.banks.map(function (a) {
    var on = String(a.active) === '1';
    return '<div class="ps-v' + (on ? '' : ' off') + '" data-id="' + esc(a.id) + '"><div class="ps-vh"><div class="ps-pic">🏦</div><div style="flex:1;min-width:0"><b>' + esc(a.bank_key) + '</b><small>Bank transfer</small></div>' + K.pill(on ? 'On' : 'Off', on ? 'green' : 'grey') + '</div>' +
      '<div style="display:grid;gap:8px"><label class="ak-fl">Label shown to the customer' + inp('ps-b', 'display_name', a.display_name, 'maxlength="60"') + '</label><label class="ak-fl">Account name' + inp('ps-b', 'account_name', a.account_name, 'maxlength="80"') + '</label><label class="ak-fl">Account number' + inp('ps-b', 'account_number', a.account_number, 'maxlength="40" inputmode="numeric"') + '</label></div>' +
      '<div style="margin-top:10px">' + K.sw('ps-b-on-' + a.id, 'Offered at checkout', on) + '</div><div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn gold" data-save-bank="' + esc(a.id) + '">💾 Save</button></div></div>';
  }).join('') + '</div>';
}

function paint() {
  var el = root(); if (!el || !S.loaded) return;
  var vis = TABS.filter(function (t) { return can(t[2]); });
  if (!vis.length) { el.innerHTML = '<div class="ak">' + K.empty('🔒', 'No pricing access', 'Your role cannot see any pricing settings.') + '</div>'; return; }
  var act = S.rules.filter(function (r) { return String(r.active) === '1'; }).length, az = S.zones.filter(function (z) { return String(z.active) === '1'; }).length;
  var bigs = [];
  if (can('pricing.manage')) bigs.push(['Vehicle types on', K.num(act)], ['Default surge', '× ' + S.surge], ['Peak windows', K.num(S.windows.length)]);
  if (can('settings.view')) bigs.push(['Delivery zones', K.num(az)]);
  var body = S.tab === 'peak' ? peakView() : S.tab === 'goals' ? goalsView() : S.tab === 'delivery' ? deliveryView() : S.tab === 'banks' ? banksView() : S.tab === 'courier' ? courierView() : faresView();
  el.innerHTML = '<div class="ak">' + K.hero('Pricing', 'What riders, customers and drivers pay — fares, surge, delivery fees and the bank accounts shown at checkout. Changes apply to new trips and orders as soon as you save.', bigs) +
    '<div class="ps-tabs"><div class="ak-seg" role="group" aria-label="Section">' + vis.map(function (t) { return '<button type="button" data-tab="' + t[0] + '" aria-pressed="' + (S.tab === t[0]) + '" class="' + (S.tab === t[0] ? 'on' : '') + '">' + esc(t[1]) + '</button>'; }).join('') + '</div><span style="flex:1"></span><button type="button" class="ak-btn" data-refresh aria-label="Refresh">🔄</button></div>' + body + '</div>';
}

/* ---------- saving ---------- */
function done(res, msg, reload) {
  if (K.okRes(res)) { K.toast(msg); if (reload !== false) return loadAll(); } else K.toast(K.failText(res, 'Could not save.'), true);
}
function vals(el, sel) { var o = {}; el.querySelectorAll(sel || 'input[data-f]').forEach(function (i) { if (i.type === 'checkbox') return; o[i.getAttribute('data-f')] = i.type === 'number' ? f(i.value) : i.value.trim(); }); return o; }
function sw(el) { var c = el.querySelector('.ak-sw input'); return c && c.checked ? 1 : 0; }
function byId(list, id, key) { var r = null; list.forEach(function (x) { if (String(x[key || 'id']) === String(id)) r = x; }); return r; }

function addVehicleDrawer() {
  var dr = K.openDrawer('<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🚘</div><div class="ak-nm"><b>New vehicle type</b><small>Riders can pick it once you save.</small></div>',
    '<form id="ps-nv" autocomplete="off" novalidate><div class="ak-sec"><h4>Name</h4>' + K.field('Name', '<input id="ps-nv-name" class="ak-in" maxlength="40" placeholder="Tricycle">', 'Spaces and capitals are fine — it is saved as a plain identifier.') + '<div class="ps-note" id="ps-nv-slug" aria-live="polite"></div></div>' +
    '<div class="ak-sec"><h4>Starting prices</h4><div class="ak-fg">' + K.field('Base fare (ETB)', '<input id="ps-nv-base" class="ak-in" type="number" min="0" step="0.01">') + K.field('Per km (ETB)', '<input id="ps-nv-km" class="ak-in" type="number" min="0" step="0.01">') + '</div><div class="ps-note">Open-trip rate, waiting and minimums start from sensible defaults; adjust them on the card afterwards.</div></div></form>',
    '<span class="ak-err" id="ps-nv-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-go>Add vehicle type</button>');
  var nm = dr.querySelector('#ps-nv-name'), sl = dr.querySelector('#ps-nv-slug');
  nm.addEventListener('input', function () { var s = slug(nm.value); sl.textContent = nm.value.trim() ? (s.length >= 2 ? 'Will be saved as "' + s + '"' : 'Needs at least 2 letters or numbers') : ''; });
  dr.addEventListener('click', function (e) {
    if (!e.target.closest('[data-go]')) return;
    var s = slug(nm.value), b = f(dr.querySelector('#ps-nv-base').value), k = f(dr.querySelector('#ps-nv-km').value), err = dr.querySelector('#ps-nv-err');
    if (!/^[a-z][a-z0-9_]{1,19}$/.test(s)) { err.textContent = 'Type a name with at least 2 letters or numbers.'; nm.focus(); return; }
    if (b <= 0 || k <= 0) { err.textContent = 'Enter a base fare and a per-km rate above 0.'; return; }
    run('admin_add_vehicle_type', { vehicle_type: s, base_fare: b, per_km_rate: k }).then(function (res) { if (K.okRes(res)) { K.closeDrawer(); done(res, vlabel(s) + ' added'); } else err.textContent = K.failText(res, 'Could not add it.'); });
  });
  nm.focus();
}
function addZoneDrawer() {
  var dr = K.openDrawer('<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">📍</div><div class="ak-nm"><b>New delivery zone</b><small>Shown to customers at checkout.</small></div>',
    '<form autocomplete="off" novalidate><div class="ak-sec"><div class="ak-fg">' + K.field('Zone name', '<input id="ps-nz-name" class="ak-in" maxlength="60">') + K.field('Fee (ETB)', '<input id="ps-nz-fee" class="ak-in" type="number" min="0" step="1">') + K.field('Estimated minutes', '<input id="ps-nz-min" class="ak-in" type="number" min="0" step="1" placeholder="20">') + '</div></div></form>',
    '<span class="ak-err" id="ps-nz-err" role="alert"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-go>Add zone</button>');
  dr.addEventListener('click', function (e) {
    if (!e.target.closest('[data-go]')) return;
    var name = dr.querySelector('#ps-nz-name').value.trim(), err = dr.querySelector('#ps-nz-err');
    if (!name) { err.textContent = 'Enter a zone name.'; dr.querySelector('#ps-nz-name').focus(); return; }
    run('admin_add_zone', { name: name, base_fare: f(dr.querySelector('#ps-nz-fee').value), est_minutes: f(dr.querySelector('#ps-nz-min').value) || 20 }).then(function (res) { if (K.okRes(res)) { K.closeDrawer(); done(res, 'Zone added'); } else err.textContent = K.failText(res, 'Could not add it.'); });
  });
  dr.querySelector('#ps-nz-name').focus();
}

function onClick(e) {
  var t = e.target, b, el;
  if ((b = t.closest('[data-tab]'))) { if (S.tab === 'peak') readWindows(); S.tab = b.getAttribute('data-tab'); paint(); return; }
  if (t.closest('[data-refresh]')) { loadAll(); return; }
  if (t.closest('[data-add-vt]')) { addVehicleDrawer(); return; }
  if (t.closest('[data-add-zone]')) { addZoneDrawer(); return; }
  if ((b = t.closest('[data-save-vt]'))) {
    var vt = b.getAttribute('data-save-vt'); el = b.closest('.ps-v'); var body = vals(el); body.vehicle_type = vt; body.active = sw(el);
    run('admin_update_ride_fare_rule', body).then(function (r) { return done(r, vlabel(vt) + ' saved'); }); return;
  }
  if ((b = t.closest('[data-del-vt]'))) {
    var dv = b.getAttribute('data-del-vt');
    K.confirm({ title: 'Switch off ' + vlabel(dv) + '?', text: 'It stops being offered for new rides. Drivers already registered with it and past rides keep their history.', label: 'Switch off', danger: true })
      .then(function (r) { if (r) run('admin_delete_vehicle_type', { vehicle_type: dv }).then(function (res) { return done(res, vlabel(dv) + ' switched off'); }); }); return;
  }
  if (t.closest('[data-save-surge]')) { run('admin_update_ride_surge_multiplier', { value: f(document.getElementById('ps-surge').value) }).then(function (r) { return done(r, 'Surge multiplier saved'); }); return; }
  if ((b = t.closest('.ps-day'))) { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); var w = S.windows[parseInt(b.closest('.ps-win').getAttribute('data-i'), 10)]; if (w) { var d = parseInt(b.getAttribute('data-day'), 10), ix = w.days.indexOf(d); if (ix < 0) w.days.push(d); else w.days.splice(ix, 1); } refreshMap(); return; }
  if (t.closest('[data-add-win]')) { readWindows(); S.windows.push({ label: 'New window', days: [1, 2, 3, 4, 5, 6, 7], start: '00:00', end: '01:00', multiplier: S.surge }); paint(); return; }
  if ((b = t.closest('[data-rm-win]'))) { readWindows(); S.windows.splice(parseInt(b.getAttribute('data-rm-win'), 10), 1); paint(); return; }
  if (t.closest('[data-save-win]')) {
    readWindows();
    if (!S.windows.length) { K.toast('Add at least one window, or surge will never apply.', true); return; }
    run('admin_update_ride_peak_windows', { windows: S.windows }).then(function (r) { return done(r, 'Peak-hour schedule saved'); }); return;
  }
  if ((b = t.closest('[data-save-goal]'))) { el = b.closest('tr'); var gb = vals(el); gb.id = parseInt(b.getAttribute('data-save-goal'), 10); gb.active = sw(el); run('admin_save_goal_tier', gb).then(function (r) { return done(r, 'Goal tier saved'); }); return; }
  if (t.closest('[data-add-goal]')) { var n = f(document.getElementById('ps-g-new').value); if (n < 1) { K.toast('Enter how many trips (1 or more).', true); return; } run('admin_save_goal_tier', { trips_required: n, active: 1 }).then(function (r) { return done(r, 'Goal tier added'); }); return; }
  if ((b = t.closest('[data-del-goal]'))) { var gid = parseInt(b.getAttribute('data-del-goal'), 10); K.confirm({ title: 'Delete this goal tier?', text: 'Drivers viewing it fall back to the next tier on their next refresh.', label: 'Delete', danger: true }).then(function (r) { if (r) run('admin_delete_goal_tier', { id: gid }).then(function (res) { return done(res, 'Goal tier deleted'); }); }); return; }
  if ((b = t.closest('[data-save-zone]'))) { el = b.closest('tr'); var zb = vals(el); zb.id = parseInt(b.getAttribute('data-save-zone'), 10); zb.active = sw(el); if (!zb.name) { K.toast('Zone name cannot be empty.', true); return; } run('admin_update_zone', zb).then(function (r) { return done(r, 'Zone saved'); }); return; }
  if ((b = t.closest('[data-del-zone]'))) { var zid = parseInt(b.getAttribute('data-del-zone'), 10), zn = b.getAttribute('data-name'); K.confirm({ title: 'Switch off "' + zn + '"?', text: 'It stops appearing to customers; past orders keep their history.', label: 'Switch off', danger: true }).then(function (r) { if (r) run('admin_delete_zone', { id: zid }).then(function (res) { return done(res, 'Zone switched off'); }); }); return; }
  if ((b = t.closest('[data-save-tier]'))) { el = b.closest('tr'); var tb = vals(el); tb.id = parseInt(b.getAttribute('data-save-tier'), 10); tb.active = sw(el); if (!tb.label) { K.toast('Label cannot be empty.', true); return; } if (!(tb.multiplier > 0)) { K.toast('Multiplier must be above 0.', true); return; } run('admin_update_delivery_tier', tb).then(function (r) { return done(r, 'Tier saved'); }); return; }
  if (t.closest('[data-save-cp]')) {
    var cf = document.getElementById('ps-cp-form'), cb = vals(cf); cb.enabled = sw(cf);
    run('admin_save_courier_pay', cb).then(function (res) { if (K.okRes(res)) { S.cp = K.data(res).settings ? { settings: K.data(res).settings, stats: S.cp.stats } : S.cp; K.toast(S.cp.settings.enabled ? 'Saved — couriers are paid by distance' : 'Saved — pay by distance is off, couriers get the delivery fee'); paint(); } else K.toast(K.failText(res, 'Could not save.'), true); }); return;
  }
  if (t.closest('[data-cp-calc]')) {
    var out = document.getElementById('ps-t-out'); out.textContent = '…';
    run('admin_preview_courier_pay', { pickup_km: document.getElementById('ps-t-pick').value, drop_km: document.getElementById('ps-t-drop').value, night: document.getElementById('ps-t-night').checked, tier: document.getElementById('ps-t-tier').value }).then(function (res) {
      if (!K.okRes(res)) { S.cpOut = K.failText(res, 'Could not calculate.'); out.textContent = S.cpOut; return; }
      var d = K.data(res), x = d.result;
      S.cpOut = 'ETB ' + x.pay + ' — base ' + x.base_part + ' + distance ' + x.distance_part + ' (' + x.km_total + ' km: ' + x.km_pickup + ' to the shop + ' + x.km_drop + ' to the customer)' + (x.night > 1 ? ' × night ' + x.night : '') + (x.tier != 1 ? ' × vehicle ' + x.tier : '') + (x.minimum_applied ? ' (minimum applied)' : '') + (d.settings_on ? '' : '. Pay by distance is off, so real orders still pay the delivery fee.');
      out.textContent = S.cpOut;
    }); return;
  }
  if (t.closest('[data-cp-find]')) {
    run('admin_get_order_pay', { order_code: document.getElementById('ps-o-code').value }).then(function (res) { if (K.okRes(res)) { S.order = K.data(res); S.cpOrderMsg = ''; } else { S.order = null; S.cpOrderMsg = K.failText(res, 'Order not found.'); } paint(); }); return;
  }
  if ((b = t.closest('[data-cp-set]'))) {
    var oid = parseInt(b.getAttribute('data-cp-set'), 10), code = S.order && S.order.order.order_code;
    run('admin_set_order_pay', { order_id: oid, amount: document.getElementById('ps-o-amt').value, reason: document.getElementById('ps-o-why').value }).then(function (res) {
      if (!K.okRes(res)) { K.toast(K.failText(res, 'Could not save.'), true); return; }
      K.toast('Saved for this order'); return run('admin_get_order_pay', { order_code: code }).then(function (r2) { if (K.okRes(r2)) S.order = K.data(r2); paint(); });
    }); return;
  }
  if ((b = t.closest('[data-save-bank]'))) { el = b.closest('.ps-v'); var bb = vals(el); bb.id = parseInt(b.getAttribute('data-save-bank'), 10); bb.active = sw(el); if (!bb.display_name) { K.toast('Label cannot be empty.', true); return; } if (!bb.account_number) { K.toast('Account number cannot be empty.', true); return; } run('admin_update_bank_account', bb).then(function (r) { return done(r, 'Bank account saved'); }); }
}
function refreshMap() { var m = document.querySelector('.ps-map'); if (m) { var tmp = document.createElement('div'); tmp.innerHTML = peakMap(); m.parentNode.replaceChild(tmp.firstChild, m); var lg = document.querySelector('.ps-legend'); if (lg && tmp.firstChild) lg.parentNode.replaceChild(tmp.firstChild, lg); } }
function onInput(e) {
  var t = e.target, card = t.closest && t.closest('.ps-v');
  if (card) card.classList.add('dirty');
  if (t.id === 'ps-c-vt') S.calc.vt = t.value;
  else if (t.id === 'ps-c-km') S.calc.km = t.value;
  else if (t.id === 'ps-c-wait') S.calc.wait = t.value;
  else if (t.id === 'ps-c-open') S.calc.open = t.checked;
  else if (t.id === 'ps-c-peak') S.calc.peak = t.checked;
  else if (t.closest && t.closest('.ps-win')) {
    var win = t.closest('.ps-win');
    if (t.getAttribute('data-f') === 'midnight') { var endEl = win.querySelector('[data-f=end]'); endEl.disabled = t.checked; if (t.checked) endEl.value = '23:59'; }
    readWindows(); refreshMap(); return;
  }
  else if (!card) return;
  var co = document.getElementById('ps-calc-out'); if (co) co.innerHTML = calcOut();
}

function install() {
  var el = root(); if (!el) return;
  if (!el._ps) { el._ps = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); el.addEventListener('change', onInput);
    el.addEventListener('error', function (e) { var i = e.target; if (i && i.tagName === 'IMG' && i.hasAttribute('data-emoji')) i.replaceWith(document.createTextNode(i.getAttribute('data-emoji'))); }, true); }
  window.ETELPricing = { loadPricing: function () { return loadAll(); } };
  window.loadPricing = window.ETELPricing.loadPricing;
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
