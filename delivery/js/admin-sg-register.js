/* ETEL Admin - Register a business (service giver): one guided panel for both ways in.
   "Go live now": admin types the owner's phone and a password and the business is verified straight away.
   "Pre-register": only the business name is needed; the account waits (no sign-in, hidden from customers) and a one-time claim link lets the
   owner set their own phone and password later (see api/lib/sg_claim.php), after which admin verifies.
   Sections: business, owner and sign-in, location (a Google Places search box, the map pin and the auto zone, reusing the picker in admin.js), extras. A progress bar shows what is
   still missing, fields are checked as you type, and the last screen holds the sign-in message or claim link ready to copy.
   Data: admin_create_service_giver. Built on AdminKit; every server value goes through AdminKit.esc; no inline handlers.
   Opens from "Register a business" on the Service givers page and from the old openModal('new-sg') entry points. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.EtelSgRegister) return;
var esc = K.esc;

var TYPES = [['restaurant', '🍽️', 'Restaurant'], ['cafe', '☕', 'Cafe'], ['fastfood', '🍔', 'Fast food'], ['grocery', '🛒', 'Grocery'], ['pharmacy', '💊', 'Pharmacy'],
  ['laundry', '🧺', 'Laundry'], ['electronics', '🔌', 'Electronics'], ['retail', '🛍️', 'Shop'], ['delivery', '🚚', 'Delivery company'], ['custom', '✨', 'Other']];
var S = { mode: 'live', type: 'restaurant', dr: null };

function css() {
  if (document.getElementById('ak-reg-css')) return;
  var s = document.createElement('style'); s.id = 'ak-reg-css';
  s.textContent = [
    '.ak-dr.ak-reg{width:min(720px,100vw)}',
    '.ak-reg .rg-top{position:sticky;top:-16px;z-index:3;margin:-16px -18px 14px;padding:12px 18px;background:var(--card);border-bottom:1px solid var(--line)}',
    '.ak-reg .rg-mode{display:grid;grid-template-columns:1fr 1fr;gap:8px}',
    '.ak-reg .rg-mode button{display:flex;flex-direction:column;gap:2px;text-align:left;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;cursor:pointer}',
    '.ak-reg .rg-mode button b{font-size:13.5px}.ak-reg .rg-mode button span{font-size:11.5px;color:var(--mute);font-weight:400}',
    '.ak-reg .rg-mode button.on{border-color:var(--gold);box-shadow:0 0 0 3px rgba(212,175,55,.22);background:rgba(212,175,55,.09)}',
    '.ak-reg .rg-prog{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:var(--mute)}',
    '.ak-reg .rg-bar{flex:1;height:7px;border-radius:99px;background:var(--line);overflow:hidden}',
    '.ak-reg .rg-bar i{display:block;height:100%;width:0;border-radius:99px;background:linear-gradient(90deg,#E8C14A,#3ad08a);transition:width .25s}',
    '.ak-reg .rg-sec{border:1px solid var(--line);border-radius:16px;padding:14px 14px 6px;margin-bottom:14px;background:var(--card)}',
    '.ak-reg .rg-sec>h4{display:flex;align-items:center;gap:9px;margin:0 0 12px;font-size:14px}',
    '.ak-reg .rg-n{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#1a1405;background:linear-gradient(135deg,#E8C14A,#b8902a)}',
    '.ak-reg .rg-sec.done .rg-n{background:#3ad08a}',
    '.ak-reg .rg-f{display:block;margin-bottom:12px}',
    '.ak-reg .rg-f>span{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);margin-bottom:5px}',
    '.ak-reg .rg-f>span em{color:var(--red,#d6364a);font-style:normal}',
    '.ak-reg .rg-f .ak-in{font-size:15px;padding:11px 12px}',
    '.ak-reg .rg-f.bad .ak-in{border-color:var(--red,#d6364a)}',
    '.ak-reg .rg-f small{display:block;margin-top:4px;font-size:11.5px;color:var(--mute);min-height:14px;text-transform:none;letter-spacing:0;font-family:inherit;font-weight:400}',
    '.ak-reg .rg-f.bad small{color:var(--red,#d6364a)}',
    '.ak-reg .rg-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.ak-reg .rg-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px;margin-bottom:12px}',
    '.ak-reg .rg-types button{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12px;font-weight:600;cursor:pointer}',
    '.ak-reg .rg-types button u{text-decoration:none;font-size:22px;line-height:1}',
    '.ak-reg .rg-types button.on{border-color:var(--gold);background:rgba(212,175,55,.12);box-shadow:0 0 0 3px rgba(212,175,55,.2)}',
    '.ak-reg .rg-pw{display:flex;gap:6px}.ak-reg .rg-pw .ak-in{flex:1;min-width:0}',
    '.ak-reg .rg-str{display:flex;gap:4px;margin-top:6px}.ak-reg .rg-str i{flex:1;height:5px;border-radius:99px;background:var(--line)}',
    '.ak-reg .rg-str[data-s="1"] i:nth-child(-n+1){background:#d6364a}.ak-reg .rg-str[data-s="2"] i:nth-child(-n+2){background:#f0b429}',
    '.ak-reg .rg-str[data-s="3"] i:nth-child(-n+3){background:#9bd34a}.ak-reg .rg-str[data-s="4"] i{background:#3ad08a}',
    '.ak-reg .rg-note{display:flex;gap:10px;padding:11px 12px;border-radius:12px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.35);font-size:12.5px;margin-bottom:12px}',
    '.ak-reg .rg-mapw{position:relative;border-radius:14px;overflow:hidden;border:1px solid var(--line);height:250px;margin-bottom:10px;background:#e8e4d8}',
    '.ak-reg .rg-mapw #sg-map{width:100%;height:100%}',
    '.ak-reg .rg-loc{position:absolute;top:10px;left:50%;transform:translateX(-50%);z-index:5;padding:8px 16px;border:0;border-radius:99px;font:inherit;font-size:12.5px;font-weight:800;color:#1a1405;background:linear-gradient(135deg,#E8C14A,#b8902a);box-shadow:0 4px 14px rgba(0,0,0,.3);cursor:pointer;white-space:nowrap}',
    '.ak-reg .rg-zone{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);z-index:5;display:none;padding:7px 16px;border-radius:99px;background:rgba(26,26,46,.92);color:#E8C14A;font-size:12.5px;font-weight:700;white-space:nowrap}',
    '.pac-container{z-index:10050!important;border-radius:12px;margin-top:4px;font-family:inherit}',
    '.ak-reg .rg-find{position:relative;margin-bottom:10px}',
    '.ak-reg .rg-find input{padding-left:38px}',
    '.ak-reg .rg-find u{position:absolute;left:12px;top:11px;text-decoration:none;font-size:16px;pointer-events:none}',
    '.ak-reg .rg-find small{display:block;margin-top:4px;font-size:11.5px;color:var(--mute);text-transform:none;letter-spacing:0;font-family:inherit;font-weight:400;min-height:14px}',
    '.ak-reg .rg-done{text-align:center;padding:10px 0 4px}.ak-reg .rg-ok{width:64px;height:64px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;background:linear-gradient(135deg,#3ad08a,#1fa463);color:#fff}',
    '.ak-reg .rg-box{border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:12px 0;text-align:left;background:var(--card)}',
    '.ak-reg .rg-box pre{white-space:pre-wrap;word-break:break-word;font:inherit;font-size:13px;margin:6px 0 10px}',
    '@media(max-width:560px){.ak-reg .rg-2{grid-template-columns:1fr}.ak-reg .rg-mapw{height:220px}}'
  ].join('\n');
  document.head.appendChild(s);
}

function $(id) { return document.getElementById(id); }
function val(id) { var e = $(id); return e ? e.value.trim() : ''; }
function field(id, label, input, hint, req) { return '<label class="rg-f" data-f="' + id + '"><span>' + esc(label) + (req ? ' <em>*</em>' : '') + '</span>' + input + '<small data-h="' + esc(hint || '') + '">' + esc(hint || '') + '</small></label>'; }
function inp(id, attrs) { return '<input id="' + id + '" class="ak-in" ' + (attrs || '') + '>'; }

function form() {
  var live = S.mode === 'live';
  var tiles = TYPES.map(function (t) { return '<button type="button" data-type="' + t[0] + '" class="' + (S.type === t[0] ? 'on' : '') + '" aria-pressed="' + (S.type === t[0]) + '"><u>' + t[1] + '</u>' + esc(t[2]) + '</button>'; }).join('');
  return '<div class="rg-top"><div class="rg-mode" role="group" aria-label="How to register">' +
      '<button type="button" data-mode="live" class="' + (live ? 'on' : '') + '"><b>⚡ Go live now</b><span>You type the owner\'s phone and password</span></button>' +
      '<button type="button" data-mode="draft" class="' + (!live ? 'on' : '') + '"><b>🕒 Pre-register</b><span>Waiting, not live. The owner claims it with a link</span></button></div>' +
    '<div class="rg-prog"><div class="rg-bar"><i id="rg-bar"></i></div><b id="rg-count">0 of 3 ready</b></div></div>' +

    '<section class="rg-sec" data-sec="biz"><h4><span class="rg-n">1</span>The business</h4>' +
      field('rg-biz', 'Business name', inp('rg-biz', 'type="text" autocomplete="off" placeholder="e.g. Hawassa Express Delivery"'), '', true) +
      '<div class="rg-f"><span>Type</span></div><div class="rg-types" role="group" aria-label="Business type">' + tiles + '</div>' +
      field('rg-addr', 'Description or address', inp('rg-addr', 'type="text" autocomplete="off" placeholder="e.g. Near the stadium, next to the bank"'), 'Optional. Helps customers and couriers find it.') + '</section>' +

    '<section class="rg-sec" data-sec="owner"><h4><span class="rg-n">2</span>Owner and sign-in</h4>' +
      '<div class="rg-note" data-only="draft"><span>🕒</span><div>No phone or password needed now. The account gets a spare number and cannot be signed in to. After you register it you get a <b>claim link</b>: the owner opens it and types their own phone and password.</div></div>' +
      '<div class="rg-2">' + field('rg-owner', 'Owner name', inp('rg-owner', 'type="text" autocomplete="off" placeholder="Full name"'), live ? '' : 'Optional. The business name is used until the owner sets theirs.', live) +
      field('rg-phone', 'Owner phone', inp('rg-phone', 'type="tel" inputmode="tel" autocomplete="off" placeholder="09XXXXXXXX"'), live ? 'The number they sign in with.' : 'Optional. Leave empty to use a spare number.', live) + '</div>' +
      '<div data-only="live">' + field('rg-pw', 'Password', '<div class="rg-pw">' + inp('rg-pw', 'type="password" autocomplete="new-password" placeholder="At least 8 characters"') +
        '<button type="button" class="ak-btn" data-pw-show aria-label="Show password">👁</button><button type="button" class="ak-btn" data-pw-gen>🎲 Generate</button></div><div class="rg-str" id="rg-str" data-s="0"><i></i><i></i><i></i><i></i></div>', '', true) + '</div></section>' +

    '<section class="rg-sec" data-sec="loc"><h4><span class="rg-n">3</span>Location</h4>' +
      '<div class="rg-find"><u>🔎</u><input id="rg-find" class="ak-in" type="search" autocomplete="off" placeholder="Search a place, shop or area in Hawassa" aria-label="Search the map"><small id="rg-find-hint">Type a name like Google Maps, pick a result and the pin moves there. You can still tap the map to fine-tune.</small></div>' +
      '<div class="rg-mapw"><button type="button" class="rg-loc" data-locate>📡 Use my location</button><div id="sg-map"></div><div class="rg-zone" id="sg-zone-detected">📍 Zone: <span id="sg-zone-label">—</span></div></div>' +
      '<div class="rg-2">' + field('sg-zone-sel', 'Zone', inp('sg-zone-sel', 'type="text" autocomplete="off" placeholder="Tap the map to pick it"'), 'Filled from the pin. You can type over it.', live) +
      '<div class="rg-2" style="gap:8px">' + field('sg-lat', 'Latitude', inp('sg-lat', 'type="text" inputmode="decimal"'), '') + field('sg-lng', 'Longitude', inp('sg-lng', 'type="text" inputmode="decimal"'), '') + '</div></div></section>' +

    '<section class="rg-sec" data-sec="more"><h4><span class="rg-n">4</span>Extras <small style="font-weight:400;color:var(--mute)">(optional)</small></h4>' +
      field('rg-idn', 'ID or licence number', inp('rg-idn', 'type="text" autocomplete="off" placeholder="National ID or trade licence"'), '') + '</section>';
}

function head() {
  return '<div class="ak-av lg" style="--a:#E8C14A;--b:#9a7a1c">🏪</div><div class="ak-nm"><b>Register a business</b><small>Shops, restaurants and delivery companies</small></div>';
}
function foot() {
  return '<span class="ak-err" id="rg-msg" role="status" aria-live="polite"></span><button type="button" class="ak-btn" data-close>Cancel</button><button type="button" class="ak-btn gold" data-submit></button>';
}

/* ---------- checks ---------- */
function phoneOk(p) { return p.replace(/[^0-9]/g, '').length >= 9; }
function strength(p) {
  var s = 0; if (p.length >= 8) s++; if (p.length >= 11) s++; if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++; if (/\d/.test(p) && /[^A-Za-z0-9]|[A-Za-z]/.test(p) && /\d/.test(p)) s++;
  return p.length < 8 ? Math.min(s, 1) : Math.max(s, 2);
}
function mark(id, msg) {
  var f = S.dr && S.dr.querySelector('[data-f="' + id + '"]'); if (!f) return;
  f.classList.toggle('bad', !!msg);
  var sm = f.querySelector('small'); if (sm) sm.textContent = msg || sm.getAttribute('data-h') || '';
}
function status() {
  var live = S.mode === 'live';
  var biz = val('rg-biz').length > 0;
  var owner = live ? (val('rg-owner').length > 0 && phoneOk(val('rg-phone')) && val('rg-pw').length >= 8) : true;
  var loc = val('sg-zone-sel').length > 0;
  return { biz: biz, owner: owner, loc: loc || !live, n: [biz, owner, loc || !live].filter(Boolean).length, locDone: loc };
}
function refresh() {
  if (!S.dr) return;
  var st = status();
  $('rg-bar').style.width = Math.round(st.n / 3 * 100) + '%';
  $('rg-count').textContent = st.n + ' of 3 ready';
  [['biz', st.biz], ['owner', st.owner], ['loc', st.loc && (S.mode === 'live' ? true : st.locDone)]].forEach(function (a) {
    var sec = S.dr.querySelector('[data-sec="' + a[0] + '"]'); if (sec) sec.classList.toggle('done', !!a[1]);
  });
  var pw = val('rg-pw'), str = $('rg-str'); if (str) str.setAttribute('data-s', String(pw ? strength(pw) : 0));
}
function applyMode() {
  var live = S.mode === 'live';
  S.dr.querySelectorAll('[data-mode]').forEach(function (b) { var on = b.getAttribute('data-mode') === S.mode; b.classList.toggle('on', on); });
  S.dr.querySelectorAll('[data-only]').forEach(function (e) { e.hidden = e.getAttribute('data-only') !== S.mode; });
  var sub = S.dr.querySelector('[data-submit]'); sub.textContent = live ? '✓ Create and go live' : '🕒 Pre-register';
  var hints = { 'rg-owner': live ? '' : 'Optional. The business name is used until the owner sets theirs.', 'rg-phone': live ? 'The number they sign in with.' : 'Optional. Leave empty to use a spare number.' };
  Object.keys(hints).forEach(function (id) { var f = S.dr.querySelector('[data-f="' + id + '"]'); if (f) { f.classList.remove('bad'); f.querySelector('small').setAttribute('data-h', hints[id]); f.querySelector('small').textContent = hints[id]; var em = f.querySelector('span em'); if (em) em.hidden = !live; else if (live) { f.querySelector('span').insertAdjacentHTML('beforeend', ' <em>*</em>'); } } });
  var zf = S.dr.querySelector('[data-f="sg-zone-sel"] span em'); if (zf) zf.hidden = !live;
  refresh();
}
function validate() {
  var live = S.mode === 'live', ok = true, first = null;
  function need(id, bad, msg) { mark(id, bad ? msg : ''); if (bad && ok) { ok = false; first = id; } else if (bad) ok = false; }
  need('rg-biz', !val('rg-biz'), 'Enter the business name.');
  if (live) {
    need('rg-owner', !val('rg-owner'), 'Enter the owner\'s name.');
    need('rg-phone', !phoneOk(val('rg-phone')), 'Enter the full phone number.');
    need('rg-pw', val('rg-pw').length < 8, 'The password needs at least 8 characters.');
    need('sg-zone-sel', !val('sg-zone-sel'), 'Tap the map to pin the location, or type the zone.');
  } else if (val('rg-phone')) need('rg-phone', !phoneOk(val('rg-phone')), 'Enter the full phone number, or leave it empty.');
  if (!ok && first) { var e = $(first); if (e) { e.scrollIntoView({ block: 'center', behavior: 'smooth' }); e.focus(); } }
  return ok;
}

