/* ETEL Admin - Ride: Drivers.
   One page for the ride drivers: who is online on a live map, who waits for approval, ID mismatches, suspended drivers, and Add driver (the same steps as the Driver desk).
   Data: admin_get_drivers + admin_get_suspended_drivers (read), admin_get_live_drivers (map, every 20 s while the page is open), admin_suspend_driver / admin_unsuspend_driver.
   Standalone: adds its own menu item and page. Look and pieces come from js/admin-kit.js. No inline handlers; server text goes through esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var S = { drivers: [], live: [], loaded: false, filter: 'all', q: '', timer: 0, map: null, markers: [], mapState: '' };

function panel() { return document.getElementById('panel-ridedrivers'); }
function ago(s) {
  if (!s) return 'never';
  var t = Date.parse(String(s).replace(' ', 'T')); if (!t) return String(s);
  var m = Math.max(0, Math.round((Date.now() - t) / 60000));
  if (m < 1) return 'just now'; if (m < 60) return m + ' min ago'; if (m < 1440) return Math.round(m / 60) + ' h ago'; return Math.round(m / 1440) + ' d ago';
}
function stateOf(d) {
  if (d.suspended_at) return 'suspended';
  if (d.identity_match === 'mismatch') return 'mismatch';
  if (Number(d.onboarded) !== 1) return 'pending';
  return Number(d.online) === 1 ? 'online' : 'offline';
}
function counts() {
  var c = { all: S.drivers.length, online: 0, pending: 0, mismatch: 0, suspended: 0 };
  S.drivers.forEach(function (d) {
    if (Number(d.online) === 1 && !d.suspended_at) c.online++;
    if (Number(d.onboarded) !== 1 && !d.suspended_at) c.pending++;
    if (d.identity_match === 'mismatch') c.mismatch++;
    if (d.suspended_at) c.suspended++;
  });
  return c;
}
function visible() {
  var q = S.q.toLowerCase().replace(/\s+/g, ''), qd = q.replace(/[^0-9]/g, '');
  return S.drivers.filter(function (d) {
    if (S.filter === 'online' && !(Number(d.online) === 1 && !d.suspended_at)) return false;
    if (S.filter === 'pending' && !(Number(d.onboarded) !== 1 && !d.suspended_at)) return false;
    if (S.filter === 'mismatch' && d.identity_match !== 'mismatch') return false;
    if (S.filter === 'suspended' && !d.suspended_at) return false;
    if (!q) return true;
    var nm = String(d.name || '').toLowerCase().replace(/\s+/g, ''), ph = String(d.phone || '').replace(/[^0-9]/g, ''), pl = String(d.vehicle_plate || '').toLowerCase().replace(/\s+/g, '');
    return nm.indexOf(q) >= 0 || pl.indexOf(q) >= 0 || (qd.length > 2 && ph.indexOf(qd) >= 0);
  });
}
function pillFor(d) {
  var s = stateOf(d);
  if (s === 'suspended') return K.pill('Suspended', 'red');
  if (s === 'mismatch') return K.pill('ID does not match', 'red');
  if (s === 'pending') return K.pill('Waiting for approval', 'amber');
  return s === 'online' ? K.pill('Online', 'green') : K.pill('Offline', 'grey');
}
function card(d) {
  var s = stateOf(d), ini = String(d.name || '?').trim().charAt(0).toUpperCase() || '?';
  var veh = [d.vehicle_type, d.vehicle_make, d.vehicle_color].filter(Boolean).join(' · ') || 'No vehicle yet';
  var h = '<div class="ak-card rdv-card" data-uid="' + esc(d.id) + '" style="cursor:default;margin-bottom:10px"><div class="ak-top"><div class="ak-av" style="--a:' + (s === 'online' ? '#2fa56a' : s === 'suspended' || s === 'mismatch' ? '#d6364a' : '#8a7f66') + ';--b:#7a5a00">' + esc(ini) + '</div>' +
    '<div class="ak-nm"><b>' + esc(d.name || 'No name') + '</b><small>' + esc(d.phone || '') + ' · ' + esc(veh) + (d.vehicle_plate ? ' · ' + esc(d.vehicle_plate) : '') + '</small></div>' + pillFor(d) + '</div>' +
    '<div class="ak-fh" style="margin:8px 0 0">' + esc(Number(d.total_rides || 0)) + ' rides · rating ' + esc(Number(d.rating || 0) || '-') + ' · seen ' + esc(ago(d.last_seen)) +
    (d.suspended_at ? ' · suspended ' + esc(ago(d.suspended_at)) + (d.suspended_reason ? ': “' + esc(d.suspended_reason) + '”' : '') : '') + '</div>' +
    '<div class="ak-acts" style="margin-top:10px">' + (d.phone ? '<a class="ak-btn" href="tel:' + esc(d.phone) + '">📞 Call</a>' : '') +
    '<button type="button" class="ak-btn" data-docs="' + esc(d.id) + '">📄 Documents</button>' +
    (d.suspended_at ? '<button type="button" class="ak-btn gold" data-unsus="' + esc(d.id) + '">Let drive again</button>' : '<button type="button" class="ak-btn danger" data-sus="' + esc(d.id) + '">Suspend</button>') + '</div></div>';
  return h;
}
function mapCard() {
  return '<div class="ak-card" style="cursor:default;margin-bottom:14px"><div style="display:flex;align-items:center;gap:10px"><div style="font-weight:800;font-size:16px;flex:1">🗺️ Drivers online now <span data-live-n style="font-weight:600;color:var(--muted,#8a7f66)">(' + S.live.length + ')</span></div>' +
    '<button type="button" class="ak-btn" data-live-refresh>↻ Refresh</button></div>' +
    '<div data-map style="height:300px;border-radius:12px;margin-top:10px;background:rgba(127,127,127,.12);overflow:hidden"></div><div data-map-note class="ak-fh" style="margin-top:6px"></div></div>';
}
function render() {
  var el = panel(); if (!el) return;
  K.css();
  var c = counts(), list = visible();
  var h = '<div class="ak">' + K.hero('Drivers', 'See who is driving, approve new drivers, and stop a driver who should not drive.',
    [['Drivers', K.num(c.all)], ['Online now', K.num(c.online)], ['Waiting for approval', K.num(c.pending)], ['Suspended', K.num(c.suspended)]]);
  h += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0"><input type="search" class="ak-in" data-q placeholder="Find by name, phone or plate" aria-label="Find a driver" value="' + esc(S.q) + '" style="flex:1;min-width:200px">' +
    '<button type="button" class="ak-btn gold" data-add>＋ Add driver</button></div>';
  h += K.kpis([{ key: 'all', icon: '👥', label: 'All', value: c.all }, { key: 'online', icon: '🟢', label: 'Online', value: c.online }, { key: 'pending', icon: '🕓', label: 'Waiting', value: c.pending, warn: true },
    { key: 'mismatch', icon: '⚠️', label: 'ID mismatch', value: c.mismatch, warn: true }, { key: 'suspended', icon: '⛔', label: 'Suspended', value: c.suspended }], S.filter);
  h += '<div data-map-host>' + mapCard() + '</div>';
  h += '<div data-list>' + (list.length ? list.map(card).join('') : K.empty('🚕', S.drivers.length ? 'No driver matches' : 'No ride driver yet', S.drivers.length ? 'Try another word or choose All.' : 'Press Add driver to register the first one.')) + '</div>';
  el.innerHTML = h + '</div>';
  S.map = null; S.markers = []; drawMap();
}
function renderList() {
  var el = panel(), l = el && el.querySelector('[data-list]'); if (!l) return;
  var list = visible();
  l.innerHTML = list.length ? list.map(card).join('') : K.empty('🚕', S.drivers.length ? 'No driver matches' : 'No ride driver yet', S.drivers.length ? 'Try another word or choose All.' : 'Press Add driver to register the first one.');
}
function setNote(t) { var el = panel(), n = el && el.querySelector('[data-map-note]'); if (n) n.textContent = t || ''; }
function plain() {
  var el = panel(), m = el && el.querySelector('[data-map]'); if (!m) return;
  m.style.height = 'auto'; m.style.padding = '10px';
  m.innerHTML = S.live.length ? S.live.map(function (d) {
    return '<div class="ak-fh" style="margin:0 0 6px"><b>' + esc(d.name || 'Driver') + '</b> · ' + esc(d.vehicle_type || '') + ' · <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + esc(d.lat) + ',' + esc(d.lng) + '">open on map</a></div>';
  }).join('') : '<div class="ak-fh" style="margin:0">No driver is online with a position right now.</div>';
  setNote('The map could not load, so here is the list.');
}
function drawMarkers() {
  if (!S.map || !window.google || !google.maps) return;
  S.markers.forEach(function (m) { m.setMap(null); }); S.markers = [];
  var b = new google.maps.LatLngBounds();
  S.live.forEach(function (d) {
    var p = { lat: Number(d.lat), lng: Number(d.lng) };
    S.markers.push(new google.maps.Marker({ position: p, map: S.map, title: (d.name || 'Driver') + (d.vehicle_plate ? ' · ' + d.vehicle_plate : ''),
      icon: { path: google.maps.SymbolPath.CIRCLE, fillColor: '#2fa56a', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2, scale: 9 } }));
    b.extend(p);
  });
  if (S.live.length > 1) S.map.fitBounds(b, 40); else if (S.live.length === 1) { S.map.setCenter({ lat: Number(S.live[0].lat), lng: Number(S.live[0].lng) }); S.map.setZoom(14); }
  setNote(S.live.length ? '' : 'No driver is online with a position right now.');
}
function drawMap() {
  var el = panel(), m = el && el.querySelector('[data-map]'); if (!m) return;
  if (S.mapState === 'failed' || typeof window.loadGoogleMaps !== 'function') { plain(); return; }
  if (window.google && google.maps && google.maps.Map) { S.map = new google.maps.Map(m, { center: { lat: 7.0621, lng: 38.4761 }, zoom: 13, disableDefaultUI: true, zoomControl: true }); drawMarkers(); return; }
  setNote('Loading the map…');
  var done = false, give = setTimeout(function () { if (!done) { S.mapState = 'failed'; plain(); } }, 9000);
  window.rdvMapCallback = function () {
    done = true; clearTimeout(give);
    if (!(window.google && google.maps && google.maps.Map)) { S.mapState = 'failed'; plain(); return; }   // core.js calls back even when Google did not load
    if (!panel() || !panel().querySelector('[data-map]')) return;
    S.map = new google.maps.Map(panel().querySelector('[data-map]'), { center: { lat: 7.0621, lng: 38.4761 }, zoom: 13, disableDefaultUI: true, zoomControl: true }); drawMarkers();
  };
  try { window.loadGoogleMaps('marker', 'rdvMapCallback'); } catch (e) { S.mapState = 'failed'; plain(); }
  document.addEventListener('etel:maps-failed', function () { if (!done) { done = true; clearTimeout(give); S.mapState = 'failed'; plain(); } }, { once: true });
}
function loadLive() {
  return K.api('admin_get_live_drivers', {}).then(function (res) {
    if (!K.okRes(res)) { setNote('Could not read the live drivers.'); return; }
    S.live = K.data(res).drivers || [];
    var n = panel() && panel().querySelector('[data-live-n]'); if (n) n.textContent = '(' + S.live.length + ')';
    if (S.map) drawMarkers(); else if (S.mapState === 'failed') plain();
  });
}
function load(quiet) {
  var el = panel(); if (!el) return;
  K.css();
  if (!S.loaded && !quiet) el.innerHTML = '<div class="ak">' + K.skeleton(4) + '</div>';
  Promise.all([K.api('admin_get_drivers', {}), K.api('admin_get_suspended_drivers', {}), K.api('admin_get_live_drivers', {})]).then(function (r) {
    if (!K.okRes(r[0])) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load the drivers', K.failText(r[0])) + '</div>'; return; }
    var sus = {}; (K.okRes(r[1]) ? (K.data(r[1]).suspended || []) : []).forEach(function (x) { sus[String(x.user_id)] = x; });
    S.drivers = (K.data(r[0]).drivers || []).map(function (d) { var x = sus[String(d.id)]; if (x) { d.suspended_at = x.suspended_at; d.suspended_reason = x.suspended_reason; } return d; });
    S.live = K.okRes(r[2]) ? (K.data(r[2]).drivers || []) : [];
    S.loaded = true; render(); startTimer();
  });
}
function startTimer() {
  if (S.timer) return;
  S.timer = setInterval(function () { var p = panel(); if (!p || !p.classList.contains('active') || document.hidden) return; loadLive(); }, 20000);
}
function find(id) { return S.drivers.filter(function (d) { return Number(d.id) === Number(id); })[0]; }
function onClick(e) {
  var t = e.target, b; if (!t.closest) return;
  if ((b = t.closest('[data-kpi]'))) { S.filter = b.getAttribute('data-kpi'); render(); return; }
  if (t.closest('[data-add]')) { if (window.EtelDriverRegister && EtelDriverRegister.open) EtelDriverRegister.open(); else K.toast('The driver form is not loaded. Refresh the page.', true); return; }
  if (t.closest('[data-live-refresh]')) { loadLive().then(function () { K.toast('Updated'); }); return; }
  if ((b = t.closest('[data-docs]'))) { if (typeof window.switchPage === 'function') window.switchPage('trust', document.querySelector('.nav-item[data-page="trust"]')); return; }
  if ((b = t.closest('[data-sus]'))) {
    var d = find(b.getAttribute('data-sus')); if (!d) return;
    K.confirm({ title: 'Suspend ' + (d.name || 'this driver') + '?', text: 'They go offline now and cannot drive until you let them again.', label: 'Suspend', danger: true, reason: 'Why? (the driver does not see this)' }).then(function (r) {
      if (!r) return;
      var why = r.reason || '';
      K.api('admin_suspend_driver', { user_id: d.id, reason: why }).then(function (res) {
        if (K.okRes(res)) { d.suspended_at = new Date().toISOString().slice(0, 19).replace('T', ' '); d.suspended_reason = why; d.online = 0; render(); K.toast('Driver suspended'); } else K.toast(K.failText(res, 'Could not suspend.'), true);
      });
    });
    return;
  }
  if ((b = t.closest('[data-unsus]'))) {
    var u = find(b.getAttribute('data-unsus')); if (!u) return;
    K.confirm({ title: 'Let ' + (u.name || 'this driver') + ' drive again?', text: 'They can go online again.', label: 'Let drive' }).then(function (r) {
      if (!r) return;
      K.api('admin_unsuspend_driver', { user_id: u.id }).then(function (res) {
        if (K.okRes(res)) { u.suspended_at = null; u.suspended_reason = null; render(); K.toast('Driver can drive again'); } else K.toast(K.failText(res, 'Could not do it.'), true);
      });
    });
  }
}
function onInput(e) {
  var t = e.target; if (!t || !t.hasAttribute || !t.hasAttribute('data-q')) return;
  S.q = t.value; renderList();
}
function install() {
  K.ensurePanel('ridedrivers', 'ridedrivers', '🚕', 'Drivers', 'liveops');
  var el = panel(); if (el && !el._rd) { el._rd = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); }
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._rd) {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'ridedrivers') { var t = document.getElementById('page-title'); if (t) t.textContent = 'Drivers'; var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = ''; load(S.loaded); }
      return r;
    };
    window.switchPage._rd = true;
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
