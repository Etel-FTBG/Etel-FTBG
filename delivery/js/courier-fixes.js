/* Courier app: small fixes added as an add-on (release v9 canary).
 *
 * 1. Pop-ups built in script with their own colours (the "Delivered" summary, the PIN box) showed dark text on their dark panel for a moment,
 *    sometimes for seconds on a slow phone, because the page's stylesheet paints text dark with !important and only a later readability pass
 *    corrected it. Here each such pop-up gets its own colours pinned (inline !important) the moment it is added, before it is first drawn.
 *    Every element takes the colour of the nearest ancestor that sets one (the pop-up's panel), so nothing falls back to the page's dark text.
 * It changes no data, no order logic and no network call. */
(function () {
    'use strict';
    if (window.__etelCourierFixes) return;
    window.__etelCourierFixes = 'v9';

    var POPUPS = ['pkg-done-overlay', 'pkg-pin-overlay'];

    function ownColor(el) { return el.style && el.style.color ? el.style.color : ''; }
    function inheritedColor(el, root) {
        for (var n = el; n; n = n.parentElement) {
            var c = ownColor(n);
            if (c) return c;
            if (n === root) break;
        }
        return '';
    }
    function pin(root) {
        var els = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));
        els.forEach(function (el) {
            var c = inheritedColor(el, root);
            if (c) el.style.setProperty('color', c, 'important');
        });
    }
    function consider(node) {
        if (!node || node.nodeType !== 1) return;
        if (node.id && POPUPS.indexOf(node.id) !== -1) { pin(node); return; }
        if (node.querySelectorAll) {
            POPUPS.forEach(function (id) { var el = node.querySelector('#' + id); if (el) pin(el); });
        }
    }
    function start() {
        if (!document.body) return;
        new MutationObserver(function (records) {
            records.forEach(function (r) { for (var i = 0; i < r.addedNodes.length; i++) consider(r.addedNodes[i]); });
        }).observe(document.body, { childList: true });
        POPUPS.forEach(function (id) { var el = document.getElementById(id); if (el) pin(el); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
