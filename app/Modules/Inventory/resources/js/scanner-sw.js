// Service worker for Skanna, the scanning app (ADR-040). The server
// prepends PRECACHE (the page and its built files), VERSION and SHELL. The
// page and its files come from the cache when the network is gone, so the
// app opens in a warehouse without coverage. No data is cached here: the
// reference data and the queue live in IndexedDB, and every JSON request
// goes to the network.
/* global PRECACHE, VERSION, SHELL */

const CACHE = `nonox-skanna-${VERSION}`;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('nonox-skanna-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // The page: the network first, so updates arrive. Only the real page is
    // kept, never a sign-in page that a logged-out session is sent to.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && !response.redirected && new URL(response.url).pathname === SHELL) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(SHELL, copy));
                    }

                    return response;
                })
                .catch(() => caches.match(SHELL)),
        );

        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }
});
