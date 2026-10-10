/* App shell cache so EtelERP opens on a weak connection. The API is never cached. */
var CACHE = 'etelerp-shell-v1';
var SHELL = ['./', 'index.html', 'assets/css/app.css', 'assets/js/core.js', 'assets/js/views_main.js', 'assets/js/views_sell.js',
  'assets/js/views_inv.js', 'assets/js/views_lists.js', 'assets/js/views_team.js', 'assets/icons/logo.png', 'manifest.webmanifest'];
self.addEventListener('install', function (e) { e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); })); });
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) { return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  var u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.indexOf('/api/') !== -1) return;   // network only
  e.respondWith(fetch(e.request).then(function (r) {
    var copy = r.clone(); caches.open(CACHE).then(function (c) { c.put(e.request, copy); }); return r;       // network first, fall back to cache
  }).catch(function () { return caches.match(e.request).then(function (m) { return m || caches.match('index.html'); }); }));
});
