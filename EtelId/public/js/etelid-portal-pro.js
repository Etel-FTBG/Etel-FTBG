/* Etel-ID portal - premium shell: light/dark theme (shared 'theme' key), ETEL coins in nav, welcome hero. */
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
    var b = document.querySelectorAll('.p-theme-btn');
    for (var i = 0; i < b.length; i++) {
      b[i].textContent = t === 'dark' ? '☀️' : '🌙';
      b[i].title = t === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      b[i].setAttribute('aria-label', b[i].title);
    }
  }
  paint(read());
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function init() {
    var cfg = window.EtelIDPortalPro || {}, base = cfg.url || '';
    var brand = document.querySelector('.p-nav-brand');
    if (brand && base && !brand.querySelector('.p-brand-logos')) {
      var w = document.createElement('span'); w.className = 'p-brand-logos';
      w.innerHTML = '<img src="' + esc(base) + 'public/images/etel-master-coin-128.webp" alt="ETEL"><img src="' + esc(base) + 'public/images/etel-delivery-coin.webp" alt="ETEL">';
      var old = brand.querySelector('.p-nav-logo'); if (old) old.parentNode.replaceChild(w, old); else brand.insertBefore(w, brand.firstChild);
    }
    var right = document.querySelector('.p-nav-right');
    if (right && !right.querySelector('.p-theme-btn')) {
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'p-theme-btn';
      right.insertBefore(btn, right.querySelector('.p-signout') || null);
    }
    var wrap = document.querySelector('.p-wrap');
    if (wrap && !wrap.querySelector('.p-hero') && cfg.name) {
      var av = cfg.photo ? '<img src="' + esc(cfg.photo) + '" alt="">' : esc(cfg.initials || '');
      var h = document.createElement('section'); h.className = 'p-hero';
      h.innerHTML = '<div class="p-hero-row"><div class="p-hero-av">' + av + '</div><div><div class="eyebrow">Etel Group member</div><h2>Welcome, ' + esc(cfg.name) + '</h2><p>' + esc(cfg.id || '') + ' · your digital identity across every Etel company</p></div><div class="p-hero-logos"><img src="' + esc(base) + 'public/images/etel-master-coin-128.webp" alt=""><img src="' + esc(base) + 'public/images/etel-delivery-coin.webp" alt=""></div></div>';
      wrap.insertBefore(h, wrap.firstChild);
    }
    paint(read());
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.p-theme-btn');
    if (!b) return;
    var t = root.getAttribute('data-eid-theme') === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('theme', t); } catch (x) { /* not remembered */ }
    paint(t);
  });
  window.addEventListener('storage', function (ev) { if (ev.key === 'theme') paint(read()); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
