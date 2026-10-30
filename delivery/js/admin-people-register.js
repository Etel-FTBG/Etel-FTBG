/* ETEL Admin - Register a person: one guided panel for a customer, a courier or a driver.
   Replaces the old narrow "New User" and "Register Courier" pop-ups. Pick who they are, type name, phone and a password (or generate one),
   fill the few details that role needs, and the last screen holds a sign-in message ready to copy and send.
   Customer: name, phone, password. Courier: opens the Courier desk (js/admin-courier-register.js). Driver: opens the Driver desk (js/admin-driver-register.js) with
   the full sign-up: service, vehicle, licence, selfie and papers.
   Data: admin_register_person (api/controllers/PeopleRegisterController.php). Built on AdminKit; every server value goes through AdminKit.esc;
   no inline handlers. Opens from "New User" and "Register Courier" (the old openModal('new-user') / ('new-courier') calls). Admin accounts and
   shops are still made from their own screens. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelPeopleRegister) return;
var esc = K.esc;

var ROLES = {
  customer: { icon: '🧑', label: 'Customer', sub: 'Orders food and rides', app: 'customer.html', appName: 'the ETEL app' },
  courier:  { vehicle: 'moto', icon: '🛵', label: 'Courier',  sub: 'Delivers orders. Starts approved', app: 'courier/', appName: 'the ETEL Courier app' },
  driver:   { vehicle: 'bajaj', icon: '🚕', label: 'Driver',   sub: 'Gives rides. Finishes setup in the app', app: 'driver/', appName: 'the ETEL Driver app' }
};
var COURIER_V = [['motorcycle', '🏍️', 'Motorcycle'], ['bicycle', '🚲', 'Bicycle'], ['car', '🚗', 'Car'], ['on_foot', '🚶', 'On foot']];
var DRIVER_V = [['bajaj', '🛺', 'Bajaj'], ['moto', '🏍️', 'Motorcycle'], ['car', '🚗', 'Car'], ['minibus', '🚐', 'Minibus'], ['qute', '🛺', 'Qute']];
var S = { role: 'customer', cv: 'motorcycle', dv: 'bajaj', dr: null, msg: '' };

function css() {
  if (document.getElementById('ak-preg-css')) return;
  var s = document.createElement('style'); s.id = 'ak-preg-css';
  s.textContent = [
    '.ak-dr.ak-preg{width:min(620px,100vw)}',
    '.ak-preg .pr-top{position:sticky;top:-16px;z-index:3;margin:-16px -18px 14px;padding:12px 18px;background:var(--card);border-bottom:1px solid var(--line)}',
    '.ak-preg .pr-roles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.ak-preg .pr-roles[data-n="2"]{grid-template-columns:repeat(2,1fr)}',
    '.ak-preg .pr-roles button{display:flex;flex-direction:column;align-items:flex-start;gap:2px;text-align:left;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;cursor:pointer}',
    '.ak-preg .pr-roles button u{text-decoration:none;font-size:22px;line-height:1.1;display:flex;align-items:center;height:42px}.ak-preg .pr-roles button b{font-size:13.5px}.ak-preg .pr-roles button span{font-size:11px;color:var(--mute);font-weight:400;line-height:1.3}',
    '.ak-preg .pr-roles button.on{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22);background:rgba(212,175,55,.09)}',
    '.ak-preg .pr-prog{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--mute)}',
    '.ak-preg .pr-bar{flex:1;height:7px;border-radius:99px;background:var(--line);overflow:hidden}',
    '.ak-preg .pr-bar i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#E8C14A,#3ad08a);transition:width .25s}',
    '.ak-preg .pr-sec{border:1px solid var(--line);border-radius:16px;padding:14px 14px 6px;margin-bottom:14px;background:var(--card)}',
    '.ak-preg .pr-sec>h4{display:flex;align-items:center;gap:9px;margin:0 0 12px;font-size:14px}',
    '.ak-preg .pr-n{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#1a1405;background:linear-gradient(135deg,#E8C14A,#b8902a)}',
    '.ak-preg .pr-sec.done .pr-n{background:#3ad08a}',
    '.ak-preg .pr-f{display:block;margin-bottom:12px}',
    '.ak-preg .pr-f>span{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);margin-bottom:5px}',
    '.ak-preg .pr-f>span em{color:var(--red,#d6364a);font-style:normal}',
    '.ak-preg .pr-f .ak-in{font-size:15px;padding:11px 12px}',
    '.ak-preg .pr-f.bad .ak-in{border-color:var(--red,#d6364a)}',
    '.ak-preg .pr-f small{display:block;margin-top:4px;font-size:11.5px;color:var(--mute);min-height:14px;text-transform:none;letter-spacing:0;font-family:inherit;font-weight:400}',
    '.ak-preg .pr-f.bad small{color:var(--red,#d6364a)}',
    '.ak-preg .pr-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.ak-preg .pr-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px;margin-bottom:12px}',
    '.ak-preg .pr-tiles button{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12px;font-weight:600;cursor:pointer}',
    '.ak-preg .pr-tiles button u{text-decoration:none;font-size:22px;line-height:1;display:flex;align-items:center;justify-content:center;height:54px;width:100%}.ak-preg .pr-tiles button u img,.ak-preg .pr-roles button u img{max-height:100%;width:auto}',
    '.ak-preg .pr-tiles button.on{border-color:var(--gold);background:rgba(212,175,55,.12);box-shadow:0 0 0 3px rgba(212,175,55,.2)}',
    '.ak-preg .pr-pw{display:flex;gap:6px}.ak-preg .pr-pw .ak-in{flex:1;min-width:0}',
    '.ak-preg .pr-str{display:flex;gap:4px;margin-top:6px}.ak-preg .pr-str i{flex:1;height:5px;border-radius:99px;background:var(--line)}',
    '.ak-preg .pr-str[data-s="1"] i:nth-child(-n+1){background:#d6364a}.ak-preg .pr-str[data-s="2"] i:nth-child(-n+2){background:#f0b429}',
    '.ak-preg .pr-str[data-s="3"] i:nth-child(-n+3){background:#9bd34a}.ak-preg .pr-str[data-s="4"] i{background:#3ad08a}',
    '.ak-preg .pr-note{display:flex;gap:10px;padding:11px 12px;border-radius:12px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.35);font-size:12.5px;margin-bottom:12px}',
    '.ak-preg .pr-done{text-align:center;padding:10px 0 4px}.ak-preg .pr-ok{width:64px;height:64px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;background:linear-gradient(135deg,#3ad08a,#1fa463);color:#fff}',
    '.ak-preg .pr-box{border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:12px 0;text-align:left;background:var(--card)}',
    '.ak-preg .pr-box pre{white-space:pre-wrap;word-break:break-word;font:inherit;font-size:13px;margin:6px 0 10px}',
    '@media(max-width:560px){.ak-preg .pr-2,.ak-preg .pr-roles,.ak-preg .pr-roles[data-n="2"]{grid-template-columns:1fr}.ak-preg .pr-roles button{flex-direction:row;align-items:center;gap:10px}}'
  ].join('\n');
  document.head.appendChild(s);
}

function $(id) { return document.getElementById(id); }
function val(id) { var e = $(id); return e ? e.value.trim() : ''; }
/* readonly until focused: keeps the browser from pouring the admin's own saved phone and password into these boxes */
function inp(id, attrs) { return '<input id="' + id + '" name="' + id + '-' + Date.now().toString(36) + '" class="ak-in" readonly data-unlock ' + (attrs || '') + '>'; }
function field(id, label, input, hint, req) { return '<label class="pr-f" data-f="' + id + '"><span>' + esc(label) + (req ? ' <em>*</em>' : '') + '</span>' + input + '<small data-h="' + esc(hint || '') + '">' + esc(hint || '') + '</small></label>'; }
/* a real vehicle picture (js/etel-vehicle-pic.js) when there is one for it, else the emoji */
function pic(type, emoji, px) { return (window.EtelVehiclePic && EtelVehiclePic.key(type)) ? EtelVehiclePic.html(type, px, { fallback: emoji }) : esc(emoji); }
function tiles(list, cur, attr) {
  return '<div class="pr-tiles" role="group">' + list.map(function (t) { return '<button type="button" ' + attr + '="' + t[0] + '" class="' + (cur === t[0] ? 'on' : '') + '" aria-pressed="' + (cur === t[0]) + '"><u>' + pic(t[0], t[1], 72) + '</u>' + esc(t[2]) + '</button>'; }).join('') + '</div>';
}
function roleKeys() { return window.ETEL_RIDE_ONLY ? ['customer', 'driver'] : ['customer', 'courier', 'driver']; }

