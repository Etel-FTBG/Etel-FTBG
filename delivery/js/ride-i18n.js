/* ETEL Ride - English / Amharic switch (additive script, no bundle release).

   The rider app's text is built in many places (page HTML, ride.js, other add-on scripts), so instead of editing
   each one this script translates what is on screen: it looks every visible piece of text up in the dictionary
   below and swaps it, and keeps watching the page so text drawn later (vehicle list, trip status, history) is
   translated too. The original English is remembered per node, so switching back restores it exactly.
   - Language is stored under the same key as the other ETEL apps (etel_lang), so one choice carries across apps.
   - A button in the header switches language. Text with no entry here simply stays English.
   - Amharic wording is a first draft for a native speaker to review: change the right-hand side of the
     lines below, nothing else needs to move.
   - Never touches typed input values, scripts, styles or the Google Places dropdown. */
(function () {
    'use strict';
    if (window.__etelRideI18n) return;
    window.__etelRideI18n = true;

    var KEY = 'etel_lang';
    var AM = {
        'ETEL Ride': 'ኢቴል ራይድ',
        'Driver': 'ሾፌር', 'Book': 'ጉዞ ይዘዙ', 'Street Hail': 'የመንገድ ጥሪ', 'Active': 'አሁን ያለ', 'History': 'ታሪክ',
        'Update Now': 'አሁን አዘምን',
        'Where are you going?': 'ወዴት ይሄዳሉ?',
        'Quick zones': 'ፈጣን ቦታዎች', 'no GPS needed': 'ጂፒኤስ አያስፈልግም',
        'Set pickup': 'መነሻ ያዘጋጁ', 'Set drop-off': 'መድረሻ ያዘጋጁ',
        'Sending a package instead?': 'ጥቅል መላክ ፈልገዋል?',
        'Rides are for you — Delivery is for your packages': 'ጉዞ ለእርስዎ ነው — ዴሊቨሪ ለጥቅሎችዎ ነው',
        'Open meter': 'ክፍት ሜትር', '— no destination? Tell the driver on the way': '— መድረሻ የለም? በመንገድ ላይ ለሾፌሩ ይንገሩት',
        'Choose vehicle': 'ተሽከርካሪ ይምረጡ',
        'Tap the map to set pickup & drop-off, or type above': 'መነሻና መድረሻ ለማዘጋጀት ካርታውን ይንኩ ወይም ከላይ ይጻፉ',
        'Change': 'ቀይር',
        'Bajaj': 'ባጃጅ', 'Moto': 'ሞተር', 'Car': 'መኪና', 'Minibus': 'ሚኒባስ', 'EV Car': 'የኤሌክትሪክ መኪና',
        'Cheapest': 'በጣም ርካሽ', 'Fastest': 'በጣም ፈጣን',
        'No drivers online': 'ሾፌር በመስመር ላይ የለም', 'Drivers available': 'ሾፌሮች አሉ',
        'See All Types on Map': 'ሁሉንም ዓይነቶች በካርታ ይመልከቱ',
        'Estimated Fare': 'የተገመተ ዋጋ', 'Peak-hour pricing': 'የትራፊክ ሰዓት ዋጋ',
        'Final fare is based on the actual distance your driver travels, from pickup to drop-off — this is just an estimate.':
            'የመጨረሻው ዋጋ ሾፌሩ ከመነሻ እስከ መድረሻ በሚጓዘው ትክክለኛ ርቀት ላይ ይመሰረታል — ይህ ግምት ብቻ ነው።',
        'Cash': 'ጥሬ ገንዘብ', 'Wallet': 'ዋሌት', 'Chapa': 'ቻፓ',
        'Schedule for later': 'ለኋላ ያዘጋጁ', 'Slide to request ride': 'ጉዞ ለመጠየቅ ያንሸራትቱ',
        'Finding your driver…': 'ሾፌር እየፈለግን ነው…', 'This usually takes a few seconds': 'ይህ ብዙውን ጊዜ ጥቂት ሰከንዶችን ይወስዳል',
        'Connecting with nearby drivers': 'በአቅራቢያ ካሉ ሾፌሮች ጋር እየተገናኘን ነው',
        'Pickup': 'መነሻ', 'Your location': 'የእርስዎ አካባቢ', 'Destination': 'መድረሻ', 'Cancel Ride': 'ጉዞ ሰርዝ',
        'Driver on the way': 'ሾፌር እየመጣ ነው', 'Tracking your driver': 'ሾፌርዎን እየተከታተልን ነው',
        'Your Driver': 'ሾፌርዎ', 'Call': 'ደውል', 'Chat': 'ውይይት', 'Issues': 'ችግሮች',
        'Pickup at': 'መነሻ በ', 'Fare estimate': 'የዋጋ ግምት', 'Driver is waiting': 'ሾፌር እየጠበቀ ነው',
        'View Route So Far': 'እስካሁን ያለውን መንገድ ይመልከቱ',
        'Trip Complete': 'ጉዞ ተጠናቋል', 'Loading...': 'በመጫን ላይ...', 'Loading…': 'በመጫን ላይ…',
        'Rate Your Driver': 'ሾፌርዎን ደረጃ ይስጡ', 'How was your ride?': 'ጉዞዎ እንዴት ነበር?',
        'Submit Rating': 'ደረጃ ላክ', 'Skip': 'ዝለል',
        'Ride History': 'የጉዞ ታሪክ', 'All your past trips': 'ያለፉ ጉዞዎችዎ ሁሉ',
        'How it works': 'እንዴት እንደሚሰራ',
        'Why are you cancelling?': 'ለምን ይሰርዛሉ?', 'Help us improve your experience': 'አገልግሎታችንን እንድናሻሽል ይርዱን',
        'Plans changed': 'እቅድ ተቀይሯል', 'Wrong address': 'የተሳሳተ አድራሻ', 'Weather': 'የአየር ሁኔታ', 'Found another ride': 'ሌላ ጉዞ አግኝቻለሁ',
        'Continue with cancellation': 'ስረዛውን ቀጥል', 'Go back': 'ተመለስ',
        'Vehicle': 'ተሽከርካሪ', 'Plate': 'ታርጋ', 'Copy': 'ቅዳ', 'Phone': 'ስልክ', 'Close': 'ዝጋ',
        'Report an Issue': 'ችግር ያሳውቁ', 'Our team reviews every report. Select what happened:': 'ቡድናችን ሁሉንም ሪፖርቶች ይመረምራል። የሆነውን ይምረጡ፦',
        "Driver didn't show up": 'ሾፌሩ አልመጣም', 'Wrong route taken': 'የተሳሳተ መንገድ ተወስዷል', 'Safety concern': 'የደህንነት ስጋት',
        'Fare dispute': 'የዋጋ ክርክር', 'Driver behavior': 'የሾፌር ባህሪ', 'Other': 'ሌላ',
        'Submit Report': 'ሪፖርት ላክ', 'Cancel': 'ሰርዝ', 'Save': 'አስቀምጥ',
        'VERIFIED RIDER': 'የተረጋገጠ ተሳፋሪ', 'Rides': 'ጉዞዎች', 'Total Spent': 'ጠቅላላ ወጪ', 'Your Rating': 'የእርስዎ ደረጃ',
        'Saved Addresses': 'የተቀመጡ አድራሻዎች', 'Quick pickup and drop-off spots': 'ፈጣን የመነሻና መድረሻ ቦታዎች',
        'Favorite Routes': 'ተወዳጅ መንገዶች', 'Save trips you take often': 'ብዙ ጊዜ የሚጓዙባቸውን ጉዞዎች ያስቀምጡ',
        'Messages': 'መልዕክቶች', 'Ride updates and support': 'የጉዞ ዝማኔዎችና ድጋፍ',
        'Referral & Rewards': 'ጓደኛ መጋበዝና ሽልማት', 'Earn ETB with friends': 'ከጓደኞችዎ ጋር ብር ያግኙ',
        'Safety contacts': 'የደህንነት ዕውቂያዎች', 'Two-step sign-in': 'ባለሁለት ደረጃ መግቢያ',
        'Receipt printer': 'የደረሰኝ ማተሚያ', 'Display Size': 'የማሳያ መጠን',
        'Settings & Privacy': 'ቅንብርና ግላዊነት', 'Notifications and password': 'ማሳወቂያዎችና የይለፍ ቃል',
        'Balance and payments': 'ቀሪ ሂሳብና ክፍያዎች', 'Become a Driver': 'ሾፌር ይሁኑ', 'Earn with ETEL Ride': 'በኢቴል ራይድ ገቢ ያግኙ',
        'Logout': 'ውጣ', 'Label': 'መለያ', 'Address': 'አድራሻ', 'Subject': 'ርዕስ', 'Message': 'መልዕክት',
        'Add Address': 'አድራሻ ጨምር', 'Contact Support': 'ድጋፍን ያግኙ', 'Send to Support': 'ወደ ድጋፍ ላክ'
    };
    var AM_ATTR = {
        'Pickup — or tap the map': 'መነሻ — ወይም ካርታውን ይንኩ', 'Where to?': 'ወዴት?', 'Use my current location': 'አሁን ያለሁበትን ተጠቀም',
        'Pickup location': 'የመነሻ ቦታ', 'Drop-off location': 'የመድረሻ ቦታ', 'Full map': 'ሙሉ ካርታ', 'Payment method': 'የክፍያ ዘዴ',
        'Leave a comment (optional)…': 'አስተያየት ይጻፉ (አማራጭ)…', 'Switch to light theme': 'ወደ ብርሃን ገጽታ ቀይር', 'Switch to dark theme': 'ወደ ጨለማ ገጽታ ቀይር'
    };
    var RULES = [   // [pattern, replacement] for text with numbers in it
        [/^~(\d+) min away · (\d+) seats?$/, '~$1 ደቂቃ ይርቃል · $2 መቀመጫ'],
        [/^No drivers online · (\d+) seats?$/, 'ሾፌር በመስመር ላይ የለም · $1 መቀመጫ'],
        [/^Drivers available · (\d+) seats?$/, 'ሾፌሮች አሉ · $1 መቀመጫ'],
        [/^~(\d+) min$/, '~$1 ደቂቃ'],
        [/^(\d+(?:\.\d+)?) km · (\d+) mins?$/, '$1 ኪ.ሜ · $2 ደቂቃ'],
        [/^(\d+(?:\.\d+)?) km$/, '$1 ኪ.ሜ']
    ];

    function lang() { try { return localStorage.getItem(KEY) === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function strip(s) { var m = /^([^A-Za-z0-9'"~]*)([\s\S]*?)([^A-Za-z0-9'"…).?]*)$/.exec(s); return m ? m : [s, '', s, '']; }

    function lookup(raw, table) {
        var t = raw.replace(/\s+/g, ' ').trim();
        if (!t) return null;
        if (table[t]) return table[t];
        var p = strip(t);                      // leading/trailing emoji, arrows, "+" etc. are kept as they are
        if (p[2] && p[2] !== t && table[p[2]]) return p[1] + table[p[2]] + p[3];
        if (table === AM) for (var i = 0; i < RULES.length; i++) if (RULES[i][0].test(t)) return t.replace(RULES[i][0], RULES[i][1]);
        return null;
    }

    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1 };   // INPUT has no text to translate, only its placeholder
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
        if (rec && n.nodeValue !== rec.shown) rec = null;        // something else rewrote it: treat as new English text
        if (to === 'am') {
            var src = rec ? rec.orig : n.nodeValue;
            var tr = lookup(src, AM);
            if (!tr) { if (rec) store.delete(n); return; }
            var lead = /^\s*/.exec(src)[0], trail = /\s*$/.exec(src)[0];
            var out = lead + tr + trail;
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
                var src = el.hasAttribute(o) && el.getAttribute(a) === el.getAttribute(o + '-shown') ? el.getAttribute(o) : cur;
                var tr = lookup(src, AM_ATTR);
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
                if (n.tagName === 'TEXTAREA') { if (!skipEl(n.parentNode)) attrs(n, to); return 2; }   // placeholder only, never its value
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

    function setLang(l) {
        try { localStorage.setItem(KEY, l); } catch (e) { /* not saved: still applies for this visit */ }
        document.documentElement.setAttribute('lang', l);
        run([document.body]);
        updateButton();
        try { window.dispatchEvent(new CustomEvent('etel:lang', { detail: l })); } catch (e) {}
    }

    function updateButton() {
        var b = document.getElementById('ride-lang-btn');
        if (!b) return;
        var am = lang() === 'am';
        b.textContent = am ? 'EN' : 'አማ';
        b.setAttribute('aria-label', am ? 'Switch to English' : 'ወደ አማርኛ ቀይር');
        b.setAttribute('data-no-i18n', '1');
        b.title = am ? 'English' : 'አማርኛ';
    }
    function addButton() {
        if (document.getElementById('ride-lang-btn')) return;
        var host = document.querySelector('.ride-header-user');
        if (!host) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'ride-lang-btn'; b.className = 'ride-theme-btn ride-lang-btn';
        b.addEventListener('click', function () { setLang(lang() === 'am' ? 'en' : 'am'); });
        host.insertBefore(b, host.firstChild);
        updateButton();
    }

    function start() {
        addButton();
        if (lang() === 'am') document.documentElement.setAttribute('lang', 'am');
        run([document.body]);
        var pending = [], timer = 0;
        obs = new MutationObserver(function (recs) {
            for (var i = 0; i < recs.length; i++) {
                var r = recs[i];
                if (r.type === 'childList') { for (var j = 0; j < r.addedNodes.length; j++) pending.push(r.addedNodes[j]); }
                else if (r.type === 'characterData') pending.push(r.target);
                else if (r.type === 'attributes') pending.push(r.target);
            }
            if (!timer && lang() === 'am') timer = setTimeout(function () {
                timer = 0; var batch = pending; pending = [];
                run(batch.filter(function (n) { return n && n.isConnected; }));
            }, 30);
            else if (lang() !== 'am') pending = [];
        });
        obs.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'aria-label'] });
        var tries = 0, iv = setInterval(function () { addButton(); updateButton(); if (++tries > 40) clearInterval(iv); }, 750);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    window.EtelRideI18n = { setLang: setLang, lang: lang };
})();
