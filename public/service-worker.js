/**
 * Offline shell only.
 *
 * Personal timetable data is not stored here. The Cache API keys entries by
 * URL, not by the signed-in user, so a cached `/api/v1/timetable` on a shared
 * phone would show the previous person's week. The page keeps that copy in
 * localStorage under the user id instead.
 */
const CACHE = 'catms-shell-v13';
const SHELL = [
  '/index.html',
  '/assets/css/app.css?v=13',
  '/assets/js/app.js?v=13',
  '/assets/js/manage.js?v=13',
  '/assets/img/mark.svg',
  '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).then((response) => {
        const copy = response.clone();
        caches.open(CACHE).then((cache) => cache.put('/shell', copy));
        return response;
      }).catch(() => caches.match('/shell').then((cached) => cached || caches.match('/index.html'))),
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (response.ok && url.pathname.startsWith('/assets/')) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }

        return response;
      });
    }),
  );
});
