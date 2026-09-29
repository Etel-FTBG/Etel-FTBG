/* ============================================================
   ETEL TAXI — shared core JS
   ============================================================ */

const TAXI = { apiBase: 'api/boot.php' };

// ---------- theme toggle (dark/light, gold stays constant) ----------
// Own localStorage key per company, per the shared Etel color-system
// spec, so a driver's theme choice here never overrides their choice
// in Delivery or any other Etel Group app.
const TAXI_THEME_STORAGE = 'etaxi_theme';
function taxiGetTheme() {
  try { return localStorage.getItem(TAXI_THEME_STORAGE) || 'dark'; } catch (e) { return 'dark'; }
}
function taxiApplyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const label = theme === 'dark' ? '\u2600\ufe0f Light' : '\ud83c\udf19 Dark';
  document.querySelectorAll('.theme-toggle-btn').forEach(btn => { btn.textContent = label; });
}
function toggleTheme() {
  const next = taxiGetTheme() === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(TAXI_THEME_STORAGE, next); } catch (e) {}
  taxiApplyTheme(next);
}
// Apply immediately (script is loaded at end of body without defer on
// most pages here, but this still runs before paint settles) so a
// returning visitor never sees a flash of the wrong theme.
taxiApplyTheme(taxiGetTheme());
// keep other open tabs in step when the theme is switched in one of them
window.addEventListener('storage', function (ev) { if (ev.key === TAXI_THEME_STORAGE) taxiApplyTheme(taxiGetTheme()); });

// Generic per-"kind" token storage (driver/admin sessions, or the
// traffic officer's plate-lookup key) - same key format
// ("taxi_"+kind+"_token") every kind used before this one already
// produced, so this is a no-op change for existing driver/admin calls.
function taxiGetToken(kind) {
  return localStorage.getItem('taxi_' + kind + '_token') || '';
}
function taxiSetToken(kind, token) {
  localStorage.setItem('taxi_' + kind + '_token', token);
}
function taxiClearToken(kind) {
  localStorage.removeItem('taxi_' + kind + '_token');
}

// ---------- printable payment receipt (used by driver history + admin
// pending/payments tables) - opens a small popup with a clean receipt
// layout and triggers the browser print dialog. ----------
function taxiPrintReceipt(data) {
  const total = Number(data.amount || 0) + Number(data.penalty || 0);
  const win = window.open('', '_blank', 'width=420,height=640');
  if (!win) { alert('Please allow pop-ups for this site to print the receipt.'); return; }
  const rows = [
    ['Driver', data.driverName || '-'],
    ['Vehicle Plate', data.plate || '-'],
    ['Association', data.association || '-'],
    ['Period', data.period || '-'],
    ['Payment Method', data.method || '-'],
    ['Reference No.', data.reference || '-'],
    ['Status', data.status || '-'],
  ].map(([k, v]) => `<tr><td class="k">${taxiEsc(k)}</td><td class="v">${taxiEsc(v)}</td></tr>`).join('');
  win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>ETEL Taxi Receipt</title>
<style>
  body{font-family:'DM Sans',system-ui,sans-serif;padding:28px;color:#111;max-width:380px;margin:0 auto;}
  .head{text-align:center;margin-bottom:18px;}
  .head .mark{font-family:'Syne',sans-serif;font-weight:800;font-size:20px;color:#8B6914;}
  .head .sub{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:1px;margin-top:2px;}
  table{width:100%;border-collapse:collapse;font-size:13px;margin:16px 0;}
  td{padding:7px 4px;border-bottom:1px solid #eee;}
  td.k{color:#666;} td.v{text-align:right;font-weight:700;}
  .total{display:flex;justify-content:space-between;font-size:16px;font-weight:800;border-top:2px solid #333;padding-top:10px;margin-top:4px;}
  .foot{margin-top:22px;text-align:center;font-size:10px;color:#999;line-height:1.5;}
  .no-print button{padding:10px 18px;font-weight:700;cursor:pointer;border-radius:8px;border:1px solid #ccc;background:#f5f5f5;}
  @media print{ .no-print{display:none;} }
</style></head><body>
  <div class="head"><div class="mark">ETEL TAXI</div><div class="sub">Payment Receipt</div></div>
  <table>${rows}</table>
  <div class="total"><span>Total Paid</span><span>ETB ${total.toFixed(0)}</span></div>
  <div class="foot">Etel Technology &amp; Business Group &middot; ethelmarket.com<br>Printed ${taxiEsc(new Date().toLocaleString())}</div>
  <div class="no-print" style="text-align:center;margin-top:20px;"><button onclick="window.print()">🧾 Print</button></div>
</body></html>`);
  win.document.close();
  win.focus();
}


async function taxiApi(action, body = {}, kind = 'driver') {
  const token = taxiGetToken(kind);
  let res;
  try {
    res = await fetch(TAXI.apiBase + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { 'Authorization': 'Bearer ' + token } : {}),
      },
      body: JSON.stringify(body),
    });
  } catch (e) {
    return { ok: false, error: 'Connection error - could not reach the server.' };
  }
  let data;
  try {
    data = await res.json();
  } catch (e) {
    // Reached the server, but it didn't send back valid JSON - almost
    // always a PHP fatal error on that specific action, not a network
    // problem. Different cause, different message, so this doesn't get
    // mistaken for "the internet is down" again.
    return { ok: false, error: 'The server had a problem processing that request (' + res.status + '). Please try again or contact support.' };
  }
  if (!data.success) return { ok: false, error: data.message || ('Request failed (' + res.status + ')') };
  return { ok: true, data: data.data };
}

let _taxiToastTimer = null;
function taxiToast(msg, type = '') {
  let el = document.getElementById('taxi-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'taxi-toast';
    el.className = 'toast';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.className = 'toast show' + (type ? ' ' + type : '');
  clearTimeout(_taxiToastTimer);
  _taxiToastTimer = setTimeout(() => el.classList.remove('show'), 3200);
}

// Driver-submitted text (full_name, vehicle_plate, payment reference
// numbers) gets rendered via innerHTML in admin/verify tables - escape
// it first so a driver can't self-register with an HTML/script payload
// that runs in an association admin's or traffic officer's browser.
function taxiEsc(s) {
  return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function taxiStatusLabel(status) {
  return { active: 'Paid & Active', grace_period: 'Payment Due Soon', overdue: 'Overdue - Penalty Applies' }[status] || status;
}

// Subtle 3D tilt for any element with class "tilt" - pure CSS transform
// driven by mouse position, no library. Applies to whatever exists in
// the DOM right now; call taxiInitTilt() again after injecting new
// .tilt elements via innerHTML.
function taxiInitTilt() {
  document.querySelectorAll('.tilt').forEach(el => {
    if (el._tiltBound) return;
    el._tiltBound = true;
    el.addEventListener('mousemove', e => {
      const r = el.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width - 0.5;
      const py = (e.clientY - r.top) / r.height - 0.5;
      el.style.transform = `perspective(800px) rotateY(${px * 8}deg) rotateX(${-py * 8}deg) translateZ(0)`;
    });
    el.addEventListener('mouseleave', () => { el.style.transform = ''; });
  });
}
document.addEventListener('DOMContentLoaded', taxiInitTilt);
