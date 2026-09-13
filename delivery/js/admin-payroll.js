/* ============================================================
   ETEL Admin — Payroll Cost Calculator
   Rebuilt to use the shared .eq-* premium CSS system already proven
   across Equity/Platform Economics/Equb/Couriers, instead of its own
   separate design - genuine visual consistency, and reuses styling
   that's already been tested this session rather than introducing a
   second design language with its own potential bugs.
   All function/variable names stay prefixed (pr*) defensively, even
   though no collision was found with admin.js.
   ============================================================ */

let prBrackets = [];

function prEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

function prDefaultBrackets() {
  return [
    { from: 0, to: 2000, rate: 0, ded: 0 },
    { from: 2001, to: 3200, rate: 10, ded: 200 },
    { from: 3201, to: 5250, rate: 15, ded: 360 },
    { from: 5251, to: 7800, rate: 20, ded: 622.50 },
    { from: 7801, to: 10900, rate: 25, ded: 1012.50 },
    { from: 10901, to: 100000000, rate: 35, ded: 2102.50 },
  ];
}

function prRingHtml(pct, label) {
  const deg = Math.max(0, Math.min(100, pct)) * 3.6;
  return `<div style="position:relative;width:96px;height:96px;flex:none;filter:drop-shadow(0 14px 22px rgba(0,0,0,.45))">
    <div style="position:absolute;inset:0;border-radius:50%;background:conic-gradient(#3fce7a ${deg}deg, rgba(255,255,255,.08) ${deg}deg);animation:eqSpin3d 14s linear infinite"></div>
    <div style="position:absolute;inset:10px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#1c1608,#08050a 75%);box-shadow:inset 0 4px 12px rgba(0,0,0,.6);display:flex;flex-direction:column;align-items:center;justify-content:center">
      <div style="font-family:'JetBrains Mono',monospace;font-weight:800;font-size:17px;color:#3fce7a">${pct.toFixed(0)}%</div>
      <div style="font-size:8px;color:#8a7f66;text-transform:uppercase;letter-spacing:.05em;text-align:center;line-height:1.2;margin-top:2px">${label}</div>
    </div>
  </div>`;
}

