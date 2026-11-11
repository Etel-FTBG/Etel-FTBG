/* Etel Group master page - light / dark toggle. Dark (the original gold look) is the default; the choice is remembered
   and shared (localStorage 'theme') with the Delivery, Madeya and investor admin screens. */
(function () {
  'use strict';
  function read() { var t = null; try { t = localStorage.getItem('theme'); } catch (e) {} return t === 'light' ? 'light' : 'dark'; }
  function paint(t) {
    document.documentElement.setAttribute('data-theme', t);
    if (document.body) document.body.classList.toggle('dark-theme', t === 'dark');
    var mc = document.querySelector('meta[name=theme-color]'); if (mc) mc.setAttribute('content', t === 'dark' ? '#1A0E00' : '#faf6ea');
    var btns = document.querySelectorAll('[data-theme-toggle]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].textContent = t === 'dark' ? '☀️' : '🌙';
      var label = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      btns[i].title = label; btns[i].setAttribute('aria-label', label);
    }
  }
  paint(read());
  document.addEventListener('DOMContentLoaded', function () { paint(read()); });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-theme-toggle]'); if (!b) return;
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('theme', t); } catch (x) {}
    paint(t);
  });
})();
