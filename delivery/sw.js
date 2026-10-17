/**
 * ETEL Delivery Service Worker v1.0
 * Strategies:
 *   - Static shell  → Cache-first (HTML, JS, CSS, fonts)
 *   - API calls     → Network-first with cache fallback
 *   - Images        → Stale-while-revalidate
 *   - Navigation    → Network-first, fallback to offline.html
 */

const CACHE_NAME   = 'etel-v172';
const API_CACHE    = 'etel-api-v7';
const IMG_CACHE    = 'etel-img-v8';

// manifest.json and sw.js must NOT be precached — browsers fetch them directly
const PRECACHE = [
    './login.html',
    './customer.html',
    './courier/',
    './servicegiver.html',
    './admin.html',
    './ride/',
    './driver/',
    './offline.html',
    './js/core.js',
    './js/etel-display-settings.js',
    './favicon.ico',
    './icons/icon-192.png',
    './icons/icon-512.png',
    './icons/ride-icon-192.png',
    './icons/ride-icon-512.png',
    /* Previously missing here entirely - meaning driver/service/courier/
     * admin icons only ever got cached lazily, on first request, via
     * stale-while-revalidate below (serve whatever's cached instantly,
     * update in the background for NEXT time). That's exactly how a
     * bad first-ever fetch for one of these URLs (e.g. from before
     * customer.html/driver's manifest links were correctly set up)
     * could get stuck in the image cache indefinitely, showing the
     * wrong icon forever even after the real underlying files were
     * fixed - which is exactly the bug this was. Eagerly precaching
     * all six app icons the same way the default/Ride ones already
     * were closes this gap for every app, not just the two that broke. */
    './icons/driver-icon-192.png',
    './icons/driver-icon-512.png',
    './icons/service-icon-192.png',
    './icons/service-icon-512.png',
    './icons/courier-icon-192.png',
    './icons/courier-icon-512.png',
    './icons/admin-icon-192.png',
    './icons/admin-icon-512.png',
];

// ── Install: pre-cache shell ───────────────────────────────────
self.addEventListener('install', e => {
    self.skipWaiting();
    e.waitUntil(
        caches.open(CACHE_NAME).then(c => c.addAll(PRECACHE.map(u => new Request(u, {cache:'reload'}))))
              .catch(() => {}) // don't fail install on bad cached files
    );
});

// ── Activate: purge old caches ────────────────────────────────
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME && k !== API_CACHE && k !== IMG_CACHE)
                           .map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ── Fetch routing ─────────────────────────────────────────────
self.addEventListener('fetch', e => {
    const { request } = e;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;
    // Skip ALL cross-origin requests — let the browser fetch CDN scripts,
    // Google Fonts, and Maps tiles directly. Intercepting them risks
    // returning a 503 when the SW cache-fetch hiccups.
    if (url.origin !== location.origin) return;

    // manifest files and sw.js must always come from network — never cache them
    if (url.pathname.endsWith('manifest.php') || url.pathname.endsWith('sw.js') || /manifest(-[a-z]+)?\.json$/.test(url.pathname)) return;

    // API calls → Network-first, short cache (5 min)
    if (url.pathname.includes('boot.php')) {
        e.respondWith(networkFirstAPI(request));
        return;
    }

    // Images → Stale-while-revalidate
    if (/\.(png|jpg|jpeg|gif|webp|svg|ico)$/i.test(url.pathname)) {
        e.respondWith(staleWhileRevalidate(request, IMG_CACHE));
        return;
    }

    // Navigation (HTML pages) → Network-first, offline fallback
    if (request.mode === 'navigate') {
        e.respondWith(networkFirstNav(request));
        return;
    }

    // Everything else (JS, CSS, fonts) → Cache-first
    e.respondWith(cacheFirst(request));
});

async function networkFirstAPI(req) {
    try {
        const net = await fetch(req.clone());
        if (net.ok) {
            const c = await caches.open(API_CACHE);
            c.put(req, net.clone());
        }
        return net;
    } catch {
        const cached = await caches.match(req);
        return cached || new Response(JSON.stringify({success:false,message:'You are offline'}),
            {headers:{'Content-Type':'application/json'}});
    }
}

async function networkFirstNav(req) {
    try {
        const net = await fetch(req);
        const c = await caches.open(CACHE_NAME);
        c.put(req, net.clone());
        return net;
    } catch {
        const cached = await caches.match(req) || await caches.match('./offline.html');
        return cached;
    }
}

async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
        const net = await fetch(req);
        const c = await caches.open(CACHE_NAME);
        c.put(req, net.clone());
        return net;
    } catch {
        return new Response('', {status:503});
    }
}

async function staleWhileRevalidate(req, cacheName) {
    const c = await caches.open(cacheName);
    const cached = await c.match(req);
    const netPromise = fetch(req).then(r => { c.put(req, r.clone()); return r; }).catch(()=>null);
    return cached || await netPromise || new Response('', {status:503});
}

// ── Push Notifications ────────────────────────────────────────
self.addEventListener('push', e => {
    if (!e.data) return;
    const d = e.data.json();
    e.waitUntil(
        self.registration.showNotification(d.title || 'ETEL Delivery', {
            body:    d.body    || 'You have a new update',
            icon:    './icons/icon-192.png',
            badge:   './icons/icon-72.png',
            vibrate: [200, 100, 200],
            tag:     (d.data && d.data.tag) || undefined,
            renotify: !!(d.data && d.data.tag),
            data:    d.url || './',
            actions: d.actions || []
        })
    );
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    e.waitUntil(
        clients.matchAll({type:'window'}).then(cs => {
            const c = cs.find(c => c.focus);
            if (c) { c.focus(); c.navigate(e.notification.data); }
            else clients.openWindow(e.notification.data || './login.html');
        })
    );
});

// ── Background Sync (queue failed order requests) ─────────────
self.addEventListener('sync', e => {
    if (e.tag === 'etel-sync') {
        e.waitUntil(replayQueuedRequests());
    }
});

async function replayQueuedRequests() {
    // Reads queued requests from IndexedDB (set up in app code)
    // This is a placeholder; full implementation hooks into app-side IDB queue
    console.log('[ETEL SW] Background sync triggered');
}
