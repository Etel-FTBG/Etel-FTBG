/* Etel Taxi - line icons instead of emoji (all screen sizes).
   Swaps an emoji that stands alone, or starts an element's text, for a line icon that follows the theme colour.
   Text is never changed; decorative sparkles and anything inside inputs are left alone. Re-runs when the page adds content. */
(function () {
  'use strict';
  function s(p, cls) { return '<svg class="tx-ic' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + p + '</svg>'; }
  var CARD = '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>';
  var BANK = '<path d="M3 9.5 12 4l9 5.5M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20.5h18"/>';
  var M = {
    '\u{1F519}': ['<path d="M19 12H5M11 6l-6 6 6 6"/>'],
    '\u{1F4CA}': ['<path d="M3 20h18M6 16v-5M11 16V6M16 16v-8M21 16v-3"/>'],
    '\u{1F695}': ['<path d="M5 15 6.5 9.5A2 2 0 0 1 8.400 8h7.200a2 2 0 0 1 1.900 1.500L19 15"/><rect x="3" y="15" width="18" height="4" rx="1.500"/><path d="M7 19v1.500M17 19v1.500M9 5h6M7.500 15h.01M16.500 15h.01"/>'],
    '⚠': ['<path d="M12 3.500 2.500 20h19z"/><path d="M12 10v4.500M12 17.500v.01"/>', 'tx-ic-warn'],
    '\u{1F3E6}': [BANK], '\u{1F3DB}': [BANK],
    '\u{1F9FE}': ['<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>'],
    '\u{1F6A6}': ['<rect x="8" y="2.500" width="8" height="19" rx="3"/><circle cx="12" cy="7" r="1.200"/><circle cx="12" cy="12" r="1.200"/><circle cx="12" cy="17" r="1.200"/>'],
    '\u{1F510}': ['<path d="M12 3 4 6v6c0 4.500 3.200 8 8 9 4.800-1 8-4.500 8-9V6l-8-3z"/><rect x="9" y="11" width="6" height="4.500" rx="1"/><path d="M10 11V9.500a2 2 0 0 1 4 0V11"/>'],
    '\u{1F50D}': ['<circle cx="11" cy="11" r="6.500"/><path d="m16 16 4.500 4.500"/>'],
    '\u{1F6AA}': ['<path d="M14 4H6.500A1.500 1.500 0 0 0 5 5.500v13A1.500 1.500 0 0 0 6.500 20H14M10 12h10M17 8l4 4-4 4"/>'],
    '⬇': ['<path d="M12 4v11M7 11l5 5 5-5M4 20h16"/>'],
    '\u{1F4F1}': ['<rect x="7" y="2.500" width="10" height="19" rx="2.500"/><path d="M11 18.500h2"/>'],
    '\u{1F512}': ['<rect x="5" y="11" width="14" height="9.500" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'],
    '\u{1F4B3}': [CARD],
    '\u{1F514}': ['<path d="M6 16V11a6 6 0 0 1 12 0v5l1.500 2h-15z"/><path d="M10 20.500a2 2 0 0 0 4 0"/>'],
    '\u{1F3E2}': ['<rect x="5" y="3" width="14" height="18" rx="1.500"/><path d="M9 7.500h2M13 7.500h2M9 11.500h2M13 11.500h2M10 21v-4h4v4"/>'],
    '✅': ['<circle cx="12" cy="12" r="9"/><path d="m8 12.400 2.700 2.700L16 9.600"/>', 'tx-ic-ok'],
    '⛔': ['<circle cx="12" cy="12" r="9"/><path d="M5.600 5.600l12.800 12.800"/>', 'tx-ic-bad'],
    '✕': ['<path d="M6 6l12 12M18 6 6 18"/>'],
    '✏': ['<path d="M4 20h4L19 9a2.800 2.800 0 0 0-4-4L4 16z"/><path d="m13.500 6.500 4 4"/>'],
    '\u{1F5D1}': ['<path d="M4 7h16M9 7V4.500h6V7M6.500 7l1 13h9l1-13M10 11v6M14 11v6"/>'],
    '\u{1F4BE}': ['<path d="M5 3.500h11l3.500 3.500V20a.5.5 0 0 1-.5.500H5a.5.5 0 0 1-.5-.5V4a.5.5 0 0 1 .5-.5z"/><path d="M8 3.500v5h7v-5M8 20.500v-6h8v6"/>'],
    '\u{1F45B}': ['<path d="M3.500 7.500h15a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-15z"/><path d="M3.500 7.500 15 4v3.500"/><circle cx="16.500" cy="14" r="1.200"/>'],
    '\u{1F4B5}': ['<rect x="2.500" y="6" width="19" height="12" rx="2.500"/><circle cx="12" cy="12" r="2.800"/><path d="M6 12h.01M18 12h.01"/>']
  };
  var KEYS = Object.keys(M);
  function norm(t) { return t.replace(/️/g, ''); }
  function lead(t) { for (var i = 0; i < KEYS.length; i++) if (t.indexOf(KEYS[i]) === 0) return KEYS[i]; return null; }
  function pass(root) {
    var all = (root || document).querySelectorAll('body *');
    for (var i = 0; i < all.length; i++) {
      var el = all[i], tag = el.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'SVG' || tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'OPTION' || tag === 'TITLE') continue;
      if (el.children.length || el.closest('svg, .tx-ic')) continue;
      var t = norm(el.textContent || '').trim();
      if (!t) continue;
      var k = lead(t);
      if (!k) continue;
      var rest = t.slice(k.length);
      if (rest && !/^\s/.test(rest)) continue;                       // only "emoji" or "emoji word"
      el.innerHTML = s(M[k][0], M[k][1]) + (rest ? '<span>' + rest.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>' : '');
      if (rest) el.classList.add('tx-ic-row');
    }
  }
  var timer;
  function later() { clearTimeout(timer); timer = setTimeout(function () { pass(); }, 80); }
  function boot() {
    pass();
    if (window.MutationObserver) new MutationObserver(later).observe(document.body, { childList: true, subtree: true, characterData: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
