/* ETEL - English / Amharic by translating what is on screen (shared engine, additive: no bundle release).

   The apps build their text in many places (page HTML, bundles, add-on scripts), so instead of editing each one
   this looks every visible piece of text up in a dictionary and swaps it, and keeps watching the page so text
   drawn later (lists, toasts, dialogs) is translated too. The original English is remembered per node, so
   switching back restores it exactly. Text with no dictionary entry stays English.

   EtelDomI18n.create({
     key:      'etel_lang'                 storage key, shared across ETEL apps
     am:       { 'English text': 'አማርኛ' }  visible text; leading/trailing emoji and arrows are ignored when matching
     attrs:    { ... }                     placeholder / title / aria-label text
     rules:    [[/^(\d+) trips$/, '$1 ...']] text with numbers in it
     buttonHost: '.some-selector'          where the language button goes (button is created if the host exists)
     buttonId, buttonClass, buttonBefore (selector inside the host to sit before), buttonFirst    button placement
   }) -> { setLang(l), lang() }

   Never touches typed values, scripts, styles or anything marked data-no-i18n. */
(function () {
    'use strict';
    if (window.EtelDomI18n) return;

    function create(cfg) {
        var KEY = cfg.key || 'etel_lang';
        var AM = cfg.am || {}, AM_ATTR = cfg.attrs || {}, RULES = cfg.rules || [];

        function lang() { try { return localStorage.getItem(KEY) === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
        function strip(s) { var m = /^([^A-Za-z0-9'"~]*)([\s\S]*?)([^A-Za-z0-9'"…).?]*)$/.exec(s); return m ? m : [s, '', s, '']; }

        function lookup(raw, table, useRules) {
            var t = raw.replace(/\s+/g, ' ').trim();
            if (!t) return null;
            if (Object.prototype.hasOwnProperty.call(table, t)) return table[t];
            var p = strip(t);
            if (p[2] && p[2] !== t && Object.prototype.hasOwnProperty.call(table, p[2])) return p[1] + table[p[2]] + p[3];
            if (useRules) for (var i = 0; i < RULES.length; i++) if (RULES[i][0].test(t)) return t.replace(RULES[i][0], RULES[i][1]);
            return null;
        }

        var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1 };
        function skipEl(el) {
            for (; el && el.nodeType === 1; el = el.parentNode) {
                if (SKIP[el.tagName]) return true;
                if (el.classList && (el.classList.contains('pac-container') || el.id === 'map' || el.hasAttribute('data-no-i18n'))) return true;
            }
            return false;
        }

        var store = new WeakMap();   // text node -> { orig, shown }
        function textNode(n, to) {
            var rec = store.get(n);
            if (rec && n.nodeValue !== rec.shown) rec = null;     // rewritten by something else: treat as new English text
            if (to === 'am') {
                var src = rec ? rec.orig : n.nodeValue;
                var tr = lookup(src, AM, true);
                if (!tr) { if (rec) store.delete(n); return; }
                var out = /^\s*/.exec(src)[0] + tr + /\s*$/.exec(src)[0];
                if (n.nodeValue !== out) n.nodeValue = out;
                store.set(n, { orig: src, shown: out });
            } else if (rec) {
                n.nodeValue = rec.orig; store.delete(n);
            }
        }
        function attrs(el, to) {
            ['placeholder', 'title', 'aria-label'].forEach(function (a) {
                var o = 'data-i18n-o-' + a, cur = el.getAttribute(a);
                if (cur == null) return;
                if (to === 'am') {
                    var src = el.hasAttribute(o) && cur === el.getAttribute(o + '-shown') ? el.getAttribute(o) : cur;
                    var tr = lookup(src, AM_ATTR, false);
                    if (tr) { el.setAttribute(o, src); el.setAttribute(o + '-shown', tr); el.setAttribute(a, tr); }
                } else if (el.hasAttribute(o)) {
                    if (cur === el.getAttribute(o + '-shown')) el.setAttribute(a, el.getAttribute(o));
                    el.removeAttribute(o); el.removeAttribute(o + '-shown');
                }
            });
        }

        function walk(root, to) {
            if (!root) return;
            if (root.nodeType === 1 && root.tagName === 'TEXTAREA') { if (!skipEl(root.parentNode)) attrs(root, to); return; }
            if (skipEl(root.nodeType === 1 ? root : root.parentNode)) return;
            if (root.nodeType === 3) { textNode(root, to); return; }
            if (root.nodeType !== 1) return;
            attrs(root, to);
            var tw = document.createTreeWalker(root, 1 | 4, { acceptNode: function (n) {
                if (n.nodeType === 1) {
                    if (n.tagName === 'TEXTAREA') { if (!skipEl(n.parentNode)) attrs(n, to); return 2; }
                    return skipEl(n) ? 2 : 1;                      // 2 = reject the whole subtree
                }
                return 1;
            } });
            for (var n = tw.nextNode(); n; n = tw.nextNode()) { if (n.nodeType === 3) textNode(n, to); else attrs(n, to); }
        }

        var obs = null;
        function run(roots) {
            var to = lang();
            roots.forEach(function (r) { walk(r, to); });
            if (obs) obs.takeRecords();                // our own edits are not news
        }

        function updateButton() {
            var b = document.getElementById(cfg.buttonId || 'etel-lang-btn');
            if (!b) return;
            var am = lang() === 'am';
            b.textContent = am ? 'EN' : 'አማ';
            b.setAttribute('aria-label', am ? 'Switch to English' : 'ወደ አማርኛ ቀይር');
            b.setAttribute('data-no-i18n', '1');
            b.title = am ? 'English' : 'አማርኛ';
        }
        function setLang(l) {
            try { localStorage.setItem(KEY, l); } catch (e) { /* not saved: still applies for this visit */ }
            document.documentElement.setAttribute('lang', l);
            run([document.body]);
            updateButton();
            if (cfg.onChange) { try { cfg.onChange(l); } catch (e) {} }
            try { window.dispatchEvent(new CustomEvent('etel:lang', { detail: l })); } catch (e) {}
        }
        function addButton() {
            var id = cfg.buttonId || 'etel-lang-btn';
            if (document.getElementById(id) || !cfg.buttonHost) return;
            var host = document.querySelector(cfg.buttonHost);
            if (!host) return;
            var b = document.createElement('button');
            b.type = 'button'; b.id = id; b.className = cfg.buttonClass || '';
            b.addEventListener('click', function () { setLang(lang() === 'am' ? 'en' : 'am'); });
            var ref = cfg.buttonBefore ? host.querySelector(cfg.buttonBefore) : null;
            if (ref && ref.parentNode === host) host.insertBefore(b, ref);
            else if (cfg.buttonFirst && host.firstChild) host.insertBefore(b, host.firstChild);
            else host.appendChild(b);
            updateButton();
        }

        function start() {
            addButton();
            if (lang() === 'am') document.documentElement.setAttribute('lang', 'am');
            run([document.body]);
            var pending = [], timer = 0;
            obs = new MutationObserver(function (recs) {
                if (lang() !== 'am') { pending = []; return; }
                for (var i = 0; i < recs.length; i++) {
                    var r = recs[i];
                    if (r.type === 'childList') { for (var j = 0; j < r.addedNodes.length; j++) pending.push(r.addedNodes[j]); }
                    else pending.push(r.target);   // characterData or a watched attribute
                }
                if (!timer) timer = setTimeout(function () {
                    timer = 0; var batch = pending; pending = [];
                    run(batch.filter(function (n) { return n && n.isConnected; }));
                }, 30);
            });
            obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label'] });
            var tries = 0, iv = setInterval(function () { addButton(); updateButton(); if (++tries > 40) clearInterval(iv); }, 750);
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
        return { setLang: setLang, lang: lang };
    }

    window.EtelDomI18n = { create: create };
})();
