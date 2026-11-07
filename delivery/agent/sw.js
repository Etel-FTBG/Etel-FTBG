/* ETEL Agent portal service worker. Scope: /delivery/agent/ only (it lives in this folder, so it never touches the Ride or Driver apps).
   Keeps the app shell, fonts and language files on the phone so the code and QR open without internet.
   API calls (api/boot.php) are NEVER cached: money and status always come from the server.
   Bump CACHE whenever any file in SHELL changes. */
var CACHE = 'etel-agent-v9';
var SHELL = ['./', 'index.html', 'app.css?v=6', 'app.js?v=9', 'qr.js?v=1', 'lang/am.json?v=9', 'lang/en.json?v=9', 'manifest.webmanifest', 'logo-128.webp', 'book.js?v=3', 'logo-320.webp',
  '../icons/agent-icon-96.png', '../icons/agent-icon-192.png', '../icons/agent-icon-512.png', '../icons/agent-apple-touch-icon.png',
  '../fonts/noto-sans-ethiopic-400.woff2', '../fonts/noto-sans-ethiopic-700.woff2', '../fonts/cormorant-garamond-normal-latin.woff2'];
self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return Promise.all(SHELL.map(function (u) { return c.add(new Request(u, { cache: 'reload' })).catch(function () { /* one missing file must not block install */ }); })); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) { return Promise.all(ks.filter(function (k) { return k.indexOf('etel-agent-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  var r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.indexOf('/api/') !== -1) return;     // network only
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).then(function (res) { var cp = res.clone(); caches.open(CACHE).then(function (c) { c.put('index.html', cp); }); return res; }).catch(function () { return caches.match('index.html'); }));
    return;
  }
  e.respondWith(caches.match(r).then(function (hit) {
    var net = fetch(r).then(function (res) { if (res && res.ok) { var cp = res.clone(); caches.open(CACHE).then(function (c) { c.put(r, cp); }); } return res; }).catch(function () { return hit; });
    return hit || net;
  }));
});
