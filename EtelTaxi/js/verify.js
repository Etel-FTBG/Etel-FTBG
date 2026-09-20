/* ============================================================
   ETEL TAXI — verify.html logic (public, no login)
   ============================================================ */

const TAXI_STATUS_MAP = {
  active:       { cls: 'ok',   icon: '✅', label: 'VALID PERMIT' },
  grace_period: { cls: 'warn', icon: '⚠️', label: 'GRACE PERIOD - STILL VALID' },
  overdue:      { cls: 'fail', icon: '⛔', label: 'OVERDUE - NOT VALID' },
};

function renderResult(d) {
  const s = TAXI_STATUS_MAP[d.permit_status] || { cls: 'fail', icon: '⛔', label: taxiEsc(d.permit_status) };
  const assoc = d.association ? (d.association.name_en || d.association.name_am) : '';
  const route = d.association ? (d.association.route_en || d.association.route_am) : '';
  return `
    <div class="result-card guilloche ${s.cls}">
      <div class="eta-holo"></div>
      <div class="result-icon">${s.icon}</div>
      <div class="result-label">${s.label}</div>
      <div class="driver-name">${taxiEsc(d.full_name)}</div>
      <div class="driver-plate">${taxiEsc(d.vehicle_plate)}</div>
      <div class="assoc-line">${taxiEsc(assoc)}${route ? ' · ' + taxiEsc(route) : ''}</div>
      <div class="valid-through">Valid through ${taxiEsc(d.valid_through)}</div>
    </div>
  `;
}

function renderFail(msg) {
  return `
    <div class="result-card fail">
      <div class="result-icon">⛔</div>
      <div class="result-label">COULD NOT VERIFY</div>
      <div class="fail-msg">${taxiEsc(msg)}</div>
    </div>
  `;
}

async function runVerify(code) {
  const hint = document.getElementById('idle-hint');
  if (hint) hint.style.display = 'none';
  const wrap = document.getElementById('result-wrap');
  const el = document.getElementById('result');
  wrap.style.display = 'block';
  el.innerHTML = '<div class="shimmer" style="height:260px;border-radius:22px"></div>';
  const res = await taxiApi('public_verify_permit', { verify_code: code });
  el.innerHTML = res.ok ? renderResult(res.data) : renderFail(res.error);
}

function manualVerify() {
  const code = document.getElementById('manual-code').value.trim();
  if (!code) return taxiToast('Paste or type the verification code', 'err');
  runVerify(code);
}

function switchMode(mode) {
  document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.mode-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + mode).classList.add('active');
  document.getElementById('mode-' + mode).classList.add('active');
}

function showOfficerSession(name) {
  document.getElementById('officer-login-box').style.display = 'none';
  document.getElementById('officer-session-box').style.display = 'block';
  document.getElementById('officer-name').textContent = name;
}

function showOfficerLogin() {
  document.getElementById('officer-login-box').style.display = 'block';
  document.getElementById('officer-session-box').style.display = 'none';
}

async function officerEtelIdLogin() {
  const identifier = document.getElementById('officer-identifier').value.trim();
  const password = document.getElementById('officer-password').value.trim();
  if (!identifier || !password) return taxiToast('Enter your EtelID and password', 'err');
  const res = await taxiApi('officer_etelid_login', { identifier, password }, 'officer');
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiSetToken('officer', res.data.token);
  taxiToast('Welcome, ' + res.data.officer.full_name, 'ok');
  showOfficerSession(res.data.officer.full_name);
}

async function officerLogout() {
  await taxiApi('officer_logout', {}, 'officer');
  taxiClearToken('officer');
  document.getElementById('officer-identifier').value = '';
  document.getElementById('officer-password').value = '';
  showOfficerLogin();
}

async function plateVerify() {
  const plate = document.getElementById('plate-input').value.trim();
  if (!plate) return taxiToast('Enter the vehicle plate number', 'err');

  document.getElementById('idle-hint') && (document.getElementById('idle-hint').style.display = 'none');
  const wrap = document.getElementById('result-wrap');
  const el = document.getElementById('result');
  wrap.style.display = 'block';
  el.innerHTML = '<div class="shimmer" style="height:260px;border-radius:22px"></div>';

  const res = await taxiApi('officer_verify_by_plate', { vehicle_plate: plate }, 'officer');
  if (!res.ok) {
    if (res.error && /session/i.test(res.error)) showOfficerLogin();
    el.innerHTML = renderFail(res.error);
    return;
  }
  el.innerHTML = renderResult(res.data);
}

(function init() {
  // We don't have the officer's name cached client-side, so a saved
  // token just shows the session box optimistically with a generic
  // label - the first plate lookup call will confirm the token is
  // still valid (or bounce back to the login form via plateVerify's
  // session-expired check above).
  if (taxiGetToken('officer')) showOfficerSession('Officer');

  const code = new URLSearchParams(location.search).get('code');
  if (code) runVerify(code);
})();
