/* ETEL Help button - ONE small script every Etel app loads so people can always reach the central Support desk.
   Add to any page:  <script src="/delivery/js/etel-help-fab.js" data-app="pay" defer></script>
   data-app is optional (ride, delivery, driver, courier, service, agent, pay, gebeya, taxi, madeya, group, id, ai); without it the app is guessed from the address.
   It only draws a round button that opens /delivery/help/?app=<app> (the Help page signs the person in with EtelID). It sends nothing and stores nothing.
   Opt out on a page with window.ETEL_NO_HELP_FAB = true. Move it with window.ETEL_HELP_FAB = { side: 'right', bottom: 90 } (set BEFORE this script). */
(function () {
'use strict';
if (window.ETEL_NO_HELP_FAB || document.getElementById('etel-help-fab')) return;
var path = (location.pathname || '/').toLowerCase();
if (/admin|updater|diag|offline/.test(path)) return;                       // staff and tool pages do not get it
var me = document.currentScript, app = me && me.getAttribute('data-app');
if (!app) {
  var qs = new URLSearchParams(location.search), role = (qs.get('role') || '').toLowerCase(), back = (qs.get('redirect') || '').toLowerCase();
  if (/\/delivery\/login/.test(path)) app = { driver: 'driver', ride: 'ride', courier: 'courier', service_giver: 'service', customer: 'delivery' }[role] || (/^\/?ride/.test(back) ? 'ride' : (/^\/?driver/.test(back) ? 'driver' : (/^\/?courier/.test(back) ? 'courier' : (/^\/?servicegiver/.test(back) ? 'service' : 'delivery'))));
  else if (/\/delivery\/ride/.test(path)) app = 'ride';
  else if (/\/delivery\/driver/.test(path)) app = 'driver';
  else if (/\/delivery\/courier/.test(path)) app = 'courier';
  else if (/\/delivery\/servicegiver/.test(path)) app = 'service';
  else if (/\/delivery\/agent/.test(path)) app = 'agent';
  else if (/\/delivery\//.test(path)) app = 'delivery';
  else if (/\/etel\.pay|\/etelpay/.test(path)) app = 'pay';
  else if (/\/etelgebeya/.test(path)) app = 'gebeya';
  else if (/\/eteltaxi/.test(path)) app = 'taxi';
  else if (/\/etelmadeya/.test(path)) app = 'madeya';
  else if (/\/etelid/.test(path)) app = 'id';
  else if (/\/etelai/.test(path)) app = 'ai';
  else app = 'group';
}
var lang = 'am';
try { var l = localStorage.getItem('etel_help_lang') || localStorage.getItem('etel_agent_lang') || localStorage.getItem('lang') || localStorage.getItem('etel_lang') || document.documentElement.lang || ''; lang = String(l).toLowerCase().indexOf('en') === 0 ? 'en' : 'am'; } catch (e) { /* default */ }
var label = lang === 'en' ? 'Help and support' : '\u12a5\u1308\u12db \u12a5\u1293 \u12f5\u130b\u134d';
var o = window.ETEL_HELP_FAB || {}, side = o.side === 'right' ? 'right' : 'left', bottom = parseInt(o.bottom, 10) || 16;
var st = document.createElement('style');
st.textContent = '#etel-help-fab{position:fixed;' + side + ':14px;bottom:calc(' + bottom + 'px + env(safe-area-inset-bottom,0px));z-index:900;width:48px;height:48px;border-radius:50%;display:grid;place-items:center;' +
  'background:linear-gradient(135deg,#B8860B,#E6C85A 55%,#C8960C);color:#1a1206;box-shadow:0 6px 18px rgba(0,0,0,.35),0 0 0 2px rgba(255,255,255,.35);text-decoration:none;-webkit-tap-highlight-color:transparent}' +
  '#etel-help-fab:active{transform:scale(.94)}#etel-help-fab:focus-visible{outline:3px solid #1a1206;outline-offset:3px}#etel-help-fab svg{width:26px;height:26px}' +
  '@media print{#etel-help-fab{display:none!important}}';
document.head.appendChild(st);
var a = document.createElement('a');
var base = '/delivery/help/'; try { if (me && me.src) base = new URL('../help/', me.src).pathname; } catch (e) { /* keep the default */ }
a.id = 'etel-help-fab'; a.href = base + '?app=' + encodeURIComponent(app); a.setAttribute('aria-label', label); a.title = label;
a.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path d="M19 20c0 1.5-2 2-4 2h-2"/></svg>';
(document.body || document.documentElement).appendChild(a);
})();
