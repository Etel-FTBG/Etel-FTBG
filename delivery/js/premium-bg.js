/* ETEL Premium – delivery-themed background elements injector */
(function () {
    'use strict';

    /* Truck SVG — gold delivery truck silhouette */
    var truckSvg = '<svg viewBox="0 0 200 80" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><rect x="10" y="28" width="110" height="38" rx="4"/><rect x="120" y="36" width="50" height="30" rx="3"/><polygon points="120,36 120,56 170,56 170,48 150,36"/><rect x="165" y="52" width="18" height="8" rx="2"/><rect x="5" y="58" width="12" height="8" rx="2"/><circle cx="35" cy="68" r="10" fill="none" stroke="currentColor" stroke-width="4"/><circle cx="35" cy="68" r="4"/><circle cx="155" cy="68" r="10" fill="none" stroke="currentColor" stroke-width="4"/><circle cx="155" cy="68" r="4"/></svg>';

    /* Layer 1 – large gold truck */
    var t1 = document.createElement('div');
    t1.className = 'etl-truck-layer';
    t1.innerHTML = truckSvg;
    t1.style.color = '#E5C76B';

    /* Layer 2 – smaller blue truck */
    var t2 = document.createElement('div');
    t2.className = 'etl-truck-layer-2';
    t2.innerHTML = truckSvg;
    t2.style.color = '#60B4FF';

    /* GPS pins */
    var pins = document.createElement('div');
    pins.innerHTML =
        '<div class="etl-pin etl-pin-1"></div>' +
        '<div class="etl-pin etl-pin-2"></div>' +
        '<div class="etl-pin etl-pin-3"></div>' +
        '<div class="etl-pin etl-pin-4"></div>';

    /* Speed streaks container */
    var streaks = document.createElement('div');
    streaks.className = 'etl-speedlines';
    for (var i = 0; i < 8; i++) {
        var s = document.createElement('div');
        s.className = 'etl-streak';
        streaks.appendChild(s);
    }

    function inject() {
        document.body.appendChild(t1);
        document.body.appendChild(t2);
        var pinsNodes = pins.children;
        while (pinsNodes.length) document.body.appendChild(pinsNodes[0]);
        document.body.appendChild(streaks);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inject);
    } else {
        inject();
    }
})();
