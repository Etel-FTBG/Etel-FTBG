// ETEL Agent OS — Service Worker v4
// Minimal SW: does NOT intercept any fetches.
// This prevents interference with WebLLM model downloads.

const CACHE      = 'etel-v4';
const SKILL_CACHE = 'etel-skills-v1';

// ── INSTALL ──
self.addEventListener('install', event => {
  self.skipWaiting();
});

// ── ACTIVATE: remove ALL old caches except WebLLM/MLC model caches ──
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k =>
          k !== CACHE &&
          k !== SKILL_CACHE &&
          !k.startsWith('webllm') &&
          !k.startsWith('mlc-ai') &&
          !k.startsWith('MLC')
        ).map(k => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// ── FETCH: completely hands-off — browser handles everything natively ──
// No fetch interception = WebLLM downloads work perfectly from any CDN
self.addEventListener('fetch', event => {
  // Do nothing — let the browser handle all requests natively
});

// ── PUSH NOTIFICATIONS ──
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : { title: 'ETEL AI', body: 'Update ready.' };
  event.waitUntil(
    self.registration.showNotification(data.title || 'ETEL AI', {
      body: data.body,
      icon: '/EtelAi/icons/icon-192.png',
    })
  );
});