function zoneField() {
  var z = (typeof ETEL !== 'undefined' && ETEL && Array.isArray(ETEL.zones)) ? ETEL.zones : null;
  if (z && z.length) return field('pr-zone', 'Home zone', '<select id="pr-zone" class="ak-in">' + z.map(function (x) { return '<option value="' + esc(x) + '">' + esc(x) + '</option>'; }).join('') + '</select>', 'Where they usually start work.');
  return field('pr-zone', 'Home zone', inp('pr-zone', 'type="text" autocomplete="off" placeholder="e.g. Tabor"'), 'Optional. Where they usually start work.');
}
function details() {
  if (S.role === 'courier') {
    return '<section class="pr-sec" data-sec="det"><h4><span class="pr-n">3</span>How they travel</h4>' + tiles(COURIER_V, S.cv, 'data-cv') + zoneField() +
      '<div class="pr-note"><span>✅</span><div>A courier you register is <b>approved straight away</b> and can take jobs as soon as they sign in. (Couriers who sign up on their own still wait for approval.)</div></div></section>';
  }
  if (S.role === 'driver') {
    return '<section class="pr-sec" data-sec="det"><h4><span class="pr-n">3</span>The vehicle</h4>' + tiles(DRIVER_V, S.dv, 'data-dv') +
      '<div class="pr-2">' + field('pr-make', 'Make', inp('pr-make', 'type="text" autocomplete="off" placeholder="e.g. TVS, Toyota"'), 'Optional.') +
      field('pr-color', 'Colour', inp('pr-color', 'type="text" autocomplete="off" placeholder="e.g. Blue"'), 'Optional.') + '</div>' +
      '<div class="pr-2">' + field('pr-year', 'Year', inp('pr-year', 'type="text" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="e.g. 2021"'), 'Optional.') +
      field('pr-plate', 'Plate number', inp('pr-plate', 'type="text" autocomplete="off" placeholder="e.g. ET 12345"'), '', true) + '</div>' +
      '<div class="pr-note"><span>📸</span><div>After they sign in, the Driver app asks for a <b>selfie</b> and a photo of their <b>driving licence</b>. They can only go online once that is done.</div></div></section>';
  }
  return '<section class="pr-sec" data-sec="det"><h4><span class="pr-n">3</span>Good to know</h4>' +
    '<div class="pr-note"><span>🧑</span><div>The customer signs in with this phone and password. They add their own delivery addresses and payment choices inside the app.</div></div></section>';
}
function form() {
  var rk = roleKeys();
  var roles = rk.map(function (k) { var r = ROLES[k]; return '<button type="button" data-role="' + k + '" class="' + (S.role === k ? 'on' : '') + '" aria-pressed="' + (S.role === k) + '"><u>' + (r.vehicle ? pic(r.vehicle, r.icon, 64) : esc(r.icon)) + '</u><b>' + esc(r.label) + '</b><span>' + esc(r.sub) + '</span></button>'; }).join('');
  return '<div class="pr-top"><div class="pr-roles" role="group" aria-label="Who are you registering?" data-n="' + rk.length + '">' + roles + '</div>' +
    '<div class="pr-prog"><div class="pr-bar"><i id="pr-bar"></i></div><b id="pr-count"></b></div></div>' +
    '<section class="pr-sec" data-sec="who"><h4><span class="pr-n">1</span>Who they are</h4>' +
      field('pr-name', 'Full name', inp('pr-name', 'type="text" autocomplete="off" placeholder="e.g. Dawit Tesfaye"'), '', true) +
      field('pr-phone', 'Phone', inp('pr-phone', 'type="tel" inputmode="tel" autocomplete="off" placeholder="09XXXXXXXX"'), 'The number they sign in with.', true) + '</section>' +
    '<section class="pr-sec" data-sec="pw"><h4><span class="pr-n">2</span>Sign-in password</h4>' +
      field('pr-pw', 'Password', '<div class="pr-pw">' + inp('pr-pw', 'type="password" autocomplete="new-password" placeholder="At least 8 characters"') +
        '<button type="button" class="ak-btn" data-pw-show aria-label="Show password">👁</button><button type="button" class="ak-btn" data-pw-gen>🎲 Generate</button></div><div class="pr-str" id="pr-str" data-s="0"><i></i><i></i><i></i><i></i></div>',
        'Send it to them privately. They can change it after signing in.', true) + '</section>' +
    '<div id="pr-details">' + details() + '</div>';
}
function head() {
  var r = ROLES[S.role];
  return '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c" id="pr-av">' + (r.vehicle ? pic(r.vehicle, r.icon, 44) : esc(r.icon)) + '</div><div class="ak-nm"><b>Register a person</b><small>Customers, couriers and drivers</small></div>';
}
function foot() {
  return '<span class="ak-err" id="pr-msg" role="status" aria-live="polite"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-submit></button>';
}

