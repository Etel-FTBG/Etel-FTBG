/* Investors admin - Incentive & marketing planner.
   Assumption-based, like the Growth planner: set the number of marketing agents, driver bonuses, referral rewards and rider (customer) promotions,
   and see what each costs per month, who gets what, how long the launch budget lasts, and the cost of winning one driver or one rider.
   Everything is calculated in the browser from the numbers typed here; the launch budget lines are read from the Launch period page. Nothing is saved on the server. */
(function () {
'use strict';
var KEY = 'ir_incentive_plan_v1';
var DEFAULTS = {
  months: 3,
  agents: 5, agent_base: 3000, agent_per_driver: 200, agent_per_rider: 10, drivers_per_agent: 8, riders_per_agent: 60, other_marketing: 20000,
  drivers_active: 60, work_days: 26,
  t1_trips: 5, t1_bonus: 30, t1_share: 50, t2_trips: 10, t2_bonus: 80, t2_share: 25, t3_trips: 15, t3_bonus: 150, t3_share: 10,
  ref_reward: 100, ref_per_month: 10,
  new_riders: 400, promo_discount: 30, promo_rides: 2, avg_fare: 150
};
var FIELDS = [
  ['months', 'Months to plan', 'Launch length (the launch rule allows up to 90 days)'],
  ['agents', 'Marketing agents', 'People you pay to sign up drivers and riders'],
  ['agent_base', 'Base pay per agent / month (ETB)', 'Fixed monthly pay'],
  ['agent_per_driver', 'Commission per driver signed (ETB)', 'Paid once per active driver'],
  ['agent_per_rider', 'Commission per rider signed (ETB)', 'Paid once per new rider'],
  ['drivers_per_agent', 'Drivers signed per agent / month', ''],
  ['riders_per_agent', 'Riders signed per agent / month', ''],
  ['other_marketing', 'Other marketing / month (ETB)', 'Posters, radio, social media, events'],
  ['drivers_active', 'Active drivers', 'Drivers who can earn the daily bonus'],
  ['work_days', 'Working days / month', ''],
  ['t1_trips', 'Bonus tier 1: trips in a day', ''], ['t1_bonus', 'Tier 1 bonus (ETB)', ''], ['t1_share', 'Share of drivers reaching tier 1 (%)', ''],
  ['t2_trips', 'Bonus tier 2: trips in a day', ''], ['t2_bonus', 'Tier 2 bonus (ETB)', ''], ['t2_share', 'Share reaching tier 2 (%)', ''],
  ['t3_trips', 'Bonus tier 3: trips in a day', ''], ['t3_bonus', 'Tier 3 bonus (ETB)', ''], ['t3_share', 'Share reaching tier 3 (%)', 'Tier shares are shares of ALL active drivers, on a typical day'],
  ['ref_reward', 'Driver referral reward (ETB)', 'Paid when a referred driver completes the required trips'], ['ref_per_month', 'Qualified referrals / month', ''],
  ['new_riders', 'New riders / month', 'Customers using the app for the first time'], ['promo_discount', 'Discount per promo ride (ETB)', ''],
  ['promo_rides', 'Promo rides per new rider', 'How many first rides get the discount'], ['avg_fare', 'Average fare (ETB)', 'Used for the cost per ride and the share of each fare']
];
var GROUPS = [
  ['Plan', ['months']],
  ['Marketing agents', ['agents', 'agent_base', 'agent_per_driver', 'agent_per_rider', 'drivers_per_agent', 'riders_per_agent', 'other_marketing']],
  ['Driver bonuses', ['drivers_active', 'work_days', 't1_trips', 't1_bonus', 't1_share', 't2_trips', 't2_bonus', 't2_share', 't3_trips', 't3_bonus', 't3_share']],
  ['Driver referrals', ['ref_reward', 'ref_per_month']],
  ['Customer (rider) promotions', ['new_riders', 'promo_discount', 'promo_rides', 'avg_fare']]
];
var BUDGET = {};                 // launch budget lines from the Launch period page: { marketing: 100000, ... }
function $(id) { return document.getElementById(id); }
function esc(s) { return window.irEsc ? window.irEsc(s) : String(s); }
function money(n) { return window.irMoney ? window.irMoney(n) : Number(n || 0).toFixed(2); }
function whole(n) { return Math.round(Number(n || 0)).toLocaleString('en-US'); }
function load() { var v = {}; try { v = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { v = {}; } var o = {}; Object.keys(DEFAULTS).forEach(function (k) { o[k] = typeof v[k] === 'number' ? v[k] : DEFAULTS[k]; }); return o; }
function save(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* not remembered */ } }
function read() { var v = {}; Object.keys(DEFAULTS).forEach(function (k) { var el = $('ip-' + k); var n = el ? Number(el.value) : DEFAULTS[k]; v[k] = isFinite(n) && n >= 0 ? n : 0; }); return v; }

function calc(v) {
  var m = Math.max(1, v.months);
  var agentBase = v.agents * v.agent_base;
  var driversWon = v.agents * v.drivers_per_agent, ridersWon = v.agents * v.riders_per_agent;
  var agentComm = driversWon * v.agent_per_driver + ridersWon * v.agent_per_rider;
  var agentsMonthly = agentBase + agentComm;
  var marketingMonthly = agentsMonthly + v.other_marketing;
  var tiers = [[v.t1_bonus, v.t1_share], [v.t2_bonus, v.t2_share], [v.t3_bonus, v.t3_share]];
  var driverBonusMonthly = tiers.reduce(function (a, t) { return a + v.drivers_active * (t[1] / 100) * t[0] * v.work_days; }, 0);
  var refMonthly = v.ref_reward * v.ref_per_month;
  var promoMonthly = v.new_riders * v.promo_discount * v.promo_rides;
  var total = marketingMonthly + driverBonusMonthly + refMonthly + promoMonthly;
  return {
    m: m, agentBase: agentBase, agentComm: agentComm, agentsMonthly: agentsMonthly, other: v.other_marketing, marketingMonthly: marketingMonthly,
    driverBonusMonthly: driverBonusMonthly, refMonthly: refMonthly, promoMonthly: promoMonthly, total: total, driversWon: driversWon, ridersWon: ridersWon,
    perAgent: v.agents ? agentsMonthly / v.agents : 0,
    perDriverBonus: v.drivers_active ? driverBonusMonthly / v.drivers_active : 0,
    perRider: v.new_riders ? (v.promo_discount * v.promo_rides) : 0,
    costPerDriver: (driversWon + v.ref_per_month) > 0 ? (agentBase * (driversWon / Math.max(1, driversWon + ridersWon)) + driversWon * v.agent_per_driver + refMonthly) / (driversWon + v.ref_per_month) : 0,
    costPerRider: (ridersWon + v.new_riders) > 0 ? (agentBase * (ridersWon / Math.max(1, driversWon + ridersWon)) + ridersWon * v.agent_per_rider + promoMonthly) / (ridersWon + v.new_riders) : 0
  };
}

function paint() {
  var out = $('ip-output'); if (!out) return;
  var v = read(); save(v);
  var c = calc(v), m = c.m;
  var lines = [
    ['Marketing agents and marketing', 'marketing', c.marketingMonthly, 'Agent pay + commissions + other marketing'],
    ['Driver daily bonuses', 'bonus', c.driverBonusMonthly, 'Tiered bonus for busy days'],
    ['Driver referral rewards', 'referral', c.refMonthly, 'Paid per qualified referral'],
    ['Customer promotions', 'promotion', c.promoMonthly, 'Discounts on the first rides']
  ];
  var haveBudget = Object.keys(BUDGET).length > 0;
  var rows = lines.map(function (l) {
    var tot = l[2] * m, b = BUDGET[l[1]], runway = (b != null && l[2] > 0) ? b / l[2] : null;
    var status = b == null ? '-' : (tot <= b ? '<span style="color:var(--ok,#2fb36b)">Within budget</span>' : '<span style="color:var(--bad)">Over by ETB ' + whole(tot - b) + '</span>');
    return '<tr><td><b>' + esc(l[0]) + '</b><br><small>' + esc(l[3]) + '</small></td><td class="ir-num">' + money(l[2]) + '</td><td class="ir-num">' + money(tot) + '</td><td class="ir-num">' + (b == null ? '-' : money(b)) + '</td><td class="ir-num">' + (runway == null ? '-' : runway.toFixed(1) + ' months') + '</td><td>' + status + '</td></tr>';
  }).join('');
  var share = function (x) { return c.total > 0 ? (x / c.total * 100).toFixed(0) + ' %' : '-'; };
  out.innerHTML =
    '<div class="stat-grid">' +
      '<div class="stat-card premium"><div class="label">Total spend / month</div><div class="val">' + money(c.total) + '</div><div class="sub">' + whole(c.total * m) + ' ETB over ' + m + ' month' + (m > 1 ? 's' : '') + '</div></div>' +
      '<div class="stat-card"><div class="label">Cost to win one driver</div><div class="val">' + money(c.costPerDriver) + '</div></div>' +
      '<div class="stat-card"><div class="label">Cost to win one rider</div><div class="val">' + money(c.costPerRider) + '</div></div>' +
      '<div class="stat-card"><div class="label">New drivers / riders by agents</div><div class="val">' + whole(c.driversWon) + ' / ' + whole(c.ridersWon) + '</div><div class="sub">each month</div></div>' +
    '</div>' +
    '<div class="section-title" style="font-size:14px;margin-top:14px">Where the money goes' + (haveBudget ? ' and how long each launch budget line lasts' : '') + '</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Line</th><th class="ir-num">Per month</th><th class="ir-num">Whole plan</th><th class="ir-num">Launch budget</th><th class="ir-num">Lasts</th><th>Status</th></tr></thead><tbody>' + rows +
    '<tr><td><b>Total</b></td><td class="ir-num"><b>' + money(c.total) + '</b></td><td class="ir-num"><b>' + money(c.total * m) + '</b></td><td></td><td></td><td></td></tr></tbody></table></div>' +
    (haveBudget ? '' : '<div class="desc" style="margin-top:6px">Open the Launch period page once so the budget lines load here for the comparison.</div>') +
    '<div class="section-title" style="font-size:14px;margin-top:18px">Who gets what (per month)</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Group</th><th class="ir-num">Amount</th><th class="ir-num">Share of spend</th></tr></thead><tbody>' +
      '<tr><td>Marketing agents (all ' + whole(v.agents) + ')</td><td class="ir-num">' + money(c.agentsMonthly) + '</td><td class="ir-num">' + share(c.agentsMonthly) + '</td></tr>' +
      '<tr><td>&nbsp;&nbsp;one agent: base ' + money(v.agent_base) + ' + commissions ' + money(c.perAgent - v.agent_base) + '</td><td class="ir-num">' + money(c.perAgent) + '</td><td></td></tr>' +
      '<tr><td>Other marketing (posters, radio, social)</td><td class="ir-num">' + money(c.other) + '</td><td class="ir-num">' + share(c.other) + '</td></tr>' +
      '<tr><td>Drivers: daily bonuses (average per active driver ' + money(c.perDriverBonus) + ')</td><td class="ir-num">' + money(c.driverBonusMonthly) + '</td><td class="ir-num">' + share(c.driverBonusMonthly) + '</td></tr>' +
      '<tr><td>Drivers: referral rewards</td><td class="ir-num">' + money(c.refMonthly) + '</td><td class="ir-num">' + share(c.refMonthly) + '</td></tr>' +
      '<tr><td>Customers: promo discounts (' + money(c.perRider) + ' per new rider)</td><td class="ir-num">' + money(c.promoMonthly) + '</td><td class="ir-num">' + share(c.promoMonthly) + '</td></tr>' +
    '</tbody></table></div>' +
    '<div class="desc" style="margin-top:8px">Estimate only. "Cost to win" spreads agent pay between drivers and riders in proportion to how many each agent signs. A promo ride costs ETB ' + money(v.promo_discount) + ' off an average fare of ETB ' + money(v.avg_fare) + ' (' + (v.avg_fare > 0 ? (v.promo_discount / v.avg_fare * 100).toFixed(0) : '0') + ' % off). Bonus and referral rules must also be switched on in Delivery &amp; Ride (Admin &gt; Pricing &gt; Incentives) before anything is paid there.</div>';
}

function build() {
  var host = $('ip-fields'); if (!host || host.getAttribute('data-built')) return;
  host.setAttribute('data-built', '1');
  var v = load(), byKey = {}; FIELDS.forEach(function (f) { byKey[f[0]] = f; });
  host.innerHTML = GROUPS.map(function (g) {
    return '<div class="section-title" style="font-size:13px;margin:12px 0 6px">' + esc(g[0]) + '</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">' +
      g[1].map(function (k) { var f = byKey[k]; return '<div class="cf-field"><label>' + esc(f[1]) + '</label><input type="number" id="ip-' + k + '" min="0" step="any" value="' + esc(v[k]) + '"' + (f[2] ? ' title="' + esc(f[2]) + '"' : '') + '></div>'; }).join('') + '</div>';
  }).join('');
  host.addEventListener('input', paint);
  var reset = $('ip-reset'); if (reset) reset.addEventListener('click', function () { Object.keys(DEFAULTS).forEach(function (k) { $('ip-' + k).value = DEFAULTS[k]; }); paint(); });
  paint();
}
async function loadBudget() {
  if (typeof irApi !== 'function') return;
  try {
    var res = await irApi('admin_launch_get');
    if (res && res.ok && res.data && res.data.summary) {
      BUDGET = {}; (res.data.summary.categories || []).forEach(function (c) { BUDGET[c.category] = Number(c.budget) || 0; });
      paint();
    }
  } catch (e) { /* the planner still works without the budget lines */ }
}
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('#irx-nav a[data-page="incentives"]');
  if (a || (e.target.closest && e.target.closest('[data-go="incentives"]'))) { build(); loadBudget(); }
});
if (location.hash === '#incentives') setTimeout(function () { build(); loadBudget(); }, 600);
})();
