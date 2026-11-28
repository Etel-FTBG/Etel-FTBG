/* ETEL - own iOS start screens for Courier, Service Giver, Admin and Agent (28 Nov 2026).
   core.js adds the Delivery / Ride start screens to every page; those would show the transport logo here.
   This file runs BEFORE core.js and puts the role's own start screens in first: core.js skips any size that already has a link.
   Pictures: icons/<role>-splash-<width>x<height>.jpg  (made by tools/brand/make_system_icons.py startscreens). */
(function () {
  'use strict';
  var p = location.pathname, role = /\/courier\//.test(p) ? 'courier' : /\/servicegiver/.test(p) ? 'service' : /\/admin/.test(p) ? 'admin' : /\/agent\//.test(p) ? 'agent' : '';
  if (!role || !document.head) return;
  var me = document.currentScript, src = me && me.src ? me.src : '';
  var base = src ? src.replace(/js\/[^\/?#]*(\?.*)?$/, '') : '/delivery/';
  [[640, 1136], [750, 1334], [1242, 2208], [1125, 2436], [828, 1792], [1242, 2688], [1170, 2532], [1284, 2778], [1179, 2556], [1290, 2796],
   [1536, 2048], [1668, 2388], [2048, 2732]].forEach(function (s) {
    var w = s[0], h = s[1], l = document.createElement('link');
    l.rel = 'apple-touch-startup-image';
    l.href = base + 'icons/' + role + '-splash-' + w + 'x' + h + '.jpg?v=1';
    l.media = '(device-width: ' + w + 'px) and (device-height: ' + h + 'px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)';
    document.head.appendChild(l);
  });
})();
