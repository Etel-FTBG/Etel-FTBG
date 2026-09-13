// ============================================================
// ETEL ID — Universal Frontend Auth Component v1.0
// Works on any Etel project frontend (HTML or PHP page).
//
// USAGE — add ONE script tag to your page:
//   <script src="js/etelid-login.js"
//           data-api="api/etel-os.php"
//           data-project="delivery">
//   </script>
//
// Then call:
//   EtelIDAuth.renderButton('container-id')   // add login button
//   EtelIDAuth.renderBadge('container-id')    // show logged-in member
//   EtelIDAuth.require('login.html')          // protect a page
//   EtelIDAuth.getMember()                    // get current member object
//   EtelIDAuth.getToken()                     // get session token
//   EtelIDAuth.logout()                       // sign out
// ============================================================

const EtelIDAuth = (() => {
  const KEY = 'etelid_auth';

  // Auto-read config from <script> data attributes
  const _script = document.currentScript || document.querySelector('script[src*="etelid-login"]');
  let _api      = _script?.dataset?.api      || 'api/etel-os.php';
  let _project  = _script?.dataset?.project  || document.title || 'unknown';
  let _redirect = _script?.dataset?.redirect || 'login.html';

  // ── Storage ───────────────────────────────────────────────
  const _save = (member, token) =>
    localStorage.setItem(KEY, JSON.stringify({ member, token, ts: Date.now() }));

  const _get = () => {
    try {
      const d = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (!d) return null;
      if (Date.now() - d.ts > 30 * 86400 * 1000) { _clear(); return null; } // 30 days
      return d;
    } catch { return null; }
  };

  const _clear = () => localStorage.removeItem(KEY);

  // ── Core auth ─────────────────────────────────────────────
  const login = async (phone, password) => {
    const res = await fetch(`${_api}?action=etelid_login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ phone: phone.replace(/\s/g, ''), password, project: _project }),
    });
    const data = await res.json();
    if (data.ok) _save(data.data.member, data.data.token);
    return data;
  };

  const logout = async () => {
    const d = _get();
    if (d?.token) {
      try {
        await fetch(`${_api}?action=etelid_verify`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Etel-Token': d.token },
          body: JSON.stringify({ token: d.token }),
        });
      } catch {}
    }
    _clear();
    location.href = _redirect;
  };

  const getMember  = () => _get()?.member || null;
  const getToken   = () => _get()?.token  || null;
  const isLoggedIn = () => !!getMember();

  // Protect a page — call at the top of protected pages
  const require = (redirectTo) => {
    const m = getMember();
    if (!m) { location.href = redirectTo || _redirect; return null; }
    return m;
  };

  // ── Login Modal ───────────────────────────────────────────
  const _styles = `
    #etelid-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.88);z-index:99999;
      display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
    #etelid-box{background:linear-gradient(150deg,#0d0a00 0%,#1a1200 100%);
      border:1px solid rgba(212,175,55,0.5);border-radius:18px;padding:36px 32px;
      width:370px;max-width:93vw;position:relative;
      box-shadow:0 0 60px rgba(212,175,55,0.15),0 30px 80px rgba(0,0,0,0.6);}
    #etelid-box input{width:100%;padding:12px 14px;
      background:rgba(255,215,0,0.04);border:1px solid rgba(212,175,55,0.25);
      border-radius:8px;color:#FFF8E7;font-size:14px;box-sizing:border-box;
      outline:none;font-family:monospace;transition:border 0.2s;}
    #etelid-box input:focus{border-color:rgba(212,175,55,0.7);}
    #etelid-box label{font-size:10px;color:#D4AF37;display:block;margin-bottom:5px;
      letter-spacing:0.08em;font-family:monospace;}
    .etelid-submit-btn{width:100%;padding:14px;
      background:linear-gradient(135deg,#C9A227,#FFD700);
      border:none;border-radius:9px;color:#0d0800;font-weight:800;
      font-size:14px;cursor:pointer;letter-spacing:0.06em;font-family:inherit;
      box-shadow:0 4px 24px rgba(212,175,55,0.4);transition:opacity 0.15s;}
    .etelid-submit-btn:hover{opacity:0.9;}
    .etelid-submit-btn:disabled{opacity:0.5;cursor:not-allowed;}
    .etelid-alert{display:none;padding:10px 12px;border-radius:7px;font-size:12px;margin-bottom:14px;}
    .etelid-alert.err{background:rgba(255,68,102,0.1);border:1px solid rgba(255,68,102,0.3);color:#ff6b6b;}
    .etelid-alert.ok{background:rgba(0,255,153,0.08);border:1px solid rgba(0,255,153,0.25);color:#00ff99;}
    .etelid-close{position:absolute;top:14px;right:16px;background:transparent;
      border:none;color:rgba(212,175,55,0.5);font-size:20px;cursor:pointer;line-height:1;}
    .etelid-close:hover{color:#FFD700;}
    .etelid-divider{display:flex;align-items:center;gap:10px;margin:16px 0;color:rgba(212,175,55,0.3);font-size:11px;}
    .etelid-divider::before,.etelid-divider::after{content:'';flex:1;height:1px;background:rgba(212,175,55,0.15);}
    .etelid-btn{display:flex;align-items:center;justify-content:center;gap:10px;
      width:100%;padding:12px 18px;
      background:linear-gradient(135deg,rgba(212,175,55,0.06),rgba(212,175,55,0.02));
      border:1px solid rgba(212,175,55,0.35);border-radius:9px;color:#D4AF37;
      font-size:13px;font-weight:600;cursor:pointer;transition:all 0.2s;
      font-family:inherit;letter-spacing:0.02em;}
    .etelid-btn:hover{background:rgba(212,175,55,0.12);border-color:rgba(212,175,55,0.6);}
    .etelid-badge{display:flex;align-items:center;gap:10px;padding:9px 14px;
      background:rgba(212,175,55,0.07);border:1px solid rgba(212,175,55,0.2);border-radius:9px;}
    .etelid-badge-name{font-size:13px;font-weight:600;color:#FFD700;}
    .etelid-badge-id{font-size:10px;color:#8B6914;font-family:monospace;}
    .etelid-signout{margin-left:auto;padding:4px 10px;background:transparent;
      border:1px solid rgba(212,175,55,0.25);border-radius:5px;
      color:#8B6914;font-size:10px;cursor:pointer;font-family:inherit;}
    .etelid-signout:hover{border-color:rgba(212,175,55,0.5);color:#D4AF37;}
  `;

  const _injectStyles = () => {
    if (document.getElementById('etelid-styles')) return;
    const s = document.createElement('style');
    s.id = 'etelid-styles';
    s.textContent = _styles;
    document.head.appendChild(s);
  };

  const showModal = (opts = {}) => {
    _injectStyles();
    document.getElementById('etelid-overlay')?.remove();

    const el = document.createElement('div');
    el.id = 'etelid-overlay';
    el.innerHTML = `
      <div id="etelid-box">
        <button class="etelid-close" onclick="document.getElementById('etelid-overlay').remove()">✕</button>
        <div style="text-align:center;margin-bottom:26px;">
          <div style="font-size:42px;margin-bottom:6px;">🪪</div>
          <div style="font-size:19px;font-weight:800;color:#FFD700;letter-spacing:0.06em;">ETEL ID</div>
          <div style="font-size:11px;color:#8B6914;margin-top:3px;font-family:serif;">ኢቴሌ መታወቂያ</div>
          <div style="font-size:11px;color:rgba(212,175,55,0.4);margin-top:8px;">
            Your single identity across all Etel services
          </div>
        </div>
        <div class="etelid-alert" id="etelid-alert"></div>
        <div style="margin-bottom:14px;">
          <label>PHONE NUMBER</label>
          <input id="etelid-phone" type="tel" placeholder="+251 9XX XXX XXX" autocomplete="tel"/>
        </div>
        <div style="margin-bottom:22px;">
          <label>PASSWORD</label>
          <input id="etelid-password" type="password" placeholder="Your Etel-ID password" autocomplete="current-password"/>
        </div>
        <button class="etelid-submit-btn" id="etelid-submit" onclick="EtelIDAuth._submit()">
          SIGN IN WITH ETEL-ID &rarr;
        </button>
        <div style="text-align:center;margin-top:14px;font-size:10px;color:rgba(212,175,55,0.3);">
          Secured by Etel Group &middot; etelgroup.et
        </div>
      </div>`;

    document.body.appendChild(el);

    // Store opts on overlay for _submit to read
    el._opts = opts;

    // Close on backdrop click
    el.addEventListener('click', e => { if (e.target === el) el.remove(); });

    // Submit on Enter
    el.addEventListener('keydown', e => { if (e.key === 'Enter') EtelIDAuth._submit(); });

    document.getElementById('etelid-phone')?.focus();
  };

  const _submit = async () => {
    const phone    = (document.getElementById('etelid-phone')?.value    || '').trim();
    const password =  document.getElementById('etelid-password')?.value || '';
    const btn      =  document.getElementById('etelid-submit');
    const overlay  =  document.getElementById('etelid-overlay');

    if (!phone || !password) { _alert('Please enter your phone number and password.', 'err'); return; }

    btn.disabled = true;
    btn.textContent = 'Signing in…';
    _alert('', '');

    try {
      const res = await login(phone, password);
      if (res.ok) {
        btn.textContent = '✓ Signed in!';
        _alert(`Welcome, ${res.data.member.full_name}!`, 'ok');
        setTimeout(() => {
          overlay?.remove();
          document.dispatchEvent(new CustomEvent('etelid:login', { detail: res.data.member }));
          if (overlay?._opts?.onSuccess) overlay._opts.onSuccess(res.data.member, res.data.token);
          else if (overlay?._opts?.redirect) location.href = overlay._opts.redirect;
          else location.reload();
        }, 700);
      } else {
        btn.disabled = false;
        btn.innerHTML = 'SIGN IN WITH ETEL-ID &rarr;';
        _alert(res.error || 'Invalid phone or password.', 'err');
      }
    } catch {
      btn.disabled = false;
      btn.innerHTML = 'SIGN IN WITH ETEL-ID &rarr;';
      _alert('Connection error. Please try again.', 'err');
    }
  };

  const _alert = (msg, type) => {
    const el = document.getElementById('etelid-alert');
    if (!el) return;
    if (!msg) { el.style.display = 'none'; el.className = 'etelid-alert'; return; }
    el.textContent = msg;
    el.className = `etelid-alert ${type}`;
    el.style.display = 'block';
  };

  // ── UI Helpers ────────────────────────────────────────────

  // Render "Sign in with Etel-ID" button into a container
  const renderButton = (containerId, opts = {}) => {
    _injectStyles();
    const container = document.getElementById(containerId);
    if (!container) return;
    const btn = document.createElement('button');
    btn.className = 'etelid-btn';
    btn.innerHTML = '<span style="font-size:18px">🪪</span><span>' + (opts.label || 'Sign in with Etel-ID') + '</span>';
    btn.onclick = () => showModal(opts);
    container.appendChild(btn);
  };

  // Render divider + Etel-ID button after an existing form
  const appendToForm = (formId, opts = {}) => {
    _injectStyles();
    const form = document.getElementById(formId);
    if (!form) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = `
      <div class="etelid-divider">OR</div>
      <button type="button" class="etelid-btn" onclick="EtelIDAuth.showModal(${JSON.stringify(opts).replace(/"/g,"'")})">
        <span style="font-size:18px">🪪</span>
        <span>${opts.label || 'Sign in with Etel-ID'}</span>
      </button>`;
    form.parentNode.insertBefore(wrap, form.nextSibling);
  };

  // Show logged-in member badge
  const renderBadge = (containerId) => {
    _injectStyles();
    const member = getMember();
    const el = document.getElementById(containerId);
    if (!el || !member) return;
    el.innerHTML = `
      <div class="etelid-badge">
        <span style="font-size:20px">🪪</span>
        <div>
          <div class="etelid-badge-name">${member.full_name}</div>
          <div class="etelid-badge-id">${member.etel_id || member.fin || member.phone}</div>
        </div>
        <button class="etelid-signout" onclick="EtelIDAuth.logout()">Sign out</button>
      </div>`;
  };

  // Full-page gate — replaces page content with login screen if not authenticated
  const gate = (opts = {}) => {
    if (isLoggedIn()) return getMember();
    _injectStyles();
    document.body.style.cssText = 'background:#0d0a00;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;';
    document.body.innerHTML = `
      <div style="text-align:center;padding:20px;">
        <div style="font-size:52px;margin-bottom:10px;">🪪</div>
        <div style="font-size:22px;font-weight:800;color:#FFD700;margin-bottom:4px;">${opts.title || 'ETEL ID'}</div>
        <div style="font-size:12px;color:#8B6914;margin-bottom:24px;">${opts.subtitle || 'Sign in to continue'}</div>
        <div id="etelid-gate-btn"></div>
      </div>`;
    renderButton('etelid-gate-btn', opts);
    return null;
  };

  return { login, logout, getMember, getToken, isLoggedIn, require, renderButton,
           appendToForm, renderBadge, showModal, gate, _submit, _alert,
           init: (cfg = {}) => {
             if (cfg.api)      _api      = cfg.api;
             if (cfg.project)  _project  = cfg.project;
             if (cfg.redirect) _redirect = cfg.redirect;
           }};
})();

window.EtelIDAuth = EtelIDAuth;
