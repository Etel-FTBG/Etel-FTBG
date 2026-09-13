/* ============================================================
   ETEL Delivery — Platform Economics
   Real commission revenue (from completed rides) vs. real cost
   assumptions the founder enters. Reuses the .eq-* premium CSS
   classes injected by admin-equity.js (loaded first) - no separate
   stylesheet needed.
   ============================================================ */

let PE_COSTS = null;

function peFmt(n) { return 'ETB ' + Math.round(Number(n) || 0).toLocaleString(); }
function peEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

async function loadPlatformEconomics() {
  if (typeof ensureEquityPremiumCss === 'function') ensureEquityPremiumCss();
  const el = document.getElementById('pe-body');
  if (!el) return;
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const costsRes = await api('platform_economics_get_costs');
  if (!costsRes.success) {
    el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${peEsc(costsRes.message || 'Could not load — do you have platform_economics.view?')}</div>`;
    return;
  }
  PE_COSTS = costsRes.data.costs;
  el.innerHTML = `
    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
      <button class="eq-btn primary" data-pe-period="month" onclick="loadPlatformEconomicsSummary('month', this)">This Month</button>
      <button class="eq-btn" data-pe-period="week" onclick="loadPlatformEconomicsSummary('week', this)">This Week</button>
      <button class="eq-btn" data-pe-period="year" onclick="loadPlatformEconomicsSummary('year', this)">This Year</button>
      <button class="eq-btn" style="margin-left:auto" onclick="openPeCostsModal()">⚙️ Edit Cost Assumptions</button>
    </div>
    <div id="pe-summary"></div>
    <div id="pe-growth-planner" style="margin-top:26px"></div>
  `;
  loadPlatformEconomicsSummary('month');
  renderGrowthGoalPlanner();
}

async function loadPlatformEconomicsSummary(period, btnEl) {
  document.querySelectorAll('[data-pe-period]').forEach(b => b.classList.remove('primary'));
  if (btnEl) btnEl.classList.add('primary');
  const out = document.getElementById('pe-summary');
  out.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('platform_economics_get_summary', { period });
  if (!res.success) {
    out.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${peEsc(res.message || 'Could not load summary')}</div>`;
    return;
  }
  const d = res.data;
  const profitColor = d.net_platform_profit >= 0 ? '#3fce7a' : '#ff5d6c';
  const profitRingGradient = d.net_platform_profit >= 0
    ? 'conic-gradient(from 0deg,#3fce7a,#1f8f52,#123d24,#1f8f52,#3fce7a)'
    : 'conic-gradient(from 0deg,#ff5d6c,#a8222d,#4a0d12,#a8222d,#ff5d6c)';
  out.innerHTML = `
    ${d.warning ? `<div style="background:linear-gradient(135deg, rgba(255,93,108,.1), rgba(255,93,108,.03));border:1px solid rgba(255,93,108,.35);border-radius:12px;padding:14px 16px;font-size:13px;color:#ffb3ba;margin-bottom:16px">⚠️ ${peEsc(d.warning)}</div>` : ''}
    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin-bottom:18px">
      <div style="position:relative;width:150px;height:150px;flex:none;filter:drop-shadow(0 14px 22px rgba(0,0,0,.45))">
        <div style="position:absolute;inset:0;border-radius:50%;background:${profitRingGradient};animation:eqSpin3d 12s linear infinite"></div>
        <div style="position:absolute;inset:8px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#1c1608,#08050a 75%);box-shadow:inset 0 4px 12px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.05);display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center">
          <div style="font-size:9.5px;font-weight:700;letter-spacing:.06em;color:#9a9a9a;line-height:1.3;margin-bottom:4px">NET PLATFORM<br>PROFIT</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:19px;font-weight:800;color:${profitColor};filter:drop-shadow(0 0 10px ${profitColor}55)">${peFmt(d.net_platform_profit)}</div>
        </div>
      </div>
      <div class="eq-grid" style="flex:1;min-width:280px;grid-template-columns:1fr 1fr">
        <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Completed Rides</div><div class="eq-value">${d.completed_rides}</div>
          <div class="eq-sub">${d.avg_fare ? 'avg fare ' + peFmt(d.avg_fare) : ''}</div></div>
        <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Gross Fares</div><div class="eq-value">${peFmt(d.gross_fares)}</div></div>
        <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">5% Commission</div><div class="eq-value">${peFmt(d.commission_revenue)}</div>
          <div class="eq-sub">real, from completed rides</div></div>
        <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Total Costs</div><div class="eq-value">${peFmt(d.total_costs)}</div></div>
      </div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>Cost Breakdown</h2><span class="eq-tag">${d.days} days</span></div>
      <table><thead><tr><th>Item</th><th>Amount</th></tr></thead><tbody>
        <tr><td>Payment processing (${d.costs.payment_processing_pct}% of gross fares)</td><td class="mono">${peFmt(d.payment_processing_cost)}</td></tr>
        <tr><td>Fixed costs, prorated to this period</td><td class="mono">${peFmt(d.prorated_fixed_costs)}</td></tr>
        <tr><td class="hi">Total costs</td><td class="mono hi">${peFmt(d.total_costs)}</td></tr>
        <tr><td class="hi">Commission revenue</td><td class="mono hi">${peFmt(d.commission_revenue)}</td></tr>
        <tr><td class="hi">Net platform profit</td><td class="mono hi" style="color:${profitColor}">${peFmt(d.net_platform_profit)}</td></tr>
      </tbody></table>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>Breakeven</h2></div>
      ${d.breakeven_gross_fare_needed !== null ? `
        <p style="font-size:13px;color:#cabf9f;line-height:1.6">
          At an effective commission rate of <b style="color:#F2CC6B">${d.effective_commission_rate_after_processing}%</b>
          (5% minus payment processing), covering this period's fixed costs alone needs
          <b style="color:#F2CC6B">${peFmt(d.breakeven_gross_fare_needed)}</b> in gross ride fares
          ${d.breakeven_rides_needed ? `— roughly <b style="color:#F2CC6B">${d.breakeven_rides_needed.toLocaleString()} completed rides</b> at this period's average fare.` : '.'}
        </p>` : `<p style="font-size:13px;color:#ff9aa4">Payment processing cost alone meets or exceeds the 5% rate — there is no positive margin left to break even against fixed costs at all. Revisit the commission rate or processing cost.</p>`}
    </div>
  `;
}

