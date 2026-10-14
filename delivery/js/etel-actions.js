/* ETEL actions - button and field actions without inline JavaScript.
 *
 * Why: the pages used to put JavaScript in HTML attributes (onclick="openCart()"). That is exactly what a strict
 * Content-Security-Policy refuses to run, and it is also the way injected markup would run code. Instead the markup
 * names an action and its plain-data arguments, and this file runs it:
 *
 *   <button data-act="applySortFilter" data-args='["trending",{"$":"el"}]'>
 *   <input  data-act-input="filterCategoryView" data-args-input='[{"$":"value"}]'>
 *   <div    data-act="closeCart" data-self="1">        (only when the click is on the element itself, not a child)
 *   <button data-act="addToCart" data-stop="1" ...>    (does not let the click reach a parent that has its own action)
 *
 * Only names a page has registered can run (EtelActions.expose), so markup that somebody manages to inject cannot call
 * an arbitrary function: at worst it presses one of the page's own buttons with plain-data arguments.
 * Argument tokens: {"$":"el"} the element, {"$":"value"} its value, {"$":"checked"} its checked state.
 * Several actions in a row: data-acts='[["closeOrderDeliveredScreen"],["switchCategory","orders"]]'.
 */
(function () {
    'use strict';
    var allowed = Object.create(null);
    var EVENTS = ['click', 'change', 'input', 'keydown'];

    function expose(names) {
        for (var i = 0; i < names.length; i++) allowed[names[i]] = true;
    }

    // Resolve "name" or "Object.method" on window, only for exposed names, and only to a function.
    function resolve(name) {
        if (!allowed[name]) return null;
        var parts = String(name).split('.');
        var ctx = window, fn = window;
        for (var i = 0; i < parts.length; i++) {
            ctx = fn;
            fn = fn == null ? undefined : fn[parts[i]];
        }
        return typeof fn === 'function' ? { fn: fn, ctx: parts.length > 1 ? ctx : window } : null;
    }

    function decode(args, el) {
        var out = [];
        for (var i = 0; i < args.length; i++) {
            var a = args[i];
            if (a && typeof a === 'object' && typeof a.$ === 'string') {
                out.push(a.$ === 'el' ? el : a.$ === 'value' ? el.value : a.$ === 'checked' ? !!el.checked : undefined);
            } else out.push(a);
        }
        return out;
    }

    function run(name, rawArgs, el) {
        var r = resolve(name);
        if (!r) { if (window.console) console.warn('[etel-actions] not available:', name); return; }
        r.fn.apply(r.ctx, decode(rawArgs || [], el));
    }

    function parse(txt) {
        if (!txt) return null;
        try { return JSON.parse(txt); } catch (e) { return null; }
    }

    function fire(el, type, ev) {
        var sfx = type === 'click' ? '' : '-' + type;
        if (type === 'keydown') {
            var want = el.getAttribute('data-key');
            if (want && ev.key !== want) return false;
        }
        if (el.getAttribute('data-self') === '1' && ev.target !== el) return false;
        var acts = parse(el.getAttribute('data-acts' + sfx));
        var name = el.getAttribute('data-act' + sfx);
        if (!acts && !name) return false;
        if (el.getAttribute('data-prevent') === '1') ev.preventDefault();
        try {
            if (acts) { for (var i = 0; i < acts.length; i++) run(acts[i][0], acts[i].slice(1), el); }
            else run(name, parse(el.getAttribute('data-args' + sfx)), el);
        } catch (e) {
            if (window.console) console.error('[etel-actions]', name || 'chain', e);
        }
        return el.getAttribute('data-stop') === '1';
    }

    EVENTS.forEach(function (type) {
        document.addEventListener(type, function (ev) {
            var sfx = type === 'click' ? '' : '-' + type;
            // Walk up like a bubbling inline handler would: the nearest element first, then its parents,
            // until one asks for the event to stop (data-stop).
            for (var el = ev.target; el && el.nodeType === 1; el = el.parentNode) {
                if (!el.hasAttribute('data-act' + sfx) && !el.hasAttribute('data-acts' + sfx)) continue;
                if (fire(el, type, ev)) break;
            }
        });
    });

    // For markup built in JavaScript: EtelActions.attrs('openShopDetail', [id]) gives the attribute text, escaped.
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function attrs(name, args, opts) {
        opts = opts || {};
        var s = ' data-act' + (opts.event && opts.event !== 'click' ? '-' + opts.event : '') + '="' + esc(name) + '"';
        if (args && args.length) s += ' data-args' + (opts.event && opts.event !== 'click' ? '-' + opts.event : '') + '="' + esc(JSON.stringify(args)) + '"';
        if (opts.stop) s += ' data-stop="1"';
        if (opts.prevent) s += ' data-prevent="1"';
        if (opts.self) s += ' data-self="1"';
        if (opts.key) s += ' data-key="' + esc(opts.key) + '"';
        return s;
    }

    window.EtelActions = { expose: expose, attrs: attrs, EL: { $: 'el' }, VALUE: { $: 'value' }, CHECKED: { $: 'checked' }, _resolve: resolve };
})();
