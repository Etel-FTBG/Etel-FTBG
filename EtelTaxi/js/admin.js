/* ============================================================
   ETEL TAXI — admin.html logic
   ============================================================ */

async function adminEtelIdLogin() {
  const identifier = document.getElementById('admin-etelid-identifier').value.trim();
  const password = document.getElementById('admin-etelid-password').value.trim();
  if (!identifier || !password) return taxiToast('Enter your EtelID and password', 'err');
  const res = await taxiApi('admin_etelid_login', { identifier, password }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiSetToken('admin', res.data.token);
  showAdminShell();
}

async function adminLogin() {
  const phone = document.getElementById('admin-login-phone').value.trim();
  const pin = document.getElementById('admin-login-pin').value.trim();
  if (!phone || !pin) return taxiToast('Enter phone and PIN', 'err');
  const res = await taxiApi('admin_login', { phone, pin }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiSetToken('admin', res.data.token);
  showAdminShell();
}

function adminLogout() {
  taxiClearToken('admin');
  document.getElementById('shell').style.display = 'none';
  document.getElementById('login-screen').style.display = 'block';
}

function showAdminShell() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('shell').style.display = 'flex';
  loadDashboard();
}

function switchPanel(name, btn) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + name).classList.add('active');
  document.querySelectorAll('.side-link').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ({ dashboard: loadDashboard, drivers: loadDrivers, pending: loadPending, overdue: loadOverdue, bank: loadBank, associations: loadAssociations, officers: loadOfficers, roles: loadRolesPanel })[name]();
}

async function loadDashboard() {
  const el = document.getElementById('stat-grid');
  el.innerHTML = Array(4).fill('<div class="stat-card"><div class="shimmer" style="width:44px;height:44px;border-radius:12px"></div><div style="flex:1"><div class="shimmer" style="width:50%;height:22px;margin-bottom:6px"></div><div class="shimmer" style="width:80%;height:11px"></div></div></div>').join('');
  const res = await taxiApi('admin_get_dashboard_stats', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  const stats = [
    { icon: '🚕', value: res.data.total_drivers, label: 'Total Drivers' },
    { icon: '⏳', value: res.data.pending_payments, label: 'Pending Payments' },
    { icon: '⚠️', value: res.data.overdue_count, label: 'Overdue Drivers' },
    { icon: '🧾', value: res.data.association_count, label: 'Associations' },
  ];
  el.innerHTML = stats.map(s => `
    <div class="stat-card tilt">
      <div class="stat-icon">${s.icon}</div>
      <div><div class="stat-value">${s.value}</div><div class="stat-label">${s.label}</div></div>
    </div>
  `).join('');
  taxiInitTilt();
  loadRevenueTrend();
}

let TAXI_REVENUE_CHART = null;
async function loadRevenueTrend() {
  const res = await taxiApi('admin_get_revenue_trend', {}, 'admin');
  if (!res.ok) return;
  const days = res.data.days;
  const ctx = document.getElementById('revenue-chart').getContext('2d');
  const labels = days.map(d => d.date.slice(5));
  const totals = days.map(d => d.total);
  if (TAXI_REVENUE_CHART) TAXI_REVENUE_CHART.destroy();
  TAXI_REVENUE_CHART = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'ETB collected',
        data: totals,
        backgroundColor: 'rgba(212,175,55,.55)',
        borderColor: '#D4AF37',
        borderWidth: 1,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#A8A8B8', font: { size: 10 }, maxTicksLimit: 15 } },
        y: { beginAtZero: true, ticks: { color: '#A8A8B8', font: { size: 10 } }, grid: { color: 'rgba(212,175,55,.08)' } },
      },
    },
  });
}

function exportDriversCsv() {
  const token = taxiGetToken('admin');
  window.open(TAXI.apiBase + '?action=admin_export_drivers_csv&token=' + encodeURIComponent(token), '_blank');
}

async function loadDriverFormAssociations() {
  const sel = document.getElementById('nd-assoc');
  const res = await taxiApi('admin_list_associations', {}, 'admin');
  if (!res.ok || !res.data.associations.length) {
    sel.innerHTML = '<option value="">No associations yet - add one first</option>';
    return;
  }
  sel.innerHTML = '<option value="">Choose association</option>' + res.data.associations.map(a =>
    `<option value="${a.id}">${a.name_en || a.name_am}</option>`).join('');
}

