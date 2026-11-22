/* ETEL Ride - readable pickup / drop-off addresses in the style of Google Maps (additive script, no bundle release).

   Google answers a map tap or a GPS fix with something nobody can read as a place: a "plus code" such as "3F2F+PH8, Hawassa, Ethiopia", an "unnamed road"
   (in English "Unnamed Road", as the Amharic words in Latin letters "Yaliteseyeme Menigedi, Awasa", or in Amharic script), or the app's own
   "Your location" label. Streets in Hawassa are often unnamed, so this is common. A bare town name ("Hawassa") is no better: every point would look the same.

   This wraps the app's setPickup / setDropoff. For such an address the box shows, at once, a plus code with the town, exactly as Google Maps writes it:
       2GW6+8HH Hawassa
   (the code is worked out on the phone from the point, so it also works when the map lookup fails). Then the point is reverse-geocoded, and when Google
   knows a place or a named street there, the box becomes:
       Mary Joy Elder Center, 2GV6+5FG, Hawassa
   The town part is tidied ("Awasa", "Awassa", "Awas" are written "Hawassa", no ", Ethiopia"); a street or business name that contains "Awasa" is left as it is.
   Anything else the rider picked or typed (a real address, a suggestion from the search box) is never touched. If the rider typed something else or moved the
   point meanwhile, the answer is dropped. Nothing else about booking changes. */
