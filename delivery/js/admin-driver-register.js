/* ETEL Admin - Driver desk: register a driver the way the Driver app's own sign-up does, but taken by ETEL staff (for a driver who comes in with their
   vehicle and papers). Seven short steps with a progress bar and a clickable step list:
     1 sign-in (name, phone, password)   2 the service (real vehicle pictures)   3 the vehicle (make, colour, year, plate, with a live preview)
     4 the driving licence (name, number, date of birth, photo)   5 the selfie   6 vehicle papers (insurance, technical inspection, road fund; optional)
     7 review: what is ready and what is missing, and a choice: switch the driver on now (staff checked the licence and face in person) or save for review.
   The last screen holds the sign-in message to copy. Photos upload as soon as they are picked (api/upload.php, the same folders the Driver app uses).
   Data: ride_get_vehicle_types (the active services), admin_register_driver (api/controllers/PeopleRegisterController.php). Built on AdminKit; every
   server value goes through AdminKit.esc; no inline handlers. Opens from "Register a person" > Driver and from openModal('new-driver'). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelDriverRegister) return;
var esc = K.esc;

var STEPS = [['Sign-in', '🔐'], ['Service', '🚦'], ['Vehicle', '🚘'], ['Licence', '🪪'], ['Selfie', '🤳'], ['Papers', '📄'], ['Review', '✅']];
var FALLBACK = ['bajaj', 'moto', 'car', 'minibus'];
var TITLE = { bajaj: 'Bajaj', moto: 'Motorcycle', car: 'Car', minibus: 'Minibus', qute: 'Qute', ev_car: 'EV Bajaj' };
var EMO = { bajaj: '🛺', moto: '🏍️', car: '🚗', minibus: '🚐', qute: '🛺', ev_car: '🚗' };
var COLORS = [['Black', '#1a1a1a'], ['White', '#f0f0f0'], ['Blue', '#1565c0'], ['Light Blue', '#42a5f5'], ['Red', '#c62828'], ['Yellow', '#f9a825'], ['Green', '#2e7d32'], ['Silver', '#9e9e9e']];
var DOCS = [['insurance', 'Insurance', 'Valid insurance certificate', 'driver_insurance_photo'], ['technical_inspection', 'Technical inspection', 'Annual vehicle inspection', 'driver_technical_inspection_photo'], ['road_fund', 'Road fund', 'Road fund payment receipt', 'driver_road_fund_photo']];
var S = { dr: null, step: 0, types: FALLBACK.slice(), fares: {}, busy: 0, D: null, result: null, mismatch: false };

function blank() {
  return { name: '', phone: '', pw: '', vt: '', make: '', color: 'Blue', year: '', plate: '', lname: '', lnum: '', ldob: '', lic: null, selfie: null,
    docs: { insurance: { exp: '', f: null }, technical_inspection: { exp: '', f: null }, road_fund: { exp: '', f: null } }, activate: false, confirm: false };
}
function css() {
  if (document.getElementById('ak-drv-css')) return;
  var s = document.createElement('style'); s.id = 'ak-drv-css';
  s.textContent = [
    '.ak-dr.ak-drv{width:min(700px,100vw)}',
    '.ak-drv .dv-top{position:sticky;top:-16px;z-index:3;margin:-16px -18px 14px;padding:12px 18px;background:var(--card);border-bottom:1px solid var(--line)}',
    '.ak-drv .dv-steps{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}',
    '.ak-drv .dv-steps button{flex:none;display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:99px;border:1.5px solid var(--line);background:var(--card);color:var(--mute);font:inherit;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}',
    '.ak-drv .dv-steps button.on{border-color:var(--gold);color:var(--ink);background:rgba(212,175,55,.13);box-shadow:0 0 0 3px rgba(212,175,55,.18)}',
    '.ak-drv .dv-steps button.ok{border-color:#3ad08a;color:var(--ink)}',
    '.ak-drv .dv-bar{height:7px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:10px}',
    '.ak-drv .dv-bar i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#E8C14A,#3ad08a);transition:width .25s}',
    '.ak-drv .dv-h{margin:0 0 4px;font-size:18px}.ak-drv .dv-p{margin:0 0 14px;font-size:13px;color:var(--mute);line-height:1.5}',
    '.ak-drv .dv-f{display:block;margin-bottom:12px}',
    '.ak-drv .dv-f>span{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);margin-bottom:5px}',
    '.ak-drv .dv-f>span em{color:var(--red,#d6364a);font-style:normal}',
    '.ak-drv .dv-f .ak-in{font-size:15px;padding:11px 12px}.ak-drv .dv-f.bad .ak-in{border-color:var(--red,#d6364a)}',
    '.ak-drv .dv-f small{display:block;margin-top:4px;font-size:11.5px;color:var(--mute);min-height:14px;text-transform:none;letter-spacing:0;font-family:inherit;font-weight:400}',
    '.ak-drv .dv-f.bad small{color:var(--red,#d6364a)}',
    '.ak-drv .dv-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.ak-drv .dv-pw{display:flex;gap:6px}.ak-drv .dv-pw .ak-in{flex:1;min-width:0}',
    '.ak-drv .dv-str{display:flex;gap:4px;margin-top:6px}.ak-drv .dv-str i{flex:1;height:5px;border-radius:99px;background:var(--line)}',
    '.ak-drv .dv-str[data-s="1"] i:nth-child(-n+1){background:#d6364a}.ak-drv .dv-str[data-s="2"] i:nth-child(-n+2){background:#f0b429}.ak-drv .dv-str[data-s="3"] i:nth-child(-n+3){background:#9bd34a}.ak-drv .dv-str[data-s="4"] i{background:#3ad08a}',
    '.ak-drv .dv-svc{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}',
    '.ak-drv .dv-svc button{display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 8px 12px;border-radius:16px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;cursor:pointer}',
    '.ak-drv .dv-svc button .pic{height:74px;display:flex;align-items:center;justify-content:center;font-size:44px;width:100%}.ak-drv .dv-svc button .pic img{max-height:100%;width:auto}',
    '.ak-drv .dv-svc button b{font-size:14px}.ak-drv .dv-svc button small{color:var(--mute);font-size:11.5px}',
    '.ak-drv .dv-svc button.on{border-color:var(--gold);background:rgba(212,175,55,.12);box-shadow:0 0 0 3px rgba(212,175,55,.2)}',
    '.ak-drv .dv-prev{display:flex;gap:14px;align-items:center;padding:12px;border-radius:16px;border:1px solid var(--line);background:linear-gradient(135deg,rgba(212,175,55,.1),rgba(212,175,55,.02));margin-bottom:14px}',
    '.ak-drv .dv-prev .pic{width:120px;flex:none;text-align:center;font-size:50px}.ak-drv .dv-prev .pic img{max-width:100%;height:auto}',
    '.ak-drv .dv-plate{display:inline-block;font-family:ui-monospace,monospace;font-weight:900;letter-spacing:.12em;font-size:18px;padding:5px 12px;border-radius:8px;background:#fff;color:#111;border:2px solid #111;min-width:110px;text-align:center}',
    '.ak-drv .dv-sw{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}.ak-drv .dv-sw button{width:34px;height:34px;border-radius:50%;border:2px solid transparent;cursor:pointer;box-shadow:inset 0 0 0 1px rgba(0,0,0,.2)}',
    '.ak-drv .dv-sw button.on{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.35)}',
    '.ak-drv .dv-up{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:150px;border:2px dashed rgba(212,175,55,.55);border-radius:14px;background:rgba(212,175,55,.06) center/cover no-repeat;cursor:pointer;text-align:center;font-size:13px;color:var(--mute);margin-bottom:12px;position:relative;overflow:hidden}',
    '.ak-drv .dv-up.has{min-height:190px}.ak-drv .dv-up.over{background-color:rgba(212,175,55,.2)}.ak-drv .dv-up b{color:var(--ink)}',
    '.ak-drv .dv-up .chg{position:absolute;right:8px;bottom:8px;padding:5px 12px;border-radius:99px;background:rgba(26,26,46,.85);color:#E8C14A;font-size:12px;font-weight:700}',
    '.ak-drv .dv-up.busy::after{content:"Uploading…";position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);color:#fff;font-weight:700}',
    '.ak-drv .dv-tips{margin:0 0 12px;padding:0;list-style:none;display:grid;gap:6px}.ak-drv .dv-tips li{display:flex;gap:10px;align-items:flex-start;font-size:13px}',
    '.ak-drv .dv-tips li u{text-decoration:none;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#E8C14A,#b8902a);color:#1a1405;font-weight:800;font-size:12px;display:inline-flex;align-items:center;justify-content:center;flex:none}',
    '.ak-drv .dv-doc{border:1px solid var(--line);border-radius:14px;padding:12px;margin-bottom:12px;background:var(--card)}',
    '.ak-drv .dv-doc h5{margin:0 0 2px;font-size:14px}.ak-drv .dv-doc .dv-up{min-height:90px;margin-bottom:8px}',
    '.ak-drv .dv-ck{display:flex;gap:10px;align-items:center;padding:9px 0;border-top:1px solid var(--line);font-size:13.5px}.ak-drv .dv-ck:first-child{border-top:0}',
    '.ak-drv .dv-ck u{text-decoration:none;font-size:16px}.ak-drv .dv-ck span{flex:1}.ak-drv .dv-ck small{color:var(--mute)}',
    '.ak-drv .dv-opt{display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:14px;border:1.5px solid var(--line);background:var(--card);margin-bottom:10px;cursor:pointer}',
    '.ak-drv .dv-opt.on{border-color:var(--gold);background:rgba(212,175,55,.1);box-shadow:0 0 0 3px rgba(212,175,55,.18)}.ak-drv .dv-opt.off{opacity:.5;cursor:not-allowed}',
    '.ak-drv .dv-opt b{display:block;font-size:14px}.ak-drv .dv-opt span{font-size:12.5px;color:var(--mute)}',
    '.ak-drv .dv-done{text-align:center;padding:10px 0 4px}.ak-drv .dv-ok{width:64px;height:64px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;background:linear-gradient(135deg,#3ad08a,#1fa463);color:#fff}',
    '.ak-drv .dv-box{border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:12px 0;text-align:left;background:var(--card)}.ak-drv .dv-box pre{white-space:pre-wrap;word-break:break-word;font:inherit;font-size:13px;margin:6px 0 10px}',
    '@media(max-width:560px){.ak-drv .dv-2{grid-template-columns:1fr}.ak-drv .dv-prev{flex-direction:column;text-align:center}}'
  ].join('\n');
  document.head.appendChild(s);
}

function $(id) { return document.getElementById(id); }
function val(id) { var e = $(id); return e ? e.value.trim() : ''; }
function inp(id, v, attrs) { return '<input id="' + id + '" name="' + id + '-' + Date.now().toString(36) + '" class="ak-in" readonly data-unlock value="' + esc(v == null ? '' : v) + '" autocomplete="off" ' + (attrs || '') + '>'; }
function field(id, label, input, hint, req) { return '<label class="dv-f" data-f="' + id + '"><span>' + esc(label) + (req ? ' <em>*</em>' : '') + '</span>' + input + '<small data-h="' + esc(hint || '') + '">' + esc(hint || '') + '</small></label>'; }
function mark(id, msg) { var f = S.dr && S.dr.querySelector('[data-f="' + id + '"]'); if (!f) return; f.classList.toggle('bad', !!msg); var sm = f.querySelector('small'); if (sm) sm.textContent = msg || sm.getAttribute('data-h') || ''; }
function pic(type, px) { return (window.EtelVehiclePic && EtelVehiclePic.key(type)) ? EtelVehiclePic.html(type, px, { fallback: EMO[type] || '🚗' }) : esc(EMO[type] || '🚗'); }
function phoneOk(p) { return p.replace(/[^0-9]/g, '').length >= 9; }
function strength(p) { var s = 0; if (p.length >= 8) s++; if (p.length >= 11) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p) && /[A-Za-z]/.test(p)) s++; return p.length < 8 ? Math.min(s, 1) : Math.max(s, 2); }
function age18(iso) { var d = new Date(iso); if (isNaN(d)) return false; var c = new Date(); c.setFullYear(c.getFullYear() - 18); return d <= c; }

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
var KIND = { lic: ['driver_license', function (D, f) { D.lic = f; }], selfie: ['driver_selfie', function (D, f) { D.selfie = f; }] };
DOCS.forEach(function (d) { KIND[d[0]] = [d[3], function (D, f) { D.docs[d[0]].f = f; }]; });
function getFile(kind) { var D = S.D; return kind === 'lic' ? D.lic : kind === 'selfie' ? D.selfie : D.docs[kind].f; }
function pick(kind, file) {
  if (!file || !/^image\//.test(file.type)) { K.toast('Choose a picture file', true); return; }
  var f = { url: '', preview: URL.createObjectURL(file) }; KIND[kind][1](S.D, f); S.busy++; draw();
  upload(file, KIND[kind][0]).then(function (u) { f.url = u; }).catch(function (e) { K.toast(e.message, true); KIND[kind][1](S.D, null); })
    .then(function () { S.busy--; draw(); });
}

/* ---------- steps ---------- */
function stepHtml() {
  var D = S.D, i = S.step;
  if (i === 0) return '<h3 class="dv-h">Sign-in details</h3><p class="dv-p">The driver signs in to the Driver app with this phone and password.</p>' +
    field('dv-name', 'Full name', inp('dv-name', D.name, 'type="text" placeholder="e.g. Kebede Alemu"'), 'As it should appear to riders.', true) +
    field('dv-phone', 'Phone', inp('dv-phone', D.phone, 'type="tel" inputmode="tel" placeholder="09XXXXXXXX"'), 'The number they sign in with.', true) +
    field('dv-pw', 'Password', '<div class="dv-pw">' + inp('dv-pw', D.pw, 'type="password" autocomplete="new-password" placeholder="At least 8 characters"') + '<button type="button" class="ak-btn" data-pw-show aria-label="Show password">👁</button><button type="button" class="ak-btn" data-pw-gen>🎲 Generate</button></div><div class="dv-str" id="dv-str" data-s="' + (D.pw ? strength(D.pw) : 0) + '"><i></i><i></i><i></i><i></i></div>', 'Send it to them privately.', true);
  if (i === 1) return '<h3 class="dv-h">Choose a service</h3><p class="dv-p">The kind of trips this driver will complete in Hawassa.</p><div class="dv-svc" role="group" aria-label="Service">' +
    S.types.map(function (t) { var fare = S.fares[t]; return '<button type="button" data-vt="' + esc(t) + '" class="' + (D.vt === t ? 'on' : '') + '" aria-pressed="' + (D.vt === t) + '"><span class="pic">' + pic(t, 110) + '</span><b>' + esc(TITLE[t] || t) + '</b>' + (fare ? '<small>from ETB ' + esc(Math.round(fare)) + '</small>' : '') + '</button>'; }).join('') + '</div>' +
    '<p class="ak-fh" id="dv-vt-err" style="color:var(--red,#d6364a);min-height:16px;margin-top:8px"></p>';
  if (i === 2) return '<h3 class="dv-h">The vehicle</h3><p class="dv-p">Their own or rented vehicle. The plate number is what riders and traffic officers see.</p>' +
    '<div class="dv-prev"><div class="pic" id="dv-pp">' + pic(D.vt, 120) + '</div><div><div style="font-weight:800;font-size:16px" id="dv-pt"></div><div style="margin-top:8px"><span class="dv-plate" id="dv-pl"></span></div></div></div>' +
    '<div class="dv-2">' + field('dv-make', 'Make / brand', inp('dv-make', D.make, 'type="text" placeholder="e.g. Bajaj, TVS, Toyota"'), 'Optional.') + field('dv-year', 'Manufacture year', inp('dv-year', D.year, 'type="text" inputmode="numeric" maxlength="4" placeholder="e.g. 2018"'), 'Optional.') + '</div>' +
    '<div class="dv-f"><span>Colour</span></div><div class="dv-sw" role="group" aria-label="Colour">' + COLORS.map(function (c) { return '<button type="button" data-color="' + esc(c[0]) + '" class="' + (D.color === c[0] ? 'on' : '') + '" style="background:' + c[1] + '" title="' + esc(c[0]) + '" aria-label="' + esc(c[0]) + '"></button>'; }).join('') + '</div>' +
    field('dv-color', 'Or type the colour', inp('dv-color', COLORS.some(function (c) { return c[0] === D.color; }) ? '' : D.color, 'type="text" placeholder="e.g. Orange"'), '') +
    field('dv-plate', 'Plate number', inp('dv-plate', D.plate, 'type="text" placeholder="e.g. 3-12345 AA" style="font-family:ui-monospace,monospace;letter-spacing:.08em;text-transform:uppercase"'), '', true);
  if (i === 3) return '<h3 class="dv-h">Driving licence</h3><p class="dv-p">Copy the details exactly as printed on the licence. They are checked against the driver\'s EtelID record.</p>' +
    field('dv-lcountry', 'Country', '<select id="dv-lcountry" class="ak-in"><option>Ethiopia</option></select>', '') +
    field('dv-lname', 'Full name (on the licence)', inp('dv-lname', D.lname, 'type="text" placeholder="First and last name"'), '', false) +
    '<div class="dv-2">' + field('dv-lnum', 'Licence number', inp('dv-lnum', D.lnum, 'type="text" placeholder="Driver\'s licence number"'), '') + field('dv-ldob', 'Date of birth', '<input id="dv-ldob" class="ak-in" type="date" value="' + esc(D.ldob) + '">', 'The driver must be at least 18.') + '</div>' +
    '<div class="dv-f"><span>Photo of the licence</span></div>' + upBox('dv-lic', D.lic, 'Photograph the licence', 'Flat, bright, all four corners and the text readable.', 'lic', 'environment');
  if (i === 4) return '<h3 class="dv-h">Selfie</h3><p class="dv-p">A clear photo of the driver\'s face, so riders and ETEL know who is behind the wheel.</p>' +
    upBox('dv-self', D.selfie, 'Take or choose a selfie', 'Face the camera, good light.', 'selfie', 'user') +
    '<ul class="dv-tips"><li><u>1</u>Remove any head covering or sunglasses</li><li><u>2</u>Hold the camera level with the face</li><li><u>3</u>Only the driver in the photo</li><li><u>4</u>A plain wall behind is best</li></ul>';
  if (i === 5) return '<h3 class="dv-h">Vehicle papers <small style="font-weight:400;color:var(--mute)">(optional)</small></h3><p class="dv-p">Traffic officers see these as Valid or Expired. You can skip them now: the driver can add them later in the app.</p>' +
    DOCS.map(function (d) { var x = D.docs[d[0]]; return '<div class="dv-doc"><h5>' + esc(d[1]) + '</h5><div class="ak-fh" style="margin:0 0 8px">' + esc(d[2]) + '</div>' + upBox('dv-doc-' + d[0], x.f, 'Add a photo', 'The certificate or receipt.', d[0], 'environment') +
      field('dv-exp-' + d[0], 'Valid until', '<input id="dv-exp-' + d[0] + '" class="ak-in" type="date" value="' + esc(x.exp) + '">', '') + '</div>'; }).join('');
  return reviewHtml();
}
function checks() {
  var D = S.D;
  return [
    ['Sign-in details', D.name.length >= 2 && phoneOk(D.phone) && D.pw.length >= 8, true, 0],
    ['Service', !!D.vt, true, 1],
    ['Plate number', D.plate.length > 0, true, 2],
    ['Licence details (name, number, date of birth)', !!(D.lname && D.lnum && D.ldob), false, 3],
    ['Photo of the licence', !!(D.lic && D.lic.url), false, 3],
    ['Selfie', !!(D.selfie && D.selfie.url), false, 4],
    ['Vehicle papers', DOCS.some(function (d) { return D.docs[d[0]].f && D.docs[d[0]].exp; }), null, 5]
  ];
}
function canActivate() { return checks().every(function (c) { return c[2] === null || c[1]; }); }
function reviewHtml() {
  var D = S.D, ck = checks(), can = canActivate();
  if (!can && D.activate) D.activate = false;
  var rows = ck.map(function (c) { var opt = c[2] === null; return '<div class="dv-ck"><u>' + (c[1] ? '✅' : (opt ? '➖' : '⬜')) + '</u><span>' + esc(c[0]) + (opt && !c[1] ? ' <small>(optional)</small>' : '') + '</span>' + (c[1] ? '' : '<button type="button" class="ak-btn" data-go="' + c[3] + '">Fix</button>') + '</div>'; }).join('');
  return '<h3 class="dv-h">Review</h3><div class="dv-prev"><div class="pic">' + pic(D.vt, 120) + '</div><div><div style="font-weight:800;font-size:16px">' + esc(D.name || 'Driver') + '</div><div class="ak-fh" style="margin:2px 0 6px">' + esc(TITLE[D.vt] || D.vt || '') + (D.make ? ' · ' + esc(D.make) : '') + (D.color ? ' · ' + esc(D.color) : '') + (D.year ? ' · ' + esc(D.year) : '') + '</div><span class="dv-plate">' + esc(D.plate.toUpperCase() || '—') + '</span></div></div>' +
    '<div class="dv-box" style="margin-top:0">' + rows + '</div>' +
    '<div class="dv-opt' + (D.activate ? ' on' : '') + (can ? '' : ' off') + '" data-opt="activate" role="radio" aria-checked="' + (D.activate && can) + '" tabindex="0"><u style="text-decoration:none;font-size:22px">✅</u><div><b>Switch the driver on now</b><span>' + (can ? 'I checked the licence and the driver\'s face in person. They can go online straight away.' : 'Needs the licence details and photo and the selfie.') + '</span></div></div>' +
    '<div class="dv-opt' + (!D.activate ? ' on' : '') + '" data-opt="review" role="radio" aria-checked="' + (!D.activate) + '" tabindex="0"><u style="text-decoration:none;font-size:22px">🕒</u><div><b>Save for review</b><span>The driver is saved as pending. Anything missing can be added later, then you approve them.</span></div></div>' +
    (S.mismatch ? '<label class="dv-opt on" style="border-color:var(--red,#d6364a)"><input type="checkbox" id="dv-confirm"' + (D.confirm ? ' checked' : '') + '><div><b>The licence does not match the EtelID record</b><span>Tick this only if you checked the licence in person and want to switch the driver on anyway.</span></div></label>' : '');
}

