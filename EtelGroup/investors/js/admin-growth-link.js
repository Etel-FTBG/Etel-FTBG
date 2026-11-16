/* Investors admin - links the Incentive planner to the Growth planner.
   When "Include the Incentive planner spend" is chosen, the plan's monthly spend is sent to the Growth planner as one more cost,
   and a side-by-side table shows the result with and without the incentives, plus how long the incentives take to pay back. */
(function () {
'use strict';
function $(id) { return document.getElementById(id); }
function esc(s) { return window.irEsc ? window.irEsc(s) : String(s); }
function money(n) { return window.irMoney ? window.irMoney(n) : Number(n || 0).toFixed(2); }
function whole(n) { return Math.round(Number(n || 0)).toLocaleString('en-US'); }
function plan() { try { return typeof window.irIncentivePlan === 'function' ? window.irIncentivePlan() : null; } catch (e) { return null; } }
function wanted() { var s = $('gp-incentive'); return !!(s && s.value === '1'); }

window.irGrowthIncentive = function () { var p = plan(); return wanted() && p ? p.monthly : 0; };

function note() {
  var n = $('gp-incentive-note'), p = plan();
  if (n) n.textContent = p ? 'Plan spend: ETB ' + whole(p.monthly) + ' a month for ' + p.months + ' month' + (p.months > 1 ? 's' : '') + ' (set on the Incentive planner page).' : '';
}
document.addEventListener('change', function (e) { if (e.target && e.target.id === 'gp-incentive') note(); });
document.addEventListener('click', function (e) { if (e.target && e.target.closest && e.target.closest('#irx-nav a[data-page="planner"]')) note(); });
setTimeout(note, 800);

function row(label, a, b, fmt, goodWhenLower) {
  var d = b - a, cls = d === 0 ? '' : ((d < 0) === !!goodWhenLower ? 'color:var(--ok,#2fb36b)' : 'color:var(--bad)');
  return '<tr><td>' + esc(label) + '</td><td class="ir-num">' + fmt(a) + '</td><td class="ir-num">' + fmt(b) + '</td><td class="ir-num" style="' + cls + '">' + (d > 0 ? '+' : '') + fmt(d) + '</td></tr>';
}

var orig = window.irRunGrowthPlanner;
if (typeof orig !== 'function') return;
window.irRunGrowthPlanner = async function () {
  await orig();
  var out = $('gp-output'), p = plan();
  if (!out) return;
  if (!wanted()) return;
  if (!p || !(p.monthly > 0)) { out.insertAdjacentHTML('beforeend', '<div class="ir-not-connected" style="margin-top:14px">The Incentive planner has no spend yet. Fill it in on the Incentive planner page first.</div>'); return; }
  if (!out.querySelector('.stat-grid') || typeof irGrowthBody !== 'function') return;     // the projection itself failed
  var withRes = null, withoutRes = null;
  try {
    withoutRes = await irApi('admin_growth_planner', irGrowthBody(0));
    withRes = await irApi('admin_growth_planner', irGrowthBody(p.monthly));
  } catch (e) { return; }
  if (!withRes || !withRes.ok || !withoutRes || !withoutRes.ok) return;
  var A = withoutRes.data, B = withRes.data, a = A.periods.month, b = B.periods.month;
  var beA = A.scenario.break_even_trips_per_day, beB = B.scenario.break_even_trips_per_day;
  var profitBefore = a.net_profit;                       // monthly profit at this scale before the incentive spend
  var paybackText = profitBefore > 0 ? (p.total / profitBefore).toFixed(1) + ' months of profit (before incentives) repay the ETB ' + whole(p.total) + ' spent over the ' + p.months + '-month plan.' : 'At this scale there is no profit before incentives, so the incentive money is not repaid yet. Raise the trips or the commission, or lower the spend.';
  var html = '<div class="section-title" style="font-size:14px;margin-top:22px">With and without the incentive &amp; marketing plan (one month)</div>' +
    '<div class="ir-tablewrap"><table class="ir-table"><thead><tr><th></th><th class="ir-num">Without incentives</th><th class="ir-num">With incentives</th><th class="ir-num">Difference</th></tr></thead><tbody>' +
    row('Monthly costs (ETB)', A.scenario.monthly_costs, B.scenario.monthly_costs, money, true) +
    row('Break-even trips a day', beA == null ? 0 : beA, beB == null ? 0 : beB, whole, true) +
    row('Net profit (ETB)', a.net_profit, b.net_profit, money, false) +
    row('Investor pool entitlement (20%)', a.pool_entitlement, b.pool_entitlement, money, false) +
    row('Total investor payout', a.total_investor_payout, b.total_investor_payout, money, false) +
    '</tbody></table></div>' +
    '<div class="desc" style="margin-top:8px"><b>What it takes to pay for the incentives:</b> ' + (beA != null && beB != null ? 'about <b>' + whole(Math.max(0, beB - beA)) + ' more trips a day</b> (' + whole(beA) + ' &rarr; ' + whole(beB) + ') to cover the same costs. ' : '') + esc(paybackText) + ' The plan itself costs ETB ' + whole(p.monthly) + ' a month: marketing ' + whole(p.marketing) + ', driver bonuses ' + whole(p.bonus) + ', referrals ' + whole(p.referral) + ', rider promotions ' + whole(p.promotion) + '. Cost to win one driver ETB ' + whole(p.perDriver) + ', one rider ETB ' + whole(p.perRider) + '.</div>' +
    '<div class="desc" style="margin-top:6px">Incentives are normally paid during the launch period and stop after it, so the &ldquo;with incentives&rdquo; column is the heavy-spending case. The projection above includes the incentive spend; choose &ldquo;Not included&rdquo; to see the steady-state projection.</div>';
  out.insertAdjacentHTML('beforeend', html);
};
})();
