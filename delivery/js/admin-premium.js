/* ════════════════════════════════════════════════════════════════
   ETEL ADMIN — premium stat count-ups (ADD-ON, optional)
   Load AFTER admin.js:  <script src="js/admin-premium.js"></script>
   Animates the dashboard KPI values that admin.js sets. No edits.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var IDS = ['s-orders', 's-pending', 's-delivered', 's-users', 's-couriers', 's-revenue'];

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    IDS.forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      var mo = new MutationObserver(function () {
        if (el.dataset.anim === '1') return;
        var raw = (el.textContent || '').trim();
        if (raw === '…' || raw === '') return;                 // still loading
        var hasComma = raw.indexOf(',') >= 0;
        var num = parseFloat(raw.replace(/[^0-9.]/g, ''));
        if (isNaN(num)) return;
        var from = parseFloat(el.dataset.shown || '0');
        if (Math.abs(num - from) < 1) { el.dataset.shown = num; return; }
        count(el, from, num, hasComma);
        el.classList.remove('ap-bump'); void el.offsetWidth; el.classList.add('ap-bump');
      });
      mo.observe(el, { childList: true, characterData: true, subtree: true });
    });
  });

  function fmt(v, comma) {
    var n = Math.round(v);
    return comma ? n.toLocaleString() : String(n);
  }
  function count(el, from, to, comma) {
    if (reduced) { el.textContent = fmt(to, comma); el.dataset.shown = to; return; }
    el.dataset.anim = '1';
    var start = performance.now(), dur = 750;
    function tick(now) {
      var p = Math.min((now - start) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(from + (to - from) * e, comma);
      if (p < 1) requestAnimationFrame(tick);
      else { el.textContent = fmt(to, comma); el.dataset.shown = to; el.dataset.anim = '0'; }
    }
    requestAnimationFrame(tick);
  }
})();
