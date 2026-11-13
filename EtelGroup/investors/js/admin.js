/* ============================================================
   Investor Relations — Admin dashboard + setup wizard JS
   Reuses EtelGroup's own admin login (../../api/boot.php?action=admin_login)
   and stores the token under the same 'ega_token' localStorage key
   EtelGroup admin itself uses, so an existing EtelGroup admin session
   in this browser is picked up automatically.
   ============================================================ */

const IR_API = 'api/boot.php';
const EG_LOGIN_API = '../api/boot.php';

function irGetToken() { return localStorage.getItem('ega_token') || ''; }
function irSetToken(t) { localStorage.setItem('ega_token', t); }
function irClearToken() { localStorage.removeItem('ega_token'); }
function irEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function irMoney(n) { return Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

async function irApi(action, bodyObj = {}, base = IR_API) {
  let res;
  try {
    res = await fetch(base + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + irGetToken() },
      body: JSON.stringify(bodyObj),
    });
  } catch (e) {
    return { ok: false, error: 'Network error - check your connection' };
  }
  let data;
  try { data = await res.json(); } catch (e) { return { ok: false, error: 'Unexpected server response' }; }
  if (res.status === 401) { irClearToken(); irShowLogin(); }
  return data;
}

// ---------- login ----------
async function irDoLogin() {
  const identifier = document.getElementById('login-identifier').value.trim();
  const password = document.getElementById('login-password').value;
  const errEl = document.getElementById('login-err');
  const btn = document.getElementById('login-btn');
  errEl.textContent = '';
  if (!identifier || !password) { errEl.textContent = 'Enter your EtelID phone/identifier and password.'; return; }
  btn.disabled = true; btn.textContent = 'Signing in...';
  const res = await irApi('admin_login', { identifier, password }, EG_LOGIN_API);
  btn.disabled = false; btn.textContent = 'Sign In with EtelID';
  if (!res.ok) { errEl.textContent = res.error || 'Login failed'; return; }
  irSetToken(res.data.token);
  irShowShell();
}
function irShowLogin() {
  document.getElementById('login-screen').style.display = 'flex';
  document.getElementById('shell').style.display = 'none';
}
function irShowShell() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('shell').style.display = 'block';
  irBoot();
}
function irLogout() { irClearToken(); irShowLogin(); }

document.addEventListener('DOMContentLoaded', () => {
  if (irGetToken()) irShowShell(); else irShowLogin();
});

// ---------- boot: decide wizard vs dashboard ----------
let IR_STATUS = null;
async function irBoot() {
  const res = await irApi('setup_get_status');
  if (!res.ok) { alert(res.error || 'Could not load status'); return; }
  IR_STATUS = res.data;
  document.getElementById('demo-toggle-btn').textContent = IR_STATUS.demo_mode ? '🟠 Demo Mode: ON' : '⚪ Demo Mode: OFF';
  document.getElementById('demo-banner').style.display = IR_STATUS.demo_mode ? 'flex' : 'none';

  const needsSetup = !IR_STATUS.delivery_connected || IR_STATUS.cost_category_count === 0;
  document.getElementById('wizard').style.display = needsSetup ? 'block' : 'none';
  document.getElementById('dashboard').style.display = needsSetup ? 'none' : 'block';
  if (needsSetup) {
    irWizardStep(1);
  } else {
    irLoadDashboard();
  }
}

async function irToggleDemo() {
  if (IR_STATUS.demo_mode) {
    if (!confirm('This clears all DEMO investors, purchases, and the sample distribution period. Continue?')) return;
    const res = await irApi('setup_clear_demo');
    if (!res.ok) { alert(res.error); return; }
  } else {
    const res = await irApi('setup_seed_demo');
    if (!res.ok) { alert(res.error); return; }
  }
  irBoot();
}

