/* ============================================================
   ETEL Admin — Business Plan & Capital Requirement
   Persists a real hiring plan + free-text notes (previously only
   existed as chat advice, lost after the conversation). The capital
   calculation reuses REAL data already live elsewhere: real
   operational costs (platform_economics_get_costs), real fundraising
   target (equity_get_settings), and the SAME tax-bracket math already
   shipped in admin-payroll.js (prCalcTax/prBrackets - loaded on the
   same page) - never a second, drifting copy of that logic.
   ============================================================ */

let BP_HIRES = [];
let BP_PLATFORM_COSTS = null;
let BP_EQUITY_SETTINGS = null;

function bpEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
function bpFmt(n) { return 'ETB ' + Math.round(Number(n) || 0).toLocaleString(); }

// Reuses the exact same payroll math as admin-payroll.js so this
// never drifts from what that tab shows for the same salary.
function bpPayrollCostFor(grossSalary) {
  if (typeof prCalcTax !== 'function' || typeof prBrackets === 'undefined') {
    // admin-payroll.js hasn't been visited yet this session, so its
    // brackets array is empty - fall back to computing with a fresh
    // copy of the same default brackets rather than silently
    // returning zero cost.
    if (typeof prDefaultBrackets === 'function' && (!prBrackets || !prBrackets.length)) {
      prBrackets = prDefaultBrackets();
    }
  }
  const pensionable = Math.min(grossSalary, 15000);
  const pensionEr = pensionable * 0.11;
  return grossSalary + pensionEr; // true monthly cost to company, same formula as the Payroll tab
}

async function loadBusinessPlanPanel() {
  if (typeof ensureEquityPremiumCss === 'function') ensureEquityPremiumCss();
  const section = document.querySelector('#panel-business-plan .data-section');
  if (!section) return;
  section.innerHTML = `
    <div class="section-title">📃 Business Plan & Capital Requirement</div>
    <p style="font-size:13px;color:#B8B0A0;margin-bottom:18px;max-width:680px">
      A real, saved hiring plan and capital estimate — pulling your actual saved operational costs
      (Platform Economics) and actual fundraising target (Equity), so this stays grounded in real numbers
      instead of living only in old chat history.
    </p>
    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
      <button class="eq-btn primary" data-bp-tab="hiring" onclick="switchBpTab('hiring', this)">Hiring Plan</button>
      <button class="eq-btn" data-bp-tab="capital" onclick="switchBpTab('capital', this)">Capital Requirement</button>
      <button class="eq-btn" data-bp-tab="notes" onclick="switchBpTab('notes', this)">Notes</button>
    </div>
    <div id="bp-tab-body"></div>
  `;
  let pendingRevenue = null;
  try { pendingRevenue = sessionStorage.getItem('bp_pending_revenue'); } catch (e) {}
  if (pendingRevenue !== null) {
    switchBpTab('capital');
  } else {
    switchBpTab('hiring');
  }
}

function switchBpTab(tab, btnEl) {
  document.querySelectorAll('[data-bp-tab]').forEach(b => b.classList.remove('primary'));
  if (btnEl) btnEl.classList.add('primary');
  else { const m = document.querySelector(`[data-bp-tab="${tab}"]`); if (m) m.classList.add('primary'); }
  if (tab === 'hiring') loadBpHiringPlan();
  else if (tab === 'capital') loadBpCapitalCalculator();
  else loadBpNotes();
}

/* ---------- Hiring Plan ---------- */

