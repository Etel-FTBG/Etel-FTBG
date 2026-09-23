/* ============================================================
   Investor Relations — investor-facing portal JS
   Separate token/storage key from admin.js ('ir_investor_token') -
   an investor and an EtelGroup admin signed into the same browser
   never share or collide with each other's session.
   ============================================================ */

const IP_API = 'api/boot.php';

function ipGetToken() { return localStorage.getItem('ir_investor_token') || ''; }
function ipSetToken(t) { localStorage.setItem('ir_investor_token', t); }
function ipClearToken() { localStorage.removeItem('ir_investor_token'); }
function ipEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function ipMoney(n) { return Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

async function ipApi(action, bodyObj = {}) {
  let res;
  try {
    res = await fetch(IP_API + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + ipGetToken() },
      body: JSON.stringify(bodyObj),
    });
  } catch (e) {
    return { ok: false, error: 'Network error - check your connection' };
  }
  let data;
  try { data = await res.json(); } catch (e) { return { ok: false, error: 'Unexpected server response' }; }
  if (res.status === 401) { ipClearToken(); ipShowLogin(); }
  return data;
}

async function ipDoLogin() {
  const identifier = document.getElementById('login-identifier').value.trim();
  const password = document.getElementById('login-password').value;
  const errEl = document.getElementById('login-err');
  const btn = document.getElementById('login-btn');
  errEl.textContent = '';
  if (!identifier || !password) { errEl.textContent = 'Enter your login identifier and password.'; return; }
  btn.disabled = true; btn.textContent = 'Signing in...';
  const res = await ipApi('investor_login', { identifier, password });
  btn.disabled = false; btn.textContent = 'Sign In';
  if (!res.ok) { errEl.textContent = res.error || 'Login failed'; return; }
  ipSetToken(res.data.token);
  ipShowShell();
}
function ipShowLogin() {
  document.getElementById('login-screen').style.display = 'flex';
  document.getElementById('shell').style.display = 'none';
}
function ipShowShell() {
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('shell').style.display = 'block';
  ipLoadAll();
}
function ipLogout() { ipClearToken(); ipShowLogin(); }

document.addEventListener('DOMContentLoaded', () => {
  if (ipGetToken()) ipShowShell(); else ipShowLogin();
});

async function ipLoadAll() {
  const res = await ipApi('investor_me');
  if (!res.ok) { alert(res.error); return; }
  const me = res.data;
  document.getElementById('p-name').textContent = 'Welcome, ' + me.name;
  document.getElementById('p-demo-banner').style.display = me.is_demo ? 'flex' : 'none';
  document.getElementById('p-stats').innerHTML = `
    <div class="stat-card"><div class="label">Shares Owned</div><div class="val">${me.shares_owned}</div></div>
    <div class="stat-card"><div class="label">Total Invested</div><div class="val">${ipMoney(me.total_invested)}</div></div>
    <div class="stat-card premium"><div class="label">Cumulative Received</div><div class="val">${ipMoney(me.cumulative_received)}</div></div>
    <div class="stat-card"><div class="label">Remaining Principal</div><div class="val">${ipMoney(me.remaining_principal)}</div></div>
  `;
  document.getElementById('p-rate').innerHTML = me.payback_phase
    ? `<span class="pill new">2.5x Boosted Rate</span> <span style="color:var(--text-dim);font-size:12.5px;margin-left:8px">You're still in payback phase — you receive a 2.5x multiplier on your pro-rata pool share until your cumulative distributions reach your invested principal.</span>`
    : `<span class="pill replied">1.0x Standard Rate</span> <span style="color:var(--text-dim);font-size:12.5px;margin-left:8px">You've fully recovered your invested principal — you now receive the standard 1.0x rate on all future distributions.</span>`;

  const hRes = await ipApi('investor_payout_history');
  const hBody = document.querySelector('#p-history-table tbody');
  if (hRes.ok) {
    hBody.innerHTML = hRes.data.map(l => `
      <tr>
        <td>${l.period_start} → ${l.period_end}${l.is_demo == 1 ? '<span class="ir-demo-tag">DEMO</span>' : ''}</td>
        <td class="ir-num">${l.shares_at_time}</td>
        <td>${l.multiplier}x</td>
        <td class="ir-num">${ipMoney(l.total_payout)}</td>
        <td class="ir-num">${ipMoney(l.cumulative_received_after)}</td>
      </tr>
    `).join('') || '<tr><td colspan="5" style="color:var(--text-dim)">No payouts recorded yet.</td></tr>';
  }

  const pRes = await ipApi('investor_purchase_history');
  const pBody = document.querySelector('#p-purchases-table tbody');
  if (pRes.ok) {
    pBody.innerHTML = pRes.data.map(p => `
      <tr><td>${p.purchase_date}</td><td class="ir-num">${p.shares}</td><td>Tranche ${p.tranche_number}</td><td class="ir-num">${ipMoney(p.amount_paid)}</td></tr>
    `).join('') || '<tr><td colspan="4" style="color:var(--text-dim)">No purchases recorded yet.</td></tr>';
  }
}
