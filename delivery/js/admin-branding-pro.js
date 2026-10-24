/* ETEL Admin - Branding & icons.
   Every logo and icon the apps show, in one place. A home screen strip shows how the six app icons look together. Each slot is a
   card: the current image, and a drop area (drag a file in or tap to choose). Choosing a file does NOT change anything yet: the card
   shows the new image next to the old one with plain warnings (not square, small, too big), and only "Replace now" uploads it.
   Phone app icons are made in every size the phones need from the one image you give.
   Data: POST api/admin-branding.php (image + slot), the same file the old screen used. Standalone: replaces loadBrandingPanel()
   so admin-branding.js is not edited. Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit;
if (!K) return;
var esc = K.esc;
var MAX = 8 * 1024 * 1024, OK_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
var GROUPS = [
  { key: 'icons', title: 'App icons', note: 'The icon on the phone home screen. Use a square image, at least 512 × 512.', square: true, items: [
    ['icon_driver', '🚖', 'Driver', 'icons/driver-icon-192.png'], ['icon_ride', '🛺', 'Ride', 'icons/ride-icon-192.png'], ['icon_courier', '📦', 'Courier', 'icons/courier-icon-192.png'],
    ['icon_service', '🏪', 'Service', 'icons/service-icon-192.png'], ['icon_admin', '🛡️', 'Admin', 'icons/admin-icon-192.png'], ['icon_default', '🛍️', 'Customer', 'icons/icon-192.png']] },
  { key: 'logos', title: 'Login page logos', note: 'The logo above the sign in form. Any shape works; it is shown at most 200 pixels tall.', square: false, items: [
    ['logo_driver', '🚖', 'Driver', 'assets/images/logo-driver.png'], ['logo_ride', '🛺', 'Ride', 'assets/images/logo-ride.png'], ['logo_courier', '📦', 'Courier', 'assets/images/logo-courier.png'],
    ['logo_service', '🏪', 'Service', 'assets/images/logo-service.png'], ['logo_admin', '🛡️', 'Admin', 'assets/images/logo-admin.png'], ['logo_default', '🛍️', 'Customer', 'assets/images/logo.png']] },
  { key: 'splash', title: 'Splash and browser tab', note: 'The splash logo shows briefly when any app opens. The favicon is the tiny picture on the browser tab.', square: true, items: [
    ['splash', '✨', 'Splash logo', 'assets/images/master-logo-splash.png'], ['favicon', '🌐', 'Browser tab icon', 'favicon.png']] }
];
var BUST = Date.now(), PENDING = {}, DONE = {}, SLOTS = {};
GROUPS.forEach(function (g) { g.items.forEach(function (i) { SLOTS[i[0]] = { g: g, i: i }; }); });

function root() { return document.getElementById('brand-pro'); }
function base() { return (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('api/boot.php', '') : ''; }
function src(i) { return base() + i[3] + '?v=' + (DONE[i[0]] || BUST); }
function size(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

function paint() {
  var el = root(); if (!el) return;
  K.css();
  var changed = Object.keys(DONE).length;
  var h = '<div class="ak">' + K.hero('Branding & icons', 'Replace any logo or icon without a code deploy. Pick a picture, check the preview, then replace. Nothing changes until you confirm.', [
    ['Images you can change', K.num(Object.keys(SLOTS).length)], ['Changed this visit', K.num(changed)]]);
  var icons = GROUPS[0].items;
  h += '<div class="ak-card" style="cursor:default;margin-bottom:14px"><b>Home screen preview</b><div style="font-size:12px;color:var(--mute);margin:2px 0 12px">How the six apps sit together on a phone.</div>' +
    '<div style="display:flex;gap:14px;flex-wrap:wrap;padding:14px;border-radius:18px;background:linear-gradient(160deg,#1a1630,#2b2147)">' + icons.map(function (i) {
      return '<div style="text-align:center;width:64px"><div style="width:56px;height:56px;margin:0 auto;border-radius:14px;overflow:hidden;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center"><img data-prev="' + esc(i[0]) + '" src="' + esc(src(i)) + '" alt="" style="width:100%;height:100%;object-fit:cover"></div><div style="font-size:10.5px;color:#fff;opacity:.85;margin-top:5px">' + esc(i[2]) + '</div></div>';
    }).join('') + '</div></div>';
  GROUPS.forEach(function (g) {
    h += '<div class="ak-sec"><h4>' + esc(g.title) + '</h4><div style="font-size:12.5px;color:var(--mute);margin:-2px 0 10px">' + esc(g.note) + '</div><div class="ak-grid">' + g.items.map(card).join('') + '</div></div>';
  });
  el.innerHTML = h + '</div>';
  Array.prototype.forEach.call(el.querySelectorAll('img[data-prev],img[data-cur]'), function (im) { im.onerror = function () { im.style.opacity = '.25'; im.alt = ''; im.removeAttribute('src'); }; });
}

function checker() { return 'background:conic-gradient(#e8e2d0 25%,#fff 0 50%,#e8e2d0 0 75%,#fff 0) 0 0/16px 16px'; }
function card(i) {
  var slot = i[0], p = PENDING[slot], busy = p && p.busy, g = SLOTS[slot].g;
  var h = '<div class="ak-card" style="cursor:default" data-slot="' + esc(slot) + '"><div class="ak-top"><div class="ak-av" style="font-size:19px">' + esc(i[1]) + '</div><div class="ak-nm"><b>' + esc(i[2]) + '</b><small>' + esc(i[3]) + '</small></div>' +
    (DONE[slot] && !p ? K.pill('Updated', 'green') : '') + '</div>';
  h += '<div style="display:grid;grid-template-columns:' + (p ? '1fr 1fr' : '1fr') + ';gap:10px;margin-top:12px">' + pic('Now', '<img data-cur="' + esc(slot) + '" src="' + esc(src(i)) + '" alt="Current ' + esc(i[2]) + ' image" style="max-width:100%;max-height:100%;object-fit:contain">') +
    (p ? pic('New', '<img src="' + esc(p.url) + '" alt="New image preview" style="max-width:100%;max-height:100%;object-fit:contain">') : '') + '</div>';
  if (p) {
    h += '<div style="font-size:12px;color:var(--mute);margin-top:8px">' + esc(p.name) + ' · ' + esc(size(p.bytes)) + (p.w ? ' · ' + esc(p.w + ' × ' + p.h) : '') + '</div>';
    p.warn.forEach(function (w) { h += '<div style="font-size:12px;margin-top:6px;color:' + (w[0] === 'bad' ? 'var(--red)' : '#b57b00') + '">' + (w[0] === 'bad' ? '⛔ ' : '⚠️ ') + esc(w[1]) + '</div>'; });
    h += '<div class="ak-acts" style="margin-top:12px"><button type="button" class="ak-btn" data-cancel="' + esc(slot) + '"' + (busy ? ' disabled' : '') + '>Cancel</button>' +
      (p.warn.some(function (w) { return w[0] === 'bad'; }) ? '' : '<button type="button" class="ak-btn gold" data-save="' + esc(slot) + '"' + (busy ? ' disabled' : '') + '>' + (busy ? 'Uploading…' : 'Replace now') + '</button>') + '</div>';
    if (p.err) h += '<div class="ak-err" role="alert" style="margin-top:6px">' + esc(p.err) + '</div>';
  } else {
    h += '<label class="ak-btn" data-drop="' + esc(slot) + '" style="display:block;text-align:center;margin-top:12px;border-style:dashed;cursor:pointer;padding:14px 10px">📤 Choose or drop an image<input type="file" data-file="' + esc(slot) + '" accept="' + OK_TYPES.join(',') + '" style="position:absolute;width:1px;height:1px;opacity:0"></label>' +
      '<div style="font-size:11px;color:var(--mute);margin-top:6px;text-align:center">PNG, JPG, WebP or GIF · up to 8 MB' + (g.square ? ' · square is best' : '') + '</div>';
  }
  return h + '</div>';
}
function pic(label, inner) {
  return '<div><div style="font-size:11px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px">' + esc(label) + '</div><div style="height:96px;border-radius:14px;border:1.5px solid var(--line);overflow:hidden;display:flex;align-items:center;justify-content:center;' + checker() + '">' + inner + '</div></div>';
}

function choose(slot, file) {
  if (!file) return;
  var warn = [];
  if (OK_TYPES.indexOf(file.type) < 0) warn.push(['bad', 'That is not a PNG, JPG, WebP or GIF picture.']);
  if (file.size > MAX) warn.push(['bad', 'The file is ' + size(file.size) + '. The limit is 8 MB.']);
  var p = { name: file.name, bytes: file.size, url: '', w: 0, h: 0, warn: warn, file: file, busy: false, err: '' };
  if (PENDING[slot] && PENDING[slot].url) URL.revokeObjectURL(PENDING[slot].url);
  PENDING[slot] = p;
  if (warn.length) { paint(); return; }
  p.url = URL.createObjectURL(file);
  var im = new Image();
  im.onload = function () {
    p.w = im.naturalWidth; p.h = im.naturalHeight;
    var g = SLOTS[slot].g;
    if (g.square && Math.abs(p.w - p.h) > Math.max(2, p.w * 0.03)) p.warn.push(['warn', 'The picture is not square, so the sides may be cut off.']);
    if (g.key === 'icons' && Math.min(p.w, p.h) < 512) p.warn.push(['warn', 'It is small. Icons look blurry below 512 × 512.']);
    if (g.key === 'splash' && Math.min(p.w, p.h) < 256) p.warn.push(['warn', 'It is small and may look blurry.']);
    paint();
  };
  im.onerror = function () { p.warn.push(['bad', 'This file could not be read as a picture.']); paint(); };
  im.src = p.url;
  paint();
}

function upload(slot) {
  var p = PENDING[slot]; if (!p || p.busy) return;
  p.busy = true; p.err = ''; paint();
  var url = (typeof ETEL !== 'undefined' && ETEL.api) ? ETEL.api.replace('boot.php', 'admin-branding.php') : 'api/admin-branding.php';
  var fd = new FormData(); fd.append('image', p.file); fd.append('slot', slot);
  var tok = (typeof Auth !== 'undefined' && Auth.getToken) ? Auth.getToken() : '';
  fetch(url, { method: 'POST', headers: { 'Authorization': 'Bearer ' + tok }, body: fd })
    .then(function (r) { return r.json().catch(function () { return { success: false, message: 'The server sent an unreadable answer.' }; }); })
    .then(function (res) {
      if (res && res.success) {
        URL.revokeObjectURL(p.url); delete PENDING[slot]; DONE[slot] = Date.now();
        K.toast((res.data && res.data.message) || 'Image replaced'); paint();
      } else { p.busy = false; p.err = (res && res.message) || 'The upload failed.'; paint(); }
    })
    .catch(function () { p.busy = false; p.err = 'Could not reach the server. Try again.'; paint(); });
}

function wire(el) {
  if (el._brWired) return; el._brWired = true;
  el.addEventListener('change', function (e) { var s = e.target.getAttribute && e.target.getAttribute('data-file'); if (s) choose(s, e.target.files && e.target.files[0]); });
  el.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('[data-cancel]'))) { var s = b.getAttribute('data-cancel'); if (PENDING[s]) { if (PENDING[s].url) URL.revokeObjectURL(PENDING[s].url); delete PENDING[s]; paint(); } return; }
    if ((b = e.target.closest('[data-save]'))) upload(b.getAttribute('data-save'));
  });
  el.addEventListener('dragover', function (e) { var d = e.target.closest && e.target.closest('[data-drop]'); if (d) { e.preventDefault(); d.style.borderColor = 'var(--gold)'; } });
  el.addEventListener('dragleave', function (e) { var d = e.target.closest && e.target.closest('[data-drop]'); if (d) d.style.borderColor = ''; });
  el.addEventListener('drop', function (e) {
    var d = e.target.closest && e.target.closest('[data-drop]'); if (!d) return;
    e.preventDefault(); d.style.borderColor = '';
    choose(d.getAttribute('data-drop'), e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
  });
}

function install() {
  var el = root(); if (!el) return;
  wire(el);
  window.loadBrandingPanel = function () { if (!el._brBuilt) { el._brBuilt = true; paint(); } };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