function loadPayrollPanel() {
  if (typeof ensureEquityPremiumCss === 'function') ensureEquityPremiumCss();
  const section = document.querySelector('#panel-payroll .data-section');
  if (!section) return;
  section.innerHTML = `
    <div class="section-title">🧮 Ethiopia Payroll Cost Calculator</div>
    <p style="font-size:13px;color:#B8B0A0;margin-bottom:16px;max-width:680px">Salary → Pension → Tax → True cost to Etel Group. Internal planning tool — not a substitute for professional tax/accounting advice.</p>

    <div style="background:linear-gradient(135deg, rgba(255,93,108,.1), rgba(255,93,108,.03));border:1px solid rgba(255,93,108,.35);border-radius:12px;padding:14px 16px;font-size:12.5px;color:#ffb3ba;margin-bottom:20px;line-height:1.6">
      ⚠ <b>Tax bracket accuracy notice:</b> Ethiopia's monthly income-tax exemption threshold was raised to
      <b>2,000 ETB</b> under Proclamation 1395/2025 (effective July 2025), replacing the older 600 ETB threshold.
      The bracket boundaries and rates above the exemption are the older, pre-2025 schedule adjusted only at the
      exemption line — the exact new bracket cutoffs have not been independently confirmed here. <b>Verify current
      brackets with an Ethiopian accountant or ERCA before running real payroll</b> — edit the table below once you
      have confirmed figures.
    </div>

    <div class="eq-card">
      <div class="eq-card-head"><h2>Employee Details</h2></div>
      <div class="eq-grid" style="grid-template-columns:repeat(3,1fr)">
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Employee / Role Name</label>
          <input id="pr-name" placeholder="e.g. Junior PHP Developer" oninput="prCalc()" style="width:100%;padding:11px 13px;border-radius:11px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:13.5px;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Gross Monthly Salary (ETB)</label>
          <input id="pr-salary" type="number" placeholder="e.g. 20000" oninput="prCalc()" style="width:100%;padding:11px 13px;border-radius:11px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:13.5px;box-sizing:border-box"></div>
        <div><label style="display:block;font-size:11px;color:#8a7f66;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:6px">Number of Employees</label>
          <input id="pr-count" type="number" value="1" min="1" oninput="prCalc()" style="width:100%;padding:11px 13px;border-radius:11px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.3);color:#f8f1e2;font-size:13.5px;box-sizing:border-box"></div>
      </div>
    </div>

    <div class="eq-card">
      <div class="eq-card-head"><h2>Cost Overview</h2></div>
      <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;margin-bottom:18px">
        <div id="pr-ring-wrap"></div>
        <div class="eq-grid" style="flex:1;min-width:280px;grid-template-columns:repeat(2,1fr)">
          <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Employee Take-Home</div><div class="eq-value" id="pr-k-net">ETB 0</div></div>
          <div class="eq-card eq-kpi" style="margin-bottom:0"><div class="eq-label">Cost Per Employee</div><div class="eq-value" id="pr-k-cost">ETB 0</div></div>
          <div class="eq-card eq-kpi" style="margin-bottom:0;grid-column:1/-1"><div class="eq-label">Total Monthly Cost (All Employees)</div><div class="eq-value" style="font-size:26px" id="pr-k-cost-total">ETB 0</div></div>
        </div>
      </div>
      <button class="eq-btn primary" style="width:100%" onclick="prAddToHiringPlan()">📃 Add This Role to Hiring Plan →</button>
      <div id="pr-add-plan-msg" style="display:none;font-size:12.5px;margin-top:10px;padding:10px 14px;border-radius:10px"></div>
    </div>

    <div class="eq-card">
      <div class="eq-card-head"><h2>Breakdown (per employee)</h2></div>
      <table><tbody>
        <tr><td>Gross Monthly Salary</td><td class="hi mono" style="text-align:right" id="pr-b-gross">0 ETB</td></tr>
        <tr><td>− Employee Pension Contribution (7%)</td><td class="mono" style="text-align:right;color:#ff9aa4" id="pr-b-pension-emp">0 ETB</td></tr>
        <tr><td>− Employee Income Tax (withheld)</td><td class="mono" style="text-align:right;color:#ff9aa4" id="pr-b-tax">0 ETB</td></tr>
        <tr><td class="hi">= Net Pay to Employee</td><td class="hi mono" style="text-align:right" id="pr-b-net">0 ETB</td></tr>
        <tr><td style="padding-top:16px">Gross Monthly Salary</td><td class="mono" style="text-align:right;padding-top:16px" id="pr-b-gross2">0 ETB</td></tr>
        <tr><td>+ Employer Pension Contribution (11%)</td><td class="mono" style="text-align:right;color:#8ac6ff" id="pr-b-pension-er">0 ETB</td></tr>
        <tr><td class="hi">= Total Cost to Company</td><td class="hi mono" style="text-align:right" id="pr-b-total">0 ETB</td></tr>
      </tbody></table>
      <div style="background:linear-gradient(135deg, rgba(90,169,255,.08), rgba(90,169,255,.02));border:1px solid rgba(90,169,255,.25);border-radius:12px;padding:14px 16px;font-size:12.5px;color:#b9d6ff;margin-top:16px;line-height:1.6">
        Pension is calculated on salary up to <b>15,000 ETB/month</b> — the excess above that is not pensionable.
        Employer pays 11% on top of salary; employee's 7% is deducted from their own gross pay, not added by you.
      </div>
    </div>

    <div class="eq-card">
      <div class="eq-card-head"><h2>Editable Income Tax Brackets</h2><span class="eq-tag">Monthly, ETB</span></div>
      <div style="overflow-x:auto"><table><thead><tr><th>From</th><th>To</th><th>Rate %</th><th>Deduction (ETB)</th><th></th></tr></thead>
        <tbody id="pr-bracket-rows"></tbody>
      </table></div>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button class="eq-btn" onclick="prAddBracket()">+ Add Bracket</button>
        <button class="eq-btn" onclick="prResetBrackets()">↺ Reset to Default</button>
      </div>
    </div>

    <div class="eq-card">
      <div class="eq-card-head"><h2>Annual Summary (per employee)</h2></div>
      <table><tbody>
        <tr><td>Annual Gross Salary</td><td class="hi mono" style="text-align:right" id="pr-a-gross">0 ETB</td></tr>
        <tr><td>Annual Employee Pension</td><td class="mono" style="text-align:right" id="pr-a-pension-emp">0 ETB</td></tr>
        <tr><td>Annual Income Tax</td><td class="mono" style="text-align:right" id="pr-a-tax">0 ETB</td></tr>
        <tr><td class="hi">Annual Net Pay to Employee</td><td class="hi mono" style="text-align:right" id="pr-a-net">0 ETB</td></tr>
        <tr><td class="hi">Annual Total Cost to Company</td><td class="hi mono" style="text-align:right" id="pr-a-total">0 ETB</td></tr>
      </tbody></table>
    </div>
  `;
  prLoadBrackets();
  prCalc();
}

