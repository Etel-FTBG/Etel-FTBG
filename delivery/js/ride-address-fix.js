/* ETEL Ride - readable pickup / drop-off addresses (additive script, no bundle release).

   Google often answers a map tap or a GPS fix with a "plus code" such as "3F2F+PH8, Hawassa, Ethiopia" as the
   top result. Riders and drivers cannot read that. This wraps the app's setPickup / setDropoff: when the address
   it was given is a plus code, the point is reverse-geocoded again and the first result that is NOT a plus code
   (street, neighbourhood, landmark) replaces it in the input box and in RIDE state. If every result is a plus
   code, or the lookup fails, the original text is left alone. Nothing else about booking changes. */
(function () {
    'use strict';
    if (window.__etelAddressFix) return;
    window.__etelAddressFix = true;

    var PLUS = /^\s*[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\b/i;
    function isPlus(s) { return PLUS.test(String(s || '')); }

    var geocoder = null;
    function gc() {
        if (!geocoder && window.google && google.maps && google.maps.Geocoder) geocoder = new google.maps.Geocoder();
        return geocoder;
    }

    function pick(results) {
        for (var i = 0; i < (results || []).length; i++) {
            var r = results[i], t = r.types || [];
            if (t.indexOf('plus_code') !== -1 || isPlus(r.formatted_address)) continue;
            return r.formatted_address;
        }
        return '';
    }

    function improve(kind, lat, lng, addr) {
        var g = gc();
        if (!g || !isPlus(addr)) return;
        g.geocode({ location: { lat: lat, lng: lng } }, function (res, st) {
            if (st !== 'OK') return;
            var better = pick(res);
            if (!better) return;
            var inp = document.getElementById(kind + '-input');
            var R = window.RIDE;
            // only if the rider has not moved the point or typed something else in the meantime
            var cur = R && R[kind + 'LatLng'];
            if (!cur || Math.abs(cur.lat - lat) > 1e-6 || Math.abs(cur.lng - lng) > 1e-6) return;
            if (inp && isPlus(inp.value)) inp.value = better;
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
