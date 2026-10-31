// Service worker for the staff ledger kiosk (ADR-022). The server prepends
// PRECACHE (the kiosk page, its manifest, icon and built assets) and VERSION.
// The page and its assets are served from the cache when the network is
// gone, so the kiosk starts and works offline for days. Kiosk data and
// events are never cached here: they live in IndexedDB.
/* global PRECACHE, VERSION, SHELL */

const CACHE = `nonox-kiosk-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('nonox-kiosk-') && key !== CACHE).map((key) => caches.delete(key))))
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
