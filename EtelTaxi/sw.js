/* ============================================================
   ETEL TAXI — Service Worker
   Only handles push notifications right now (no offline caching yet).
   Same event-handling pattern as Delivery & Ride's sw.js.
   ============================================================ */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  if (!e.data) return;
  const d = e.data.json();
  e.waitUntil(
    self.registration.showNotification(d.title || 'Etel Taxi', {
      body: d.body || 'You have a new update',
      vibrate: [200, 100, 200],
      data: (d.data && d.data.url) || './driver.html'
    })
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const target = e.notification.data || './driver.html';
  e.waitUntil(
    clients.matchAll({ type: 'window' }).then(cs => {
      const c = cs.find(c => c.focus);
      if (c) { c.focus(); c.navigate(target); }
      else clients.openWindow(target);
    })
  );
});
