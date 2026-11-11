import { tameZoomWhenInstalled } from './installed-zoom';

tameZoomWhenInstalled();

// Makes NONOX installable on a phone, tablet or computer (public/sw.js). The
// worker only shows an offline page; it caches no data. The staff ledger
// kiosk and the workshop terminal register their own workers for their narrower scopes.
if ('serviceWorker' in navigator && window.isSecureContext && !location.pathname.startsWith('/personalliggare/kiosk') && !location.pathname.startsWith('/verkstad/terminal')) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