/* ---------- Growth & Goal Planner ---------- */
// Projects revenue/profit/distribution across day/week/month/year from
// assumed registered-driver count, trips/driver/day, and average trip
// distance - NOT from real historical data (that's the summary above
// this section). Pulls real cost assumptions (PE_COSTS) and real
// equity settings/investors via the API so the distribution numbers
// shown here are always consistent with what's actually configured,
// not a second, drifting copy of those settings.

let PE_GROWTH_EQUITY = null; // { settings, investors }
let LAST_GROWTH_SCENARIO = null; // { inputs, rows, investors, pct, target } - last computed Growth Planner scenario, used by the PDF snapshot report
let GROWTH_ASSUMPTIONS = null; // the persisted scenario itself - see migration 047. Shared with Equity/Payroll/Business Plan via getSavedGrowthAssumptions() below.
let GROWTH_SAVE_TIMER = null;

/* The one function other Finance & Planning sub-tabs (Equity, Payroll,
 * Business Plan) should call to reuse the founder's last-saved Growth
 * & Goal Planner scenario, instead of quietly assuming their own
 * numbers. Returns null if nothing's loaded yet (e.g. Platform
 * Economics has never been opened this session) - callers should
 * treat that as "no scenario available" rather than guessing. */
function getSavedGrowthAssumptions() {
  return GROWTH_ASSUMPTIONS;
}

