/* Driver app: vehicle pictures for every type in the vehicle pickers (additive script, no bundle release).
   The "Update Vehicle Info" panel and the sign-up wizard draw each type from REG.vtypeImages. That table was written for the original
   four types (Bajaj, Motorcycle, Car, Minibus), so a Qute or an EV Bajaj showed only a small drawn glyph next to real pictures.
   This adds the Qute and EV pictures from img/vehicles (the same files the map markers use) and their proper names. A type whose
   picture is missing on the server simply keeps the glyph, as before. */
(function () {
    'use strict';
    var BASE = '../img/vehicles/';
    var V = '2';
    var PIC = { qute: 'qute.png', ev_car: 'ev_car.png' };
    var TITLE = { qute: 'Qute', ev_car: 'EV Bajaj' };
    var tries = 0;
    function patch() {
        var R = window.REG;
        if (!R) return false;
        R.vtypeImages = R.vtypeImages || {};
        R.vtypeTitles = R.vtypeTitles || {};
        Object.keys(PIC).forEach(function (slug) {
            var url = BASE + PIC[slug] + '?v=' + V;
            R.vtypeImages[slug] = url;
            R.vtypeImages[slug.charAt(0).toUpperCase() + slug.slice(1)] = url;   // 'Qute'
            if (!R.vtypeTitles[slug]) R.vtypeTitles[slug] = TITLE[slug];
        });
        return true;
    }
    (function wait() {
        if (!patch() && ++tries < 200) setTimeout(wait, 300);
    })();
})();