async function loadBpHiringPlan() {
  const el = document.getElementById('bp-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('planning_list_hires');
  if (!res.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${bpEsc(res.message || 'Could not load — has migration 045 been applied?')}</div>`; return; }
  BP_HIRES = res.data.hires;
  const totalHeadcount = BP_HIRES.reduce((s, h) => s + Number(h.headcount), 0);
  const totalGross = BP_HIRES.reduce((s, h) => s + Number(h.monthly_salary) * Number(h.headcount), 0);
  const totalCost = BP_HIRES.reduce((s, h) => s + bpPayrollCostFor(Number(h.monthly_salary)) * Number(h.headcount), 0);

  el.innerHTML = `
    <div class="eq-grid" style="margin-bottom:18px">
      <div class="eq-card eq-kpi"><div class="eq-label">Planned Headcount</div><div class="eq-value">${totalHeadcount}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-label">Total Gross Payroll</div><div class="eq-value" style="font-size:19px">${bpFmt(totalGross)}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-label">Total True Cost</div><div class="eq-value" style="font-size:19px">${bpFmt(totalCost)}</div>
        <div class="eq-sub">incl. 11% employer pension</div></div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>Planned Hires</h2>
        <div style="display:flex;gap:8px">
          <button class="eq-btn" style="border-color:rgba(255,196,0,.4);color:#ffce54" onclick="resetAndSeedBpDemo()">🔄 Reset & Seed Demo Data</button>
          <button class="eq-btn primary" onclick="openBpAddHireModal()">➕ Add Role</button>
        </div>
      </div>
      <div style="overflow-x:auto"><table><thead><tr><th>Role</th><th>Salary (ea.)</th><th>Headcount</th><th>True Cost (all)</th><th>Notes</th><th>Actions</th></tr></thead><tbody>
        ${BP_HIRES.map(h => {
          const cost = bpPayrollCostFor(Number(h.monthly_salary)) * Number(h.headcount);
          return `<tr>
            <td class="hi">${bpEsc(h.role_name)}</td>
            <td class="mono">${bpFmt(h.monthly_salary)}</td>
            <td class="mono">${h.headcount}</td>
            <td class="mono hi">${bpFmt(cost)}</td>
            <td style="font-size:11.5px;color:#8a7f66">${bpEsc(h.notes || '')}</td>
            <td><button class="eq-btn danger" style="padding:6px 10px;font-size:11px" onclick="deleteBpHire(${h.id})">🗑</button></td>
          </tr>`;
        }).join('') || '<tr><td colspan="6" style="text-align:center;color:#8a7f66;padding:16px">No planned hires yet — add your first role above.</td></tr>'}
      </tbody></table></div>
    </div>
  `;
}

function openBpAddHireModal() {
  openModal('custom', eqModalWrap(`
    <h2 style="margin-bottom:16px;color:#F2CC6B;font-family:'Syne',sans-serif">➕ Add Planned Hire</h2>
    <label>Role name</label>
    <input id="bp-h-role" placeholder="e.g. Operations Associate">
    <label>Monthly gross salary (ETB, per person)</label>
    <input id="bp-h-salary" type="number" min="1" placeholder="e.g. 12000">
    <label>Headcount</label>
    <input id="bp-h-count" type="number" min="1" value="1">
    <label>Notes (optional)</label>
    <input id="bp-h-notes" placeholder="e.g. Phase 1, Q1 2027">
    <button class="eq-btn primary" style="width:100%;margin-top:6px" onclick="saveBpHire()">Add to Plan</button>
  `));
}

async function saveBpHire() {
  const payload = {
    role_name: document.getElementById('bp-h-role').value.trim(),
    monthly_salary: Number(document.getElementById('bp-h-salary').value),
    headcount: Number(document.getElementById('bp-h-count').value) || 1,
    notes: document.getElementById('bp-h-notes').value.trim(),
  };
  if (!payload.role_name || !payload.monthly_salary) { alert('Enter a role name and salary'); return; }
  const res = await api('planning_add_hire', payload);
  if (!res.success) { alert(res.message || 'Could not add'); return; }
  closeModal();
  loadBpHiringPlan();
}

async function deleteBpHire(id) {
  if (!confirm('Remove this role from the hiring plan?')) return;
  const res = await api('planning_delete_hire', { id });
  if (!res.success) { alert(res.message || 'Could not delete'); return; }
  loadBpHiringPlan();
}

async function resetAndSeedBpDemo() {
  if (!confirm('This deletes your CURRENT hiring plan and notes AND overwrites your Platform Economics cost assumptions with demo figures sized for a 200-driver operation. Continue?')) return;
  const res = await api('planning_reset_and_seed_demo', {});
  if (!res.success) { alert(res.message || 'Could not reset demo data'); return; }
  loadBpHiringPlan();
  // Platform Economics may already be loaded this session (lazy-load-
  // once) - force it to refresh so the newly-seeded cost assumptions
  // actually show there too, not just silently in the database.
  if (typeof FIN_LOADED !== 'undefined') FIN_LOADED['platform-econ'] = false;
}

/* ---------- Capital Requirement Calculator ---------- */

async function loadBpCapitalCalculator() {
  const el = document.getElementById('bp-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading real cost/fundraising data…</div>';

  const [hiresRes, costsRes, settingsRes] = await Promise.all([
    api('planning_list_hires'),
    api('platform_economics_get_costs'),
    api('equity_get_settings'),
  ]);
  if (!hiresRes.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${bpEsc(hiresRes.message)}</div>`; return; }
  BP_HIRES = hiresRes.data.hires;
  BP_PLATFORM_COSTS = costsRes.success ? costsRes.data.costs : null;
  BP_EQUITY_SETTINGS = settingsRes.success ? settingsRes.data.settings : null;

  // Consume (not just read) the pending value handed off by the
  // Growth & Goal Planner's "Use This Month's Revenue" button, if
  // present - kept as a manual override option, but no longer the
  // only way this gets filled in (see growthAutoRevenue below, which
  // now works even if that button was never clicked this session).
  let pendingRevenue = null;
  try {
    pendingRevenue = sessionStorage.getItem('bp_pending_revenue');
    if (pendingRevenue !== null) sessionStorage.removeItem('bp_pending_revenue');
  } catch (e) {}

  // Reads the persisted Growth & Goal Planner scenario directly (see
  // migration 047 / computeGrowthMonthlyRevenue()) - this is what
  // actually fixes "the data resets when I switch tabs": Business
  // Plan no longer depends on Platform Economics having been opened
  // this session at all, since it's reading the same saved settings
  // straight from the database. The manual button above still takes
  // priority when present (an explicit, deliberate choice from the
  // founder outranks an automatic fill).
  let growthAutoRevenue = null;
  if (pendingRevenue === null) {
    try {
      const growthRes = await api('platform_economics_get_growth_assumptions');
      if (growthRes.success && typeof computeGrowthMonthlyRevenue === 'function') {
        growthAutoRevenue = computeGrowthMonthlyRevenue(growthRes.data.assumptions);
      }
    } catch (e) {}
  }
  const revenueToShow = pendingRevenue !== null ? Number(pendingRevenue) : growthAutoRevenue;

  el.innerHTML = `
    ${pendingRevenue !== null ? `<div style="background:linear-gradient(135deg, rgba(63,206,122,.1), rgba(63,206,122,.02));border:1px solid rgba(63,206,122,.35);border-radius:12px;padding:12px 16px;font-size:12.5px;color:#a8e6bc;margin-bottom:16px">
      ✓ Filled in automatically from the Growth & Goal Planner scenario you were just viewing.
    </div>` : (growthAutoRevenue !== null ? `<div style="background:linear-gradient(135deg, rgba(212,175,55,.1), rgba(212,175,55,.02));border:1px solid rgba(212,175,55,.3);border-radius:12px;padding:12px 16px;font-size:12.5px;color:#e0c778;margin-bottom:16px">
      ✓ Filled in from your saved Growth & Goal Planner scenario (Platform Economics tab) - edit the number below if you want a different assumption just for this plan.
    </div>` : '')}
    <div class="eq-card">
      <div class="eq-card-head"><h2>Assumptions</h2></div>
      <div class="eq-grid" style="grid-template-columns:1fr 1fr">
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Assumed monthly commission revenue (ETB)</label>
          <input id="bp-c-revenue" type="number" min="0" value="${revenueToShow !== null ? Math.round(Number(revenueToShow)) : ''}" placeholder="from Growth Planner or Platform Economics" oninput="renderBpCapital()" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Desired runway (months)</label>
          <input id="bp-c-runway" type="number" min="1" value="6" oninput="renderBpCapital()" style="width:100%;padding:11px;border-radius:10px;border:1px solid rgba(212,175,55,.3);background:#191926;color:#EDEDF5;box-sizing:border-box"></div>
      </div>
      <div style="font-size:11.5px;color:#8a7f66;margin-top:4px">Auto-filled from your saved Growth Planner scenario - edit freely, or use the "Use This Month's Revenue" button on the Growth & Goal Planner (Platform Economics tab) to override with a specific snapshot.</div>
    </div>
    <div id="bp-capital-output"></div>
  `;
  renderBpCapital();
}

function renderBpCapital() {
  const out = document.getElementById('bp-capital-output');
  if (!out) return;
  const revenue = Number(document.getElementById('bp-c-revenue').value) || 0;
  const runwayMonths = Number(document.getElementById('bp-c-runway').value) || 6;

  const payrollCost = BP_HIRES.reduce((s, h) => s + bpPayrollCostFor(Number(h.monthly_salary)) * Number(h.headcount), 0);
  const c = BP_PLATFORM_COSTS || {};
  const platformFixedCosts = Number(c.monthly_customer_acquisition || 0) + Number(c.monthly_hosting_cost || 0)
    + Number(c.monthly_support_cost || 0) + Number(c.monthly_incentive_budget || 0)
    + Number(c.monthly_refunds_estimate || 0) + Number(c.monthly_admin_overhead || 0);

  const totalMonthlyCost = payrollCost + platformFixedCosts;
  const netMonthly = revenue - totalMonthlyCost;
  const deficitPerMonth = netMonthly < 0 ? Math.abs(netMonthly) : 0;
  const capitalNeeded = deficitPerMonth * runwayMonths;

  const targetRaise = BP_EQUITY_SETTINGS ? Number(BP_EQUITY_SETTINGS.target_amount) : null;

  out.innerHTML = `
    <div class="eq-grid" style="margin:16px 0">
      <div class="eq-card eq-kpi"><div class="eq-label">Payroll Cost / Month</div><div class="eq-value" style="font-size:18px">${bpFmt(payrollCost)}</div></div>
      <div class="eq-card eq-kpi"><div class="eq-label">Platform Costs / Month</div><div class="eq-value" style="font-size:18px">${bpFmt(platformFixedCosts)}</div>
        <div class="eq-sub">from Platform Economics</div></div>
      <div class="eq-card eq-kpi"><div class="eq-label">Total Monthly Cost</div><div class="eq-value" style="font-size:18px">${bpFmt(totalMonthlyCost)}</div></div>
      <div class="eq-card eq-kpi" style="border-color:${netMonthly >= 0 ? 'rgba(63,206,122,.5)' : 'rgba(255,93,108,.5)'}">
        <div class="eq-label">Net Monthly Position</div>
        <div class="eq-value" style="font-size:18px;background:none;-webkit-text-fill-color:${netMonthly >= 0 ? '#3fce7a' : '#ff5d6c'};color:${netMonthly >= 0 ? '#3fce7a' : '#ff5d6c'}">${netMonthly >= 0 ? '+' : ''}${bpFmt(netMonthly)}</div>
      </div>
    </div>
    <div class="eq-card">
      <div class="eq-card-head"><h2>Capital Required</h2></div>
      ${netMonthly >= 0 ? `
        <div style="text-align:center;padding:20px 0">
          <div style="font-size:13px;color:#8a7f66;margin-bottom:8px">At this assumed revenue, your monthly costs are already covered — no additional capital needed to sustain operations at this volume.</div>
        </div>
      ` : `
        <div style="text-align:center;padding:10px 0 20px">
          <div style="font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700">To cover ${runwayMonths} months at this shortfall</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:32px;font-weight:800;background:linear-gradient(135deg,#F2CC6B,#C8960C);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;margin-top:6px">${bpFmt(capitalNeeded)}</div>
        </div>
        ${targetRaise !== null ? `
          <div style="background:linear-gradient(135deg, rgba(90,169,255,.08), rgba(90,169,255,.02));border:1px solid rgba(90,169,255,.25);border-radius:12px;padding:14px 16px;font-size:12.5px;color:#b9d6ff;line-height:1.6">
            Your current Equity fundraising target is <b>${bpFmt(targetRaise)}</b>.
            ${targetRaise >= capitalNeeded
              ? `That covers this ${runwayMonths}-month runway estimate${targetRaise > capitalNeeded * 1.3 ? ', with meaningful room to spare.' : '.'}`
              : `That falls short of this estimate by <b>${bpFmt(capitalNeeded - targetRaise)}</b> — worth revisiting either the fundraising target, the hiring plan, or the runway assumption.`}
          </div>
        ` : ''}
      `}
      <div style="font-size:11px;color:#8a7f66;margin-top:14px;line-height:1.6">
        This is a projection from the assumptions above — it does not know your real order volume or whether
        revenue will actually reach this level. Recompute as real numbers come in from Platform Economics.
      </div>
    </div>
  `;
}

/* ---------- Notes ---------- */

async function loadBpNotes() {
  const el = document.getElementById('bp-tab-body');
  el.innerHTML = '<div class="eq-empty">Loading…</div>';
  const res = await api('planning_get_notes');
  if (!res.success) { el.innerHTML = `<div class="eq-card" style="color:#ff9aa4">${bpEsc(res.message)}</div>`; return; }
  el.innerHTML = `
    <div class="eq-card">
      <div class="eq-card-head"><h2>Planning Notes</h2>${res.data.updated_at ? `<span class="eq-tag">Saved ${bpEsc(res.data.updated_at.slice(0,16))}</span>` : ''}</div>
      <textarea id="bp-notes-text" style="width:100%;min-height:320px;padding:14px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:13.5px;line-height:1.6;font-family:inherit;box-sizing:border-box">${bpEsc(res.data.content || '')}</textarea>
      <button class="eq-btn primary" style="margin-top:12px" onclick="saveBpNotes()">💾 Save Notes</button>
    </div>
  `;
}

async function saveBpNotes() {
  const content = document.getElementById('bp-notes-text').value;
  const res = await api('planning_save_notes', { content });
  if (!res.success) { alert(res.message || 'Could not save'); return; }
  loadBpNotes();
}

// Hide this tab for anyone without planning.view
(async function hideBpNavIfNoCapability() {
  try {
    const res = await api('admin_get_my_capabilities');
    if (!res.success) return;
    const caps = res.data.capabilities || [];
    if (caps.includes('*') || caps.includes('planning.view')) return;
    const nav = document.getElementById('nav-item-business-plan');
    if (nav) nav.style.display = 'none';
  } catch (e) { /* fail open on network hiccup - server-side gating is the real boundary */ }
})();
