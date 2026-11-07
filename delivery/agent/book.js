/* ETEL Agent portal - "Book a ride for a customer". Loaded on demand by app.js (only when the agent opens #/book).
   The agent types the customer's phone, picks a vehicle (real pictures, same artwork as the Ride app), searches the pickup and drop-off places
   (Google Places, same as Ride) or taps the map, sees the route and the live drivers on the map, and confirms. The server (fa_agent_* in
   AgentBookingController.php) creates the ride exactly like Admin's phone booking and links the customer to the agent.
   If Google Maps cannot load (no key, blocked, offline) the screen still works: type the place and choose the distance. */
(function () {
'use strict';
var P = window.ETELAP; if (!P || window.ETELBook) return;
var t = P.t, esc = P.esc, ic = P.ic, api = P.api, fmt = P.fmt;
var VICON = { bajaj: 'bajaj', moto: 'moto', car: 'car', minibus: 'bus', qute: 'bajaj', ev_car: 'car' };
var ART = { bajaj: 1, moto: 1, car: 1, minibus: 1, qute: 1 };           // small pictures in agent/veh/ (the Ride artwork, scaled down)
var KMS = [2, 4, 6, 8, 12];
var HAWASSA = { lat: 7.0621, lng: 38.4763 };
var B = { veh: null, vcap: 10, fares: {}, phone: '', name: '', vtype: '', km: '', open: false, pickup: '', dest: '', pLat: null, pLng: null, dLat: null, dLng: null, mode: '',
          geo: '', routeKm: 0, list: null, result: null, busy: false, err: '', qt: null, maps: null, mapOn: false, mapFail: false, drivers: 0, dpoll: null };
var M = { map: null, el: null, pin: null, drop: null, line: null, drv: [], icons: {}, thumbs: {}, ac: [], dirs: null, geocoder: null };

function vname(v) { var k = 'veh.' + v, s = t(k); v = String(v).replace(/_/g, ' '); return s === k ? (v.charAt(0).toUpperCase() + v.slice(1)) : s; }
function vnorm(v) { v = String(v || '').toLowerCase(); if (ART[v]) return v; if (v.indexOf('moto') !== -1) return 'moto'; if (v.indexOf('mini') !== -1 || v.indexOf('bus') !== -1) return 'minibus'; if (v.indexOf('qute') !== -1) return 'qute'; if (v.indexOf('bajaj') !== -1 || v.indexOf('tuk') !== -1) return 'bajaj'; if (v.indexOf('car') !== -1 || v.indexOf('ev') === 0) return 'car'; return ''; }
function vimg(v, w) { var n = vnorm(v); return n ? '<img class="bv-img" src="veh/' + n + '.webp" alt="" width="' + w + '" height="' + Math.round(w * 0.7) + '" decoding="async" draggable="false">' : ic(VICON[v] || 'car'); }
function phoneOk() { var d = B.phone.replace(/\D/g, ''); return /^(251|0)?[79]\d{8}$/.test(d); }
function tripOk() { return B.open || parseFloat(B.km) > 0; }
function ready() { return phoneOk() && !!B.vtype && tripOk(); }
function fareOf(v) { var f = B.fares[v]; return f ? f.fare : null; }
function money(n) { return n == null ? '-' : fmt(n); }
function hav(a, b, c, d) { var R = 6371, r = Math.PI / 180, x = (c - a) * r, y = (d - b) * r, q = Math.sin(x / 2) * Math.sin(x / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(y / 2) * Math.sin(y / 2); return 2 * R * Math.atan2(Math.sqrt(q), Math.sqrt(1 - q)); }

/* ---------------- data ---------------- */
function load() {
  B.err = '';
  var a = api('fa_agent_vehicles').then(function (r) {
    if (r.ok) { B.veh = r.data.vehicles; B.vcap = r.data.daily_cap; quote(); } else B.err = reasonText(r);
    if (P.S.view === 'book') P.render();
  });
  return Promise.all([a, loadList()]);
}
function loadList() { return api('fa_agent_bookings').then(function (r) { B.list = r.ok ? r.data.bookings : (r.net ? [] : null); if (P.S.view === 'book') P.render(); }); }
function reasonText(r) {
  var c = r && r.j && r.j.reason ? r.j.reason : '';
  if (c) { var k = 'bk.err.' + c, s = t(k); if (s !== k) return s; }
  if (r && r.net) return t('c.offline');
  return (r && r.j && r.j.message) ? r.j.message : t('c.error');
}
function quote() {
  clearTimeout(B.qt);
  B.qt = setTimeout(function () {
    api('fa_agent_quote', { km: parseFloat(B.km) || 0, open: B.open ? 1 : 0 }).then(function (r) {
      if (!r.ok) return; B.fares = r.data.fares;
      document.querySelectorAll('[data-bkfare]').forEach(function (el) { var f = fareOf(el.getAttribute('data-bkfare')); el.textContent = f == null ? '' : (B.open ? t('bk.from', { f: money(f) }) : (money(f) + ' ' + t('c.etb'))); });
      summary();
    });
  }, 250);
}
function summary() {
  var el = document.getElementById('bk-sum'), btn = document.getElementById('bk-go'); if (!el) return;
  var f = fareOf(B.vtype);
  el.textContent = B.vtype && f != null && tripOk() ? (B.open ? t('bk.from', { f: money(f) }) : t('bk.fare', { f: money(f) })) : '';
  if (btn) btn.disabled = !ready();
}

/* ---------------- Google Maps (same key and libraries as the Ride app) ---------------- */
function loadMaps() {
  if (window.google && google.maps && google.maps.places) return Promise.resolve(true);
  if (B.maps) return B.maps;
  B.maps = new Promise(function (res) {
    var done = function (v) { if (!v) { B.mapOn = false; B.mapFail = true; } res(v); };
    var prev = window.gm_authFailure; window.gm_authFailure = function () { try { if (prev) prev(); } catch (e) { /* ignore */ } B.mapOn = false; B.mapFail = true; mapNote(t('bk.map_off')); };
    fetch(P.API + '?action=get_config').then(function (r) { return r.json(); }).then(function (d) {
      var key = d && d.success && d.data && d.data.google_maps_api_key; if (!key) return done(false);
      window.__bkMapsReady = function () { done(true); };
      var s = document.createElement('script'); s.async = true; s.defer = true; s.onerror = function () { done(false); };
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=places,geometry&loading=async&callback=__bkMapsReady';
      document.head.appendChild(s);
    }).catch(function () { done(false); });
    setTimeout(function () { if (!(window.google && google.maps)) done(false); }, 15000);
  });
  return B.maps;
}
function mapNote(msg) { var n = document.getElementById('bk-mapnote'); if (n) n.textContent = msg || ''; }
var DARK = [{ elementType: 'geometry', stylers: [{ color: '#1d1b16' }] }, { elementType: 'labels.text.fill', stylers: [{ color: '#c9bf9c' }] }, { elementType: 'labels.text.stroke', stylers: [{ color: '#1d1b16' }] },
  { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#3a3526' }] }, { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#0f1a24' }] }, { featureType: 'poi', stylers: [{ visibility: 'off' }] }];
function ensureMap() {
  return loadMaps().then(function (ok) {
    if (!ok) { B.mapOn = false; mapNote(t('bk.map_off')); var sl = document.getElementById('bk-map'); if (sl) sl.classList.add('off'); return false; }
    if (!M.map) {
      M.el = document.createElement('div'); M.el.className = 'bv-mapbox'; M.el.setAttribute('aria-label', t('bk.map'));
      var c = B.pLat ? { lat: B.pLat, lng: B.pLng } : HAWASSA;
      M.map = new google.maps.Map(M.el, { center: c, zoom: 14, disableDefaultUI: true, zoomControl: true, gestureHandling: 'greedy', clickableIcons: false, styles: document.documentElement.getAttribute('data-theme') === 'light' ? [] : DARK });
      M.dirs = new google.maps.DirectionsService(); M.geocoder = new google.maps.Geocoder();
      M.map.addListener('click', function (e) { if (B.mode === 'drop') setDrop(e.latLng.lat(), e.latLng.lng(), true); else setPickup(e.latLng.lat(), e.latLng.lng(), true); B.mode = ''; });
    }
    B.mapOn = true; mountMap(); return true;
  });
}
function mountMap() {
  var slot = document.getElementById('bk-map'); if (!slot || !M.el) return;
  if (M.el.parentNode !== slot) { slot.innerHTML = ''; slot.appendChild(M.el); }
  slot.classList.add('on'); try { google.maps.event.trigger(M.map, 'resize'); } catch (e) { /* ignore */ }
}
function pinIcon(color) { return { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: color, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 3 }; }
function placePins() {
  if (!M.map) return;
  var sync = function (key, lat, lng, color, drag, cb) {
    if (!lat) { if (M[key]) { M[key].setMap(null); M[key] = null; } return; }
    var pos = { lat: lat, lng: lng };
    if (!M[key]) { M[key] = new google.maps.Marker({ map: M.map, position: pos, icon: pinIcon(color), draggable: drag, zIndex: 900 }); M[key].addListener('dragend', function (e) { cb(e.latLng.lat(), e.latLng.lng(), true); }); }
    else M[key].setPosition(pos);
  };
  sync('pin', B.pLat, B.pLng, '#1d9e5f', true, setPickup); sync('drop', B.dLat, B.dLng, '#d4af37', true, setDrop);
  fitMap();
}
function fitMap() {
  if (!M.map) return;
  try {
    if (B.pLat && B.dLat) { var b = new google.maps.LatLngBounds(); b.extend({ lat: B.pLat, lng: B.pLng }); b.extend({ lat: B.dLat, lng: B.dLng }); M.map.fitBounds(b, 40); }
    else if (B.pLat) { M.map.setCenter({ lat: B.pLat, lng: B.pLng }); M.map.setZoom(15); }
  } catch (e) { /* ignore */ }
}
function reverse(lat, lng, field) {
  if (!M.geocoder) return;
  M.geocoder.geocode({ location: { lat: lat, lng: lng } }, function (res, st) {
    if (st !== 'OK' || !res || !res[0]) return;
    var txt = res[0].formatted_address || '', el = document.getElementById(field === 'pickup' ? 'bk-pickup' : 'bk-dest');
    if (field === 'pickup') B.pickup = txt; else B.dest = txt; if (el) el.value = txt;
  });
}
function setPickup(lat, lng, fromMap, label) {
  B.pLat = lat; B.pLng = lng; B.geo = ''; if (label) { B.pickup = label; var e = document.getElementById('bk-pickup'); if (e) e.value = label; } else if (fromMap) reverse(lat, lng, 'pickup');
  placePins(); route(); drivers(); summary();
}
function setDrop(lat, lng, fromMap, label) {
  B.dLat = lat; B.dLng = lng; if (label) { B.dest = label; var e = document.getElementById('bk-dest'); if (e) e.value = label; } else if (fromMap) reverse(lat, lng, 'drop');
  placePins(); route(); summary();
}
/* Route between the two pins: the real driving distance (Google), else 1.3 x the straight line. Sets the distance and refreshes the fares. */
function route() {
  var note = document.getElementById('bk-route');
  if (!(B.pLat && B.dLat)) { B.routeKm = 0; if (M.line) { M.line.setMap(null); M.line = null; } if (note) note.textContent = ''; return; }
  var use = function (km, path) {
    B.routeKm = Math.round(km * 10) / 10; B.km = String(B.routeKm); B.open = false;
    var kmEl = document.getElementById('bk-km'); if (kmEl) { kmEl.value = B.km; kmEl.disabled = false; }
    document.querySelectorAll('.bv-chip').forEach(function (c) { c.classList.remove('on'); c.setAttribute('aria-pressed', 'false'); });
    var n = document.getElementById('bk-route'); if (n) n.textContent = t('bk.route', { km: B.routeKm });
    if (M.line) { M.line.setMap(null); M.line = null; }
    if (path && path.length && M.map) M.line = new google.maps.Polyline({ map: M.map, path: path, strokeColor: '#d4af37', strokeOpacity: .95, strokeWeight: 5 });
    quote(); summary();
  };
  var straight = hav(B.pLat, B.pLng, B.dLat, B.dLng) * 1.3;
  if (!M.dirs) return use(straight, null);
  M.dirs.route({ origin: { lat: B.pLat, lng: B.pLng }, destination: { lat: B.dLat, lng: B.dLng }, travelMode: 'DRIVING' }, function (res, st) {
    if (st === 'OK' && res && res.routes && res.routes[0] && res.routes[0].legs[0]) use(res.routes[0].legs[0].distance.value / 1000, res.routes[0].overview_path);
    else use(straight, null);
  });
}
function attachSearch() {
  if (!(window.google && google.maps && google.maps.places)) return;
  [['bk-pickup', setPickup], ['bk-dest', setDrop]].forEach(function (x) {
    var el = document.getElementById(x[0]); if (!el || el._ac) return; el._ac = true;
    try {
      var ac = new google.maps.places.Autocomplete(el, { componentRestrictions: { country: 'ET' }, fields: ['geometry', 'formatted_address', 'name'] });
      ac.addListener('place_changed', function () { var p = ac.getPlace(); if (p && p.geometry) x[1](p.geometry.location.lat(), p.geometry.location.lng(), false, (p.name && p.formatted_address && p.formatted_address.indexOf(p.name) === -1) ? (p.name + ', ' + p.formatted_address) : (p.formatted_address || p.name)); });
    } catch (e) { /* the field still works as plain text */ }
  });
}

/* ---------------- live drivers on the map ---------------- */
function thumb(type, cb) {
  var n = vnorm(type); if (!n) return cb(null);
  if (M.thumbs[n]) return cb(M.thumbs[n]);
  var im = new Image(); im.onload = function () { M.thumbs[n] = im; cb(im); }; im.onerror = function () { cb(null); }; im.src = 'veh/' + n + '.webp';
}
function drvIcon(type, cb) {
  var n = vnorm(type) || 'car', key = n + '44';
  if (M.icons[key]) return cb(M.icons[key]);
  thumb(n, function (im) {
    if (!im) return cb(null);
    var S = 88, c = document.createElement('canvas'); c.width = S; c.height = S;
    var k = Math.min((S - 8) / im.naturalWidth, (S - 8) / im.naturalHeight), w = im.naturalWidth * k, h = im.naturalHeight * k, g = c.getContext('2d'); g.shadowColor = 'rgba(0,0,0,.5)'; g.shadowBlur = 5; g.shadowOffsetY = 2; g.drawImage(im, (S - w) / 2, (S - h) / 2, w, h);
    cb(M.icons[key] = { url: c.toDataURL('image/png'), scaledSize: new google.maps.Size(44, 44), anchor: new google.maps.Point(22, 22) });
  });
}
function clearDrivers() { M.drv.forEach(function (m) { try { m.setMap(null); } catch (e) { /* ignore */ } }); M.drv = []; }
function drivers() {
  if (!M.map || P.S.view !== 'book') return;
  var c = B.pLat ? { lat: B.pLat, lng: B.pLng } : (function () { var x = M.map.getCenter(); return { lat: x.lat(), lng: x.lng() }; })();
  api('passenger_get_nearby_drivers', { lat: c.lat, lng: c.lng, radius_km: 6, vehicle_type: B.vtype || 'all' }).then(function (r) {
    if (!r.ok || P.S.view !== 'book' || !M.map) return;
    var list = r.data.drivers || []; clearDrivers(); B.drivers = list.length;
    list.forEach(function (d) {
      drvIcon(d.vehicle_type, function (icon) {
        try { M.drv.push(new google.maps.Marker({ map: M.map, position: { lat: d.lat, lng: d.lng }, icon: icon || undefined, zIndex: 500, optimized: true })); } catch (e) { /* ignore */ }
      });
    });
    var n = document.getElementById('bk-drv'); if (n) n.textContent = list.length ? t('bk.drivers', { n: list.length }) : t('bk.drivers_0');
  });
}
function startPoll() { stopPoll(); B.dpoll = setInterval(function () { if (P.S.view !== 'book') return stopPoll(); if (!document.hidden) drivers(); }, 8000); }
function stopPoll() { if (B.dpoll) { clearInterval(B.dpoll); B.dpoll = null; } clearDrivers(); }

/* ---------------- screen ---------------- */
function view() {
  var h = '<h1>' + esc(t('bk.title')) + '</h1><p class="sub">' + esc(t('bk.sub')) + '</p>';
  if (B.err) h += '<div class="err" role="alert">' + esc(B.err) + '</div>';
  if (B.result) h += resultCard(B.result);
  h += '<section class="card"><label class="fld" style="margin-top:0"><span>' + esc(t('bk.phone')) + '</span><input class="in ltr" id="bk-phone" type="tel" inputmode="tel" autocomplete="off" maxlength="16" placeholder="' + esc(t('bk.phone_ph')) + '" value="' + esc(B.phone) + '"></label>' +
    '<label class="fld"><span>' + esc(t('bk.name')) + '</span><input class="in" id="bk-name" maxlength="60" autocomplete="off" value="' + esc(B.name) + '"></label></section>';
  h += '<h2>' + esc(t('bk.trip')) + '</h2><section class="card" style="margin-top:8px">' +
    '<div class="bv-map" id="bk-map" role="region" aria-label="' + esc(t('bk.map')) + '"><div class="bv-skel skel"></div></div><p class="sub bv-note" id="bk-mapnote" role="status"></p><p class="sub bv-note" id="bk-drv" role="status" aria-live="polite"></p>' +
    '<label class="fld"><span>' + esc(t('bk.pickup')) + '</span><div class="bv-srch"><input class="in" id="bk-pickup" maxlength="160" autocomplete="off" placeholder="' + esc(t('bk.search_pick')) + '" value="' + esc(B.pickup) + '"><button type="button" class="ib" data-act="bk-loc" aria-label="' + esc(t('bk.loc')) + '" title="' + esc(t('bk.loc')) + '">' + ic('pin') + '</button><button type="button" class="ib' + (B.mode === 'pickup' ? ' on' : '') + '" data-act="bk-mappick" data-m="pickup" aria-label="' + esc(t('bk.on_map')) + '" title="' + esc(t('bk.on_map')) + '">' + ic('map') + '</button></div></label>' +
    (B.geo ? '<p class="sub" style="margin-top:6px">' + esc(B.geo) + '</p>' : '') +
    '<label class="fld"><span>' + esc(t('bk.dest')) + '</span><div class="bv-srch"><input class="in" id="bk-dest" maxlength="160" autocomplete="off" placeholder="' + esc(t('bk.search_drop')) + '" value="' + esc(B.dest) + '"><button type="button" class="ib' + (B.mode === 'drop' ? ' on' : '') + '" data-act="bk-mappick" data-m="drop" aria-label="' + esc(t('bk.on_map')) + '" title="' + esc(t('bk.on_map')) + '">' + ic('map') + '</button></div></label>' +
    '<p class="sub bv-note" id="bk-route" role="status">' + (B.routeKm ? esc(t('bk.route', { km: B.routeKm })) : '') + '</p>' +
    '<div class="fld"><span id="bk-lkm">' + esc(t('bk.km')) + '</span><div class="bv-chips" role="group" aria-labelledby="bk-lkm">' + KMS.map(function (k) { return '<button type="button" class="bv-chip' + (!B.open && !B.routeKm && String(B.km) === String(k) ? ' on' : '') + '" data-act="bk-km" data-k="' + k + '" aria-pressed="' + (!B.open && !B.routeKm && String(B.km) === String(k)) + '">' + k + ' km</button>'; }).join('') +
    '<button type="button" class="bv-chip' + (B.open ? ' on' : '') + '" data-act="bk-open" aria-pressed="' + B.open + '">' + esc(t('bk.open')) + '</button></div>' +
    '<input class="in" id="bk-km" type="number" inputmode="decimal" min="0.5" max="100" step="0.5" placeholder="km" value="' + esc(B.open ? '' : B.km) + '" style="margin-top:10px"' + (B.open ? ' disabled' : '') + '></div></section>';
  h += '<h2>' + esc(t('bk.vehicle')) + '</h2>';
  if (B.veh === null) h += '<div class="stack"><div class="skel" style="height:84px"></div></div>';
  else h += '<div class="bv-list" role="radiogroup" aria-label="' + esc(t('bk.vehicle')) + '">' + B.veh.map(function (v) {
    var on = B.vtype === v.type, f = fareOf(v.type);
    return '<button type="button" class="bv-veh' + (on ? ' on' : '') + (v.online ? '' : ' off') + '" role="radio" aria-checked="' + on + '" data-act="bk-veh" data-v="' + esc(v.type) + '"><span class="bv-pic">' + vimg(v.type, 104) + '</span>' +
      '<span class="bv-txt"><b>' + esc(vname(v.type)) + '</b><small>' + esc(v.online ? t('bk.online', { n: v.online }) : t('bk.none')) + '</small></span>' +
      '<span class="bv-fare" data-bkfare="' + esc(v.type) + '">' + (f == null ? '' : esc(B.open ? t('bk.from', { f: money(f) }) : money(f) + ' ' + t('c.etb'))) + '</span></button>'; }).join('') + '</div>';
  h += '<p class="bv-sum" id="bk-sum" role="status" aria-live="polite"></p><div style="margin-top:12px"><button class="btn primary" id="bk-go" data-act="bk-go" disabled>' + ic('car') + esc(t('bk.book')) + '</button></div>';
  h += '<h2>' + esc(t('bk.list')) + ' <button type="button" class="ib" data-act="bk-refresh" style="min-height:40px;min-width:40px;vertical-align:middle" aria-label="' + esc(t('bk.refresh')) + '">' + ic('refresh') + '</button></h2>';
  if (B.list === null) h += '<div class="err" role="alert">' + esc(t('c.error')) + '</div>';
  else if (!B.list.length) h += '<div class="empty">' + ic('car') + '<p>' + esc(t('bk.list_none')) + '</p></div>';
  else h += '<div class="list">' + B.list.map(function (x) {
    var st = t('bk.s.' + x.ride_status); if (st === 'bk.s.' + x.ride_status) st = x.ride_status || '';
    return '<div class="li"><div class="m"><b>' + esc(x.customer) + '</b><small>' + esc(vname(x.vehicle)) + ' · ' + esc(fmt(x.fare)) + ' ' + esc(t('c.etb')) + ' · ' + esc(P.when(x.created_at)) + '</small><small>' + esc(t('bk.b.' + x.bonus)) + '</small></div><div class="r"><span class="chip ' + (x.ride_status === 'completed' ? 'ok' : (x.ride_status === 'cancelled' || x.ride_status === 'expired' ? 'bad' : 'gold')) + '">' + esc(st) + '</span></div></div>'; }).join('') + '</div>';
  setTimeout(after, 0);
  return P.shell(h);
}
/* runs after every render: put the (persistent) map back, re-attach the place search, refresh numbers */
function after() {
  summary(); mountMap(); attachSearch();
  var d = document.getElementById('bk-drv'); if (d && B.mapOn && M.map) d.textContent = B.drivers ? t('bk.drivers', { n: B.drivers }) : '';
  if (B.mapFail) { mapNote(t('bk.map_off')); var sl = document.getElementById('bk-map'); if (sl) sl.classList.add('off'); }
}
function resultCard(r) {
  var b = r.bonus || {}, lines = ['<p><b>' + esc(t('bk.done')) + '</b></p>'];
  lines.push('<p class="sub">' + esc(vname(r.vehicle_type)) + ' · ' + esc(fmt(r.fare)) + ' ' + esc(t('c.etb')) + ' · ' + esc(r.customer) + '</p>');
  if (r.new_account) lines.push('<p class="sub">' + esc(t('bk.done_new')) + '</p>');
  lines.push('<p class="sub">' + esc(t('bk.pay_note')) + '</p>');
  lines.push('<p class="sub">' + esc(t(r.sms === 'sent' ? 'bk.sms_sent' : 'bk.sms_none')) + '</p>');
  if (b.eligible) { lines.push('<p class="sub">' + esc(t('bk.bonus_yes')) + '</p>'); if (b.review) lines.push('<p class="sub">' + esc(t('bk.bonus_review')) + '</p>'); }
  else if (b.reason) lines.push('<p class="sub">' + esc(t('bk.bonus_' + b.reason)) + '</p>');
  lines.push('<p class="sub">' + esc(t('bk.left', { n: r.left_today })) + '</p>');
  return '<section class="card" role="status"><div class="row"><div>' + lines.join('') + '</div><span class="ver">' + ic('check') + '</span></div></section>';
}

function confirm() {
  if (!ready()) return;
  var f = fareOf(B.vtype), ov = document.createElement('div'); ov.className = 'ov noprint'; ov.id = 'ov';
  ov.innerHTML = '<div class="sheet" role="dialog" aria-modal="true" aria-label="' + esc(t('bk.confirm_title')) + '"><h2 style="margin:0 0 8px">' + esc(t('bk.confirm_title')) + '</h2>' +
    '<p>' + esc(t('bk.confirm_text', { v: vname(B.vtype), p: B.phone.trim(), f: (B.open ? t('bk.from', { f: money(f) }) : money(f)) })) + '</p>' +
    (B.pickup || B.dest ? '<p class="sub">' + esc((B.pickup || '-') + ' → ' + (B.dest || '-')) + '</p>' : '') +
    '<div class="stack" style="margin-top:16px"><button class="btn primary" id="bk-yes" data-act="bk-yes">' + ic('check') + esc(t('bk.confirm_btn')) + '</button><button class="btn ghost" data-act="closesheet">' + esc(t('bk.cancel')) + '</button></div></div>';
  document.body.appendChild(ov); var y = document.getElementById('bk-yes'); if (y) y.focus();
}
function submit() {
  if (B.busy) return; B.busy = true; var yes = document.getElementById('bk-yes'); if (yes) { yes.disabled = true; yes.textContent = t('c.sending'); }
  api('fa_agent_book_ride', { phone: B.phone.trim(), name: B.name.trim(), vehicle_type: B.vtype, km: parseFloat(B.km) || 0, open: B.open ? 1 : 0, pickup_note: B.pickup.trim(), dest_note: B.dest.trim(),
    pickup_lat: B.pLat, pickup_lng: B.pLng, dropoff_lat: B.dLat, dropoff_lng: B.dLng }).then(function (r) {
    B.busy = false; P.closeSheet();
    if (r.ok) { B.result = r.data; B.err = ''; B.phone = ''; B.name = ''; B.dest = ''; B.dLat = B.dLng = null; B.routeKm = 0; B.km = ''; B.open = false; B.vtype = ''; if (M.drop) { M.drop.setMap(null); M.drop = null; } if (M.line) { M.line.setMap(null); M.line = null; } quote(); loadList(); drivers(); }
    else { B.result = null; B.err = reasonText(r); }
    P.render(); try { window.scrollTo(0, 0); } catch (e) { /* not needed */ }
  });
}

function act(a, el) {
  if (a === 'bk-veh') { B.vtype = el.getAttribute('data-v'); sync(); P.render(); drivers(); }
  else if (a === 'bk-km') { B.km = el.getAttribute('data-k'); B.open = false; B.routeKm = 0; sync(); P.render(); quote(); }
  else if (a === 'bk-open') { B.open = !B.open; if (B.open) { B.km = ''; B.routeKm = 0; } sync(); P.render(); quote(); }
  else if (a === 'bk-mappick') { var m = el.getAttribute('data-m'); B.mode = B.mode === m ? '' : m; sync(); P.render(); if (B.mode) mapNote(t('bk.tap_map')); }
  else if (a === 'bk-loc') {
    if (!navigator.geolocation) { B.geo = t('bk.loc_err'); sync(); P.render(); return; }
    B.geo = t('bk.loc_wait'); sync(); P.render();
    navigator.geolocation.getCurrentPosition(function (p) { B.geo = ''; sync(); P.render(); ensureMap().then(function () { setPickup(p.coords.latitude, p.coords.longitude, true); }); if (!M.map) { B.pLat = p.coords.latitude; B.pLng = p.coords.longitude; } }, function () { B.geo = t('bk.loc_err'); sync(); P.render(); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }
  else if (a === 'bk-go') { sync(); confirm(); }
  else if (a === 'bk-yes') submit();
  else if (a === 'bk-refresh') loadList();
}
/* typed text lives in the inputs; copy it into B before any re-render so nothing is lost */
function sync() {
  var g = function (id) { var e = document.getElementById(id); return e ? e.value : null; };
  var v; if ((v = g('bk-phone')) !== null) B.phone = v; if ((v = g('bk-name')) !== null) B.name = v; if ((v = g('bk-pickup')) !== null) B.pickup = v; if ((v = g('bk-dest')) !== null) B.dest = v;
  if (!B.open && (v = g('bk-km')) !== null && v !== '') B.km = v;
}
document.addEventListener('input', function (e) {
  if (P.S.view !== 'book' || !e.target.id || e.target.id.indexOf('bk-') !== 0) return;
  sync();
  if (e.target.id === 'bk-km') { B.open = false; B.routeKm = 0; var rn = document.getElementById('bk-route'); if (rn) rn.textContent = ''; document.querySelectorAll('.bv-chip').forEach(function (c) { c.classList.remove('on'); c.setAttribute('aria-pressed', 'false'); }); quote(); }
  if (e.target.id === 'bk-pickup' && !e.target.value) { B.pLat = B.pLng = null; placePins(); route(); }
  if (e.target.id === 'bk-dest' && !e.target.value) { B.dLat = B.dLng = null; placePins(); route(); }
  summary();
});
/* a picture that fails to load falls back to the drawn icon */
document.addEventListener('error', function (e) { var im = e.target; if (im && im.classList && im.classList.contains('bv-img')) { var s = document.createElement('span'); s.innerHTML = ic('car'); im.replaceWith(s.firstChild); } }, true);

function open() {
  B.result = null; B.mode = '';
  if (!B.veh) load(); else { loadList(); quote(); }
  ensureMap().then(function (ok) { if (!ok || P.S.view !== 'book') return; mountMap(); attachSearch(); placePins(); drivers(); startPoll(); });
}
window.ETELBook = { view: view, act: act, open: open };
window.addEventListener('hashchange', function () { if (P.S.view !== 'book') stopPoll(); });
})();
