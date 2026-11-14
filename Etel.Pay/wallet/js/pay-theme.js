/**
 * ኢE Pay - theme and text-size controls (light / dark, three text sizes).
 * Loaded in <head> of every page (no defer) so the saved choice is applied before first paint.
 * The choice is kept per device in localStorage; the default is the existing dark gold look.
 * Nothing here touches money logic, login or the API.
 */
(function () {
  'use strict';
  var KEY_T = 'etelpay_theme', KEY_S = 'etelpay_textsize';
  var root = document.documentElement;

  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* not remembered */ } }

  var theme = get(KEY_T) === 'light' ? 'light' : 'dark';
  var sizes = ['md', 'lg', 'xl'];
  var size = sizes.indexOf(get(KEY_S)) >= 0 ? get(KEY_S) : 'md';

  function metaColor() {
    var m = document.querySelector('meta[name="theme-color"]');
    if (!m) { m = document.createElement('meta'); m.name = 'theme-color'; (document.head || root).appendChild(m); }
    return m;
  }
  function apply() {
    root.setAttribute('data-theme', theme);
    root.setAttribute('data-textsize', size);
    try {
      metaColor().setAttribute('content', theme === 'light' ? '#F6EFD9' : '#0a0703');
      var cs = document.querySelector('meta[name="color-scheme"]');
      if (cs) cs.setAttribute('content', theme);
    } catch (e) { /* meta not ready */ }
    var b = document.getElementById('ep-theme-btn');
    if (b) {
      b.setAttribute('aria-label', 'Display settings: theme and text size | የማሳያ ቅንብር');
      b.innerHTML = theme === 'light' ? ICON_SUN : ICON_MOON;
    }
    var segs = document.querySelectorAll('#ep-theme-pop button');
    for (var i = 0; i < segs.length; i++) {
      var on = (segs[i].getAttribute('data-theme-set') === theme) || (segs[i].getAttribute('data-size-set') === size);
      segs[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    try { window.dispatchEvent(new CustomEvent('etelpay:theme', { detail: { theme: theme, size: size } })); } catch (e) { /* old browser */ }
  }

  var ICON_SUN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.4M12 19.1v2.4M2.5 12h2.4M19.1 12h2.4M5.2 5.2l1.7 1.7M17.1 17.1l1.7 1.7M5.2 18.8l1.7-1.7M17.1 6.9l1.7-1.7"/></svg>';
  var ICON_MOON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.6 8.6 0 0 1 9.8 3.5a8.6 8.6 0 1 0 10.7 10.7z"/></svg>';

  function build() {
    if (document.getElementById('ep-theme-fab')) return;
    var box = document.createElement('div');
    box.id = 'ep-theme-fab';
    box.innerHTML =
      '<button type="button" id="ep-theme-btn" aria-haspopup="true" aria-expanded="false" aria-controls="ep-theme-pop"></button>' +
      '<div id="ep-theme-pop" role="group" hidden>' +
        '<div class="ep-tp-row"><span class="ep-tp-lbl">Theme | ገጽታ</span>' +
          '<span class="ep-tp-seg"><button type="button" data-theme-set="light">Light</button><button type="button" data-theme-set="dark">Dark</button></span></div>' +
        '<div class="ep-tp-row"><span class="ep-tp-lbl">Text size | መጠን</span>' +
          '<span class="ep-tp-seg"><button type="button" data-size-set="md" style="font-size:13px">A</button><button type="button" data-size-set="lg" style="font-size:16px">A</button><button type="button" data-size-set="xl" style="font-size:19px">A</button></span></div>' +
      '</div>';
    (document.body || root).appendChild(box);
    var btn = document.getElementById('ep-theme-btn'), pop = document.getElementById('ep-theme-pop');
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

  // other tabs / windows keep in step
  window.addEventListener('storage', function (e) {
    if (e.key === KEY_T) { theme = e.newValue === 'light' ? 'light' : 'dark'; apply(); }
    if (e.key === KEY_S && sizes.indexOf(e.newValue) >= 0) { size = e.newValue; apply(); }
  });
  window.EtelPayTheme = { get: function () { return theme; }, set: function (v) { theme = v === 'light' ? 'light' : 'dark'; put(KEY_T, theme); apply(); } };
})();
