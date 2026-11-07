/* Courier app: Amharic brand spelling (add-on, v14 canary).
 * The brand is written ETEL in Amharic as <U+12A2><U+1275><U+12A4><U+120D>. The Amharic add-on released earlier (js/courier-i18n.js, v8) spells it
 * <U+12A2><U+1274><U+120D>, and a released add-on is never edited. This add-on runs after it and corrects that one word wherever the page shows it:
 * in text and in placeholder / title / aria-label. It also updates the Amharic copy the language add-on keeps for each piece of text, so switching
 * to English and back still works. It changes nothing else: no orders, money, location or login. Source is ASCII (the word is written as escapes). */
(function () {
    'use strict';
    if (window.__etelCourierBrand) return;
    window.__etelCourierBrand = 'v14';

    var OLD = '\u12a2\u1274\u120d', NEW = '\u12a2\u1275\u12a4\u120d';
    var ATTRS = ['placeholder', 'title', 'aria-label'];
    function fix(s) { return s.split(OLD).join(NEW); }

    function fixText(n) {
        var v = n.nodeValue;
        if (!v || v.indexOf(OLD) < 0) return;
        var nv = fix(v);
        if (n.__etelAm === v) n.__etelAm = nv;      // keep the language add-on's own record of its translation in step
        n.nodeValue = nv;
    }
    function fixAttrs(el) {
        if (!el || el.nodeType !== 1) return;
        ATTRS.forEach(function (a) {
            var v = el.getAttribute(a);
            if (!v || v.indexOf(OLD) < 0) return;
            var nv = fix(v), k = '__etel_' + a;
            if (el[k] && el[k].am === v) el[k].am = nv;
            el.setAttribute(a, nv);
        });
    }
    function walk(root) {
        if (!root) return;
        if (root.nodeType === 3) { fixText(root); return; }
        if (root.nodeType !== 1 && root.nodeType !== 9) return;
        var tw = document.createTreeWalker(root, 4 /* NodeFilter.SHOW_TEXT */, {
            acceptNode: function (n) {
                var p = n.parentNode, t = p && p.nodeName;
                return (t === 'SCRIPT' || t === 'STYLE') ? 2 : 1;
            }
        });
        var n, list = [];
        while ((n = tw.nextNode())) list.push(n);
        list.forEach(fixText);
        if (root.nodeType === 1) fixAttrs(root);
        Array.prototype.forEach.call((root.querySelectorAll ? root.querySelectorAll('[placeholder],[title],[aria-label]') : []), fixAttrs);
    }

    var timer = 0;
    function later() {
        if (timer) return;
        timer = setTimeout(function () { timer = 0; walk(document); }, 60);   // after the language add-on has drawn its translation
    }
    function start() {
        if (!document.body) return;
        walk(document);
        new MutationObserver(later).observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
        setTimeout(function () { walk(document); }, 400);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
