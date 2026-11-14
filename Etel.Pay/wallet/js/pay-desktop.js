/**
 * ኢE Pay - desktop web app layer (runs only on screens 1024px and wider; the phone layout is untouched).
 *  - marks the page (html[data-page]) so css/pay-desktop.css can lay each page out as a desktop screen
 *  - vector icons in the sidebar and the four quick actions (instead of emoji)
 *  - sign-in page: split screen (brand panel + form); the existing elements are moved, never copied, so language toggle and login keep working
 * No money logic, API call or form behaviour is touched.
 */
(function () {
  'use strict';
  var BREAK = 1024;
  var root = document.documentElement;
  var page = (location.pathname.split('/').pop() || 'wallet').replace('.html', '') || 'wallet';
  root.setAttribute('data-page', page);
  if (window.innerWidth < BREAK) return;
  root.classList.add('ep-desk');

  function svg(inner) { return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + inner + '</svg>'; }
  var ICONS = {
    wallet: '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h13V10"/><path d="M10 19.5v-5h4v5"/>',
    send: '<path d="M5 19 19 5M9 5h10v10"/>',
    history: '<path d="M4 6h16M4 12h16M4 18h10"/>',
    qr: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.2"/><path d="M14 14h2.5v2.5M20.5 14v.01M14 20.5h.01M17.5 18v2.5h3"/>',
    topup: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M12 12.8v4M10 14.8h4"/>',
    'pay-links': '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
    analytics: '<path d="M3 20h18M6 16v-5M11 16V6M16 16v-8M21 16v-3"/>',
    profile: '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20c.8-4 3.7-6 7.5-6s6.7 2 7.5 6"/>',
    kyc: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5.5 16c.5-1.7 1.6-2.4 3-2.4s2.5.7 3 2.4M14.5 10h4M14.5 13.5h3"/>',
    admin: '<path d="M12 3 4 6v6c0 4.5 3.2 8 8 9 4.8-1 8-4.5 8-9V6l-8-3z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    updater: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
    scan: '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16M4 12h16"/>',
    receive: '<path d="M19 5 5 19M15 19H5V9"/>'
  };

  function icons() {
    var items = document.querySelectorAll('.sidebar-nav-item');
    for (var i = 0; i < items.length; i++) {
      var href = (items[i].getAttribute('href') || '').split('/').pop().replace('.html', '');
      var key = href === 'wallet' || href === '' ? 'wallet' : href;
      var ic = items[i].querySelector('.sidebar-nav-icon');
      if (ic && ICONS[key] && !ic.getAttribute('data-svg')) { ic.innerHTML = svg(ICONS[key]); ic.setAttribute('data-svg', '1'); }
    }
    // the four quick actions on the wallet
    var map = { send: 'send', qr: 'receive', topup: 'topup' };
    var acts = document.querySelectorAll('.ep-action .ep-action-icon');
    var order = ['send', 'receive', 'topup', 'scan'];
    for (var j = 0; j < acts.length && j < 4; j++) {
      if (!acts[j].getAttribute('data-svg')) { acts[j].innerHTML = svg(ICONS[order[j]]); acts[j].setAttribute('data-svg', '1'); }
    }
  }

  function loginSplit() {
    var pg = document.querySelector('.ep-auth-page'); if (!pg || pg.querySelector('.ep-brand-side')) return;
    var medal = pg.querySelector('.ep-medal'), trust = pg.querySelector('.ep-auth-trust');
    if (!medal) return;
    var side = document.createElement('div');
    side.className = 'ep-brand-side';
    side.innerHTML = '<div class="ep-brand-medal"></div><div class="ep-brand-name">ኢE Pay</div><div class="ep-brand-tag">Ethiopia’s digital wallet</div><div class="ep-brand-trust"></div><div class="ep-brand-foot">Etel Group · Hawassa</div>';
    side.querySelector('.ep-brand-medal').appendChild(medal);
    if (trust) side.querySelector('.ep-brand-trust').appendChild(trust);
    pg.insertBefore(side, pg.firstChild);
  }

  function moveFab() {
    var fab = document.getElementById('ep-theme-fab'), sb = document.getElementById('ep-sidebar');
    if (fab && sb && fab.parentNode !== sb) sb.appendChild(fab);
  }
  function boot() {
    icons();
    if (page === 'login') loginSplit();
    // the shell builds the sidebar a moment after load: look again
    var n = 0, t = setInterval(function () { icons(); moveFab(); if (++n > 12) clearInterval(t); }, 300);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
