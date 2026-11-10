/* Etel-ID admin - premium shell: light/dark theme (shared 'theme' key) + ETEL logos in the top bar. */
(function () {
  'use strict';
  var root = document.documentElement;
  function read() {
    var t = null; try { t = localStorage.getItem('theme'); } catch (e) { /* none */ }
    if (t !== 'dark' && t !== 'light') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    return t;
  }
  function paint(t) {
    root.setAttribute('data-eid-theme', t);
    var b = document.querySelectorAll('.eid-theme-btn');
    for (var i = 0; i < b.length; i++) {
      b[i].textContent = t === 'dark' ? '☀️' : '🌙';
      b[i].title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      b[i].setAttribute('aria-label', b[i].title);
    }
  }
  paint(read());
  function init() {
    var base = (window.EtelIDAdmin && window.EtelIDAdmin.url) || '';
    var navs = document.querySelectorAll('.eid-topnav');
    for (var i = 0; i < navs.length; i++) {
      var nav = navs[i];
      var brand = nav.querySelector('.eid-nav-brand');
      if (brand && base && !brand.querySelector('.eid-brand-logos')) {
        var wrap = document.createElement('span'); wrap.className = 'eid-brand-logos';
        ['etel-master-coin-128.webp', 'etel-delivery-coin.webp'].forEach(function (f) {
          var im = document.createElement('img'); im.className = 'eid-brand-coin'; im.alt = 'ETEL';
          im.src = base + 'public/images/' + f; wrap.appendChild(im);
        });
        var old = brand.querySelector('.eid-nav-sig, .eid-nav-logo-img');
        if (old) old.parentNode.replaceChild(wrap, old); else brand.insertBefore(wrap, brand.firstChild);
      }
      if (!nav.querySelector('.eid-theme-btn')) {
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'eid-theme-btn';
        nav.appendChild(btn);
      }
    }
    paint(read());
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.eid-theme-btn');
    if (!b) return;
    var t = root.getAttribute('data-eid-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('theme', t); } catch (x) { /* not remembered */ }
    paint(t);
  });
  window.addEventListener('storage', function (ev) { if (ev.key === 'theme') paint(read()); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
