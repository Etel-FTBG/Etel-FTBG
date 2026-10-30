/* ETEL Admin - Courier desk: register a courier properly, with the details ETEL needs to trust them with other people's orders and money.
   Six short steps with a progress bar and a clickable step list (same look as the Driver desk):
     1 sign-in   2 how they travel (real vehicle pictures, plate for motorcycle and car, home zone)   3 identity (kind of ID, number, ID photo; the driving
     licence for a motorcycle)   4 selfie   5 an emergency contact and address   6 review: what is ready and what is missing, then approve now (staff checked the
     ID and face in person: the courier can take jobs at once) or save for review (waits in the approval list).
   The ID, selfie and licence are kept in the private documents folder (only signed, expiring links are ever shown). An ID number already registered to another
   courier is stopped until staff confirm it. Photos upload as soon as they are picked. The last screen holds the sign-in message to copy.
   Data: admin_register_courier (api/controllers/PeopleRegisterController.php). Built on AdminKit; every server value goes through AdminKit.esc; no inline
   handlers. Opens from "Register a person" > Courier and from openModal('new-courier'). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelCourierRegister) return;
var esc = K.esc;

var STEPS = [['Sign-in', '🔐'], ['Travel', '🛵'], ['Identity', '🪪'], ['Selfie', '🤳'], ['Contact', '☎️'], ['Review', '✅']];
var VEH = [['motorcycle', '🏍️', 'Motorcycle', 'Needs a plate and a licence'], ['car', '🚗', 'Car', 'Needs a plate'], ['bicycle', '🚲', 'Bicycle', 'No plate needed'], ['on_foot', '🚶', 'On foot', 'Short deliveries nearby']];
var IDS = [['national_id', 'National ID'], ['kebele_id', 'Kebele ID'], ['passport', 'Passport'], ['driving_licence', 'Driving licence']];
var S = { dr: null, step: 0, busy: 0, D: null, result: null, dup: false };

function blank() { return { name: '', phone: '', pw: '', vt: 'motorcycle', plate: '', zone: '', idt: 'national_id', idn: '', idp: null, selfie: null, lnum: '', lic: null, en: '', ep: '', addr: '', activate: false, confirm: false }; }
function needsPlate() { return S.D.vt === 'motorcycle' || S.D.vt === 'car'; }

function $(id) { return document.getElementById(id); }
function val(id) { var e = $(id); return e ? e.value.trim() : ''; }
function inp(id, v, attrs) { return '<input id="' + id + '" name="' + id + '-' + Date.now().toString(36) + '" class="ak-in" readonly data-unlock value="' + esc(v == null ? '' : v) + '" autocomplete="off" ' + (attrs || '') + '>'; }
function field(id, label, input, hint, req) { return '<label class="dv-f" data-f="' + id + '"><span>' + esc(label) + (req ? ' <em>*</em>' : '') + '</span>' + input + '<small data-h="' + esc(hint || '') + '">' + esc(hint || '') + '</small></label>'; }
function mark(id, msg) { var f = S.dr && S.dr.querySelector('[data-f="' + id + '"]'); if (!f) return; f.classList.toggle('bad', !!msg); var sm = f.querySelector('small'); if (sm) sm.textContent = msg || sm.getAttribute('data-h') || ''; }
function pic(type, px, emoji) { return (window.EtelVehiclePic && EtelVehiclePic.key(type)) ? EtelVehiclePic.html(type, px, { fallback: emoji }) : esc(emoji); }
function phoneOk(p) { return p.replace(/[^0-9]/g, '').length >= 9; }
function last9(p) { return p.replace(/[^0-9]/g, '').slice(-9); }
function strength(p) { var s = 0; if (p.length >= 8) s++; if (p.length >= 11) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p) && /[A-Za-z]/.test(p)) s++; return p.length < 8 ? Math.min(s, 1) : Math.max(s, 2); }
function vehInfo() { var v = null; VEH.forEach(function (x) { if (x[0] === S.D.vt) v = x; }); return v || VEH[0]; }

/* ---------- uploads ---------- */
function upBox(id, f, title, hint, kind, cap) {
  var has = f && f.preview;
  return '<div class="dv-up' + (has ? ' has' : '') + '" id="' + id + '" data-up="' + esc(kind) + '" tabindex="0" role="button" aria-label="' + esc(title) + '"' + (has ? ' style="background-image:url(\'' + esc(f.preview) + '\')"' : '') + '>' +
    (has ? '<span class="chg">' + (f.url ? 'Change photo' : 'Uploading…') + '</span>' : '<div style="font-size:28px">📷</div><b>' + esc(title) + '</b><div>' + esc(hint) + '</div>') + '</div>' +
    '<input type="file" accept="image/*"' + (cap ? ' capture="' + cap + '"' : '') + ' hidden data-file="' + esc(kind) + '">';
}
function upload(file, type) {
  var fd = new FormData(); fd.append('image', file); fd.append('type', type);
  return fetch(ETEL.api.replace('boot.php', 'upload.php'), { method: 'POST', headers: { 'Authorization': 'Bearer ' + Auth.getToken() }, body: fd })
    .then(function (r) { return r.json(); }).then(function (j) { if (!j || !j.success || !j.data) throw new Error((j && j.message) || 'Upload failed'); return j.data.url; });
}
var KIND = { idp: ['courier_id', function (D, f) { D.idp = f; }], selfie: ['courier_selfie', function (D, f) { D.selfie = f; }], lic: ['courier_licence', function (D, f) { D.lic = f; }] };
function pick(kind, file) {
  if (!file || !/^image\//.test(file.type)) { K.toast('Choose a picture file', true); return; }
  var f = { url: '', preview: URL.createObjectURL(file) }; KIND[kind][1](S.D, f); S.busy++; draw();
  upload(file, KIND[kind][0]).then(function (u) { f.url = u; }).catch(function (e) { K.toast(e.message, true); KIND[kind][1](S.D, null); })
    .then(function () { S.busy--; draw(); });
}

/* ---------- steps ---------- */
function zoneField(D) {
  var z = (typeof ETEL !== 'undefined' && ETEL && Array.isArray(ETEL.zones)) ? ETEL.zones : null;
  if (z && z.length) return field('cd-zone', 'Home zone', '<select id="cd-zone" class="ak-in"><option value="">Choose a zone</option>' + z.map(function (x) { return '<option value="' + esc(x) + '"' + (D.zone === x ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select>', 'Where they usually start work.');
  return field('cd-zone', 'Home zone', inp('cd-zone', D.zone, 'type="text" placeholder="e.g. Tabor"'), 'Optional. Where they usually start work.');
}
function stepHtml() {
  var D = S.D, i = S.step;
  if (i === 0) return '<h3 class="dv-h">Sign-in details</h3><p class="dv-p">The courier signs in to the Courier app with this phone and password.</p>' +
    field('cd-name', 'Full name', inp('cd-name', D.name, 'type="text" placeholder="e.g. Dawit Tesfaye"'), 'As on their ID.', true) +
    field('cd-phone', 'Phone', inp('cd-phone', D.phone, 'type="tel" inputmode="tel" placeholder="09XXXXXXXX"'), 'The number they sign in with.', true) +
    field('cd-pw', 'Password', '<div class="dv-pw">' + inp('cd-pw', D.pw, 'type="password" autocomplete="new-password" placeholder="At least 8 characters"') + '<button type="button" class="ak-btn" data-pw-show aria-label="Show password">👁</button><button type="button" class="ak-btn" data-pw-gen>🎲 Generate</button></div><div class="dv-str" id="cd-str" data-s="' + (D.pw ? strength(D.pw) : 0) + '"><i></i><i></i><i></i><i></i></div>', 'Send it to them privately.', true);
  if (i === 1) return '<h3 class="dv-h">How will they travel?</h3><p class="dv-p">The vehicle decides which jobs they are offered.</p><div class="dv-svc" role="group" aria-label="Vehicle">' +
    VEH.map(function (v) { return '<button type="button" data-vt="' + v[0] + '" class="' + (D.vt === v[0] ? 'on' : '') + '" aria-pressed="' + (D.vt === v[0]) + '"><span class="pic">' + pic(v[0], 110, v[1]) + '</span><b>' + esc(v[2]) + '</b><small>' + esc(v[3]) + '</small></button>'; }).join('') + '</div>' +
    '<div style="height:14px"></div>' + (needsPlate() ? field('cd-plate', 'Plate number', inp('cd-plate', D.plate, 'type="text" placeholder="e.g. 3-12345 AA" style="font-family:ui-monospace,monospace;letter-spacing:.08em;text-transform:uppercase"'), '', true) : '') + zoneField(D);
  if (i === 2) return '<h3 class="dv-h">Identity</h3><p class="dv-p">ETEL hands orders and cash to its couriers, so every courier is identified. These are kept private: only signed, expiring links are shown, only to staff.</p>' +
    '<div class="dv-f"><span>Kind of ID</span></div><div class="dv-tabs" role="group" aria-label="Kind of ID">' + IDS.map(function (x) { return '<button type="button" data-idt="' + x[0] + '" class="' + (D.idt === x[0] ? 'on' : '') + '" aria-pressed="' + (D.idt === x[0]) + '">' + esc(x[1]) + '</button>'; }).join('') + '</div>' +
    field('cd-idn', 'ID number', inp('cd-idn', D.idn, 'type="text" placeholder="As printed on the ID" style="text-transform:uppercase"'), '', false) +
    '<div class="dv-f"><span>Photo of the ID</span></div>' + upBox('cd-idp', D.idp, 'Photograph the ID', 'Flat, bright, all four corners, text readable.', 'idp', 'environment') +
    (D.vt === 'motorcycle' ? '<div class="dv-doc" style="margin-top:6px"><h5>Driving licence <small style="font-weight:400;color:var(--mute)">(motorcycle)</small></h5><div class="ak-fh" style="margin:0 0 8px">A motorcycle courier needs a valid licence.</div>' +
      field('cd-lnum', 'Licence number', inp('cd-lnum', D.lnum, 'type="text" placeholder="Driver\'s licence number" style="text-transform:uppercase"'), '') + upBox('cd-lic', D.lic, 'Photograph the licence', 'Front side, text readable.', 'lic', 'environment') + '</div>' : '');
  if (i === 3) return '<h3 class="dv-h">Selfie</h3><p class="dv-p">A clear photo of the courier\'s face, so ETEL and customers know who is delivering.</p>' +
    upBox('cd-self', D.selfie, 'Take or choose a selfie', 'Face the camera, good light.', 'selfie', 'user') +
    '<ul class="dv-tips"><li><u>1</u>Remove sunglasses and any head covering that hides the face</li><li><u>2</u>Hold the camera level with the face</li><li><u>3</u>Only the courier in the photo</li><li><u>4</u>A plain wall behind is best</li></ul>';
  if (i === 4) return '<h3 class="dv-h">Emergency contact</h3><p class="dv-p">Someone ETEL can call if something goes wrong on a delivery: a relative, a friend or a guarantor.</p>' +
    field('cd-en', 'Contact name', inp('cd-en', D.en, 'type="text" placeholder="Full name"'), '') +
    field('cd-ep', 'Contact phone', inp('cd-ep', D.ep, 'type="tel" inputmode="tel" placeholder="09XXXXXXXX"'), 'Must be someone else, not the courier.') +
    field('cd-addr', 'Home address', inp('cd-addr', D.addr, 'type="text" placeholder="e.g. Tabor, near the church"'), 'Optional.');
  return reviewHtml();
}
function checks() {
  var D = S.D;
  var rows = [
    ['Sign-in details', D.name.length >= 2 && phoneOk(D.phone) && D.pw.length >= 8, true, 0],
    ['Vehicle' + (needsPlate() ? ' and plate number' : ''), !needsPlate() || !!D.plate, true, 1],
    ['ID: kind, number and photo', !!(D.idn && D.idp && D.idp.url), false, 2]
  ];
  if (D.vt === 'motorcycle') rows.push(['Driving licence: number and photo', !!(D.lnum && D.lic && D.lic.url), false, 2]);
  rows.push(['Selfie', !!(D.selfie && D.selfie.url), false, 3]);
  rows.push(['Emergency contact', !!(D.en && D.ep && phoneOk(D.ep)), false, 4]);
  return rows;
}
function canActivate() { return checks().every(function (c) { return c[1]; }); }
function reviewHtml() {
  var D = S.D, ck = checks(), can = canActivate(), v = vehInfo();
  if (!can && D.activate) D.activate = false;
  var rows = ck.map(function (c) { return '<div class="dv-ck"><u>' + (c[1] ? '✅' : '⬜') + '</u><span>' + esc(c[0]) + '</span>' + (c[1] ? '' : '<button type="button" class="ak-btn" data-go="' + c[3] + '">Fix</button>') + '</div>'; }).join('');
  return '<h3 class="dv-h">Review</h3><div class="dv-prev"><div class="pic">' + pic(D.vt, 120, v[1]) + '</div><div><div style="font-weight:800;font-size:16px">' + esc(D.name || 'Courier') + '</div><div class="ak-fh" style="margin:2px 0 6px">' + esc(v[2]) + (D.zone ? ' · ' + esc(D.zone) : '') + '</div>' + (D.plate ? '<span class="dv-plate">' + esc(D.plate.toUpperCase()) + '</span>' : '') + '</div></div>' +
    '<div class="dv-box" style="margin-top:0">' + rows + '</div>' +
    '<div class="dv-opt' + (D.activate ? ' on' : '') + (can ? '' : ' off') + '" data-opt="activate" role="radio" aria-checked="' + (D.activate && can) + '" tabindex="0"><u style="text-decoration:none;font-size:22px">✅</u><div><b>Approve now</b><span>' + (can ? 'I checked the ID and the courier\'s face in person. They can take jobs as soon as they sign in.' : 'Needs everything in the list above.') + '</span></div></div>' +
    '<div class="dv-opt' + (!D.activate ? ' on' : '') + '" data-opt="review" role="radio" aria-checked="' + (!D.activate) + '" tabindex="0"><u style="text-decoration:none;font-size:22px">🕒</u><div><b>Save for review</b><span>The courier waits in the approval list and cannot take jobs until you approve them. Anything missing can be added later.</span></div></div>' +
    (S.dup ? '<label class="dv-opt on" style="border-color:var(--red,#d6364a)"><input type="checkbox" id="cd-confirm"' + (D.confirm ? ' checked' : '') + '><div><b>This ID number is already registered to another courier</b><span>Tick this only if it really is the same person and you want to register again on purpose.</span></div></label>' : '');
}

/* ---------- validation ---------- */
function validateStep(i, silent) {
  var D = S.D, ok = true, first = null;
  function need(id, bad, msg) { if (!silent) mark(id, bad ? msg : ''); if (bad && ok) { ok = false; first = id; } else if (bad) ok = false; }
  if (i === 0) { need('cd-name', D.name.length < 2, 'Enter the full name.'); need('cd-phone', !phoneOk(D.phone), 'Enter the full phone number.'); need('cd-pw', D.pw.length < 8, 'The password needs at least 8 characters.'); }
  if (i === 1 && needsPlate()) need('cd-plate', !D.plate, 'Enter the plate number.');
  if (i === 4 && (D.en || D.ep)) { need('cd-ep', D.ep && (!phoneOk(D.ep) || last9(D.ep) === last9(D.phone)), D.ep && last9(D.ep) === last9(D.phone) ? 'The emergency contact must be someone else.' : 'Enter the full phone number.'); need('cd-en', !D.en, 'Enter the contact\'s name.'); }
  if (!ok && first && !silent) { var el = $(first); if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus(); } }
  return ok;
}
function readStep() {
  if (!S.dr) return; var D = S.D, i = S.step;
  if (i === 0) { D.name = val('cd-name'); D.phone = val('cd-phone').replace(/\s/g, ''); D.pw = val('cd-pw'); var st = $('cd-str'); if (st) st.setAttribute('data-s', String(D.pw ? strength(D.pw) : 0)); }
  if (i === 1) { D.plate = val('cd-plate').toUpperCase(); D.zone = val('cd-zone'); }
  if (i === 2) { D.idn = val('cd-idn').toUpperCase(); D.lnum = val('cd-lnum').toUpperCase(); }
  if (i === 4) { D.en = val('cd-en'); D.ep = val('cd-ep').replace(/\s/g, ''); D.addr = val('cd-addr'); }
  if (i === 5) { var cf = $('cd-confirm'); if (cf) D.confirm = cf.checked; }
}

/* ---------- drawing ---------- */
function head() { return '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">' + pic('moto', 46, '🛵') + '</div><div class="ak-nm"><b>Courier desk</b><small>Register a courier step by step</small></div>'; }
function foot() { return '<span class="ak-err" id="cd-msg" role="status" aria-live="polite"></span><button type="button" class="ak-btn" data-back>← Back</button><button type="button" class="ak-btn gold" data-next></button>'; }
function paintChrome() {
  var ck = checks(), D = S.D;
  var done = [ck[0][1], ck[1][1], ck.filter(function (c) { return c[3] === 2; }).every(function (c) { return c[1]; }), ck.filter(function (c) { return c[3] === 3; })[0][1], ck.filter(function (c) { return c[3] === 4; })[0][1], false];
  $('cd-bar').style.width = Math.round(S.step / (STEPS.length - 1) * 100) + '%';
  S.dr.querySelector('.dv-steps').innerHTML = STEPS.map(function (s, i) { return '<button type="button" data-step="' + i + '" class="' + (i === S.step ? 'on' : (done[i] ? 'ok' : '')) + '"' + (i === S.step ? ' aria-current="step"' : '') + '>' + (done[i] && i !== S.step ? '✓' : s[1]) + ' ' + esc(s[0]) + '</button>'; }).join('');
  S.dr.querySelector('[data-next]').textContent = S.step === STEPS.length - 1 ? (D.activate ? '✓ Register and approve' : '✓ Register courier') : 'Next →';
  S.dr.querySelector('[data-back]').style.visibility = S.step === 0 ? 'hidden' : 'visible';
}
function draw() {
  if (!S.dr || S.result) return;
  $('cd-body').innerHTML = stepHtml();
  S.dr.querySelectorAll('[data-unlock]').forEach(function (el) { if (!el._u) { el._u = true; el.addEventListener('focus', function () { el.removeAttribute('readonly'); }); } });
  paintChrome();
}
function go(i) { readStep(); S.step = Math.max(0, Math.min(STEPS.length - 1, i)); $('cd-msg').textContent = ''; draw(); var b = $('cd-body'); if (b) b.scrollIntoView({ block: 'start' }); }

/* ---------- finish ---------- */
function baseUrl() { return location.origin + location.pathname.replace(/[^\/]*$/, ''); }
function copy(text) {
  var done = function () { K.toast('Copied'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { K.toast('Select the text and copy it by hand', true); }); return; }
  var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { K.toast('Select the text and copy it by hand', true); } t.remove();
}
function success(d) {
  var D = S.D, v = vehInfo(); S.result = d;
  S.msg = 'Welcome to ETEL, ' + D.name + '! Your courier account is ready.\nOpen the ETEL Courier app: ' + baseUrl() + 'courier/\nPhone: ' + D.phone + '\nPassword: ' + D.pw + '\nKeep this password private.' +
    (d.activated ? '\nYou can take jobs as soon as you sign in.' : '\nETEL is checking your details. We will tell you when you can start.');
  var miss = (d.missing || []).length ? '<p class="ak-fh" style="color:var(--amber,#d98e04)">Still missing: ' + esc(d.missing.join(', ')) + '. Add them, then approve the courier in Couriers.</p>' : '';
  S.dr.querySelector('.ak-dr-body').innerHTML = '<div class="dv-done"><div class="dv-ok">✓</div><h3 style="margin:0 0 4px">' + (d.activated ? 'Courier approved' : 'Courier saved for review') + '</h3>' +
    '<div class="dv-prev" style="justify-content:center;margin-top:12px"><div class="pic">' + pic(D.vt, 120, v[1]) + '</div><div style="text-align:left"><div style="font-weight:800">' + esc(D.name) + (d.code ? ' · ' + esc(d.code) : '') + '</div>' + (D.plate ? '<span class="dv-plate">' + esc(D.plate) + '</span>' : '<span class="ak-fh">' + esc(v[2]) + '</span>') + '</div></div></div>' + miss +
    '<div class="dv-box"><b>Sign-in message</b><pre>' + esc(S.msg) + '</pre><button type="button" class="ak-btn gold" data-copy-msg>Copy message</button></div>';
  S.dr.querySelector('.ak-dr-foot').innerHTML = '<button type="button" class="ak-btn" data-again>Register another courier</button><button type="button" class="ak-btn gold" data-close>Done</button>';
  ['loadCouriers', 'loadUsers', 'loadPeople'].forEach(function (f) { try { if (typeof window[f] === 'function') window[f](); } catch (e) { /* refreshes on its next visit */ } });
}
function submit(btn) {
  readStep();
  for (var i = 0; i < STEPS.length - 1; i++) if (!validateStep(i, false)) { go(i); validateStep(i, false); $('cd-msg').textContent = 'Check the highlighted fields.'; return; }
  if (S.busy) { $('cd-msg').textContent = 'Wait for the photos to finish uploading.'; return; }
  var D = S.D, p = { name: D.name, phone: D.phone, password: D.pw, vehicle_type: D.vt, plate_number: needsPlate() ? D.plate : '', home_zone: D.zone, id_type: D.idt, id_number: D.idn, id_photo_url: D.idp && D.idp.url || '',
    selfie_url: D.selfie && D.selfie.url || '', licence_number: D.vt === 'motorcycle' ? D.lnum : '', licence_photo_url: D.vt === 'motorcycle' && D.lic && D.lic.url || '', emergency_name: D.en, emergency_phone: D.ep, address: D.addr,
    activate: D.activate ? 1 : 0, confirm_duplicate: D.confirm ? 1 : 0 };
  btn.disabled = true; $('cd-msg').textContent = 'Registering…';
  K.api('admin_register_courier', p).then(function (res) {
    btn.disabled = false;
    if (!K.okRes(res)) { if (res && (res.duplicate_id || (res.data && res.data.duplicate_id))) { S.dup = true; draw(); } $('cd-msg').textContent = K.failText(res); return; }
    success(K.data(res));
  });
}

/* ---------- open ---------- */
function open() {
  if (window.EtelDriverRegister && EtelDriverRegister.css) EtelDriverRegister.css();   // the same step look as the Driver desk
  S.result = null; S.dup = false; S.busy = 0; S.step = 0; S.D = blank();
  var dr = K.openDrawer(head(), '<div class="dv-top"><div class="dv-steps" role="group" aria-label="Steps"></div><div class="dv-bar"><i id="cd-bar"></i></div></div><div id="cd-body"></div>', foot());
  S.dr = dr; dr.classList.add('ak-drv'); draw();
  var tabCss = document.getElementById('ak-cd-css');
  if (!tabCss) { tabCss = document.createElement('style'); tabCss.id = 'ak-cd-css'; tabCss.textContent = '.ak-drv .dv-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.ak-drv .dv-tabs button{padding:8px 14px;border-radius:99px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}.ak-drv .dv-tabs button.on{border-color:var(--gold);background:rgba(212,175,55,.14);box-shadow:0 0 0 3px rgba(212,175,55,.18)}'; document.head.appendChild(tabCss); }
  dr.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-step]'))) go(parseInt(b.getAttribute('data-step'), 10));
    else if (t.closest('[data-next]')) { readStep(); if (S.step === STEPS.length - 1) submit(t.closest('[data-next]')); else if (validateStep(S.step, false)) go(S.step + 1); else $('cd-msg').textContent = 'Check the highlighted fields.'; }
    else if (t.closest('[data-back]')) go(S.step - 1);
    else if ((b = t.closest('[data-go]'))) go(parseInt(b.getAttribute('data-go'), 10));
    else if ((b = t.closest('[data-vt]'))) { readStep(); S.D.vt = b.getAttribute('data-vt'); draw(); }
    else if ((b = t.closest('[data-idt]'))) { readStep(); S.D.idt = b.getAttribute('data-idt'); draw(); }
    else if ((b = t.closest('[data-opt]'))) { var act = b.getAttribute('data-opt') === 'activate'; if (act && !canActivate()) { K.toast('Finish everything in the list first.', true); return; } S.D.activate = act; draw(); }
    else if ((b = t.closest('[data-up]'))) { var f0 = dr.querySelector('[data-file="' + b.getAttribute('data-up') + '"]'); if (f0) f0.click(); }
    else if (t.closest('[data-pw-show]')) { var p = $('cd-pw'); p.type = p.type === 'password' ? 'text' : 'password'; }
    else if (t.closest('[data-pw-gen]')) {
      var a = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', n = new Uint32Array(10), out = ''; (window.crypto || window.msCrypto).getRandomValues(n);
      for (var i = 0; i < n.length; i++) out += a.charAt(n[i] % a.length);
      var pe = $('cd-pw'); pe.removeAttribute('readonly'); pe.value = out + '7'; pe.type = 'text'; readStep();
    }
    else if (t.closest('[data-copy-msg]')) copy(S.msg || '');
    else if (t.closest('[data-again]')) open();
  });
  dr.addEventListener('input', function (e) { var f = e.target.closest && e.target.closest('[data-f]'); if (f && f.classList.contains('bad')) mark(f.getAttribute('data-f'), ''); readStep(); });
  dr.addEventListener('change', function (e) {
    var k = e.target.getAttribute && e.target.getAttribute('data-file');
    if (k && e.target.files && e.target.files[0]) { pick(k, e.target.files[0]); e.target.value = ''; } else readStep();
  });
  dr.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-up],[data-opt]')) { e.preventDefault(); e.target.click(); } });
  ['dragenter', 'dragover'].forEach(function (n) { dr.addEventListener(n, function (e) { var u = e.target.closest && e.target.closest('[data-up]'); if (u) { e.preventDefault(); u.classList.add('over'); } }); });
  ['dragleave', 'drop'].forEach(function (n) { dr.addEventListener(n, function (e) { var u = e.target.closest && e.target.closest('[data-up]'); if (u) { e.preventDefault(); u.classList.remove('over'); if (n === 'drop' && e.dataTransfer && e.dataTransfer.files[0]) pick(u.getAttribute('data-up'), e.dataTransfer.files[0]); } }); });
  setTimeout(function () { var b = $('cd-name'); if (b) { b.removeAttribute('readonly'); b.focus(); } }, 320);
}

window.EtelCourierRegister = { open: open };
(function hook() {
  var orig = window.openModal;
  if (typeof orig !== 'function') return;
  window.openModal = function (type) { if (type === 'new-courier') return open(); return orig.apply(this, arguments); };
})();
})();
