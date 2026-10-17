/* Courier app: English / Amharic (add-on, v8 canary).
 * The courier screens are drawn by the release bundle, which has no translation hooks. Rather than change that bundle, this add-on translates
 * what is on screen: it looks up each piece of text in a dictionary (exact match, then a few number patterns), keeps the English original so the
 * switch can go back, and watches the page so text drawn later (job cards, toasts, timers) is translated as it appears.
 * Same language setting as every other ETEL app (localStorage "etel_lang"). Text with no entry stays English and is listed by
 * window.__etelCourierI18nMissing() so the next release can add it. Nothing here touches orders, money or location. */
(function () {
    'use strict';
    if (window.__etelCourierI18n) return;
    window.__etelCourierI18n = 'v8';

    var EXACT = {
        // header, status, tabs
        'ETEL Courier': 'ኢቴል ሹፌር', 'Professional Delivery': 'ሙያዊ አቅርቦት', 'LIVE': 'ቀጥታ', 'Slide to go online': 'በመስመር ለመሆን ይጎትቱ',
        'Online — Ready for deliveries': 'በመስመር ላይ — ለማድረስ ዝግጁ', 'Loading map…': 'ካርታ በመጫን ላይ…', 'Calculating…': 'በማስላት ላይ…',
        'Fastest driving route · Hawassa': 'ፈጣኑ መንገድ · ሃዋሳ', '✕ Clear Route': '✕ መንገዱን አጽዳ',
        'JOBS': 'ሥራዎች', 'ACTIVE': 'ንቁ', 'HISTORY': 'ታሪክ', 'All': 'ሁሉም', 'High Pay': 'ከፍተኛ ክፍያ', 'Refresh': 'አድስ', 'Refresh jobs': 'ሥራዎችን አድስ',
        'Loading available jobs…': 'ያሉ ሥራዎች በመጫን ላይ…', 'Loading active deliveries…': 'ንቁ አቅርቦቶች በመጫን ላይ…', 'Loading history…': 'ታሪክ በመጫን ላይ…', 'Loading…': 'በመጫን ላይ…',
        'A new version (': 'አዲስ ስሪት (', ') is available.': ') ተዘጋጅቷል።', 'Update Now': 'አሁን አዘምን',
        // profile
        'Earnings': 'ገቢ', '🛵 VERIFIED COURIER': '🛵 የተረጋገጠ ሹፌር', 'Deliveries': 'አቅርቦቶች', 'Rating': 'ደረጃ', 'All-time': 'ሁሉም ጊዜ', 'Vehicle': 'ተሽከርካሪ', 'Zone': 'አካባቢ', 'Courier ID': 'የሹፌር መታወቂያ',
        '📊 Your Performance': '📊 አፈጻጸምዎ', 'Today': 'ዛሬ', 'Week': 'ሳምንት', 'Month': 'ወር', 'View delivery totals': 'የአቅርቦት ድምርን ይመልከቱ',
        'Delivery History': 'የአቅርቦት ታሪክ', 'All your past deliveries': 'ያለፉ አቅርቦቶችዎ ሁሉ', 'Invite Friends': 'ጓደኞችን ይጋብዙ', 'Earn together with ETEL Delivery': 'ከኢቴል ዴሊቨሪ ጋር አብረው ይሥሩ',
        'Two-step sign-in': 'ባለ ሁለት ደረጃ መግቢያ', 'A code from your phone when you sign in': 'ሲገቡ ከስልክዎ የሚላክ ኮድ', 'Delete my account': 'አካውንቴን ሰርዝ', 'Ask ETEL to delete your account and data': 'ኢቴል አካውንትዎን እና መረጃዎን እንዲሰርዝ ይጠይቁ',
        'Privacy Policy': 'የግላዊነት መመሪያ', 'How ETEL uses your data': 'ኢቴል መረጃዎን እንዴት እንደሚጠቀም', 'Display Size': 'የማሳያ መጠን', 'Text, icons, buttons and logos': 'ጽሑፍ፣ ምልክቶች፣ አዝራሮች እና አርማዎች',
        '📲 Download the ETEL app': '📲 የኢቴል መተግበሪያን ያውርዱ', '🛡️ Admin Dashboard': '🛡️ የአስተዳዳሪ ዳሽቦርድ', '🧹 Fix app not updating': '🧹 መተግበሪያው አልዘመነም? አስተካክል', '🚪 Logout': '🚪 ውጣ',
        'Show this to the Service Giver': 'ይህን ለአገልግሎት ሰጪው ያሳዩ', "They'll scan it to confirm instantly": 'ወዲያውኑ ለማረጋገጥ ይቃኙታል', 'Close': 'ዝጋ',
        'Dismiss': 'አሰናብት', 'Switch to Driver Dashboard': 'ወደ የመኪና ሹፌር ዳሽቦርድ ቀይር', 'Driver mode': 'የመኪና ሹፌር ሁነታ', 'Switch to dark mode': 'ወደ ጨለማ ሁነታ ቀይር', 'Profile': 'መገለጫ',
        'Full map': 'ሙሉ ካርታ', 'Search destination…': 'መድረሻ ፈልግ…', 'Main menu': 'ዋና ምናሌ', 'Tap to refresh. Hold to fully update.': 'ለማደስ ይንኩ። ሙሉ በሙሉ ለማዘመን ተጭነው ይያዙ።', 'Install App': 'መተግበሪያ ጫን', 'Language': 'ቋንቋ',
        // job cards
        'Accept': 'ተቀበል', 'Accept Job': 'ሥራውን ተቀበል', 'Accept a job from the Jobs tab': 'ከ"ሥራዎች" ትር ሥራ ይቀበሉ', 'Accepted': 'ተቀብሏል', 'Add': 'ጨምር', 'Amount paid (ETB)': 'የተከፈለ መጠን (ብር)',
        'Cancel': 'ተው', 'Cancel request': 'ጥያቄውን ተው', 'Check your internet connection and try again.': 'የበይነመረብ ግንኙነትዎን ይፈትሹ እና እንደገና ይሞክሩ።', 'Collected': 'ተሰብስቧል', 'Could not load active orders.': 'ንቁ ትዕዛዞችን መጫን አልተቻለም።',
        'Could not load your code — try again shortly.': 'ኮድዎን መጫን አልተቻለም — ትንሽ ቆይተው ይሞክሩ።', 'Decline': 'አትቀበል', 'Delivered': 'ደርሷል', 'Done': 'ተጠናቋል', 'Drop-off': 'ማስረከቢያ', 'ETB': 'ብር',
        'Liters': 'ሊትር', 'Maps API Error': 'የካርታ ስህተት', 'NEW JOB OFFER': 'አዲስ የሥራ ጥያቄ', 'NEW PACKAGE OFFER': 'አዲስ የጥቅል ጥያቄ', 'Net ETB/hour': 'የተጣራ ብር/ሰዓት',
        'No active deliveries': 'ንቁ አቅርቦት የለም', 'No completed deliveries yet': 'እስካሁን የተጠናቀቀ አቅርቦት የለም', 'No fill-ups logged yet.': 'እስካሁን የነዳጅ ሙሌት አልተመዘገበም።', 'No history available.': 'ታሪክ የለም።', 'No jobs available right now': 'አሁን ምንም ሥራ የለም',
        'Odometer reading (km) — optional but recommended': 'የኦዶሜትር ንባብ (ኪ.ሜ) — አማራጭ ግን ይመከራል', 'Online time': 'በመስመር ላይ የቆዩበት ጊዜ', 'Open in Maps ↗': 'በካርታ ክፈት ↗', 'Pickup': 'መረከቢያ', 'Pickup:': 'መረከቢያ፦',
        'Retry': 'እንደገና ሞክር', 'Save Fill-up': 'ሙሌቱን አስቀምጥ', 'Share Code': 'ኮዱን አጋራ', 'Skip': 'እለፍ', 'Tap to open in Google Maps navigation': 'በGoogle Maps ለመክፈት ይንኩ',
        'To the pickup': 'ወደ መረከቢያው', 'To the recipient': 'ወደ ተቀባዩ', 'Today’s Earnings': 'የዛሬ ገቢ', 'Total': 'ጠቅላላ', 'Total Earned': 'ጠቅላላ ያገኙት', 'Waiting for service giver to confirm pickup…': 'አገልግሎት ሰጪው መረከቡን እስኪያረጋግጥ በመጠበቅ ላይ…',
        'You earn': 'የሚያገኙት', '⛽ Log a Fill-up': '⛽ የነዳጅ ሙሌት መዝግብ', '✓ DELIVERED': '✓ ደርሷል', '👥 Invite Friends': '👥 ጓደኞችን ይጋብዙ', '📍 CUSTOMER LOCATION': '📍 የደንበኛ አድራሻ',
        '📍 Getting your location…': '📍 አካባቢዎን በማግኘት ላይ…', '📍 PICKUP ZONE': '📍 የመረከቢያ አካባቢ', '📍 SHOP LOCATION': '📍 የሱቅ አድራሻ', '📞 Call': '📞 ደውል', '📦 Package': '📦 ጥቅል',
        '📷 Customer added a gate/entrance photo — tap to view': '📷 ደንበኛው የበር ፎቶ አክሏል — ለማየት ይንኩ', '🔇 Mute': '🔇 አጥፋ',
        // package add-on
        'Arrive about ': 'ይደርሳል በ ', 'Call recipient': 'ተቀባዩን ደውል', 'Call sender': 'ላኪውን ደውል', 'Collect from': 'ከዚህ ተረከብ', 'Deliver to': 'ወደዚህ አድርስ', 'Delivery PIN': 'የማስረከቢያ ፒን', 'Pickup PIN': 'የመረከቢያ ፒን',
        'Picked up': 'ተወስዷል', 'Sender': 'ላኪ', 'Could not check the PIN. Try again.': 'ፒኑን ማረጋገጥ አልተቻለም። እንደገና ይሞክሩ።', 'Enter the 4-digit PIN.': 'ባለ 4 አሃዝ ፒኑን ያስገቡ።', 'No connection. Try again.': 'ግንኙነት የለም። እንደገና ይሞክሩ።',
        'Enter the recipient’s delivery PIN': 'የተቀባዩን የማስረከቢያ ፒን ያስገቡ', 'Enter the sender’s pickup PIN': 'የላኪውን የመረከቢያ ፒን ያስገቡ', 'Recipient’s delivery PIN': 'የተቀባዩ የማስረከቢያ ፒን', 'Sender’s pickup PIN': 'የላኪው የመረከቢያ ፒን',
        'The recipient reads the 4-digit PIN to you. It confirms they received it.': 'ተቀባዩ ባለ 4 አሃዝ ፒኑን ይነግርዎታል። መቀበላቸውን ያረጋግጣል።', 'The sender shows the 4-digit PIN in their app. It confirms you have the right package.': 'ላኪው ባለ 4 አሃዝ ፒኑን በመተግበሪያው ያሳይዎታል። ትክክለኛውን ጥቅል መውሰድዎን ያረጋግጣል።',
        'Document or envelope': 'ሰነድ ወይም ፖስታ', 'Small (shoebox)': 'ትንሽ (የጫማ ሳጥን)', 'Medium (backpack)': 'መካከለኛ (ቦርሳ)', 'Large (box, suitcase)': 'ትልቅ (ሳጥን፣ ሻንጣ)',
        '📦 Collect from the sender': '📦 ከላኪው ተረከብ', '🚚 Take it to the recipient': '🚚 ወደ ተቀባዩ ውሰድ', 'Enter': 'አስገባ',
        // messages and toasts
        'Confirmation request cancelled': 'የማረጋገጫ ጥያቄው ተሰርዟል', 'Copied to clipboard': 'ወደ ክሊፕቦርድ ተቀድቷል', 'Could not delete': 'መሰረዝ አልተቻለም', 'Could not find customer location': 'የደንበኛውን አድራሻ ማግኘት አልተቻለም',
        'Could not get location': 'አካባቢን ማግኘት አልተቻለም', 'Could not go offline': 'ከመስመር መውጣት አልተቻለም', 'Could not go online': 'በመስመር መሆን አልተቻለም', 'Could not load earnings': 'ገቢን መጫን አልተቻለም',
        'Customer location not available': 'የደንበኛ አድራሻ አልተገኘም', 'Enable notifications to hear about new jobs even when the app is in the background': 'መተግበሪያው ከበስተጀርባ ሲሆንም አዳዲስ ሥራዎችን ለመስማት ማሳወቂያዎችን ያንቁ',
        'Geolocation not available': 'የአካባቢ መረጃ አይገኝም', 'Go offline? You will stop appearing available for new deliveries.': 'ከመስመር ይውጡ? ለአዳዲስ አቅርቦቶች ዝግጁ ሆነው መታየት ያቆማሉ።', 'Job accepted! Go to Active tab.': 'ሥራው ተቀባይነት አግኝቷል! ወደ "ንቁ" ትር ይሂዱ።',
        'Job accepted! Head to the shop.': 'ሥራው ተቀባይነት አግኝቷል! ወደ ሱቁ ይሂዱ።', 'Logout?': 'ይውጡ?', 'Map not ready — try again': 'ካርታው ዝግጁ አይደለም — እንደገና ይሞክሩ', 'Map not ready — try again in a moment': 'ካርታው ዝግጁ አይደለም — ትንሽ ቆይተው ይሞክሩ',
        'Map not ready. Try again in a moment.': 'ካርታው ዝግጁ አይደለም። ትንሽ ቆይተው ይሞክሩ።', 'New job offer - accept it before the timer runs out': 'አዲስ የሥራ ጥያቄ - ጊዜው ሳያልቅ ይቀበሉ', 'Offer declined': 'ጥያቄው ውድቅ ተደርጓል',
        'Remove this fuel log?': 'ይህ የነዳጅ መዝገብ ይወገድ?', 'Request sent! Waiting for customer to confirm…': 'ጥያቄ ተልኳል! ደንበኛው እስኪያረጋግጥ በመጠበቅ ላይ…', 'Request sent! Waiting for service giver to confirm…': 'ጥያቄ ተልኳል! አገልግሎት ሰጪው እስኪያረጋግጥ በመጠበቅ ላይ…',
        'Share not supported on this browser': 'ማጋራት በዚህ ብራውዘር አይደገፍም', "You're offline": 'ከመስመር ውጭ ነዎት', "You're online": 'በመስመር ላይ ነዎት', '✅ Pickup confirmed by service giver! Order is now in transit.': '✅ አገልግሎት ሰጪው መረከቡን አረጋግጧል! ትዕዛዙ አሁን በጉዞ ላይ ነው።',
        '🎉 Delivery confirmed by customer! Great job!': '🎉 ደንበኛው ማድረሱን አረጋግጧል! ጥሩ ሥራ!', '📦 New job available!': '📦 አዲስ ሥራ አለ!'
    };
    // text that carries a number or a name: the captured parts are put back unchanged
    var PATTERNS = [
        [/^Arrive about (.+)$/, 'ይደርሳል በ $1'], [/^ETEL Courier v(.+)$/, 'ኢቴል ሹፌር v$1'],
        [/^(\d+(?:\.\d+)?) km away$/, '$1 ኪ.ሜ ይርቃል'], [/^(\d+(?:\.\d+)?) km trip$/, '$1 ኪ.ሜ ጉዞ'], [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ'],
        [/^(\d+) min$/, '$1 ደቂቃ'], [/^ETB (.+)$/, 'ብር $1'],
        [/^Sender: (.+)$/, 'ላኪ፦ $1'], [/^Pickup · (.+)$/, 'መረከቢያ · $1'], [/^Drop-off · (.+)$/, 'ማስረከቢያ · $1'],
        [/^Accept job (.+)$/, 'ሥራ $1 ተቀበል'], [/^(\d+) jobs?$/, '$1 ሥራ'], [/^(\d+) deliver(?:y|ies)$/, '$1 አቅርቦት']
    ];
    var ETHIOPIC = /[ሀ-፿]/;
    var lang = 'en';
    var missing = {};

    function norm(s) { return s.replace(/\s+/g, ' ').trim(); }
    function translate(text) {
        var core = norm(text);
        if (!core) return null;
        var hit = EXACT.hasOwnProperty(core) ? EXACT[core] : null;
        if (hit === null) {
            for (var i = 0; i < PATTERNS.length; i++) {
                if (PATTERNS[i][0].test(core)) { hit = core.replace(PATTERNS[i][0], PATTERNS[i][1]); break; }
            }
        }
        if (hit === null) {
            if (/[A-Za-z]{3}/.test(core) && !ETHIOPIC.test(core) && core.length < 160) missing[core] = 1;
            return null;
        }
        var lead = text.match(/^\s*/)[0], tail = text.match(/\s*$/)[0];
        return lead + hit + tail;
    }

    // ---- text nodes ----
    function skip(el) {
        for (var n = el; n && n.nodeType === 1; n = n.parentNode) {
            var t = n.tagName;
            if (t === 'SCRIPT' || t === 'STYLE' || t === 'TEXTAREA' || t === 'NOSCRIPT' || n.hasAttribute('data-no-i18n')) return true;
        }
        return false;
    }
    function doNode(node) {
        if (node.__etelAm === node.nodeValue) return;            // our own translation
        if (node.__etelEn !== undefined && node.__etelAm !== node.nodeValue) { node.__etelEn = undefined; node.__etelAm = undefined; }   // the app wrote new text
        if (lang !== 'am') return;
        if (!node.parentNode || skip(node.parentNode)) return;
        var tr = translate(node.nodeValue);
        if (tr === null || tr === node.nodeValue) return;
        node.__etelEn = node.nodeValue; node.__etelAm = tr; node.nodeValue = tr;
    }
    var ATTRS = ['placeholder', 'title', 'aria-label'];
    function doAttrs(el) {
        if (!el.getAttribute || skip(el)) return;
        ATTRS.forEach(function (a) {
            var v = el.getAttribute(a);
            if (!v) return;
            var key = '__etel_' + a;
            if (el[key] && el[key].am === v) return;
            if (lang !== 'am') return;
            var tr = translate(v);
            if (tr !== null && tr !== v) { el[key] = { en: v, am: tr }; el.setAttribute(a, tr); }
        });
    }
    function walk(root) {
        if (!root) return;
        if (root.nodeType === 3) { doNode(root); return; }
        if (root.nodeType !== 1 || skip(root)) return;
        doAttrs(root);
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null);
        var n;
        while ((n = w.nextNode())) { if (n.nodeType === 3) doNode(n); else doAttrs(n); }
    }
    function restore(root) {
        var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, null), n;
        while ((n = w.nextNode())) {
            if (n.nodeType === 3) { if (n.__etelEn !== undefined && n.nodeValue === n.__etelAm) n.nodeValue = n.__etelEn; n.__etelEn = undefined; n.__etelAm = undefined; }
            else ATTRS.forEach(function (a) { var k = '__etel_' + a; if (n[k] && n.getAttribute(a) === n[k].am) n.setAttribute(a, n[k].en); n[k] = undefined; });
        }
    }

    // ---- watch the page (batched: many changes in one frame are handled once) ----
    var pending = [], queued = false;
    function flush() {
        queued = false;
        var list = pending; pending = [];
        if (lang !== 'am') return;
        list.forEach(function (r) {
            if (r.type === 'childList') { for (var i = 0; i < r.addedNodes.length; i++) walk(r.addedNodes[i]); }
            else if (r.type === 'characterData') doNode(r.target);
            else if (r.type === 'attributes') doAttrs(r.target);
        });
    }
    var obs = new MutationObserver(function (records) {
        if (lang !== 'am') return;
        pending = pending.concat(records);
        if (!queued) { queued = true; (window.requestAnimationFrame || setTimeout)(flush); }
    });
    function start() {
        if (!document.body) return;
        obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
        setLang(currentLang(), true);
        addSwitch();
    }

    // ---- language ----
    function currentLang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function setLang(l, quiet) {
        lang = l === 'am' ? 'am' : 'en';
        try { localStorage.setItem('etel_lang', lang); } catch (e) {}
        document.documentElement.setAttribute('lang', lang);
        if (lang === 'am') walk(document.body); else restore(document.body);
        Array.prototype.forEach.call(document.querySelectorAll('.cl8-b'), function (b) {
            var on = b.getAttribute('data-lang') === lang;
            b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (!quiet) { try { if (window.ETEL_I18N) ETEL_I18N.apply(lang); } catch (e) {} }
    }
    function addSwitch() {
        if (document.getElementById('cl8-lang')) return;
        var host = document.querySelector('.header-right');
        if (!host) { setTimeout(addSwitch, 500); return; }
        var css = document.createElement('style');
        css.id = 'cl8-css';
        css.textContent = '#cl8-lang{display:inline-flex;padding:2px;border-radius:999px;background:rgba(255,255,255,.07);border:1px solid rgba(212,175,55,.4);margin-right:6px;flex-shrink:0}' +
            '.cl8-b{min-width:30px;min-height:30px;padding:0 6px;border:0;border-radius:999px;background:transparent;color:#C9C3B0;font:800 12px/1 inherit;font-family:inherit;cursor:pointer}' +
            '.cl8-b.on{background:linear-gradient(135deg,#FFE9A8,#D4AF37);color:#1a1400}';
        document.head.appendChild(css);
        var box = document.createElement('div');
        box.id = 'cl8-lang'; box.setAttribute('role', 'group'); box.setAttribute('aria-label', 'Language');
        [['en', 'EN'], ['am', 'አማ']].forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'cl8-b'; b.setAttribute('data-lang', p[0]); b.textContent = p[1];
            b.addEventListener('click', function () { setLang(p[0]); });
            box.appendChild(b);
        });
        host.insertBefore(box, host.firstChild);
        setLang(lang, true);
    }

    window.__etelCourierI18nMissing = function () { return Object.keys(missing); };
    window.etelCourierSetLang = setLang;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