// ---------- wizard ----------
function irWizardStep(n) {
  document.querySelectorAll('.ir-wizard-step').forEach(el => el.classList.toggle('active', el.dataset.step == n));
  document.querySelectorAll('.ir-wizard-steps .step').forEach(el => {
    const s = Number(el.dataset.step);
    el.classList.toggle('active', s === n);
    el.classList.toggle('done', s < n);
  });
  if (n === 3 && !document.getElementById('w-cats').children.length) {
    ['Staff/Payroll', 'Office Rent', 'Payment Gateway Fees', 'Other/Contingency'].forEach(irAddCatRow);
  }
}
function irAddCatRow(name = '') {
  const wrap = document.getElementById('w-cats');
  const row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px';
  row.innerHTML = `<input type="text" class="w-cat-name" placeholder="Category name" value="${irEsc(typeof name === 'string' ? name : '')}" style="flex:2">
    <input type="number" class="w-cat-amount" placeholder="Monthly ETB" min="0" step="0.01" style="flex:1">
    <button class="btn btn-outline" onclick="this.parentElement.remove()">✕</button>`;
  wrap.appendChild(row);
}
async function irSaveConnection() {
  const host = document.getElementById('w-host').value.trim();
  const name = document.getElementById('w-name').value.trim();
  const user = document.getElementById('w-user').value.trim();
  const password = document.getElementById('w-pass').value;
  const msgEl = document.getElementById('w-conn-msg');
  const res = await irApi('setup_save_delivery_connection', { host, name, user, password });
  msgEl.style.color = res.ok ? 'var(--ok)' : 'var(--bad)';
  msgEl.textContent = res.ok ? '✓ Saved' : (res.error || 'Could not save');
}
async function irTestConnection() {
  const msgEl = document.getElementById('w-conn-msg');
  msgEl.style.color = 'var(--gold2)'; msgEl.textContent = 'Testing…';
  const res = await irApi('setup_test_delivery_connection');
  msgEl.style.color = res.ok ? 'var(--ok)' : 'var(--bad)';
  msgEl.textContent = res.ok ? `✓ Connected — ${res.data.completed_rides_total} completed rides found` : (res.error || 'Connection failed');
}
async function irSaveRate() {
  const rate = Number(document.getElementById('w-rate').value);
  const res = await irApi('setup_save_commission_rate', { rate });
  if (!res.ok) { alert(res.error); return; }
  irWizardStep(3);
}
async function irSaveCats() {
  const cats = [...document.querySelectorAll('#w-cats > div')].map(row => ({
    name: row.querySelector('.w-cat-name').value.trim(),
    monthly_amount: Number(row.querySelector('.w-cat-amount').value || 0),
  })).filter(c => c.name);
  if (!cats.length) { alert('Add at least one cost category.'); return; }
  const res = await irApi('setup_save_cost_categories', { categories: cats });
  if (!res.ok) { alert(res.error); return; }
  irWizardStep(4);
}
async function irSeedDemo() {
  const res = await irApi('setup_seed_demo');
  alert(res.ok ? `Seeded ${res.data.seeded_investors} demo investors.` : (res.error || 'Failed'));
}
function irFinishWizard() { irBoot(); }

// ---------- dashboard ----------
async function irLoadDashboard() {
  if (typeof irLoadDeals === 'function') irLoadDeals();
  const res = await irApi('admin_dashboard_summary');
  if (!res.ok) { alert(res.error); return; }
  const d = res.data;
  const statsEl = document.getElementById('ir-stats');
  statsEl.innerHTML = `
    <div class="stat-card"><div class="ic">\u{1F4B0}</div><div class="label">Total invested</div><div class="val">${irMoney(d.invested_total)}</div><div class="sub">ETB from ${d.investor_count} investor${d.investor_count == 1 ? '' : 's'}</div></div>
    <div class="stat-card"><div class="ic">\u{1F3AF}</div><div class="label">Profit Shares sold</div><div class="val">${d.sold_shares} / ${d.sold_shares + d.unsold_shares}</div><div class="sub">${d.unsold_shares} still unsold</div></div>
    <div class="stat-card"><div class="ic">\u{1F465}</div><div class="label">Investors</div><div class="val">${d.investor_count}</div><div class="sub">active holders</div></div>
    <div class="stat-card"><div class="ic">\u{1F4B8}</div><div class="label">Monthly costs</div><div class="val">${irMoney(d.monthly_cost_total)}</div><div class="sub">ETB, reduces net profit</div></div>
    <div class="stat-card premium"><div class="ic">%</div><div class="label">Commission rate</div><div class="val">${(d.commission_rate * 100).toFixed(2)}%</div><div class="sub">on ride and order fares</div></div>
  `;
  document.dispatchEvent(new CustomEvent('ir:dashboard', { detail: d }));

  const milEl = document.getElementById('ir-milestone');
  if (!d.delivery.connected) {
    milEl.innerHTML = `<div class="ir-not-connected">⚠ Not connected to Delivery &amp; Ride. <a href="#" onclick="irBackToWizard();return false" style="color:var(--gold2)">Finish setup</a> to see real milestone data.</div>`;
  } else if (d.delivery.error) {
    milEl.innerHTML = `<div class="ir-not-connected">⚠ Could not read live data: ${irEsc(d.delivery.error)}</div>`;
  } else {
    const m = d.delivery.milestone;
    const nextSt = (d.stages || []).find(x => !x.unlocked);
    const target = nextSt && nextSt.unlock_trips > 0 ? nextSt.unlock_trips : 400;
    const pct = Math.min(100, Math.round((m.avg_per_day / target) * 100));
    milEl.innerHTML = `
      <div class="ir-progress-label"><span>${m.avg_per_day} trips/day avg</span><span>${nextSt ? 'Stage ' + nextSt.stage + ' target' : 'All stages open'}: ${target}/day</span></div>
      <div class="ir-progress-track"><div class="ir-progress-fill" style="width:${pct}%"></div></div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:6px">Window: ${m.from} → ${m.to} (28 days, ${m.total_trips} total trips) — unlocking a stage is still a manual decision below.</div>
    `;
  }

  const pt = document.getElementById('pitch-traction'); if (pt) pt.checked = !!d.pitch_show_traction;
  const trEl = document.getElementById('ir-tranches');
  const stages = d.stages || [];
  IR_STAGES = stages;
  const raiseAll = stages.reduce((n, x) => n + x.raise, 0);
  const sharesAll = stages.reduce((n, x) => n + x.shares_total, 0);
  trEl.innerHTML = stages.map(t => {
    const prevOpen = t.stage === 1 || (stages.find(x => x.stage === t.stage - 1) || {}).unlocked;
    const status = t.unlocked
      ? (t.remaining > 0 ? `<span class="pill replied">Open &middot; ${t.remaining} left</span>` : `<span class="pill replied">Sold out</span>`)
      : (t.stage > 1 && prevOpen ? `<button class="btn btn-outline" onclick="irOpenTrancheModal(${t.stage})">Unlock Stage ${t.stage}</button>` : `<span class="pill new">Locked</span>`);
    return `
    <div class="ir-tranche-row">
      <div class="name">Stage ${t.stage}</div>
      <div class="meta">${t.shares_total} shares &middot; ETB ${irInt(t.price)} each &middot; raises ETB ${irInt(t.raise)} &middot; ${t.company_pct}% of the company${t.unlock_trips ? ' &middot; opens at ' + t.unlock_trips + ' trips/day' : ''}</div>
      ${status}
      <button class="btn btn-outline" onclick="irOpenStageModal(${t.stage})">Edit</button>
    </div>`;
  }).join('') + `<div style="font-size:12px;color:var(--text-dim);margin-top:10px">All three stages: ${sharesAll} shares &middot; ETB ${irInt(raiseAll)} &middot; ${(sharesAll / 100).toFixed(1)}% of the company (the pool is 20%).</div>`;

  irLoadLaunch();
  irLoadCostCategories();
  irLoadInvestors();
  irLoadPeriods();
}
function irBackToWizard() {
  document.getElementById('wizard').style.display = 'block';
  document.getElementById('dashboard').style.display = 'none';
  irWizardStep(1);
}

