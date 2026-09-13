/* ============================================================
   ETEL Admin — Finance & Planning Hub
   Consolidates Equity / Platform Economics / Payroll / Business Plan
   into one nav entry with internal sub-tabs. Solves the "resets every
   time I switch tabs" problem by MOVING each panel's real DOM node
   (not cloning, not rebuilding) into this hub once, then only ever
   toggling visibility afterward - each panel's load function runs
   exactly once per page session, so typed input (Growth Planner
   assumptions, Payroll salary fields, etc.) survives switching
   between sub-tabs, something the original one-tab-at-a-time
   switchPage() always destroyed by re-calling each load function
   fresh on every activation.

   Each moved panel keeps its own id/.data-section untouched, so
   their existing load functions (loadEquityPanel, loadPlatformEconomics,
   loadPayrollPanel, loadBusinessPlanPanel) work completely unchanged -
   this file only orchestrates visibility, never their internal logic.
   ============================================================ */

const FINANCE_SUBPAGES = [
  { key: 'equity', label: '📈 Equity', panelId: 'panel-equity', loadFn: 'loadEquityPanel' },
  { key: 'platform-econ', label: '📊 Platform Economics', panelId: 'panel-platform-econ', loadFn: 'loadPlatformEconomics' },
  { key: 'payroll', label: '🧮 Payroll', panelId: 'panel-payroll', loadFn: 'loadPayrollPanel' },
  { key: 'business-plan', label: '📃 Business Plan', panelId: 'panel-business-plan', loadFn: 'loadBusinessPlanPanel' },
  { key: 'reset', label: '🔄 Reset', panelId: 'panel-finance-reset', loadFn: 'loadFinanceResetPanel', synthetic: true },
];

let FIN_MOVED = false;
let FIN_LOADED = {}; // tracks which sub-panel's load function has already run once
let FIN_CURRENT_SUB = 'equity';
let FIN_REQUESTED_SUB = null; // set by goToFinanceSubTab() before switchPage('finance') runs

// The only correct way to navigate to Equity/Platform Economics/
// Payroll/Business Plan from anywhere else in the app now that
// they're consolidated - calling switchPage('business-plan', ...)
// directly (the old standalone path) shows a panel that's now nested
// INSIDE the hidden #panel-finance container, which stays invisible
// no matter what that panel's own display is set to. This goes
// through the real Finance nav item instead, then lands on the
// requested sub-tab once the hub is (re)built.
function goToFinanceSubTab(subKey) {
  FIN_REQUESTED_SUB = subKey;
  const nav = document.getElementById('nav-item-finance');
  if (typeof switchPage === 'function') switchPage('finance', nav);
}

async function loadFinanceHub() {
  const section = document.querySelector('#panel-finance .data-section');
  if (!section) return;

  if (!FIN_MOVED) {
    // Each sub-tab has its own real permission requirement (Payroll
    // has none - open to any admin - matching its pre-existing
    // policy). Filtered once here rather than fully hiding the
    // consolidated hub for anyone who lacks just one of the four.
    let caps = ['*'];
    try {
      const capRes = await api('admin_get_my_capabilities');
      if (capRes.success) caps = capRes.data.capabilities || [];
    } catch (e) {}
    const hasCap = c => caps.includes('*') || caps.includes(c);
    const capRequired = { 'equity': 'equity.view', 'platform-econ': 'platform_economics.view', 'business-plan': 'planning.view' };
    const visibleSubpages = FINANCE_SUBPAGES.filter(sp => !capRequired[sp.key] || hasCap(capRequired[sp.key]));
    if (visibleSubpages.length && !visibleSubpages.some(sp => sp.key === FIN_CURRENT_SUB)) {
      FIN_CURRENT_SUB = visibleSubpages[0].key;
    }

    section.innerHTML = `
      <div class="section-title">💼 Finance & Planning</div>
      <p style="font-size:13px;color:#B8B0A0;margin-bottom:16px">
        Equity, Platform Economics, Payroll, and Business Plan in one place — switching between them here
        no longer resets what you've typed, since each tab loads once and just stays as you left it.
      </p>
      <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" id="fin-subnav"></div>
      <div id="fin-slots"></div>
    `;
    const subnav = document.getElementById('fin-subnav');
    const slots = document.getElementById('fin-slots');
    visibleSubpages.forEach(sp => {
      const btn = document.createElement('button');
      btn.className = 'eq-btn' + (sp.key === FIN_CURRENT_SUB ? ' primary' : '');
      btn.setAttribute('data-fin-sub', sp.key);
      btn.textContent = sp.label;
      btn.onclick = () => switchFinanceSubTab(sp.key);
      subnav.appendChild(btn);

      const realPanel = document.getElementById(sp.panelId);
      if (realPanel) {
        // Move the actual node (not a copy) - its .data-section,
        // ids, and any already-rendered content come with it intact.
        realPanel.style.display = sp.key === FIN_CURRENT_SUB ? 'block' : 'none';
        slots.appendChild(realPanel);
      } else if (sp.synthetic) {
        // Reset tab has no pre-existing top-level panel elsewhere in
        // admin.html to move (unlike the other four) - it's a hub-
        // native concept, not a separately-permissioned admin page -
        // so it's created fresh here instead.
        const panel = document.createElement('div');
        panel.className = 'panel';
        panel.id = sp.panelId;
        panel.style.display = sp.key === FIN_CURRENT_SUB ? 'block' : 'none';
        panel.innerHTML = '<div class="data-section"></div>';
        slots.appendChild(panel);
      }
    });
    FIN_MOVED = true;
  }

  // Honor a specific sub-tab request from goToFinanceSubTab() (e.g.
  // "Use This Month's Revenue" or "Add to Hiring Plan" elsewhere in
  // the app) - takes priority over whatever was previously active.
  if (FIN_REQUESTED_SUB) {
    FIN_CURRENT_SUB = FIN_REQUESTED_SUB;
    FIN_REQUESTED_SUB = null;
  }

  // Re-assert correct visibility every time this hub activates -
  // necessary because switchPage()'s blanket .panel hide-all still
  // matches these moved panels by class and will have just hidden
  // all of them, including whichever sub-tab was active.
  switchFinanceSubTab(FIN_CURRENT_SUB, /* skipButtonRerender */ true);
}

