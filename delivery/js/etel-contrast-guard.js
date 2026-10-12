/* ETEL contrast guard - last-line safety net for readability.
   Dark pop-ups, drop-downs and modals that are built in JS with inline colours can end up with
   dark text on a dark surface (or the reverse) when the light/dark theme is switched. This script
   watches for new content, measures the real text/background contrast, and when it is too low
   switches that one element to a readable colour. It never touches elements that already read well. */
(function () {
    'use strict';
    var MIN = 3.6, DARK_TEXT = '#1A1200', LIGHT_TEXT = '#F7EFD9', pending = [], timer = null;

    function parse(c) {
        var m = c && c.match(/rgba?\(([^)]+)\)/); if (!m) return null;
        var p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number);
        return { r: p[0], g: p[1], b: p[2], a: p[3] === undefined ? 1 : p[3] };
    }
    function over(t, b) {
        var a = t.a + b.a * (1 - t.a); if (!a) return { r: 0, g: 0, b: 0, a: 0 };
        return { r: (t.r * t.a + b.r * b.a * (1 - t.a)) / a, g: (t.g * t.a + b.g * b.a * (1 - t.a)) / a, b: (t.b * t.a + b.b * b.a * (1 - t.a)) / a, a: a };
    }
    function lum(c) { var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); }
    function ratio(a, b) { var l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
    /* A gradient background is approximated by the average of its colour stops. */
    function gradientColour(img) {
        var stops = (img.match(/rgba?\([^)]+\)/g) || []).map(parse).filter(function (c) { return c && c.a > 0.05; });
        if (!stops.length) return null;
        var s = stops.reduce(function (t, c) { return { r: t.r + c.r, g: t.g + c.g, b: t.b + c.b, a: t.a + c.a }; }, { r: 0, g: 0, b: 0, a: 0 });
        var n = stops.length; return { r: s.r / n, g: s.g / n, b: s.b / n, a: Math.min(1, s.a / n) };
    }
    function backdrop(el) {
        var layers = [], n = el;
        while (n && n.nodeType === 1) {
            var cs = getComputedStyle(n), c = null;
            if (cs.backgroundImage && cs.backgroundImage.indexOf('gradient') > -1) c = gradientColour(cs.backgroundImage);
            if (!c) { c = parse(cs.backgroundColor); if (c && c.a <= 0) c = null; }
            if (c) { layers.push(c); if (c.a >= 0.98) break; }
            n = n.parentElement;
        }
        var base = parse(getComputedStyle(document.body).backgroundColor);
        var acc = (base && base.a > 0) ? base : { r: 255, g: 255, b: 255, a: 1 };
        for (var i = layers.length - 1; i >= 0; i--) acc = over(layers[i], acc);
        return acc;
    }
    function fix(el) {
        var cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return;
        /* Gradient-filled text (background-clip:text): judge the gradient against what is BEHIND the element. */
        if (cs.webkitBackgroundClip === 'text' || cs.backgroundClip === 'text') {
            var gc = gradientColour(cs.backgroundImage) || parse(cs.backgroundColor);
            var behind = el.parentElement ? backdrop(el.parentElement) : { r: 255, g: 255, b: 255, a: 1 };
            if (gc && ratio(over(gc, behind), behind) < MIN) {
                var w2 = lum(behind) < 0.4 ? LIGHT_TEXT : DARK_TEXT;
                el.__ecgSet = true;
                el.style.setProperty('background-image', 'none', 'important');
                el.style.setProperty('color', w2, 'important');
                el.style.setProperty('-webkit-text-fill-color', w2, 'important');
            }
            return;
        }
        var fg = parse(cs.webkitTextFillColor && cs.webkitTextFillColor.indexOf('rgb') === 0 ? cs.webkitTextFillColor : cs.color); if (!fg) return;
        var bg = backdrop(el), eff = over(fg, bg);
        if (ratio(eff, bg) >= MIN) return;
        var want = lum(bg) < 0.4 ? LIGHT_TEXT : DARK_TEXT;
        el.__ecgSet = true;
        el.style.setProperty('color', want, 'important');
        el.style.setProperty('-webkit-text-fill-color', want, 'important');
    }
    function scan(root) {
        if (!root || root.nodeType !== 1) return;
        var tag = root.tagName; if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'OPTION') return;
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), t, seen = [], budget = 1500;
        while ((t = w.nextNode()) && budget-- > 0) {
            if (!t.nodeValue || !t.nodeValue.trim()) continue;
            var p = t.parentElement; if (!p || seen.indexOf(p) > -1) continue;
            seen.push(p); fix(p);
        }
    }
    function flush() {
        timer = null; var list = pending; pending = [];
        for (var i = 0; i < list.length; i++) { try { if (document.body.contains(list[i])) scan(list[i]); } catch (e) {} }
    }
    function inMap(n) { try { return !!(n.closest && n.closest('.gm-style, .leaflet-container, [id$="map"], [class*="map-canvas"]')); } catch (e) { return false; } }
    function queue(n) { if (n && n.nodeType === 1 && !inMap(n)) { pending.push(n); if (!timer) timer = setTimeout(flush, 120); } }
    function rescanAll() { try { scan(document.body); } catch (e) {} }
    function start() {
        try {
            new MutationObserver(function (ms) {
                for (var i = 0; i < ms.length; i++) {
                    var m = ms[i];
                    if (m.type === 'childList') { for (var j = 0; j < m.addedNodes.length; j++) queue(m.addedNodes[j]); }
                    else if (m.type === 'attributes' && m.target.id !== '_diag_panel') queue(m.target);
                }
            }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
        } catch (e) { return; }
        /* Theme switches change colours without adding nodes: re-clear our overrides and re-check everything. */
        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest && e.target.closest('#dark-theme-toggle, .dark-toggle-btn');
            if (!b) return;
            setTimeout(function () {
                [].forEach.call(document.querySelectorAll('[style*="-webkit-text-fill-color"]'), function (el) { if (el.__ecgSet) { el.style.removeProperty('color'); el.style.removeProperty('-webkit-text-fill-color'); el.style.removeProperty('background-image'); el.__ecgSet = false; } });
                rescanAll();
            }, 400);
        }, true);
        setTimeout(rescanAll, 1500);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