async function renderGrowthGoalPlanner() {
  const el = document.getElementById('pe-growth-planner');
  if (!el) return;
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  try {
    const [settingsRes, investorsRes, growthRes] = await Promise.all([
      api('equity_get_settings'),
      api('equity_list_investors'),
      api('platform_economics_get_growth_assumptions'),
    ]);
    PE_GROWTH_EQUITY = {
      settings: settingsRes.success ? settingsRes.data.settings : { target_amount: 0, investor_equity_pct: 20, total_shares: 0 },
      investors: investorsRes.success ? investorsRes.data.investors : [],
    };
    GROWTH_ASSUMPTIONS = growthRes.success ? growthRes.data.assumptions : null;
  } catch (e) {
    PE_GROWTH_EQUITY = { settings: { target_amount: 0, investor_equity_pct: 20, total_shares: 0 }, investors: [] };
    GROWTH_ASSUMPTIONS = null;
  }
  // Fallback to the same defaults migration 047 ships with, if the
  // saved-assumptions fetch failed outright (e.g. migration not yet
  // applied) - keeps the planner usable either way rather than blank.
  const a = GROWTH_ASSUMPTIONS || {
    registered_drivers: 20, trips_per_driver_day: 12, avg_distance_km: 5, base_fare: 120,
    per_km_rate: 30, avg_wait_min: 2, wait_rate: 3, surge_share_pct: 25, surge_multiplier: 1.2,
  };

  el.innerHTML = `
    <div class="eq-card">
      <div class="eq-card-head"><h2>🚀 Growth & Goal Planner</h2><span class="eq-tag">Assumption-based, not historical</span></div>
      <div style="font-size:12px;color:#8a7f66;margin-bottom:16px;line-height:1.5">
        Set assumed registered drivers, trips per driver per day, average trip distance, wait time,
        and peak-hour surge below. This projects gross revenue — including wait charges and surge
        uplift, the same components your real Pricing panel tracks per vehicle type — real 5%
        commission, your actual saved cost assumptions (prorated), and the actual equity distribution
        split (${peEsc(String(PE_GROWTH_EQUITY.settings.investor_equity_pct))}%
        investor pool, 30/10/40 founder split) across day/week/month/year — entirely hypothetical
        until it matches real activity in the summary above. Keep the wait-rate and surge-multiplier
        fields matched to your real Pricing panel settings so this stays a fair model, not a
        best-case fantasy.
      </div>
      <div style="font-size:11px;color:#8a7f66;margin-bottom:14px;display:flex;align-items:center;gap:8px">
        <span id="pg-save-status">💾 Saved — shared with Equity, Payroll &amp; Business Plan tabs</span>
      </div>
      <div class="eq-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:6px">
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Registered drivers</label>
          <input id="pg-drivers" type="number" min="0" value="${a.registered_drivers}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Trips / driver / day</label>
          <input id="pg-trips" type="number" min="0" step="0.1" value="${a.trips_per_driver_day}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Avg distance / trip (km)</label>
          <input id="pg-km" type="number" min="0" step="0.1" value="${a.avg_distance_km}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Avg fare (ETB, base+km)</label>
          <input id="pg-fare-preview" type="text" readonly value="—" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.15);background:#14141c;color:#8a7f66;box-sizing:border-box"></div>
      </div>
      <div style="display:flex;gap:10px;margin-bottom:16px">
        <div style="flex:1"><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Base fare (ETB)</label>
          <input id="pg-base" type="number" min="0" value="${a.base_fare}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div style="flex:1"><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Per-km rate (ETB)</label>
          <input id="pg-perkm" type="number" min="0" value="${a.per_km_rate}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
      </div>
      <div style="font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:8px;border-top:1px solid rgba(212,175,55,.15);padding-top:14px">Wait-time & surge (match your Pricing panel's real settings)</div>
      <div class="eq-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:6px">
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Avg wait time / trip (min)</label>
          <input id="pg-wait-min" type="number" min="0" step="0.1" value="${a.avg_wait_min}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Wait rate (ETB/min)</label>
          <input id="pg-wait-rate" type="number" min="0" step="0.1" value="${a.wait_rate}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">% of trips at peak/surge</label>
          <input id="pg-surge-share" type="number" min="0" max="100" step="1" value="${a.surge_share_pct}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Surge multiplier</label>
          <input id="pg-surge-mult" type="number" min="1" step="0.05" value="${a.surge_multiplier}" oninput="renderGrowthGoalOutput()" style="width:100%;padding:10px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
      </div>
      <div id="pg-output"></div>
    </div>`;
  renderGrowthGoalOutput();
}

