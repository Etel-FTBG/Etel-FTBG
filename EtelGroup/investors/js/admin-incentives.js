/* Investors admin - Incentive & marketing planner.
   Assumption-based, like the Growth planner: set the number of marketing agents, driver bonuses, referral rewards and rider (customer) promotions,
   and see what each costs, who gets what, how long the launch budget lasts, and the cost of winning one driver or one rider.
   Every amount can be given three ways:  ETB (a fixed amount),  % (of the fares of the trips it relates to),  Trip (ETB for every trip).
   Every volume can be given per day, per week or per month, and the results can be shown per day, week or month.
   Everything is calculated in the browser from the numbers typed here; the launch budget lines are read from the Launch period page. Nothing is saved on the server. */
(function () {
'use strict';
var KEY = 'ir_incentive_plan_v3';
var DAYS_IN_MONTH = 30, WEEKS_IN_MONTH = 30 / 7;
var UNITS = [['etb', 'ETB'], ['pct', '% of fares'], ['trip', 'ETB per trip']];
var PERIODS = [['day', 'per day'], ['week', 'per week'], ['month', 'per month']];

// kind: num (plain) | amt (amount: ETB / % / per trip) | vol (a count or ETB that has a period: day / week / month)
var FIELDS = {
  months:            { kind: 'num', label: 'Months to plan', def: 3, hint: 'Launch length (the launch rule allows up to 90 days)' },
  agents:            { kind: 'num', label: 'Marketing agents (at least 10)', def: 10, hint: 'Agents posted at busy places: hotels, parking areas, malls, markets' },
  agent_trips:       { kind: 'vol', label: 'New-user trips one agent orders', def: 4, per: 'day', hint: 'Each time the agent calls a driver for a new user' },
  completion_pct:    { kind: 'num', label: 'Share of those trips that are completed (%)', def: 90, hint: 'The agent is paid only when the trip is completed' },
  agent_per_trip:    { kind: 'amt', label: 'Agent fee per completed trip', def: 40, unit: 'etb', hint: 'Your plan: 30 to 50 ETB. The tables below also show 30 / 40 / 50' },
  agent_base:        { kind: 'vol', label: 'Base pay per agent (ETB), if any', def: 0, per: 'month', hint: 'Leave 0 if agents are paid only per completed trip' },
  agent_per_driver:  { kind: 'amt', label: '(Optional) Commission per driver signed', def: 200, unit: 'etb', trips: 'driver_qual_trips', hint: 'Paid when a signed driver has completed the qualifying trips' },
  driver_qual_trips: { kind: 'num', label: 'Trips a new driver must complete to qualify', def: 10, hint: 'Used by "% of fares" and "ETB per trip" for driver commissions and referrals' },
  drivers_per_agent: { kind: 'vol', label: '(Optional) Drivers signed per agent', def: 0, per: 'month' },
  other_marketing:   { kind: 'vol', label: 'Other marketing (ETB)', def: 20000, per: 'month', hint: 'Posters, radio, social media, events' },
  drivers_active:    { kind: 'num', label: 'Active drivers', def: 60, hint: 'Drivers who can earn the daily bonus' },
  work_days:         { kind: 'num', label: 'Bonus days per month', def: 26 },
  t1_trips: { kind: 'num', label: 'Tier 1: trips in a day', def: 5 },  t1_bonus: { kind: 'amt', label: 'Tier 1 bonus', def: 30, unit: 'etb', trips: 't1_trips', hint: 'Bonus for a day reaching tier 1' },  t1_share: { kind: 'num', label: 'Share of drivers reaching tier 1 (%)', def: 50 },
  t2_trips: { kind: 'num', label: 'Tier 2: trips in a day', def: 10 }, t2_bonus: { kind: 'amt', label: 'Tier 2 bonus', def: 80, unit: 'etb', trips: 't2_trips', hint: 'Bonus for a day reaching tier 2' }, t2_share: { kind: 'num', label: 'Share reaching tier 2 (%)', def: 25 },
  t3_trips: { kind: 'num', label: 'Tier 3: trips in a day', def: 15 }, t3_bonus: { kind: 'amt', label: 'Tier 3 bonus', def: 150, unit: 'etb', trips: 't3_trips', hint: 'Bonus for a day reaching tier 3' }, t3_share: { kind: 'num', label: 'Share reaching tier 3 (%)', def: 10, hint: 'Tier shares are shares of ALL active drivers, on a typical day' },
  ref_reward:        { kind: 'amt', label: 'Driver referral reward', def: 100, unit: 'etb', trips: 'driver_qual_trips', hint: 'Paid when the referred driver completes the qualifying trips' },
  ref_per_month:     { kind: 'vol', label: 'Qualified referrals', def: 10, per: 'month' },
  new_riders:        { kind: 'vol', label: 'Other new riders (not through agents)', def: 0, per: 'month', hint: 'Customers who find the app on their own and also get the discount' },
  promo_discount:    { kind: 'amt', label: 'New-rider discount', def: 20, unit: 'pct', trips: 'promo_rides', hint: 'Your plan: a % off the completed trip. The driver still gets the full fare; the company pays the discount to the driver' },
  promo_rides:       { kind: 'num', label: 'Discounted rides per new rider', def: 1, hint: 'How many first rides get the discount' },
  avg_fare:          { kind: 'num', label: 'Average fare (ETB)', def: 150, hint: 'Used when an amount is given as a % of fares' },
  settle:            { kind: 'sel', label: 'Company pays drivers the discount every', def: 'week', hint: 'How often drivers collect the discounts from the company' }
};
var GROUPS = [
  ['Plan', ['months']],
  ['Marketing agents at busy places', ['agents', 'agent_trips', 'completion_pct', 'agent_per_trip', 'agent_base', 'other_marketing', 'agent_per_driver', 'driver_qual_trips', 'drivers_per_agent']],
  ['Driver bonuses', ['drivers_active', 'work_days', 't1_trips', 't1_bonus', 't1_share', 't2_trips', 't2_bonus', 't2_share', 't3_trips', 't3_bonus', 't3_share']],
  ['Driver referrals', ['ref_reward', 'ref_per_month']],
  ['New-rider discount (paid by the company to the driver)', ['promo_discount', 'promo_rides', 'avg_fare', 'settle', 'new_riders']]
];
var BUDGET = {};                 // launch budget lines from the Launch period page
function $(id) { return document.getElementById(id); }
function esc(s) { return window.irEsc ? window.irEsc(s) : String(s); }
function money(n) { return window.irMoney ? window.irMoney(n) : Number(n || 0).toFixed(2); }
function whole(n) { return Math.round(Number(n || 0)).toLocaleString('en-US'); }

var SETTLE = [['day', 'Day'], ['week', 'Week']];
function defaults() { var s = { show: 'month', settle: 'week', v: {}, u: {}, p: {} }; Object.keys(FIELDS).forEach(function (k) { var f = FIELDS[k]; if (f.kind === 'sel') return; s.v[k] = f.def; if (f.unit) s.u[k] = f.unit; if (f.per) s.p[k] = f.per; }); return s; }
function load() {
  var d = defaults(), s = {};
  try { s = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { s = {}; }
  Object.keys(FIELDS).forEach(function (k) {
    if (FIELDS[k].kind === 'sel') return;
    if (s.v && typeof s.v[k] === 'number') d.v[k] = s.v[k];
    if (s.u && UNITS.some(function (x) { return x[0] === s.u[k]; })) d.u[k] = s.u[k];
    if (s.p && PERIODS.some(function (x) { return x[0] === s.p[k]; })) d.p[k] = s.p[k];
  });
  if (['day', 'week', 'month'].indexOf(s.show) >= 0) d.show = s.show;
  if (s.settle === 'day' || s.settle === 'week') d.settle = s.settle;
  return d;
}
function read() {
  var s = defaults();
  Object.keys(FIELDS).forEach(function (k) {
    if (FIELDS[k].kind === 'sel') return;
    var el = $('ip-' + k), n = el ? Number(el.value) : s.v[k]; s.v[k] = isFinite(n) && n >= 0 ? n : 0;
    var u = $('ipu-' + k); if (u) s.u[k] = u.value;
    var p = $('ipp-' + k); if (p) s.p[k] = p.value;
  });
  var sh = $('ip-show'); if (sh) s.show = sh.value;
  var st = $('ip-settle'); if (st) s.settle = st.value === 'day' ? 'day' : 'week';
  return s;
}
function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* not remembered */ } }
function perMonth(n, per) { return per === 'day' ? n * DAYS_IN_MONTH : per === 'week' ? n * WEEKS_IN_MONTH : n; }

// ETB for one event: a fixed amount, a % of the fares of the related trips, or ETB for every one of those trips
function amount(s, k) {
  var f = FIELDS[k], val = s.v[k], unit = s.u[k], trips = f.trips ? (s.v[f.trips] || 0) : 1, fare = s.v.avg_fare || 0;
  return unit === 'pct' ? val / 100 * trips * fare : unit === 'trip' ? val * trips : val;
}

function calc(s) {
  var v = s.v, m = Math.max(1, v.months);
  var agentBase = v.agents * perMonth(v.agent_base, s.p.agent_base);
  var agentTrips = v.agents * perMonth(v.agent_trips, s.p.agent_trips);               // new-user trips the agents order each month
  var completed = agentTrips * Math.min(100, v.completion_pct) / 100;                   // only completed trips are paid
  var fee = amount(s, 'agent_per_trip'), agentFees = completed * fee;
  var driversWon = v.agents * perMonth(v.drivers_per_agent, s.p.drivers_per_agent), perDriver = amount(s, 'agent_per_driver');
  var agentComm = agentFees + driversWon * perDriver;
  var agentsMonthly = agentBase + agentComm;
  var other = perMonth(v.other_marketing, s.p.other_marketing);
  var marketingMonthly = agentsMonthly + other;
  var tierBonus = [1, 2, 3].map(function (i) { return [amount(s, 't' + i + '_bonus'), v['t' + i + '_share']]; });
  var driverBonusMonthly = tierBonus.reduce(function (a, t) { return a + v.drivers_active * (t[1] / 100) * t[0] * v.work_days; }, 0);
  var refs = perMonth(v.ref_per_month, s.p.ref_per_month), refUnit = amount(s, 'ref_reward');
  var refMonthly = refUnit * refs;
  var otherRiders = perMonth(v.new_riders, s.p.new_riders), discountPerRider = amount(s, 'promo_discount');
  var newUsers = completed + otherRiders;                                               // new riders who get the discount
  var promoMonthly = newUsers * discountPerRider;                                       // the company pays this to the drivers
  var total = marketingMonthly + driverBonusMonthly + refMonthly + promoMonthly;
  var split = Math.max(1, driversWon + newUsers);
  return {
    m: m, agentBase: agentBase, agentTrips: agentTrips, completed: completed, fee: fee, agentFees: agentFees, agentComm: agentComm, agentsMonthly: agentsMonthly, other: other, marketingMonthly: marketingMonthly,
    driverBonusMonthly: driverBonusMonthly, refMonthly: refMonthly, promoMonthly: promoMonthly, total: total, driversWon: driversWon, ridersWon: completed, newUsers: newUsers,
    perAgent: v.agents ? agentsMonthly / v.agents : 0, perAgentBase: v.agents ? agentBase / v.agents : 0, perAgentFees: v.agents ? agentFees / v.agents : 0,
    perDriverBonus: v.drivers_active ? driverBonusMonthly / v.drivers_active : 0, promoPerRider: discountPerRider, refUnit: refUnit,
    costPerDriver: (driversWon + refs) > 0 ? (agentBase * (driversWon / split) + driversWon * perDriver + refMonthly) / (driversWon + refs) : 0,
    costPerRider: newUsers > 0 ? (agentBase * (newUsers / split) + agentFees + promoMonthly) / newUsers : 0
  };
}
// the same plan with one amount changed (for the 30 / 40 / 50 ETB and discount % comparisons)
function withField(s, k, unit, val) { var c = JSON.parse(JSON.stringify(s)); c.v[k] = val; if (unit) c.u[k] = unit; return calc(c); }

function payBlock(s, c) {
  var days = s.settle === 'day' ? 1 : 7, word = s.settle === 'day' ? 'day' : 'week', f = days / DAYS_IN_MONTH;
  return '<div class="section-title" style="font-size:14px;margin-top:18px">What the company pays out every ' + word + '</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Paid to</th><th>What for</th><th class="ir-num">Every ' + word + '</th></tr></thead><tbody>' +
    '<tr><td>Drivers</td><td>The new-rider discounts: the driver receives the full fare from the rider\'s trip and collects the discount from the company</td><td class="ir-num">' + money(c.promoMonthly * f) + '</td></tr>' +
    '<tr><td>Agents</td><td>Fee for each completed trip they ordered' + (c.agentBase > 0 ? ' + base pay' : '') + '</td><td class="ir-num">' + money(c.agentsMonthly * f) + '</td></tr>' +
    '<tr><td><b>Cash to keep ready</b></td><td>For these two lines, every ' + word + '</td><td class="ir-num"><b>' + money((c.promoMonthly + c.agentsMonthly) * f) + '</b></td></tr></tbody></table></div>';
}
function sensBlock(s, c, fac, per) {
  var fees = [30, 40, 50].map(function (x) { var r = withField(s, 'agent_per_trip', 'etb', x); return '<tr><td>ETB ' + x + ' per completed trip</td><td class="ir-num">' + money(r.agentFees * fac / Math.max(1, s.v.agents)) + '</td><td class="ir-num">' + money(r.total * fac) + '</td><td class="ir-num">' + money(r.costPerRider) + '</td></tr>'; }).join('');
  var disc = [10, 20, 30, 50].map(function (x) { var r = withField(s, 'promo_discount', 'pct', x); return '<tr><td>' + x + ' % off the trip</td><td class="ir-num">' + money(r.promoPerRider) + '</td><td class="ir-num">' + money(r.total * fac) + '</td><td class="ir-num">' + money(r.costPerRider) + '</td></tr>'; }).join('');
  return '<div class="section-title" style="font-size:14px;margin-top:18px">What if the agent fee is different? (30 to 50 ETB)</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Agent fee</th><th class="ir-num">One agent earns / ' + per + '</th><th class="ir-num">Total spend / ' + per + '</th><th class="ir-num">Cost to win one rider</th></tr></thead><tbody>' + fees + '</tbody></table></div>' +
    '<div class="section-title" style="font-size:14px;margin-top:18px">What if the discount is different?</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>New-rider discount</th><th class="ir-num">Company pays per new rider</th><th class="ir-num">Total spend / ' + per + '</th><th class="ir-num">Cost to win one rider</th></tr></thead><tbody>' + disc + '</tbody></table></div>';
}

function paint() {
  var out = $('ip-output'); if (!out) return;
  var s = read(); save(s);
  var v = s.v, c = calc(s), m = c.m;
  var fac = s.show === 'day' ? 1 / DAYS_IN_MONTH : s.show === 'week' ? 7 / DAYS_IN_MONTH : 1;     // month -> chosen period
  var per = { day: 'day', week: 'week', month: 'month' }[s.show];
  var lines = [
    ['Marketing agents and marketing', 'marketing', c.marketingMonthly, 'Agent fees per completed trip + any base pay + other marketing'],
    ['Driver daily bonuses', 'bonus', c.driverBonusMonthly, 'Tiered bonus for busy days'],
    ['Driver referral rewards', 'referral', c.refMonthly, 'Paid per qualified referral'],
    ['New-rider discounts', 'promotion', c.promoMonthly, 'The company pays this to the drivers']
  ];
  var haveBudget = Object.keys(BUDGET).length > 0;
  var rows = lines.map(function (l) {
    var tot = l[2] * m, b = BUDGET[l[1]], runway = (b != null && l[2] > 0) ? b / l[2] : null;
    var status = b == null ? '-' : (tot <= b ? '<span style="color:var(--ok,#2fb36b)">Within budget</span>' : '<span style="color:var(--bad)">Over by ETB ' + whole(tot - b) + '</span>');
    return '<tr><td><b>' + esc(l[0]) + '</b><br><small>' + esc(l[3]) + '</small></td><td class="ir-num">' + money(l[2] * fac) + '</td><td class="ir-num">' + money(tot) + '</td><td class="ir-num">' + (b == null ? '-' : money(b)) + '</td><td class="ir-num">' + (runway == null ? '-' : runway.toFixed(1) + ' months') + '</td><td>' + status + '</td></tr>';
  }).join('');
  var share = function (x) { return c.total > 0 ? (x / c.total * 100).toFixed(0) + ' %' : '-'; };
  out.innerHTML =
    '<div class="stat-grid">' +
      '<div class="stat-card premium"><div class="label">Total spend / ' + per + '</div><div class="val">' + money(c.total * fac) + '</div><div class="sub">' + whole(c.total * m) + ' ETB over ' + m + ' month' + (m > 1 ? 's' : '') + '</div></div>' +
      '<div class="stat-card"><div class="label">Cost to win one new rider</div><div class="val">' + money(c.costPerRider) + '</div><div class="sub">agent fee + discount</div></div>' +
      '<div class="stat-card"><div class="label">New riders from agents</div><div class="val">' + whole(c.completed * fac) + '</div><div class="sub">completed trips each ' + per + '</div></div>' +
      '<div class="stat-card"><div class="label">One agent earns</div><div class="val">' + money((c.perAgent) * fac) + '</div><div class="sub">per ' + per + '</div></div>' +
    '</div>' +
    (v.agents < 10 ? '<div class="ir-not-connected" style="margin-top:10px">Your plan is at least 10 agents at busy places (hotels, parking, malls, markets). You have ' + whole(v.agents) + '.</div>' : '') +
    '<div class="section-title" style="font-size:14px;margin-top:14px">Where the money goes' + (haveBudget ? ' and how long each launch budget line lasts' : '') + '</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Line</th><th class="ir-num">Per ' + per + '</th><th class="ir-num">Whole plan</th><th class="ir-num">Launch budget</th><th class="ir-num">Lasts</th><th>Status</th></tr></thead><tbody>' + rows +
    '<tr><td><b>Total</b></td><td class="ir-num"><b>' + money(c.total * fac) + '</b></td><td class="ir-num"><b>' + money(c.total * m) + '</b></td><td></td><td></td><td></td></tr></tbody></table></div>' +
    (haveBudget ? '' : '<div class="desc" style="margin-top:6px">Open the Launch period page once so the budget lines load here for the comparison.</div>') +
    '<div class="section-title" style="font-size:14px;margin-top:18px">Who gets what (per ' + per + ')</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Group</th><th class="ir-num">Amount</th><th class="ir-num">Share of spend</th></tr></thead><tbody>' +
      '<tr><td>Marketing agents (all ' + whole(v.agents) + ')</td><td class="ir-num">' + money(c.agentsMonthly * fac) + '</td><td class="ir-num">' + share(c.agentsMonthly) + '</td></tr>' +
      '<tr><td>&nbsp;&nbsp;one agent: base ' + money(c.perAgentBase * fac) + ' + trip fees ' + money(c.perAgentFees * fac) + (c.driversWon > 0 ? ' + driver commissions' : '') + '</td><td class="ir-num">' + money(c.perAgent * fac) + '</td><td></td></tr>' +
      '<tr><td>Other marketing (posters, radio, social)</td><td class="ir-num">' + money(c.other * fac) + '</td><td class="ir-num">' + share(c.other) + '</td></tr>' +
      '<tr><td>Drivers: daily bonuses (average per active driver ' + money(c.perDriverBonus * fac) + ')</td><td class="ir-num">' + money(c.driverBonusMonthly * fac) + '</td><td class="ir-num">' + share(c.driverBonusMonthly) + '</td></tr>' +
      '<tr><td>Drivers: referral rewards (' + money(c.refUnit) + ' each)</td><td class="ir-num">' + money(c.refMonthly * fac) + '</td><td class="ir-num">' + share(c.refMonthly) + '</td></tr>' +
      '<tr><td>New-rider discounts: company pays the driver (' + money(c.promoPerRider) + ' per new rider)</td><td class="ir-num">' + money(c.promoMonthly * fac) + '</td><td class="ir-num">' + share(c.promoMonthly) + '</td></tr>' +
    '</tbody></table></div>' + payBlock(s, c) + sensBlock(s, c, fac, per) +
    '<div class="desc" style="margin-top:8px">Estimate only. A month is counted as 30 days (a week as 7). "ETB" is a fixed amount, "% of fares" is a share of the fares of the trips it relates to (average fare ETB ' + money(v.avg_fare) + '), "ETB per trip" is paid for every one of those trips. "Cost to win a rider" = agent fee + discount (+ any base pay shared out) for each new rider. The agent is paid only when the trip is completed. Bonus and referral rules must also be switched on in Delivery &amp; Ride (Admin &gt; Pricing &gt; Incentives) before anything is paid there.</div>';
}

function select(id, opts, cur) { return '<select id="' + id + '" style="flex:0 0 auto;width:auto;max-width:48%">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'; }
function build() {
  var host = $('ip-fields'); if (!host || host.getAttribute('data-built')) return;
  host.setAttribute('data-built', '1');
  var s = load();
  var head = '<div class="cf-field" style="max-width:260px"><label>Show the results per</label>' + select('ip-show', [['day', 'Day'], ['week', 'Week'], ['month', 'Month']], s.show) + '</div>';
  host.innerHTML = head + GROUPS.map(function (g) {
    return '<div class="section-title" style="font-size:13px;margin:12px 0 6px">' + esc(g[0]) + '</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px">' +
      g[1].map(function (k) {
        var f = FIELDS[k], ctl = '';
        if (f.kind === 'sel') return '<div class="cf-field"><label>' + esc(f.label) + '</label>' + select('ip-settle', SETTLE, s.settle) + '</div>';
        if (f.kind === 'amt') ctl = select('ipu-' + k, UNITS, s.u[k]);
        else if (f.kind === 'vol') ctl = select('ipp-' + k, PERIODS, s.p[k]);
        return '<div class="cf-field"><label>' + esc(f.label) + '</label><div style="display:flex;gap:6px"><input type="number" id="ip-' + k + '" min="0" step="any" value="' + esc(s.v[k]) + '"' + (f.hint ? ' title="' + esc(f.hint) + '"' : '') + ' style="flex:1 1 90px;min-width:80px;width:90px">' + ctl + '</div></div>';
      }).join('') + '</div>';
  }).join('');
  host.addEventListener('input', paint); host.addEventListener('change', paint);
  var reset = $('ip-reset');
  if (reset) reset.addEventListener('click', function () {
    var d = defaults();
    Object.keys(FIELDS).forEach(function (k) { if (FIELDS[k].kind === 'sel') return; $('ip-' + k).value = d.v[k]; if ($('ipu-' + k)) $('ipu-' + k).value = d.u[k]; if ($('ipp-' + k)) $('ipp-' + k).value = d.p[k]; });
    $('ip-show').value = d.show; $('ip-settle').value = d.settle; paint();
  });
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
// Used by the Growth planner: the plan's spend per month and over the whole plan (works even if this page was never opened).
window.irIncentivePlan = function () {
  var host = $('ip-fields'), s = (host && host.getAttribute('data-built')) ? read() : load(), c = calc(s);
  return { monthly: c.total, months: c.m, total: c.total * c.m, marketing: c.marketingMonthly, bonus: c.driverBonusMonthly, referral: c.refMonthly, promotion: c.promoMonthly, perDriver: c.costPerDriver, perRider: c.costPerRider };
};
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('#irx-nav a[data-page="incentives"]');
  if (a || (e.target.closest && e.target.closest('[data-go="incentives"]'))) { build(); loadBudget(); }
});
if (location.hash === '#incentives') setTimeout(function () { build(); loadBudget(); }, 600);
})();