/* ---------- validation per step ---------- */
function validateStep(i, silent) {
  var D = S.D, ok = true, first = null;
  function need(id, bad, msg) { if (!silent) mark(id, bad ? msg : ''); if (bad && ok) { ok = false; first = id; } else if (bad) ok = false; }
  if (i === 0) { need('dv-name', D.name.length < 2, 'Enter the full name.'); need('dv-phone', !phoneOk(D.phone), 'Enter the full phone number.'); need('dv-pw', D.pw.length < 8, 'The password needs at least 8 characters.'); }
  if (i === 1) { if (!D.vt) { ok = false; if (!silent) { var e = $('dv-vt-err'); if (e) e.textContent = 'Choose a service.'; } } }
  if (i === 2) { need('dv-plate', !D.plate, 'Enter the plate number.'); need('dv-year', D.year !== '' && !/^(19[89]\d|20\d\d)$/.test(D.year), 'Four digits, like 2018, or leave it empty.'); }
  if (i === 3) {
    var any = D.lname || D.lnum || D.ldob || D.lic;
    if (any) { need('dv-lname', !D.lname, 'Enter the name on the licence.'); need('dv-ldob', !D.ldob || !age18(D.ldob), 'Enter the date of birth. The driver must be at least 18.'); }
  }
  if (i === 5) DOCS.forEach(function (d) { var x = D.docs[d[0]]; if (x.exp || x.f) { need('dv-exp-' + d[0], !x.exp, 'Enter the date it is valid until.'); if (!x.f && ok) { ok = false; if (!silent) K.toast('Add a photo of the ' + d[1].toLowerCase() + ', or clear its date.', true); } } });
  if (!ok && first && !silent) { var el = $(first); if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.focus(); } }
  return ok;
}
function readStep() {
  if (!S.dr) return; var D = S.D, i = S.step;
  if (i === 0) { D.name = val('dv-name'); D.phone = val('dv-phone').replace(/\s/g, ''); D.pw = val('dv-pw'); var st = $('dv-str'); if (st) st.setAttribute('data-s', String(D.pw ? strength(D.pw) : 0)); }
  if (i === 2) { D.make = val('dv-make'); D.year = val('dv-year'); D.plate = val('dv-plate').toUpperCase(); var c = val('dv-color'); if (c) D.color = c; }
  if (i === 3) { D.lname = val('dv-lname'); D.lnum = val('dv-lnum'); D.ldob = val('dv-ldob'); }
  if (i === 5) DOCS.forEach(function (d) { D.docs[d[0]].exp = val('dv-exp-' + d[0]); });
  if (i === 6) { var cf = $('dv-confirm'); if (cf) D.confirm = cf.checked; }
}

