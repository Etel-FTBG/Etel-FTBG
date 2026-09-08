/* ============================================================
   ETEL AI — shared core JS
   Used by: js/customer-chat.js, js/admin-dashboard.js
   Mirrors the Delivery project's core.js convention: one shared
   file with auth/session reading and a fetch helper, so each
   page's own script stays focused on its own page.
   ============================================================ */

const ETELAI = {
  apiBase: 'api/',
  // Real cross-app SSO key - set by Delivery/Ride's Auth.save() after an
  // EtelID login (Etelid_sessions table token, same mechanism the EtelID
  // portal's own login uses). The old 'etelid_auth' key this used to read
  // was never actually written by anything - dead on arrival.
  authKey: 'etel_sso',
};

function etelaiGetSession() {
  try { return JSON.parse(localStorage.getItem(ETELAI.authKey) || 'null'); }
  catch { return null; }
}
function etelaiGetToken() {
  return etelaiGetSession()?.token || null;
}
function etelaiGetMember() {
  return etelaiGetSession()?.member || etelaiGetSession() || null;
}

// POST helper - every EtelAI endpoint returns {ok, data} or {ok:false, error}.
async function etelaiApi(endpoint, body = {}) {
  try {
    const res = await fetch(ETELAI.apiBase + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!data.ok) return { ok: false, error: data.error || ('Request failed (' + res.status + ')') };
    return data;
  } catch (e) {
    return { ok: false, error: 'Connection error - could not reach the server.' };
  }
}
async function etelaiApiGet(endpoint, params = {}) {
  try {
    // Admin-only GET endpoints (providers.php) need the session token too -
    // GET has no body, so it goes as a query param instead.
    const token = etelaiGetToken();
    const qs = new URLSearchParams({ ...params, ...(token ? { token } : {}) }).toString();
    const url = ETELAI.apiBase + endpoint + (qs ? '?' + qs : '');
    const res = await fetch(url);
    const data = await res.json();
    if (!data.ok) return { ok: false, error: data.error || ('Request failed (' + res.status + ')') };
    return data;
  } catch (e) {
    return { ok: false, error: 'Connection error - could not reach the server.' };
  }
}

let _toastTimer = null;
function etelaiToast(msg, type = '') {
  let el = document.getElementById('etelai-toast');
  if (!el) {
    el = document.createElement('div');
    el.id = 'etelai-toast';
    el.className = 'toast';
    document.body.appendChild(el);
  }
  el.textContent = msg;
  el.className = 'toast show' + (type ? ' ' + type : '');
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => el.classList.remove('show'), 3200);
}

// Admin-key storage, shared between admin-dashboard.js actions.
const ETELAI_ADMIN_KEY_STORAGE = 'etelai_admin_key';
function etelaiGetAdminKey(promptIfMissing) {
  let k = '';
  try { k = localStorage.getItem(ETELAI_ADMIN_KEY_STORAGE) || ''; } catch {}
  if (!k && promptIfMissing) {
    k = prompt('Enter your ETELAI_ADMIN_KEY (set in api/secrets.php):') || '';
    if (k) { try { localStorage.setItem(ETELAI_ADMIN_KEY_STORAGE, k); } catch {} }
  }
  return k;
}
function etelaiClearAdminKey() {
  try { localStorage.removeItem(ETELAI_ADMIN_KEY_STORAGE); } catch {}
}

// ── Theme system (Etel Color System) ────────────────────────────
// Pure black or pure white background + bold text, gold stays
// constant in both. Remembered per-browser so returning members see
// their own choice, not a reset to dark every visit.
const ETELAI_THEME_STORAGE = 'etelai_theme';
function etelaiGetTheme() {
  try { return localStorage.getItem(ETELAI_THEME_STORAGE) || 'dark'; } catch { return 'dark'; }
}
function etelaiApplyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const btn = document.getElementById('theme-toggle-btn');
  if (btn) btn.textContent = theme === 'dark' ? '☀️ Light' : '🌙 Dark';
}
function etelaiToggleTheme() {
  const next = etelaiGetTheme() === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(ETELAI_THEME_STORAGE, next); } catch {}
  etelaiApplyTheme(next);
}
// Applied immediately (not waiting for DOMContentLoaded) so a
// returning member never sees a flash of the wrong theme.
etelaiApplyTheme(etelaiGetTheme());