function prLoadBrackets() {
  prBrackets = prDefaultBrackets();
  prRenderBracketRows();
}

function prRenderBracketRows() {
  const wrap = document.getElementById('pr-bracket-rows');
  if (!wrap) return;
  wrap.innerHTML = prBrackets.map((b, i) => `
    <tr>
      <td><input type="number" value="${b.from}" onchange="prUpdateBracket(${i},'from',this.value)" style="width:100%;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.25);color:#f8f1e2;font-size:12.5px;box-sizing:border-box"></td>
      <td><input type="number" value="${b.to >= 100000000 ? '' : b.to}" placeholder="above" onchange="prUpdateBracket(${i},'to',this.value)" style="width:100%;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.25);color:#f8f1e2;font-size:12.5px;box-sizing:border-box"></td>
      <td><input type="number" value="${b.rate}" onchange="prUpdateBracket(${i},'rate',this.value)" style="width:100%;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.25);color:#f8f1e2;font-size:12.5px;box-sizing:border-box"></td>
      <td><input type="number" value="${b.ded}" onchange="prUpdateBracket(${i},'ded',this.value)" style="width:100%;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,.05);border:1px solid rgba(212,175,55,.25);color:#f8f1e2;font-size:12.5px;box-sizing:border-box"></td>
      <td><button class="eq-btn danger" style="padding:6px 10px;font-size:11px" onclick="prRemoveBracket(${i})">✕</button></td>
    </tr>
  `).join('');
}

function prUpdateBracket(i, field, val) {
  if (field === 'to' && val === '') { prBrackets[i].to = 100000000; }
  else { prBrackets[i][field] = Number(val); }
  prCalc();
}

function prAddBracket() {
  prBrackets.push({ from: 0, to: 100000000, rate: 0, ded: 0 });
  prRenderBracketRows();
  prCalc();
}

function prRemoveBracket(i) {
  prBrackets.splice(i, 1);
  prRenderBracketRows();
  prCalc();
}

function prResetBrackets() {
  prBrackets = prDefaultBrackets();
  prRenderBracketRows();
  prCalc();
}

function prCalcTax(gross) {
  for (const b of prBrackets) {
    if (gross >= b.from && gross <= b.to) {
      const t = gross * (b.rate / 100) - b.ded;
      return Math.max(t, 0);
    }
  }
  return 0;
}

function prFmt(n) {
  n = Math.round((n + Number.EPSILON) * 100) / 100;
  return n.toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' ETB';
}