/* Debounced (900ms after the last keystroke) so this doesn't fire an
 * API call on every single digit typed - still frequent enough that a
 * scenario is essentially always saved by the time anyone switches
 * tabs or reloads. Silent by design (small status-line update, not a
 * toast) so testing lots of "what if" numbers doesn't spam alerts. */
function scheduleGrowthAssumptionsSave() {
  const statusEl = document.getElementById('pg-save-status');
  if (statusEl) { statusEl.textContent = '⏳ Saving…'; statusEl.style.color = '#D4AF37'; }
  if (GROWTH_SAVE_TIMER) clearTimeout(GROWTH_SAVE_TIMER);
  GROWTH_SAVE_TIMER = setTimeout(async () => {
    const payload = {
      registered_drivers: Number(document.getElementById('pg-drivers').value) || 0,
      trips_per_driver_day: Number(document.getElementById('pg-trips').value) || 0,
      avg_distance_km: Number(document.getElementById('pg-km').value) || 0,
      base_fare: Number(document.getElementById('pg-base').value) || 0,
      per_km_rate: Number(document.getElementById('pg-perkm').value) || 0,
      avg_wait_min: Number(document.getElementById('pg-wait-min').value) || 0,
      wait_rate: Number(document.getElementById('pg-wait-rate').value) || 0,
      surge_share_pct: Number(document.getElementById('pg-surge-share').value) || 0,
      surge_multiplier: Number(document.getElementById('pg-surge-mult').value) || 1,
    };
    const res = await api('platform_economics_save_growth_assumptions', payload);
    const el = document.getElementById('pg-save-status');
    if (res.success) {
      GROWTH_ASSUMPTIONS = payload;
      if (el) { el.textContent = '💾 Saved — shared with Equity, Payroll & Business Plan tabs'; el.style.color = '#8a7f66'; }
    } else if (el) {
      el.textContent = '⚠ Could not save: ' + (res.message || 'unknown error');
      el.style.color = '#ff9aa4';
    }
  }, 900);
}

/* Pure function, no DOM reads - takes the same 9 saved-assumption
 * fields migration 047 persists and returns the implied monthly
 * commission revenue. Extracted out of renderGrowthGoalOutput() so
 * Business Plan (and anything else in Finance & Planning) can compute
 * the exact same number from the persisted scenario directly, instead
 * of re-deriving this formula a second time somewhere else with a
 * real risk of drifting from what Platform Economics actually shows
 * on screen. */
function computeGrowthMonthlyRevenue(a) {
  if (!a) return null;
  const rideFare = Number(a.base_fare) + Number(a.per_km_rate) * Number(a.avg_distance_km);
  const waitCharge = Number(a.avg_wait_min) * Number(a.wait_rate);
  const fareBeforeSurge = rideFare + waitCharge;
  const surgeShare = Math.min(100, Math.max(0, Number(a.surge_share_pct))) / 100;
  const surgeMult = Math.max(1, Number(a.surge_multiplier));
  const avgFare = fareBeforeSurge * (1 + surgeShare * (surgeMult - 1));
  const tripsPerDay = Number(a.registered_drivers) * Number(a.trips_per_driver_day);
  const grossMonthly = tripsPerDay * avgFare * 30;
  return grossMonthly * 0.05; // real 5% commission rate, same as everywhere else this is computed
}

