/* ETEL - real vehicle pictures for screens that used to show an emoji (shared helper, no bundle release).
   One place that knows which picture in img/vehicles/ belongs to which vehicle name, so the admin panels, the customer app and the courier
   app all show the same real vehicles:
     EtelVehiclePic.html(type, px, {fallback})   an <img> of the vehicle, about px wide (px x 0.75 tall); '' emoji fallback when there is no picture for it
     EtelVehiclePic.url(type)                    the picture's address, or '' when the name is not a vehicle
     EtelVehiclePic.has(type)                    true when a real picture for it is really there (bicycle / on_foot only once their files exist)
     EtelVehiclePic.emoji(type)                  the drawn fallback (also used when a picture fails to load)
   Pictures: bajaj, qute, moto, car, ev_car, minibus (shipped), and bicycle, on_foot (optional: drop bicycle.png / on_foot.png in img/vehicles/
   and they appear; until then those two keep their emoji). A picture that fails to load is swapped for its emoji, so there is never an empty
   box. No inline handlers; the one error listener runs in the capture phase because image errors do not bubble. */
(function () {
    'use strict';
    if (window.EtelVehiclePic) return;
    var src = (document.currentScript && document.currentScript.src) || '';
    var BASE = src ? src.split('?')[0].replace(/js\/[^\/]*$/, 'img/vehicles/') : '/delivery/img/vehicles/';
    var V = '1';
    var PIC = { bajaj: 'bajaj', qute: 'qute', moto: 'moto', motorcycle: 'moto', car: 'car', ev_car: 'ev_car', minibus: 'minibus', van: 'minibus', express_cargo: 'minibus', cargo: 'minibus', bicycle: 'bicycle', on_foot: 'on_foot' };
    var EMO = { bajaj: '🛺', qute: '🛺', moto: '🏍️', motorcycle: '🏍️', car: '🚗', ev_car: '🚗', minibus: '🚐', van: '🚐', express_cargo: '🚚', cargo: '🚚', bicycle: '🚲', on_foot: '🚶' };
    function key(t) {
        t = String(t == null ? '' : t).toLowerCase().replace(/[\s-]+/g, '_');
        if (PIC[t]) return t;
        if (t.indexOf('qute') !== -1) return 'qute';
        if (t.indexOf('moto') !== -1 || t.indexOf('scoot') !== -1) return 'moto';
        if (t.indexOf('bicycle') !== -1 || t === 'bike') return 'bicycle';
        if (t.indexOf('foot') !== -1 || t.indexOf('walk') !== -1) return 'on_foot';
        if (t.indexOf('mini') !== -1 || t.indexOf('bus') !== -1 || t.indexOf('van') !== -1) return 'minibus';
        if (t.indexOf('bajaj') !== -1 || t.indexOf('tuk') !== -1) return 'bajaj';
        if (t.indexOf('car') !== -1 || t.indexOf('sedan') !== -1) return 'car';
        return '';
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    var SHIPPED = { bajaj: 1, qute: 1, moto: 1, car: 1, ev_car: 1, minibus: 1 };
    var found = {};   // optional pictures (bicycle, on_foot) that turned out to exist
    ['bicycle', 'on_foot'].forEach(function (k) { var im = new Image(); im.onload = function () { found[k] = true; }; im.src = BASE + k + '.png?v=' + V; });
    /* true when a real picture for this vehicle is there (a map marker must not point at a missing file) */
    function has(type) { var k = key(type); return !!k && (!!SHIPPED[PIC[k]] || !!found[PIC[k]]); }
    function url(type) { var k = key(type); return k ? BASE + PIC[k] + '.png?v=' + V : ''; }
    function emoji(type) { var k = key(type); return k ? EMO[k] : ''; }
    function html(type, px, opts) {
        var k = key(type), fb = (opts && opts.fallback) || (k ? EMO[k] : '');
        if (!k) return esc(fb || '');
        var w = px || 48, h = Math.round(w * 0.75);
        return '<img class="etel-vpic" src="' + esc(url(type)) + '" alt="" width="' + w + '" height="' + h + '" data-vfb="' + esc(fb) + '" loading="lazy" decoding="async" draggable="false" style="object-fit:contain;max-width:100%;height:auto;vertical-align:middle">';
    }
    document.addEventListener('error', function (e) {
        var t = e.target;
        if (!t || t.tagName !== 'IMG' || !t.classList || !t.classList.contains('etel-vpic')) return;
        var s = document.createElement('span');
        s.className = 'etel-vpic-fb'; s.setAttribute('aria-hidden', 'true');
        s.style.cssText = 'font-size:' + Math.max(16, Math.round((parseInt(t.getAttribute('width'), 10) || 48) * 0.6)) + 'px;line-height:1';
        s.textContent = t.getAttribute('data-vfb') || '';
        if (t.parentNode) t.parentNode.replaceChild(s, t);
    }, true);
    window.EtelVehiclePic = { html: html, url: url, emoji: emoji, key: key, has: has };
})();