/* ---------- sharing ---------- */
function baseUrl() { return location.origin + location.pathname.replace(/[^\/]*$/, ''); }
function copy(text) {
  var done = function () { K.toast('Copied'); };
  if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { K.toast('Select the text and copy it by hand', true); }); return; }
  var t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
  try { document.execCommand('copy'); done(); } catch (e) { K.toast('Select the text and copy it by hand', true); } t.remove();
}
function success(d, info) {
  var live = !d.draft, msg, extra = '';
  if (live) {
    msg = 'ETEL - your business account is ready.\nBusiness: ' + info.biz + '\nSign in: ' + baseUrl() + 'servicegiver.html\nPhone: ' + info.phone + '\nPassword: ' + info.pw + '\nKeep this password private.';
    extra = '<div class="rg-box"><b>Sign-in message for the owner</b><pre>' + esc(msg) + '</pre><button type="button" class="ak-btn gold" data-copy-msg>Copy message</button></div>';
  } else {
    var link = baseUrl() + 'claim.html?code=' + encodeURIComponent(d.claim_code || '');
    msg = 'ETEL - set up your business "' + info.biz + '": ' + link + '\nClaim code: ' + d.claim_code + '\nOpen it and add your own phone number and password.';
    extra = '<div class="rg-box"><b>Claim link for the owner</b> <small style="color:var(--mute)">(shown only now' + (d.claim_expires_at ? ', valid until ' + esc(K.fullDate(d.claim_expires_at)) : '') + ')</small><pre>' + esc(msg) + '</pre>' +
      '<div class="ak-acts"><button type="button" class="ak-btn gold" data-copy-msg>Copy message</button><button type="button" class="ak-btn" data-copy-only="' + esc(link) + '">Copy link only</button></div></div>' +
      '<p class="ak-fh">The business waits in the list as "Waiting verification". Open it later to fix any details, then press Verify to make it live.</p>';
  }
  S.msg = msg;
  S.dr.querySelector('.ak-dr-body').innerHTML = '<div class="rg-done"><div class="rg-ok">✓</div><h3 style="margin:0 0 4px">' + (live ? 'Business is live' : 'Business pre-registered') + '</h3>' +
    '<p class="ak-fh" style="margin:0">' + esc(info.biz) + ' · <b>' + esc(d.code || '') + '</b></p></div>' + extra;
  S.dr.querySelector('.ak-dr-foot').innerHTML = '<button type="button" class="ak-btn" data-again>Register another</button><button type="button" class="ak-btn gold" data-view="' + esc(d.service_giver_id) + '">Open in the list</button>';
}

