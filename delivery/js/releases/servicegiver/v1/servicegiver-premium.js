/* ════════════════════════════════════════════════════════════════
   ETEL SERVICE GIVER — premium KPI count-ups (ADD-ON, optional)
   Load AFTER servicegiver.js: <script src="js/servicegiver-premium.js"></script>
   Animates dashboard / analytics KPI values that servicegiver.js sets.
   ════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var IDS = ['kpi-today-orders', 'kpi-completed', 'kpi-earnings', 'kpi-rating', 'kpi-reviews',
             'analytics-accuracy'];

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    IDS.forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      var mo = new MutationObserver(function () {
        if (el.dataset.anim === '1') return;
        var raw = (el.textContent || '').trim();
        var num = parseFloat(raw.replace(/[^0-9.]/g, ''));
        if (isNaN(num)) return;
        var from = parseFloat(el.dataset.shown || '0');
        var fmt = detectFmt(id, raw);
        if (Math.abs(num - from) < (fmt.dec ? 0.05 : 1)) { el.dataset.shown = num; return; }
        count(el, from, num, fmt);
        el.classList.remove('sg-bump'); void el.offsetWidth; el.classList.add('sg-bump');
      });
      mo.observe(el, { childList: true, characterData: true, subtree: true });
    });
  });

  function detectFmt(id, raw) {
    var f = { prefix: '', suffix: '', dec: 0, comma: false };
    if (/br/i.test(raw)) { f.prefix = 'Br '; f.comma = true; }
    if (raw.indexOf('%') >= 0) f.suffix = '%';
    if (id === 'kpi-rating') f.dec = 1;
    return f;
  }
  function render(v, f) {
    var n = f.dec ? v.toFixed(f.dec) : (f.comma ? Math.round(v).toLocaleString() : String(Math.round(v)));
    return f.prefix + n + f.suffix;
  }
  function count(el, from, to, f) {
    if (reduced) { el.textContent = render(to, f); el.dataset.shown = to; return; }
    el.dataset.anim = '1';
    var start = performance.now(), dur = 720;
    function tick(now) {
      var p = Math.min((now - start) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      el.textContent = render(from + (to - from) * e, f);
      if (p < 1) requestAnimationFrame(tick);
      else { el.textContent = render(to, f); el.dataset.shown = to; el.dataset.anim = '0'; }
    }
    requestAnimationFrame(tick);
  }
})();
