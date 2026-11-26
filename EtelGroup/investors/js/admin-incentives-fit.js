/* Investors admin - "Fit my budget" box for the Incentive planner.
   You type how much money you have, for how many months, the average fare and the active drivers.
   It fills the planner boxes with a plan that costs no more than 90 % of the budget (10 % is kept spare),
   with at least 10 agents. Nothing is saved on the server. Needs admin-incentives.js (v6 or newer). */
(function () {
'use strict';
var KEY = 'ir_incentive_fit_v1';
var RESERVE = 0.10;          // share of the budget kept spare
var AGENTS = 10;             // the plan: at least 10 agents
var COMPLETION = 0.9;        // share of agent trips that are completed
var DISCOUNT_PCT = 10;       // new-rider discount, % of the fare
var DAYS = 30;               // a month is 30 days in the planner
function $(id) { return document.getElementById(id); }
function num(x, d) { var n = Number(x); return isFinite(n) && n >= 0 ? n : d; }
function whole(n) { return Math.round(Number(n || 0)).toLocaleString('en-US'); }

// Pure: returns { ok, note, s, spendMonth, spare, ... }. s has the planner's own shape (show, settle, v, u, p).
function fit(o) {
  var D = window.irIncentiveDefaults;
  if (typeof D !== 'function') return { ok: false, note: 'The planner file is old. Upload admin-incentives.js (v6) too.' };
  var budget = num(o.budget, 0), months = Math.min(6, Math.max(1, Math.round(num(o.months, 2) || 2)));
  var fare = num(o.fare, 0) || 275, drivers = Math.floor(num(o.drivers, 0));
  if (budget < 20000) return { ok: false, note: 'The budget is too small for 10 agents. Use at least ETB 20,000.' };
  var spend = budget * (1 - RESERVE) / months;                                   // ETB we may spend each month
  var fee = spend < 55000 ? 30 : (spend < 75000 ? 35 : 40);                       // agent fee per completed trip
  var other = Math.floor(spend * 0.08 / 100) * 100;                               // posters, social media
  var refN = spend < 55000 ? 5 : 8, refCost = refN * 100;                         // driver referrals, ETB 100 each
  var bonusTarget = drivers > 0 ? spend * (spend < 55000 ? 0.20 : 0.27) : 0;
  var s1 = 0, s2 = 0, bonus = 0;
  if (drivers > 0) {
    s1 = Math.min(40, Math.floor(bonusTarget / (drivers * 30 * 26) * 100));      // tier 1: 5 trips a day pays ETB 30
    bonus = drivers * (s1 / 100) * 30 * 26;
    s2 = Math.min(15, Math.floor(Math.max(0, bonusTarget - bonus) / (drivers * 80 * 26) * 100)); // tier 2: 10 trips pays ETB 80
    bonus += drivers * (s2 / 100) * 80 * 26;
  }
  var perRider = fee + DISCOUNT_PCT / 100 * fare;                                 // cost of one new rider (fee + discount)
  var left = spend - other - refCost - bonus;
  var trips = Math.floor(left / (AGENTS * DAYS * COMPLETION * perRider) * 10) / 10; // new-user trips one agent orders each day
  var s = D();
  var v = s.v, u = s.u, p = s.p;
  v.months = months; v.agents = AGENTS; v.agent_trips = Math.max(0, trips); p.agent_trips = 'day';
  v.completion_pct = COMPLETION * 100; v.agent_per_trip = fee; u.agent_per_trip = 'etb'; v.agent_base = 0;
  v.other_marketing = other; p.other_marketing = 'month'; v.drivers_per_agent = 0;
  v.drivers_active = drivers; v.work_days = 26;
  v.t1_trips = 5; v.t1_bonus = 30; u.t1_bonus = 'etb'; v.t1_share = s1;
  v.t2_trips = 10; v.t2_bonus = 80; u.t2_bonus = 'etb'; v.t2_share = s2;
  v.t3_trips = 15; v.t3_bonus = 150; u.t3_bonus = 'etb'; v.t3_share = 0;
  v.ref_reward = 100; u.ref_reward = 'etb'; v.ref_per_month = refN; p.ref_per_month = 'month';
  v.promo_discount = DISCOUNT_PCT; u.promo_discount = 'pct'; v.promo_rides = 1; v.promo_cap = 0; v.avg_fare = fare; v.new_riders = 0;
  var out = { ok: true, s: s, fee: fee, trips: v.agent_trips, spend: spend, budget: budget, months: months, fare: fare, drivers: drivers, note: '' };
  if (trips < 1) out.note = 'This budget gives each agent fewer than 1 new-user trip a day. Think about a longer budget, fewer months, or a smaller bonus.';
  if (typeof window.irIncentiveCalc === 'function') {
    var c = window.irIncentiveCalc(s);
    out.spendMonth = c.total; out.spendAll = c.total * months; out.spare = budget - c.total * months; out.riders = c.newUsers; out.perRider = c.costPerRider;
  }
  return out;
}
window.irFitPlan = fit;

function loadBox() {
  var d = { budget: 150000, months: 2, fare: 275, drivers: 50 }, s = {};
  try { s = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { s = {}; }
  Object.keys(d).forEach(function (k) { if (typeof s[k] === 'number' && s[k] >= 0) d[k] = s[k]; });
  return d;
}
function readBox() { var o = {}; ['budget', 'months', 'fare', 'drivers'].forEach(function (k) { o[k] = Number(($('ipf-' + k) || {}).value); }); return o; }

function apply() {
  var o = readBox(), msg = $('ipf-msg'), r = fit(o);
  try { localStorage.setItem(KEY, JSON.stringify(o)); } catch (e) { /* not remembered */ }
  if (!r.ok) { msg.style.color = 'var(--bad,#ff8a7a)'; msg.textContent = r.note; return; }
  var s = r.s;
  Object.keys(s.v).forEach(function (k) {
    var el = $('ip-' + k); if (el) el.value = s.v[k];
    var uu = $('ipu-' + k); if (uu && s.u[k]) uu.value = s.u[k];
    var pp = $('ipp-' + k); if (pp && s.p[k]) pp.value = s.p[k];
  });
  var sh = $('ip-show'); if (sh) sh.value = 'month';
  var st = $('ip-settle'); if (st) st.value = 'week';
  var host = $('ip-fields');
  if (host) host.dispatchEvent(new Event('input', { bubbles: true }));            // the planner repaints and saves
  var lasts = r.spendMonth > 0 ? (r.budget / r.spendMonth) : 0;
  msg.style.color = '';
  msg.innerHTML = '<b>Plan set below.</b> ' + AGENTS + ' agents, each ordering about ' + r.trips + ' new-user trips a day (fee ETB ' + r.fee + ' per completed trip), ' + DISCOUNT_PCT + ' % new-rider discount, driver bonuses and referrals.' +
    ' About <b>ETB ' + whole(r.spendMonth) + ' a month</b>, <b>ETB ' + whole(r.spendAll) + '</b> over ' + r.months + ' months, <b>ETB ' + whole(r.spare) + '</b> kept spare. The money lasts about <b>' + lasts.toFixed(1) + ' months</b> at this speed.' +
    ' Cost to win one new rider: ETB ' + whole(r.perRider) + '.' + (r.note ? ' <span style="color:var(--bad,#ff8a7a)">' + r.note + '</span>' : '') +
    ' <small>These are assumptions. Change any box below to try another plan.</small>';
}

function build() {
  var host = $('ip-fields');
  if (!host || !host.getAttribute('data-built') || $('ipf-box')) return;
  if (typeof window.irIncentiveDefaults !== 'function') return;                    // old planner file: leave the page as it was
  var d = loadBox(), box = document.createElement('div');
  box.id = 'ipf-box'; box.style.cssText = 'margin:0 0 14px;padding:12px 14px;border:1px solid var(--border,rgba(255,255,255,.12));border-radius:10px';
  function f(k, label, hint) { return '<div class="cf-field"><label>' + label + '</label><input type="number" id="ipf-' + k + '" min="0" step="any" value="' + d[k] + '" title="' + hint + '" style="width:100%"></div>'; }
  box.innerHTML = '<div class="section-title" style="font-size:14px;margin:0 0 6px">Fit my budget</div>' +
    '<div class="desc" style="margin-bottom:8px">Type the money you have. The planner fills every box below with a plan that uses at most 90 % of it. 10 % is kept spare. You can still change any box.</div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">' +
    f('budget', 'My budget (ETB)', 'All the money you can spend, for example 100000 to 200000') + f('months', 'Months', 'How long it must last (1 to 6)') +
    f('fare', 'Average fare (ETB)', 'For example 200 to 350') + f('drivers', 'Active drivers', 'Drivers who can earn the daily bonus') + '</div>' +
    '<button class="btn btn-sm" id="ipf-go" type="button" style="margin-top:10px">Fit my budget</button>' +
    '<div id="ipf-msg" class="desc" style="margin-top:8px" aria-live="polite"></div>';
  host.parentNode.insertBefore(box, host);
  $('ipf-go').addEventListener('click', apply);
}
document.addEventListener('click', function (e) {
  if (e.target.closest && (e.target.closest('#irx-nav a[data-page="incentives"]') || e.target.closest('[data-go="incentives"]'))) setTimeout(build, 0);
});
if (location.hash === '#incentives') setTimeout(build, 900);
})();
