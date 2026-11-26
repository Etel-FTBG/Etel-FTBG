/* ETEL - the address of a map point as a name plus a plus code, like the Ride system and the courier app:
       Piassa, 2FQM+RHF, Hawassa, Ethiopia      (a name Google or the customer gave, then the code)
       2FQM+RHF, Hawassa, Ethiopia              (no usable name: "Unnamed Road", "Your location", a bare code or bare coordinates)
   The code is worked out on the phone from the point, so it also works when the map lookup gives nothing. Shared helper, no bundle release:
       EtelPlusCode.code(lat, lng)            "6GVQ2FQM+RHF" style, 11 digits (8 + "+" + 3)
       EtelPlusCode.label(lat, lng)           "2FQM+RHF, Hawassa, Ethiopia" (the first 4 digits are dropped in and around Hawassa, like Google does)
       EtelPlusCode.nameOf(text)              the readable name in an address text, or '' when it has none
       EtelPlusCode.withCode(text, lat, lng)  the address to show and save; the text is returned as it is when there is no point or it already has a code
   Nothing here is asked of a server. */
(function () {
    'use strict';
    if (window.EtelPlusCode) return;
    var ALPHA = '23456789CFGHJMPQRVWX';
    function code(lat, lng) {
        lat = Math.min(90, Math.max(-90, lat));
        lng = ((lng + 180) % 360 + 360) % 360 - 180;
        var latVal = Math.floor(Math.round((lat + 90) * 40000 * 1e6) / 1e6);
        var lngVal = Math.floor(Math.round((lng + 180) * 32000 * 1e6) / 1e6);
        if (latVal >= 180 * 40000) latVal = 180 * 40000 - 1;
        var c = '';
        c = ALPHA.charAt((latVal % 5) * 4 + (lngVal % 4)) + c;           // the 11th digit: a 5 x 4 grid
        latVal = Math.floor(latVal / 5); lngVal = Math.floor(lngVal / 4);
        for (var i = 0; i < 5; i++) {                                    // five letter pairs: latitude then longitude
            c = ALPHA.charAt(lngVal % 20) + c;
            c = ALPHA.charAt(latVal % 20) + c;
            latVal = Math.floor(latVal / 20); lngVal = Math.floor(lngVal / 20);
        }
        return c.slice(0, 8) + '+' + c.slice(8);
    }
    function near(lat, lng) { return Math.abs(lat - 7.06) < 0.4 && Math.abs(lng - 38.48) < 0.4; }
    function label(lat, lng) {
        var c = code(lat, lng);
        return near(lat, lng) ? c.slice(4) + ', Hawassa, Ethiopia' : c + ', Ethiopia';
    }

    var CODE = '[23456789CFGHJMPQRVWX]';
    var HAS_CODE = new RegExp(CODE + '{4,8}\\+' + CODE + '{2,3}', 'i');
    var UNNAMED = /unnamed\s+road|yal[a-z]*tese?y[a-z]*\s+men[a-z]*|\u12eb\u120d\u1270\u1230\u12e8\u1218/i;   // "Unnamed Road", its Latin-letter Amharic spelling, and the Amharic words
    var YOUR_LOC = /^\s*your location\s*$/i;
    var COORDS = /^\s*-?\d+(?:\.\d+)?\s*,\s*-?\d+(?:\.\d+)?\s*$/;
    function hasCode(s) { return HAS_CODE.test(String(s || '')); }
    function isBad(s) { s = String(s || ''); return hasCode(s) || UNNAMED.test(s) || YOUR_LOC.test(s) || COORDS.test(s) || /^\s*$/.test(s); }
    function nameOf(text) {
        var s = String(text || '').replace(/^\s+|\s+$/g, '');
        if (isBad(s)) return '';
        s = s.replace(/,?\s*Ethiopia\s*$/i, '');
        // the town at the end adds nothing here (Hawassa is also written Awasa / Awassa / Awas by the map data)
        s = s.replace(/,?\s*(Hawassa|Awa?ss?a|Awas)\s*$/i, '');
        s = s.replace(/^[\s,]+|[\s,]+$/g, '');
        return s;
    }
    function withCode(text, lat, lng) {
        lat = Number(lat); lng = Number(lng);
        if (!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0)) return String(text == null ? '' : text);
        if (hasCode(text)) return String(text);
        var n = nameOf(text), l = label(lat, lng);
        return n ? n + ', ' + l : l;
    }
    // The same place written with or without the code compares equal: "Piassa" = "Piassa, 2FQM+RHF, Hawassa, Ethiopia". '' when nothing readable is left.
    var CODE_G = new RegExp(CODE + '{4,8}\\+' + CODE + '{2,3}', 'ig');
    function key(text) {
        var s = String(text || '').replace(CODE_G, ' ');
        s = s.replace(/,?\s*Ethiopia\b/ig, ' ').replace(/,?\s*(Hawassa|Awa?ss?a|Awas)\b/ig, ' ');
        return s.replace(/[\s,]+/g, ' ').replace(/^\s+|\s+$/g, '').toLowerCase();
    }
    function same(a, b) {
        var x = String(a || '').replace(/^\s+|\s+$/g, '').toLowerCase(), y = String(b || '').replace(/^\s+|\s+$/g, '').toLowerCase();
        if (!x || !y) return false;
        if (x === y) return true;
        var kx = key(x), ky = key(y);
        return kx !== '' && kx === ky;
    }
    window.EtelPlusCode = { code: code, label: label, nameOf: nameOf, withCode: withCode, hasCode: hasCode, key: key, same: same };
})();