function switchFinanceSubTab(key, skipButtonRerender) {
  FIN_CURRENT_SUB = key;
  if (!skipButtonRerender) {
    document.querySelectorAll('[data-fin-sub]').forEach(b => {
      b.classList.toggle('primary', b.getAttribute('data-fin-sub') === key);
    });
  }
  FINANCE_SUBPAGES.forEach(sp => {
    const realPanel = document.getElementById(sp.panelId);
    if (!realPanel) return;
    realPanel.style.display = sp.key === key ? 'block' : 'none';
  });
  // Lazy-load: call each sub-panel's real load function exactly once,
  // the first time its tab is actually opened - never again after
  // that, which is what preserves typed input across switches.
  if (!FIN_LOADED[key]) {
    FIN_LOADED[key] = true;
    const sp = FINANCE_SUBPAGES.find(s => s.key === key);
    if (sp && typeof window[sp.loadFn] === 'function') window[sp.loadFn]();
  }
}

/* ---------- Reset tab ----------
 * Deliberately narrow in scope: only clears the Growth & Goal
 * Planner's saved assumptions (migration 047) back to defaults, so a
 * new hypothetical scenario can be built from a clean slate. Does NOT
 * touch real saved data elsewhere in Finance & Planning - not
 * investors, not platform cost assumptions, not the payroll/hiring
 * plan. That's an intentional, much smaller blast radius than the
 * separate Danger Zone tab (full system reset) - this is closer to
 * "clear my scratch pad" than anything destructive to real records. */
function loadFinanceResetPanel() {
  const section = document.querySelector('#panel-finance-reset .data-section');
  if (!section) return;
  section.innerHTML = `
    <div class="section-title">🔄 Reset Growth &amp; Goal Planner</div>
    <p style="font-size:13px;color:#B8B0A0;margin-bottom:16px;line-height:1.6">
      Clears the saved Growth &amp; Goal Planner scenario (registered drivers, trips/day, fares, wait
      time, surge) back to its starting defaults, so you can build a fresh "what if" scenario without
      last time's numbers still sitting in the fields. This is scoped to that one saved scenario only -
      your real investors, platform cost assumptions, and hiring plan are completely untouched.
    </p>
    <button class="eq-btn" style="background:#ff5d6c;color:#fff;border:none" onclick="financeResetGrowthPlanner()">
      🔄 Reset Growth &amp; Goal Planner to defaults
    </button>
    <div id="fin-reset-status" style="margin-top:12px;font-size:12.5px"></div>
  `;
}

async function financeResetGrowthPlanner() {
  const statusEl = document.getElementById('fin-reset-status');
  if (statusEl) { statusEl.style.color = '#D4AF37'; statusEl.textContent = 'Resetting…'; }
  const res = await api('platform_economics_reset_growth_assumptions');
  if (!res.success) {
    if (statusEl) { statusEl.style.color = '#ff9aa4'; statusEl.textContent = '⚠ ' + (res.message || 'Could not reset'); }
    return;
  }
  if (statusEl) { statusEl.style.color = '#3fce7a'; statusEl.textContent = '✓ Reset — showing fresh defaults now'; }
  // If Platform Economics has already been opened this session, its
  // Growth Planner DOM is sitting live in the hub right now - refresh
  // it directly rather than waiting for a future tab switch that
  // FIN_LOADED would otherwise skip (by design, for every OTHER
  // sub-tab) - this one genuinely needs to re-render immediately so
  // the reset is visibly real.
  if (FIN_LOADED['platform-econ'] && typeof renderGrowthGoalPlanner === 'function') {
    renderGrowthGoalPlanner();
  }
}
