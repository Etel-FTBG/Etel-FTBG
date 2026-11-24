/* ETEL Courier - ONE button to hide / show the earnings (the "Today's earnings" box and the weekly statement under it).
   Why: with the earnings box open, the job list and the buttons under each job (Show Pickup QR, Request pickup confirmation) are pushed off the
   screen on a phone. One slim button sits above the box; tapping it hides both cards (the choice is remembered on this phone), tapping again shows
   them. Additive script (no bundle release): the two cards keep drawing themselves; this only adds a class to them. English / Amharic (etel_lang). */
(function () {
  'use strict';
  if (window.__etelEarnToggle) return;
  window.__etelEarnToggle = true;
  var KEY = 'etel_courier_earn_hidden';
  var AM = { 'Hide earnings': 'ገቢን ደብቅ', 'Show earnings': 'ገቢን አሳይ', 'Earnings are hidden': 'ገቢ ተደብቋል' };

  function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
  function T(s) { return lang() === 'am' && AM[s] ? AM[s] : s; }
  function isHidden() { try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; } }
  function remember(v) { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch (e) { /* private window: not remembered */ } }

  function css() {
    if (document.getElementById('ect-css')) return;
    var s = document.createElement('style'); s.id = 'ect-css';
    s.textContent =
      '.ect-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:10px 14px 0}' +
      '.ect-note{font-size:12.5px;opacity:.7}' +
      '.ect-btn{display:inline-flex;align-items:center;gap:6px;border:1.5px solid rgba(212,175,55,.55);background:rgba(212,175,55,.12);color:inherit;font:inherit;font-size:13px;font-weight:700;padding:8px 14px;border-radius:999px;cursor:pointer;min-height:38px}' +
      '.ect-btn:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}' +
      '.ect-off{display:none !important}';
    document.head.appendChild(s);
  }

  function apply() {
    var hidden = isHidden();
    var earn = document.querySelector('.earn-card'), st = document.getElementById('cst-card');
    if (earn) earn.classList.toggle('ect-off', hidden);
    if (st) st.classList.toggle('ect-off', hidden);
    var b = document.getElementById('ect-btn'), n = document.getElementById('ect-note');
    if (b) { b.textContent = (hidden ? '👁 ' + T('Show earnings') : '🙈 ' + T('Hide earnings')); b.setAttribute('aria-pressed', hidden ? 'true' : 'false'); }
    if (n) n.textContent = hidden ? T('Earnings are hidden') : '';
  }

  var tries = 0;
  (function wait() {
    var earn = document.querySelector('.earn-card');
    if (!earn || !earn.parentNode) { if (tries++ < 100) return setTimeout(wait, 200); return; }
    if (document.getElementById('ect-bar')) return;
    css();
    var bar = document.createElement('div'); bar.id = 'ect-bar'; bar.className = 'ect-bar';
    bar.innerHTML = '<span class="ect-note" id="ect-note"></span><button type="button" class="ect-btn" id="ect-btn"></button>';
    earn.parentNode.insertBefore(bar, earn);
    bar.querySelector('#ect-btn').addEventListener('click', function () { remember(!isHidden()); apply(); });
    apply();
    // the weekly statement card is added a moment later, and a card may be drawn again: keep both in step
    if (window.MutationObserver) new MutationObserver(function () { var st = document.getElementById('cst-card'); if (st && st.classList.contains('ect-off') !== isHidden()) apply(); }).observe(earn.parentNode, { childList: true });
  })();
})();
