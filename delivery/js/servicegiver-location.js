/* Service giver app - "Shop location" card in Settings: the owner puts their shop on the map by dropping a pin, dragging it, typing the latitude and
   longitude, or using the phone's location. Customers in a city only see shops that have a pin in that city, so a shop without one is invisible.
   API: sg_profile (reads lat/lng), sg_update_profile { lat, lng } (api/controllers/ServiceGiverController.php; owners with settings.manage).
   Additive: no inline handlers; the map is Google Maps (key from get_config); without it the typed fields and the phone location still work. */
(function () {
'use strict';
var card = null, map = null, marker = null, mapsP = null, loaded = false, saved = null;
var HAWASSA = { lat: 7.0500, lng: 38.4850 };
function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
var AM = {
  'Shop location': 'የሱቅ ቦታ', 'Customers near you only see your shop when it has a pin on the map. Tap the map to drop a pin, drag the pin, type the numbers, or use your phone\'s location.': 'በአካባቢዎ ያሉ ደንበኞች ሱቅዎን የሚያዩት ካርታ ላይ ምልክት ሲኖረው ብቻ ነው። ካርታውን ይንኩ፣ ምልክቱን ይጎትቱ፣ ቁጥሮቹን ይጻፉ ወይም የስልክዎን ቦታ ይጠቀሙ።',
  'Use my current location': 'አሁን ያለሁበትን ቦታ ተጠቀም', 'Latitude': 'ኬክሮስ', 'Longitude': 'ኬንትሮስ', 'Save location': 'ቦታውን አስቀምጥ',
  'No location yet. Customers cannot see your shop until you set it.': 'ገና ቦታ የለም። ቦታ እስኪያስቀምጡ ድረስ ደንበኞች ሱቅዎን ማየት አይችሉም።', 'Location saved. Customers near this spot can see your shop.': 'ቦታው ተቀምጧል። በዚህ አካባቢ ያሉ ደንበኞች ሱቅዎን ማየት ይችላሉ።'
};
function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
function el(id) { return document.getElementById(id); }
function num(v) { v = String(v == null ? '' : v).replace(',', '.').replace(/\s+/g, ''); if (v === '' || !/^-?\d+(\.\d+)?$/.test(v)) return null; return parseFloat(v); }
function validPair(lat, lng) { return lat !== null && lng !== null && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180; }
function inEthiopia(lat, lng) { return lat >= 3 && lat <= 15.5 && lng >= 32.5 && lng <= 48.5; }
function say(text, kind) { var m = el('sgl-msg'); if (!m) return; m.textContent = text || ''; m.style.color = kind === 'bad' ? '#c0392b' : '#0a8a4a'; }
function status(lat, lng) {
  var s = el('sgl-status'); if (!s) return;
  if (validPair(lat, lng) && !(lat === 0 && lng === 0)) { s.textContent = '📍 ' + lat.toFixed(5) + ', ' + lng.toFixed(5); s.style.color = '#0a8a4a'; }
  else { s.textContent = T('No location yet. Customers cannot see your shop until you set it.'); s.style.color = '#c0392b'; }
}

function build() {
  var anchor = el('sg-papers-card'); if (!anchor || el('sg-location-card')) return false;
  card = document.createElement('div'); card.className = 'card'; card.id = 'sg-location-card'; card.setAttribute('data-owner-only', '1');
  card.innerHTML = '<div class="card-body">' +
    '<p style="font-size:11px;color:var(--text-faint,#9A9AAE);margin:0 0 6px;text-transform:uppercase;letter-spacing:.5px;font-weight:700">' + esc(T('Shop location')) + '</p>' +
    '<p style="font-size:13px;color:var(--text-dim);margin:0 0 10px">' + esc(T('Customers near you only see your shop when it has a pin on the map. Tap the map to drop a pin, drag the pin, type the numbers, or use your phone\'s location.')) + '</p>' +
    '<div id="sgl-status" style="font-weight:700;font-size:13.5px;margin:0 0 8px"></div>' +
    '<div id="sgl-map" style="height:260px;border-radius:12px;background:rgba(128,128,128,.12);overflow:hidden;margin-bottom:10px"></div>' +
    '<button type="button" id="sgl-gps" class="btn btn-secondary btn-small" style="margin-bottom:10px">📍 ' + esc(T('Use my current location')) + '</button>' +
    '<div style="display:flex;gap:10px;flex-wrap:wrap"><label style="flex:1;min-width:130px;font-size:12px">' + esc(T('Latitude')) + '<input id="sgl-lat" type="text" inputmode="decimal" autocomplete="off" style="width:100%"></label>' +
    '<label style="flex:1;min-width:130px;font-size:12px">' + esc(T('Longitude')) + '<input id="sgl-lng" type="text" inputmode="decimal" autocomplete="off" style="width:100%"></label></div>' +
    '<div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button type="button" id="sgl-save" class="btn btn-primary">' + esc(T('Save location')) + '</button><span id="sgl-msg" style="font-size:13px"></span></div></div>';
  anchor.parentNode.insertBefore(card, anchor.nextSibling);
  el('sgl-gps').addEventListener('click', useGps);
  el('sgl-save').addEventListener('click', save);
  ['sgl-lat', 'sgl-lng'].forEach(function (id) { el(id).addEventListener('change', fromFields); });
  return true;
}

function loadMaps() {
  if (window.google && google.maps && google.maps.Map) return Promise.resolve(true);
  if (mapsP) return mapsP;
  mapsP = new Promise(function (res) {
    var api = (window.ETEL && ETEL.api) || '/delivery/api/boot.php';
    fetch(api + (api.indexOf('?') < 0 ? '?' : '&') + 'action=get_config').then(function (r) { return r.json(); }).then(function (d) {
      var key = d && d.success && d.data && d.data.google_maps_api_key; if (!key) return res(false);
      window.__sglMapsReady = function () { res(true); };
      var s = document.createElement('script'); s.async = true; s.defer = true; s.onerror = function () { res(false); };
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&loading=async&callback=__sglMapsReady';
      document.head.appendChild(s);
    }).catch(function () { res(false); });
    setTimeout(function () { if (!(window.google && google.maps && google.maps.Map)) res(false); }, 15000);
  });
  return mapsP;
}

function setPoint(lat, lng, fromMap, keepFields) {
  if (!keepFields) { el('sgl-lat').value = lat.toFixed(6); el('sgl-lng').value = lng.toFixed(6); }   // typed numbers are left as typed
  status(lat, lng); say('');
  if (!map) return;
  var p = { lat: lat, lng: lng };
  if (!marker) {
    marker = new google.maps.Marker({ position: p, map: map, draggable: true });
    marker.addListener('dragend', function () { var q = marker.getPosition(); setPoint(q.lat(), q.lng(), true); });
  } else marker.setPosition(p);
  if (!fromMap) { map.setCenter(p); map.setZoom(17); }
}
function fromFields() {
  var lat = num(el('sgl-lat').value), lng = num(el('sgl-lng').value);
  if (validPair(lat, lng)) setPoint(lat, lng, false, true); else { say(T('Latitude') + ' / ' + T('Longitude') + ': numbers like 7.0500 and 38.4850', 'bad'); }
}
function useGps() {
  if (!navigator.geolocation) { say('This phone cannot share its location. Tap the map instead.', 'bad'); return; }
  say('…');
  navigator.geolocation.getCurrentPosition(function (p) { setPoint(p.coords.latitude, p.coords.longitude, false); say(''); },
    function () { say('Could not get your location. Allow location for this page, or tap the map instead.', 'bad'); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
}

function save() {
  var lat = num(el('sgl-lat').value), lng = num(el('sgl-lng').value);
  if (!validPair(lat, lng) || (lat === 0 && lng === 0)) { say('Drop a pin or type the latitude and longitude first.', 'bad'); return; }
  if (!inEthiopia(lat, lng) && !window.confirm('These numbers are not in Ethiopia. Save them anyway?')) return;
  var b = el('sgl-save'); b.disabled = true;
  apiCall('sg_update_profile', { lat: lat, lng: lng }).then(function (d) {
    b.disabled = false;
    if (d) { saved = { lat: lat, lng: lng }; status(lat, lng); say(T('Location saved. Customers near this spot can see your shop.')); if (typeof showNotification === 'function') showNotification(T('Location saved. Customers near this spot can see your shop.'), 'success'); }
  }).catch(function () { b.disabled = false; say('Could not save. Check your connection.', 'bad'); });
}

function load() {
  if (!card && !build()) return;
  if (typeof apiCall !== 'function') return;
  apiCall('sg_profile').then(function (d) {
    var g = d && d.service_giver; if (!g) return;
    var lat = g.lat == null ? null : parseFloat(g.lat), lng = g.lng == null ? null : parseFloat(g.lng);
    var has = validPair(lat, lng) && !(lat === 0 && lng === 0);
    saved = has ? { lat: lat, lng: lng } : null;
    status(has ? lat : null, has ? lng : null);
    if (has) { el('sgl-lat').value = lat.toFixed(6); el('sgl-lng').value = lng.toFixed(6); }
    loadMaps().then(function (ok) {
      var box = el('sgl-map');
      if (!ok) { box.style.display = 'none'; return; }
      if (!map) {
        map = new google.maps.Map(box, { center: has ? { lat: lat, lng: lng } : HAWASSA, zoom: has ? 17 : 13, disableDefaultUI: true, zoomControl: true, gestureHandling: 'greedy', clickableIcons: false });
        map.addListener('click', function (e) { setPoint(e.latLng.lat(), e.latLng.lng(), true); });
      }
      if (has) setPoint(lat, lng, false);
    });
  });
}

function init() {
  if (!el('sg-papers-card')) return;
  build();
  document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-page="settings"]')) setTimeout(load, 80); });
  setTimeout(load, 1800);
}
window.__etelSgLocation = { validPair: validPair, inEthiopia: inEthiopia, num: num };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
