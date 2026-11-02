# ADR-031: NONOX as an installable web app (PWA)

Status: proposed, 2026-11-01

## Context

The owner (2026-11-01): the comparison pages said NONOX has no mobile app,
although the staff ledger kiosk already installs on a tablet and works
offline, and the app should be a responsive app with PWA features. Every page
is already built and checked for phones (`npm run check:mobile`,
`MobileLayoutTest`). What was missing was the part that lets a phone, tablet
or computer install NONOX on its home screen and open it without browser
chrome.

## Decisions

1. **One web app, no store apps.** `public/app.webmanifest` describes NONOX
   (`start_url` `/oversikt`, `display: standalone`, icons in `public/brand`,
   shortcuts to Inkorg and Uppgifter). The app's layouts and the sign-in page
   link it from `partials/favicon`, together with an `apple-touch-icon` for
   iOS. The public website (landing page, industry, comparison, security and
   legal pages) does not: it is a website, not the app, and installing it
   would only open a page that leads to the sign-in.
2. **Min sida gets its own manifest.** Employees who only use Min sida are not
   members of a company and would be sent to onboarding from `/oversikt`.
   `public/anstalld.webmanifest` starts at `/anstalld`; the portal layout links
   it instead.
3. **The service worker caches no data.** `public/sw.js` precaches only
   `offline.html` and the icon, answers a failed navigation with that page and
   leaves every other request to the network. No page, voucher, payslip or
   personal number is stored on the device, so a lost phone holds nothing a
   signed-out browser would not. Offline work stays limited to the staff
   ledger kiosk, whose own worker and sealed roster (ADR on the kiosk) are
   designed for it and registered for the narrower scope
   `/personalliggare/kiosk/`, which takes precedence there.
4. **The installed app does not zoom the page** (`resources/js/zoom-lock.js`,
   loaded by the app and by Skanna). Only when the page runs standalone
   (`display-mode: standalone` or iOS `navigator.standalone`) it adds
   `maximum-scale=1, user-scalable=no` to the viewport, which also stops
   iOS zooming into a focused field, and cancels iOS `gesturestart`/
   `gesturechange` and two-finger `touchmove`, since iOS ignores
   `user-scalable=no`. Pictures worth zooming (invoices, receipts, article
   pictures) carry `data-zoom` and open in a full-screen viewer that scales
   the picture itself with pointer events (pinch, pan, double tap, Esc). A PDF
   in an iframe is its own document and keeps its zoom; a page that did zoom
   can always zoom back out. A browser tab is unchanged, so zoom as an
   accessibility aid stays where it is needed most.
5. **Public comparison.** `docs/migration/jamforelse.json` gets the value
   `web` ("Webbapp att installera (PWA)") for NONOX on the "Mobilapp" row,
   instead of "yes": other programs on that row have apps in App Store and
   Google Play, which NONOX does not. A separate row states that the staff
   ledger runs as a tablet app without internet; other programs are "Anges
   ej" there, since their price pages do not say.

## Consequences

- Installing works from Safari (Dela → Lägg till på hemskärmen) and from
  Chromium browsers (Installera app). Push notifications and offline
  bookkeeping are not part of this decision.
- Changing `sw.js` changes `CACHE`, so an old offline page is replaced.
