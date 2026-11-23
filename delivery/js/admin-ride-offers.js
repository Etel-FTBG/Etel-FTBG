/* ETEL Admin - Ride: Special offers and the driver Pull tab.
   What drivers see in the Pull tab of the Driver app (api/lib/ride_pull.php):
   - the rules: how long a trip waits before far drivers can pull it, how far a driver must be (nearest / farthest, km), and the fare from which a trip is a
     high paying (VIP) offer;
   - the special areas: places you draw (centre, radius, a note drivers read): a Sweet area (many calls) or a VIP area. A trip called from inside one is shown
     at once to every driver nearby, with the area name, your note and all trip details.
   Data: admin_ride_offers_get, admin_ride_offers_save, admin_ride_area_save, admin_ride_area_delete (api/controllers/RideOffersAdminController.php).
   Standalone: adds its own menu item and page. Look and pieces come from js/admin-kit.js. No inline handlers; server text goes through esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var S = { settings: null, areas: [], loaded: false, edit: null };
var ZONES = [['Stadium', 7.0606, 38.4775], ['Mehal Ketema', 7.0559, 38.4762], ['Tabor', 7.04, 38.49], ['Liqe Mender', 7.07, 38.48], ['Referral', 7.049, 38.468], ['Addis Ketema', 7.045, 38.475],
             ['University', 7.062, 38.511], ['Misrak', 7.08, 38.485], ['Haik Dar', 7.035, 38.5], ['Tulu Dimtu', 7.1, 38.5], ['Hawela Teklehaimanot', 7.065, 38.49], ['Dore Bafana', 7.12, 38.47]];
var NUMS = [['ride_pull_after_secs', 'Wait before far drivers can pull a trip (seconds)', 'How long a trip waits for the drivers near the rider first. 0 = at once.'],
            ['ride_pull_min_km', 'A driver must be at least this far from the pickup (km)', 'Nearer drivers already get the trip in their normal Requests list.'],
            ['ride_pull_max_km', 'Farthest driver who can pull or see an offer (km)', 'Drivers beyond this never see it.'],
            ['ride_vip_min_fare', 'High paying (VIP) from this fare (ETB)', 'A trip that pays at least this much is a special offer. 0 = off.']];

function panel() { return document.getElementById('panel-rideoffers'); }
function note(c, msg, bad) { var n = c.querySelector('[data-note]'); if (n) { n.textContent = msg; n.style.color = bad ? '#d6364a' : '#1fa463'; } }
function numIn(name, v) { return '<input class="ak-in" name="' + name + '" type="number" inputmode="decimal" step="any" min="0" value="' + esc(v) + '">'; }

function settingsCard() {
  var s = S.settings || {}, on = Number(s.ride_pull_on) === 1;
  return '<form data-settings class="ak-card" style="cursor:default;margin-bottom:14px"><div style="font-weight:800;font-size:16px;margin-bottom:2px">⚡ Pull tab rules</div>' +
    '<div class="ak-fh" style="margin:0 0 12px">Drivers get a Pull tab. It lists trips the drivers near the rider did not take, for drivers who are farther away, and your special offers.</div>' +
    '<label style="display:flex;gap:8px;align-items:center;font-weight:700;margin-bottom:12px"><input type="checkbox" name="ride_pull_on"' + (on ? ' checked' : '') + ' style="width:auto"> Show waiting trips to far drivers in the Pull tab</label>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px">' + NUMS.map(function (n) { return K.field(n[1], numIn(n[0], s[n[0]]), n[2]); }).join('') + '</div>' +
    '<div class="ak-acts" style="margin-top:14px"><button type="submit" class="ak-btn gold">Save rules</button></div><div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}
function areaCard(a) {
  var vip = a.kind === 'vip';
  return '<div class="ak-card" style="cursor:default;margin-bottom:10px"><div class="ak-top"><div class="ak-av" style="--a:' + (vip ? '#d6364a' : '#2fa56a') + ';--b:#7a5a00;font-size:22px">' + (vip ? '💎' : '🍬') + '</div>' +
    '<div class="ak-nm"><b>' + esc(a.name) + '</b><small>' + (vip ? 'VIP area' : 'Sweet area') + ' · radius ' + esc(a.radius_km) + ' km · ' + esc(Number(a.lat).toFixed(4)) + ', ' + esc(Number(a.lng).toFixed(4)) + '</small></div>' +
    K.pill(a.active ? 'On' : 'Off', a.active ? 'green' : 'grey') + '</div>' + (a.note ? '<div class="ak-fh" style="margin-top:8px">“' + esc(a.note) + '”</div>' : '') +
    '<div class="ak-acts" style="margin-top:10px"><button type="button" class="ak-btn" data-edit="' + esc(a.id) + '">Edit</button><a class="ak-btn" target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + esc(a.lat) + ',' + esc(a.lng) + '">🗺 Map</a>' +
    '<button type="button" class="ak-btn danger" data-del="' + esc(a.id) + '">Delete</button></div></div>';
}
function areaForm(a) {
  a = a || { id: 0, name: '', kind: 'sweet', lat: '', lng: '', radius_km: 1, note: '', active: 1 };
  return '<form data-area class="ak-card" style="cursor:default;margin:0 0 14px;border-color:var(--gold)"><input type="hidden" name="id" value="' + esc(a.id) + '">' +
    '<div style="font-weight:800;margin-bottom:8px">' + (a.id ? 'Edit area' : 'Add a special area') + '</div>' +
    '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">' +
    K.field('Name drivers see', '<input class="ak-in" name="name" maxlength="80" placeholder="Piassa market" value="' + esc(a.name) + '">') +
    K.field('Kind', '<select class="ak-in" name="kind"><option value="sweet"' + (a.kind === 'sweet' ? ' selected' : '') + '>🍬 Sweet area (many calls)</option><option value="vip"' + (a.kind === 'vip' ? ' selected' : '') + '>💎 VIP area</option></select>') +
    K.field('Start from a Hawassa zone', '<select class="ak-in" data-zone><option value="">Choose…</option>' + ZONES.map(function (z, i) { return '<option value="' + i + '">' + esc(z[0]) + '</option>'; }).join('') + '</select>', 'Fills the centre. You can change it.') +
    K.field('Latitude', '<input class="ak-in" name="lat" type="number" step="any" inputmode="decimal" value="' + esc(a.lat) + '">') +
    K.field('Longitude', '<input class="ak-in" name="lng" type="number" step="any" inputmode="decimal" value="' + esc(a.lng) + '">') +
    K.field('Radius (km)', '<input class="ak-in" name="radius_km" type="number" step="any" min="0.1" max="20" inputmode="decimal" value="' + esc(a.radius_km) + '">') + '</div>' +
    '<div style="margin-top:12px">' + K.field('Note drivers read (optional)', '<input class="ak-in" name="note" maxlength="160" placeholder="Busy at lunch time" value="' + esc(a.note) + '">') + '</div>' +
    '<label style="display:flex;gap:8px;align-items:center;margin-top:10px;font-weight:700"><input type="checkbox" name="active"' + (a.active ? ' checked' : '') + ' style="width:auto"> On</label>' +
    '<div class="ak-acts" style="margin-top:12px"><button type="submit" class="ak-btn gold">Save area</button><button type="button" class="ak-btn" data-here>📍 Use my location</button><button type="button" class="ak-btn" data-cancel>Cancel</button></div>' +
    '<div data-note role="status" style="font-size:12.5px;min-height:18px;margin-top:6px"></div></form>';
}
function render() {
  var el = panel(); if (!el) return;
  K.css();
  var on = S.areas.filter(function (a) { return a.active; }).length;
  var h = '<div class="ak">' + K.hero('Special offers and Pull', 'Control what drivers see in their Pull tab: trips waiting far from them, high paying trips and trips called from your special areas.',
    [['Special areas on', K.num(on)], ['Areas in all', K.num(S.areas.length)], ['VIP from', Number(S.settings && S.settings.ride_vip_min_fare) > 0 ? K.money(S.settings.ride_vip_min_fare) : 'off']]);
  h += settingsCard();
  h += '<div style="display:flex;gap:10px;align-items:center;margin:6px 0 10px"><h3 style="margin:0;font-size:15px">Special areas</h3><button type="button" class="ak-btn gold" data-add>＋ Add area</button></div>';
  h += '<div data-form-host>' + (S.edit !== null ? areaForm(S.edit) : '') + '</div>';
  h += S.areas.length ? S.areas.map(areaCard).join('') : K.empty('🍬', 'No special area yet', 'Add a place where many calls come from. Trips called from inside it are shown at once to drivers nearby.');
  el.innerHTML = h + '</div>';
}
function load(quiet) {
  var el = panel(); if (!el) return;
  K.css();
  if (!S.loaded && !quiet) el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  K.api('admin_ride_offers_get', {}).then(function (res) {
    if (!K.okRes(res)) { el.innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load', K.failText(res)) + '</div>'; return; }
    var d = K.data(res); S.settings = d.settings || {}; S.areas = d.areas || []; S.loaded = true; render();
  });
}
function onClick(e) {
  var t = e.target, b; if (!t.closest) return;
  if (t.closest('[data-add]')) { S.edit = { id: 0, name: '', kind: 'sweet', lat: '', lng: '', radius_km: 1, note: '', active: 1 }; render(); return; }
  if ((b = t.closest('[data-edit]'))) { var id = Number(b.getAttribute('data-edit')); S.edit = S.areas.filter(function (a) { return a.id === id; })[0] || null; render(); var f = panel().querySelector('form[data-area]'); if (f && f.scrollIntoView) f.scrollIntoView({ block: 'center' }); return; }
  if (t.closest('[data-cancel]')) { S.edit = null; render(); return; }
  if ((b = t.closest('[data-del]'))) {
    var did = Number(b.getAttribute('data-del')), a = S.areas.filter(function (x) { return x.id === did; })[0];
    K.confirm({ title: 'Delete ' + (a ? a.name : 'this area') + '?', text: 'Trips called from there will stop being special offers.', label: 'Delete', danger: true }).then(function (r) {
      if (r) K.api('admin_ride_area_delete', { id: did }).then(function (res) { if (K.okRes(res)) { S.areas = K.data(res).areas || []; render(); K.toast('Area deleted'); } else K.toast(K.failText(res), true); });
    });
    return;
  }
  if (t.closest('[data-here]')) {
    var f2 = t.closest('form'); if (!navigator.geolocation) { note(f2, 'This phone or browser cannot give its location.', true); return; }
    navigator.geolocation.getCurrentPosition(function (p) { f2.elements.lat.value = p.coords.latitude.toFixed(6); f2.elements.lng.value = p.coords.longitude.toFixed(6); note(f2, 'Location filled in.', false); }, function () { note(f2, 'Could not get the location. Allow it, or type the numbers.', true); }, { enableHighAccuracy: true, timeout: 10000 });
  }
}
function onChange(e) {
  var z = e.target && e.target.hasAttribute && e.target.hasAttribute('data-zone') ? e.target : null; if (!z || z.value === '') return;
  var f = z.closest('form'), zz = ZONES[Number(z.value)]; if (!zz) return;
  f.elements.lat.value = zz[1]; f.elements.lng.value = zz[2]; if (!f.elements.name.value) f.elements.name.value = zz[0];
}
function onSubmit(e) {
  var f = e.target; e.preventDefault(); var btn = f.querySelector('button[type=submit]'); if (!btn) return;
  btn.disabled = true; note(f, 'Saving…');
  if (f.hasAttribute('data-settings')) {
    var body = {}; Array.prototype.forEach.call(f.elements, function (x) { if (!x.name) return; body[x.name] = x.type === 'checkbox' ? (x.checked ? 1 : 0) : x.value; });
    K.api('admin_ride_offers_save', body).then(function (res) {
      btn.disabled = false;
      if (K.okRes(res)) { S.settings = K.data(res).settings || S.settings; note(f, 'Saved. Drivers get it within a few seconds.', false); K.toast('Rules saved'); } else note(f, K.failText(res, 'Could not save.'), true);
    });
  } else if (f.hasAttribute('data-area')) {
    var ab = { id: Number(f.elements.id.value) || 0, name: f.elements.name.value, kind: f.elements.kind.value, lat: f.elements.lat.value, lng: f.elements.lng.value, radius_km: f.elements.radius_km.value, note: f.elements.note.value, active: f.elements.active.checked ? 1 : 0 };
    K.api('admin_ride_area_save', ab).then(function (res) {
      btn.disabled = false;
      if (K.okRes(res)) { S.areas = K.data(res).areas || []; S.edit = null; render(); K.toast('Area saved'); } else note(f, K.failText(res, 'Could not save.'), true);
    });
  }
}
function install() {
  K.ensurePanel('rideoffers', 'rideoffers', '⚡', 'Special offers', 'liveops');
  var el = panel(); if (el && !el._ro) { el._ro = true; el.addEventListener('click', onClick); el.addEventListener('change', onChange); el.addEventListener('submit', onSubmit); }
  var orig = window.switchPage;
  if (typeof orig === 'function' && !orig._ro) {
    window.switchPage = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'rideoffers') { var t = document.getElementById('page-title'); if (t) t.textContent = 'Special offers'; var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = ''; load(S.loaded); }
      return r;
    };
    window.switchPage._ro = true;
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
