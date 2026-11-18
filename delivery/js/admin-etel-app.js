/* ETEL Admin - "ETEL app": which apps the ETEL phone app shows. The owner chooses for each app: Visible (everyone who may open it),
   Admins only (a test before launch) or Hidden. A coming-soon app (EtelPay, EtelMadeya, EtelGebeya) is switched on by typing its address.
   Data: admin_hub_apps_get, admin_hub_apps_save (api/controllers/HubAppsController.php). Owner admins only ("owner.only").
   The phone app asks again whenever it opens or is pulled down to refresh. Built on AdminKit. English only (Amharic not translated yet). */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELHubApps) return;
var esc = K.esc;
var D = { caps: [], apps: null, host: '', err: '', draft: {}, tab: 'apps' };
var MODES = [['on', 'Visible'], ['admins', 'Admins only'], ['off', 'Hidden']];
var FMODES = [['on', 'On'], ['admins', 'Admins only'], ['off', 'Off']];                 // a feature switch (no address)
var FLABEL = { on: 'On', admins: 'Admins only', off: 'Off' };
var TONE = { on: 'green', admins: 'amber', off: '' };
function can(c) { return D.caps.indexOf('*') !== -1 || D.caps.indexOf(c) !== -1; }
function root() { return document.getElementById('panel-etelapp'); }
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function css() {
  K.css();
  if (document.getElementById('ha-css')) return;
  var s = document.createElement('style'); s.id = 'ha-css';
  s.textContent = [
    '.ha-hero{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:18px;border-radius:18px;margin:0 0 14px;color:#f6e7b0;background:linear-gradient(135deg,#0b1b4d,#1a2f6e 60%,#3a2b0a)}',
    '.ha-hero h2{margin:0 0 4px;font-size:19px;color:#f6e7b0!important;-webkit-text-fill-color:#f6e7b0!important;background:none!important}.ha-hero p{margin:0;font-size:13px;color:#c9d3e8!important;-webkit-text-fill-color:#c9d3e8!important}',
    '.ha-grp{margin:18px 0 8px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mute)}',
    '.ha-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}',
    '.ha-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:10px}',
    '.ha-card h3{margin:0;font-size:17px}.ha-meta{font-size:12.5px;color:var(--mute)}',
    '.ha-pill{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;border:1px solid var(--line);background:var(--soft)}',
    '.ha-pill.green{color:#0b6b33;border-color:rgba(11,107,51,.5)}.ha-pill.amber{color:#8a4b00;border-color:rgba(138,75,0,.5)}',
    '.ha-card .ak-seg{align-self:flex-start}.ha-note{font-size:12.5px;color:var(--mute);margin:0}',
    '.ha-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}'
  ].join('');
  document.head.appendChild(s);
}
function cur(a) { var d = D.draft[a.key]; return { mode: d && d.mode ? d.mode : a.mode, url: d && d.url !== undefined ? d.url : a.url }; }
function dirty(a) { var c = cur(a); return c.mode !== a.mode || c.url !== a.url; }
function card(a) {
  var c = cur(a), edit = can('owner.only'), feat = a.kind === 'feature';
  var h = '<div class="ha-card" data-key="' + esc(a.key) + '"><div class="ha-row"><h3>' + esc(a.title) + '</h3><span class="ha-pill ' + TONE[a.mode] + '">' +
    esc(feat ? FLABEL[a.mode] : (a.mode === 'on' ? 'Visible' : (a.mode === 'admins' ? 'Admins only' : 'Hidden'))) + '</span></div>' +
    '<div class="ha-meta">' + (feat ? 'Works for: ' : 'Opens for: ') + esc(a.who) + (a.changed ? '' : ' - default setting') + '</div>' +
    '<div class="ak-seg" role="radiogroup" aria-label="' + esc(a.title) + (feat ? ' switch' : ' visibility') + '">' + (feat ? FMODES : MODES).map(function (m) {
      return '<button type="button" role="radio" aria-checked="' + (c.mode === m[0]) + '" class="' + (c.mode === m[0] ? 'on' : '') + '" data-mode="' + m[0] + '"' + (edit ? '' : ' disabled') + '>' + esc(m[1]) + '</button>'; }).join('') + '</div>';
  var builtIn = a.default_path ? 'https://' + (D.host || 'ethelmarket.com') + a.default_path : '';
  if (!feat) h += K.field('Address of the app (on ' + (D.host || 'this website') + ')', '<input class="ak-in" data-url inputmode="url" autocomplete="off" placeholder="' + esc(builtIn || ('https://' + (D.host || 'ethelmarket.com') + '/...')) + '" value="' + esc(c.url) + '"' + (edit ? '' : ' disabled') + '>',
    a.needs_url ? 'This app has no built-in address yet. Type it here, then choose Visible or Admins only.' : 'Leave empty to use the built-in address (' + builtIn + '). Type another address only to move the app.');
  if (edit) h += '<div class="ha-row"><span class="ha-note">' + (dirty(a) ? 'Not saved yet.' : '') + '</span><button type="button" class="ak-btn gold" data-save' + (dirty(a) ? '' : ' disabled') + '>Save</button></div>';
  return h + '</div>';
}
function view() {
  if (D.err) return '<div class="ak">' + K.empty('⚠️', D.err, '') + '</div>';
  if (!D.apps) return '<div class="ak">' + K.skeleton(4) + '</div>';
  var tabBar = '<div class="ak-seg" role="tablist" style="margin:0 0 14px">' + [['apps', 'Switches'], ['look', 'Names, icons and texts']].map(function (t) {
    return '<button type="button" role="tab" aria-selected="' + (D.tab === t[0]) + '" class="' + (D.tab === t[0] ? 'on' : '') + '" data-ha-tab="' + t[0] + '">' + esc(t[1]) + '</button>'; }).join('') + '</div>';
  if (D.tab === 'look') {
    return '<div class="ak"><div class="ha-hero"><div><h2>ETEL app</h2><p>Change the names, short descriptions, icons and home texts the phones show. Phones get the change when the app opens or is pulled down to refresh.</p></div></div>' + tabBar +
      (window.ETELHubLook ? window.ETELHubLook.view(D.apps) : K.skeleton(3)) + '</div>';
  }
  var h = '<div class="ak"><div class="ha-hero"><div><h2>ETEL app</h2><p>Choose which apps the ETEL phone app shows. Phones check again when the app opens or is pulled down to refresh.</p></div></div>' + tabBar +
    '<p class="ha-note">The <b>Features</b> at the end switch parts of the phone app on or off: the floating golden light, and its sound and vibration. <b>Visible</b> = everyone who is allowed to open the app sees it. <b>Admins only</b> = only admin accounts see it (test before you launch). <b>Hidden</b> = nobody sees it. ' +
    'Even when Visible, the app still checks the person\'s role (for example Driver or Courier) and programme (Investor, Equb, Agent), so a customer never sees the Agent app.</p>';
  var groups = [], by = {};
  D.apps.forEach(function (a) { if (!by[a.group]) { by[a.group] = []; groups.push(a.group); } by[a.group].push(a); });
  groups.forEach(function (g) { h += '<div class="ha-grp">' + esc(g) + '</div><div class="ha-grid">' + by[g].map(card).join('') + '</div>'; });
  return h + '</div>';
}
function paint() { var el = root(); if (!el) return; css(); el.innerHTML = view(); }
function load() {
  D.err = ''; D.draft = {};
  return run('admin_hub_apps_get').then(function (r) {
    if (!ok(r)) { D.err = K.failText(r, 'Could not load the apps.'); D.apps = null; }
    else { D.apps = dat(r).apps || []; D.host = dat(r).host || ''; }
    paint();
  });
}
function byKey(k) { return (D.apps || []).filter(function (a) { return a.key === k; })[0]; }
function save(a, btn) {
  var c = cur(a), turningOn = c.mode === 'on' && a.mode !== 'on' && a.kind !== 'feature';
  var go = function () {
    btn.disabled = true;
    run('admin_hub_apps_save', { key: a.key, mode: c.mode, url: c.url }).then(function (r) {
      if (!ok(r)) { btn.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
      K.toast(a.title + ': saved'); delete D.draft[a.key]; return load();
    });
  };
  if (turningOn) K.confirm({ title: 'Show ' + a.title + ' to everyone?', text: 'Everyone who is allowed to open it will see it in the ETEL app the next time the app opens or refreshes.', label: 'Show it' }).then(function (res) { if (res) go(); });
  else go();
}
function onClick(e) {
  var tb = e.target.closest('[data-ha-tab]');
  if (tb) { D.tab = tb.getAttribute('data-ha-tab'); if (D.tab === 'look' && window.ETELHubLook) window.ETELHubLook.open(); paint(); return; }
  var cardEl = e.target.closest('.ha-card'); if (!cardEl) return;
  var a = byKey(cardEl.getAttribute('data-key')); if (!a) return;
  var m = e.target.closest('[data-mode]');
  if (m && !m.disabled) { var d = D.draft[a.key] || (D.draft[a.key] = {}); d.mode = m.getAttribute('data-mode'); var u = cardEl.querySelector('[data-url]'); if (u) d.url = u.value.trim(); paint(); return; }
  var s = e.target.closest('[data-save]'); if (s && !s.disabled) save(a, s);
}
function onInput(e) {
  var u = e.target.closest('[data-url]'); if (!u) return;
  var cardEl = u.closest('.ha-card'), a = byKey(cardEl.getAttribute('data-key')); if (!a) return;
  var d = D.draft[a.key] || (D.draft[a.key] = {}); d.url = u.value.trim();
  var sv = cardEl.querySelector('[data-save]'); if (sv) sv.disabled = !dirty(a);
  var note = cardEl.querySelector('.ha-note'); if (note) note.textContent = dirty(a) ? 'Not saved yet.' : '';
}
function open() {
  var el = root(); if (!el) return; css();
  if (!el._ha) { el._ha = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); }
  el.innerHTML = '<div class="ak">' + K.skeleton(3) + '</div>';
  var caps = window.ETEL_CAPS ? Promise.resolve(window.ETEL_CAPS) : (window.ETEL_CAPS_PROMISE || Promise.resolve([]));
  caps.then(function (c) {
    D.caps = c || [];
    if (!can('owner.only')) { el.innerHTML = '<div class="ak">' + K.empty('🔒', 'Only the owner can change the ETEL app', 'This page decides what every phone shows.') + '</div>'; return; }
    return load();
  });
}
function hook() {
  if (window.__haHooked || typeof window.switchPage !== 'function') return;
  window.__haHooked = true;
  var orig = window.switchPage;
  window.switchPage = function (page) {
    var r = orig.apply(this, arguments);
    if (page === 'etelapp') {
      var ttl = document.getElementById('page-title'); if (ttl) ttl.textContent = 'ETEL app';
      var ab = document.getElementById('action-buttons'); if (ab) ab.innerHTML = '';
      open();
    }
    return r;
  };
}
function install() {
  K.ensurePanel('etelapp', 'etelapp', '📱', 'ETEL app', 'releases');
  hook();
  window.ETELHubApps = { refresh: load, repaint: paint, can: can };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
