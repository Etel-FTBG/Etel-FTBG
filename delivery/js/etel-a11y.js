/* ETEL accessibility helper (loaded on admin, shop, customer and courier pages).
   Many controls in these apps are plain <div onclick>, icon-only buttons and unlabeled fields. Screen readers and
   keyboards cannot use them. This script adds the missing roles and labels at runtime, so no page code changes:
     - clickable div/span/li       -> role="button", tabindex="0", Enter/Space activates it
     - icon-only button            -> aria-label from its title or a known icon (X = Close, menu = Menu, ...)
     - form field without a label  -> aria-label from the label text next to it, its placeholder or its name
     - side menu / main area       -> navigation and main landmarks
     - modal windows               -> role="dialog" aria-modal, named from their heading
     - toasts                      -> role="status" (errors: role="alert") so they are announced
     - active menu item            -> aria-current="page"
     - image without alt           -> alt="" (decorative)
     - visible keyboard focus ring and reduced-motion support
   It only adds attributes that are missing and never changes what an element looks like or does. */
(function () {
  'use strict';
  if (window.__etelA11y) return; window.__etelA11y = true;

  var NATIVE = /^(A|BUTTON|INPUT|SELECT|TEXTAREA|SUMMARY|LABEL|OPTION)$/;
  var ICON_NAMES = {
    '✕': 'Close', '✖': 'Close', '×': 'Close', 'x': 'Close', '☰': 'Menu', '≡': 'Menu', '↗': 'Open in new tab', '◎': 'Centre on me',
    '🔄': 'Refresh', '⟳': 'Refresh', '↻': 'Refresh', '+': 'Add', '➕': 'Add', '−': 'Remove', '-': 'Remove', '🌙': 'Switch theme', '☀': 'Switch theme',
    '🔍': 'Search', '♡': 'Favorite', '❤': 'Favorite', '🛒': 'Cart', '🔔': 'Notifications', '⚙': 'Settings', '🗑': 'Delete', '✏': 'Edit', '←': 'Back', '‹': 'Back', '›': 'Next'
  };

  // Icon-only buttons: guess the name from the class, id or click handler (avatar-btn, toggleProfile, search-filter-btn ...)
  var HINTS = [[/filter/i, 'Filters'], [/avatar|profile|account|user/i, 'Profile'], [/cart|basket/i, 'Cart'], [/fav|heart|wish/i, 'Favorite'], [/search/i, 'Search'],
    [/close|dismiss|cancel/i, 'Close'], [/back/i, 'Back'], [/menu|hamburger|sidebar/i, 'Menu'], [/refresh|reload/i, 'Refresh'], [/notif|bell/i, 'Notifications'],
    [/lang/i, 'Language'], [/theme|dark|moon/i, 'Switch theme'], [/share/i, 'Share'], [/call|phone/i, 'Call'], [/more|dots|options/i, 'More options'], [/add|plus/i, 'Add'], [/delete|remove|trash/i, 'Delete']];

  function clean(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }
  function hasName(el) {
    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')) return true;
    if (el.id) { try { if (document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]')) return true; } catch (e) {} }
    return !!el.closest('label');
  }
  function humanize(s) { return clean(String(s || '').replace(/[_\-\[\]]+/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2')); }
  function letters(s) { return String(s || '').replace(/[^\p{L}\p{N}]/gu, ''); }

  function fixClickable(root) {
    var list = root.querySelectorAll('[onclick]');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      if (NATIVE.test(el.tagName) || el.getAttribute('role') || el.tabIndex >= 0) continue;
      // A box that already contains links, buttons or fields must not become a button itself (buttons cannot nest them).
      if (el.querySelector('a[href], button, input, select, textarea, [tabindex], [role=button]')) continue;
      el.setAttribute('role', 'button'); el.setAttribute('tabindex', '0'); el.setAttribute('data-a11y-auto', '1');
      if (!hasName(el) && !letters(el.textContent).length) el.setAttribute('aria-label', 'Action');
    }
  }
  // A box we made a button can later receive a real button or link (content loads after the page). Undo it then.
  function undoNested(root) {
    var list = root.querySelectorAll('[data-a11y-auto]');
    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      if (el.querySelector('a[href], button, input, select, textarea, [tabindex], [role=button]')) { el.removeAttribute('role'); el.removeAttribute('tabindex'); el.removeAttribute('data-a11y-auto'); }
    }
  }
  function nameIconButtons(root) {
    var list = root.querySelectorAll('button, [role=button]');
    for (var i = 0; i < list.length; i++) {
      var b = list[i];
      if (hasName(b) || letters(b.textContent).length) continue;
      var t = clean(b.textContent), n = ICON_NAMES[t] || ICON_NAMES[t.charAt(0)];
      if (!n) { var img = b.querySelector('img[alt]'); if (img && clean(img.alt)) n = clean(img.alt); }
      if (!n) { var hay = (b.className || '') + ' ' + (b.getAttribute('onclick') || '') + ' ' + (b.id || ''); for (var k = 0; k < HINTS.length; k++) { if (HINTS[k][0].test(hay)) { n = HINTS[k][1]; break; } } }
      if (!n && b.id) n = humanize(b.id.replace(/^(btn|button)[-_]?/i, ''));
      if (n) b.setAttribute('aria-label', n);
    }
  }
  function labelFields(root) {
    var list = root.querySelectorAll('input, select, textarea');
    for (var i = 0; i < list.length; i++) {
      var f = list[i];
      if (/^(hidden|submit|button|image|reset)$/.test(f.type) || hasName(f)) continue;
      var name = '', p = f.previousElementSibling;
      if (p && /^(LABEL|SPAN|DIV|SMALL|P|B|STRONG)$/.test(p.tagName) && !p.querySelector('input,select,textarea')) { var tx = clean(p.textContent); if (tx && tx.length < 80) name = tx; }
      if (!name && f.parentElement) { var pl = f.parentElement.previousElementSibling; if (pl && /^(LABEL|SPAN|DIV|SMALL|P|B|STRONG)$/.test(pl.tagName) && !pl.querySelector('input,select,textarea,button')) { var t2 = clean(pl.textContent); if (t2 && t2.length < 60) name = t2; } }
      if (!name) name = clean(f.getAttribute('placeholder'));
      if (!name) name = humanize(f.name || f.id);
      if (name) f.setAttribute('aria-label', name.replace(/[:*]+$/, '').trim());
    }
  }
  function landmarks() {
    var side = document.querySelector('aside.sidebar, .sidebar, #sidebar');
    if (side && !side.getAttribute('role') && side.tagName !== 'NAV' && !side.querySelector('nav')) { side.setAttribute('role', 'navigation'); if (!side.getAttribute('aria-label')) side.setAttribute('aria-label', 'Main menu'); }
    if (!document.querySelector('main, [role=main]')) {
      var m = document.querySelector('#main-content, .main-content, .main-area, .main, #content, .content');
      if (m) m.setAttribute('role', 'main');
    }
    var cur = document.querySelectorAll('.nav-item.active, .nav-link.active, .sidebar a.active, [data-page].active');
    for (var i = 0; i < cur.length; i++) cur[i].setAttribute('aria-current', 'page');
    var old = document.querySelectorAll('[aria-current="page"]');
    for (var j = 0; j < old.length; j++) { if (!/(^|\s)active(\s|$)/.test(old[j].className)) old[j].removeAttribute('aria-current'); }
  }
  function dialogs() {
    var list = document.querySelectorAll('.modal, [id$="-modal"], [id$="Modal"], [id$="-dialog"]');
    for (var i = 0; i < list.length; i++) {
      var d = list[i];
      if (d.getAttribute('role') || /^(BUTTON|A|INPUT)$/.test(d.tagName)) continue;
      d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true');
      if (!d.getAttribute('aria-label') && !d.getAttribute('aria-labelledby')) { var h = d.querySelector('h1,h2,h3,h4,.modal-title'); if (h && clean(h.textContent)) d.setAttribute('aria-label', clean(h.textContent).slice(0, 80)); }
    }
  }
  function images(root) {
    var list = root.querySelectorAll('img:not([alt])');
    for (var i = 0; i < list.length; i++) list[i].setAttribute('alt', '');
  }
  function scrollers(root) {
    var list = root.querySelectorAll('[class*=tabs], [class*=scroll], [class*=chips], [class*=nav-bar], [class*=table-wrap], [style*=overflow]');
    for (var i = 0; i < list.length; i++) {
      var s = list[i];
      if (s.tabIndex >= 0 || s.scrollWidth <= s.clientWidth + 1 && s.scrollHeight <= s.clientHeight + 1) continue;
      if (s.querySelector('a[href], button, input, select, textarea, [tabindex="0"]')) continue;
      s.setAttribute('tabindex', '0');
    }
  }
  function announce(node) {
    if (node.nodeType !== 1 || node.getAttribute('role') || node.parentNode !== document.body) return;
    var cls = String(node.className || ''), st = node.getAttribute('style') || '';
    var toast = /toast|snackbar|notification|notice/i.test(cls) || (/position:\s*fixed/i.test(st) && !cls && !node.id && !node.querySelector('button, input, a') && clean(node.textContent).length < 220);
    if (!toast) return;
    var bad = /error|err|danger|fail/i.test(cls + ' ' + st.slice(0, 200));
    node.setAttribute('role', bad ? 'alert' : 'status'); node.setAttribute('aria-live', bad ? 'assertive' : 'polite');
  }

  function sweep(root) {
    root = root && root.querySelectorAll ? root : document;
    try { undoNested(root); fixClickable(root); nameIconButtons(root); labelFields(root); images(root); } catch (e) {}
    try { landmarks(); dialogs(); } catch (e) {}
  }

  function css() {
    var s = document.createElement('style');
    s.textContent = ':where(button,a[href],[role=button],input,select,textarea,summary,[tabindex="0"]):focus-visible{outline:3px solid #f6c22b;outline-offset:2px}' +
      '@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}';
    document.head.appendChild(s);
  }

  // Enter or Space on a role="button" that is not a native button
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t || t.nodeType !== 1 || NATIVE.test(t.tagName) || t.getAttribute('role') !== 'button') return;
    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); t.click(); }
  });

  function start() {
    css(); sweep(document);
    // The scroll-region check measures layout, so it runs only a couple of times after the page has settled.
    setTimeout(function () { try { scrollers(document); } catch (e) {} }, 1500);
    setTimeout(function () { try { scrollers(document); } catch (e) {} }, 5000);
    var timer = null, full = false;
    new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (m.type === 'childList') { full = true; for (var j = 0; j < m.addedNodes.length; j++) announce(m.addedNodes[j]); }
      }
      clearTimeout(timer);
      timer = setTimeout(function () { if (full) { full = false; sweep(document); } else { try { landmarks(); } catch (e) {} } }, 350);
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
