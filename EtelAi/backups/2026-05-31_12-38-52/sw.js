// ETEL Agent OS — Service Worker
// Caches all assets for full offline use. Skills stored permanently.

const CACHE = 'etel-v1';
const SKILL_CACHE = 'etel-skills-v1';

const CORE_FILES = [
  '/EtelAi/',
  '/EtelAi/index.html',
  '/EtelAi/manifest.json',
  '/EtelAi/icons/icon-192.png',
  '/EtelAi/icons/icon-512.png',
];

// ── INSTALL: cache core files ──
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(CORE_FILES))
  );
  self.skipWaiting();
});

// ── ACTIVATE: clean old caches ──
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE && k !== SKILL_CACHE)
          .map(k => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// ── FETCH: serve from cache first, fallback to network ──
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Google Fonts — network first, fallback cache
  if (url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com') {
    event.respondWith(
      fetch(event.request)
        .then(res => {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(event.request, clone));
          return res;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Skill JSON files — cache permanently once fetched
  if (url.pathname.startsWith('/skills/')) {
    event.respondWith(
      caches.open(SKILL_CACHE).then(cache =>
        cache.match(event.request).then(cached => {
          if (cached) return cached;
          return fetch(event.request).then(res => {
            cache.put(event.request, res.clone());
            return res;
          });
        })
      )
    );
    return;
  }

  // Everything else — cache first
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(res => {
        if (!res || res.status !== 200 || res.type !== 'basic') return res;
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(event.request, clone));
        return res;
      }).catch(() => caches.match('/index.html'));
    })
  );
});

// ── BACKGROUND SYNC ──
self.addEventListener('sync', event => {
  if (event.tag === 'sync-skills') {
    event.waitUntil(syncSkills());
  }
});

async function syncSkills() {
  // In production: push installed skill IDs to your backend / Obsidian webhook
  console.log('[ETEL SW] Background sync: skills');
}

// ── PUSH NOTIFICATIONS (optional) ──
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : { title: 'ETEL', body: 'Agent update ready.' };
  event.waitUntil(
    self.registration.showNotification(data.title || 'ETEL Agent OS', {
      body: data.body,
      icon: '/EtelAi/icons/icon-192.png',
      badge: '/EtelAi/icons/icon-192.png',
    })
  );
});
