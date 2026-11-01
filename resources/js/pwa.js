// Makes NONOX installable on a phone, tablet or computer (public/sw.js). The
// worker only shows an offline page; it caches no data. The staff ledger
// kiosk registers its own worker for its narrower scope.
if ('serviceWorker' in navigator && window.isSecureContext && !location.pathname.startsWith('/personalliggare/kiosk')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
