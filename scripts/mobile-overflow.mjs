// Checks that no page is wider than a phone: on each route and screen size the
// page must not scroll sideways (a wide table scrolls inside its own card).
//
//   npm run check:mobile                      # against http://127.0.0.1:8000
//   BASE_URL=http://oss.test npm run check:mobile
//   ROUTES="/fakturor/ny /kunder" npm run check:mobile
//
// Needs Playwright, which is not a dependency of NONOX: install it once with
// `npm install --no-save playwright`, or point PLAYWRIGHT at an installed copy.
// Uses the browser in CHROME_PATH if set, otherwise Playwright's own Chromium.
// Signs in through /logga-in, so run it against a local installation with
// APP_ENV=local and OTP_AUTOFILL=true, where the code is filled in for you.
// Signing in once per run keeps you far below the daily cap of login codes.
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');

const base = (process.env.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');

// Pages that need no id. Pages with an id (one invoice, one voucher) are
// added with ROUTES once there is data to show.
const publicRoutes = ['/', '/logga-in', '/flytta-hit', '/egen-drift', '/villkor', '/integritetspolicy', '/sakerhet', '/alternativ-till-fortnox'];
const signedInRoutes = [
    '/oversikt', '/mina-foretag', '/profil',
    '/verifikationer', '/verifikationer/ny', '/kontoplan', '/verifikationsserier', '/rakenskapsar', '/kostnadsstallen', '/projekt',
    '/rapporter', '/moms', '/eu-rapporter', '/bokslut', '/periodiseringar', '/anlaggningar', '/bankavstamning', '/arsredovisning', '/inkomstdeklaration', '/importera-sie',
    '/fakturor', '/fakturor/ny', '/offerter', '/offerter/ny', '/ordrar', '/ordrar/ny', '/avtal', '/avtal/nytt', '/kunder', '/artiklar', '/inbetalningar',
    '/leverantorer', '/leverantorsfakturor', '/leverantorsfakturor/ny', '/leverantorsfakturor/betalfiler',
];
const routes = process.env.ROUTES ? process.env.ROUTES.split(/\s+/).filter(Boolean) : [...publicRoutes, ...signedInRoutes];

// iPhone, a small Android phone and an iPad held upright.
const sizes = [[390, 844], [360, 780], [768, 1024]];

const browser = await chromium.launch(process.env.CHROME_PATH ? { executablePath: path.resolve(process.env.CHROME_PATH) } : {});
const failures = [];
let storageState;

try {
    for (const [width, height] of sizes) {
        // Reduced motion on: phones with it switched on lay out differently.
        const context = await browser.newContext({
            viewport: { width, height }, isMobile: width < 700, hasTouch: true, reducedMotion: 'reduce', storageState,
        });
        // The cookie choice is made, so the consent banner does not cover the sign-in button.
        await context.addInitScript(() => {
            try {
                localStorage.setItem('nonox-consent', JSON.stringify({ version: 1, at: Date.now(), granted: [] }));
            } catch {
                // Storage is off; the banner then stays, which is fine for the measurement.
            }
        });
        const page = await context.newPage();

        if (!storageState) {
            await page.goto(`${base}/logga-in`, { waitUntil: 'networkidle' });
            await page.click('button[type=submit]');
            await page.waitForURL((url) => !url.pathname.startsWith('/logga-in'), { timeout: 30_000 });
            storageState = await context.storageState();
        }

        for (const route of routes) {
            const response = await page.goto(`${base}${route}`, { waitUntil: 'networkidle' });
            const pageWidth = await page.evaluate(() => document.documentElement.scrollWidth);
            const status = response?.status() ?? 0;

            if (status >= 400) {
                failures.push(`${width}×${height} ${route}: HTTP ${status}`);
            } else if (pageWidth > width) {
                failures.push(`${width}×${height} ${route}: ${pageWidth} px wide`);
            }

            console.log(`${pageWidth > width || status >= 400 ? '✗' : '✓'} ${width}×${height} ${route}`);
        }

        await context.close();
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(`\n${failures.length} page(s) wider than the screen or failing:\n${failures.join('\n')}`);
    process.exit(1);
}

console.log(`\nNo page scrolls sideways (${routes.length} pages, ${sizes.length} screen sizes).`);