// ---------- cost categories ----------
// Keyed caches so row buttons can reference a record by its (always-
// numeric, injection-safe) id rather than inline-embedding JSON.stringify
// of admin-entered strings into an HTML attribute - a category or
// investor name containing an apostrophe would otherwise break out of
// the onclick='...' attribute and allow script injection.
let IR_COST_CACHE = {};
let IR_INVESTOR_CACHE = {};

async function irLoadCostCategories() {
  const res = await irApi('admin_list_cost_categories');
  const tbody = document.querySelector('#ir-costs-table tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="4">${irEsc(res.error)}</td></tr>`; return; }
  IR_COST_CACHE = {};
  tbody.innerHTML = res.data.map(c => {
    IR_COST_CACHE[c.id] = c;
    return `
    <tr>
      <td>${irEsc(c.name)}</td>
      <td class="ir-num">${irMoney(c.monthly_amount)}</td>
      <td>${c.is_active == 1 ? '<span class="pill replied">Active</span>' : '<span class="pill new">Inactive</span>'}</td>
      <td><button class="btn btn-outline btn-sm" onclick="irOpenCostModal(IR_COST_CACHE[${c.id}])">Edit</button></td>
    </tr>`;
  }).join('') || '<tr><td colspan="4" style="color:var(--text-dim)">No cost categories yet.</td></tr>';
}
function irOpenCostModal(cat) {
  document.getElementById('cost-modal-title').textContent = cat ? 'Edit Cost Category' : 'Add Cost Category';
  document.getElementById('cost-id').value = cat ? cat.id : '';
  document.getElementById('cost-name').value = cat ? cat.name : '';
  document.getElementById('cost-amount').value = cat ? cat.monthly_amount : '';
  document.getElementById('cost-active').checked = cat ? cat.is_active == 1 : true;
  document.getElementById('cost-modal-err').textContent = '';
  document.getElementById('cost-history-body').innerHTML = '';
  document.getElementById('cost-history-btn').style.display = cat ? 'inline-flex' : 'none';
  document.getElementById('cost-delete-btn').style.display = cat ? 'inline-flex' : 'none';
  document.getElementById('cost-modal-bg').classList.add('open');
}
async function irDeleteCostCategory() {
  const id = document.getElementById('cost-id').value;
  if (!id) return;
  if (!confirm('Permanently delete this cost category? This cannot be undone.')) return;
  const res = await irApi('admin_delete_cost_category', { id });
  if (!res.ok) { document.getElementById('cost-modal-err').textContent = res.error; return; }
  irCloseModal('cost-modal-bg');
  irLoadCostCategories();
  irLoadDashboard();
}
async function irSaveCostCategory() {
  const id = document.getElementById('cost-id').value;
  const name = document.getElementById('cost-name').value.trim();
  const monthly_amount = Number(document.getElementById('cost-amount').value);
  const is_active = document.getElementById('cost-active').checked ? 1 : 0;
  const errEl = document.getElementById('cost-modal-err');
  const res = await irApi('admin_save_cost_category', { id: id || 0, name, monthly_amount, is_active });
  if (!res.ok) { errEl.textContent = res.error; return; }
  irCloseModal('cost-modal-bg');
  irLoadCostCategories();
  irLoadDashboard();
}
async function irShowCostHistory() {
  const id = document.getElementById('cost-id').value;
  const res = await irApi('admin_get_cost_category_history', { id });
  const el = document.getElementById('cost-history-body');
  if (!res.ok) { el.innerHTML = irEsc(res.error); return; }
  el.innerHTML = '<div class="field-label">Change History</div>' + (res.data.map(h =>
    `<div style="font-size:12px;color:var(--text-dim);padding:4px 0">${h.changed_at} — ${irEsc(h.changed_by)}: ${irMoney(h.old_amount)} → ${irMoney(h.new_amount)}</div>`
  ).join('') || '<div style="font-size:12px;color:var(--text-dim)">No changes yet.</div>');
}

// ---------- investors ----------
let IR_CURRENT_INVESTOR = null;
async function irLoadInvestors() {
  const res = await irApi('admin_list_investors');
  const tbody = document.querySelector('#ir-investors-table tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="7">${irEsc(res.error)}</td></tr>`; return; }
  IR_INVESTOR_CACHE = {};
  tbody.innerHTML = res.data.map(i => {
    IR_INVESTOR_CACHE[i.id] = i;
    return `
    <tr>
      <td>${irEsc(i.name)}${i.is_demo == 1 ? '<span class="ir-demo-tag">DEMO</span>' : ''}</td>
      <td class="ir-num">${i.shares_owned}</td>
      <td class="ir-num">${irMoney(i.total_invested)}</td>
      <td class="ir-num">${irMoney(i.cumulative_received)}</td>
      <td class="ir-num">${irMoney(i.remaining_principal)}</td>
      <td>${i.payback_phase ? '<span class="pill new">2.5x (payback)</span>' : '<span class="pill replied">1.0x</span>'}</td>
      <td><button class="btn btn-outline btn-sm" onclick="irOpenInvestorModal(IR_INVESTOR_CACHE[${i.id}])">Manage</button></td>
    </tr>`;
  }).join('') || '<tr><td colspan="7" style="color:var(--text-dim)">No investors yet.</td></tr>';
}
function irOpenInvestorModal(inv) {
  IR_CURRENT_INVESTOR = inv;
  document.getElementById('investor-modal-title').textContent = inv ? 'Edit Investor' : 'Add Investor';
  document.getElementById('inv-id').value = inv ? inv.id : '';
  document.getElementById('inv-name').value = inv ? inv.name : '';
  document.getElementById('inv-email').value = inv ? inv.contact_email : '';
  document.getElementById('inv-phone').value = inv ? inv.contact_phone : '';
  document.getElementById('inv-identifier-field').style.display = inv ? 'none' : 'flex';
  document.getElementById('inv-identifier').value = '';
  document.getElementById('inv-password').value = '';
  document.getElementById('inv-pw-label').textContent = inv ? 'New Password (leave blank to keep current)' : 'Password (min 6 chars)';
  document.getElementById('investor-modal-err').textContent = '';
  document.getElementById('inv-purchases-section').style.display = inv ? 'block' : 'none';
  if (inv) irLoadPurchases(inv.id);
  document.getElementById('investor-modal-bg').classList.add('open');
}
async function irSaveInvestor() {
  const id = document.getElementById('inv-id').value;
  const name = document.getElementById('inv-name').value.trim();
  const contact_email = document.getElementById('inv-email').value.trim();
  const contact_phone = document.getElementById('inv-phone').value.trim();
  const password = document.getElementById('inv-password').value;
  const errEl = document.getElementById('investor-modal-err');
  let res;
  if (id) {
    res = await irApi('admin_update_investor', { id, name, contact_email, contact_phone, password: password || undefined });
  } else {
    const login_identifier = document.getElementById('inv-identifier').value.trim();
    res = await irApi('admin_create_investor', { name, contact_email, contact_phone, login_identifier, password });
  }
  if (!res.ok) { errEl.textContent = res.error; return; }
  irCloseModal('investor-modal-bg');
  irLoadInvestors();
  irLoadDashboard();
}
async function irLoadPurchases(investorId) {
  const res = await irApi('admin_list_investor_purchases', { investor_id: investorId });
  const tbody = document.querySelector('#inv-purchases-table tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="4">${irEsc(res.error)}</td></tr>`; return; }
  tbody.innerHTML = res.data.map(p => `
    <tr><td>${p.purchase_date}</td><td class="ir-num">${p.shares}</td><td>Stage ${p.tranche_number}</td><td class="ir-num">${irMoney(p.amount_paid)}</td></tr>
  `).join('') || '<tr><td colspan="4" style="color:var(--text-dim)">No purchases yet.</td></tr>';
}
async function irAddPurchase() {
  if (!IR_CURRENT_INVESTOR) return;
  const purchase_date = document.getElementById('p-date').value;
  const shares = Number(document.getElementById('p-shares').value);
  const tranche_number = Number(document.getElementById('p-tranche').value);
  const amount_paid = Number(document.getElementById('p-amount').value);
  const res = await irApi('admin_add_share_purchase', { investor_id: IR_CURRENT_INVESTOR.id, purchase_date, shares, tranche_number, amount_paid });
  if (!res.ok) { alert(res.error); return; }
  irLoadPurchases(IR_CURRENT_INVESTOR.id);
  irLoadInvestors();
  irLoadDashboard();
}

async function irSetPitchTraction(show) {
  const res = await irApi('admin_deal_pitch_traction', { show });
  if (!res.ok) alert(res.error || 'Could not save');
}

function irInt(n) { return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }); }

// ---------- stage unlock and edit ----------
let IR_STAGES = [];
function irOpenTrancheModal(n) {
  document.getElementById('tranche-modal-n').value = n;
  document.getElementById('tranche-modal-title').textContent = 'Unlock Stage ' + n;
  document.getElementById('tranche-note').value = '';
  document.getElementById('tranche-modal-err').textContent = '';
  document.getElementById('tranche-modal-bg').classList.add('open');
}
async function irUnlockTranche() {
  const note = document.getElementById('tranche-note').value.trim();
  const tranche_number = Number(document.getElementById('tranche-modal-n').value);
  const res = await irApi('admin_unlock_tranche', { tranche_number, note });
  if (!res.ok) { document.getElementById('tranche-modal-err').textContent = res.error; return; }
  irCloseModal('tranche-modal-bg');
  irLoadDashboard();
}
function irOpenStageModal(n) {
  const t = IR_STAGES.find(x => x.stage === n); if (!t) return;
  document.getElementById('stage-n').value = n;
  document.getElementById('stage-modal-title').textContent = 'Edit Stage ' + n;
  document.getElementById('stage-shares').value = t.shares_total;
  document.getElementById('stage-price').value = t.price;
  document.getElementById('stage-price').disabled = t.sold > 0;
  document.getElementById('stage-trips').value = t.unlock_trips;
  document.getElementById('stage-trips-wrap').style.display = n === 1 ? 'none' : '';
  document.getElementById('stage-modal-err').textContent = t.sold > 0 ? t.sold + ' shares already sold at this price: the price is locked.' : '';
  document.getElementById('stage-modal-bg').classList.add('open');
}
async function irSaveStage() {
  const res = await irApi('admin_save_tranche', {
    tranche_number: Number(document.getElementById('stage-n').value),
    shares_total: Number(document.getElementById('stage-shares').value),
    price_per_share: Number(document.getElementById('stage-price').value),
    unlock_trips: Number(document.getElementById('stage-trips').value || 0),
  });
  if (!res.ok) { document.getElementById('stage-modal-err').textContent = res.error; return; }
  irCloseModal('stage-modal-bg');
  irLoadDashboard();
}

// ---------- growth & goal planner ----------
async function irRunGrowthPlanner() {
  const body = {
    registered_drivers: Number(document.getElementById('gp-drivers').value || 0),
    trips_per_driver_per_day: Number(document.getElementById('gp-trips-per-driver').value || 0),
    avg_distance_km: Number(document.getElementById('gp-distance').value || 0),
    base_fare: Number(document.getElementById('gp-base-fare').value || 0),
    per_km_rate: Number(document.getElementById('gp-per-km').value || 0),
    avg_wait_min: Number(document.getElementById('gp-wait-min').value || 0),
    wait_rate_per_min: Number(document.getElementById('gp-wait-rate').value || 0),
    pct_peak_surge: Number(document.getElementById('gp-pct-surge').value || 0),
    surge_multiplier: Number(document.getElementById('gp-surge-mult').value || 1),
    other_monthly_income: Number(document.getElementById('gp-other-income').value || 0),
    cost_scale_pct: Number(document.getElementById('gp-cost-scale').value || 100),
  };
  const el = document.getElementById('gp-output');
  el.innerHTML = 'Calculating…';
  const res = await irApi('admin_growth_planner', body);
  if (!res.ok) { el.innerHTML = `<div class="ir-not-connected">⚠ ${irEsc(res.error)}</div>`; return; }
  const d = res.data;
  const fb = d.fare_breakdown;
  const periodOrder = ['day', 'week', 'month', 'year'];
  const periodLabel = { day: 'Day', week: 'Week', month: 'Month', year: 'Year' };

  const sc = d.scenario || {};
  let html = `
    <div class="stat-grid">
      <div class="stat-card premium"><div class="label">Break-even trips / day</div><div class="val">${sc.break_even_trips_per_day == null ? '-' : Number(sc.break_even_trips_per_day).toLocaleString()}</div><div class="sub">to cover ETB ${irMoney(sc.monthly_costs || 0)} of monthly costs</div></div>
      <div class="stat-card"><div class="label">Ride Fare (base+km)</div><div class="val">${irMoney(fb.ride_fare)}</div></div>
      <div class="stat-card"><div class="label">Wait Charge</div><div class="val">${irMoney(fb.wait_charge)}</div></div>
      <div class="stat-card"><div class="label">Surge Uplift</div><div class="val">${irMoney(fb.surge_uplift)}</div></div>
      <div class="stat-card premium"><div class="label">Blended Avg Fare</div><div class="val">${irMoney(fb.blended_avg_fare)}</div></div>
    </div>
    <table class="ir-table"><thead><tr><th></th>${periodOrder.map(p => `<th class="ir-num">${periodLabel[p]}</th>`).join('')}</tr></thead><tbody>
      <tr><td>Trips</td>${periodOrder.map(p => `<td class="ir-num">${d.periods[p].trips.toLocaleString()}</td>`).join('')}</tr>
      <tr><td>Gross Fares (ETB)</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].gross_fare_total)}</td>`).join('')}</tr>
      <tr><td>Commission Revenue</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].commission_revenue)}</td>`).join('')}</tr>
      <tr><td>Total Costs</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].total_costs)}</td>`).join('')}</tr>
      <tr><td><strong>Net Profit</strong></td>${periodOrder.map(p => `<td class="ir-num"><strong>${irMoney(d.periods[p].net_profit)}</strong></td>`).join('')}</tr>
      <tr><td>Investor Pool Entitlement (20%)</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].pool_entitlement)}</td>`).join('')}</tr>
      <tr><td>Total Investor Payout</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].total_investor_payout)}</td>`).join('')}</tr>
      <tr><td>Founder Amount</td>${periodOrder.map(p => `<td class="ir-num">${irMoney(d.periods[p].founder_amount)}</td>`).join('')}</tr>
    </tbody></table>
  `;

  const monthLines = d.periods.month.lines;
  if (monthLines.length) {
    html += `
      <div class="section-title" style="font-size:14px;margin-top:18px">Projected Per-Investor Payout</div>
      <table class="ir-table"><thead><tr><th>Investor</th><th>Rate</th>${periodOrder.map(p => `<th class="ir-num">${periodLabel[p]}</th>`).join('')}</tr></thead><tbody>
        ${monthLines.map(ml => {
          const byPeriod = periodOrder.map(p => {
            const line = d.periods[p].lines.find(l => l.investor_id === ml.investor_id);
            return `<td class="ir-num">${irMoney(line ? line.total_payout : 0)}</td>`;
          }).join('');
          return `<tr><td>${irEsc(ml.investor_name)}</td><td>${ml.multiplier}x</td>${byPeriod}</tr>`;
        }).join('')}
      </tbody></table>
    `;
  } else {
    html += '<div class="ir-not-connected" style="margin-top:14px">No investors yet — add one to see their projected payout here.</div>';
  }

  el.innerHTML = html;
}

// ---------- distribution ----------
let IR_LAST_PREVIEW = null;
async function irPreviewDistribution() {
  const period_start = document.getElementById('dist-start').value;
  const period_end = document.getElementById('dist-end').value;
  const el = document.getElementById('dist-preview');
  if (!period_start || !period_end) { el.innerHTML = '<div class="ir-not-connected">Pick a start and end date.</div>'; return; }
  el.innerHTML = 'Calculating…';
  const res = await irApi('admin_preview_distribution', { period_start, period_end });
  if (!res.ok) { el.innerHTML = `<div class="ir-not-connected">⚠ ${irEsc(res.error)}</div>`; return; }
  IR_LAST_PREVIEW = { period_start, period_end };
  const r = res.data;
  el.innerHTML = `
    <div class="stat-grid">
      <div class="stat-card"><div class="label">Gross Fares</div><div class="val">${irMoney(r.gross_fare_total)}</div></div>
      <div class="stat-card"><div class="label">Commission Revenue</div><div class="val">${irMoney(r.commission_revenue)}</div></div>
      <div class="stat-card"><div class="label">Total Costs</div><div class="val">${irMoney(r.total_costs)}</div></div>
      <div class="stat-card premium"><div class="label">Net Profit</div><div class="val">${irMoney(r.net_profit)}</div></div>
      <div class="stat-card"><div class="label">Investor Payout</div><div class="val">${irMoney(r.total_investor_payout)}</div></div>
      <div class="stat-card"><div class="label">Founder Amount</div><div class="val">${irMoney(r.founder_amount)}</div></div>
    </div>
    <table class="ir-table"><thead><tr><th>Investor</th><th class="ir-num">Shares</th><th>Rate</th><th class="ir-num">Payout</th></tr></thead><tbody>
      ${r.lines.map(l => `<tr><td>#${l.investor_id}</td><td class="ir-num">${l.shares_at_time}</td><td>${l.multiplier}x</td><td class="ir-num">${irMoney(l.total_payout)}</td></tr>`).join('') || '<tr><td colspan="4" style="color:var(--text-dim)">No investors yet.</td></tr>'}
    </tbody></table>
    <button class="btn btn-primary" style="margin-top:14px" onclick="irLockDistribution()">🔒 Lock This Period</button>
  `;
}
async function irLockDistribution() {
  if (!IR_LAST_PREVIEW) return;
  if (!confirm('This permanently locks this distribution period. Continue?')) return;
  const res = await irApi('admin_run_distribution', IR_LAST_PREVIEW);
  if (!res.ok) { alert(res.error); return; }
  document.getElementById('dist-preview').innerHTML = '<div class="ir-not-connected" style="border-color:rgba(46,204,113,.4);color:var(--ok)">✓ Period locked.</div>';
  irLoadPeriods();
  irLoadInvestors();
}

async function irLoadPeriods() {
  const res = await irApi('admin_list_distribution_periods');
  const tbody = document.querySelector('#ir-periods-table tbody');
  if (!res.ok) { tbody.innerHTML = `<tr><td colspan="5">${irEsc(res.error)}</td></tr>`; return; }
  tbody.innerHTML = res.data.map(p => `
    <tr>
      <td>${p.period_start} → ${p.period_end}${p.is_demo == 1 ? '<span class="ir-demo-tag">DEMO</span>' : ''}</td>
      <td class="ir-num">${irMoney(p.net_profit)}</td>
      <td class="ir-num">${irMoney(p.total_investor_payout)}</td>
      <td class="ir-num">${irMoney(p.founder_amount)}</td>
      <td><button class="btn btn-outline btn-sm" onclick="irViewPeriod(${p.id})">View</button></td>
    </tr>
  `).join('') || '<tr><td colspan="5" style="color:var(--text-dim)">No locked periods yet.</td></tr>';
}
async function irViewPeriod(id) {
  const res = await irApi('admin_get_distribution_period', { id });
  if (!res.ok) { alert(res.error); return; }
  const p = res.data;
  document.getElementById('period-modal-title').textContent = `${p.period_start} → ${p.period_end}`;
  document.getElementById('period-modal-body').innerHTML = `
    <div class="stat-grid">
      <div class="stat-card"><div class="label">Net Profit</div><div class="val">${irMoney(p.net_profit)}</div></div>
      <div class="stat-card"><div class="label">Investor Payout</div><div class="val">${irMoney(p.total_investor_payout)}</div></div>
      <div class="stat-card"><div class="label">Founder Amount</div><div class="val">${irMoney(p.founder_amount)}</div></div>
    </div>
    <table class="ir-table"><thead><tr><th>Investor</th><th class="ir-num">Shares</th><th>Rate</th><th class="ir-num">Base</th><th class="ir-num">Bounce</th><th class="ir-num">Total</th></tr></thead><tbody>
      ${p.lines.map(l => `<tr><td>${irEsc(l.investor_name)}</td><td class="ir-num">${l.shares_at_time}</td><td>${l.multiplier}x</td><td class="ir-num">${irMoney(l.base_entitlement)}</td><td class="ir-num">${irMoney(l.bounce_share)}</td><td class="ir-num">${irMoney(l.total_payout)}</td></tr>`).join('')}
    </tbody></table>
  `;
  document.getElementById('period-modal-bg').classList.add('open');
}

function irCloseModal(id) { document.getElementById(id).classList.remove('open'); }


// ---------- launch period, launch budget, order income ----------
let IR_LAUNCH = null;
function irRenderLaunch(d) {
  IR_LAUNCH = d;
  const st = d.state || {};
  document.getElementById('ln-active').checked = !!st.switch_on;
  document.getElementById('ln-until').value = st.until || '';
  document.getElementById('ln-note').value = st.note || '';
  document.getElementById('ln-autofrom').value = st.auto_from || '';
  document.getElementById('ln-started').value = st.started_on || '';
  document.getElementById('ln-maxdays').value = st.max_days || 90;
  document.getElementById('ln-goal-in').value = st.trips_goal == null ? 150 : st.trips_goal;
  document.getElementById('ln-rule-days').textContent = st.max_days || 90;
  document.getElementById('ln-rule-goal').textContent = st.trips_goal == null ? 150 : st.trips_goal;
  const g = d.goal || {}, gb = document.getElementById('ln-goal');
  if (st.switch_on && (g.avg_per_day != null || g.error)) {
    gb.style.display = '';
    if (g.error) gb.textContent = g.error;
    else gb.innerHTML = `4-week average: <b>${irEsc(String(g.avg_per_day))}</b> trips a day` + (g.goal > 0 ? ` (goal ${irInt(g.goal)}). ` : '. ') +
      (g.reached ? '<b style="color:#4caf50">The goal is reached: end the launch period now by unticking the switch and saving.</b>' : (st.ends_by ? `Latest end by the rule: <b>${irEsc(st.ends_by)}</b>.` : ''));
  } else gb.style.display = 'none';
  const sm = d.summary;
  document.querySelector('#ln-budget tbody').innerHTML = sm.categories.map(c => `
    <tr><td>${irEsc(c.label)}</td><td class="ir-num">${irInt(c.budget)}</td><td class="ir-num">${irInt(c.spent)}${c.spent_auto ? ` <small style="color:var(--text-dim)">(${irInt(c.spent_auto)} automatic)</small>` : ''}</td>
    <td class="ir-num" style="${c.left < 0 ? 'color:#ff8a7a' : ''}">${irInt(c.left)}</td>
    <td><button class="btn btn-outline btn-sm" onclick="irEditBudget('${c.category}')">Edit</button></td></tr>`).join('') +
    `<tr><td><b>Total</b></td><td class="ir-num"><b>${irInt(sm.total_budget)}</b></td><td class="ir-num"><b>${irInt(sm.total_spent)}</b></td><td class="ir-num"><b>${irInt(sm.total_left)}</b></td><td></td></tr>`;
  const an = document.getElementById('ln-autonote');
  if (sm.auto && sm.auto.error) { an.style.display = ''; an.textContent = sm.auto.error; } else { an.style.display = 'none'; }
  const sel = document.getElementById('sp-cat');
  if (!sel.options.length) sel.innerHTML = sm.categories.map(c => `<option value="${c.category}">${irEsc(c.label)}</option>`).join('');
  const lab = {}; sm.categories.forEach(c => { lab[c.category] = c.label; });
  document.querySelector('#ln-spend tbody').innerHTML = sm.spend.map(x => `
    <tr><td>${irEsc(x.spent_on)}</td><td>${irEsc(lab[x.category] || x.category)}</td><td class="ir-num">${irInt(x.amount)}</td><td>${irEsc(x.note)}</td>
    <td><button class="btn btn-outline btn-sm" onclick="irDeleteSpend(${Number(x.id)})">Remove</button></td></tr>`).join('') || '<tr><td colspan="5" style="color:var(--text-dim)">No spends recorded yet.</td></tr>';
  const o = d.orders || {};
  document.getElementById('oi-on').checked = !!o.include_orders;
  document.getElementById('oi-rate').value = o.order_commission_rate || '';
}
async function irLoadLaunch() {
  const res = await irApi('admin_launch_get');
  if (res.ok) irRenderLaunch(res.data);
  const d = document.getElementById('sp-date'); if (d && !d.value) d.value = new Date().toISOString().slice(0, 10);
}
async function irSaveLaunch() {
  const res = await irApi('admin_launch_save', { active: document.getElementById('ln-active').checked, until: document.getElementById('ln-until').value, auto_from: document.getElementById('ln-autofrom').value, note: document.getElementById('ln-note').value.trim(),
    started_on: document.getElementById('ln-started').value, max_days: Number(document.getElementById('ln-maxdays').value || 90), trips_goal: Number(document.getElementById('ln-goal-in').value || 0) });
  const m = document.getElementById('ln-msg');
  if (!res.ok) { m.style.color = '#ff8a7a'; m.textContent = res.error; return; }
  m.style.color = ''; m.textContent = res.data.state.active ? 'Saved. Launch period is ON: payouts are paused' + (res.data.state.until ? ' until ' + res.data.state.until : '') + '.' : 'Saved. Launch period is off.';
  irRenderLaunch(res.data);
}
async function irEditBudget(cat) {
  const c = IR_LAUNCH.summary.categories.find(x => x.category === cat); if (!c) return;
  const v = prompt('Budget for ' + c.label + ' (ETB)', String(c.budget));
  if (v === null) return;
  const res = await irApi('admin_launch_budget_save', { category: cat, budget: Number(v) });
  if (!res.ok) { alert(res.error); return; }
  irRenderLaunch(res.data);
}
async function irAddSpend() {
  const res = await irApi('admin_launch_spend_add', { spent_on: document.getElementById('sp-date').value, category: document.getElementById('sp-cat').value, amount: Number(document.getElementById('sp-amount').value || 0), note: document.getElementById('sp-note').value.trim() });
  const m = document.getElementById('sp-msg');
  if (!res.ok) { m.textContent = res.error; return; }
  m.textContent = ''; document.getElementById('sp-amount').value = ''; document.getElementById('sp-note').value = '';
  irRenderLaunch(res.data);
}
async function irDeleteSpend(id) {
  if (!confirm('Remove this spend?')) return;
  const res = await irApi('admin_launch_spend_delete', { id });
  if (res.ok) irRenderLaunch(res.data); else alert(res.error);
}
async function irSaveOrders() {
  const res = await irApi('admin_orders_income_save', { include_orders: document.getElementById('oi-on').checked, order_commission_rate: document.getElementById('oi-rate').value.trim() });
  const m = document.getElementById('oi-msg');
  if (!res.ok) { m.style.color = '#ff8a7a'; m.textContent = res.error; return; }
  m.style.color = ''; m.textContent = 'Saved.'; irRenderLaunch(res.data);
}
