/**
 * ኢE Pay Service Worker v1.1
 * Cache strategy:
 *   Shell (HTML/CSS/JS) → Cache-first
 *   API calls           → Network-first, no cache
 *   Navigation          → Network-first → offline.html fallback
 */

const CACHE     = 'etelpay-v9';   // bumped so old cache clears on activate
const PRECACHE  = [
  './login',
  './wallet',
  './send',
  './history',
  './qr',
  './profile',
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

  // Never let any non-GET request reach the cache -- cache.put()/cache.match()
  // only support GET and throw on POST, which was silently turning every
  // login/API call into a rejected fetch promise (client saw "Connection
  // error. Check internet." even though the server responded fine).
  if (e.request.method !== 'GET') {
    e.respondWith(
      fetch(e.request).catch(() =>
        new Response('{"success":false,"data":{"message":"Offline"}}', {
          headers: { 'Content-Type': 'application/json' }
        })
      )
    );
    return;
  }

  // API calls + reset.php → always network, never cache
  if (
    url.pathname.includes('wp-json') ||
    url.pathname.includes('api.php') ||
    url.pathname.includes('v2_verify.php') ||
    url.pathname.includes('ep_r1.php') ||
    url.pathname.includes('ep_r2.php') ||
    url.pathname.includes('ep_r3.php') ||
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

  // CDN (fonts, qrcode.js) → don't intercept at all. A fetch() call made
  // *inside* the service worker is checked against the page's CSP
  // connect-src (not style-src/font-src, even though the underlying
  // request is for a stylesheet/font) -- and connect-src here only
  // allows ethelmarket.com. Re-issuing the request from inside the SW
  // was therefore CSP-blocked on every single Google Fonts file, which
  // is what caused dozens of repeated console errors on every load.
  // Not calling respondWith() lets the browser fetch it directly under
  // the page's own (correct) CSP context instead.
  if (url.hostname !== self.location.hostname) {
    return;
  }

  // Navigation → network first, offline.html fallback
  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request).catch(() =>
        caches.match('./offline.html').then(r => r || caches.match('./login').then(r2 => r2 || fetch(e.request)))
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
      }).catch(() => fetch(e.request));
    })
  );
});
