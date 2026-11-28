/* ETEL Admin > Branding > Languages (v1, 28 Nov 2026). The owner decides which languages the apps offer and in what state:
   Live (no tag), Beta (BETA tag), Coming soon (listed, cannot be chosen) or Hidden; and which language a new visitor starts in.
   Reads and saves with admin_get_languages / admin_save_languages (settings.manage). The picker in every app reads the result (get_language_config).
   English is always live. Needs js/admin-kit.js and js/etel-langs.js (flags only). Every server value goes through AdminKit.esc. */
(function () {
'use strict';
var K = window.AdminKit, esc = K && K.esc;
if (!K) return;
var S = null, busy = false;
var STATES = [['live', 'Live (no tag)'], ['beta', 'Beta (BETA tag)'], ['soon', 'Coming soon (cannot be chosen)'], ['off', 'Hidden']];
var NAMES = { en: ['English', 'English'], am: ['\u12a0\u121b\u122d\u129b', 'Amharic'], om: ['Afaan Oromoo', 'Oromo'], ti: ['\u1275\u130d\u122d\u129b', 'Tigrinya'], so: ['Soomaali', 'Somali'], aa: ['Qafar af', 'Afar'] };
var CODES = ['en', 'am', 'om', 'ti', 'so', 'aa'];

function root() { return document.getElementById('brand-lang'); }
function flag(c) { return (window.EtelLangs && EtelLangs.flag) ? '<span style="display:inline-block;line-height:0;border-radius:6px;overflow:hidden;box-shadow:0 0 0 1px rgba(0,0,0,.25)">' + EtelLangs.flag(c, 44) + '</span>' : '<b>' + esc(c.toUpperCase()) + '</b>'; }
function paint() {
  var el = root(); if (!el) return;
  if (!S) { el.innerHTML = '<div class="ak"><div class="ak-sec"><h4>Languages</h4><div style="font-size:12.5px;color:var(--mute)">Loading\u2026</div></div></div>'; return; }
  var h = '<div class="ak"><div class="ak-sec"><h4>Languages</h4><div style="font-size:12.5px;color:var(--mute);margin:-2px 0 12px">Choose what the language button shows in every app. Afar stays \u201cComing soon\u201d until a native speaker has written the words. People who already chose a language that you hide are moved back to English.</div><div class="ak-grid" style="grid-template-columns:1fr">';
  CODES.forEach(function (c) {
    var st = S.config.langs[c], locked = c === 'en', n = S.stats[c] || 0;
    h += '<div class="ak-card" style="cursor:default"><div class="ak-top" style="align-items:center;gap:12px;flex-wrap:wrap">' + flag(c) +
      '<div class="ak-nm" style="flex:1 1 140px;min-width:0"><b>' + esc(NAMES[c][0]) + '</b><small>' + esc(NAMES[c][1]) + ' \u00b7 ' + esc(String(n)) + ' people chose it</small></div>' +
      '<select data-st="' + c + '"' + (locked ? ' disabled' : '') + ' style="min-height:38px;border-radius:10px;padding:0 8px;max-width:100%;box-sizing:border-box;flex:1 1 190px">' + STATES.map(function (x) { return '<option value="' + x[0] + '"' + (x[0] === st ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select></div>' +
      '<label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px"><input type="radio" name="lang-def" data-def="' + c + '"' + (S.config.default === c ? ' checked' : '') + (st === 'live' || st === 'beta' ? '' : ' disabled') + '> New visitors start in this language</label></div>';
  });
  h += '</div><div style="margin-top:12px"><button type="button" class="ak-btn gold" data-save-lang="1"' + (busy ? ' disabled' : '') + '>' + (busy ? 'Saving\u2026' : 'Save languages') + '</button></div></div></div>';
  el.innerHTML = h;
}
function load() {
  K.api('admin_get_languages', {}).then(function (res) {
    if (K.okRes(res)) { var d = K.data(res); S = { config: d.config, stats: d.stats || {} }; paint(); }
    else { var el = root(); if (el) el.innerHTML = '<div class="ak"><div class="ak-sec"><h4>Languages</h4><div style="color:var(--red)">' + esc(K.failText(res, 'Could not load the language settings.')) + '</div></div></div>'; }
  });
}
function save() {
  if (busy || !S) return;
  busy = true; paint();
  K.api('admin_save_languages', S.config).then(function (res) {
    busy = false;
    if (K.okRes(res)) { S.config = K.data(res).config; K.toast('Saved. Phones pick up the change within a few hours (or when the app is opened again).'); }
    else K.toast(K.failText(res, 'Could not save.'), true);
    paint();
  });
}
function wire(el) {
  el.addEventListener('change', function (e) {
    var t = e.target; if (!S || !t.getAttribute) return;
    var c = t.getAttribute('data-st');
    if (c) { S.config.langs[c] = t.value; if (S.config.default === c && t.value !== 'live' && t.value !== 'beta') S.config.default = 'en'; paint(); return; }
    c = t.getAttribute('data-def'); if (c) S.config.default = c;
  });
  el.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-save-lang]')) save(); });
}
function install() {
  var host = document.getElementById('brand-pro'); if (!host) return;
  var el = document.createElement('div'); el.id = 'brand-lang'; host.parentNode.insertBefore(el, host.nextSibling);
  wire(el);
  var prev = window.loadBrandingPanel;
  window.loadBrandingPanel = function () { if (prev) prev.apply(this, arguments); if (!el._built) { el._built = true; K.css(); paint(); load(); } };
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install); else install();
})();