(function () {
    'use strict';
    if (window.__etelAddressFix) return;
    window.__etelAddressFix = true;

    /* ---- Open Location Code (plus code), 11 digits: 8 + "+" + 3, like "6GVW2GW6+8HH". Google writes it without the first 4 digits next to a town. ---- */
    var ALPHA = '23456789CFGHJMPQRVWX';
    function olc(lat, lng) {
        lat = Math.min(90, Math.max(-90, lat));
        lng = ((lng + 180) % 360 + 360) % 360 - 180;
        var latVal = Math.floor(Math.round((lat + 90) * 40000 * 1e6) / 1e6);
        var lngVal = Math.floor(Math.round((lng + 180) * 32000 * 1e6) / 1e6);
        if (latVal >= 180 * 40000) latVal = 180 * 40000 - 1;
        var code = '';
        code = ALPHA.charAt((latVal % 5) * 4 + (lngVal % 4)) + code;           // the 11th digit: a 5 x 4 grid
        latVal = Math.floor(latVal / 5); lngVal = Math.floor(lngVal / 4);
        for (var i = 0; i < 5; i++) {                                           // five letter pairs: latitude then longitude
            code = ALPHA.charAt(lngVal % 20) + code;
            code = ALPHA.charAt(latVal % 20) + code;
            latVal = Math.floor(latVal / 20); lngVal = Math.floor(lngVal / 20);
        }
        return code.slice(0, 8) + '+' + code.slice(8);
    }
    window.__etelPlusCode = olc;   // for the tests
    // In and around Hawassa the first 4 digits are the same for everyone, so Google (and we) drop them: "2GW6+8HH Hawassa". Elsewhere the full code is kept.
    function shortCode(lat, lng) {
        var c = olc(lat, lng);
        return (Math.abs(lat - 7.06) < 0.4 && Math.abs(lng - 38.48) < 0.4) ? c.slice(4) : c;
    }

    var CODE = '[23456789CFGHJMPQRVWX]';
    var PLUS = new RegExp('^\\s*' + CODE + '{4,8}\\+' + CODE + '{2,3}\\b', 'i');
    var PLUS_TOKEN = new RegExp('^\\s*(' + CODE + '{2,8}\\+' + CODE + '{2,3})', 'i');
    function isPlus(s) { return PLUS.test(String(s || '')); }
    // "Unnamed Road", its Latin-letter Amharic ("Yaliteseyeme Menigedi", spelled a few ways) and the Amharic words themselves
    var UNNAMED = /unnamed\s+road|yal[a-z]*tese?y[a-z]*\s+men[a-z]*|ያልተሰየመ/i;
    function isUnnamed(s) { return UNNAMED.test(String(s || '')); }
    function isYourLocation(s) { return /^\s*your location\s*$/i.test(String(s || '')); }
    function isBad(s) { return isPlus(s) || isUnnamed(s) || isYourLocation(s); }

    // ", Ethiopia" at the end adds nothing here; Hawassa is also written Awasa / Awassa / Awas by the map data (only the town part is respelled)
    function townOf(seg) {
        var t = String(seg || '').replace(/^\s+|\s+$/g, '');
        return /^(Awa?ss?a|Awas)$/i.test(t) ? 'Hawassa' : t;
    }
    // "2GW6+8HH, Hawassa, Ethiopia" or "3F2F+PH8 Awasa" -> "2GW6+8HH Hawassa" (Google writes the code and the town with a space)
    function tidyPlus(addr) {
        var s = String(addr || '').replace(/,?\s*Ethiopia\s*$/i, '');
        var m = PLUS_TOKEN.exec(s);
        if (!m) return s.replace(/^\s+|\s+$/g, '');
        var rest = s.slice(m[0].length).replace(/^[\s,]+/, '').split(',')[0];
        return m[1].toUpperCase() + (rest ? ' ' + townOf(rest) : ' Hawassa');
    }

    var geocoder = null;
    function gc() {
        if (!geocoder && window.google && google.maps && google.maps.Geocoder) geocoder = new google.maps.Geocoder();
        return geocoder;
    }

    // what the map knows about the point, as { plus, town, name }
    var NAME_TYPES = ['establishment', 'point_of_interest', 'premise', 'subpremise', 'street_address', 'route'];
    function has(types, list) { for (var i = 0; i < list.length; i++) if ((types || []).indexOf(list[i]) !== -1) return true; return false; }
    function firstSeg(r) {
        var c = r.address_components && r.address_components[0] && r.address_components[0].long_name;
        return String(c || String(r.formatted_address || '').split(',')[0]).replace(/^\s+|\s+$/g, '');
    }
    function facts(results) {
        var out = { plus: '', town: '', name: '' };
        for (var i = 0; i < (results || []).length; i++) {
            var r = results[i], t = r.types || [];
            if (!out.plus && (t.indexOf('plus_code') !== -1 || isPlus(r.formatted_address))) {
                var m = PLUS_TOKEN.exec(String(r.formatted_address || '')); if (m) out.plus = m[1].toUpperCase();
            }
            if (!out.town && t.indexOf('locality') !== -1) out.town = townOf(firstSeg(r));
            if (!out.name && has(t, NAME_TYPES) && !isBad(r.formatted_address) && !isBad(firstSeg(r))) out.name = firstSeg(r);
        }
        return out;
    }
    function compose(lat, lng, results) {
        var f = facts(results);
        var plus = f.plus || shortCode(lat, lng), town = f.town || 'Hawassa';
        if (f.name && f.name !== town && !isPlus(f.name)) return f.name + ', ' + plus + ', ' + town;
        return plus + ' ' + town;
    }

    function improve(kind, lat, lng, addr) {
        if (!isBad(addr) || !isFinite(lat) || !isFinite(lng)) return;
        var inp = document.getElementById(kind + '-input');
        var R = window.RIDE;
        var shown = isPlus(addr) ? tidyPlus(addr) : shortCode(lat, lng) + ' Hawassa';   // at once: no waiting for the map
        function stillThere() {                                                         // the rider has not moved the point or typed something else
            var cur = R && R[kind + 'LatLng'];
            return !!cur && Math.abs(cur.lat - lat) <= 1e-6 && Math.abs(cur.lng - lng) <= 1e-6 && (!inp || inp.value === shown);
        }
        if (inp && isBad(inp.value)) inp.value = shown;
        if (R) R[kind + 'Address'] = shown;
        var g = gc();
        if (!g) return;
        g.geocode({ location: { lat: lat, lng: lng } }, function (res, st) {
            if (st !== 'OK' || !res || !res.length) return;
            var better = compose(lat, lng, res);
            if (!better || better === shown || !stillThere()) return;
            if (inp) inp.value = better;
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
