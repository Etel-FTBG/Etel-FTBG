/**
 * ኢE Pay Service Worker v1.1
 * Cache strategy:
 *   Shell (HTML/CSS/JS) → Cache-first
 *   API calls           → Network-first, no cache
 *   Navigation          → Network-first → offline.html fallback
 */

const CACHE     = 'etelpay-v5';   // bumped so old cache clears on activate
const PRECACHE  = [
  './login',
  './wallet',
  './send',
  './history',
  './qr',
  './profile',
  './updater',
  './offline.html',
  './css/pay.css',
  './js/config.js',
  './js/api.js',
  './js/core.js',
  './js/devpanel.js',
];

// ── Install ──────────────────────────────────────────────────
self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(PRECACHE.map(u => new Request(u, { cache: 'reload' }))))
      .catch(() => {})
  );
});

// ── Activate ─────────────────────────────────────────────────
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// ── Fetch ────────────────────────────────────────────────────
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // API calls + reset.php → always network, never cache
  if (
    url.pathname.includes('wp-json') ||
    url.pathname.includes('api.php') ||
    url.pathname.includes('updater-api.php') ||
    url.pathname.endsWith('reset.php')
  ) {
    e.respondWith(
      fetch(e.request).catch(() =>
        new Response('{"success":false,"data":{"message":"Offline"}}', {
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  // CDN (fonts, qrcode.js) → cache-first
  if (url.hostname !== self.location.hostname) {
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request).then(res => {
        if (res.ok) { const c = res.clone(); caches.open(CACHE).then(cache => cache.put(e.request, c)); }
        return res;
      }).catch(() => cached))
    );
    return;
  }

  // Navigation → network first, offline.html fallback
  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request).catch(() =>
        caches.match('./offline.html').then(r => r || caches.match('./login.html'))
      )
    );
    return;
  }

  // Shell assets → cache-first
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (res.ok) { const c = res.clone(); caches.open(CACHE).then(cache => cache.put(e.request, c)); }
        return res;
      });
    })
  );
});
