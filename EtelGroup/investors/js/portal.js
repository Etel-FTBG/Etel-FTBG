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
  const pct = me.total_invested > 0 ? Math.max(0, Math.min(100, me.cumulative_received / me.total_invested * 100)) : 0;
  document.getElementById('p-stats').innerHTML = `
    <div class="stat-card"><div class="ic">🪙</div><div class="label">Shares owned</div><div class="val">${ipEsc(me.shares_owned)}</div></div>
    <div class="stat-card"><div class="ic">💼</div><div class="label">Total invested (ETB)</div><div class="val">${ipMoney(me.total_invested)}</div></div>
    <div class="stat-card premium"><div class="ic">💰</div><div class="label">Cumulative received (ETB)</div><div class="val">${ipMoney(me.cumulative_received)}</div></div>
    <div class="stat-card"><div class="ic">🏁</div><div class="label">Remaining principal (ETB)</div><div class="val">${ipMoney(me.remaining_principal)}</div></div>
  `;
  document.getElementById('p-prog').style.width = pct.toFixed(1) + '%';
  document.getElementById('p-prog-pct').textContent = pct.toFixed(1) + '%';
  document.getElementById('p-prog-a').textContent = 'Received ' + ipMoney(me.cumulative_received);
  document.getElementById('p-prog-b').textContent = 'of ' + ipMoney(me.total_invested);
  const C = 2 * Math.PI * 44;
  document.getElementById('p-ring').innerHTML = `<svg viewBox="0 0 104 104"><circle cx="52" cy="52" r="44" fill="none" stroke="rgba(255,255,255,.16)" stroke-width="9"/><circle cx="52" cy="52" r="44" fill="none" stroke="#F2CC6B" stroke-width="9" stroke-linecap="round" stroke-dasharray="${(C * pct / 100).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 52 52)"/></svg><div class="t">${Math.round(pct)}%<small>recovered</small></div>`;
  document.getElementById('p-rate').innerHTML = me.payback_phase
    ? `<span class="pill warn">2.5x boosted rate</span><p style="color:var(--mute);font-size:12.5px;margin-top:10px;line-height:1.6">You're still in the payback phase — you receive a 2.5x multiplier on your pro-rata pool share until your cumulative distributions reach your invested principal.</p>`
    : `<span class="pill replied">1.0x standard rate</span><p style="color:var(--mute);font-size:12.5px;margin-top:10px;line-height:1.6">You've fully recovered your invested principal — you now receive the standard 1.0x rate on all future distributions.</p>`;

  const hRes = await ipApi('investor_payout_history');
  const hBody = document.querySelector('#p-history-table tbody');
  if (hRes.ok) {
    ipChart(hRes.data);
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

  ipLoadLaunch();

  const pRes = await ipApi('investor_purchase_history');
  const pBody = document.querySelector('#p-purchases-table tbody');
  if (pRes.ok) {
    pBody.innerHTML = pRes.data.map(p => `
      <tr><td>${p.purchase_date}</td><td class="ir-num">${p.shares}</td><td>Stage ${p.tranche_number}</td><td class="ir-num">${ipMoney(p.amount_paid)}</td></tr>
    `).join('') || '<tr><td colspan="4" style="color:var(--text-dim)">No purchases recorded yet.</td></tr>';
  }
}

function ipChart(rows) {
  const el = document.getElementById('p-chart');
  const d = (rows || []).slice().reverse().slice(-12);
  if (!d.length) { el.innerHTML = '<p style="color:var(--mute);font-size:12.5px">Your payouts will appear here after the first distribution.</p>'; return; }
  const W = 360, H = 150, pad = 18, max = Math.max(1, ...d.map(r => Number(r.total_payout) || 0));
  const bw = (W - pad * 2) / d.length;
  let g = '';
  d.forEach((r, i) => {
    const v = Number(r.total_payout) || 0, h = Math.round((H - 44) * v / max), x = pad + i * bw + bw * .18, y = H - 22 - h;
    g += `<rect x="${x.toFixed(1)}" y="${y}" width="${(bw * .64).toFixed(1)}" height="${Math.max(h, 2)}" rx="4" fill="url(#pg)"><title>${ipEsc(r.period_start)}: ${ipMoney(v)}</title></rect>`;
    g += `<text class="ax" x="${(x + bw * .32).toFixed(1)}" y="${H - 8}" text-anchor="middle">${ipEsc(String(r.period_start).slice(5, 7))}</text>`;
  });
  el.innerHTML = `<svg viewBox="0 0 ${W} ${H}"><defs><linearGradient id="pg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f1d36a"/><stop offset="1" stop-color="#b8921f"/></linearGradient></defs><line x1="${pad}" y1="${H - 22}" x2="${W - pad}" y2="${H - 22}" stroke="currentColor" opacity=".18"/>${g}</svg>`;
}


// Launch period banner and the launch budget (read only).
async function ipLoadLaunch() {
  const res = await ipApi('investor_launch');
  if (!res.ok) return;
  const st = res.data.state, sm = res.data.summary, ban = document.getElementById('p-launch');
  if (st.active) {
    ban.style.display = '';
    ban.innerHTML = '<b>Launch period</b> &middot; ' + (st.until ? 'until ' + ipEsc(st.until) + '. ' : '') +
      'The raise is being spent on growth, and no profit is paid during this time. ' + (st.note ? ipEsc(st.note) : 'Payouts start after the launch period, once there is profit.');
  } else { ban.style.display = 'none'; }
  const card = document.getElementById('p-launch-card');
  const any = sm.total_budget > 0 || sm.spend.length;
  card.style.display = any ? '' : 'none';
  if (!any) return;
  const money0 = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
  document.querySelector('#p-budget-table tbody').innerHTML = sm.categories.map(c =>
    `<tr><td>${ipEsc(c.label)}</td><td class="ir-num">${money0(c.budget)}</td><td class="ir-num">${money0(c.spent)}</td><td class="ir-num">${money0(c.left)}</td></tr>`).join('') +
    `<tr><td><b>Total</b></td><td class="ir-num"><b>${money0(sm.total_budget)}</b></td><td class="ir-num"><b>${money0(sm.total_spent)}</b></td><td class="ir-num"><b>${money0(sm.total_left)}</b></td></tr>`;
  const lab = {}; sm.categories.forEach(c => { lab[c.category] = c.label; });
  document.querySelector('#p-spend-table tbody').innerHTML = sm.spend.map(x =>
    `<tr><td>${ipEsc(x.spent_on)}</td><td>${ipEsc(lab[x.category] || x.category)}</td><td class="ir-num">${money0(x.amount)}</td><td>${ipEsc(x.note)}</td></tr>`).join('') ||
    '<tr><td colspan="4" style="color:var(--text-dim)">No spending recorded yet.</td></tr>';
}
