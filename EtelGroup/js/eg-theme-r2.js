/* Etel Group - shared light/dark theme + premium shell helpers.
   Theme key 'theme' + body.dark-theme are shared with the Delivery admin and the Investor admin. */
(function () {
'use strict';
function isDark() { return document.body.classList.contains('dark-theme'); }
function readTheme() {
  var t = null; try { t = localStorage.getItem('theme'); } catch (e) { /* none */ }
  if (t !== 'dark' && t !== 'light') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  return t;
}
function paintTheme(t) {
  document.body.classList.toggle('dark-theme', t === 'dark');
  document.documentElement.setAttribute('data-theme', t);
  var mc = document.querySelector('meta[name=theme-color]'); if (mc) mc.setAttribute('content', t === 'dark' ? '#10101a' : '#D4AF37');
  Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (b) {
    b.textContent = t === 'dark' ? '☀️' : '🌙';
    b.title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    b.setAttribute('aria-label', b.title);
  });
  document.dispatchEvent(new CustomEvent('ir:theme', { detail: t }));
}
paintTheme(readTheme());
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-theme-toggle]');
  if (b) {
    var t = isDark() ? 'light' : 'dark';
    try { localStorage.setItem('theme', t); } catch (x) { /* not remembered */ }
    paintTheme(t); return;
  }
  if (e.target.closest && e.target.closest('#irx-burger')) { document.body.classList.toggle('nav-open'); return; }
  if (e.target.id === 'irx-scrim') document.body.classList.remove('nav-open');
});
window.addEventListener('storage', function (ev) { if (ev.key === 'theme') paintTheme(readTheme()); });

/* Group admin: keep sidebar highlight + topbar title in step with switchTab() */
var TITLES = {
  dashboard: ['Dashboard', 'Messages and enquiries at a glance'], companies: ['Companies', 'The Etel Group family of companies'],
  employees: ['Employees', 'Roles across the group'], activity: ['Activity', 'Who did what, and when'], sitecontent: ['Site content', 'Edit the texts and links shown on the master page'],
  finance: ['Finance & Planning', 'Equity, economics, payroll and business plan']
};
function wrapSwitch() {
  if (typeof window.switchTab !== 'function' || window.switchTab.__egw) return;
  var orig = window.switchTab;
  var w = function (name) {
    orig(name);
    var t = TITLES[name] || TITLES.dashboard;
    var h = document.getElementById('irx-title'), s = document.getElementById('irx-sub');
    if (h) h.textContent = t[0]; if (s) s.textContent = t[1];
    Array.prototype.forEach.call(document.querySelectorAll('#irx-nav a[data-tab]'), function (a) { a.classList.toggle('on', a.getAttribute('data-tab') === name); });
    document.body.classList.remove('nav-open');
    window.scrollTo(0, 0);
  };
  w.__egw = true; window.switchTab = w;
}
wrapSwitch();
document.addEventListener('DOMContentLoaded', wrapSwitch);
})();
