/* ETEL Admin - tells the owner when js/admin.js on the server is the WRONG file (additive script, loaded right after js/admin.js).
   A file from another Etel system (for example the old EtelGroup admin.js, which calls showLogin() and fails with "Cannot read properties of null (reading 'style')")
   or an old copy can end up in delivery/js/ when files are uploaded by hand. The Admin still half-works, so nobody notices. This shows one red bar at the top
   that says what is wrong and what to do. Nothing is shown when the right file is there. Text only; fails quietly. */
(function () {
  'use strict';
  if (window.__etelAdminFileCheck) return;
  window.__etelAdminFileCheck = true;
  function check() {
    if (typeof window.orderIssuesFilterBtn === 'function') return;   // the Delivery admin.js defines this; the wrong file does not
    if (document.getElementById('etel-wrong-file-bar')) return;
    var other = typeof window.showLogin === 'function';
    var bar = document.createElement('div');
    bar.id = 'etel-wrong-file-bar'; bar.setAttribute('role', 'alert');
    bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483000;padding:10px 44px 10px 14px;background:#B3261E;color:#fff;font:600 13px/1.4 system-ui,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.4)';
    bar.textContent = (other ? 'Wrong file on this server: delivery/js/admin.js belongs to another Etel system. ' : 'The file delivery/js/admin.js on this server is old or incomplete. ') +
      'Upload delivery/js/admin.js from the latest ETEL zip, then press Ctrl+F5.';
    var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', 'Close');
    x.style.cssText = 'position:absolute;right:8px;top:6px;width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.2);color:#fff;font-size:20px;line-height:1;cursor:pointer';
    x.addEventListener('click', function () { if (bar.parentNode) bar.parentNode.removeChild(bar); });
    bar.appendChild(x); document.body.appendChild(bar);
  }
  if (document.readyState === 'complete') setTimeout(check, 300); else window.addEventListener('load', function () { setTimeout(check, 600); });
})();
