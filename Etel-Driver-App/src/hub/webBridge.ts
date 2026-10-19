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
