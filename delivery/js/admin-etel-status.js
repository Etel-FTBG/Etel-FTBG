/* ETEL Admin - "ETEL app" > Maintenance and updates. The owner (1) puts the phone app in maintenance (everybody except admin accounts sees a "we are back soon"
   page) and (2) sets the oldest app version that may still be used (older phones see an "Update ETEL" page with a button to the update address).
   Data: admin_hub_content_get (settings) and admin_hub_settings_save (api/controllers/HubAppsController.php); phones read app_status. Owner admins only.
   Needs js/admin-etel-app.js. Texts are plain text; the update address must be Google Play, Expo or this website. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELHubStatus) return;
var esc = K.esc;
var S = { settings: null, err: '', draft: {} };
var KEYS = ['maint_enabled', 'maint_text_en', 'maint_text_am', 'min_version_android', 'update_text_en', 'update_text_am', 'update_url_android'];
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function repaint() { if (window.ETELHubApps) window.ETELHubApps.repaint(); }
function css() {
  if (document.getElementById('hs-css')) return;
  var s = document.createElement('style'); s.id = 'hs-css';
  s.textContent = [
    '.hs-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px;display:flex;flex-direction:column;gap:8px}',
    '.hs-card h3{margin:0;font-size:16px}.hs-note{font-size:12.5px;color:var(--mute);margin:0}.hs-lbl{font-size:12px;color:var(--mute);display:block;margin-bottom:2px}',
    '.hs-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}@media(max-width:560px){.hs-two{grid-template-columns:1fr}}',
    '.hs-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}',
    '.hs-on{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;border:1px solid #c05621;color:#c05621}.hs-off{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;border:1px solid var(--line);color:var(--mute)}'
  ].join('');
  document.head.appendChild(s);
}
function base(k) { return (S.settings && S.settings[k]) || ''; }
function cur(k) { return S.draft[k] !== undefined ? S.draft[k] : base(k); }
function dirty(keys) { return keys.some(function (k) { return S.draft[k] !== undefined && S.draft[k] !== base(k); }); }
var G1 = KEYS.slice(0, 3), G2 = KEYS.slice(3);
function field(k, label, ph, max) {
  return '<div><label class="hs-lbl">' + esc(label) + '</label><input class="ak-in" data-hs-f="' + k + '" maxlength="' + (max || 160) + '" value="' + esc(cur(k)) + '" placeholder="' + esc(ph || '') + '"></div>';
}
function view() {
  css();
  if (S.err) return K.empty('!', S.err, '');
  if (!S.settings) return K.skeleton(3);
  var on = cur('maint_enabled') === '1';
  return '<div class="hs-card" data-hs-group="maint"><div class="hs-row"><h3>Maintenance mode</h3><span class="' + (on ? 'hs-on' : 'hs-off') + '">' + (on ? 'ON - phones are blocked' : 'Off') + '</span></div>' +
    '<p class="hs-note">When it is ON every phone shows a "we are back soon" page instead of the app. Admin accounts can still sign in and use the app, so you can test. Phones check when the app opens and when it comes back to the front.</p>' +
    '<label class="hs-lbl"><input type="checkbox" data-hs-f="maint_enabled" data-hs-flag ' + (on ? 'checked' : '') + '> Maintenance mode is ON</label>' +
    '<div class="hs-two">' + field('maint_text_en', 'Message (English)', 'We are improving ETEL. Back soon.') + field('maint_text_am', 'Message (Amharic)', '') + '</div>' +
    '<div class="hs-row"><span class="hs-note">' + (dirty(G1) ? 'Not saved yet.' : '') + '</span><button type="button" class="ak-btn gold" data-hs-save="maint"' + (dirty(G1) ? '' : ' disabled') + '>Save</button></div></div>' +
    '<div class="hs-card" data-hs-group="upd"><div class="hs-row"><h3>Minimum app version</h3><span class="' + (base('min_version_android') ? 'hs-on' : 'hs-off') + '">' + (base('min_version_android') ? 'Minimum ' + esc(base('min_version_android')) : 'Not set') + '</span></div>' +
    '<p class="hs-note">A phone with an older version sees an "Update ETEL" page and cannot use the app until it updates. Leave empty to let every version in. Set it only AFTER the new app is on Google Play (or the new APK link works), or people cannot update.</p>' +
    '<div class="hs-two">' + field('min_version_android', 'Oldest allowed version (for example 1.8.0)', '1.8.0', 12) + field('update_url_android', 'Update address (Google Play or Expo link)', 'https://play.google.com/store/apps/details?id=com.etelgroup.driver', 255) + '</div>' +
    '<div class="hs-two">' + field('update_text_en', 'Message (English)', 'Please update ETEL to keep using it.') + field('update_text_am', 'Message (Amharic)', '') + '</div>' +
    '<div class="hs-row"><span class="hs-note">' + (dirty(G2) ? 'Not saved yet.' : '') + '</span><button type="button" class="ak-btn gold" data-hs-save="upd"' + (dirty(G2) ? '' : ' disabled') + '>Save</button></div></div>';
}
function load() {
  return run('admin_hub_content_get', {}).then(function (r) {
    if (!ok(r)) { S.err = K.failText(r, 'Could not load.'); S.settings = null; }
    else { S.err = ''; S.settings = dat(r).settings || {}; S.draft = {}; }
    repaint();
  });
}
function onInput(e) {
  var f = e.target.closest('[data-hs-f]'); if (!f) return;
  var k = f.getAttribute('data-hs-f'); S.draft[k] = f.hasAttribute('data-hs-flag') ? (f.checked ? '1' : '0') : f.value;
  var card = f.closest('[data-hs-group]'), g = card.getAttribute('data-hs-group') === 'maint' ? G1 : G2;
  var sv = card.querySelector('[data-hs-save]'); if (sv) sv.disabled = !dirty(g);   // typing never rebuilds the boxes
  if (f.hasAttribute('data-hs-flag')) repaint();
}
function save(group, btn) {
  var keys = group === 'maint' ? G1 : G2, send = {};
  keys.forEach(function (k) { if (S.draft[k] !== undefined && S.draft[k] !== base(k)) send[k] = S.draft[k]; });
  if (!Object.keys(send).length) return;
  var go = function () { btn.disabled = true; run('admin_hub_settings_save', { settings: send }).then(function (r) {
    if (!ok(r)) { btn.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
    K.toast('Saved'); return load(); }); };
  if (send.maint_enabled === '1') K.confirm({ title: 'Turn maintenance ON?', text: 'Every phone shows the maintenance page the next time the app opens or comes to the front. Admin accounts can still use the app.', label: 'Turn it on' }).then(function (res) { if (res) go(); });
  else if (send.min_version_android) K.confirm({ title: 'Block versions older than ' + send.min_version_android + '?', text: 'Phones with an older version cannot use the app until they update. Is the new version already on Google Play (or the update address works)?', label: 'Save the minimum' }).then(function (res) { if (res) go(); });
  else go();
}
function onClick(e) { var sv = e.target.closest('[data-hs-save]'); if (sv && !sv.disabled) save(sv.getAttribute('data-hs-save'), sv); }
function open() {
  var el = document.getElementById('panel-etelapp'); if (!el) return;
  if (!el._hs) { el._hs = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); el.addEventListener('change', onInput); }
  return load();
}
window.ETELHubStatus = { view: view, open: open };
})();
