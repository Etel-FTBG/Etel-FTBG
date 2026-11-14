/**
 * Etel Pay - vector icons instead of emoji (all screen sizes).
 * Looks for elements that contain only one known emoji, or start with one, and swaps it for a line icon.
 * Text is never changed; nothing else is touched. Runs again when the page adds content (lists, success screens).
 */
(function () {
  'use strict';
  function s(p) { return '<svg class="ep-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + p + '</svg>'; }
  var CARD = '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>';
  var CHECK = '<circle cx="12" cy="12" r="9"/><path d="m8 12.4 2.7 2.7L16 9.6"/>';
  var M = {
    '\u{1F464}': '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20c.8-4 3.7-6 7.5-6s6.7 2 7.5 6"/>',
    '✉': '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
    '\u{1F4F1}': '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
    '\u{1F511}': '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16 7l2.5 2.5M14 9l2 2"/>',
    '\u{1FAAA}': '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5.5 16c.5-1.7 1.6-2.4 3-2.4s2.5.7 3 2.4M14.5 10h4M14.5 13.5h3"/>',
    '\u{1F4B3}': CARD,
    '\u{1F517}': '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
    '\u{1F4CA}': '<path d="M3 20h18M6 16v-5M11 16V6M16 16v-8M21 16v-3"/>',
    '\u{1F310}': '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.400 5.600 12 3z"/>',
    '⚙': '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M18.7 5.3l-2.1 2.1M7.4 16.6l-2.1 2.1"/>',
    '✏': '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    '\u{1F4DE}': '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
    '\u{1F50D}': '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
    '✅': CHECK, '✓': '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    '\u{1F3E6}': '<path d="M3 9.5 12 4l9 5.500M5 10v8M9.5 10v8M14.500 10v8M19 10v8M3 20.500h18"/>',
    '\u{1F3DB}': '<path d="M3 9.500 12 4l9 5.500M5 10v8M9.500 10v8M14.500 10v8M19 10v8M3 20.500h18"/>',
    '\u{1F91D}': '<path d="M3 12l4-4 4 1.500 3-2 7 6.500-4 4-2-1.500-2 2-2-1-2 1.500z"/>',
    '\u{1F4CB}': '<rect x="5" y="4" width="14" height="17" rx="2.500"/><path d="M9 4V3h6v1M8.500 10h7M8.500 14h7M8.500 17.500h4"/>',
    '\u{1F4C4}': '<path d="M14 3H7.500A2.500 2.500 0 0 0 5 5.500v13A2.500 2.500 0 0 0 7.500 21h9a2.500 2.500 0 0 0 2.500-2.500V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
    '\u{1F933}': '<rect x="7" y="2.500" width="10" height="19" rx="2.500"/><circle cx="12" cy="10" r="2.200"/><path d="M8.500 17c.6-1.800 1.800-2.600 3.500-2.600s2.900.8 3.500 2.600"/>',
    '⚡': '<path d="M13 2.500 5 13.500h6L10 21.500l8-11h-6z"/>',
    '\u{1F3E0}': '<path d="M3 11.500 12 4l9 7.500"/><path d="M5.500 10v9.500h13V10"/><path d="M10 19.500v-5h4v5"/>',
    '\u{1F4B8}': '<rect x="2.500" y="6" width="19" height="12" rx="2.500"/><circle cx="12" cy="12" r="2.800"/><path d="M6 12h.01M18 12h.01"/>',
    '❌': '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
    '\u{1F4A1}': '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.500 10.900c.6.5 1 1.200 1 2.100h5c0-.9.4-1.600 1-2.100A6 6 0 0 0 12 3z"/>'
  };
  var KEYS = Object.keys(M);
  function norm(t) { return t.replace(/️/g, ''); }
  function lead(t) {
    for (var i = 0; i < KEYS.length; i++) if (t.indexOf(KEYS[i]) === 0) return KEYS[i];
    return null;
  }
  function pass(root) {
    var all = (root || document).querySelectorAll('body *');
    for (var i = 0; i < all.length; i++) {
      var el = all[i], tag = el.tagName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'SVG' || tag === 'TEXTAREA' || tag === 'INPUT' || tag === 'OPTION') continue;
      if (el.children.length || el.closest('#ep-sidebar, svg')) continue;
      var t = norm(el.textContent || '');
      if (!t) continue;
      var k = lead(t.trim());
      if (!k) continue;
      var rest = t.trim().slice(k.length);
      if (rest && !/^\s/.test(rest)) continue;               // only "emoji" or "emoji word" - never mid-word
      el.innerHTML = s(M[k]) + (rest ? '<span>' + rest.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>' : '');
      if (rest) el.classList.add('ep-ic-row');
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