function renderGrowthGoalOutput() {
  const out = document.getElementById('pg-output');
  const fareEl = document.getElementById('pg-fare-preview');
  if (!out || !PE_GROWTH_EQUITY) return;
  scheduleGrowthAssumptionsSave();
  const drivers = Number(document.getElementById('pg-drivers').value) || 0;
  const tripsPerDriver = Number(document.getElementById('pg-trips').value) || 0;
  const km = Number(document.getElementById('pg-km').value) || 0;
  const base = Number(document.getElementById('pg-base').value) || 0;
  const perKm = Number(document.getElementById('pg-perkm').value) || 0;
  const waitMin = Number(document.getElementById('pg-wait-min').value) || 0;
  const waitRate = Number(document.getElementById('pg-wait-rate').value) || 0;
  const surgeSharePct = Math.min(100, Math.max(0, Number(document.getElementById('pg-surge-share').value) || 0));
  const surgeMult = Math.max(1, Number(document.getElementById('pg-surge-mult').value) || 1);

  // Ride fare + wait charge, same components your real Pricing panel
  // tracks per vehicle type (base, per-km, wait/min). Surge is blended
  // as a weighted average: the assumed % of trips that land in a peak
  // window get the surge multiplier applied, the rest don't - this
  // mirrors how the real peak-hour schedule only applies during its
  // configured windows, not to every trip.
  const rideFare = base + perKm * km;
  const waitCharge = waitMin * waitRate;
  const fareBeforeSurge = rideFare + waitCharge;
  const surgeShare = surgeSharePct / 100;
  const avgFare = fareBeforeSurge * (1 + surgeShare * (surgeMult - 1));
  const surgeUplift = avgFare - fareBeforeSurge;
  if (fareEl) fareEl.value = peFmt(avgFare) + ' / trip (blended)';

  const tripsPerDay = drivers * tripsPerDriver;
  const grossPerDay = tripsPerDay * avgFare;
  const costs = PE_COSTS || { payment_processing_pct: 0, monthly_customer_acquisition: 0, monthly_hosting_cost: 0, monthly_support_cost: 0, monthly_incentive_budget: 0, monthly_refunds_estimate: 0, monthly_admin_overhead: 0 };
  const monthlyFixed = Number(costs.monthly_customer_acquisition) + Number(costs.monthly_hosting_cost) + Number(costs.monthly_support_cost)
    + Number(costs.monthly_incentive_budget) + Number(costs.monthly_refunds_estimate) + Number(costs.monthly_admin_overhead);
  const pct = PE_GROWTH_EQUITY.settings.investor_equity_pct;
  const investors = PE_GROWTH_EQUITY.investors;
  const target = PE_GROWTH_EQUITY.settings.target_amount;

  const periods = [
    { label: 'Day', days: 1 }, { label: 'Week', days: 7 }, { label: 'Month', days: 30 }, { label: 'Year', days: 365 },
  ];
  const rows = periods.map(p => {
    const trips = tripsPerDay * p.days;
    const gross = grossPerDay * p.days;
    const commission = gross * 0.05;
    const processingCost = gross * (Number(costs.payment_processing_pct) / 100);
    const fixedCost = monthlyFixed * (p.days / 30);
    const totalCost = processingCost + fixedCost;
    const netPlatformProfit = commission - totalCost;
    const investorPool = commission * (pct / 100);
    const founderPool = commission - investorPool;
    const company = founderPool * 0.30, charity = founderPool * 0.10, personal = founderPool * 0.40;
    return { ...p, trips, gross, commission, totalCost, netPlatformProfit, investorPool, company, charity, personal };
  });

  // Stored for the PDF snapshot report - avoids re-deriving this
  // exact scenario's numbers a second time with any risk of drifting
  // from what's actually shown on screen.
  LAST_GROWTH_SCENARIO = {
    inputs: { drivers, tripsPerDriver, km, base, perKm, waitMin, waitRate, surgeSharePct, surgeMult, avgFare, rideFare, waitCharge, surgeUplift },
    rows, investors, pct, target,
  };

  out.innerHTML = `
    <div class="eq-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Ride fare (base+km)</div><div class="eq-value" style="font-size:18px">${peFmt(rideFare)}</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Wait charge</div><div class="eq-value" style="font-size:18px">${peFmt(waitCharge)}</div>
        <div class="eq-sub">${waitMin} min × ${peFmt(waitRate)}/min</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Surge uplift</div><div class="eq-value" style="font-size:18px">${peFmt(surgeUplift)}</div>
        <div class="eq-sub">${surgeSharePct}% of trips × ${surgeMult}×</div></div>
      <div class="eq-card eq-kpi" style="margin-bottom:0;border-color:rgba(212,175,55,.5)"><div class="eq-label">Blended avg fare</div><div class="eq-value" style="font-size:18px">${peFmt(avgFare)}</div>
        <div class="eq-sub">per trip, all-in</div></div>
    </div>
    <div style="overflow-x:auto">
      <table><thead><tr><th></th>${rows.map(r => `<th>${r.label}</th>`).join('')}</tr></thead><tbody>
        <tr><td>Completed trips</td>${rows.map(r => `<td class="mono">${Math.round(r.trips).toLocaleString()}</td>`).join('')}</tr>
        <tr><td>Gross fares</td>${rows.map(r => `<td class="mono">${peFmt(r.gross)}</td>`).join('')}</tr>
        <tr><td class="hi">5% commission revenue</td>${rows.map(r => `<td class="mono hi">${peFmt(r.commission)}</td>`).join('')}</tr>
        <tr><td>Total costs</td>${rows.map(r => `<td class="mono">${peFmt(r.totalCost)}</td>`).join('')}</tr>
        <tr><td class="hi">Net platform profit</td>${rows.map(r => `<td class="mono hi" style="color:${r.netPlatformProfit >= 0 ? '#3fce7a' : '#ff5d6c'}">${peFmt(r.netPlatformProfit)}</td>`).join('')}</tr>
        <tr><td>Investor pool (${pct}%)</td>${rows.map(r => `<td class="mono">${peFmt(r.investorPool)}</td>`).join('')}</tr>
        <tr><td>Company (30% of founder's 80%)</td>${rows.map(r => `<td class="mono">${peFmt(r.company)}</td>`).join('')}</tr>
        <tr><td>Charity (10%)</td>${rows.map(r => `<td class="mono">${peFmt(r.charity)}</td>`).join('')}</tr>
        <tr><td>Personal (40%)</td>${rows.map(r => `<td class="mono">${peFmt(r.personal)}</td>`).join('')}</tr>
      </tbody></table>
    </div>
    ${investors.length ? `
    <div style="margin-top:18px">
      <div style="font-size:12.5px;color:#8a7f66;margin-bottom:8px">Per-investor share at this assumed volume:</div>
      <div style="overflow-x:auto"><table><thead><tr><th>Investor</th>${periods.map(p => `<th>${p.label}</th>`).join('')}</tr></thead><tbody>
        ${investors.map(inv => {
          const amt = Number(inv.amount_invested);
          const shareOfPool = target > 0 ? amt / target : 0;
          return `<tr><td>${peEsc(inv.name)}</td>${rows.map(r => `<td class="mono">${peFmt(r.investorPool * shareOfPool)}</td>`).join('')}</tr>`;
        }).join('')}
      </tbody></table></div>
    </div>` : '<div style="margin-top:14px;font-size:12.5px;color:#8a7f66">No investors on record yet — add one in the Equity tab to see per-investor projections here.</div>'}
    <div style="margin-top:14px;font-size:11.5px;color:#8a7f66;line-height:1.5">
      This is entirely a projection from the assumptions above — it does not know your real order volume,
      driver activity, or churn. Compare it against the real summary above as actual activity comes in,
      and adjust the assumptions rather than trusting this as a forecast.
    </div>
    <button class="eq-btn primary" style="width:100%;margin-top:16px" onclick="sendGrowthRevenueToBusinessPlan(${rows[2].commission})">
      📃 Use This Month's Revenue (${peFmt(rows[2].commission)}) in Business Plan →
    </button>
  `;
}

