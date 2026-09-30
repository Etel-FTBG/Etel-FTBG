/* Etel Gebeya - dark / light theme switch.
   Loaded synchronously in <head> so the saved theme is on <html> before the
   first paint (no flash). Dark is the default. Any element with
   [data-etel-theme-toggle] becomes a toggle; it always shows the mode you
   would switch TO. The choice is per browser (localStorage) and follows
   across open tabs. */
(function () {
  'use strict';
  var KEY = 'egebya_theme';
  var root = document.documentElement;

  function read() {
    try { return localStorage.getItem(KEY) === 'light' ? 'light' : 'dark'; } catch (e) { return 'dark'; }
  }
  function paintButtons(theme) {
    var toLight = theme === 'dark';
    var label = toLight ? 'Light mode' : 'Dark mode';
    var nodes = document.querySelectorAll('[data-etel-theme-toggle]');
    for (var i = 0; i < nodes.length; i++) {
      var b = nodes[i];
      b.setAttribute('title', label);
      b.setAttribute('aria-label', 'Switch to ' + label.toLowerCase());
      var ico = b.querySelector('i');
      if (ico) ico.className = 'bi ' + (toLight ? 'bi-sun' : 'bi-moon-stars') + (ico.getAttribute('data-keep') ? ' ' + ico.getAttribute('data-keep') : '');
      var txt = b.querySelector('[data-etel-theme-label]');
      if (txt) txt.textContent = label;
    }
  }
  function apply(theme) {
    root.setAttribute('data-theme', theme);
    root.style.colorScheme = theme;
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'light' ? '#FBF6EA' : (meta.getAttribute('data-dark') || '#0A0A1A'));
    paintButtons(theme);
  }

  apply(read());

  document.addEventListener('DOMContentLoaded', function () {
    var m = document.querySelector('meta[name="theme-color"]');
    if (m && !m.getAttribute('data-dark')) m.setAttribute('data-dark', m.getAttribute('content') || '#0A0A1A');
    apply(read());
  });
  document.addEventListener('click', function (ev) {
    var t = ev.target && ev.target.closest ? ev.target.closest('[data-etel-theme-toggle]') : null;
    if (!t) return;
    ev.preventDefault();
    var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    try { localStorage.setItem(KEY, next); } catch (e) {}
    apply(next);
  });
  window.addEventListener('storage', function (ev) { if (ev.key === KEY) apply(read()); });
})();
