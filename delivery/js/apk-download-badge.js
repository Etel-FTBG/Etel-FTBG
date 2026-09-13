/* ETEL — APK download badge
   Shows a small dismissible top banner prompting a direct .apk download,
   but only in a normal browser tab — never inside the installed
   APK/TWA itself (display-mode: standalone), so installed users never see it.
   Include with: <script src="../js/apk-download-badge.js" data-role="driver"></script>
   Valid data-role values: customer | driver | courier | service | ride | admin */
(function () {
  try {
    var scriptEl = document.currentScript;
    var role = scriptEl && scriptEl.getAttribute('data-role');
    if (!role) return;

    var isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;
    if (isStandalone) return; // already running as the installed app - don't nag

    var dismissKey = 'etel_apk_badge_dismissed_' + role;
    if (localStorage.getItem(dismissKey)) return;

    var apkMap = {
      customer: '\u12A2E_Delivery.apk',
      driver:   '\u12A2E_Driver.apk',
      courier:  '\u12A2E_Courier.apk',
      service:  '\u12A2E_Service.apk',
      ride:     '\u12A2E_Ride.apk'
      // admin: not yet provided
    };
    var file = apkMap[role];
    if (!file) return;

    // Resolve the /delivery/ site root regardless of how deep the current page is
    // (delivery/, delivery/driver/, delivery/ride/, delivery/courier/, etc).
    var path = window.location.pathname;
    var base = path.replace(/\/delivery\/.*$/, '/delivery/');
    if (base === path) base = '/delivery/';
    var apkUrl = base + 'apk/' + file;

    var bar = document.createElement('div');
    bar.id = 'etel-apk-badge';
    bar.style.cssText =
      'position:fixed;top:0;left:0;right:0;z-index:99999;' +
      'background:linear-gradient(135deg,#C8960C,#D4AF37);color:#0d0d0d;' +
      "font-family:'Poppins',Sora,sans-serif;font-size:13px;font-weight:700;" +
      'display:flex;align-items:center;justify-content:center;gap:10px;' +
      'padding:10px 14px;box-shadow:0 2px 10px rgba(0,0,0,.35);' +
      'flex-wrap:wrap;text-align:center';

    var msg = document.createElement('span');
    msg.textContent = '📦 Get the ETEL app — faster, and opens even with a weak connection';

    var link = document.createElement('a');
    link.href = apkUrl;
    link.textContent = 'Download APK';
    link.style.cssText =
      'background:#0d0d0d;color:#D4AF37;padding:6px 14px;border-radius:20px;' +
      'text-decoration:none;font-weight:800;white-space:nowrap';

    var close = document.createElement('span');
    close.textContent = '\u00D7';
    close.setAttribute('aria-label', 'Dismiss');
    close.style.cssText =
      'cursor:pointer;font-size:18px;line-height:1;padding:0 4px;font-weight:900';
    close.onclick = function () {
      bar.remove();
      try { localStorage.setItem(dismissKey, '1'); } catch (e) {}
    };

    bar.appendChild(msg);
    bar.appendChild(link);
    bar.appendChild(close);

    if (document.body) {
      document.body.appendChild(bar);
    } else {
      window.addEventListener('DOMContentLoaded', function () {
        document.body.appendChild(bar);
      });
    }
  } catch (e) {
    // fail silently - never break the host page over a promo banner
  }
})();
