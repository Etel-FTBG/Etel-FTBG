/* Etel Madeya - premium shell: light/dark theme (shared 'theme' key) + toggle buttons. */
(function () {
  'use strict';
  var root = document.documentElement;
  function read() {
    var t = null; try { t = localStorage.getItem('theme'); } catch (e) { /* none */ }
    if (t !== 'dark' && t !== 'light') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    return t;
  }
  function paint(t) {
    root.setAttribute('data-mp-theme', t);
    var b = document.querySelectorAll('.mp-theme');
    for (var i = 0; i < b.length; i++) {
      b[i].textContent = t === 'dark' ? '☀️' : '🌙';
      b[i].title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      b[i].setAttribute('aria-label', b[i].title);
    }
    var mc = document.querySelector('meta[name=theme-color]'); if (mc) mc.setAttribute('content', t === 'dark' ? '#10101a' : '#D4AF37');
    document.dispatchEvent(new CustomEvent('mp:theme', { detail: t }));
  }
  paint(read());
  function mk(cls) { var b = document.createElement('button'); b.type = 'button'; b.className = 'mp-theme' + (cls ? ' ' + cls : ''); return b; }
  function init() {
    var spots = [['.am-hdr-r', 'before:.btn-logout'], ['.mc-hdr-r', 'before:.btn-logout'], ['.topbar-right', 'prepend']];
    spots.forEach(function (s) {
      var host = document.querySelector(s[0]); if (!host || host.querySelector('.mp-theme')) return;
      var b = mk('');
      if (s[1] === 'prepend') host.insertBefore(b, host.firstChild);
      else { var ref = host.querySelector(s[1].split(':')[1]); host.insertBefore(b, ref || null); }
    });
    var boxes = document.querySelectorAll('.login-box');
    for (var i = 0; i < boxes.length; i++) if (!boxes[i].querySelector('.mp-theme')) boxes[i].insertBefore(mk('float'), boxes[i].firstChild);
    paint(read());
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.mp-theme'); if (!b) return;
    var t = root.getAttribute('data-mp-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('theme', t); } catch (x) { /* not remembered */ }
    paint(t);
  });
  window.addEventListener('storage', function (ev) { if (ev.key === 'theme') paint(read()); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
