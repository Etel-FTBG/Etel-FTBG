/* ============================================================
   ETEL TAXI — driver.html logic
   ============================================================ */

let _bankAccountsCache = [];

// Standard Web Push (RFC 8291/8292) - same mechanism Delivery & Ride
// uses, not Firebase. No separate Google project needed; the server's
// VAPID public key is fetched fresh (it's not a secret) and used to
// subscribe this browser directly with the push service.
function _urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

async function enablePushNotifications() {
  if (!('Notification' in window) || !navigator.serviceWorker) {
    return taxiToast('This browser does not support notifications.', 'err');
  }
  try {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return taxiToast('Notification permission was not granted.', 'err');

    const keyRes = await taxiApi('get_vapid_public_key');
    if (!keyRes.ok || !keyRes.data || !keyRes.data.publicKey) {
      return taxiToast('Payment reminders are not set up yet on this server - ask Etel Taxi support.', 'err');
    }

    const reg = await navigator.serviceWorker.register('sw.js');
    await navigator.serviceWorker.ready;
    let subscription = await reg.pushManager.getSubscription();
    if (!subscription) {
      subscription = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: _urlBase64ToUint8Array(keyRes.data.publicKey)
      });
    }

    const res = await taxiApi('driver_register_push_token', { subscription: subscription.toJSON() });
    if (!res.ok) return taxiToast(res.error, 'err');
    taxiToast('Payment reminders enabled.', 'ok');
  } catch (e) {
    taxiToast('Could not enable notifications: ' + e.message, 'err');
  }
}

function showView(name) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
  document.getElementById('view-' + name).classList.add('active');
  const header = document.getElementById('app-header');
  if (header) header.style.display = (name === 'login') ? 'none' : 'flex';
  if (name === 'status') loadStatus();
  if (name === 'pay') loadPayView();
  if (name === 'history') loadHistory();
  if (name === 'register') loadAssociationsInto('reg-association');
  if (name === 'complete-etelid') loadAssociationsInto('ce-association');
}

async function loadAssociationsInto(selectId) {
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option>Loading...</option>';
  const res = await taxiApi('driver_list_associations', {});
  if (!res.ok || !res.data.associations.length) {
    sel.innerHTML = '<option value="">No associations available - contact Etel Taxi support</option>';
    return;
  }
  sel.innerHTML = '<option value="">Choose your association</option>' + res.data.associations.map(a =>
    `<option value="${a.id}">${taxiEsc(a.name_am)}${a.route_am ? ' - ' + taxiEsc(a.route_am) : ''}</option>`
  ).join('');
}

let _pendingEtelIdToken = null;

async function doEtelIdLogin() {
  const identifier = document.getElementById('etelid-identifier').value.trim();
  const password = document.getElementById('etelid-password').value.trim();
  if (!identifier || !password) return taxiToast('Enter your EtelID and password', 'err');
  const res = await taxiApi('driver_etelid_login', { identifier, password });
  if (!res.ok) return taxiToast(res.error, 'err');

  if (res.data.needs_registration) {
    _pendingEtelIdToken = res.data.token;
    document.getElementById('ce-name').textContent = res.data.member.full_name;
    showView('complete-etelid');
  } else {
    taxiSetToken('driver', res.data.token);
    taxiToast('Welcome back, ' + res.data.driver.full_name, 'ok');
    showView('status');
  }
}

async function completeEtelIdRegistration() {
  const vehicle_plate = document.getElementById('ce-plate').value.trim();
  const association_id = document.getElementById('ce-association').value;
  const license_full_name = document.getElementById('ce-license-name').value.trim();
  const license_number = document.getElementById('ce-license-num').value.trim();
  const license_dob = document.getElementById('ce-license-dob').value;
  if (!vehicle_plate || !association_id) return taxiToast('Enter your plate number and choose an association', 'err');
  if (!license_full_name || !license_number || !license_dob) return taxiToast('Enter your driving license name, number, and date of birth', 'err');

  // Temporarily use the pending EtelID token to authorize this one call.
  taxiSetToken('driver', _pendingEtelIdToken);
  const res = await taxiApi('driver_complete_etelid_registration', { vehicle_plate, association_id, license_full_name, license_number, license_dob });
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast(res.data.message || 'Registered! Welcome to Etel Taxi.', res.data.identity_match === 'mismatch' ? 'err' : 'ok');
  showView('status');
}