function sendGrowthRevenueToBusinessPlan(monthlyRevenue) {
  // Hands off the computed monthly commission figure to the Business
  // Plan tab's Capital Requirement calculator via sessionStorage
  // (survives the tab switch/DOM rebuild, cleared once consumed) so
  // the founder never has to manually read a number off one tab and
  // retype it into another.
  try { sessionStorage.setItem('bp_pending_revenue', String(monthlyRevenue)); } catch (e) {}
  // Force Business Plan to genuinely reload even if it was already
  // visited earlier this session - the lazy-load-once mechanism that
  // preserves typed state elsewhere would otherwise silently skip
  // picking up this fresh value.
  if (typeof FIN_LOADED !== 'undefined') FIN_LOADED['business-plan'] = false;
  if (typeof goToFinanceSubTab === 'function') goToFinanceSubTab('business-plan');
  if (typeof generateFinancialSnapshotPDF === 'function' && LAST_GROWTH_SCENARIO) {
    generateFinancialSnapshotPDF(LAST_GROWTH_SCENARIO);
  }
}

function openPeCostsModal() {
  const c = PE_COSTS;
  openModal('custom', eqModalWrap(`
      <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">⚙️ Cost Assumptions</h2>
      <label>Payment processing (% of gross fares)</label>
      <input id="pe-c-processing" type="number" min="0" step="0.01" value="${c.payment_processing_pct}">
      <label>Monthly customer acquisition (ETB)</label>
      <input id="pe-c-cac" type="number" min="0" value="${c.monthly_customer_acquisition}">
      <label>Monthly hosting/server cost (ETB)</label>
      <input id="pe-c-hosting" type="number" min="0" value="${c.monthly_hosting_cost}">
      <label>Monthly support cost (ETB)</label>
      <input id="pe-c-support" type="number" min="0" value="${c.monthly_support_cost}">
      <label>Monthly driver incentive budget (ETB)</label>
      <input id="pe-c-incentive" type="number" min="0" value="${c.monthly_incentive_budget}">
      <label>Monthly refunds estimate (ETB)</label>
      <input id="pe-c-refunds" type="number" min="0" value="${c.monthly_refunds_estimate}">
      <label>Monthly admin overhead (ETB)</label>
      <input id="pe-c-overhead" type="number" min="0" value="${c.monthly_admin_overhead}">
      <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="savePeCosts()">💾 Save</button>
  `));
}