/* ---------- drawing ---------- */
function head() { return '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">' + pic('bajaj', 46) + '</div><div class="ak-nm"><b>Driver desk</b><small>Register a driver step by step</small></div>'; }
function foot() { return '<span class="ak-err" id="dv-msg" role="status" aria-live="polite"></span><button type="button" class="ak-btn" data-back>← Back</button><button type="button" class="ak-btn gold" data-next></button>'; }
function paintChrome() {
  var D = S.D, ck = checks();
  var done = [ck[0][1], ck[1][1], ck[2][1], ck[3][1] && ck[4][1], ck[5][1], ck[6][1], false];
  var n = [ck[0][1], ck[1][1], ck[2][1], ck[3][1] && ck[4][1], ck[5][1]].filter(Boolean).length;
  $('dv-bar').style.width = Math.round((S.step) / (STEPS.length - 1) * 100) + '%';
  S.dr.querySelector('.dv-steps').innerHTML = STEPS.map(function (s, i) { return '<button type="button" data-step="' + i + '" class="' + (i === S.step ? 'on' : (done[i] ? 'ok' : '')) + '"' + (i === S.step ? ' aria-current="step"' : '') + '>' + (done[i] && i !== S.step ? '✓' : s[1]) + ' ' + esc(s[0]) + '</button>'; }).join('');
  var next = S.dr.querySelector('[data-next]'), back = S.dr.querySelector('[data-back]');
  next.textContent = S.step === STEPS.length - 1 ? (D.activate ? '✓ Register and switch on' : '✓ Register driver') : 'Next →'; back.style.visibility = S.step === 0 ? 'hidden' : 'visible';
  void n;
}
function draw() {
  if (!S.dr || S.result) return;
  $('dv-body').innerHTML = stepHtml();
  S.dr.querySelectorAll('[data-unlock]').forEach(function (el) { if (!el._u) { el._u = true; el.addEventListener('focus', function () { el.removeAttribute('readonly'); }); } });
  if (S.step === 2) preview();
  paintChrome();
}
function preview() {
  var D = S.D; if (!$('dv-pt')) return;
  $('dv-pt').textContent = (TITLE[D.vt] || 'Vehicle') + (D.make ? ' · ' + D.make : '') + (D.color ? ' · ' + D.color : '') + (D.year ? ' · ' + D.year : '');
  $('dv-pl').textContent = D.plate || 'PLATE';
}
function go(i) { readStep(); S.step = Math.max(0, Math.min(STEPS.length - 1, i)); $('dv-msg').textContent = ''; draw(); var b = $('dv-body'); if (b) b.scrollIntoView({ block: 'start' }); }

