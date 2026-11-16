/* Investors admin - Incentive & marketing planner.
   Assumption-based, like the Growth planner: set the number of marketing agents, driver bonuses, referral rewards and rider (customer) promotions,
   and see what each costs, who gets what, how long the launch budget lasts, and the cost of winning one driver or one rider.
   Every amount can be given three ways:  ETB (a fixed amount),  % (of the fares of the trips it relates to),  Trip (ETB for every trip).
   Every volume can be given per day, per week or per month, and the results can be shown per day, week or month.
   Everything is calculated in the browser from the numbers typed here; the launch budget lines are read from the Launch period page. Nothing is saved on the server. */
(function () {
'use strict';
var KEY = 'ir_incentive_plan_v2';
var DAYS_IN_MONTH = 30, WEEKS_IN_MONTH = 30 / 7;
var UNITS = [['etb', 'ETB'], ['pct', '% of fares'], ['trip', 'ETB per trip']];
var PERIODS = [['day', 'per day'], ['week', 'per week'], ['month', 'per month']];

// kind: num (plain) | amt (amount: ETB / % / per trip) | vol (a count or ETB that has a period: day / week / month)
var FIELDS = {
  months:            { kind: 'num', label: 'Months to plan', def: 3, hint: 'Launch length (the launch rule allows up to 90 days)' },
  agents:            { kind: 'num', label: 'Marketing agents', def: 5, hint: 'People you pay to sign up drivers and riders' },
  agent_base:        { kind: 'vol', label: 'Base pay per agent (ETB)', def: 3000, per: 'month', hint: 'Fixed pay for each agent' },
  agent_per_driver:  { kind: 'amt', label: 'Commission per driver signed', def: 200, unit: 'etb', trips: 'driver_qual_trips', hint: 'Paid when a signed driver has completed the qualifying trips' },
  agent_per_rider:   { kind: 'amt', label: 'Commission per rider signed', def: 10, unit: 'etb', trips: 'rider_qual_trips', hint: 'Paid when a signed rider has completed the qualifying trips' },
  driver_qual_trips: { kind: 'num', label: 'Trips a new driver must complete to qualify', def: 10, hint: 'Used by "% of fares" and "ETB per trip" for driver commissions and referrals' },
  rider_qual_trips:  { kind: 'num', label: 'Trips a new rider must complete to qualify', def: 1, hint: 'Used by "% of fares" and "ETB per trip" for rider commissions' },
  drivers_per_agent: { kind: 'vol', label: 'Drivers signed per agent', def: 8, per: 'month' },
  riders_per_agent:  { kind: 'vol', label: 'Riders signed per agent', def: 60, per: 'month' },
  other_marketing:   { kind: 'vol', label: 'Other marketing (ETB)', def: 20000, per: 'month', hint: 'Posters, radio, social media, events' },
  drivers_active:    { kind: 'num', label: 'Active drivers', def: 60, hint: 'Drivers who can earn the daily bonus' },
  work_days:         { kind: 'num', label: 'Bonus days per month', def: 26 },
  t1_trips: { kind: 'num', label: 'Tier 1: trips in a day', def: 5 },  t1_bonus: { kind: 'amt', label: 'Tier 1 bonus', def: 30, unit: 'etb', trips: 't1_trips', hint: 'Bonus for a day reaching tier 1' },  t1_share: { kind: 'num', label: 'Share of drivers reaching tier 1 (%)', def: 50 },
  t2_trips: { kind: 'num', label: 'Tier 2: trips in a day', def: 10 }, t2_bonus: { kind: 'amt', label: 'Tier 2 bonus', def: 80, unit: 'etb', trips: 't2_trips', hint: 'Bonus for a day reaching tier 2' }, t2_share: { kind: 'num', label: 'Share reaching tier 2 (%)', def: 25 },
  t3_trips: { kind: 'num', label: 'Tier 3: trips in a day', def: 15 }, t3_bonus: { kind: 'amt', label: 'Tier 3 bonus', def: 150, unit: 'etb', trips: 't3_trips', hint: 'Bonus for a day reaching tier 3' }, t3_share: { kind: 'num', label: 'Share reaching tier 3 (%)', def: 10, hint: 'Tier shares are shares of ALL active drivers, on a typical day' },
  ref_reward:        { kind: 'amt', label: 'Driver referral reward', def: 100, unit: 'etb', trips: 'driver_qual_trips', hint: 'Paid when the referred driver completes the qualifying trips' },
  ref_per_month:     { kind: 'vol', label: 'Qualified referrals', def: 10, per: 'month' },
  new_riders:        { kind: 'vol', label: 'New riders', def: 400, per: 'month', hint: 'Customers using the app for the first time' },
  promo_discount:    { kind: 'amt', label: 'Promo discount for a new rider', def: 30, unit: 'trip', trips: 'promo_rides', hint: 'ETB = total for the rider; % = of the fares of the promo rides; ETB per trip = for each promo ride' },
  promo_rides:       { kind: 'num', label: 'Promo rides per new rider', def: 2, hint: 'How many first rides get the discount' },
  avg_fare:          { kind: 'num', label: 'Average fare (ETB)', def: 150, hint: 'Used when an amount is given as a % of fares' }
};
var GROUPS = [
  ['Plan', ['months']],
  ['Marketing agents', ['agents', 'agent_base', 'agent_per_driver', 'agent_per_rider', 'driver_qual_trips', 'rider_qual_trips', 'drivers_per_agent', 'riders_per_agent', 'other_marketing']],
  ['Driver bonuses', ['drivers_active', 'work_days', 't1_trips', 't1_bonus', 't1_share', 't2_trips', 't2_bonus', 't2_share', 't3_trips', 't3_bonus', 't3_share']],
  ['Driver referrals', ['ref_reward', 'ref_per_month']],
  ['Customer (rider) promotions', ['new_riders', 'promo_discount', 'promo_rides', 'avg_fare']]
];
var BUDGET = {};                 // launch budget lines from the Launch period page
function $(id) { return document.getElementById(id); }
function esc(s) { return window.irEsc ? window.irEsc(s) : String(s); }
function money(n) { return window.irMoney ? window.irMoney(n) : Number(n || 0).toFixed(2); }
function whole(n) { return Math.round(Number(n || 0)).toLocaleString('en-US'); }

function defaults() { var s = { show: 'month', v: {}, u: {}, p: {} }; Object.keys(FIELDS).forEach(function (k) { var f = FIELDS[k]; s.v[k] = f.def; if (f.unit) s.u[k] = f.unit; if (f.per) s.p[k] = f.per; }); return s; }
function load() {
  var d = defaults(), s = {};
  try { s = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { s = {}; }
  Object.keys(FIELDS).forEach(function (k) {
    if (s.v && typeof s.v[k] === 'number') d.v[k] = s.v[k];
    if (s.u && UNITS.some(function (x) { return x[0] === s.u[k]; })) d.u[k] = s.u[k];
    if (s.p && PERIODS.some(function (x) { return x[0] === s.p[k]; })) d.p[k] = s.p[k];
  });
  if (['day', 'week', 'month'].indexOf(s.show) >= 0) d.show = s.show;
  return d;
}
function read() {
  var s = defaults();
  Object.keys(FIELDS).forEach(function (k) {
    var el = $('ip-' + k), n = el ? Number(el.value) : s.v[k]; s.v[k] = isFinite(n) && n >= 0 ? n : 0;
    var u = $('ipu-' + k); if (u) s.u[k] = u.value;
    var p = $('ipp-' + k); if (p) s.p[k] = p.value;
  });
  var sh = $('ip-show'); if (sh) s.show = sh.value;
  return s;
}
function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* not remembered */ } }
function perMonth(n, per) { return per === 'day' ? n * DAYS_IN_MONTH : per === 'week' ? n * WEEKS_IN_MONTH : n; }

// ETB for one event: a fixed amount, a % of the fares of the related trips, or ETB for every one of those trips
function amount(s, k) {
  var f = FIELDS[k], val = s.v[k], unit = s.u[k], trips = s.v[f.trips] || 0, fare = s.v.avg_fare || 0;
  return unit === 'pct' ? val / 100 * trips * fare : unit === 'trip' ? val * trips : val;
}

function calc(s) {
  var v = s.v, m = Math.max(1, v.months);
  var agentBase = v.agents * perMonth(v.agent_base, s.p.agent_base);
  var driversWon = v.agents * perMonth(v.drivers_per_agent, s.p.drivers_per_agent);
  var ridersWon = v.agents * perMonth(v.riders_per_agent, s.p.riders_per_agent);
  var perDriver = amount(s, 'agent_per_driver'), perRider = amount(s, 'agent_per_rider');
  var agentComm = driversWon * perDriver + ridersWon * perRider;
  var agentsMonthly = agentBase + agentComm;
  var other = perMonth(v.other_marketing, s.p.other_marketing);
  var marketingMonthly = agentsMonthly + other;
  var tierBonus = [1, 2, 3].map(function (i) { return [amount(s, 't' + i + '_bonus'), v['t' + i + '_share']]; });
  var driverBonusMonthly = tierBonus.reduce(function (a, t) { return a + v.drivers_active * (t[1] / 100) * t[0] * v.work_days; }, 0);
  var refs = perMonth(v.ref_per_month, s.p.ref_per_month), refUnit = amount(s, 'ref_reward');
  var refMonthly = refUnit * refs;
  var newRiders = perMonth(v.new_riders, s.p.new_riders), promoPerRider = amount(s, 'promo_discount');
  var promoMonthly = newRiders * promoPerRider;
  var total = marketingMonthly + driverBonusMonthly + refMonthly + promoMonthly;
  var split = Math.max(1, driversWon + ridersWon);
  return {
    m: m, agentBase: agentBase, agentComm: agentComm, agentsMonthly: agentsMonthly, other: other, marketingMonthly: marketingMonthly,
    driverBonusMonthly: driverBonusMonthly, refMonthly: refMonthly, promoMonthly: promoMonthly, total: total, driversWon: driversWon, ridersWon: ridersWon,
    perAgent: v.agents ? agentsMonthly / v.agents : 0, perAgentBase: v.agents ? agentBase / v.agents : 0,
    perDriverBonus: v.drivers_active ? driverBonusMonthly / v.drivers_active : 0, promoPerRider: promoPerRider, perDriverComm: perDriver, perRiderComm: perRider, refUnit: refUnit,
    costPerDriver: (driversWon + refs) > 0 ? (agentBase * (driversWon / split) + driversWon * perDriver + refMonthly) / (driversWon + refs) : 0,
    costPerRider: (ridersWon + newRiders) > 0 ? (agentBase * (ridersWon / split) + ridersWon * perRider + promoMonthly) / (ridersWon + newRiders) : 0
  };
}

function paint() {
  var out = $('ip-output'); if (!out) return;
  var s = read(); save(s);
  var v = s.v, c = calc(s), m = c.m;
  var fac = s.show === 'day' ? 1 / DAYS_IN_MONTH : s.show === 'week' ? 7 / DAYS_IN_MONTH : 1;     // month -> chosen period
  var per = { day: 'day', week: 'week', month: 'month' }[s.show];
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
    return '<tr><td><b>' + esc(l[0]) + '</b><br><small>' + esc(l[3]) + '</small></td><td class="ir-num">' + money(l[2] * fac) + '</td><td class="ir-num">' + money(tot) + '</td><td class="ir-num">' + (b == null ? '-' : money(b)) + '</td><td class="ir-num">' + (runway == null ? '-' : runway.toFixed(1) + ' months') + '</td><td>' + status + '</td></tr>';
  }).join('');
  var share = function (x) { return c.total > 0 ? (x / c.total * 100).toFixed(0) + ' %' : '-'; };
  out.innerHTML =
    '<div class="stat-grid">' +
      '<div class="stat-card premium"><div class="label">Total spend / ' + per + '</div><div class="val">' + money(c.total * fac) + '</div><div class="sub">' + whole(c.total * m) + ' ETB over ' + m + ' month' + (m > 1 ? 's' : '') + '</div></div>' +
      '<div class="stat-card"><div class="label">Cost to win one driver</div><div class="val">' + money(c.costPerDriver) + '</div></div>' +
      '<div class="stat-card"><div class="label">Cost to win one rider</div><div class="val">' + money(c.costPerRider) + '</div></div>' +
      '<div class="stat-card"><div class="label">New drivers / riders by agents</div><div class="val">' + whole(c.driversWon * fac) + ' / ' + whole(c.ridersWon * fac) + '</div><div class="sub">each ' + per + '</div></div>' +
    '</div>' +
    '<div class="section-title" style="font-size:14px;margin-top:14px">Where the money goes' + (haveBudget ? ' and how long each launch budget line lasts' : '') + '</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Line</th><th class="ir-num">Per ' + per + '</th><th class="ir-num">Whole plan</th><th class="ir-num">Launch budget</th><th class="ir-num">Lasts</th><th>Status</th></tr></thead><tbody>' + rows +
    '<tr><td><b>Total</b></td><td class="ir-num"><b>' + money(c.total * fac) + '</b></td><td class="ir-num"><b>' + money(c.total * m) + '</b></td><td></td><td></td><td></td></tr></tbody></table></div>' +
    (haveBudget ? '' : '<div class="desc" style="margin-top:6px">Open the Launch period page once so the budget lines load here for the comparison.</div>') +
    '<div class="section-title" style="font-size:14px;margin-top:18px">Who gets what (per ' + per + ')</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th>Group</th><th class="ir-num">Amount</th><th class="ir-num">Share of spend</th></tr></thead><tbody>' +
      '<tr><td>Marketing agents (all ' + whole(v.agents) + ')</td><td class="ir-num">' + money(c.agentsMonthly * fac) + '</td><td class="ir-num">' + share(c.agentsMonthly) + '</td></tr>' +
      '<tr><td>&nbsp;&nbsp;one agent: base ' + money(c.perAgentBase * fac) + ' + commissions ' + money((c.perAgent - c.perAgentBase) * fac) + '</td><td class="ir-num">' + money(c.perAgent * fac) + '</td><td></td></tr>' +
      '<tr><td>Other marketing (posters, radio, social)</td><td class="ir-num">' + money(c.other * fac) + '</td><td class="ir-num">' + share(c.other) + '</td></tr>' +
      '<tr><td>Drivers: daily bonuses (average per active driver ' + money(c.perDriverBonus * fac) + ')</td><td class="ir-num">' + money(c.driverBonusMonthly * fac) + '</td><td class="ir-num">' + share(c.driverBonusMonthly) + '</td></tr>' +
      '<tr><td>Drivers: referral rewards (' + money(c.refUnit) + ' each)</td><td class="ir-num">' + money(c.refMonthly * fac) + '</td><td class="ir-num">' + share(c.refMonthly) + '</td></tr>' +
      '<tr><td>Customers: promo discounts (' + money(c.promoPerRider) + ' per new rider)</td><td class="ir-num">' + money(c.promoMonthly * fac) + '</td><td class="ir-num">' + share(c.promoMonthly) + '</td></tr>' +
    '</tbody></table></div>' +
    '<div class="desc" style="margin-top:8px">Estimate only. A month is counted as 30 days (a week as 7). "ETB" is a fixed amount, "% of fares" is a share of the fares of the trips it relates to (average fare ETB ' + money(v.avg_fare) + '), "ETB per trip" is paid for every one of those trips. "Cost to win" spreads agent pay between drivers and riders in proportion to how many each agent signs. Bonus and referral rules must also be switched on in Delivery &amp; Ride (Admin &gt; Pricing &gt; Incentives) before anything is paid there.</div>';
}

