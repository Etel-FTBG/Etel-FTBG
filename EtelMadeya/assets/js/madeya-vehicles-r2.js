/* Etel Madeya - real vehicle pictures instead of emoji icons.
   vehPic(icon, name, h) -> HTML. Picks a photo from assets/images/vehicles/ by vehicle name (bajaj, taxi, private,
   minibus, truck, motorcycle ...). If a type's "icon" field holds an image file name or URL (e.g. truck.png or /x/y.webp)
   that picture is used instead. No picture found -> falls back to the emoji icon, so nothing breaks. */
(function () {
  'use strict';
  var BASE = (function () {
    var s = document.currentScript && document.currentScript.src || '';
    return s ? s.replace(/assets\/js\/madeya-vehicles\.js.*$/, 'assets/images/vehicles/') : 'assets/images/vehicles/';
  })();
  var FILES = { bajaj: 1, qute: 1, ev_car: 1, car: 1, minibus: 1, moto: 1, truck: 1 };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function key(name) {
    var s = String(name || '').toLowerCase();
    if (/qute/.test(s)) return 'qute';
    if (/\bev\b|electric/.test(s)) return 'ev_car';
    if (/bajaj|tuk|three|3[- ]?wheel/.test(s)) return 'bajaj';
    if (/moto|bike|scooter/.test(s)) return 'moto';
    if (/mini ?bus|van|bus|hiace/.test(s)) return 'minibus';
    if (/truck|lorry|isuzu|cargo|load/.test(s)) return 'truck';
    if (/taxi|car|private|vehicle|sedan|suv|automobile/.test(s)) return 'car';
    return '';
  }
  function isPath(v) { return /\.(png|webp|jpe?g|svg)(\?.*)?$/i.test(v || '') || /^(https?:)?\/\//.test(v || '') || /^\//.test(v || ''); }
  window.vehSrc = function (icon, name) {
    if (isPath(icon)) return /^(https?:)?\/\/|^\//.test(icon) ? icon : BASE + icon;
    var k = key(name) || key(icon);
    return k ? BASE + k + '.png' : '';
  };
  window.vehFb = function (img) {
    var t = document.createElement('span'); t.className = 'veh-fb'; t.textContent = img.getAttribute('data-fb') || '';
    if (img.parentNode) img.parentNode.replaceChild(t, img);
  };
  window.vehPic = function (icon, name, h) {
    var src = window.vehSrc(icon, name), fb = isPath(icon) ? '' : (icon || '');
    if (!src) return fb ? '<span class="veh-fb">' + esc(fb) + '</span>' : '';
    return '<img class="veh-pic" src="' + esc(src) + '" alt="' + esc(name || '') + '" loading="lazy"' + (h ? ' style="height:' + (+h) + 'px"' : '') + ' data-fb="' + esc(fb) + '" onerror="vehFb(this)">';
  };
})();