/* ---------- finish ---------- */
function baseUrl() { return location.origin + location.pathname.replace(/[^\/]*$/, ''); }
function copy(text) {
  var done = function () { K.toast('Copied'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { K.toast('Select the text and copy it by hand', true); }); return; }
  var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { K.toast('Select the text and copy it by hand', true); } t.remove();
}
function success(d) {
  var D = S.D; S.result = d;
  S.msg = 'Welcome to ETEL, ' + D.name + '! Your driver account is ready.\nOpen the ETEL Driver app: ' + baseUrl() + 'driver/\nPhone: ' + D.phone + '\nPassword: ' + D.pw + '\nKeep this password private.' +
    (d.activated ? '\nYou can go online now.' : '\nETEL is checking your details. We will tell you when you can go online.');
  var miss = (d.missing || []).length ? '<p class="ak-fh" style="color:var(--amber,#d98e04)">Still missing: ' + esc(d.missing.join(', ')) + '. Add them, then approve the driver in Rides &gt; Drivers.</p>' : '';
  S.dr.querySelector('.ak-dr-body').innerHTML = '<div class="dv-done"><div class="dv-ok">✓</div><h3 style="margin:0 0 4px">' + (d.activated ? 'Driver is switched on' : 'Driver saved for review') + '</h3>' +
    '<div class="dv-prev" style="justify-content:center;margin-top:12px"><div class="pic">' + pic(D.vt, 120) + '</div><div style="text-align:left"><div style="font-weight:800">' + esc(D.name) + '</div><span class="dv-plate">' + esc(D.plate) + '</span></div></div></div>' +
    (d.identity_check === 'mismatch' ? '<p class="ak-fh" style="color:var(--red,#d6364a)">The licence name or date of birth did not match the EtelID record.</p>' : d.identity_check === 'matched' ? '<p class="ak-fh" style="color:#2a9d63">The licence details match the driver\'s EtelID record.</p>' : '') + miss +
    '<div class="dv-box"><b>Sign-in message</b><pre>' + esc(S.msg) + '</pre><button type="button" class="ak-btn gold" data-copy-msg>Copy message</button></div>';
  S.dr.querySelector('.ak-dr-foot').innerHTML = '<button type="button" class="ak-btn" data-again>Register another driver</button><button type="button" class="ak-btn gold" data-close>Done</button>';
  ['loadUsers', 'loadDrivers', 'loadRidesDrivers', 'loadPeople'].forEach(function (f) { try { if (typeof window[f] === 'function') window[f](); } catch (e) { /* refreshes on its next visit */ } });
}
function submit(btn) {
  readStep();
  for (var i = 0; i < STEPS.length - 1; i++) if (!validateStep(i, false)) { go(i); $('dv-msg').textContent = 'Check the highlighted fields.'; validateStep(i, false); return; }
  if (S.busy) { $('dv-msg').textContent = 'Wait for the photos to finish uploading.'; return; }
  var D = S.D, p = { name: D.name, phone: D.phone, password: D.pw, vehicle_type: D.vt, vehicle_make: D.make, vehicle_color: D.color, vehicle_year: D.year, vehicle_plate: D.plate,
    license_name: D.lname, license_number: D.lnum, license_dob: D.ldob, license_photo_url: D.lic && D.lic.url || '', selfie_url: D.selfie && D.selfie.url || '', activate: D.activate ? 1 : 0, confirm_mismatch: D.confirm ? 1 : 0, docs: {} };
  DOCS.forEach(function (d) { var x = D.docs[d[0]]; if (x.f && x.f.url && x.exp) p.docs[d[0]] = { expiry_date: x.exp, receipt_url: x.f.url }; });
  btn.disabled = true; $('dv-msg').textContent = 'Registering…';
  K.api('admin_register_driver', p).then(function (res) {
    btn.disabled = false;
    if (!K.okRes(res)) {
      var data = res && (res.data || res); if (res && (res.identity_check === 'mismatch' || (data && data.identity_check === 'mismatch'))) { S.mismatch = true; draw(); }
      $('dv-msg').textContent = K.failText(res); return;
    }
    success(K.data(res));
  });
}

