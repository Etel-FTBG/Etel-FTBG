/* ETEL Admin - "ETEL app" > Names, icons and texts. The owner changes, for every app: its name and short description (English and Amharic), a small
   label (New, Beta, Soon), its place in the list and its icon picture (upload); and for the whole phone app: the welcome title, the tagline, the
   support phone / Telegram / email and one home banner (picture, text, link, dates, who sees it).
   Data: admin_hub_content_get / admin_hub_content_save / admin_hub_settings_save (api/controllers/HubAppsController.php); pictures go through api/upload.php
   (type hub_icon / hub_banner, admin only). Needs js/admin-etel-app.js. Owner admins only. Amharic text is typed by the owner: please have it read by a native speaker. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K || window.ETELHubLook) return;
var esc = K.esc;
var L = { content: null, settings: null, host: '', err: '', draft: {}, sdraft: {}, busy: false };
function run(a, b) { return K.api(a, b || {}); }
function ok(r) { return K.okRes(r); }
function dat(r) { return K.data(r); }
function repaint() { if (window.ETELHubApps) window.ETELHubApps.repaint(); }
var APPFIELDS = ['title_en', 'title_am', 'desc_en', 'desc_am', 'badge', 'sort_order', 'icon_url'];
var SETFIELDS = ['welcome_title_en', 'welcome_title_am', 'tagline_en', 'tagline_am', 'support_phone', 'support_telegram', 'support_email',
  'banner_enabled', 'banner_title_en', 'banner_title_am', 'banner_text_en', 'banner_text_am', 'banner_image_url', 'banner_link_app', 'banner_audience', 'banner_starts_at', 'banner_ends_at'];

function css() {
  if (document.getElementById('hl-css')) return;
  var s = document.createElement('style'); s.id = 'hl-css';
  s.textContent = [
    '.hl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px}',
    '.hl-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px;display:flex;flex-direction:column;gap:8px}',
    '.hl-card h3{margin:0;font-size:16px}.hl-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}.hl-lbl{font-size:12px;color:var(--mute);margin-bottom:2px;display:block}',
    '.hl-prev{display:flex;gap:10px;align-items:center;padding:10px;border-radius:14px;border:1px dashed var(--line);background:var(--soft)}',
    '.hl-ico{width:52px;height:52px;border-radius:14px;object-fit:cover;background:#0a0703;color:#D4AF37;display:flex;align-items:center;justify-content:center;font-weight:800;flex:none;overflow:hidden}',
    '.hl-ico img{width:100%;height:100%;object-fit:cover}.hl-t{font-weight:700;font-size:14px}.hl-d{font-size:12px;color:var(--mute)}',
    '.hl-badge{display:inline-block;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:99px;border:1px solid #B8962E;color:#7A5A00;margin-left:6px}',
    '.hl-sec{margin:20px 0 8px;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--mute)}',
    '.hl-row{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.hl-note{font-size:12.5px;color:var(--mute);margin:0}',
    '.hl-file{font-size:12px}.hl-banner{padding:14px;border-radius:16px;background:linear-gradient(135deg,#0b1b4d,#3a2b0a);color:#f6e7b0}.hl-banner b{display:block;font-size:15px}'
  ].join('');
  document.head.appendChild(s);
}
function cur(key) {
  var base = (L.content && L.content[key]) || { title_en: '', title_am: '', desc_en: '', desc_am: '', badge: '', sort_order: 0, icon_url: '' };
  var d = L.draft[key] || {}, o = {};
  APPFIELDS.forEach(function (f) { o[f] = d[f] !== undefined ? d[f] : base[f]; });
  return o;
}
function changed(key) {
  var c = cur(key), b = (L.content && L.content[key]) || {};
  return APPFIELDS.some(function (f) { return String(c[f] == null ? '' : c[f]) !== String(b[f] == null ? (f === 'sort_order' ? 0 : '') : b[f]); });
}
function sval(k) { return L.sdraft[k] !== undefined ? L.sdraft[k] : ((L.settings && L.settings[k] !== undefined) ? L.settings[k] : ''); }
function inp(attr, val, ph, extra) { return '<input class="ak-in" ' + attr + ' value="' + esc(val) + '" placeholder="' + esc(ph || '') + '" ' + (extra || '') + '>'; }

function preview(a, c) {
  var title = c.title_en || a.title;
  return '<div class="hl-prev"><div class="hl-ico">' + (c.icon_url ? '<img src="' + esc(c.icon_url) + '" alt="">' : esc(title.charAt(0))) + '</div><div><div class="hl-t">' + esc(title) +
    (c.badge ? '<span class="hl-badge">' + esc(c.badge) + '</span>' : '') + '</div><div class="hl-d">' + esc(c.desc_en || '(built-in description)') + '</div>' +
    (c.title_am || c.desc_am ? '<div class="hl-d">' + esc(c.title_am) + (c.desc_am ? ' - ' + esc(c.desc_am) : '') + '</div>' : '') + '</div></div>';
}
function appCard(a) {
  var c = cur(a.key), dirty = changed(a.key);
  return '<div class="hl-card" data-hl-key="' + esc(a.key) + '"><h3>' + esc(a.title) + '</h3>' + preview(a, c) +
    '<div class="hl-two"><div><span class="hl-lbl">Name (English)</span>' + inp('data-hl-f="title_en" maxlength="40"', c.title_en, a.title) + '</div><div><span class="hl-lbl">Name (Amharic)</span>' + inp('data-hl-f="title_am" maxlength="40"', c.title_am, '') + '</div></div>' +
    '<div><span class="hl-lbl">Short description (English)</span>' + inp('data-hl-f="desc_en" maxlength="80"', c.desc_en, 'for example: Book a ride') + '</div>' +
    '<div><span class="hl-lbl">Short description (Amharic)</span>' + inp('data-hl-f="desc_am" maxlength="80"', c.desc_am, '') + '</div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Small label (New, Beta, Soon)</span>' + inp('data-hl-f="badge" maxlength="14"', c.badge, '') + '</div><div><span class="hl-lbl">Place in the list (0 = built-in)</span>' + inp('data-hl-f="sort_order" inputmode="numeric" maxlength="2"', c.sort_order || 0, '0') + '</div></div>' +
    '<div class="hl-row"><label class="ak-btn hl-file">Upload icon<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-hl-file="icon" style="display:none"></label>' +
    (c.icon_url ? '<button type="button" class="ak-btn" data-hl-clear="icon">Remove icon</button>' : '<span class="hl-note">Square picture, at least 128 px, up to 1 MB.</span>') + '</div>' +
    '<div class="hl-row"><span class="hl-note">' + (dirty ? 'Not saved yet.' : '') + '</span><button type="button" class="ak-btn gold" data-hl-save' + (dirty ? '' : ' disabled') + '>Save</button></div></div>';
}
function textsCard() {
  var dirty = SETFIELDS.slice(0, 7).some(function (k) { return L.sdraft[k] !== undefined && L.sdraft[k] !== ((L.settings && L.settings[k]) || ''); });
  return '<div class="hl-card" data-hl-sets="home"><div class="hl-two"><div><span class="hl-lbl">Welcome title (English)</span>' + inp('data-hl-s="welcome_title_en" maxlength="60"', sval('welcome_title_en'), 'built-in') + '</div><div><span class="hl-lbl">Welcome title (Amharic)</span>' + inp('data-hl-s="welcome_title_am" maxlength="60"', sval('welcome_title_am'), '') + '</div></div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Tagline (English)</span>' + inp('data-hl-s="tagline_en" maxlength="80"', sval('tagline_en'), 'One account. One ecosystem.') + '</div><div><span class="hl-lbl">Tagline (Amharic)</span>' + inp('data-hl-s="tagline_am" maxlength="80"', sval('tagline_am'), '') + '</div></div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Support phone</span>' + inp('data-hl-s="support_phone" inputmode="tel" maxlength="20"', sval('support_phone'), '+251911234567') + '</div><div><span class="hl-lbl">Support Telegram</span>' + inp('data-hl-s="support_telegram" maxlength="60"', sval('support_telegram'), '@etelsupport') + '</div></div>' +
    '<div><span class="hl-lbl">Support email</span>' + inp('data-hl-s="support_email" inputmode="email" maxlength="120"', sval('support_email'), 'support@ethelmarket.com') + '</div>' +
    '<div class="hl-row"><span class="hl-note">' + (dirty ? 'Not saved yet.' : 'Empty = the built-in text. Phones show the support contacts on the Help screen.') + '</span><button type="button" class="ak-btn gold" data-hl-saveset="home"' + (dirty ? '' : ' disabled') + '>Save</button></div></div>';
}
function bannerCard() {
  var on = sval('banner_enabled') === '1';
  var dirty = SETFIELDS.slice(7).some(function (k) { return L.sdraft[k] !== undefined && L.sdraft[k] !== ((L.settings && L.settings[k]) || ''); });
  var apps = (window.ETELHubApps && L.appsList) || [];
  var img = sval('banner_image_url');
  return '<div class="hl-card" data-hl-sets="banner"><label class="ak-sw"><input type="checkbox" data-hl-s="banner_enabled" data-hl-flag' + (on ? ' checked' : '') + '><span>Show the banner on the home screen</span></label>' +
    '<div class="hl-two"><div><span class="hl-lbl">Title (English)</span>' + inp('data-hl-s="banner_title_en" maxlength="60"', sval('banner_title_en'), '') + '</div><div><span class="hl-lbl">Title (Amharic)</span>' + inp('data-hl-s="banner_title_am" maxlength="60"', sval('banner_title_am'), '') + '</div></div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Text (English)</span>' + inp('data-hl-s="banner_text_en" maxlength="160"', sval('banner_text_en'), '') + '</div><div><span class="hl-lbl">Text (Amharic)</span>' + inp('data-hl-s="banner_text_am" maxlength="160"', sval('banner_text_am'), '') + '</div></div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Opens this app when tapped</span><select class="ak-in" data-hl-s="banner_link_app"><option value="">(nothing)</option>' + apps.map(function (a) { return '<option value="' + esc(a.key) + '"' + (sval('banner_link_app') === a.key ? ' selected' : '') + '>' + esc(a.title) + '</option>'; }).join('') + '</select></div>' +
    '<div><span class="hl-lbl">Who sees it</span><select class="ak-in" data-hl-s="banner_audience">' + [['all', 'Everyone'], ['driver', 'Drivers'], ['courier', 'Couriers'], ['service_giver', 'Service givers'], ['customer', 'Customers']].map(function (o) { return '<option value="' + o[0] + '"' + ((sval('banner_audience') || 'all') === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div></div>' +
    '<div class="hl-two"><div><span class="hl-lbl">Starts (for example 2026-12-01 08:00)</span>' + inp('data-hl-s="banner_starts_at" maxlength="20"', sval('banner_starts_at'), '') + '</div><div><span class="hl-lbl">Ends</span>' + inp('data-hl-s="banner_ends_at" maxlength="20"', sval('banner_ends_at'), '') + '</div></div>' +
    '<div class="hl-row"><label class="ak-btn hl-file">Upload banner picture<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-hl-file="banner" style="display:none"></label>' + (img ? '<button type="button" class="ak-btn" data-hl-clear="banner">Remove picture</button>' : '<span class="hl-note">Up to 2 MB.</span>') + '</div>' +
    '<div class="hl-banner">' + (img ? '<img src="' + esc(img) + '" alt="" style="max-width:100%;border-radius:10px;margin-bottom:8px">' : '') + '<b>' + esc(sval('banner_title_en') || '(banner title)') + '</b>' + esc(sval('banner_text_en') || '(banner text)') + '</div>' +
    '<div class="hl-row"><span class="hl-note">' + (dirty ? 'Not saved yet.' : 'People who close a banner do not see that banner again; a changed banner shows again.') + '</span><button type="button" class="ak-btn gold" data-hl-saveset="banner"' + (dirty ? '' : ' disabled') + '>Save</button></div></div>';
}
function view(apps) {
  css(); L.appsList = (apps || []).filter(function (a) { return a.kind !== 'feature'; });
  if (L.err) return K.empty('⚠️', L.err, '');
  if (!L.content) return K.skeleton(4);
  return '<div class="hl-sec">Home screen texts and support contacts</div>' + textsCard() + '<div class="hl-sec">Home banner</div>' + bannerCard() +
    '<div class="hl-sec">The apps</div><div class="hl-grid">' + L.appsList.map(appCard).join('') + '</div>';
}
function load() {
  L.err = '';
  return run('admin_hub_content_get').then(function (r) {
    if (!ok(r)) { L.err = K.failText(r, 'Could not load.'); L.content = null; }
    else { L.content = dat(r).content || {}; L.settings = dat(r).settings || {}; L.host = dat(r).host || ''; L.draft = {}; L.sdraft = {}; }
    repaint();
  });
}
function uploadPicture(file, type) {
  var fd = new FormData(); fd.append('type', type); fd.append('image', file);
  var tok = ''; try { tok = localStorage.getItem('etel_token') || ''; } catch (e) { /* no storage */ }
  return fetch(new URL('api/upload.php', location.href).href, { method: 'POST', headers: { Authorization: 'Bearer ' + tok }, body: fd }).then(function (r) { return r.json().catch(function () { return {}; }); });
}
function saveApp(key, btn) {
  var c = cur(key), b = (L.content && L.content[key]) || {}, send = { key: key };
  APPFIELDS.forEach(function (f) { if (String(c[f] == null ? '' : c[f]) !== String(b[f] == null ? (f === 'sort_order' ? 0 : '') : b[f])) send[f] = c[f]; });
  btn.disabled = true;
  run('admin_hub_content_save', send).then(function (r) {
    if (!ok(r)) { btn.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
    K.toast('Saved'); return load();
  });
}
function saveSettings(group, btn) {
  var keys = group === 'home' ? SETFIELDS.slice(0, 7) : SETFIELDS.slice(7), send = {};
  keys.forEach(function (k) { if (L.sdraft[k] !== undefined && L.sdraft[k] !== ((L.settings && L.settings[k]) || '')) send[k] = L.sdraft[k]; });
  if (!Object.keys(send).length) return;
  btn.disabled = true;
  run('admin_hub_settings_save', { settings: send }).then(function (r) {
    if (!ok(r)) { btn.disabled = false; K.toast(K.failText(r, 'Could not save.'), true); return; }
    K.toast('Saved'); return load();
  });
}
function onClick(e) {
  var card = e.target.closest('[data-hl-key]'), sets = e.target.closest('[data-hl-sets]');
  var b;
  if ((b = e.target.closest('[data-hl-clear]'))) {
    var what = b.getAttribute('data-hl-clear');
    if (what === 'icon' && card) { var k = card.getAttribute('data-hl-key'); (L.draft[k] = L.draft[k] || {}).icon_url = ''; }
    if (what === 'banner') L.sdraft.banner_image_url = '';
    repaint(); return;
  }
  if ((b = e.target.closest('[data-hl-save]')) && card && !b.disabled) { saveApp(card.getAttribute('data-hl-key'), b); return; }
  if ((b = e.target.closest('[data-hl-saveset]')) && !b.disabled) { saveSettings(b.getAttribute('data-hl-saveset'), b); return; }
}
function onInput(e) {
  var f = e.target.closest('[data-hl-f]'), s = e.target.closest('[data-hl-s]');
  if (f) { var card = f.closest('[data-hl-key]'), k = card.getAttribute('data-hl-key'); (L.draft[k] = L.draft[k] || {})[f.getAttribute('data-hl-f')] = f.value; refreshCard(card, k); return; }
  if (s) { L.sdraft[s.getAttribute('data-hl-s')] = s.hasAttribute('data-hl-flag') ? (s.checked ? '1' : '0') : s.value; refreshSets(s.closest('[data-hl-sets]')); }
}
// Typing must not rebuild the box being typed in: only the small preview and the Save button follow.
function refreshCard(card, key) {
  var a = L.appsList.filter(function (x) { return x.key === key; })[0]; if (!a) return;
  var p = card.querySelector('.hl-prev'); if (p) { var tmp = document.createElement('div'); tmp.innerHTML = preview(a, cur(key)); p.parentNode.replaceChild(tmp.firstChild, p); }
  var sv = card.querySelector('[data-hl-save]'); if (sv) sv.disabled = !changed(key);
  var n = card.querySelector('.hl-row .hl-note:last-of-type'); var rows = card.querySelectorAll('.hl-row'); var last = rows[rows.length - 1]; if (last) { var nt = last.querySelector('.hl-note'); if (nt) nt.textContent = changed(key) ? 'Not saved yet.' : ''; }
}
function refreshSets(card) {
  if (!card) return; var group = card.getAttribute('data-hl-sets'), keys = group === 'home' ? SETFIELDS.slice(0, 7) : SETFIELDS.slice(7);
  var dirty = keys.some(function (k) { return L.sdraft[k] !== undefined && L.sdraft[k] !== ((L.settings && L.settings[k]) || ''); });
  var sv = card.querySelector('[data-hl-saveset]'); if (sv) sv.disabled = !dirty;
  var bn = card.querySelector('.hl-banner'); if (bn) { var im = sval('banner_image_url'); bn.innerHTML = (im ? '<img src="' + esc(im) + '" alt="" style="max-width:100%;border-radius:10px;margin-bottom:8px">' : '') + '<b>' + esc(sval('banner_title_en') || '(banner title)') + '</b>' + esc(sval('banner_text_en') || '(banner text)'); }
}
function onChange(e) {
  var f = e.target.closest('[data-hl-file]'); if (!f || !f.files || !f.files[0]) { if (e.target.closest('[data-hl-s]')) onInput(e); return; }
  var kind = f.getAttribute('data-hl-file'), card = f.closest('[data-hl-key]'), file = f.files[0];
  K.toast('Uploading...');
  uploadPicture(file, kind === 'icon' ? 'hub_icon' : 'hub_banner').then(function (j) {
    if (!j || !j.success || !j.data || !j.data.url) { K.toast((j && j.message) || 'Could not upload the picture.', true); return; }
    if (kind === 'icon' && card) { var k = card.getAttribute('data-hl-key'); (L.draft[k] = L.draft[k] || {}).icon_url = j.data.url; }
    else L.sdraft.banner_image_url = j.data.url;
    K.toast('Picture added. Press Save.'); repaint();
  }).catch(function () { K.toast('Could not upload the picture.', true); });
}
function open() {
  var el = document.getElementById('panel-etelapp'); if (!el) return;
  if (!el._hl) { el._hl = true; el.addEventListener('click', onClick); el.addEventListener('input', onInput); el.addEventListener('change', onChange); }
  return load();
}
window.ETELHubLook = { view: view, open: open };
})();