/* ---------- checks ---------- */
function phoneOk(p) { return p.replace(/[^0-9]/g, '').length >= 9; }
function strength(p) {
  var s = 0; if (p.length >= 8) s++; if (p.length >= 11) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p) && /[A-Za-z]/.test(p)) s++;
  return p.length < 8 ? Math.min(s, 1) : Math.max(s, 2);
}
function mark(id, msg) {
  var f = S.dr && S.dr.querySelector('[data-f="' + id + '"]'); if (!f) return;
  f.classList.toggle('bad', !!msg);
  var sm = f.querySelector('small'); if (sm) sm.textContent = msg || sm.getAttribute('data-h') || '';
}
function checks() {
  var c = [val('pr-name').length >= 2 && phoneOk(val('pr-phone')), val('pr-pw').length >= 8];
  c.push(S.role === 'driver' ? val('pr-plate').length > 0 : true);
  return c;
}
function refresh() {
  if (!S.dr) return;
  var c = checks(), n = c.filter(Boolean).length;
  $('pr-bar').style.width = Math.round(n / 3 * 100) + '%';
  $('pr-count').textContent = n + ' of 3 ready';
  ['who', 'pw', 'det'].forEach(function (k, i) { var sec = S.dr.querySelector('[data-sec="' + k + '"]'); if (sec) sec.classList.toggle('done', !!c[i]); });
  var pw = val('pr-pw'), str = $('pr-str'); if (str) str.setAttribute('data-s', String(pw ? strength(pw) : 0));
}
function validate() {
  var ok = true, first = null;
  function need(id, bad, msg) { mark(id, bad ? msg : ''); if (bad && ok) { ok = false; first = id; } else if (bad) ok = false; }
  need('pr-name', val('pr-name').length < 2, 'Enter the full name.');
  need('pr-phone', !phoneOk(val('pr-phone')), 'Enter the full phone number.');
  need('pr-pw', val('pr-pw').length < 8, 'The password needs at least 8 characters.');
  if (S.role === 'driver') {
    need('pr-plate', !val('pr-plate'), 'Enter the plate number.');
    var y = val('pr-year'); need('pr-year', y !== '' && !/^(19[89]\d|20\d\d)$/.test(y), 'Four digits, like 2021, or leave it empty.');
  }
  if (!ok && first) { var e = $(first); if (e) { e.scrollIntoView({ block: 'center', behavior: 'smooth' }); e.focus(); } }
  return ok;
}
function applyRole() {
  S.dr.querySelectorAll('[data-role]').forEach(function (b) { var on = b.getAttribute('data-role') === S.role; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
  $('pr-details').innerHTML = details();
  S.dr.querySelector('[data-submit]').textContent = '✓ Register ' + ROLES[S.role].label.toLowerCase();
  var av = $('pr-av'); if (av) av.innerHTML = ROLES[S.role].vehicle ? pic(ROLES[S.role].vehicle, ROLES[S.role].icon, 44) : esc(ROLES[S.role].icon);
  S.dr.querySelectorAll('[data-unlock]').forEach(unlockOnFocus);
  refresh();
}
function unlockOnFocus(el) { if (el._pu) return; el._pu = true; el.addEventListener('focus', function () { el.removeAttribute('readonly'); }); }

/* ---------- sharing ---------- */
function baseUrl() { return location.origin + location.pathname.replace(/[^\/]*$/, ''); }
function copy(text) {
  var done = function () { K.toast('Copied'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { K.toast('Select the text and copy it by hand', true); }); return; }
  var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { K.toast('Select the text and copy it by hand', true); } t.remove();
}
function success(d, info) {
  var r = ROLES[S.role];
  S.msg = 'Welcome to ETEL, ' + info.name + '! Your ' + r.label.toLowerCase() + ' account is ready.\nOpen ' + r.appName + ': ' + baseUrl() + r.app + '\nPhone: ' + info.phone + '\nPassword: ' + info.pw +
    '\nKeep this password private.' + (S.role === 'driver' ? '\nAfter you sign in, add your selfie and driving licence photo to start driving.' : '');
  S.dr.querySelector('.ak-dr-body').innerHTML = '<div class="pr-done"><div class="pr-ok">✓</div><h3 style="margin:0 0 4px">' + esc(r.label) + ' registered</h3>' +
    '<p class="ak-fh" style="margin:0">' + esc(info.name) + (d.code ? ' · <b>' + esc(d.code) + '</b>' : '') + '</p></div>' +
    '<div class="pr-box"><b>Sign-in message</b><pre>' + esc(S.msg) + '</pre><button type="button" class="ak-btn gold" data-copy-msg>Copy message</button></div>' +
    (S.role === 'driver' ? '<p class="ak-fh">The driver cannot go online until they add their selfie and licence photo in the Driver app.</p>' : '');
  S.dr.querySelector('.ak-dr-foot').innerHTML = '<button type="button" class="ak-btn" data-again>Register another</button><button type="button" class="ak-btn gold" data-close>Done</button>';
}

/* ---------- submit ---------- */
function refreshLists() {
  ['loadUsers', 'loadCouriers', 'loadCustomers', 'loadPeople'].forEach(function (f) { try { if (typeof window[f] === 'function') window[f](); } catch (e) { /* the list refreshes on its next visit */ } });
}
function submit(btn) {
  if (!validate()) { $('pr-msg').textContent = 'Check the highlighted fields.'; return; }
  var info = { name: val('pr-name'), phone: val('pr-phone').replace(/\s/g, ''), pw: val('pr-pw') };
  var p = { role: S.role, name: info.name, phone: info.phone, password: info.pw };
  if (S.role === 'courier') { p.vehicle_type = S.cv; p.zone = val('pr-zone'); }
  if (S.role === 'driver') { p.vehicle_type = S.dv; p.vehicle_make = val('pr-make'); p.vehicle_color = val('pr-color'); p.vehicle_year = val('pr-year'); p.vehicle_plate = val('pr-plate'); }
  var msg = $('pr-msg'); btn.disabled = true; msg.textContent = 'Saving…';
  K.api('admin_register_person', p).then(function (res) {
    btn.disabled = false;
    if (!K.okRes(res)) { msg.textContent = K.failText(res); return; }
    success(K.data(res), info); refreshLists();
  });
}

/* ---------- open ---------- */
function open(opts) {
  // Drivers have the full Driver desk (vehicle, licence, selfie, papers), not this short form.
  if (opts && opts.role === 'driver' && window.EtelDriverRegister) return window.EtelDriverRegister.open();
  if (opts && opts.role === 'courier' && window.EtelCourierRegister) return window.EtelCourierRegister.open();
  css();
  var want = opts && opts.role, rk = roleKeys();
  S.role = rk.indexOf(want) >= 0 ? want : rk[0]; S.cv = 'motorcycle'; S.dv = 'bajaj';
  var dr = K.openDrawer(head(), form(), foot()); S.dr = dr; dr.classList.add('ak-preg');
  applyRole();
  dr.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-role]'))) { if (b.getAttribute('data-role') === 'driver' && window.EtelDriverRegister) { window.EtelDriverRegister.open(); return; } if (b.getAttribute('data-role') === 'courier' && window.EtelCourierRegister) { window.EtelCourierRegister.open(); return; } S.role = b.getAttribute('data-role'); applyRole(); }
    else if ((b = t.closest('[data-cv]'))) { S.cv = b.getAttribute('data-cv'); applyRole(); }
    else if ((b = t.closest('[data-dv]'))) { S.dv = b.getAttribute('data-dv'); applyRole(); }
    else if (t.closest('[data-pw-show]')) { var p = $('pr-pw'); p.type = p.type === 'password' ? 'text' : 'password'; }
    else if (t.closest('[data-pw-gen]')) {
      var a = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', n = new Uint32Array(10), out = ''; (window.crypto || window.msCrypto).getRandomValues(n);
      for (var i = 0; i < n.length; i++) out += a.charAt(n[i] % a.length);
      var pe = $('pr-pw'); pe.removeAttribute('readonly'); pe.value = out + '7'; pe.type = 'text'; refresh();
    }
    else if ((b = t.closest('[data-submit]'))) submit(b);
    else if (t.closest('[data-copy-msg]')) copy(S.msg || '');
    else if (t.closest('[data-again]')) open({ role: S.role });
  });
  dr.addEventListener('input', function (e) {
    var f = e.target.closest && e.target.closest('[data-f]'); if (f && f.classList.contains('bad')) mark(f.getAttribute('data-f'), '');
    if (!S.dr.querySelector('.pr-f.bad')) { var m = $('pr-msg'); if (m) m.textContent = ''; }
    refresh();
  });
  setTimeout(function () { var b = $('pr-name'); if (b) { b.removeAttribute('readonly'); b.focus(); } }, 320);
}

window.EtelPeopleRegister = { open: open };

/* The old "New User" and "Register Courier" pop-ups (openModal('new-user') / ('new-courier')) open this panel instead. */
(function hook() {
  var orig = window.openModal;
  if (typeof orig !== 'function') return;
  window.openModal = function (type) {
    if (type === 'new-user') return open({ role: 'customer' });
    if (type === 'new-courier') return open({ role: 'courier' });
    return orig.apply(this, arguments);
  };
})();
})();