/* ---------- submit ---------- */
function submit(btn) {
  if (!validate()) { $('rg-msg').textContent = 'Check the highlighted fields.'; return; }
  var live = S.mode === 'live', msg = $('rg-msg');
  var info = { biz: val('rg-biz'), phone: val('rg-phone'), pw: val('rg-pw') };
  var payload = { business_name: info.biz, name: val('rg-owner'), phone: info.phone, service_type: S.type, address: val('rg-addr'), identification_number: val('rg-idn') };
  if (live) payload.password = info.pw; else payload.draft = 1;
  if (val('sg-zone-sel')) payload.zone = val('sg-zone-sel');
  if (val('sg-lat') && val('sg-lng')) { payload.lat = val('sg-lat'); payload.lng = val('sg-lng'); }
  btn.disabled = true; msg.textContent = 'Saving…';
  K.api('admin_create_service_giver', payload).then(function (res) {
    btn.disabled = false;
    if (!K.okRes(res)) { msg.textContent = K.failText(res); return; }
    success(K.data(res), info);
    try { if (typeof window.loadServiceGivers === 'function') window.loadServiceGivers(); } catch (e) { /* the list refreshes on its next visit */ }
  });
}

/* ---------- place search (Google Places, with the geocoder as a fallback for Enter) ---------- */
function hint(t, bad) { var h = $('rg-find-hint'); if (h) { h.textContent = t; h.style.color = bad ? 'var(--red,#d6364a)' : ''; } }
function goTo(lat, lng, label, addr) {
  try { if (typeof setSGCoords === 'function') setSGCoords(lat, lng); } catch (e) { /* the pin fields stay as they were */ }
  try {
    if (typeof sgMap !== 'undefined' && sgMap) { sgMap.setCenter({ lat: lat, lng: lng }); sgMap.setZoom(17); }
    if (typeof sgMarker !== 'undefined' && sgMarker) { if (sgMarker.setPosition) sgMarker.setPosition({ lat: lat, lng: lng }); else sgMarker.position = { lat: lat, lng: lng }; }
  } catch (e) { /* the map is still loading: the coordinates are set anyway */ }
  var a = $('rg-addr'); if (a && !a.value.trim() && addr) a.value = addr;
  hint('📍 ' + (label || 'Pinned') + (addr && label && addr.indexOf(label) !== 0 ? ' · ' + addr : ''), false);
  refresh();
}
function geocodeSearch(q) {
  if (!q || !window.google || !google.maps || !google.maps.Geocoder) { hint('The map is still loading. Try again in a moment.', true); return; }
  new google.maps.Geocoder().geocode({ address: q + ', Hawassa, Ethiopia', region: 'ET' }, function (res, st) {
    if (st === 'OK' && res && res[0]) { var l = res[0].geometry.location; goTo(l.lat(), l.lng(), q, res[0].formatted_address); }
    else hint('No place found for "' + q + '". Try another spelling, or tap the map.', true);
  });
}
function initSearch(dr, tries) {
  var input = $('rg-find'); if (!input || S.dr !== dr) return;
  if (!input._rgKeys) {
    input._rgKeys = true;
    input.addEventListener('input', function () { input._rgPicked = false; });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); setTimeout(function () { if (!input._rgPicked) geocodeSearch(input.value.trim()); }, 300); } else input._rgPicked = false; });
  }
  if (window.google && google.maps && google.maps.places && google.maps.places.Autocomplete) {
    try {
      var ac = new google.maps.places.Autocomplete(input, {
        componentRestrictions: { country: 'et' },
        bounds: { north: 7.15, south: 6.95, east: 38.58, west: 38.40 },
        fields: ['geometry', 'name', 'formatted_address']
      });
      ac.addListener('place_changed', function () {
        var p = ac.getPlace();
        if (!p || !p.geometry || !p.geometry.location) { geocodeSearch(input.value.trim()); return; }
        input._rgPicked = true;
        goTo(p.geometry.location.lat(), p.geometry.location.lng(), p.name || '', p.formatted_address || '');
      });
    } catch (e) { hint('Search suggestions are not available. Type a place and press Enter.', false); }
    return;
  }
  if ((tries || 0) < 40 && !window.ETEL_MAPS_FAILED) setTimeout(function () { initSearch(dr, (tries || 0) + 1); }, 400);
  else hint('Search needs the map, which did not load. Type the zone below instead.', true);
}

