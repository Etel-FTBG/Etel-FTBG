/* ETEL — APK download link
   No longer pops a banner over the page. Instead each app's Profile screen
   carries a "Download the app" row marked with  data-etel-apk  and this script
   shows it (and points it at the right .apk) only in a normal browser tab —
   never inside the installed APK/TWA (display-mode: standalone), and never for
   a role that has no .apk yet.
   Include with: <script src="../js/apk-download-badge.js" data-role="driver"></script>
   Valid data-role values: customer | driver | courier | service | ride | etel | admin
   Markup contract: any element with data-etel-apk is hidden until it can be
   used; its <a> links (itself or descendants) get the .apk URL. Optional
   data-etel-apk-display="flex|block" sets how it is shown (default block).
   Rows added later (profile panels built by JS) are picked up automatically. */
(function () {
  try {
    var scriptEl = document.currentScript;
    var role = scriptEl && scriptEl.getAttribute('data-role');
    if (!role) return;

    var isStandalone =
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;

    var apkMap = {
      customer: 'ኢE_Delivery.apk',
      driver:   'ኢE_Driver.apk',
      courier:  'ኢE_Courier.apk',
      service:  'ኢE_Service.apk',
      ride:     'ኢE_Ride.apk',
      etel:     'ETEL.apk'   // the all-in-one app
      // admin: not yet provided
    };
    var file = apkMap[role];
    var available = !!file && !isStandalone;

    // Resolve the /delivery/ site root regardless of how deep the current page is
    // (delivery/, delivery/driver/, delivery/ride/, delivery/courier/, etc).
    var path = window.location.pathname;
    var base = path.replace(/\/delivery\/.*$/, '/delivery/');
    if (base === path) base = '/delivery/';
    var apkUrl = file ? base + 'apk/' + file : '';

    window.EtelApk = { available: available, url: apkUrl, role: role };
    if (!available) return; // rows stay hidden

    function hydrate(root) {
      if (!root || !root.querySelectorAll) return;
      var rows = [];
      if (root.hasAttribute && root.hasAttribute('data-etel-apk')) rows.push(root);
      Array.prototype.push.apply(rows, root.querySelectorAll('[data-etel-apk]'));
      rows.forEach(function (el) {
        if (el.getAttribute('data-etel-apk-ready')) return;
        el.setAttribute('data-etel-apk-ready', '1');
        var links = [];
        if (el.tagName === 'A') links.push(el);
        Array.prototype.push.apply(links, el.querySelectorAll('a'));
        links.forEach(function (a) { a.setAttribute('href', apkUrl); a.setAttribute('download', ''); });
        el.style.display = el.getAttribute('data-etel-apk-display') || 'block';
      });
    }

    function start() {
      hydrate(document.body);
      try {
        new MutationObserver(function (muts) {
          muts.forEach(function (m) {
            Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1) hydrate(n); });
          });
        }).observe(document.body, { childList: true, subtree: true });
      } catch (e) { /* older browsers: rows present at load still work */ }
    }

    if (document.body) start();
    else window.addEventListener('DOMContentLoaded', start);
  } catch (e) {
    // fail silently - never break the host page over a download link
  }
})();
