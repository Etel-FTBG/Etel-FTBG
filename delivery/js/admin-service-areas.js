/* ETEL Admin - Service areas. The cities Etel serves. A person in Shashemene only sees Shashemene shops, drivers and map results; a person in
   Hawassa only sees Hawassa. Each city is a centre point plus a radius. Built on AdminKit. Data: admin_areas_* in api/controllers/ServiceAreaController.php.
   Capabilities: settings.view (read), settings.manage (change). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELAreas) return;
var esc = K.esc;
var D = { caps: [], areas: null, enforced: true, edit: null };
function can(c) { return D.caps.indexOf('*') !== -1 || D.caps.indexOf(c) !== -1; }
function root() { return document.getElementById('panel-serviceareas'); }
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function num(v, d) { var n = parseFloat(v); return isFinite(n) ? n : d; }

function css() {
  K.css();
  if (document.getElementById('sa-css')) return;
  var s = document.createElement('style'); s.id = 'sa-css';
  s.textContent = [
    '.sa-hero{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:18px;border-radius:18px;margin:0 0 14px;color:#f6e7b0;background:linear-gradient(135deg,#0b1a33,#13294d 60%,#1b3563);border:1px solid rgba(201,162,39,.5)}',
    '.sa-hero h2{margin:0 0 4px;font-size:19px;color:#f6e7b0}.sa-hero p{margin:0;font-size:13px;color:#c9d3e8;max-width:56ch}',
    '.sa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}',
    '.sa-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:10px}',
    '.sa-card.off{opacity:.65}.sa-card h3{margin:0;font-size:17px}.sa-card .am{font-size:13px;color:var(--mute)}',
    '.sa-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.sa-stats div{background:var(--soft);border-radius:12px;padding:8px 6px;text-align:center}',
    '.sa-stats b{display:block;font-size:18px}.sa-stats small{font-size:11px;color:var(--mute)}',
    '.sa-meta{font-size:12.5px;color:var(--mute)}',
    '.sa-pill{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;border:1px solid var(--line);background:var(--soft)}',
    '.sa-pill.on{color:var(--green);border-color:var(--green)}.sa-pill.off{color:var(--amber);border-color:var(--amber)}',
    '.sa-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.sa-form .full{grid-column:1/-1}',
    '.sa-form input{width:100%;min-height:44px;padding:0 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font:inherit}',
    '.sa-acts{display:flex;gap:8px;flex-wrap:wrap}',
    '@media(max-width:600px){.sa-form{grid-template-columns:1fr}}'
  ].join('\n');
  document.head.appendChild(s);
}

function cardHtml(a) {
  return '<div class="sa-card' + (a.active ? '' : ' off') + '"><div><h3>' + esc(a.name) + ' <span class="sa-pill ' + (a.active ? 'on' : 'off') + '">' + (a.active ? 'Serving' : 'Paused') + '</span></h3>' +
    (a.name_am ? '<div class="am">' + esc(a.name_am) + '</div>' : '') + '</div>' +
    '<div class="sa-stats"><div><b>' + a.shops + '</b><small>Shops</small></div><div><b>' + a.drivers_online + '</b><small>Drivers online</small></div><div><b>' + a.rides_7d + '</b><small>Rides, 7 days</small></div></div>' +
    '<div class="sa-meta">Centre ' + a.lat.toFixed(4) + ', ' + a.lng.toFixed(4) + ' &middot; radius ' + a.radius_km + ' km</div>' +
    (can('settings.manage') ? '<div class="sa-acts"><button class="ak-btn" data-sa-edit="' + a.id + '">Edit</button><button class="ak-btn" data-sa-del="' + a.id + '">Delete</button></div>' : '') + '</div>';
}
function formHtml(a) {
  var n = !a.id;
  return '<div class="ak-box sa-card"><h3>' + (n ? 'Add a city' : 'Edit ' + esc(a.name)) + '</h3><div class="sa-form">' +
    K.field('City name (English)', '<input id="sa-name" value="' + esc(a.name || '') + '" maxlength="80" placeholder="Shashemene">') +
    K.field('City name (Amharic)', '<input id="sa-nameam" value="' + esc(a.name_am || '') + '" maxlength="80">') +
    K.field('Centre latitude', '<input id="sa-lat" inputmode="decimal" value="' + esc(a.lat != null ? a.lat : '') + '" placeholder="7.2000">') +
    K.field('Centre longitude', '<input id="sa-lng" inputmode="decimal" value="' + esc(a.lng != null ? a.lng : '') + '" placeholder="38.5900">') +
    K.field('Radius (km)', '<input id="sa-rad" inputmode="decimal" value="' + esc(a.radius_km != null ? a.radius_km : 10) + '">', 'How far from the centre the city is served. 1 to 60 km.') +
    '<div class="full">' + K.sw('sa-active', 'Serving this city', a.active !== false) + '</div></div>' +
    '<div class="sa-acts" style="margin-top:12px"><button class="ak-btn gold" data-sa-save="1">Save</button><button class="ak-btn" data-sa-here="1">Use my location as the centre</button><button class="ak-btn" data-sa-cancel="1">Cancel</button></div></div>';
}
function paint() {
  var el = root(); if (!el) return;
  var h = '<div class="ak"><div class="sa-hero"><div><h2>Service areas</h2><p>Each person only sees the shops, drivers and map results of the city they are in. Add a city here and it starts working at once.</p></div>' +
    (can('settings.manage') ? K.sw('sa-enforce', 'Keep people inside their city', D.enforced) : '') + '</div>';
  if (D.edit) h += formHtml(D.edit);
  if (!D.areas.length) h += K.empty('📍', 'No cities yet', 'Add your first city.');
  else h += '<div class="sa-grid">' + D.areas.map(cardHtml).join('') + '</div>';
  if (can('settings.manage') && !D.edit) h += '<div style="margin-top:14px"><button class="ak-btn gold" data-sa-add="1">+ Add a city</button></div>';
  el.innerHTML = h + '</div>';
}
function reload() {
  return run('admin_areas_list').then(function (r) {
    if (!ok(r)) { root().innerHTML = '<div class="ak">' + K.empty('⚠️', 'Could not load the cities', K.failText(r, 'Please try again.')) + '</div>'; return; }
    var d = dat(r); D.areas = d.areas || []; D.enforced = d.switch_on !== false; paint();
  });
}
function onClick(e) {
  var t = e.target, b;
  if ((b = t.closest('[data-sa-add]'))) { D.edit = { active: true, radius_km: 10 }; paint(); return; }
  if ((b = t.closest('[data-sa-edit]'))) { var id = parseInt(b.getAttribute('data-sa-edit'), 10); D.edit = D.areas.filter(function (a) { return a.id === id; })[0] || null; paint(); return; }
  if (t.closest('[data-sa-cancel]')) { D.edit = null; paint(); return; }
  if ((b = t.closest('[data-sa-del]'))) {
    var did = parseInt(b.getAttribute('data-sa-del'), 10);
    K.confirm({ title: 'Delete this city?', text: 'People inside it will no longer be able to book or order until you add it again.', label: 'Delete', danger: true }).then(function (res) {
      if (res) run('admin_area_delete', { id: did }).then(function (r) { K.toast(ok(r) ? 'Deleted' : K.failText(r, 'Could not delete.'), !ok(r)); reload(); });
    });
    return;
  }
  if (t.closest('[data-sa-here]')) {
    if (!navigator.geolocation) { K.toast('This device cannot share its location.', true); return; }
    navigator.geolocation.getCurrentPosition(function (p) { document.getElementById('sa-lat').value = p.coords.latitude.toFixed(5); document.getElementById('sa-lng').value = p.coords.longitude.toFixed(5); }, function () { K.toast('Allow location access to use this.', true); }, { timeout: 9000 });
    return;
  }
  if (t.closest('[data-sa-save]')) {
    var body = { id: D.edit && D.edit.id || 0, name: document.getElementById('sa-name').value, name_am: document.getElementById('sa-nameam').value,
      lat: num(document.getElementById('sa-lat').value, 0), lng: num(document.getElementById('sa-lng').value, 0), radius_km: num(document.getElementById('sa-rad').value, 0),
      active: document.getElementById('sa-active').checked ? 1 : 0, sort_order: D.edit && D.edit.sort_order || 0 };
    run('admin_area_save', body).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); return; } K.toast('Saved'); D.edit = null; reload(); });
  }
}
function onChange(e) {
  if (e.target && e.target.id === 'sa-enforce') {
    var on = e.target.checked;
    run('admin_areas_settings_save', { enforce: on ? 1 : 0 }).then(function (r) { if (!ok(r)) { K.toast(K.failText(r, 'Could not save.'), true); e.target.checked = !on; return; } D.enforced = on; K.toast(on ? 'People are kept inside their city' : 'City limits are off'); });
  }
}
function load() {
  var el = root(); if (!el) return; css();
  if (!el._sa) { el._sa = true; el.addEventListener('click', onClick); el.addEventListener('change', onChange); }
  el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  var caps = window.ETEL_CAPS ? Promise.resolve(window.ETEL_CAPS) : (window.ETEL_CAPS_PROMISE || Promise.resolve([]));
  caps.then(function (c) {
    D.caps = c || [];
    if (!can('settings.view')) { el.innerHTML = '<div class="ak">' + K.empty('🔒', 'You do not have access to Service areas', 'Ask the owner to give your role the "settings.view" permission.') + '</div>'; return; }
    D.edit = null; return reload();
  });
}
function hook() {
  if (window.__saHooked || typeof window.switchPage !== 'function') return;
  window.__saHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page) {
    var r = orig.apply(this, arguments);
    if (page === 'serviceareas') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = 'Service areas';
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
      load();
    }
    return r;
  };
}
function install() {
  K.ensurePanel('serviceareas', 'serviceareas', '📍', 'Service areas', 'settings');
  hook();
  window.ETELAreas = { refresh: reload };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
