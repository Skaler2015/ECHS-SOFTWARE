/* Minimal service worker for installability + light asset caching. */
const CACHE = 'noble-v1';
self.addEventListener('install', e => { self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // cache only static assets; never cache PHP/dynamic pages
  if (e.request.method === 'GET' && /\.(css|js|png|svg|webmanifest)$/.test(url.pathname)) {
    e.respondWith(
      caches.open(CACHE).then(c =>
        c.match(e.request).then(hit => hit || fetch(e.request).then(res => { c.put(e.request, res.clone()); return res; }))
      )
    );
  }
});
