/* ================================================================
   ETEL - Display size controller  v1.2
   ================================================================
   One shared script, loaded by every delivery / ride / driver /
   courier / service-giver / admin / login page (and Etel Gebeya).
   It adds a small floating "Aa" button; tapping it opens a panel
   where ANYONE can choose how big (or small) and how bold things
   look:

       - Text size        (70% - 200%)
       - Icon size        (70% - 200%)
       - Button size      (70% - 200%)
       - Logo size        (70% - 200%)
       - Text boldness    (Normal / Bold / Extra bold)

   The choice is saved in localStorage under ONE shared key, so it
   follows the person across every ETEL app on this origin.

   How the scaling works (no page has to be edited beyond loading
   this file):
     1. Every px-based font-size / width / height / padding in the
        page's stylesheets is rewritten ONCE into
            calc(<original px> * var(--etel-ts|is|bs|ls))
        so moving a slider only updates a CSS variable - the whole
        page resizes live with no re-scan.
     2. Elements that carry the same values inline (style="...")
        - including ones the apps render later from JS - are handled
        by a small MutationObserver.
     3. Rules/elements are put in a category (text / icon / button /
        logo) from their class names, tag, and emoji-only content.
     4. Nothing is rewritten until the person changes a setting, so
        users on the default size pay zero cost.

   The panel lives in a Shadow DOM so the page's own CSS (and this
   script's rewriting) can never distort it - it stays usable even
   at 200%.

   Public API:  window.EtelDisplay.open() / .close() / .get() /
                .set({ts,is,bs,ls,fw}) / .reset()
   Any element with the attribute  data-etel-display-open  opens the
   panel when clicked (handy for a "Display size" row in a settings
   screen).  Apps that have such a row in their profile menu load this
   script with  data-fab="off"  so the floating Aa button is not shown.
   ================================================================ */