async function doLogin() {
  const phone = document.getElementById('login-phone').value.trim();
  const pin = document.getElementById('login-pin').value.trim();
  if (!phone || !pin) return taxiToast('Enter your phone number and PIN', 'err');
  const res = await taxiApi('driver_login', { phone, pin });
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiSetToken('driver', res.data.token);
  taxiToast('Welcome back, ' + res.data.driver.full_name, 'ok');
  showView('status');
}

async function doRegister() {
  const full_name = document.getElementById('reg-name').value.trim();
  const phone = document.getElementById('reg-phone').value.trim();
  const pin = document.getElementById('reg-pin').value.trim();
  const vehicle_plate = document.getElementById('reg-plate').value.trim();
  const association_id = document.getElementById('reg-association').value;
  const license_full_name = document.getElementById('reg-license-name').value.trim();
  const license_number = document.getElementById('reg-license-num').value.trim();
  const license_dob = document.getElementById('reg-license-dob').value;
  if (!full_name || !phone || !pin || !vehicle_plate || !association_id) {
    return taxiToast('Please fill in every field, including your association', 'err');
  }
  if (!license_full_name || !license_number || !license_dob) {
    return taxiToast('Enter your driving license name, number, and date of birth', 'err');
  }
  const res = await taxiApi('driver_register', { full_name, phone, pin, vehicle_plate, association_id, license_full_name, license_number, license_dob });
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiSetToken('driver', res.data.token);
  taxiToast(res.data.message || 'Registered! Welcome to Etel Taxi.', res.data.identity_match === 'mismatch' ? 'err' : 'ok');
  showView('status');
}

function doLogout() {
  taxiClearToken('driver');
  showView('login');
}

async function loadStatus() {
  const res = await taxiApi('driver_get_status', {});
  if (!res.ok) { taxiToast(res.error, 'err'); showView('login'); return; }
  const d = res.data;
  TAXI_DRIVER_INFO = {
    name: d.driver.full_name,
    plate: d.driver.vehicle_plate,
    association: d.association ? (d.association.name_en || d.association.name_am) : '',
  };
  document.getElementById('status-name').textContent = d.driver.full_name;
  document.getElementById('status-plate').textContent = 'Plate: ' + d.driver.vehicle_plate;
  document.getElementById('status-route').textContent = d.association ? (d.association.route_am || d.association.route_en || '') : '';
  const pill = document.getElementById('status-pill');
  pill.textContent = taxiStatusLabel(d.status.status);
  pill.className = 'pill ' + d.status.status;

  document.getElementById('info-period').textContent = d.status.current_period;
  document.getElementById('info-window').textContent = d.status.window_end;
  document.getElementById('info-due').textContent = 'ETB ' + Number(d.status.monthly_due).toFixed(0);
  const penaltyRow = document.getElementById('info-penalty-row');
  if (d.status.penalty_owed > 0) {
    penaltyRow.style.display = 'flex';
    document.getElementById('info-penalty').textContent = 'ETB ' + Number(d.status.penalty_owed).toFixed(0);
  } else {
    penaltyRow.style.display = 'none';
  }

  const qrBox = document.getElementById('qr-box');
  qrBox.innerHTML = '';
  if (d.verify_code && window.QRCode) {
    // Encode a full verify.html URL, not the bare code - so any generic
    // phone camera/QR app opens the verification page directly instead
    // of just showing plain text a traffic officer would have no way
    // to act on.
    const verifyUrl = new URL('verify.html?code=' + encodeURIComponent(d.verify_code), location.href).href;
    new QRCode(qrBox, { text: verifyUrl, width: 160, height: 160 });
  } else {
    qrBox.innerHTML = '<div style="color:#333;font-size:11px;padding:20px;">QR verification not configured yet</div>';
  }
  document.getElementById('status-serial').textContent = d.verify_code
    ? 'PERMIT NO. ' + String(d.verify_code).toUpperCase()
    : '';
}

const TAXI_PAY_METHODS = [
  { key: 'telebirr',       icon: '📱', label: 'Telebirr' },
  { key: 'cbe',             icon: '🏦', label: 'CBE Transfer' },
  { key: 'abysinia',        icon: '🏛️', label: 'Abysinia Bank' },
  { key: 'etelpay_wallet',  icon: '👛', label: 'Etel Pay' },
  { key: 'cash',            icon: '💵', label: 'Cash' },
];

function taxiMethodMeta(key) {
  return TAXI_PAY_METHODS.find(m => m.key === key) || { icon: '💳', label: key };
}

