/* ETEL Ride - readable pickup / drop-off addresses (additive script, no bundle release).

   Google often answers a map tap or a GPS fix with something nobody can read: a "plus code" such as "3F2F+PH8, Hawassa, Ethiopia", or an "unnamed
   road" (shown in English as "Unnamed Road", or as the Amharic words written in Latin letters, "Yaliteseyeme Menigedi, Awasa", or in Amharic script).
   Streets in Hawassa are often unnamed, so this is common. This wraps the app's setPickup / setDropoff: when the address it was given is one of those
   (or the bare "Your location" label), the point is reverse-geocoded again and the first result that is NOT one of those (landmark, street,
   neighbourhood, town) replaces it in the input box and in RIDE state, tidied: no trailing ", Ethiopia", and "Awasa" spelled "Hawassa".
   If every result is unreadable, or the lookup fails, the original text is left alone. Nothing else about booking changes. */
(function () {
    'use strict';
    if (window.__etelAddressFix) return;
    window.__etelAddressFix = true;

    var PLUS = /^\s*[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\b/i;
    function isPlus(s) { return PLUS.test(String(s || '')); }
    // "Unnamed Road", its Latin-letter Amharic ("Yaliteseyeme Menigedi", spelled a few ways) and the Amharic words themselves
    var UNNAMED = /unnamed\s+road|yal[a-z]*tese?y[a-z]*\s+men[a-z]*|\u12eb\u120d\u1270\u1230\u12e8\u1218/i;
    function isUnnamed(s) { return UNNAMED.test(String(s || '')); }
    function isYourLocation(s) { return /^\s*your location\s*$/i.test(String(s || '')); }
    function isBad(s) { return isPlus(s) || isUnnamed(s) || isYourLocation(s); }
    // ", Ethiopia" at the end adds nothing here; Hawassa is also written Awasa / Awassa / Awas by the map data
    // Only the town part (the last piece of the address) is respelled; a street or business name that contains "Awasa" is left as it is.
    function tidy(s) {
        var parts = String(s || '').replace(/,?\s*Ethiopia\s*$/i, '').split(',');
        var last = parts.length - 1;
        if (last >= 0 && /^\s*(Awa?ss?a|Awas)\s*$/i.test(parts[last])) parts[last] = ' Hawassa';
        return parts.join(',').replace(/\s+,/g, ',').replace(/^\s+|\s+$/g, '');
    }

    var geocoder = null;
    function gc() {
        if (!geocoder && window.google && google.maps && google.maps.Geocoder) geocoder = new google.maps.Geocoder();
        return geocoder;
    }

    function pick(results) {
        for (var i = 0; i < (results || []).length; i++) {
            var r = results[i], t = r.types || [];
            if (t.indexOf('plus_code') !== -1 || isBad(r.formatted_address)) continue;
            return tidy(r.formatted_address);
        }
        return '';
    }

    function improve(kind, lat, lng, addr) {
        var g = gc();
        if (!g || !isBad(addr)) return;
        g.geocode({ location: { lat: lat, lng: lng } }, function (res, st) {
            if (st !== 'OK') return;
            var better = pick(res);
            if (!better) return;
            var inp = document.getElementById(kind + '-input');
            var R = window.RIDE;
            // only if the rider has not moved the point or typed something else in the meantime
            var cur = R && R[kind + 'LatLng'];
            if (!cur || Math.abs(cur.lat - lat) > 1e-6 || Math.abs(cur.lng - lng) > 1e-6) return;
            if (inp && isBad(inp.value)) inp.value = better;                  // only if the box still shows the unreadable text
            if (R) R[kind + 'Address'] = better;
        });
    }

    function wrap(name, kind) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__etelWrapped) return false;
        var fn = function (lat, lng, addr) {
            var out = orig.apply(this, arguments);
            try { improve(kind, Number(lat), Number(lng), addr); } catch (e) {}
            return out;
        };
        fn.__etelWrapped = true;
        window[name] = fn;
        return true;
    }

    // ride.js defines them when it runs; this script is deferred, but retry briefly in case of ordering
    var tries = 0;
    (function boot() {
        var a = wrap('setPickup', 'pickup'), b = wrap('setDropoff', 'dropoff');
        if ((!a || !b) && ++tries < 40) setTimeout(boot, 250);
    })();
})();