async function savePeCosts() {
  const payload = {
    payment_processing_pct: Number(document.getElementById('pe-c-processing').value) || 0,
    monthly_customer_acquisition: Number(document.getElementById('pe-c-cac').value) || 0,
    monthly_hosting_cost: Number(document.getElementById('pe-c-hosting').value) || 0,
    monthly_support_cost: Number(document.getElementById('pe-c-support').value) || 0,
    monthly_incentive_budget: Number(document.getElementById('pe-c-incentive').value) || 0,
    monthly_refunds_estimate: Number(document.getElementById('pe-c-refunds').value) || 0,
    monthly_admin_overhead: Number(document.getElementById('pe-c-overhead').value) || 0,
  };
  const res = await api('platform_economics_update_costs', payload);
  if (!res.success) { alert(res.message || 'Could not save'); return; }
  closeModal();
  loadPlatformEconomics();
}

// Hide this tab for anyone without platform_economics.view
(async function hidePlatformEconNavIfNoCapability() {
  try {
    const res = await api('admin_get_my_capabilities');
    if (!res.success) return;
    const caps = res.data.capabilities || [];
    if (caps.includes('*') || caps.includes('platform_economics.view')) return;
    const nav = document.getElementById('nav-item-platform-econ');
    if (nav) nav.style.display = 'none';
  } catch (e) { /* fail open on network hiccup - server-side gating is the real boundary */ }
})();