async function loadDrivers() {
  loadDriverFormAssociations();
  const el = document.getElementById('drivers-table');
  const res = await taxiApi('admin_list_drivers', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  if (!res.data.drivers.length) { el.innerHTML = '<div class="card">No drivers registered yet.</div>'; return; }
  el.innerHTML = `<table><thead><tr><th>Name</th><th>Phone</th><th>Plate</th><th>Association</th><th>Status</th><th>Identity</th></tr></thead><tbody>${
    res.data.drivers.map(d => `<tr${d.identity_match === 'mismatch' ? ' style="background:rgba(220,50,50,.08)"' : ''}>
      <td>${taxiEsc(d.full_name)}</td><td>${taxiEsc(d.phone)}</td><td>${taxiEsc(d.vehicle_plate)}</td><td>${taxiEsc(d.association_name)}</td>
      <td><span class="pill ${d.permit_status}">${taxiStatusLabel(d.permit_status)}</span></td>
      <td>${d.identity_match === 'matched' ? '✅ Matched' : d.identity_match === 'mismatch' ? '⚠️ Mismatch - review' : '— Unverified'}</td>
    </tr>`).join('')
  }</tbody></table>`;
}

async function registerDriver() {
  const full_name = document.getElementById('nd-name').value.trim();
  const phone = document.getElementById('nd-phone').value.trim();
  const vehicle_plate = document.getElementById('nd-plate').value.trim();
  const association_id = document.getElementById('nd-assoc').value;
  const license_full_name = document.getElementById('nd-license-name').value.trim();
  const license_number = document.getElementById('nd-license-num').value.trim();
  const license_dob = document.getElementById('nd-license-dob').value;
  if (!full_name || !phone || !vehicle_plate || !association_id) return taxiToast('Fill in name, phone, plate, and association', 'err');
  if (!license_full_name || !license_number || !license_dob) return taxiToast('Fill in the license name, number, and date of birth', 'err');
  const res = await taxiApi('admin_register_driver', { full_name, phone, vehicle_plate, association_id, license_full_name, license_number, license_dob }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast(res.data.message || `Driver added. Starter PIN: ${res.data.starter_pin} - give this to the driver.`, res.data.identity_match === 'mismatch' ? 'err' : 'ok');
  document.getElementById('nd-name').value = ''; document.getElementById('nd-phone').value = ''; document.getElementById('nd-plate').value = '';
  document.getElementById('nd-license-name').value = ''; document.getElementById('nd-license-num').value = ''; document.getElementById('nd-license-dob').value = '';
  loadDrivers();
}

let TAXI_ADMIN_PENDING_PAYMENTS = [];

async function loadPending() {
  const el = document.getElementById('pending-table');
  const res = await taxiApi('admin_list_pending_payments', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  if (!res.data.payments.length) { el.innerHTML = '<div class="card">No pending payments.</div>'; return; }
  TAXI_ADMIN_PENDING_PAYMENTS = res.data.payments;
  el.innerHTML = `<table><thead><tr><th>Driver</th><th>Period</th><th>Amount</th><th>Method</th><th>Reference</th><th></th></tr></thead><tbody>${
    res.data.payments.map((p, i) => `<tr>
      <td>${taxiEsc(p.full_name)}<br><span style="color:var(--text-dim);font-size:11px">${taxiEsc(p.vehicle_plate)}</span></td>
      <td>${p.period_month}</td>
      <td>ETB ${Number(p.amount).toFixed(0)}${p.penalty_amount > 0 ? ' +' + Number(p.penalty_amount).toFixed(0) : ''}</td>
      <td>${taxiEsc(p.payment_method)}</td><td>${taxiEsc(p.reference_number) || '-'}</td>
      <td style="white-space:nowrap">
        <button class="btn btn-primary" style="padding:6px 12px;font-size:12px" onclick="verifyPayment(${p.id})">Verify</button>
        <button class="btn btn-outline" style="padding:6px 12px;font-size:12px" onclick="rejectPayment(${p.id})">Reject</button>
        <button class="btn btn-outline" style="padding:6px 12px;font-size:12px" onclick="taxiPrintAdminReceipt(${i})">🧾 Receipt</button>
      </td>
    </tr>`).join('')
  }</tbody></table>`;
}

function taxiPrintAdminReceipt(i) {
  const p = TAXI_ADMIN_PENDING_PAYMENTS[i];
  if (!p) return;
  taxiPrintReceipt({
    driverName: p.full_name,
    plate: p.vehicle_plate,
    association: p.association_name || '',
    period: p.period_month,
    method: p.payment_method,
    reference: p.reference_number,
    amount: p.amount,
    penalty: p.penalty_amount,
    status: p.status,
  });
}

async function verifyPayment(id) {
  const res = await taxiApi('admin_verify_payment', { id }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast('Payment verified.', 'ok');
  loadPending();
}
async function rejectPayment(id) {
  const reason = prompt('Reason for rejecting (optional):') || '';
  const res = await taxiApi('admin_reject_payment', { id, reason }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast('Payment rejected.', 'ok');
  loadPending();
}

async function loadOverdue() {
  const el = document.getElementById('overdue-table');
  const res = await taxiApi('admin_list_overdue', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  if (!res.data.overdue.length) { el.innerHTML = '<div class="card">No overdue drivers right now.</div>'; return; }
  el.innerHTML = `<table><thead><tr><th>Name</th><th>Phone</th><th>Plate</th><th>Association</th><th>Period</th><th>Penalty</th></tr></thead><tbody>${
    res.data.overdue.map(d => `<tr>
      <td>${taxiEsc(d.full_name)}</td><td>${taxiEsc(d.phone)}</td><td>${taxiEsc(d.vehicle_plate)}</td><td>${taxiEsc(d.association_name)}</td>
      <td>${d.current_period}</td><td style="color:var(--red)">ETB ${Number(d.penalty_owed).toFixed(0)}</td>
    </tr>`).join('')
  }</tbody></table>`;
}

async function loadBank() {
  const el = document.getElementById('bank-table');
  const res = await taxiApi('admin_get_bank_accounts', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  el.innerHTML = res.data.accounts.map(a => `
    <div class="card" style="margin-bottom:10px" data-id="${a.id}">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <strong style="min-width:100px">${a.display_name}</strong>
        <input type="text" class="bank-name" value="${a.account_name || ''}" placeholder="Account name" style="flex:1;min-width:120px">
        <input type="text" class="bank-number" value="${a.account_number || ''}" placeholder="Account number" style="flex:1;min-width:120px">
        <label style="display:flex;align-items:center;gap:6px;margin:0;font-weight:400;text-transform:none">
          <input type="checkbox" class="bank-active" ${a.active ? 'checked' : ''} style="width:auto"> Active
        </label>
        <button class="btn btn-primary" style="padding:8px 14px;font-size:12px" onclick="saveBankAccount(${a.id})">Save</button>
      </div>
    </div>
  `).join('');
}

async function saveBankAccount(id) {
  const row = document.querySelector(`#bank-table .card[data-id="${id}"]`);
  const account_name = row.querySelector('.bank-name').value.trim();
  const account_number = row.querySelector('.bank-number').value.trim();
  const active = row.querySelector('.bank-active').checked ? 1 : 0;
  const displayNameEl = row.querySelector('strong');
  const res = await taxiApi('admin_update_bank_account', { id, display_name: displayNameEl.textContent, account_name, account_number, active }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast('Saved.', 'ok');
}

async function loadAssociations() {
  const el = document.getElementById('associations-table');
  const res = await taxiApi('admin_list_associations', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  el.innerHTML = `<table><thead><tr><th>Name</th><th>Route</th><th>Monthly Due</th><th>Penalty</th><th>Grace Days</th></tr></thead><tbody>${
    res.data.associations.map(a => `<tr>
      <td>${a.name_am}${a.name_en ? '<br><span style="color:var(--text-dim);font-size:11px">' + a.name_en + '</span>' : ''}</td>
      <td>${a.route_am || '-'}</td>
      <td>ETB ${Number(a.monthly_due_amount).toFixed(0)}</td>
      <td>ETB ${Number(a.penalty_amount).toFixed(0)}</td>
      <td>${a.grace_period_days}</td>
    </tr>`).join('')
  }</tbody></table>`;
}

async function loadOfficers() {
  const el = document.getElementById('officers-table');
  const res = await taxiApi('admin_list_officers', {}, 'admin');
  if (!res.ok) { el.innerHTML = res.error; return; }
  if (!res.data.officers.length) { el.innerHTML = '<div class="card">No traffic officers added yet.</div>'; return; }
  el.innerHTML = `<table><thead><tr><th>Name</th><th>Phone</th><th>EtelID Linked</th><th>Added</th></tr></thead><tbody>${
    res.data.officers.map(o => `<tr>
      <td>${taxiEsc(o.full_name)}</td><td>${taxiEsc(o.phone)}</td>
      <td><span class="pill ${o.etel_id ? 'active' : 'grace_period'}">${o.etel_id ? 'Linked' : 'Not signed in yet'}</span></td>
      <td>${o.created_at}</td>
    </tr>`).join('')
  }</tbody></table>`;
}

async function registerOfficer() {
  const full_name = document.getElementById('no-name').value.trim();
  const phone = document.getElementById('no-phone').value.trim();
  if (!full_name || !phone) return taxiToast('Fill in name and phone', 'err');
  const res = await taxiApi('admin_register_officer', { full_name, phone }, 'admin');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast('Officer added. They can now sign in with their EtelID on the Verification Tool.', 'ok');
  document.getElementById('no-name').value = ''; document.getElementById('no-phone').value = '';
  loadOfficers();
}

(function init() {
  if (taxiGetToken('admin')) showAdminShell();
})();
