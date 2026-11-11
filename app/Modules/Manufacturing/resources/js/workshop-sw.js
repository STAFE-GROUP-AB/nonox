// Service worker for the workshop terminal (ADR-071 §7). The server prepends
// PRECACHE (the terminal page, its manifest, icon and built assets) and
// VERSION. The page and its assets come from the cache when the network is
// slow or gone, so the tablet starts at once; data is never cached here, and
// the terminal says plainly that nothing is saved without a connection.
/* global PRECACHE, VERSION, SHELL */

const CACHE = `nonox-workshop-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('nonox-workshop-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // The page: the network first, so updates arrive; the cached page without it.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(SHELL, copy));

                    return response;
                })
                .catch(() => caches.match(SHELL)),
        );

        return;
    }

    if (PRECACHE.includes(url.pathname) || url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copy));

                return response;
            })),
        );
    }
});
