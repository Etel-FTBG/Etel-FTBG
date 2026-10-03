/* ETEL UI kit - shared by the rider and driver bundles.
 *
 * 1. EtelUI.icon(name, size)  - one drawn SVG icon family (24px grid, 1.75
 *    stroke, round caps, currentColor) so nav, buttons and badges stop using
 *    emoji as icons.
 * 2. EtelUI.vehicleGlyph(type) - the same family, for Bajaj / Moto / Car /
 *    Minibus / Qute.
 * 3. EtelUI.mapStyles(isLight) - carbon + gold map styling, applied client
 *    side. Both maps used to be created with a Cloud mapId, which ignores
 *    `styles` entirely and left the rider map in default light Google colours
 *    under a dark gold UI. All markers in this app are classic
 *    google.maps.Marker, which does not need a mapId.
 * 4. EtelUI.vehicleMarker(type, opts) - google.maps.Marker icon (round carbon
 *    badge, gold ring, vehicle glyph) replacing emoji-on-circle pins.
 */
(function () {
    "use strict";

    var GOLD = "#E5C76B";
    var CARBON = "#0F0F16";

    /* Paths drawn for a 24x24 grid. Strokes only, no fills. */
    var P = {
        car: '<path d="M3.5 16.5v-3a1.2 1.2 0 0 1 .9-1.15L6.2 11l1.5-3.1A1.8 1.8 0 0 1 9.3 7h5.4a1.8 1.8 0 0 1 1.6.9L17.8 11l1.8.35a1.2 1.2 0 0 1 .9 1.15v4"/><path d="M5.5 16.5h-2M20.5 16.5h-2M9.2 16.5h5.6"/><circle cx="7.5" cy="16.5" r="1.9"/><circle cx="16.5" cy="16.5" r="1.9"/><path d="M6.4 11h11.2"/>',
        bajaj: '<path d="M4 17V9.6a1 1 0 0 1 .3-.7L7 6h7.2a1 1 0 0 1 .9.55L16.7 10H19a1.5 1.5 0 0 1 1.5 1.5V17"/><path d="M4 17h1.6M9.4 17h5.2M18.4 17h2.1"/><circle cx="7.5" cy="17" r="1.9"/><circle cx="16.5" cy="17" r="1.9"/><path d="M4 10h12.7M10 6v4"/>',
        moto: '<circle cx="6" cy="16.5" r="3"/><circle cx="18" cy="16.5" r="3"/><path d="M6 16.5l3.2-6.2h3.6l3.7 6.2"/><path d="M9.2 10.3L8.2 8H6M12.8 10.3l1.3-2.8H17M10.4 13.2h4"/>',
        minibus: '<rect x="3" y="6.5" width="18" height="10" rx="2"/><path d="M3 12h18M9 6.5V12M15 6.5V12"/><circle cx="7.5" cy="17.5" r="1.7"/><circle cx="16.5" cy="17.5" r="1.7"/>',
        hail: '<path d="M7.5 9l1.1-3h6.8l1.1 3"/><path d="M12 6V4.5M10.2 4.5h3.6"/><rect x="3.5" y="9" width="17" height="7.5" rx="1.8"/><path d="M7 16.5v2M17 16.5v2M3.5 13h17"/>',
        pin: '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        history: '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5M12 8v4.2l2.8 1.8"/>',
        phone: '<path d="M6.8 4h2.6l1.3 3.6-1.7 1.2a10 10 0 0 0 5.2 5.2l1.2-1.7 3.6 1.3v2.6a2 2 0 0 1-2.1 2A14.5 14.5 0 0 1 4.8 6.1 2 2 0 0 1 6.8 4z"/>',
        chat: '<path d="M5 6.5h14a1.5 1.5 0 0 1 1.5 1.5v7a1.5 1.5 0 0 1-1.5 1.5h-7l-4 3v-3H5A1.5 1.5 0 0 1 3.5 15V8A1.5 1.5 0 0 1 5 6.5z"/><path d="M8 10.5h8M8 13h5"/>',
        alert: '<path d="M12 4.5l8.5 14.5h-17z"/><path d="M12 10.2v4M12 16.6v.1"/>',
        share: '<path d="M8 12.5l8-4M8 11.5l8 4"/><circle cx="6.5" cy="12" r="2"/><circle cx="17.5" cy="8" r="2"/><circle cx="17.5" cy="16" r="2"/>',
        route: '<circle cx="6" cy="18" r="2"/><circle cx="18" cy="6" r="2"/><path d="M8 18h6.5a3 3 0 0 0 0-6h-5a3 3 0 0 1 0-6H16"/>',
        flag: '<path d="M6 20V4.5M6 5.5h10.5l-2 3.5 2 3.5H6"/>',
        star: '<path d="M12 4l2.4 5 5.4.7-4 3.8 1 5.4L12 16.2 7.2 18.9l1-5.4-4-3.8 5.4-.7z"/>',
        shield: '<path d="M12 3.5l7 2.6v5.4c0 4.2-2.9 7.3-7 9-4.1-1.7-7-4.8-7-9V6.1z"/><path d="M12 8.5v4M12 15.2v.1"/>',
        close: '<path d="M6 6l12 12M18 6L6 18"/>',
        check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        sun: '<circle cx="12" cy="12" r="3.6"/><path d="M12 3.5v2M12 18.5v2M3.5 12h2M18.5 12h2M6 6l1.4 1.4M16.6 16.6L18 18M18 6l-1.4 1.4M7.4 16.6L6 18"/>',
        moon: '<path d="M19.5 14.2A7.5 7.5 0 0 1 9.8 4.5a7.5 7.5 0 1 0 9.7 9.7z"/>',
        wallet: '<path d="M4 8.5A2.5 2.5 0 0 1 6.5 6H18a1.5 1.5 0 0 1 1.5 1.5V9"/><rect x="3.5" y="8.5" width="17" height="10" rx="2"/><path d="M16.5 13.5h.1"/>',
        locate: '<circle cx="12" cy="12" r="3.5"/><path d="M12 3v3.5M12 17.5V21M3 12h3.5M17.5 12H21"/>',
        clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        doc: '<path d="M7 3.5h10a1 1 0 0 1 1 1V20l-2-1.3L14 20l-2-1.3L10 20l-2-1.3L6 20V4.5a1 1 0 0 1 1-1z"/><path d="M9 8.5h6M9 12h6"/>',
        camera: '<path d="M4.5 8.5h3l1.5-2h6l1.5 2h3a1 1 0 0 1 1 1V18a1 1 0 0 1-1 1h-15a1 1 0 0 1-1-1V9.5a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.2"/>',
        bolt: '<path d="M13 3.5L6.5 13.2H12l-1 7.3 6.5-9.7H12z"/>',
        bell: '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 1.5h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        building: '<rect x="5" y="4" width="14" height="16" rx="1.2"/><path d="M9 8.5h1.5M13.5 8.5H15M9 12h1.5M13.5 12H15M10.5 20v-3.5h3V20"/>',
        logout: '<path d="M10 4.5H6.5a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1H10"/><path d="M14.5 8l4 4-4 4M18.5 12H9.5"/>',
        fuel: '<path d="M6 20V5.5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1V20M5 20h10"/><path d="M8 8.5h4M14 10.5h1.7a1.3 1.3 0 0 1 1.3 1.3v4.2a1.2 1.2 0 0 0 2.4 0V9L17 6.5"/>',
        plus: '<path d="M12 5.5v13M5.5 12h13"/>',
        tool: '<path d="M19.5 7.5l-3 3-2.5-.5-.5-2.5 3-3a4 4 0 0 0-5 5.2l-7 7a1.7 1.7 0 0 0 2.4 2.4l7-7a4 4 0 0 0 5.2-4.9z"/>',
        refresh: '<path d="M19 12a7 7 0 1 1-2.1-5"/><path d="M19 4.5V8h-3.5"/>',
        gift: '<rect x="4" y="9" width="16" height="4" rx="1"/><path d="M5.5 13v7h13v-7M12 9v11M12 9c-1-3.5-5-4-5-1.5C7 9 9 9 12 9zM12 9c1-3.5 5-4 5-1.5C17 9 15 9 12 9z"/>',
        signal: '<path d="M5 12a10 10 0 0 1 14 0M8 15a6 6 0 0 1 8 0"/><circle cx="12" cy="18" r="1"/>',
        medal: '<circle cx="12" cy="9.5" r="4.5"/><path d="M9 13.5L7.5 20l4.5-2.2 4.5 2.2-1.5-6.5"/>',
        trophy: '<path d="M8 4.5h8v5a4 4 0 0 1-8 0z"/><path d="M8 6.5H5v1.5a3 3 0 0 0 3 3M16 6.5h3V8a3 3 0 0 1-3 3M12 13.5V17M8.5 19.5h7M10 17h4"/>',
        ban: '<circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/>',
        mail: '<rect x="3.5" y="6" width="17" height="12" rx="1.5"/><path d="M4 7l8 6 8-6"/>',
        home: '<path d="M4 11l8-6.5 8 6.5M6 9.5V19.5h12V9.5M10 19.5v-5h4v5"/>',
        lock: '<rect x="5.5" y="11" width="13" height="8.5" rx="1.5"/><path d="M8.5 11V8.5a3.5 3.5 0 0 1 7 0V11"/>',
        headset: '<path d="M5 14v-2a7 7 0 0 1 14 0v2"/><rect x="4" y="14" width="3.5" height="5" rx="1.2"/><rect x="16.5" y="14" width="3.5" height="5" rx="1.2"/>',
        chart: '<path d="M5 19.5V5M5 19.5h14"/><path d="M8.5 15l3.5-4 3 2.5 3.5-5"/>',
        map: '<path d="M4 6.5l5-2 6 2.5 5-2v12.5l-5 2-6-2.5-5 2z"/><path d="M9 4.5v12.5M15 7v12.5"/>',
        globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c3 3 3 14 0 17M12 3.5c-3 3-3 14 0 17"/>',
        heart: '<path d="M12 19.5s-7-4.3-7-9.5a4 4 0 0 1 7-2.5 4 4 0 0 1 7 2.5c0 5.2-7 9.5-7 9.5z"/>',
        id: '<rect x="3.5" y="6" width="17" height="12" rx="1.8"/><circle cx="9" cy="11" r="1.8"/><path d="M6.5 15.5a2.8 2.8 0 0 1 5 0M14 10.5h4M14 13.5h3"/>',
        users: '<circle cx="9" cy="9" r="3"/><circle cx="16.5" cy="10" r="2.4"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M14.5 15.2A4.5 4.5 0 0 1 20.5 19"/>',
        box: '<path d="M4 8l8-4 8 4v8.5l-8 4-8-4z"/><path d="M4 8l8 4 8-4M12 12v8.5"/>',
        menu: '<path d="M5 7h14M5 12h14M5 17h14"/>',
        user: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 19.5a7 7 0 0 1 14 0"/>'
    };

    var VEHICLE_ALIAS = { qute: "bajaj", tuk: "bajaj", rickshaw: "bajaj", bike: "moto", scooter: "moto", van: "minibus", bus: "minibus", sedan: "car" };

    function norm(type) {
        var t = String(type || "").toLowerCase();
        if (P[t] && (t === "car" || t === "bajaj" || t === "moto" || t === "minibus")) return t;
        for (var k in VEHICLE_ALIAS) { if (t.indexOf(k) !== -1) return VEHICLE_ALIAS[k]; }
        if (t.indexOf("moto") !== -1) return "moto";
        return "car";
    }

    function svg(inner, size, extra) {
        size = size || 20;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"' + (extra || "") + '>' + inner + '</svg>';
    }

    function icon(name, size) { return P[name] ? svg(P[name], size) : ""; }
    function vehicleGlyph(type, size) { return svg(P[norm(type)], size); }

    /* ---- Map styling: carbon + gold, restrained ------------------------- */
    var DARK = [
        { elementType: "geometry", stylers: [{ color: "#0f0f16" }] },
        { elementType: "labels.text.fill", stylers: [{ color: "#8f8878" }] },
        { elementType: "labels.text.stroke", stylers: [{ color: "#0f0f16" }] },
        { elementType: "labels.icon", stylers: [{ visibility: "off" }] },
        { featureType: "administrative", elementType: "geometry.stroke", stylers: [{ color: "#2a2418" }] },
        { featureType: "administrative.land_parcel", stylers: [{ visibility: "off" }] },
        { featureType: "poi", stylers: [{ visibility: "off" }] },
        { featureType: "poi.park", elementType: "geometry", stylers: [{ visibility: "on" }, { color: "#111a15" }] },
        { featureType: "transit", stylers: [{ visibility: "off" }] },
        { featureType: "road", elementType: "geometry", stylers: [{ color: "#1d1d29" }] },
        { featureType: "road", elementType: "geometry.stroke", stylers: [{ color: "#0f0f16" }] },
        { featureType: "road", elementType: "labels.text.fill", stylers: [{ color: "#716b5e" }] },
        { featureType: "road.arterial", elementType: "geometry", stylers: [{ color: "#2a2635" }] },
        { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#4a3b17" }] },
        { featureType: "road.highway", elementType: "geometry.stroke", stylers: [{ color: "#2a2210" }] },
        { featureType: "road.highway", elementType: "labels.text.fill", stylers: [{ color: "#c9ab55" }] },
        { featureType: "water", elementType: "geometry", stylers: [{ color: "#0a1119" }] },
        { featureType: "water", elementType: "labels.text.fill", stylers: [{ color: "#3d4a5c" }] }
    ];
    var LIGHT = [
        { elementType: "geometry", stylers: [{ color: "#f2efe8" }] },
        { elementType: "labels.icon", stylers: [{ visibility: "off" }] },
        { elementType: "labels.text.fill", stylers: [{ color: "#6b6558" }] },
        { elementType: "labels.text.stroke", stylers: [{ color: "#f7f4ee" }] },
        { featureType: "administrative.land_parcel", stylers: [{ visibility: "off" }] },
        { featureType: "poi", stylers: [{ visibility: "off" }] },
        { featureType: "poi.park", elementType: "geometry", stylers: [{ visibility: "on" }, { color: "#dfe8d6" }] },
        { featureType: "transit", stylers: [{ visibility: "off" }] },
        { featureType: "road", elementType: "geometry", stylers: [{ color: "#ffffff" }] },
        { featureType: "road", elementType: "geometry.stroke", stylers: [{ color: "#e4ddcd" }] },
        { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#f1d88a" }] },
        { featureType: "road.highway", elementType: "geometry.stroke", stylers: [{ color: "#dcbf6a" }] },
        { featureType: "water", elementType: "geometry", stylers: [{ color: "#cfdfe9" }] }
    ];

    function mapStyles(isLight) { return isLight ? LIGHT : DARK; }

    /* ---- Vehicle marker ---------------------------------------------------
       A 44px carbon badge with a gold ring and the vehicle glyph in gold.
       opts.ring overrides the ring colour (e.g. the selected driver). */
    function vehicleMarker(type, opts) {
        opts = opts || {};
        var ring = opts.ring || GOLD;
        var size = opts.size || 44;
        var inner = P[norm(type)];
        var markup =
            '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 44 44">' +
            '<circle cx="22" cy="22" r="19" fill="' + CARBON + '" stroke="' + ring + '" stroke-width="2.5"/>' +
            '<g transform="translate(9 9) scale(1.1)" fill="none" stroke="' + GOLD + '" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' + inner + '</g>' +
            '</svg>';
        var url = "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(markup);
        var out = { url: url };
        if (window.google && google.maps && google.maps.Size) {
            out.scaledSize = new google.maps.Size(size, size);
            out.anchor = new google.maps.Point(size / 2, size / 2);
        }
        return out;
    }

    /* Replaces `<span data-ei="car" data-size="18"></span>` placeholders, so
       static HTML can use the icon family without inlining every path. */
    function hydrate(root) {
        var nodes = (root || document).querySelectorAll("[data-ei]");
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if (n.getAttribute("data-ei-done")) continue;
            var name = n.getAttribute("data-ei");
            var size = parseInt(n.getAttribute("data-size") || "20", 10);
            n.innerHTML = (name.indexOf("vehicle:") === 0) ? vehicleGlyph(name.slice(8), size) : icon(name, size);
            n.setAttribute("data-ei-done", "1");
        }
    }


    /* ---- Emoji -> icon upgrade -------------------------------------------
       The apps render ~350 emoji as icons, many of them inside HTML built by
       JavaScript at runtime. Instead of editing every string, walk the text
       nodes and swap each known emoji for the matching drawn icon, and keep
       watching for new nodes. Unknown emoji are left alone. Nothing in the
       app reads these glyphs back out of the DOM. */
    var EMOJI = {
        "📍": "pin", "🏁": "flag", "🚗": "car", "🚙": "car",
        "🚖": "hail", "🛺": "bajaj", "🛵": "moto", "🚐": "minibus", "🚌": "minibus",
        "⭐": "star-fill", "★": "star-fill", "☆": "star",
        "✓": "check", "✅": "check", "✔": "check", "✕": "close", "✖": "close", "❌": "close",
        "⚠": "alert", "💬": "chat", "🗺": "map", "🧭": "route", "🛣": "route",
        "⏱": "clock", "🕘": "clock", "🕓": "clock", "⏰": "clock",
        "💰": "wallet", "💵": "wallet", "💳": "wallet", "🏦": "wallet",
        "🧑": "user", "👤": "user", "👥": "users", "🚶": "user",
        "📋": "history", "🧾": "doc", "📄": "doc", "📰": "doc", "📝": "doc",
        "📷": "camera", "📸": "camera", "⚡": "bolt", "🚀": "bolt",
        "🛡": "shield", "🛟": "shield", "🔔": "bell", "🏢": "building",
        "🚪": "logout", "⛽": "fuel", "➕": "plus", "🔧": "tool", "⚙": "tool",
        "🔄": "refresh", "📡": "signal", "🏅": "medal", "🏆": "trophy",
        "🚫": "ban", "📵": "ban", "🎁": "gift", "✉": "mail", "🎯": "locate",
        "📟": "phone", "📲": "phone", "📱": "phone", "📦": "box",
        "🏠": "home", "🔒": "lock", "🔐": "lock", "🎧": "headset",
        "☰": "menu", "📈": "chart", "📊": "chart", "🌐": "globe",
        "🪪": "id", "❤": "heart"
    };
    var EMOJI_RE = new RegExp("(" + Object.keys(EMOJI).join("|") + ")\\uFE0F?", "g");
    var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, TITLE: 1, SVG: 1, svg: 1, NOSCRIPT: 1 };

    function emojiIconEl(ch) {
        var name = EMOJI[ch];
        var span = document.createElement("span");
        span.className = "ei-i";
        span.setAttribute("aria-hidden", "true");
        span.setAttribute("data-emoji", ch);
        span.style.cssText = "display:inline-flex;align-items:center;vertical-align:-.2em;line-height:0;flex:none" +
            (name === "star-fill" ? ";color:" + GOLD : "");
        var inner = name === "star-fill" ? P.star : (P[name] || P.pin);
        span.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="1.15em" height="1.15em" viewBox="0 0 24 24" ' +
            'fill="' + (name === "star-fill" ? "currentColor" : "none") + '" stroke="currentColor" stroke-width="1.75" ' +
            'stroke-linecap="round" stroke-linejoin="round" focusable="false">' + inner + '</svg>';
        return span;
    }

    function skipNode(node) {
        for (var p = node.parentNode; p && p.nodeType === 1; p = p.parentNode) {
            if (SKIP_TAGS[p.tagName] || p.isContentEditable || (p.classList && (p.classList.contains("ei-i") || p.classList.contains("gm-style")))) return true;
            if (p.getAttribute && p.getAttribute("data-no-emoji-icons") !== null) return true;
        }
        return false;
    }

    function upgradeTextNode(node) {
        var text = node.nodeValue;
        if (!text || text.length > 2000) return;
        EMOJI_RE.lastIndex = 0;
        if (!EMOJI_RE.test(text)) return;
        if (skipNode(node)) return;
        EMOJI_RE.lastIndex = 0;
        var frag = document.createDocumentFragment(), last = 0, m;
        while ((m = EMOJI_RE.exec(text))) {
            if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
            frag.appendChild(emojiIconEl(m[1]));
            last = m.index + m[0].length;
        }
        if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
        node.parentNode.replaceChild(frag, node);
    }

    function upgradeEmoji(root) {
        root = root || document.body;
        if (!root) return;
        if (root.nodeType === 3) { upgradeTextNode(root); return; }
        if (root.nodeType !== 1 || SKIP_TAGS[root.tagName]) return;
        var walker = document.createTreeWalker(root, 4, null), nodes = [], n;
        while ((n = walker.nextNode())) nodes.push(n);
        for (var i = 0; i < nodes.length; i++) upgradeTextNode(nodes[i]);
    }

    var pending = [], scheduled = false;
    function flush() {
        scheduled = false;
        var list = pending; pending = [];
        for (var i = 0; i < list.length; i++) { if (list[i].isConnected) upgradeEmoji(list[i]); }
    }
    function startEmojiObserver() {
        if (!window.MutationObserver || !document.body || startEmojiObserver.on) return;
        startEmojiObserver.on = true;
        upgradeEmoji(document.body);
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                if (m.type === "characterData") pending.push(m.target);
                else for (var j = 0; j < m.addedNodes.length; j++) pending.push(m.addedNodes[j]);
            }
            if (pending.length && !scheduled) {
                scheduled = true;
                (window.requestAnimationFrame || setTimeout)(flush);
            }
        }).observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    window.EtelUI = {
        icon: icon,
        vehicleGlyph: vehicleGlyph,
        vehicleType: norm,
        mapStyles: mapStyles,
        vehicleMarker: vehicleMarker,
        hydrate: hydrate,
        upgradeEmoji: upgradeEmoji,
        GOLD: GOLD
    };

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { hydrate(); startEmojiObserver(); });
    else { hydrate(); startEmojiObserver(); }
    // Pages load this bundle asynchronously (release-loader.js); anything
    // injected before it finished gets its icons filled in here.
    window.addEventListener("etel:bundle-ready", function () { hydrate(); });
})();
