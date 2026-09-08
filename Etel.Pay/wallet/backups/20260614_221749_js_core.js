/**
 * ኢE Pay — Core Engine
 * Auth, token management, toast, navigation, language, shared utils.
 * Loaded on every page before page-specific JS.
 */
const EP_CORE = (function() {

  // ── Language ─────────────────────────────────────────────────
  let _lang = 'en';
  function setLang(l) {
    _lang = l;
    try { localStorage.setItem(EP_CONFIG.STORAGE.LANG, l); } catch(e) {}
    document.documentElement.lang = l === 'am' ? 'am' : 'en';
    _applyLang();
  }
  function getLang() { return _lang; }
  function t(key) {
    return (EP_CONFIG.LANG[_lang] && EP_CONFIG.LANG[_lang][key]) || EP_CONFIG.LANG.en[key] || key;
  }
  function _applyLang() {
    document.querySelectorAll('[data-t]').forEach(el => {
      const key = el.getAttribute('data-t');
      el.textContent = t(key);
    });
    document.querySelectorAll('[data-t-ph]').forEach(el => {
      el.placeholder = t(el.getAttribute('data-t-ph'));
    });
  }

  // ── Token / session ──────────────────────────────────────────
  let _token = null;
  let _phone = '';

  function saveToken(token, phone) {
    _token = token; _phone = phone;
    try {
      localStorage.setItem(EP_CONFIG.STORAGE.TOKEN, token);
      localStorage.setItem(EP_CONFIG.STORAGE.PHONE, phone);
    } catch(e) {}
  }
  function getToken()  { return _token; }
  function getPhone()  { return _phone; }
  function clearToken() {
    _token = null; _phone = '';
    try {
      localStorage.removeItem(EP_CONFIG.STORAGE.TOKEN);
      localStorage.removeItem(EP_CONFIG.STORAGE.PHONE);
      sessionStorage.removeItem(EP_CONFIG.STORAGE.NONCE || 'ep_nonce');
    } catch(e) {}
  }
  function loadSaved() {
    try {
      _token = localStorage.getItem(EP_CONFIG.STORAGE.TOKEN) || null;
      _phone = localStorage.getItem(EP_CONFIG.STORAGE.PHONE) || '';
    } catch(e) {}
    try {
      _lang = localStorage.getItem(EP_CONFIG.STORAGE.LANG) || 'en';
    } catch(e) {}
    return !!_token;
  }

  // ── Navigation ───────────────────────────────────────────────
  const PAGES = {
    login:    'login',
    wallet:   'wallet',
    send:     'send',
    history:  'history',
    qr:       'qr',
    profile:  'profile',
    admin:    'admin',
    register: 'register',
    offline:  'offline.html',
    updater:   'updater',
    topup:     'topup',
    paylinks:  'pay-links',
    analytics: 'analytics',
    kyc:       'kyc',
  };
  function nav(page) {
    window.location.href = PAGES[page] || page;
  }
  function back() { window.history.back(); }

  // ── Guard: require auth ───────────────────────────────────────
  function requireAuth() {
    if (!loadSaved()) { nav('login'); return false; }
    return true;
  }
  // Guard: redirect if already logged in
  function redirectIfLoggedIn(to = 'wallet') {
    if (loadSaved()) { nav(to); return true; }
    return false;
  }

  // ── Active nav highlight ──────────────────────────────────────
  function setActiveNav(page) {
    // Also auto-detect from URL if not passed
    if (!page) {
      var p = window.location.pathname.split('/').pop().replace('.html','') || 'wallet';
      page = p;
    }
    document.querySelectorAll('.ep-nav-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.page === page);
    });
  }

  // ── Toast ────────────────────────────────────────────────────
  let _toastTimer = null;
  function toast(msg, type = '', duration = 2800) {
    let el = document.getElementById('ep-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'ep-toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => { el.className = ''; }, duration);
  }

  // ── Sheet / overlay ───────────────────────────────────────────
  function openSheet(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
  }
  function closeSheet(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }
  function initSheets() {
    document.addEventListener('click', e => {
      if (e.target.classList.contains('ep-overlay')) {
        e.target.classList.remove('open');
      }
    });
  }

  // ── Format helpers ───────────────────────────────────────────
  function numFmt(n, decimals = 2) {
    return parseFloat(n || 0).toLocaleString('en-ET', {
      minimumFractionDigits:  decimals,
      maximumFractionDigits:  decimals
    });
  }
  function dateFmt(s) {
    try {
      return new Date(s).toLocaleDateString('en-ET', {
        month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    } catch(e) { return s || ''; }
  }
  function dateFmtFull(s) {
    try {
      return new Date(s).toLocaleDateString('en-ET', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    } catch(e) { return s || ''; }
  }
  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }
  function txDirection(tx) {
    const phone = getPhone();
    if (tx.type === 'receive' || tx.type === 'deposit' || tx.type === 'refund') return 'in';
    if (tx.type === 'send' || tx.type === 'payment' || tx.type === 'withdraw' || tx.type === 'fee') return 'out';
    return 'out';
  }
  function txIcon(tx) {
    const icons = { send:'↗', receive:'↙', deposit:'⊕', withdraw:'↗', payment:'⊡', refund:'↩', fee:'⊟' };
    return icons[tx.type] || '◎';
  }
  function txLabel(tx) {
    if (tx.type === 'receive' && tx.sender_phone) return tx.sender_name || tx.sender_phone || 'Received';
    if (tx.type === 'send'    && tx.receiver_phone) return tx.receiver_name || tx.receiver_phone || 'Sent';
    return tx.note || tx.type || 'Transaction';
  }

  // ── Spinner helpers ──────────────────────────────────────────
  function btnLoading(btn, spinClass = '') {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="ep-spin ${spinClass}"></span>`;
    return () => { btn.innerHTML = orig; btn.disabled = false; };
  }

  // ── On DOM ready ─────────────────────────────────────────────
  function onReady(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  // ── Register service worker ──────────────────────────────────
  function registerSW() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('./sw.js').catch(() => {});
    }
  }

  // ── Lang toggle button ────────────────────────────────────────
  function initLangToggle() {
    const btn = document.getElementById('ep-lang-btn');
    if (!btn) return;
    btn.textContent = _lang === 'am' ? 'EN' : 'አማ';
    btn.addEventListener('click', () => {
      setLang(_lang === 'am' ? 'en' : 'am');
      btn.textContent = _lang === 'am' ? 'EN' : 'አማ';
    });
  }

  return {
    // Lang
    setLang, getLang, t, applyLang: _applyLang,
    // Token
    saveToken, getToken, getPhone, clearToken, loadSaved,
    // Nav
    nav, back, requireAuth, redirectIfLoggedIn, setActiveNav,
    PAGES,
    // UI
    toast, openSheet, closeSheet, initSheets,
    // Format
    numFmt, dateFmt, dateFmtFull, esc, txDirection, txIcon, txLabel,
    // Helpers
    btnLoading, onReady, registerSW, initLangToggle,
  };
})();
