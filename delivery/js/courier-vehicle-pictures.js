/* Courier app: the courier's own vehicle as a real picture instead of an emoji (additive script, no bundle release).
   The profile's "Vehicle" row and the route card on the map showed a fixed motorcycle emoji whatever the courier rides. The profile fills
   #cp-vehicle with the vehicle type (motorcycle, bicycle, car, on_foot); this watches it and puts the matching real picture from img/vehicles/
   in both places (js/etel-vehicle-pic.js knows which picture belongs to which vehicle). A vehicle with no picture (bicycle and on foot until
   their photos are added) keeps its emoji. No inline handlers. */
(function () {
    'use strict';
    function swap(host, type, px) {
        if (!host || !window.EtelVehiclePic) return;
        var k = EtelVehiclePic.key(type);
        if (!k) return;
        host.innerHTML = EtelVehiclePic.has(k) ? EtelVehiclePic.html(k, px, { fallback: EtelVehiclePic.emoji(k) }) : EtelVehiclePic.emoji(k);
    }
    function targets() {
        var v = document.getElementById('cp-vehicle');
        var row = v && v.parentNode && v.parentNode.previousElementSibling;       // the small square icon left of "Vehicle"
        var route = document.getElementById('map-route-dist');
        var card = route && route.closest('div[style*="display:flex"]');
        var big = card ? Array.prototype.filter.call(card.children, function (c) { return c.style && c.style.fontSize === '32px'; })[0] : null;
        return { src: v, row: row, big: big };
    }
    function apply() {
        var t = targets(); if (!t.src) return false;
        var type = (t.src.textContent || '').trim();
        if (!type || type === '—' || /not set/i.test(type)) return true;
        if (t.row) { t.row.style.width = '46px'; swap(t.row, type, 40); }
        if (t.big) { t.big.style.cssText = 'width:72px;display:flex;align-items:center;justify-content:center'; swap(t.big, type, 64); }
        return true;
    }
    function init() {
        var t = targets();
        if (!t.src) { setTimeout(init, 600); return; }
        new MutationObserver(apply).observe(t.src, { childList: true, characterData: true, subtree: true });
        apply();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
