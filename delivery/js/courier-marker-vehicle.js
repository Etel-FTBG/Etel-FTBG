/* ETEL Courier - the courier's own vehicle as a real picture on the live map (release v13 add-on; no change to the shipped bundle code).
   The map marker for "you" used to be a dark circle with an emoji (motorcycle, bicycle, car or person on foot). The bundle builds that circle
   inside a function nobody outside can reach, so this does not replace the function: it watches the map's Advanced Markers (the public
   google.maps.marker element API) and, when one has that exact emoji circle as its content, sets the marker's content to a circle holding the
   real vehicle picture from img/vehicles/ (js/etel-vehicle-pic.js knows which picture belongs to which vehicle). A vehicle with no picture yet
   (bicycle, on foot, until their photos are added) is left exactly as it was, and so is every other marker (job pins, destinations). Runs
   again whenever the bundle swaps the content (it does that once the profile says what the courier rides). No inline handlers. */
(function () {
    'use strict';
    if (window.__etelCourierMarkerVehicle) return;
    window.__etelCourierMarkerVehicle = true;
    var EMOJI = { '🛵': 'motorcycle', '🚲': 'bicycle', '🚗': 'car', '🚶': 'on_foot' };

    function typeOfContent(el) {
        if (!el || el.nodeType !== 1 || el.getAttribute('data-vpic-marker')) return null;
        // the bundle's circle: 34px wide, 3px green border (the browser stores colours as rgb(...)); any other marker is not ours
        var st = el.style || {}, green = String(st.borderTopColor || '').replace(/\s+/g, '').toLowerCase();
        if (st.width !== '34px' || st.borderTopWidth !== '3px' || (green !== 'rgb(46,204,113)' && green !== '#2ecc71')) return null;
        return EMOJI[(el.textContent || '').trim()] || null;
    }
    function circle(type) {
        var V = window.EtelVehiclePic;
        var el = document.createElement('div');
        el.setAttribute('data-vpic-marker', type);
        el.style.cssText = 'width:58px;height:58px;border-radius:50%;background:#fff;border:3px solid #2ECC71;box-shadow:0 0 0 3px rgba(46,204,113,.35),0 4px 10px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;overflow:hidden';
        el.innerHTML = V.html(type, 46, { fallback: V.emoji(type) });
        return el;
    }
    function upgrade() {
        var V = window.EtelVehiclePic;
        if (!V) return;
        var markers = document.querySelectorAll('gmp-advanced-marker');
        for (var i = 0; i < markers.length; i++) {
            var m = markers[i], type = null;
            try { type = typeOfContent(m.content); } catch (e) { type = null; }
            if (type && V.has(type)) { try { m.content = circle(type); } catch (e) { /* leave the emoji circle */ } }
        }
    }
    var queued = false;
    function soon() { if (queued) return; queued = true; setTimeout(function () { queued = false; upgrade(); }, 150); }
    function start() {
        try { new MutationObserver(soon).observe(document.body, { childList: true, subtree: true }); } catch (e) { /* the timer below still runs */ }
        setInterval(upgrade, 2500);   // the bundle sets the marker's content in code, which changes no attribute the observer could see
        upgrade();
    }
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
})();