(function () {
    'use strict';
    if (window.EtelDisplay) return;

    var KEY = 'etel_display_prefs';
    var POS_KEY = 'etel_display_fab_pos';
    var MIN = 70, MAX = 200, STEP = 5;
    var DEFAULTS = { ts: 100, is: 100, bs: 100, ls: 100, fw: 0 };
    var FW_MIN = [0, 700, 800];          // fw level -> minimum font-weight

    var doc = document;
    var cur = doc.currentScript;
    var fabOff = !!(cur && cur.getAttribute('data-fab') === 'off');   // app has a Display size row in its profile menu
    var root = doc.documentElement;
    var prefs = load();
    var engineOn = false;

    /* ---------------------------  storage  --------------------------- */
    function clamp(n, lo, hi) { n = Number(n); if (isNaN(n)) return lo; return Math.max(lo, Math.min(hi, n)); }
    function sanitize(p) {
        p = p || {};
        return {
            ts: clamp(p.ts == null ? 100 : p.ts, MIN, MAX),
            is: clamp(p.is == null ? 100 : p.is, MIN, MAX),
            bs: clamp(p.bs == null ? 100 : p.bs, MIN, MAX),
            ls: clamp(p.ls == null ? 100 : p.ls, MIN, MAX),
            fw: clamp(p.fw == null ? 0 : p.fw, 0, 2) | 0
        };
    }
    function load() {
        try { return sanitize(JSON.parse(localStorage.getItem(KEY) || 'null')); }
        catch (e) { return sanitize(null); }
    }
    function save() {
        try {
            if (isDefault(prefs)) localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, JSON.stringify(prefs));
        } catch (e) {}
    }
    function isDefault(p) {
        return p.ts === 100 && p.is === 100 && p.bs === 100 && p.ls === 100 && p.fw === 0;
    }

    /* -----------------------  CSS variables  ------------------------- */
    function applyVars() {
        var s = root.style;
        s.setProperty('--etel-ts', String(prefs.ts / 100));
        s.setProperty('--etel-is', String(prefs.is / 100));
        s.setProperty('--etel-bs', String(prefs.bs / 100));
        s.setProperty('--etel-ls', String(prefs.ls / 100));
        s.setProperty('--etel-cs', String(Math.max(prefs.ts, prefs.is, prefs.bs, prefs.ls) / 100));
        s.setProperty('--etel-fw', String(FW_MIN[prefs.fw]));
        if (!isDefault(prefs)) startEngine();
    }

    /* -------------------  element / rule categories  ----------------- */
    var RE_ICONBTN = /icon-?btn|btn-?icon|icon-?button|button-?icon/i;
    var RE_LOGO = /logo|seal|brand-?(mark|icon|img|image)|app-?icon|splash-?(img|image)/i;
    var RE_ICON = /icon|emoji|^\s*svg|[\s>+~]svg|(^|[^a-z])ico([^a-z]|$)|\.bi([^a-z]|$|-)|\.fa[srb]?([^a-z]|$|-)/i;
    var RE_BTN = /btn|button|(^|[^a-z])cta([^a-z]|$)|chip|pill|(^|[^a-z])tabs?([^a-z]|$)|-tab([^a-z]|$)|nav-item|nav-link|(^|[\s,>+~])(input|select|textarea)([^a-z-]|$)/i;
    var RE_BAR = /nav|header|footer|topbar|tabbar|toolbar|appbar/i;
    var RE_NOBAR = /progress|track|fill|meter|skeleton/i;
    var RE_ICONFONT = /::?(before|after)|(^|[^a-z])(bi|fa[srb]?|material-icons)([^a-z]|$|-)/i;
    var RE_PAGEPAD = /body|main|content|page|screen|view|container|wrap/i;
    var RE_EMOJI = /^[\s‍️\p{Extended_Pictographic}\p{Emoji_Presentation}]+$/u;
    var emojiOk = true;
    try { RE_EMOJI.test('x'); } catch (e) { emojiOk = false; }

    function classifyCompound(c) {
        if (RE_ICONBTN.test(c)) return 'button';
        if (RE_LOGO.test(c)) return 'logo';
        if (RE_ICON.test(c)) return 'icon';
        if (RE_BTN.test(c)) return 'button';
        return 'text';
    }
    // Category of a CSS selector list: look at the last compound of each part.
    function classifySelector(sel) {
        var parts = String(sel || '').split(',');
        var found = 'text';
        for (var i = 0; i < parts.length; i++) {
            var p = parts[i].trim().replace(/\s*([>+~])\s*/g, ' ');
            var last = p.split(/\s+/).pop() || '';
            var c = classifyCompound(last);
            if (c !== 'text') { found = c; break; }
        }
        return found;
    }
    // Category of a live element (for inline styles).
    function classifyElement(el) {
        var tag = el.tagName.toLowerCase();
        var sig = tag;
        var cls = el.getAttribute('class');
        if (cls && typeof cls === 'string') sig += '.' + cls.trim().split(/\s+/).join('.');
        if (el.id) sig += '#' + el.id;
        if (tag === 'input') {
            var t = (el.getAttribute('type') || '').toLowerCase();
            if (t === 'checkbox' || t === 'radio') return 'icon';
        }
        if (tag === 'img') {
            var probe = (el.getAttribute('src') || '') + ' ' + (el.getAttribute('alt') || '') + ' ' + sig;
            return RE_LOGO.test(probe) ? 'logo' : 'text';
        }
        if (el.getAttribute('role') === 'button') return 'button';
        var c = classifyCompound(sig);
        if (c === 'text' && emojiOk && !el.children.length) {
            var tx = el.textContent;
            if (tx && tx.length <= 8 && RE_EMOJI.test(tx)) return 'icon';
        }
        return c;
    }

    var CFG = {
        text: [['font-size', 'ts'], ['line-height', 'ts']],
        button: [['font-size', 'ts'], ['line-height', 'bs'], ['height', 'bs'], ['min-height', 'bs'],
                 ['min-width', 'bs'], ['padding', 'bs'], ['padding-top', 'bs'], ['padding-right', 'bs'],
                 ['padding-bottom', 'bs'], ['padding-left', 'bs']]
    };
    function boxCfg(v) {
        return [['font-size', v], ['line-height', v], ['width', v], ['height', v], ['min-width', v],
                ['min-height', v], ['max-width', v], ['max-height', v]];
    }
    CFG.icon = boxCfg('is');
    CFG.logo = boxCfg('ls');

    /* -----------------------  value rewriting  ----------------------- */
    var PX = /^(-?\d*\.?\d+)px$/;
    function scaleVal(val, v) {
        val = String(val || '').trim();
        if (!val || val.indexOf('(') !== -1) return null;     // leave calc()/var()/env() alone
        var toks = val.split(/\s+/), changed = false;
        for (var i = 0; i < toks.length; i++) {
            var m = PX.exec(toks[i]);
            if (m && parseFloat(m[1]) !== 0) {
                toks[i] = 'calc(' + m[1] + 'px * var(--etel-' + v + ',1))';
                changed = true;
            }
        }
        return changed ? toks.join(' ') : null;
    }
    var fwOk = null;
    function fwValue(val) {
        val = String(val || '').trim().toLowerCase();
        var n = val === 'normal' ? 400 : val === 'bold' ? 700 : /^\d{3}$/.test(val) ? parseInt(val, 10) : 0;
        if (!n) return null;
        if (fwOk === null) {
            try { fwOk = !!(window.CSS && CSS.supports && CSS.supports('font-weight', 'max(400,var(--x,0))')); }
            catch (e) { fwOk = false; }
        }
        return fwOk ? 'max(' + n + ',var(--etel-fw,0))' : null;
    }

    // Rewrite one declaration block (a CSS rule's style, or an element's inline style).
    function processStyle(style, cat, opts) {
        var cfg = CFG[cat], i, prop, val, nv;
        for (i = 0; i < cfg.length; i++) {
            prop = cfg[i][0];
            val = style.getPropertyValue(prop);
            if (!val) continue;
            nv = scaleVal(val, cfg[i][1]);
            if (nv) style.setProperty(prop, nv, style.getPropertyPriority(prop));
        }
        // Square buttons (equal width/height in px) must stay square.
        if (cat === 'button') {
            var w = style.getPropertyValue('width'), h = style.getPropertyValue('height');
            var wm = PX.exec(w), hm = PX.exec(h);
            if (wm && hm && wm[1] === hm[1]) style.setProperty('width', scaleVal(w, 'bs'), style.getPropertyPriority('width'));
        }
        // Bars (nav / header / footer): let them grow with the biggest control.
        if (opts.sel && cat === 'text' && RE_BAR.test(opts.sel) && !RE_NOBAR.test(opts.sel)) {
            var hm2 = PX.exec(style.getPropertyValue('height'));
            if (hm2 && parseFloat(hm2[1]) >= 36) {
                style.setProperty('height', 'auto', style.getPropertyPriority('height'));
                style.setProperty('min-height', 'calc(' + hm2[1] + 'px * var(--etel-cs,1))');
            }
        }
        // Space reserved under the page for a fixed bottom nav.
        if (opts.sel && RE_PAGEPAD.test(opts.sel)) {
            var pb = PX.exec(style.getPropertyValue('padding-bottom'));
            if (pb && parseFloat(pb[1]) >= 48) {
                style.setProperty('padding-bottom', 'calc(' + pb[1] + 'px * var(--etel-cs,1))',
                    style.getPropertyPriority('padding-bottom'));
            }
        }
        // Boldness.
        if (!opts.sel || !RE_ICONFONT.test(opts.sel)) {
            var fw = fwValue(style.getPropertyValue('font-weight'));
            if (fw) style.setProperty('font-weight', fw, style.getPropertyPriority('font-weight'));
        }
    }

    /* ---------------------  stylesheet rewriting  -------------------- */
    var doneRules = (typeof WeakSet === 'function') ? new WeakSet() : null;
    var rootSized = false;

    function walkRules(list) {
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            if (r.type === 1) {
                if (doneRules && doneRules.has(r)) { if (r.cssRules && r.cssRules.length) walkRules(r.cssRules); continue; }
                if (doneRules) doneRules.add(r);
                var sel = r.selectorText || '';
                if (/^\s*(html|:root)\s*(,|$)/i.test(sel) || /,\s*(html|:root)\s*(,|$)/i.test(sel)) {
                    if (r.style.getPropertyValue('font-size') && PX.test(r.style.getPropertyValue('font-size'))) rootSized = true;
                }
                try { processStyle(r.style, classifySelector(sel), { sel: sel }); } catch (e) {}
                if (r.cssRules && r.cssRules.length) walkRules(r.cssRules);      // CSS nesting
            } else if (r.type === 5 || r.type === 7) {
                continue;                                                        // @font-face, @keyframes
            } else if (r.cssRules && r.cssRules.length) {
                walkRules(r.cssRules);                                           // @media / @supports / @layer
            }
        }
    }
    function walkSheets() {
        var sheets = doc.styleSheets;
        for (var i = 0; i < sheets.length; i++) {
            var sh = sheets[i], rules;
            if (sh.ownerNode && sh.ownerNode.id === 'etel-display-css') continue;
            try { rules = sh.cssRules; } catch (e) { continue; }                // cross-origin sheet
            if (!rules) continue;
            walkRules(rules);
        }
        ensureExtraCss();
        if (!rootSized) ensureRootRule();
    }
    var extraAdded = false;
    function styleEl() {
        var st = doc.getElementById('etel-display-css');
        if (!st) {
            st = doc.createElement('style');
            st.id = 'etel-display-css';
            (doc.head || root).appendChild(st);
        }
        return st;
    }
    // Tab / nav labels are often nowrap - at big sizes let them wrap instead of being cut off.
    function ensureExtraCss() {
        if (extraAdded) return;
        extraAdded = true;
        styleEl().appendChild(doc.createTextNode(
            'html[data-etel-on] :is([class*="tab"],[class*="nav-item"],[class*="nav-btn"],[class*="nav-link"])' +
            '{white-space:normal!important;text-overflow:clip!important;line-height:1.15}'));
    }
    var rootRuleAdded = false;
    function ensureRootRule() {
        if (rootRuleAdded) return;
        rootRuleAdded = true;
        var base = 16;
        try { base = parseFloat(getComputedStyle(root).fontSize) || 16; } catch (e) {}
        styleEl().appendChild(doc.createTextNode('html[data-etel-on]{font-size:calc(' + base + 'px * var(--etel-ts,1))}'));
    }

    /* ----------------------  inline-style pass  ---------------------- */
    var pending = [];
    var flushTimer = 0;
    var flushing = false;
    var HOST_ID = 'etel-display-host';

    function inHost(n) { var h = doc.getElementById(HOST_ID); return !!(h && (n === h || h.contains(n))); }

    function processElement(el) {
        if (el.nodeType !== 1 || el.hasAttribute('data-etel-noscale') || inHost(el)) return;
        var tag = el.tagName;
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' || tag === 'META' || tag === 'HTML' || tag === 'BODY') return;
        var st = el.style;
        if (st && st.length) {
            try { processStyle(st, classifyElement(el), { inline: true }); } catch (e) {}
        }
        // <svg width="24" height="24"> attributes (no CSS rule): scale as an icon.
        if (tag === 'svg' || tag === 'SVG') {
            var wa = parseFloat(el.getAttribute('width')), ha = parseFloat(el.getAttribute('height'));
            if ((wa > 0 || ha > 0) && !st.width && !st.height) {
                var cs = getComputedStyle(el);
                var same = (!(wa > 0) || Math.abs(parseFloat(cs.width) - wa) < 0.6) &&
                           (!(ha > 0) || Math.abs(parseFloat(cs.height) - ha) < 0.6);
                if (same) {
                    if (wa > 0) st.setProperty('width', 'calc(' + wa + 'px * var(--etel-is,1))');
                    if (ha > 0) st.setProperty('height', 'calc(' + ha + 'px * var(--etel-is,1))');
                }
            }
        }
    }
    function processTree(node) {
        if (node.nodeType !== 1) return;
        processElement(node);
        var list = node.querySelectorAll('[style], svg[width], svg[height]');
        for (var i = 0; i < list.length; i++) processElement(list[i]);
    }
    function schedule(node) {
        pending.push(node);
        if (flushTimer) return;
        flushTimer = setTimeout(flush, 40);
    }
    function flush() {
        flushTimer = 0;
        flushing = true;
        var batch = pending; pending = [];
        var sheetsChanged = false;
        for (var i = 0; i < batch.length; i++) {
            var n = batch[i];
            if (n.nodeType !== 1) continue;
            var t = n.tagName;
            if ((t === 'STYLE' || t === 'LINK') && n.id !== 'etel-display-css') { sheetsChanged = true; continue; }
            if (doc.documentElement.contains(n)) processTree(n);
        }
        if (sheetsChanged) walkSheets();
        // ignore the mutations our own writes just caused
        if (observer) observer.takeRecords();
        flushing = false;
    }
    var observer = null;
    function startObserver() {
        if (observer || typeof MutationObserver !== 'function') return;
        observer = new MutationObserver(function (recs) {
            if (flushing) return;
            for (var i = 0; i < recs.length; i++) {
                var r = recs[i];
                if (r.type === 'attributes') { schedule(r.target); continue; }
                for (var j = 0; j < r.addedNodes.length; j++) {
                    var a = r.addedNodes[j];
                    if (a.nodeType === 1 && !inHost(a)) schedule(a);
                }
            }
        });
        observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
        // late-loading stylesheets
        doc.addEventListener('load', function (e) {
            var t = e.target;
            if (t && t.tagName === 'LINK') schedule(t);
        }, true);
    }

    function startEngine() {
        if (engineOn) return;
        engineOn = true;
        root.setAttribute('data-etel-on', '1');
        var go = function () {
            walkSheets();
            processTree(doc.body || root);
            startObserver();
        };
        if (doc.body) go();
        else doc.addEventListener('DOMContentLoaded', go);
        // sheets that finish loading after first paint
        window.addEventListener('load', function () { walkSheets(); });
    }

    /* ---------------------------  UI  ------------------------------- */
    var I18N = {
        en: {
            fab: 'Display size settings',
            title: 'Display size',
            sub: 'Pick the size that is comfortable for your eyes.',
            presets: 'Quick presets',
            pSmall: 'Small', pStd: 'Standard', pLarge: 'Large', pXl: 'Extra large', pEasy: 'Large + bold',
            text: 'Text size', icons: 'Icon size', buttons: 'Button size', logos: 'Logo size',
            weight: 'Text boldness', wNormal: 'Normal', wBold: 'Bold', wXbold: 'Extra bold',
            preview: 'Preview', sample: 'Hello, Hawassa!', btn: 'Order now',
            reset: 'Reset to default', done: 'Done', close: 'Close',
            dec: 'Smaller', inc: 'Bigger', saved: 'Saved automatically on this device.'
        },
        am: {
            fab: 'የማሳያ መጠን ቅንብር',
            title: 'የማሳያ መጠን',
            sub: 'ለዓይንዎ የሚመችዎትን መጠን ይምረጡ።',
            presets: 'ፈጣን ምርጫዎች',
            pSmall: 'ትንሽ', pStd: 'መደበኛ', pLarge: 'ትልቅ', pXl: 'እጅግ ትልቅ', pEasy: 'ትልቅ + ደማቅ',
            text: 'የጽሑፍ መጠን', icons: 'የአዶ መጠን', buttons: 'የአዝራር መጠን', logos: 'የአርማ መጠን',
            weight: 'የጽሑፍ ውፍረት', wNormal: 'መደበኛ', wBold: 'ደማቅ', wXbold: 'እጅግ ደማቅ',
            preview: 'ቅድመ እይታ', sample: 'ሰላም ሐዋሳ!', btn: 'አሁን ይዘዙ',
            reset: 'ወደ መጀመሪያው መልስ', done: 'ተጠናቀቀ', close: 'ዝጋ',
            dec: 'ቀንስ', inc: 'ጨምር', saved: 'በዚህ መሣሪያ ላይ በራሱ ይቀመጣል።'
        }
    };
    function lang() {
        var l = 'en';
        try { l = localStorage.getItem('etel_lang') || doc.documentElement.getAttribute('lang') || 'en'; } catch (e) {}
        return l.toLowerCase().indexOf('am') === 0 ? 'am' : 'en';
    }
    function T(k) { return (I18N[lang()] || I18N.en)[k] || I18N.en[k] || k; }

    var CSS_UI = [
        ':host{all:initial}',
        '*{box-sizing:border-box;font-family:"Noto Sans Ethiopic","Outfit","Sora","Syne",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
        '@media print{:host{display:none!important}}',
        '.fab{position:fixed;z-index:2147483000;width:46px;height:46px;border-radius:50%;border:2px solid #D4AF37;',
        'background:#0d0d0d;color:#F2CC6B;font-size:17px;font-weight:800;letter-spacing:-.02em;cursor:pointer;',
        'display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.45);',
        'touch-action:none;user-select:none;-webkit-user-select:none;padding:0}',
        '.fab:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid #F2CC6B;outline-offset:2px}',
        '.fab small{font-size:12px;font-weight:700}',
        '.veil{position:fixed;inset:0;z-index:2147483001;background:rgba(0,0,0,.55);display:none;',
        'align-items:flex-end;justify-content:center}',
        '.veil.open{display:flex}',
        '.panel{width:100%;max-width:460px;max-height:92vh;overflow:auto;background:#0d0d0d;color:#f5f0e6;',
        'border:1px solid rgba(212,175,55,.55);border-radius:20px 20px 0 0;padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));',
        'box-shadow:0 -10px 40px rgba(0,0,0,.6);font-size:16px;line-height:1.35}',
        '@media(min-width:560px){.veil{align-items:center}.panel{border-radius:20px;max-height:88vh}}',
        '.hd{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}',
        'h2{margin:0;font-size:20px;font-weight:800;color:#F2CC6B}',
        '.sub{margin:4px 0 0;font-size:14px;color:#c9c2b0}',
        '.x{flex:none;width:44px;height:44px;border-radius:50%;border:1px solid rgba(212,175,55,.5);background:transparent;',
        'color:#F2CC6B;font-size:22px;cursor:pointer;padding:0;line-height:1}',
        '.lbl{font-size:13px;font-weight:700;color:#D4AF37;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 8px}',
        '.presets{display:flex;flex-wrap:wrap;gap:8px}',
        '.pre{min-height:44px;padding:8px 14px;border-radius:999px;border:1px solid rgba(212,175,55,.5);background:#17130a;',
        'color:#f5f0e6;font-size:15px;font-weight:600;cursor:pointer}',
        '.pre.on{background:#D4AF37;color:#0d0d0d;border-color:#D4AF37}',
        '.prev{border:1px dashed rgba(212,175,55,.5);border-radius:14px;padding:14px;display:flex;align-items:center;',
        'gap:calc(12px * var(--is,1));flex-wrap:wrap;background:#130d05;overflow:hidden}',
        '.prev .logo{width:calc(40px * var(--ls,1));height:calc(40px * var(--ls,1));border-radius:28%;background:#D4AF37;',
        'color:#0d0d0d;display:flex;align-items:center;justify-content:center;font-weight:800;',
        'font-size:calc(20px * var(--ls,1))}',
        '.prev .ic{font-size:calc(24px * var(--is,1));line-height:1}',
        '.prev .tx{font-size:calc(16px * var(--ts,1));font-weight:max(400,var(--fw,0))}',
        '.prev .bt{font-size:calc(15px * var(--ts,1));padding:calc(10px * var(--bs,1)) calc(18px * var(--bs,1));',
        'border-radius:12px;border:0;background:#D4AF37;color:#0d0d0d;font-weight:max(700,var(--fw,0))}',
        '.row{margin:12px 0 4px}',
        '.rh{display:flex;justify-content:space-between;align-items:baseline;font-weight:700;font-size:16px}',
        '.rh b{color:#F2CC6B;font-variant-numeric:tabular-nums;font-size:16px}',
        '.ctl{display:flex;align-items:center;gap:10px;margin-top:6px}',
        '.step{flex:none;width:46px;height:46px;border-radius:12px;border:1px solid rgba(212,175,55,.55);background:#17130a;',
        'color:#F2CC6B;font-size:24px;font-weight:700;cursor:pointer;padding:0;line-height:1}',
        'input[type=range]{flex:1;min-width:0;height:36px;accent-color:#D4AF37;background:transparent;margin:0}',
        '.seg{display:flex;gap:8px}',
        '.seg button{flex:1;min-height:46px;border-radius:12px;border:1px solid rgba(212,175,55,.5);background:#17130a;',
        'color:#f5f0e6;font-size:15px;cursor:pointer;padding:6px}',
        '.seg button.on{background:#D4AF37;color:#0d0d0d;border-color:#D4AF37}',
        '.seg button:nth-child(1){font-weight:400}.seg button:nth-child(2){font-weight:700}.seg button:nth-child(3){font-weight:900}',
        '.ft{display:flex;gap:10px;margin-top:18px}',
        '.ft button{flex:1;min-height:48px;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;padding:8px}',
        '.rs{background:transparent;color:#F2CC6B;border:1px solid rgba(212,175,55,.6)}',
        '.dn{background:#D4AF37;color:#0d0d0d;border:1px solid #D4AF37}',
        '.note{margin:10px 0 0;font-size:12px;color:#9c957f;text-align:center}'
    ].join('');

    var host, shadow, fab, veil, panel, ui = {}, lastFocus = null;

    function el(tag, cls, txt) {
        var e = doc.createElement(tag);
        if (cls) e.className = cls;
        if (txt != null) e.textContent = txt;
        return e;
    }

    var ROWS = [['ts', 'text'], ['is', 'icons'], ['bs', 'buttons'], ['ls', 'logos']];
    var PRESETS = [
        ['pSmall', { ts: 85, is: 85, bs: 85, ls: 85, fw: 0 }],
        ['pStd', { ts: 100, is: 100, bs: 100, ls: 100, fw: 0 }],
        ['pLarge', { ts: 125, is: 125, bs: 125, ls: 125, fw: 0 }],
        ['pXl', { ts: 150, is: 150, bs: 150, ls: 150, fw: 0 }],
        ['pEasy', { ts: 130, is: 130, bs: 130, ls: 130, fw: 1 }]
    ];

    function build() {
        if (host) return;
        host = doc.createElement('div');
        host.id = HOST_ID;
        host.setAttribute('data-etel-noscale', '1');
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        var st = doc.createElement('style');
        st.textContent = CSS_UI;
        shadow.appendChild(st);

        fab = el('button', 'fab');
        if (fabOff) fab.style.display = 'none';
        fab.type = 'button';
        fab.innerHTML = 'A<small>a</small>';
        fab.addEventListener('click', function () { if (!dragMoved) open(); });
        placeFab(loadPos());
        enableDrag();
        shadow.appendChild(fab);

        veil = el('div', 'veil');
        veil.addEventListener('mousedown', function (e) { if (e.target === veil) close(); });
        veil.addEventListener('touchstart', function (e) { if (e.target === veil) close(); }, { passive: true });
        panel = el('div', 'panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.tabIndex = -1;
        veil.appendChild(panel);
        shadow.appendChild(veil);
        renderPanel();

        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && veil.classList.contains('open')) close();
        });
        (doc.body || root).appendChild(host);
    }

    function renderPanel() {
        panel.textContent = '';
        ui = { rows: {}, presets: [], seg: [] };
        panel.setAttribute('aria-label', T('title'));
        fab.setAttribute('aria-label', T('fab'));
        fab.title = T('fab');

        var hd = el('div', 'hd');
        var hw = el('div');
        hw.appendChild(el('h2', '', T('title')));
        hw.appendChild(el('p', 'sub', T('sub')));
        var x = el('button', 'x', '×');
        x.type = 'button';
        x.setAttribute('aria-label', T('close'));
        x.addEventListener('click', close);
        hd.appendChild(hw); hd.appendChild(x);
        panel.appendChild(hd);

        panel.appendChild(el('div', 'lbl', T('presets')));
        var pr = el('div', 'presets');
        PRESETS.forEach(function (p) {
            var b = el('button', 'pre', T(p[0]));
            b.type = 'button';
            b.addEventListener('click', function () { set(p[1]); });
            ui.presets.push([b, p[1]]);
            pr.appendChild(b);
        });
        panel.appendChild(pr);

        panel.appendChild(el('div', 'lbl', T('preview')));
        var pv = el('div', 'prev');
        ui.prev = pv;
        var logo = el('span', 'logo', 'E'); logo.setAttribute('aria-hidden', 'true');
        var ic = el('span', 'ic', '🚗'); ic.setAttribute('aria-hidden', 'true');
        pv.appendChild(logo); pv.appendChild(ic);
        pv.appendChild(el('span', 'tx', T('sample')));
        var bt = el('button', 'bt', T('btn')); bt.type = 'button'; bt.tabIndex = -1;
        pv.appendChild(bt);
        panel.appendChild(pv);

        ROWS.forEach(function (r) {
            var k = r[0];
            var row = el('div', 'row');
            var rh = el('div', 'rh');
            var name = el('span', '', T(r[1]));
            var val = el('b', '', '');
            rh.appendChild(name); rh.appendChild(val);
            var ctl = el('div', 'ctl');
            var dec = el('button', 'step', '−'); dec.type = 'button'; dec.setAttribute('aria-label', T('dec') + ' - ' + T(r[1]));
            var inc = el('button', 'step', '+'); inc.type = 'button'; inc.setAttribute('aria-label', T('inc') + ' - ' + T(r[1]));
            var rng = doc.createElement('input');
            rng.type = 'range'; rng.min = MIN; rng.max = MAX; rng.step = STEP;
            rng.setAttribute('aria-label', T(r[1]));
            rng.addEventListener('input', function () { var o = {}; o[k] = Number(rng.value); set(o); });
            dec.addEventListener('click', function () { var o = {}; o[k] = prefs[k] - STEP; set(o); });
            inc.addEventListener('click', function () { var o = {}; o[k] = prefs[k] + STEP; set(o); });
            ctl.appendChild(dec); ctl.appendChild(rng); ctl.appendChild(inc);
            row.appendChild(rh); row.appendChild(ctl);
            panel.appendChild(row);
            ui.rows[k] = { rng: rng, val: val };
        });

        panel.appendChild(el('div', 'lbl', T('weight')));
        var seg = el('div', 'seg');
        ['wNormal', 'wBold', 'wXbold'].forEach(function (key, i) {
            var b = el('button', '', T(key)); b.type = 'button';
            b.addEventListener('click', function () { set({ fw: i }); });
            ui.seg.push(b); seg.appendChild(b);
        });
        panel.appendChild(seg);

        var ft = el('div', 'ft');
        var rs = el('button', 'rs', T('reset')); rs.type = 'button';
        rs.addEventListener('click', reset);
        var dn = el('button', 'dn', T('done')); dn.type = 'button';
        dn.addEventListener('click', close);
        ft.appendChild(rs); ft.appendChild(dn);
        panel.appendChild(ft);
        panel.appendChild(el('p', 'note', T('saved')));
        syncUI();
    }

    function syncUI() {
        if (!host || !ui.rows) return;
        ROWS.forEach(function (r) {
            var k = r[0], u = ui.rows[k];
            if (!u) return;
            if (Number(u.rng.value) !== prefs[k]) u.rng.value = prefs[k];
            u.val.textContent = prefs[k] + '%';
        });
        ui.seg.forEach(function (b, i) {
            b.classList.toggle('on', prefs.fw === i);
            b.setAttribute('aria-pressed', prefs.fw === i ? 'true' : 'false');
        });
        ui.presets.forEach(function (p) {
            var on = sanitize(p[1]);
            var same = on.ts === prefs.ts && on.is === prefs.is && on.bs === prefs.bs && on.ls === prefs.ls && on.fw === prefs.fw;
            p[0].classList.toggle('on', same);
            p[0].setAttribute('aria-pressed', same ? 'true' : 'false');
        });
        var s = ui.prev.style;
        s.setProperty('--ts', prefs.ts / 100);
        s.setProperty('--is', prefs.is / 100);
        s.setProperty('--bs', prefs.bs / 100);
        s.setProperty('--ls', prefs.ls / 100);
        s.setProperty('--fw', FW_MIN[prefs.fw]);
    }

    /* floating button position (draggable, remembered) */
    var dragMoved = false;
    function loadPos() {
        try {
            var p = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
            if (p && typeof p.x === 'number' && typeof p.y === 'number') return p;
        } catch (e) {}
        return null;
    }
    function placeFab(p) {
        var s = fab.style;
        if (p) {
            var maxX = Math.max(0, window.innerWidth - 50), maxY = Math.max(0, window.innerHeight - 50);
            s.left = Math.min(Math.max(0, p.x), maxX) + 'px';
            s.top = Math.min(Math.max(0, p.y), maxY) + 'px';
            s.bottom = 'auto';
        } else {
            s.left = '16px';
            s.top = 'auto';
            s.bottom = 'calc(env(safe-area-inset-bottom,0px) + 150px)';
        }
    }
    function enableDrag() {
        var sx, sy, ox, oy, down = false;
        fab.addEventListener('pointerdown', function (e) {
            down = true; dragMoved = false;
            var r = fab.getBoundingClientRect();
            sx = e.clientX; sy = e.clientY; ox = r.left; oy = r.top;
            try { fab.setPointerCapture(e.pointerId); } catch (er) {}
        });
        fab.addEventListener('pointermove', function (e) {
            if (!down) return;
            var dx = e.clientX - sx, dy = e.clientY - sy;
            if (!dragMoved && Math.abs(dx) + Math.abs(dy) < 8) return;
            dragMoved = true;
            placeFab({ x: ox + dx, y: oy + dy });
        });
        function end() {
            if (!down) return;
            down = false;
            if (dragMoved) {
                var r = fab.getBoundingClientRect();
                try { localStorage.setItem(POS_KEY, JSON.stringify({ x: Math.round(r.left), y: Math.round(r.top) })); } catch (e) {}
                setTimeout(function () { dragMoved = false; }, 0);
            }
        }
        fab.addEventListener('pointerup', end);
        fab.addEventListener('pointercancel', end);
        window.addEventListener('resize', function () { var p = loadPos(); if (p) placeFab(p); });
    }

    /* ---------------------------  API  ------------------------------ */
    function set(part) {
        var n = {};
        for (var k in prefs) n[k] = prefs[k];
        for (var k2 in (part || {})) if (k2 in n) n[k2] = part[k2];
        prefs = sanitize(n);
        save();
        applyVars();
        syncUI();
        return get();
    }
    function get() { return { ts: prefs.ts, is: prefs.is, bs: prefs.bs, ls: prefs.ls, fw: prefs.fw }; }
    function reset() { return set(DEFAULTS); }
    function open() {
        build();
        renderPanel();                       // refresh language
        lastFocus = doc.activeElement;
        veil.classList.add('open');
        panel.focus();
    }
    function close() {
        if (!veil) return;
        veil.classList.remove('open');
        try { fab.focus(); } catch (e) {}
    }

    window.EtelDisplay = { open: open, close: close, get: get, set: set, reset: reset };

    /* ---------------------------  boot  ----------------------------- */
    applyVars();                              // sets vars + starts engine if non-default

    window.addEventListener('storage', function (e) {                // another tab/app changed it
        if (e.key !== KEY && e.key !== null) return;
        prefs = load();
        applyVars();
        syncUI();
    });
    doc.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-etel-display-open]') : null;
        if (t) { e.preventDefault(); open(); }
    });

    function ready() { build(); }
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