/* ---------- open ---------- */
function open(opts) {
  css();
  S.mode = opts && opts.mode === 'draft' ? 'draft' : 'live'; S.type = 'restaurant';
  var dr = K.openDrawer(head(), form(), foot()); S.dr = dr; dr.classList.add('ak-reg');
  applyMode();
  dr.addEventListener('click', function (e) {
    var t = e.target, b;
    if ((b = t.closest('[data-mode]'))) { S.mode = b.getAttribute('data-mode'); applyMode(); }
    else if ((b = t.closest('[data-type]'))) { S.type = b.getAttribute('data-type'); dr.querySelectorAll('[data-type]').forEach(function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', String(on)); }); }
    else if (t.closest('[data-pw-show]')) { var p = $('rg-pw'); p.type = p.type === 'password' ? 'text' : 'password'; }
    else if (t.closest('[data-pw-gen]')) {
      var a = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', n = new Uint32Array(10), out = ''; (window.crypto || window.msCrypto).getRandomValues(n);
      for (var i = 0; i < n.length; i++) out += a.charAt(n[i] % a.length);
      $('rg-pw').value = out + '7'; $('rg-pw').type = 'text'; refresh();
    }
    else if (t.closest('[data-locate]')) { if (typeof sgUseCurrentLocation === 'function') sgUseCurrentLocation(); }
    else if ((b = t.closest('[data-submit]'))) submit(b);
    else if (t.closest('[data-copy-msg]')) copy(S.msg || '');
    else if ((b = t.closest('[data-copy-only]'))) copy(b.getAttribute('data-copy-only'));
    else if (t.closest('[data-again]')) open({ mode: S.mode });
    else if ((b = t.closest('[data-view]'))) { var id = parseInt(b.getAttribute('data-view'), 10); K.closeDrawer(); if (typeof window.etelOpenServiceGiver === 'function') window.etelOpenServiceGiver(id); }
  });
  dr.addEventListener('input', function (e) {
    var f = e.target.closest && e.target.closest('[data-f]'); if (f && f.classList.contains('bad')) mark(f.getAttribute('data-f'), '');
    refresh();
  });
  // The map pin fills the zone and coordinates from admin.js by setting their values, which fires no event, so look again now and then.
  var zoneEl = $('sg-zone-sel'); if (zoneEl) { var tick = setInterval(function () { if (!S.dr || !document.body.contains(zoneEl)) { clearInterval(tick); return; } refresh(); }, 600); }
  setTimeout(function () { if (typeof initSGMap === 'function' && $('sg-map')) initSGMap(); }, 300);
  initSearch(dr, 0);
  setTimeout(function () { var b = $('rg-biz'); if (b) b.focus(); }, 320);
}

window.EtelSgRegister = { open: open };

/* The old "New Service Giver" entry points (openModal('new-sg') in admin.js / admin-ui.js) open this panel instead. */
(function hook() {
  var orig = window.openModal;
  if (typeof orig !== 'function') return;
  window.openModal = function (type) { if (type === 'new-sg') return open({ mode: 'live' }); return orig.apply(this, arguments); };
})();
})();