function select(id, opts, cur) { return '<select id="' + id + '">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>'; }
function build() {
  var host = $('ip-fields'); if (!host || host.getAttribute('data-built')) return;
  host.setAttribute('data-built', '1');
  var s = load();
  var head = '<div class="cf-field" style="max-width:260px"><label>Show the results per</label>' + select('ip-show', [['day', 'Day'], ['week', 'Week'], ['month', 'Month']], s.show) + '</div>';
  host.innerHTML = head + GROUPS.map(function (g) {
    return '<div class="section-title" style="font-size:13px;margin:12px 0 6px">' + esc(g[0]) + '</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px">' +
      g[1].map(function (k) {
        var f = FIELDS[k], ctl = '';
        if (f.kind === 'amt') ctl = select('ipu-' + k, UNITS, s.u[k]);
        else if (f.kind === 'vol') ctl = select('ipp-' + k, PERIODS, s.p[k]);
        return '<div class="cf-field"><label>' + esc(f.label) + '</label><div style="display:flex;gap:6px"><input type="number" id="ip-' + k + '" min="0" step="any" value="' + esc(s.v[k]) + '"' + (f.hint ? ' title="' + esc(f.hint) + '"' : '') + ' style="flex:1;min-width:0">' + ctl + '</div></div>';
      }).join('') + '</div>';
  }).join('');
  host.addEventListener('input', paint); host.addEventListener('change', paint);
  var reset = $('ip-reset');
  if (reset) reset.addEventListener('click', function () {
    var d = defaults();
    Object.keys(FIELDS).forEach(function (k) { $('ip-' + k).value = d.v[k]; if ($('ipu-' + k)) $('ipu-' + k).value = d.u[k]; if ($('ipp-' + k)) $('ipp-' + k).value = d.p[k]; });
    $('ip-show').value = d.show; paint();
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
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('#irx-nav a[data-page="incentives"]');
  if (a || (e.target.closest && e.target.closest('[data-go="incentives"]'))) { build(); loadBudget(); }
});
if (location.hash === '#incentives') setTimeout(function () { build(); loadBudget(); }, 600);
})();
