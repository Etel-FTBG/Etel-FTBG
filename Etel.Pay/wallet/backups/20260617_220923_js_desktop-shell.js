/**
 * ኢE Pay — Desktop Shell v1.0
 * Injects sidebar + info panel on ALL pages at 1024px+
 * No DOM corruption on mobile — only runs on desktop
 * Works on every page uniformly
 */
(function () {
  'use strict';

  var BREAKPOINT = 1024;
  var initialized = false;

  // Navigation items
  var NAV = [
    { icon: '🏠', label: 'Dashboard',     page: 'wallet',    section: 'nav' },
    { icon: '💸', label: 'Send Money',     page: 'send',      section: 'nav' },
    { icon: '📋', label: 'History',        page: 'history',   section: 'nav' },
    { icon: '⬛', label: 'QR Code',        page: 'qr',        section: 'nav' },
    { icon: '💳', label: 'Top Up',         page: 'topup',     section: 'nav' },
    { icon: '🔗', label: 'Payment Links',  page: 'pay-links', section: 'nav' },
    { icon: '📊', label: 'Analytics',      page: 'analytics', section: 'nav' },
    { icon: '👤', label: 'Profile',        page: 'profile',   section: 'account' },
    { icon: '🪪', label: 'Verify ID',      page: 'kyc',       section: 'account' },
    { icon: '⚙️', label: 'Admin Panel',    page: 'admin',     section: 'admin',  adminOnly: true },
    { icon: '⬆',  label: 'Updater',       page: 'updater',   section: 'admin',  adminOnly: true },
  ];

  var QUICK = [
    { icon: '↗', label: 'Send Money',    sub: 'Transfer to any wallet', page: 'send' },
    { icon: '💳', label: 'Top Up',        sub: 'Fund via Telebirr/CBE',  page: 'topup' },
    { icon: '🔗', label: 'Payment Links', sub: 'Create & share links',   page: 'pay-links' },
    { icon: '📊', label: 'Analytics',     sub: 'View your statistics',   page: 'analytics' },
  ];

  function currentPage() {
    var p = window.location.pathname.split('/').pop().replace('.html', '');
    return p || 'wallet';
  }

  function base() {
    var p = window.location.pathname;
    return window.location.origin + p.substring(0, p.lastIndexOf('/') + 1);
  }

  function nav(page) {
    if (window.EP_CORE) {
      EP_CORE.nav(page);
    } else {
      window.location.href = base() + page;
    }
  }

  // Build sidebar HTML
  function buildSidebar(cur, isAdmin) {
    var sections = { nav: [], account: [], admin: [] };
    NAV.forEach(function (item) {
      if (item.adminOnly && !isAdmin) return;
      sections[item.section].push(item);
    });

    function navItem(item) {
      var active = (item.page === cur) ? ' active' : '';
      return '<a class="sidebar-nav-item' + active + '" href="' + base() + item.page + '" onclick="return epNav(event,\'' + item.page + '\')">' +
        '<span class="sidebar-nav-icon">' + item.icon + '</span>' + item.label + '</a>';
    }

    return '<div class="sidebar-logo">' +
        '<img src="' + base() + 'icons/logo.png" alt="ኢE" style="width:42px;height:42px;border-radius:12px;object-fit:cover;box-shadow:0 4px 20px rgba(212,168,67,.3)">' +
        '<div><div class="sidebar-logo-name">ኢE Pay</div><div class="sidebar-logo-sub">Ethiopia\'s Digital Wallet</div></div>' +
      '</div>' +
      '<div class="sidebar-section-label">Navigation</div>' +
      sections.nav.map(navItem).join('') +
      '<div class="sidebar-section-label">Account</div>' +
      sections.account.map(navItem).join('') +
      (sections.admin.length ? '<div class="sidebar-section-label">Admin</div>' + sections.admin.map(navItem).join('') : '') +
      '<div class="sidebar-user">' +
        '<div class="sidebar-user-card">' +
          '<div class="sidebar-user-avatar" id="sb-avatar">👤</div>' +
          '<div>' +
            '<div class="sidebar-user-name" id="sb-name">—</div>' +
            '<div class="sidebar-user-phone" id="sb-phone"></div>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  // Build info panel HTML
  function buildInfoPanel() {
    var quickHTML = QUICK.map(function (q) {
      return '<a class="desk-quick-action" href="' + base() + q.page + '" onclick="return epNav(event,\'' + q.page + '\')">' +
        '<div class="desk-quick-icon">' + q.icon + '</div>' +
        '<div><div class="desk-quick-text">' + q.label + '</div>' +
        '<div class="desk-quick-sub">' + q.sub + '</div></div></a>';
    }).join('');

    return '<div class="desk-info-card">' +
        '<div class="desk-info-label">Quick Actions</div>' +
        '<div style="display:flex;flex-direction:column;gap:8px">' + quickHTML + '</div>' +
      '</div>' +
      '<div class="desk-info-card">' +
        '<div class="desk-info-label">Live Rates · ETB</div>' +
        '<div id="desk-rates-list">' +
          ['USD','EUR','GBP','SAR'].map(function(c) {
            return '<div class="desk-rate-item"><span class="desk-rate-cur">' + c + '</span>' +
              '<span class="desk-rate-val" id="drv-' + c.toLowerCase() + '">—</span></div>';
          }).join('') +
        '</div>' +
      '</div>' +
      '<div style="text-align:center;padding:16px 0">' +
        '<div style="font-family:\'Noto Sans Ethiopic\',sans-serif;font-size:38px;font-weight:700;' +
          'background:linear-gradient(135deg,#F0C84A,#D4A843);-webkit-background-clip:text;' +
          '-webkit-text-fill-color:transparent;background-clip:text;line-height:1">ኢE</div>' +
        '<div style="font-size:10px;color:rgba(240,244,255,.22);letter-spacing:2px;text-transform:uppercase;margin-top:6px">Etel Pay · v2.0</div>' +
      '</div>';
  }

  // Initialize desktop layout
  function init() {
    if (window.innerWidth < BREAKPOINT) return;
    if (initialized) return;
    initialized = true;

    var cur = currentPage();
    var app = document.getElementById('app');
    if (!app) return;

    document.body.style.cssText = 'margin:0;padding:0;overflow:hidden;background:#060810;';

    // Check if page already has ep-desktop-wrap (wallet.html does)
    var existingWrap = document.getElementById('ep-desktop-wrap');

    if (existingWrap) {
      // Just inject sidebar content into existing sidebar element
      var existingSb = document.getElementById('ep-sidebar');
      if (existingSb) existingSb.innerHTML = buildSidebar(cur, false);
      // Add info panel if missing
      if (!document.getElementById('ep-desktop-info')) {
        var info = document.createElement('div');
        info.id = 'ep-desktop-info';
        info.innerHTML = buildInfoPanel();
        existingWrap.appendChild(info);
      }
    } else {
      // Inject full desktop layout
      var wrap = document.createElement('div');
      wrap.id = 'ep-desktop-wrap';

      var sb = document.createElement('aside');
      sb.id = 'ep-sidebar';
      sb.innerHTML = buildSidebar(cur, false);

      var main = document.createElement('div');
      main.id = 'ep-desktop-main';

      var info = document.createElement('div');
      info.id = 'ep-desktop-info';
      info.innerHTML = buildInfoPanel();

      app.parentNode.insertBefore(wrap, app);
      main.appendChild(app);
      wrap.appendChild(sb);
      wrap.appendChild(main);
      wrap.appendChild(info);
    }

    loadUserData(cur);
  }

  // SPA navigation handler
  window.epNav = function (e, page) {
    e.preventDefault();
    nav(page);
    // Update active state without full reload
    document.querySelectorAll('.sidebar-nav-item').forEach(function (el) {
      el.classList.remove('active');
    });
    var target = document.querySelector('.sidebar-nav-item[href*="/' + page + '"]');
    if (target) target.classList.add('active');
    return false;
  };

  // Load user info into sidebar
  function loadUserData(cur) {
    setTimeout(function () {
      // Phone from localStorage
      try {
        var phone = localStorage.getItem('ep_phone') || '';
        var nm = document.getElementById('sb-name');
        var ph = document.getElementById('sb-phone');
        if (ph) ph.textContent = phone;
        if (nm && phone) nm.textContent = 'ኢE Pay User';
      } catch (e) {}

      // Check admin & get profile via API if available
      if (window.EP_API && window.EP_API.getBalance) {
        EP_API.getBalance().then(function (j) {
          if (!j.success) return;
          var d = j.data;
          if (d.is_admin) {
            // Re-render sidebar with admin items
            var sb2 = document.getElementById('ep-sidebar');
            if (sb2) sb2.innerHTML = buildSidebar(cur, true);
            bindUserCard();
          }
        }).catch(function () {});
      }

      if (window.EP_API && window.EP_API.getProfile) {
        EP_API.getProfile().then(function (j) {
          if (!j.success) return;
          var d = j.data;
          window.EP_DESKTOP.updateUser(d.full_name, d.phone, d.avatar_url);
        }).catch(function () {});
      }

      // Sync rates
      syncRates();
      setInterval(syncRates, 6000);
    }, 600);
  }

  function bindUserCard() {}

  // Sync rates from wallet rate bar or fetch
  function syncRates() {
    ['usd','eur','gbp','sar'].forEach(function (k) {
      var src = document.getElementById('r-' + k);
      var dst = document.getElementById('drv-' + k);
      if (src && dst && src.textContent && src.textContent !== '—') {
        dst.textContent = src.textContent + ' ETB';
      }
    });
  }

  // Public API
  window.EP_DESKTOP = {
    updateUser: function (name, phone, avatar) {
      var nm = document.getElementById('sb-name');
      var ph = document.getElementById('sb-phone');
      var av = document.getElementById('sb-avatar');
      if (nm && name) nm.textContent = name;
      if (ph && phone) ph.textContent = phone;
      if (av && avatar) av.innerHTML = '<img src="' + avatar + '" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover">';
    },
    syncRates: syncRates
  };

  // Run on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
