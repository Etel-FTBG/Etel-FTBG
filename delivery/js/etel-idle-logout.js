/* ETEL - sign out when the app is not used (every signed-in app; additive script, no bundle release).
   "Not used" = no tap, key press or scroll for the number of minutes the owner set for the role (the server tells the app: action session_ping; defaults below).
   One minute before the end a warning with a countdown asks "Still there?"; "Stay signed in" keeps the session, otherwise the person is signed out and sent to the
   sign-in page with a short explanation. Background refreshes do not count as use. The server enforces the same limit, so a forgotten phone or a stolen token
   stops working too. Never signs out a courier or driver who is working (online, or with a job): the server says so in the ping. The last time of use is kept in
   localStorage (etel_last_active), so a phone reopened hours later is signed out at once and several tabs share one clock. English / Amharic (etel_lang).
   RIDE PAGES (/ride/ and /driver/) have NO timer: no warning, no sign-out. They only (1) tell the server every request is from Ride (header X-Etel-App: ride, so the
   server does not end the session for being idle) and (2) now and then report use, so the Delivery apps' clock does not run out while a rider or driver works. */
(function () {
  'use strict';
  if (window.__etelIdle) return;
  window.__etelIdle = true;
  var DEFAULTS = { admin: 15, customer: 30, courier: 30, driver: 30, service_giver: 240, agent: 60, other: 30 };
  var AM = { 'Are you still there?': 'አሁንም እዚያ ነዎት?', 'You will be signed out in': 'ከመለያዎ የሚወጡት በ', 'seconds': 'ሰከንድ ውስጥ', 'because the app was not used.': 'መተግበሪያው ስላልተጠቀመ ነው።',
    'Stay signed in': 'ገብቼ ልቆይ', 'Sign out now': 'አሁን ውጣ' };
  var KEY = 'etel_last_active', REASON = 'etel_logout_reason';
  var RIDE = (window.ETEL_IDLE_RIDE === true) || /\/(ride|driver)(\/|$)/.test(location.pathname);
  /* Ride pages: mark every same-origin request as Ride (the server then never ends the session for being idle) */
  if (RIDE && window.fetch && !window.__etelRideFetch) {
    window.__etelRideFetch = true;
    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        var u = new URL(typeof input === 'string' ? input : (input && input.url) || '', location.href);
        if (u.origin === location.origin) {
          init = init || {};
          var h = new Headers(init.headers || (input && input.headers) || {});
          h.set('X-Etel-App', 'ride');
          var o = {}; for (var k in init) o[k] = init[k]; o.headers = h; init = o;
        }
      } catch (e) { /* send it as it was */ }
      return nativeFetch.call(this, input, init);
    };
  }
  var S = { limit: 0, exempt: false, lastPing: 0, dirty: false, ready: false, warn: null, last: Date.now(), done: false };

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function token() { try { return (window.Auth && Auth.getToken) ? Auth.getToken() : (localStorage.getItem('etel_token') || ''); } catch (e) { return ''; } }
  function role() { try { var u = JSON.parse(localStorage.getItem('etel_user') || 'null'); return u && u.role ? String(u.role) : 'other'; } catch (e) { return 'other'; } }
  function apiUrl() { return (window.ETEL && window.ETEL.api) ? window.ETEL.api : '/delivery/api/boot.php'; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  /* The saved last-use time belongs to ONE sign-in (the tail of its token is saved with it). A time left over from an earlier sign-in is ignored, so signing in again
     after an idle sign-out does not read the old time, think the phone was idle for hours and sign out again at once. */
  function tag() { try { return String(token()).slice(-16); } catch (e) { return ''; } }
  function storedLast() { try { if (localStorage.getItem(KEY + '_tok') !== tag()) return 0; var v = parseInt(localStorage.getItem(KEY), 10); return v > 0 ? v : 0; } catch (e) { return 0; } }
  function saveLast(t) { try { localStorage.setItem(KEY, String(t)); localStorage.setItem(KEY + '_tok', tag()); } catch (e) { /* private window: this tab only */ } }
  function forgetLast() { try { localStorage.removeItem(KEY); localStorage.removeItem(KEY + '_tok'); } catch (e) { } }

  /* a courier who is online or has a job is working without taps */
  function localExempt() {
    try { if (typeof COURIER_IS_ONLINE !== 'undefined' && COURIER_IS_ONLINE) return true; } catch (e) {}
    return !!document.querySelector('#active-content .job-card');
  }
  function limitMs() { return S.limit > 0 ? S.limit * 60000 : 0; }

  function ping(active, done) {
    if (!token()) return;
    S.lastPing = Date.now();
    var h = { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token() };
    fetch(apiUrl(), { method: 'POST', headers: h, body: JSON.stringify({ action: 'session_ping', active: !!active }), keepalive: true })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        if (x.s === 401 && x.j && x.j.code === 'idle_expired') return expire();
        var d = x.j && x.j.success && x.j.data;
        if (d) { S.limit = parseInt(d.idle_minutes, 10) || 0; S.exempt = !!d.exempt; S.ready = true; }
        if (done) done(!!d);
      })
      .catch(function () { if (done) done(false); });   /* offline: keep the local rules */
  }

  function css() {
    if (document.getElementById('eil-css')) return;
    var s = document.createElement('style'); s.id = 'eil-css';
    s.textContent =
      '.eil-wrap{position:fixed;inset:0;z-index:2147483100;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,10,20,.7);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}' +
      '.eil-card{width:100%;max-width:360px;border-radius:22px;padding:26px 22px 20px;background:linear-gradient(180deg,#171B2E 0%,#0F1222 100%);color:#F4F1E8;border:1px solid rgba(212,175,55,.45);box-shadow:0 24px 60px rgba(0,0,0,.55);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center}' +
      '.eil-brand{font:800 11px system-ui,sans-serif;letter-spacing:.28em;color:#E5C76B;margin-bottom:12px}' +
      '.eil-t{font-size:19px;font-weight:800;margin:0 0 8px;color:#fff}.eil-n{font-size:40px;font-weight:800;color:#E5C76B;font-variant-numeric:tabular-nums;margin:4px 0}' +
      '.eil-p{font-size:14px;line-height:1.5;color:#B9B7C9;margin:0 0 18px}' +
      '.eil-row{display:flex;gap:10px}.eil-row button{flex:1;min-height:48px;border-radius:14px;font:800 15px system-ui,sans-serif;cursor:pointer;border:0}' +
      '.eil-no{background:rgba(255,255,255,.08);color:#F4F1E8;border:1px solid rgba(255,255,255,.18)!important}.eil-yes{background:linear-gradient(180deg,#F0D26A,#C99A1E);color:#1a1200}' +
      '.eil-row button:focus-visible{outline:3px solid #fff;outline-offset:2px}';
    document.head.appendChild(s);
  }
  function showWarn(secs) {
    css();
    if (!S.warn) {
      var w = document.createElement('div'); w.className = 'eil-wrap';
      w.innerHTML = '<div class="eil-card" role="alertdialog" aria-modal="true" aria-labelledby="eil-t"><div class="eil-brand">ETEL</div><h2 class="eil-t" id="eil-t">' + esc(T('Are you still there?')) + '</h2>' +
        '<div class="eil-n" id="eil-n"></div><p class="eil-p">' + esc(T('You will be signed out in')) + ' <span id="eil-s"></span> ' + esc(T('seconds')) + ' ' + esc(T('because the app was not used.')) + '</p>' +
        '<div class="eil-row"><button type="button" class="eil-no" id="eil-out">' + esc(T('Sign out now')) + '</button><button type="button" class="eil-yes" id="eil-stay">' + esc(T('Stay signed in')) + '</button></div></div>';
      document.body.appendChild(w); S.warn = w;
      w.querySelector('#eil-stay').addEventListener('click', function (e) { e.stopPropagation(); touch(true); });
      w.querySelector('#eil-out').addEventListener('click', function (e) { e.stopPropagation(); expire(); });
      setTimeout(function () { var b = w.querySelector('#eil-stay'); if (b) b.focus(); }, 30);
    }
    var n = S.warn.querySelector('#eil-n'), s = S.warn.querySelector('#eil-s'); if (n) n.textContent = secs; if (s) s.textContent = secs;
  }
  function hideWarn() { if (S.warn && S.warn.parentNode) S.warn.parentNode.removeChild(S.warn); S.warn = null; }

  function expire() {
    if (S.done) return; S.done = true; hideWarn();
    try { sessionStorage.setItem(REASON, 'idle'); } catch (e) {}
    forgetLast();                                      /* the next sign-in starts a fresh clock */
    try { if (window.Auth && Auth.logout) return Auth.logout(); } catch (e) {}
    ['etel_token', 'etel_user', 'etel_courier', 'etel_service_giver', 'etel_sso'].forEach(function (k) { try { localStorage.removeItem(k); } catch (e) {} });
    try { location.href = (apiUrl().replace('api/boot.php', '')) + 'login.html'; } catch (e) {}
  }

  /* the person used the app: a tap, a key, a scroll (force = the "Stay signed in" button) */
  var lastWrite = 0;
  function touch(force) {
    var now = Date.now();
    if (S.done) return;
    if (S.warn && !force) return;                       /* while the warning shows, only its own buttons count */
    S.last = now; S.dirty = true;
    if (force || now - lastWrite > 3000) { lastWrite = now; saveLast(now); }
    if (force) { hideWarn(); ping(true); S.dirty = false; }
  }
  ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(function (ev) { document.addEventListener(ev, function () { touch(false); }, { capture: true, passive: true }); });
  document.addEventListener('scroll', function () { touch(false); }, { capture: true, passive: true });

  function tick() {
    if (S.done || !token()) return;
    var now = Date.now(), L = limitMs();
    var other = storedLast(); if (other > S.last) S.last = other;      /* another tab of this phone was used */
    if (!S.ready || L === 0) return;                                    /* limit unknown yet, or never */
    if (S.exempt || localExempt()) {                                    /* working without taps: counts as in use */
      S.last = now; hideWarn();
      if (now - S.lastPing > 300000) ping(true);
      return;
    }
    var idle = now - S.last, left = L - idle, warnAt = Math.min(60000, Math.floor(L / 4));
    if (left <= 0) return expire();
    if (left <= warnAt) showWarn(Math.ceil(left / 1000)); else if (S.warn) hideWarn();
    if (S.dirty && now - S.lastPing > 120000) { S.dirty = false; ping(true); }
  }

  /* Ride: no timer. Report use at most every 5 minutes so the shared sign-in stays fresh for the Delivery apps. */
  function startRide() {
    var last = 0;
    function use() { var n = Date.now(); if (n - last < 300000 || !token()) return; last = n; ping(true); }
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, use, { capture: true, passive: true }); });
    use();
  }

  function start() {
    if (!token()) return;                                               /* the sign-in pages: nothing to do */
    if (RIDE) return startRide();
    S.last = storedLast() || Date.now();
    S.limit = DEFAULTS[role()] || DEFAULTS.other; S.ready = false;
    ping(false, function () {                                           /* the server's limit and whether this person is working */
      var L = limitMs(), idle = Date.now() - S.last;
      if (S.ready && L > 0 && !S.exempt && !localExempt() && idle > L) return expire();   /* reopened after a long time */
      touch(true);                                                      /* opening the app is use */
    });
    setInterval(tick, 5000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') tick(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.__etelIdleTick = tick;   // for tests
})();
