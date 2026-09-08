/**
 * ኢE Pay — Desktop Layout Engine
 * Activates on 1024px+ screens
 * Adds sidebar nav + right info panel around the phone frame
 */
(function() {
  if (typeof window === 'undefined') return;

  var PAGES = {
    wallet:    {icon:'🏠', label:'Dashboard'},
    send:      {icon:'💸', label:'Send Money'},
    history:   {icon:'📋', label:'History'},
    qr:        {icon:'⬛', label:'QR Code'},
    topup:     {icon:'💳', label:'Top Up'},
    'pay-links':{icon:'🔗', label:'Payment Links'},
    analytics: {icon:'📊', label:'Analytics'},
    profile:   {icon:'👤', label:'Profile'},
    kyc:       {icon:'🪪', label:'Verify ID'},
    admin:     {icon:'⚙️', label:'Admin Panel', admin:true},
    updater:   {icon:'⬆',  label:'Updater', admin:true},
  };

  function currentPage() {
    var p = window.location.pathname.split('/').pop().replace('.html','');
    return p || 'wallet';
  }

  function initDesktop() {
    if (window.innerWidth < 1024) return;
    if (document.getElementById('ep-sidebar')) return; // already done

    var app = document.getElementById('app');
    if (!app) return;

    // Build outer wrapper
    var wrap = document.createElement('div');
    wrap.id = 'ep-desktop-wrap';
    wrap.style.cssText = 'display:flex;width:100vw;height:100vh;overflow:hidden;';

    // Sidebar
    var sb = document.createElement('aside');
    sb.id = 'ep-sidebar';
    sb.innerHTML = buildSidebarHTML();

    // Main area
    var mainArea = document.createElement('div');
    mainArea.id = 'ep-desktop-main';

    // Right info panel
    var infoPanel = document.createElement('div');
    infoPanel.id = 'ep-desktop-info';
    infoPanel.innerHTML = buildInfoHTML();

    // Move app into main
    app.parentNode.insertBefore(wrap, app);
    mainArea.appendChild(app);
    mainArea.appendChild(infoPanel);
    wrap.appendChild(sb);
    wrap.appendChild(mainArea);

    document.body.style.cssText = 'margin:0;padding:0;overflow:hidden;background:#04060A;';

    loadSidebarUser();
    loadDesktopRates();
  }

  function buildSidebarHTML() {
    var cur = currentPage();
    var html = '<div class="sidebar-logo">' +
      '<img src="' + getBase() + 'icons/logo.png" alt="ኢE" onerror="this.style.display=\'none\'">' +
      '<div class="sidebar-logo-text">' +
      '<div class="sidebar-logo-name">ኢE Pay</div>' +
      '<div class="sidebar-logo-sub">Ethiopia\'s Digital Wallet</div>' +
      '</div></div>';

    html += '<div class="sidebar-section-label">Navigation</div>';
    var adminPages = [];
    Object.keys(PAGES).forEach(function(key) {
      var p = PAGES[key];
      if (p.admin) { adminPages.push(key); return; }
      html += navBtn(key, p.icon, p.label, key === cur);
    });

    html += '<div id="sb-admin-block" style="display:none">' +
      '<div class="sidebar-section-label">Admin</div>';
    adminPages.forEach(function(key) {
      var p = PAGES[key];
      html += navBtn(key, p.icon, p.label, key === cur);
    });
    html += '</div>';

    html += '<div class="sidebar-user">' +
      '<div class="sidebar-user-card">' +
      '<div class="sidebar-user-avatar" id="sb-av">👤</div>' +
      '<div><div class="sidebar-user-name" id="sb-nm">—</div>' +
      '<div class="sidebar-user-phone" id="sb-ph"></div></div>' +
      '</div></div>';
    return html;
  }

  function navBtn(page, icon, label, active) {
    var base = getBase();
    return '<a class="sidebar-nav-item' + (active?' active':'') + '" href="' + base + page + '">' +
      '<span class="sidebar-nav-icon">' + icon + '</span>' + label + '</a>';
  }

  function buildInfoHTML() {
    return '<div class="desk-info-card">' +
      '<div class="desk-info-label">Quick Actions</div>' +
      '<div style="display:flex;flex-direction:column;gap:8px">' +
      deskBtn('↗','Send Money','Transfer to any wallet','send') +
      deskBtn('💳','Top Up','Add funds via Telebirr','topup') +
      deskBtn('🔗','Pay Links','Create payment links','pay-links') +
      deskBtn('📊','Analytics','View your statistics','analytics') +
      '</div></div>' +
      '<div class="desk-info-card" id="desk-rates-card">' +
      '<div class="desk-info-label">Live Rates · ETB</div>' +
      '<div id="desk-rates-list"><div style="color:rgba(241,245,255,.3);font-size:13px">Loading…</div></div>' +
      '</div>' +
      '<div style="text-align:center;padding:12px 0">' +
      '<div style="font-family:\'Noto Sans Ethiopic\',sans-serif;font-size:36px;font-weight:700;background:linear-gradient(135deg,#F0C84A,#D4A843);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1">ኢE</div>' +
      '<div style="font-size:10px;color:rgba(241,245,255,.22);letter-spacing:2px;text-transform:uppercase;margin-top:6px">Etel Pay · Ethiopia</div>' +
      '<div style="font-size:11px;color:rgba(241,245,255,.18);margin-top:4px">v2.0 · ethelmarket.com</div>' +
      '</div>';
  }

  function deskBtn(icon, label, sub, page) {
    var base = getBase();
    return '<a class="desk-quick-action" href="' + base + page + '">' +
      '<div class="desk-quick-icon">' + icon + '</div>' +
      '<div><div class="desk-quick-text">' + label + '</div>' +
      '<div class="desk-quick-sub">' + sub + '</div></div></a>';
  }

  function getBase() {
    var p = window.location.pathname;
    return window.location.origin + p.substring(0, p.lastIndexOf('/') + 1);
  }

  function loadSidebarUser() {
    setTimeout(function() {
      try {
        var phone = localStorage.getItem('ep_phone') || '';
        var el = document.getElementById('sb-ph');
        if (el) el.textContent = phone;
        var nm = document.getElementById('sb-nm');
        if (nm) nm.textContent = phone ? 'ኢE Pay User' : 'Guest';
      } catch(e) {}
    }, 400);
  }

  function loadDesktopRates() {
    setTimeout(function() {
      var rates = ['USD','EUR','GBP','SAR'];
      var rateEl = document.getElementById('desk-rates-list');
      if (!rateEl) return;

      // Try to read from wallet rate bar first
      var items = rates.map(function(cur) {
        var srcId = 'r-' + cur.toLowerCase();
        var src = document.getElementById(srcId);
        var val = src ? src.textContent : '—';
        return '<div class="desk-rate-item">' +
          '<span class="desk-rate-cur">' + cur + '</span>' +
          '<span class="desk-rate-val" id="drv-' + cur.toLowerCase() + '">' + val + '</span>' +
          '</div>';
      }).join('');
      rateEl.innerHTML = items;

      // Keep syncing
      setInterval(function() {
        rates.forEach(function(cur) {
          var src = document.getElementById('r-' + cur.toLowerCase());
          var dst = document.getElementById('drv-' + cur.toLowerCase());
          if (src && dst && src.textContent !== '—') dst.textContent = src.textContent;
        });
      }, 5000);
    }, 1500);
  }

  // Public API for wallet page to call
  window.EP_DESKTOP = {
    showAdmin: function() {
      var el = document.getElementById('sb-admin-block');
      if (el) el.style.display = 'block';
    },
    updateUser: function(name, phone, avatar) {
      var nm = document.getElementById('sb-nm');
      var ph = document.getElementById('sb-ph');
      var av = document.getElementById('sb-av');
      if (nm) nm.textContent = name || '—';
      if (ph) ph.textContent = phone || '';
      if (av && avatar) av.innerHTML = '<img src="' + avatar + '" alt="">';
    }
  };

  // Init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDesktop);
  } else {
    setTimeout(initDesktop, 0);
  }
})();
