/**
 * ኢE Pay — API Layer
 * Handles all REST calls to WP backend.
 * Auto-refreshes nonce on expiry, retries once.
 */
const EP_API = (function() {

  // ── Nonce management ────────────────────────────────────────
  let _nonce = null;

  async function _freshNonce() {
    try {
      const r = await fetch(EP_CONFIG.NONCE_EP);
      const j = await r.json();
      _nonce = j.nonce || '';
      try { sessionStorage.setItem(EP_CONFIG.STORAGE.NONCE, _nonce); } catch(e) {}
    } catch(e) { _nonce = ''; }
    return _nonce;
  }

  async function _getNonce() {
    if (_nonce) return _nonce;
    try { _nonce = sessionStorage.getItem(EP_CONFIG.STORAGE.NONCE) || ''; } catch(e) {}
    if (_nonce) return _nonce;
    return await _freshNonce();
  }

  // ── Core fetch ───────────────────────────────────────────────
  async function _post(url, body) {
    const r = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body)
    });
    return r.json();
  }

  // ── /action endpoint ─────────────────────────────────────────
  async function call(action, extra = {}) {
    const nonce = await _getNonce();
    const token = EP_CORE.getToken();
    const body  = Object.assign({ action, nonce, token: token || '' }, extra);
    let j = await _post(EP_CONFIG.ACTION, body);

    // Nonce expired — refresh and retry once
    if (j && j.code === 'rest_cookie_invalid_nonce') {
      await _freshNonce();
      body.nonce = _nonce;
      j = await _post(EP_CONFIG.ACTION, body);
    }
    return j;
  }

  // ── /portal endpoint (profile + admin) ──────────────────────
  async function portal(ep_action, extra = {}) {
    const token = EP_CORE.getToken();
    const body  = Object.assign({ ep_action, token: token || '' }, extra);
    return _post(EP_CONFIG.PORTAL, body);
  }

  // ── Public methods ───────────────────────────────────────────
  return {
    // Auth
    login(phone, pin)      { return call('etel_pay_login',  { phone, pin }); },
    logout()               { return call('etel_pay_logout'); },
    refreshNonce()         { return _freshNonce(); },

    // Wallet
    getBalance()           { return call('etel_pay_get_balance'); },
    getHistory(limit=20, offset=0) { return call('etel_pay_get_history', { limit, offset }); },
    getRates()             { return call('etel_pay_get_rates'); },
    send(receiver, amount, currency='ETB', note='') {
      return call('etel_pay_send', { receiver, amount, currency, note });
    },

    // QR
    qrGenerate(type='static', amount=null, currency='ETB', label='') {
      return call('etel_pay_qr_generate', { type, amount, currency, label });
    },
    qrProcess(token, amount=null, currency='ETB') {
      return call('etel_pay_qr_process', { token, amount, currency });
    },

    // Profile (portal)
    getProfile()           { return portal('ep_get_profile'); },
    updateProfile(data)    { return portal('ep_update_profile', data); },
    changePin(old_pin, new_pin, confirm_pin) {
      return portal('ep_change_pin', { old_pin, new_pin, confirm_pin });
    },
    uploadAvatar(avatar_b64) { return portal('ep_upload_avatar', { avatar_b64 }); },

    // Admin (portal — requires WP admin cookie)
    adminStats()           { return portal('ep_admin_stats'); },
    adminUsers(page=1, per=20) { return portal('ep_admin_users', { page, per_page: per }); },
    adminTxs(page=1, per=20)   { return portal('ep_admin_txs',   { page, per_page: per }); },
    adminGetUser(id)       { return portal('ep_admin_get_user',   { user_id: id }); },
    adminToggleUser(id, status) { return portal('ep_admin_toggle_user', { user_id: id, status }); },
    adminCredit(id, amount, note='') { return portal('ep_admin_credit', { user_id: id, amount, note }); },
    adminSelfTopup(amount, note='') { return call('ep_admin_self_topup', { amount, note }); },
    adminDebit(id, amount, note='')  { return portal('ep_admin_debit',  { user_id: id, amount, note }); },
    adminRates()           { return portal('ep_admin_rates'); },
    adminUpdateRate(currency, rate) { return portal('ep_admin_update_rate', { currency, rate }); },
    adminSettings()        { return portal('ep_admin_settings'); },
    adminSaveSettings(d)   { return portal('ep_admin_save_settings', d); },
    adminSearch(q)         { return portal('ep_admin_search', { query: q }); },
    adminReverseT(id)      { return portal('ep_admin_reverse_tx', { tx_id: id }); },
    adminDeleteUser(id)    { return portal('ep_admin_delete_user', { user_id: id }); },
  };
})();