function buildMethodGrid() {
  const select = document.getElementById('pay-method');
  const grid = document.getElementById('method-grid');
  select.innerHTML = TAXI_PAY_METHODS.map(m => `<option value="${m.key}">${m.label}</option>`).join('');
  grid.innerHTML = TAXI_PAY_METHODS.map(m => `
    <div class="method-card${m.key === select.value ? ' sel' : ''}" data-key="${m.key}" onclick="selectPayMethod('${m.key}')">
      <span class="mi">${m.icon}</span><span>${taxiEsc(m.label)}</span>
    </div>
  `).join('');
}

function selectPayMethod(key) {
  document.getElementById('pay-method').value = key;
  document.querySelectorAll('#method-grid .method-card').forEach(el => el.classList.toggle('sel', el.dataset.key === key));
  renderBankInfo();
}

async function loadPayView() {
  buildMethodGrid();
  const res = await taxiApi('driver_get_bank_accounts', {});
  if (res.ok) _bankAccountsCache = res.data.accounts;
  renderBankInfo();
}

function renderBankInfo() {
  const method = document.getElementById('pay-method').value;
  const infoEl = document.getElementById('bank-info');
  const refField = document.getElementById('ref-field');
  if (method === 'cash') {
    infoEl.textContent = 'Pay in person at your association office and ask staff to mark it verified.';
    refField.style.display = 'none';
    return;
  }
  if (method === 'etelpay_wallet') {
    infoEl.textContent = 'Etel Pay wallet payment is coming soon - please use Telebirr, CBE, or Abysinia for now.';
    refField.style.display = 'none';
    return;
  }
  refField.style.display = 'block';
  const acct = _bankAccountsCache.find(a => a.bank_key === method);
  infoEl.textContent = acct && acct.account_number
    ? `Send to: ${acct.account_name} - ${acct.account_number}, then enter the reference number below.`
    : 'This payment method is not set up yet - ask your association for the correct account details.';
}

async function submitPayment() {
  const payment_method = document.getElementById('pay-method').value;
  const reference_number = document.getElementById('pay-ref').value.trim();
  if (payment_method !== 'cash' && payment_method !== 'etelpay_wallet' && !reference_number) {
    return taxiToast('Enter the reference/transaction number', 'err');
  }
  const res = await taxiApi('driver_submit_payment', { payment_method, reference_number });
  if (!res.ok) return taxiToast(res.error, 'err');
  taxiToast(res.data.message, 'ok');
  showView('status');
}

let TAXI_DRIVER_INFO = null;
let TAXI_DRIVER_PAYMENTS = [];

async function loadHistory() {
  const el = document.getElementById('history-list');
  el.innerHTML = 'Loading...';
  const res = await taxiApi('driver_get_payment_history', {});
  if (!res.ok) { el.innerHTML = res.error; return; }
  TAXI_DRIVER_PAYMENTS = res.data.payments;
  if (!res.data.payments.length) { el.innerHTML = '<div style="color:var(--text-dim);font-size:13px">No payments yet.</div>'; return; }
  el.innerHTML = res.data.payments.map((p, i) => `
    <div class="history-row">
      <div class="hr-icon">${taxiMethodMeta(p.payment_method).icon}</div>
      <div class="hr-body">
        <div class="hr-period">${taxiEsc(p.period_month)}</div>
        <div class="hr-meta">${taxiEsc(taxiMethodMeta(p.payment_method).label)}${p.reference_number ? ' · ' + taxiEsc(p.reference_number) : ''}</div>
      </div>
      <div class="hr-amount">
        <div class="amt">ETB ${Number(p.amount).toFixed(0)}${p.penalty_amount > 0 ? ' + ' + Number(p.penalty_amount).toFixed(0) : ''}</div>
        <div class="pill ${p.status === 'verified' ? 'active' : p.status === 'rejected' ? 'overdue' : 'grace_period'}" style="margin-top:4px">${p.status}</div>
        <button class="btn btn-outline" style="padding:5px 10px;font-size:11px;margin-top:6px" onclick="taxiPrintDriverReceipt(${i})">🧾 Receipt</button>
      </div>
    </div>
  `).join('');
}

function taxiPrintDriverReceipt(i) {
  const p = TAXI_DRIVER_PAYMENTS[i];
  if (!p) return;
  taxiPrintReceipt({
    driverName: TAXI_DRIVER_INFO ? TAXI_DRIVER_INFO.name : '',
    plate: TAXI_DRIVER_INFO ? TAXI_DRIVER_INFO.plate : '',
    association: TAXI_DRIVER_INFO ? TAXI_DRIVER_INFO.association : '',
    period: p.period_month,
    method: p.payment_method,
    reference: p.reference_number,
    amount: p.amount,
    penalty: p.penalty_amount,
    status: p.status,
  });
}

(function init() {
  if (taxiGetToken('driver')) showView('status');
})();
