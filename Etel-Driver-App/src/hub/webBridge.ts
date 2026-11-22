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
    var m = document.querySelector('meta[name=viewport]');
    if (!m) { m = document.createElement('meta'); m.name = 'viewport'; document.head.appendChild(m); }
    if (m.content.indexOf('maximum-scale') < 0) m.content = c;
    document.documentElement.classList.add('etel-in-app');
    // The Courier page inside the app was drawn about 1.5 times too big (a top bar that wraps, buttons pushed off the screen), unlike the same page in the
    // TWA. When the page is laid out clearly narrower than the screen the phone has zoomed it in: lay it out at the screen's width again. This does nothing when
    // the two match. Either way a small note (sizes only, no account data) goes to the page-error list (app "inapp", System health) so a wrong guess shows up.
    setTimeout(function () {
      try {
        var sw = window.screen && window.screen.width, iw = window.innerWidth, vv = window.visualViewport, dpr = window.devicePixelRatio || 1, fixed = 0;
        var before = m ? m.content : '';
        if (m && sw > 300 && iw > 0 && iw < sw * 0.9 && !window.__etelFitFixed) { window.__etelFitFixed = 1; fixed = 1; m.content = 'width=' + sw + ', viewport-fit=cover'; }
        var base = location.pathname.split('/delivery/')[0] + '/delivery/api/client-error.php';
        var msg = 'LAYOUT inner=' + iw + ' screen=' + sw + 'x' + (window.screen && window.screen.height) + ' dpr=' + dpr + ' vv=' + (vv ? Math.round(vv.scale * 100) / 100 + '/' + Math.round(vv.width) : '-') + ' fixed=' + fixed + ' meta=' + before + ' font=' + getComputedStyle(document.body).fontSize;
        fetch(location.origin + base, { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ app: 'inapp', kind: 'js', page: location.pathname, message: msg, source: 'w' + iw + '-s' + sw, line: Math.round(dpr * 100) }) }).catch(function () {});
      } catch (e) {}
    }, 3500);
    if (!document.getElementById('etel-in-app-fit')) {
      var s = document.createElement('style'); s.id = 'etel-in-app-fit';
      s.textContent = 'html,body{max-width:100vw;overflow-x:hidden}html{-webkit-text-size-adjust:100%}';
      document.head.appendChild(s);
    }
  } catch (e) {}
  true;
})();
`;
