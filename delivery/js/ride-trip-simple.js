/* ETEL Ride - the rider sees only the distance and the total during the trip (additive script, no bundle release).

   While the trip is running the server sends the full itemized fare (base, distance, waiting, surge) and the page drew it as a table. The rider should
   not have to read a bill while riding, only the distance so far and the total so far. The itemized bill stays where it belongs: on the Trip Complete card
   and in the ride history. The driver's screen keeps the details (see driver-trip-breakdown.js). */
(function () {
    'use strict';
    if (window.__etelRideTripSimple) return;
    window.__etelRideTripSimple = true;

    var mine = false;
    function n(v) { v = parseFloat(v); return isFinite(v) ? v : 0; }
    function lang() { try { return localStorage.getItem('etel_lang') === 'am' ? 'am' : 'en'; } catch (e) { return 'en'; } }
    function T(a, b) { return lang() === 'am' ? b : a; }
    function simplify() {
        var box = document.getElementById('a-fare-breakdown');
        if (!box || mine || box.style.display === 'none') return;
        var ours = box.firstElementChild && box.firstElementChild.getAttribute('data-etel-simple') === sigNow();   // already showing the simple card for these numbers
        if (ours) return;
        var r = window.RIDE && window.RIDE.activeRideData;
        if (!r || r.fare_total == null) return;
        mine = true;
        try {
            var km = n(r.traveled_km != null ? r.traveled_km : r.distance_km).toFixed(2);
            box.innerHTML = '<div data-etel-simple="' + sigNow() + '" style="display:flex;gap:8px;margin:8px 0 4px;background:rgba(255,255,255,.02);border:1px solid rgba(212,175,55,.12);border-radius:12px;padding:12px 14px;align-items:center">' +
                '<div style="flex:1"><div style="font-size:11px;color:var(--dim)">' + T('Distance', 'ርቀት') + '</div><div style="font-size:17px;font-weight:800">' + km + ' km</div></div>' +
                '<div style="text-align:right"><div style="font-size:11px;color:var(--dim)">' + T('Total so far', 'እስካሁን ጠቅላላ') + '</div><div style="font-size:17px;font-weight:800;color:var(--gold-lt)">ETB ' + n(r.fare_total).toFixed(0) + '</div></div></div>';
        } finally { mine = false; }
    }
    function sigNow() { var r = window.RIDE && window.RIDE.activeRideData; return r ? [r.traveled_km, r.distance_km, r.fare_total, lang()].join('|') : ''; }
    function boot() {
        var box = document.getElementById('a-fare-breakdown');
        if (!box) return;
        new MutationObserver(function () { simplify(); }).observe(box, { childList: true, attributes: true, attributeFilter: ['style'] });
        simplify();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
