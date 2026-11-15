/* Etel Taxi - display settings: light / dark theme and three text sizes.
   Loaded in <head> (no defer) so the saved text size is applied before first paint; the buttons are built once the page is ready.
   Every existing "Light/Dark" pill button becomes one round display button that opens a small panel (theme + text size).
   Same storage key for the theme as before (etaxi_theme), plus etaxi_textsize. Nothing here touches login, payments or the API. */
(function () {
  'use strict';
  var KEY_T = 'etaxi_theme', KEY_S = 'etaxi_textsize';
  var root = document.documentElement;
  var SIZES = ['md', 'lg', 'xl'];
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* not remembered */ } }
  function theme() { return get(KEY_T) === 'light' ? 'light' : 'dark'; }
  function size() { var s = get(KEY_S); return SIZES.indexOf(s) >= 0 ? s : 'md'; }

  root.setAttribute('data-textsize', size());                       // before first paint

  var SUN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8"/></svg>';
  var MOON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5z"/></svg>';

  var wraps = [];
  function repaint() {
    var t = theme(), s = size();
    root.setAttribute('data-textsize', s);
    try { var m = document.querySelector('meta[name="theme-color"]'); if (m) m.setAttribute('content', t === 'light' ? '#FFFFFF' : '#000000'); } catch (e) { /* none */ }
    for (var i = 0; i < wraps.length; i++) {
      var w = wraps[i];
      w.btn.innerHTML = t === 'light' ? SUN : MOON;
      var segs = w.pop.querySelectorAll('button[data-theme-set],button[data-size-set]');
      for (var j = 0; j < segs.length; j++) {
        var on = segs[j].getAttribute('data-theme-set') === t || segs[j].getAttribute('data-size-set') === s;
        segs[j].setAttribute('aria-pressed', on ? 'true' : 'false');
      }
    }
  }
  function closeAll(except) {
    for (var i = 0; i < wraps.length; i++) if (wraps[i] !== except) { wraps[i].pop.hidden = true; wraps[i].btn.setAttribute('aria-expanded', 'false'); }
  }
  function build(old) {
    if (old.getAttribute('data-tx-done')) return;
    old.setAttribute('data-tx-done', '1');
    old.removeAttribute('onclick');
    var wrap = document.createElement('span'); wrap.className = 'tx-disp';
    old.parentNode.insertBefore(wrap, old); wrap.appendChild(old);
    old.classList.add('tx-disp-btn');
    old.setAttribute('type', 'button'); old.setAttribute('aria-haspopup', 'true'); old.setAttribute('aria-expanded', 'false');
    old.setAttribute('aria-label', 'Display settings: theme and text size');
    var pop = document.createElement('div'); pop.className = 'tx-pop'; pop.setAttribute('role', 'group'); pop.hidden = true;
    pop.innerHTML =
      '<div class="tx-row"><span class="tx-lbl">Theme</span><span class="tx-seg"><button type="button" data-theme-set="light">Light</button><button type="button" data-theme-set="dark">Dark</button></span></div>' +
      '<div class="tx-row"><span class="tx-lbl">Text size</span><span class="tx-seg"><button type="button" data-size-set="md" style="font-size:13px">A</button><button type="button" data-size-set="lg" style="font-size:16px">A</button><button type="button" data-size-set="xl" style="font-size:19px">A</button></span></div>';
    wrap.appendChild(pop);
    var rec = { btn: old, pop: pop, wrap: wrap };
    wraps.push(rec);
    old.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = pop.hidden;
      closeAll(rec);
      var r = old.getBoundingClientRect();
      pop.classList.toggle('tx-left', (r.left + r.width / 2) < (window.innerWidth / 2));
      pop.hidden = !open; old.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    pop.addEventListener('click', function (e) {
      var t = e.target.closest('button'); if (!t) return;
      if (t.hasAttribute('data-theme-set')) { var v = t.getAttribute('data-theme-set') === 'light' ? 'light' : 'dark'; put(KEY_T, v); root.setAttribute('data-theme', v); }
      if (t.hasAttribute('data-size-set')) put(KEY_S, t.getAttribute('data-size-set'));
      if (typeof taxiApplyTheme === 'function') taxiApplyTheme(theme());
      repaint();
    });
  }
  function boot() {
    // core.js has run by now: keep its own label code from overwriting the icon
    if (typeof taxiApplyTheme === 'function' && !window.__txWrapped) {
      var orig = taxiApplyTheme; window.__txWrapped = true;
      window.taxiApplyTheme = function (t) { orig(t); repaint(); };
    }
    var olds = document.querySelectorAll('.theme-toggle-btn');
    for (var i = 0; i < olds.length; i++) build(olds[i]);
    repaint();
  }
  document.addEventListener('click', function (e) { for (var i = 0; i < wraps.length; i++) if (wraps[i].wrap.contains(e.target)) return; closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
  window.addEventListener('storage', function (ev) { if (ev.key === KEY_S || ev.key === KEY_T) repaint(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
