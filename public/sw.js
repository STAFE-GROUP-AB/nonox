/*
 * NONOX service worker. It makes the app installable and shows a Swedish
 * offline page when there is no connection. It caches nothing else: every
 * page and every file is fetched from the server, so no bookkeeping or
 * personal data is stored on the device. The staff ledger kiosk has its own
 * worker under /personalliggare/kiosk/ that works offline.
 */
const CACHE = 'nonox-offline-v1';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/brand/nonox-icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('nonox-offline-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});