/* ---------- open ---------- */
function loadTypes() {
  return K.api('ride_get_vehicle_types', {}).then(function (res) {
    var d = K.okRes(res) ? K.data(res) : {};
    var list = d.vehicle_types || d.types;
    if (Array.isArray(list) && list.length) S.types = list.map(function (t) { return String(t).toLowerCase(); });
    S.fares = d.base_fares && typeof d.base_fares === 'object' ? d.base_fares : {};
  }).catch(function () { /* the built-in four */ });
}
function open() {
  css(); S.result = null; S.mismatch = false; S.busy = 0; S.step = 0; S.D = blank();
  var go0 = function () {
    var dr = K.openDrawer(head(), '<div class="dv-top"><div class="dv-steps" role="group" aria-label="Steps"></div><div class="dv-bar"><i id="dv-bar"></i></div></div><div id="dv-body"></div>', foot());
    S.dr = dr; dr.classList.add('ak-drv'); draw();
    dr.addEventListener('click', function (e) {
      var t = e.target, b;
      if ((b = t.closest('[data-step]'))) go(parseInt(b.getAttribute('data-step'), 10));
      else if (t.closest('[data-next]')) { readStep(); if (S.step === STEPS.length - 1) submit(t.closest('[data-next]')); else if (validateStep(S.step, false)) go(S.step + 1); else $('dv-msg').textContent = 'Check the highlighted fields.'; }
      else if (t.closest('[data-back]')) go(S.step - 1);
      else if ((b = t.closest('[data-go]'))) go(parseInt(b.getAttribute('data-go'), 10));
      else if ((b = t.closest('[data-vt]'))) { S.D.vt = b.getAttribute('data-vt'); draw(); var er = $('dv-vt-err'); if (er) er.textContent = ''; }
      else if ((b = t.closest('[data-color]'))) { S.D.color = b.getAttribute('data-color'); if ($('dv-color')) $('dv-color').value = ''; dr.querySelectorAll('[data-color]').forEach(function (x) { x.classList.toggle('on', x === b); }); preview(); }
      else if ((b = t.closest('[data-opt]'))) { var act = b.getAttribute('data-opt') === 'activate'; if (act && !canActivate()) { K.toast('Add the licence details, the licence photo and the selfie first.', true); return; } S.D.activate = act; draw(); }
      else if ((b = t.closest('[data-up]'))) { var inp0 = dr.querySelector('[data-file="' + b.getAttribute('data-up') + '"]'); if (inp0) inp0.click(); }
      else if (t.closest('[data-pw-show]')) { var p = $('dv-pw'); p.type = p.type === 'password' ? 'text' : 'password'; }
      else if (t.closest('[data-pw-gen]')) {
        var a = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', n = new Uint32Array(10), out = ''; (window.crypto || window.msCrypto).getRandomValues(n);
        for (var i = 0; i < n.length; i++) out += a.charAt(n[i] % a.length);
        var pe = $('dv-pw'); pe.removeAttribute('readonly'); pe.value = out + '7'; pe.type = 'text'; readStep();
      }
      else if (t.closest('[data-copy-msg]')) copy(S.msg || '');
      else if (t.closest('[data-again]')) open();
    });
    dr.addEventListener('input', function (e) {
      var f = e.target.closest && e.target.closest('[data-f]'); if (f && f.classList.contains('bad')) mark(f.getAttribute('data-f'), '');
      readStep(); if (S.step === 2) preview();
    });
    dr.addEventListener('change', function (e) {
      var k = e.target.getAttribute && e.target.getAttribute('data-file');
      if (k && e.target.files && e.target.files[0]) { pick(k, e.target.files[0]); e.target.value = ''; }
      else if (e.target.id === 'dv-confirm') readStep();
      else readStep();
    });
    dr.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-up],[data-opt]')) { e.preventDefault(); e.target.click(); } });
    ['dragenter', 'dragover'].forEach(function (n) { dr.addEventListener(n, function (e) { var u = e.target.closest && e.target.closest('[data-up]'); if (u) { e.preventDefault(); u.classList.add('over'); } }); });
    ['dragleave', 'drop'].forEach(function (n) { dr.addEventListener(n, function (e) { var u = e.target.closest && e.target.closest('[data-up]'); if (u) { e.preventDefault(); u.classList.remove('over'); if (n === 'drop' && e.dataTransfer && e.dataTransfer.files[0]) pick(u.getAttribute('data-up'), e.dataTransfer.files[0]); } }); });
    setTimeout(function () { var b = $('dv-name'); if (b) { b.removeAttribute('readonly'); b.focus(); } }, 320);
  };
  return loadTypes().then(go0);
}

window.EtelDriverRegister = { open: open, css: css };
/* openModal('new-driver') opens the desk, and "Register a person" hands its Driver choice over to it. */
(function hook() {
  var orig = window.openModal;
  if (typeof orig !== 'function') return;
  window.openModal = function (type) { if (type === 'new-driver') return open(); return orig.apply(this, arguments); };
})();
})();
