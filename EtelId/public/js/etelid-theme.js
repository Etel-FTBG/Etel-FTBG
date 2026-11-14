/**
 * EtelID public pages - theme and text-size controls (light / dark, three text sizes).
 * Used on the NFC card page and the sign-in page. Loaded in <head> (no defer) so the saved choice is applied before first paint.
 * The theme is stored in localStorage 'theme' - the SAME key the EtelID portal and admin console, the Delivery admin and the
 * Group admins already use - so one choice follows the person across the systems. Text size is stored in 'etel_textsize'.
 * data-default="light|dark" on the script tag is the look used when nothing is saved yet.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  var cs = document.currentScript;
  var def = (cs && cs.getAttribute('data-default') === 'light') ? 'light' : 'dark';
  var KEY_T = 'theme', KEY_S = 'etel_textsize';
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* not remembered */ } }

  var t0 = get(KEY_T);
  var theme = (t0 === 'light' || t0 === 'dark') ? t0 : def;
  var sizes = ['md', 'lg', 'xl'];
  var size = sizes.indexOf(get(KEY_S)) >= 0 ? get(KEY_S) : 'md';

  var ICON_SUN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M5.2 5.2l1.7 1.7M17.1 17.1l1.7 1.7M5.2 18.8l1.7-1.7M17.1 6.9l1.7-1.7"/></svg>';
  var ICON_MOON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.6 8.6 0 0 1 9.8 3.5a8.6 8.6 0 1 0 10.7 10.7z"/></svg>';

  function apply() {
    root.setAttribute('data-eid-theme', theme);
    root.setAttribute('data-eid-textsize', size);
    try {
      var m = document.querySelector('meta[name="theme-color"]');
      if (!m) { m = document.createElement('meta'); m.name = 'theme-color'; (document.head || root).appendChild(m); }
      m.setAttribute('content', theme === 'light' ? '#F6EFD9' : '#1c1100');
    } catch (e) { /* meta not ready */ }
    var b = document.getElementById('eid-theme-btn');
    if (b) {
      b.setAttribute('aria-label', 'Display settings: theme and text size | የማሳያ ቅንብር');
      b.innerHTML = theme === 'light' ? ICON_SUN : ICON_MOON;
    }
    var segs = document.querySelectorAll('#eid-theme-pop button');
    for (var i = 0; i < segs.length; i++) {
      var on = (segs[i].getAttribute('data-theme-set') === theme) || (segs[i].getAttribute('data-size-set') === size);
      segs[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    try { window.dispatchEvent(new CustomEvent('etelid:theme', { detail: { theme: theme, size: size } })); } catch (e) { /* old browser */ }
  }

  function build() {
    if (document.getElementById('eid-theme-fab')) return;
    var box = document.createElement('div');
    box.id = 'eid-theme-fab';
    box.innerHTML =
      '<button type="button" id="eid-theme-btn" aria-haspopup="true" aria-expanded="false" aria-controls="eid-theme-pop"></button>' +
      '<div id="eid-theme-pop" role="group" hidden>' +
        '<div class="eid-tp-row"><span class="eid-tp-lbl">Theme | ገጽታ</span>' +
          '<span class="eid-tp-seg"><button type="button" data-theme-set="light">Light</button><button type="button" data-theme-set="dark">Dark</button></span></div>' +
        '<div class="eid-tp-row"><span class="eid-tp-lbl">Text size | መጠን</span>' +
          '<span class="eid-tp-seg"><button type="button" data-size-set="md" style="font-size:13px">A</button><button type="button" data-size-set="lg" style="font-size:16px">A</button><button type="button" data-size-set="xl" style="font-size:19px">A</button></span></div>' +
      '</div>';
    (document.body || root).appendChild(box);
    var btn = document.getElementById('eid-theme-btn'), pop = document.getElementById('eid-theme-pop');
    function open(v) { pop.hidden = !v; btn.setAttribute('aria-expanded', v ? 'true' : 'false'); }
    btn.addEventListener('click', function (e) { e.stopPropagation(); open(pop.hidden); });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) open(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });
    pop.addEventListener('click', function (e) {
      var t = e.target.closest('button'); if (!t) return;
      if (t.hasAttribute('data-theme-set')) { theme = t.getAttribute('data-theme-set') === 'light' ? 'light' : 'dark'; put(KEY_T, theme); }
      if (t.hasAttribute('data-size-set')) { size = t.getAttribute('data-size-set'); put(KEY_S, size); }
      apply();
    });
    apply();
  }

  apply();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
  window.addEventListener('storage', function (e) {
    if (e.key === KEY_T && (e.newValue === 'light' || e.newValue === 'dark')) { theme = e.newValue; apply(); }
    if (e.key === KEY_S && sizes.indexOf(e.newValue) >= 0) { size = e.newValue; apply(); }
  });
})();