function prCalc() {
  const salaryEl = document.getElementById('pr-salary');
  const countEl = document.getElementById('pr-count');
  if (!salaryEl || !countEl) return;
  const gross = Number(salaryEl.value) || 0;
  const count = Number(countEl.value) || 1;

  const pensionable = Math.min(gross, 15000);
  const pensionEmp = pensionable * 0.07;
  const pensionEr = pensionable * 0.11;
  const tax = prCalcTax(gross);
  const net = gross - pensionEmp - tax;
  const totalCost = gross + pensionEr;
  const netPct = gross > 0 ? (net / gross) * 100 : 0;

  const ringWrap = document.getElementById('pr-ring-wrap');
  if (ringWrap) ringWrap.innerHTML = prRingHtml(netPct, 'NET OF GROSS');

  document.getElementById('pr-k-net').textContent = prFmt(net);
  document.getElementById('pr-k-cost').textContent = prFmt(totalCost);
  document.getElementById('pr-k-cost-total').textContent = prFmt(totalCost * count);

  document.getElementById('pr-b-gross').textContent = prFmt(gross);
  document.getElementById('pr-b-pension-emp').textContent = '− ' + prFmt(pensionEmp);
  document.getElementById('pr-b-tax').textContent = '− ' + prFmt(tax);
  document.getElementById('pr-b-net').textContent = prFmt(net);
  document.getElementById('pr-b-gross2').textContent = prFmt(gross);
  document.getElementById('pr-b-pension-er').textContent = '+ ' + prFmt(pensionEr);
  document.getElementById('pr-b-total').textContent = prFmt(totalCost);

  document.getElementById('pr-a-gross').textContent = prFmt(gross * 12);
  document.getElementById('pr-a-pension-emp').textContent = prFmt(pensionEmp * 12);
  document.getElementById('pr-a-tax').textContent = prFmt(tax * 12);
  document.getElementById('pr-a-net').textContent = prFmt(net * 12);
  document.getElementById('pr-a-total').textContent = prFmt(totalCost * 12);
}

async function prAddToHiringPlan() {
  const msgEl = document.getElementById('pr-add-plan-msg');
  function showMsg(text, ok) {
    if (!msgEl) return;
    msgEl.style.display = 'block';
    msgEl.style.color = ok ? '#a8e6bc' : '#ff9aa4';
    msgEl.style.background = ok ? 'rgba(63,206,122,.1)' : 'rgba(255,93,108,.1)';
    msgEl.style.border = '1px solid ' + (ok ? 'rgba(63,206,122,.3)' : 'rgba(255,93,108,.3)');
    msgEl.textContent = text;
  }
  const roleName = (document.getElementById('pr-name').value || '').trim() || 'Untitled Role';
  const salary = Number(document.getElementById('pr-salary').value) || 0;
  const count = Number(document.getElementById('pr-count').value) || 1;
  if (salary <= 0) { showMsg('Enter a gross monthly salary above first.', false); return; }

  // Goes through the exact same planning_add_hire action the
  // Business Plan tab's own "+ Add Role" modal uses - one real,
  // persisted hire list, never a second copy that could drift out of
  // sync with what Business Plan actually shows.
  const res = await api('planning_add_hire', {
    role_name: roleName, monthly_salary: salary, headcount: count,
    notes: 'Added from Payroll tab',
  });
  if (!res.success) { showMsg(res.message || 'Could not add to hiring plan', false); return; }
  showMsg('✓ Added "' + roleName + '" to your Hiring Plan — opening it now…', true);
  setTimeout(() => {
    // Force a fresh reload so the just-added role actually shows up,
    // even if Business Plan was already visited earlier this session
    // (the lazy-load-once mechanism that preserves typed state
    // elsewhere would otherwise skip re-fetching the hire list).
    if (typeof FIN_LOADED !== 'undefined') FIN_LOADED['business-plan'] = false;
    if (typeof goToFinanceSubTab === 'function') goToFinanceSubTab('business-plan');
  }, 900);
}
