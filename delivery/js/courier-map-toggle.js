/* ETEL Courier - ONE button to hide / show the map (additive script, no bundle release).
   The big map pushes the jobs and their buttons far down the phone screen. One slim button above it hides the whole map (the choice is remembered on this phone);
   tapping again shows it. While the courier is in full-screen navigation the map always shows, whatever was chosen. English / Amharic (etel_lang). No inline handlers. */
(function () {
  'use strict';
  if (window.__etelMapToggle) return;
  window.__etelMapToggle = true;
  var KEY = 'etel_courier_map_hidden';
  var AM = { 'Hide map': 'ካርታውን ደብቅ', 'Show map': 'ካርታውን አሳይ', 'The map is hidden': 'ካርታው ተደብቋል' };
  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function isHidden() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function remember(v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) { /* private window: not remembered */ } }

  function css() {
    if (document.getElementById('cmt-css')) return;
    var s = document.createElement('style'); s.id = 'cmt-css';
    s.textContent =
      '.cmt-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:10px 14px 0}' +
      '.cmt-note{font-size:12.5px;opacity:.7}' +
      '.cmt-btn{display:inline-flex;align-items:center;gap:6px;border:1.5px solid rgba(212,175,55,.55);background:rgba(212,175,55,.12);color:inherit;font:inherit;font-size:13px;font-weight:700;padding:8px 14px;border-radius:999px;cursor:pointer;min-height:38px}' +
      '.cmt-btn:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}' +
      'body.cmt-hide #courier-map-container{display:none !important}' +
      'body.cmt-hide .sidebar{width:100% !important;flex:1 1 auto !important}' +
      'body.cn-on.cmt-hide #courier-map-container{display:block !important}';   /* full-screen navigation always shows the map */
    document.head.appendChild(s);
  }
  function apply() {
    var hidden = isHidden();
    document.body.classList.toggle('cmt-hide', hidden);
    var b = document.getElementById('cmt-btn'), n = document.getElementById('cmt-note');
    if (b) { b.textContent = (hidden ? '🗺 ' + T('Show map') : '🗺 ' + T('Hide map')); b.setAttribute('aria-pressed', hidden ? 'true' : 'false'); }
    if (n) n.textContent = hidden ? T('The map is hidden') : '';
    if (!hidden) { try { setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 120); } catch (e) {} }   /* the map re-measures itself when it comes back */
  }
  var tries = 0;
  (function wait() {
    var box = document.getElementById('courier-map-container');
    if (!box || !box.parentNode) { if (tries++ < 100) return setTimeout(wait, 200); return; }
    if (document.getElementById('cmt-bar')) return;
    css();
    var bar = document.createElement('div'); bar.id = 'cmt-bar'; bar.className = 'cmt-bar';
    bar.innerHTML = '<span class="cmt-note" id="cmt-note"></span><button type="button" class="cmt-btn" id="cmt-btn"></button>';
    box.parentNode.insertBefore(bar, box);
    bar.querySelector('#cmt-btn').addEventListener('click', function () { remember(!isHidden()); apply(); });
    apply();
  })();
})();
