/* Etel Madeya - public EtelID sign-in for drivers (used when an admin turns on "Require EtelID for drivers"). */
(function () {
  'use strict';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MAD = window.MAD || (window.MAD = { requireLogin: false, member: null, privileged: false });
  window.madLocked = function () { return !!(MAD.requireLogin && !MAD.member && !MAD.privileged); };
  window.madLockCard = function () {
    return '<div class="container"><div class="mad-lock"><div class="mad-lock-ic">🔒</div><h2>Sign in with EtelID</h2>' +
      '<p>To use this feature please sign in with your EtelID. You only need to do it once.</p>' +
      '<button type="button" class="btn btn-primary" onclick="madLogin()">EtelID login</button>' +
      '<p class="mad-lock-sub">No EtelID yet? <a href="https://ethelmarket.com/EtelId/" target="_blank" rel="noopener">Create one</a></p></div></div>';
  };
  window.madHandleAuth = function (data) {
    if (data && data.code === 'LOGIN_REQUIRED') { MAD.requireLogin = true; MAD.member = null; window.madLogin(data.message); return true; }
    return false;
  };
  window.madLogin = function (msg) {
    var old = document.getElementById('madLoginModal'); if (old) old.remove();
    var d = document.createElement('div'); d.id = 'madLoginModal'; d.className = 'mad-modal';
    d.innerHTML = '<div class="mad-modal-box"><button type="button" class="mad-x" onclick="madLoginClose()" aria-label="Close">✕</button>' +
      '<h2>EtelID login</h2><p class="mad-sub">' + esc(msg || 'Sign in with your EtelID phone / ID and password.') + '</p>' +
      '<form onsubmit="madLoginSubmit(event)"><label>EtelID, phone or FIN</label><input id="madLid" type="text" autocomplete="username" required>' +
      '<label>Password</label><input id="madLpw" type="password" autocomplete="current-password" required>' +
      '<div id="madLerr" class="mad-err"></div><button type="submit" class="btn btn-primary" id="madLbtn">Sign in</button></form>' +
      '<p class="mad-sub">No EtelID yet? <a href="https://ethelmarket.com/EtelId/" target="_blank" rel="noopener">Create one</a></p></div>';
    d.addEventListener('click', function (e) { if (e.target === d) window.madLoginClose(); });
    document.body.appendChild(d);
    setTimeout(function () { var i = document.getElementById('madLid'); if (i) i.focus(); }, 50);
  };
  window.madLoginClose = function () { var m = document.getElementById('madLoginModal'); if (m) m.remove(); };
  window.madLoginSubmit = function (e) {
    e.preventDefault();
    var b = document.getElementById('madLbtn'), er = document.getElementById('madLerr');
    b.disabled = true; b.textContent = 'Signing in…'; er.textContent = '';
    var fd = new FormData(); fd.append('action', 'driver_etelid_login');
    fd.append('identifier', document.getElementById('madLid').value); fd.append('password', document.getElementById('madLpw').value);
    fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success) { location.reload(); }
      else { er.textContent = (d && d.message) || 'Sign-in failed'; b.disabled = false; b.textContent = 'Sign in'; }
    }).catch(function () { er.textContent = 'Connection error'; b.disabled = false; b.textContent = 'Sign in'; });
  };
  window.madLogout = function () {
    var fd = new FormData(); fd.append('action', 'driver_logout');
    fetch(API, { method: 'POST', body: fd }).then(function () { location.reload(); }).catch(function () { location.reload(); });
  };
})();
