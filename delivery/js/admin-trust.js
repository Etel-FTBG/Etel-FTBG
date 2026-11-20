/* ETEL Admin - Operations > Driver trust. Fake GPS: drivers whose phone said the position is mocked (fake GPS app) or whose position jumped farther than a
   vehicle can travel, with the owner's switch "Put a driver offline when a mock location is reported" (off by default) and a Clear button once support checked the phone.
   Documents: which drivers have an insurance, technical inspection or road fund that has expired or expires within
   14 days (they get a reminder push on their own), and the owner's switch "Block going online with an expired document" (off by default).
   Data: admin_driver_trust (api/controllers/TrustController.php, read only). The switch is saved with admin_hub_settings_save (owner admins only). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELTrust) return;
var esc = K.esc;
var S = { d: null, err: '', busy: false };
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function root() { return document.getElementById('panel-trust'); }
function css() {
  if (document.getElementById('tr-css')) return;
  var s = document.createElement('style'); s.id = 'tr-css';
  s.textContent = [
    '.tr-hero{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}.tr-note{font-size:12.5px;color:var(--mute);margin:0}',
    '.tr-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px}.tr-card h3{margin:0;font-size:16px}',
    '.tr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}.tr-tile{border:1px solid var(--line);border-radius:14px;padding:10px}.tr-tile .n{font-size:26px;font-weight:800}.tr-tile .l{font-size:12.5px;color:var(--mute)}.tr-tile.hot{border-color:#c53030}',
    '.tr-row{border-top:1px solid var(--line);padding:10px 0;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.tr-row b{font-size:14px}.tr-row:first-of-type{border-top:0}',
    '.tr-pill{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:99px;border:1px solid var(--line);margin:2px 4px 2px 0}.tr-pill.expired{border-color:#c53030;color:#c53030}.tr-pill.today,.tr-pill.soon{border-color:#c05621;color:#c05621}',
    '.tr-on{display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:99px;border:1px solid #2f855a;color:#2f855a}.tr-sw{display:flex;gap:8px;align-items:flex-start}'
  ].join('');
  document.head.appendChild(s);
}
function stateText(d) { return d.state === 'expired' ? 'expired ' + Math.abs(d.days_left) + (Math.abs(d.days_left) === 1 ? ' day ago' : ' days ago') : d.state === 'today' ? 'expires today' : 'expires in ' + d.days_left + (d.days_left === 1 ? ' day' : ' days'); }
function driverRow(x) {
  var docs = Object.keys(x.docs).map(function (k) { var d = x.docs[k]; return '<span class="tr-pill ' + esc(d.state) + '">' + esc(d.label) + ': ' + esc(stateText(d)) + ' (' + esc(d.expiry) + ')</span>'; }).join('');
  return '<div class="tr-row"><div><b>' + esc(x.name || 'Driver') + '</b>' + (x.online ? ' <span class="tr-on">online now</span>' : '') + '<div>' + docs + '</div></div>' +
    '<div>' + (x.phone ? '<a class="ak-btn" href="tel:' + esc(x.phone) + '">Call ' + esc(x.phone) + '</a>' : '') + '</div></div>';
}
function gpsRow(x) {
  var pills = (x.mocks ? '<span class="tr-pill expired">mock location x' + esc(x.mocks) + '</span>' : '') + (x.jumps ? '<span class="tr-pill soon">position jump x' + esc(x.jumps) + '</span>' : '');
  return '<div class="tr-row"><div><b>' + esc(x.name || 'Driver') + '</b>' + (x.online ? ' <span class="tr-on">online now</span>' : '') + '<div>' + pills + '</div>' +
    '<p class="tr-note">Last: ' + esc(x.last_kind === 'mock' ? 'mock location' : 'position jump') + ' at ' + esc(String(x.last_at).substring(0, 16)) + (x.last_detail ? ' - ' + esc(x.last_detail) : '') + '</p></div>' +
    '<div>' + (x.phone ? '<a class="ak-btn" href="tel:' + esc(x.phone) + '">Call ' + esc(x.phone) + '</a> ' : '') + '<button type="button" class="ak-btn" data-tr-clear="' + esc(x.user_id) + '" data-tr-name="' + esc(x.name) + '">Clear</button></div></div>';
}
function gpsCard() {
  var g = S.d.gps; if (!g) return '';
  var list = g.drivers.length ? g.drivers.map(gpsRow).join('') : '<p class="tr-note">No driver was flagged in the last 7 days.</p>';
  return '<div class="tr-card"><h3>Fake GPS</h3><div class="tr-grid"><div class="tr-tile' + (g.drivers.length ? ' hot' : '') + '"><div class="n">' + esc(g.drivers.length) + '</div><div class="l">Drivers flagged in the last 7 days</div></div></div>' +
    '<p class="tr-note"><b>Mock location</b>: the phone itself said the position comes from a fake GPS app (only seen while the ETEL phone app reports from the background). <b>Position jump</b>: the position moved farther than a vehicle can travel since the last report (seen for every app, but it can also be a bad GPS fix, so it only warns).</p>' +
    '<label class="tr-sw"><input type="checkbox" data-tr-gps ' + (g.block_fake ? 'checked' : '') + (S.busy ? ' disabled' : '') + '><span><b>Put a driver offline when a mock location is reported</b><br><span class="tr-note">When ON, a driver whose phone reports a mock location is put offline and cannot go online for ' + esc(g.block_hours) + ' hours (or until you press Clear). Position jumps never block. Only the owner can change this. Off by default: until you turn it on, sightings are only recorded here.</span></span></label></div>' +
    '<div class="tr-card"><h3>Flagged drivers</h3>' + list + '</div>';
}
function view() {
  css();
  if (S.err) return '<div class="ak">' + K.empty('!', S.err, '') + '</div>';
  if (!S.d) return '<div class="ak">' + K.skeleton(3) + '</div>';
  var d = S.d.documents;
  var list = d.drivers.length ? d.drivers.map(driverRow).join('') : '<p class="tr-note">No driver has a document that is expired or expires in the next 14 days.</p>';
  return '<div class="ak"><div class="tr-hero"><p class="tr-note">Updated ' + esc(String(S.d.generated_at).substring(11, 19)) + '. Drivers are reminded on their phone 14, 7, 3 and 1 days before, on the day, and once when it has expired.</p><button type="button" class="ak-btn" data-tr-refresh>Refresh</button></div>' +
    '<div class="tr-card"><h3>Driver documents</h3>' + (d.columns_ready ? '' : '<p class="tr-note">This server does not keep document dates yet (nobody has opened Documents in the driver app), so there is nothing to show.</p>') +
    '<div class="tr-grid"><div class="tr-tile' + (d.expired ? ' hot' : '') + '"><div class="n">' + esc(d.expired) + '</div><div class="l">Drivers with an expired document</div></div><div class="tr-tile"><div class="n">' + esc(d.soon) + '</div><div class="l">Drivers with a document expiring in 14 days</div></div></div>' +
    '<label class="tr-sw"><input type="checkbox" data-tr-block ' + (d.block_expired ? 'checked' : '') + (S.busy ? ' disabled' : '') + '><span><b>Block going online with an expired document</b><br><span class="tr-note">When ON, a driver whose insurance, technical inspection or road fund has expired cannot go online until the renewal is approved. Drivers already online are not switched off. Only the owner can change this. Off by default.</span></span></label></div>' +
    '<div class="tr-card"><h3>Who needs to renew</h3>' + list + '</div>' + gpsCard() + '</div>';
}
function paint() { var el = root(); if (el) el.innerHTML = view(); }
function load() {
  return run('admin_driver_trust', {}).then(function (r) {
    if (!ok(r)) S.err = K.failText(r, 'Could not load the driver checks.'); else { S.err = ''; S.d = dat(r); }
    paint();
  });
}
function setSwitch(key, on, onMsg, offMsg, ask) {
  var go = function () {
    S.busy = true; paint();
    var st = {}; st[key] = on ? '1' : '0';
    run('admin_hub_settings_save', { settings: st }).then(function (r) {
      S.busy = false;
      if (!ok(r)) { K.toast(K.failText(r, 'Could not save. Only the owner can change this.'), true); return load(); }
      K.toast(on ? onMsg : offMsg); return load();
    });
  };
  if (on) K.confirm(ask).then(function (yes) { if (yes) go(); else paint(); });
  else go();
}
function setBlock(on) {
  setSwitch('trust_block_expired_docs', on, 'Expired documents now block going online', 'Expired documents no longer block going online',
    { title: 'Block drivers with an expired document?', text: 'From now on a driver whose insurance, technical inspection or road fund has expired cannot go online until the renewal is approved. Drivers who are already online stay online. Make sure staff approve renewals quickly.', label: 'Turn it on' });
}
function setGps(on) {
  setSwitch('trust_block_fake_gps', on, 'A driver who reports a mock location is now put offline', 'Mock locations are only recorded now',
    { title: 'Put drivers offline when a mock location is reported?', text: 'A driver whose phone reports a mock location (a fake GPS app) will be put offline and cannot go online for a few hours. Some phones have developer options that look like this by mistake, so check the list first and be ready to press Clear for an honest driver.', label: 'Turn it on' });
}
function clearOne(id, name) {
  K.confirm({ title: 'Clear the flags of ' + (name || 'this driver') + '?', text: 'Do this after support has checked the phone. The driver can go online again at once, and the sightings are removed from the list.', label: 'Clear' }).then(function (yes) {
    if (!yes) return;
    run('admin_gps_clear', { driver_user_id: parseInt(id, 10) }).then(function (r) {
      if (!ok(r)) { K.toast(K.failText(r, 'Could not clear. Only the owner can do this.'), true); return; }
      K.toast('Cleared'); return load();
    });
  });
}
function onClick(e) {
  if (e.target.closest('[data-tr-refresh]')) { load(); return; }
  var c = e.target.closest('[data-tr-clear]'); if (c) clearOne(c.getAttribute('data-tr-clear'), c.getAttribute('data-tr-name'));
}
function onChange(e) {
  var c = e.target.closest('[data-tr-block]'); if (c) { setBlock(c.checked); return; }
  var g = e.target.closest('[data-tr-gps]'); if (g) setGps(g.checked);
}
function hook() {
  if (window.__trHooked || typeof window.switchPage !== 'function') return;
  window.__trHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page) {
    var r = orig.apply(this, arguments);
    if (page === 'trust') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = 'Driver trust';
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
      var el = root(); if (el && !el._tr) { el._tr = true; el.addEventListener('click', onClick); el.addEventListener('change', onChange); }
      load();
    }
    return r;
  };
}
function install() { K.ensurePanel('trust', 'trust', '🛡️', 'Driver trust', 'liveops'); hook(); window.ETELTrust = { refresh: load }; }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
