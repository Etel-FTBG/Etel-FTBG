// Injected into every web app before it loads. It tells the native shell two things:
//  - the login token (so the background location and push notifications can run for that user), and
//  - when the user goes online or offline in the web Driver app (so the shell keeps running in the background).
// Language and theme chosen in the shell are written into the web app's own storage before it loads.
export function prefsJs(lang: 'en' | 'am', mode: 'dark' | 'light'): string {
  return `(function(){try{localStorage.setItem('etel_lang','${lang}');localStorage.setItem('etel_driver_lang','${lang}');localStorage.setItem('etel_ride_theme','${mode}');}catch(e){}})();true;`;
}

export const BRIDGE_JS = `
(function () {
  if (window.__etelHubBridge) return;
  window.__etelHubBridge = true;
  function post(o) { try { window.ReactNativeWebView.postMessage(JSON.stringify(o)); } catch (e) {} }
  function sendToken() { try { var t = localStorage.getItem('etel_token'); if (t) post({ type: 'token', token: t }); } catch (e) {} }
  var origFetch = window.fetch;
  if (origFetch) {
    window.fetch = function (input, init) {
      var action = '';
      try { if (init && typeof init.body === 'string') action = (JSON.parse(init.body) || {}).action || ''; } catch (e) {}
      var p = origFetch.apply(this, arguments);
      if (action === 'driver_go_online' || action === 'driver_go_offline' || action === 'login') {
        p.then(function (r) { return r.clone().json(); }).then(function (j) {
          if (!j || j.success === false) return;
          if (action === 'driver_go_online') { sendToken(); post({ type: 'online' }); }
          else if (action === 'driver_go_offline') post({ type: 'offline' });
          else setTimeout(sendToken, 300);
        }).catch(function () {});
      }
      return p;
    };
  }
  window.addEventListener('load', sendToken);
  true;
})();
`;

// Run once the page has loaded: makes every web app fit the phone the same way. It pins the page to the phone's width (no zoomed-out or
// sideways-scrolling page), stops sideways overflow, and marks the page as running inside the app so the apps can style for it.
export const FIT_JS = `
(function () {
  try {
    var c = 'width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover';
    var ps = window.__etelPageScale;
    if (ps && ps < 0.999) { var sw = (window.screen && window.screen.width) || window.innerWidth; c = 'width=' + Math.round(sw / ps) + ', initial-scale=' + ps + ', minimum-scale=' + ps + ', maximum-scale=' + ps + ', user-scalable=no, viewport-fit=cover'; }
    var m = document.querySelector('meta[name=viewport]');
    if (!m) { m = document.createElement('meta'); m.name = 'viewport'; document.head.appendChild(m); }
    if (ps && ps < 0.999) m.content = c; else if (m.content.indexOf('maximum-scale') < 0) m.content = c;
    document.documentElement.classList.add('etel-in-app');
    if (!document.getElementById('etel-in-app-fit')) {
      var s = document.createElement('style'); s.id = 'etel-in-app-fit';
      s.textContent = 'html,body{max-width:100vw;overflow-x:hidden}html{-webkit-text-size-adjust:100%}';
      document.head.appendChild(s);
    }
  } catch (e) {}
  true;
})();
`;

// The "Page size" choice: how much of the page fits across the phone. 1 is the page at the phone's own width (about 360 px). Chrome and the TWA on the owner's
// phone drew the same pages about 1.6 times wider (a page-zoom setting of Chrome), so everything looked smaller and fitted; the app, which draws at the phone's
// width, looked too big. A smaller number lays the page out wider and shrinks it to fit, the way Chrome does. Runs BEFORE the page loads so the first layout is right.
export function pageScaleJs(scale: number): string {
  const s = Math.max(0.5, Math.min(1, Number(scale) || 1));
  return `window.__etelPageScale=${s};` + `
(function () {
  var s = window.__etelPageScale;
  if (!s || s >= 0.999) return;
  function apply() {
    var m = document.querySelector('meta[name=viewport]');
    if (!m) { if (!document.head) return false; m = document.createElement('meta'); m.name = 'viewport'; document.head.appendChild(m); }
    var sw = (window.screen && window.screen.width) || window.innerWidth;
    var v = 'width=' + Math.round(sw / s) + ', initial-scale=' + s + ', minimum-scale=' + s + ', maximum-scale=' + s + ', user-scalable=no, viewport-fit=cover';
    if (m.content !== v) m.content = v;
    return true;
  }
  var n = 0, iv = setInterval(function () { if (apply() || ++n > 300) clearInterval(iv); }, 10);
  document.addEventListener('DOMContentLoaded', apply);
})();
true;`;
}
